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
$activePage = 'false-completion-reports';
$pageTitle = 'False Completion Reports';

/* =========================
   Helper Functions
========================= */

function fcrBindParams($stmt, $types, &$params)
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

function fcrFetchOne($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    fcrBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function fcrFetchAll($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    fcrBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function fcrText($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fcrDateTime($datetime)
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

function fcrPriorityClass($priority)
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

function fcrStatusLabel($status)
{
    $map = [
        'submitted' => 'Submitted',
        'received' => 'Received',
        'pending_verification' => 'Ward Officer Pending Verification',
        'verified' => 'Verified / Waiting for Team Assignment',
        'team_assigned' => 'Team Assigned',
        'in_progress' => 'In Progress',
        'solved_by_team' => 'Solved by Maintenance Team',
        'inspector_verification' => 'Inspector Verification Pending',
        'closed' => 'Solved',
        'reopened' => 'Reopened',
        'disputed' => 'Disputed',
        'rejected' => 'Rejected',
        'duplicate' => 'Duplicate'
    ];

    return $map[$status] ?? ucwords(str_replace('_', ' ', (string) $status));
}

function fcrRequestStatusLabel($status)
{
    $map = [
        'pending' => 'Pending Ward Officer Review',
        'true' => 'Inspector Claim Confirmed True',
        'false' => 'Inspector Claim Marked False'
    ];

    return $map[$status] ?? ucwords(str_replace('_', ' ', (string) $status));
}

function fcrPenaltyLabel($type)
{
    $map = [
        'warning' => 'Warning',
        'demerit' => 'Demerit Point',
        'suspension' => 'Suspension'
    ];

    return $map[$type] ?? ucwords(str_replace('_', ' ', (string) $type));
}

function fcrBuildQuery($search, $teamId, $areaId, $sort, $page = 1)
{
    $query = [];

    if ($search !== '') {
        $query['search'] = $search;
    }

    if ($teamId !== '') {
        $query['team_id'] = $teamId;
    }

    if ($areaId !== '') {
        $query['area_id'] = $areaId;
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

function fcrBuildPageUrl($page, $search, $teamId, $areaId, $sort)
{
    return 'false-completion-reports.php' . fcrBuildQuery($search, $teamId, $areaId, $sort, (int) $page);
}

/* =========================
   Inspector Info
========================= */

$inspector = fcrFetchOne(
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
$teamId = trim($_GET['team_id'] ?? '');
$areaId = trim($_GET['area_id'] ?? '');
$sort = trim($_GET['sort'] ?? 'newest');

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;

if ($page < 1) {
    $page = 1;
}

$perPage = 6;
$offset = ($page - 1) * $perPage;

$allowedSorts = ['newest', 'oldest', 'priority_high', 'priority_low'];

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'newest';
}

/* =========================
   Dropdown Data
========================= */

$teamRows = fcrFetchAll(
    $conn,
    "SELECT DISTINCT
        mt.maintenance_team_id,
        mt.team_name
    FROM false_completion_reviews fcr
    INNER JOIN complaints c ON c.complaint_id = fcr.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN maintenance_teams mt ON mt.maintenance_team_id = ca.maintenance_team_id
    WHERE fcr.inspector_user_id = ?
    AND ca.ward_id = ?
    AND mt.maintenance_team_id IS NOT NULL
    ORDER BY mt.team_name ASC",
    "ii",
    [$userId, $assignedWardId]
);

$areaRows = fcrFetchAll(
    $conn,
    "SELECT area_id, area_name
    FROM areas
    WHERE ward_id = ?
    ORDER BY area_name ASC",
    "i",
    [$assignedWardId]
);

/* =========================
   Count Query
========================= */

$countWhere = "
    WHERE fcr.inspector_user_id = ?
    AND ca.ward_id = ?
";

$countTypes = "ii";
$countParams = [$userId, $assignedWardId];

if ($search !== '') {
    $countWhere .= "
        AND (
            c.complaint_code LIKE ?
            OR c.problem_description LIKE ?
            OR issue.issue_name LIKE ?
            OR a.area_name LIKE ?
            OR mt.team_name LIKE ?
            OR fcr.inspector_claim_note LIKE ?
        )
    ";

    $term = "%" . $search . "%";

    $countTypes .= "ssssss";
    $countParams[] = $term;
    $countParams[] = $term;
    $countParams[] = $term;
    $countParams[] = $term;
    $countParams[] = $term;
    $countParams[] = $term;
}

if ($teamId !== '' && ctype_digit($teamId)) {
    $countWhere .= " AND ca.maintenance_team_id = ?";
    $countTypes .= "i";
    $countParams[] = (int) $teamId;
}

if ($areaId !== '' && ctype_digit($areaId)) {
    $countWhere .= " AND a.area_id = ?";
    $countTypes .= "i";
    $countParams[] = (int) $areaId;
}

$countRow = fcrFetchOne(
    $conn,
    "SELECT COUNT(DISTINCT fcr.review_id) AS total
    FROM false_completion_reviews fcr
    INNER JOIN complaints c ON c.complaint_id = fcr.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN maintenance_teams mt ON mt.maintenance_team_id = ca.maintenance_team_id
    LEFT JOIN issues issue ON issue.issue_id = c.issue_id
    LEFT JOIN locations l ON l.loc_id = c.loc_id
    LEFT JOIN areas a ON a.area_id = l.area_id
    $countWhere",
    $countTypes,
    $countParams
);

$totalReports = $countRow ? (int) $countRow['total'] : 0;
$totalPages = (int) ceil($totalReports / $perPage);

if ($totalPages < 1) {
    $totalPages = 1;
}

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/* =========================
   Summary Stats
========================= */

$summary = fcrFetchOne(
    $conn,
    "SELECT
        COUNT(DISTINCT fcr.review_id) AS total_false_reports,
        COUNT(DISTINCT ca.maintenance_team_id) AS affected_teams,
        SUM(CASE WHEN fcr.inspector_claim_status = 'pending' THEN 1 ELSE 0 END) AS pending_reassignment,
        SUM(CASE WHEN fcr.inspector_claim_status IN ('true', 'false') THEN 1 ELSE 0 END) AS reassigned_cases
    FROM false_completion_reviews fcr
    INNER JOIN complaints c ON c.complaint_id = fcr.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    WHERE fcr.inspector_user_id = ?
    AND ca.ward_id = ?",
    "ii",
    [$userId, $assignedWardId]
);

$totalFalseReports = (int) ($summary['total_false_reports'] ?? 0);
$affectedTeams = (int) ($summary['affected_teams'] ?? 0);
$pendingReassignment = (int) ($summary['pending_reassignment'] ?? 0);
$reassignedCases = (int) ($summary['reassigned_cases'] ?? 0);

/* =========================
   Main Query
========================= */

$where = "
    WHERE fcr.inspector_user_id = ?
    AND ca.ward_id = ?
";

$types = "ii";
$params = [$userId, $assignedWardId];

if ($search !== '') {
    $where .= "
        AND (
            c.complaint_code LIKE ?
            OR c.problem_description LIKE ?
            OR issue.issue_name LIKE ?
            OR a.area_name LIKE ?
            OR mt.team_name LIKE ?
            OR fcr.inspector_claim_note LIKE ?
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

if ($teamId !== '' && ctype_digit($teamId)) {
    $where .= " AND ca.maintenance_team_id = ?";
    $types .= "i";
    $params[] = (int) $teamId;
}

if ($areaId !== '' && ctype_digit($areaId)) {
    $where .= " AND a.area_id = ?";
    $types .= "i";
    $params[] = (int) $areaId;
}

$orderBy = "ORDER BY fcr.created_at DESC";

if ($sort === 'oldest') {
    $orderBy = "ORDER BY fcr.created_at ASC";
} elseif ($sort === 'priority_high') {
    $orderBy = "ORDER BY FIELD(ca.assignment_priority, 'High', 'Medium', 'Low'), fcr.created_at DESC";
} elseif ($sort === 'priority_low') {
    $orderBy = "ORDER BY FIELD(ca.assignment_priority, 'Low', 'Medium', 'High'), fcr.created_at DESC";
}

$reportSql = "
    SELECT
        fcr.review_id,
        fcr.complaint_id,
        fcr.inspector_user_id AS reviewed_by,
        fcr.inspector_claim_note AS review_note,
        fcr.created_at AS false_reported_at,

        'false_completion' AS request_type,
        fcr.inspector_claim_status AS request_status,
        fcr.ward_decision_note AS reopen_reason,

        c.complaint_code,
        c.problem_description,
        c.address_description,
        c.complaint_status,
        c.updated_at AS complaint_updated_at,

        ca.assignment_id,
        ca.assignment_priority,
        ca.maintenance_team_id,
        ca.task_note,

        mt.team_name,
        mt.availability_status,

        issue.issue_name,

        w.ward_no,
        w.ward_name,

        a.area_id,
        a.area_name,

        latest_proof.proof_note,
        latest_proof.uploaded_at AS proof_uploaded_at,

        leader.member_id AS leader_member_id,
        leader.full_name AS leader_name,
        leader.demerit_points AS leader_demerit_points,
        leader.warning_count AS leader_warning_count,
        leader.member_status AS leader_member_status

    FROM false_completion_reviews fcr

    INNER JOIN complaints c ON c.complaint_id = fcr.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN maintenance_teams mt ON mt.maintenance_team_id = ca.maintenance_team_id
    LEFT JOIN issues issue ON issue.issue_id = c.issue_id
    LEFT JOIN locations l ON l.loc_id = c.loc_id
    LEFT JOIN areas a ON a.area_id = l.area_id
    LEFT JOIN wards w ON w.ward_id = ca.ward_id

    LEFT JOIN maintenance_team_members leader
        ON leader.maintenance_team_id = ca.maintenance_team_id
        AND leader.role = 'team_leader'

    LEFT JOIN (
        SELECT mp1.*
        FROM maintenance_proofs mp1
        INNER JOIN (
            SELECT complaint_id, MAX(proof_id) AS latest_proof_id
            FROM maintenance_proofs
            WHERE proof_stage = 'after'
            GROUP BY complaint_id
        ) latest ON latest.latest_proof_id = mp1.proof_id
    ) latest_proof ON latest_proof.complaint_id = c.complaint_id

    $where

    GROUP BY fcr.review_id

    $orderBy

    LIMIT ? OFFSET ?
";

$types .= "ii";
$params[] = $perPage;
$params[] = $offset;

$reports = fcrFetchAll($conn, $reportSql, $types, $params);

/* =========================
   Penalty History Per Report
========================= */

$penalties = [];

foreach ($reports as $report) {
    $reviewId = (int) $report['review_id'];
    $complaintId = (int) $report['complaint_id'];
    $assignmentId = (int) $report['assignment_id'];
    $teamIdForPenalty = (int) $report['maintenance_team_id'];

    $penalties[$reviewId] = fcrFetchAll(
        $conn,
        "SELECT
            p.penalty_id,
            p.penalty_type,
            p.penalty_reason,
            p.created_at,
            mtm.full_name,
            mtm.role,
            mtm.demerit_points,
            mtm.warning_count,
            mtm.member_status
        FROM maintenance_member_penalties p
        INNER JOIN maintenance_team_members mtm ON mtm.member_id = p.member_id
        WHERE p.complaint_id = ?
        AND p.assignment_id = ?
        AND p.maintenance_team_id = ?
        ORDER BY 
            FIELD(mtm.role, 'team_leader', 'assistant_team_leader', 'worker'),
            p.created_at DESC",
        "iii",
        [$complaintId, $assignmentId, $teamIdForPenalty]
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>False Completion Reports | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/inspector/sidebar.css">
    <link rel="stylesheet" href="../../css/inspector/topbar.css">
    <link rel="stylesheet" href="../../css/inspector/false-completion-reports.css">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>

<body class="inspector">

    <div class="inspector-layout">

        <?php include __DIR__ . '/../../includes/inspector/sidebar.php'; ?>

        <main class="inspector-main">

            <?php include __DIR__ . '/../../includes/inspector/topbar.php'; ?>

            <section class="false-completion-page">

                <div class="page-heading">
                    <h1>False Completion Reports</h1>
                    <p>Track confirmed false completion claims, penalty consequences, and reassignment status.</p>
                </div>

                <div class="summary-grid">
                    <div class="summary-card">
                        <i class="bi bi-exclamation-triangle"></i>
                        <div>
                            <span><?php echo $totalFalseReports; ?></span>
                            <p>Total False Reports</p>
                        </div>
                    </div>

                    <div class="summary-card">
                        <i class="bi bi-people"></i>
                        <div>
                            <span><?php echo $affectedTeams; ?></span>
                            <p>Affected Teams</p>
                        </div>
                    </div>

                    <div class="summary-card warning">
                        <i class="bi bi-arrow-return-left"></i>
                        <div>
                            <span><?php echo $pendingReassignment; ?></span>
                            <p>Waiting Reassignment</p>
                        </div>
                    </div>

                    <div class="summary-card success">
                        <i class="bi bi-check2-circle"></i>
                        <div>
                            <span><?php echo $reassignedCases; ?></span>
                            <p>Reassigned Cases</p>
                        </div>
                    </div>
                </div>

                <form method="GET" class="filter-card" id="falseCompletionFilterForm">

                    <input
                        type="text"
                        name="search"
                        class="filter-control"
                        placeholder="Search complaint, team, issue, area, inspector note..."
                        value="<?php echo fcrText($search); ?>">

                    <select name="team_id" class="filter-control">
                        <option value="">All Teams</option>

                        <?php foreach ($teamRows as $team): ?>
                            <option value="<?php echo (int) $team['maintenance_team_id']; ?>"
                                <?php echo ($teamId !== '' && (int) $teamId === (int) $team['maintenance_team_id']) ? 'selected' : ''; ?>>
                                <?php echo fcrText($team['team_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="area_id" class="filter-control">
                        <option value="">All Areas</option>

                        <?php foreach ($areaRows as $area): ?>
                            <option value="<?php echo (int) $area['area_id']; ?>"
                                <?php echo ($areaId !== '' && (int) $areaId === (int) $area['area_id']) ? 'selected' : ''; ?>>
                                <?php echo fcrText($area['area_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="sort" class="filter-control sort-control">
                        <option value="newest" <?php echo ($sort === 'newest') ? 'selected' : ''; ?>>Sort by: Newest</option>
                        <option value="oldest" <?php echo ($sort === 'oldest') ? 'selected' : ''; ?>>Sort by: Oldest</option>
                        <option value="priority_high" <?php echo ($sort === 'priority_high') ? 'selected' : ''; ?>>Priority: High to Low</option>
                        <option value="priority_low" <?php echo ($sort === 'priority_low') ? 'selected' : ''; ?>>Priority: Low to High</option>
                    </select>

                </form>

                <div class="report-list">

                    <?php if (!empty($reports)): ?>

                        <?php foreach ($reports as $report): ?>
                            <?php
                            $reviewId = (int) $report['review_id'];
                            $priority = $report['assignment_priority'] ?: 'Medium';
                            $reportPenalties = $penalties[$reviewId] ?? [];
                            ?>

                            <article class="report-card">

                                <button type="button" class="report-toggle">
                                    <div>
                                        <div class="case-meta-row">
                                            <span class="case-code">
                                                <?php echo fcrText($report['complaint_code']); ?>
                                            </span>

                                            <span class="priority-badge <?php echo fcrPriorityClass($priority); ?>">
                                                <?php echo fcrText($priority); ?>
                                            </span>

                                            <span class="status-badge">
                                                <?php echo fcrText(fcrRequestStatusLabel($report['request_status'])); ?>
                                            </span>
                                        </div>

                                        <h3><?php echo fcrText($report['issue_name'] ?: 'Drainage Issue'); ?></h3>

                                        <p>
                                            <?php echo fcrText($report['area_name'] ?: 'N/A'); ?>,
                                            Ward <?php echo fcrText($report['ward_no'] ?: 'N/A'); ?>
                                            ·
                                            <?php echo fcrText($report['team_name'] ?: 'No Team'); ?>
                                        </p>
                                    </div>

                                    <div class="toggle-side">
                                        <span><?php echo fcrText(fcrDateTime($report['false_reported_at'])); ?></span>
                                        <i class="bi bi-chevron-down"></i>
                                    </div>
                                </button>

                                <div class="report-details">

                                    <div class="info-grid">

                                        <div>
                                            <span>Complaint Status</span>
                                            <strong><?php echo fcrText(fcrStatusLabel($report['complaint_status'])); ?></strong>
                                        </div>

                                        <div>
                                            <span>Current Flow</span>
                                            <strong><?php echo fcrText(fcrRequestStatusLabel($report['request_status'])); ?></strong>
                                        </div>

                                        <div>
                                            <span>Maintenance Team</span>
                                            <strong><?php echo fcrText($report['team_name'] ?: 'N/A'); ?></strong>
                                        </div>

                                        <div>
                                            <span>Team Leader</span>
                                            <strong><?php echo fcrText($report['leader_name'] ?: 'N/A'); ?></strong>
                                        </div>

                                        <div>
                                            <span>Leader Demerit</span>
                                            <strong><?php echo (int) ($report['leader_demerit_points'] ?? 0); ?></strong>
                                        </div>

                                        <div>
                                            <span>Leader Status</span>
                                            <strong><?php echo fcrText(ucwords(str_replace('_', ' ', $report['leader_member_status'] ?? 'active'))); ?></strong>
                                        </div>

                                    </div>

                                    <div class="note-box">
                                        <span>Inspector False Completion Finding</span>
                                        <p><?php echo fcrText($report['review_note'] ?: 'No inspector note recorded.'); ?></p>
                                    </div>

                                    <div class="note-box muted">
                                        <span>Team Completion Proof Note</span>
                                        <p><?php echo fcrText($report['proof_note'] ?: 'No proof note found.'); ?></p>
                                    </div>

                                    <div class="penalty-section">
                                        <h4>
                                            <i class="bi bi-shield-exclamation"></i>
                                            Penalty / Accountability History
                                        </h4>

                                        <?php if (!empty($reportPenalties)): ?>
                                            <div class="penalty-table-wrap">
                                                <table class="penalty-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Member</th>
                                                            <th>Role</th>
                                                            <th>Penalty</th>
                                                            <th>Demerit</th>
                                                            <th>Warning</th>
                                                            <th>Status</th>
                                                            <th>Date</th>
                                                        </tr>
                                                    </thead>

                                                    <tbody>
                                                        <?php foreach ($reportPenalties as $penalty): ?>
                                                            <tr>
                                                                <td><?php echo fcrText($penalty['full_name']); ?></td>
                                                                <td><?php echo fcrText(ucwords(str_replace('_', ' ', $penalty['role']))); ?></td>
                                                                <td>
                                                                    <span class="penalty-badge <?php echo fcrText($penalty['penalty_type']); ?>">
                                                                        <?php echo fcrText(fcrPenaltyLabel($penalty['penalty_type'])); ?>
                                                                    </span>
                                                                </td>
                                                                <td><?php echo (int) $penalty['demerit_points']; ?></td>
                                                                <td><?php echo (int) $penalty['warning_count']; ?></td>
                                                                <td><?php echo fcrText(ucwords(str_replace('_', ' ', $penalty['member_status']))); ?></td>
                                                                <td><?php echo fcrText(fcrDateTime($penalty['created_at'])); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="empty-penalty">
                                                <i class="bi bi-info-circle"></i>
                                                No penalty log found for this report.
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <div class="empty-state">
                            <i class="bi bi-check2-circle"></i>
                            <h3>No False Completion Report Found</h3>
                            <p>No confirmed false completion case is available for your assigned ward.</p>
                        </div>

                    <?php endif; ?>

                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination-wrap">

                        <?php if ($page > 1): ?>
                            <a href="<?php echo fcrText(fcrBuildPageUrl($page - 1, $search, $teamId, $areaId, $sort)); ?>" class="page-btn">
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
                                <a href="<?php echo fcrText(fcrBuildPageUrl($i, $search, $teamId, $areaId, $sort)); ?>"
                                   class="page-number <?php echo ($i === $page) ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?php echo fcrText(fcrBuildPageUrl($page + 1, $search, $teamId, $areaId, $sort)); ?>" class="page-btn">
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

    <script src="../../js/inspector/sidebar.js"></script>
    <script src="../../js/inspector/false-completion-reports.js"></script>

</body>

</html>