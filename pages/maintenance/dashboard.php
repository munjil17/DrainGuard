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
    'local_team_assigned' => 0,
    'in_progress' => 0,
    'solved_by_team' => 0,
    'inspector_closed' => 0,
    'inspector_rejected' => 0,
    'reopen' => 0
];

$assignedList = [];
$inProgressList = [];
$uploadProofList = [];
$feedbackList = [];

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
    // 1. KPI Stats
    $statsSql = "
        SELECT
            SUM(CASE WHEN ca.assignment_status = 'team_assigned' THEN 1 ELSE 0 END) AS local_team_assigned,
            SUM(CASE WHEN ca.assignment_status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN c.complaint_status = 'solved_by_team' THEN 1 ELSE 0 END) AS solved_by_team,
            SUM(CASE WHEN c.complaint_status = 'closed' THEN 1 ELSE 0 END) AS inspector_closed,
            SUM(CASE WHEN c.complaint_status IN ('rejected_by_central', 'rejected_by_ward', 'final_rejected', 'disputed') THEN 1 ELSE 0 END) AS inspector_rejected,
            SUM(CASE WHEN c.complaint_status = 'reopened' THEN 1 ELSE 0 END) AS reopen
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

    // 2. Assigned Tasks (Limit 3)
    $assignedSql = "
        SELECT ca.assignment_id, ca.assignment_priority, ca.deadline_at, c.complaint_code, c.address_description
        FROM complaint_assignments ca
        INNER JOIN complaints c ON c.complaint_id = ca.complaint_id
        WHERE ca.maintenance_team_id = ? AND ca.assignment_status = 'team_assigned'
        ORDER BY ca.assigned_at DESC LIMIT 3
    ";
    $assignedStmt = mysqli_prepare($conn, $assignedSql);
    if ($assignedStmt) {
        mysqli_stmt_bind_param($assignedStmt, "i", $teamId);
        mysqli_stmt_execute($assignedStmt);
        $res = mysqli_stmt_get_result($assignedStmt);
        while ($res && $r = mysqli_fetch_assoc($res)) {
            $assignedList[] = $r;
        }
        mysqli_stmt_close($assignedStmt);
    }

    // 3. In Progress Work (Limit 3)
    $inProgressSql = "
        SELECT ca.assignment_id, ca.assignment_priority, c.complaint_code, c.address_description, c.work_started_at
        FROM complaint_assignments ca
        INNER JOIN complaints c ON c.complaint_id = ca.complaint_id
        WHERE ca.maintenance_team_id = ? AND ca.assignment_status = 'in_progress'
        ORDER BY c.work_started_at DESC, ca.assigned_at DESC LIMIT 3
    ";
    $inProgressStmt = mysqli_prepare($conn, $inProgressSql);
    if ($inProgressStmt) {
        mysqli_stmt_bind_param($inProgressStmt, "i", $teamId);
        mysqli_stmt_execute($inProgressStmt);
        $res = mysqli_stmt_get_result($inProgressStmt);
        while ($res && $r = mysqli_fetch_assoc($res)) {
            $inProgressList[] = $r;
        }
        mysqli_stmt_close($inProgressStmt);
    }

    // 4. Upload Completion Proof (Limit 3)
    $uploadSql = "
        SELECT ca.assignment_id, c.complaint_code, c.address_description, c.work_started_at
        FROM complaint_assignments ca
        INNER JOIN complaints c ON c.complaint_id = ca.complaint_id
        WHERE ca.maintenance_team_id = ? AND ca.assignment_status = 'in_progress' AND c.complaint_status = 'in_progress'
        ORDER BY c.work_started_at DESC LIMIT 3
    ";
    $uploadStmt = mysqli_prepare($conn, $uploadSql);
    if ($uploadStmt) {
        mysqli_stmt_bind_param($uploadStmt, "i", $teamId);
        mysqli_stmt_execute($uploadStmt);
        $res = mysqli_stmt_get_result($uploadStmt);
        while ($res && $r = mysqli_fetch_assoc($res)) {
            $uploadProofList[] = $r;
        }
        mysqli_stmt_close($uploadStmt);
    }

    // 5. Feedback (Limit 3)
    $feedbackSql = "
        SELECT mtr.rating, mtr.created_at, c.complaint_code, u.user_name AS citizen_name
        FROM maintenance_team_reviews mtr
        LEFT JOIN complaints c ON mtr.complaint_id = c.complaint_id
        LEFT JOIN users u ON mtr.citizen_user_id = u.user_id
        WHERE mtr.maintenance_team_id = ?
        ORDER BY mtr.created_at DESC LIMIT 3
    ";
    $feedbackStmt = mysqli_prepare($conn, $feedbackSql);
    if ($feedbackStmt) {
        mysqli_stmt_bind_param($feedbackStmt, "i", $teamId);
        mysqli_stmt_execute($feedbackStmt);
        $res = mysqli_stmt_get_result($feedbackStmt);
        while ($res && $r = mysqli_fetch_assoc($res)) {
            $feedbackList[] = $r;
        }
        mysqli_stmt_close($feedbackStmt);
    }
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
    
    <style>
        .lists-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-top: 30px;
        }
        
        .list-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #eef2f6;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .list-card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.04);
            transform: translateY(-2px);
        }
        
        .list-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .list-head h2 {
            font-size: 1.15rem;
            color: #1e293b;
            margin: 0;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        
        .list-head a {
            font-size: 0.9rem;
            color: #3b82f6;
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: color 0.2s;
        }
        
        .list-head a:hover {
            color: #2563eb;
        }
        
        .list-body {
            padding: 8px 0;
            flex: 1;
        }
        
        .list-item {
            padding: 16px 24px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s ease;
        }
        
        .list-item:hover {
            background: #f8fafc;
        }
        
        .list-item:last-child {
            border-bottom: none;
        }
        
        .item-info {
            flex: 1;
            padding-right: 15px;
            overflow: hidden;
        }
        
        .item-info strong {
            display: block;
            color: #0f172a;
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .item-info small {
            display: block;
            color: #64748b;
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.4;
        }
        
        .item-action {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            min-width: max-content;
        }
        
        .empty-list {
            padding: 40px 20px;
            text-align: center;
            color: #94a3b8;
            font-size: 0.95rem;
            font-weight: 500;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }
        
        .empty-list i {
            font-size: 2rem;
            color: #cbd5e1;
        }
        
        @media (max-width: 992px) {
            .lists-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
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
                            <i class="bi bi-person-check"></i>
                        </div>
                        <strong><?php echo e($stats['local_team_assigned']); ?></strong>
                        <span>Local Team Assigned</span>
                    </article>

                    <article class="md-kpi-card">
                        <div class="md-kpi-icon icon-warning">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                        <strong><?php echo e($stats['in_progress']); ?></strong>
                        <span>In Progress</span>
                    </article>

                    <article class="md-kpi-card">
                        <div class="md-kpi-icon icon-success">
                            <i class="bi bi-tools"></i>
                        </div>
                        <strong><?php echo e($stats['solved_by_team']); ?></strong>
                        <span>Solve by Team</span>
                    </article>

                    <article class="md-kpi-card">
                        <div class="md-kpi-icon icon-primary" style="color: #3b82f6; background: #eff6ff;">
                            <i class="bi bi-check-circle-fill"></i>
                        </div>
                        <strong><?php echo e($stats['inspector_closed']); ?></strong>
                        <span>Inspector Accepted (Closed)</span>
                    </article>

                    <article class="md-kpi-card">
                        <div class="md-kpi-icon icon-danger">
                            <i class="bi bi-x-circle-fill"></i>
                        </div>
                        <strong><?php echo e($stats['inspector_rejected']); ?></strong>
                        <span>Inspector Rejected (Disputed)</span>
                    </article>

                    <article class="md-kpi-card">
                        <div class="md-kpi-icon icon-warning" style="color: #d97706; background: #fef3c7;">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </div>
                        <strong><?php echo e($stats['reopen']); ?></strong>
                        <span>Reopen</span>
                    </article>
                </div>

                <div class="lists-grid">
                    <!-- List 1: Assigned Task -->
                    <div class="list-card">
                        <div class="list-head">
                            <h2>Assigned Task</h2>
                            <a href="assigned-tasks.php">View All <i class="bi bi-chevron-right"></i></a>
                        </div>
                        <div class="list-body">
                            <?php if (count($assignedList) > 0): ?>
                                <?php foreach ($assignedList as $task): ?>
                                    <div class="list-item">
                                        <div class="item-info">
                                            <strong><?php echo e($task['complaint_code']); ?></strong>
                                            <small><?php echo e($task['address_description'] ?: 'Address not provided'); ?></small>
                                        </div>
                                        <div class="item-action">
                                            <span class="priority-pill <?php echo e(priorityClass($task['assignment_priority'])); ?>"><?php echo e($task['assignment_priority']); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-list">No Assigned Tasks</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- List 2: In Progress Work -->
                    <div class="list-card">
                        <div class="list-head">
                            <h2>In Progress Work</h2>
                            <a href="in-progress-work.php">View All <i class="bi bi-chevron-right"></i></a>
                        </div>
                        <div class="list-body">
                            <?php if (count($inProgressList) > 0): ?>
                                <?php foreach ($inProgressList as $task): ?>
                                    <div class="list-item">
                                        <div class="item-info">
                                            <strong><?php echo e($task['complaint_code']); ?></strong>
                                            <small><?php echo e($task['address_description'] ?: 'Address not provided'); ?></small>
                                        </div>
                                        <div class="item-action">
                                            <span class="status-pill status-progress" style="font-size: 0.8rem; padding: 4px 10px;">In Progress</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-list">No In Progress Work</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- List 3: Upload Completion Proof -->
                    <div class="list-card">
                        <div class="list-head">
                            <h2>Upload Completion Proof</h2>
                            <a href="upload-completion-proof.php">View All <i class="bi bi-chevron-right"></i></a>
                        </div>
                        <div class="list-body">
                            <?php if (count($uploadProofList) > 0): ?>
                                <?php foreach ($uploadProofList as $task): ?>
                                    <div class="list-item">
                                        <div class="item-info">
                                            <strong><?php echo e($task['complaint_code']); ?></strong>
                                            <small>Needs Proof</small>
                                        </div>
                                        <div class="item-action">
                                            <a href="upload-completion-proof.php?assignment_id=<?php echo e($task['assignment_id']); ?>" style="color: #3b82f6; font-size: 1.2rem;" title="Upload Proof"><i class="bi bi-upload"></i></a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-list">No Tasks Pending Proof</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- List 4: Feedback -->
                    <div class="list-card">
                        <div class="list-head">
                            <h2>Feedback</h2>
                            <a href="feedback.php">View All <i class="bi bi-chevron-right"></i></a>
                        </div>
                        <div class="list-body">
                            <?php if (count($feedbackList) > 0): ?>
                                <?php foreach ($feedbackList as $fb): ?>
                                    <div class="list-item">
                                        <div class="item-info">
                                            <strong><?php echo e($fb['complaint_code']); ?></strong>
                                            <small><?php echo e($fb['citizen_name']); ?></small>
                                        </div>
                                        <div class="item-action">
                                            <span style="color: #eab308; font-weight: bold;"><i class="bi bi-star-fill"></i> <?php echo e($fb['rating']); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-list">No Feedback Found</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </section>
        </main>
    </div>

    <script src="../../js/maintenance/sidebar.js"></script>
    <script src="../../js/maintenance/dashboard.js"></script>
<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>
