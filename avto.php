<?php
session_start();
include('db.php');

$error = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "Заполните все поля";
    } else {
        $query = "SELECT user_id, password, role, username, avatar FROM users WHERE email = ?";
        $stmt = $conn->prepare($query);

        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if ($user) {
                if (password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['email'] = $email;
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['avatar'] = $user['avatar'];
                    $_SESSION['role'] = $user['role'];

                    // Перенаправляем в зависимости от роли
                    if ($user['role'] === 'admin') {
                        header("Location: admin.php");
                    } else {
                        header("Location: profile.php");
                    }
                    exit();
                } else {
                    $error = "Неверная почта или пароль";
                }
            } else {
                $error = "Неверная почта или пароль";
            }
            $stmt->close();
        } else {
            $error = "Ошибка подготовки запроса: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Авторизация</title>
    <link rel="stylesheet" href="avto.css">
</head>
<body>

<?php include('fh/header.php'); ?>

<div class="form1">
    <h2>Вход</h2>
    <?php if ($error) : ?>
        <p style="color: red;"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>
    <form method="post">
        <div class="form2">
            <input type="email" name="email" placeholder="Электронная почта">
        </div>
        <div class="form2">
            <input type="password" name="password" placeholder="Пароль">
        </div>
        <button type="submit">Войти</button>
    </form>
</div>

<?php include('fh/footer.php'); ?>

</body>
</html>
