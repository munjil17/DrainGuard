<?php
$activePage = 'settings';
$pageTitle = 'Settings';
$pageParent = 'Citizen';
$pageChild = 'Settings';

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'citizen') {
    header("Location: ../../index.php");
    exit();
}

$userId = (int) $_SESSION['user_id'];
$successMessage = "";
$errorMessage = "";

function safeText($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ===============================
   DEFAULT SETTINGS INSERT
================================ */

$defaultSettingSql = "
    INSERT INTO citizen_settings (user_id)
    VALUES (?)
    ON DUPLICATE KEY UPDATE user_id = user_id
";

$defaultStmt = mysqli_prepare($conn, $defaultSettingSql);

if ($defaultStmt) {
    mysqli_stmt_bind_param($defaultStmt, "i", $userId);
    mysqli_stmt_execute($defaultStmt);
    mysqli_stmt_close($defaultStmt);
}

/* ===============================
   UPDATE PROFILE
================================ */

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['form_type'] ?? '') === 'profile_update') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');

    if ($fullName === '' || $email === '') {
        $errorMessage = "Full name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = "Please enter a valid email address.";
    } elseif (!preg_match("/^[a-zA-Z0-9._%+-]+@gmail\.com$/", $email)) {
        $errorMessage = "Only Gmail addresses are allowed.";
    } else {
        $checkSql = "
            SELECT user_id
            FROM users
            WHERE user_mail = ?
            AND user_id != ?
            LIMIT 1
        ";

        $checkStmt = mysqli_prepare($conn, $checkSql);

        if ($checkStmt) {
            mysqli_stmt_bind_param($checkStmt, "si", $email, $userId);
            mysqli_stmt_execute($checkStmt);
            $checkResult = mysqli_stmt_get_result($checkStmt);

            if ($checkResult && mysqli_num_rows($checkResult) > 0) {
                $errorMessage = "This email is already used by another account.";
            } else {
                mysqli_begin_transaction($conn);

                try {
                    $updateUserSql = "
                        UPDATE users
                        SET user_name = ?, user_mail = ?
                        WHERE user_id = ?
                    ";

                    $updateUserStmt = mysqli_prepare($conn, $updateUserSql);

                    if (!$updateUserStmt) {
                        throw new Exception("Unable to complete this action. Please try again.");
                    }

                    mysqli_stmt_bind_param($updateUserStmt, "ssi", $fullName, $email, $userId);
                    mysqli_stmt_execute($updateUserStmt);
                    mysqli_stmt_close($updateUserStmt);

                    $updateCitizenSql = "
                        UPDATE citizens
                        SET phone_number = ?
                        WHERE user_id = ?
                    ";

                    $updateCitizenStmt = mysqli_prepare($conn, $updateCitizenSql);

                    if (!$updateCitizenStmt) {
                        throw new Exception("Unable to complete this action. Please try again.");
                    }

                    mysqli_stmt_bind_param($updateCitizenStmt, "si", $phone, $userId);
                    mysqli_stmt_execute($updateCitizenStmt);
                    mysqli_stmt_close($updateCitizenStmt);

                    mysqli_commit($conn);

                    $_SESSION['user_name'] = $fullName;
                    $_SESSION['user_email'] = $email;

                    $successMessage = "Profile updated successfully.";
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $errorMessage = "Profile update failed.";
                }
            }

            mysqli_stmt_close($checkStmt);
        } else {
            $errorMessage = "Something went wrong. Please try again.";
        }
    }
}

/* ===============================
   CHANGE PASSWORD
================================ */

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['form_type'] ?? '') === 'password_update') {
    $currentPassword = trim($_POST['current_password'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $errorMessage = "Please fill all password fields.";
    } elseif ($newPassword !== $confirmPassword) {
        $errorMessage = "New password and confirm password do not match.";
    } elseif (strlen($newPassword) < 6) {
        $errorMessage = "New password must be at least 6 characters.";
    } else {
        $passSql = "
            SELECT user_password
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ";

        $passStmt = mysqli_prepare($conn, $passSql);

        if ($passStmt) {
            mysqli_stmt_bind_param($passStmt, "i", $userId);
            mysqli_stmt_execute($passStmt);

            $passResult = mysqli_stmt_get_result($passStmt);
            $userPass = mysqli_fetch_assoc($passResult);

            if (!$userPass || !password_verify($currentPassword, $userPass['user_password'])) {
                $errorMessage = "Current password is incorrect.";
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                $updatePassSql = "
                    UPDATE users
                    SET user_password = ?
                    WHERE user_id = ?
                ";

                $updatePassStmt = mysqli_prepare($conn, $updatePassSql);

                if ($updatePassStmt) {
                    mysqli_stmt_bind_param($updatePassStmt, "si", $newHash, $userId);

                    if (mysqli_stmt_execute($updatePassStmt)) {
                        $successMessage = "Password changed successfully.";
                    } else {
                        $errorMessage = "Password change failed.";
                    }

                    mysqli_stmt_close($updatePassStmt);
                } else {
                    $errorMessage = "Password change failed.";
                }
            }

            mysqli_stmt_close($passStmt);
        } else {
            $errorMessage = "Something went wrong. Please try again.";
        }
    }
}

/* ===============================
   UPDATE NOTIFICATION SETTINGS
================================ */

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['form_type'] ?? '') === 'notification_update') {
    $emailNotification = isset($_POST['email_notification']) ? 1 : 0;
    $smsAlert = isset($_POST['sms_alert']) ? 1 : 0;
    $pushNotification = isset($_POST['push_notification']) ? 1 : 0;

    $settingSql = "
        UPDATE citizen_settings
        SET email_notification = ?,
            sms_alert = ?,
            push_notification = ?
        WHERE user_id = ?
    ";

    $settingStmt = mysqli_prepare($conn, $settingSql);

    if ($settingStmt) {
        mysqli_stmt_bind_param($settingStmt, "iiii", $emailNotification, $smsAlert, $pushNotification, $userId);

        if (mysqli_stmt_execute($settingStmt)) {
            $successMessage = "Notification preferences updated successfully.";
        } else {
            $errorMessage = "Notification update failed.";
        }

        mysqli_stmt_close($settingStmt);
    } else {
        $errorMessage = "Notification update failed.";
    }
}

/* ===============================
   FETCH USER DATA
================================ */

$userData = [
    'user_name' => '',
    'user_mail' => '',
    'phone' => ''
];

$userSql = "
    SELECT
        u.user_name,
        u.user_mail,
        c.phone_number AS phone
    FROM users u
    LEFT JOIN citizens c
        ON u.user_id = c.user_id
    WHERE u.user_id = ?
    LIMIT 1
";

$userStmt = mysqli_prepare($conn, $userSql);

if ($userStmt) {
    mysqli_stmt_bind_param($userStmt, "i", $userId);
    mysqli_stmt_execute($userStmt);

    $userResult = mysqli_stmt_get_result($userStmt);

    if ($userResult && mysqli_num_rows($userResult) === 1) {
        $userData = mysqli_fetch_assoc($userResult);
    }

    mysqli_stmt_close($userStmt);
}

/* ===============================
   FETCH SETTINGS
================================ */

$settings = [
    'email_notification' => 1,
    'sms_alert' => 1,
    'push_notification' => 1
];

$settingsSql = "
    SELECT email_notification, sms_alert, push_notification
    FROM citizen_settings
    WHERE user_id = ?
    LIMIT 1
";

$settingsStmt = mysqli_prepare($conn, $settingsSql);

if ($settingsStmt) {
    mysqli_stmt_bind_param($settingsStmt, "i", $userId);
    mysqli_stmt_execute($settingsStmt);

    $settingsResult = mysqli_stmt_get_result($settingsStmt);

    if ($settingsResult && mysqli_num_rows($settingsResult) === 1) {
        $settings = mysqli_fetch_assoc($settingsResult);
    }

    mysqli_stmt_close($settingsStmt);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settings | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <!-- Global CSS -->
    <link rel="stylesheet" href="../../css/global/global.css">

    <!-- Reusable Citizen Layout CSS -->
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">

    <!-- Page CSS -->
    <link rel="stylesheet" href="../../css/citizen/settings.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>
<body class="citizen">

<div class="citizen-layout">

    <?php include "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main main-content">

        <?php include "../../includes/citizen/topbar.php"; ?>

        <section class="st-page">

            <div class="st-header">
                <h1>Settings</h1>
                <p>Manage your account preferences</p>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="st-alert st-success">
                    <i class="bi bi-check-circle"></i>
                    <?php echo safeText($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="st-alert st-error">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo safeText($errorMessage); ?>
                </div>
            <?php endif; ?>

            <div class="st-grid">

                <!-- PROFILE -->
                <form class="st-card" method="POST" action="settings.php">
                    <input type="hidden" name="form_type" value="profile_update">

                    <h2>Profile Information</h2>

                    <div class="st-form-group">
                        <label>Full Name</label>
                        <input
                            type="text"
                            name="full_name"
                            value="<?php echo safeText($userData['user_name'] ?? ''); ?>"
                            required
                        >
                    </div>

                    <div class="st-form-group">
                        <label>Email</label>
                        <input
                            type="email"
                            name="email"
                            value="<?php echo safeText($userData['user_mail'] ?? ''); ?>"
                            required
                        >
                    </div>

                    <div class="st-form-group">
                        <label>Phone</label>
                        <input
                            type="text"
                            name="phone"
                            value="<?php echo safeText($userData['phone'] ?? ''); ?>"
                            placeholder="+880 1XXXXXXXXX"
                        >
                    </div>

                    <button type="submit" class="st-primary-btn">
                        Update Profile
                    </button>
                </form>

                <!-- PASSWORD -->
                <form class="st-card" method="POST" action="settings.php">
                    <input type="hidden" name="form_type" value="password_update">

                    <h2>Change Password</h2>

                    <div class="st-form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>

                    <div class="st-form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" id="newPassword" required>
                    </div>

                    <div class="st-form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" id="confirmPassword" required>
                    </div>

                    <button type="submit" class="st-warning-btn">
                        Change Password
                    </button>
                </form>

                <!-- NOTIFICATIONS -->
                <form class="st-card st-wide-card" method="POST" action="settings.php">
                    <input type="hidden" name="form_type" value="notification_update">

                    <h2>Notification Preferences</h2>

                    <label class="st-check-row">
                        <input
                            type="checkbox"
                            name="email_notification"
                            <?php echo ((int)$settings['email_notification'] === 1) ? 'checked' : ''; ?>
                        >
                        <span>Email notifications for complaint updates</span>
                    </label>

                    <label class="st-check-row">
                        <input
                            type="checkbox"
                            name="sms_alert"
                            <?php echo ((int)$settings['sms_alert'] === 1) ? 'checked' : ''; ?>
                        >
                        <span>SMS alerts for urgent issues</span>
                    </label>

                    <label class="st-check-row">
                        <input
                            type="checkbox"
                            name="push_notification"
                            <?php echo ((int)$settings['push_notification'] === 1) ? 'checked' : ''; ?>
                        >
                        <span>Push notifications for completed work</span>
                    </label>

                    <button type="submit" class="st-primary-btn st-pref-btn">
                        Save Preferences
                    </button>
                </form>

            </div>

        </section>

    </main>

</div>

<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/citizen/settings.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>