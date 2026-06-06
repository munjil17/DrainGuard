<?php
// C:\xampp\htdocs\DrainGuard\pages\citizen\track-complaint.php

$activePage = "track-complaint";
$pageTitle = "Track Complaint";
$pageParent = "Citizen";
$pageChild = "Track Complaint";

require_once "../../config.php";
require_login(["citizen"]);
require_once "../../commentSystem/discussion_logic.php";

$userId = (int)($_SESSION["user_id"] ?? 0);
$searchCode = trim($_GET["code"] ?? "");

$complaint = null;
$mediaFiles = [];
$statusLogs = [];
$decision = null;
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function dgStartsWith($string, $prefix)
{
    return substr($string, 0, strlen($prefix)) === $prefix;
}

function normalizeComplaintStatus($status)
{
    $status = strtolower(trim((string)$status));

    $map = [
        "assigned" => "team_assigned",
        "assigned_to_team" => "team_assigned",
        "completed" => "solved_by_team",
        "completed_by_team" => "solved_by_team",
        "team_completed" => "solved_by_team",
        "under_inspection" => "inspector_verification",
        "pending_inspection" => "inspector_verification",
        "solved" => "closed",
        "resolved" => "closed",
        "verified" => "verified_by_ward",
        "ward_verified" => "verified_by_ward",
        "rejected" => "rejected_by_central",
        "objection_rejected" => "final_rejected"
    ];

    return $map[$status] ?? $status;
}

function normalizeWorkStatus($status)
{
    $status = strtolower(trim((string)$status));

    $map = [
        "assigned" => "team_assigned",
        "started" => "in_progress",
        "completed" => "solved_by_team"
    ];

    return $map[$status] ?? $status;
}

function formatStatus($status)
{
    $status = normalizeComplaintStatus($status);

    $labels = [
        "submitted" => "Submitted",
        "received" => "Received",
        "pending_verification" => "Pending Verification",
        "verified_by_ward" => "Verified by Ward Officer",
        "rejected_by_central" => "Rejected by Central Officer",
        "rejected_by_ward" => "Rejected by Ward Officer",
        "duplicate" => "Duplicate",
        "team_assigned" => "Assigned to Team",
        "in_progress" => "In Progress",
        "solved_by_team" => "Solved by Team",
        "inspector_verification" => "Inspector Verification",
        "closed" => "Closed / Solved",
        "reopened" => "Reopened",
        "disputed" => "Disputed",
        "final_rejected" => "Final Rejected",
        "n/a" => "N/A"
    ];

    return $labels[$status] ?? ucwords(str_replace("_", " ", (string)$status));
}

function statusClass($status)
{
    $status = normalizeComplaintStatus($status);

    $classes = [
        "submitted" => "status-submitted",
        "received" => "status-received",
        "pending_verification" => "status-pending-verification",
        "verified_by_ward" => "status-verified-by-ward",
        "rejected_by_central" => "status-rejected-by-central",
        "rejected_by_ward" => "status-rejected-by-ward",
        "duplicate" => "status-duplicate",
        "team_assigned" => "status-team-assigned",
        "in_progress" => "status-in-progress",
        "solved_by_team" => "status-solved-by-team",
        "inspector_verification" => "status-inspector-verification",
        "closed" => "status-closed",
        "reopened" => "status-reopened",
        "disputed" => "status-disputed",
        "final_rejected" => "status-final-rejected"
    ];

    return $classes[$status] ?? "status-submitted";
}

function timelineIconClass($status)
{
    $status = normalizeComplaintStatus($status);

    if ($status === "rejected_by_central" || $status === "rejected_by_ward" || $status === "final_rejected") {
        return "rejected";
    }

    if ($status === "duplicate") {
        return "duplicate";
    }

    if ($status === "reopened") {
        return "reopened";
    }

    if ($status === "disputed") {
        return "disputed";
    }

    if ($status === "closed") {
        return "closed";
    }

    return "";
}

function timelineDescription($status)
{
    $status = normalizeComplaintStatus($status);

    $descriptions = [
        "submitted" => "Citizen submitted the complaint successfully.",
        "received" => "Central officer accepted and received the complaint.",
        "pending_verification" => "Complaint was sent to ward officer for verification.",
        "verified_by_ward" => "Ward officer verified the complaint.",
        "rejected_by_central" => "Central officer rejected this complaint.",
        "rejected_by_ward" => "Ward officer rejected this complaint.",
        "duplicate" => "This complaint was marked as duplicate.",
        "team_assigned" => "Maintenance team was assigned to solve the complaint.",
        "in_progress" => "Maintenance team started the work.",
        "solved_by_team" => "Maintenance team submitted completion proof.",
        "inspector_verification" => "Inspector verification is pending.",
        "closed" => "Complaint has been solved and closed.",
        "reopened" => "Complaint has been reopened for further action.",
        "disputed" => "Citizen objection is under review.",
        "final_rejected" => "Final decision rejected this complaint."
    ];

    return $descriptions[$status] ?? "Complaint status updated.";
}

function formatFileSize($bytes)
{
    $bytes = (int)$bytes;

    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1) . " MB";
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . " KB";
    }

    return $bytes . " B";
}

function makeMediaPath($path)
{
    $path = trim((string)$path);

    if ($path === "") {
        return "";
    }

    $path = str_replace("\\", "/", $path);

    if (preg_match("/^https?:\/\//i", $path)) {
        return $path;
    }

    if (dgStartsWith($path, "../../")) {
        return $path;
    }

    if (dgStartsWith($path, "/")) {
        return $path;
    }

    if (dgStartsWith($path, "assets/")) {
        return "../../" . $path;
    }

    if (dgStartsWith($path, "uploads/")) {
        return "../../assets/" . $path;
    }

    if (!strpos($path, "/")) {
        return "../../assets/uploads/complaints/" . $path;
    }

    return "../../" . ltrim($path, "/");
}

/*
|--------------------------------------------------------------------------
| Final Timeline Path Logic
|--------------------------------------------------------------------------
| This function defines the visible workflow path.
| The current complaint_status controls the path.
|--------------------------------------------------------------------------
*/
function buildFallbackTimeline($currentStatus, $wasReopened = false)
{
    $currentStatus = normalizeComplaintStatus($currentStatus);

    $normalPath = [
        "submitted",
        "received",
        "pending_verification",
        "verified_by_ward",
        "team_assigned",
        "in_progress",
        "solved_by_team",
        "inspector_verification",
        "closed"
    ];

    $centralRejectPath = [
        "submitted",
        "rejected_by_central"
    ];

    $wardRejectPath = [
        "submitted",
        "received",
        "pending_verification",
        "rejected_by_ward"
    ];

    $duplicatePath = [
        "submitted",
        "received",
        "pending_verification",
        "duplicate"
    ];

    $reopenPath = [
        "submitted",
        "received",
        "pending_verification",
        "verified_by_ward",
        "reopened",
        "team_assigned",
        "in_progress",
        "solved_by_team",
        "inspector_verification",
        "closed"
    ];

    $finalRejectedPath = [
        "submitted",
        "received",
        "pending_verification",
        "verified_by_ward",
        "team_assigned",
        "in_progress",
        "solved_by_team",
        "inspector_verification",
        "closed",
        "disputed",
        "final_rejected"
    ];

    if ($currentStatus === "rejected_by_central") {
        return $centralRejectPath;
    }

    if ($currentStatus === "rejected_by_ward") {
        return $wardRejectPath;
    }

    if ($currentStatus === "duplicate") {
        return $duplicatePath;
    }

    $basePath = $wasReopened ? $reopenPath : $normalPath;

    if ($currentStatus === "disputed") {
        $path = $basePath;
        $path[] = "disputed";
        return $path;
    }

    if ($currentStatus === "final_rejected") {
        $path = $basePath;
        $path[] = "disputed";
        $path[] = "final_rejected";
        return $path;
    }

    $index = array_search($currentStatus, $basePath, true);

    if ($index === false) {
        return ["submitted"];
    }

    return array_slice($basePath, 0, $index + 1);
}

if ($searchCode !== "") {
    $sql = "
        SELECT
            c.complaint_id,
            c.complaint_code,
            c.issue_id,
            c.affected_area_id,
            c.address_description,
            c.problem_description,
            c.complaint_status,
            c.work_started_at,
            c.submitted_at,
            c.updated_at,
            c.closed_at,

            i.issue_name,
            aa.affected_area_name,

            city.city_name,
            cc.city_cor_name,
            t.thana_name,
            w.ward_no,
            w.ward_name,
            a.area_name,

            ca.assignment_id,
            ca.assignment_status,
            ca.assigned_at,

            mu.work_status,
            mu.work_note,
            mu.proof_file_path,
            mu.proof_file_type,
            mu.started_at,
            mu.completed_at,
            mu.delayed_at,
            mu.delay_reason,
            mu.created_at AS work_update_created_at,
            mu.updated_at AS work_update_updated_at

        FROM complaints c

        LEFT JOIN issues i
            ON c.issue_id = i.issue_id

        LEFT JOIN affected_areas aa
            ON c.affected_area_id = aa.affected_area_id

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

        LEFT JOIN (
            SELECT ca1.*
            FROM complaint_assignments ca1
            INNER JOIN (
                SELECT complaint_id, MAX(assignment_id) AS latest_assignment_id
                FROM complaint_assignments
                GROUP BY complaint_id
            ) latest_ca
                ON ca1.assignment_id = latest_ca.latest_assignment_id
        ) ca
            ON c.complaint_id = ca.complaint_id

        LEFT JOIN (
            SELECT mu1.*
            FROM maintenance_updates mu1
            INNER JOIN (
                SELECT complaint_id, MAX(update_id) AS latest_update_id
                FROM maintenance_updates
                GROUP BY complaint_id
            ) latest_mu
                ON mu1.update_id = latest_mu.latest_update_id
        ) mu
            ON c.complaint_id = mu.complaint_id

        WHERE c.complaint_code = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $searchCode);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) === 1) {
            $complaint = mysqli_fetch_assoc($result);

            $mediaSql = "
                SELECT
                    media_id,
                    complaint_id,
                    media_type,
                    media_path,
                    original_name,
                    file_size,
                    mime_type
                FROM complaint_media
                WHERE complaint_id = ?
                ORDER BY media_id ASC
            ";

            $mediaStmt = mysqli_prepare($conn, $mediaSql);

            if ($mediaStmt) {
                mysqli_stmt_bind_param($mediaStmt, "i", $complaint["complaint_id"]);
                mysqli_stmt_execute($mediaStmt);

                $mediaResult = mysqli_stmt_get_result($mediaStmt);

                if ($mediaResult) {
                    while ($media = mysqli_fetch_assoc($mediaResult)) {
                        $media["display_path"] = makeMediaPath($media["media_path"] ?? "");
                        $mediaFiles[] = $media;
                    }
                }

                mysqli_stmt_close($mediaStmt);
            }

            $logSql = "
                SELECT
                    log_id,
                    old_status,
                    new_status,
                    action_by_user_id,
                    action_by_role,
                    remarks,
                    created_at
                FROM complaint_status_logs
                WHERE complaint_id = ?
                ORDER BY created_at ASC, log_id ASC
            ";

            $logStmt = mysqli_prepare($conn, $logSql);

            if ($logStmt) {
                mysqli_stmt_bind_param($logStmt, "i", $complaint["complaint_id"]);
                mysqli_stmt_execute($logStmt);

                $logResult = mysqli_stmt_get_result($logStmt);

                if ($logResult) {
                    while ($log = mysqli_fetch_assoc($logResult)) {
                        $log["new_status"] = normalizeComplaintStatus($log["new_status"]);
                        $log["old_status"] = normalizeComplaintStatus($log["old_status"]);
                        $statusLogs[] = $log;
                    }
                }

                mysqli_stmt_close($logStmt);
            }

            $decisionSql = "
                SELECT
                    decision_id,
                    decision_type,
                    reason,
                    reference_complaint_id,
                    created_at
                FROM complaint_decisions
                WHERE complaint_id = ?
                ORDER BY created_at DESC, decision_id DESC
                LIMIT 1
            ";

            $decisionStmt = mysqli_prepare($conn, $decisionSql);

            if ($decisionStmt) {
                mysqli_stmt_bind_param($decisionStmt, "i", $complaint["complaint_id"]);
                mysqli_stmt_execute($decisionStmt);

                $decisionResult = mysqli_stmt_get_result($decisionStmt);

                if ($decisionResult && mysqli_num_rows($decisionResult) === 1) {
                    $decision = mysqli_fetch_assoc($decisionResult);
                }

                mysqli_stmt_close($decisionStmt);
            }
        } else {
            $errorMessage = "No complaint found with this ID under your account.";
        }

        mysqli_stmt_close($stmt);
    } else {
        $errorMessage = "Unable to track complaint right now. Please try again.";
    }
}

/*
|--------------------------------------------------------------------------
| Timeline Construction
|--------------------------------------------------------------------------
| Fixed:
| Logs are used only for timestamp/remarks.
| Logs do not decide visible steps.
| Visible steps always come from complaints.complaint_status path.
|--------------------------------------------------------------------------
*/
$currentStatus = $complaint ? normalizeComplaintStatus($complaint["complaint_status"] ?? "submitted") : "";
$timelineStatuses = [];
$statusLogMap = [];

if ($complaint) {
    foreach ($statusLogs as $log) {
        $logStatus = normalizeComplaintStatus($log["new_status"] ?? "");

        if ($logStatus === "") {
            continue;
        }

        if (!isset($statusLogMap[$logStatus])) {
            $statusLogMap[$logStatus] = $log;
        }
    }

    $wasReopened = isset($statusLogMap["reopened"]) || $currentStatus === "reopened";
    $timelineStatuses = buildFallbackTimeline($currentStatus, $wasReopened);

    if (!isset($statusLogMap["submitted"]) && !empty($complaint["submitted_at"])) {
        $statusLogMap["submitted"] = [
            "new_status" => "submitted",
            "remarks" => timelineDescription("submitted"),
            "created_at" => $complaint["submitted_at"]
        ];
    }

    if (!isset($statusLogMap[$currentStatus]) && !empty($complaint["updated_at"])) {
        $statusLogMap[$currentStatus] = [
            "new_status" => $currentStatus,
            "remarks" => timelineDescription($currentStatus),
            "created_at" => $complaint["updated_at"]
        ];
    }
}

$hasDiscussionAccess = false;
if ($complaint) {
    $context = cs_get_discussion_context($conn, $complaint["complaint_id"]);
    $hasDiscussionAccess = cs_has_discussion_access($context, $userId, 'citizen');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Track Complaint | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">
    <link rel="stylesheet" href="../../css/citizen/track-complaint.css">
    <link rel="stylesheet" href="../../css/commentSystem/commentSystem.css">
    <link rel="stylesheet" href="../../css/citizen/citizenTextFix.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="citizen">

<div class="citizen-layout">

    <?php include "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main main-content">

        <?php include "../../includes/citizen/topbar.php"; ?>

        <section class="tc-page">

            <div class="tc-header">
                <h1>Track Complaint</h1>
                <p>Enter complaint ID to track its actual workflow progress</p>
            </div>

            <form class="tc-search-card" method="GET" action="track-complaint.php">
                <div class="tc-search-box">
                    <i class="bi bi-search"></i>
                    <input
                        type="text"
                        name="code"
                        value="<?php echo safeText($searchCode); ?>"
                        placeholder="Enter Complaint ID, e.g. DG-20260523-81881"
                        required
                    >
                </div>

                <button type="submit">
                    Track
                    <i class="bi bi-arrow-right"></i>
                </button>
            </form>

            <?php if ($errorMessage !== ""): ?>
                <div class="tc-alert tc-error">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo safeText($errorMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($searchCode === "" && !$complaint): ?>
                <div class="tc-empty-card">
                    <i class="bi bi-search"></i>
                    <h2>Track your complaint</h2>
                    <p>Enter your complaint ID to view current progress, timeline, location, and uploaded evidence.</p>
                </div>
            <?php endif; ?>

            <?php if ($complaint): ?>

                <div class="tc-summary-card"
                     data-complaint-id="<?php echo (int)$complaint["complaint_id"]; ?>"
                     data-complaint-code="<?php echo safeText($complaint["complaint_code"]); ?>"
                     data-notification-target="<?php echo safeText($complaint["complaint_code"]); ?>">
                    <div>
                        <span>Complaint ID</span>
                        <strong><?php echo safeText($complaint["complaint_code"]); ?></strong>
                    </div>

                    <div>
                        <span>Issue Type</span>
                        <strong><?php echo safeText($complaint["issue_name"] ?? "N/A"); ?></strong>
                    </div>

                    <div>
                        <span>Affected Area</span>
                        <strong><?php echo safeText($complaint["affected_area_name"] ?? "N/A"); ?></strong>
                    </div>

                    <div>
                        <span>Status</span>
                        <strong class="tc-status <?php echo statusClass($currentStatus); ?>">
                            <?php echo safeText(formatStatus($currentStatus)); ?>
                        </strong>
                    </div>
                </div>

                <?php if ($hasDiscussionAccess): ?>
                    <div style="margin-bottom: 24px; text-align: right;">
                        <a href="discussion.php?id=<?php echo (int)$complaint['complaint_id']; ?>" class="cm-discussion-btn" style="display: inline-flex; align-items: center; gap: 8px; background: #2563eb; color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 500; transition: background 0.2s;">
                            <i class="bi bi-chat-dots"></i> Open Discussion
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ($currentStatus === "rejected_by_central"): ?>
                    <div class="tc-alert tc-error">
                        <i class="bi bi-x-circle"></i>
                        This complaint has been rejected by Central Officer.
                    </div>
                <?php endif; ?>

                <?php if ($currentStatus === "rejected_by_ward"): ?>
                    <div class="tc-alert tc-error">
                        <i class="bi bi-x-circle"></i>
                        This complaint has been rejected by Ward Officer.
                    </div>
                <?php endif; ?>

                <?php if ($currentStatus === "duplicate"): ?>
                    <div class="tc-alert tc-duplicate">
                        <i class="bi bi-files"></i>
                        This complaint has been marked as duplicate.
                    </div>
                <?php endif; ?>

                <?php if ($currentStatus === "reopened"): ?>
                    <div class="tc-alert tc-warning">
                        <i class="bi bi-arrow-clockwise"></i>
                        This complaint has been reopened for further action.
                    </div>
                <?php endif; ?>

                <?php if ($currentStatus === "disputed"): ?>
                    <div class="tc-alert tc-warning">
                        <i class="bi bi-exclamation-diamond"></i>
                        Citizen objection is under review.
                    </div>
                <?php endif; ?>

                <?php if ($currentStatus === "final_rejected"): ?>
                    <div class="tc-alert tc-error">
                        <i class="bi bi-shield-x"></i>
                        This complaint has been finally rejected after review.
                    </div>
                <?php endif; ?>

                <?php if ($decision): ?>
                    <div class="tc-decision-card">
                        <h2>Decision Reason</h2>

                        <div class="tc-decision-grid">
                            <div>
                                <span>Decision Type</span>
                                <strong><?php echo safeText(ucwords(str_replace("_", " ", $decision["decision_type"] ?? "Decision"))); ?></strong>
                            </div>

                            <div>
                                <span>Decision Date</span>
                                <strong>
                                    <?php
                                        echo !empty($decision["created_at"])
                                            ? safeText(date("M d, Y h:i A", strtotime($decision["created_at"])))
                                            : "N/A";
                                    ?>
                                </strong>
                            </div>

                            <?php if (!empty($decision["reference_complaint_id"])): ?>
                                <div>
                                    <span>Reference Complaint</span>
                                    <strong>#<?php echo (int)$decision["reference_complaint_id"]; ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="tc-description-block">
                            <h3>Reason</h3>
                            <p><?php echo safeText($decision["reason"] ?? "No reason recorded."); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="tc-card">
                    <div class="tc-card-header">
                        <h2>Progress Timeline</h2>
                        <p>This timeline shows the actual completed workflow path only.</p>
                    </div>

                    <div class="tc-timeline">

                        <?php foreach ($timelineStatuses as $index => $status): ?>
                            <?php
                                $isLast = ($index === count($timelineStatuses) - 1);
                                $stepClass = $isLast ? "active" : "completed";
                                $specialClass = timelineIconClass($status);

                                if ($specialClass !== "" && $isLast) {
                                    $stepClass .= " " . $specialClass;
                                }

                                $logData = $statusLogMap[$status] ?? null;
                                $remarks = $logData["remarks"] ?? "";
                                $createdAt = $logData["created_at"] ?? "";
                            ?>

                            <div class="tc-timeline-item <?php echo safeText($stepClass); ?>">
                                <div class="tc-timeline-icon">
                                    <?php if (strpos($stepClass, "rejected") !== false): ?>
                                        <i class="bi bi-x-lg"></i>
                                    <?php elseif (strpos($stepClass, "duplicate") !== false): ?>
                                        <i class="bi bi-files"></i>
                                    <?php elseif (strpos($stepClass, "reopened") !== false): ?>
                                        <i class="bi bi-arrow-clockwise"></i>
                                    <?php elseif (strpos($stepClass, "disputed") !== false): ?>
                                        <i class="bi bi-exclamation-diamond"></i>
                                    <?php elseif (strpos($stepClass, "closed") !== false): ?>
                                        <i class="bi bi-check2-circle"></i>
                                    <?php elseif (!$isLast): ?>
                                        <i class="bi bi-check-lg"></i>
                                    <?php else: ?>
                                        <i class="bi bi-clock"></i>
                                    <?php endif; ?>
                                </div>

                                <div class="tc-timeline-content">
                                    <h3><?php echo safeText(formatStatus($status)); ?></h3>
                                    <p>
                                        <?php
                                            echo safeText($remarks !== "" ? $remarks : timelineDescription($status));

                                            if ($createdAt !== "") {
                                                echo "<br><small>" . safeText(date("M d, Y h:i A", strtotime($createdAt))) . "</small>";
                                            }
                                        ?>
                                    </p>
                                </div>
                            </div>

                        <?php endforeach; ?>

                    </div>
                </div>

                <div class="tc-details-card">
                    <h2>Complaint Details</h2>

                    <div class="tc-details-grid">
                        <div>
                            <span>City</span>
                            <strong><?php echo safeText($complaint["city_name"] ?? "N/A"); ?></strong>
                        </div>

                        <div>
                            <span>City Corporation</span>
                            <strong><?php echo safeText($complaint["city_cor_name"] ?? "N/A"); ?></strong>
                        </div>

                        <div>
                            <span>Thana</span>
                            <strong><?php echo safeText($complaint["thana_name"] ?? "N/A"); ?></strong>
                        </div>

                        <div>
                            <span>Ward</span>
                            <strong><?php echo safeText("Ward " . ($complaint["ward_no"] ?? "N/A")); ?></strong>
                        </div>

                        <div>
                            <span>Area</span>
                            <strong><?php echo safeText($complaint["area_name"] ?? "N/A"); ?></strong>
                        </div>

                        <div>
                            <span>Affected Area</span>
                            <strong><?php echo safeText($complaint["affected_area_name"] ?? "N/A"); ?></strong>
                        </div>

                        <div>
                            <span>Issue Type</span>
                            <strong><?php echo safeText($complaint["issue_name"] ?? "N/A"); ?></strong>
                        </div>

                        <div>
                            <span>Submitted At</span>
                            <strong>
                                <?php
                                    echo !empty($complaint["submitted_at"])
                                        ? safeText(date("M d, Y h:i A", strtotime($complaint["submitted_at"])))
                                        : "N/A";
                                ?>
                            </strong>
                        </div>

                        <div>
                            <span>Last Updated</span>
                            <strong>
                                <?php
                                    echo !empty($complaint["updated_at"])
                                        ? safeText(date("M d, Y h:i A", strtotime($complaint["updated_at"])))
                                        : "Not updated";
                                ?>
                            </strong>
                        </div>

                        <div>
                            <span>Closed At</span>
                            <strong>
                                <?php
                                    echo !empty($complaint["closed_at"])
                                        ? safeText(date("M d, Y h:i A", strtotime($complaint["closed_at"])))
                                        : "Not closed";
                                ?>
                            </strong>
                        </div>

                        <div>
                            <span>Assignment Status</span>
                            <strong>
                                <?php
                                    $assignmentStatus = $complaint["assignment_status"] ?? "N/A";
                                    echo safeText(formatStatus(normalizeComplaintStatus($assignmentStatus)));
                                ?>
                            </strong>
                        </div>

                        <div>
                            <span>Maintenance Status</span>
                            <strong>
                                <?php
                                    $workStatus = !empty($complaint["work_status"])
                                        ? normalizeWorkStatus($complaint["work_status"])
                                        : "N/A";

                                    echo safeText(formatStatus($workStatus));
                                ?>
                            </strong>
                        </div>
                    </div>

                    <div class="tc-description-block">
                        <h3>Address Description</h3>
                        <p><?php echo safeText($complaint["address_description"] ?? ""); ?></p>
                    </div>

                    <div class="tc-description-block">
                        <h3>Problem Description</h3>
                        <p><?php echo safeText($complaint["problem_description"] ?? ""); ?></p>
                    </div>
                </div>

                <?php if (!empty($complaint["work_status"]) || !empty($complaint["work_note"]) || !empty($complaint["delay_reason"])): ?>
                    <div class="tc-work-update-card">
                        <h2>Maintenance Update</h2>

                        <div class="tc-work-grid">
                            <div>
                                <span>Work Status</span>
                                <strong>
                                    <?php
                                        $workStatus = normalizeWorkStatus($complaint["work_status"] ?? "N/A");
                                        echo safeText(formatStatus($workStatus));
                                    ?>
                                </strong>
                            </div>

                            <div>
                                <span>Started At</span>
                                <strong>
                                    <?php
                                        echo !empty($complaint["started_at"])
                                            ? safeText(date("M d, Y h:i A", strtotime($complaint["started_at"])))
                                            : "N/A";
                                    ?>
                                </strong>
                            </div>

                            <div>
                                <span>Completed At</span>
                                <strong>
                                    <?php
                                        echo !empty($complaint["completed_at"])
                                            ? safeText(date("M d, Y h:i A", strtotime($complaint["completed_at"])))
                                            : "N/A";
                                    ?>
                                </strong>
                            </div>

                            <div>
                                <span>Delayed At</span>
                                <strong>
                                    <?php
                                        echo !empty($complaint["delayed_at"])
                                            ? safeText(date("M d, Y h:i A", strtotime($complaint["delayed_at"])))
                                            : "N/A";
                                    ?>
                                </strong>
                            </div>
                        </div>

                        <?php if (!empty($complaint["work_note"])): ?>
                            <div class="tc-work-note">
                                <span>Work Note</span>
                                <p><?php echo safeText($complaint["work_note"]); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($complaint["delay_reason"])): ?>
                            <div class="tc-work-note">
                                <span>Delay Reason</span>
                                <p><?php echo safeText($complaint["delay_reason"]); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="tc-media-card">
                    <h2>Uploaded Evidence</h2>

                    <?php if (count($mediaFiles) > 0): ?>
                        <div class="tc-media-grid">
                            <?php foreach ($mediaFiles as $media): ?>
                                <?php
                                    $mediaType = strtolower((string)($media["media_type"] ?? "image"));
                                    $mediaPath = $media["display_path"];
                                    $originalName = $media["original_name"] ?: "Evidence file";
                                    $fileSize = formatFileSize($media["file_size"] ?? 0);
                                    $mimeType = $media["mime_type"] ?? "";
                                ?>

                                <div class="tc-media-item">
                                    <?php if ($mediaType === "video"): ?>
                                        <video controls class="tc-media-video">
                                            <source src="<?php echo safeText($mediaPath); ?>" type="<?php echo safeText($mimeType ?: "video/mp4"); ?>">
                                            Your browser does not support the video tag.
                                        </video>

                                        <a href="<?php echo safeText($mediaPath); ?>" target="_blank" class="tc-media-link">
                                            <i class="bi bi-camera-video"></i>
                                            <?php echo safeText($originalName); ?>
                                        </a>
                                    <?php else: ?>
                                        <a href="<?php echo safeText($mediaPath); ?>" target="_blank" class="tc-media-preview">
                                            <img src="<?php echo safeText($mediaPath); ?>" alt="<?php echo safeText($originalName); ?>">
                                        </a>

                                        <a href="<?php echo safeText($mediaPath); ?>" target="_blank" class="tc-media-link">
                                            <i class="bi bi-image"></i>
                                            <?php echo safeText($originalName); ?>
                                        </a>
                                    <?php endif; ?>

                                    <small class="tc-media-meta">
                                        <?php echo safeText(strtoupper($mediaType)); ?>
                                        <?php if ((int)($media["file_size"] ?? 0) > 0): ?>
                                            · <?php echo safeText($fileSize); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="tc-media-empty">
                            No evidence file uploaded for this complaint.
                        </div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </section>

    </main>

</div>

<script src="../../js/citizen/sidebar.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>
