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

if ($addressColumns["present_address"]) {
    $selectCitizenFields[] = "c.present_address";
} else {
    $selectCitizenFields[] = "NULL AS present_address";
}

if ($addressColumns["permanent_address"]) {
    $selectCitizenFields[] = "c.permanent_address";
} else {
    $selectCitizenFields[] = "NULL AS permanent_address";
}

if ($addressColumns["address"]) {
    $selectCitizenFields[] = "c.address";
} else {
    $selectCitizenFields[] = "NULL AS address";
}

if ($addressColumns["house_no"]) {
    $selectCitizenFields[] = "c.house_no";
} else {
    $selectCitizenFields[] = "NULL AS house_no";
}

if ($addressColumns["road_no"]) {
    $selectCitizenFields[] = "c.road_no";
} else {
    $selectCitizenFields[] = "NULL AS road_no";
}

if ($addressColumns["area_name"]) {
    $selectCitizenFields[] = "c.area_name";
} else {
    $selectCitizenFields[] = "NULL AS area_name";
}

if ($addressColumns["city_name"]) {
    $selectCitizenFields[] = "c.city_name";
} else {
    $selectCitizenFields[] = "NULL AS city_name";
}

$citizenSelectSql = implode(",\n        ", $selectCitizenFields);

$userData = [
    "user_name" => "",
    "user_mail" => "",
    "user_role" => "citizen",
    "phone_number" => "",
    "profile_photo" => "",
    "present_address" => "",
    "permanent_address" => "",
    "address" => "",
    "house_no" => "",
    "road_no" => "",
    "area_name" => "",
    "city_name" => "",
];

$sql = "
    SELECT
    u.user_name,
    u.user_mail,
    u.user_role,
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

if (!empty($userData['house_no'])) {
    $fullAddressParts[] = "House: " . $userData['house_no'];
}

if (!empty($userData['road_no'])) {
    $fullAddressParts[] = "Road: " . $userData['road_no'];
}

if (!empty($userData['area_name'])) {
    $fullAddressParts[] = $userData['area_name'];
}

if (!empty($userData['city_name'])) {
    $fullAddressParts[] = $userData['city_name'];
}

if (!empty($userData['present_address'])) {
    $fullAddressParts[] = $userData['present_address'];
}

if (!empty($userData['address'])) {
    $fullAddressParts[] = $userData['address'];
}

$fullAddress = count($fullAddressParts) > 0 ? implode(", ", $fullAddressParts) : "Not provided";
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

                <div class="cp-info-grid">

                    <div class="cp-info-item">
                        <span>Full Name</span>
                        <strong><?php echo safeText($userData['user_name'] ?? 'Not provided'); ?></strong>
                    </div>

                    <div class="cp-info-item">
                        <span>Email</span>
                        <strong><?php echo safeText($userData['user_mail'] ?? 'Not provided'); ?></strong>
                    </div>

                    <div class="cp-info-item">
                        <span>Phone</span>
                        <strong><?php echo safeText($userData['phone_number'] ?? 'Not provided'); ?></strong>
                    </div>

                    <div class="cp-info-item">
                        <span>Role</span>
                        <strong><?php echo safeText(ucwords(str_replace("_", " ", $userData['user_role'] ?? 'citizen'))); ?></strong>
                    </div>

                    <div class="cp-info-item">
                        <span>Joined</span>
                        <strong><?php echo safeText($createdAt); ?></strong>
                    </div>

                    <div class="cp-info-item">
                        <span>Account Status</span>
                        <strong>Active</strong>
                    </div>

                    <div class="cp-info-item cp-full">
                        <span>Address</span>
                        <strong><?php echo safeText($fullAddress); ?></strong>
                    </div>

                    <?php if (!empty($userData['permanent_address'])): ?>
                        <div class="cp-info-item cp-full">
                            <span>Permanent Address</span>
                            <strong><?php echo safeText($userData['permanent_address']); ?></strong>
                        </div>
                    <?php endif; ?>

                </div>

                <div class="cp-actions">
                    <a href="settings.php" class="cp-edit-btn">
                        <i class="bi bi-pencil-square"></i>
                        Edit Profile
                    </a>
                </div>

            </div>

        </section>

    </main>

</div>

<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/citizen/profile.js"></script>

</body>
</html>