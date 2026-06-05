<?php
$activePage = 'profile';
$pageTitle = 'Profile';
$pageParent = 'Citizen';
$pageChild = 'Profile';

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

function tableColumnExists($conn, $tableName, $columnName) {
    $sql = "
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ss", $tableName, $columnName);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $exists = ($result && mysqli_num_rows($result) > 0);

    mysqli_stmt_close($stmt);

    return $exists;
}

function firstExistingColumn($conn, $tableName, $columns) {
    foreach ($columns as $column) {
        if (tableColumnExists($conn, $tableName, $column)) {
            return $column;
        }
    }

    return null;
}

/*
    citizens table column detection
    This prevents "Unknown column c.phone" / "Unknown column c.profile_photo" fatal errors.
*/

$phoneColumn = firstExistingColumn($conn, "citizens", [
    "phone_number",
    "phone",
    "citizen_phone",
    "contact_number"
]);

$photoColumn = firstExistingColumn($conn, "citizens", [
    "profile_photo",
    "profile_image",
    "citizen_photo",
    "citizen_image",
    "photo",
    "image"
]);

$addressColumns = [
    "present_address" => tableColumnExists($conn, "citizens", "present_address"),
    "permanent_address" => tableColumnExists($conn, "citizens", "permanent_address"),
    "address" => tableColumnExists($conn, "citizens", "address"),
    "house_no" => tableColumnExists($conn, "citizens", "house_no"),
    "road_no" => tableColumnExists($conn, "citizens", "road_no"),
    "area_name" => tableColumnExists($conn, "citizens", "area_name"),
    "city_name" => tableColumnExists($conn, "citizens", "city_name")
];

/* ===============================
   HANDLE PROFILE PHOTO UPLOAD
================================ */

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['form_type'] ?? '') === "photo_update") {

    if (!$photoColumn) {
        $errorMessage = "No photo column found in citizens table.";
    } elseif (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] === UPLOAD_ERR_NO_FILE) {
        $errorMessage = "Please select a photo.";
    } elseif ($_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = "Photo upload failed. Please try again.";
    } else {
        $allowedExtensions = ["jpg", "jpeg", "png"];
        $originalName = $_FILES['profile_photo']['name'];
        $tmpName = $_FILES['profile_photo']['tmp_name'];
        $fileSize = $_FILES['profile_photo']['size'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions)) {
            $errorMessage = "Only JPG, JPEG, and PNG images are allowed.";
        } elseif ($fileSize > 5 * 1024 * 1024) {
            $errorMessage = "Photo size must be less than 5MB.";
        } else {
            $uploadDir = __DIR__ . "/../../assets/uploads/citizens/";

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            if (!is_writable($uploadDir)) {
                $errorMessage = "Citizen upload folder is not writable.";
            } else {
                $newFileName = "citizen_" . $userId . "_" . time() . "." . $extension;
                $targetFile = $uploadDir . $newFileName;

                if (move_uploaded_file($tmpName, $targetFile)) {
                    $dbPhotoPath = "assets/uploads/citizens/" . $newFileName;

                    $updatePhotoSql = "
                        UPDATE citizens
                        SET {$photoColumn} = ?
                        WHERE user_id = ?
                    ";

                    $photoStmt = mysqli_prepare($conn, $updatePhotoSql);

                    if ($photoStmt) {
                        mysqli_stmt_bind_param($photoStmt, "si", $dbPhotoPath, $userId);

                        if (mysqli_stmt_execute($photoStmt)) {
                            $successMessage = "Profile photo updated successfully.";
                        } else {
                            $errorMessage = "Failed to update profile photo.";
                        }

                        mysqli_stmt_close($photoStmt);
                    } else {
                        $errorMessage = "Failed to update profile photo.";
                    }
                } else {
                    $errorMessage = "Failed to save uploaded photo.";
                }
            }
        }
    }
}

/* ===============================
   HANDLE PROFILE & PASSWORD UPDATE
================================ */

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['form_type'] ?? '') === 'profile_update') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $street_village = trim($_POST['street_village'] ?? '');
    $union_area = trim($_POST['union_area'] ?? '');
    $upazila_thana = trim($_POST['upazila_thana'] ?? '');
    $district = trim($_POST['district'] ?? '');
    $division = trim($_POST['division'] ?? '');

    if ($fullName === '' || $email === '') {
        $errorMessage = "Full name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = "Invalid email format.";
    } elseif (!preg_match("/^[a-zA-Z0-9._%+-]+@gmail\.com$/", $email)) {
        $errorMessage = "Only Gmail addresses are allowed.";
    } else {
        $checkSql = "SELECT user_id FROM users WHERE user_mail = ? AND user_id != ? LIMIT 1";
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
                    $updateUserSql = "UPDATE users SET user_mail = ? WHERE user_id = ?";
                    $updateUserStmt = mysqli_prepare($conn, $updateUserSql);
                    mysqli_stmt_bind_param($updateUserStmt, "si", $email, $userId);
                    mysqli_stmt_execute($updateUserStmt);
                    mysqli_stmt_close($updateUserStmt);

                    $updateCitizenSql = "UPDATE citizens SET phone_number = ?, street_village = ?, union_area = ?, upazila_thana = ?, district = ?, division = ?";
                    $bindTypes = "ssssss";
                    $bindParams = [&$phone, &$street_village, &$union_area, &$upazila_thana, &$district, &$division];
                    
                    if (tableColumnExists($conn, "citizens", "full_name")) {
                        $updateCitizenSql .= ", full_name = ?";
                        $bindTypes .= "s";
                        $bindParams[] = &$fullName;
                    }
                    
                    $updateCitizenSql .= " WHERE user_id = ?";
                    $bindTypes .= "i";
                    $bindParams[] = &$userId;

                    $updateCitizenStmt = mysqli_prepare($conn, $updateCitizenSql);
                    $bindParamsArray = array_merge(array($updateCitizenStmt, $bindTypes), $bindParams);
                    call_user_func_array('mysqli_stmt_bind_param', $bindParamsArray);
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
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['form_type'] ?? '') === 'password_update') {
    $currentPassword = trim($_POST['old_password'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $errorMessage = "Please fill all password fields.";
    } elseif ($newPassword !== $confirmPassword) {
        $errorMessage = "New password and confirm password do not match.";
    } elseif (strlen($newPassword) < 6) {
        $errorMessage = "New password must be at least 6 characters.";
    } else {
        $passSql = "SELECT user_password FROM users WHERE user_id = ? LIMIT 1";
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
                $updatePassSql = "UPDATE users SET user_password = ? WHERE user_id = ?";
                $updatePassStmt = mysqli_prepare($conn, $updatePassSql);
                mysqli_stmt_bind_param($updatePassStmt, "si", $newHash, $userId);
                if (mysqli_stmt_execute($updatePassStmt)) {
                    $successMessage = "Password changed successfully.";
                } else {
                    $errorMessage = "Password change failed.";
                }
                mysqli_stmt_close($updatePassStmt);
            }
            mysqli_stmt_close($passStmt);
        }
    }
}

/* ===============================
   FETCH USER PROFILE DATA
================================ */

$selectCitizenFields = [];

if ($phoneColumn) {
    $selectCitizenFields[] = "c.{$phoneColumn} AS phone_number";
} else {
    $selectCitizenFields[] = "NULL AS phone_number";
}

if ($photoColumn) {
    $selectCitizenFields[] = "c.{$photoColumn} AS profile_photo";
} else {
    $selectCitizenFields[] = "NULL AS profile_photo";
}

if (tableColumnExists($conn, "citizens", "division")) {
    $selectCitizenFields[] = "c.division";
} else {
    $selectCitizenFields[] = "NULL AS division";
}

if (tableColumnExists($conn, "citizens", "district")) {
    $selectCitizenFields[] = "c.district";
} else {
    $selectCitizenFields[] = "NULL AS district";
}

if (tableColumnExists($conn, "citizens", "upazila_thana")) {
    $selectCitizenFields[] = "c.upazila_thana";
} else {
    $selectCitizenFields[] = "NULL AS upazila_thana";
}

if (tableColumnExists($conn, "citizens", "union_area")) {
    $selectCitizenFields[] = "c.union_area";
} else {
    $selectCitizenFields[] = "NULL AS union_area";
}

if (tableColumnExists($conn, "citizens", "street_village")) {
    $selectCitizenFields[] = "c.street_village";
} else {
    $selectCitizenFields[] = "NULL AS street_village";
}

$citizenSelectSql = implode(",\n        ", $selectCitizenFields);

$citizenSelectSql .= ",\n        c.full_name AS citizen_full_name";

$userData = [
    "user_name" => "",
    "citizen_full_name" => "",
    "user_mail" => "",
    "user_role" => "citizen",
    "phone_number" => "",
    "profile_photo" => "",
    "division" => "",
    "district" => "",
    "upazila_thana" => "",
    "union_area" => "",
    "street_village" => "",
];

$sql = "
    SELECT
    u.user_name,
    u.user_mail,
    u.user_role,
    u.user_status,
    {$citizenSelectSql}
    FROM users u
    LEFT JOIN citizens c
        ON u.user_id = c.user_id
    WHERE u.user_id = ?
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $sql);

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if ($result && mysqli_num_rows($result) === 1) {
        $userData = mysqli_fetch_assoc($result);
    }

    mysqli_stmt_close($stmt);
}

$profilePhoto = trim((string)($userData['profile_photo'] ?? ''));

if ($profilePhoto !== '') {
    $profilePhotoPath = "../../" . $profilePhoto;
} else {
    $profilePhotoPath = "";
}

$createdAt = !empty($userData['created_at'])
    ? date("M d, Y", strtotime($userData['created_at']))
    : "N/A";

$fullAddressParts = [];

if (!empty($userData['street_village'])) {
    $fullAddressParts[] = trim($userData['street_village']);
}

if (!empty($userData['union_area'])) {
    $fullAddressParts[] = trim($userData['union_area']);
}

if (!empty($userData['upazila_thana'])) {
    $fullAddressParts[] = trim($userData['upazila_thana']);
}

if (!empty($userData['district'])) {
    $fullAddressParts[] = trim($userData['district']);
}

if (!empty($userData['division'])) {
    $fullAddressParts[] = trim($userData['division']);
}

$fullAddress = count($fullAddressParts) > 0 ? implode(", ", $fullAddressParts) : "Not provided";
$editableAddress = $userData['street_village'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profile | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <!-- Global CSS -->
    <link rel="stylesheet" href="../../css/global/global.css">

    <!-- Reusable Citizen Layout CSS -->
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">

    <!-- Page CSS -->
    <link rel="stylesheet" href="../../css/citizen/profile.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>
<body class="citizen">

<div class="citizen-layout">

    <?php include "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main main-content">

        <?php include "../../includes/citizen/topbar.php"; ?>

        <section class="cp-page">

            <div class="cp-header">
                <h1>Profile</h1>
                <p>View your citizen account information</p>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="cp-alert cp-success">
                    <i class="bi bi-check-circle"></i>
                    <?php echo safeText($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="cp-alert cp-error">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo safeText($errorMessage); ?>
                </div>
            <?php endif; ?>

            <div class="cp-card">

                <div class="cp-profile-top">

                    <form
                        class="cp-photo-form"
                        id="photoForm"
                        method="POST"
                        action="profile.php"
                        enctype="multipart/form-data"
                    >
                        <input type="hidden" name="form_type" value="photo_update">

                        <label class="cp-photo-box" for="profilePhotoInput">
                            <?php if ($profilePhotoPath !== ''): ?>
                                <img src="<?php echo safeText($profilePhotoPath); ?>" alt="Citizen profile photo">
                            <?php else: ?>
                                <i class="bi bi-person"></i>
                            <?php endif; ?>

                            <span class="cp-photo-overlay">
                                <i class="bi bi-camera-fill"></i>
                            </span>
                        </label>

                        <input
                            type="file"
                            name="profile_photo"
                            id="profilePhotoInput"
                            accept="image/png, image/jpeg, image/jpg"
                            hidden
                        >
                    </form>

                    <div class="cp-user-title">
                        <h2><?php echo safeText($userData['user_name'] ?? 'Citizen User'); ?></h2>
                        <p><?php echo safeText($userData['user_mail'] ?? ''); ?></p>
                        <span>Citizen Account</span>
                    </div>

                </div>

                <div class="cp-forms-container" style="display: flex; flex-direction: column; gap: 30px; padding: 20px;">
                    
                    <!-- Profile Update Form -->
                    <form method="POST" action="profile.php" class="cp-profile-form">
                        <input type="hidden" name="form_type" value="profile_update">
                        <h3 style="margin-bottom: 20px;">Profile Information</h3>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Full Name</label>
                            <input type="text" name="full_name" value="<?php echo safeText($userData['citizen_full_name'] ?? $userData['user_name'] ?? ''); ?>" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                        </div>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Email</label>
                            <input type="email" name="email" value="<?php echo safeText($userData['user_mail'] ?? ''); ?>" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                        </div>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Phone</label>
                            <input type="text" name="phone" value="<?php echo safeText($userData['phone_number'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                        </div>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Account Status</label>
                            <input type="text" value="<?php echo safeText(ucwords($userData['user_status'] ?? 'Active')); ?>" readonly disabled style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; background-color: #f5f5f5; color: #666; cursor: not-allowed;">
                        </div>

                        <h4 style="margin-bottom: 15px; color: #1e293b; font-size: 16px; border-top: 1px solid #eee; padding-top: 15px;">Address Information</h4>
                        
                        <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
                            <div class="form-group" style="flex: 1 1 calc(33.333% - 15px); min-width: 200px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 500;">Division</label>
                                <input type="text" name="division" value="<?php echo safeText($userData['division'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                            </div>

                            <div class="form-group" style="flex: 1 1 calc(33.333% - 15px); min-width: 200px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 500;">District</label>
                                <input type="text" name="district" value="<?php echo safeText($userData['district'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                            </div>

                            <div class="form-group" style="flex: 1 1 calc(33.333% - 15px); min-width: 200px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 500;">Upazila/Thana</label>
                                <input type="text" name="upazila_thana" value="<?php echo safeText($userData['upazila_thana'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                            </div>

                            <div class="form-group" style="flex: 1 1 calc(50% - 15px); min-width: 200px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 500;">Union/Area</label>
                                <input type="text" name="union_area" value="<?php echo safeText($userData['union_area'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                            </div>

                            <div class="form-group" style="flex: 1 1 calc(50% - 15px); min-width: 200px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 500;">Street/Village</label>
                                <input type="text" name="street_village" value="<?php echo safeText($userData['street_village'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                            </div>
                        </div>

                        <button type="submit" style="background: linear-gradient(135deg, #0f172a, #1e293b); color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 600;">Update Profile</button>
                    </form>

                    <hr style="border: 0; height: 1px; background: #eee;">

                    <!-- Password Update Form -->
                    <form method="POST" action="profile.php" class="cp-password-form">
                        <input type="hidden" name="form_type" value="password_update">
                        <h3 style="margin-bottom: 20px;">Change Password</h3>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Old Password</label>
                            <input type="password" name="old_password" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                        </div>

                        <div class="form-group" style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">New Password</label>
                            <input type="password" name="new_password" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                        </div>

                        <div class="form-group" style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Confirm New Password</label>
                            <input type="password" name="confirm_password" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px;">
                        </div>

                        <button type="submit" style="background: var(--warning-color, #f59e0b); color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer; font-weight: 600;">Change Password</button>
                    </form>

                </div>

            </div>

        </section>

    </main>

</div>

<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/citizen/profile.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>