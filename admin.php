<?php
session_start();
include('db.php'); 
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "random_friend";

function connectDB() {
    global $servername, $username, $password, $dbname;
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: avto.php"); 
        exit();
    }
}
function isAdmin() {
    if (isLoggedIn()) {
        $conn = connectDB();
        $user_id = $_SESSION['user_id'];
        $sql = "SELECT role FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $row = $result->fetch_assoc();
            $conn->close();
            return $row['role'] === 'admin';
        }
        $conn->close();
    }
    return false;
}

// *** ОБРАБОТКА ЗАПРОСОВ ***

// 1. Получение списка пользователей
if (isset($_GET['action']) && $_GET['action'] == 'get_users') {
    $conn = connectDB();
    $sql = "SELECT user_id, username, email, is_blocked, last_activity FROM users";
    $result = $conn->query($sql);
    $users = array();
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    header('Content-Type: application/json');
    echo json_encode($users);
    $conn->close();
    exit();
}

// 2. Удаление пользователя 
if (isset($_POST['action']) && $_POST['action'] == 'delete_user') {
    $conn = connectDB();
    $user_id = $_POST['user_id'];
    $sql = "DELETE FROM users WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        echo "success";
    } else {
        echo "error";
    }
    $stmt->close();
    $conn->close();
    exit();
}

// 3. Получение данных пользователя 
if (isset($_GET['action']) && $_GET['action'] == 'get_user') {
    $conn = connectDB();
    $user_id = $_GET['user_id'];
    $sql = "SELECT user_id, username, email, is_blocked FROM users WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        header('Content-Type: application/json');
        echo json_encode($user);
    } else {
        echo json_encode(null);
    }
    $stmt->close();
    $conn->close();
    exit();
}

// 4. Обновление данных пользователя 
if (isset($_POST['action']) && $_POST['action'] == 'update_user') {
    $conn = connectDB();
    $user_id = $_POST['user_id'];
    $username = $_POST['username'];
    $email = $_POST['email'];
    $is_blocked = $_POST['is_blocked'];
    $sql = "UPDATE users SET username = ?, email = ?, is_blocked = ? WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $username, $email, $is_blocked, $user_id);
    if ($stmt->execute()) {
        echo "success";
    } else {
        echo "error";
    }
    $stmt->close();
    $conn->close();
    exit();
}

// *** ГЛАВНАЯ СТРАНИЦА АДМИН-ПАНЕЛИ ***
requireLogin();

if (!isAdmin()) {
    header("Location: profile.php");
    exit();
}
// *** Код для анализа активности ***
$conn = connectDB(); // Устанавливаем соединение для анализа активности
// 1. Общее количество пользователей
$sql_total_users = "SELECT COUNT(*) AS total FROM users";
$result_total_users = $conn->query($sql_total_users);
$total_users = 0;
if ($result_total_users->num_rows > 0) {
    $row = $result_total_users->fetch_assoc();
    $total_users = $row["total"];
}

// 2. Количество активных пользователей (is_active = 1)
$sql_active_users = "SELECT COUNT(*) AS active FROM users WHERE is_active = 1";
$result_active_users = $conn->query($sql_active_users);
$active_users = 0;
if ($result_active_users->num_rows > 0) {
    $row = $result_active_users->fetch_assoc();
    $active_users = $row["active"];
}

// 3. Количество заблокированных пользователей (is_blocked = 1)
$sql_blocked_users = "SELECT COUNT(*) AS blocked FROM users WHERE is_blocked = 1";
$result_blocked_users = $conn->query($sql_blocked_users);
$blocked_users = 0;
if ($result_blocked_users->num_rows > 0) {
    $row = $result_blocked_users->fetch_assoc();
    $blocked_users = $row["blocked"];
}


// 5. Количество новых регистраций за последний месяц
$sql_new_registrations = "SELECT COUNT(*) AS new_registrations FROM users WHERE RegistrationDate >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
$result_new_registrations = $conn->query($sql_new_registrations);

if ($result_new_registrations === false) {
    // Обработка ошибок запроса
    error_log("Ошибка выполнения запроса: " . $conn->error);
    $new_registrations = 0;
} else {
    $new_registrations = 0; // Инициализация по умолчанию
    if ($result_new_registrations->num_rows > 0) {
        $row = $result_new_registrations->fetch_assoc();
        $new_registrations = $row["new_registrations"];
    }
}


// 6. Средняя активность пользователя (кол-во действий на пользователя за последний месяц)
$sql_avg_activity = "SELECT COALESCE(AVG(action_count), 0) AS average_actions_per_user FROM (SELECT COUNT(*) AS action_count FROM user_actions WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 1 MONTH) GROUP BY user_id) AS user_activity;";

$result_avg_activity = $conn->query($sql_avg_activity);

if ($result_avg_activity === false) {
    // Обработка ошибок запроса
    error_log("Ошибка выполнения запроса: " . $conn->error);  // Запись в лог
    $avg_activity = 0; // Или другое значение по умолчанию
} else {
    $row = $result_avg_activity->fetch_assoc();
    $avg_activity = round($row["average_actions_per_user"], 2);
}


?>

<!DOCTYPE html>
<html>
<head>
    <?php include ('fh/header.php')?>
    <title>Админ-панель</title>
    <link rel="stylesheet" href="moder.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
</head>
<body>

<div id="content">
    <!-- Секция модерации пользователей -->
    <div class="section">
        <h2>Модерация пользователей</h2>
        <div id="user-list">
            <table id="user-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Имя пользователя</th>
                        <th>Email</th>
                        <th>Заблокирован</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody id="user-table-body">
                    <!-- Данные о пользователях будут загружены сюда -->
                </tbody>
            </table>
        </div>
    </div>

    <!-- Секция анализа активности -->
    <div class="section">
        <h2>Анализ активности</h2>
        <div id="activity-data">
          <p>Общее количество пользователей: <?php echo $total_users; ?></p>
            <p>Активных пользователей: <?php echo $active_users; ?></p>
            <p>Заблокированных пользователей: <?php echo $blocked_users; ?></p>
            <p>Новых регистраций за последний месяц: <?php echo $new_registrations; ?></p>
            <p>Среднее количество действий на пользователя за месяц: <?php echo $avg_activity; ?></p>
        </div>
    </div>

        </div>
    </div>
</div>

<!-- Модальное окно (осталось без изменений) -->
<div id="user-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); z-index:1000;">
  <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); background-color:#fff; padding:20px; border-radius:5px;">
    <h2>Редактировать пользователя</h2>
    <form id="user-form">
      <input type="hidden" id="edit-user-id" name="user_id">
      <div>
        <label for="edit-username">Имя пользователя:</label>
        <input type="text" id="edit-username" name="username">
      </div>
      <div>
        <label for="edit-email">Email:</label>
        <input type="email" id="edit-email" name="email">
      </div>
      <div>
        <label for="edit-is-blocked">Заблокирован:</label>
        <select id="edit-is-blocked" name="is_blocked">
          <option value="0">Нет</option>
          <option value="1">Да</option>
        </select>
      </div>
      <button type="submit">Сохранить</button>
      <button type="button" id="cancel-edit">Отмена</button>
    </form>
  </div>
</div>


<script>
    $(document).ready(function() {
        // Функция для загрузки пользователей
        function loadUsers() {
            $.ajax({
                url: '?action=get_users',
                method: 'GET',
                dataType: 'json',
                success: function(data) {
                    var userTableBody = $('#user-table-body');
                    userTableBody.empty();

                    if (data.length > 0) {
                        $.each(data, function(index, user) {
                            var row = '<tr>' +
                                '<td>' + user.user_id + '</td>' +
                                '<td>' + user.username + '</td>' +
                                '<td>' + user.email + '</td>' +
                                '<td>' + (user.is_blocked == 1 ? 'Да' : 'Нет') + '</td>' +
                                '<td>' +
                                '<button class="edit-user" data-id="' + user.user_id + '">Редактировать</button>' +
                                '<button class="delete-user" data-id="' + user.user_id + '">Удалить</button>' +
                                '</td>' +
                                '</tr>';
                            userTableBody.append(row);
                        });
                    } else {
                        userTableBody.append('<tr><td colspan="5">Нет пользователей</td></tr>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error("Ошибка при загрузке пользователей:", error);
                    $('#user-list').html('<p>Ошибка при загрузке списка пользователей.</p>');
                }
            });
        }

        // Загрузка пользователей при загрузке страницы
        loadUsers();

        // Обработчик удаления пользователя
        $(document).on('click', '.delete-user', function() {
            var userId = $(this).data('id');
            if (confirm('Вы уверены, что хотите удалить этого пользователя?')) {
                $.ajax({
                    url: '',
                    method: 'POST',
                    data: { action: 'delete_user', user_id: userId },
                    success: function(response) {
                        if (response === 'success') {
                            alert('Пользователь успешно удален.');
                            loadUsers();
                        } else {
                            alert('Ошибка при удалении пользователя.');
                        }
                    },
                    error: function() {
                        alert('Произошла ошибка при отправке запроса на удаление.');
                    }
                });
            }
        });

        // Обработчик редактирования пользователя
        $(document).on('click', '.edit-user', function() {
            var userId = $(this).data('id');
            $.ajax({
                url: '',
                method: 'GET',
                data: { action: 'get_user', user_id: userId },
                dataType: 'json',
                success: function(user) {
                    $('#edit-user-id').val(user.user_id);
                    $('#edit-username').val(user.username);
                    $('#edit-email').val(user.email);
                    $('#edit-is-blocked').val(user.is_blocked);
                    $('#user-modal').show();
                },
                error: function() {
                    alert('Ошибка при загрузке данных пользователя.');
                }
            });
        });

        // Обработчик формы редактирования
        $('#user-form').submit(function(event) {
            event.preventDefault();
            $.ajax({
                url: '',
                method: 'POST',
                data: $(this).serialize() + '&action=update_user',
                success: function(response) {
                    if (response === 'success') {
                        alert('Данные пользователя успешно обновлены.');
                        $('#user-modal').hide(); 
                        loadUsers();
                    } else {
                        alert('Ошибка при обновлении данных пользователя.');
                    }
                },
                error: function() {
                    alert('Произошла ошибка при отправке запроса на обновление.');
                }
            });
        });

        // Закрытие модального окна
        $('#cancel-edit').click(function() {
            $('#user-modal').hide();
        });
    });
</script>

</body>
<?php include ('fh/footer.php')?>
</html>