<?php
$pageTitle = "Assigned Tasks";
$activePage = "assigned-tasks";

require_once "../../config.php";
require_once "../../auth/session_check.php";

$userId = $_SESSION['user_id'] ?? 0;

/* =========================================================
   NOTIFICATION HELPER
========================================================= */
if (!function_exists('insertNotification')) {
    function insertNotification($conn, $table, $recipientId, $senderId, $complaintId, $type, $title, $message) {
        if ($recipientId <= 0) return false;
        
        $sql = "INSERT INTO `$table` (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "iiisss", $recipientId, $senderId, $complaintId, $type, $title, $message);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            return true;
        }
        return false;
    }
}

/* =========================================================
   AJAX HANDLER: START WORK
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_work') {
    header('Content-Type: application/json');

    $assignmentId = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;

    if ($userId <= 0 || $assignmentId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid request.'
        ]);
        exit;
    }

    $checkSql = "
        SELECT
            ca.assignment_id,
            ca.complaint_id,
            ca.maintenance_team_id,
            ca.assignment_status,
            c.complaint_status,
            c.complaint_code,
            c.user_id AS citizen_user_id,
            mt.team_name,
            ca.assigned_by AS assigned_by_user_id,
            ca.ward_id
        FROM complaint_assignments ca
        INNER JOIN complaints c
            ON c.complaint_id = ca.complaint_id
        INNER JOIN maintenance_team_members mtm
            ON mtm.maintenance_team_id = ca.maintenance_team_id
        INNER JOIN maintenance_teams mt
            ON mt.maintenance_team_id = ca.maintenance_team_id
        WHERE ca.assignment_id = ?
        AND mtm.user_id = ?
        LIMIT 1
    ";

    $checkStmt = mysqli_prepare($conn, $checkSql);

    if (!$checkStmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Database prepare failed.'
        ]);
        exit;
    }

    mysqli_stmt_bind_param($checkStmt, "ii", $assignmentId, $userId);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);

    if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
        mysqli_stmt_close($checkStmt);

        echo json_encode([
            'success' => false,
            'message' => 'This task is not assigned to your team.'
        ]);
        exit;
    }

    $taskRow = mysqli_fetch_assoc($checkResult);
    mysqli_stmt_close($checkStmt);

    if ($taskRow['assignment_status'] === 'in_progress') {
        echo json_encode([
            'success' => true,
            'message' => 'Work is already in progress.'
        ]);
        exit;
    }

    if ($taskRow['assignment_status'] !== 'team_assigned') {
        echo json_encode([
            'success' => false,
            'message' => 'Only assigned tasks can be started.'
        ]);
        exit;
    }

    $complaintId = (int)$taskRow['complaint_id'];
    $maintenanceTeamId = (int)$taskRow['maintenance_team_id'];

    mysqli_begin_transaction($conn);

    try {
        $updateAssignmentSql = "
            UPDATE complaint_assignments
            SET assignment_status = 'in_progress'
            WHERE assignment_id = ?
        ";

        $updateAssignmentStmt = mysqli_prepare($conn, $updateAssignmentSql);

        if (!$updateAssignmentStmt) {
            throw new Exception('Assignment update prepare failed.');
        }

        mysqli_stmt_bind_param($updateAssignmentStmt, "i", $assignmentId);

        if (!mysqli_stmt_execute($updateAssignmentStmt)) {
            throw new Exception('Failed to update assignment status.');
        }

        mysqli_stmt_close($updateAssignmentStmt);

        $updateComplaintSql = "
            UPDATE complaints
            SET 
                complaint_status = 'in_progress',
                work_started_at = NOW(),
                updated_at = NOW()
            WHERE complaint_id = ?
        ";

        $updateComplaintStmt = mysqli_prepare($conn, $updateComplaintSql);

        if (!$updateComplaintStmt) {
            throw new Exception('Complaint update prepare failed.');
        }

        mysqli_stmt_bind_param($updateComplaintStmt, "i", $complaintId);

        if (!mysqli_stmt_execute($updateComplaintStmt)) {
            throw new Exception('Failed to update complaint status.');
        }

        mysqli_stmt_close($updateComplaintStmt);

        // Fetch ward officer user ID based on ward_id
        $wardOfficerUserId = 0;
        $wardId = (int)$taskRow['ward_id'];
        $fetchWardSql = "SELECT user_id FROM ward_officers WHERE assigned_ward_id = ? LIMIT 1";
        $stmtWard = mysqli_prepare($conn, $fetchWardSql);
        if ($stmtWard) {
            mysqli_stmt_bind_param($stmtWard, "i", $wardId);
            mysqli_stmt_execute($stmtWard);
            $resWard = mysqli_stmt_get_result($stmtWard);
            if ($rowWard = mysqli_fetch_assoc($resWard)) {
                $wardOfficerUserId = (int)$rowWard['user_id'];
            }
            mysqli_stmt_close($stmtWard);
        }

        // Fetch central officer user ID
        $centralOfficerUserId = 0;
        $fetchCentralSql = "SELECT ca.assigned_by FROM complaint_assignments ca JOIN users u ON u.user_id = ca.assigned_by WHERE ca.complaint_id = ? AND u.user_role = 'central_officer' LIMIT 1";
        $stmtCentral = mysqli_prepare($conn, $fetchCentralSql);
        if ($stmtCentral) {
            mysqli_stmt_bind_param($stmtCentral, "i", $complaintId);
            mysqli_stmt_execute($stmtCentral);
            $resCentral = mysqli_stmt_get_result($stmtCentral);
            if ($rowCentral = mysqli_fetch_assoc($resCentral)) {
                $centralOfficerUserId = (int)$rowCentral['assigned_by'];
            }
            mysqli_stmt_close($stmtCentral);
        }

        $complaintCode = (string)$taskRow['complaint_code'];
        $teamName = (string)$taskRow['team_name'];
        $citizenUserId = (int)$taskRow['citizen_user_id'];

        $notifType = "maintenance_start_work";
        $notifTitle = "Maintenance Work Started";
        $notifMessage = "Maintenance Team has started work on complaint {$complaintCode}.";

        // Send Notification to Citizen
        if ($citizenUserId > 0 && function_exists('insertNotification')) {
            insertNotification($conn, "citizen_notifications", $citizenUserId, $userId, $complaintId, $notifType, $notifTitle, $notifMessage);
        }

        // Send Notification to Ward Officer
        if ($wardOfficerUserId > 0 && function_exists('insertNotification')) {
            insertNotification($conn, "ward_notifications", $wardOfficerUserId, $userId, $complaintId, $notifType, $notifTitle, $notifMessage);
        }

        // Send Notification to Central Officer
        if ($centralOfficerUserId > 0 && function_exists('insertNotification')) {
            insertNotification($conn, "central_notifications", $centralOfficerUserId, $userId, $complaintId, $notifType, $notifTitle, $notifMessage);
        }

        mysqli_commit($conn);

        // Auto update team availability
        if (function_exists('autoUpdateTeamAvailability')) {
            autoUpdateTeamAvailability($conn, $maintenanceTeamId);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Work started successfully. Notifications sent.'
        ]);
        exit;
    } catch (Exception $e) {
        mysqli_rollback($conn);

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function statusLabel($status)
{
    $labels = [
        'ward_assigned' => 'Ward Assigned',
        'team_assigned' => 'Assigned',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'submitted' => 'Submitted',
        'received' => 'Received',
        'pending_verification' => 'Pending Verification',
        'verified' => 'Verified',
        'solved_by_team' => 'Solved by Team',
        'inspector_verification' => 'Waiting Inspection',
        'closed' => 'Closed',
        'reopened' => 'Reopened',
        'disputed' => 'Disputed',
        'rejected' => 'Rejected'
    ];

    return $labels[$status] ?? ucwords(str_replace('_', ' ', (string)$status));
}

function statusClass($status)
{
    $status = strtolower((string)$status);

    if ($status === 'in_progress') {
        return 'status-progress';
    }

    if ($status === 'completed' || $status === 'solved_by_team') {
        return 'status-completed';
    }

    if ($status === 'reopened' || $status === 'disputed' || $status === 'rejected') {
        return 'status-danger';
    }

    return 'status-assigned';
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
    'anchal_id' => 0,
    'availability_status' => 'available',
    'member_name' => $_SESSION['user_name'] ?? 'Maintenance User'
];

$tasks = [];
$wardOptions = [];
$areaOptions = [];

if ($userId > 0) {
    $teamSql = "
        SELECT
            mt.maintenance_team_id,
            mt.team_name,
            mt.anchal_id,
            mt.availability_status,
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
            $teamInfo['anchal_id'] = (int)($teamRow['anchal_id'] ?? 0);
            $teamInfo['availability_status'] = $teamRow['availability_status'] ?? 'available';
            $teamInfo['member_name'] = $teamRow['full_name'] ?? $teamInfo['member_name'];
        }

        mysqli_stmt_close($teamStmt);
    }
}

$teamId = (int)$teamInfo['team_id'];
$anchalId = (int)$teamInfo['anchal_id'];

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

            c.complaint_code,
            c.complaint_status,
            c.address_description,
            c.problem_description,
            c.work_started_at,
            c.submitted_at,
            c.updated_at,

            cm.media_path,
            cm.media_type,
            cm.original_name,
            cm.file_size,
            cm.mime_type,

            w.ward_no,
            w.ward_name,

            a.area_id,
            a.area_name,

            u.user_name AS assigned_by_name,
            
            msr.request_status AS support_status,
            msr.support_reason,
            msr.other_reason,
            msr.support_details,
            msr.ward_reply,
            msr.replied_at
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
            SELECT complaint_id, MIN(media_id) AS first_media_id
            FROM complaint_media
            GROUP BY complaint_id
        ) first_media
            ON first_media.complaint_id = c.complaint_id
        LEFT JOIN complaint_media cm
            ON cm.media_id = first_media.first_media_id
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
        WHERE ca.maintenance_team_id = ?
        AND ca.assignment_status = 'team_assigned'
        AND c.complaint_status = 'team_assigned'
        ORDER BY
            CASE ca.assignment_priority
                WHEN 'High' THEN 1
                WHEN 'Medium' THEN 2
                ELSE 3
            END,
            ca.deadline_at IS NULL,
            ca.deadline_at ASC,
            ca.assigned_at DESC
    ";

    $taskStmt = mysqli_prepare($conn, $taskSql);

    if ($taskStmt) {
        mysqli_stmt_bind_param($taskStmt, "i", $teamId);
        mysqli_stmt_execute($taskStmt);
        $taskResult = mysqli_stmt_get_result($taskStmt);

        while ($taskResult && $row = mysqli_fetch_assoc($taskResult)) {
            $tasks[] = $row;
        }

        mysqli_stmt_close($taskStmt);
    }
}

if ($anchalId > 0) {
    $wardSql = "
        SELECT 
            ward_id,
            ward_no,
            ward_name
        FROM wards
        WHERE anchal_id = ?
        ORDER BY ward_no ASC
    ";

    $wardStmt = mysqli_prepare($conn, $wardSql);

    if ($wardStmt) {
        mysqli_stmt_bind_param($wardStmt, "i", $anchalId);
        mysqli_stmt_execute($wardStmt);
        $wardResult = mysqli_stmt_get_result($wardStmt);

        while ($wardResult && $wardRow = mysqli_fetch_assoc($wardResult)) {
            $wardLabel = 'Ward ' . $wardRow['ward_no'];
            $wName = trim($wardRow['ward_name'] ?? '');

            if ($wName !== '' && strcasecmp($wName, $wardLabel) !== 0 && strcasecmp($wName, (string)$wardRow['ward_no']) !== 0) {
                $wardLabel .= ' - ' . $wName;
            }

            $wardOptions[] = [
                'ward_id' => (int)$wardRow['ward_id'],
                'ward_label' => $wardLabel
            ];
        }

        mysqli_stmt_close($wardStmt);
    }

    $areaSql = "
        SELECT 
            a.area_id,
            a.area_name,
            w.ward_id,
            w.ward_no
        FROM areas a
        INNER JOIN wards w
            ON w.ward_id = a.ward_id
        WHERE w.anchal_id = ?
        ORDER BY w.ward_no ASC, a.area_name ASC
    ";

    $areaStmt = mysqli_prepare($conn, $areaSql);

    if ($areaStmt) {
        mysqli_stmt_bind_param($areaStmt, "i", $anchalId);
        mysqli_stmt_execute($areaStmt);
        $areaResult = mysqli_stmt_get_result($areaStmt);

        while ($areaResult && $areaRow = mysqli_fetch_assoc($areaResult)) {
            $areaOptions[] = [
                'area_id' => (int)$areaRow['area_id'],
                'ward_id' => (int)$areaRow['ward_id'],
                'area_label' => $areaRow['area_name']
            ];
        }

        mysqli_stmt_close($areaStmt);
    }
}

$totalTasks = count($tasks);
if (function_exists('autoUpdateTeamAvailability')) {
    $newStatus = autoUpdateTeamAvailability($conn, $teamId);
    if ($newStatus) {
        $teamInfo['availability_status'] = $newStatus;
    }
}
$availabilityClass = $teamInfo['availability_status'] === 'busy' ? 'team-busy' : 'team-available';
$availabilityLabel = ucfirst($teamInfo['availability_status']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assigned Tasks | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/maintenance/sidebar.css">
    <link rel="stylesheet" href="../../css/maintenance/topbar.css">
    <link rel="stylesheet" href="../../css/maintenance/assigned-tasks.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="maintenance">
    <div class="maintenance-layout">
        <?php include "../../includes/maintenance/sidebar.php"; ?>

        <main class="maintenance-main">
            <?php include "../../includes/maintenance/topbar.php"; ?>

            <section class="assigned-page">
                <div class="page-heading">
                    <h1>Assigned Tasks</h1>
                    <p>View and start work on tasks assigned to your team</p>
                </div>

                <div class="assigned-alert">
                    <div class="alert-left">
                        <div class="alert-icon">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>

                        <div>
                            <h2><?php echo e($totalTasks); ?> Tasks Assigned to <?php echo e($teamInfo['team_name']); ?></h2>
                            <p>Review task details and start work on priority items</p>
                        </div>
                    </div>

                    <div class="team-availability-box">
                        <span>Team Availability</span>
                        <strong class="<?php echo e($availabilityClass); ?>">
                            <?php echo e($availabilityLabel); ?>
                        </strong>
                    </div>
                </div>

                <div class="assigned-toolbar">
                    <div class="assigned-search">
                        <input type="text" id="taskSearchInput" placeholder="Search by Task ID...">
                    </div>

                    <select id="wardFilter" class="assigned-filter">
                        <option value="all">All Wards</option>
                        <?php foreach ($wardOptions as $ward): ?>
                            <option value="<?php echo e($ward['ward_id']); ?>">
                                <?php echo e($ward['ward_label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select id="areaFilter" class="assigned-filter">
                        <option value="all">All Areas</option>
                        <?php foreach ($areaOptions as $area): ?>
                            <option 
                                value="<?php echo e($area['area_id']); ?>"
                                data-ward-id="<?php echo e($area['ward_id']); ?>"
                            >
                                <?php echo e($area['area_label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select id="sortFilter" class="assigned-filter">
                        <option value="priority">Sort by: Priority</option>
                        <option value="newest">Sort by: Newest</option>
                        <option value="closest_deadline">Closest to Deadline</option>
                        <option value="overdue">Deadline Overdue</option>
                    </select>
                </div>

                <div class="assigned-task-list" id="assignedTaskList">
                    <?php if (count($tasks) > 0): ?>
                        <?php foreach ($tasks as $task): ?>
                            <?php
                            $deadlineText = 'No deadline';
                            $deadlineRaw = '';

                            if (!empty($task['deadline_at'])) {
                                $deadlineRaw = $task['deadline_at'];
                                $deadlineText = date("M d, Y", strtotime($task['deadline_at']));
                            }

                            $mediaPath = $task['media_path'] ?? '';
                            $mediaType = $task['media_type'] ?? '';
                            $hasMedia = !empty($mediaPath);
                            $hasImage = $hasMedia && $mediaType === 'image';
                            $hasVideo = $hasMedia && $mediaType === 'video';

                            $downloadPath = $hasMedia ? "../../" . $mediaPath : "#";

                            $wardText = 'Ward not found';

                            if (!empty($task['ward_name'])) {
                                $wardText = $task['ward_name'];
                            } elseif (!empty($task['ward_no'])) {
                                $wardText = 'Ward ' . $task['ward_no'];
                            }

                            $areaText = !empty($task['area_name'])
                                ? $task['area_name']
                                : 'Area not found';

                            $issueTitle = 'Drainage Complaint';
                            ?>

                            <article
                                class="task-card"
                                data-priority="<?php echo e($task['assignment_priority']); ?>"
                                data-status="<?php echo e($task['assignment_status']); ?>"
                                data-ward-id="<?php echo e($task['ward_id']); ?>"
                                data-area-id="<?php echo e($task['area_id'] ?? ''); ?>"
                                data-deadline="<?php echo e($deadlineRaw); ?>"
                                data-assigned-at="<?php echo e($task['assigned_at']); ?>"
                                data-search="<?php echo e(strtolower(
                                    ($task['complaint_code'] ?? '') . ' ' .
                                    ($task['address_description'] ?? '') . ' ' .
                                    ($task['problem_description'] ?? '') . ' ' .
                                    ($wardText ?? '') . ' ' .
                                    ($areaText ?? '') . ' ' .
                                    ($task['assignment_priority'] ?? '') . ' ' .
                                    ($task['assignment_status'] ?? '')
                                )); ?>"
                            >
                                <div class="task-media">
                                    <?php if ($hasImage): ?>
                                        <a href="<?php echo e($downloadPath); ?>" download class="media-download-link" title="Download complaint photo">
                                            <img src="../../<?php echo e($mediaPath); ?>" alt="Complaint evidence">
                                            <span class="download-hover">
                                                <i class="bi bi-download"></i>
                                                Download
                                            </span>
                                        </a>
                                    <?php elseif ($hasVideo): ?>
                                        <a href="<?php echo e($downloadPath); ?>" download class="media-download-link" title="Download complaint video">
                                            <video src="../../<?php echo e($mediaPath); ?>" muted></video>
                                            <span class="video-mark">
                                                <i class="bi bi-play-fill"></i>
                                            </span>
                                            <span class="download-hover">
                                                <i class="bi bi-download"></i>
                                                Download
                                            </span>
                                        </a>
                                    <?php else: ?>
                                        <div class="no-media">
                                            <i class="bi bi-image"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="task-content">
                                    <div class="task-topline">
                                        <div class="task-badges">
                                            <span class="code-badge"><?php echo e($task['complaint_code']); ?></span>

                                            <span class="priority-badge <?php echo e(priorityClass($task['assignment_priority'])); ?>">
                                                <?php echo e($task['assignment_priority']); ?>
                                            </span>

                                            <span class="status-badge status-assigned">
                                                Task Assigned
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($task['support_status']): ?>
                                        <div style="background: #e9ecef; border-left: 4px solid #6c757d; padding: 10px; margin-bottom: 15px; border-radius: 4px;">
                                            <p style="margin: 0; font-size: 13px;"><strong><i class="bi bi-info-circle"></i> Support Request:</strong> <?= e($task["support_reason"] === 'others' ? $task["other_reason"] : str_replace('_', ' ', $task["support_reason"])); ?></p>
                                            <p style="margin: 2px 0 0 0; font-size: 13px;"><strong>Status:</strong> <span style="text-transform: capitalize;"><?= e($task["support_status"]); ?></span></p>
                                            
                                            <?php if ($task['support_status'] === 'replied' && !empty($task['ward_reply'])): ?>
                                                <div style="background: #fff; padding: 8px; margin-top: 8px; border-radius: 4px; border: 1px solid #ced4da;">
                                                    <p style="margin: 0; font-size: 12px; color: #495057;"><strong>Ward Officer Reply:</strong></p>
                                                    <p style="margin: 0; font-size: 13px;"><?= e($task["ward_reply"]); ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <h2><?php echo e($issueTitle); ?></h2>

                                    <div class="task-info-grid">
                                        <span>
                                            <i class="bi bi-geo-alt"></i>
                                            Location:
                                            <strong><?php echo e($task['address_description'] ?: 'Address not provided'); ?></strong>
                                        </span>

                                        <span>
                                            <i class="bi bi-people"></i>
                                            Assigned From:
                                            <strong><?php echo e($task['assigned_by_name'] ?: 'Ward Officer'); ?></strong>
                                        </span>

                                        <span>
                                            <i class="bi bi-map"></i>
                                            Ward:
                                            <strong><?php echo e($wardText); ?></strong>
                                        </span>

                                        <span>
                                            <i class="bi bi-pin-map"></i>
                                            Area:
                                            <strong><?php echo e($areaText); ?></strong>
                                        </span>

                                        <span class="deadline-line">
                                            <i class="bi bi-calendar-event"></i>
                                            Deadline:
                                            <strong><?php echo e($deadlineText); ?></strong>
                                        </span>

                                        <span>
                                            <i class="bi bi-calendar-check"></i>
                                            Current Status:
                                            <strong><?php echo e(statusLabel($task['assignment_status'])); ?></strong>
                                        </span>
                                    </div>

                                    <p class="problem-text">
                                        <?php echo e($task['problem_description'] ?: 'No problem description provided.'); ?>
                                    </p>

                                    <div class="workflow-box">
                                        <p>Workflow Timeline</p>

                                        <div class="workflow-line">
                                            <span class="done">Submitted</span>
                                            <span class="done">Verified</span>
                                            <span class="current">Assigned</span>
                                            <span>In Progress</span>
                                            <span>Solved by Team</span>
                                            <span>Waiting Inspection</span>
                                            <span>Citizen Feedback</span>
                                            <span>Closed</span>
                                        </div>
                                    </div>

                                    <div class="task-actions">
                                        <button type="button" class="task-btn start-btn" data-assignment-id="<?php echo e($task['assignment_id']); ?>">
                                            <i class="bi bi-wrench"></i>
                                            Start Work
                                        </button>

                                        <button
                                            type="button"
                                            class="task-btn details-btn"
                                            data-issue="<?php echo e($issueTitle); ?>"
                                            data-priority="<?php echo e($task['assignment_priority']); ?>"
                                            data-assignment-status="<?php echo e(statusLabel($task['assignment_status'])); ?>"
                                            data-complaint-status="<?php echo e(statusLabel($task['complaint_status'])); ?>"
                                            data-address="<?php echo e($task['address_description'] ?: 'Address not provided'); ?>"
                                            data-ward="<?php echo e($wardText); ?>"
                                            data-area="<?php echo e($areaText); ?>"
                                            data-problem="<?php echo e($task['problem_description'] ?: 'No problem description provided.'); ?>"
                                            data-note="<?php echo e($task['task_note'] ?: 'No task note provided.'); ?>"
                                            data-assigned-by="<?php echo e($task['assigned_by_name'] ?: 'Ward Officer'); ?>"
                                            data-assigned-at="<?php echo e(!empty($task['assigned_at']) ? date('M d, Y h:i A', strtotime($task['assigned_at'])) : 'Not available'); ?>"
                                            data-submitted-at="<?php echo e(!empty($task['submitted_at']) ? date('M d, Y h:i A', strtotime($task['submitted_at'])) : 'Not available'); ?>"
                                            data-deadline="<?php echo e($deadlineText); ?>"
                                            data-media-path="<?php echo e($hasMedia ? '../../' . $mediaPath : ''); ?>"
                                            data-media-type="<?php echo e($mediaType); ?>"
                                        >
                                            <i class="bi bi-eye"></i>
                                            View Details
                                        </button>

                                        <button 
                                            type="button" 
                                            class="task-btn support-btn need-support-btn"
                                            data-assignment-id="<?php echo e($task['assignment_id']); ?>"
                                        >
                                            <i class="bi bi-bell"></i>
                                            Need Support
                                        </button>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (count($tasks) === 0): ?>
                        <div class="empty-state initial-empty-state">
                            <i class="bi bi-check2-circle"></i>
                            <h2>No assigned tasks found</h2>
                            <p>No complaint has been assigned to this maintenance team yet.</p>
                        </div>
                    <?php endif; ?>

                    <div class="empty-state filter-empty-state" id="filterEmptyState">
                        <i class="bi bi-inbox"></i>
                        <h2>No complaint assigned</h2>
                        <p>No complaint is assigned to this maintenance team for the selected ward, area, priority, or deadline filter.</p>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <div class="complaint-modal" id="complaintModal" aria-hidden="true">
        <div class="complaint-modal-backdrop" data-close-modal></div>

        <div class="complaint-modal-card">
            <div class="modal-head">
                <div>
                    <span id="modalComplaintCode">Complaint</span>
                    <h2 id="modalIssue">Complaint Details</h2>
                </div>

                <button type="button" class="modal-close" data-close-modal>
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="modal-media" id="modalMediaBox">
                    <div class="modal-no-media">
                        <i class="bi bi-image"></i>
                        <span>No media available</span>
                    </div>
                </div>

                <div class="modal-details">
                    <div class="detail-row"><strong>Priority</strong><span id="modalPriority">N/A</span></div>
                    <div class="detail-row"><strong>Assignment Status</strong><span id="modalAssignmentStatus">N/A</span></div>
                    <div class="detail-row"><strong>Complaint Status</strong><span id="modalComplaintStatus">N/A</span></div>
                    <div class="detail-row"><strong>Address</strong><span id="modalAddress">N/A</span></div>
                    <div class="detail-row"><strong>Ward</strong><span id="modalWard">N/A</span></div>
                    <div class="detail-row"><strong>Area</strong><span id="modalArea">N/A</span></div>
                    <div class="detail-row"><strong>Assigned By</strong><span id="modalAssignedBy">N/A</span></div>
                    <div class="detail-row"><strong>Submitted At</strong><span id="modalSubmittedAt">N/A</span></div>
                    <div class="detail-row"><strong>Assigned At</strong><span id="modalAssignedAt">N/A</span></div>
                    <div class="detail-row"><strong>Deadline</strong><span id="modalDeadline">N/A</span></div>
                </div>

                <div class="modal-text-block">
                    <h3>Problem Description</h3>
                    <p id="modalProblem">N/A</p>
                </div>

                <div class="modal-text-block">
                    <h3>Ward Officer Task Note</h3>
                    <p id="modalNote">N/A</p>
                </div>
            </div>

            <div class="modal-actions">
                <a href="#" id="modalDownloadBtn" class="modal-download-btn" download>
                    <i class="bi bi-download"></i>
                    Download Complaint Photo
                </a>

                <button type="button" class="modal-secondary-btn" data-close-modal>
                    Close
                </button>
            </div>
        </div>
    </div>

    <div class="support-modal" id="supportModal" aria-hidden="true">
        <div class="support-modal-backdrop" data-close-support-modal></div>

        <div class="support-modal-card">
            <div class="support-modal-head">
                <div>
                    <span id="supportComplaintCode">Complaint</span>
                    <h2>Need Support</h2>
                    <p>Select why your team needs support for this assigned task.</p>
                </div>

                <button type="button" class="support-modal-close" data-close-support-modal>
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <form id="supportRequestForm">
                <div class="support-form-group">
                    <label for="selectedSupportReason" class="support-label">Support Reason (Subject)</label>
                    <select id="selectedSupportReason" name="support_reason" class="support-input" required>
                        <option value="" disabled selected>Select a reason...</option>
                        <option value="equipment_needed">Equipment Needed</option>
                        <option value="extra_manpower_needed">Extra Manpower Needed</option>
                        <option value="location_access_problem">Location / Access Problem</option>
                        <option value="complaint_info_unclear">Complaint Info Unclear</option>
                        <option value="safety_risk">Safety Risk</option>
                        <option value="large_work_scope">Large Work Scope</option>
                        <option value="others">Others</option>
                    </select>
                </div>

                <div class="support-form-group" id="otherReasonGroup" style="display: none; margin-top: 15px;">
                    <label for="otherReasonInput" class="support-label">Write your support reason</label>
                    <input type="text" id="otherReasonInput" name="other_reason" class="support-input" placeholder="Specify your reason">
                </div>

                <div class="support-form-group" id="supportDetailsGroup" style="display: none; margin-top: 15px;">
                    <label for="supportDetailsInput" class="support-label">Support Details / Issue Description</label>
                    <textarea id="supportDetailsInput" name="support_details" class="support-textarea" placeholder="Explain what support is needed for this task..." required rows="4"></textarea>
                </div>

                <div class="support-modal-actions" style="margin-top: 20px;">
                    <button type="button" class="support-cancel-btn" data-close-support-modal>
                        Cancel
                    </button>
                    <button type="submit" class="support-submit-btn" id="submitSupportBtn" disabled>
                        Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="../../js/maintenance/sidebar.js"></script>
    <script src="../../js/maintenance/assigned-tasks.js"></script>
<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>