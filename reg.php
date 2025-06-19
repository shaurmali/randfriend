<?php
session_start();

include('db.php');
include('fh/header.php');

$upload_dir = 'uploads/';
$default_avatar = 'https://i.pinimg.com/474x/bb/d3/c5/bbd3c576550df36d8cc82ff56abd714b.jpg'; 

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true); 
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $date = $_POST['date'];
    $city_name = $_POST['city']; 
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $avatar_filename = $default_avatar;

    if (empty($name) || empty($email) || empty($date) || empty($city_name) || empty($password) || empty($confirm_password)) {
        echo "Заполните все поля";
    } else {
        if ($password !== $confirm_password) {
            echo "Пароли не совпадают";
        } elseif (!preg_match('/^[a-zA-Zа-яА-Я0-9\s]+$/u', $name)) {
            echo "Имя может содержать только буквы и цифры";
        } else {
            // Handle file upload
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['avatar']['tmp_name'];
                $filename = $_FILES['avatar']['name'];
                $fileSize = $_FILES['avatar']['size'];
                $fileType = $_FILES['avatar']['type'];
                $filenameCmps = explode(".", $filename);
                $fileExtension = strtolower(end($filenameCmps));

                $newFilename = md5(time() . $filename) . '.' . $fileExtension;
                $destFilePath = $upload_dir . $newFilename;

                $allowedfileExtensions = array('jpg', 'jpeg', 'png', 'gif');

                if (in_array($fileExtension, $allowedfileExtensions)) {
                    if ($fileSize < 2000000) {
                        if (move_uploaded_file($fileTmpPath, $destFilePath)) {
                            $avatar_filename = $destFilePath;
                        } else {
                            echo "Ошибка при перемещении загруженного файла.";
                        }
                    } else {
                        echo "Файл слишком большой. Максимальный размер: 2MB.";
                    }
                } else {
                    echo "Недопустимый тип файла. Разрешены: jpg, jpeg, png, gif.";
                }
            }

            // Check if email exists
            $check_sql = "SELECT email FROM users WHERE email = ?";
            if ($check_stmt = $conn->prepare($check_sql)) {
                $check_stmt->bind_param("s", $email);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    echo "Пользователь с такой почтой уже существует";
                } else {
                    // First, find or create the city
                    $city_id = null;
                    $city_sql = "SELECT id FROM cities WHERE city = ?";
                    if ($city_stmt = $conn->prepare($city_sql)) {
                        $city_stmt->bind_param("s", $city_name);
                        $city_stmt->execute();
                        $city_result = $city_stmt->get_result();
                        
                        if ($city_result->num_rows > 0) {
                            $row = $city_result->fetch_assoc();
                            $city_id = $row['id'];
                        } else {
                            // City doesn't exist, create it
                            $insert_city_sql = "INSERT INTO cities (city) VALUES (?)";
                            if ($insert_city_stmt = $conn->prepare($insert_city_sql)) {
                                $insert_city_stmt->bind_param("s", $city_name);
                                if ($insert_city_stmt->execute()) {
                                    $city_id = $conn->insert_id;
                                } else {
                                    echo "Ошибка при добавлении города: " . $insert_city_stmt->error;
                                }
                                $insert_city_stmt->close();
                            }
                        }
                        $city_stmt->close();
                    }

                    if ($city_id) {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        
                        // Insert new user with city_id
                        $insert_sql = "INSERT INTO users (username, email, birthdate, city_id, password, avatar, is_active, role) 
                                      VALUES (?, ?, ?, ?, ?, ?, 1, 'user')";
                        if ($insert_stmt = $conn->prepare($insert_sql)) {
                            $insert_stmt->bind_param("sssiss", $name, $email, $date, $city_id, $hashed_password, $avatar_filename);
                            
                            if ($insert_stmt->execute()) {
                                $user_id = $conn->insert_id;
                                $_SESSION['user_id'] = $user_id;
                                $_SESSION['avatar'] = $avatar_filename;
                                $_SESSION['username'] = $name;
                                $_SESSION['role'] = 'user';
                                
                                header("Location: profile.php");
                                exit();
                            } else {
                                echo "Ошибка при регистрации: " . $insert_stmt->error;
                            }
                            $insert_stmt->close();
                        } else {
                            echo "Ошибка подготовки запроса: " . $conn->error;
                        }
                    } else {
                        echo "Ошибка при обработке города";
                    }
                }
                $check_stmt->close();
            } else {
                echo "Ошибка подготовки запроса: " . $conn->error;
            }
        }
    }
}

$avatar = isset($_SESSION['avatar']) ? $_SESSION['avatar'] : $default_avatar;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Регистрация</title>
    <link rel="stylesheet" href="reg.css">
    <style>
        .avatar-upload {
            position: relative;
            max-width: 205px;
            margin: 50px auto;
        }
        .avatar-upload .avatar-edit {
            position: absolute;
            right: 12px;
            z-index: 1;
            top: 10px;
        }

        .avatar-upload .avatar-edit input {
            display: none;
        }

        .avatar-upload .avatar-edit input + label {
            display: inline-block;
            width: 34px;
            height: 34px;
            margin-bottom: 0;
            border-radius: 100%;
            background: #FFFFFF;
            border: 1px solid transparent;
            box-shadow: 0px 2px 4px 0px rgba(0,0,0,0.12);
            cursor: pointer;
            font-weight: normal;
            transition: all .3s ease-in-out;
        }

        .avatar-upload .avatar-edit input + label:hover {
            background: #f1f1f1;
            border-color: #d6d6d6;
        }

        .avatar-upload .avatar-preview {
            width: 192px;
            height: 192px;
            position: relative;
            border-radius: 100%;
            border: 6px solid #F8F8F8;
            box-shadow: 0px 2px 4px 0px rgba(0,0,0,0.1);
        }

        .avatar-upload .avatar-preview > div {
            width: 100%;
            height: 100%;
            border-radius: 100%;
            background-size: cover;
            background-repeat: no-repeat;
            background-position: center;
        }
    </style>
</head>
<body>
<form action='reg.php' method='post' enctype="multipart/form-data">
    <div class="form1">
        <h2>Регистрация</h2>
         <div class="avatar-upload">
            <div class="avatar-edit">
                <input type='file' id="imageUpload" name="avatar" accept=".png, .jpg, .jpeg" />
                <label for="imageUpload"></label>
            </div>
            <div class="avatar-preview">
                <div id="imagePreview" style="background-image: url(<?php echo htmlspecialchars($avatar); ?>);">
                </div>
            </div>
        </div>
        <div class="form2">
        <div class="error-message" style="display: none;">Заполните все поля</div>
            <label for="reg-name"></label>
            <input type="text" name="name" placeholder="Имя" required>
        </div>
        <div class="form2">
            <label for="reg-email"></label>
            <input type="email" name="email" placeholder="Электронная почта" required>
        </div>
        <div class="form2">
            <label for="reg-birthdate"></label>
            <input type="date" name="date" placeholder="Дата рождения" required>
        </div>
        <div class="form2">
            <label for="reg-city"></label>
            <input type="text" name="city" placeholder="Город" required>
        </div>
        <div class="form2">
            <label for="reg-password"></label>
            <input type="password" name="password" placeholder="Пароль" required>
        </div>
        <div class="form2">
            <label for="reg-confirm-password"></label>
            <input type="password" name="confirm_password" placeholder="Повторите пароль" required>
        </div>
        <button type="submit">Зарегистрироваться</button>
        <p>Уже есть аккаунт? <a href="avto.php" style="color: #0000EE; text-decoration:none;">Войти</a></p>
    </div>
</form>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
<script>
    function readURL(input) {
        if (input.files && input.files[0]) { 
            var reader = new FileReader();
            reader.onload = function(e) {
                $('#imagePreview').css('background-image', 'url('+e.target.result +')');
                $('#imagePreview').hide();
                $('#imagePreview').fadeIn(650);
            }
            reader.readAsDataURL(input.files[0]);
        }
    }
    $("#imageUpload").change(function() {
        readURL(this);
    });
</script>
<?php include('fh/footer.php'); ?>
</body>
</html>