# PHP MCPサーバー サンプル

Claudeから接続できるMCPサーバー（Streamable HTTPトランスポート）のPHPサンプル。
composer等の依存ライブラリは不要（PHP 8.1以上）。

## 構成

| ファイル | 役割 |
|---|---|
| `mcp-server.php` | MCPサーバー本体。Claudeはここに接続する（ポート8000） |
| `api.php` | サンプルのバックエンドAPI。GETでJSONを返し、POSTでJSONを受け取る（ポート8001） |
| `.mcp.json` | Claude Code用の接続設定 |
| `data.json` | `api.php` が自動生成する簡易データストア |

```
Claude ──(MCP / JSON-RPC over HTTP)──> mcp-server.php ──(HTTP GET/POST)──> api.php ──> data.json
```

## 提供ツール

- **`get_data`** — バックエンドAPIに GET してJSONデータを取得（`id` 指定で1件取得）
- **`post_data`** — `{name, value}` をバックエンドAPIに POST して登録し、結果のJSONを返す

## 起動方法

### 一括起動スクリプト（推奨）

```sh
./mcp.sh start    # PHPサーバー2つ + cloudflaredトンネル起動 + .mcp.json自動更新 + 疎通確認
./mcp.sh status   # 稼働状況と現在の公開URL、.mcp.jsonとの同期状態を表示
./mcp.sh stop     # すべて停止
```

トンネルURLは起動のたびに変わるが、`start` が `.mcp.json` を自動で書き換える。
実行後に表示される案内に従い、ルーチンで使う場合は `git push`、
claude.aiコネクタで使う場合はコネクタ設定のURLを更新すること。

### 手動起動

ターミナルを2つ使い、それぞれのポートで起動する:

```sh
# ターミナル1: MCPサーバー
php -S localhost:8000

# ターミナル2: バックエンドAPI
php -S localhost:8001
```

> **注意:** PHPビルトインサーバーはシングルスレッドのため、MCPサーバーとバックエンドAPIを
> 同じポートに置くとデッドロックする。1コマンドで済ませたい場合は
> `PHP_CLI_SERVER_WORKERS=4 php -S localhost:8000` でワーカー数を増やし、
> `mcp-server.php` の `BACKEND_URL` を8000番に戻す方法もある。
> Apache/nginx等の通常のWebサーバーに配置する場合はこの問題は起きない。

## Claudeからの接続

### Claude Code

このディレクトリに `.mcp.json` があるので、ここで `claude` を起動すれば自動で認識される。
手動で追加する場合:

```sh
claude mcp add --transport http php-sample http://localhost:8000/mcp-server.php
```

接続後、「get_dataでデータを取得して」「post_dataでname=xxx, value=yyyを登録して」のように指示できる。

### claude.ai / Claude Desktop（カスタムコネクタ）

カスタムコネクタは公開HTTPSのURLが必要。ローカル開発中は ngrok 等でトンネルする:

```sh
ngrok http 8000
# 発行された https://xxxx.ngrok.io/mcp-server.php をコネクタのURLに設定
```

## 動作確認（curl）

```sh
# initialize
curl -s -X POST http://localhost:8000/mcp-server.php \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"0.1"}}}'

# ツール一覧
curl -s -X POST http://localhost:8000/mcp-server.php \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'

# データ取得（GET）
curl -s -X POST http://localhost:8000/mcp-server.php \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"get_data","arguments":{}}}'

# データ登録（POST）
curl -s -X POST http://localhost:8000/mcp-server.php \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"post_data","arguments":{"name":"pressure","value":"1013"}}}'
```

## カスタマイズのポイント

- 実際のAPIに接続する場合は `mcp-server.php` の `BACKEND_URL` を変更し、
  `handleToolsList()` の `inputSchema` と `handleToolsCall()` の処理を実データに合わせる
- ツールを増やす場合は `tools/list` に定義を追加し、`handleToolsCall()` の `switch` に分岐を追加する
- 本サンプルは認証なし。公開環境に置く場合は Bearer トークン検証等を `handlePost()` の先頭に追加すること
