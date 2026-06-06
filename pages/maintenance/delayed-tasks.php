<?php
$pageTitle = "Delayed Tasks";
$activePage = "delayed-tasks";

require_once "../../config.php";
require_once "../../auth/session_check.php";

$userId = $_SESSION['user_id'] ?? 0;

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function jsonResponse($success, $message)
{
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);
    exit;
}

function formatDateOnly($date)
{
    if (empty($date)) {
        return 'Not available';
    }

    return date("M d, Y", strtotime($date));
}

function priorityClass($priority)
{
    $priority = strtolower((string)$priority);

    if ($priority === 'high') {
        return 'priority-high';
    }

    if ($priority === 'medium') {
        return 'priority-medium';
    }

    return 'priority-low';
}

$teamInfo = [
    'team_id' => 0,
    'team_name' => 'Maintenance Team',
    'member_name' => $_SESSION['user_name'] ?? 'Maintenance User'
];

if ($userId > 0) {
    $teamSql = "
        SELECT
            mt.maintenance_team_id,
            mt.team_name,
            mtm.full_name
        FROM maintenance_team_members mtm
        INNER JOIN maintenance_teams mt
            ON mt.maintenance_team_id = mtm.maintenance_team_id
        WHERE mtm.user_id = ?
        LIMIT 1
    ";

    $teamStmt = mysqli_prepare($conn, $teamSql);

    if ($teamStmt) {
        mysqli_stmt_bind_param($teamStmt, "i", $userId);
        mysqli_stmt_execute($teamStmt);
        $teamResult = mysqli_stmt_get_result($teamStmt);

        if ($teamResult && mysqli_num_rows($teamResult) > 0) {
            $teamRow = mysqli_fetch_assoc($teamResult);

            $teamInfo['team_id'] = (int)$teamRow['maintenance_team_id'];
            $teamInfo['team_name'] = $teamRow['team_name'] ?? $teamInfo['team_name'];
            $teamInfo['member_name'] = $teamRow['full_name'] ?? $teamInfo['member_name'];
        }

        mysqli_stmt_close($teamStmt);
    }
}

$teamId = (int)$teamInfo['team_id'];

/* =========================================================
   AJAX HANDLER
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_delay_request') {
    $assignmentId = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;
    $requestType = trim($_POST['request_type'] ?? '');
    $delayReason = trim($_POST['delay_reason'] ?? '');
    $additionalNote = trim($_POST['additional_note'] ?? '');
    $newDate = trim($_POST['new_deadline_date'] ?? '');

    if ($userId <= 0 || $teamId <= 0) {
        jsonResponse(false, 'Invalid session. Please login again.');
    }

    if ($assignmentId <= 0) {
        jsonResponse(false, 'Invalid task selected.');
    }

    if (!in_array($requestType, ['delay_notification', 'deadline_extension'], true)) {
        jsonResponse(false, 'Invalid request type.');
    }

    if ($delayReason === '') {
        jsonResponse(false, 'Delay reason is required.');
    }

    $requestedNewDeadline = null;
    $requestStatus = $requestType === 'delay_notification' ? 'sent' : 'pending';

    if ($requestType === 'deadline_extension') {
        if ($newDate === '') {
            jsonResponse(false, 'Expected new completion deadline is required.');
        }

        $dateObj = DateTime::createFromFormat('Y-m-d', $newDate);

        if (!$dateObj) {
            jsonResponse(false, 'Invalid new deadline date.');
        }

        $today = new DateTime('today');

        if ($dateObj <= $today) {
            jsonResponse(false, 'New deadline must be a future date.');
        }

        $requestedNewDeadline = $dateObj->format('Y-m-d') . ' 00:00:00';
    }

    $taskSql = "
        SELECT
            ca.assignment_id,
            ca.complaint_id,
            ca.maintenance_team_id,
            ca.assignment_status,
            ca.deadline_at,
            c.complaint_status
        FROM complaint_assignments ca
        INNER JOIN complaints c
            ON c.complaint_id = ca.complaint_id
        WHERE ca.assignment_id = ?
        AND ca.maintenance_team_id = ?
        AND ca.deadline_at IS NOT NULL
        AND ca.deadline_at < CURDATE()
        AND ca.assignment_status IN ('team_assigned', 'in_progress')
        AND c.complaint_status NOT IN ('solved_by_team', 'inspector_verification', 'closed', 'reopened', 'rejected_by_central', 'rejected_by_ward', 'final_rejected')
        LIMIT 1
    ";

    $taskStmt = mysqli_prepare($conn, $taskSql);

    if (!$taskStmt) {
        jsonResponse(false, 'Task validation prepare failed.');
    }

    mysqli_stmt_bind_param($taskStmt, "ii", $assignmentId, $teamId);
    mysqli_stmt_execute($taskStmt);
    $taskResult = mysqli_stmt_get_result($taskStmt);

    if (!$taskResult || mysqli_num_rows($taskResult) === 0) {
        mysqli_stmt_close($taskStmt);
        jsonResponse(false, 'This delayed task is not available for your team.');
    }

    $taskRow = mysqli_fetch_assoc($taskResult);
    mysqli_stmt_close($taskStmt);

    $complaintId = (int)$taskRow['complaint_id'];
    $maintenanceTeamId = (int)$taskRow['maintenance_team_id'];

    $duplicateSql = "
        SELECT delay_request_id
        FROM delay_requests
        WHERE assignment_id = ?
        AND request_type = ?
        AND request_status IN ('sent', 'pending')
        LIMIT 1
    ";

    $duplicateStmt = mysqli_prepare($conn, $duplicateSql);

    if (!$duplicateStmt) {
        jsonResponse(false, 'Duplicate check prepare failed.');
    }

    mysqli_stmt_bind_param($duplicateStmt, "is", $assignmentId, $requestType);
    mysqli_stmt_execute($duplicateStmt);
    $duplicateResult = mysqli_stmt_get_result($duplicateStmt);

    if ($duplicateResult && mysqli_num_rows($duplicateResult) > 0) {
        mysqli_stmt_close($duplicateStmt);

        if ($requestType === 'delay_notification') {
            jsonResponse(false, 'Delay notification has already been sent for this task.');
        }

        jsonResponse(false, 'Deadline extension request is already pending for this task.');
    }

    mysqli_stmt_close($duplicateStmt);

    $insertSql = "
        INSERT INTO delay_requests (
            assignment_id,
            complaint_id,
            maintenance_team_id,
            requested_by,
            delay_reason,
            requested_new_deadline,
            additional_note,
            request_type,
            request_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $insertStmt = mysqli_prepare($conn, $insertSql);

    if (!$insertStmt) {
        jsonResponse(false, 'Delay request insert prepare failed.');
    }

    $additionalNoteValue = $additionalNote !== '' ? $additionalNote : null;

    mysqli_stmt_bind_param(
        $insertStmt,
        "iiiisssss",
        $assignmentId,
        $complaintId,
        $maintenanceTeamId,
        $userId,
        $delayReason,
        $requestedNewDeadline,
        $additionalNoteValue,
        $requestType,
        $requestStatus
    );

    if (!mysqli_stmt_execute($insertStmt)) {
        mysqli_stmt_close($insertStmt);
        jsonResponse(false, 'Failed to save delay request.');
    }

    mysqli_stmt_close($insertStmt);

    if ($requestType === 'delay_notification') {
        jsonResponse(true, 'Delay notification sent to Ward Officer successfully.');
    }

    jsonResponse(true, 'Deadline extension request submitted successfully.');
}

/* =========================================================
   DELAYED TASK LIST
========================================================= */
$delayedTasks = [];

if ($teamId > 0) {
    $taskSql = "
        SELECT
            ca.assignment_id,
            ca.complaint_id,
            ca.ward_id,
            ca.maintenance_team_id,
            ca.assigned_by,
            ca.assignment_status,
            ca.assigned_at,
            ca.deadline_at,
            ca.assignment_priority,
            ca.task_note,

            DATEDIFF(CURDATE(), ca.deadline_at) AS overdue_days,

            c.complaint_code,
            c.complaint_status,
            c.address_description,
            c.problem_description,
            c.work_started_at,
            c.submitted_at,
            c.updated_at,

            w.ward_no,
            w.ward_name,

            a.area_id,
            a.area_name,

            u.user_name AS assigned_by_name,

            dr.delay_request_id,
            dr.request_type,
            dr.request_status,
            dr.requested_new_deadline,
            dr.created_at AS delay_request_created_at
        FROM complaint_assignments ca

        INNER JOIN complaints c
            ON c.complaint_id = ca.complaint_id

        LEFT JOIN wards w
            ON w.ward_id = ca.ward_id

        LEFT JOIN locations l
            ON l.loc_id = c.loc_id

        LEFT JOIN areas a
            ON a.area_id = l.area_id

        LEFT JOIN users u
            ON u.user_id = ca.assigned_by

        LEFT JOIN (
            SELECT d1.*
            FROM delay_requests d1
            INNER JOIN (
                SELECT assignment_id, MAX(delay_request_id) AS latest_delay_id
                FROM delay_requests
                GROUP BY assignment_id
            ) latest
                ON latest.latest_delay_id = d1.delay_request_id
        ) dr
            ON dr.assignment_id = ca.assignment_id

        WHERE ca.maintenance_team_id = ?
        AND ca.deadline_at IS NOT NULL
        AND ca.deadline_at < CURDATE()
        AND ca.assignment_status IN ('team_assigned', 'in_progress')
        AND c.complaint_status NOT IN ('solved_by_team', 'inspector_verification', 'closed', 'reopened', 'rejected_by_central', 'rejected_by_ward', 'final_rejected')

        ORDER BY
            overdue_days DESC,
            CASE ca.assignment_priority
                WHEN 'High' THEN 1
                WHEN 'Medium' THEN 2
                ELSE 3
            END,
            ca.deadline_at ASC
    ";

    $taskStmt = mysqli_prepare($conn, $taskSql);

    if ($taskStmt) {
        mysqli_stmt_bind_param($taskStmt, "i", $teamId);
        mysqli_stmt_execute($taskStmt);
        $taskResult = mysqli_stmt_get_result($taskStmt);

        while ($taskResult && $row = mysqli_fetch_assoc($taskResult)) {
            $delayedTasks[] = $row;
        }

        mysqli_stmt_close($taskStmt);
    }
}

$totalDelayedTasks = count($delayedTasks);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delayed Tasks | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/maintenance/sidebar.css">
    <link rel="stylesheet" href="../../css/maintenance/topbar.css">
    <link rel="stylesheet" href="../../css/maintenance/delayed-tasks.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="maintenance">
    <div class="maintenance-layout">
        <?php include "../../includes/maintenance/sidebar.php"; ?>

        <main class="maintenance-main">
            <?php include "../../includes/maintenance/topbar.php"; ?>

            <section class="delayed-page">
                <div class="page-heading">
                    <h1>Delayed Tasks</h1>
                    <p>Tasks past deadline — notify ward officer or request new deadline</p>
                </div>

                <div class="delay-alert">
                    <div class="alert-icon">
                        <i class="bi bi-clock"></i>
                    </div>

                    <div>
                        <h2><?php echo e($totalDelayedTasks); ?> Task<?php echo $totalDelayedTasks === 1 ? '' : 's'; ?> Past Deadline</h2>
                        <p>Immediate action required — notify ward officer or request a deadline extension</p>
                    </div>
                </div>

                <div class="delayed-task-list">
                    <?php if (count($delayedTasks) > 0): ?>
                        <?php foreach ($delayedTasks as $task): ?>
                            <?php
                            $wardText = 'Ward not found';

                            if (!empty($task['ward_no'])) {
                                $wardText = 'Ward ' . $task['ward_no'];
                            } elseif (!empty($task['ward_name'])) {
                                $wardText = $task['ward_name'];
                            }

                            $areaText = !empty($task['area_name']) ? $task['area_name'] : 'Area not found';

                            $overdueDays = (int)($task['overdue_days'] ?? 0);
                            $overdueText = $overdueDays . ' day' . ($overdueDays > 1 ? 's' : '') . ' overdue';

                            $assignedDate = !empty($task['assigned_at']) ? formatDateOnly($task['assigned_at']) : 'Not available';
                            $deadlineText = !empty($task['deadline_at']) ? formatDateOnly($task['deadline_at']) : 'No deadline';

                            $latestRequestText = '';

                            if (!empty($task['delay_request_id'])) {
                                if ($task['request_type'] === 'deadline_extension') {
                                    $latestRequestText = 'Latest request: Deadline Extension - ' . ucfirst($task['request_status']);
                                } else {
                                    $latestRequestText = 'Latest request: Delay Notification - ' . ucfirst($task['request_status']);
                                }
                            }
                            ?>

                            <article class="delayed-card">
                                <div class="task-badges">
                                    <span class="task-code"><?php echo e($task['complaint_code']); ?></span>
                                    <span class="overdue-badge"><?php echo e($overdueText); ?></span>
                                    <span class="priority-badge <?php echo e(priorityClass($task['assignment_priority'])); ?>">
                                        <?php echo e($task['assignment_priority']); ?> Priority
                                    </span>
                                </div>

                                <h2>Drainage Complaint</h2>

                                <div class="task-meta-grid">
                                    <p>
                                        Area:
                                        <strong><?php echo e($areaText); ?>, <?php echo e($wardText); ?></strong>
                                    </p>

                                    <p>
                                        Assigned From:
                                        <strong><?php echo e($task['assigned_by_name'] ?: 'Ward Officer'); ?></strong>
                                    </p>

                                    <p>
                                        Assigned Date:
                                        <strong><?php echo e($assignedDate); ?></strong>
                                    </p>

                                    <p>
                                        Original Deadline:
                                        <strong class="deadline-text"><?php echo e($deadlineText); ?></strong>
                                    </p>
                                </div>

                                <p class="problem-text">
                                    <?php echo e($task['problem_description'] ?: 'No problem description provided.'); ?>
                                </p>

                                <?php if ($latestRequestText !== ''): ?>
                                    <div class="latest-request-note">
                                        <i class="bi bi-info-circle"></i>
                                        <?php echo e($latestRequestText); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="delay-form-grid">
                                    <form class="delay-form delay-notification-form">
                                        <input type="hidden" name="action" value="submit_delay_request">
                                        <input type="hidden" name="request_type" value="delay_notification">
                                        <input type="hidden" name="assignment_id" value="<?php echo e($task['assignment_id']); ?>">

                                        <div class="form-block">
                                            <label>Delay Reason <span>(Required)</span></label>
                                            <textarea
                                                name="delay_reason"
                                                class="delay-reason"
                                                placeholder="Explain the delay reason for Ward Officer notification..."
                                            ></textarea>
                                        </div>

                                        <div class="form-block">
                                            <label>Additional Notes / Support Required</label>
                                            <textarea
                                                name="additional_note"
                                                class="additional-note"
                                                placeholder="Any support or additional information needed..."
                                            ></textarea>
                                        </div>

                                        <button type="submit" class="delay-btn notify-btn">
                                            <i class="bi bi-send"></i>
                                            Send Delay Notification to Ward Officer
                                        </button>
                                    </form>

                                    <form class="delay-form deadline-extension-form">
                                        <input type="hidden" name="action" value="submit_delay_request">
                                        <input type="hidden" name="request_type" value="deadline_extension">
                                        <input type="hidden" name="assignment_id" value="<?php echo e($task['assignment_id']); ?>">

                                        <div class="form-block">
                                            <label>Delay Reason / Justification <span>(Required)</span></label>
                                            <textarea
                                                name="delay_reason"
                                                class="delay-reason"
                                                placeholder="Explain why deadline extension is needed..."
                                            ></textarea>
                                        </div>

                                        <div class="form-block">
                                            <label>Expected New Completion Deadline <span>(Required)</span></label>
                                            <input type="date" name="new_deadline_date" class="new-deadline-date">
                                        </div>

                                        <div class="form-block">
                                            <label>Additional Notes / Support Required</label>
                                            <textarea
                                                name="additional_note"
                                                class="additional-note"
                                                placeholder="Any additional support needed to complete this task..."
                                            ></textarea>
                                        </div>

                                        <button type="submit" class="delay-btn extension-btn">
                                            <i class="bi bi-chat-square-text"></i>
                                            Request Deadline Extension
                                        </button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-check2-circle"></i>
                            <h2>No delayed task found</h2>
                            <p>Tasks past deadline will appear here when action is required.</p>
                        </div>
                    <?php endif; ?>
                </div>


            </section>
        </main>
    </div>

    <script src="../../js/maintenance/sidebar.js"></script>
    <script src="../../js/maintenance/delayed-tasks.js"></script>
<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>
