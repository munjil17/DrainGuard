<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';

if (!isset($conn) && isset($connection)) {
    $conn = $connection;
}

if (!isset($conn) || !$conn) {
    die("Database connection not found.");
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

$userId = (int) $_SESSION['user_id'];
$activePage = 'dashboard';
$pageTitle = 'Inspector Dashboard';

/* =========================
   Helper Functions
========================= */

function fetchSingleRow($conn, $sql, $types = "", ...$params)
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return null;
    }

    if (!empty($types)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function fetchAllRows($conn, $sql, $types = "", ...$params)
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return [];
    }

    if (!empty($types)) {
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

function fetchCount($conn, $sql, $types = "", ...$params)
{
    $row = fetchSingleRow($conn, $sql, $types, ...$params);

    if (!$row) {
        return 0;
    }

    return (int) array_values($row)[0];
}

function safeText($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function timeAgo($datetime)
{
    if (empty($datetime)) {
        return "Recently";
    }

    $timestamp = strtotime($datetime);

    if (!$timestamp) {
        return "Recently";
    }

    $diff = time() - $timestamp;

    if ($diff < 60) {
        return "Just now";
    }

    if ($diff < 3600) {
        return floor($diff / 60) . " minutes ago";
    }

    if ($diff < 86400) {
        return floor($diff / 3600) . " hours ago";
    }

    if ($diff < 604800) {
        return floor($diff / 86400) . " days ago";
    }

    return date("d M Y", $timestamp);
}

function priorityClass($priority)
{
    $priority = strtolower((string) $priority);

    if ($priority === 'high') {
        return 'priority-high';
    }

    if ($priority === 'medium') {
        return 'priority-medium';
    }

    return 'priority-low';
}

/* =========================
   Inspector Info
========================= */

$inspector = fetchSingleRow(
    $conn,
    "SELECT 
        i.inspector_id,
        i.user_id,
        i.city_cor_id,
        i.assigned_ward_id,
        i.full_name,
        i.user_mail,
        i.employee_code,
        i.designation,
        u.user_name,
        u.user_role
    FROM inspectors i
    INNER JOIN users u ON u.user_id = i.user_id
    WHERE i.user_id = ?
    LIMIT 1",
    "i",
    $userId
);

if (!$inspector) {
    die("Inspector profile not found for this logged-in user.");
}

$inspectorId = (int) $inspector['inspector_id'];
$assignedWardId = (int) $inspector['assigned_ward_id'];
$cityCorId = (int) $inspector['city_cor_id'];

$inspectorName = !empty($inspector['full_name'])
    ? $inspector['full_name']
    : ($inspector['user_name'] ?? 'Inspector');

$_SESSION['user_name'] = $inspectorName;

/* =========================
   KPI Counts
========================= */

$pendingInspections = fetchCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND c.complaint_status = 'inspector_verification'",
    "i",
    $assignedWardId
);

$approvedThisWeek = fetchCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND c.complaint_status = 'closed'
    AND YEARWEEK(c.updated_at, 1) = YEARWEEK(CURDATE(), 1)",
    "i",
    $assignedWardId
);

$reopenedCases = fetchCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND c.complaint_status IN ('reopened', 'disputed')",
    "i",
    $assignedWardId
);

$citizenObjections = fetchCount(
    $conn,
    "SELECT COUNT(DISTINCT rr.reopen_id) AS total
    FROM reopen_requests rr
    INNER JOIN complaints c ON c.complaint_id = rr.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND rr.request_type IN ('disputed', 'false_completion')
    AND rr.request_status IN ('pending', 'sent_to_inspector')",
    "i",
    $assignedWardId
);

$awaitingReview = fetchCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN maintenance_updates mu ON mu.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND (
        c.complaint_status = 'solved_by_team'
        OR mu.work_status = 'completed'
    )
    AND c.complaint_status NOT IN ('closed', 'rejected', 'duplicate')",
    "i",
    $assignedWardId
);

$totalInspections = fetchCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND c.complaint_status IN (
        'inspector_verification',
        'closed',
        'reopened',
        'disputed',
        'rejected'
    )",
    "i",
    $assignedWardId
);

$approvedTotal = fetchCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND c.complaint_status = 'closed'",
    "i",
    $assignedWardId
);

$decisionTotal = fetchCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND c.complaint_status IN ('closed', 'reopened', 'disputed', 'rejected')",
    "i",
    $assignedWardId
);

$approvalRate = $decisionTotal > 0
    ? round(($approvedTotal / $decisionTotal) * 100)
    : 0;

$falseCompletionFromRequests = fetchCount(
    $conn,
    "SELECT COUNT(DISTINCT rr.reopen_id) AS total
    FROM reopen_requests rr
    INNER JOIN complaints c ON c.complaint_id = rr.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND rr.request_type = 'false_completion'",
    "i",
    $assignedWardId
);

$falseCompletionFromFeedback = fetchCount(
    $conn,
    "SELECT COUNT(DISTINCT f.feedback_id) AS total
    FROM feedbacks f
    INNER JOIN complaints c ON c.complaint_id = f.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND f.feedback_type = 'false_completion'",
    "i",
    $assignedWardId
);

$falseCompletion = $falseCompletionFromRequests + $falseCompletionFromFeedback;

/* =========================
   Priority Inspections List
========================= */

$priorityInspections = fetchAllRows(
    $conn,
    "SELECT DISTINCT
        c.complaint_id,
        c.complaint_code,
        c.problem_description,
        c.complaint_status,
        c.updated_at,
        ca.assignment_priority,
        mt.team_name,
        i.issue_name
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN maintenance_teams mt ON mt.maintenance_team_id = ca.maintenance_team_id
    LEFT JOIN issues i ON i.issue_id = c.issue_id
    WHERE ca.ward_id = ?
    AND ca.assignment_priority = 'High'
    AND c.complaint_status IN ('solved_by_team', 'inspector_verification', 'reopened', 'disputed')
    ORDER BY c.updated_at DESC
    LIMIT 5",
    "i",
    $assignedWardId
);

/* =========================
   Citizen Objections List
========================= */

$objectionRows = fetchAllRows(
    $conn,
    "SELECT DISTINCT
        rr.reopen_id,
        rr.request_type,
        rr.request_status,
        rr.reason,
        rr.created_at,
        c.complaint_code,
        w.ward_no
    FROM reopen_requests rr
    INNER JOIN complaints c ON c.complaint_id = rr.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN wards w ON w.ward_id = ca.ward_id
    WHERE ca.ward_id = ?
    AND rr.request_type IN ('disputed', 'false_completion', 'reopened')
    AND rr.request_status IN ('pending', 'sent_to_inspector')
    ORDER BY rr.created_at DESC
    LIMIT 5",
    "i",
    $assignedWardId
);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspector Dashboard | DrainGuard</title>

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/inspector/sidebar.css">
    <link rel="stylesheet" href="../../css/inspector/topbar.css">
    <link rel="stylesheet" href="../../css/inspector/dashboard.css">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>

<body class="inspector">

    <div class="inspector-layout">

        <?php include __DIR__ . '/../../includes/inspector/sidebar.php'; ?>

        <main class="inspector-main">

            <?php include __DIR__ . '/../../includes/inspector/topbar.php'; ?>

            <section class="inspector-dashboard">

                <div class="dashboard-hero">

                    <div>
                        <span class="hero-badge">Inspector Verification Access</span>
                        <h1>Inspector Final Judgment Authority</h1>
                        <p>Review maintenance work, verify completion proof, approve or reopen complaints.</p>
                    </div>

                    <div class="hero-stat">
                        <span>Pending Inspections</span>
                        <strong><?php echo $pendingInspections; ?></strong>
                    </div>

                </div>

                <div class="authority-alert">

                    <div class="authority-icon">
                        <i class="bi bi-shield-check"></i>
                    </div>

                    <div>
                        <h3>Inspector Final Judgment Authority</h3>
                        <p>
                            You have the authority to verify maintenance team work.
                            Approve work if proof is satisfactory, or reopen complaints if work is incomplete or suspicious.
                        </p>
                    </div>

                </div>

                <div class="kpi-grid">

                    <div class="kpi-card">
                        <div class="kpi-icon icon-pending">
                            <i class="bi bi-eye"></i>
                        </div>
                        <h2><?php echo $pendingInspections; ?></h2>
                        <p>Pending Inspections</p>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-icon icon-success">
                            <i class="bi bi-check2-circle"></i>
                        </div>
                        <h2><?php echo $approvedThisWeek; ?></h2>
                        <p>Approved This Week</p>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-icon icon-danger">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <h2><?php echo $reopenedCases; ?></h2>
                        <p>Reopened Cases</p>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-icon icon-warning">
                            <i class="bi bi-flag"></i>
                        </div>
                        <h2><?php echo $citizenObjections; ?></h2>
                        <p>Citizen Objections</p>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-icon icon-purple">
                            <i class="bi bi-clock"></i>
                        </div>
                        <h2><?php echo $awaitingReview; ?></h2>
                        <p>Awaiting Review</p>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-icon icon-blue">
                            <i class="bi bi-clipboard-check"></i>
                        </div>
                        <h2><?php echo $totalInspections; ?></h2>
                        <p>Total Inspections</p>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-icon icon-green">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <h2><?php echo $approvalRate; ?>%</h2>
                        <p>Approval Rate</p>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-icon icon-orange">
                            <i class="bi bi-flag"></i>
                        </div>
                        <h2><?php echo $falseCompletion; ?></h2>
                        <p>False Completion</p>
                    </div>

                </div>

                <div class="dashboard-panels">

                    <div class="dashboard-panel">

                        <div class="panel-header">
                            <h3>
                                <i class="bi bi-clock"></i>
                                Priority Inspections Today
                            </h3>
                        </div>

                        <div class="inspection-list">

                            <?php if (!empty($priorityInspections)): ?>

                                <?php foreach ($priorityInspections as $item): ?>

                                    <?php
                                    $priority = $item['assignment_priority'] ?? 'Medium';
                                    $issueName = $item['issue_name'] ?? 'Drainage Issue';
                                    $teamName = $item['team_name'] ?? 'No Team Assigned';
                                    ?>

                                    <div class="inspection-item">

                                        <div>
                                            <span class="complaint-code">
                                                <?php echo safeText($item['complaint_code']); ?>
                                            </span>

                                            <h4><?php echo safeText($issueName); ?></h4>

                                            <p>
                                                <?php echo safeText($teamName); ?>
                                                •
                                                <?php echo safeText(timeAgo($item['updated_at'])); ?>
                                            </p>
                                        </div>

                                        <span class="priority-badge <?php echo priorityClass($priority); ?>">
                                            <?php echo safeText($priority); ?>
                                        </span>

                                    </div>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <div class="empty-state">
                                    <i class="bi bi-check-circle"></i>
                                    <h4>No High Priority Inspection</h4>
                                    <p>No high priority inspection is pending for your assigned ward.</p>
                                </div>

                            <?php endif; ?>

                        </div>

                        <a href="inspection-queue.php" class="panel-link">
                            View All Pending Inspections
                            <i class="bi bi-arrow-right"></i>
                        </a>

                    </div>

                    <div class="dashboard-panel">

                        <div class="panel-header objection-header">
                            <h3>
                                <i class="bi bi-flag"></i>
                                Citizen Objections Requiring Action
                            </h3>
                        </div>

                        <div class="objection-list">

                            <?php if (!empty($objectionRows)): ?>

                                <?php foreach ($objectionRows as $row): ?>

                                    <div class="objection-item">

                                        <div class="objection-top">
                                            <span><?php echo safeText($row['complaint_code']); ?></span>
                                            <strong>
                                                Ward <?php echo safeText($row['ward_no'] ?? 'N/A'); ?>
                                            </strong>
                                        </div>

                                        <h4>
                                            <?php
                                            if (!empty($row['reason'])) {
                                                echo safeText($row['reason']);
                                            } else {
                                                echo safeText(ucwords(str_replace('_', ' ', $row['request_type'])));
                                            }
                                            ?>
                                        </h4>

                                        <p><?php echo safeText(timeAgo($row['created_at'])); ?></p>

                                    </div>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <div class="empty-state empty-danger">
                                    <i class="bi bi-check-circle"></i>
                                    <h4>No Active Objection</h4>
                                    <p>No citizen objection currently requires inspector action.</p>
                                </div>

                            <?php endif; ?>

                        </div>

                        <a href="citizen-objections.php" class="panel-link danger-link">
                            Review All Objections
                            <i class="bi bi-arrow-right"></i>
                        </a>

                    </div>

                </div>

            </section>

            <?php
            $footerPath = __DIR__ . '/../../includes/inspector/footer.php';
            if (file_exists($footerPath)) {
                include $footerPath;
            }
            ?>

        </main>

    </div>

    <script src="../../js/inspector/sidebar.js"></script>
    <script src="../../js/inspector/dashboard.js"></script>

</body>

</html>