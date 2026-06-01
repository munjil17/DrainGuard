<?php

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

$issueId = (int)($_POST["issue_id"] ?? 0);
$affectedAreaId = (int)($_POST["affected_area_id"] ?? 0);

$addressDescription = trim($_POST["address_description"] ?? "");
$problemDescription = trim($_POST["problem_description"] ?? "");

$savedUploadedFiles = [];

function sc_redirect_error($message)
{
    $_SESSION["complaint_error"] = $message;
    redirect_to("pages/citizen/submit-complaint.php");
}

function sc_normalize_key($value)
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace("/\s+/", " ", $value);

    return $value;
}

function sc_make_risk_area_key($cityCorporationName, $thanaName, $wardNo, $areaName)
{
    return sc_normalize_key($cityCorporationName) . "|" .
        sc_normalize_key($thanaName) . "|" .
        sc_normalize_key($wardNo) . "|" .
        sc_normalize_key($areaName);
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

function sc_check_repeat_rule($conn, $userId, $locId, $drainId, $issueId, $affectedAreaId)
{
    $response = [
        "allowed" => true,
        "is_repeat" => 0,
        "parent_id" => null,
        "message" => ""
    ];

    $activeSql = "
        SELECT complaint_id, complaint_status
        FROM complaints
        WHERE user_id = ?
          AND loc_id = ?
          AND drain_id = ?
          AND issue_id = ?
          AND affected_area_id = ?
          AND complaint_status IN (
              'submitted',
              'received',
              'pending_verification',
              'verified_by_ward',
              'team_assigned',
              'in_progress',
              'solved_by_team',
              'inspector_verification',
              'reopened',
              'disputed'
          )
        ORDER BY submitted_at DESC
        LIMIT 1
    ";

    $activeStmt = mysqli_prepare($conn, $activeSql);

    if (!$activeStmt) {
        throw new Exception("Active repeat complaint check failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($activeStmt, "iiiii", $userId, $locId, $drainId, $issueId, $affectedAreaId);
    mysqli_stmt_execute($activeStmt);

    $activeResult = mysqli_stmt_get_result($activeStmt);

    if ($activeResult && mysqli_num_rows($activeResult) === 1) {
        mysqli_stmt_close($activeStmt);

        $response["allowed"] = false;
        $response["message"] = "You already have an active complaint for the same issue at this location. Please track the existing complaint instead of submitting a new one.";

        return $response;
    }

    mysqli_stmt_close($activeStmt);

    $closedSql = "
        SELECT complaint_id, closed_at
        FROM complaints
        WHERE user_id = ?
          AND loc_id = ?
          AND drain_id = ?
          AND issue_id = ?
          AND affected_area_id = ?
          AND complaint_status = 'closed'
          AND closed_at IS NOT NULL
          AND closed_at >= DATE_SUB(NOW(), INTERVAL 15 DAY)
        ORDER BY closed_at DESC
        LIMIT 1
    ";

    $closedStmt = mysqli_prepare($conn, $closedSql);

    if (!$closedStmt) {
        throw new Exception("Closed repeat complaint check failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($closedStmt, "iiiii", $userId, $locId, $drainId, $issueId, $affectedAreaId);
    mysqli_stmt_execute($closedStmt);

    $closedResult = mysqli_stmt_get_result($closedStmt);

    if ($closedResult && mysqli_num_rows($closedResult) === 1) {
        $existing = mysqli_fetch_assoc($closedResult);
        mysqli_stmt_close($closedStmt);

        $closedAt = strtotime($existing["closed_at"]);
        $daysPassed = $closedAt ? floor((time() - $closedAt) / 86400) : 0;
        $remainingDays = max(1, 15 - $daysPassed);

        $response["allowed"] = false;
        $response["message"] = "This complaint was recently solved. If the problem still exists, please submit an objection/reopen request. You can submit a new complaint for the same issue after " . $remainingDays . " day(s).";

        return $response;
    }

    mysqli_stmt_close($closedStmt);

    $oldClosedSql = "
        SELECT complaint_id
        FROM complaints
        WHERE user_id = ?
          AND loc_id = ?
          AND drain_id = ?
          AND issue_id = ?
          AND affected_area_id = ?
          AND complaint_status = 'closed'
          AND closed_at IS NOT NULL
          AND closed_at < DATE_SUB(NOW(), INTERVAL 15 DAY)
        ORDER BY closed_at DESC
        LIMIT 1
    ";

    $oldClosedStmt = mysqli_prepare($conn, $oldClosedSql);

    if (!$oldClosedStmt) {
        throw new Exception("Old closed complaint check failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($oldClosedStmt, "iiiii", $userId, $locId, $drainId, $issueId, $affectedAreaId);
    mysqli_stmt_execute($oldClosedStmt);

    $oldClosedResult = mysqli_stmt_get_result($oldClosedStmt);

    if ($oldClosedResult && mysqli_num_rows($oldClosedResult) === 1) {
        $existing = mysqli_fetch_assoc($oldClosedResult);

        $response["allowed"] = true;
        $response["is_repeat"] = 1;
        $response["parent_id"] = (int)$existing["complaint_id"];
    }

    mysqli_stmt_close($oldClosedStmt);

    return $response;
}

function sc_insert_status_log($conn, $complaintId, $oldStatus, $newStatus, $actionByUserId, $actionByRole, $remarks = null)
{
    $sql = "
        INSERT INTO complaint_status_logs (
            complaint_id,
            old_status,
            new_status,
            action_by_user_id,
            action_by_role,
            remarks
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception("Status log insert failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $stmt,
        "ississ",
        $complaintId,
        $oldStatus,
        $newStatus,
        $actionByUserId,
        $actionByRole,
        $remarks
    );

    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);

        throw new Exception("Status log insert failed: " . $error);
    }

    mysqli_stmt_close($stmt);
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

function sc_get_issue_name($conn, $issueId)
{
    $sql = "
        SELECT issue_name
        FROM issues
        WHERE issue_id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception("Issue validation failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, "i", $issueId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if (!$result || mysqli_num_rows($result) !== 1) {
        mysqli_stmt_close($stmt);
        throw new Exception("Invalid issue type selected.");
    }

    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return $row["issue_name"];
}

function sc_get_affected_area_name($conn, $affectedAreaId)
{
    $sql = "
        SELECT affected_area_name
        FROM affected_areas
        WHERE affected_area_id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception("Affected area validation failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, "i", $affectedAreaId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if (!$result || mysqli_num_rows($result) !== 1) {
        mysqli_stmt_close($stmt);
        throw new Exception("Invalid affected area selected.");
    }

    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return $row["affected_area_name"];
}

function sc_get_issue_score($issueName)
{
    $scoreMap = [
        "Open Manhole" => 3,
        "Missing Drain Cover" => 3,
        "Collapsed Drain Structure" => 3,
        "Severe Road Flooding" => 3,
        "Water Contamination" => 3,
        "Sewage Leakage" => 3,

        "Waterlogging" => 2,
        "Overflowing Drain" => 2,
        "Blocked Drain" => 2,
        "Drain Backflow" => 2,
        "Broken Drain Cover" => 2,
        "Mosquito Breeding" => 2,
        "Illegal Waste Dumping" => 2,

        "Bad Odor" => 1,
        "Garbage Accumulation" => 1,
        "Slow Drainage" => 1
    ];

    return $scoreMap[$issueName] ?? 1;
}

function sc_get_location_score($affectedAreaName)
{
    $scoreMap = [
        "Hospital Zone" => 3,
        "Main Road" => 3,
        "Commercial Hub / Market Area" => 3,
        "Bus Stand / Transport Hub" => 3,
        "Industrial Area" => 3,
        "Low-income Settlement" => 3,

        "School / College / University Zone" => 2,
        "Residential Street" => 2,
        "Narrow Lane" => 2,
        "Government Office Area" => 2,
        "Religious Place Area" => 2,

        "Footpath" => 1,
        "Public Park" => 1,
        "Empty Plot" => 1
    ];

    return $scoreMap[$affectedAreaName] ?? 1;
}

function sc_get_complaint_count_for_days($conn, $locId, $days)
{
    $sql = "
        SELECT COUNT(*) AS total_complaints
        FROM complaints
        WHERE loc_id = ?
       AND complaint_status NOT IN (
    'rejected_by_central',
    'rejected_by_ward',
    'duplicate',
    'final_rejected'
)
        AND submitted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception("Complaint count failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, "ii", $locId, $days);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : [];

    mysqli_stmt_close($stmt);

    return (int)($row["total_complaints"] ?? 0);
}

function sc_get_complaint_count_this_week($conn, $locId)
{
    $sql = "
        SELECT COUNT(*) AS total_complaints
        FROM complaints
        WHERE loc_id = ?
      AND complaint_status NOT IN (
    'rejected_by_central',
    'rejected_by_ward',
    'duplicate',
    'final_rejected'
)
        AND YEARWEEK(submitted_at, 1) = YEARWEEK(CURDATE(), 1)
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception("Weekly complaint count failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, "i", $locId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : [];

    mysqli_stmt_close($stmt);

    return (int)($row["total_complaints"] ?? 0);
}

function sc_calculate_urgency_level($issueName, $affectedAreaName, $complaintCount7Days)
{
    /*
        Final Risk Meaning:
        High   = Emergency
        Medium = Priority
        Low    = Normal

        Risk calculation priority:
        1. Water Contamination always High
        2. Direct public safety hazard override
        3. Sewage leakage health-risk override
        4. Repeated complaint override
        5. General score-based calculation
    */

    $issueName = trim($issueName);
    $affectedAreaName = trim($affectedAreaName);

    /*
        Water Contamination = always High / Emergency.
        It must not be downgraded for Empty Plot or Public Park.
    */
    if ($issueName === "Water Contamination") {
        return "High";
    }

    /*
        Direct public safety hazard issues.
        These are High in most public/sensitive areas.
        Empty Plot/Public Park can be Medium because public exposure is lower.
    */
    $directSafetyHazardIssues = [
        "Open Manhole",
        "Missing Drain Cover",
        "Collapsed Drain Structure"
    ];

    $lowerExposureAreas = [
        "Empty Plot",
        "Public Park"
    ];

    if (in_array($issueName, $directSafetyHazardIssues, true)) {
        if (in_array($affectedAreaName, $lowerExposureAreas, true)) {
            return "Medium";
        }

        return "High";
    }

    /*
        Sewage Leakage is a health-risk issue.
        High in sensitive/public areas, Medium elsewhere.
    */
    $sewageHighRiskAreas = [
        "Hospital Zone",
        "School / College / University Zone",
        "Residential Street",
        "Main Road",
        "Commercial Hub / Market Area",
        "Bus Stand / Transport Hub",
        "Low-income Settlement",
        "Government Office Area",
        "Religious Place Area",
        "Narrow Lane",
        "Industrial Area"
    ];

    if ($issueName === "Sewage Leakage") {
        if (in_array($affectedAreaName, $sewageHighRiskAreas, true)) {
            return "High";
        }

        return "Medium";
    }

    /*
        Repeated complaint override:
        5+ valid complaints in same location within 7 days = High.
    */
    if ($complaintCount7Days >= 5) {
        return "High";
    }

    $issueScore = sc_get_issue_score($issueName);
    $locationScore = sc_get_location_score($affectedAreaName);

    if ($complaintCount7Days >= 3) {
        $complaintScore = 3;
    } elseif ($complaintCount7Days === 2) {
        $complaintScore = 2;
    } else {
        $complaintScore = 1;
    }

    $totalScore = $issueScore + $locationScore + $complaintScore;

    if ($totalScore >= 7) {
        return "High";
    }

    if ($totalScore >= 4) {
        return "Medium";
    }

    /*
        Repeated complaint minimum rule:
        3+ valid complaints cannot stay Low.
    */
    if ($complaintCount7Days >= 3) {
        return "Medium";
    }

    return "Low";
}

function sc_update_risk_area(
    $conn,
    $riskAreaKey,
    $complaintId,
    $urgencyLevel,
    $count7Days,
    $count30Days,
    $countThisWeek
) {
    $findSql = "
        SELECT risk_id
        FROM risk
        WHERE risk_area_key = ?
        LIMIT 1
    ";

    $findStmt = mysqli_prepare($conn, $findSql);

    if (!$findStmt) {
        throw new Exception("Risk lookup failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($findStmt, "s", $riskAreaKey);
    mysqli_stmt_execute($findStmt);

    $findResult = mysqli_stmt_get_result($findStmt);

    if ($findResult && mysqli_num_rows($findResult) === 1) {
        $riskRow = mysqli_fetch_assoc($findResult);
        $riskId = (int)$riskRow["risk_id"];

        mysqli_stmt_close($findStmt);

        $updateSql = "
            UPDATE risk
            SET
                urgency_level = ?,
                risk_status = 'Active',
                complaint_count_7_days = ?,
                complaint_count_30_days = ?,
                complaint_count_this_week = ?,
                last_complaint_id = ?,
                last_reported_at = NOW()
            WHERE risk_id = ?
        ";

        $updateStmt = mysqli_prepare($conn, $updateSql);

        if (!$updateStmt) {
            throw new Exception("Risk update failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param(
            $updateStmt,
            "siiiii",
            $urgencyLevel,
            $count7Days,
            $count30Days,
            $countThisWeek,
            $complaintId,
            $riskId
        );

        if (!mysqli_stmt_execute($updateStmt)) {
            $error = mysqli_stmt_error($updateStmt);
            mysqli_stmt_close($updateStmt);

            throw new Exception("Risk update failed: " . $error);
        }

        mysqli_stmt_close($updateStmt);

        return;
    }

    mysqli_stmt_close($findStmt);

    $insertSql = "
        INSERT INTO risk (
            risk_area_key,
            urgency_level,
            risk_status,
            complaint_count_7_days,
            complaint_count_30_days,
            complaint_count_this_week,
            last_complaint_id,
            first_reported_at,
            last_reported_at
        )
        VALUES (?, ?, 'Active', ?, ?, ?, ?, NOW(), NOW())
    ";

    $insertStmt = mysqli_prepare($conn, $insertSql);

    if (!$insertStmt) {
        throw new Exception("Risk insert failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $insertStmt,
        "ssiiii",
        $riskAreaKey,
        $urgencyLevel,
        $count7Days,
        $count30Days,
        $countThisWeek,
        $complaintId
    );

    if (!mysqli_stmt_execute($insertStmt)) {
        $error = mysqli_stmt_error($insertStmt);
        mysqli_stmt_close($insertStmt);

        throw new Exception("Risk insert failed: " . $error);
    }

    mysqli_stmt_close($insertStmt);
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
    $issueId <= 0 ||
    $affectedAreaId <= 0 ||
    $addressDescription === "" ||
    $problemDescription === ""
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
| Validate selected location combination and fetch readable location data
|--------------------------------------------------------------------------
*/

$locationCheckSql = "
    SELECT
        l.loc_id,
        cc.city_cor_name,
        t.thana_name,
        w.ward_no,
        a.area_name
    FROM locations l
    INNER JOIN city_corporations cc
        ON l.city_cor_id = cc.city_cor_id
    INNER JOIN thanas t
        ON l.thana_id = t.thana_id
    INNER JOIN wards w
        ON l.ward_id = w.ward_id
    INNER JOIN areas a
        ON l.area_id = a.area_id
    WHERE l.loc_id = ?
    AND l.city_id = ?
    AND l.city_cor_id = ?
    AND l.thana_id = ?
    AND l.ward_id = ?
    AND l.area_id = ?
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

$locationRow = mysqli_fetch_assoc($locationResult);
mysqli_stmt_close($locationStmt);

$mediaFiles = sc_normalize_files($_FILES["complaint_media"] ?? []);

try {
    $issueName = sc_get_issue_name($conn, $issueId);
    $affectedAreaName = sc_get_affected_area_name($conn, $affectedAreaId);

    mysqli_begin_transaction($conn);

    $drainId = sc_get_or_create_drain_id($conn, $locId, $addressDescription);

    $repeatCheck = sc_check_repeat_rule(
        $conn,
        $userId,
        $locId,
        $drainId,
        $issueId,
        $affectedAreaId
    );

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
            issue_id,
            affected_area_id,
            address_description,
            problem_description,
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
        "siiiiissii",
        $complaintCode,
        $userId,
        $locId,
        $drainId,
        $issueId,
        $affectedAreaId,
        $addressDescription,
        $problemDescription,
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

    sc_insert_status_log(
        $conn,
        $complaintId,
        null,
        "submitted",
        $userId,
        "citizen",
        "Citizen submitted a new complaint."
    );

    if (!empty($mediaFiles)) {
        sc_upload_media_files($conn, $complaintId, $userId, $mediaFiles, $savedUploadedFiles);
    }

    $riskAreaKey = sc_make_risk_area_key(
        $locationRow["city_cor_name"],
        $locationRow["thana_name"],
        $locationRow["ward_no"],
        $locationRow["area_name"]
    );

    $count7Days = sc_get_complaint_count_for_days($conn, $locId, 7);
    $count30Days = sc_get_complaint_count_for_days($conn, $locId, 30);
    $countThisWeek = sc_get_complaint_count_this_week($conn, $locId);

    $calculatedUrgency = sc_calculate_urgency_level(
        $issueName,
        $affectedAreaName,
        $count7Days
    );

    if ($calculatedUrgency === "High" || $calculatedUrgency === "Medium" || $count7Days >= 3) {
        sc_update_risk_area(
            $conn,
            $riskAreaKey,
            $complaintId,
            $calculatedUrgency,
            $count7Days,
            $count30Days,
            $countThisWeek
        );
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