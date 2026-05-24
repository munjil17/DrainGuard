<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "complaints";
$pageTitle = "Complaints Management";
$pageParent = "Central Control";
$pageChild = "Complaints";

$successMessage = "";
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function formatStatus($status)
{
    return ucwords(str_replace("_", " ", (string)$status));
}

function statusClass($status)
{
    $status = strtolower((string)$status);

    if ($status === "submitted") return "status-submitted";
    if ($status === "received") return "status-received";
    if ($status === "pending_verification") return "status-pending";
    if ($status === "verified") return "status-verified";
    if ($status === "assigned_to_team") return "status-assigned";
    if ($status === "in_progress") return "status-progress";
    if ($status === "solved_by_team") return "status-completed";
    if ($status === "inspector_verification") return "status-inspection";
    if ($status === "closed") return "status-solved";
    if ($status === "rejected") return "status-rejected";
    if ($status === "reopened") return "status-reopened";

    return "status-submitted";
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

function makeMediaPublicPath($path)
{
    $path = trim((string)$path);

    if ($path === "") {
        return "";
    }

    $path = str_replace("\\", "/", $path);
    $path = preg_replace("#^\.\./\.\./#", "", $path);
    $path = preg_replace("#^\./#", "", $path);
    $path = ltrim($path, "/");

    return "../../" . $path;
}

function redirectComplaints()
{
    header("Location: /DrainGuard/pages/central/complaints.php");
    exit();
}

function setComplaintFlash($type, $message)
{
    if ($type === "success") {
        $_SESSION["central_complaint_success"] = $message;
    } else {
        $_SESSION["central_complaint_error"] = $message;
    }

    redirectComplaints();
}

function cm_table_exists($conn, $tableName)
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "s", $tableName);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : ["total" => 0];

    mysqli_stmt_close($stmt);

    return (int)$row["total"] > 0;
}

function cm_column_exists($conn, $tableName, $columnName)
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ss", $tableName, $columnName);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : ["total" => 0];

    mysqli_stmt_close($stmt);

    return (int)$row["total"] > 0;
}

if (isset($_SESSION["central_complaint_success"])) {
    $successMessage = $_SESSION["central_complaint_success"];
    unset($_SESSION["central_complaint_success"]);
}

if (isset($_SESSION["central_complaint_error"])) {
    $errorMessage = $_SESSION["central_complaint_error"];
    unset($_SESSION["central_complaint_error"]);
}

/*
|--------------------------------------------------------------------------
| ACCEPT / REJECT ACTION
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $complaintId = (int)($_POST["complaint_id"] ?? 0);
    $action = trim($_POST["action"] ?? "");

    if ($complaintId <= 0 || !in_array($action, ["accept", "reject"], true)) {
        setComplaintFlash("error", "Invalid action.");
    }

    $allowedCurrentStatuses = ["submitted", "reopened"];

    $checkSql = "
        SELECT complaint_status
        FROM complaints
        WHERE complaint_id = ?
        LIMIT 1
    ";

    $checkStmt = mysqli_prepare($conn, $checkSql);

    if (!$checkStmt) {
        setComplaintFlash("error", "Complaint check query failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($checkStmt, "i", $complaintId);
    mysqli_stmt_execute($checkStmt);

    $checkResult = mysqli_stmt_get_result($checkStmt);
    $complaintRow = $checkResult ? mysqli_fetch_assoc($checkResult) : null;

    mysqli_stmt_close($checkStmt);

    if (!$complaintRow) {
        setComplaintFlash("error", "Complaint not found.");
    }

    $currentStatus = strtolower((string)$complaintRow["complaint_status"]);

    if (!in_array($currentStatus, $allowedCurrentStatuses, true)) {
        setComplaintFlash("error", "Only submitted or reopened complaints can be accepted/rejected from this page.");
    }

    $newStatus = ($action === "accept") ? "received" : "rejected";

    $updateSql = "
        UPDATE complaints
        SET complaint_status = ?,
            updated_at = NOW()
        WHERE complaint_id = ?
    ";

    $updateStmt = mysqli_prepare($conn, $updateSql);

    if (!$updateStmt) {
        setComplaintFlash("error", "Complaint update query failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($updateStmt, "si", $newStatus, $complaintId);

    if (!mysqli_stmt_execute($updateStmt)) {
        mysqli_stmt_close($updateStmt);
        setComplaintFlash("error", "Complaint update failed.");
    }

    mysqli_stmt_close($updateStmt);

    if ($action === "accept") {
        setComplaintFlash("success", "Complaint accepted and marked as received.");
    }

    setComplaintFlash("success", "Complaint rejected successfully.");
}

/*
|--------------------------------------------------------------------------
| FETCH COMPLAINTS
|--------------------------------------------------------------------------
*/

$complaints = [];

/*
|--------------------------------------------------------------------------
| Dynamic table compatibility
|--------------------------------------------------------------------------
| Your complaints table now uses:
| - complaints.issue_id
| - complaints.affected_area_id
|
| So this page must not use:
| - c.issue_type
| - c.urgency_level
|--------------------------------------------------------------------------
*/

$issueJoin = "";
$issueSelect = "'Unknown Issue' AS issue_type";

if (
    cm_table_exists($conn, "issue_types") &&
    cm_column_exists($conn, "issue_types", "issue_id") &&
    cm_column_exists($conn, "issue_types", "issue_name")
) {
    $issueJoin = "
        LEFT JOIN issue_types it
            ON c.issue_id = it.issue_id
    ";

    $issueSelect = "COALESCE(it.issue_name, 'Unknown Issue') AS issue_type";
}

$affectedAreaTable = "";

if (cm_table_exists($conn, "affected_areas")) {
    $affectedAreaTable = "affected_areas";
} elseif (cm_table_exists($conn, "affected_area")) {
    $affectedAreaTable = "affected_area";
}

$affectedAreaJoin = "";
$affectedAreaSelect = "'General Area' AS affected_area_name, 'Low' AS urgency_level";

if (
    $affectedAreaTable !== "" &&
    cm_column_exists($conn, $affectedAreaTable, "affected_area_id") &&
    cm_column_exists($conn, $affectedAreaTable, "affected_area_name")
) {
    $priorityColumn = cm_column_exists($conn, $affectedAreaTable, "priority")
        ? "COALESCE(aa.priority, 'Low')"
        : "'Low'";

    $affectedAreaJoin = "
        LEFT JOIN {$affectedAreaTable} aa
            ON c.affected_area_id = aa.affected_area_id
    ";

    $affectedAreaSelect = "
        COALESCE(aa.affected_area_name, 'General Area') AS affected_area_name,
        {$priorityColumn} AS urgency_level
    ";
}

$sql = "
    SELECT
        c.complaint_id,
        c.complaint_code,
        c.user_id,
        c.loc_id,
        c.drain_id,
        c.issue_id,
        c.affected_area_id,

        $issueSelect,
        $affectedAreaSelect,

        c.address_description,
        c.problem_description,
        c.complaint_status,
        c.work_started_at,
        c.parent_complaint_id,
        c.is_repeat_complaint,
        c.submitted_at,
        c.updated_at,

        u.user_name,
        u.user_mail,

        city.city_name,
        cc.city_cor_name,
        t.thana_name,
        w.ward_no,
        w.ward_name,
        a.area_name,

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

    INNER JOIN thanas t
        ON l.thana_id = t.thana_id

    INNER JOIN wards w
        ON l.ward_id = w.ward_id

    INNER JOIN areas a
        ON l.area_id = a.area_id

    $issueJoin

    $affectedAreaJoin

    LEFT JOIN drains d
        ON c.drain_id = d.drain_id

    ORDER BY c.submitted_at DESC
";

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row["media"] = [];
        $complaints[(int)$row["complaint_id"]] = $row;
    }
} else {
    $errorMessage = "Complaint fetch failed: " . mysqli_error($conn);
}

/*
|--------------------------------------------------------------------------
| FETCH COMPLAINT MEDIA
|--------------------------------------------------------------------------
*/

if (count($complaints) > 0) {
    $complaintIds = array_keys($complaints);
    $safeIds = array_map("intval", $complaintIds);
    $idList = implode(",", $safeIds);

    $mediaSql = "
        SELECT
            media_id,
            complaint_id,
            media_type,
            media_path,
            original_name,
            file_size,
            mime_type,
            uploaded_at
        FROM complaint_media
        WHERE complaint_id IN ($idList)
        ORDER BY complaint_id ASC, media_id ASC
    ";

    $mediaResult = mysqli_query($conn, $mediaSql);

    if ($mediaResult) {
        while ($media = mysqli_fetch_assoc($mediaResult)) {
            $complaintId = (int)$media["complaint_id"];

            if (isset($complaints[$complaintId])) {
                $complaints[$complaintId]["media"][] = [
                    "media_id" => (int)$media["media_id"],
                    "type" => (string)$media["media_type"],
                    "path" => makeMediaPublicPath($media["media_path"]),
                    "original_name" => (string)($media["original_name"] ?? ""),
                    "file_size" => (int)($media["file_size"] ?? 0),
                    "mime_type" => (string)($media["mime_type"] ?? "")
                ];
            }
        }
    }
}

$complaints = array_values($complaints);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>Complaints Management | DrainGuard</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">
    <link rel="stylesheet" href="../../css/central/footer.css">
    <link rel="stylesheet" href="../../css/central/complaints.css">
    <link rel="stylesheet" href="../../css/central/centralTextFix.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="cm-page">

            <div class="cm-header">
                <div>
                    <h1>Complaints Management</h1>
                    <p>Review newly submitted complaints and mark accepted cases as received.</p>
                </div>

                <div class="cm-count-card">
                    <span><?php echo count($complaints); ?></span>
                    <small>Total Complaints</small>
                </div>
            </div>

            <?php if ($successMessage !== ""): ?>
                <div class="cm-alert cm-success">
                    <i class="bi bi-check-circle"></i>
                    <?php echo safeText($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ""): ?>
                <div class="cm-alert cm-error">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo safeText($errorMessage); ?>
                </div>
            <?php endif; ?>

            <div class="cm-toolbar">
                <div class="cm-search-box">
                    <i class="bi bi-search"></i>
                    <input
                        type="text"
                        id="complaintSearch"
                        placeholder="Search complaints by ID, location, issue, citizen, or description..."
                    >
                </div>

                <button type="button" class="cm-filter-btn" id="filterToggleBtn">
                    <i class="bi bi-funnel"></i>
                    Filter
                </button>
            </div>

            <div class="cm-filter-panel" id="filterPanel">
                <div class="cm-filter-group">
                    <label>Status</label>
                    <select id="statusFilter">
                        <option value="all">All Status</option>
                        <option value="submitted">Submitted</option>
                        <option value="received">Received</option>
                        <option value="pending_verification">Pending Verification</option>
                        <option value="verified">Verified</option>
                        <option value="assigned_to_team">Assigned to Team</option>
                        <option value="in_progress">In Progress</option>
                        <option value="solved_by_team">Solved by Team</option>
                        <option value="inspector_verification">Inspector Verification</option>
                        <option value="closed">Closed</option>
                        <option value="rejected">Rejected</option>
                        <option value="reopened">Reopened</option>
                    </select>
                </div>

                <div class="cm-filter-group">
                    <label>Priority</label>
                    <select id="priorityFilter">
                        <option value="all">All Priority</option>
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High">High</option>
                        <option value="Critical">Critical</option>
                    </select>
                </div>

                <button type="button" class="cm-clear-btn" id="clearFilterBtn">
                    Clear
                </button>
            </div>

            <div class="cm-tabs">
                <button type="button" class="cm-tab active" data-filter="all">All</button>
                <button type="button" class="cm-tab" data-filter="submitted">Submitted</button>
                <button type="button" class="cm-tab" data-filter="received">Received</button>
                <button type="button" class="cm-tab" data-priority="Critical">Emergency</button>
                <button type="button" class="cm-tab" data-filter="rejected">Rejected</button>
            </div>

            <div class="cm-table-card">

                <?php if (count($complaints) > 0): ?>

                    <div class="cm-table-wrap">
                        <table class="cm-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Ward</th>
                                    <th>Area</th>
                                    <th>Type</th>
                                    <th>Affected Area</th>
                                    <th>Evidence</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($complaints as $complaint): ?>
                                    <?php
                                        $complaintId = (int)$complaint["complaint_id"];
                                        $complaintCode = safeText($complaint["complaint_code"]);
                                        $issueType = safeText($complaint["issue_type"]);
                                        $affectedAreaName = safeText($complaint["affected_area_name"] ?? "General Area");

                                        $rawProblemDescription = (string)$complaint["problem_description"];

                                        $shortTitleRaw = mb_strlen($rawProblemDescription) > 60
                                            ? mb_substr($rawProblemDescription, 0, 60) . "..."
                                            : $rawProblemDescription;

                                        $shortTitle = safeText($shortTitleRaw);

                                        $ward = !empty($complaint["ward_name"])
                                            ? safeText($complaint["ward_name"])
                                            : safeText("Ward " . $complaint["ward_no"]);

                                        $area = safeText($complaint["area_name"]);
                                        $priority = safeText($complaint["urgency_level"] ?? "Low");

                                        $rawStatus = strtolower((string)$complaint["complaint_status"]);
                                        $status = safeText($rawStatus);
                                        $statusText = safeText(formatStatus($rawStatus));

                                        $date = date("M d", strtotime($complaint["submitted_at"]));
                                        $fullDate = date("M d, Y h:i A", strtotime($complaint["submitted_at"]));

                                        $canCentralAct = in_array($rawStatus, ["submitted", "reopened"], true);

                                        $mediaItems = $complaint["media"] ?? [];
                                        $mediaCount = count($mediaItems);
                                        $mediaJson = json_encode($mediaItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

                                        $drainText = "Not linked";
                                        if (!empty($complaint["drain_code"]) || !empty($complaint["drain_name"])) {
                                            $drainText = trim(($complaint["drain_code"] ?? "") . " - " . ($complaint["drain_name"] ?? ""), " -");
                                        }
                                    ?>

                                    <tr
                                        class="cm-row"
                                        data-code="<?php echo strtolower($complaintCode); ?>"
                                        data-title="<?php echo strtolower($shortTitle); ?>"
                                        data-user="<?php echo strtolower(safeText($complaint["user_name"])); ?>"
                                        data-ward="<?php echo strtolower($ward); ?>"
                                        data-area="<?php echo strtolower($area); ?>"
                                        data-type="<?php echo strtolower($issueType); ?>"
                                        data-status="<?php echo $status; ?>"
                                        data-priority="<?php echo $priority; ?>"
                                    >
                                        <td>
                                            <span class="cm-code"><?php echo $complaintCode; ?></span>
                                        </td>

                                        <td>
                                            <div class="cm-title">
                                                <strong><?php echo $shortTitle; ?></strong>
                                                <small><?php echo safeText($complaint["user_name"]); ?></small>
                                            </div>
                                        </td>

                                        <td><?php echo $ward; ?></td>

                                        <td><?php echo $area; ?></td>

                                        <td>
                                            <span class="cm-type-badge">
                                                <?php echo $issueType; ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="cm-type-badge">
                                                <?php echo $affectedAreaName; ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="cm-media-count">
                                                <i class="bi bi-paperclip"></i>
                                                <?php echo $mediaCount; ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="cm-priority <?php echo urgencyClass($priority); ?>">
                                                <?php echo $priority; ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="cm-status <?php echo statusClass($status); ?>">
                                                <?php echo $statusText; ?>
                                            </span>
                                        </td>

                                        <td><?php echo safeText($date); ?></td>

                                        <td>
                                            <div class="cm-actions">

                                                <?php if ($canCentralAct): ?>

                                                    <form method="POST" action="complaints.php" class="cm-action-form">
                                                        <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
                                                        <input type="hidden" name="action" value="accept">

                                                        <button type="submit" class="cm-icon-btn accept" title="Accept / Mark as Received">
                                                            <i class="bi bi-check-circle"></i>
                                                        </button>
                                                    </form>

                                                    <form method="POST" action="complaints.php" class="cm-action-form">
                                                        <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
                                                        <input type="hidden" name="action" value="reject">

                                                        <button type="submit" class="cm-icon-btn reject" title="Reject">
                                                            <i class="bi bi-x-lg"></i>
                                                        </button>
                                                    </form>

                                                <?php endif; ?>

                                                <button
                                                    type="button"
                                                    class="cm-details-btn"
                                                    data-code="<?php echo $complaintCode; ?>"
                                                    data-title="<?php echo $shortTitle; ?>"
                                                    data-user="<?php echo safeText($complaint["user_name"]); ?>"
                                                    data-email="<?php echo safeText($complaint["user_mail"]); ?>"
                                                    data-type="<?php echo $issueType; ?>"
                                                    data-priority="<?php echo $priority; ?>"
                                                    data-status="<?php echo $statusText; ?>"
                                                    data-city="<?php echo safeText($complaint["city_name"]); ?>"
                                                    data-corporation="<?php echo safeText($complaint["city_cor_name"]); ?>"
                                                    data-thana="<?php echo safeText($complaint["thana_name"]); ?>"
                                                    data-ward="<?php echo $ward; ?>"
                                                    data-area="<?php echo $area; ?>"
                                                    data-drain="<?php echo safeText($drainText); ?>"
                                                    data-address="<?php echo safeText($complaint["address_description"]); ?>"
                                                    data-problem="<?php echo safeText($complaint["problem_description"]); ?>"
                                                    data-date="<?php echo safeText($fullDate); ?>"
                                                    data-media="<?php echo safeText($mediaJson ?: "[]"); ?>"
                                                >
                                                    View Details
                                                    <i class="bi bi-arrow-right"></i>
                                                </button>

                                            </div>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php else: ?>

                    <div class="cm-empty">
                        <i class="bi bi-inbox"></i>
                        <h2>No complaints found</h2>
                        <p>Citizen submitted complaints will appear here.</p>
                    </div>

                <?php endif; ?>

            </div>

        </section>

        <?php include "../../includes/central/footer.php"; ?>

    </main>

</div>

<div class="cm-modal-overlay" id="detailsModal">
    <div class="cm-modal">

        <div class="cm-modal-header">
            <div>
                <h2 id="modalTitle">Complaint Details</h2>
                <p id="modalCode"></p>
            </div>

            <button type="button" id="modalCloseBtn" class="cm-modal-close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="cm-modal-body">

            <div class="cm-detail-grid">
                <div><span>Citizen</span><strong id="modalUser"></strong></div>
                <div><span>Email</span><strong id="modalEmail"></strong></div>
                <div><span>Issue Type</span><strong id="modalType"></strong></div>
                <div><span>Priority</span><strong id="modalPriority"></strong></div>
                <div><span>Status</span><strong id="modalStatus"></strong></div>
                <div><span>Date</span><strong id="modalDate"></strong></div>
                <div><span>City Corporation</span><strong id="modalCorporation"></strong></div>
                <div><span>Thana</span><strong id="modalThana"></strong></div>
                <div><span>Ward</span><strong id="modalWard"></strong></div>
                <div><span>Area</span><strong id="modalArea"></strong></div>
                <div><span>Drain</span><strong id="modalDrain"></strong></div>
            </div>

            <div class="cm-modal-section">
                <h4>Address Description</h4>
                <p id="modalAddress"></p>
            </div>

            <div class="cm-modal-section">
                <h4>Problem Description</h4>
                <p id="modalProblem"></p>
            </div>

            <div class="cm-modal-section" id="modalMediaWrap">
                <h4>Uploaded Evidence</h4>
                <div class="cm-media-gallery" id="modalMediaGallery"></div>
            </div>

        </div>

    </div>
</div>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/complaints.js"></script>

</body>
</html>