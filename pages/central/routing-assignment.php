<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "routing-assignment";
$pageTitle = "Ward Verification";
$pageParent = "Central Control";
$pageChild = "Ward Verification";

$successMessage = "";
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function urgencyClass($urgency)
{
    $urgency = strtolower((string)$urgency);

    if ($urgency === "low") return "priority-low";
    if ($urgency === "medium") return "priority-medium";
    if ($urgency === "high") return "priority-high";
    if ($urgency === "critical") return "priority-critical";

    return "priority-low";
}

function formatStatus($status)
{
    return ucwords(str_replace("_", " ", (string)$status));
}

function statusClass($status)
{
    $status = strtolower((string)$status);

    if ($status === "ward_assigned") return "status-assigned";
    if ($status === "pending_verification") return "status-pending";
    if ($status === "team_assigned") return "status-team";
    if ($status === "in_progress") return "status-progress";
    if ($status === "solved_by_team") return "status-completed";
    if ($status === "closed") return "status-completed";
    if ($status === "rejected") return "status-rejected";

    return "status-assigned";
}

function wardDisplayName($wardNo, $wardName)
{
    $wardName = trim((string)$wardName);

    if ($wardName !== "") {
        return $wardName;
    }

    return "Ward " . $wardNo;
}

/*
|--------------------------------------------------------------------------
| ROUTE / REJECT ACTION
|--------------------------------------------------------------------------
| Existing DB-safe flow:
| complaints.complaint_status = received
| Route:
|   complaint_assignments.assignment_status = ward_assigned
|   complaints.complaint_status = pending_verification
| Reject:
|   complaints.complaint_status = rejected
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $complaintId = (int)($_POST["complaint_id"] ?? 0);
    $action = trim($_POST["action"] ?? "");
    $wardId = (int)($_POST["ward_id"] ?? 0);
    $centralUserId = (int)($_SESSION["user_id"] ?? 0);

    if ($complaintId <= 0 || $action === "" || $centralUserId <= 0) {
        $errorMessage = "Invalid request.";
    } else {
        if ($action === "route") {
            if ($wardId <= 0) {
                $errorMessage = "Please select a valid ward.";
            } else {
                mysqli_begin_transaction($conn);

                try {
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

                    if ($complaintRow["complaint_status"] !== "received") {
                        throw new Exception("Only received complaints can be sent for ward verification.");
                    }

                    $wardCheckSql = "
                        SELECT ward_id
                        FROM wards
                        WHERE ward_id = ?
                        LIMIT 1
                    ";

                    $wardCheckStmt = mysqli_prepare($conn, $wardCheckSql);

                    if (!$wardCheckStmt) {
                        throw new Exception("Ward check failed: " . mysqli_error($conn));
                    }

                    mysqli_stmt_bind_param($wardCheckStmt, "i", $wardId);
                    mysqli_stmt_execute($wardCheckStmt);

                    $wardCheckResult = mysqli_stmt_get_result($wardCheckStmt);

                    if (!$wardCheckResult || mysqli_num_rows($wardCheckResult) !== 1) {
                        mysqli_stmt_close($wardCheckStmt);
                        throw new Exception("Selected ward does not exist.");
                    }

                    mysqli_stmt_close($wardCheckStmt);

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

                    $insertSql = "
                        INSERT INTO complaint_assignments (
                            complaint_id,
                            ward_id,
                            assigned_by,
                            assignment_status
                        )
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

        if ($action === "reject") {
            $checkSql = "
                SELECT complaint_status
                FROM complaints
                WHERE complaint_id = ?
                LIMIT 1
            ";

            $checkStmt = mysqli_prepare($conn, $checkSql);

            if (!$checkStmt) {
                $errorMessage = "Complaint check failed: " . mysqli_error($conn);
            } else {
                mysqli_stmt_bind_param($checkStmt, "i", $complaintId);
                mysqli_stmt_execute($checkStmt);

                $checkResult = mysqli_stmt_get_result($checkStmt);
                $complaintRow = $checkResult ? mysqli_fetch_assoc($checkResult) : null;

                mysqli_stmt_close($checkStmt);

                if (!$complaintRow) {
                    $errorMessage = "Complaint not found.";
                } elseif ($complaintRow["complaint_status"] !== "received") {
                    $errorMessage = "Only received complaints can be rejected from Ward Verification.";
                } else {
                    $updateSql = "
                        UPDATE complaints
                        SET complaint_status = 'rejected',
                            updated_at = NOW()
                        WHERE complaint_id = ?
                    ";

                    $stmt = mysqli_prepare($conn, $updateSql);

                    if (!$stmt) {
                        $errorMessage = "Reject query failed: " . mysqli_error($conn);
                    } else {
                        mysqli_stmt_bind_param($stmt, "i", $complaintId);

                        if (mysqli_stmt_execute($stmt)) {
                            $successMessage = "Complaint rejected successfully.";
                        } else {
                            $errorMessage = "Reject failed: " . mysqli_stmt_error($stmt);
                        }

                        mysqli_stmt_close($stmt);
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| FETCH WARDS
|--------------------------------------------------------------------------
*/

$wards = [];

$wardSql = "
    SELECT
        w.ward_id,
        w.ward_no,
        w.ward_name,
        t.thana_name,
        cc.city_cor_name
    FROM wards w
    INNER JOIN thanas t
        ON w.thana_id = t.thana_id
    INNER JOIN city_corporations cc
        ON w.city_cor_id = cc.city_cor_id
    ORDER BY cc.city_cor_name ASC, CAST(w.ward_no AS UNSIGNED), w.ward_no ASC
";

$wardResult = mysqli_query($conn, $wardSql);

if ($wardResult) {
    while ($row = mysqli_fetch_assoc($wardResult)) {
        $wards[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| FETCH RECEIVED COMPLAINTS
| Only accepted/received complaints that are not routed yet.
|--------------------------------------------------------------------------
*/

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
} else {
    $errorMessage = "Complaint fetch failed: " . mysqli_error($conn);
}

/*
|--------------------------------------------------------------------------
| FETCH RECENTLY ROUTED
|--------------------------------------------------------------------------
*/

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
        at.thana_name AS assigned_thana_name,
        acc.city_cor_name AS assigned_city_cor_name,

        ca.assignment_status,
        ca.assigned_at,

        au.user_name AS assigned_by_name

    FROM complaint_assignments ca

    INNER JOIN complaints c
        ON ca.complaint_id = c.complaint_id

    INNER JOIN wards aw
        ON ca.ward_id = aw.ward_id

    INNER JOIN thanas at
        ON aw.thana_id = at.thana_id

    INNER JOIN city_corporations acc
        ON aw.city_cor_id = acc.city_cor_id

    INNER JOIN users au
        ON ca.assigned_by = au.user_id

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

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">
    <link rel="stylesheet" href="../../css/central/footer.css">
    <link rel="stylesheet" href="../../css/central/routing-assignment.css">
    <link rel="stylesheet" href="../../css/central/centralTextFix.css">
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
                    <p>Route accepted complaints to the correct ward for field verification.</p>
                </div>

                <div class="ra-count-card">
                    <span><?php echo count($awaitingComplaints); ?></span>
                    <small>Awaiting Route</small>
                </div>
            </div>

            <?php if ($successMessage !== ""): ?>
                <div class="ra-alert ra-success">
                    <i class="bi bi-check-circle"></i>
                    <?php echo safeText($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ""): ?>
                <div class="ra-alert ra-error">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo safeText($errorMessage); ?>
                </div>
            <?php endif; ?>

            <div class="ra-warning-card">
                <div class="ra-warning-left">
                    <div class="ra-warning-icon">
                        <i class="bi bi-arrow-up-circle"></i>
                    </div>

                    <div>
                        <h2>Received Complaints Need Ward Verification</h2>
                        <p><?php echo count($awaitingComplaints); ?> received complaint(s) are waiting to be routed.</p>
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
                        placeholder="Search by complaint ID, issue, ward, area..."
                    >
                </div>

                <select id="priorityFilter">
                    <option value="all">All Priority</option>
                    <option value="Low">Low</option>
                    <option value="Medium">Medium</option>
                    <option value="High">High</option>
                    <option value="Critical">Critical</option>
                </select>
            </div>

            <div class="ra-list">

                <?php if (count($awaitingComplaints) > 0): ?>

                    <?php foreach ($awaitingComplaints as $complaint): ?>
                        <?php
                            $complaintId = (int)$complaint["complaint_id"];
                            $complaintCode = safeText($complaint["complaint_code"]);
                            $issueType = safeText($complaint["issue_type"]);
                            $problemDescription = safeText($complaint["problem_description"]);
                            $urgency = safeText($complaint["urgency_level"]);
                            $wardId = (int)$complaint["ward_id"];
                            $wardText = wardDisplayName($complaint["ward_no"], $complaint["ward_name"]);
                            $areaText = safeText($complaint["area_name"]);
                            $thanaText = safeText($complaint["thana_name"]);
                            $cityCorText = safeText($complaint["city_cor_name"]);
                            $dateText = date("M d, Y h:i A", strtotime($complaint["submitted_at"]));
                        ?>

                        <article
                            class="ra-card"
                            data-code="<?php echo strtolower($complaintCode); ?>"
                            data-issue="<?php echo strtolower($issueType); ?>"
                            data-title="<?php echo strtolower($problemDescription); ?>"
                            data-ward="<?php echo strtolower(safeText($wardText)); ?>"
                            data-area="<?php echo strtolower($areaText); ?>"
                            data-priority="<?php echo $urgency; ?>"
                        >

                            <div class="ra-card-meta">
                                <span class="ra-code"><?php echo $complaintCode; ?></span>

                                <span class="ra-priority <?php echo urgencyClass($urgency); ?>">
                                    <?php echo $urgency; ?>
                                </span>

                                <span>Issue: <strong><?php echo $issueType; ?></strong></span>
                            </div>

                            <h2><?php echo $problemDescription; ?></h2>

                            <div class="ra-info-line">
                                <span>City Corporation: <strong><?php echo $cityCorText; ?></strong></span>
                                <span>•</span>
                                <span>Ward: <strong><?php echo safeText($wardText); ?></strong></span>
                                <span>•</span>
                                <span>Thana: <strong><?php echo $thanaText; ?></strong></span>
                                <span>•</span>
                                <span>Area: <strong><?php echo $areaText; ?></strong></span>
                                <span>•</span>
                                <span>Submitted: <?php echo safeText($dateText); ?></span>
                                <span>•</span>
                                <span>By: <?php echo safeText($complaint["user_name"]); ?></span>
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
                                            <?php
                                                $optionWardText = wardDisplayName($ward["ward_no"], $ward["ward_name"]);
                                            ?>
                                            <option value="<?php echo (int)$ward["ward_id"]; ?>">
                                                <?php echo safeText($ward["city_cor_name"]); ?> -
                                                <?php echo safeText($optionWardText); ?> -
                                                <?php echo safeText($ward["thana_name"]); ?>
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
                                    <th>Complaint ID</th>
                                    <th>Issue</th>
                                    <th>Assigned Ward</th>
                                    <th>Priority</th>
                                    <th>Complaint Status</th>
                                    <th>Assignment Status</th>
                                    <th>Assigned By</th>
                                    <th>Assigned At</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($routedComplaints as $routed): ?>
                                    <?php
                                        $assignedWardText = wardDisplayName($routed["assigned_ward_no"], $routed["assigned_ward_name"]);
                                        $assignedAt = date("M d, Y h:i A", strtotime($routed["assigned_at"]));
                                    ?>

                                    <tr>
                                        <td>
                                            <span class="ra-code">
                                                <?php echo safeText($routed["complaint_code"]); ?>
                                            </span>
                                        </td>

                                        <td><?php echo safeText($routed["issue_type"]); ?></td>

                                        <td>
                                            <?php echo safeText($routed["assigned_city_cor_name"]); ?> -
                                            <?php echo safeText($assignedWardText); ?> -
                                            <?php echo safeText($routed["assigned_thana_name"]); ?>
                                        </td>

                                        <td>
                                            <span class="ra-priority <?php echo urgencyClass($routed["urgency_level"]); ?>">
                                                <?php echo safeText($routed["urgency_level"]); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="ra-status <?php echo statusClass($routed["complaint_status"]); ?>">
                                                <?php echo safeText(formatStatus($routed["complaint_status"])); ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="ra-status <?php echo statusClass($routed["assignment_status"]); ?>">
                                                <?php echo safeText(formatStatus($routed["assignment_status"])); ?>
                                            </span>
                                        </td>

                                        <td><?php echo safeText($routed["assigned_by_name"]); ?></td>

                                        <td><?php echo safeText($assignedAt); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php else: ?>

                    <div class="ra-empty-small">
                        No routed complaints yet.
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