<?php
session_start();
require 'db.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$current_user_id = $_SESSION['user_id'];

// AJAX обработчик для поиска интересов
if (isset($_GET['term'])) {
    header('Content-Type: application/json');
    
    $term = '%' . trim($_GET['term']) . '%';
    
    try {
        $stmt = $conn->prepare("SELECT id, interest_name FROM interests WHERE interest_name LIKE ? LIMIT 10");
        if (!$stmt) {
            throw new Exception("Ошибка подготовки запроса: " . $conn->error);
        }
        
        $stmt->bind_param("s", $term);
        if (!$stmt->execute()) {
            throw new Exception("Ошибка выполнения запроса: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $suggestions = [];
        
        while ($row = $result->fetch_assoc()) {
            $suggestions[] = [
                'id' => $row['id'],
                'label' => $row['interest_name'],
                'value' => $row['interest_name']
            ];
        }
        
        echo json_encode($suggestions);
        
    } catch (Exception $e) {
        error_log("Ошибка при поиске интересов: " . $e->getMessage());
        echo json_encode(['error' => 'Произошла ошибка при поиске']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Поиск по интересам</title>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://code.jquery.com/ui/1.13.3/jquery-ui.min.js"></script>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.3/themes/base/jquery-ui.css">
    <style>
        body {
            font-family: Arial;
            padding: 50px;
            background: linear-gradient(133deg, #FF6200 0%, #F4561F 11%, #EA4B3B 22%, #DF4157 33%, #D53673 44%, #CB2B8F 56%, #C120AB 67%, #B616C7 78%, #AC0BE3 89%, #A200FF 100%);
            min-height: 100vh;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .search-container {
            background-color: rgba(30, 30, 30, 0.85);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 600px;
            text-align: center;
            color: white;
        }
        .search-box {
            max-width: 400px;
            margin: 0 auto;
        }
        #interestSearch {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #ccc;
            font-size: 16px;
        }
        .ui-autocomplete {
            max-height: 200px;
            overflow-y: auto;
            overflow-x: hidden;
        }
        h2 {
            color: white;
            margin-bottom: 20px;
        }
        .popular-interests {
            margin-top: 20px;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
        }
        .interest-tag {
            background-color: rgba(255, 123, 0, 0.8);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .interest-tag:hover {
            background-color: rgba(255, 123, 0, 1);
        }
    </style>
</head>
<body>
<?php include('fh/header.php'); ?>
<div class="search-container">
    <h2>Найти собеседника по интересу</h2>
    <div class="search-box">
        <input type="text" id="interestSearch" placeholder="Введите интерес...">
    </div>
</div>

<script>
$(function() {
    // Автозаполнение интересов
    $("#interestSearch").autocomplete({
        source: function(request, response) {
            $.ajax({
                url: "interests.php",
                dataType: "json",
                data: {
                    term: request.term
                },
                success: function(data) {
                    if (data.error) {
                        console.error(data.error);
                        response([{ label: "Ошибка поиска", value: "" }]);
                    } else if (data.length === 0) {
                        response([{ label: "Ничего не найдено", value: "" }]);
                    } else {
                        response(data);
                    }
                },
                error: function(xhr, status, error) {
                    console.log("AJAX ошибка:", error);
                    response([{ label: "Ошибка соединения", value: "" }]);
                }
            });
        },
        minLength: 1,
        select: function(event, ui) {
            if (ui.item.id) {
                findChatByInterest(ui.item.id);
            }
        }
    });
    
    // Функция для поиска чата по интересу
    function findChatByInterest(interestId) {
        $.ajax({
            url: "find_chat.php",
            method: "POST",
            dataType: "json",
            contentType: "application/json",
            data: JSON.stringify({ interest_id: interestId }),
            success: function(data) {
                if (data.success) {
                    window.location.href = "chat.php?match_id=" + data.match_id;
                } else {
                    alert(data.message || "Не удалось найти собеседника");
                }
            },
            error: function(xhr, status, error) {
                console.error("Ошибка:", error);
                alert("Произошла ошибка при поиске");
            }
        });
    }
});
</script>
</body>
<?php include('fh/footer.php'); ?>
</html>