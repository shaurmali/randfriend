<?php
session_start();
include('db.php');
include('fh/header.php');
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Разработчики</title>
    <link rel="stylesheet" href="devs.css">
</head>
<body>

<h1 class="title">Сайт разработали</h1>
    
    <div class="gallery">
        <img src="uploads/dec9f595b3f245e080865b7eff8a9314.jpg" class="photo">
        <img src="uploads/5217618149577453963.jpg" class="photo">
        <img src="uploads/5194948298576885040.jpg" class="photo">
        <img src="uploads/5217618149577453907.jpg" class="photo">
    </div>

    <?php include('fh/footer.php'); ?>
</body>
</html>