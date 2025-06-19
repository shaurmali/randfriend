<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

    $servername = 'localhost';
    $username = 'root';
    $password = '';
    $dbname='random_friend';
    $conn = mysqli_connect($servername, $username, $password, $dbname);
    if (!$conn){
        die('Connection Fialed'. mysqli_connect_error());
    } else{
    } ?>