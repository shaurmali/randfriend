<?php
session_start();
include('fh/header.php');
include('db.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: reg.php");
    exit;
}

$currentUserId = $_SESSION['user_id'];
$matchId = isset($_GET['match_id']) ? $_GET['match_id'] : null;

// Функция для получения id собеседника по match_id
function getRecipientId($conn, $matchId, $currentUserId) {
    $sql = "SELECT user1_id, user2_id FROM matches WHERE match_id = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Error preparing statement: " . $conn->error);
        return null;
    }

    $stmt->bind_param("i", $matchId);

    if (!$stmt->execute()) {
        error_log("Error executing statement: " . $stmt->error);
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        return null;
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row['user1_id'] == $currentUserId) {
        return $row['user2_id'];
    } elseif ($row['user2_id'] == $currentUserId) {
        return $row['user1_id'];
    }

    return null;
}

// Получение сообщений по match_id
function getMessages($conn, $matchId) {
    $sql = "SELECT m.message_id, m.sender_id, m.recipient_id, m.message, m.timestamp, u.username
            FROM messages m
            JOIN users u ON m.sender_id = u.user_id
            WHERE m.match_id = ?
            ORDER BY m.timestamp ASC";

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        error_log("Error preparing statement: " . $conn->error);
        return false;
    }

    $stmt->bind_param("i", $matchId);

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
        $stmt->close();
        return $messages;
    } else {
        error_log("Error executing statement: " . $stmt->error);
        $stmt->close();
        return false;
    }
}

// Отправка сообщения
function sendMessage($conn, $matchId, $senderId, $recipientId, $messagetext) {
    if (!$conn || !($conn instanceof mysqli)) {
        error_log("Invalid database connection");
        return false;
    }

    if (strlen($messagetext) > 65535) {
        error_log("Message is too long");
        return false;
    }

    $sql = "INSERT INTO messages (match_id, sender_id, recipient_id, message, timestamp) VALUES (?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        error_log("Error preparing statement: " . $conn->error);
        return false;
    }

    if (!$stmt->bind_param("iiis", $matchId, $senderId, $recipientId, $messagetext)) {
        error_log("Error binding parameters: " . $stmt->error);
        $stmt->close();
        return false;
    }

    if ($stmt->execute()) {
        $stmt->close();
        return true;
    } else {
        error_log("Error executing statement: " . $stmt->error);
        $stmt->close();
        return false;
    }
}

// Обработка отправки сообщения
if ($_SERVER["REQUEST_METHOD"] == "POST" && $matchId !== null) {
    if (isset($_POST['message']) && !empty(trim($_POST['message']))) {
        $messageText = htmlspecialchars(trim($_POST['message']));
        $recipientId = getRecipientId($conn, $matchId, $currentUserId);

        if (!$recipientId) {
            $errorMessage = "Ошибка: Невозможно определить получателя";
        } else {
            if (sendMessage($conn, $matchId, $currentUserId, $recipientId, $messageText)) {
                header("Location: chat.php?match_id=" . htmlspecialchars($matchId));
                exit;
            } else {
                $errorMessage = "Ошибка отправки сообщения.";
            }
        }
    } else {
        $errorMessage = "Сообщение не может быть пустым.";
    }
}

// Получаем сообщения, если match_id указан
if ($matchId !== null) {
    $messages = getMessages($conn, $matchId);
} else {
    $messages = [];
}

// Получаем список чатов для левой панели
$sql_chats = "SELECT m.match_id,
               CASE
                   WHEN m.user1_id = ? THEN u2.username
                   ELSE u1.username
               END AS other_user_name
        FROM matches m
        LEFT JOIN users u1 ON m.user1_id = u1.user_id
        LEFT JOIN users u2 ON m.user2_id = u2.user_id
        WHERE m.user1_id = ? OR m.user2_id = ?";

$stmt_chats = $conn->prepare($sql_chats);
$stmt_chats->bind_param("iii", $currentUserId, $currentUserId, $currentUserId);
$stmt_chats->execute();
$result_chats = $stmt_chats->get_result();

$chats = [];
while ($row = $result_chats->fetch_assoc()) {
    $chats[] = $row;
}

// Получаем историю знакомств (все пользователи из матчей с текущим, кроме его самого)
$sql_history = "
    SELECT u.*, c.city AS city_name
    FROM users u
    INNER JOIN matches m ON (u.user_id = m.user1_id OR u.user_id = m.user2_id)
    LEFT JOIN cities c ON u.city_id = c.id
    WHERE (m.user1_id = ? OR m.user2_id = ?) AND u.user_id != ?
";
$stmt_history = $conn->prepare($sql_history);
if (!$stmt_history) {
    die("Ошибка подготовки запроса (история): " . $conn->error);
}

$stmt_history->bind_param("iii", $currentUserId, $currentUserId, $currentUserId);
$stmt_history->execute();
$result_history = $stmt_history->get_result();

$history = [];
while ($row = $result_history->fetch_assoc()) {
    $history[] = $row;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Чат</title>
    <link rel="stylesheet" href="chat.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet">
</head>
<body>

<?php include('fh/header.php'); ?>

<div class="chat-container">

    <!-- Левая панель: Список чатов -->
    <div class="chat-list">
        <h2>Чаты</h2>
        <?php if (empty($chats)): ?>
            <p>У вас пока нет активных чатов.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($chats as $chat): ?>
                    <li>
                        <a href="chat.php?match_id=<?php echo htmlspecialchars($chat['match_id']); ?>">
                            <?php echo htmlspecialchars($chat['other_user_name']); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- Центральная панель: Активный чат -->
    <div class="chat-content">
        <h1>Чат</h1>

        <?php if ($matchId === null): ?>
            <p>Выберите чат слева, чтобы начать общение.</p>
        <?php else: ?>

            <?php if (isset($errorMessage) && $errorMessage): ?>
                <div class="error"><?php echo $errorMessage; ?></div>
            <?php endif; ?>

            <div class="messages">
                <?php if ($messages): ?>
                    <?php foreach ($messages as $message): ?>
                        <div class="message <?php echo ($message['sender_id'] == $currentUserId) ? 'sent' : 'received'; ?>">
                            <span class="username"><?php echo htmlspecialchars($message['username']); ?>:</span>
                            <span class="message-text"><?php echo htmlspecialchars($message['message']); ?></span>
                            <span class="timestamp"><?php echo $message['timestamp']; ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>Пока нет сообщений в этом чате.</p>
                <?php endif; ?>
            </div>

            <div class="message-form">
                <div class="message-form-input-area">
                    <form action="chat.php?match_id=<?php echo htmlspecialchars($matchId); ?>" method="post" >
                        <textarea name="message" placeholder="Сообщение" aria-label="Message"></textarea>
                        <div class="input-buttons">
                            <i class="fas fa-microphone"></i>
                            <i class="fas fa-paperclip"></i>
                        </div>
                        <button type="submit">Отправить</button>
                    </form>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <!-- Правая панель: История знакомств -->
    <div class="chat-history">
        <h2>История знакомств</h2>
        <?php if (empty($history)): ?>
            <p>Здесь пока пусто.</p>
        <?php else: ?>
            <div class="history-cards">
                <?php foreach ($history as $person): ?>
                    <div class="history-card">
                        <?php 
                            $avatarPath = !empty($person['avatar']) ? '' . $person['avatar'] : 'uploads/default.png';
                        ?>
                        <img src="<?php echo htmlspecialchars($avatarPath); ?>" alt="<?php echo htmlspecialchars($person['username']); ?>">
                        <h3><?php echo htmlspecialchars($person['username']); ?></h3>
                        <p><?php echo !empty($person['city_name']) ? htmlspecialchars($person['city_name']) : 'Город не указан'; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php include('fh/footer.php'); ?>

</body>
</html>
