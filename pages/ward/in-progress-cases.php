<?php
$activePage = "in-progress-cases";
$pageTitle = "In Progress Cases";

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

function firstExistingColumn($columns, $possibleColumns)
{
    foreach ($possibleColumns as $column) {
        if (in_array($column, $columns, true)) {
            return $column;
        }
    }

    return null;
}

function formatDeadline($date)
{
    if (!$date) {
        return "N/A";
    }

    $time = strtotime($date);

    if (!$time) {
        return "N/A";
    }

    return date("M d, Y", $time);
}

function progressLabel($workStatus, $assignmentStatus, $complaintStatus)
{
    $workStatus = strtolower(trim((string)$workStatus));
    $assignmentStatus = strtolower(trim((string)$assignmentStatus));
    $complaintStatus = strtolower(trim((string)$complaintStatus));

    if ($workStatus === "completed" || $complaintStatus === "solved_by_team") {
        return "Completed";
    }

    if ($workStatus === "in_progress" || $assignmentStatus === "in_progress" || $complaintStatus === "in_progress") {
        return "Working";
    }

    if ($workStatus === "started") {
        return "Started";
    }

    if ($workStatus === "assigned" || $assignmentStatus === "team_assigned" || $complaintStatus === "team_assigned") {
        return "Assigned";
    }

    return "Assigned";
}

function progressClass($label)
{
    $label = strtolower(trim((string)$label));

    if ($label === "completed") {
        return "completed";
    }

    if ($label === "working") {
        return "working";
    }

    if ($label === "started") {
        return "started";
    }

    return "assigned";
}

function scheduleLabel($deadline, $workStatus)
{
    $workStatus = strtolower(trim((string)$workStatus));

    if ($workStatus === "completed") {
        return "Completed";
    }

    if (!$deadline) {
        return "No Deadline";
    }

    $today = date("Y-m-d");
    $deadlineDate = date("Y-m-d", strtotime($deadline));

    if ($deadlineDate < $today) {
        return "Delayed";
    }

    return "On Schedule";
}

function scheduleClass($label)
{
    $label = strtolower(trim((string)$label));

    if ($label === "delayed") {
        return "delayed";
    }

    if ($label === "completed") {
        return "completed";
    }

    if ($label === "no deadline") {
        return "no-deadline";
    }

    return "on-schedule";
}

/*
|--------------------------------------------------------------------------
| Detect maintenance_teams columns
|--------------------------------------------------------------------------
*/

$teamColumns = tableColumns($conn, "maintenance_teams");

$teamIdColumn = firstExistingColumn($teamColumns, [
    "maintenance_team_id",
    "team_id",
    "id"
]);

$teamNameColumn = firstExistingColumn($teamColumns, [
    "team_name",
    "maintenance_team_name",
    "name"
]);

if (!$teamIdColumn || !$teamNameColumn) {
    die("Maintenance team information is not available right now.");
}

/*
|--------------------------------------------------------------------------
| Get logged-in ward officer
|--------------------------------------------------------------------------
*/

try {
    $wardOfficer = fetchOne(
        $conn,
        "SELECT
            wo.ward_officer_id,
            wo.user_id,
            wo.assigned_ward_id,
            wo.full_name,
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
        throw new Exception("No assigned ward found for this Ward Officer.");
    }

    $wardId = (int)$wardOfficer["assigned_ward_id"];
    $wardNo = $wardOfficer["ward_no"] ?? "";
    $wardName = $wardOfficer["ward_name"] ?? "";
    $userName = $wardOfficer["full_name"] ?? ($_SESSION["user_name"] ?? "Ward Officer");

    $_SESSION["user_name"] = $userName;
    $_SESSION["user_role_label"] = "Ward Operations";
} catch (Exception $e) {
    die($e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Fetch active assigned/in-progress cases
|--------------------------------------------------------------------------
*/

try {
    $casesSql = "
        SELECT
            c.complaint_id,
            c.complaint_code,
            c.complaint_status,
            c.problem_description,
            c.submitted_at,
            c.updated_at,

            i.issue_name,
            i.priority AS issue_priority,

            a.area_name,

            ca.assignment_id,
            ca.maintenance_team_id,
            ca.assignment_status,
            ca.deadline_at,
            ca.assignment_priority,
            ca.task_note,
            ca.assigned_at,

            mt.`$teamNameColumn` AS team_name,

            mu.update_id,
            mu.work_status,
            mu.work_note,
            mu.started_at,
            mu.completed_at,
            mu.delayed_at,
            mu.delay_reason,
            mu.updated_at AS work_updated_at,

            msr.support_request_id,
            msr.support_reason,
            msr.other_reason,
            msr.support_details,
            msr.request_status AS support_status,
            msr.ward_reply,
            msr.requested_at
        FROM complaints c

        INNER JOIN locations l
            ON c.loc_id = l.loc_id

        LEFT JOIN areas a
            ON l.area_id = a.area_id

        LEFT JOIN issues i
            ON c.issue_id = i.issue_id

        INNER JOIN complaint_assignments ca
            ON c.complaint_id = ca.complaint_id

        LEFT JOIN maintenance_teams mt
            ON ca.maintenance_team_id = mt.`$teamIdColumn`

        LEFT JOIN (
            SELECT mu1.*
            FROM maintenance_updates mu1
            INNER JOIN (
                SELECT
                    complaint_id,
                    MAX(update_id) AS latest_update_id
                FROM maintenance_updates
                GROUP BY complaint_id
            ) latest_mu
                ON mu1.update_id = latest_mu.latest_update_id
        ) mu
            ON c.complaint_id = mu.complaint_id

        LEFT JOIN (
            SELECT msr1.*
            FROM maintenance_support_requests msr1
            INNER JOIN (
                SELECT assignment_id, MAX(support_request_id) as latest_id
                FROM maintenance_support_requests
                WHERE request_status IN ('pending', 'seen', 'replied')
                GROUP BY assignment_id
            ) msr2 ON msr1.support_request_id = msr2.latest_id
        ) msr
            ON msr.assignment_id = ca.assignment_id

        WHERE l.ward_id = ?
        AND ca.maintenance_team_id IS NOT NULL
        AND (
            c.complaint_status = 'in_progress'
            OR ca.assignment_status = 'in_progress'
            OR mu.work_status IN ('started', 'in_progress')
        )
        AND c.complaint_status NOT IN ('solved_by_team', 'inspector_verification', 'closed', 'rejected_by_central', 'rejected_by_ward', 'final_rejected', 'duplicate')

        ORDER BY
            CASE
                WHEN ca.deadline_at IS NOT NULL AND ca.deadline_at < CURDATE() THEN 1
                ELSE 2
            END,
            ca.deadline_at ASC,
            c.updated_at DESC
    ";

    $inProgressCases = fetchAllRows($conn, $casesSql, "i", [$wardId]);
} catch (Exception $e) {
    $inProgressCases = [];
    $errorMessage = $e->getMessage();
}

$totalActive = count($inProgressCases);
$onScheduleCount = 0;
$delayedCount = 0;

foreach ($inProgressCases as $caseItem) {
    $statusText = scheduleLabel($caseItem["deadline_at"] ?? null, $caseItem["work_status"] ?? "");

    if ($statusText === "Delayed") {
        $delayedCount++;
    } else {
        $onScheduleCount++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>In Progress Cases | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
    <link rel="stylesheet" href="../../css/ward/in-progress-cases.css">
    <link rel="stylesheet" href="../../css/ward/wardTextFix.css">
</head>

<body class="ward">

<?php include "../../includes/ward/sidebar.php"; ?>

<main class="ward-main">
    <?php include "../../includes/ward/topbar.php"; ?>

    <section class="ipc-page">

        <div class="ipc-header">
            <div>
                <h1>In Progress Cases</h1>
                <p>
                    Monitor active work by local maintenance teams for
                    Ward <?= safeText($wardNo); ?><?= $wardName ? " - " . safeText($wardName) : ""; ?>.
                </p>
            </div>
        </div>

        <?php if ($errorMessage !== ""): ?>
            <div class="ipc-alert ipc-error">
                <i class="bi bi-exclamation-circle"></i>
                <?= safeText($errorMessage); ?>
            </div>
        <?php endif; ?>

        <div class="ipc-summary-grid">
            <div class="ipc-summary-card">
                <div class="ipc-summary-icon active">
                    <i class="bi bi-clock"></i>
                </div>
                <div>
                    <h2><?= $totalActive; ?></h2>
                    <p>Active Cases</p>
                </div>
            </div>

            <div class="ipc-summary-card">
                <div class="ipc-summary-icon schedule">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div>
                    <h2><?= $onScheduleCount; ?></h2>
                    <p>On Schedule</p>
                </div>
            </div>

            <div class="ipc-summary-card">
                <div class="ipc-summary-icon delayed">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div>
                    <h2><?= $delayedCount; ?></h2>
                    <p>Delayed</p>
                </div>
            </div>
        </div>

        <div class="ipc-toolbar">
            <div class="ipc-search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="ipcSearch" placeholder="Search by complaint ID, area, issue, or team">
            </div>

            <select id="ipcStatusFilter">
                <option value="all">All Status</option>
                <option value="on-schedule">On Schedule</option>
                <option value="delayed">Delayed</option>
                <option value="no-deadline">No Deadline</option>
            </select>
        </div>

        <div class="ipc-table-card">
            <div class="ipc-table-responsive">
                <table class="ipc-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Issue</th>
                            <th>Area</th>
                            <th>Assigned Team</th>
                            <th>Progress</th>
                            <th>Expected Completion</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody id="ipcTableBody">
                        <?php if (!empty($inProgressCases)): ?>
                            <?php foreach ($inProgressCases as $case): ?>
                                <?php
                                    $progressText = progressLabel(
                                        $case["work_status"] ?? "",
                                        $case["assignment_status"] ?? "",
                                        $case["complaint_status"] ?? ""
                                    );

                                    $progressClass = progressClass($progressText);

                                    $scheduleText = scheduleLabel(
                                        $case["deadline_at"] ?? null,
                                        $case["work_status"] ?? ""
                                    );

                                    $scheduleClass = scheduleClass($scheduleText);

                                    $complaintCode = $case["complaint_code"] ?? "";
                                    $issueName = $case["issue_name"] ?: "Unknown Issue";
                                    $areaName = $case["area_name"] ?: "Area not specified";
                                    $teamName = $case["team_name"] ?: "Unassigned Team";
                                    $deadline = formatDeadline($case["deadline_at"] ?? null);

                                    $searchText = strtolower(
                                        $complaintCode . " " .
                                        $issueName . " " .
                                        $areaName . " " .
                                        $teamName . " " .
                                        $progressText . " " .
                                        $scheduleText
                                    );
                                ?>

                                <tr class="ipc-row <?= $scheduleClass === 'delayed' ? 'is-delayed' : ''; ?>"
                                    data-complaint-id="<?= (int)$case["complaint_id"]; ?>"
                                    data-complaint-code="<?= safeText($complaintCode); ?>"
                                    data-notification-target="<?= (int)$case["complaint_id"]; ?>"
                                    data-search="<?= safeText($searchText); ?>"
                                    data-status="<?= safeText($scheduleClass); ?>">

                                    <td>
                                        <span class="ipc-code"><?= safeText($complaintCode); ?></span>
                                    </td>

                                    <td>
                                        <strong class="ipc-issue"><?= safeText($issueName); ?></strong>
                                    </td>

                                    <td><?= safeText($areaName); ?></td>

                                    <td><?= safeText($teamName); ?></td>

                                    <td>
                                        <span class="ipc-progress <?= safeText($progressClass); ?>">
                                            <?= safeText($progressText); ?>
                                        </span>
                                    </td>

                                    <td><?= safeText($deadline); ?></td>

                                    <td>
                                        <span class="ipc-status <?= safeText($scheduleClass); ?>">
                                            <?php if ($scheduleClass === "delayed"): ?>
                                                <i class="bi bi-exclamation-triangle"></i>
                                            <?php endif; ?>
                                            <?= safeText($scheduleText); ?>
                                        </span>
                                    </td>

                                </tr>
                                
                                <?php if ($case["support_request_id"]): ?>
                                <tr class="ipc-support-row" style="background: #fff3cd;">
                                    <td colspan="7" style="padding: 15px; border-top: none; border-bottom: 2px solid #ffe69c;">
                                        <div class="lta-support-block" style="border: 1px solid #ffe69c; padding: 15px; border-radius: 6px; background: #fff;">
                                            <h3 style="color: #664d03; font-size: 14px; margin-bottom: 10px; font-weight: 600;"><i class="bi bi-info-circle-fill"></i> Maintenance Team Support Request</h3>
                                            <p style="margin-bottom: 5px; font-size: 14px;"><strong>Reason:</strong> <?= safeText($case["support_reason"] === 'others' ? $case["other_reason"] : str_replace('_', ' ', $case["support_reason"])); ?></p>
                                            <p style="margin-bottom: 5px; font-size: 14px;"><strong>Details:</strong> <?= safeText($case["support_details"]); ?></p>
                                            <p style="margin: 0; font-size: 14px;"><strong>Status:</strong> <span style="text-transform: capitalize; font-weight:600;"><?= safeText($case["support_status"]); ?></span></p>
                                            
                                            <?php if ($case["support_status"] === 'pending' || $case["support_status"] === 'seen'): ?>
                                                <form method="POST" action="reply_support.php" style="margin-top: 15px;">
                                                    <input type="hidden" name="support_request_id" value="<?= $case["support_request_id"]; ?>">
                                                    <input type="hidden" name="redirect_to" value="in-progress-cases.php">
                                                    <textarea name="ward_reply" rows="3" required placeholder="Write your reply to the maintenance team" style="width:100%; border:1px solid #ddd; padding:8px; border-radius:4px; font-family:inherit;"></textarea>
                                                    <button type="submit" style="margin-top: 10px; background:#0d6efd; color:#fff; border:none; padding:8px 16px; border-radius:4px; cursor:pointer;"><i class="bi bi-reply"></i> Send Reply</button>
                                                </form>
                                            <?php else: ?>
                                                <div style="margin-top: 15px; background: #e2e3e5; padding: 10px; border-radius: 4px;">
                                                    <p style="margin-bottom: 5px; font-size: 13px; color: #495057;"><strong>Your Reply:</strong></p>
                                                    <p style="margin: 0; font-size: 14px;"><?= safeText($case["ward_reply"]); ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="ipc-empty" id="ipcEmptyState" <?= !empty($inProgressCases) ? 'style="display:none;"' : ''; ?>>
                <i class="bi bi-inbox"></i>
                <h2>No active cases found</h2>
                <p>Assigned or in-progress complaints will appear here after team assignment.</p>
            </div>
        </div>

    </section>

</main>

<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/in-progress-cases.js"></script>

</body>
</html>
