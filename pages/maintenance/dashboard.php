<?php
$pageTitle = "Maintenance Dashboard";
$activePage = "dashboard";

require_once "../../config.php";
require_once "../../auth/session_check.php";

$userId = $_SESSION['user_id'] ?? 0;

$teamInfo = [
    'team_id' => 0,
    'team_name' => 'Maintenance Team',
    'member_name' => $_SESSION['user_name'] ?? 'Maintenance User',
    'member_role' => 'Maintenance Team'
];

$stats = [
    'assigned_today' => 0,
    'urgent_tasks' => 0,
    'near_deadline' => 0,
    'solved_by_team' => 0,
    'awaiting_inspection' => 0,
    'delayed_tasks' => 0
];

$assignedTasks = [];
$recentActivities = [];

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function roleLabel($role)
{
    if ($role === 'team_leader') {
        return 'Team Leader';
    }

    if ($role === 'assistant_team_leader') {
        return 'Assistant Team Leader';
    }

    if ($role === 'worker') {
        return 'Worker';
    }

    return 'Maintenance Team';
}

function statusLabel($status)
{
    $labels = [
        'ward_assigned' => 'Ward Assigned',
        'team_assigned' => 'Assigned',
        'in_progress' => 'In Progress',
        'completed' => 'Solved by Team',
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

function statusClass($status)
{
    $status = strtolower((string)$status);

    if ($status === 'in_progress') {
        return 'status-progress';
    }

    if ($status === 'completed' || $status === 'solved_by_team') {
        return 'status-completed';
    }

    if ($status === 'inspector_verification') {
        return 'status-inspection';
    }

    if ($status === 'reopened' || $status === 'disputed' || $status === 'rejected') {
        return 'status-danger';
    }

    return 'status-assigned';
}

if ($userId > 0) {
    $teamSql = "
        SELECT
            mt.maintenance_team_id,
            mt.team_name,
            mtm.full_name,
            mtm.role
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
            $row = mysqli_fetch_assoc($teamResult);

            $teamInfo['team_id'] = (int)$row['maintenance_team_id'];
            $teamInfo['team_name'] = $row['team_name'] ?? $teamInfo['team_name'];
            $teamInfo['member_name'] = $row['full_name'] ?? $teamInfo['member_name'];
            $teamInfo['member_role'] = roleLabel($row['role'] ?? '');
        }

        mysqli_stmt_close($teamStmt);
    }
}

$teamId = (int)$teamInfo['team_id'];

if ($teamId > 0) {
    $statsSql = "
        SELECT
            SUM(CASE 
                WHEN DATE(ca.assigned_at) = CURDATE() 
                THEN 1 ELSE 0 
            END) AS assigned_today,

            SUM(CASE 
                WHEN ca.assignment_priority = 'High'
                AND ca.assignment_status IN ('team_assigned', 'in_progress')
                THEN 1 ELSE 0 
            END) AS urgent_tasks,

            SUM(CASE 
                WHEN ca.deadline_at IS NOT NULL
                AND ca.deadline_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
                AND ca.assignment_status IN ('team_assigned', 'in_progress')
                THEN 1 ELSE 0 
            END) AS near_deadline,

            SUM(CASE 
                WHEN ca.assignment_status = 'completed'
                OR c.complaint_status IN ('solved_by_team', 'inspector_verification', 'closed')
                THEN 1 ELSE 0 
            END) AS solved_by_team,

            SUM(CASE 
                WHEN c.complaint_status = 'inspector_verification'
                THEN 1 ELSE 0 
            END) AS awaiting_inspection,

            SUM(CASE 
                WHEN ca.deadline_at IS NOT NULL
                AND ca.deadline_at < CURDATE()
                AND ca.assignment_status IN ('team_assigned', 'in_progress')
                THEN 1 ELSE 0 
            END) AS delayed_tasks

        FROM complaint_assignments ca
        INNER JOIN complaints c
            ON c.complaint_id = ca.complaint_id
        WHERE ca.maintenance_team_id = ?
    ";

    $statsStmt = mysqli_prepare($conn, $statsSql);

    if ($statsStmt) {
        mysqli_stmt_bind_param($statsStmt, "i", $teamId);
        mysqli_stmt_execute($statsStmt);
        $statsResult = mysqli_stmt_get_result($statsStmt);

        if ($statsResult && mysqli_num_rows($statsResult) > 0) {
            $row = mysqli_fetch_assoc($statsResult);

            foreach ($stats as $key => $value) {
                $stats[$key] = (int)($row[$key] ?? 0);
            }
        }

        mysqli_stmt_close($statsStmt);
    }

    $tasksSql = "
        SELECT
            ca.assignment_id,
            ca.assignment_status,
            ca.assigned_at,
            ca.deadline_at,
            ca.assignment_priority,
            ca.task_note,
            c.complaint_id,
            c.complaint_code,
            c.complaint_status,
            c.address_description,
            c.problem_description,
            c.work_started_at,
            c.submitted_at,
            cm.media_path,
            cm.media_type
        FROM complaint_assignments ca
        INNER JOIN complaints c
            ON c.complaint_id = ca.complaint_id
        LEFT JOIN (
            SELECT complaint_id, MIN(media_id) AS first_media_id
            FROM complaint_media
            GROUP BY complaint_id
        ) first_media
            ON first_media.complaint_id = c.complaint_id
        LEFT JOIN complaint_media cm
            ON cm.media_id = first_media.first_media_id
        WHERE ca.maintenance_team_id = ?
        AND ca.assignment_status IN ('team_assigned', 'in_progress')
        ORDER BY
            CASE ca.assignment_priority
                WHEN 'High' THEN 1
                WHEN 'Medium' THEN 2
                ELSE 3
            END,
            ca.deadline_at IS NULL,
            ca.deadline_at ASC,
            ca.assigned_at DESC
        LIMIT 5
    ";

    $tasksStmt = mysqli_prepare($conn, $tasksSql);

    if ($tasksStmt) {
        mysqli_stmt_bind_param($tasksStmt, "i", $teamId);
        mysqli_stmt_execute($tasksStmt);
        $tasksResult = mysqli_stmt_get_result($tasksStmt);

        while ($tasksResult && $row = mysqli_fetch_assoc($tasksResult)) {
            $assignedTasks[] = $row;
        }

        mysqli_stmt_close($tasksStmt);
    }

    $activitySql = "
        SELECT
            ca.assignment_id,
            ca.assignment_status,
            ca.assignment_priority,
            ca.assigned_at,
            c.complaint_code,
            c.complaint_status,
            c.updated_at,
            c.work_started_at
        FROM complaint_assignments ca
        INNER JOIN complaints c
            ON c.complaint_id = ca.complaint_id
        WHERE ca.maintenance_team_id = ?
        ORDER BY COALESCE(c.updated_at, ca.assigned_at) DESC
        LIMIT 5
    ";

    $activityStmt = mysqli_prepare($conn, $activitySql);

    if ($activityStmt) {
        mysqli_stmt_bind_param($activityStmt, "i", $teamId);
        mysqli_stmt_execute($activityStmt);
        $activityResult = mysqli_stmt_get_result($activityStmt);

        while ($activityResult && $row = mysqli_fetch_assoc($activityResult)) {
            $recentActivities[] = $row;
        }

        mysqli_stmt_close($activityStmt);
    }
}

$todayTaskCount = $stats['assigned_today'];
$delayRate = 0;

$totalActive = $stats['assigned_today'] + $stats['urgent_tasks'] + $stats['near_deadline'] + $stats['solved_by_team'];

if ($totalActive > 0) {
    $delayRate = round(($stats['delayed_tasks'] / $totalActive) * 100);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Maintenance Dashboard | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/maintenance/sidebar.css">
    <link rel="stylesheet" href="../../css/maintenance/topbar.css">
    <link rel="stylesheet" href="../../css/maintenance/dashboard.css">
</head>

<body class="maintenance">
    <div class="maintenance-layout">
        <?php include "../../includes/maintenance/sidebar.php"; ?>

        <main class="maintenance-main">
            <?php include "../../includes/maintenance/topbar.php"; ?>

            <section class="maintenance-dashboard">
                <div class="md-hero">
                    <div>
                        <span class="md-hero-badge">Field Maintenance Team</span>
                        <h1><?php echo e($teamInfo['team_name']); ?> — Daily Work Queue</h1>
                        <p>Complete assigned tasks, upload work photos, and mark jobs as solved by team.</p>
                    </div>

                    <div class="md-hero-count">
                        <span>Today's Tasks</span>
                        <strong><?php echo e($todayTaskCount); ?></strong>
                    </div>
                </div>

                <div class="md-notice">
                    <div class="md-notice-icon">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                    <div>
                        <h3>Important: Team Work Scope</h3>
                        <p>
                            You can mark tasks as “Solved by Team” after completing work.
                            Final closure still requires citizen feedback and inspector verification.
                        </p>
                    </div>
                </div>

                <div class="md-kpi-grid">
                    <article class="md-kpi-card">
                        <div class="md-kpi-icon icon-info">
                            <i class="bi bi-list-check"></i>
                        </div>
                        <strong><?php echo e($stats['assigned_today']); ?></strong>
                        <span>Assigned Jobs Today</span>
                    </article>

                    <article class="md-kpi-card">
                        <div class="md-kpi-icon icon-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <strong><?php echo e($stats['urgent_tasks']); ?></strong>
                        <span>Urgent Tasks</span>
                    </article>

                    <article class="md-kpi-card">
                        <div class="md-kpi-icon icon-warning">
                            <i class="bi bi-clock"></i>
                        </div>
                        <strong><?php echo e($stats['near_deadline']); ?></strong>
                        <span>Tasks Near Deadline</span>
                    </article>

                    <article class="md-kpi-card">
                        <div class="md-kpi-icon icon-success">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <strong><?php echo e($stats['solved_by_team']); ?></strong>
                        <span>Solved by Team</span>
                    </article>

                    <article class="md-kpi-card">
                        <div class="md-kpi-icon icon-eye">
                            <i class="bi bi-eye"></i>
                        </div>
                        <strong><?php echo e($stats['awaiting_inspection']); ?></strong>
                        <span>Awaiting Inspection</span>
                    </article>
                </div>

                <div class="md-section-card">
                    <div class="md-section-head">
                        <h2>Assigned Tasks</h2>
                        <a href="assigned-tasks.php">
                            View All Tasks
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>

                    <div class="md-task-list">
                        <?php if (count($assignedTasks) > 0): ?>
                            <?php foreach ($assignedTasks as $task): ?>
                                <?php
                                $deadlineText = 'No deadline';

                                if (!empty($task['deadline_at'])) {
                                    $deadlineText = date("M d, Y", strtotime($task['deadline_at']));
                                }

                                $mediaPath = $task['media_path'] ?? '';
                                $hasImage = !empty($mediaPath) && ($task['media_type'] ?? '') === 'image';
                                ?>

                                <article class="md-task-card">
                                    <div class="md-task-media">
                                        <?php if ($hasImage): ?>
                                            <img src="../../<?php echo e($mediaPath); ?>" alt="Complaint media">
                                        <?php else: ?>
                                            <i class="bi bi-image"></i>
                                        <?php endif; ?>
                                    </div>

                                    <div class="md-task-body">
                                        <div class="md-task-meta">
                                            <span class="task-code"><?php echo e($task['complaint_code']); ?></span>

                                            <span class="priority-pill <?php echo e(priorityClass($task['assignment_priority'])); ?>">
                                                <?php echo e($task['assignment_priority']); ?>
                                            </span>

                                            <span class="status-pill <?php echo e(statusClass($task['assignment_status'])); ?>">
                                                <?php echo e(statusLabel($task['assignment_status'])); ?>
                                            </span>
                                        </div>

                                        <h3>Drainage Complaint</h3>

                                        <div class="md-task-location">
                                            <span>
                                                <i class="bi bi-geo-alt"></i>
                                                <?php echo e($task['address_description'] ?: 'Address not provided'); ?>
                                            </span>

                                            <span>
                                                <i class="bi bi-calendar-event"></i>
                                                <?php echo e($deadlineText); ?>
                                            </span>
                                        </div>

                                        <p>
                                            <?php echo e($task['problem_description'] ?: $task['task_note'] ?: 'No problem description available.'); ?>
                                        </p>

                                        <div class="md-workflow">
                                            <span class="done">Submitted</span>
                                            <span class="done">Verified</span>
                                            <span class="<?php echo $task['assignment_status'] === 'team_assigned' ? 'current' : 'done'; ?>">Assigned</span>
                                            <span class="<?php echo $task['assignment_status'] === 'in_progress' ? 'current' : ''; ?>">In Progress</span>
                                            <span>Solved by Team</span>
                                            <span>Waiting Inspection</span>
                                            <span>Closed</span>
                                        </div>

                                        <div class="md-task-actions">
                                            <?php if ($task['assignment_status'] === 'team_assigned'): ?>
                                                <button type="button" class="action-btn start-btn" data-assignment-id="<?php echo e($task['assignment_id']); ?>">
                                                    <i class="bi bi-wrench"></i>
                                                    Start Work
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="action-btn progress-btn" disabled>
                                                    <i class="bi bi-hourglass-split"></i>
                                                    Work Started
                                                </button>
                                            <?php endif; ?>

                                            <a href="assigned-tasks.php?assignment_id=<?php echo e($task['assignment_id']); ?>" class="action-btn detail-btn">
                                                <i class="bi bi-eye"></i>
                                                View Details
                                            </a>

                                            <a href="upload-completion-proof.php?assignment_id=<?php echo e($task['assignment_id']); ?>" class="action-btn proof-btn">
                                                <i class="bi bi-upload"></i>
                                                Upload Proof
                                            </a>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="md-empty-state">
                                <i class="bi bi-check2-circle"></i>
                                <h3>No active assigned tasks</h3>
                                <p>There are no assigned or in-progress tasks for this maintenance team right now.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="md-bottom-grid">
                    <section class="md-panel-card">
                        <div class="md-panel-title">
                            <div class="md-panel-icon activity-icon">
                                <i class="bi bi-activity"></i>
                            </div>
                            <h2>Recent Activity Feed</h2>
                        </div>

                        <div class="activity-list">
                            <?php if (count($recentActivities) > 0): ?>
                                <?php foreach ($recentActivities as $activity): ?>
                                    <div class="activity-item">
                                        <div class="activity-symbol">
                                            <i class="bi bi-tools"></i>
                                        </div>

                                        <div>
                                            <h4>
                                                <?php echo e($activity['complaint_code']); ?>
                                                — <?php echo e(statusLabel($activity['assignment_status'])); ?>
                                            </h4>
                                            <p>
                                                Updated:
                                                <?php
                                                $activityTime = $activity['updated_at'] ?: $activity['assigned_at'];
                                                echo e(date("M d, Y h:i A", strtotime($activityTime)));
                                                ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="activity-item">
                                    <div class="activity-symbol">
                                        <i class="bi bi-info-circle"></i>
                                    </div>

                                    <div>
                                        <h4>No recent activity</h4>
                                        <p>Team activity will appear here after tasks are assigned.</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="md-panel-card">
                        <div class="md-panel-title">
                            <div class="md-panel-icon performance-icon">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                            <h2>Team Performance Snapshot</h2>
                        </div>

                        <div class="performance-list">
                            <div class="performance-box success-box">
                                <div>
                                    <h4>Solved by Team</h4>
                                    <p>Tasks moved to solved/inspection stage</p>
                                </div>
                                <strong><?php echo e($stats['solved_by_team']); ?></strong>
                            </div>

                            <div class="performance-box warning-box">
                                <div>
                                    <h4>Awaiting Inspection</h4>
                                    <p>Needs inspector verification</p>
                                </div>
                                <strong><?php echo e($stats['awaiting_inspection']); ?></strong>
                            </div>

                            <div class="performance-box neutral-box">
                                <div>
                                    <h4>Delay Rate</h4>
                                    <p>Based on active workload</p>
                                </div>
                                <strong><?php echo e($delayRate); ?>%</strong>
                            </div>
                        </div>
                    </section>
                </div>

                <?php if ($stats['delayed_tasks'] > 0): ?>
                    <div class="md-delay-alert">
                        <div>
                            <i class="bi bi-clock-history"></i>
                            <div>
                                <h3>Delayed Task Alert</h3>
                                <p><?php echo e($stats['delayed_tasks']); ?> task(s) are past the deadline.</p>
                            </div>
                        </div>

                        <a href="delayed-tasks.php">View Delayed</a>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <script src="../../js/maintenance/sidebar.js"></script>
    <script src="../../js/maintenance/dashboard.js"></script>
</body>
</html>