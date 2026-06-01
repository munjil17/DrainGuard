<?php
// Path: auth/reset_password.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config.php";

$pageTitle = "Reset Password | DrainGuard";
$message = "";
$messageType = "";
$isValidToken = false;
$userEmail = "";

// ১. URL থেকে টোকেন চেক করা
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $message = "Invalid or missing reset token. Please request a new link.";
    $messageType = "error";
} else {
    // ২. ডেটাবেসে টোকেনটি আছে কি না এবং এক্সপায়ার হয়েছে কি না তা চেক করা
    $sql = "SELECT user_mail, reset_time FROM users WHERE reset_token = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $token);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) === 1) {
            $user = mysqli_fetch_assoc($result);
            $expiryTime = $user['reset_time'];
            
            // সময় চেক (১ ঘন্টা পার হয়েছে কি না)
            if (strtotime($expiryTime) > time()) {
                $isValidToken = true;
                $userEmail = $user['user_mail'];
            } else {
                $message = "Your password reset link has expired. Please request a new one.";
                $messageType = "error";
            }
        } else {
            $message = "Invalid reset token.";
            $messageType = "error";
        }
    }
}

// ৩. ফর্ম সাবমিট হলে পাসওয়ার্ড আপডেট করা
if ($_SERVER["REQUEST_METHOD"] === "POST" && $isValidToken) {
    $newPassword = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";
    
    if (strlen($newPassword) < 8) {
        $message = "Password must be at least 8 characters long.";
        $messageType = "error";
    } elseif ($newPassword !== $confirmPassword) {
        $message = "Passwords do not match! Please try again.";
        $messageType = "error";
    } else {
        // নতুন পাসওয়ার্ড সিকিউরলি হ্যাশ করা
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // ডেটাবেস আপডেট করা এবং টোকেন মুছে ফেলা (যাতে লিংকটি দ্বিতীয়বার কাজ না করে)
        $updateSql = "UPDATE users SET user_password = ?, reset_token = NULL, reset_time = NULL WHERE user_mail = ?";
        $updateStmt = mysqli_prepare($conn, $updateSql);
        
        if ($updateStmt) {
            mysqli_stmt_bind_param($updateStmt, "ss", $hashedPassword, $userEmail);
            if (mysqli_stmt_execute($updateStmt)) {
                $message = "Password updated successfully! You can now log in.";
                $messageType = "success";
                $isValidToken = false; // পাসওয়ার্ড আপডেট হয়ে গেলে ফর্মটি হাইড করে দেবো
            } else {
                $message = "Something went wrong. Please try again.";
                $messageType = "error";
            }
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
    
    <!-- External CSS -->
    <link rel="stylesheet" href="../css/global/forgot_password.css">
</head>
<body>

    <div class="forgot-card">
        <div class="brand-icon">
            <i class="bi bi-key"></i>
        </div>
        
        <h1>Reset Password</h1>
        
        <?php if ($messageType === "success"): ?>
            <p class="subtitle" style="color: #31F6E6;">All done! You are good to go.</p>
        <?php elseif ($isValidToken): ?>
            <p class="subtitle">Enter your new password below.</p>
        <?php else: ?>
            <p class="subtitle" style="color: #FF4D6D;">Action not allowed.</p>
        <?php endif; ?>

        <!-- মেসেজ দেখানো -->
        <?php if ($message !== ""): ?>
            <div class="alert-box <?php echo $messageType === 'error' ? 'error-box' : 'success-box'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- টোকেন ভ্যালিড থাকলে তবেই ফর্মটি দেখাবে -->
        <?php if ($isValidToken): ?>
        <form method="POST">
            <div class="form-group">
                <label>New Password</label>
                <div class="input-wrapper">
                    <i class="bi bi-lock icon-left"></i>
                    <input type="password" name="password" id="newPassword" class="form-control password-input" placeholder="Minimum 8 characters" minlength="8" required>
                    <button type="button" class="toggle-password" data-target="newPassword">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="form-group" style="margin-top: 16px;">
                <label>Confirm Password</label>
                <div class="input-wrapper">
                    <i class="bi bi-lock-fill icon-left"></i>
                    <input type="password" name="confirm_password" id="confirmPassword" class="form-control password-input" placeholder="Retype new password" minlength="8" required>
                    <button type="button" class="toggle-password" data-target="confirmPassword">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="submit-btn">
                <i class="bi bi-check-circle"></i> Update Password
            </button>
        </form>
        <?php endif; ?>

        <a href="login.php" class="back-link">
            <i class="bi bi-arrow-left"></i> Back to Login
        </a>
    </div>

    <!-- Password Show/Hide JavaScript -->
    <script>
        document.querySelectorAll(".toggle-password").forEach(btn => {
            btn.addEventListener("click", function () {
                const targetId = this.getAttribute("data-target");
                const input = document.getElementById(targetId);
                const icon = this.querySelector("i");
                
                if (input.type === "password") {
                    input.type = "text";
                    icon.classList.remove("bi-eye");
                    icon.classList.add("bi-eye-slash");
                } else {
                    input.type = "password";
                    icon.classList.remove("bi-eye-slash");
                    icon.classList.add("bi-eye");
                }
            });
        });
    </script>
</body>
</html>