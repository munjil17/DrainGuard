<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "settings";
$pageTitle = "Settings";
$pageParent = "Central Control";
$pageChild = "Settings";

$successMessage = "";
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function cleanUploadPath($path)
{
    $path = str_replace("\\", "/", (string)$path);
    $path = ltrim($path, "/");
    return $path;
}

$userId = (int)($_SESSION["user_id"] ?? 0);

if ($userId <= 0) {
    header("Location: ../../index.php");
    exit();
}

$profile = [
    "user_mail" => "",
    "full_name" => "",
    "phone" => "",
    "designation" => "",
    "profile_picture" => ""
];

/*
|--------------------------------------------------------------------------
| FETCH CENTRAL OFFICER INFO
|--------------------------------------------------------------------------
| Visible settings info comes from central_officers table.
| Profile picture is shown only, not updated from settings.
|--------------------------------------------------------------------------
*/

$profileSql = "
    SELECT
        user_mail,
        full_name,
        phone,
        designation,
        profile_picture
    FROM central_officers
    WHERE user_id = ?
    LIMIT 1
";

$profileStmt = mysqli_prepare($conn, $profileSql);

if ($profileStmt) {
    mysqli_stmt_bind_param($profileStmt, "i", $userId);
    mysqli_stmt_execute($profileStmt);

    $profileResult = mysqli_stmt_get_result($profileStmt);
    $row = $profileResult ? mysqli_fetch_assoc($profileResult) : null;

    if ($row) {
        $profile = array_merge($profile, $row);
    }

    mysqli_stmt_close($profileStmt);
} else {
    $errorMessage = "Profile fetch failed: " . mysqli_error($conn);
}

/*
|--------------------------------------------------------------------------
| UPDATE PROFILE INFO
|--------------------------------------------------------------------------
| Only full_name, email, phone can update from settings.
| Profile picture cannot update from settings.
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["form_type"] ?? "") === "profile_update") {
    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");

    if ($fullName === "" || $email === "" || $phone === "") {
        $errorMessage = "Full name, email, and phone are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = "Please enter a valid email address.";
    } else {
        mysqli_begin_transaction($conn);

        try {
            $emailCheckSql = "
                SELECT user_id
                FROM users
                WHERE user_mail = ?
                AND user_id <> ?
                LIMIT 1
            ";

            $emailCheckStmt = mysqli_prepare($conn, $emailCheckSql);

            if (!$emailCheckStmt) {
                throw new Exception("Email check failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($emailCheckStmt, "si", $email, $userId);
            mysqli_stmt_execute($emailCheckStmt);

            $emailCheckResult = mysqli_stmt_get_result($emailCheckStmt);

            if ($emailCheckResult && mysqli_num_rows($emailCheckResult) > 0) {
                mysqli_stmt_close($emailCheckStmt);
                throw new Exception("This email is already used by another account.");
            }

            mysqli_stmt_close($emailCheckStmt);

            $updateUserSql = "
                UPDATE users
                SET user_name = ?,
                    user_mail = ?
                WHERE user_id = ?
            ";

            $updateUserStmt = mysqli_prepare($conn, $updateUserSql);

            if (!$updateUserStmt) {
                throw new Exception("User update failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($updateUserStmt, "ssi", $fullName, $email, $userId);

            if (!mysqli_stmt_execute($updateUserStmt)) {
                throw new Exception("User update failed: " . mysqli_stmt_error($updateUserStmt));
            }

            mysqli_stmt_close($updateUserStmt);

            $updateCentralSql = "
                UPDATE central_officers
                SET
                    full_name = ?,
                    user_mail = ?,
                    phone = ?
                WHERE user_id = ?
            ";

            $updateCentralStmt = mysqli_prepare($conn, $updateCentralSql);

            if (!$updateCentralStmt) {
                throw new Exception("Central officer update failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($updateCentralStmt, "sssi", $fullName, $email, $phone, $userId);

            if (!mysqli_stmt_execute($updateCentralStmt)) {
                throw new Exception("Central officer update failed: " . mysqli_stmt_error($updateCentralStmt));
            }

            mysqli_stmt_close($updateCentralStmt);

            mysqli_commit($conn);

            $_SESSION["user_name"] = $fullName;
            $_SESSION["user_mail"] = $email;

            $successMessage = "Profile updated successfully.";

            $profile["full_name"] = $fullName;
            $profile["user_mail"] = $email;
            $profile["phone"] = $phone;

        } catch (Exception $e) {
            mysqli_rollback($conn);
            $errorMessage = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| CHANGE PASSWORD
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["form_type"] ?? "") === "password_update") {
    $currentPassword = trim($_POST["current_password"] ?? "");
    $newPassword = trim($_POST["new_password"] ?? "");
    $confirmPassword = trim($_POST["confirm_password"] ?? "");

    if ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {
        $errorMessage = "All password fields are required.";
    } elseif (strlen($newPassword) < 8) {
        $errorMessage = "New password must be at least 8 characters long.";
    } elseif ($newPassword !== $confirmPassword) {
        $errorMessage = "New password and confirm password do not match.";
    } else {
        $passwordSql = "
            SELECT user_password
            FROM users
            WHERE user_id = ?
            LIMIT 1
        ";

        $passwordStmt = mysqli_prepare($conn, $passwordSql);

        if (!$passwordStmt) {
            $errorMessage = "Password check failed: " . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param($passwordStmt, "i", $userId);
            mysqli_stmt_execute($passwordStmt);

            $passwordResult = mysqli_stmt_get_result($passwordStmt);
            $passwordRow = $passwordResult ? mysqli_fetch_assoc($passwordResult) : null;

            mysqli_stmt_close($passwordStmt);

            if (!$passwordRow) {
                $errorMessage = "User account not found.";
            } elseif (!password_verify($currentPassword, $passwordRow["user_password"])) {
                $errorMessage = "Current password is incorrect.";
            } else {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                $updatePasswordSql = "
                    UPDATE users
                    SET user_password = ?
                    WHERE user_id = ?
                ";

                $updatePasswordStmt = mysqli_prepare($conn, $updatePasswordSql);

                if (!$updatePasswordStmt) {
                    $errorMessage = "Password update failed: " . mysqli_error($conn);
                } else {
                    mysqli_stmt_bind_param($updatePasswordStmt, "si", $hashedPassword, $userId);

                    if (mysqli_stmt_execute($updatePasswordStmt)) {
                        $successMessage = "Password changed successfully.";
                    } else {
                        $errorMessage = "Password update failed.";
                    }

                    mysqli_stmt_close($updatePasswordStmt);
                }
            }
        }
    }
}

$displayName = trim($profile["full_name"]) !== "" ? $profile["full_name"] : "Central Officer";
$displayEmail = trim($profile["user_mail"]) !== "" ? $profile["user_mail"] : "";
$displayPhone = trim($profile["phone"]) !== "" ? $profile["phone"] : "";
$displayRole = trim($profile["designation"]) !== "" ? $profile["designation"] : "Central Officer";

$profilePicture = cleanUploadPath($profile["profile_picture"] ?? "");
$profilePictureSrc = $profilePicture !== "" ? "../../" . $profilePicture : "";
$initial = strtoupper(substr($displayName, 0, 1));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>Settings | DrainGuard</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">
    <link rel="stylesheet" href="../../css/central/footer.css">
    <link rel="stylesheet" href="../../css/central/settings.css">
    <link rel="stylesheet" href="../../css/central/centralTextFix.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="settings-page">

            <div class="settings-header">
                <h1>Settings</h1>
                <p>Manage your profile and system preferences</p>
            </div>

            <?php if ($successMessage !== ""): ?>
                <div class="settings-alert success">
                    <i class="bi bi-check-circle"></i>
                    <?php echo safeText($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ""): ?>
                <div class="settings-alert error">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo safeText($errorMessage); ?>
                </div>
            <?php endif; ?>

            <div class="settings-grid">

                <form method="POST" action="settings.php" class="settings-card" id="profileForm">
                    <input type="hidden" name="form_type" value="profile_update">

                    <div class="settings-profile-summary">
                        <div class="settings-profile-avatar">
                            <?php if ($profilePictureSrc !== ""): ?>
                                <img
                                    src="<?php echo safeText($profilePictureSrc); ?>"
                                    alt="Profile Picture"
                                    class="settings-profile-img"
                                >
                            <?php else: ?>
                                <span><?php echo safeText($initial); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="settings-profile-meta">
                            <h2><?php echo safeText($displayName); ?></h2>
                            <p><?php echo safeText($displayEmail); ?></p>
                        </div>
                    </div>

                    <div class="settings-card-title">
                        <div class="settings-title-icon profile">
                            <i class="bi bi-person"></i>
                        </div>

                        <h2>Profile Information</h2>
                    </div>

                    <div class="settings-form-group">
                        <label for="full_name">Full Name</label>
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            value="<?php echo safeText($displayName); ?>"
                            required
                        >
                    </div>

                    <div class="settings-form-group">
                        <label for="email">Email</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?php echo safeText($displayEmail); ?>"
                            required
                        >
                    </div>

                    <div class="settings-form-group">
                        <label for="phone">Phone</label>
                        <input
                            type="text"
                            id="phone"
                            name="phone"
                            value="<?php echo safeText($displayPhone); ?>"
                            required
                        >
                    </div>

                    <div class="settings-form-group">
                        <label for="role">Role</label>
                        <input
                            type="text"
                            id="role"
                            value="<?php echo safeText($displayRole); ?>"
                            readonly
                        >
                    </div>

                    <button type="submit" class="settings-btn primary">
                        Update Profile
                    </button>
                </form>

                <form method="POST" action="settings.php" class="settings-card" id="passwordForm">
                    <input type="hidden" name="form_type" value="password_update">

                    <div class="settings-card-title">
                        <div class="settings-title-icon password">
                            <i class="bi bi-lock"></i>
                        </div>

                        <h2>Change Password</h2>
                    </div>

                    <div class="settings-form-group">
                        <label for="current_password">Current Password</label>
                        <div class="settings-password-wrap">
                            <input
                                type="password"
                                id="current_password"
                                name="current_password"
                                autocomplete="current-password"
                            >

                            <button type="button" class="settings-toggle-password" data-target="current_password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="settings-form-group">
                        <label for="new_password">New Password</label>
                        <div class="settings-password-wrap">
                            <input
                                type="password"
                                id="new_password"
                                name="new_password"
                                autocomplete="new-password"
                            >

                            <button type="button" class="settings-toggle-password" data-target="new_password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>

                        <small class="settings-help" id="passwordHelp">
                            Minimum 8 characters required.
                        </small>
                    </div>

                    <div class="settings-form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <div class="settings-password-wrap">
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                autocomplete="new-password"
                            >

                            <button type="button" class="settings-toggle-password" data-target="confirm_password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>

                        <small class="settings-help" id="confirmHelp"></small>
                    </div>

                    <button type="submit" class="settings-btn warning">
                        Change Password
                    </button>
                </form>

                <div class="settings-card">
                    <div class="settings-card-title">
                        <div class="settings-title-icon notification">
                            <i class="bi bi-bell"></i>
                        </div>

                        <h2>Notification Preferences</h2>
                    </div>

                    <div class="settings-checkbox-list">
                        <label>
                            <input type="checkbox" checked>
                            <span>Email alerts for emergency complaints</span>
                        </label>

                        <label>
                            <input type="checkbox" checked>
                            <span>SMS notifications for red alerts</span>
                        </label>

                        <label>
                            <input type="checkbox" checked>
                            <span>Push notifications for ward escalations</span>
                        </label>

                        <label>
                            <input type="checkbox" checked>
                            <span>Daily summary report via email</span>
                        </label>

                        <label>
                            <input type="checkbox" checked>
                            <span>Weekly performance digest</span>
                        </label>
                    </div>
                </div>

            </div>

        </section>

        <?php include "../../includes/central/footer.php"; ?>

    </main>

</div>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/settings.js"></script>

</body>
</html>