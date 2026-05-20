<?php
// C:\xampp\htdocs\DrainGuard\auth\submit_complaint_process.php

require_once "../config.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    redirect_to("pages/citizen/submit-complaint.php");
}

if (
    empty($_SESSION["logged_in"]) ||
    $_SESSION["logged_in"] !== true ||
    empty($_SESSION["user_id"]) ||
    ($_SESSION["user_role"] ?? "") !== "citizen"
) {
    redirect_to("auth/login.php");
}

$userId = (int)$_SESSION["user_id"];

$locId = (int)($_POST["loc_id"] ?? 0);
$cityId = (int)($_POST["city_id"] ?? 0);
$cityCorId = (int)($_POST["city_cor_id"] ?? 0);
$thanaId = (int)($_POST["thana_id"] ?? 0);
$wardId = (int)($_POST["ward_id"] ?? 0);
$areaId = (int)($_POST["area_id"] ?? 0);

$issueType = trim($_POST["issue_type"] ?? "");
$addressDescription = trim($_POST["address_description"] ?? "");
$problemDescription = trim($_POST["problem_description"] ?? "");
$urgencyLevel = trim($_POST["urgency_level"] ?? "");

$allowedIssues = [
    "Blocked Drain",
    "Waterlogging",
    "Broken Drain Cover",
    "Bad Odor",
    "Overflowing Drain",
    "Other"
];

$allowedUrgency = ["Low", "Medium", "High", "Critical"];

$savedUploadedFiles = [];

function sc_redirect_error($message)
{
    $_SESSION["complaint_error"] = $message;
    redirect_to("pages/citizen/submit-complaint.php");
}

function sc_generate_complaint_code($conn)
{
    while (true) {
        $code = "DG-" . date("Ymd") . "-" . random_int(10000, 99999);

        $sql = "
            SELECT complaint_id
            FROM complaints
            WHERE complaint_code = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            throw new Exception("Complaint code check failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($stmt, "s", $code);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $exists = $result && mysqli_num_rows($result) > 0;

        mysqli_stmt_close($stmt);

        if (!$exists) {
            return $code;
        }
    }
}

function sc_generate_drain_code($conn, $locId)
{
    while (true) {
        $code = "DRN-LOC" . $locId . "-" . random_int(10000, 99999);

        $sql = "
            SELECT drain_id
            FROM drains
            WHERE drain_code = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            throw new Exception("Drain code check failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($stmt, "s", $code);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $exists = $result && mysqli_num_rows($result) > 0;

        mysqli_stmt_close($stmt);

        if (!$exists) {
            return $code;
        }
    }
}

function sc_make_drain_name($addressDescription)
{
    $cleanAddress = preg_replace("/\s+/", " ", trim($addressDescription));

    if (function_exists("mb_substr")) {
        $shortAddress = mb_substr($cleanAddress, 0, 80);
    } else {
        $shortAddress = substr($cleanAddress, 0, 80);
    }

    return "Drain near " . $shortAddress;
}

function sc_get_or_create_drain_id($conn, $locId, $addressDescription)
{
    $findSql = "
        SELECT drain_id
        FROM drains
        WHERE loc_id = ?
        AND drain_address_hash = MD5(LOWER(TRIM(?)))
        LIMIT 1
    ";

    $findStmt = mysqli_prepare($conn, $findSql);

    if (!$findStmt) {
        throw new Exception("Drain lookup failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($findStmt, "is", $locId, $addressDescription);
    mysqli_stmt_execute($findStmt);

    $findResult = mysqli_stmt_get_result($findStmt);

    if ($findResult && mysqli_num_rows($findResult) === 1) {
        $row = mysqli_fetch_assoc($findResult);
        mysqli_stmt_close($findStmt);

        return (int)$row["drain_id"];
    }

    mysqli_stmt_close($findStmt);

    $drainCode = sc_generate_drain_code($conn, $locId);
    $drainName = sc_make_drain_name($addressDescription);

    $insertSql = "
        INSERT INTO drains (
            loc_id,
            drain_code,
            drain_name,
            drain_address_description,
            drain_condition
        )
        VALUES (?, ?, ?, ?, 'moderate')
    ";

    $insertStmt = mysqli_prepare($conn, $insertSql);

    if (!$insertStmt) {
        throw new Exception("Drain insert failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $insertStmt,
        "isss",
        $locId,
        $drainCode,
        $drainName,
        $addressDescription
    );

    if (!mysqli_stmt_execute($insertStmt)) {
        $insertError = mysqli_stmt_error($insertStmt);
        mysqli_stmt_close($insertStmt);

        throw new Exception("Drain insert failed: " . $insertError);
    }

    $drainId = (int)mysqli_insert_id($conn);

    mysqli_stmt_close($insertStmt);

    return $drainId;
}

function sc_check_repeat_rule($conn, $userId, $locId, $drainId)
{
    $sql = "
        SELECT
            complaint_id,
            complaint_status,
            submitted_at,
            work_started_at
        FROM complaints
        WHERE user_id = ?
        AND loc_id = ?
        AND drain_id = ?
        AND complaint_status NOT IN ('solved', 'closed', 'rejected')
        ORDER BY submitted_at DESC
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception("Repeat complaint check failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, "iii", $userId, $locId, $drainId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $response = [
        "allowed" => true,
        "is_repeat" => 0,
        "parent_id" => null,
        "message" => ""
    ];

    if ($result && mysqli_num_rows($result) === 1) {
        $existing = mysqli_fetch_assoc($result);

        $workStarted = !empty($existing["work_started_at"]);

        $submittedTime = strtotime($existing["submitted_at"]);
        $daysPassed = $submittedTime ? floor((time() - $submittedTime) / 86400) : 0;

        if ($workStarted) {
            $response["allowed"] = false;
            $response["message"] = "Work has already started for your previous complaint on this drain.";
        } elseif ($daysPassed < 15) {
            $remainingDays = 15 - $daysPassed;

            $response["allowed"] = false;
            $response["message"] = "You already submitted a complaint for this drain. You can complain again after " . $remainingDays . " day(s) if work does not start.";
        } else {
            $response["allowed"] = true;
            $response["is_repeat"] = 1;
            $response["parent_id"] = (int)$existing["complaint_id"];
        }
    }

    mysqli_stmt_close($stmt);

    return $response;
}

function sc_normalize_files($files)
{
    $normalized = [];

    if (empty($files) || empty($files["name"]) || !is_array($files["name"])) {
        return $normalized;
    }

    $count = count($files["name"]);

    for ($i = 0; $i < $count; $i++) {
        if (($files["error"][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $normalized[] = [
            "name" => $files["name"][$i],
            "type" => $files["type"][$i],
            "tmp_name" => $files["tmp_name"][$i],
            "error" => $files["error"][$i],
            "size" => $files["size"][$i]
        ];
    }

    return $normalized;
}

function sc_upload_error_message($errorCode)
{
    if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
        return "Uploaded file is too large. Please check the allowed file size.";
    }

    if ($errorCode === UPLOAD_ERR_PARTIAL) {
        return "File upload was incomplete. Please try again.";
    }

    if ($errorCode === UPLOAD_ERR_NO_TMP_DIR) {
        return "Server temporary upload folder is missing.";
    }

    if ($errorCode === UPLOAD_ERR_CANT_WRITE) {
        return "Server failed to write uploaded file.";
    }

    if ($errorCode === UPLOAD_ERR_EXTENSION) {
        return "File upload was blocked by a PHP extension.";
    }

    return "File upload failed. Error code: " . $errorCode;
}

function sc_upload_media_files($conn, $complaintId, $userId, $files, &$savedUploadedFiles)
{
    $maxImageCount = 5;
    $maxImageSize = 5 * 1024 * 1024;
    $maxVideoCount = 1;
    $maxVideoSize = 150 * 1024 * 1024;

    $allowedImageExt = ["jpg", "jpeg", "png", "webp"];
    $allowedVideoExt = ["mp4", "webm"];

    $allowedImageMime = ["image/jpeg", "image/png", "image/webp"];
    $allowedVideoMime = ["video/mp4", "video/webm"];

    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR .
        "assets" . DIRECTORY_SEPARATOR .
        "uploads" . DIRECTORY_SEPARATOR .
        "complaints" . DIRECTORY_SEPARATOR;

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            throw new Exception("Upload folder could not be created.");
        }
    }

    if (!is_writable($uploadDir)) {
        throw new Exception("Upload folder is not writable.");
    }

    if (!function_exists("finfo_open")) {
        throw new Exception("PHP fileinfo extension is not enabled.");
    }

    $imageCount = 0;
    $videoCount = 0;
    $duplicateKeys = [];

    foreach ($files as $file) {
        if ($file["error"] !== UPLOAD_ERR_OK) {
            throw new Exception(sc_upload_error_message($file["error"]));
        }

        if (!is_uploaded_file($file["tmp_name"])) {
            throw new Exception("Invalid uploaded file detected.");
        }

        $originalName = basename($file["name"]);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $duplicateKey = strtolower($originalName) . "|" . $file["size"];

        if (isset($duplicateKeys[$duplicateKey])) {
            throw new Exception("Duplicate file detected: " . $originalName);
        }

        $duplicateKeys[$duplicateKey] = true;

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);

        if (!$fileInfo) {
            throw new Exception("Unable to verify uploaded file type.");
        }

        $mimeType = finfo_file($fileInfo, $file["tmp_name"]);
        finfo_close($fileInfo);

        $mediaType = "";

        if (in_array($extension, $allowedImageExt, true) && in_array($mimeType, $allowedImageMime, true)) {
            $mediaType = "image";
            $imageCount++;

            if ($imageCount > $maxImageCount) {
                throw new Exception("You can upload maximum 5 images.");
            }

            if ($file["size"] > $maxImageSize) {
                throw new Exception("Each image must be 5MB or less.");
            }

            if (getimagesize($file["tmp_name"]) === false) {
                throw new Exception("Invalid image file detected.");
            }
        } elseif (in_array($extension, $allowedVideoExt, true) && in_array($mimeType, $allowedVideoMime, true)) {
            $mediaType = "video";
            $videoCount++;

            if ($videoCount > $maxVideoCount) {
                throw new Exception("You can upload maximum 1 video.");
            }

            if ($file["size"] > $maxVideoSize) {
                throw new Exception("Video must be 150MB or less.");
            }
        } else {
            throw new Exception("Allowed files: JPG, JPEG, PNG, WEBP, MP4, WEBM.");
        }

        $safeExtension = ($extension === "jpeg") ? "jpg" : $extension;

        $newFileName = "complaint_" .
            $complaintId . "_" .
            $mediaType . "_" .
            $userId . "_" .
            time() . "_" .
            random_int(1000, 9999) .
            "." . $safeExtension;

        $targetPath = $uploadDir . $newFileName;

        if (!move_uploaded_file($file["tmp_name"], $targetPath)) {
            throw new Exception("Failed to save uploaded file.");
        }

        $savedUploadedFiles[] = $targetPath;

        $relativePath = "assets/uploads/complaints/" . $newFileName;

        $insertSql = "
            INSERT INTO complaint_media (
                complaint_id,
                media_type,
                media_path,
                original_name,
                file_size,
                mime_type
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ";

        $insertStmt = mysqli_prepare($conn, $insertSql);

        if (!$insertStmt) {
            throw new Exception("Media insert failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param(
            $insertStmt,
            "isssis",
            $complaintId,
            $mediaType,
            $relativePath,
            $originalName,
            $file["size"],
            $mimeType
        );

        if (!mysqli_stmt_execute($insertStmt)) {
            $mediaError = mysqli_stmt_error($insertStmt);
            mysqli_stmt_close($insertStmt);

            throw new Exception("Media insert failed: " . $mediaError);
        }

        mysqli_stmt_close($insertStmt);
    }
}

/*
|--------------------------------------------------------------------------
| Basic validation
|--------------------------------------------------------------------------
*/

if (
    $userId <= 0 ||
    $locId <= 0 ||
    $cityId <= 0 ||
    $cityCorId <= 0 ||
    $thanaId <= 0 ||
    $wardId <= 0 ||
    $areaId <= 0 ||
    $issueType === "" ||
    $addressDescription === "" ||
    $problemDescription === "" ||
    !in_array($issueType, $allowedIssues, true) ||
    !in_array($urgencyLevel, $allowedUrgency, true)
) {
    sc_redirect_error("Please fill up all required fields correctly.");
}

if (strlen($addressDescription) < 8) {
    sc_redirect_error("Address description must be at least 8 characters. Please write the exact drain location.");
}

if (strlen($problemDescription) < 10) {
    sc_redirect_error("Problem description must be at least 10 characters. Please describe the issue clearly.");
}

/*
|--------------------------------------------------------------------------
| Validate selected location combination
|--------------------------------------------------------------------------
*/

$locationCheckSql = "
    SELECT loc_id
    FROM locations
    WHERE loc_id = ?
    AND city_id = ?
    AND city_cor_id = ?
    AND thana_id = ?
    AND ward_id = ?
    AND area_id = ?
    LIMIT 1
";

$locationStmt = mysqli_prepare($conn, $locationCheckSql);

if (!$locationStmt) {
    sc_redirect_error("Location validation failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param(
    $locationStmt,
    "iiiiii",
    $locId,
    $cityId,
    $cityCorId,
    $thanaId,
    $wardId,
    $areaId
);

mysqli_stmt_execute($locationStmt);

$locationResult = mysqli_stmt_get_result($locationStmt);

if (!$locationResult || mysqli_num_rows($locationResult) !== 1) {
    mysqli_stmt_close($locationStmt);
    sc_redirect_error("Invalid location mapping selected.");
}

mysqli_stmt_close($locationStmt);

$mediaFiles = sc_normalize_files($_FILES["complaint_media"] ?? []);

try {
    mysqli_begin_transaction($conn);

    $drainId = sc_get_or_create_drain_id($conn, $locId, $addressDescription);

    $repeatCheck = sc_check_repeat_rule($conn, $userId, $locId, $drainId);

    if (!$repeatCheck["allowed"]) {
        mysqli_rollback($conn);
        sc_redirect_error($repeatCheck["message"]);
    }

    $complaintCode = sc_generate_complaint_code($conn);

    $parentComplaintId = $repeatCheck["parent_id"];
    $isRepeatComplaint = (int)$repeatCheck["is_repeat"];

    $insertSql = "
        INSERT INTO complaints (
            complaint_code,
            user_id,
            loc_id,
            drain_id,
            issue_type,
            address_description,
            problem_description,
            urgency_level,
            complaint_status,
            parent_complaint_id,
            is_repeat_complaint
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'submitted', ?, ?)
    ";

    $insertStmt = mysqli_prepare($conn, $insertSql);

    if (!$insertStmt) {
        throw new Exception("Complaint insert failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $insertStmt,
        "siiissssii",
        $complaintCode,
        $userId,
        $locId,
        $drainId,
        $issueType,
        $addressDescription,
        $problemDescription,
        $urgencyLevel,
        $parentComplaintId,
        $isRepeatComplaint
    );

    if (!mysqli_stmt_execute($insertStmt)) {
        $insertError = mysqli_stmt_error($insertStmt);
        mysqli_stmt_close($insertStmt);

        throw new Exception("Complaint submission failed: " . $insertError);
    }

    $complaintId = (int)mysqli_insert_id($conn);

    mysqli_stmt_close($insertStmt);

    if (!empty($mediaFiles)) {
        sc_upload_media_files($conn, $complaintId, $userId, $mediaFiles, $savedUploadedFiles);
    }

    mysqli_commit($conn);

    $_SESSION["complaint_success"] = "Complaint submitted successfully. Your complaint code is " . $complaintCode . ".";
    redirect_to("pages/citizen/submit-complaint.php");
} catch (Throwable $exception) {
    mysqli_rollback($conn);

    foreach ($savedUploadedFiles as $filePath) {
        if (is_file($filePath)) {
            unlink($filePath);
        }
    }

    $_SESSION["complaint_error"] = $exception->getMessage();
    redirect_to("pages/citizen/submit-complaint.php");
}
?>