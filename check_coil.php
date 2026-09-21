<?php
header('Content-Type: application/json; charset=utf-8');

// 1. Ошибки — только для локальной разработки
$isLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);
if ($isLocal) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

date_default_timezone_set('Asia/Jerusalem');

// 2. Только POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST method required']);
    exit;
}

// 3. Чтение .env с проверками
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile) || !is_readable($envFile)) {
    http_response_code(500);
    die(json_encode(['status' => 'error', 'message' => 'File .env NOT found or not readable']));
}

$env = parse_ini_file($envFile);
if ($env === false) {
    http_response_code(500);
    die(json_encode(['status' => 'error', 'message' => 'Invalid .env format']));
}

$pass = $env['DB_PASSWORD'] ?? null;
if (!$pass) {
    http_response_code(500);
    die(json_encode(['status' => 'error', 'message' => 'Password in .env is NOT set']));
}

// 4. Входные данные
$coilNumber = trim($_POST['coil'] ?? '');
$action = $_POST['action'] ?? null;

if ($coilNumber === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Coil Number is empty']);
    exit;
}

// 5. Формат coil — буквы, цифры, дефис, подчёркивание (1–50 символов)
if (!preg_match('/^[A-Za-z0-9\/\-_]{1,50}$/', $coilNumber)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid coil format']);
    exit;
}

// 6. Валидация action
$allowedActions = ['partial', 'complete'];
if ($action !== null && !in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    exit;
}

// 7. Подключение к БД
$host = '127.0.0.1';
$port = 3306;
$db   = 'spiral_production';
$user = 'root';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    // 8. Поиск рулона — конкретные поля
    $stmt = $pdo->prepare(
        "SELECT id, coil_number, finish_status, finished_at, order_id
         FROM shift_coils
         WHERE coil_number = :coil
         LIMIT 1"
    );
    $stmt->execute(['coil' => $coilNumber]);
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'Roll NOT found ❌']);
        exit;
    }

    // 9. Блокировка повтора
    if ($row['finish_status'] === 'complete') {
        echo json_encode([
            'status' => 'error',
            'message' => 'The roll is already a SET ❌ — action is impossible',
            'coil' => $row,
            'action_done' => false
        ]);
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $nowDisplay = date('d.m.Y H:i:s');

    // 10. Транзакция
    $pdo->beginTransaction();

    try {
        // 11. Новые значения
        if ($action === 'partial') {
            $newStatus = 'partial';
            $newTime = null;
        } elseif ($action === 'complete') {
            $newStatus = 'complete';
            $newTime = $now;
        } else {
            $newStatus = $row['finish_status'];
            $newTime = $row['finished_at'];
        }

        // 12. Один UPDATE вместо двух
        if ($action !== null) {
            $updateStmt = $pdo->prepare(
                "UPDATE shift_coils
                 SET finish_status = :status, finished_at = :time
                 WHERE id = :id"
            );
            $updateStmt->execute([
                'status' => $newStatus,
                'time'   => $newTime,
                'id'     => $row['id']
            ]);

            $row['finish_status'] = $newStatus;
            $row['finished_at']   = $newTime;

            // 13. Логирование
            try {
                $logStmt = $pdo->prepare(
                    "INSERT INTO coil_logs (coil_id, action, timestamp, user_ip)
                     VALUES (:coil_id, :action, :ts, :ip)"
                );
                $logStmt->execute([
                    'coil_id' => $row['id'],
                    'action'  => $action,
                    'ts'      => $now,
                    'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
            } catch (PDOException $e) {
                error_log('Log insert failed: ' . $e->getMessage());
            }
        }

        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    // 14. Информация о заказе — ИСПРАВЛЕНО под реальную структуру orders
    $orderInfo = null;
    if (!empty($row['order_id'])) {
        $orderStmt = $pdo->prepare(
            "SELECT id, order_code, diameter, thickness, target_length,
                    start_date, end_date, max_transition_days
             FROM orders
             WHERE id = :id
             LIMIT 1"
        );
        $orderStmt->execute(['id' => $row['order_id']]);
        $orderInfo = $orderStmt->fetch() ?: null;
    }

    echo json_encode([
        'status'      => 'ok',
        'message'     => 'Roll found ✅',
        'coil'        => $row,
        'order'       => $orderInfo,
        'action'      => $action,
        'datetime'    => $nowDisplay,
        'action_done' => $action !== null
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'DB error: ' . $e->getMessage()
    ]);
}
