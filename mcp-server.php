<?php
/**
 * MCPサーバー サンプル（PHP / Streamable HTTP トランスポート）
 *
 * Claude (Claude Code / Claude Desktop / claude.ai カスタムコネクタ) から
 * HTTP経由で接続できる MCP サーバーの最小実装。
 *
 * 提供ツール:
 *   - get_data  : バックエンドAPIに GET してJSONデータを取得する
 *   - post_data : バックエンドAPIに JSONを POST して結果を返す
 *
 * 起動方法:
 *   php -S localhost:8000
 *   → エンドポイントは http://localhost:8000/mcp-server.php
 */

declare(strict_types=1);

// ツールが呼び出すバックエンドAPI
// 注意: PHPビルトインサーバーはシングルスレッドのため、MCPサーバーと同じ
// ポートに置くとデッドロックする。別ポートで起動すること。
const BACKEND_URL = 'http://localhost:8001/api.php';

const SERVER_NAME    = 'php-sample-mcp';
const SERVER_VERSION = '1.0.0';
const SUPPORTED_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26'];

// ---------------------------------------------------------------------------
// HTTPメソッドの振り分け
// ---------------------------------------------------------------------------
switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        handlePost();
        break;
    case 'GET':
        // SSEストリームは未対応（Streamable HTTP仕様上、405を返してよい）
        // ブラウザで開いた人向けに説明を返す
        header('Allow: POST');
        sendJson([
            'status'  => 'ok',
            'message' => 'This is an MCP server endpoint (' . SERVER_NAME . '). '
                       . 'It only accepts POST requests with JSON-RPC 2.0 messages. '
                       . 'Register this URL as a custom connector in Claude.',
        ], 405);
        break;
    default:
        http_response_code(405);
        header('Allow: POST');
        break;
}

// ---------------------------------------------------------------------------
// JSON-RPC 処理
// ---------------------------------------------------------------------------
function handlePost(): void
{
    $raw = file_get_contents('php://input');
    $message = json_decode($raw, true);

    if (!is_array($message) || !isset($message['method'])) {
        sendJson(jsonRpcError(null, -32700, 'Parse error'), 400);
        return;
    }

    $id     = $message['id'] ?? null;
    $method = $message['method'];
    $params = $message['params'] ?? [];

    // 通知（idなし）にはボディなしの 202 を返す
    if ($id === null) {
        http_response_code(202);
        return;
    }

    try {
        $result = match ($method) {
            'initialize' => handleInitialize($params),
            'ping'       => new stdClass(),
            'tools/list' => handleToolsList(),
            'tools/call' => handleToolsCall($params),
            default      => null,
        };

        if ($result === null) {
            sendJson(jsonRpcError($id, -32601, "Method not found: {$method}"));
            return;
        }

        sendJson(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    } catch (Throwable $e) {
        sendJson(jsonRpcError($id, -32603, 'Internal error: ' . $e->getMessage()));
    }
}

function handleInitialize(array $params): array
{
    $requested = $params['protocolVersion'] ?? '';
    $version = in_array($requested, SUPPORTED_PROTOCOL_VERSIONS, true)
        ? $requested
        : SUPPORTED_PROTOCOL_VERSIONS[0];

    return [
        'protocolVersion' => $version,
        'capabilities' => [
            'tools' => new stdClass(),
        ],
        'serverInfo' => [
            'name'    => SERVER_NAME,
            'version' => SERVER_VERSION,
        ],
    ];
}

function handleToolsList(): array
{
    return [
        'tools' => [
            [
                'name'        => 'get_data',
                'description' => 'バックエンドAPIからJSONデータを取得する。idを指定すると該当レコードのみ返す。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => [
                            'type'        => 'integer',
                            'description' => '取得するレコードのID（省略時は全件）',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'post_data',
                'description' => 'バックエンドAPIにJSONデータを送信して登録し、結果を返す。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name' => [
                            'type'        => 'string',
                            'description' => '登録する項目の名前',
                        ],
                        'value' => [
                            'type'        => 'string',
                            'description' => '登録する項目の値',
                        ],
                    ],
                    'required' => ['name', 'value'],
                ],
            ],
        ],
    ];
}

function handleToolsCall(array $params): array
{
    $name      = $params['name'] ?? '';
    $arguments = $params['arguments'] ?? [];

    switch ($name) {
        case 'get_data':
            $url = BACKEND_URL;
            if (isset($arguments['id'])) {
                $url .= '?id=' . urlencode((string)$arguments['id']);
            }
            [$status, $body] = httpRequest('GET', $url);
            break;

        case 'post_data':
            [$status, $body] = httpRequest('POST', BACKEND_URL, [
                'name'  => $arguments['name'] ?? '',
                'value' => $arguments['value'] ?? '',
            ]);
            break;

        default:
            return toolError("Unknown tool: {$name}");
    }

    if ($status < 200 || $status >= 300) {
        return toolError("Backend API error (HTTP {$status}): {$body}");
    }

    return [
        'content' => [
            ['type' => 'text', 'text' => $body],
        ],
        'isError' => false,
    ];
}

// ---------------------------------------------------------------------------
// ヘルパー
// ---------------------------------------------------------------------------

/** バックエンドへのHTTPリクエスト。[ステータスコード, レスポンスボディ] を返す */
function httpRequest(string $method, string $url, ?array $jsonBody = null): array
{
    $options = [
        'http' => [
            'method'        => $method,
            'timeout'       => 10,
            'ignore_errors' => true, // 4xx/5xxでもボディを読む
        ],
    ];
    if ($jsonBody !== null) {
        $options['http']['header']  = 'Content-Type: application/json';
        $options['http']['content'] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
    }

    $body = @file_get_contents($url, false, stream_context_create($options));
    if ($body === false) {
        return [0, 'Connection failed'];
    }

    // $http_response_header からステータスコードを取り出す
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
            $status = (int)$m[1];
        }
    }
    return [$status, $body];
}

function toolError(string $message): array
{
    return [
        'content' => [['type' => 'text', 'text' => $message]],
        'isError' => true,
    ];
}

function jsonRpcError(mixed $id, int $code, string $message): array
{
    return [
        'jsonrpc' => '2.0',
        'id'      => $id,
        'error'   => ['code' => $code, 'message' => $message],
    ];
}

function sendJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
