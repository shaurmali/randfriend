<?
session_start();
include('db.php');

error_reporting(E_ALL);
ini_set('display_errors', 1);

$isLoggedIn = isset($_SESSION['user_id']);
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$user_id = $_SESSION['user_id'] ?? 1;

// Если в сессии указана роль — обрабатываем редирект
if (isset($_SESSION['role'])) 
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin.php");
        exit();
    }
// Получаем интересы пользователя
$user_interests = [];
$stmt = $conn->prepare("
    SELECT i.interest_name 
    FROM user_interests ui 
    JOIN interests i ON ui.interest_id = i.id 
    WHERE ui.user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $user_interests[] = $row['interest_name'];
}
$stmt->close();

// Получаем черный список пользователя

// Получаем пользователей, с которыми был чат и которые не заблокированы
$chat_users = [];
$stmt = $conn->prepare("
    SELECT DISTINCT u.user_id, u.username
    FROM users u
    INNER JOIN matches m ON (u.user_id = m.user1_id OR u.user_id = m.user2_id)
    WHERE (m.user1_id = ? OR m.user2_id = ?)
      AND u.user_id != ?
      AND u.user_id NOT IN (
          SELECT blocked_user_id FROM user_blocklist WHERE blocker_user_id = ?
      )
");
$stmt->bind_param("iiii", $user_id, $user_id, $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $chat_users[] = $row;
}
$stmt->close();
$blacklist = [];
$stmt = $conn->prepare("
    SELECT u.username, u.user_id 
    FROM users u 
    INNER JOIN user_blocklist ub ON u.user_id = ub.blocked_user_id 
    WHERE ub.blocker_user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $blacklist[] = $row;
}
$stmt->close();

// AJAX: Поиск городов
if (isset($_GET['city'])) {
    $term = $conn->real_escape_string($_GET['city']);
    $sql = "SELECT city FROM cities WHERE city LIKE '%$term%' LIMIT 10";
    $res = $conn->query($sql);
    while ($row = $res->fetch_assoc()) {
        echo $row['city'] . "\n";
    }
    exit;
}

// AJAX: Поиск интересов
if (isset($_GET['interest'])) {
    $term = $conn->real_escape_string($_GET['interest']);
    $sql = "SELECT interest_name FROM interests
            WHERE interest_name LIKE '%$term%' AND id NOT IN
            (SELECT interest_id FROM user_interests WHERE user_id = $user_id)
            LIMIT 10";
    $res = $conn->query($sql);
    while ($row = $res->fetch_assoc()) {
        echo $row['interest_name'] . "\n";
    }
    exit;
}

// AJAX: Получить черный список (если нужно отдельным запросом)
// В вашем изначальном коде был запрос по GET ?blacklist, оставлю для совместимости
if (isset($_GET['blacklist'])) {
    header('Content-Type: application/json');
    echo json_encode($blacklist);
    exit;
}

// AJAX: Добавить пользователя в черный список
if (isset($_GET['block_user'])) {
    $blockedUserId = intval($_GET['block_user']);
    if ($blockedUserId != $user_id) {
        $stmt = $conn->prepare("INSERT IGNORE INTO user_blocklist (blocker_user_id, blocked_user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $user_id, $blockedUserId);
        $stmt->execute();
        $stmt->close();
        echo "Пользователь успешно добавлен в черный список.";
    } else {
        echo "Нельзя добавить себя в черный список.";
    }
    exit;
}

// AJAX: Удалить пользователя из черного списка
if (isset($_GET['unblock_user'])) {
    $unblockedUserId = intval($_GET['unblock_user']);
    $stmt = $conn->prepare("DELETE FROM user_blocklist WHERE blocker_user_id = ? AND blocked_user_id = ?");
    $stmt->bind_param("ii", $user_id, $unblockedUserId);
    $stmt->execute();
    $stmt->close();
    echo "Пользователь успешно удален из черного списка.";
    exit;
}

// Обработка сохранения профиля
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $dob = $_POST['dob'] ?? '';
    $city = $_POST['city'] ?? '';
    $password = $_POST['password'] ?? '';
    $interests = $_POST['interests'] ?? [];

    // Обработка аватара
    $avatar_path = null;
    if (!empty($_FILES['avatar']['tmp_name'])) {
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $avatar_name = uniqid() . '_' . basename($_FILES['avatar']['name']);
        $avatar_path = $upload_dir . $avatar_name;
        move_uploaded_file($_FILES['avatar']['tmp_name'], $avatar_path);
    }

    // Получаем текущие данные пользователя
    $stmt = $conn->prepare("SELECT password, city_id, avatar FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    $current_password_hash = $user['password'];
    $current_city_id = $user['city_id'];
    $current_avatar = $user['avatar'];

    if (!empty($name) && !empty($dob)) {
        // Обработка города
        if (empty($city)) {
            $city_id = $current_city_id;
        } else {
            // Проверяем, есть ли город в базе
            $stmt = $conn->prepare("SELECT id FROM cities WHERE city = ?");
            $stmt->bind_param("s", $city);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $city_id = $row['id'];
            } else {
                // Добавляем новый город
                $stmtInsert = $conn->prepare("INSERT INTO cities (city) VALUES (?)");
                $stmtInsert->bind_param("s", $city);
                $stmtInsert->execute();
                $city_id = $stmtInsert->insert_id;
                $stmtInsert->close();
            }
            $stmt->close();
        }

        // Хеширование пароля, если он был изменен
        $password_hash = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : $current_password_hash;

        // Формируем запрос обновления профиля
        $sql = "UPDATE users SET username=?, email=?, birthdate=?, city_id=?, password=?";
        $params = [$name, $email, $dob, $city_id, $password_hash];
        $types = "sssis";

        if ($avatar_path !== null) {
            $sql .= ", avatar=?";
            $params[] = $avatar_path;
            $types .= "s";
        }

        $sql .= " WHERE user_id=?";
        $params[] = $user_id;
        $types .= "i";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();

        // Обновление интересов
        $stmt = $conn->prepare("DELETE FROM user_interests WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        if (!empty($interests)) {
            $stmtSelectInterest = $conn->prepare("SELECT id FROM interests WHERE interest_name = ?");
            $stmtInsertUserInterest = $conn->prepare("INSERT INTO user_interests (user_id, interest_id) VALUES (?, ?)");

            foreach ($interests as $interestName) {
                $stmtSelectInterest->bind_param("s", $interestName);
                $stmtSelectInterest->execute();
                $result = $stmtSelectInterest->get_result();
                if ($row = $result->fetch_assoc()) {
                    $interest_id = $row['id'];
                    $stmtInsertUserInterest->bind_param("ii", $user_id, $interest_id);
                    $stmtInsertUserInterest->execute();
                }
            }
            $stmtSelectInterest->close();
            $stmtInsertUserInterest->close();
        }

        echo "Профиль успешно сохранен.";
    } else {
        echo "Заполните обязательные поля: имя и дата рождения.";
    }
    exit;
}

// Получаем данные пользователя для отображения формы
$stmt = $conn->prepare("SELECT users.*, cities.city as city FROM users LEFT JOIN cities ON users.city_id = cities.id WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

$user['city'] = $user['city'] ?? '';

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактирование профиля</title>
    <link rel="stylesheet" href="profile.css">
</head>
<body>
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['block_user_id'])) {
    $block_user_id = intval($_POST['block_user_id']);
    $stmt = $conn->prepare("INSERT IGNORE INTO user_blocklist (blocker_user_id, blocked_user_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $user_id, $block_user_id);
    $stmt->execute();
    $stmt->close();
}
 include('fh/header.php'); ?>
<main>
    <section class="profile-settings">
        <h2>Редактирование профиля</h2>
        <form id="profile-form" method="POST" enctype="multipart/form-data">
            <img id="avatar-preview" src="<?= htmlspecialchars($user['avatar'] ?: 'default-avatar.png') ?>" alt="avatar" title="Кликните, чтобы выбрать аватар">
            <input type="file" name="avatar" id="avatar-input" style="display:none;" accept="image/*">

            <label for="name">Имя:</label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($user['username']) ?>" required>

            <label for="email">Электронная почта:</label>
            <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>">

            <label for="birthdate">Дата рождения:</label>
            <input type="date" id="birthdate" name="dob" value="<?= htmlspecialchars($user['birthdate']) ?>" required>

            <label for="city">Город:</label>
            <input type="text" id="city" name="city" autocomplete="off" value="<?= htmlspecialchars($user['city']) ?>">

            <label for="password">Новый пароль:</label>
            <input type="password" id="password" name="password">

            <div class="buttons">
                <button type="button" class="close-button" onclick="window.location.reload()">Закрыть</button>
                <button type="submit" class="save-button">Сохранить</button>
            </div>

            <section class="interests" style="margin-top: 20px;">
                <h3>Выберите интересы</h3>
                <div class="search-bar">
                    <input type="text" id="interest-search" placeholder="Поиск по интересам...">
                </div>
                <ul id="interest-suggestions" style="list-style:none; padding-left:0;"></ul>
                <div id="selected-interests">
                    <?php foreach ($user_interests as $interest): ?>
                        <div class="selected-interest">
                            <input type="hidden" name="interests[]" value="<?= htmlspecialchars($interest) ?>">
                            <?= htmlspecialchars($interest) ?>
                            <span onclick="this.parentElement.remove()">✖</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </form>
    </section>

    <section class="blacklist" style="margin-top: 40px;">
        <h2>Черный список</h2>
        <input type="text" id="block-user-search" placeholder="Введите username для блокировки" style="margin-bottom:10px;">
        <button id="block-user-btn">Добавить в черный список</button>
        <ul id="blacklist">
            <?php if (!empty($blacklist)): ?>
                <?php foreach ($blacklist as $blockedUser): ?>
                    <li class="blacklist-item" data-userid="<?= (int)$blockedUser['user_id'] ?>">
                        <?= htmlspecialchars($blockedUser['username']) ?>
                        <a href="#" class="unblock-link">Удалить</a>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <li>Нет заблокированных пользователей.</li>
            <?php endif; ?>
        </ul>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const addedInterests = new Set(<?= json_encode($user_interests) ?>);
    const selectedInterests = document.getElementById('selected-interests');
    const interestInput = document.getElementById('interest-search');
    const interestSuggestions = document.getElementById('interest-suggestions');

    // Поиск интересов
    interestInput.addEventListener('input', () => {
        const query = interestInput.value.trim();
        if (!query) {
            interestSuggestions.innerHTML = '';
            return;
        }

        fetch(`?interest=${encodeURIComponent(query)}`)
            .then(res => res.text())
            .then(data => {
                interestSuggestions.innerHTML = '';
                data.trim().split('\n').forEach(interest => {
                    if (interest && !addedInterests.has(interest)) {
                        const li = document.createElement('li');
                        li.textContent = interest;
                        li.style.cursor = 'pointer';
                        li.style.padding = '5px';
                        li.style.borderBottom = '1px solid #ccc';
                        li.addEventListener('click', () => {
                            if (!addedInterests.has(interest)) {
                                addedInterests.add(interest);
                                const div = document.createElement('div');
                                div.className = 'selected-interest';
                                div.innerHTML = `
                                    <input type="hidden" name="interests[]" value="${interest}">
                                    ${interest} <span onclick="this.parentElement.remove(); addedInterests.delete('${interest}');">✖</span>
                                `;
                                selectedInterests.appendChild(div);
                                interestInput.value = '';
                                interestSuggestions.innerHTML = '';
                            }
                        });
                        interestSuggestions.appendChild(li);
                    }
                });
            });
    });

    // Обработка клика по аватару - открыть диалог выбора файла
    const avatarPreview = document.getElementById('avatar-preview');
    const avatarInput = document.getElementById('avatar-input');
    avatarPreview.addEventListener('click', () => {
        avatarInput.click();
    });

    avatarInput.addEventListener('change', () => {
        const file = avatarInput.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = e => {
                avatarPreview.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    });

    // Обработка отправки формы профиля вместе с интересами
    const profileForm = document.getElementById('profile-form');
    profileForm.addEventListener('submit', function(e) {
        e.preventDefault();

        // Копируем скрытые input с интересами в форму профиля, чтобы они отправились вместе
        const interestsInputs = selectedInterests.querySelectorAll('input[name="interests[]"]');
        profileForm.querySelectorAll('input[name="interests[]"]').forEach(el => el.remove());
        interestsInputs.forEach(input => {
            const clone = input.cloneNode();
            profileForm.appendChild(clone);
        });

        const formData = new FormData(profileForm);
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(res => res.text())
        .then(msg => {
            alert(msg);
            location.reload();
        })
        .catch(err => {
            console.error('Ошибка:', err);
            alert('Произошла ошибка при сохранении');
        });
    });

    // --- Черный список ---

    const blacklistEl = document.getElementById('blacklist');
    const blockUserSearch = document.getElementById('block-user-search');
    const blockUserBtn = document.getElementById('block-user-btn');

    // Функция для добавления пользователя в черный список по username
    blockUserBtn.addEventListener('click', () => {
        const username = blockUserSearch.value.trim();
        if (!username) {
            alert('Введите имя пользователя для блокировки');
            return;
        }

        // Получаем user_id по username через AJAX (можно сделать отдельный endpoint, но здесь простой пример)
        fetch(`search_user.php?username=${encodeURIComponent(username)}`)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.user_id) {
                    if (data.user_id === <?= (int)$user_id ?>) {
                        alert('Нельзя добавить себя в черный список.');
                        return;
                    }
                    // Добавляем пользователя в черный список
                    fetch(`?block_user=${data.user_id}`)
                        .then(res => res.text())
                        .then(msg => {
                            alert(msg);
                            location.reload();
                        });
                } else {
                    alert('Пользователь не найден.');
                }
            })
            .catch(() => alert('Ошибка при поиске пользователя'));
    });

    // Обработка удаления из черного списка
    blacklistEl.addEventListener('click', (e) => {
        if (e.target.classList.contains('unblock-link')) {
            e.preventDefault();
            const li = e.target.closest('li.blacklist-item');
            const blockedUserId = li.getAttribute('data-userid');
            if (!blockedUserId) return;

            fetch(`?unblock_user=${blockedUserId}`)
                .then(res => res.text())
                .then(msg => {
                    alert(msg);
                    location.reload();
                })
                .catch(() => alert('Ошибка при удалении пользователя из черного списка'));
        }
    });

});
</script>
<?php include('fh/footer.php'); ?>
</body>
</html>

