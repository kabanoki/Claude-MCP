<?php
/**
 * サンプル バックエンドAPI
 *
 *   GET  /api.php        → 全レコードをJSONで返す
 *   GET  /api.php?id=N   → 該当レコードをJSONで返す
 *   POST /api.php        → JSONボディ {name, value} を受け取り登録して結果を返す
 *
 * データは data.json に保存する（デモ用の簡易ストレージ）。
 */

declare(strict_types=1);

const DATA_FILE = __DIR__ . '/data.json';

header('Content-Type: application/json');

$records = loadRecords();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];
            foreach ($records as $record) {
                if ($record['id'] === $id) {
                    echo json_encode($record, JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
            http_response_code(404);
            echo json_encode(['error' => "id={$id} not found"], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode($records, JSON_UNESCAPED_UNICODE);
        }
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !isset($input['name'], $input['value'])) {
            http_response_code(400);
            echo json_encode(['error' => 'JSON body {name, value} is required'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $ids = array_column($records, 'id');
        $newRecord = [
            'id'         => $ids === [] ? 1 : max($ids) + 1,
            'name'       => (string)$input['name'],
            'value'      => (string)$input['value'],
            'created_at' => date('c'),
        ];
        $records[] = $newRecord;
        file_put_contents(DATA_FILE, json_encode($records, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

        http_response_code(201);
        echo json_encode(['status' => 'created', 'record' => $newRecord], JSON_UNESCAPED_UNICODE);
        break;

    default:
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        break;
}

function loadRecords(): array
{
    if (!file_exists(DATA_FILE)) {
        // 初回アクセス時にサンプルデータを作成
        $seed = [
            ['id' => 1, 'name' => 'temperature', 'value' => '23.5', 'created_at' => date('c')],
            ['id' => 2, 'name' => 'humidity',    'value' => '45',   'created_at' => date('c')],
        ];
        file_put_contents(DATA_FILE, json_encode($seed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        return $seed;
    }
    $data = json_decode(file_get_contents(DATA_FILE), true);
    return is_array($data) ? $data : [];
}
