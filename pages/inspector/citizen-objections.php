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
$activePage = 'citizen-objections';
$pageTitle = 'Citizen Objections';

/* =========================
   Helper Functions
========================= */

function coBindParams($stmt, $types, &$params)
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

function coFetchOne($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    coBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function coFetchAll($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    coBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function coText($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function coDate($datetime)
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

function coMediaPath($path)
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

function coPriorityClass($priority)
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

function coStatusLabel($status)
{
    $map = [
        'submitted' => 'Submitted',
        'received' => 'Received',
        'pending_verification' => 'Ward Officer Pending Verification',
        'verified' => 'Verified / Waiting for Ward Reassignment',
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

function coRequestStatusLabel($status)
{
    $map = [
        'pending' => 'Pending Ward Review',
        'sent_to_inspector' => 'Forwarded to Inspector',
        'sent_to_ward_for_reassign' => 'Sent to Ward for Team Assignment',
        'reassigned_same_team' => 'Reassigned Same Team',
        'reassigned_different_team' => 'Reassigned Different Team',
        'rejected' => 'Objection Rejected',
        'resolved' => 'Resolved'
    ];

    return $map[$status] ?? ucwords(str_replace('_', ' ', (string) $status));
}

function coBuildQuery($search, $areaId, $sort, $page = 1, $status = '', $error = '')
{
    $query = [];

    if ($search !== '') {
        $query['search'] = $search;
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

    if ($status !== '') {
        $query['status'] = $status;
    }

    if ($error !== '') {
        $query['error'] = $error;
    }

    $queryString = http_build_query($query);

    return $queryString !== '' ? '?' . $queryString : '';
}

function coBuildPageUrl($page, $search, $areaId, $sort)
{
    return 'citizen-objections.php' . coBuildQuery($search, $areaId, $sort, (int) $page);
}

/* =========================
   Inspector Info
========================= */

$inspector = coFetchOne(
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

$search = trim($_GET['search'] ?? $_POST['search'] ?? '');
$areaId = trim($_GET['area_id'] ?? $_POST['area_id'] ?? '');
$sort = trim($_GET['sort'] ?? $_POST['sort'] ?? 'newest');

$page = isset($_GET['page']) ? (int) $_GET['page'] : (int) ($_POST['page'] ?? 1);

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
   Alert Messages
========================= */

$successMessage = '';
$errorMessage = '';

if (isset($_GET['status'])) {
    if ($_GET['status'] === 'sent_to_ward') {
        $successMessage = "Objection accepted. Case sent to Ward Officer for local team assignment.";
    } elseif ($_GET['status'] === 'rejected') {
        $successMessage = "Objection rejected. Complaint remains Solved.";
    }
}

if (isset($_GET['error'])) {
    $errorMessage = "Could not process the objection decision. Please try again.";
}

/* =========================
   Inspector Decision Action
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reopenId = isset($_POST['reopen_id']) ? (int) $_POST['reopen_id'] : 0;
    $complaintId = isset($_POST['complaint_id']) ? (int) $_POST['complaint_id'] : 0;
    $decision = trim($_POST['objection_action'] ?? '');
    $inspectorNote = trim($_POST['inspector_note'] ?? '');

    $allowedDecisions = ['reopen', 'reject'];

    if ($reopenId <= 0 || $complaintId <= 0 || !in_array($decision, $allowedDecisions, true)) {
        header("Location: citizen-objections.php" . coBuildQuery($search, $areaId, $sort, $page, '', 'invalid_action'));
        exit();
    }

    mysqli_begin_transaction($conn);

    try {
        $allowedRequest = coFetchOne(
            $conn,
            "SELECT 
                rr.reopen_id,
                rr.complaint_id,
                rr.request_type,
                rr.request_status,
                c.complaint_status
            FROM reopen_requests rr
            INNER JOIN complaints c ON c.complaint_id = rr.complaint_id
            INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
            WHERE rr.reopen_id = ?
            AND rr.complaint_id = ?
            AND ca.ward_id = ?
            AND rr.request_type = 'citizen_objection'
            AND rr.request_status = 'sent_to_inspector'
            AND c.complaint_status = 'disputed'
            LIMIT 1",
            "iii",
            [$reopenId, $complaintId, $assignedWardId]
        );

        if (!$allowedRequest) {
            throw new Exception("This objection is not available for inspector decision.");
        }

        if ($decision === 'reopen') {
            /*
                Inspector approves citizen objection.
                Inspector does NOT assign same/different team.
                Ward Officer will decide same/different team in Local Team Assignment.
            */
            $newRequestStatus = 'sent_to_ward_for_reassign';
            $newComplaintStatus = 'verified';
            $redirectStatus = 'sent_to_ward';
            $reviewAction = 'reopen_complaint';
        } else {
            /*
                Inspector rejects citizen objection.
                Complaint remains solved/closed.
            */
            $newRequestStatus = 'rejected';
            $newComplaintStatus = 'closed';
            $redirectStatus = 'rejected';
            $reviewAction = 'reject_objection';
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE reopen_requests
            SET request_status = ?,
                inspector_note = ?,
                handled_by = ?,
                handled_at = NOW()
            WHERE reopen_id = ?"
        );

        if (!$stmt) {
            throw new Exception(mysqli_error($conn));
        }

        mysqli_stmt_bind_param($stmt, "ssii", $newRequestStatus, $inspectorNote, $userId, $reopenId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE complaints
            SET complaint_status = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE complaint_id = ?"
        );

        if (!$stmt) {
            throw new Exception(mysqli_error($conn));
        }

        mysqli_stmt_bind_param($stmt, "si", $newComplaintStatus, $complaintId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $reviewStmt = mysqli_prepare(
            $conn,
            "INSERT INTO objection_reviews
            (reopen_id, complaint_id, reviewed_by, reviewer_role, review_action, review_note)
            VALUES (?, ?, ?, 'inspector', ?, ?)"
        );

        if (!$reviewStmt) {
            throw new Exception(mysqli_error($conn));
        }

        mysqli_stmt_bind_param(
            $reviewStmt,
            "iiiss",
            $reopenId,
            $complaintId,
            $userId,
            $reviewAction,
            $inspectorNote
        );

        mysqli_stmt_execute($reviewStmt);
        mysqli_stmt_close($reviewStmt);

        mysqli_commit($conn);

        header("Location: citizen-objections.php" . coBuildQuery($search, $areaId, $sort, $page, $redirectStatus, ''));
        exit();

    } catch (Exception $e) {
        mysqli_rollback($conn);
        header("Location: citizen-objections.php" . coBuildQuery($search, $areaId, $sort, $page, '', 'action_failed'));
        exit();
    }
}

/* =========================
   Area Dropdown
========================= */

$areaRows = coFetchAll(
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

$countWhereSql = "
    WHERE ca.ward_id = ?
    AND rr.request_type = 'citizen_objection'
    AND rr.request_status = 'sent_to_inspector'
    AND c.complaint_status = 'disputed'
";

$countTypes = "i";
$countParams = [$assignedWardId];

if ($search !== '') {
    $countWhereSql .= "
        AND (
            c.complaint_code LIKE ?
            OR c.problem_description LIKE ?
            OR issue.issue_name LIKE ?
            OR a.area_name LIKE ?
            OR ct.full_name LIKE ?
        )
    ";

    $searchTerm = "%" . $search . "%";

    $countTypes .= "sssss";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}

if ($areaId !== '' && ctype_digit($areaId)) {
    $countWhereSql .= " AND a.area_id = ?";
    $countTypes .= "i";
    $countParams[] = (int) $areaId;
}

$countRow = coFetchOne(
    $conn,
    "SELECT COUNT(DISTINCT rr.reopen_id) AS total
    FROM reopen_requests rr
    INNER JOIN complaints c ON c.complaint_id = rr.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN citizens ct ON ct.user_id = rr.requested_by
    LEFT JOIN issues issue ON issue.issue_id = c.issue_id
    LEFT JOIN locations l ON l.loc_id = c.loc_id
    LEFT JOIN areas a ON a.area_id = l.area_id
    $countWhereSql",
    $countTypes,
    $countParams
);

$totalObjections = $countRow ? (int) $countRow['total'] : 0;
$totalPages = (int) ceil($totalObjections / $perPage);

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

$whereSql = "
    WHERE ca.ward_id = ?
    AND rr.request_type = 'citizen_objection'
    AND rr.request_status = 'sent_to_inspector'
    AND c.complaint_status = 'disputed'
";

$types = "i";
$params = [$assignedWardId];

if ($search !== '') {
    $whereSql .= "
        AND (
            c.complaint_code LIKE ?
            OR c.problem_description LIKE ?
            OR issue.issue_name LIKE ?
            OR a.area_name LIKE ?
            OR ct.full_name LIKE ?
        )
    ";

    $searchTerm = "%" . $search . "%";

    $types .= "sssss";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($areaId !== '' && ctype_digit($areaId)) {
    $whereSql .= " AND a.area_id = ?";
    $types .= "i";
    $params[] = (int) $areaId;
}

$orderBy = "ORDER BY rr.forwarded_at DESC, rr.created_at DESC";

if ($sort === 'oldest') {
    $orderBy = "ORDER BY rr.forwarded_at ASC, rr.created_at ASC";
} elseif ($sort === 'priority_high') {
    $orderBy = "ORDER BY FIELD(ca.assignment_priority, 'High', 'Medium', 'Low'), rr.forwarded_at DESC";
} elseif ($sort === 'priority_low') {
    $orderBy = "ORDER BY FIELD(ca.assignment_priority, 'Low', 'Medium', 'High'), rr.forwarded_at DESC";
}

$objectionSql = "
    SELECT
        rr.reopen_id,
        rr.complaint_id,
        rr.requested_by,
        rr.request_type,
        rr.reason,
        rr.ward_note,
        rr.inspector_note,
        rr.request_status,
        rr.handled_by,
        rr.handled_at,
        rr.forwarded_at,
        rr.created_at AS objection_created_at,

        c.complaint_code,
        c.problem_description,
        c.address_description,
        c.complaint_status,
        c.updated_at AS complaint_updated_at,

        ca.assignment_id,
        ca.assignment_priority,
        ca.task_note,

        mt.team_name,

        ct.full_name AS citizen_name,
        ct.phone_number AS citizen_phone,
        ct.user_mail AS citizen_email,

        issue.issue_name,

        w.ward_no,
        w.ward_name,

        a.area_id,
        a.area_name,

        latest_proof.uploaded_at AS approved_at,
        latest_proof.proof_note AS maintenance_proof_note

    FROM reopen_requests rr

    INNER JOIN complaints c ON c.complaint_id = rr.complaint_id
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id

    LEFT JOIN maintenance_teams mt ON mt.maintenance_team_id = ca.maintenance_team_id
    LEFT JOIN citizens ct ON ct.user_id = rr.requested_by
    LEFT JOIN issues issue ON issue.issue_id = c.issue_id
    LEFT JOIN locations l ON l.loc_id = c.loc_id
    LEFT JOIN areas a ON a.area_id = l.area_id
    LEFT JOIN wards w ON w.ward_id = ca.ward_id

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

    $whereSql

    GROUP BY rr.reopen_id

    $orderBy

    LIMIT ? OFFSET ?
";

$types .= "ii";
$params[] = $perPage;
$params[] = $offset;

$objections = coFetchAll($conn, $objectionSql, $types, $params);

/* =========================
   Media per Complaint
========================= */

$beforeMedia = [];
$afterMedia = [];

foreach ($objections as $item) {
    $cid = (int) $item['complaint_id'];

    $beforeMedia[$cid] = coFetchAll(
        $conn,
        "SELECT media_id, media_type, media_path, original_name
        FROM complaint_media
        WHERE complaint_id = ?
        ORDER BY uploaded_at ASC
        LIMIT 3",
        "i",
        [$cid]
    );

    $afterMedia[$cid] = coFetchAll(
        $conn,
        "SELECT proof_id, media_type, media_path, original_name, proof_note
        FROM maintenance_proofs
        WHERE complaint_id = ?
        AND proof_stage = 'after'
        ORDER BY uploaded_at DESC
        LIMIT 3",
        "i",
        [$cid]
    );
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Citizen Objections | DrainGuard</title>

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/inspector/sidebar.css">
    <link rel="stylesheet" href="../../css/inspector/topbar.css">
    <link rel="stylesheet" href="../../css/inspector/citizen-objections.css">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>

<body class="inspector">

    <div class="inspector-layout">

        <?php include __DIR__ . '/../../includes/inspector/sidebar.php'; ?>

        <main class="inspector-main">

            <?php include __DIR__ . '/../../includes/inspector/topbar.php'; ?>

            <section class="citizen-objections-page">

                <div class="page-heading">
                    <h1>Citizen Objections</h1>
                    <p>Review citizen objections forwarded by Ward Officer after preliminary verification</p>
                </div>

                <?php if (!empty($successMessage)): ?>
                    <div class="page-success-alert">
                        <?php echo coText($successMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errorMessage)): ?>
                    <div class="page-error-alert">
                        <?php echo coText($errorMessage); ?>
                    </div>
                <?php endif; ?>

                <div class="status-alert">
                    <div class="status-alert-icon">
                        <i class="bi bi-chat-left-text"></i>
                    </div>

                    <div>
                        <h3><?php echo $totalObjections; ?> Forwarded Citizen Objections</h3>
                        <p>Inspector only validates the objection; Ward Officer will assign the team</p>
                    </div>
                </div>

                <form method="GET" class="filter-card" id="citizenObjectionsFilterForm">

                    <input
                        type="text"
                        name="search"
                        class="filter-control"
                        placeholder="Search by complaint code, citizen, issue, area..."
                        value="<?php echo coText($search); ?>">

                    <select name="area_id" class="filter-control">
                        <option value="">All Areas</option>

                        <?php foreach ($areaRows as $area): ?>
                            <option value="<?php echo (int) $area['area_id']; ?>"
                                <?php echo ($areaId !== '' && (int) $areaId === (int) $area['area_id']) ? 'selected' : ''; ?>>
                                <?php echo coText($area['area_name']); ?>
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

                <div class="objection-list">

                    <?php if (!empty($objections)): ?>

                        <?php foreach ($objections as $item): ?>

                            <?php
                            $cid = (int) $item['complaint_id'];
                            $priority = $item['assignment_priority'] ?: 'Medium';
                            $citizenBefore = $beforeMedia[$cid] ?? [];
                            $teamAfter = $afterMedia[$cid] ?? [];
                            ?>

                            <article class="objection-card">

                                <div class="objection-top">
                                    <div>
                                        <div class="case-meta-row">
                                            <span class="case-code">
                                                <?php echo coText($item['complaint_code']); ?>
                                            </span>

                                            <span class="priority-badge <?php echo coPriorityClass($priority); ?>">
                                                <?php echo coText($priority); ?>
                                            </span>

                                            <span class="status-badge">
                                                <?php echo coText(coRequestStatusLabel($item['request_status'])); ?>
                                            </span>
                                        </div>

                                        <h3><?php echo coText($item['issue_name'] ?: 'Drainage Issue'); ?></h3>

                                        <p class="location-line">
                                            <?php echo coText($item['area_name'] ?: 'N/A'); ?>,
                                            Ward <?php echo coText($item['ward_no'] ?: 'N/A'); ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="info-grid">

                                    <div>
                                        <span>Submitted By</span>
                                        <strong><?php echo coText($item['citizen_name'] ?: 'Citizen'); ?></strong>
                                    </div>

                                    <div>
                                        <span>Objection Date</span>
                                        <strong><?php echo coText(coDate($item['objection_created_at'])); ?></strong>
                                    </div>

                                    <div>
                                        <span>Forwarded Date</span>
                                        <strong><?php echo coText(coDate($item['forwarded_at'])); ?></strong>
                                    </div>

                                    <div>
                                        <span>Previous Approved Date</span>
                                        <strong><?php echo coText(coDate($item['approved_at'] ?: $item['complaint_updated_at'])); ?></strong>
                                    </div>

                                    <div>
                                        <span>Maintenance Team</span>
                                        <strong><?php echo coText($item['team_name'] ?: 'No Team Assigned'); ?></strong>
                                    </div>

                                    <div>
                                        <span>Complaint Status</span>
                                        <strong><?php echo coText(coStatusLabel($item['complaint_status'])); ?></strong>
                                    </div>

                                </div>

                                <div class="reason-box citizen-reason">
                                    <span>Citizen Objection Reason</span>
                                    <p><?php echo coText($item['reason'] ?: 'No objection reason provided.'); ?></p>
                                </div>

                                <div class="reason-box ward-note">
                                    <span>Ward Officer Note</span>
                                    <p><?php echo coText($item['ward_note'] ?: 'No ward note provided.'); ?></p>
                                </div>

                                <div class="media-grid-wrap">

                                    <div class="media-section">
                                        <h4>
                                            <i class="bi bi-image"></i>
                                            Original Complaint Media
                                        </h4>

                                        <div class="media-grid">
                                            <?php if (!empty($citizenBefore)): ?>
                                                <?php foreach ($citizenBefore as $media): ?>
                                                    <?php $path = coMediaPath($media['media_path']); ?>

                                                    <div class="media-box">
                                                        <?php if ($media['media_type'] === 'video'): ?>
                                                            <video controls>
                                                                <source src="<?php echo coText($path); ?>">
                                                            </video>
                                                        <?php else: ?>
                                                            <img src="<?php echo coText($path); ?>" alt="Complaint media">
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="empty-media">
                                                    <i class="bi bi-image"></i>
                                                    <span>No before media</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="media-section">
                                        <h4>
                                            <i class="bi bi-check2-circle"></i>
                                            Maintenance Completion Proof
                                        </h4>

                                        <div class="media-grid">
                                            <?php if (!empty($teamAfter)): ?>
                                                <?php foreach ($teamAfter as $proof): ?>
                                                    <?php $path = coMediaPath($proof['media_path']); ?>

                                                    <div class="media-box">
                                                        <?php if ($proof['media_type'] === 'video'): ?>
                                                            <video controls>
                                                                <source src="<?php echo coText($path); ?>">
                                                            </video>
                                                        <?php else: ?>
                                                            <img src="<?php echo coText($path); ?>" alt="Maintenance proof">
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="empty-media">
                                                    <i class="bi bi-image"></i>
                                                    <span>No after proof</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                </div>

                                <form method="POST" action="citizen-objections.php" class="objection-actions">
                                    <input type="hidden" name="reopen_id" value="<?php echo (int) $item['reopen_id']; ?>">
                                    <input type="hidden" name="complaint_id" value="<?php echo (int) $item['complaint_id']; ?>">
                                    <input type="hidden" name="search" value="<?php echo coText($search); ?>">
                                    <input type="hidden" name="area_id" value="<?php echo coText($areaId); ?>">
                                    <input type="hidden" name="sort" value="<?php echo coText($sort); ?>">
                                    <input type="hidden" name="page" value="<?php echo (int) $page; ?>">

                                    <label class="note-label" for="inspector_note_<?php echo (int) $item['reopen_id']; ?>">
                                        Inspector Investigation Notes
                                    </label>

                                    <textarea
                                        id="inspector_note_<?php echo (int) $item['reopen_id']; ?>"
                                        name="inspector_note"
                                        class="inspector-note"
                                        rows="3"
                                        placeholder="Write field verification note or decision reason..."><?php echo coText($item['inspector_note'] ?? ''); ?></textarea>

                                    <div class="action-row">
                                        <button type="submit" name="objection_action" value="reopen" class="action-btn reopen-btn">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                            Reopen Complaint
                                        </button>

                                        <button type="submit" name="objection_action" value="reject" class="action-btn reject-btn">
                                            <i class="bi bi-x-circle"></i>
                                            Reject Objection
                                        </button>
                                    </div>
                                </form>

                            </article>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <div class="empty-state">
                            <i class="bi bi-check2-circle"></i>
                            <h3>No Citizen Objection Found</h3>
                            <p>No Ward-forwarded citizen objection is waiting for inspector review.</p>
                        </div>

                    <?php endif; ?>

                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination-wrap">

                        <?php if ($page > 1): ?>
                            <a href="<?php echo coText(coBuildPageUrl($page - 1, $search, $areaId, $sort)); ?>" class="page-btn">
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
                                <a href="<?php echo coText(coBuildPageUrl($i, $search, $areaId, $sort)); ?>"
                                   class="page-number <?php echo ($i === $page) ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?php echo coText(coBuildPageUrl($page + 1, $search, $areaId, $sort)); ?>" class="page-btn">
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
    <script src="../../js/inspector/citizen-objections.js"></script>

</body>

</html>