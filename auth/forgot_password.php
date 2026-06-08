<?php
// Path: auth/forgot_password.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config.php";

// Load PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

$pageTitle = "Forgot Password | DrainGuard";
$message = "";
$messageType = ""; 

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = strtolower(trim($_POST["email"] ?? ""));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $messageType = "error";
    } else {
        $sql = "SELECT user_id, user_name FROM users WHERE user_mail = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if (mysqli_num_rows($result) === 1) {
                $user = mysqli_fetch_assoc($result);
                $userName = $user['user_name'];
                
                $token = bin2hex(random_bytes(32));
                $expiryTime = date("Y-m-d H:i:s", strtotime('+1 hour'));
                
                $updateSql = "UPDATE users SET reset_token = ?, reset_time = ? WHERE user_mail = ?";
                $updateStmt = mysqli_prepare($conn, $updateSql);
                mysqli_stmt_bind_param($updateStmt, "sss", $token, $expiryTime, $email);
                
                if (mysqli_stmt_execute($updateStmt)) {
                    if (!function_exists("dg_smtp_is_configured") || !dg_smtp_is_configured()) {
                        error_log("[DrainGuard forgot_password] SMTP configuration is missing.");
                        $message = "Email service is currently unavailable. Please contact the administrator.";
                        $messageType = "error";
                    } else {
                    
                        $mail = new PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host       = DG_SMTP_HOST;
                            $mail->SMTPAuth   = true;
                            $mail->Username   = DG_SMTP_USERNAME;
                            $mail->Password   = DG_SMTP_PASSWORD;
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port       = DG_SMTP_PORT;
                            $mail->SMTPDebug  = 0;

                            $mail->setFrom(DG_SMTP_FROM_EMAIL, DG_SMTP_FROM_NAME);
                            $mail->addAddress($email, $userName);

                            $resetLink = "http://localhost/DrainGuard/auth/reset_password.php?token=" . $token;
                            
                            $mail->isHTML(true);
                            $mail->Subject = 'Password Reset Request - DrainGuard';
                            $mail->Body    = "
                                <div style='font-family: Arial, sans-serif; background-color: #050816; color: #F8FAFC; padding: 40px; border-radius: 10px; text-align: center;'>
                                    <h2 style='color: #31F6E6;'>Password Reset Request</h2>
                                    <p style='color: #A7B4D6;'>Hello <strong>{$userName}</strong>,</p>
                                    <p style='color: #A7B4D6;'>We received a request to reset your password for your DrainGuard account.</p>
                                    <p style='color: #A7B4D6;'>Click the button below to reset your password. This link is valid for 1 hour.</p>
                                    <a href='{$resetLink}' style='display: inline-block; margin-top: 20px; padding: 14px 30px; background-color: #31F6E6; color: #050816; text-decoration: none; border-radius: 50px; font-weight: bold;'>Reset Password</a>
                                    <p style='margin-top: 30px; color: #6B7AA6; font-size: 13px;'>If you did not request this, please ignore this email.</p>
                                </div>
                            ";

                            $mail->send();
                            $message = "If this email is registered, a reset link will be sent shortly.";
                            $messageType = "success";
                        } catch (Exception $e) {
                            error_log("[DrainGuard forgot_password] Mailer Error: " . $mail->ErrorInfo);
                            $message = "Email service is currently unavailable. Please contact the administrator.";
                            $messageType = "error";
                        }
                    }
                } else {
                    $message = "Something went wrong. Please try again.";
                    $messageType = "error";
                }
            } else {
                // Generic message for security (no debug info exposed)
                $message = "If this email is registered, a reset link will be sent shortly.";
                $messageType = "success";
            }
        } else {
            $message = "Something went wrong. Please try again later.";
            $messageType = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/global/forgot_password.css">
    <link rel="stylesheet" href="../css/global/confirm-modal.css">
</head>
<body>

    <div class="forgot-card">
        <div class="brand-icon">
            <i class="bi bi-shield-lock"></i>
        </div>
        
        <h1>Forgot Password</h1>
        <p class="subtitle">Enter your registered email to receive a reset link.</p>

        <?php if ($message !== ""): ?>
            <div class="alert-box <?php echo $messageType === 'error' ? 'error-box' : 'success-box'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Registered Email Address</label>
                <div class="input-wrapper">
                    <i class="bi bi-envelope"></i>
                    <input type="email" name="email" class="form-control" placeholder="example@email.com" required>
                </div>
            </div>

            <button type="submit" class="submit-btn">
                <i class="bi bi-send"></i> Send Reset Link
            </button>
        </form>

        <a href="login.php" class="back-link">
            <i class="bi bi-arrow-left"></i> Back to Login
        </a>
    </div>

<script src="../js/global/confirm-modal.js"></script>
</body>
</html>
