<?php
require_once "../../config.php";
require_once "../../includes/notification_workflow_cleanup.php";

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
| ROUTE ACTION ONLY
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $complaintId = (int)($_POST["complaint_id"] ?? 0);
    $action = trim($_POST["action"] ?? "");
    $wardId = (int)($_POST["ward_id"] ?? 0);
    $centralUserId = (int)($_SESSION["user_id"] ?? 0);

    if ($complaintId <= 0 || $action !== "route" || $wardId <= 0 || $centralUserId <= 0) {
        $errorMessage = "Invalid route request.";
    } else {
        mysqli_begin_transaction($conn);

        try {
            $checkSql = "
                SELECT complaint_status, user_id, complaint_code
                FROM complaints
                WHERE complaint_id = ?
                LIMIT 1
            ";

            $checkStmt = mysqli_prepare($conn, $checkSql);

            if (!$checkStmt) {
                throw new Exception("Unable to complete this action. Please try again.");
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

            $duplicateSql = "
                SELECT assignment_id
                FROM complaint_assignments
                WHERE complaint_id = ?
                LIMIT 1
            ";

            $duplicateStmt = mysqli_prepare($conn, $duplicateSql);

            if (!$duplicateStmt) {
                throw new Exception("Unable to complete this action. Please try again.");
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
                throw new Exception("Unable to complete this action. Please try again.");
            }

            mysqli_stmt_bind_param($insertStmt, "iii", $complaintId, $wardId, $centralUserId);

            if (!mysqli_stmt_execute($insertStmt)) {
                throw new Exception("Unable to complete this action. Please try again.");
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
                throw new Exception("Unable to complete this action. Please try again.");
            }

            mysqli_stmt_bind_param($updateStmt, "i", $complaintId);

            if (!mysqli_stmt_execute($updateStmt)) {
                throw new Exception("Unable to complete this action. Please try again.");
            }

            mysqli_stmt_close($updateStmt);

            $citizenUserId = (int)($complaintRow["user_id"] ?? 0);
            if ($citizenUserId > 0) {
                $notifSql = "
                    INSERT INTO citizen_notifications (
                        recipient_user_id,
                        sender_user_id,
                        related_complaint_id,
                        notification_type,
                        notification_title,
                        notification_message,
                        is_read,
                        created_at
                    ) VALUES (?, ?, ?, 'status_update', 'Complaint Routed to Ward', 'Your complaint has been processed by the Central Control and routed to your respective Ward Officer for field verification.', 0, NOW())
                ";
                $notifStmt = mysqli_prepare($conn, $notifSql);
                if ($notifStmt) {
                    dg_cleanup_workflow_notifications($conn, "citizen_notifications", $citizenUserId, $complaintId, "status_update");
                    mysqli_stmt_bind_param($notifStmt, "iii", $citizenUserId, $centralUserId, $complaintId);
                    mysqli_stmt_execute($notifStmt);
                    mysqli_stmt_close($notifStmt);
                }
            }

            // Find Ward Officer user_id
            $wardOfficerUserId = 0;
            $wardOfficerSql = "SELECT user_id FROM ward_officers WHERE assigned_ward_id = ? LIMIT 1";
            $wardOfficerStmt = mysqli_prepare($conn, $wardOfficerSql);
            if ($wardOfficerStmt) {
                mysqli_stmt_bind_param($wardOfficerStmt, "i", $wardId);
                mysqli_stmt_execute($wardOfficerStmt);
                $wardOfficerResult = mysqli_stmt_get_result($wardOfficerStmt);
                if ($row = mysqli_fetch_assoc($wardOfficerResult)) {
                    $wardOfficerUserId = (int)$row['user_id'];
                }
                mysqli_stmt_close($wardOfficerStmt);
            }
            
            // Insert Ward Notification
            if ($wardOfficerUserId > 0) {
                $complaintCode = $complaintRow['complaint_code'] ?? 'Unknown';
                $wardNotifSql = "
                    INSERT INTO ward_notifications (
                        recipient_user_id,
                        sender_user_id,
                        related_complaint_id,
                        notification_type,
                        notification_title,
                        notification_message,
                        is_read,
                        created_at
                    ) VALUES (?, ?, ?, 'complaint_routed', 'New Complaint Assigned for Verification', ?, 0, NOW())
                ";
                $wardNotifStmt = mysqli_prepare($conn, $wardNotifSql);
                if ($wardNotifStmt) {
                    $wardMessage = "Central Control has routed complaint #$complaintCode to your ward for verification.";
                    dg_cleanup_workflow_notifications($conn, "ward_notifications", $wardOfficerUserId, $complaintId, "complaint_routed");
                    mysqli_stmt_bind_param($wardNotifStmt, "iiis", $wardOfficerUserId, $centralUserId, $complaintId, $wardMessage);
                    mysqli_stmt_execute($wardNotifStmt);
                    mysqli_stmt_close($wardNotifStmt);
                }
            }

            mysqli_commit($conn);

            $successMessage = "Complaint sent to ward for verification successfully.";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $errorMessage = "Routing failed: " . $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOCATION FILTER MASTER DATA
|--------------------------------------------------------------------------
*/

$cities = [];
$cityCorporations = [];
$thanas = [];
$wardsForFilter = [];
$areasForFilter = [];

$cityResult = mysqli_query($conn, "
    SELECT city_id, city_name
    FROM cities
    ORDER BY city_name ASC
");

if ($cityResult) {
    while ($row = mysqli_fetch_assoc($cityResult)) {
        $cities[] = $row;
    }
}

$corpResult = mysqli_query($conn, "
    SELECT city_cor_id, city_cor_name, city_id
    FROM city_corporations
    ORDER BY city_cor_name ASC
");

if ($corpResult) {
    while ($row = mysqli_fetch_assoc($corpResult)) {
        $cityCorporations[] = $row;
    }
}

$thanaResult = mysqli_query($conn, "
    SELECT thana_id, thana_name, city_cor_id
    FROM thanas
    ORDER BY thana_name ASC
");

if ($thanaResult) {
    while ($row = mysqli_fetch_assoc($thanaResult)) {
        $thanas[] = $row;
    }
}

$wardResult = mysqli_query($conn, "
    SELECT ward_id, ward_no, ward_name, thana_id
    FROM wards
    ORDER BY CAST(ward_no AS UNSIGNED), ward_no ASC
");

if ($wardResult) {
    while ($row = mysqli_fetch_assoc($wardResult)) {
        $wardsForFilter[] = $row;
    }
}

$areaResult = mysqli_query($conn, "
    SELECT area_id, ward_id, area_name
    FROM areas
    ORDER BY area_name ASC
");

if ($areaResult) {
    while ($row = mysqli_fetch_assoc($areaResult)) {
        $areasForFilter[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| FETCH RECEIVED COMPLAINTS
| Issue source:
| complaints.issue_id -> issues.issue_id -> issues.issue_name / priority
|--------------------------------------------------------------------------
*/

$awaitingComplaints = [];

$sql = "
    SELECT
        c.complaint_id,
        c.complaint_code,
        c.address_description,
        c.problem_description,
        c.complaint_status,
        c.submitted_at,

        u.user_name,
        u.user_mail,

        l.city_id,
        l.city_cor_id,
        l.thana_id,
        l.ward_id,
        l.area_id,

        city.city_name,
        cc.city_cor_name,

        w.ward_no,
        w.ward_name,

        a.area_name,
        t.thana_name,

        COALESCE(i.issue_name, 'Unknown Issue') AS issue_type,
        COALESCE(i.priority, 'Low') AS urgency_level,

        aa.affected_area_name,

        d.drain_code,
        d.drain_name

    FROM complaints c

    INNER JOIN users u
        ON c.user_id = u.user_id

    INNER JOIN locations l
        ON c.loc_id = l.loc_id

    INNER JOIN cities city
        ON l.city_id = city.city_id

    INNER JOIN city_corporations cc
        ON l.city_cor_id = cc.city_cor_id

    INNER JOIN wards w
        ON l.ward_id = w.ward_id

    INNER JOIN areas a
        ON l.area_id = a.area_id

    INNER JOIN thanas t
        ON l.thana_id = t.thana_id

    LEFT JOIN issues i
        ON c.issue_id = i.issue_id

    LEFT JOIN affected_areas aa
        ON c.affected_area_id = aa.affected_area_id

    LEFT JOIN drains d
        ON c.drain_id = d.drain_id

    LEFT JOIN complaint_assignments ca
        ON c.complaint_id = ca.complaint_id

    WHERE c.complaint_status = 'received'
    AND ca.assignment_id IS NULL

    ORDER BY
        CASE
            WHEN COALESCE(i.priority, 'Low') = 'High' THEN 1
            WHEN COALESCE(i.priority, 'Low') = 'Medium' THEN 2
            ELSE 3
        END,
        c.submitted_at DESC
";

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $awaitingComplaints[] = $row;
    }
} else {
    $errorMessage = "Unable to load complaints. Please try again.";
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
        c.problem_description,
        c.complaint_status,

        COALESCE(i.issue_name, 'Unknown Issue') AS issue_type,
        COALESCE(i.priority, 'Low') AS urgency_level,

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

    LEFT JOIN issues i
        ON c.issue_id = i.issue_id

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

    <link rel="stylesheet" href="../../css/central/routing-assignment.css">
    <link rel="stylesheet" href="../../css/central/centralTextFix.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
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
                </select>
            </div>

            <div class="ra-location-filter-card">
                <select id="cityFilter">
                    <option value="">All City</option>
                    <?php foreach ($cities as $city): ?>
                        <option value="<?php echo (int)$city["city_id"]; ?>">
                            <?php echo safeText($city["city_name"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="cityCorporationFilter" disabled>
                    <option value="">All City Corporation</option>
                </select>

                <select id="thanaFilter" disabled>
                    <option value="">All Thana</option>
                </select>

                <select id="wardFilter" disabled>
                    <option value="">All Ward</option>
                </select>

                <select id="areaFilter" disabled>
                    <option value="">All Area</option>
                </select>

                <button type="button" id="clearLocationFilters" class="ra-clear-location-btn">
                    Clear
                </button>
            </div>

            <div class="ra-list">

                <?php if (count($awaitingComplaints) > 0): ?>

                    <?php foreach ($awaitingComplaints as $complaint): ?>
                        <?php
                            $complaintId = (int)$complaint["complaint_id"];
                            $complaintCode = safeText($complaint["complaint_code"]);
                            $issueType = safeText($complaint["issue_type"]);
                            $affectedAreaName = safeText($complaint["affected_area_name"] ?? "General Area");
                            $problemDescription = safeText($complaint["problem_description"]);
                            $addressDescription = safeText($complaint["address_description"]);
                            $urgency = safeText($complaint["urgency_level"] ?? "Low");

                            $wardId = (int)$complaint["ward_id"];
                            $wardText = wardDisplayName($complaint["ward_no"], $complaint["ward_name"]);

                            $areaText = safeText($complaint["area_name"]);
                            $thanaText = safeText($complaint["thana_name"]);
                            $cityText = safeText($complaint["city_name"]);
                            $cityCorText = safeText($complaint["city_cor_name"]);
                            $dateText = date("M d, Y h:i A", strtotime($complaint["submitted_at"]));
                            $citizenName = safeText($complaint["user_name"]);
                            $citizenEmail = safeText($complaint["user_mail"]);

                            $drainText = "Not linked";

                            if (!empty($complaint["drain_code"]) || !empty($complaint["drain_name"])) {
                                $drainText = trim(($complaint["drain_code"] ?? "") . " - " . ($complaint["drain_name"] ?? ""), " -");
                            }
                        ?>

                        <article
                            class="ra-card"
                            data-code="<?php echo strtolower($complaintCode); ?>"
                            data-issue="<?php echo strtolower($issueType); ?>"
                            data-title="<?php echo strtolower($problemDescription); ?>"
                            data-ward="<?php echo strtolower(safeText($wardText)); ?>"
                            data-area="<?php echo strtolower($areaText); ?>"
                            data-priority="<?php echo $urgency; ?>"
                            data-city-id="<?php echo (int)$complaint["city_id"]; ?>"
                            data-city-cor-id="<?php echo (int)$complaint["city_cor_id"]; ?>"
                            data-thana-id="<?php echo (int)$complaint["thana_id"]; ?>"
                            data-ward-id="<?php echo (int)$complaint["ward_id"]; ?>"
                            data-area-id="<?php echo (int)$complaint["area_id"]; ?>"
                        >

                            <div class="ra-card-meta">
                                <span class="ra-code"><?php echo $complaintCode; ?></span>

                                <span class="ra-priority <?php echo urgencyClass($urgency); ?>">
                                    <?php echo $urgency; ?>
                                </span>

                                <span>Issue: <strong><?php echo $issueType; ?></strong></span>

                                <span>Affected Area: <strong><?php echo $affectedAreaName; ?></strong></span>
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
                                <span>By: <?php echo $citizenName; ?></span>
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

                                <button
                                    type="button"
                                    class="ra-details-btn"
                                    data-code="<?php echo $complaintCode; ?>"
                                    data-issue="<?php echo $issueType; ?>"
                                    data-affected-area="<?php echo $affectedAreaName; ?>"
                                    data-priority="<?php echo $urgency; ?>"
                                    data-title="<?php echo $problemDescription; ?>"
                                    data-address="<?php echo $addressDescription; ?>"
                                    data-city="<?php echo $cityText; ?>"
                                    data-corporation="<?php echo $cityCorText; ?>"
                                    data-thana="<?php echo $thanaText; ?>"
                                    data-ward="<?php echo safeText($wardText); ?>"
                                    data-area="<?php echo $areaText; ?>"
                                    data-date="<?php echo safeText($dateText); ?>"
                                    data-citizen="<?php echo $citizenName; ?>"
                                    data-email="<?php echo $citizenEmail; ?>"
                                    data-drain="<?php echo safeText($drainText); ?>"
                                >
                                    <i class="bi bi-info-circle"></i>
                                    More Details
                                </button>

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

       

    </main>

</div>

<div class="ra-details-modal-overlay" id="raDetailsModal">
    <div class="ra-details-modal">
        <div class="ra-details-modal-header">
            <div>
                <h2 id="raModalTitle">Complaint Details</h2>
                <p id="raModalCode">Complaint Code</p>
            </div>

            <button type="button" class="ra-modal-close" id="raModalClose">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="ra-details-modal-body">
            <div class="ra-detail-grid">
                <div>
                    <span>Issue</span>
                    <strong id="raModalIssue"></strong>
                </div>

                <div>
                    <span>Affected Area</span>
                    <strong id="raModalAffectedArea"></strong>
                </div>

                <div>
                    <span>Priority</span>
                    <strong id="raModalPriority"></strong>
                </div>

                <div>
                    <span>Citizen</span>
                    <strong id="raModalCitizen"></strong>
                </div>

                <div>
                    <span>Email</span>
                    <strong id="raModalEmail"></strong>
                </div>

                <div>
                    <span>City</span>
                    <strong id="raModalCity"></strong>
                </div>

                <div>
                    <span>City Corporation</span>
                    <strong id="raModalCorporation"></strong>
                </div>

                <div>
                    <span>Thana</span>
                    <strong id="raModalThana"></strong>
                </div>

                <div>
                    <span>Ward</span>
                    <strong id="raModalWard"></strong>
                </div>

                <div>
                    <span>Area</span>
                    <strong id="raModalArea"></strong>
                </div>

                <div>
                    <span>Drain</span>
                    <strong id="raModalDrain"></strong>
                </div>

                <div>
                    <span>Submitted At</span>
                    <strong id="raModalDate"></strong>
                </div>

                <div class="ra-detail-wide">
                    <span>Address Description</span>
                    <strong id="raModalAddress"></strong>
                </div>

                <div class="ra-detail-wide">
                    <span>Problem Description</span>
                    <strong id="raModalProblem"></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.routingLocationData = {
        cityCorporations: <?php echo json_encode($cityCorporations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        thanas: <?php echo json_encode($thanas, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        wards: <?php echo json_encode($wardsForFilter, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        areas: <?php echo json_encode($areasForFilter, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
    };
</script>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/routing-assignment.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>
