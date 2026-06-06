<?php
$activePage = "local-team-assignment";
$pageTitle = "Local Team Assignment";

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

function lta_fetchOne($conn, $sql, $types = "", $params = [])
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

function lta_fetchAll($conn, $sql, $types = "", $params = [])
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

function lta_tableColumns($conn, $tableName)
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

function lta_firstColumn($columns, $possible)
{
    foreach ($possible as $col) {
        if (in_array($col, $columns, true)) {
            return $col;
        }
    }
    return null;
}

function lta_priorityClass($priority)
{
    $p = strtolower(trim((string)$priority));
    if ($p === "high")   return "high";
    if ($p === "low")    return "low";
    return "medium";
}

function lta_formatDate($date)
{
    if (!$date) return "N/A";
    $t = strtotime($date);
    return $t ? date("M d, Y", $t) : "N/A";
}

/*
|--------------------------------------------------------------------------
| Detect maintenance_teams columns
|--------------------------------------------------------------------------
*/
$teamColumns      = lta_tableColumns($conn, "maintenance_teams");
$teamIdCol        = lta_firstColumn($teamColumns, ["maintenance_team_id", "team_id", "id"]);
$teamNameCol      = lta_firstColumn($teamColumns, ["team_name", "maintenance_team_name", "name"]);
$teamCityCorCol   = lta_firstColumn($teamColumns, ["city_cor_id", "city_corporation_id"]);
$teamAnchalCol    = lta_firstColumn($teamColumns, ["anchal_id"]);
$teamStatusCol    = lta_firstColumn($teamColumns, ["availability_status", "team_status", "status"]);
$teamCodeCol      = lta_firstColumn($teamColumns, ["team_code", "maintenance_team_code", "code"]);
$teamPhoneCol     = lta_firstColumn($teamColumns, ["team_phone", "phone", "phone_number", "contact_number"]);

if (!$teamIdCol || !$teamNameCol || !$teamCityCorCol || !$teamAnchalCol) {
    die("Maintenance team information is not available right now.");
}

/*
|--------------------------------------------------------------------------
| Get logged-in Ward Officer info
|--------------------------------------------------------------------------
*/
try {
    $wardOfficer = lta_fetchOne(
        $conn,
        "SELECT
            wo.ward_officer_id,
            wo.user_id,
            wo.assigned_ward_id,
            wo.full_name,
            w.ward_id,
            w.ward_no,
            w.ward_name,
            w.city_cor_id,
            w.anchal_id,
            an.anchal_name,
            cc.city_cor_name
        FROM ward_officers wo
        INNER JOIN wards w       ON wo.assigned_ward_id = w.ward_id
        LEFT  JOIN anchals an    ON w.anchal_id = an.anchal_id
        LEFT  JOIN city_corporations cc ON w.city_cor_id = cc.city_cor_id
        WHERE wo.user_id = ?
        LIMIT 1",
        "i",
        [$currentUserId]
    );

    if (!$wardOfficer) {
        throw new Exception("No assigned ward found for this Ward Officer.");
    }

    $wardId      = (int)$wardOfficer["assigned_ward_id"];
    $wardNo      = $wardOfficer["ward_no"]       ?? "";
    $wardName    = $wardOfficer["ward_name"]     ?? "";
    $cityCorId   = (int)$wardOfficer["city_cor_id"];
    $anchalId    = (int)$wardOfficer["anchal_id"];
    $anchalName  = $wardOfficer["anchal_name"]   ?? "Assigned Anchal";
    $cityCorName = $wardOfficer["city_cor_name"] ?? "City Corporation";
    $userName    = $wardOfficer["full_name"]     ?? ($_SESSION["user_name"] ?? "Ward Officer");

    $_SESSION["user_name"]       = $userName;
    $_SESSION["user_role_label"] = "Ward Operations";

} catch (Exception $e) {
    die($e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Helper: insert a notification row
|--------------------------------------------------------------------------
*/
function lta_insertNotification($conn, $table, $recipientUserId, $senderUserId, $complaintId, $type, $title, $message)
{
    if ($recipientUserId <= 0) {
        return; // skip invalid recipients silently
    }

    $sql = "INSERT INTO `$table`
            (recipient_user_id, sender_user_id, related_complaint_id,
             notification_type, notification_title, notification_message,
             is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) throw new Exception("Unable to complete this action. Please try again.");

    mysqli_stmt_bind_param($stmt, "iiisss",
        $recipientUserId,
        $senderUserId,
        $complaintId,
        $type,
        $title,
        $message
    );
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Unable to complete this action. Please try again.");
    }
    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| Process POST � assign team
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $complaintId       = (int)($_POST["complaint_id"]        ?? 0);
    $maintenanceTeamId = (int)($_POST["maintenance_team_id"] ?? 0);
    $deadlineAt        = trim($_POST["deadline_at"]          ?? "");
    $assignmentPriority = trim($_POST["assignment_priority"] ?? "Medium");
    $taskNote          = trim($_POST["task_note"]            ?? "");

    $allowedPriorities = ["Low", "Medium", "High"];

    if ($complaintId <= 0 || $maintenanceTeamId <= 0 || $deadlineAt === "" || !in_array($assignmentPriority, $allowedPriorities, true)) {
        $errorMessage = "Please select a valid complaint, team, deadline, and priority.";
    } else {
        mysqli_begin_transaction($conn);

        try {
            /* --- 1. Verify complaint belongs to this ward and is verified_by_ward --- */
            $complaintCheck = lta_fetchOne(
                $conn,
                "SELECT
                    c.complaint_id,
                    c.complaint_code,
                    c.complaint_status,
                    c.user_id AS citizen_user_id,
                    l.ward_id
                FROM complaints c
                INNER JOIN locations l ON c.loc_id = l.loc_id
                WHERE c.complaint_id = ?
                  AND l.ward_id = ?
                  AND c.complaint_status IN ('verified_by_ward', 'reopened')
                LIMIT 1",
                "ii",
                [$complaintId, $wardId]
            );

            if (!$complaintCheck) {
                throw new Exception("This complaint is not verified or does not belong to your assigned ward.");
            }

            $complaintCode  = (string)$complaintCheck["complaint_code"];
            $citizenUserId  = (int)$complaintCheck["citizen_user_id"];

            /* --- 2. Verify team belongs to same city corporation + anchal --- */
            $teamCheckSql = "SELECT `$teamIdCol` AS maintenance_team_id, `$teamNameCol` AS team_name, `$teamStatusCol` AS team_status
                             FROM maintenance_teams
                             WHERE `$teamIdCol` = ?
                               AND `$teamCityCorCol` = ?
                               AND `$teamAnchalCol` = ?
                             LIMIT 1";

            $teamCheck = lta_fetchOne($conn, $teamCheckSql, "iii", [$maintenanceTeamId, $cityCorId, $anchalId]);

            if (!$teamCheck) {
                throw new Exception("Selected team is not under this ward's city corporation and anchal.");
            }

            // Check if team is busy
            $checkStatus = strtolower(trim($teamCheck["team_status"] ?? ""));
            if ($checkStatus === "busy") {
                throw new Exception("The selected team is currently busy and cannot be assigned new tasks.");
            }

            $teamName = (string)$teamCheck["team_name"];

            /* --- 3. Upsert complaint_assignments --- */
            $existingAssignment = lta_fetchOne(
                $conn,
                "SELECT assignment_id FROM complaint_assignments
                 WHERE complaint_id = ? AND ward_id = ?
                 ORDER BY assignment_id DESC LIMIT 1",
                "ii",
                [$complaintId, $wardId]
            );

            if ($existingAssignment) {
                $assignmentId = (int)$existingAssignment["assignment_id"];

                $upd = mysqli_prepare($conn, "
                    UPDATE complaint_assignments
                    SET maintenance_team_id  = ?,
                        assignment_status    = 'team_assigned',
                        deadline_at          = ?,
                        assignment_priority  = ?,
                        task_note            = ?,
                        assigned_at          = CURRENT_TIMESTAMP
                    WHERE assignment_id = ?
                ");
                if (!$upd) throw new Exception("Unable to complete this action. Please try again.");
                mysqli_stmt_bind_param($upd, "isssi", $maintenanceTeamId, $deadlineAt, $assignmentPriority, $taskNote, $assignmentId);
                if (!mysqli_stmt_execute($upd)) throw new Exception("Unable to complete this action. Please try again.");
                mysqli_stmt_close($upd);

                /* Identify central officer from existing assignment's assigned_by */
                $centralOfficerUserId = (int)($existingAssignment["assigned_by"] ?? 0);
                // Re-fetch because lta_fetchOne selected only assignment_id � re-query
                $assignRow = lta_fetchOne(
                    $conn,
                    "SELECT assigned_by FROM complaint_assignments WHERE assignment_id = ? LIMIT 1",
                    "i",
                    [$assignmentId]
                );
                $centralOfficerUserId = (int)($assignRow["assigned_by"] ?? 0);

            } else {
                $ins = mysqli_prepare($conn, "
                    INSERT INTO complaint_assignments
                        (complaint_id, ward_id, maintenance_team_id, assigned_by,
                         assignment_status, assigned_at, deadline_at, assignment_priority, task_note)
                    VALUES (?, ?, ?, ?, 'team_assigned', CURRENT_TIMESTAMP, ?, ?, ?)
                ");
                if (!$ins) throw new Exception("Unable to complete this action. Please try again.");
                mysqli_stmt_bind_param($ins, "iiiisss", $complaintId, $wardId, $maintenanceTeamId, $currentUserId, $deadlineAt, $assignmentPriority, $taskNote);
                if (!mysqli_stmt_execute($ins)) throw new Exception("Unable to complete this action. Please try again.");
                mysqli_stmt_close($ins);

                $centralOfficerUserId = 0; // new assignment has no prior central officer recorded
            }

            /* --- 4. Update complaint status to team_assigned --- */
            $updComp = mysqli_prepare($conn, "
                UPDATE complaints
                SET complaint_status = 'team_assigned',
                    updated_at       = CURRENT_TIMESTAMP
                WHERE complaint_id = ?
            ");
            if (!$updComp) throw new Exception("Unable to complete this action. Please try again.");
            mysqli_stmt_bind_param($updComp, "i", $complaintId);
            if (!mysqli_stmt_execute($updComp)) throw new Exception("Unable to complete this action. Please try again.");
            mysqli_stmt_close($updComp);

            /* --- 5. Find maintenance team leader's user_id for notification --- */
            $teamLeader = lta_fetchOne(
                $conn,
                "SELECT user_id FROM maintenance_team_members
                 WHERE maintenance_team_id = ?
                   AND role = 'team_leader'
                   AND status = 'active'
                 LIMIT 1",
                "i",
                [$maintenanceTeamId]
            );
            $teamLeaderUserId = (int)($teamLeader["user_id"] ?? 0);

            /* --- 6. Send notifications (inside transaction) --- */

            // 6a. Citizen
            lta_insertNotification(
                $conn,
                "citizen_notifications",
                $citizenUserId,
                $currentUserId,
                $complaintId,
                "complaint_status_updated",
                "Team Assigned to Your Complaint",
                "Your complaint #{$complaintCode} has been assigned to maintenance team \"{$teamName}\". Work will begin soon."
            );

            // 6b. Central Officer (who routed this complaint)
            if ($centralOfficerUserId > 0) {
                lta_insertNotification(
                    $conn,
                    "central_notifications",
                    $centralOfficerUserId,
                    $currentUserId,
                    $complaintId,
                    "complaint_status_updated",
                    "Maintenance Team Assigned",
                    "Ward Officer assigned complaint #{$complaintCode} to maintenance team \"{$teamName}\"."
                );
            }

            // 6c. Maintenance team leader
            if ($teamLeaderUserId > 0) {
                lta_insertNotification(
                    $conn,
                    "maintenance_notifications",
                    $teamLeaderUserId,
                    $currentUserId,
                    $complaintId,
                    "task_assigned",
                    "New Task Assigned",
                    "Complaint #{$complaintCode} has been assigned to your team \"{$teamName}\". Please review and start work."
                );
            }

            mysqli_commit($conn);

            /* --- 7. Update team availability based on active task count --- */
            autoUpdateTeamAvailability($conn, $maintenanceTeamId);

            $successMessage = "Team \"{$teamName}\" assigned successfully. Notifications sent.";

        } catch (Exception $e) {
            mysqli_rollback($conn);
            $errorMessage = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Fetch verified_by_ward complaints for this ward
|--------------------------------------------------------------------------
*/
try {
    $verifiedComplaints = lta_fetchAll(
        $conn,
        "SELECT
            c.complaint_id,
            c.complaint_code,
            c.problem_description,
            c.address_description,
            c.complaint_status,
            c.submitted_at,
            i.issue_name,
            i.priority,
            a.area_name,
            ca.assignment_id,
            ca.assignment_status,
            ca.maintenance_team_id AS assigned_team_id,
            ca.deadline_at,
            ca.assignment_priority,
            ca.task_note,
            msr.support_request_id,
            msr.support_reason,
            msr.other_reason,
            msr.support_details,
            msr.request_status AS support_status,
            msr.ward_reply,
            msr.requested_at,
            mt.team_name
        FROM complaints c
        INNER JOIN locations l   ON c.loc_id = l.loc_id
        LEFT  JOIN areas a       ON l.area_id = a.area_id
        LEFT  JOIN issues i      ON c.issue_id = i.issue_id
        LEFT JOIN (
            SELECT ca1.*
            FROM complaint_assignments ca1
            INNER JOIN (
                SELECT complaint_id, MAX(assignment_id) as latest_id
                FROM complaint_assignments
                GROUP BY complaint_id
            ) ca2 ON ca1.assignment_id = ca2.latest_id
        ) ca ON c.complaint_id = ca.complaint_id
        LEFT JOIN maintenance_teams mt ON ca.maintenance_team_id = mt.maintenance_team_id
        LEFT JOIN (
            SELECT msr1.*
            FROM maintenance_support_requests msr1
            INNER JOIN (
                SELECT assignment_id, MAX(support_request_id) as latest_id
                FROM maintenance_support_requests
                WHERE request_status IN ('pending', 'seen', 'replied')
                GROUP BY assignment_id
            ) msr2 ON msr1.support_request_id = msr2.latest_id
        ) msr ON msr.assignment_id = ca.assignment_id
        WHERE l.ward_id = ?
          AND c.complaint_status IN ('verified_by_ward', 'team_assigned', 'reopened')
        ORDER BY
            CASE WHEN i.priority = 'High'   THEN 1
                 WHEN i.priority = 'Medium' THEN 2
                 WHEN i.priority = 'Low'    THEN 3
                 ELSE 4 END,
            c.submitted_at DESC",
        "i",
        [$wardId]
    );
} catch (Exception $e) {
    $verifiedComplaints = [];
    $errorMessage = $e->getMessage();
}

/*
|--------------------------------------------------------------------------
| Fetch maintenance teams under this city corporation + anchal
|--------------------------------------------------------------------------
*/
try {
    $statusSelect = $teamStatusCol ? "`$teamStatusCol` AS team_status" : "'Active' AS team_status";
    $codeSelect   = $teamCodeCol   ? "`$teamCodeCol`   AS team_code"   : "'' AS team_code";
    $phoneSelect  = $teamPhoneCol  ? "`$teamPhoneCol`  AS team_phone"  : "'' AS team_phone";

    $teamsSql = "SELECT
        `$teamIdCol`   AS maintenance_team_id,
        `$teamNameCol` AS team_name,
        $statusSelect,
        $codeSelect,
        $phoneSelect
    FROM maintenance_teams
    WHERE `$teamCityCorCol` = ?
      AND `$teamAnchalCol`  = ?
    ORDER BY `$teamNameCol` ASC";

    $maintenanceTeams = lta_fetchAll($conn, $teamsSql, "ii", [$cityCorId, $anchalId]);
} catch (Exception $e) {
    $maintenanceTeams = [];
    $errorMessage = $e->getMessage();
}

$totalVerified    = count($verifiedComplaints);
$totalTeams       = count($maintenanceTeams);
$highPriorityCount = 0;

foreach ($verifiedComplaints as $ci) {
    if (strtolower((string)($ci["priority"] ?? "")) === "high") {
        $highPriorityCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Local Team Assignment | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
    <link rel="stylesheet" href="../../css/ward/local-team-assignment.css">
    <link rel="stylesheet" href="../../css/ward/wardTextFix.css">
    <link rel="stylesheet" href="../../css/global/notification-target.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="ward">

<?php include "../../includes/ward/sidebar.php"; ?>

<main class="ward-main">
    <?php include "../../includes/ward/topbar.php"; ?>

    <section class="lta-page">

        <div class="lta-header">
            <div>
                <h1>Local Team Assignment</h1>
                <p>
                    Assign verified complaints from Ward <?= safeText($wardNo); ?>
                    to maintenance teams under <?= safeText($anchalName); ?>.
                </p>
            </div>

            <div class="lta-location-card">
                <span><?= safeText($cityCorName); ?></span>
                <strong><?= safeText($anchalName); ?></strong>
            </div>
        </div>

        <?php if ($successMessage !== ""): ?>
            <div class="lta-alert lta-success">
                <i class="bi bi-check-circle"></i>
                <?= safeText($successMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ""): ?>
            <div class="lta-alert lta-error">
                <i class="bi bi-exclamation-circle"></i>
                <?= safeText($errorMessage); ?>
            </div>
        <?php endif; ?>


        <div class="lta-toolbar">
            <div class="lta-search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="ltaSearch" placeholder="Search by complaint ID, issue, or area">
            </div>
        </div>

        <div class="lta-list" id="ltaComplaintList">

            <?php if (!empty($verifiedComplaints)): ?>
                <?php foreach ($verifiedComplaints as $complaint): ?>
                    <?php
                        $cId          = (int)$complaint["complaint_id"];
                        $cCode        = $complaint["complaint_code"]      ?? "";
                        $issueName    = $complaint["issue_name"]          ?: "Unknown Issue";
                        $priority     = $complaint["priority"]            ?: "Medium";
                        $areaName     = $complaint["area_name"]           ?: "Area not specified";
                        $problemDesc  = $complaint["problem_description"] ?: "No description provided.";
                        $addressDesc  = $complaint["address_description"] ?: "No address description.";
                        $submittedAt  = lta_formatDate($complaint["submitted_at"] ?? null);
                        $searchText   = strtolower($cCode . " " . $issueName . " " . $areaName . " " . $priority . " " . $problemDesc);
                    ?>

                      <article
                         class="lta-card"
                         data-complaint-id="<?= $cId; ?>"
                         data-complaint-code="<?= safeText($cCode); ?>"
                         data-notification-target="<?= $cId; ?>"
                         data-search="<?= safeText($searchText); ?>"
                         data-priority="<?= safeText($priority); ?>"
                      >
                        <div class="lta-card-top">
                            <div class="lta-card-top-left">
                                <span class="lta-code"><?= safeText($cCode); ?></span>
                                <span class="lta-priority <?= lta_priorityClass($priority); ?>">
                                    <?= safeText($priority); ?>
                                </span>
                            </div>
                            <?php if ($complaint["complaint_status"] === 'team_assigned'): ?>
                                <span class="lta-status" style="background: #e0e7ff; color: #3730a3;">Team Assigned</span>
                            <?php elseif ($complaint["complaint_status"] === 'reopened'): ?>
                                <span class="lta-status" style="background: #fef08a; color: #854d0e;">Reopened</span>
                            <?php else: ?>
                                <span class="lta-status">Verified</span>
                            <?php endif; ?>
                        </div>

                        <div class="lta-card-body">
                            <div class="lta-complaint-info">
                                <h2><?= safeText($issueName); ?></h2>
                                <p><?= safeText($problemDesc); ?></p>
                                <div class="lta-meta">
                                    <span><i class="bi bi-geo-alt"></i> <?= safeText($areaName); ?></span>
                                    <span><i class="bi bi-calendar"></i> <?= safeText($submittedAt); ?></span>
                                    <span><i class="bi bi-signpost"></i> <?= safeText($addressDesc); ?></span>
                                </div>
                            </div>

                            <?php if ($complaint["complaint_status"] === 'verified_by_ward' || $complaint["complaint_status"] === 'reopened'): ?>
                                <form method="POST" action="local-team-assignment.php" class="lta-assign-form">
                                    <input type="hidden" name="complaint_id" value="<?= $cId; ?>">

                                    <div class="lta-form-grid">
                                        <div class="lta-form-group">
                                            <label>Maintenance Team</label>
                                            <select name="maintenance_team_id" required>
                                                <option value="">Select team</option>
                                                <?php foreach ($maintenanceTeams as $team): ?>
                                                    <?php
                                                        $tid        = (int)$team["maintenance_team_id"];
                                                        $tName      = $team["team_name"]   ?? "Unnamed Team";
                                                        $tCode      = $team["team_code"]   ?? "";
                                                        $tStatus    = $team["team_status"] ?? "";
                                                        $isBusy     = (strtolower(trim($tStatus)) === "busy");
                                                    ?>
                                                    <option value="<?= $tid; ?>" <?= $isBusy ? "disabled" : ""; ?>>
                                                        <?= safeText($tName); ?>
                                                        <?= $tCode   !== "" ? " - "  . safeText($tCode)   : ""; ?>
                                                        <?= $tStatus !== "" ? " (" . safeText($tStatus) . ")" : ""; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="lta-form-group">
                                            <label>Deadline</label>
                                            <input type="date" name="deadline_at" required min="<?= date('Y-m-d'); ?>">
                                        </div>

                                        <input type="hidden" name="assignment_priority" value="<?= safeText($priority); ?>">
                                    </div>

                                    <div class="lta-form-group">
                                        <label>Task Note</label>
                                        <textarea
                                            name="task_note"
                                            rows="3"
                                            placeholder="Write a short instruction for the maintenance team"
                                        ></textarea>
                                    </div>

                                    <button
                                        type="submit"
                                        class="lta-assign-btn"
                                        <?= empty($maintenanceTeams) ? "disabled" : ""; ?>
                                    >
                                        <i class="bi bi-send"></i>
                                        Assign Team
                                    </button>
                                </form>
                            <?php else: ?>
                                <?php
                                    $today = date("Y-m-d");
                                    $deadlineDate = date("Y-m-d", strtotime($complaint["deadline_at"]));
                                    $scheduleStatus = ($deadlineDate < $today) ? "Delayed" : "On Schedule";
                                    $scheduleColor = ($scheduleStatus === "Delayed") ? "#dc3545" : "#198754";
                                ?>
                                <div class="lta-assigned-summary" style="margin-top: 15px; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                        <h3 style="font-size: 14px; margin: 0; color: #495057;">Task Assigned (Waiting to Start)</h3>
                                        <span style="font-size: 12px; font-weight: 600; color: <?= $scheduleColor; ?>; padding: 4px 8px; background: <?= $scheduleStatus === 'Delayed' ? '#f8d7da' : '#d1e7dd'; ?>; border-radius: 12px;">
                                            <?= $scheduleStatus; ?>
                                        </span>
                                    </div>
                                    <p style="margin-bottom: 5px; font-size: 14px;"><strong>Assigned Team:</strong> <?= safeText($complaint["team_name"]); ?></p>
                                    <p style="margin-bottom: 5px; font-size: 14px;"><strong>Expected Completion:</strong> <?= safeText(date("M d, Y", strtotime($complaint["deadline_at"]))); ?></p>
                                    <p style="margin: 0; font-size: 14px;"><strong>Task Note:</strong> <?= safeText($complaint["task_note"] ?: "None"); ?></p>
                                </div>
                                
                                <?php if ($complaint["support_request_id"]): ?>
                                    <div class="lta-support-block" style="background: #fff3cd; border: 1px solid #ffe69c; padding: 15px; border-radius: 6px; margin-top: 15px;">
                                        <h3 style="color: #664d03; font-size: 14px; margin-bottom: 10px; font-weight: 600;"><i class="bi bi-info-circle-fill"></i> Maintenance Team Support Request</h3>
                                        <p style="margin-bottom: 5px; font-size: 14px;"><strong>Reason:</strong> <?= safeText($complaint["support_reason"] === 'others' ? $complaint["other_reason"] : str_replace('_', ' ', $complaint["support_reason"])); ?></p>
                                        <p style="margin-bottom: 5px; font-size: 14px;"><strong>Details:</strong> <?= safeText($complaint["support_details"]); ?></p>
                                        <p style="margin: 0; font-size: 14px;"><strong>Status:</strong> <span style="text-transform: capitalize; font-weight:600;"><?= safeText($complaint["support_status"]); ?></span></p>
                                        
                                        <?php if ($complaint["support_status"] === 'pending' || $complaint["support_status"] === 'seen'): ?>
                                            <form method="POST" action="reply_support.php" class="support-reply-form">
                                                <input type="hidden" name="support_request_id" value="<?= $complaint["support_request_id"]; ?>">
                                                <input type="hidden" name="redirect_to" value="local-team-assignment.php">
                                                <textarea name="ward_reply" rows="3" required placeholder="Write your reply to the maintenance team" style="width:100%; box-sizing:border-box; resize:vertical; border:1px solid #ddd; padding:8px; border-radius:4px; font-family:inherit;"></textarea>
                                                <button type="submit" class="send-reply-btn" style="background:#0d6efd; color:#fff; border:none; padding:8px 16px; border-radius:4px; cursor:pointer;"><i class="bi bi-reply"></i> Send Reply</button>
                                            </form>
                                        <?php else: ?>
                                            <div style="margin-top: 15px; background: #e2e3e5; padding: 10px; border-radius: 4px;">
                                                <p style="margin-bottom: 5px; font-size: 13px; color: #495057;"><strong>Your Reply:</strong></p>
                                                <p style="margin: 0; font-size: 14px;"><?= safeText($complaint["ward_reply"]); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </article>

                <?php endforeach; ?>

                <div class="lta-empty" id="ltaNoResults" style="display:none;">
                    <i class="bi bi-search"></i>
                    <h2>No matching complaints</h2>
                    <p>Try a different search term.</p>
                </div>

            <?php else: ?>
                <div class="lta-empty">
                    <i class="bi bi-check-circle"></i>
                    <h2>No verified complaints waiting for team assignment</h2>
                    <p>After Ward Verification, verified complaints will appear here.</p>
                </div>
            <?php endif; ?>

        </div>

    </section>

</main>

<!-- Custom Confirm Modal -->
<div class="lta-modal-overlay" id="ltaConfirmModal">
    <div class="lta-modal-box">
        <div class="lta-modal-icon">
            <i class="bi bi-send-check"></i>
        </div>
        <h3 class="lta-modal-title">Confirm Assignment</h3>
        <p class="lta-modal-desc" id="ltaConfirmMessage">Send to team?</p>
        <div class="lta-modal-actions">
            <button class="lta-modal-btn lta-modal-cancel" id="ltaBtnCancel">Cancel</button>
            <button class="lta-modal-btn lta-modal-confirm" id="ltaBtnConfirm">Assign</button>
        </div>
    </div>
</div>

<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/local-team-assignment.js"></script>
<script src="../../js/global/notification-target.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>
