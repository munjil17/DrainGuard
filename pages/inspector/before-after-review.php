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

$activePage = 'before-after-review';
$pageTitle = 'Before / After Review';

/* =========================
   Helper Functions
========================= */

function barBindParams($stmt, $types, &$params)
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

function barFetchOne($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    barBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function barFetchAll($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    barBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function barText($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function barMediaPath($path)
{
    if (empty($path)) {
        return "";
    }

    $path = trim($path);

    if (preg_match('/^https?:\/\//', $path)) {
        return $path;
    }

    if (substr($path, 0, 6) === "../../") {
        return $path;
    }

    return "../../" . ltrim($path, "/");
}

function barDate($datetime)
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

function barStatusLabel($status)
{
    $map = [
        'submitted' => 'Submitted',
        'received' => 'Received',
        'pending_verification' => 'Ward Officer Pending Verification',
        'verified' => 'Verified by Ward Officer',
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

function barPriorityClass($priority)
{
    $priority = strtolower((string) $priority);

    if ($priority === "high") {
        return "priority-high";
    }

    if ($priority === "medium") {
        return "priority-medium";
    }

    return "priority-low";
}

function barBuildCaseUrl($complaintId, $search, $teamId, $areaId, $sort, $page = 1)
{
    $query = [
        'complaint_id' => (int) $complaintId,
        'page' => (int) $page
    ];

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

    return 'before-after-review.php?' . http_build_query($query);
}

function barBuildPageUrl($page, $search, $teamId, $areaId, $sort)
{
    $query = [
        'page' => (int) $page
    ];

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

    return 'before-after-review.php?' . http_build_query($query);
}

/* =========================
   Inspector Info
========================= */

$inspector = barFetchOne(
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
$selectedComplaintId = isset($_GET['complaint_id']) ? (int) $_GET['complaint_id'] : 0;

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;

if ($page < 1) {
    $page = 1;
}

$perPage = 8;
$offset = ($page - 1) * $perPage;

$allowedSorts = ['newest', 'oldest', 'priority_high', 'priority_low'];

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'newest';
}

/* =========================
   Dropdown Data
========================= */

$teamRows = barFetchAll(
    $conn,
    "SELECT DISTINCT
        mt.maintenance_team_id,
        mt.team_name
    FROM maintenance_teams mt
    INNER JOIN complaint_assignments ca ON ca.maintenance_team_id = mt.maintenance_team_id
    INNER JOIN complaints c ON c.complaint_id = ca.complaint_id
    WHERE ca.ward_id = ?
    AND c.complaint_status = 'inspector_verification'
    ORDER BY mt.team_name ASC",
    "i",
    [$assignedWardId]
);

$areaRows = barFetchAll(
    $conn,
    "SELECT area_id, area_name
    FROM areas
    WHERE ward_id = ?
    ORDER BY area_name ASC",
    "i",
    [$assignedWardId]
);

/* =========================
   Count With Filters
========================= */

$countWhereSql = "
    WHERE ca.ward_id = ?
    AND c.complaint_status = 'inspector_verification'
";

$countTypes = "i";
$countParams = [$assignedWardId];

if ($search !== '') {
    $countWhereSql .= "
        AND (
            c.complaint_code LIKE ?
            OR c.problem_description LIKE ?
            OR issue.issue_name LIKE ?
            OR d.drain_name LIKE ?
            OR d.drain_code LIKE ?
            OR a.area_name LIKE ?
        )
    ";

    $searchTerm = "%" . $search . "%";

    $countTypes .= "ssssss";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}

if ($teamId !== '' && ctype_digit($teamId)) {
    $countWhereSql .= " AND ca.maintenance_team_id = ?";
    $countTypes .= "i";
    $countParams[] = (int) $teamId;
}

if ($areaId !== '' && ctype_digit($areaId)) {
    $countWhereSql .= " AND a.area_id = ?";
    $countTypes .= "i";
    $countParams[] = (int) $areaId;
}

$countRow = barFetchOne(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN issues issue ON issue.issue_id = c.issue_id
    LEFT JOIN locations l ON l.loc_id = c.loc_id
    LEFT JOIN areas a ON a.area_id = l.area_id
    LEFT JOIN drains d ON d.drain_id = c.drain_id
    $countWhereSql",
    $countTypes,
    $countParams
);

$totalReviewCases = $countRow ? (int) $countRow['total'] : 0;
$totalPages = (int) ceil($totalReviewCases / $perPage);

if ($totalPages < 1) {
    $totalPages = 1;
}

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/* =========================
   Main List Query
========================= */

$whereSql = "
    WHERE ca.ward_id = ?
    AND c.complaint_status = 'inspector_verification'
";

$types = "i";
$params = [$assignedWardId];

if ($search !== '') {
    $whereSql .= "
        AND (
            c.complaint_code LIKE ?
            OR c.problem_description LIKE ?
            OR issue.issue_name LIKE ?
            OR d.drain_name LIKE ?
            OR d.drain_code LIKE ?
            OR a.area_name LIKE ?
        )
    ";

    $searchTerm = "%" . $search . "%";

    $types .= "ssssss";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($teamId !== '' && ctype_digit($teamId)) {
    $whereSql .= " AND ca.maintenance_team_id = ?";
    $types .= "i";
    $params[] = (int) $teamId;
}

if ($areaId !== '' && ctype_digit($areaId)) {
    $whereSql .= " AND a.area_id = ?";
    $types .= "i";
    $params[] = (int) $areaId;
}

$orderBy = "ORDER BY after_proof.uploaded_at DESC, c.updated_at DESC";

if ($sort === 'oldest') {
    $orderBy = "ORDER BY after_proof.uploaded_at ASC, c.updated_at ASC";
} elseif ($sort === 'priority_high') {
    $orderBy = "ORDER BY FIELD(ca.assignment_priority, 'High', 'Medium', 'Low'), after_proof.uploaded_at DESC";
} elseif ($sort === 'priority_low') {
    $orderBy = "ORDER BY FIELD(ca.assignment_priority, 'Low', 'Medium', 'High'), after_proof.uploaded_at DESC";
}

$reviewListSql = "
    SELECT
        c.complaint_id,
        c.complaint_code,
        c.problem_description,
        c.address_description,
        c.complaint_status,
        c.submitted_at,
        c.updated_at,

        ca.assignment_id,
        ca.assignment_priority,
        ca.task_note,
        ca.assigned_at,
        ca.deadline_at,

        mt.team_name,

        issue.issue_name,

        w.ward_no,
        w.ward_name,

        a.area_id,
        a.area_name,

        d.drain_code,
        d.drain_name,

        after_proof.proof_id,
        after_proof.media_path AS after_media_path,
        after_proof.media_type AS after_media_type,
        after_proof.proof_note,
        after_proof.uploaded_at AS after_uploaded_at,

        COALESCE(proof_count.total_proofs, 0) AS total_proofs

    FROM complaints c

    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id

    LEFT JOIN maintenance_teams mt ON mt.maintenance_team_id = ca.maintenance_team_id

    LEFT JOIN issues issue ON issue.issue_id = c.issue_id

    LEFT JOIN locations l ON l.loc_id = c.loc_id

    LEFT JOIN areas a ON a.area_id = l.area_id

    LEFT JOIN wards w ON w.ward_id = ca.ward_id

    LEFT JOIN drains d ON d.drain_id = c.drain_id

    LEFT JOIN (
        SELECT mp1.*
        FROM maintenance_proofs mp1
        INNER JOIN (
            SELECT complaint_id, MAX(proof_id) AS latest_proof_id
            FROM maintenance_proofs
            WHERE proof_stage = 'after'
            AND media_path IS NOT NULL
            AND media_path <> ''
            GROUP BY complaint_id
        ) latest ON latest.latest_proof_id = mp1.proof_id
    ) after_proof ON after_proof.complaint_id = c.complaint_id

    LEFT JOIN (
        SELECT complaint_id, COUNT(proof_id) AS total_proofs
        FROM maintenance_proofs
        WHERE proof_stage = 'after'
        AND media_path IS NOT NULL
        AND media_path <> ''
        GROUP BY complaint_id
    ) proof_count ON proof_count.complaint_id = c.complaint_id

    $whereSql

    GROUP BY c.complaint_id

    $orderBy

    LIMIT ? OFFSET ?
";

$types .= "ii";
$params[] = $perPage;
$params[] = $offset;

$reviewRows = barFetchAll($conn, $reviewListSql, $types, $params);

/* =========================
   Selected Complaint Details
   Details show only after card click
========================= */

$selectedComplaint = null;
$beforeMedia = [];
$afterProofs = [];
$latestProofNote = 'No proof note provided by maintenance team.';

if ($selectedComplaintId > 0) {
    $selectedComplaint = barFetchOne(
        $conn,
        "SELECT
            c.complaint_id,
            c.complaint_code,
            c.user_id,
            c.problem_description,
            c.address_description,
            c.complaint_status,
            c.submitted_at,
            c.updated_at,

            ca.assignment_id,
            ca.assignment_priority,
            ca.task_note,
            ca.assigned_at,
            ca.deadline_at,

            mt.team_name,

            issue.issue_name,

            w.ward_no,
            w.ward_name,

            a.area_name,

            d.drain_code,
            d.drain_name,
            d.drain_address_description,
            d.drain_condition,

            ct.full_name AS citizen_name,
            ct.phone_number AS citizen_phone,
            ct.user_mail AS citizen_email,
            ct.street_village,
            ct.union_area,
            ct.upazila_thana,
            ct.district,
            ct.division

        FROM complaints c
        INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
        LEFT JOIN maintenance_teams mt ON mt.maintenance_team_id = ca.maintenance_team_id
        LEFT JOIN issues issue ON issue.issue_id = c.issue_id
        LEFT JOIN locations l ON l.loc_id = c.loc_id
        LEFT JOIN areas a ON a.area_id = l.area_id
        LEFT JOIN wards w ON w.ward_id = ca.ward_id
        LEFT JOIN drains d ON d.drain_id = c.drain_id
        LEFT JOIN citizens ct ON ct.user_id = c.user_id

        WHERE c.complaint_id = ?
        AND ca.ward_id = ?
        AND c.complaint_status = 'inspector_verification'
        LIMIT 1",
        "ii",
        [$selectedComplaintId, $assignedWardId]
    );

    if ($selectedComplaint) {
        $beforeMedia = barFetchAll(
            $conn,
            "SELECT
                media_id,
                media_type,
                media_path,
                original_name,
                uploaded_at
            FROM complaint_media
            WHERE complaint_id = ?
            ORDER BY uploaded_at ASC",
            "i",
            [$selectedComplaintId]
        );

        $afterProofs = barFetchAll(
            $conn,
            "SELECT
                proof_id,
                proof_stage,
                media_type,
                media_path,
                original_name,
                proof_note,
                proof_status,
                uploaded_at
            FROM maintenance_proofs
            WHERE complaint_id = ?
            AND proof_stage = 'after'
            ORDER BY uploaded_at ASC",
            "i",
            [$selectedComplaintId]
        );

        if (!empty($afterProofs)) {
            foreach ($afterProofs as $proof) {
                if (!empty($proof['proof_note'])) {
                    $latestProofNote = $proof['proof_note'];
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Before / After Review | DrainGuard</title>

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/inspector/sidebar.css">
    <link rel="stylesheet" href="../../css/inspector/topbar.css">
    <link rel="stylesheet" href="../../css/inspector/before-after-review.css">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>

<body class="inspector">

    <div class="inspector-layout">

        <?php include __DIR__ . '/../../includes/inspector/sidebar.php'; ?>

        <main class="inspector-main">

            <?php include __DIR__ . '/../../includes/inspector/topbar.php'; ?>

            <section class="before-after-page">

                <div class="page-heading">
                    <h1>Before / After Review</h1>
                    <p>Compare citizen complaint media with maintenance team submitted completion proof</p>
                </div>

                <div class="status-alert">
                    <div class="status-alert-icon">
                        <i class="bi bi-images"></i>
                    </div>

                    <div>
                        <h3><?php echo $totalReviewCases; ?> Cases in Before / After Review</h3>
                        <p>All cases here are marked as Inspector Verification Pending</p>
                    </div>
                </div>

                <form method="GET" class="filter-card" id="beforeAfterFilterForm">

                    <input
                        type="text"
                        name="search"
                        class="filter-control"
                        placeholder="Search by complaint code, issue, drain, area..."
                        value="<?php echo barText($search); ?>">

                    <select name="team_id" class="filter-control">
                        <option value="">All Teams</option>

                        <?php foreach ($teamRows as $team): ?>
                            <option value="<?php echo (int) $team['maintenance_team_id']; ?>"
                                <?php echo ($teamId !== '' && (int) $teamId === (int) $team['maintenance_team_id']) ? 'selected' : ''; ?>>
                                <?php echo barText($team['team_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="area_id" class="filter-control">
                        <option value="">All Areas</option>

                        <?php foreach ($areaRows as $area): ?>
                            <option value="<?php echo (int) $area['area_id']; ?>"
                                <?php echo ($areaId !== '' && (int) $areaId === (int) $area['area_id']) ? 'selected' : ''; ?>>
                                <?php echo barText($area['area_name']); ?>
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

                <div class="review-layout">

                    <aside class="review-list-panel">

                        <div class="list-panel-header">
                            <h3>Review Cases</h3>
                            <span><?php echo count($reviewRows); ?> showing of <?php echo $totalReviewCases; ?></span>
                        </div>

                        <div class="review-case-list">

                            <?php if (!empty($reviewRows)): ?>

                                <?php foreach ($reviewRows as $row): ?>

                                    <?php
                                    $caseUrl = barBuildCaseUrl((int) $row['complaint_id'], $search, $teamId, $areaId, $sort, $page);
                                    $isActive = ((int) $row['complaint_id'] === $selectedComplaintId);
                                    ?>

                                    <a href="<?php echo barText($caseUrl); ?>" class="review-case-link <?php echo $isActive ? 'active' : ''; ?>">

                                        <div class="review-case-code">
                                            <?php echo barText($row['complaint_code']); ?>
                                        </div>

                                        <h4><?php echo barText($row['issue_name'] ?: 'Drainage Issue'); ?></h4>

                                        <p>
                                            <?php echo barText($row['area_name'] ?: 'N/A'); ?>,
                                            Ward <?php echo barText($row['ward_no'] ?: 'N/A'); ?>
                                        </p>

                                        <div class="review-case-bottom">
                                            <span class="priority-badge <?php echo barPriorityClass($row['assignment_priority']); ?>">
                                                <?php echo barText($row['assignment_priority'] ?: 'Medium'); ?>
                                            </span>

                                            <small>
                                                <?php echo barText(barDate($row['after_uploaded_at'] ?: $row['updated_at'])); ?>
                                            </small>
                                        </div>

                                    </a>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <div class="empty-mini">
                                    <i class="bi bi-check-circle"></i>
                                    <p>No case found</p>
                                </div>

                            <?php endif; ?>

                        </div>

                        <?php if ($totalPages > 1): ?>
                            <div class="pagination-wrap">

                                <?php if ($page > 1): ?>
                                    <a href="<?php echo barText(barBuildPageUrl($page - 1, $search, $teamId, $areaId, $sort)); ?>" class="page-btn">
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
                                        <a href="<?php echo barText(barBuildPageUrl($i, $search, $teamId, $areaId, $sort)); ?>"
                                           class="page-number <?php echo ($i === $page) ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                </div>

                                <?php if ($page < $totalPages): ?>
                                    <a href="<?php echo barText(barBuildPageUrl($page + 1, $search, $teamId, $areaId, $sort)); ?>" class="page-btn">
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

                    </aside>

                    <div class="review-details-panel">

                        <?php if ($selectedComplaint): ?>

                            <div class="details-header-card">

                                <div>
                                    <span class="details-code">
                                        <?php echo barText($selectedComplaint['complaint_code']); ?>
                                    </span>

                                    <h2><?php echo barText($selectedComplaint['issue_name'] ?: 'Drainage Issue'); ?></h2>

                                    <p>
                                        <?php echo barText($selectedComplaint['area_name'] ?: 'N/A'); ?>,
                                        Ward <?php echo barText($selectedComplaint['ward_no'] ?: 'N/A'); ?>
                                    </p>
                                </div>

                                <div class="details-status-group">
                                    <span class="status-badge">
                                        <?php echo barText(barStatusLabel($selectedComplaint['complaint_status'])); ?>
                                    </span>

                                    <span class="priority-badge <?php echo barPriorityClass($selectedComplaint['assignment_priority']); ?>">
                                        <?php echo barText($selectedComplaint['assignment_priority'] ?: 'Medium'); ?>
                                    </span>
                                </div>

                            </div>

                            <div class="info-section-card">

                                <div class="section-title">
                                    <i class="bi bi-person"></i>
                                    <h3>Citizen Complaint Details</h3>
                                </div>

                                <div class="info-grid">

                                    <div>
                                        <span>Citizen Name</span>
                                        <strong><?php echo barText($selectedComplaint['citizen_name'] ?: 'N/A'); ?></strong>
                                    </div>

                                    <div>
                                        <span>Phone</span>
                                        <strong><?php echo barText($selectedComplaint['citizen_phone'] ?: 'N/A'); ?></strong>
                                    </div>

                                    <div>
                                        <span>Email</span>
                                        <strong><?php echo barText($selectedComplaint['citizen_email'] ?: 'N/A'); ?></strong>
                                    </div>

                                    <div>
                                        <span>Submitted</span>
                                        <strong><?php echo barText(barDate($selectedComplaint['submitted_at'])); ?></strong>
                                    </div>

                                    <div>
                                        <span>Issue Type</span>
                                        <strong><?php echo barText($selectedComplaint['issue_name'] ?: 'Drainage Issue'); ?></strong>
                                    </div>

                                    <div>
                                        <span>Drain</span>
                                        <strong><?php echo barText($selectedComplaint['drain_name'] ?: 'N/A'); ?></strong>
                                    </div>

                                </div>

                                <div class="long-info">
                                    <span>Problem Description</span>
                                    <p><?php echo barText($selectedComplaint['problem_description'] ?: 'No problem description available.'); ?></p>
                                </div>

                                <div class="long-info">
                                    <span>Address Description</span>
                                    <p><?php echo barText($selectedComplaint['address_description'] ?: 'No address description available.'); ?></p>
                                </div>

                            </div>

                            <div class="media-comparison-grid">

                                <div class="media-card before-card">

                                    <div class="section-title">
                                        <i class="bi bi-image"></i>
                                        <h3>Before Complaint Media</h3>
                                    </div>

                                    <div class="media-gallery">

                                        <?php if (!empty($beforeMedia)): ?>

                                            <?php foreach ($beforeMedia as $media): ?>

                                                <?php $mediaPath = barMediaPath($media['media_path']); ?>

                                                <div class="media-box">
                                                    <?php if ($media['media_type'] === 'video'): ?>
                                                        <video controls>
                                                            <source src="<?php echo barText($mediaPath); ?>">
                                                        </video>
                                                    <?php else: ?>
                                                        <img src="<?php echo barText($mediaPath); ?>" alt="Before complaint media">
                                                    <?php endif; ?>
                                                </div>

                                            <?php endforeach; ?>

                                        <?php else: ?>

                                            <div class="empty-media">
                                                <i class="bi bi-image"></i>
                                                <strong>No before media found</strong>
                                            </div>

                                        <?php endif; ?>

                                    </div>

                                </div>

                                <div class="media-card after-card">

                                    <div class="section-title">
                                        <i class="bi bi-check2-circle"></i>
                                        <h3>After Maintenance Proof</h3>
                                    </div>

                                    <div class="media-gallery">

                                        <?php if (!empty($afterProofs)): ?>

                                            <?php foreach ($afterProofs as $proof): ?>

                                                <?php $proofPath = barMediaPath($proof['media_path']); ?>

                                                <div class="media-box">
                                                    <?php if ($proof['media_type'] === 'video'): ?>
                                                        <video controls>
                                                            <source src="<?php echo barText($proofPath); ?>">
                                                        </video>
                                                    <?php else: ?>
                                                        <img src="<?php echo barText($proofPath); ?>" alt="After maintenance proof">
                                                    <?php endif; ?>
                                                </div>

                                            <?php endforeach; ?>

                                        <?php else: ?>

                                            <div class="empty-media">
                                                <i class="bi bi-image"></i>
                                                <strong>No after proof found</strong>
                                            </div>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>

                            <div class="info-section-card">

                                <div class="section-title">
                                    <i class="bi bi-tools"></i>
                                    <h3>Maintenance Team Submitted Details</h3>
                                </div>

                                <div class="info-grid">

                                    <div>
                                        <span>Team</span>
                                        <strong><?php echo barText($selectedComplaint['team_name'] ?: 'No Team Assigned'); ?></strong>
                                    </div>

                                    <div>
                                        <span>Priority</span>
                                        <strong><?php echo barText($selectedComplaint['assignment_priority'] ?: 'Medium'); ?></strong>
                                    </div>

                                    <div>
                                        <span>Assigned Date</span>
                                        <strong><?php echo barText(barDate($selectedComplaint['assigned_at'])); ?></strong>
                                    </div>

                                    <div>
                                        <span>Deadline</span>
                                        <strong><?php echo barText(barDate($selectedComplaint['deadline_at'])); ?></strong>
                                    </div>

                                </div>

                                <div class="long-info proof-note">
                                    <span>Maintenance Proof Note</span>
                                    <p><?php echo barText($latestProofNote); ?></p>
                                </div>

                                <div class="long-info">
                                    <span>Assignment Note</span>
                                    <p><?php echo barText($selectedComplaint['task_note'] ?: 'No assignment note available.'); ?></p>
                                </div>

                            </div>

                        <?php else: ?>

                            <div class="empty-state">
                                <i class="bi bi-hand-index-thumb"></i>
                                <h3>Select a Review Case</h3>
                                <p>Click any review case card above to see citizen complaint details, before media, after proof, and maintenance submitted details.</p>
                            </div>

                        <?php endif; ?>

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
    <script src="../../js/inspector/before-after-review.js"></script>

</body>

</html>