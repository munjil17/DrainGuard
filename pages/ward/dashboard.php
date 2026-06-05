<?php
require_once "../../config.php";
require_once "../../auth/session_check.php";

$activePage = "dashboard";
$pageTitle = "Ward Office Operations Dashboard";

/*
|--------------------------------------------------------------------------
| DB connection safety
|--------------------------------------------------------------------------
*/
if (!isset($conn) || !$conn) {
    die("Database connection not found. Please check config.php");
}

/*
|--------------------------------------------------------------------------
| Session user
|--------------------------------------------------------------------------
*/
$currentUserId = $_SESSION['user_id'] ?? 0;
$currentUserMail = $_SESSION['user_mail'] ?? '';

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/
function fetchOne($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return null;
    }

    if (!empty($types) && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function fetchAllRows($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return [];
    }

    if (!empty($types) && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function scalarCount($conn, $sql, $types = "", $params = [])
{
    $row = fetchOne($conn, $sql, $types, $params);
    return (int)($row['total'] ?? 0);
}

function timeAgo($datetime)
{
    if (!$datetime) {
        return "Unknown time";
    }

    $timestamp = strtotime($datetime);

    if (!$timestamp) {
        return "Unknown time";
    }

    $diff = time() - $timestamp;

    if ($diff < 60) {
        return "Just now";
    }

    if ($diff < 3600) {
        return floor($diff / 60) . " min ago";
    }

    if ($diff < 86400) {
        return floor($diff / 3600) . " hour ago";
    }

    return floor($diff / 86400) . " days ago";
}

function priorityClass($priority)
{
    $priority = strtolower(trim($priority ?? ''));

    if ($priority === 'high') {
        return 'high';
    }

    if ($priority === 'medium') {
        return 'medium';
    }

    return 'low';
}

/*
|--------------------------------------------------------------------------
| Get current ward officer
|--------------------------------------------------------------------------
*/
$wardOfficer = null;

if ($currentUserId) {
    $wardOfficer = fetchOne(
        $conn,
        "SELECT 
            wo.ward_officer_id,
            wo.user_id,
            wo.city_cor_id,
            wo.assigned_ward_id,
            wo.full_name,
            wo.user_mail,
            w.ward_no,
            w.ward_name
        FROM ward_officers wo
        INNER JOIN wards w ON wo.assigned_ward_id = w.ward_id
        WHERE wo.user_id = ?
        LIMIT 1",
        "i",
        [$currentUserId]
    );
}

if (!$wardOfficer && !empty($currentUserMail)) {
    $wardOfficer = fetchOne(
        $conn,
        "SELECT 
            wo.ward_officer_id,
            wo.user_id,
            wo.city_cor_id,
            wo.assigned_ward_id,
            wo.full_name,
            wo.user_mail,
            w.ward_no,
            w.ward_name
        FROM ward_officers wo
        INNER JOIN wards w ON wo.assigned_ward_id = w.ward_id
        WHERE wo.user_mail = ?
        LIMIT 1",
        "s",
        [$currentUserMail]
    );
}

/*
|--------------------------------------------------------------------------
| Demo fallback
|--------------------------------------------------------------------------
*/
if (!$wardOfficer) {
    $wardOfficer = fetchOne(
        $conn,
        "SELECT 
            wo.ward_officer_id,
            wo.user_id,
            wo.city_cor_id,
            wo.assigned_ward_id,
            wo.full_name,
            wo.user_mail,
            w.ward_no,
            w.ward_name
        FROM ward_officers wo
        INNER JOIN wards w ON wo.assigned_ward_id = w.ward_id
        ORDER BY wo.ward_officer_id ASC
        LIMIT 1"
    );
}

if (!$wardOfficer) {
    die("No ward officer found. Please insert ward officer data first.");
}

$wardOfficerId = (int)$wardOfficer['ward_officer_id'];
$wardId = (int)$wardOfficer['assigned_ward_id'];
$cityCorId = (int)$wardOfficer['city_cor_id'];
$wardNo = $wardOfficer['ward_no'] ?? '';
$wardName = $wardOfficer['ward_name'] ?? '';
$userName = $wardOfficer['full_name'] ?? ($_SESSION['user_name'] ?? 'Ward Officer');

$_SESSION['user_name'] = $userName;
$_SESSION['user_role_label'] = "Ward Operations";

/*
|--------------------------------------------------------------------------
| Dashboard Counts
|--------------------------------------------------------------------------
*/
$totalComplaints = scalarCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN locations l ON c.loc_id = l.loc_id
    WHERE l.ward_id = ? AND l.city_cor_id = ?
    AND c.complaint_status NOT IN ('submitted', 'received', 'rejected_by_central')",
    "ii",
    [$wardId, $cityCorId]
);

$pendingVerification = scalarCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN locations l ON c.loc_id = l.loc_id
    WHERE l.ward_id = ? AND l.city_cor_id = ?
    AND c.complaint_status = 'pending_verification'",
    "ii",
    [$wardId, $cityCorId]
);

$verifiedCases = scalarCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN locations l ON c.loc_id = l.loc_id
    WHERE l.ward_id = ? AND l.city_cor_id = ?
    AND c.complaint_status = 'verified_by_ward'",
    "ii",
    [$wardId, $cityCorId]
);

$assignedCases = scalarCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN locations l ON c.loc_id = l.loc_id
    WHERE l.ward_id = ? AND l.city_cor_id = ?
    AND c.complaint_status = 'team_assigned'",
    "ii",
    [$wardId, $cityCorId]
);

$inProgressCases = scalarCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN locations l ON c.loc_id = l.loc_id
    WHERE l.ward_id = ? AND l.city_cor_id = ?
    AND c.complaint_status = 'in_progress'",
    "ii",
    [$wardId, $cityCorId]
);

$duplicateCases = scalarCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN locations l ON c.loc_id = l.loc_id
    WHERE l.ward_id = ? AND l.city_cor_id = ?
    AND (c.is_repeat_complaint = 1 OR c.complaint_status = 'duplicate')",
    "ii",
    [$wardId, $cityCorId]
);

$rejectedCases = scalarCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN locations l ON c.loc_id = l.loc_id
    WHERE l.ward_id = ? AND l.city_cor_id = ?
    AND c.complaint_status = 'rejected_by_ward'",
    "ii",
    [$wardId, $cityCorId]
);

$totalTeams = scalarCount(
    $conn,
    "SELECT COUNT(*) AS total
    FROM maintenance_teams mt
    INNER JOIN wards w ON mt.anchal_id = w.anchal_id AND mt.city_cor_id = w.city_cor_id
    WHERE w.ward_id = ?",
    "i",
    [$wardId]
);

$wardOfficersCount = scalarCount(
    $conn,
    "SELECT COUNT(*) AS total
    FROM ward_officers
    WHERE assigned_ward_id = ?",
    "i",
    [$wardId]
);

/*
|--------------------------------------------------------------------------
| A. Recent Complaints for Verification
|--------------------------------------------------------------------------
*/
$recentVerification = fetchAllRows(
    $conn,
    "SELECT 
        c.complaint_id,
        c.complaint_code,
        c.problem_description,
        c.submitted_at,
        c.complaint_status,
        i.issue_name,
        a.area_name
    FROM complaints c
    LEFT JOIN issues i ON c.issue_id = i.issue_id
    INNER JOIN locations l ON c.loc_id = l.loc_id
    LEFT JOIN areas a ON l.area_id = a.area_id
    WHERE l.ward_id = ? AND l.city_cor_id = ?
    AND c.complaint_status = 'pending_verification'
    ORDER BY c.submitted_at DESC
    LIMIT 4",
    "ii",
    [$wardId, $cityCorId]
);

/*
|--------------------------------------------------------------------------
| B. Recent Ward Complaints
|--------------------------------------------------------------------------
*/
$recentWardComplaints = fetchAllRows(
    $conn,
    "SELECT 
        c.complaint_id,
        c.complaint_code,
        c.problem_description,
        c.submitted_at,
        c.complaint_status,
        i.issue_name,
        a.area_name
    FROM complaints c
    LEFT JOIN issues i ON c.issue_id = i.issue_id
    INNER JOIN locations l ON c.loc_id = l.loc_id
    LEFT JOIN areas a ON l.area_id = a.area_id
    WHERE l.ward_id = ? AND l.city_cor_id = ?
    AND c.complaint_status NOT IN ('submitted', 'received', 'rejected_by_central')
    ORDER BY c.submitted_at DESC
    LIMIT 4",
    "ii",
    [$wardId, $cityCorId]
);

/*
|--------------------------------------------------------------------------
| C. Local Team Assignment Waiting
|--------------------------------------------------------------------------
*/
$teamAssignmentWaiting = fetchAllRows(
    $conn,
    "SELECT 
        c.complaint_id,
        c.complaint_code,
        c.problem_description,
        c.submitted_at,
        c.complaint_status,
        i.issue_name,
        a.area_name,
        mt.team_name,
        ca.deadline_at
    FROM complaints c
    LEFT JOIN issues i ON c.issue_id = i.issue_id
    INNER JOIN locations l ON c.loc_id = l.loc_id
    LEFT JOIN areas a ON l.area_id = a.area_id
    LEFT JOIN complaint_assignments ca ON c.complaint_id = ca.complaint_id AND ca.assignment_status = 'team_assigned'
    LEFT JOIN maintenance_teams mt ON ca.maintenance_team_id = mt.maintenance_team_id
    WHERE l.ward_id = ? AND l.city_cor_id = ?
    AND c.complaint_status IN ('verified_by_ward', 'team_assigned')
    ORDER BY c.submitted_at DESC
    LIMIT 4",
    "ii",
    [$wardId, $cityCorId]
);

/*
|--------------------------------------------------------------------------
| D. Recent In Progress Cases
|--------------------------------------------------------------------------
*/
$recentInProgress = fetchAllRows(
    $conn,
    "SELECT 
        c.complaint_id,
        c.complaint_code,
        c.problem_description,
        c.submitted_at,
        c.complaint_status,
        i.issue_name,
        a.area_name,
        mt.team_name,
        ca.deadline_at
    FROM complaints c
    LEFT JOIN issues i ON c.issue_id = i.issue_id
    INNER JOIN locations l ON c.loc_id = l.loc_id
    LEFT JOIN areas a ON l.area_id = a.area_id
    LEFT JOIN complaint_assignments ca ON c.complaint_id = ca.complaint_id
    LEFT JOIN maintenance_teams mt ON ca.maintenance_team_id = mt.maintenance_team_id
    WHERE l.ward_id = ? AND l.city_cor_id = ?
    AND c.complaint_status = 'in_progress'
    ORDER BY c.submitted_at DESC
    LIMIT 4",
    "ii",
    [$wardId, $cityCorId]
);

/*
|--------------------------------------------------------------------------
| E. Recent Local Reports
|--------------------------------------------------------------------------
*/
$recentReports = fetchAllRows(
    $conn,
    "SELECT 
        report_id,
        report_name,
        report_type,
        generated_at,
        file_path
    FROM generated_reports
    WHERE ward_id = ? AND city_cor_id = ?
    ORDER BY generated_at DESC
    LIMIT 4",
    "ii",
    [$wardId, $cityCorId]
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle); ?> | DrainGuard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
    <link rel="stylesheet" href="../../css/ward/dashboard.css">
    <link rel="stylesheet" href="../../css/ward/wardTextFix.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="ward">

<?php include "../../includes/ward/sidebar.php"; ?>

<main class="ward-main">
    <?php include "../../includes/ward/topbar.php"; ?>

    <section class="ward-dashboard-page">

        <div class="dashboard-hero">
            <div>
                <h1>Ward Office Operations Dashboard</h1>
                <p>
                    Monitor and manage drainage complaints for
                    Ward <?= htmlspecialchars($wardNo); ?>
                    <?= !empty($wardName) ? " - " . htmlspecialchars($wardName) : ""; ?>
                </p>
            </div>
        </div>

        <div class="dashboard-kpi-grid">

            <div class="kpi-card" data-search="ward total complaints">
                <div class="kpi-icon cyan">
                    <i class="bi bi-file-earmark-text"></i>
                </div>
                <h2><?= $totalComplaints; ?></h2>
                <p>Ward Total Complaint</p>
            </div>

            <div class="kpi-card" data-search="pending verification">
                <div class="kpi-icon blue">
                    <i class="bi bi-clock"></i>
                </div>
                <h2><?= $pendingVerification; ?></h2>
                <p>Pending Verification</p>
            </div>

            <div class="kpi-card" data-search="verified by ward">
                <div class="kpi-icon green">
                    <i class="bi bi-check-circle"></i>
                </div>
                <h2><?= $verifiedCases; ?></h2>
                <p>Verified By Ward</p>
            </div>

            <div class="kpi-card" data-search="assigned to team">
                <div class="kpi-icon pink">
                    <i class="bi bi-person-workspace"></i>
                </div>
                <h2><?= $assignedCases; ?></h2>
                <p>Assigned To Team</p>
            </div>

            <div class="kpi-card" data-search="in progress cases">
                <div class="kpi-icon orange">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <h2><?= $inProgressCases; ?></h2>
                <p>In Progress</p>
            </div>

            <div class="kpi-card" data-search="duplicate cases">
                <div class="kpi-icon yellow">
                    <i class="bi bi-files"></i>
                </div>
                <h2><?= $duplicateCases; ?></h2>
                <p>Duplicate</p>
            </div>

            <div class="kpi-card" data-search="rejected by ward">
                <div class="kpi-icon red">
                    <i class="bi bi-x-circle"></i>
                </div>
                <h2><?= $rejectedCases; ?></h2>
                <p>Rejected By The Ward</p>
            </div>

            <div class="kpi-card" data-search="total team in this ward">
                <div class="kpi-icon purple">
                    <i class="bi bi-people"></i>
                </div>
                <h2><?= $totalTeams; ?></h2>
                <p>Total Team In This Ward</p>
            </div>



        </div>

        <div class="verification-panel" style="margin-top: 24px;">
            <div class="panel-header">
                <h2>Recent Complaints for Verification</h2>
                <a href="verification-queue.php">
                    View All
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>

            <div class="complaint-list" id="dashboardVerificationList">
                <?php if (!empty($recentVerification)): ?>
                    <?php foreach ($recentVerification as $complaint): ?>
                        <?php
                        $complaintTitle = $complaint['issue_name'] ?: $complaint['problem_description'];
                        $areaName = $complaint['area_name'] ?: 'Area not specified';
                        ?>

                        <div class="complaint-item">
                            <div class="complaint-info">
                                <div class="complaint-code-row">
                                    <span class="complaint-code">
                                        <?= htmlspecialchars($complaint['complaint_code']); ?>
                                    </span>

                                    <span class="priority-badge medium">
                                        <?= ucwords(str_replace('_', ' ', $complaint['complaint_status'])); ?>
                                    </span>
                                </div>

                                <h3><?= htmlspecialchars($complaintTitle); ?></h3>

                                <p>
                                    <?= htmlspecialchars($areaName); ?>
                                    <span>·</span>
                                    <?= htmlspecialchars(timeAgo($complaint['submitted_at'])); ?>
                                </p>
                            </div>

                            <a class="verify-btn" href="verification-queue.php?complaint_id=<?= (int)$complaint['complaint_id']; ?>">
                                Verify
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-check2-circle"></i>
                        <h3>No pending verification complaints</h3>
                        <p>All assigned complaints for this ward are currently processed.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="verification-panel" style="margin-top: 24px;">
            <div class="panel-header">
                <h2>Recent Ward Complaints</h2>
                <a href="ward-complaints.php">
                    View All
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>

            <div class="complaint-list">
                <?php if (!empty($recentWardComplaints)): ?>
                    <?php foreach ($recentWardComplaints as $complaint): ?>
                        <?php
                        $complaintTitle = $complaint['issue_name'] ?: $complaint['problem_description'];
                        $areaName = $complaint['area_name'] ?: 'Area not specified';
                        ?>

                        <div class="complaint-item">
                            <div class="complaint-info">
                                <div class="complaint-code-row">
                                    <span class="complaint-code">
                                        <?= htmlspecialchars($complaint['complaint_code']); ?>
                                    </span>
                                    <span class="priority-badge low">
                                        <?= ucwords(str_replace('_', ' ', $complaint['complaint_status'])); ?>
                                    </span>
                                </div>
                                <h3><?= htmlspecialchars($complaintTitle); ?></h3>
                                <p>
                                    <?= htmlspecialchars($areaName); ?>
                                    <span>·</span>
                                    <?= htmlspecialchars(timeAgo($complaint['submitted_at'])); ?>
                                </p>
                            </div>
                            <a class="verify-btn" style="background:#F8FAFC; color:#64748B; border:1px solid #D7E2EF;" href="ward-complaints.php">
                                Details
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-folder2-open"></i>
                        <h3>No ward complaints</h3>
                        <p>No complaints currently in workflow.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="verification-panel" style="margin-top: 24px;">
            <div class="panel-header">
                <h2>Local Team Assignment Waiting</h2>
                <a href="local-team-assignment.php">
                    View All
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>

            <div class="complaint-list">
                <?php if (!empty($teamAssignmentWaiting)): ?>
                    <?php foreach ($teamAssignmentWaiting as $complaint): ?>
                        <?php
                        $complaintTitle = $complaint['issue_name'] ?: $complaint['problem_description'];
                        $areaName = $complaint['area_name'] ?: 'Area not specified';
                        $teamName = $complaint['team_name'] ?: 'No team assigned';
                        $deadline = $complaint['deadline_at'] ? date("d M Y", strtotime($complaint['deadline_at'])) : 'No deadline';
                        ?>

                        <div class="complaint-item">
                            <div class="complaint-info">
                                <div class="complaint-code-row">
                                    <span class="complaint-code">
                                        <?= htmlspecialchars($complaint['complaint_code']); ?>
                                    </span>
                                    <span class="priority-badge high">
                                        <?= ucwords(str_replace('_', ' ', $complaint['complaint_status'])); ?>
                                    </span>
                                </div>
                                <h3><?= htmlspecialchars($complaintTitle); ?></h3>
                                <p>
                                    <?= htmlspecialchars($areaName); ?>
                                    <span>·</span>
                                    Team: <?= htmlspecialchars($teamName); ?>
                                    <span>·</span>
                                    Deadline: <?= htmlspecialchars($deadline); ?>
                                </p>
                            </div>
                            <a class="verify-btn" href="local-team-assignment.php">
                                Assign
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-people"></i>
                        <h3>No assignments pending</h3>
                        <p>All verified cases have been started by teams.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="verification-panel" style="margin-top: 24px;">
            <div class="panel-header">
                <h2>Recent In Progress Cases</h2>
                <a href="in-progress-cases.php">
                    View All
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>

            <div class="complaint-list">
                <?php if (!empty($recentInProgress)): ?>
                    <?php foreach ($recentInProgress as $complaint): ?>
                        <?php
                        $complaintTitle = $complaint['issue_name'] ?: $complaint['problem_description'];
                        $areaName = $complaint['area_name'] ?: 'Area not specified';
                        $teamName = $complaint['team_name'] ?: 'Unknown team';
                        ?>

                        <div class="complaint-item">
                            <div class="complaint-info">
                                <div class="complaint-code-row">
                                    <span class="complaint-code">
                                        <?= htmlspecialchars($complaint['complaint_code']); ?>
                                    </span>
                                    <span class="priority-badge medium">
                                        <?= ucwords(str_replace('_', ' ', $complaint['complaint_status'])); ?>
                                    </span>
                                </div>
                                <h3><?= htmlspecialchars($complaintTitle); ?></h3>
                                <p>
                                    <?= htmlspecialchars($areaName); ?>
                                    <span>·</span>
                                    Team: <?= htmlspecialchars($teamName); ?>
                                </p>
                            </div>
                            <a class="verify-btn" href="in-progress-cases.php">
                                Track
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-gear-wide-connected"></i>
                        <h3>No in-progress cases</h3>
                        <p>No active work currently happening in this ward.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="verification-panel" style="margin-top: 24px; margin-bottom: 40px;">
            <div class="panel-header">
                <h2>Recent Local Reports</h2>
                <a href="local-reports.php">
                    View All
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>

            <div class="complaint-list">
                <?php if (!empty($recentReports)): ?>
                    <?php foreach ($recentReports as $report): ?>
                        <div class="complaint-item">
                            <div class="complaint-info">
                                <div class="complaint-code-row">
                                    <span class="complaint-code">
                                        REP-<?= htmlspecialchars($report['report_id']); ?>
                                    </span>
                                    <span class="priority-badge low">
                                        <?= ucwords(str_replace('_', ' ', $report['report_type'])); ?>
                                    </span>
                                </div>
                                <h3><?= htmlspecialchars($report['report_name']); ?></h3>
                                <p>
                                    Generated: <?= htmlspecialchars(timeAgo($report['generated_at'])); ?>
                                </p>
                            </div>
                            <?php if (!empty($report['file_path'])): ?>
                                <a class="verify-btn" style="background:#F8FAFC; color:#64748B; border:1px solid #D7E2EF;" href="<?= htmlspecialchars($report['file_path']); ?>" target="_blank">
                                    <i class="bi bi-download"></i> View
                                </a>
                            <?php else: ?>
                                <button class="verify-btn" style="background:#F1F5F9; color:#94A3B8; border:1px solid #E2E8F0;" disabled>
                                    No File
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-bar-chart"></i>
                        <h3>No recent reports</h3>
                        <p>Generate a report to see it here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </section>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/dashboard.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>