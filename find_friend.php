<?php
session_start();
require 'db.php';

// Включить вывод ошибок для отладки
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Требуется авторизация']);
    exit;
}

$currentUserId = $_SESSION['user_id'];

// Находит случайного активного пользователя, исключая текущего 
function getRandomUser($conn, $currentUserId) {
    try {
        $sql = "
            SELECT u.user_id 
            FROM users u
            WHERE u.user_id != ?
              AND u.is_active = 1
              AND u.user_id NOT IN (
                  SELECT CASE 
                             WHEN m.user1_id = ? THEN m.user2_id 
                             ELSE m.user1_id 
                         END
                  FROM matches m
                  WHERE m.user1_id = ? OR m.user2_id = ?
              )
            ORDER BY RAND()
            LIMIT 1
        ";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Ошибка подготовки запроса: " . $conn->error);
        
        $stmt->bind_param("iiii", $currentUserId, $currentUserId, $currentUserId, $currentUserId);
        if (!$stmt->execute()) throw new Exception("Ошибка выполнения запроса: " . $stmt->error);
        
        $result = $stmt->get_result();
        return $result->num_rows > 0 ? $result->fetch_assoc()['user_id'] : null;
        
    } catch (Exception $e) {
        error_log("Ошибка в getRandomUser: " . $e->getMessage());
        return null;
    }
}

// Создает новый мэтч или возвращает существующий
function getOrCreateMatch($conn, $user1, $user2) {
    $users = [(int)$user1, (int)$user2];
    sort($users); // Для предотвращения дубликатов
    
    try {
        // Проверка существующего мэтча
        $stmt = $conn->prepare("SELECT match_id FROM matches WHERE user1_id = ? AND user2_id = ?");
        if (!$stmt) throw new Exception("Ошибка подготовки запроса: " . $conn->error);
        
        $stmt->bind_param("ii", $users[0], $users[1]);
        if (!$stmt->execute()) throw new Exception("Ошибка выполнения запроса: " . $stmt->error);
        
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            return $result->fetch_assoc()['match_id'];
        }
        
        // Создание нового мэтча
        $stmt = $conn->prepare("INSERT INTO matches (user1_id, user2_id) VALUES (?, ?)");
        if (!$stmt) throw new Exception("Ошибка подготовки запроса: " . $conn->error);
        
        $stmt->bind_param("ii", $users[0], $users[1]);
        if (!$stmt->execute()) throw new Exception("Ошибка выполнения запроса: " . $stmt->error);
        
        return $conn->insert_id;
        
    } catch (Exception $e) {
        error_log("Ошибка в getOrCreateMatch: " . $e->getMessage());
        return null;
    }
}

try {
    // Поиск случайного пользователя
    $randomUserId = getRandomUser($conn, $currentUserId);
    
    if (!$randomUserId) {
        throw new Exception("Нет доступных пользователей для создания мэтча");
    }
    
    // Создание или получение мэтча
    $matchId = getOrCreateMatch($conn, $currentUserId, $randomUserId);
    
    if (!$matchId) {
        throw new Exception("Ошибка создания мэтча");
    }
    
    // Успешный ответ
    echo json_encode([
        'status' => 'success',
        'match_id' => $matchId
    ]);
    
} catch (Exception $e) {
    error_log("Глобальная ошибка: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Произошла внутренняя ошибка. Попробуйте позже.'
    ]);
} finally {
    $conn->close();
}
?>