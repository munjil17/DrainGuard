<?php
$activePage = 'routing-assignment';
$pageTitle = "Ward Verification";
$pageParent = "Central Control";
$pageChild = "Ward Verification";

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'central_officer') {
    header("Location: ../../index.php");
    exit();
}

$centralUserId = (int) $_SESSION['user_id'];
$successMessage = "";
$errorMessage = "";

function safeText($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function urgencyClass($urgency) {
    $urgency = strtolower((string)$urgency);

    if ($urgency === 'low') return 'priority-low';
    if ($urgency === 'medium') return 'priority-medium';
    if ($urgency === 'high') return 'priority-high';
    if ($urgency === 'critical') return 'priority-critical';

    return 'priority-low';
}

function assignmentStatusText($status) {
    return ucwords(str_replace('_', ' ', (string)$status));
}

function assignmentStatusClass($status) {
    $status = strtolower((string)$status);

    if ($status === 'ward_assigned') return 'status-assigned';
    if ($status === 'pending_verification') return 'status-pending';
    if ($status === 'verified') return 'status-verified';
    if ($status === 'team_assigned') return 'status-team';
    if ($status === 'in_progress') return 'status-progress';
    if ($status === 'completed') return 'status-completed';
    if ($status === 'rejected') return 'status-rejected';

    return 'status-assigned';
}

/* ===============================
   ROUTE / REJECT ACTION
   Correct flow:
   received → pending_verification
================================ */

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $complaintId = (int)($_POST['complaint_id'] ?? 0);
    $action = trim($_POST['action'] ?? '');
    $wardId = (int)($_POST['ward_id'] ?? 0);

    if ($complaintId <= 0 || $action === '') {
        $errorMessage = "Invalid request.";
    } else {

        if ($action === 'route') {
            if ($wardId <= 0) {
                $errorMessage = "Please select a valid ward.";
            } else {
                mysqli_begin_transaction($conn);

                try {
                    /*
                    |--------------------------------------------------------------------------
                    | Check complaint must be received
                    |--------------------------------------------------------------------------
                    */

                    $checkSql = "
                        SELECT complaint_status
                        FROM complaints
                        WHERE complaint_id = ?
                        LIMIT 1
                    ";

                    $checkStmt = mysqli_prepare($conn, $checkSql);

                    if (!$checkStmt) {
                        throw new Exception("Complaint check failed: " . mysqli_error($conn));
                    }

                    mysqli_stmt_bind_param($checkStmt, "i", $complaintId);
                    mysqli_stmt_execute($checkStmt);

                    $checkResult = mysqli_stmt_get_result($checkStmt);
                    $complaintRow = $checkResult ? mysqli_fetch_assoc($checkResult) : null;

                    mysqli_stmt_close($checkStmt);

                    if (!$complaintRow) {
                        throw new Exception("Complaint not found.");
                    }

                    if ($complaintRow['complaint_status'] !== 'received') {
                        throw new Exception("Only received complaints can be sent for ward verification.");
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Prevent duplicate assignment
                    |--------------------------------------------------------------------------
                    */

                    $duplicateSql = "
                        SELECT assignment_id
                        FROM complaint_assignments
                        WHERE complaint_id = ?
                        LIMIT 1
                    ";

                    $duplicateStmt = mysqli_prepare($conn, $duplicateSql);

                    if (!$duplicateStmt) {
                        throw new Exception("Assignment check failed: " . mysqli_error($conn));
                    }

                    mysqli_stmt_bind_param($duplicateStmt, "i", $complaintId);
                    mysqli_stmt_execute($duplicateStmt);

                    $duplicateResult = mysqli_stmt_get_result($duplicateStmt);

                    if ($duplicateResult && mysqli_num_rows($duplicateResult) > 0) {
                        mysqli_stmt_close($duplicateStmt);
                        throw new Exception("This complaint is already routed.");
                    }

                    mysqli_stmt_close($duplicateStmt);

                    /*
                    |--------------------------------------------------------------------------
                    | Insert ward assignment
                    |--------------------------------------------------------------------------
                    */

                    $insertSql = "
                        INSERT INTO complaint_assignments
                        (complaint_id, ward_id, assigned_by, assignment_status)
                        VALUES (?, ?, ?, 'ward_assigned')
                    ";

                    $insertStmt = mysqli_prepare($conn, $insertSql);

                    if (!$insertStmt) {
                        throw new Exception("Assignment insert failed: " . mysqli_error($conn));
                    }

                    mysqli_stmt_bind_param($insertStmt, "iii", $complaintId, $wardId, $centralUserId);

                    if (!mysqli_stmt_execute($insertStmt)) {
                        throw new Exception("Assignment save failed: " . mysqli_stmt_error($insertStmt));
                    }

                    mysqli_stmt_close($insertStmt);

                    /*
                    |--------------------------------------------------------------------------
                    | Update complaint status for citizen tracking and ward queue
                    |--------------------------------------------------------------------------
                    */

                    $updateSql = "
                        UPDATE complaints
                        SET complaint_status = 'pending_verification',
                            updated_at = NOW()
                        WHERE complaint_id = ?
                    ";

                    $updateStmt = mysqli_prepare($conn, $updateSql);

                    if (!$updateStmt) {
                        throw new Exception("Complaint status update failed: " . mysqli_error($conn));
                    }

                    mysqli_stmt_bind_param($updateStmt, "i", $complaintId);

                    if (!mysqli_stmt_execute($updateStmt)) {
                        throw new Exception("Complaint status update failed: " . mysqli_stmt_error($updateStmt));
                    }

                    mysqli_stmt_close($updateStmt);

                    mysqli_commit($conn);

                    $successMessage = "Complaint sent to ward for verification successfully.";

                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $errorMessage = "Routing failed: " . $e->getMessage();
                }
            }
        }

        if ($action === 'reject') {
            $checkSql = "
                SELECT complaint_status
                FROM complaints
                WHERE complaint_id = ?
                LIMIT 1
            ";

            $checkStmt = mysqli_prepare($conn, $checkSql);

            if ($checkStmt) {
                mysqli_stmt_bind_param($checkStmt, "i", $complaintId);
                mysqli_stmt_execute($checkStmt);

                $checkResult = mysqli_stmt_get_result($checkStmt);
                $complaintRow = $checkResult ? mysqli_fetch_assoc($checkResult) : null;

                mysqli_stmt_close($checkStmt);

                if (!$complaintRow) {
                    $errorMessage = "Complaint not found.";
                } elseif ($complaintRow['complaint_status'] !== 'received') {
                    $errorMessage = "Only received complaints can be rejected from Ward Verification.";
                } else {
                    $updateSql = "
                        UPDATE complaints
                        SET complaint_status = 'rejected',
                            updated_at = NOW()
                        WHERE complaint_id = ?
                    ";

                    $stmt = mysqli_prepare($conn, $updateSql);

                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "i", $complaintId);

                        if (mysqli_stmt_execute($stmt)) {
                            $successMessage = "Complaint rejected successfully.";
                        } else {
                            $errorMessage = "Reject failed.";
                        }

                        mysqli_stmt_close($stmt);
                    } else {
                        $errorMessage = "Reject query failed.";
                    }
                }
            } else {
                $errorMessage = "Complaint check failed.";
            }
        }
    }
}

/* ===============================
   FETCH WARDS
================================ */

$wards = [];

$wardSql = "
    SELECT
        w.ward_id,
        w.ward_no,
        w.ward_name,
        t.thana_name
    FROM wards w
    INNER JOIN thanas t
        ON w.thana_id = t.thana_id
    ORDER BY CAST(w.ward_no AS UNSIGNED), w.ward_no ASC
";

$wardResult = mysqli_query($conn, $wardSql);

if ($wardResult) {
    while ($row = mysqli_fetch_assoc($wardResult)) {
        $wards[] = $row;
    }
}

/* ===============================
   FETCH RECEIVED COMPLAINTS
   Only received complaints appear here
================================ */

$awaitingComplaints = [];

$sql = "
    SELECT
        c.complaint_id,
        c.complaint_code,
        c.issue_type,
        c.problem_description,
        c.urgency_level,
        c.complaint_status,
        c.submitted_at,

        u.user_name,

        w.ward_id,
        w.ward_no,
        w.ward_name,
        a.area_name,
        t.thana_name,
        cc.city_cor_name

    FROM complaints c

    INNER JOIN users u
        ON c.user_id = u.user_id

    INNER JOIN locations l
        ON c.loc_id = l.loc_id

    INNER JOIN wards w
        ON l.ward_id = w.ward_id

    INNER JOIN areas a
        ON l.area_id = a.area_id

    INNER JOIN thanas t
        ON l.thana_id = t.thana_id

    INNER JOIN city_corporations cc
        ON l.city_cor_id = cc.city_cor_id

    LEFT JOIN complaint_assignments ca
        ON c.complaint_id = ca.complaint_id

    WHERE c.complaint_status = 'received'
    AND ca.assignment_id IS NULL

    ORDER BY
        CASE
            WHEN c.urgency_level = 'Critical' THEN 1
            WHEN c.urgency_level = 'High' THEN 2
            WHEN c.urgency_level = 'Medium' THEN 3
            ELSE 4
        END,
        c.submitted_at DESC
";

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $awaitingComplaints[] = $row;
    }
}

/* ===============================
   FETCH RECENTLY ROUTED
================================ */

$routedComplaints = [];

$routedSql = "
    SELECT
        c.complaint_code,
        c.issue_type,
        c.problem_description,
        c.urgency_level,
        c.complaint_status,

        aw.ward_no AS assigned_ward_no,
        aw.ward_name AS assigned_ward_name,

        ca.assignment_status,
        ca.assigned_at

    FROM complaint_assignments ca

    INNER JOIN complaints c
        ON ca.complaint_id = c.complaint_id

    INNER JOIN wards aw
        ON ca.ward_id = aw.ward_id

    ORDER BY ca.assigned_at DESC
    LIMIT 10
";

$routedResult = mysqli_query($conn, $routedSql);

if ($routedResult) {
    while ($row = mysqli_fetch_assoc($routedResult)) {
        $routedComplaints[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ward Verification | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <!-- Reusable Central Layout CSS -->
    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">
    <link rel="stylesheet" href="../../css/central/footer.css">

    <!-- Page CSS only -->
    <link rel="stylesheet" href="../../css/central/routing-assignment.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="ra-page">

            <div class="ra-header">
                <div>
                    <h1>Ward Verification</h1>
                    <p>Send received complaints to the correct ward for problem verification.</p>
                </div>

                <div class="ra-count-card">
                    <span><?php echo count($awaitingComplaints); ?></span>
                    <small>Awaiting Route</small>
                </div>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="ra-alert ra-success">
                    <i class="bi bi-check-circle"></i>
                    <?php echo safeText($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="ra-alert ra-error">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo safeText($errorMessage); ?>
                </div>
            <?php endif; ?>

            <div class="ra-warning-card">
                <div class="ra-warning-left">
                    <div class="ra-warning-icon">
                        <i class="bi bi-send-check"></i>
                    </div>

                    <div>
                        <h2><?php echo count($awaitingComplaints); ?> Received Complaints Awaiting Ward Verification</h2>
                        <p>Route complaints to their ward so ward officers can verify the problem.</p>
                    </div>
                </div>

                <button type="button" class="ra-bulk-btn" id="bulkRouteBtn">
                    Bulk Route
                </button>
            </div>

            <div class="ra-toolbar">
                <div class="ra-search-box">
                    <i class="bi bi-search"></i>
                    <input
                        type="text"
                        id="routeSearch"
                        placeholder="Search by complaint ID, ward, area, issue..."
                    >
                </div>

                <select id="priorityFilter">
                    <option value="all">All Priority</option>
                    <option value="Critical">Critical</option>
                    <option value="High">High</option>
                    <option value="Medium">Medium</option>
                    <option value="Low">Low</option>
                </select>
            </div>

            <div class="ra-list">

                <?php if (count($awaitingComplaints) > 0): ?>

                    <?php foreach ($awaitingComplaints as $complaint): ?>
                        <?php
                            $complaintId = (int)$complaint['complaint_id'];
                            $complaintCode = safeText($complaint['complaint_code']);
                            $issueType = safeText($complaint['issue_type']);

                            $rawProblem = (string)$complaint['problem_description'];
                            $problem = safeText($rawProblem);
                            $shortTitleRaw = strlen($rawProblem) > 80 ? substr($rawProblem, 0, 80) . "..." : $rawProblem;
                            $shortTitle = safeText($shortTitleRaw);

                            $wardId = (int)$complaint['ward_id'];
                            $wardText = "Ward " . $complaint['ward_no'];
                            $areaText = safeText($complaint['area_name']);
                            $thanaText = safeText($complaint['thana_name']);
                            $priority = safeText($complaint['urgency_level']);
                            $dateText = date("M d, h:i A", strtotime($complaint['submitted_at']));
                        ?>

                        <article
                            class="ra-card"
                            data-code="<?php echo strtolower($complaintCode); ?>"
                            data-issue="<?php echo strtolower($issueType); ?>"
                            data-title="<?php echo strtolower($shortTitle); ?>"
                            data-ward="<?php echo strtolower($wardText); ?>"
                            data-area="<?php echo strtolower($areaText); ?>"
                            data-priority="<?php echo $priority; ?>"
                        >

                            <div class="ra-card-meta">
                                <span class="ra-code"><?php echo $complaintCode; ?></span>
                                <span class="ra-priority <?php echo urgencyClass($priority); ?>">
                                    <?php echo $priority; ?>
                                </span>
                            </div>

                            <h2><?php echo $shortTitle; ?></h2>

                            <div class="ra-info-line">
                                <span>Detected Ward: <strong><?php echo safeText($wardText); ?></strong></span>
                                <span>•</span>
                                <span>Thana: <strong><?php echo $thanaText; ?></strong></span>
                                <span>•</span>
                                <span>Area: <strong><?php echo $areaText; ?></strong></span>
                                <span>•</span>
                                <span>Submitted: <?php echo $dateText; ?></span>
                                <span>•</span>
                                <span>By: <?php echo safeText($complaint['user_name']); ?></span>
                            </div>

                            <div class="ra-actions">

                                <form method="POST" action="routing-assignment.php" class="ra-form">
                                    <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
                                    <input type="hidden" name="ward_id" value="<?php echo $wardId; ?>">
                                    <input type="hidden" name="action" value="route">

                                    <button type="submit" class="ra-route-btn">
                                        <i class="bi bi-send"></i>
                                        Send to <?php echo safeText($wardText); ?>
                                    </button>
                                </form>

                                <form method="POST" action="routing-assignment.php" class="ra-form different-ward-form">
                                    <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
                                    <input type="hidden" name="action" value="route">

                                    <select name="ward_id" required>
                                        <option value="">Different Ward</option>
                                        <?php foreach ($wards as $ward): ?>
                                            <option value="<?php echo (int)$ward['ward_id']; ?>">
                                                Ward <?php echo safeText($ward['ward_no']); ?> - <?php echo safeText($ward['thana_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button type="submit" class="ra-different-btn">
                                        Send to Different Ward
                                    </button>
                                </form>

                                <form method="POST" action="routing-assignment.php" class="ra-form">
                                    <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
                                    <input type="hidden" name="action" value="reject">

                                    <button type="submit" class="ra-reject-btn">
                                        Reject
                                    </button>
                                </form>

                            </div>

                        </article>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="ra-empty">
                        <i class="bi bi-check-circle"></i>
                        <h2>No complaints awaiting ward verification</h2>
                        <p>Received complaints will appear here after Central accepts them.</p>
                    </div>

                <?php endif; ?>

            </div>

            <div class="ra-table-card">

                <div class="ra-table-header">
                    <h2>Recently Sent for Ward Verification</h2>
                </div>

                <?php if (count($routedComplaints) > 0): ?>

                    <div class="ra-table-wrap">
                        <table class="ra-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Sent To</th>
                                    <th>Priority</th>
                                    <th>Current Status</th>
                                    <th>Sent At</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($routedComplaints as $routed): ?>
                                    <?php
                                        $routedCode = safeText($routed['complaint_code']);
                                        $routedProblemRaw = (string)$routed['problem_description'];
                                        $routedTitleRaw = strlen($routedProblemRaw) > 55 ? substr($routedProblemRaw, 0, 55) . "..." : $routedProblemRaw;
                                        $routedTitle = safeText($routedTitleRaw);

                                        $assignedWard = safeText("Ward " . $routed['assigned_ward_no']);
                                        $routedPriority = safeText($routed['urgency_level']);
                                        $assignmentStatus = safeText(assignmentStatusText($routed['assignment_status']));
                                        $assignedAt = date("M d, h:i A", strtotime($routed['assigned_at']));
                                    ?>

                                    <tr>
                                        <td>
                                            <span class="ra-code"><?php echo $routedCode; ?></span>
                                        </td>

                                        <td><?php echo $routedTitle; ?></td>

                                        <td><?php echo $assignedWard; ?></td>

                                        <td>
                                            <span class="ra-priority <?php echo urgencyClass($routedPriority); ?>">
                                                <?php echo $routedPriority; ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="ra-status <?php echo assignmentStatusClass($routed['assignment_status']); ?>">
                                                <?php echo $assignmentStatus; ?>
                                            </span>
                                        </td>

                                        <td><?php echo $assignedAt; ?></td>
                                    </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php else: ?>

                    <div class="ra-empty-small">
                        No complaints sent for ward verification yet.
                    </div>

                <?php endif; ?>

            </div>

        </section>

        <?php include "../../includes/central/footer.php"; ?>

    </main>

</div>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/routing-assignment.js"></script>

</body>
</html>