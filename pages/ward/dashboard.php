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
    "SELECT COUNT(DISTINCT ca.complaint_id) AS total
    FROM complaint_assignments ca
    WHERE ca.ward_id = ?",
    "i",
    [$wardId]
);

$pendingVerification = scalarCount(
    $conn,
    "SELECT COUNT(DISTINCT ca.complaint_id) AS total
    FROM complaint_assignments ca
    INNER JOIN complaints c ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND (
        ca.assignment_status = 'ward_assigned'
        OR c.complaint_status IN ('submitted', 'received', 'pending_verification')
    )",
    "i",
    [$wardId]
);

$assignedCases = scalarCount(
    $conn,
    "SELECT COUNT(DISTINCT ca.complaint_id) AS total
    FROM complaint_assignments ca
    WHERE ca.ward_id = ?
    AND ca.assignment_status = 'team_assigned'",
    "i",
    [$wardId]
);

$inProgressCases = scalarCount(
    $conn,
    "SELECT COUNT(DISTINCT ca.complaint_id) AS total
    FROM complaint_assignments ca
    LEFT JOIN maintenance_updates mu ON ca.assignment_id = mu.assignment_id
    WHERE ca.ward_id = ?
    AND (
        ca.assignment_status = 'in_progress'
        OR mu.work_status IN ('started', 'in_progress')
    )",
    "i",
    [$wardId]
);

$solvedCases = scalarCount(
    $conn,
    "SELECT COUNT(DISTINCT ca.complaint_id) AS total
    FROM complaint_assignments ca
    INNER JOIN maintenance_updates mu ON ca.assignment_id = mu.assignment_id
    WHERE ca.ward_id = ?
    AND mu.work_status = 'completed'",
    "i",
    [$wardId]
);

$reopenedCases = scalarCount(
    $conn,
    "SELECT COUNT(DISTINCT ca.complaint_id) AS total
    FROM complaint_assignments ca
    INNER JOIN complaints c ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND (
        c.is_repeat_complaint = 1
        OR c.parent_complaint_id IS NOT NULL
    )",
    "i",
    [$wardId]
);

$delayedCases = scalarCount(
    $conn,
    "SELECT COUNT(DISTINCT ca.complaint_id) AS total
    FROM complaint_assignments ca
    INNER JOIN maintenance_updates mu ON ca.assignment_id = mu.assignment_id
    WHERE ca.ward_id = ?
    AND mu.delayed_at IS NOT NULL",
    "i",
    [$wardId]
);

$wardRiskZones = scalarCount(
    $conn,
    "SELECT COUNT(*) AS total
    FROM risk
    WHERE ward_id = ?
    AND risk_status = 'Active'",
    "i",
    [$wardId]
);

/*
|--------------------------------------------------------------------------
| New Complaints Waiting for Verification
|--------------------------------------------------------------------------
*/
$newComplaints = fetchAllRows(
    $conn,
    "SELECT 
        c.complaint_id,
        c.complaint_code,
        c.problem_description,
        c.submitted_at,
        i.issue_name,
        i.priority,
        a.area_name
    FROM complaint_assignments ca
    INNER JOIN complaints c ON ca.complaint_id = c.complaint_id
    LEFT JOIN issues i ON c.issue_id = i.issue_id
    LEFT JOIN locations l ON c.loc_id = l.loc_id
    LEFT JOIN areas a ON l.area_id = a.area_id
    WHERE ca.ward_id = ?
    AND (
        ca.assignment_status = 'ward_assigned'
        OR c.complaint_status IN ('submitted', 'received', 'pending_verification')
    )
    ORDER BY c.submitted_at DESC
    LIMIT 3",
    "i",
    [$wardId]
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
    <link rel="stylesheet" href="../../css/ward/footer.css">
    <link rel="stylesheet" href="../../css/ward/dashboard.css">
    <link rel="stylesheet" href="../../css/ward/wardTextFix.css">
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
                <p>Ward Total Complaints</p>
            </div>

            <div class="kpi-card" data-search="pending verification">
                <div class="kpi-icon blue">
                    <i class="bi bi-clock"></i>
                </div>
                <h2><?= $pendingVerification; ?></h2>
                <p>Pending Verification</p>
            </div>

            <div class="kpi-card" data-search="assigned cases">
                <div class="kpi-icon purple">
                    <i class="bi bi-people"></i>
                </div>
                <h2><?= $assignedCases; ?></h2>
                <p>Assigned Cases</p>
            </div>

            <div class="kpi-card" data-search="in progress cases">
                <div class="kpi-icon orange">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <h2><?= $inProgressCases; ?></h2>
                <p>In Progress Cases</p>
            </div>

            <div class="kpi-card" data-search="solved cases">
                <div class="kpi-icon green">
                    <i class="bi bi-check-circle"></i>
                </div>
                <h2><?= $solvedCases; ?></h2>
                <p>Solved Cases</p>
            </div>

            <div class="kpi-card" data-search="reopened cases">
                <div class="kpi-icon yellow">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <h2><?= $reopenedCases; ?></h2>
                <p>Reopened Cases</p>
            </div>

            <div class="kpi-card" data-search="delayed cases">
                <div class="kpi-icon red">
                    <i class="bi bi-clock-history"></i>
                </div>
                <h2><?= $delayedCases; ?></h2>
                <p>Delayed Cases</p>
            </div>

            <div class="kpi-card" data-search="ward risk zones">
                <div class="kpi-icon pink">
                    <i class="bi bi-geo-alt"></i>
                </div>
                <h2><?= $wardRiskZones; ?></h2>
                <p>Ward Risk Zones</p>
            </div>

        </div>

        <div class="verification-panel">
            <div class="panel-header">
                <h2>New Complaints Waiting for Verification</h2>
                <a href="verification-queue.php">
                    View Queue
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>

            <div class="complaint-list" id="dashboardComplaintList">
                <?php if (!empty($newComplaints)): ?>
                    <?php foreach ($newComplaints as $complaint): ?>
                        <?php
                        $priority = $complaint['priority'] ?? 'Low';
                        $priorityClass = priorityClass($priority);
                        $complaintTitle = $complaint['issue_name'] ?: $complaint['problem_description'];
                        $areaName = $complaint['area_name'] ?: 'Area not specified';
                        ?>

                        <div class="complaint-item"
                             data-search="<?= htmlspecialchars(strtolower($complaint['complaint_code'] . ' ' . $complaintTitle . ' ' . $areaName . ' ' . $priority)); ?>">

                            <div class="complaint-info">
                                <div class="complaint-code-row">
                                    <span class="complaint-code">
                                        <?= htmlspecialchars($complaint['complaint_code']); ?>
                                    </span>

                                    <span class="priority-badge <?= $priorityClass; ?>">
                                        <?= htmlspecialchars($priority); ?>
                                    </span>
                                </div>

                                <h3><?= htmlspecialchars($complaintTitle); ?></h3>

                                <p>
                                    <?= htmlspecialchars($areaName); ?>
                                    <span>·</span>
                                    <?= htmlspecialchars(timeAgo($complaint['submitted_at'])); ?>
                                </p>
                            </div>

                            <a class="verify-btn"
                               href="verification-queue.php?complaint_id=<?= (int)$complaint['complaint_id']; ?>">
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

    </section>

    <?php include "../../includes/ward/footer.php"; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/dashboard.js"></script>

</body>
</html>