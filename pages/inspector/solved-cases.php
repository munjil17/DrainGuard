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
$activePage = 'solved-cases';
$pageTitle = 'Solved by Team Cases';

/* =========================
   Helper Functions
========================= */

function solvedBindParams($stmt, $types, &$params)
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

function solvedFetchOne($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    solvedBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function solvedFetchAll($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    solvedBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function solvedText($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function solvedMediaPath($path)
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

function solvedDate($datetime)
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

function solvedWaitingTime($datetime)
{
    if (empty($datetime)) {
        return "Waiting";
    }

    $timestamp = strtotime($datetime);

    if (!$timestamp) {
        return "Waiting";
    }

    $diff = time() - $timestamp;

    if ($diff < 60) {
        return "Waiting just now";
    }

    if ($diff < 3600) {
        return "Waiting " . floor($diff / 60) . " minutes";
    }

    if ($diff < 86400) {
        return "Waiting " . floor($diff / 3600) . " hours";
    }

    return "Waiting " . floor($diff / 86400) . " days";
}

function solvedPriorityClass($priority)
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

function solvedStatusLabel($status)
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

function solvedBuildQuery($search, $teamId, $areaId, $sort, $error = '')
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

    if ($error !== '') {
        $query['error'] = $error;
    }

    $queryString = http_build_query($query);

    return $queryString !== '' ? '?' . $queryString : '';
}

/* =========================
   Inspector Info
========================= */

$inspector = solvedFetchOne(
    $conn,
    "SELECT 
        i.inspector_id,
        i.user_id,
        i.city_cor_id,
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

$inspectorName = !empty($inspector['full_name'])
    ? $inspector['full_name']
    : ($inspector['user_name'] ?? 'Inspector');

$_SESSION['user_name'] = $inspectorName;

/* =========================
   Filters
========================= */

$search = trim($_GET['search'] ?? $_POST['search'] ?? '');
$teamId = trim($_GET['team_id'] ?? $_POST['team_id'] ?? '');
$areaId = trim($_GET['area_id'] ?? $_POST['area_id'] ?? '');
$sort = trim($_GET['sort'] ?? $_POST['sort'] ?? 'newest');

$allowedSorts = ['newest', 'oldest', 'priority_high', 'priority_low'];

if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'newest';
}

$errorMessage = '';

if (isset($_GET['error'])) {
    $errorMessage = "Could not move this complaint to Before / After Review. Please try again.";
}

/* =========================
   Review Button Action
   Same page POST
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_action'])) {
    $complaintId = isset($_POST['complaint_id']) ? (int) $_POST['complaint_id'] : 0;

    if ($complaintId <= 0) {
        header("Location: solved-cases.php" . solvedBuildQuery($search, $teamId, $areaId, $sort, 'invalid_complaint'));
        exit();
    }

    mysqli_begin_transaction($conn);

    try {
        $allowedComplaint = solvedFetchOne(
            $conn,
            "SELECT 
                c.complaint_id,
                c.complaint_status
            FROM complaints c
            INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
            INNER JOIN maintenance_proofs mp ON mp.complaint_id = c.complaint_id
            WHERE c.complaint_id = ?
            AND ca.ward_id = ?
            AND mp.proof_stage = 'after'
            AND mp.proof_status = 'submitted'
            AND c.complaint_status NOT IN ('inspector_verification', 'closed', 'reopened', 'disputed', 'rejected', 'duplicate')
            LIMIT 1",
            "ii",
            [$complaintId, $assignedWardId]
        );

        if (!$allowedComplaint) {
            throw new Exception("Complaint is not available for review.");
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE complaints
            SET complaint_status = 'inspector_verification',
                updated_at = CURRENT_TIMESTAMP
            WHERE complaint_id = ?"
        );

        if (!$stmt) {
            throw new Exception(mysqli_error($conn));
        }

        mysqli_stmt_bind_param($stmt, "i", $complaintId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        // Fetch recipients for notifications
        $citizenUserId = 0;
        $centralOfficerUserId = 0;
        $wardOfficerUserId = 0;
        $maintenanceTeamMembers = [];
        $complaintCode = '';
        $locId = 0;
        $maintenanceTeamId = 0;

        $fetchSql = "
            SELECT 
                c.complaint_code, 
                c.user_id AS citizen_id, 
                c.loc_id,
                ca.maintenance_team_id,
                (SELECT assigned_by FROM complaint_assignments WHERE complaint_id = c.complaint_id AND assignment_status = 'ward_assigned' LIMIT 1) AS central_officer_id
            FROM complaints c
            INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
            WHERE c.complaint_id = ?
            LIMIT 1
        ";
        
        $stmtC = mysqli_prepare($conn, $fetchSql);
        if ($stmtC) {
            mysqli_stmt_bind_param($stmtC, "i", $complaintId);
            mysqli_stmt_execute($stmtC);
            $resC = mysqli_stmt_get_result($stmtC);
            if ($rowC = mysqli_fetch_assoc($resC)) {
                $complaintCode = $rowC['complaint_code'];
                $citizenUserId = (int)$rowC['citizen_id'];
                $centralOfficerUserId = (int)$rowC['central_officer_id'];
                $locId = (int)$rowC['loc_id'];
                $maintenanceTeamId = (int)$rowC['maintenance_team_id'];
            }
            mysqli_stmt_close($stmtC);
        }

        if ($locId > 0) {
            $woSql = "SELECT wo.user_id FROM locations l JOIN ward_officers wo ON wo.assigned_ward_id = l.ward_id AND wo.city_cor_id = l.city_cor_id WHERE l.loc_id = ? LIMIT 1";
            $stmtWo = mysqli_prepare($conn, $woSql);
            if ($stmtWo) {
                mysqli_stmt_bind_param($stmtWo, "i", $locId);
                mysqli_stmt_execute($stmtWo);
                $resWo = mysqli_stmt_get_result($stmtWo);
                if ($rowWo = mysqli_fetch_assoc($resWo)) {
                    $wardOfficerUserId = (int)$rowWo['user_id'];
                }
                mysqli_stmt_close($stmtWo);
            }
        }

        if ($maintenanceTeamId > 0) {
            $mtSql = "SELECT user_id FROM maintenance_team_members WHERE maintenance_team_id = ?";
            $stmtMt = mysqli_prepare($conn, $mtSql);
            if ($stmtMt) {
                mysqli_stmt_bind_param($stmtMt, "i", $maintenanceTeamId);
                mysqli_stmt_execute($stmtMt);
                $resMt = mysqli_stmt_get_result($stmtMt);
                while ($rowMt = mysqli_fetch_assoc($resMt)) {
                    $maintenanceTeamMembers[] = (int)$rowMt['user_id'];
                }
                mysqli_stmt_close($stmtMt);
            }
        }

        $notifTime = date('Y-m-d H:i:s');

        // Citizen
        if ($citizenUserId > 0) {
            $msg = "Inspector has started reviewing the completion proof for your complaint.";
            $ins = mysqli_prepare($conn, "INSERT INTO citizen_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'system', 'Review Started', ?, 0, ?)");
            if ($ins) {
                mysqli_stmt_bind_param($ins, "iiiss", $citizenUserId, $userId, $complaintId, $msg, $notifTime);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        }

        // Central Officer
        if ($centralOfficerUserId > 0) {
            $msg = "Inspector has started reviewing the completion proof for complaint {$complaintCode}.";
            $ins = mysqli_prepare($conn, "INSERT INTO central_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'system', 'Review Started', ?, 0, ?)");
            if ($ins) {
                mysqli_stmt_bind_param($ins, "iiiss", $centralOfficerUserId, $userId, $complaintId, $msg, $notifTime);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        }

        // Ward Officer
        if ($wardOfficerUserId > 0) {
            $msg = "Inspector has started reviewing the completion proof for assigned complaint {$complaintCode}.";
            $ins = mysqli_prepare($conn, "INSERT INTO ward_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'system', 'Review Started', ?, 0, ?)");
            if ($ins) {
                mysqli_stmt_bind_param($ins, "iiiss", $wardOfficerUserId, $userId, $complaintId, $msg, $notifTime);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        }

        // Maintenance Team
        foreach ($maintenanceTeamMembers as $memberId) {
            $msg = "Inspector has started reviewing the completion proof you submitted for complaint {$complaintCode}.";
            $ins = mysqli_prepare($conn, "INSERT INTO maintenance_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'system', 'Review Started', ?, 0, ?)");
            if ($ins) {
                mysqli_stmt_bind_param($ins, "iiiss", $memberId, $userId, $complaintId, $msg, $notifTime);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        }

        mysqli_commit($conn);

        header("Location: before-after-review.php?complaint_id=" . $complaintId);
        exit();

    } catch (Exception $e) {
        mysqli_rollback($conn);
        header("Location: solved-cases.php" . solvedBuildQuery($search, $teamId, $areaId, $sort, 'review_failed'));
        exit();
    }
}

/* =========================
   Filter Dropdown Data
========================= */

$teamRows = solvedFetchAll(
    $conn,
    "SELECT DISTINCT
        mt.maintenance_team_id,
        mt.team_name
    FROM maintenance_teams mt
    INNER JOIN complaint_assignments ca 
        ON ca.maintenance_team_id = mt.maintenance_team_id
    INNER JOIN complaints c
        ON c.complaint_id = ca.complaint_id
    INNER JOIN maintenance_proofs mp
        ON mp.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND mp.proof_stage = 'after'
    AND mp.proof_status = 'submitted'
    AND c.complaint_status NOT IN ('inspector_verification', 'closed', 'reopened', 'rejected', 'duplicate')
    ORDER BY mt.team_name ASC",
    "i",
    [$assignedWardId]
);

$areaRows = solvedFetchAll(
    $conn,
    "SELECT 
        area_id,
        area_name
    FROM areas
    WHERE ward_id = ?
    ORDER BY area_name ASC",
    "i",
    [$assignedWardId]
);

/* =========================
   Total Count
========================= */

$countRow = solvedFetchOne(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    INNER JOIN maintenance_proofs mp ON mp.complaint_id = c.complaint_id
    WHERE ca.ward_id = ?
    AND mp.proof_stage = 'after'
    AND mp.proof_status = 'submitted'
    AND c.complaint_status NOT IN ('inspector_verification', 'closed', 'reopened', 'disputed', 'rejected', 'duplicate')",
    "i",
    [$assignedWardId]
);

$totalSolvedByTeam = $countRow ? (int) $countRow['total'] : 0;

/* =========================
   Main Query
========================= */

$whereSql = "
    WHERE ca.ward_id = ?
    AND after_proof.proof_id IS NOT NULL
    AND c.complaint_status NOT IN ('inspector_verification', 'closed', 'reopened', 'disputed', 'rejected', 'duplicate')
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

$casesSql = "
    SELECT
        c.complaint_id,
        c.complaint_code,
        c.problem_description,
        c.address_description,
        c.complaint_status,
        c.updated_at,

        ca.assignment_id,
        ca.assignment_priority,
        ca.task_note,

        mt.team_name,

        after_proof.proof_id,
        after_proof.media_path AS after_media_path,
        after_proof.media_type AS after_media_type,
        after_proof.original_name AS after_original_name,
        after_proof.proof_note,
        after_proof.proof_status,
        after_proof.uploaded_at AS proof_uploaded_at,

        COALESCE(proof_count.total_proofs, 0) AS total_proofs,

        issue.issue_name,

        w.ward_no,
        w.ward_name,

        a.area_id,
        a.area_name,

        d.drain_code,
        d.drain_name

    FROM complaints c

    INNER JOIN complaint_assignments ca 
        ON ca.complaint_id = c.complaint_id

    LEFT JOIN maintenance_teams mt 
        ON mt.maintenance_team_id = ca.maintenance_team_id

    LEFT JOIN issues issue 
        ON issue.issue_id = c.issue_id

    LEFT JOIN locations l 
        ON l.loc_id = c.loc_id

    LEFT JOIN areas a 
        ON a.area_id = l.area_id

    LEFT JOIN wards w 
        ON w.ward_id = ca.ward_id

    LEFT JOIN drains d 
        ON d.drain_id = c.drain_id

    LEFT JOIN (
        SELECT mp1.*
        FROM maintenance_proofs mp1
        INNER JOIN (
            SELECT complaint_id, MAX(proof_id) AS latest_after_proof_id
            FROM maintenance_proofs
            WHERE proof_stage = 'after'
            AND proof_status = 'submitted'
            GROUP BY complaint_id
        ) latest
            ON latest.latest_after_proof_id = mp1.proof_id
    ) after_proof
        ON after_proof.complaint_id = c.complaint_id

    LEFT JOIN (
        SELECT complaint_id, COUNT(proof_id) AS total_proofs
        FROM maintenance_proofs
        WHERE proof_stage = 'after'
        AND media_path IS NOT NULL
        AND media_path <> ''
        GROUP BY complaint_id
    ) proof_count
        ON proof_count.complaint_id = c.complaint_id

    $whereSql

    GROUP BY c.complaint_id

    $orderBy
";

$cases = solvedFetchAll($conn, $casesSql, $types, $params);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solved by Team Cases | DrainGuard</title>

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/inspector/sidebar.css">
    <link rel="stylesheet" href="../../css/inspector/topbar.css">
    <link rel="stylesheet" href="../../css/inspector/solved-cases.css">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>

<body class="inspector">

    <div class="inspector-layout">

        <?php include __DIR__ . '/../../includes/inspector/sidebar.php'; ?>

        <main class="inspector-main">

            <?php include __DIR__ . '/../../includes/inspector/topbar.php'; ?>

            <section class="solved-cases-page">

                <div class="page-heading">
                    <h1>Solved by Team Cases</h1>
                    <p>Maintenance-completed cases waiting to be moved for before/after review</p>
                </div>

                <?php if (!empty($errorMessage)): ?>
                    <div class="page-error-alert">
                        <?php echo solvedText($errorMessage); ?>
                    </div>
                <?php endif; ?>

                <div class="status-alert">
                    <div class="status-alert-icon">
                        <i class="bi bi-check2-circle"></i>
                    </div>

                    <div>
                        <h3><?php echo $totalSolvedByTeam; ?> Cases Marked "Solved by Team"</h3>
                        <p>Review these cases and move them to Before / After Review</p>
                    </div>
                </div>

                <form method="GET" class="filter-card" id="solvedCasesFilterForm">

                    <input
                        type="text"
                        name="search"
                        class="filter-control"
                        placeholder="Search by Task ID, issue, drain, area..."
                        value="<?php echo solvedText($search); ?>">

                    <select name="team_id" class="filter-control">
                        <option value="">All Teams</option>

                        <?php foreach ($teamRows as $team): ?>
                            <option value="<?php echo (int) $team['maintenance_team_id']; ?>"
                                <?php echo ($teamId !== '' && (int) $teamId === (int) $team['maintenance_team_id']) ? 'selected' : ''; ?>>
                                <?php echo solvedText($team['team_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="area_id" class="filter-control">
                        <option value="">All Areas</option>

                        <?php foreach ($areaRows as $area): ?>
                            <option value="<?php echo (int) $area['area_id']; ?>"
                                <?php echo ($areaId !== '' && (int) $areaId === (int) $area['area_id']) ? 'selected' : ''; ?>>
                                <?php echo solvedText($area['area_name']); ?>
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

                <div class="cases-list">

                    <?php if (!empty($cases)): ?>

                        <?php foreach ($cases as $case): ?>

                            <?php
                            $issueName = $case['issue_name'] ?: 'Drainage Issue';
                            $teamName = $case['team_name'] ?: 'No Team Assigned';
                            $areaName = $case['area_name'] ?: 'Area not available';
                            $wardNo = $case['ward_no'] ?: 'N/A';
                            $proofCount = (int) $case['total_proofs'];
                            $completedAt = $case['proof_uploaded_at'] ?: $case['updated_at'];
                            $proofNote = $case['proof_note'] ?: 'No proof note provided by maintenance team.';
                            $afterProofPath = solvedMediaPath($case['after_media_path']);
                            $priority = $case['assignment_priority'] ?: 'Medium';
                            ?>

                            <article class="case-card">

                                <div class="case-thumb">

                                    <?php if (!empty($afterProofPath) && ($case['after_media_type'] ?? '') === 'image'): ?>
                                        <img src="<?php echo solvedText($afterProofPath); ?>" alt="Maintenance completion proof">
                                    <?php elseif (!empty($afterProofPath) && ($case['after_media_type'] ?? '') === 'video'): ?>
                                        <video muted>
                                            <source src="<?php echo solvedText($afterProofPath); ?>">
                                        </video>
                                    <?php else: ?>
                                        <i class="bi bi-image"></i>
                                    <?php endif; ?>

                                </div>

                                <div class="case-content">

                                    <div class="case-meta-row">
                                        <span class="case-code">
                                            <?php echo solvedText($case['complaint_code']); ?>
                                        </span>

                                        <span class="status-badge">
                                            <?php echo solvedText(solvedStatusLabel($case['complaint_status'])); ?>
                                        </span>

                                        <span class="waiting-badge">
                                            <i class="bi bi-clock"></i>
                                            <?php echo solvedText(solvedWaitingTime($completedAt)); ?>
                                        </span>

                                        <span class="priority-badge <?php echo solvedPriorityClass($priority); ?>">
                                            <?php echo solvedText($priority); ?>
                                        </span>
                                    </div>

                                    <h3><?php echo solvedText($issueName); ?></h3>

                                    <div class="case-info-grid">

                                        <p>
                                            Area:
                                            <strong>
                                                <?php echo solvedText($areaName); ?>, Ward <?php echo solvedText($wardNo); ?>
                                            </strong>
                                        </p>

                                        <p>
                                            Team:
                                            <strong><?php echo solvedText($teamName); ?></strong>
                                        </p>

                                        <p>
                                            Solved Date:
                                            <strong><?php echo solvedText(solvedDate($completedAt)); ?></strong>
                                        </p>

                                        <p>
                                            Proof Files:
                                            <strong class="proof-count">
                                                <?php echo $proofCount; ?> uploaded
                                            </strong>
                                        </p>

                                    </div>

                                    <div class="completion-note">
                                        <span>Maintenance Proof Note</span>
                                        <p><?php echo solvedText($proofNote); ?></p>
                                    </div>

                                    <form method="POST" action="solved-cases.php" class="review-form">
                                        <input type="hidden" name="complaint_id" value="<?php echo (int) $case['complaint_id']; ?>">
                                        <input type="hidden" name="review_action" value="move_to_before_after">
                                        <input type="hidden" name="search" value="<?php echo solvedText($search); ?>">
                                        <input type="hidden" name="team_id" value="<?php echo solvedText($teamId); ?>">
                                        <input type="hidden" name="area_id" value="<?php echo solvedText($areaId); ?>">
                                        <input type="hidden" name="sort" value="<?php echo solvedText($sort); ?>">

                                        <button type="submit" class="review-btn">
                                            <i class="bi bi-eye"></i>
                                            Review & Inspect This Case
                                        </button>
                                    </form>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <div class="empty-state">
                            <i class="bi bi-check2-circle"></i>
                            <h3>No Solved by Team Case Found</h3>
                            <p>No maintenance-completed case is waiting to move for before/after review.</p>
                        </div>

                    <?php endif; ?>

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
    <script src="../../js/inspector/solved-cases.js"></script>

</body>

</html>