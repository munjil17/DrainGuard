<?php
$activePage = "settings";
$pageTitle = "Settings";

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "ward_officer") {
    header("Location: ../../index.php");
    exit();
}

if (!isset($conn) || !$conn) {
    die("Database connection not found.");
}

$currentUserId = (int)($_SESSION["user_id"] ?? 0);
$successMessage = "";
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function fetchOne($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception("SQL Prepare Failed: " . mysqli_error($conn));
    }

    if ($types !== "" && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function fetchAllRows($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception("SQL Prepare Failed: " . mysqli_error($conn));
    }

    if ($types !== "" && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function tableColumns($conn, $tableName)
{
    $columns = [];
    $safeTable = mysqli_real_escape_string($conn, $tableName);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$safeTable`");

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $columns[] = $row["Field"];
        }
    }

    return $columns;
}

function firstExistingColumn($columns, $possibleColumns)
{
    foreach ($possibleColumns as $column) {
        if (in_array($column, $columns, true)) {
            return $column;
        }
    }

    return null;
}

function makeImagePath($path)
{
    $path = trim((string)$path);

    if ($path === "") {
        return "";
    }

    $path = str_replace("\\", "/", $path);

    if (preg_match("/^https?:\/\//i", $path)) {
        return $path;
    }

    if (str_starts_with($path, "../../")) {
        return $path;
    }

    if (str_starts_with($path, "/")) {
        return $path;
    }

    if (str_starts_with($path, "assets/")) {
        return "../../" . $path;
    }

    if (str_starts_with($path, "uploads/")) {
        return "../../assets/" . $path;
    }

    if (!str_contains($path, "/")) {
        return "../../assets/uploads/ward_officers/" . $path;
    }

    return "../../" . ltrim($path, "/");
}

/*
|--------------------------------------------------------------------------
| Optional profile image column detection
|--------------------------------------------------------------------------
| If ward_officers table has image column, it will show the image.
| If not, default avatar/icon will show.
|--------------------------------------------------------------------------
*/

$wardOfficerColumns = tableColumns($conn, "ward_officers");

$profileImageColumn = firstExistingColumn($wardOfficerColumns, [
    "profile_image",
    "profile_photo",
    "photo",
    "image",
    "avatar",
    "profile_picture"
]);

$imageSelect = $profileImageColumn ? ", wo.`$profileImageColumn` AS profile_image" : ", NULL AS profile_image";

/*
|--------------------------------------------------------------------------
| Fetch Ward Officer information
|--------------------------------------------------------------------------
*/

try {
    $wardOfficer = fetchOne(
        $conn,
        "SELECT
            wo.ward_officer_id,
            wo.user_id,
            wo.assigned_ward_id,
            wo.user_mail,
            wo.full_name,
            wo.phone_number,
            wo.designation
            $imageSelect,

            u.user_password,

            w.ward_id,
            w.ward_no,
            w.ward_name

        FROM ward_officers wo

        INNER JOIN users u
            ON wo.user_id = u.user_id

        INNER JOIN wards w
            ON wo.assigned_ward_id = w.ward_id

        WHERE wo.user_id = ?
        LIMIT 1",
        "i",
        [$currentUserId]
    );

    if (!$wardOfficer) {
        throw new Exception("Ward Officer profile not found.");
    }

    $wardId = (int)$wardOfficer["assigned_ward_id"];
    $wardNo = $wardOfficer["ward_no"] ?? "";
    $wardName = $wardOfficer["ward_name"] ?? "";
    $fullName = $wardOfficer["full_name"] ?? "";
    $email = $wardOfficer["user_mail"] ?? "";
    $phone = $wardOfficer["phone_number"] ?? "";
    $designation = $wardOfficer["designation"] ?: "Ward Officer";
    $profileImage = makeImagePath($wardOfficer["profile_image"] ?? "");

    $_SESSION["user_name"] = $fullName;
    $_SESSION["user_role_label"] = "Ward Operations";
} catch (Exception $e) {
    die($e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Fetch coverage areas of assigned ward
|--------------------------------------------------------------------------
*/

try {
    $coverageAreas = fetchAllRows(
        $conn,
        "SELECT DISTINCT
            a.area_id,
            a.area_name
        FROM locations l
        INNER JOIN areas a
            ON l.area_id = a.area_id
        WHERE l.ward_id = ?
        ORDER BY a.area_name ASC",
        "i",
        [$wardId]
    );
} catch (Exception $e) {
    $coverageAreas = [];
}

/*
|--------------------------------------------------------------------------
| Profile update
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["form_type"] ?? "") === "profile_update") {
    $newFullName = trim($_POST["full_name"] ?? "");
    $newEmail = trim($_POST["user_mail"] ?? "");
    $newPhone = trim($_POST["phone_number"] ?? "");

    if ($newFullName === "" || $newEmail === "" || $newPhone === "") {
        $errorMessage = "Full name, email, and phone are required.";
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = "Please enter a valid email address.";
    } else {
        mysqli_begin_transaction($conn);

        try {
            $emailCheck = fetchOne(
                $conn,
                "SELECT user_id
                FROM users
                WHERE user_mail = ?
                AND user_id <> ?
                LIMIT 1",
                "si",
                [$newEmail, $currentUserId]
            );

            if ($emailCheck) {
                throw new Exception("This email is already used by another user.");
            }

            $updateWardSql = "
                UPDATE ward_officers
                SET
                    full_name = ?,
                    user_mail = ?,
                    phone_number = ?
                WHERE user_id = ?
            ";

            $updateWardStmt = mysqli_prepare($conn, $updateWardSql);

            if (!$updateWardStmt) {
                throw new Exception("Ward officer update failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param(
                $updateWardStmt,
                "sssi",
                $newFullName,
                $newEmail,
                $newPhone,
                $currentUserId
            );

            if (!mysqli_stmt_execute($updateWardStmt)) {
                throw new Exception("Ward officer update failed: " . mysqli_stmt_error($updateWardStmt));
            }

            mysqli_stmt_close($updateWardStmt);

            $updateUserSql = "
                UPDATE users
                SET
                    user_name = ?,
                    user_mail = ?
                WHERE user_id = ?
            ";

            $updateUserStmt = mysqli_prepare($conn, $updateUserSql);

            if (!$updateUserStmt) {
                throw new Exception("User update failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($updateUserStmt, "ssi", $newFullName, $newEmail, $currentUserId);

            if (!mysqli_stmt_execute($updateUserStmt)) {
                throw new Exception("User update failed: " . mysqli_stmt_error($updateUserStmt));
            }

            mysqli_stmt_close($updateUserStmt);

            mysqli_commit($conn);

            $_SESSION["user_name"] = $newFullName;

            $successMessage = "Profile updated successfully.";

            $fullName = $newFullName;
            $email = $newEmail;
            $phone = $newPhone;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $errorMessage = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Password update
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["form_type"] ?? "") === "password_update") {
    $currentPassword = trim($_POST["current_password"] ?? "");
    $newPassword = trim($_POST["new_password"] ?? "");
    $confirmPassword = trim($_POST["confirm_password"] ?? "");

    if ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {
        $errorMessage = "All password fields are required.";
    } elseif (strlen($newPassword) < 8) {
        $errorMessage = "New password must be at least 8 characters.";
    } elseif ($newPassword !== $confirmPassword) {
        $errorMessage = "New password and confirm password do not match.";
    } else {
        try {
            $userPasswordRow = fetchOne(
                $conn,
                "SELECT user_password
                FROM users
                WHERE user_id = ?
                LIMIT 1",
                "i",
                [$currentUserId]
            );

            if (!$userPasswordRow) {
                throw new Exception("User password record not found.");
            }

            $storedPassword = $userPasswordRow["user_password"];

            $passwordMatched = password_verify($currentPassword, $storedPassword);

            if (!$passwordMatched && hash_equals((string)$storedPassword, (string)$currentPassword)) {
                $passwordMatched = true;
            }

            if (!$passwordMatched) {
                throw new Exception("Current password is incorrect.");
            }

            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            $updatePasswordStmt = mysqli_prepare(
                $conn,
                "UPDATE users
                SET user_password = ?
                WHERE user_id = ?"
            );

            if (!$updatePasswordStmt) {
                throw new Exception("Password update failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($updatePasswordStmt, "si", $hashedPassword, $currentUserId);

            if (!mysqli_stmt_execute($updatePasswordStmt)) {
                throw new Exception("Password update failed: " . mysqli_stmt_error($updatePasswordStmt));
            }

            mysqli_stmt_close($updatePasswordStmt);

            $successMessage = "Password changed successfully.";
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
        }
    }
}

$profileInitial = strtoupper(substr(trim($fullName), 0, 1));
if ($profileInitial === "") {
    $profileInitial = "W";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settings | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
    <link rel="stylesheet" href="../../css/ward/footer.css">
    <link rel="stylesheet" href="../../css/ward/settings.css">
    <link rel="stylesheet" href="../../css/ward/wardTextFix.css">
</head>

<body class="ward">

<?php include "../../includes/ward/sidebar.php"; ?>

<main class="ward-main">
    <?php include "../../includes/ward/topbar.php"; ?>

    <section class="ws-page">

        <div class="ws-header">
            <h1>Settings</h1>
            <p>Manage ward officer profile and preferences</p>
        </div>

        <?php if ($successMessage !== ""): ?>
            <div class="ws-alert ws-success">
                <i class="bi bi-check-circle"></i>
                <?= safeText($successMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ""): ?>
            <div class="ws-alert ws-error">
                <i class="bi bi-exclamation-circle"></i>
                <?= safeText($errorMessage); ?>
            </div>
        <?php endif; ?>

        <div class="ws-card">
            <div class="ws-card-title">
                <div class="ws-title-icon profile">
                    <i class="bi bi-person"></i>
                </div>
                <h2>Ward Officer Profile</h2>
            </div>

            <div class="ws-profile-photo-row">
                <div class="ws-profile-photo">
                    <?php if ($profileImage !== ""): ?>
                        <img src="<?= safeText($profileImage); ?>" alt="Ward Officer Profile Photo">
                    <?php else: ?>
                        <span><?= safeText($profileInitial); ?></span>
                    <?php endif; ?>
                </div>

                <div>
                    <h3><?= safeText($fullName); ?></h3>
                </div>
            </div>

            <form method="POST" action="settings.php" id="wardProfileForm">
                <input type="hidden" name="form_type" value="profile_update">

                <div class="ws-form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" value="<?= safeText($fullName); ?>" required>
                </div>

                <div class="ws-form-group">
                    <label>Email</label>
                    <input type="email" name="user_mail" value="<?= safeText($email); ?>" required>
                </div>

                <div class="ws-form-group">
                    <label>Phone</label>
                    <input type="text" name="phone_number" value="<?= safeText($phone); ?>" required>
                </div>

                <div class="ws-form-group">
                    <label>Role</label>
                    <input type="text" value="<?= safeText($designation); ?>" readonly>
                </div>

                <button type="submit" class="ws-profile-btn">
                    Update Profile
                </button>
            </form>
        </div>

        <div class="ws-card">
            <div class="ws-card-title">
                <div class="ws-title-icon ward">
                    <i class="bi bi-geo-alt"></i>
                </div>
                <h2>Ward Information</h2>
            </div>

            <div class="ws-form-group">
                <label>Assigned Ward</label>
                <input
                    type="text"
                    value="Ward <?= safeText($wardNo); ?><?= $wardName ? ' - ' . safeText($wardName) : ''; ?>"
                    readonly
                >
            </div>

            <div class="ws-form-group">
                <label>Coverage Areas</label>

                <div class="ws-area-box">
                    <?php if (!empty($coverageAreas)): ?>
                        <?php foreach ($coverageAreas as $area): ?>
                            <span><?= safeText($area["area_name"]); ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span>No areas found</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="ws-card">
            <div class="ws-card-title">
                <div class="ws-title-icon password">
                    <i class="bi bi-lock"></i>
                </div>
                <h2>Change Password</h2>
            </div>

            <form method="POST" action="settings.php" id="wardPasswordForm">
                <input type="hidden" name="form_type" value="password_update">

                <div class="ws-form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" id="currentPassword" autocomplete="current-password" required>
                </div>

                <div class="ws-form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" id="newPassword" autocomplete="new-password" required>
                </div>

                <div class="ws-form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" id="confirmPassword" autocomplete="new-password" required>
                </div>

                <button type="submit" class="ws-password-btn">
                    Change Password
                </button>
            </form>
        </div>

        <div class="ws-card">
            <div class="ws-card-title">
                <div class="ws-title-icon notify">
                    <i class="bi bi-bell"></i>
                </div>
                <h2>Notification Preferences</h2>
            </div>

            <div class="ws-check-list">
                <label>
                    <input type="checkbox" checked>
                    <span>Email alerts for new complaints</span>
                </label>

                <label>
                    <input type="checkbox" checked>
                    <span>SMS notifications for high priority issues</span>
                </label>

                <label>
                    <input type="checkbox" checked>
                    <span>Push notifications for team updates</span>
                </label>

                <label>
                    <input type="checkbox" checked>
                    <span>Daily summary report</span>
                </label>

                <label>
                    <input type="checkbox" checked>
                    <span>Weekly performance digest</span>
                </label>
            </div>
        </div>

    </section>

    <?php include "../../includes/ward/footer.php"; ?>
</main>

<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/settings.js"></script>

</body>
</html>