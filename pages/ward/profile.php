<?php
$activePage = "profile";
$pageTitle = "Profile";

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "ward_officer") {
    header("Location: ../../index.php");
    exit();
}

if (!isset($conn) || !$conn) {
    die("Service is temporarily unavailable. Please try again.");
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
        throw new Exception("Unable to load records. Please try again.");
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
        throw new Exception("Unable to load records. Please try again.");
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
| Check profile_image column
|--------------------------------------------------------------------------
*/

$wardOfficerColumns = tableColumns($conn, "ward_officers");

if (!in_array("profile_image", $wardOfficerColumns, true)) {
    die("Profile photo is not available right now.");
}

/*
|--------------------------------------------------------------------------
| Fetch Ward Officer Data
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
            wo.employee_code,
            wo.address,
            wo.office_address,
            wo.designation,
            wo.profile_image,

            w.ward_id,
            w.ward_no,
            w.ward_name

        FROM ward_officers wo

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

    $wardOfficerId = (int)$wardOfficer["ward_officer_id"];
    $wardId = (int)$wardOfficer["assigned_ward_id"];

    $fullName = $wardOfficer["full_name"] ?? "Ward Officer";
    $email = $wardOfficer["user_mail"] ?? "";
    $phone = $wardOfficer["phone_number"] ?? "";
    $employeeCode = $wardOfficer["employee_code"] ?? "";
    $address = $wardOfficer["address"] ?? "";
    $officeAddress = $wardOfficer["office_address"] ?? "";
    $designation = $wardOfficer["designation"] ?: "Ward Officer";

    $wardNo = $wardOfficer["ward_no"] ?? "";
    $wardName = $wardOfficer["ward_name"] ?? "";

    $profileImage = makeImagePath($wardOfficer["profile_image"] ?? "");

    $_SESSION["user_name"] = $fullName;
    $_SESSION["user_role_label"] = "Ward Operations";
} catch (Exception $e) {
    die($e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Upload Profile Image Only
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["form_type"] ?? "") === "photo_update") {
    if (!isset($_FILES["profile_image"]) || $_FILES["profile_image"]["error"] !== UPLOAD_ERR_OK) {
        $errorMessage = "Please select a valid profile photo.";
    } else {
        $file = $_FILES["profile_image"];

        $allowedTypes = ["image/jpeg", "image/jpg", "image/png", "image/webp"];
        $maxSize = 2 * 1024 * 1024;

        $fileType = mime_content_type($file["tmp_name"]);

        if (!in_array($fileType, $allowedTypes, true)) {
            $errorMessage = "Only JPG, PNG, or WEBP image is allowed.";
        } elseif ($file["size"] > $maxSize) {
            $errorMessage = "Profile photo must be less than 2MB.";
        } else {
            try {
                $uploadDirAbsolute = "../../assets/uploads/ward_officers";
                $uploadDirDb = "assets/uploads/ward_officers";

                if (!is_dir($uploadDirAbsolute)) {
                    mkdir($uploadDirAbsolute, 0777, true);
                }

                $extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));

                if ($extension === "jpeg") {
                    $extension = "jpg";
                }

                $newFileName = "ward_" . $currentUserId . "_" . date("Ymd_His") . "." . $extension;
                $absolutePath = $uploadDirAbsolute . "/" . $newFileName;
                $dbPath = $uploadDirDb . "/" . $newFileName;

                if (!move_uploaded_file($file["tmp_name"], $absolutePath)) {
                    throw new Exception("Photo upload failed.");
                }

                $stmt = mysqli_prepare(
                    $conn,
                    "UPDATE ward_officers
                    SET profile_image = ?
                    WHERE user_id = ?"
                );

                if (!$stmt) {
                    throw new Exception("Unable to complete this action. Please try again.");
                }

                mysqli_stmt_bind_param($stmt, "si", $dbPath, $currentUserId);

                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Unable to complete this action. Please try again.");
                }

                mysqli_stmt_close($stmt);

                $successMessage = "Profile photo updated successfully.";
                $profileImage = makeImagePath($dbPath);
            } catch (Exception $e) {
                $errorMessage = $e->getMessage();
            }
        }
    }
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
    $newAddress = trim($_POST["address"] ?? "");

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
                    phone_number = ?,
                    address = ?
                WHERE user_id = ?
            ";

            $updateWardStmt = mysqli_prepare($conn, $updateWardSql);

            if (!$updateWardStmt) {
                throw new Exception("Unable to complete this action. Please try again.");
            }

            mysqli_stmt_bind_param(
                $updateWardStmt,
                "ssssi",
                $newFullName,
                $newEmail,
                $newPhone,
                $newAddress,
                $currentUserId
            );

            if (!mysqli_stmt_execute($updateWardStmt)) {
                throw new Exception("Unable to complete this action. Please try again.");
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
                throw new Exception("Unable to complete this action. Please try again.");
            }

            mysqli_stmt_bind_param($updateUserStmt, "ssi", $newFullName, $newEmail, $currentUserId);

            if (!mysqli_stmt_execute($updateUserStmt)) {
                throw new Exception("Unable to complete this action. Please try again.");
            }

            mysqli_stmt_close($updateUserStmt);

            mysqli_commit($conn);

            $_SESSION["user_name"] = $newFullName;

            $successMessage = "Profile updated successfully.";

            $fullName = $newFullName;
            $email = $newEmail;
            $phone = $newPhone;
            $address = $newAddress;
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
                throw new Exception("Unable to complete this action. Please try again.");
            }

            mysqli_stmt_bind_param($updatePasswordStmt, "si", $hashedPassword, $currentUserId);

            if (!mysqli_stmt_execute($updatePasswordStmt)) {
                throw new Exception("Unable to complete this action. Please try again.");
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
    <title>Profile | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
    <link rel="stylesheet" href="../../css/ward/profile.css">
    <link rel="stylesheet" href="../../css/ward/settings.css">
    <link rel="stylesheet" href="../../css/ward/wardTextFix.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="ward">

<?php include "../../includes/ward/sidebar.php"; ?>

<main class="ward-main">
    <?php include "../../includes/ward/topbar.php"; ?>

    <section class="wp-page">

        <?php if ($successMessage !== ""): ?>
            <div class="wp-alert wp-success">
                <i class="bi bi-check-circle"></i>
                <?= safeText($successMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ""): ?>
            <div class="wp-alert wp-error">
                <i class="bi bi-exclamation-circle"></i>
                <?= safeText($errorMessage); ?>
            </div>
        <?php endif; ?>

        <div class="wp-profile-card">

            <div class="wp-top">
                <div class="wp-avatar-wrap">
                    <div class="wp-avatar">
                        <?php if ($profileImage !== ""): ?>
                            <img src="<?= safeText($profileImage); ?>" alt="Ward Officer Photo">
                        <?php else: ?>
                            <span><?= safeText($profileInitial); ?></span>
                        <?php endif; ?>
                    </div>

                    <form method="POST" action="profile.php" enctype="multipart/form-data" id="photoForm">
                        <input type="hidden" name="form_type" value="photo_update">
                        <input type="file" name="profile_image" id="profileImageInput" accept="image/png,image/jpeg,image/jpg,image/webp" hidden>

                        <button type="button" class="wp-camera-btn" id="changePhotoBtn" title="Change Photo">
                            <i class="bi bi-camera-fill"></i>
                        </button>
                    </form>
                </div>

                <div class="wp-name-box">
                    <h1><?= safeText($fullName); ?></h1>
                    <p><?= safeText($email); ?></p>
                    <span>Ward Officer Account</span>
                </div>
            </div>

            <div class="wp-divider"></div>

            <form method="POST" action="profile.php" class="wp-info-grid">
                <input type="hidden" name="form_type" value="profile_update">
                <div class="wp-info-box">
                    <span>Full Name</span>
                    <input type="text" name="full_name" value="<?= safeText($fullName); ?>" required class="wp-edit-input">
                </div>

                <div class="wp-info-box">
                    <span>Email</span>
                    <input type="email" name="user_mail" value="<?= safeText($email); ?>" required class="wp-edit-input">
                </div>

                <div class="wp-info-box">
                    <span>Phone</span>
                    <input type="text" name="phone_number" value="<?= safeText($phone); ?>" required class="wp-edit-input">
                </div>

                <div class="wp-info-box">
                    <span>Role</span>
                    <input type="text" value="<?= safeText($designation); ?>" readonly class="wp-edit-input readonly">
                </div>

                <div class="wp-info-box">
                    <span>Employee ID</span>
                    <input type="text" value="<?= safeText($employeeCode); ?>" readonly class="wp-edit-input readonly">
                </div>

                <div class="wp-info-box">
                    <span>Assigned Ward</span>
                    <input type="text" value="Ward <?= safeText($wardNo); ?>" readonly class="wp-edit-input readonly">
                </div>

                <div class="wp-info-box wp-info-wide">
                    <span>Address</span>
                    <input type="text" name="address" value="<?= safeText($address); ?>" required class="wp-edit-input">
                </div>

                <div class="wp-info-box wp-info-wide">
                    <span>Office Address</span>
                    <input type="text" value="<?= safeText($officeAddress); ?>" readonly class="wp-edit-input readonly">
                </div>

                <div class="wp-info-wide" style="display: flex; justify-content: flex-end; margin-top: 6px;">
                    <button type="submit" class="wp-edit-btn" style="margin-top: 0;">Save Changes</button>
                </div>
            </form>

        </div>

        <div class="ws-card" style="margin-top: 24px;">
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
                    value="Ward <?= safeText($wardNo); ?>"
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

        <div class="ws-card" style="margin-top: 24px;">
            <div class="ws-card-title">
                <div class="ws-title-icon password">
                    <i class="bi bi-lock"></i>
                </div>
                <h2>Change Password</h2>
            </div>

            <form method="POST" action="profile.php" id="wardPasswordForm">
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

    </section>

</main>

<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/profile.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>