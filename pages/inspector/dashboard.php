<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';

if (!isset($conn) && isset($connection)) {
    $conn = $connection;
}

if (!isset($conn) || !$conn) {
    die("Service is temporarily unavailable. Please try again.");
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
   KPI Counts & Hero Stats
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

$kpiSolvedByTeam = fetchCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND c.complaint_status = 'solved_by_team'",
    "i",
    $assignedWardId
);

$kpiApprovedWork = fetchCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND c.complaint_status = 'closed'",
    "i",
    $assignedWardId
);

$kpiFalseCompletion = fetchCount(
    $conn,
    "SELECT COUNT(DISTINCT fcr.review_id) AS total
    FROM false_completion_reviews fcr
    INNER JOIN complaints c ON c.complaint_id = fcr.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND fcr.inspector_claim_status = 'true'",
    "i",
    $assignedWardId
);

$kpiReopenCases = fetchCount(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND c.complaint_status IN ('reopened', 'disputed')",
    "i",
    $assignedWardId
);

/* =========================
   A. Recent Solved by Team Cases
========================= */

$recentSolved = fetchAllRows(
    $conn,
    "SELECT DISTINCT
        c.complaint_id,
        c.complaint_code,
        i.issue_name,
        mt.team_name,
        c.updated_at,
        c.complaint_status,
        w.ward_no
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN issues i ON i.issue_id = c.issue_id
    LEFT JOIN maintenance_teams mt ON mt.maintenance_team_id = ca.maintenance_team_id
    LEFT JOIN wards w ON w.ward_id = ca.ward_id
    WHERE ca.ward_id = ?
    AND c.complaint_status = 'solved_by_team'
    ORDER BY c.updated_at DESC
    LIMIT 4",
    "i",
    $assignedWardId
);

/* =========================
   B. Recent Inspection Queue
========================= */

$recentInspection = fetchAllRows(
    $conn,
    "SELECT DISTINCT
        c.complaint_id,
        c.complaint_code,
        i.issue_name,
        c.updated_at,
        c.complaint_status,
        w.ward_no
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN issues i ON i.issue_id = c.issue_id
    LEFT JOIN wards w ON w.ward_id = ca.ward_id
    WHERE ca.ward_id = ?
    AND c.complaint_status = 'inspector_verification'
    ORDER BY c.updated_at DESC
    LIMIT 4",
    "i",
    $assignedWardId
);

/* =========================
   C. Citizen Objections
========================= */

$objectionRows = fetchAllRows(
    $conn,
    "SELECT DISTINCT
        rr.reopen_id,
        c.complaint_code,
        u.user_name AS citizen_name,
        i.issue_name,
        rr.created_at,
        rr.request_status,
        w.ward_no
    FROM reopen_requests rr
    INNER JOIN complaints c ON c.complaint_id = rr.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN users u ON u.user_id = c.user_id
    LEFT JOIN issues i ON i.issue_id = c.issue_id
    LEFT JOIN wards w ON w.ward_id = ca.ward_id
    WHERE ca.ward_id = ?
    AND rr.request_status IN ('pending', 'sent_to_inspector')
    ORDER BY rr.created_at DESC
    LIMIT 4",
    "i",
    $assignedWardId
);

/* =========================
   D. False Completion Reports
========================= */

$falseCompletionReports = fetchAllRows(
    $conn,
    "SELECT DISTINCT
        fcr.review_id,
        c.complaint_code,
        i.issue_name,
        mt.team_name,
        fcr.decided_at,
        w.ward_no
    FROM false_completion_reviews fcr
    INNER JOIN complaints c ON c.complaint_id = fcr.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN issues i ON i.issue_id = c.issue_id
    LEFT JOIN maintenance_teams mt ON mt.maintenance_team_id = fcr.maintenance_team_id
    LEFT JOIN wards w ON w.ward_id = ca.ward_id
    WHERE ca.ward_id = ?
    AND fcr.inspector_claim_status = 'true'
    ORDER BY fcr.decided_at DESC
    LIMIT 4",
    "i",
    $assignedWardId
);

/* =========================
   E. Recent Inspector Logs
========================= */

$recentLogs = fetchAllRows(
    $conn,
    "SELECT DISTINCT
        il.log_id,
        c.complaint_code,
        il.decision_type,
        il.created_at,
        w.ward_no
    FROM inspection_logs il
    INNER JOIN complaints c ON c.complaint_id = il.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN wards w ON w.ward_id = ca.ward_id
    WHERE ca.ward_id = ?
    ORDER BY il.created_at DESC
    LIMIT 4",
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
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
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
                        <div class="kpi-icon icon-blue">
                            <i class="bi bi-clipboard-check"></i>
                        </div>
                        <h2><?php echo $kpiSolvedByTeam; ?></h2>
                        <p>Total Solved by Team</p>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-icon icon-success">
                            <i class="bi bi-check2-circle"></i>
                        </div>
                        <h2><?php echo $kpiApprovedWork; ?></h2>
                        <p>Total Approved Work</p>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-icon icon-orange">
                            <i class="bi bi-exclamation-octagon"></i>
                        </div>
                        <h2><?php echo $kpiFalseCompletion; ?></h2>
                        <p>Total False Completion</p>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-icon icon-danger">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </div>
                        <h2><?php echo $kpiReopenCases; ?></h2>
                        <p>Total Reopen Cases</p>
                    </div>

                </div>

                <div class="dashboard-panels">

                    <div class="dashboard-panel">
                        <div class="panel-header">
                            <h3><i class="bi bi-check2-square"></i> Recent Solved by Team Cases</h3>
                        </div>
                        <div class="inspection-list">
                            <?php if (!empty($recentSolved)): ?>
                                <?php foreach ($recentSolved as $item): ?>
                                    <div class="inspection-item">
                                        <div>
                                            <span class="complaint-code"><?php echo safeText($item['complaint_code']); ?></span>
                                            <h4><?php echo safeText($item['issue_name'] ?? 'Drainage Issue'); ?></h4>
                                            <p><?php echo safeText($item['team_name'] ?? 'Team'); ?> • <?php echo safeText(timeAgo($item['updated_at'])); ?></p>
                                        </div>
                                        <span class="priority-badge priority-medium">Solved</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="bi bi-check-circle"></i>
                                    <h4>No Solved Cases</h4>
                                    <p>No recent cases solved by maintenance teams.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <a href="solved-cases.php" class="panel-link">View All Solved Cases <i class="bi bi-arrow-right"></i></a>
                    </div>

                    <div class="dashboard-panel">
                        <div class="panel-header">
                            <h3><i class="bi bi-eye"></i> Recent Inspection Queue</h3>
                        </div>
                        <div class="inspection-list">
                            <?php if (!empty($recentInspection)): ?>
                                <?php foreach ($recentInspection as $item): ?>
                                    <div class="inspection-item">
                                        <div>
                                            <span class="complaint-code"><?php echo safeText($item['complaint_code']); ?></span>
                                            <h4><?php echo safeText($item['issue_name'] ?? 'Drainage Issue'); ?></h4>
                                            <p>Ward <?php echo safeText($item['ward_no'] ?? 'N/A'); ?> • <?php echo safeText(timeAgo($item['updated_at'])); ?></p>
                                        </div>
                                        <span class="priority-badge priority-high">Pending</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="bi bi-inbox"></i>
                                    <h4>Queue is Empty</h4>
                                    <p>No pending inspections at the moment.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <a href="inspection-queue.php" class="panel-link">View Inspection Queue <i class="bi bi-arrow-right"></i></a>
                    </div>

                    <div class="dashboard-panel">
                        <div class="panel-header objection-header">
                            <h3><i class="bi bi-flag"></i> Citizen Objections</h3>
                        </div>
                        <div class="objection-list">
                            <?php if (!empty($objectionRows)): ?>
                                <?php foreach ($objectionRows as $row): ?>
                                    <div class="objection-item">
                                        <div class="objection-top">
                                            <span><?php echo safeText($row['complaint_code']); ?></span>
                                            <strong>Ward <?php echo safeText($row['ward_no'] ?? 'N/A'); ?></strong>
                                        </div>
                                        <h4><?php echo safeText($row['issue_name'] ?? 'Objection'); ?></h4>
                                        <p><?php echo safeText($row['citizen_name'] ?? 'Citizen'); ?> • <?php echo safeText(timeAgo($row['created_at'])); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state empty-danger">
                                    <i class="bi bi-check-circle"></i>
                                    <h4>No Active Objections</h4>
                                    <p>No citizen objection currently requires attention.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <a href="citizen-objections.php" class="panel-link danger-link">Review All Objections <i class="bi bi-arrow-right"></i></a>
                    </div>

                    <div class="dashboard-panel">
                        <div class="panel-header" style="background: rgba(245, 158, 11, 0.1); border-bottom: 1px solid rgba(245, 158, 11, 0.2);">
                            <h3 style="color: #B45309;"><i class="bi bi-exclamation-triangle"></i> False Completion Reports</h3>
                        </div>
                        <div class="inspection-list">
                            <?php if (!empty($falseCompletionReports)): ?>
                                <?php foreach ($falseCompletionReports as $item): ?>
                                    <div class="inspection-item">
                                        <div>
                                            <span class="complaint-code"><?php echo safeText($item['complaint_code']); ?></span>
                                            <h4><?php echo safeText($item['issue_name'] ?? 'Drainage Issue'); ?></h4>
                                            <p><?php echo safeText($item['team_name'] ?? 'Team'); ?> • <?php echo safeText(timeAgo($item['decided_at'])); ?></p>
                                        </div>
                                        <span class="priority-badge" style="background:#FEF3C7; color:#92400E;">Confirmed True</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="bi bi-shield-check" style="color: #B45309;"></i>
                                    <h4>No False Completions</h4>
                                    <p>No confirmed false completions recorded.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <a href="false-completion-reports.php" class="panel-link" style="color: #B45309;">View All False Completions <i class="bi bi-arrow-right"></i></a>
                    </div>

                    <div class="dashboard-panel full-width-panel">
                        <div class="panel-header">
                            <h3><i class="bi bi-journal-text"></i> Recent Inspector Logs</h3>
                        </div>
                        <div class="inspection-list" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px;">
                            <?php if (!empty($recentLogs)): ?>
                                <?php foreach ($recentLogs as $log): ?>
                                    <div class="inspection-item" style="border: 1px solid #E2E8F0; padding: 12px; border-radius: 8px;">
                                        <div>
                                            <span class="complaint-code"><?php echo safeText($log['complaint_code']); ?></span>
                                            <h4>Action: <?php echo safeText(ucwords(str_replace('_', ' ', $log['decision_type']))); ?></h4>
                                            <p>Ward <?php echo safeText($log['ward_no'] ?? 'N/A'); ?> • <?php echo safeText(timeAgo($log['created_at'])); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state" style="grid-column: 1 / -1;">
                                    <i class="bi bi-journal-x"></i>
                                    <h4>No Logs Found</h4>
                                    <p>You haven't made any inspection decisions yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <a href="inspection-logs.php" class="panel-link">View All Inspector Logs <i class="bi bi-arrow-right"></i></a>
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

<script src="../../js/global/confirm-modal.js"></script>
</body>

</html>