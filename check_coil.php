<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set('Asia/Jerusalem');

// 🔐 ЧИТАЕМ ПАРОЛЬ ИЗ СКРЫТОГО ФАЙЛА .ENV
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $env = parse_ini_file($envFile);
    $pass = $env['DB_PASSWORD'] ?? null;
} else {
    die(json_encode(['status' => 'error', 'message' => 'File .env NOT found!']));
}

if (!$pass) {
    die(json_encode(['status' => 'error', 'message' => 'Password in .env is NOT set!']));
}

$coilNumber = $_GET['coil'] ?? $_POST['coil'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($coilNumber === '') {
    echo json_encode(['status' => 'error', 'message' => 'Coil Number пустой']);
    exit;
}

$allowedActions = ['partial', 'complete'];
if ($action && !in_array($action, $allowedActions)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    exit;
}

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
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    // Поиск рулона
    $stmt = $pdo->prepare("SELECT * FROM shift_coils WHERE coil_number = :coil LIMIT 1");
    $stmt->execute(['coil' => $coilNumber]);
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'Roll NOT found ❌']);
        exit;
    }

    // Если рулон уже комплект, блокируем изменения
    if ($row['finish_status'] === 'complete') {
        echo json_encode([
            'status' => 'error',
            'message' => 'The roll is already a SET ❌ — action is impossible',
            'coil' => $row, // Отправляем данные рулона, даже если он уже готов
            'action_done' => false
        ]);
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $nowDisplay = date('d.m.Y H:i:s');

    // Обновление статуса
    if ($action === 'partial') {
        $pdo->prepare("UPDATE shift_coils SET finish_status = 'partial', finished_at = NULL WHERE id = :id")
            ->execute(['id' => $row['id']]);
        $row['finish_status'] = 'partial';
        $row['finished_at'] = null;
    }

    if ($action === 'complete') {
        $pdo->prepare("UPDATE shift_coils SET finish_status = 'complete', finished_at = :now WHERE id = :id")
            ->execute(['id' => $row['id'], 'now' => $now]);
        $row['finish_status'] = 'complete';
        $row['finished_at'] = $now;
    }

    // Получаем информацию о заказе
    $orderInfo = null;
    if ($row['order_id']) {
        $orderStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $orderStmt->execute([$row['order_id']]);
        $orderInfo = $orderStmt->fetch();
    }

    echo json_encode([
        'status' => 'ok',
        'message' => 'Roll found ✅',
        'coil' => $row,
        'order' => $orderInfo,
        'action' => $action,
        'datetime' => $nowDisplay,
        'action_done' => true
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'DB error: ' . $e->getMessage()
    ]);
}
