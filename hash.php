<?php

require_once "config.php";

$message = "";
$messageType = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST['email'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if ($email === '' || $newPassword === '' || $confirmPassword === '') {
        $message = "Please fill up all fields.";
        $messageType = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format.";
        $messageType = "error";
    } elseif (!preg_match("/^[a-zA-Z0-9._%+-]+@gmail\.com$/", $email)) {
        $message = "Only Gmail addresses are allowed.";
        $messageType = "error";
    } elseif (strlen($newPassword) < 8) {
        $message = "Password must be at least 8 characters.";
        $messageType = "error";
    } elseif ($newPassword !== $confirmPassword) {
        $message = "New password and confirm password do not match.";
        $messageType = "error";
    } else {

        $checkSql = "SELECT user_id FROM users WHERE user_mail = ?";
        $checkStmt = mysqli_prepare($conn, $checkSql);

        if (!$checkStmt) {
            $message = "Something went wrong while checking email.";
            $messageType = "error";
        } else {
            mysqli_stmt_bind_param($checkStmt, "s", $email);
            mysqli_stmt_execute($checkStmt);

            $checkResult = mysqli_stmt_get_result($checkStmt);

            if (mysqli_num_rows($checkResult) !== 1) {
                $message = "Invalid email. No user found with this email.";
                $messageType = "error";
            } else {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                $updateSql = "UPDATE users SET user_password = ? WHERE user_mail = ?";
                $updateStmt = mysqli_prepare($conn, $updateSql);

                if (!$updateStmt) {
                    $message = "Something went wrong while preparing update.";
                    $messageType = "error";
                } else {
                    mysqli_stmt_bind_param($updateStmt, "ss", $hashedPassword, $email);

                    if (mysqli_stmt_execute($updateStmt)) {
                        $message = "Password updated successfully.";
                        $messageType = "success";
                    } else {
                        $message = "Failed to update password.";
                        $messageType = "error";
                    }

                    mysqli_stmt_close($updateStmt);
                }
            }

            mysqli_stmt_close($checkStmt);
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update User Password</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #0f1328;
            font-family: Arial, sans-serif;
            color: #ffffff;
        }

        .box {
            width: 100%;
            max-width: 420px;
            background: #1d2440;
            border: 1px solid #2c3757;
            border-radius: 14px;
            padding: 24px;
        }

        h2 {
            margin: 0 0 20px;
            font-size: 22px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            color: #cbd5e1;
        }

        input {
            width: 100%;
            padding: 12px 14px;
            margin-bottom: 16px;
            border-radius: 10px;
            border: 1px solid #33405f;
            background: #12172a;
            color: #ffffff;
            outline: none;
        }

        input::placeholder {
            color: #94a3b8;
        }

        .btn-group {
            display: flex;
            gap: 12px;
            margin-top: 6px;
        }

        button,
        .back-btn {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 10px;
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            display: inline-block;
        }

        button {
            background: #22c55e;
        }

        .back-btn {
            background: #3b82f6;
        }

        .msg {
            margin-top: 16px;
            font-size: 14px;
            padding: 10px 12px;
            border-radius: 8px;
        }

        .msg.success {
            background: rgba(34, 197, 94, .12);
            color: #4ade80;
        }

        .msg.error {
            background: rgba(239, 68, 68, .12);
            color: #f87171;
        }
    </style>
</head>
<body>

<div class="box">
    <h2>Update User Password</h2>

    <form method="POST">
        <label for="email">User Gmail</label>
        <input type="email" id="email" name="email" placeholder="Enter Gmail address" required>

        <label for="new_password">New Password</label>
        <input type="text" id="new_password" name="new_password" placeholder="Minimum 8 characters" required>

        <label for="confirm_password">Confirm Password</label>
        <input type="text" id="confirm_password" name="confirm_password" placeholder="Re-enter new password" required>

        <div class="btn-group">
            <button type="submit">Update Password</button>
            <a href="auth/login.php" class="back-btn">Back to Login</a>
        </div>
    </form>

    <?php if ($message !== "") { ?>
        <div class="msg <?php echo htmlspecialchars($messageType); ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php } ?>
</div>

</body>
</html>