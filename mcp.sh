#!/usr/bin/env bash
#
# mcp.sh — MCPサーバー一式の起動/停止スクリプト
#
#   ./mcp.sh start   : PHPサーバー2つ + cloudflaredトンネルを起動し、
#                      発行された新URLで .mcp.json を自動更新して疎通確認まで行う
#   ./mcp.sh stop    : すべて停止
#   ./mcp.sh status  : 稼働状況と現在の公開URLを表示
#
set -euo pipefail
cd "$(dirname "$0")"

CLOUDFLARED="${CLOUDFLARED:-$HOME/.local/bin/cloudflared}"
LOG_DIR="/tmp/claude-mcp-sample"
TUNNEL_LOG="$LOG_DIR/cloudflared.log"
MCP_JSON=".mcp.json"

mkdir -p "$LOG_DIR"

start_php() {
    local port=$1
    if pgrep -f "php -S localhost:$port" > /dev/null; then
        echo "  localhost:$port : 既に稼働中"
    else
        nohup php -S "localhost:$port" > "$LOG_DIR/php-$port.log" 2>&1 &
        echo "  localhost:$port : 起動しました (PID $!)"
    fi
}

cmd_start() {
    echo "== PHPサーバー =="
    start_php 8000
    start_php 8001

    echo "== cloudflaredトンネル =="
    if ! [ -x "$CLOUDFLARED" ]; then
        echo "エラー: cloudflaredが見つかりません ($CLOUDFLARED)" >&2
        exit 1
    fi
    # 既存トンネルは停止（URLが変わるため作り直す）
    pkill -f 'cloudflared tunnel' 2>/dev/null && sleep 1 || true
    : > "$TUNNEL_LOG"
    nohup "$CLOUDFLARED" tunnel --url http://localhost:8000 > "$TUNNEL_LOG" 2>&1 &
    echo "  cloudflared起動 (PID $!) — URL発行を待機中..."

    # URLが発行されるまで最大30秒待つ
    local url=""
    for _ in $(seq 1 30); do
        url=$(grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' "$TUNNEL_LOG" | head -1 || true)
        [ -n "$url" ] && break
        sleep 1
    done
    if [ -z "$url" ]; then
        echo "エラー: トンネルURLを取得できませんでした。ログ: $TUNNEL_LOG" >&2
        exit 1
    fi
    echo "  公開URL: $url"

    echo "== .mcp.json 更新 =="
    local endpoint="$url/mcp-server.php"
    jq --arg u "$endpoint" '.mcpServers["php-sample"].url = $u' "$MCP_JSON" > "$MCP_JSON.tmp" \
        && mv "$MCP_JSON.tmp" "$MCP_JSON"
    echo "  php-sample.url -> $endpoint"

    echo "== 疎通確認 (公開URL経由でMCP initialize) =="
    local resp
    for _ in $(seq 1 10); do
        resp=$(curl -s --max-time 10 -X POST "$endpoint" \
            -H 'Content-Type: application/json' \
            -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"mcp.sh","version":"1.0"}}}' || true)
        echo "$resp" | grep -q '"serverInfo"' && break
        sleep 2
    done
    if echo "$resp" | grep -q '"serverInfo"'; then
        echo "  OK: MCPサーバーが公開URLで応答しています"
    else
        echo "  警告: まだ応答がありません（DNS浸透待ちの可能性）。少し待って ./mcp.sh status で確認してください" >&2
    fi

    cat <<EOF

======================================================
 MCPエンドポイント: $endpoint
------------------------------------------------------
 次のどちらかを忘れずに:
  - ルーチンで使う場合  : git add .mcp.json && git commit && git push
  - コネクタで使う場合  : claude.aiのコネクタ設定のURLを上記に変更
 停止するときは        : ./mcp.sh stop
======================================================
EOF
}

cmd_stop() {
    pkill -f 'cloudflared tunnel' 2>/dev/null && echo "cloudflared: 停止" || echo "cloudflared: 起動していません"
    pkill -f 'php -S localhost:8000' 2>/dev/null && echo "php :8000  : 停止" || echo "php :8000  : 起動していません"
    pkill -f 'php -S localhost:8001' 2>/dev/null && echo "php :8001  : 停止" || echo "php :8001  : 起動していません"
}

cmd_status() {
    pgrep -f 'php -S localhost:8000' > /dev/null && echo "php :8000  : 稼働中" || echo "php :8000  : 停止"
    pgrep -f 'php -S localhost:8001' > /dev/null && echo "php :8001  : 稼働中" || echo "php :8001  : 停止"
    if pgrep -f 'cloudflared tunnel' > /dev/null; then
        local url
        url=$(grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' "$TUNNEL_LOG" 2>/dev/null | head -1 || true)
        echo "cloudflared: 稼働中 (${url:-URL不明})"
        if [ -n "$url" ]; then
            local mcp_url
            mcp_url=$(jq -r '.mcpServers["php-sample"].url' "$MCP_JSON")
            if [ "$mcp_url" = "$url/mcp-server.php" ]; then
                echo ".mcp.json  : 一致（現在のトンネルURLと同期済み）"
            else
                echo ".mcp.json  : 不一致! ($mcp_url) → ./mcp.sh start で再同期してください"
            fi
        fi
    else
        echo "cloudflared: 停止"
    fi
}

case "${1:-}" in
    start)  cmd_start ;;
    stop)   cmd_stop ;;
    status) cmd_status ;;
    *)      echo "使い方: $0 {start|stop|status}"; exit 1 ;;
esac
