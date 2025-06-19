<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Проверка соединения с БД
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Ошибка подключения к базе данных']);
    exit;
}

// Получение и валидация входных данных
$input = json_decode(file_get_contents("php://input"), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'Неверный формат данных']);
    exit;
}

$interest_id = isset($input['interest_id']) ? intval($input['interest_id']) : 0;
$current_user_id = $_SESSION['user_id'] ?? 0;

// Проверка обязательных полей
if ($interest_id <= 0 || $current_user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Недостаточно данных']);
    exit;
}

try {
    // Проверяем, есть ли у текущего пользователя этот интерес
    $checkStmt = $conn->prepare("SELECT 1 FROM user_interests WHERE user_id = ? AND interest_id = ?");
    if (!$checkStmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $checkStmt->bind_param("ii", $current_user_id, $interest_id);
    if (!$checkStmt->execute()) {
        throw new Exception("Execute failed: " . $checkStmt->error);
    }
    
    if ($checkStmt->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'ошибка']);
        exit;
    }

    // Ищем подходящего пользователя с таким интересом, с которым еще нет мэтча
    $sql = "
        SELECT ui.user_id, u.username
        FROM user_interests ui
        INNER JOIN users u ON u.user_id = ui.user_id
        WHERE ui.interest_id = ?
          AND ui.user_id != ?
          AND u.is_active = 1
          AND u.is_blocked = 0
          AND ui.user_id NOT IN (
              SELECT CASE 
                         WHEN m.user1_id = ? THEN m.user2_id 
                         ELSE m.user1_id 
                     END
              FROM matches m
              WHERE m.user1_id = ? OR m.user2_id = ?
          )
          AND ui.user_id NOT IN (
              SELECT blocked_user_id FROM user_blocklist WHERE blocker_user_id = ?
          )
          AND ui.user_id NOT IN (
              SELECT blocker_user_id FROM user_blocklist WHERE blocked_user_id = ?
          )
        ORDER BY RAND()
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("iiiiiii", $interest_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $match_user = $result->fetch_assoc();

    if (!$match_user) {
        echo json_encode(['success' => false, 'message' => 'Нет доступных пользователей по этому интересу']);
        exit;
    }

    $other_user_id = $match_user['user_id'];

    // Создаём мэтч 
    $users = [$current_user_id, $other_user_id];
    sort($users);
    
    $insertStmt = $conn->prepare("INSERT INTO matches (user1_id, user2_id) VALUES (?, ?)");
    if (!$insertStmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $insertStmt->bind_param("ii", $users[0], $users[1]);
    if (!$insertStmt->execute()) {
        throw new Exception("Execute failed: " . $insertStmt->error);
    }
    
    $match_id = $conn->insert_id;
    
    // Возвращаем данные для редиректа
    echo json_encode([
        'success' => true, 
        'match_id' => $match_id,
        'username' => $match_user['username']
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Произошла ошибка: ' . $e->getMessage()]);
}