<?php

require_once "../config.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo "invalid_request";
    exit();
}

$email = trim($_POST['email'] ?? '');

if ($email === '') {
    echo "empty";
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "invalid_email";
    exit();
}

if (!preg_match("/^[a-zA-Z0-9._%+-]+@gmail\.com$/", $email)) {
    echo "invalid_gmail";
    exit();
}

$sql = "SELECT user_id FROM users WHERE user_mail = ? AND user_status = 'active'";
$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    echo "error";
    exit();
}

mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

echo (mysqli_num_rows($result) > 0) ? "exists" : "not_found";

mysqli_stmt_close($stmt);
exit();

?>