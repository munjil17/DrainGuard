<?php
// C:\xampp\htdocs\DrainGuard\hash.php

require_once "config.php";

if (($_GET["key"] ?? "") !== "DG_TEST_2026") {
    http_response_code(403);
    exit("403 Forbidden");
}

$message = "";
$messageType = "";

function safeText($value)
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

function column_exists($conn, $tableName, $columnName)
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ss", $tableName, $columnName);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return ((int)($row["total"] ?? 0)) > 0;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = strtolower(trim($_POST["email"] ?? ""));
    $newPassword = trim($_POST["new_password"] ?? "");
    $confirmPassword = trim($_POST["confirm_password"] ?? "");

    if ($email === "" || $newPassword === "" || $confirmPassword === "") {
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
        $checkSql = "
            SELECT 
                user_id,
                user_mail,
                user_role,
                user_status
            FROM users
            WHERE user_mail = ?
            LIMIT 1
        ";

        $checkStmt = mysqli_prepare($conn, $checkSql);

        if (!$checkStmt) {
            $message = "Email check failed. SQL Error: " . mysqli_error($conn);
            $messageType = "error";
        } else {
            mysqli_stmt_bind_param($checkStmt, "s", $email);
            mysqli_stmt_execute($checkStmt);

            $checkResult = mysqli_stmt_get_result($checkStmt);

            if (!$checkResult || mysqli_num_rows($checkResult) !== 1) {
                $message = "Invalid email. No user found with this Gmail.";
                $messageType = "error";
            } else {
                $user = mysqli_fetch_assoc($checkResult);

                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $hasUpdatedAt = column_exists($conn, "users", "updated_at");

                if ($hasUpdatedAt) {
                    $updateSql = "
                        UPDATE users
                        SET user_password = ?, updated_at = NOW()
                        WHERE user_id = ?
                    ";
                } else {
                    $updateSql = "
                        UPDATE users
                        SET user_password = ?
                        WHERE user_id = ?
                    ";
                }

                $updateStmt = mysqli_prepare($conn, $updateSql);

                if (!$updateStmt) {
                    $message = "Password update prepare failed. SQL Error: " . mysqli_error($conn);
                    $messageType = "error";
                } else {
                    mysqli_stmt_bind_param($updateStmt, "si", $hashedPassword, $user["user_id"]);

                    if (mysqli_stmt_execute($updateStmt)) {
                        $message = "Password updated successfully for " . $user["user_role"] . ".";
                        $messageType = "success";
                    } else {
                        $message = "Failed to update password. SQL Error: " . mysqli_stmt_error($updateStmt);
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

    <title>Update User Password | DrainGuard</title>

    <style>
        * ,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            min-height: 100%;
            margin: 0;
            overflow-x: hidden;
        }

        body {
            min-height: 100vh;
            padding: 18px;
            display: flex;
            justify-content: center;
            align-items: center;
            background:
                radial-gradient(circle at top left, rgba(49, 246, 230, 0.12), transparent 32%),
                linear-gradient(135deg, #050816, #0f1328);
            font-family: Arial, sans-serif;
            color: #ffffff;
        }

        .box {
            width: 100%;
            max-width: 430px;
            background: #1d2440;
            border: 1px solid #2c3757;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.32);
        }

        h2 {
            margin: 0 0 8px;
            font-size: 22px;
            line-height: 1.25;
        }

        .helper {
            margin: 0 0 22px;
            color: #94a3b8;
            font-size: 13px;
            line-height: 1.5;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            color: #cbd5e1;
            font-weight: 700;
        }

        input {
            width: 100%;
            min-width: 0;
            padding: 12px 14px;
            margin-bottom: 16px;
            border-radius: 10px;
            border: 1px solid #33405f;
            background: #12172a;
            color: #ffffff;
            outline: none;
            font-size: 15px;
        }

        input:focus {
            border-color: #31F6E6;
            box-shadow: 0 0 0 3px rgba(49, 246, 230, 0.12);
        }

        input::placeholder {
            color: #94a3b8;
        }

        .btn-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 6px;
        }

        button,
        .back-btn {
            width: 100%;
            min-height: 46px;
            padding: 12px;
            border: none;
            border-radius: 10px;
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
            line-height: 1.45;
            word-break: break-word;
        }

        .msg.success {
            background: rgba(34, 197, 94, .12);
            color: #4ade80;
            border: 1px solid rgba(34, 197, 94, .25);
        }

        .msg.error {
            background: rgba(239, 68, 68, .12);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, .25);
        }

        .warning {
            margin-top: 18px;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(245, 158, 11, 0.10);
            border: 1px solid rgba(245, 158, 11, 0.22);
            color: #fbbf24;
            font-size: 12px;
            line-height: 1.5;
        }

        @media (max-width: 480px) {
            body {
                align-items: flex-start;
                padding: 14px;
            }

            .box {
                padding: 20px 16px;
            }

            .btn-group {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<div class="box">
    <h2>Update User Password</h2>

    <p class="helper">
        Enter an existing user Gmail and set a new hashed password.
    </p>

    <form method="POST" autocomplete="off">
        <label for="email">User Gmail</label>
        <input 
            type="email" 
            id="email" 
            name="email" 
            placeholder="example@gmail.com" 
            value="<?php echo safeText($_POST["email"] ?? ""); ?>"
            required
        >

        <label for="new_password">New Password</label>
        <input 
            type="password" 
            id="new_password" 
            name="new_password" 
            placeholder="Minimum 8 characters" 
            required
        >

        <label for="confirm_password">Confirm Password</label>
        <input 
            type="password" 
            id="confirm_password" 
            name="confirm_password" 
            placeholder="Re-enter new password" 
            required
        >

        <div class="btn-group">
            <button type="submit">Update Password</button>
            <a href="auth/login.php" class="back-btn">Back to Login</a>
        </div>
    </form>

    <?php if ($message !== ""): ?>
        <div class="msg <?php echo safeText($messageType); ?>">
            <?php echo safeText($message); ?>
        </div>
    <?php endif; ?>

    <div class="warning">
        Use this file only for development/admin recovery. Do not keep public password reset without token verification.
    </div>
</div>

</body>
</html>
