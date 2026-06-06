<?php
// C:\xampp\htdocs\DrainGuard\pages\central\ward-area.php

$activePage = "ward-area";
$pageTitle = "Ward & Area Management";
$pageParent = "Central Control";
$pageChild = "Ward & Area";

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "central_officer") {
    header("Location: ../../index.php");
    exit();
}

function wa_safe($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function wa_redirect_with($type, $message)
{
    $_SESSION["ward_area_" . $type] = $message;
    header("Location: ward-area.php");
    exit();
}

function wa_table_exists($conn, $tableName)
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "s", $tableName);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : ["total" => 0];

    mysqli_stmt_close($stmt);

    return (int)$row["total"] > 0;
}

function wa_column_exists($conn, $tableName, $columnName)
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ss", $tableName, $columnName);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : ["total" => 0];

    mysqli_stmt_close($stmt);

    return (int)$row["total"] > 0;
}

function wa_get_area_info_for_ward($conn, $areaId, $wardId)
{
    $sql = "
        SELECT area_id, ward_id, area_name
        FROM areas
        WHERE area_id = ?
        AND ward_id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "ii", $areaId, $wardId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return $row;
}

function wa_area_name_exists($conn, $wardId, $areaName, $excludeAreaId = 0)
{
    if ($excludeAreaId > 0) {
        $sql = "
            SELECT area_id
            FROM areas
            WHERE ward_id = ?
            AND LOWER(TRIM(area_name)) = LOWER(TRIM(?))
            AND area_id <> ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            return true;
        }

        mysqli_stmt_bind_param($stmt, "isi", $wardId, $areaName, $excludeAreaId);
    } else {
        $sql = "
            SELECT area_id
            FROM areas
            WHERE ward_id = ?
            AND LOWER(TRIM(area_name)) = LOWER(TRIM(?))
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            return true;
        }

        mysqli_stmt_bind_param($stmt, "is", $wardId, $areaName);
    }

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $exists = $result && mysqli_num_rows($result) > 0;

    mysqli_stmt_close($stmt);

    return $exists;
}

function wa_get_existing_area_id($conn, $wardId, $areaName)
{
    $sql = "
        SELECT area_id
        FROM areas
        WHERE ward_id = ?
        AND LOWER(TRIM(area_name)) = LOWER(TRIM(?))
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, "is", $wardId, $areaName);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return $row ? (int)$row["area_id"] : 0;
}

function wa_validate_location_chain($conn, $cityId, $cityCorId, $thanaId, $wardId)
{
    $sql = "
        SELECT w.ward_id
        FROM wards w
        INNER JOIN thanas t ON w.thana_id = t.thana_id
        INNER JOIN city_corporations cc ON t.city_cor_id = cc.city_cor_id
        INNER JOIN cities c ON cc.city_id = c.city_id
        WHERE c.city_id = ?
        AND cc.city_cor_id = ?
        AND t.thana_id = ?
        AND w.ward_id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "iiii", $cityId, $cityCorId, $thanaId, $wardId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $valid = $result && mysqli_num_rows($result) === 1;

    mysqli_stmt_close($stmt);

    return $valid;
}

function wa_location_mapping_exists($conn, $cityId, $cityCorId, $thanaId, $wardId, $areaId)
{
    $sql = "
        SELECT loc_id
        FROM locations
        WHERE city_id = ?
        AND city_cor_id = ?
        AND thana_id = ?
        AND ward_id = ?
        AND area_id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return true;
    }

    mysqli_stmt_bind_param($stmt, "iiiii", $cityId, $cityCorId, $thanaId, $wardId, $areaId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $exists = $result && mysqli_num_rows($result) > 0;

    mysqli_stmt_close($stmt);

    return $exists;
}

/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = trim($_POST["action"] ?? "");

    if ($action === "rename_area") {
        $areaId = (int)($_POST["area_id"] ?? 0);
        $wardId = (int)($_POST["ward_id"] ?? 0);
        $newAreaName = trim($_POST["area_name"] ?? "");

        if ($areaId <= 0 || $wardId <= 0 || $newAreaName === "") {
            wa_redirect_with("error", "Invalid area rename request.");
        }

        if (strlen($newAreaName) < 2) {
            wa_redirect_with("error", "Area name must be at least 2 characters.");
        }

        $areaInfo = wa_get_area_info_for_ward($conn, $areaId, $wardId);

        if (!$areaInfo) {
            wa_redirect_with("error", "Area not found under this selected ward.");
        }

        if (wa_area_name_exists($conn, $wardId, $newAreaName, $areaId)) {
            wa_redirect_with("error", "Another area with this name already exists under the same ward.");
        }

        $sql = "
            UPDATE areas
            SET area_name = ?
            WHERE area_id = ?
            AND ward_id = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            wa_redirect_with("error", "Area rename failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($stmt, "sii", $newAreaName, $areaId, $wardId);

        if (!mysqli_stmt_execute($stmt)) {
            $error = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            wa_redirect_with("error", "Area rename failed: " . $error);
        }

        mysqli_stmt_close($stmt);

        wa_redirect_with("success", "Area renamed successfully.");
    }

    if ($action === "add_area") {
        $cityId = (int)($_POST["city_id"] ?? 0);
        $cityCorId = (int)($_POST["city_cor_id"] ?? 0);
        $thanaId = (int)($_POST["thana_id"] ?? 0);
        $wardId = (int)($_POST["ward_id"] ?? 0);
        $areaName = trim($_POST["area_name"] ?? "");

        if ($cityId <= 0 || $cityCorId <= 0 || $thanaId <= 0 || $wardId <= 0 || $areaName === "") {
            wa_redirect_with("error", "Please select city, corporation, thana, ward and enter an area name.");
        }

        if (strlen($areaName) < 2) {
            wa_redirect_with("error", "Area name must be at least 2 characters.");
        }

        if (!wa_validate_location_chain($conn, $cityId, $cityCorId, $thanaId, $wardId)) {
            wa_redirect_with("error", "Invalid city/corporation/thana/ward selection.");
        }

        try {
            mysqli_begin_transaction($conn);

            $areaId = wa_get_existing_area_id($conn, $wardId, $areaName);

            if ($areaId <= 0) {
                $insertAreaSql = "
                    INSERT INTO areas (ward_id, area_name)
                    VALUES (?, ?)
                ";

                $insertAreaStmt = mysqli_prepare($conn, $insertAreaSql);

                if (!$insertAreaStmt) {
                    throw new Exception("Unable to complete this action. Please try again.");
                }

                mysqli_stmt_bind_param($insertAreaStmt, "is", $wardId, $areaName);

                if (!mysqli_stmt_execute($insertAreaStmt)) {
                    $error = mysqli_stmt_error($insertAreaStmt);
                    mysqli_stmt_close($insertAreaStmt);
                    throw new Exception("Unable to complete this action. Please try again.");
                }

                $areaId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($insertAreaStmt);
            }

            if (wa_location_mapping_exists($conn, $cityId, $cityCorId, $thanaId, $wardId, $areaId)) {
                mysqli_commit($conn);
                wa_redirect_with("success", "Area already exists and is already mapped with this ward.");
            }

            $insertLocationSql = "
                INSERT INTO locations (
                    city_id,
                    city_cor_id,
                    thana_id,
                    ward_id,
                    area_id
                )
                VALUES (?, ?, ?, ?, ?)
            ";

            $insertLocationStmt = mysqli_prepare($conn, $insertLocationSql);

            if (!$insertLocationStmt) {
                throw new Exception("Unable to complete this action. Please try again.");
            }

            mysqli_stmt_bind_param($insertLocationStmt, "iiiii", $cityId, $cityCorId, $thanaId, $wardId, $areaId);

            if (!mysqli_stmt_execute($insertLocationStmt)) {
                $error = mysqli_stmt_error($insertLocationStmt);
                mysqli_stmt_close($insertLocationStmt);
                throw new Exception("Unable to complete this action. Please try again.");
            }

            mysqli_stmt_close($insertLocationStmt);
            mysqli_commit($conn);

            wa_redirect_with("success", "Area added and mapped with selected ward successfully.");
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            wa_redirect_with("error", $exception->getMessage());
        }
    }

    if ($action === "send_instruction") {
        $senderUserId = (int)($_SESSION["user_id"] ?? 0);
        $receiverUserId = (int)($_POST["receiver_user_id"] ?? 0);
        $receiverRole = trim($_POST["receiver_role"] ?? "");
        $wardId = (int)($_POST["ward_id"] ?? 0);
        $instructionTitle = trim($_POST["instruction_title"] ?? "");
        $instructionMessage = trim($_POST["instruction_message"] ?? "");

        $allowedRoles = ["ward_officer", "inspector"];

        if (!wa_table_exists($conn, "role_instructions")) {
            wa_redirect_with("error", "role_instructions table not found.");
        }

        if ($senderUserId <= 0 || $wardId <= 0 || !in_array($receiverRole, $allowedRoles, true) || $instructionTitle === "") {
            wa_redirect_with("error", "Invalid instruction request.");
        }

        if ($instructionMessage === "") {
            wa_redirect_with("error", "Instruction message cannot be empty.");
        }

        if ($receiverUserId <= 0) {
            wa_redirect_with("error", "Receiver missing or not assigned to this ward.");
        }

        try {
            mysqli_begin_transaction($conn);

            $sql = "
                INSERT INTO role_instructions (
                    sender_user_id,
                    receiver_user_id,
                    receiver_role,
                    ward_id,
                    instruction_title,
                    instruction_message,
                    instruction_status
                )
                VALUES (?, ?, ?, ?, ?, ?, 'Sent')
            ";

            $stmt = mysqli_prepare($conn, $sql);

            if (!$stmt) {
                throw new Exception("Instruction send failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param(
                $stmt,
                "iisiss",
                $senderUserId,
                $receiverUserId,
                $receiverRole,
                $wardId,
                $instructionTitle,
                $instructionMessage
            );

            if (!mysqli_stmt_execute($stmt)) {
                $error = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                throw new Exception("Instruction send failed: " . $error);
            }

            $instructionId = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            // Fetch ward info for notification message
            $wardQuery = mysqli_query($conn, "SELECT ward_no FROM wards WHERE ward_id = $wardId LIMIT 1");
            $wardNo = ($wardQuery && mysqli_num_rows($wardQuery) > 0) ? mysqli_fetch_assoc($wardQuery)['ward_no'] : 'Unknown';
            
            $notifTitle = ($receiverRole === "ward_officer") 
                ? "New instruction from Central Officer for Ward $wardNo."
                : "New inspection request from Central Officer for Ward $wardNo.";
            
            $notifType = 'central_instruction';
            $notifTable = ($receiverRole === "ward_officer") ? "ward_notifications" : "inspector_notifications";

            $notifSql = "
                INSERT INTO $notifTable (
                    recipient_user_id, 
                    sender_user_id, 
                    related_complaint_id, 
                    notification_type, 
                    notification_title, 
                    notification_message
                ) VALUES (?, ?, NULL, ?, ?, ?)
            ";

            $notifStmt = mysqli_prepare($conn, $notifSql);
            if (!$notifStmt) {
                throw new Exception("Notification send failed.");
            }

            mysqli_stmt_bind_param(
                $notifStmt,
                "iisss",
                $receiverUserId,
                $senderUserId,
                $notifType,
                $notifTitle,
                $instructionMessage
            );

            if (!mysqli_stmt_execute($notifStmt)) {
                mysqli_stmt_close($notifStmt);
                throw new Exception("Unable to complete this action. Please try again.");
            }
            $notificationId = mysqli_insert_id($conn);
            mysqli_stmt_close($notifStmt);

            // Insert into mapping table
            $mapSql = "INSERT INTO instruction_notifications_map (instruction_id, notification_id, role_type) VALUES (?, ?, ?)";
            $mapStmt = mysqli_prepare($conn, $mapSql);
            if ($mapStmt) {
                mysqli_stmt_bind_param($mapStmt, "iis", $instructionId, $notificationId, $receiverRole);
                mysqli_stmt_execute($mapStmt);
                mysqli_stmt_close($mapStmt);
            }

            mysqli_commit($conn);
            wa_redirect_with("success", "Instruction sent successfully.");
        } catch (Exception $e) {
            mysqli_rollback($conn);
            wa_redirect_with("error", $e->getMessage());
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load dropdown master data
|--------------------------------------------------------------------------
*/

$cities = [];
$cityCorporations = [];
$thanas = [];
$wards = [];
$wardDetails = [];

$cityResult = mysqli_query($conn, "
    SELECT city_id, city_name
    FROM cities
    ORDER BY city_name ASC
");

if ($cityResult) {
    while ($row = mysqli_fetch_assoc($cityResult)) {
        $cities[] = $row;
    }
}

$corpResult = mysqli_query($conn, "
    SELECT city_cor_id, city_cor_name, city_id
    FROM city_corporations
    ORDER BY city_cor_name ASC
");

if ($corpResult) {
    while ($row = mysqli_fetch_assoc($corpResult)) {
        $cityCorporations[] = $row;
    }
}

$thanaResult = mysqli_query($conn, "
    SELECT thana_id, thana_name, city_cor_id
    FROM thanas
    ORDER BY thana_name ASC
");

if ($thanaResult) {
    while ($row = mysqli_fetch_assoc($thanaResult)) {
        $thanas[] = $row;
    }
}

$wardResult = mysqli_query($conn, "
    SELECT ward_id, ward_no, ward_name, thana_id
    FROM wards
    ORDER BY CAST(ward_no AS UNSIGNED), ward_no ASC
");

if ($wardResult) {
    while ($row = mysqli_fetch_assoc($wardResult)) {
        $wards[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Officer + Inspector dynamic setup
|--------------------------------------------------------------------------
*/

$officerSelect = "'Not Assigned' AS officer_name";
$officerUserSelect = "NULL AS officer_user_id";
$officerJoin = "";

if (
    wa_table_exists($conn, "ward_officers") &&
    wa_column_exists($conn, "ward_officers", "assigned_ward_id")
) {
    if (wa_column_exists($conn, "ward_officers", "user_id")) {
        $officerJoin = "
            LEFT JOIN ward_officers wo
                ON w.ward_id = wo.assigned_ward_id
            LEFT JOIN users u
                ON wo.user_id = u.user_id
        ";

        $officerUserSelect = "MAX(wo.user_id) AS officer_user_id";

        if (wa_column_exists($conn, "ward_officers", "full_name")) {
            $officerSelect = "COALESCE(MAX(u.user_name), MAX(wo.full_name), 'Not Assigned') AS officer_name";
        } else {
            $officerSelect = "COALESCE(MAX(u.user_name), 'Not Assigned') AS officer_name";
        }
    }
}

$inspectorSelect = "'Not Assigned' AS inspector_name";
$inspectorUserSelect = "NULL AS inspector_user_id";
$inspectorJoin = "";

if (
    wa_table_exists($conn, "inspectors") &&
    wa_column_exists($conn, "inspectors", "assigned_ward_id")
) {
    if (wa_column_exists($conn, "inspectors", "user_id")) {
        $inspectorJoin = "
            LEFT JOIN inspectors ins
                ON w.ward_id = ins.assigned_ward_id
            LEFT JOIN users iu
                ON ins.user_id = iu.user_id
        ";

        $inspectorUserSelect = "MAX(ins.user_id) AS inspector_user_id";

        if (wa_column_exists($conn, "inspectors", "full_name")) {
            $inspectorSelect = "COALESCE(MAX(iu.user_name), MAX(ins.full_name), 'Not Assigned') AS inspector_name";
        } else {
            $inspectorSelect = "COALESCE(MAX(iu.user_name), 'Not Assigned') AS inspector_name";
        }
    }
}

/*
|--------------------------------------------------------------------------
| Ward details query
|--------------------------------------------------------------------------
*/

$wardDetailsSql = "
    SELECT
        w.ward_id,
        w.ward_no,
        w.ward_name,
        w.thana_id,

        t.thana_name,
        cc.city_cor_id,
        cc.city_cor_name,
        c.city_id,
        c.city_name,

        $officerSelect,
        $officerUserSelect,

        $inspectorSelect,
        $inspectorUserSelect,

        COUNT(DISTINCT comp.complaint_id) AS total_complaints,
        COUNT(DISTINCT a.area_id) AS total_areas

    FROM wards w

    INNER JOIN thanas t
        ON w.thana_id = t.thana_id

    INNER JOIN city_corporations cc
        ON t.city_cor_id = cc.city_cor_id

    INNER JOIN cities c
        ON cc.city_id = c.city_id

    $officerJoin

    $inspectorJoin

    LEFT JOIN areas a
        ON w.ward_id = a.ward_id

    LEFT JOIN locations l
        ON w.ward_id = l.ward_id

    LEFT JOIN complaints comp
        ON l.loc_id = comp.loc_id

    GROUP BY
        w.ward_id,
        w.ward_no,
        w.ward_name,
        w.thana_id,
        t.thana_name,
        cc.city_cor_id,
        cc.city_cor_name,
        c.city_id,
        c.city_name

    ORDER BY CAST(w.ward_no AS UNSIGNED), w.ward_no ASC
";

$wardDetailsResult = mysqli_query($conn, $wardDetailsSql);

if ($wardDetailsResult) {
    while ($row = mysqli_fetch_assoc($wardDetailsResult)) {
        $wardDetails[(int)$row["ward_id"]] = [
            "ward_id" => (int)$row["ward_id"],
            "ward_no" => $row["ward_no"],
            "ward_name" => $row["ward_name"],
            "thana_id" => (int)$row["thana_id"],
            "thana_name" => $row["thana_name"],
            "city_cor_id" => (int)$row["city_cor_id"],
            "city_cor_name" => $row["city_cor_name"],
            "city_id" => (int)$row["city_id"],
            "city_name" => $row["city_name"],

            "officer_name" => $row["officer_name"] ?: "Not Assigned",
            "officer_user_id" => (int)($row["officer_user_id"] ?? 0),

            "inspector_name" => $row["inspector_name"] ?: "Not Assigned",
            "inspector_user_id" => (int)($row["inspector_user_id"] ?? 0),

            "total_complaints" => (int)$row["total_complaints"],
            "total_areas" => (int)$row["total_areas"],
            "areas" => []
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Ward area mapping
|--------------------------------------------------------------------------
*/

$areaMapSql = "
    SELECT area_id, ward_id, area_name
    FROM areas
    ORDER BY area_name ASC
";

$areaMapResult = mysqli_query($conn, $areaMapSql);

if ($areaMapResult) {
    while ($row = mysqli_fetch_assoc($areaMapResult)) {
        $wardId = (int)$row["ward_id"];

        if (isset($wardDetails[$wardId])) {
            $wardDetails[$wardId]["areas"][] = [
                "area_id" => (int)$row["area_id"],
                "area_name" => $row["area_name"]
            ];
        }
    }
}

$successMessage = $_SESSION["ward_area_success"] ?? "";
$errorMessage = $_SESSION["ward_area_error"] ?? "";

unset($_SESSION["ward_area_success"], $_SESSION["ward_area_error"]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ward & Area Management | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">
    <link rel="stylesheet" href="../../css/central/ward-area.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body>

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="wa-page">

            <div class="wa-header">
                <div>
                    <h1>Ward & Area Management</h1>
                    <p>Select city, corporation, thana, and ward to view or update area coverage.</p>
                </div>
            </div>

            <?php if ($successMessage !== ""): ?>
                <div class="wa-alert wa-success">
                    <i class="bi bi-check-circle"></i>
                    <?php echo wa_safe($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ""): ?>
                <div class="wa-alert wa-error">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?php echo wa_safe($errorMessage); ?>
                </div>
            <?php endif; ?>

            <div class="wa-filter-card">
                <div class="wa-field">
                    <label for="citySelect">City</label>
                    <select id="citySelect">
                        <option value="">Select City</option>
                        <?php foreach ($cities as $city): ?>
                            <option value="<?php echo (int)$city["city_id"]; ?>">
                                <?php echo wa_safe($city["city_name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="wa-field">
                    <label for="cityCorporationSelect">City Corporation</label>
                    <select id="cityCorporationSelect" disabled>
                        <option value="">Select City Corporation</option>
                    </select>
                </div>

                <div class="wa-field">
                    <label for="thanaSelect">Thana</label>
                    <select id="thanaSelect" disabled>
                        <option value="">Select Thana</option>
                    </select>
                </div>

                <div class="wa-field">
                    <label for="wardSelect">Ward</label>
                    <select id="wardSelect" disabled>
                        <option value="">Select Ward</option>
                    </select>
                </div>
            </div>

            <div class="wa-empty-state" id="wardEmptyState">
                <i class="bi bi-geo-alt"></i>
                <h2>Select a ward to view area coverage</h2>
                <p>After selecting a ward, you can view areas, rename areas, add areas, or send role-specific instructions.</p>
            </div>

            <div class="wa-card" id="wardCard" hidden>
                <div class="wa-card-top">
                    <div class="wa-card-title">
                        <div class="wa-card-icon">
                            <i class="bi bi-geo-alt"></i>
                        </div>

                        <div>
                            <h2 id="wardTitle">Ward</h2>
                            <p>Ward Officer: <span id="wardOfficer">Not Assigned</span></p>
                            <p>Inspector: <span id="wardInspector">Not Assigned</span></p>
                        </div>
                    </div>

                    <button type="button" class="wa-edit-btn" id="areaEditBtn" title="Edit areas">
                        <i class="bi bi-pencil-square"></i>
                    </button>
                </div>

                <div class="wa-area-block">
                    <h3>Areas Covered</h3>
                    <div class="wa-area-chip-list" id="areaChipList"></div>
                </div>

                <div class="wa-stats-grid">
                    <div>
                        <span>Total Complaints</span>
                        <strong id="totalComplaints">0</strong>
                    </div>

                    <div>
                        <span>Total Areas</span>
                        <strong id="totalAreas">0</strong>
                    </div>
                </div>

                <div class="wa-action-grid">
                    <button type="button" class="wa-instruction-btn" id="wardInstructionBtn">
                        <span class="wa-btn-icon">
                            <i class="bi bi-send-check"></i>
                        </span>
                        <span>
                            <strong>Send Ward Instruction</strong>
                            <small>Verify complaint / assign team</small>
                        </span>
                    </button>

                    <button type="button" class="wa-inspection-btn" id="inspectionRequestBtn">
                        <span class="wa-btn-icon">
                            <i class="bi bi-clipboard2-check"></i>
                        </span>
                        <span>
                            <strong>Send Inspection Request</strong>
                            <small>Check completed work quality</small>
                        </span>
                    </button>
                </div>
            </div>

        </section>

    </main>

</div>

<div class="wa-modal-overlay" id="areaModal">
    <div class="wa-modal">
        <div class="wa-modal-header">
            <div>
                <h2>Update Ward Areas</h2>
                <p id="modalWardLabel">Selected Ward</p>
            </div>

            <button type="button" class="wa-modal-close" id="areaModalClose">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="wa-modal-body">

            <div class="wa-modal-section">
                <h3>Existing Areas</h3>
                <div class="wa-edit-area-list" id="editAreaList"></div>
            </div>

            <div class="wa-modal-section">
                <h3>Add New Area</h3>

                <form method="POST" class="wa-add-form" id="addAreaForm">
                    <input type="hidden" name="action" value="add_area">
                    <input type="hidden" name="city_id" id="addCityId">
                    <input type="hidden" name="city_cor_id" id="addCityCorId">
                    <input type="hidden" name="thana_id" id="addThanaId">
                    <input type="hidden" name="ward_id" id="addWardId">

                    <input
                        type="text"
                        name="area_name"
                        id="newAreaName"
                        placeholder="Enter new area name"
                        required
                    >

                    <button type="submit">
                        <i class="bi bi-plus-lg"></i>
                        Add Area
                    </button>
                </form>
            </div>

        </div>
    </div>
</div>

<div class="wa-modal-overlay" id="instructionModal">
    <div class="wa-modal wa-instruction-modal">
        <div class="wa-modal-header">
            <div>
                <h2 id="instructionModalTitle">Send Instruction</h2>
                <p id="instructionModalSubtitle">Select an instruction and send it.</p>
            </div>

            <button type="button" class="wa-modal-close" id="instructionModalClose">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form method="POST" class="wa-modal-body" id="instructionForm">
            <input type="hidden" name="action" value="send_instruction">
            <input type="hidden" name="receiver_user_id" id="instructionReceiverUserId">
            <input type="hidden" name="receiver_role" id="instructionReceiverRole">
            <input type="hidden" name="ward_id" id="instructionWardId">
            <input type="hidden" name="instruction_title" id="instructionTitleInput">

            <div class="wa-instruction-options" id="instructionOptions"></div>



            <div class="wa-modal-section">
                <h3>Instruction Message</h3>
                <textarea
                    name="instruction_message"
                    id="instructionMessage"
                    rows="4"
                    placeholder="Select instruction first. You can edit message before sending."
                    required
                ></textarea>
            </div>

            <button type="submit" class="wa-send-final-btn">
                <i class="bi bi-send-check"></i>
                Send Selected Instruction
            </button>
        </form>
    </div>
</div>

<form method="POST" id="renameAreaForm" hidden>
    <input type="hidden" name="action" value="rename_area">
    <input type="hidden" name="area_id" id="renameAreaId">
    <input type="hidden" name="ward_id" id="renameWardId">
    <input type="hidden" name="area_name" id="renameAreaName">
</form>

<script>
    window.wardAreaData = {
        cityCorporations: <?php echo json_encode($cityCorporations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        thanas: <?php echo json_encode($thanas, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        wards: <?php echo json_encode($wards, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        wardDetails: <?php echo json_encode($wardDetails, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
    };
</script>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/ward-area.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>