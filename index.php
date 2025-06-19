<?php
session_start();
include('db.php');
$isLoggedIn = isset($_SESSION['user_id']);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="css/index.css">
    <title>RandFriend</title>
</head>
<body>
<?php include('fh/header.php'); ?>

<div class="logo">RandFriend</div>
<div class="slogan">ПРЕВРАТИ СЛУЧАЙНОСТЬ В ДРУЖБУ.<br><span>ЗДЕСЬ И СЕЙЧАС.</span></div>
<div class="headline">ВСТРЕЧАЙ НЕИЗВЕДАННОЕ: НОВЫЙ ДРУГ В ОДИН КЛИК.</div>

<button class="btn" onclick="handleClick()" id="findFriendBtn">Найти друга</button>

<script>
    function handleClick() {
        const btn = document.getElementById('findFriendBtn');
        btn.disabled = true; 

        <?php if ($isLoggedIn): ?>
        fetch('find_friend.php')
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success' && data.match_id) {
                    window.location.href = `chat.php?match_id=${data.match_id}`;
                } else {
                    alert(data.message || 'Ошибка при поиске друга');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Произошла ошибка');
            })
            .finally(() => {
                btn.disabled = false; 
            });
        <?php else: ?>
        window.location.href = 'reg.php';
        <?php endif; ?>
    }
</script>

<?php include('fh/footer.php'); ?>
</body>
</html>