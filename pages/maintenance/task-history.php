<?php
$pageTitle = "Task History";
$activePage = "task-history";

require_once "../../config.php";
require_once "../../auth/session_check.php";

$userId = $_SESSION['user_id'] ?? 0;

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function historyStatusLabel($status)
{
    $status = strtolower((string)$status);

    if ($status === 'verified' || $status === 'accepted') {
        return 'Verified';
    }

    if ($status === 'team_assigned') {
        return 'Assigned';
    }

    if ($status === 'in_progress') {
        return 'In Progress';
    }

    if ($status === 'solved_by_team' || $status === 'submitted') {
        return 'Solved by Team';
    }

    if ($status === 'closed') {
        return 'Closed';
    }

    if ($status === 'rejected') {
        return 'Rejected';
    }

    if ($status === 'reopened') {
        return 'Reopened';
    }

    return ucwords(str_replace('_', ' ', $status));
}

function historyStatusClass($status)
{
    $status = strtolower((string)$status);

    if ($status === 'verified' || $status === 'accepted' || $status === 'closed') {
        return 'verified';
    }

    if ($status === 'team_assigned') {
        return 'assigned';
    }

    if ($status === 'in_progress') {
        return 'progress';
    }

    if ($status === 'solved_by_team' || $status === 'submitted') {
        return 'solved';
    }

    if ($status === 'rejected' || $status === 'reopened') {
        return 'rejected';
    }

    return 'pending';
}

function formatDateOnly($date)
{
    if (empty($date)) {
        return 'Not available';
    }

    return date("M d, Y", strtotime($date));
}

function formatDateTime($date)
{
    if (empty($date)) {
        return 'Not available';
    }

    return date("M d, Y h:i A", strtotime($date));
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

$historyTasks = [];
$areaOptions = [];

if ($teamId > 0) {
    $historySql = "
        SELECT
            ca.assignment_id,
            ca.complaint_id,
            ca.assignment_status,
            ca.assignment_priority,
            ca.assigned_at,
            ca.deadline_at,

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

            MAX(mp.uploaded_at) AS proof_submitted_at,
            COUNT(mp.proof_id) AS total_proofs,
            SUM(CASE WHEN mp.media_type = 'image' THEN 1 ELSE 0 END) AS total_images,
            SUM(CASE WHEN mp.media_type = 'video' THEN 1 ELSE 0 END) AS total_videos,
            SUM(CASE WHEN mp.proof_status = 'accepted' THEN 1 ELSE 0 END) AS accepted_proofs,
            SUM(CASE WHEN mp.proof_status = 'rejected' THEN 1 ELSE 0 END) AS rejected_proofs,
            SUM(CASE WHEN mp.proof_status = 'submitted' THEN 1 ELSE 0 END) AS submitted_proofs,

            CASE
                WHEN SUM(CASE WHEN mp.proof_status = 'rejected' THEN 1 ELSE 0 END) > 0 THEN 'rejected'
                WHEN SUM(CASE WHEN mp.proof_status = 'accepted' THEN 1 ELSE 0 END) > 0 THEN 'accepted'
                WHEN SUM(CASE WHEN mp.proof_status = 'submitted' THEN 1 ELSE 0 END) > 0 THEN 'submitted'
                ELSE ca.assignment_status
            END AS final_status,

            SUBSTRING_INDEX(
                GROUP_CONCAT(mp.proof_note ORDER BY mp.uploaded_at DESC SEPARATOR '|||'),
                '|||',
                1
            ) AS latest_proof_note,

            GROUP_CONCAT(
                CONCAT_WS(
                    '::',
                    mp.proof_id,
                    mp.media_type,
                    mp.media_path,
                    mp.original_name,
                    mp.proof_status,
                    DATE_FORMAT(mp.uploaded_at, '%b %d, %Y %h:%i %p')
                )
                ORDER BY mp.uploaded_at DESC
                SEPARATOR '||'
            ) AS proof_files

        FROM complaint_assignments ca

        INNER JOIN complaints c
            ON c.complaint_id = ca.complaint_id

        LEFT JOIN maintenance_proofs mp
            ON mp.assignment_id = ca.assignment_id
            AND mp.proof_stage = 'after'

        LEFT JOIN wards w
            ON w.ward_id = ca.ward_id

        LEFT JOIN locations l
            ON l.loc_id = c.loc_id

        LEFT JOIN areas a
            ON a.area_id = l.area_id

        LEFT JOIN users u
            ON u.user_id = ca.assigned_by

        WHERE ca.maintenance_team_id = ?
        AND (
            ca.assignment_status IN ('team_assigned', 'in_progress', 'completed')
            OR c.complaint_status IN ('verified', 'in_progress', 'solved_by_team', 'inspector_verification', 'closed', 'reopened', 'rejected')
        )

        GROUP BY
            ca.assignment_id,
            ca.complaint_id,
            ca.assignment_status,
            ca.assignment_priority,
            ca.assigned_at,
            ca.deadline_at,
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
            u.user_name

        ORDER BY
            CASE ca.assignment_status
                WHEN 'team_assigned' THEN 1
                WHEN 'in_progress' THEN 2
                WHEN 'completed' THEN 3
                ELSE 4
            END,
            c.updated_at DESC
    ";

    $historyStmt = mysqli_prepare($conn, $historySql);

    if ($historyStmt) {
        mysqli_stmt_bind_param($historyStmt, "i", $teamId);
        mysqli_stmt_execute($historyStmt);
        $historyResult = mysqli_stmt_get_result($historyStmt);

        while ($historyResult && $row = mysqli_fetch_assoc($historyResult)) {
            $historyTasks[] = $row;
        }

        mysqli_stmt_close($historyStmt);
    }

    $areaSql = "
        SELECT DISTINCT
            a.area_id,
            a.area_name
        FROM complaint_assignments ca
        INNER JOIN complaints c
            ON c.complaint_id = ca.complaint_id
        INNER JOIN locations l
            ON l.loc_id = c.loc_id
        INNER JOIN areas a
            ON a.area_id = l.area_id
        WHERE ca.maintenance_team_id = ?
        AND (
            ca.assignment_status IN ('team_assigned', 'in_progress', 'completed')
            OR c.complaint_status IN ('verified', 'in_progress', 'solved_by_team', 'inspector_verification', 'closed', 'reopened', 'rejected')
        )
        ORDER BY a.area_name ASC
    ";

    $areaStmt = mysqli_prepare($conn, $areaSql);

    if ($areaStmt) {
        mysqli_stmt_bind_param($areaStmt, "i", $teamId);
        mysqli_stmt_execute($areaStmt);
        $areaResult = mysqli_stmt_get_result($areaStmt);

        while ($areaResult && $areaRow = mysqli_fetch_assoc($areaResult)) {
            $areaOptions[] = $areaRow;
        }

        mysqli_stmt_close($areaStmt);
    }
}

/*
    KPI FORMULA:

    Total Task Complete:
    assignment_status = completed OR complaint_status = solved_by_team/closed

    Complete This Month:
    completed tasks where proof_submitted_at/updated_at is current month

    Assigned Tasks:
    assignment_status = team_assigned

    Inspector Accepted:
    maintenance_proofs.proof_status = accepted

    Success Rate:
    Inspector Accepted / Total Submitted to Inspector * 100
*/
$totalTaskComplete = 0;
$completeThisMonth = 0;
$totalAssignedBeforeStart = 0;
$totalInspectorAccepted = 0;
$totalSubmittedToInspector = 0;

$currentMonth = date('Y-m');

foreach ($historyTasks as $task) {
    $complaintStatus = $task['complaint_status'] ?? '';
    $assignmentStatus = $task['assignment_status'] ?? '';
    $finalStatus = $task['final_status'] ?? '';
    $proofSubmittedAt = $task['proof_submitted_at'] ?? null;
    $totalProofs = (int)($task['total_proofs'] ?? 0);

    if (
        $assignmentStatus === 'completed'
        || $complaintStatus === 'solved_by_team'
        || $complaintStatus === 'closed'
    ) {
        $totalTaskComplete++;

        $monthSource = !empty($proofSubmittedAt) ? $proofSubmittedAt : ($task['updated_at'] ?? null);

        if (!empty($monthSource) && date('Y-m', strtotime($monthSource)) === $currentMonth) {
            $completeThisMonth++;
        }
    }

    if ($assignmentStatus === 'team_assigned') {
        $totalAssignedBeforeStart++;
    }

    if ($totalProofs > 0) {
        $totalSubmittedToInspector++;
    }

    if ($finalStatus === 'accepted') {
        $totalInspectorAccepted++;
    }
}

$successRate = $totalSubmittedToInspector > 0
    ? round(($totalInspectorAccepted / $totalSubmittedToInspector) * 100)
    : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Task History | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/maintenance/sidebar.css">
    <link rel="stylesheet" href="../../css/maintenance/topbar.css">
    <link rel="stylesheet" href="../../css/maintenance/task-history.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="maintenance">
    <div class="maintenance-layout">
        <?php include "../../includes/maintenance/sidebar.php"; ?>

        <main class="maintenance-main">
            <?php include "../../includes/maintenance/topbar.php"; ?>

            <section class="history-page">
                <div class="page-heading">
                    <h1>Task History</h1>
                    <p>All assigned, in-progress, submitted, and inspector-accepted maintenance tasks</p>
                </div>

                <div class="history-kpi-grid">
                    <article class="history-kpi-card">
                        <div class="kpi-icon green-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <strong><?php echo e($totalTaskComplete); ?></strong>
                        <span>Total Task Complete</span>
                    </article>

                    <article class="history-kpi-card">
                        <div class="kpi-icon blue-icon">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                        <strong><?php echo e($completeThisMonth); ?></strong>
                        <span>Complete This Month</span>
                    </article>

                    <article class="history-kpi-card">
                        <div class="kpi-icon amber-icon">
                            <i class="bi bi-clipboard-check"></i>
                        </div>
                        <strong><?php echo e($totalAssignedBeforeStart); ?></strong>
                        <span>Assigned Tasks</span>
                    </article>

                    <article class="history-kpi-card">
                        <div class="kpi-icon blue-icon">
                            <i class="bi bi-patch-check"></i>
                        </div>
                        <strong><?php echo e($totalInspectorAccepted); ?></strong>
                        <span>Inspector Accepted</span>
                    </article>


                </div>

                <div class="history-toolbar">
                    <div class="history-search">
                        <input type="text" id="historySearchInput" placeholder="Search by Task ID...">
                    </div>

                    <select id="statusFilter" class="history-filter">
                        <option value="all">All Status</option>
                        <option value="assigned">Assigned</option>
                        <option value="progress">In Progress</option>
                        <option value="solved">Solved by Team</option>
                        <option value="verified">Inspector Accepted</option>
                        <option value="rejected">Rejected</option>
                    </select>

                    <select id="areaFilter" class="history-filter">
                        <option value="all">All Areas</option>
                        <?php foreach ($areaOptions as $area): ?>
                            <option value="<?php echo e($area['area_id']); ?>">
                                <?php echo e($area['area_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select id="timeFilter" class="history-filter">
                        <option value="all">All Time</option>
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                    </select>
                </div>

                <div class="history-table-card">
                    <div class="history-table-wrap">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>Task ID</th>
                                    <th>Issue Type</th>
                                    <th>Area</th>
                                    <th>Current Status</th>
                                    <th>Completion Date</th>
                                    <th>Proof Submitted</th>
                                    <th>Verification Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>

                            <tbody id="historyTableBody">
                                <?php if (count($historyTasks) > 0): ?>
                                    <?php foreach ($historyTasks as $task): ?>
                                        <?php
                                        $wardText = 'Ward not found';

                                        if (!empty($task['ward_no'])) {
                                            $wardText = 'Ward ' . $task['ward_no'];
                                        } elseif (!empty($task['ward_name'])) {
                                            $wardText = $task['ward_name'];
                                        }

                                        $areaText = !empty($task['area_name']) ? $task['area_name'] : 'Area not found';

                                        $issueType = 'Drainage Complaint';

                                        $finalStatus = $task['final_status'] ?: $task['assignment_status'];
                                        $statusLabel = historyStatusLabel($finalStatus);
                                        $statusClass = historyStatusClass($finalStatus);

                                        $complaintStatusLabel = historyStatusLabel($task['complaint_status']);
                                        $complaintStatusClass = historyStatusClass($task['complaint_status']);

                                        $completionDateSource = !empty($task['proof_submitted_at'])
                                            ? $task['proof_submitted_at']
                                            : $task['updated_at'];

                                        $completionDate = formatDateOnly($completionDateSource);

                                        $totalImages = (int)($task['total_images'] ?? 0);
                                        $totalVideos = (int)($task['total_videos'] ?? 0);
                                        $totalProofs = (int)($task['total_proofs'] ?? 0);

                                        if ($totalProofs > 0) {
                                            $proofSubmittedLabel = '';

                                            if ($totalImages > 0) {
                                                $proofSubmittedLabel .= $totalImages . ' Photo' . ($totalImages > 1 ? 's' : '');
                                            }

                                            if ($totalVideos > 0) {
                                                $proofSubmittedLabel .= $proofSubmittedLabel !== '' ? ' + ' : '';
                                                $proofSubmittedLabel .= $totalVideos . ' Video' . ($totalVideos > 1 ? 's' : '');
                                            }

                                            if ($proofSubmittedLabel === '') {
                                                $proofSubmittedLabel = 'Proof Uploaded';
                                            }
                                        } else {
                                            $proofSubmittedLabel = 'No Proof Yet';
                                        }
                                        ?>

                                        <tr
                                            class="history-row"
                                            data-search="<?php echo e(strtolower(
                                                ($task['complaint_code'] ?? '') . ' ' .
                                                ($issueType ?? '') . ' ' .
                                                ($areaText ?? '') . ' ' .
                                                ($wardText ?? '') . ' ' .
                                                ($task['problem_description'] ?? '') . ' ' .
                                                ($statusLabel ?? '') . ' ' .
                                                ($complaintStatusLabel ?? '')
                                            )); ?>"
                                            data-status="<?php echo e($statusClass); ?>"
                                            data-area-id="<?php echo e($task['area_id'] ?? ''); ?>"
                                            data-date="<?php echo e($completionDateSource ?? ''); ?>"
                                        >
                                            <td>
                                                <span class="task-code"><?php echo e($task['complaint_code']); ?></span>
                                            </td>

                                            <td><?php echo e($issueType); ?></td>

                                            <td>
                                                <span><?php echo e($areaText); ?></span>
                                                <small><?php echo e($wardText); ?></small>
                                            </td>

                                            <td>
                                                <span class="verification-badge <?php echo e($complaintStatusClass); ?>">
                                                    <?php echo e($complaintStatusLabel); ?>
                                                </span>
                                            </td>

                                            <td><?php echo e($completionDate); ?></td>

                                            <td>
                                                <span class="proof-badge <?php echo $totalProofs > 0 ? '' : 'no-proof'; ?>">
                                                    <i class="bi bi-image"></i>
                                                    <?php echo e($proofSubmittedLabel); ?>
                                                </span>

                                                <?php if (!empty($task['proof_submitted_at'])): ?>
                                                    <small><?php echo e(formatDateOnly($task['proof_submitted_at'])); ?></small>
                                                <?php else: ?>
                                                    <small>Pending upload</small>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <span class="verification-badge <?php echo e($statusClass); ?>">
                                                    <?php echo e($statusLabel); ?>
                                                </span>
                                            </td>

                                            <td>
                                                <button
                                                    type="button"
                                                    class="view-history-btn"
                                                    data-code="<?php echo e($task['complaint_code']); ?>"
                                                    data-issue="<?php echo e($issueType); ?>"
                                                    data-area="<?php echo e($areaText); ?>"
                                                    data-ward="<?php echo e($wardText); ?>"
                                                    data-address="<?php echo e($task['address_description'] ?: 'Address not provided'); ?>"
                                                    data-problem="<?php echo e($task['problem_description'] ?: 'No problem description provided.'); ?>"
                                                    data-note="<?php echo e($task['latest_proof_note'] ?: 'No proof note available yet.'); ?>"
                                                    data-assigned-by="<?php echo e($task['assigned_by_name'] ?: 'Ward Officer'); ?>"
                                                    data-started-at="<?php echo e(formatDateTime($task['work_started_at'])); ?>"
                                                    data-completed-at="<?php echo e(formatDateTime($task['proof_submitted_at'])); ?>"
                                                    data-status="<?php echo e($statusLabel); ?>"
                                                    data-complaint-status="<?php echo e($complaintStatusLabel); ?>"
                                                    data-files="<?php echo e($task['proof_files'] ?? ''); ?>"
                                                >
                                                    <i class="bi bi-eye"></i>
                                                    View Details
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (count($historyTasks) === 0): ?>
                        <div class="empty-state initial-empty-state">
                            <i class="bi bi-clock-history"></i>
                            <h2>No task history found</h2>
                            <p>Assigned, in-progress, solved, and inspector-accepted tasks will appear here.</p>
                        </div>
                    <?php endif; ?>

                    <div class="empty-state filter-empty-state" id="filterEmptyState">
                        <i class="bi bi-inbox"></i>
                        <h2>No matching history found</h2>
                        <p>No task matches your selected filter.</p>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <div class="history-modal" id="historyModal" aria-hidden="true">
        <div class="history-modal-backdrop" data-close-history-modal></div>

        <div class="history-modal-card">
            <div class="modal-head">
                <div>
                    <span id="modalTaskCode">Task</span>
                    <h2 id="modalIssue">Task History Details</h2>
                </div>

                <button type="button" class="modal-close" data-close-history-modal>
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="modal-details">
                    <div class="detail-row">
                        <strong>Current Status</strong>
                        <span id="modalComplaintStatus">N/A</span>
                    </div>

                    <div class="detail-row">
                        <strong>Verification Status</strong>
                        <span id="modalStatus">N/A</span>
                    </div>

                    <div class="detail-row">
                        <strong>Area</strong>
                        <span id="modalArea">N/A</span>
                    </div>

                    <div class="detail-row">
                        <strong>Ward</strong>
                        <span id="modalWard">N/A</span>
                    </div>

                    <div class="detail-row">
                        <strong>Address</strong>
                        <span id="modalAddress">N/A</span>
                    </div>

                    <div class="detail-row">
                        <strong>Assigned By</strong>
                        <span id="modalAssignedBy">N/A</span>
                    </div>

                    <div class="detail-row">
                        <strong>Started At</strong>
                        <span id="modalStartedAt">N/A</span>
                    </div>

                    <div class="detail-row">
                        <strong>Completed At</strong>
                        <span id="modalCompletedAt">N/A</span>
                    </div>
                </div>

                <div class="modal-text-block">
                    <h3>Problem Description</h3>
                    <p id="modalProblem">N/A</p>
                </div>

                <div class="modal-text-block">
                    <h3>Completion Note</h3>
                    <p id="modalNote">N/A</p>
                </div>

                <div class="modal-proof-block">
                    <h3>Submitted Proof Files</h3>
                    <div class="proof-file-grid" id="modalProofFiles">
                        <p class="no-proof-text">No proof file found.</p>
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="modal-secondary-btn" data-close-history-modal>
                    Close
                </button>
            </div>
        </div>
    </div>

    <script src="../../js/maintenance/sidebar.js"></script>
    <script src="../../js/maintenance/task-history.js"></script>
<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>