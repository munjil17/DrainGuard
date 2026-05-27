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
$activePage = 'inspection-logs';
$pageTitle = 'Inspection Logs';

/* =========================
   Helper Functions
========================= */

function ilBindParams($stmt, $types, &$params)
{
    if (empty($types) || empty($params)) {
        return;
    }

    $bindValues = [];
    $bindValues[] = $types;

    for ($i = 0; $i < count($params); $i++) {
        $bindValues[] = &$params[$i];
    }

    call_user_func_array([$stmt, 'bind_param'], $bindValues);
}

function ilFetchOne($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    ilBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function ilFetchAll($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    ilBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function ilText($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ilDate($datetime)
{
    if (empty($datetime)) {
        return "Not available";
    }

    $timestamp = strtotime($datetime);

    if (!$timestamp) {
        return "Not available";
    }

    return date("M j, Y", $timestamp);
}

function ilDateTime($datetime)
{
    if (empty($datetime)) {
        return "Not available";
    }

    $timestamp = strtotime($datetime);

    if (!$timestamp) {
        return "Not available";
    }

    return date("M j, Y · h:i A", $timestamp);
}

function ilDecisionLabel($decision)
{
    $map = [
        'approved' => 'Approved',
        'false_completion' => 'False Completion',
        'sent_to_ward_for_reassign' => 'Sent to Ward',
        'rejected_objection' => 'Rejected Objection'
    ];

    return $map[$decision] ?? ucwords(str_replace('_', ' ', (string) $decision));
}

function ilDecisionClass($decision)
{
    $decision = strtolower((string) $decision);

    if ($decision === 'approved') {
        return 'decision-approved';
    }

    if ($decision === 'false_completion') {
        return 'decision-false';
    }

    if ($decision === 'sent_to_ward_for_reassign') {
        return 'decision-sent';
    }

    if ($decision === 'rejected_objection') {
        return 'decision-rejected';
    }

    return 'decision-default';
}

function ilBuildQuery($search, $decision, $areaId, $dateRange, $sort, $page = 1)
{
    $query = [];

    if ($search !== '') {
        $query['search'] = $search;
    }

    if ($decision !== '') {
        $query['decision'] = $decision;
    }

    if ($areaId !== '') {
        $query['area_id'] = $areaId;
    }

    if ($dateRange !== '') {
        $query['date_range'] = $dateRange;
    }

    if ($sort !== '') {
        $query['sort'] = $sort;
    }

    if ($page > 1) {
        $query['page'] = (int) $page;
    }

    $queryString = http_build_query($query);

    return $queryString !== '' ? '?' . $queryString : '';
}

function ilBuildPageUrl($page, $search, $decision, $areaId, $dateRange, $sort)
{
    return 'inspection-logs.php' . ilBuildQuery($search, $decision, $areaId, $dateRange, $sort, (int) $page);
}

/* =========================
   Inspector Info
========================= */

$inspector = ilFetchOne(
    $conn,
    "SELECT 
        i.inspector_id,
        i.user_id,
        i.assigned_ward_id,
        i.full_name,
        u.user_name
    FROM inspectors i
    INNER JOIN users u ON u.user_id = i.user_id
    WHERE i.user_id = ?
    LIMIT 1",
    "i",
    [$userId]
);

if (!$inspector) {
    die("Inspector profile not found for this logged-in user.");
}

$assignedWardId = (int) $inspector['assigned_ward_id'];
$inspectorName = !empty($inspector['full_name']) ? $inspector['full_name'] : ($inspector['user_name'] ?? 'Inspector');

$_SESSION['user_name'] = $inspectorName;

/* =========================
   Filters + Pagination
========================= */

$search = trim($_GET['search'] ?? '');
$decision = trim($_GET['decision'] ?? '');
$areaId = trim($_GET['area_id'] ?? '');
$dateRange = trim($_GET['date_range'] ?? 'all');
$sort = trim($_GET['sort'] ?? 'newest');

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;

if ($page < 1) {
    $page = 1;
}

$perPage = 10;
$offset = ($page - 1) * $perPage;

$allowedDecisions = ['', 'approved', 'false_completion', 'sent_to_ward_for_reassign', 'rejected_objection'];
$allowedDateRanges = ['all', '7_days', '30_days', '90_days'];
$allowedSorts = ['newest', 'oldest'];

if (!in_array($decision, $allowedDecisions, true)) {
    $decision = '';
}

if (!in_array($dateRange, $allowedDateRanges, true)) {
    $dateRange = 'all';
}

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'newest';
}

/* =========================
   Dropdown Area
========================= */

$areaRows = ilFetchAll(
    $conn,
    "SELECT area_id, area_name
    FROM areas
    WHERE ward_id = ?
    ORDER BY area_name ASC",
    "i",
    [$assignedWardId]
);

/* =========================
   Summary
========================= */

$summary = ilFetchOne(
    $conn,
    "SELECT
        COUNT(DISTINCT il.log_id) AS total_logs,
        SUM(CASE WHEN il.decision_type = 'approved' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN il.decision_type = 'false_completion' THEN 1 ELSE 0 END) AS false_count,
        SUM(CASE WHEN il.decision_type = 'sent_to_ward_for_reassign' THEN 1 ELSE 0 END) AS sent_ward_count,
        SUM(CASE WHEN il.decision_type = 'rejected_objection' THEN 1 ELSE 0 END) AS rejected_objection_count
    FROM inspection_logs il
    INNER JOIN complaints c ON c.complaint_id = il.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND il.inspector_user_id = ?",
    "ii",
    [$assignedWardId, $userId]
);

$totalLogs = (int) ($summary['total_logs'] ?? 0);
$approvedCount = (int) ($summary['approved_count'] ?? 0);
$falseCount = (int) ($summary['false_count'] ?? 0);
$sentWardCount = (int) ($summary['sent_ward_count'] ?? 0);
$rejectionCount = (int) ($summary['rejected_objection_count'] ?? 0);

$approvalRate = 0;

if ($totalLogs > 0) {
    $approvalRate = round(($approvedCount / $totalLogs) * 100);
}

/* =========================
   Base WHERE
========================= */

$whereSql = "
    WHERE ca.ward_id = ?
    AND il.inspector_user_id = ?
";

$types = "ii";
$params = [$assignedWardId, $userId];

if ($search !== '') {
    $whereSql .= "
        AND (
            c.complaint_code LIKE ?
            OR c.problem_description LIKE ?
            OR issue.issue_name LIKE ?
            OR a.area_name LIKE ?
            OR mt.team_name LIKE ?
            OR il.decision_note LIKE ?
        )
    ";

    $term = "%" . $search . "%";

    $types .= "ssssss";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($decision !== '') {
    $whereSql .= " AND il.decision_type = ?";
    $types .= "s";
    $params[] = $decision;
}

if ($areaId !== '' && ctype_digit($areaId)) {
    $whereSql .= " AND a.area_id = ?";
    $types .= "i";
    $params[] = (int) $areaId;
}

if ($dateRange === '7_days') {
    $whereSql .= " AND il.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($dateRange === '30_days') {
    $whereSql .= " AND il.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($dateRange === '90_days') {
    $whereSql .= " AND il.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
}

/* =========================
   Count
========================= */

$countRow = ilFetchOne(
    $conn,
    "SELECT COUNT(DISTINCT il.log_id) AS total
    FROM inspection_logs il
    INNER JOIN complaints c ON c.complaint_id = il.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN maintenance_teams mt ON mt.maintenance_team_id = ca.maintenance_team_id
    LEFT JOIN issues issue ON issue.issue_id = c.issue_id
    LEFT JOIN locations l ON l.loc_id = c.loc_id
    LEFT JOIN areas a ON a.area_id = l.area_id
    $whereSql",
    $types,
    $params
);

$totalFiltered = $countRow ? (int) $countRow['total'] : 0;
$totalPages = (int) ceil($totalFiltered / $perPage);

if ($totalPages < 1) {
    $totalPages = 1;
}

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/* =========================
   Main Query
========================= */

$orderBy = $sort === 'oldest'
    ? "ORDER BY il.created_at ASC"
    : "ORDER BY il.created_at DESC";

$mainTypes = $types . "ii";
$mainParams = $params;
$mainParams[] = $perPage;
$mainParams[] = $offset;

$logs = ilFetchAll(
    $conn,
    "SELECT
        il.log_id,
        il.complaint_id,
        il.assignment_id,
        il.inspector_user_id,
        il.decision_type,
        il.decision_note,
        il.source_type,
        il.source_id,
        il.created_at AS decision_date,

        c.complaint_code,
        c.problem_description,
        c.complaint_status,

        ca.assignment_priority,
        ca.maintenance_team_id,

        mt.team_name,

        issue.issue_name,

        a.area_name,

        w.ward_no,

        i.full_name AS inspector_name

    FROM inspection_logs il

    INNER JOIN complaints c ON c.complaint_id = il.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id

    LEFT JOIN maintenance_teams mt ON mt.maintenance_team_id = ca.maintenance_team_id
    LEFT JOIN issues issue ON issue.issue_id = c.issue_id
    LEFT JOIN locations l ON l.loc_id = c.loc_id
    LEFT JOIN areas a ON a.area_id = l.area_id
    LEFT JOIN wards w ON w.ward_id = ca.ward_id
    LEFT JOIN inspectors i ON i.user_id = il.inspector_user_id

    $whereSql

    GROUP BY il.log_id

    $orderBy

    LIMIT ? OFFSET ?",
    $mainTypes,
    $mainParams
);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Inspection Logs | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/inspector/sidebar.css">
    <link rel="stylesheet" href="../../css/inspector/topbar.css">
    <link rel="stylesheet" href="../../css/inspector/inspection-logs.css">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>

<body class="inspector">

    <div class="inspector-layout">

        <?php include __DIR__ . '/../../includes/inspector/sidebar.php'; ?>

        <main class="inspector-main">

            <?php include __DIR__ . '/../../includes/inspector/topbar.php'; ?>

            <section class="inspection-logs-page">

                <div class="page-heading">
                    <h1>Inspection Logs</h1>
                    <p>Complete read-only history of inspector decisions, remarks, and verification outcomes.</p>
                </div>

                <div class="summary-grid">

                    <div class="summary-card">
                        <i class="bi bi-clipboard-check"></i>
                        <div>
                            <span><?php echo $totalLogs; ?></span>
                            <p>Total Logs</p>
                        </div>
                    </div>

                    <div class="summary-card success">
                        <i class="bi bi-check2-circle"></i>
                        <div>
                            <span><?php echo $approvedCount; ?></span>
                            <p>Approved</p>
                        </div>
                    </div>

                    <div class="summary-card danger">
                        <i class="bi bi-exclamation-triangle"></i>
                        <div>
                            <span><?php echo $falseCount; ?></span>
                            <p>False Completion</p>
                        </div>
                    </div>

                    <div class="summary-card warning">
                        <i class="bi bi-arrow-return-left"></i>
                        <div>
                            <span><?php echo $sentWardCount; ?></span>
                            <p>Sent to Ward</p>
                        </div>
                    </div>

                    <div class="summary-card">
                        <i class="bi bi-x-circle"></i>
                        <div>
                            <span><?php echo $rejectionCount; ?></span>
                            <p>Rejected Objection</p>
                        </div>
                    </div>

                    <div class="summary-card">
                        <i class="bi bi-graph-up-arrow"></i>
                        <div>
                            <span><?php echo $approvalRate; ?>%</span>
                            <p>Approval Rate</p>
                        </div>
                    </div>

                </div>

                <form method="GET" class="filter-card" id="inspectionLogsFilterForm">

                    <input
                        type="text"
                        name="search"
                        class="filter-control"
                        placeholder="Search by task ID, issue, team, area, note..."
                        value="<?php echo ilText($search); ?>">

                    <select name="decision" class="filter-control">
                        <option value="">All Decisions</option>
                        <option value="approved" <?php echo ($decision === 'approved') ? 'selected' : ''; ?>>Approved</option>
                        <option value="false_completion" <?php echo ($decision === 'false_completion') ? 'selected' : ''; ?>>False Completion</option>
                        <option value="sent_to_ward_for_reassign" <?php echo ($decision === 'sent_to_ward_for_reassign') ? 'selected' : ''; ?>>Sent to Ward</option>
                        <option value="rejected_objection" <?php echo ($decision === 'rejected_objection') ? 'selected' : ''; ?>>Rejected Objection</option>
                    </select>

                    <select name="area_id" class="filter-control">
                        <option value="">All Areas</option>

                        <?php foreach ($areaRows as $area): ?>
                            <option value="<?php echo (int) $area['area_id']; ?>"
                                <?php echo ($areaId !== '' && (int) $areaId === (int) $area['area_id']) ? 'selected' : ''; ?>>
                                <?php echo ilText($area['area_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="date_range" class="filter-control">
                        <option value="all" <?php echo ($dateRange === 'all') ? 'selected' : ''; ?>>All Time</option>
                        <option value="7_days" <?php echo ($dateRange === '7_days') ? 'selected' : ''; ?>>Last 7 Days</option>
                        <option value="30_days" <?php echo ($dateRange === '30_days') ? 'selected' : ''; ?>>Last 30 Days</option>
                        <option value="90_days" <?php echo ($dateRange === '90_days') ? 'selected' : ''; ?>>Last 90 Days</option>
                    </select>

                    <select name="sort" class="filter-control sort-control">
                        <option value="newest" <?php echo ($sort === 'newest') ? 'selected' : ''; ?>>Sort by: Newest</option>
                        <option value="oldest" <?php echo ($sort === 'oldest') ? 'selected' : ''; ?>>Sort by: Oldest</option>
                    </select>

                </form>

                <div class="logs-table-card">

                    <?php if (!empty($logs)): ?>

                        <div class="table-responsive">
                            <table class="logs-table">
                                <thead>
                                    <tr>
                                        <th>Task ID</th>
                                        <th>Issue Type</th>
                                        <th>Area</th>
                                        <th>Team</th>
                                        <th>Inspection Date</th>
                                        <th>Decision</th>
                                        <th>Inspector</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td>
                                                <span class="task-id">
                                                    <?php echo ilText($log['complaint_code']); ?>
                                                </span>
                                            </td>

                                            <td>
                                                <?php echo ilText($log['issue_name'] ?: 'Drainage Issue'); ?>
                                            </td>

                                            <td>
                                                <?php echo ilText($log['area_name'] ?: 'N/A'); ?>,
                                                Ward <?php echo ilText($log['ward_no'] ?: 'N/A'); ?>
                                            </td>

                                            <td>
                                                <?php echo ilText($log['team_name'] ?: 'No Team'); ?>
                                            </td>

                                            <td>
                                                <?php echo ilText(ilDate($log['decision_date'])); ?>
                                            </td>

                                            <td>
                                                <span class="decision-badge <?php echo ilDecisionClass($log['decision_type']); ?>">
                                                    <?php echo ilText(ilDecisionLabel($log['decision_type'])); ?>
                                                </span>
                                            </td>

                                            <td>
                                                <?php echo ilText($log['inspector_name'] ?: $inspectorName); ?>
                                            </td>

                                            <td>
                                                <button
                                                    type="button"
                                                    class="view-note-btn"
                                                    data-note="<?php echo ilText($log['decision_note'] ?: 'No note recorded.'); ?>"
                                                    data-task="<?php echo ilText($log['complaint_code']); ?>"
                                                    data-decision="<?php echo ilText(ilDecisionLabel($log['decision_type'])); ?>"
                                                    data-date="<?php echo ilText(ilDateTime($log['decision_date'])); ?>">
                                                    <i class="bi bi-eye"></i>
                                                    View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php else: ?>

                        <div class="empty-state">
                            <i class="bi bi-clipboard-check"></i>
                            <h3>No Inspection Log Found</h3>
                            <p>No inspector decision history found for your selected filters.</p>
                        </div>

                    <?php endif; ?>

                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination-wrap">

                        <?php if ($page > 1): ?>
                            <a href="<?php echo ilText(ilBuildPageUrl($page - 1, $search, $decision, $areaId, $dateRange, $sort)); ?>" class="page-btn">
                                <i class="bi bi-chevron-left"></i>
                                Previous
                            </a>
                        <?php else: ?>
                            <span class="page-btn disabled">
                                <i class="bi bi-chevron-left"></i>
                                Previous
                            </span>
                        <?php endif; ?>

                        <div class="page-numbers">
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            ?>

                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <a href="<?php echo ilText(ilBuildPageUrl($i, $search, $decision, $areaId, $dateRange, $sort)); ?>"
                                   class="page-number <?php echo ($i === $page) ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?php echo ilText(ilBuildPageUrl($page + 1, $search, $decision, $areaId, $dateRange, $sort)); ?>" class="page-btn">
                                Next
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="page-btn disabled">
                                Next
                                <i class="bi bi-chevron-right"></i>
                            </span>
                        <?php endif; ?>

                    </div>
                <?php endif; ?>

            </section>

            <?php
            $footerPath = __DIR__ . '/../../includes/inspector/footer.php';

            if (file_exists($footerPath)) {
                include $footerPath;
            }
            ?>

        </main>

    </div>

    <div class="log-modal" id="logModal">
        <div class="log-modal-backdrop" data-close-modal></div>

        <div class="log-modal-card">
            <button type="button" class="modal-close-btn" data-close-modal>
                <i class="bi bi-x-lg"></i>
            </button>

            <h3 id="modalTaskId">Task ID</h3>
            <p class="modal-meta">
                <span id="modalDecision">Decision</span>
                ·
                <span id="modalDate">Date</span>
            </p>

            <div class="modal-note-box">
                <span>Inspector Note</span>
                <p id="modalNote">No note recorded.</p>
            </div>
        </div>
    </div>

    <script src="../../js/inspector/sidebar.js"></script>
    <script src="../../js/inspector/inspection-logs.js"></script>

</body>

</html>