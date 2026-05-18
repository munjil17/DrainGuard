<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../pages/citizen/submit-complaint.php");
    exit();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'citizen') {
    header("Location: ../index.php");
    exit();
}

$userId = (int) $_SESSION['user_id'];

$locId = (int) ($_POST['loc_id'] ?? 0);
$issueType = trim($_POST['issue_type'] ?? '');
$addressDescription = trim($_POST['address_description'] ?? '');
$problemDescription = trim($_POST['problem_description'] ?? '');
$urgencyLevel = trim($_POST['urgency_level'] ?? '');

$allowedUrgency = ['Low', 'Medium', 'High', 'Critical'];

if (
    $locId <= 0 ||
    $issueType === '' ||
    $addressDescription === '' ||
    $problemDescription === '' ||
    !in_array($urgencyLevel, $allowedUrgency, true)
) {
    $_SESSION['complaint_error'] = "Please fill up all required fields correctly.";
    header("Location: ../pages/citizen/submit-complaint.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| Validate Location
|--------------------------------------------------------------------------
*/

$locationCheckSql = "
    SELECT loc_id
    FROM locations
    WHERE loc_id = ?
    LIMIT 1
";

$locationStmt = mysqli_prepare($conn, $locationCheckSql);

if (!$locationStmt) {
    $_SESSION['complaint_error'] = "Location validation failed: " . mysqli_error($conn);
    header("Location: ../pages/citizen/submit-complaint.php");
    exit();
}

mysqli_stmt_bind_param($locationStmt, "i", $locId);
mysqli_stmt_execute($locationStmt);

$locationResult = mysqli_stmt_get_result($locationStmt);

if (!$locationResult || mysqli_num_rows($locationResult) !== 1) {
    mysqli_stmt_close($locationStmt);

    $_SESSION['complaint_error'] = "Invalid location selected.";
    header("Location: ../pages/citizen/submit-complaint.php");
    exit();
}

mysqli_stmt_close($locationStmt);

/*
|--------------------------------------------------------------------------
| Image Upload
|--------------------------------------------------------------------------
| Supports:
| - JPG
| - JPEG
| - PNG
|--------------------------------------------------------------------------
*/

$complaintImagePath = null;

if (isset($_FILES['complaint_image']) && $_FILES['complaint_image']['error'] !== UPLOAD_ERR_NO_FILE) {

    if ($_FILES['complaint_image']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['complaint_error'] = "Image upload failed. Error code: " . $_FILES['complaint_image']['error'];
        header("Location: ../pages/citizen/submit-complaint.php");
        exit();
    }

    $maxFileSize = 5 * 1024 * 1024; // 5MB

    if ($_FILES['complaint_image']['size'] > $maxFileSize) {
        $_SESSION['complaint_error'] = "Image size must be less than 5MB.";
        header("Location: ../pages/citizen/submit-complaint.php");
        exit();
    }

    $originalFileName = $_FILES['complaint_image']['name'];
    $tmpFilePath = $_FILES['complaint_image']['tmp_name'];

    $fileExtension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png'];

    if (!in_array($fileExtension, $allowedExtensions, true)) {
        $_SESSION['complaint_error'] = "Only JPG, JPEG, and PNG files are allowed.";
        header("Location: ../pages/citizen/submit-complaint.php");
        exit();
    }

    /*
    |--------------------------------------------------------------------------
    | MIME Type Check
    |--------------------------------------------------------------------------
    | JPG/JPEG MIME type = image/jpeg
    | PNG MIME type = image/png
    |--------------------------------------------------------------------------
    */

    $allowedMimeTypes = ['image/jpeg', 'image/png'];

    if (!function_exists('finfo_open')) {
        $_SESSION['complaint_error'] = "PHP fileinfo extension is not enabled.";
        header("Location: ../pages/citizen/submit-complaint.php");
        exit();
    }

    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($fileInfo, $tmpFilePath);
    finfo_close($fileInfo);

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        $_SESSION['complaint_error'] = "Invalid image type. Please upload JPG, JPEG, or PNG.";
        header("Location: ../pages/citizen/submit-complaint.php");
        exit();
    }

    $imageInfo = getimagesize($tmpFilePath);

    if ($imageInfo === false) {
        $_SESSION['complaint_error'] = "Uploaded file is not a valid image.";
        header("Location: ../pages/citizen/submit-complaint.php");
        exit();
    }

    /*
    |--------------------------------------------------------------------------
    | Correct Absolute Upload Path
    |--------------------------------------------------------------------------
    | dirname(__DIR__) = C:\xampp\htdocs\DrainGuard
    |--------------------------------------------------------------------------
    */

    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR .
        "assets" . DIRECTORY_SEPARATOR .
        "uploads" . DIRECTORY_SEPARATOR .
        "complaints" . DIRECTORY_SEPARATOR;

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            $_SESSION['complaint_error'] = "Upload folder could not be created: " . $uploadDir;
            header("Location: ../pages/citizen/submit-complaint.php");
            exit();
        }
    }

    if (!is_writable($uploadDir)) {
        $_SESSION['complaint_error'] = "Upload folder is not writable: " . $uploadDir;
        header("Location: ../pages/citizen/submit-complaint.php");
        exit();
    }

    if (!is_uploaded_file($tmpFilePath)) {
        $_SESSION['complaint_error'] = "Temporary uploaded file not found.";
        header("Location: ../pages/citizen/submit-complaint.php");
        exit();
    }

    $safeExtension = ($fileExtension === 'jpeg') ? 'jpg' : $fileExtension;

    $newFileName = "complaint_" . $userId . "_" . time() . "_" . rand(1000, 9999) . "." . $safeExtension;
    $targetFilePath = $uploadDir . $newFileName;

    if (!move_uploaded_file($tmpFilePath, $targetFilePath)) {
        $_SESSION['complaint_error'] = "Failed to save uploaded image. Target path: " . $targetFilePath;
        header("Location: ../pages/citizen/submit-complaint.php");
        exit();
    }

    $complaintImagePath = "assets/uploads/complaints/" . $newFileName;
}

/*
|--------------------------------------------------------------------------
| Generate Unique Complaint Code
|--------------------------------------------------------------------------
*/

$complaintCode = "";
$codeExists = true;

while ($codeExists) {
    $complaintCode = "DG-" . date("Ymd") . "-" . rand(10000, 99999);

    $codeCheckSql = "
        SELECT complaint_id
        FROM complaints
        WHERE complaint_code = ?
        LIMIT 1
    ";

    $codeCheckStmt = mysqli_prepare($conn, $codeCheckSql);

    if (!$codeCheckStmt) {
        $_SESSION['complaint_error'] = "Complaint code check failed: " . mysqli_error($conn);
        header("Location: ../pages/citizen/submit-complaint.php");
        exit();
    }

    mysqli_stmt_bind_param($codeCheckStmt, "s", $complaintCode);
    mysqli_stmt_execute($codeCheckStmt);

    $codeCheckResult = mysqli_stmt_get_result($codeCheckStmt);
    $codeExists = ($codeCheckResult && mysqli_num_rows($codeCheckResult) > 0);

    mysqli_stmt_close($codeCheckStmt);
}

/*
|--------------------------------------------------------------------------
| Insert Complaint
|--------------------------------------------------------------------------
*/

$insertSql = "
    INSERT INTO complaints (
        complaint_code,
        user_id,
        loc_id,
        issue_type,
        address_description,
        problem_description,
        complaint_image,
        urgency_level,
        complaint_status
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'submitted')
";

$insertStmt = mysqli_prepare($conn, $insertSql);

if (!$insertStmt) {
    $_SESSION['complaint_error'] = "Complaint insert failed: " . mysqli_error($conn);
    header("Location: ../pages/citizen/submit-complaint.php");
    exit();
}

mysqli_stmt_bind_param(
    $insertStmt,
    "siisssss",
    $complaintCode,
    $userId,
    $locId,
    $issueType,
    $addressDescription,
    $problemDescription,
    $complaintImagePath,
    $urgencyLevel
);

if (mysqli_stmt_execute($insertStmt)) {
    mysqli_stmt_close($insertStmt);

    $_SESSION['complaint_success'] = "Complaint submitted successfully. Your complaint code is " . $complaintCode;
    header("Location: ../pages/citizen/submit-complaint.php");
    exit();
}

$error = mysqli_stmt_error($insertStmt);
mysqli_stmt_close($insertStmt);

$_SESSION['complaint_error'] = "Complaint submission failed: " . $error;
header("Location: ../pages/citizen/submit-complaint.php");
exit();

?>