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
    die("Missing column: ward_officers.profile_image. Run: ALTER TABLE ward_officers ADD COLUMN profile_image VARCHAR(255) NULL AFTER office_address;");
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
                    throw new Exception("Photo update failed: " . mysqli_error($conn));
                }

                mysqli_stmt_bind_param($stmt, "si", $dbPath, $currentUserId);

                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Photo update failed: " . mysqli_stmt_error($stmt));
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
    <link rel="stylesheet" href="../../css/ward/footer.css">
    <link rel="stylesheet" href="../../css/ward/profile.css">
    <link rel="stylesheet" href="../../css/ward/wardTextFix.css">
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

            <div class="wp-info-grid">
                <div class="wp-info-box">
                    <span>Full Name</span>
                    <strong><?= safeText($fullName); ?></strong>
                </div>

                <div class="wp-info-box">
                    <span>Email</span>
                    <strong><?= safeText($email); ?></strong>
                </div>

                <div class="wp-info-box">
                    <span>Phone</span>
                    <strong><?= safeText($phone); ?></strong>
                </div>

                <div class="wp-info-box">
                    <span>Role</span>
                    <strong><?= safeText($designation); ?></strong>
                </div>

                <div class="wp-info-box">
                    <span>Employee ID</span>
                    <strong><?= safeText($employeeCode); ?></strong>
                </div>

                <div class="wp-info-box">
                    <span>Assigned Ward</span>
                    <strong>
                        Ward <?= safeText($wardNo); ?><?= $wardName ? " - " . safeText($wardName) : ""; ?>
                    </strong>
                </div>

                <div class="wp-info-box wp-info-wide">
                    <span>Address</span>
                    <strong><?= safeText($address); ?></strong>
                </div>

                <div class="wp-info-box wp-info-wide">
                    <span>Office Address</span>
                    <strong><?= safeText($officeAddress); ?></strong>
                </div>
            </div>

            <a href="settings.php" class="wp-edit-btn">
                <i class="bi bi-pencil-square"></i>
                Edit Profile Info
            </a>

        </div>

    </section>

    <?php include "../../includes/ward/footer.php"; ?>
</main>

<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/profile.js"></script>

</body>
</html>