<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";
require_once "../../commentSystem/discussion_logic.php";

$activePage = "complaints";
$pageTitle = "Complaints Management";
$pageParent = "Central Control";
$pageChild = "Complaints";

$successMessage = "";
$errorMessage = "";

$userId = (int)($_SESSION["user_id"] ?? 0);

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function normalizeCentralStatus($status)
{
    $status = strtolower(trim((string)$status));

    $map = [
        "verified" => "verified_by_ward",
        "ward_verified" => "verified_by_ward",
        "assigned" => "team_assigned",
        "assigned_to_team" => "team_assigned",
        "completed" => "solved_by_team",
        "team_completed" => "solved_by_team",
        "under_inspection" => "inspector_verification",
        "pending_inspection" => "inspector_verification",
        "solved" => "closed",
        "resolved" => "closed",
        "rejected" => "rejected_by_central"
    ];

    return $map[$status] ?? $status;
}

function formatStatus($status)
{
    $status = normalizeCentralStatus($status);

    $labels = [
        "submitted" => "Submitted",
        "received" => "Received",
        "pending_verification" => "Pending Verification",
        "verified_by_ward" => "Verified by Ward Officer",
        "team_assigned" => "Assigned to Team",
        "in_progress" => "In Progress",
        "solved_by_team" => "Solved by Team",
        "inspector_verification" => "Inspector Verification",
        "closed" => "Closed",
        "rejected_by_central" => "Rejected by Central Officer",
        "rejected_by_ward" => "Rejected by Ward Officer",
        "duplicate" => "Duplicate",
        "reopened" => "Reopened",
        "disputed" => "Disputed",
        "final_rejected" => "Final Rejected"
    ];

    return $labels[$status] ?? ucwords(str_replace("_", " ", $status));
}

function statusClass($status)
{
    $status = normalizeCentralStatus($status);

    if ($status === "submitted") return "status-submitted";
    if ($status === "received") return "status-received";
    if ($status === "pending_verification") return "status-pending";
    if ($status === "verified_by_ward") return "status-verified";
    if ($status === "team_assigned") return "status-assigned";
    if ($status === "in_progress") return "status-progress";
    if ($status === "solved_by_team") return "status-completed";
    if ($status === "inspector_verification") return "status-inspection";
    if ($status === "closed") return "status-solved";
    if ($status === "rejected_by_central" || $status === "rejected_by_ward") return "status-rejected";
    if ($status === "duplicate") return "status-duplicate";
    if ($status === "reopened") return "status-reopened";
    if ($status === "disputed") return "status-disputed";
    if ($status === "final_rejected") return "status-final-rejected";

    return "status-submitted";
}

function urgencyClass($urgency)
{
    $urgency = strtolower(trim((string)$urgency));

    if ($urgency === "low") return "priority-low";
    if ($urgency === "medium") return "priority-medium";
    if ($urgency === "high") return "priority-high";

    return "priority-low";
}

function urgencyLabel($urgency)
{
    $urgency = strtolower(trim((string)$urgency));

    if ($urgency === "high") return "High";
    if ($urgency === "medium") return "Medium";
    return "Low";
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
    header("Location: complaints.php");
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

function cm_insert_status_log($conn, $complaintId, $oldStatus, $newStatus, $actionUserId, $remarks)
{
    if (!cm_table_exists($conn, "complaint_status_logs")) {
        return true;
    }

    $sql = "
        INSERT INTO complaint_status_logs (
            complaint_id,
            old_status,
            new_status,
            action_by_user_id,
            action_by_role,
            remarks,
            created_at
        )
        VALUES (?, ?, ?, ?, 'central_officer', ?, NOW())
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "issis", $complaintId, $oldStatus, $newStatus, $actionUserId, $remarks);

    $ok = mysqli_stmt_execute($stmt);

    mysqli_stmt_close($stmt);

    return $ok;
}

function cm_insert_decision($conn, $complaintId, $centralUserId, $reason)
{
    if (!cm_table_exists($conn, "complaint_decisions")) {
        return true;
    }

    $hasDecidedByUser = cm_column_exists($conn, "complaint_decisions", "decided_by_user_id");
    $hasDecidedByRole = cm_column_exists($conn, "complaint_decisions", "decided_by_role");

    if ($hasDecidedByUser && $hasDecidedByRole) {
        $sql = "
            INSERT INTO complaint_decisions (
                complaint_id,
                decided_by_user_id,
                decided_by_role,
                decision_type,
                reason,
                reference_complaint_id,
                created_at
            )
            VALUES (?, ?, 'central_officer', 'central_reject', ?, NULL, NOW())
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "iis", $complaintId, $centralUserId, $reason);
    } else {
        $sql = "
            INSERT INTO complaint_decisions (
                complaint_id,
                decision_type,
                reason,
                reference_complaint_id,
                created_at
            )
            VALUES (?, 'central_reject', ?, NULL, NOW())
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "is", $complaintId, $reason);
    }

    $ok = mysqli_stmt_execute($stmt);

    mysqli_stmt_close($stmt);

    return $ok;
}

function cm_insert_citizen_notification($conn, $recipientUserId, $senderUserId, $complaintId, $type, $title, $message)
{
    if (!cm_table_exists($conn, "citizen_notifications")) {
        return true;
    }

    $sql = "
        INSERT INTO citizen_notifications (
            recipient_user_id,
            sender_user_id,
            related_complaint_id,
            notification_type,
            notification_title,
            notification_message,
            is_read,
            created_at
        )
        VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "iiisss",
        $recipientUserId,
        $senderUserId,
        $complaintId,
        $type,
        $title,
        $message
    );

    $ok = mysqli_stmt_execute($stmt);

    mysqli_stmt_close($stmt);

    return $ok;
}

function cm_mailer_files_ready()
{
    return file_exists("../../auth/PHPMailer/Exception.php")
        && file_exists("../../auth/PHPMailer/PHPMailer.php")
        && file_exists("../../auth/PHPMailer/SMTP.php");
}

function cm_send_citizen_mail($toEmail, $toName, $subject, $htmlBody, $plainBody)
{
    if (!cm_mailer_files_ready()) {
        return false;
    }

    require_once "../../auth/PHPMailer/Exception.php";
    require_once "../../auth/PHPMailer/PHPMailer.php";
    require_once "../../auth/PHPMailer/SMTP.php";

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = "smtp.gmail.com";
        $mail->SMTPAuth   = true;

        /*
            এই দুইটা value তোমার working forgot_password.php থেকে copy করে বসাবে.
            Placeholder থাকলে mail যাবে না, কিন্তু complaint action কাজ করবে.
        */
        $mail->Username   = "munjilislambd17@gmail.com";
        $mail->Password   = "PASTE_YOUR_GMAIL_APP_PASSWORD_HERE";

        if ($mail->Password === "PASTE_YOUR_GMAIL_APP_PASSWORD_HERE") {
            return false;
        }

        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom($mail->Username, "DrainGuard Support");
        $mail->addAddress($toEmail, $toName ?: "Citizen");

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainBody;

        $mail->send();

        return true;
    } catch (Exception $e) {
        return false;
    }
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
    $rejectReason = trim($_POST["reject_reason"] ?? "");
    $centralUserId = (int)($_SESSION["user_id"] ?? 0);

    if ($complaintId <= 0 || !in_array($action, ["accept", "reject"], true)) {
        setComplaintFlash("error", "Invalid action.");
    }

    if ($centralUserId <= 0) {
        setComplaintFlash("error", "Invalid central officer session.");
    }

    if ($action === "reject") {
        if ($rejectReason === "") {
            setComplaintFlash("error", "Reject reason is required.");
        }

        if (mb_strlen($rejectReason) < 8) {
            setComplaintFlash("error", "Reject reason must be at least 8 characters.");
        }

        if (mb_strlen($rejectReason) > 1000) {
            setComplaintFlash("error", "Reject reason cannot exceed 1000 characters.");
        }
    }

    $checkSql = "
        SELECT
            c.complaint_id,
            c.complaint_code,
            c.user_id AS citizen_user_id,
            c.complaint_status,
            u.user_name,
            u.user_mail
        FROM complaints c
        INNER JOIN users u
            ON c.user_id = u.user_id
        WHERE c.complaint_id = ?
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

    $currentStatus = normalizeCentralStatus($complaintRow["complaint_status"]);

    if ($currentStatus !== "submitted") {
        setComplaintFlash("error", "Only submitted complaints can be accepted/rejected from this page.");
    }

    $newStatus = ($action === "accept") ? "received" : "rejected_by_central";

    $complaintCode = (string)$complaintRow["complaint_code"];
    $citizenUserId = (int)$complaintRow["citizen_user_id"];
    $citizenName = (string)$complaintRow["user_name"];
    $citizenEmail = (string)$complaintRow["user_mail"];

    mysqli_begin_transaction($conn);

    try {
        $updateSql = "
            UPDATE complaints
            SET complaint_status = ?,
                updated_at = NOW()
            WHERE complaint_id = ?
              AND complaint_status = 'submitted'
        ";

        $updateStmt = mysqli_prepare($conn, $updateSql);

        if (!$updateStmt) {
            throw new Exception("Complaint update query failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($updateStmt, "si", $newStatus, $complaintId);

        if (!mysqli_stmt_execute($updateStmt)) {
            mysqli_stmt_close($updateStmt);
            throw new Exception("Complaint update failed.");
        }

        if (mysqli_stmt_affected_rows($updateStmt) !== 1) {
            mysqli_stmt_close($updateStmt);
            throw new Exception("Complaint was already processed.");
        }

        mysqli_stmt_close($updateStmt);

        if ($action === "accept") {
            if (!cm_insert_status_log(
                $conn,
                $complaintId,
                $currentStatus,
                $newStatus,
                $centralUserId,
                "Central officer accepted and received the complaint."
            )) {
                throw new Exception("Status log insert failed.");
            }

            if (!cm_insert_citizen_notification(
                $conn,
                $citizenUserId,
                $centralUserId,
                $complaintId,
                "complaint_accepted",
                "Complaint Accepted",
                "Your complaint {$complaintCode} has been accepted by Central Officer and marked as Received."
            )) {
                throw new Exception("Citizen notification insert failed.");
            }
        }

        if ($action === "reject") {
            if (!cm_insert_decision($conn, $complaintId, $centralUserId, $rejectReason)) {
                throw new Exception("Decision reason insert failed.");
            }

            if (!cm_insert_status_log(
                $conn,
                $complaintId,
                $currentStatus,
                $newStatus,
                $centralUserId,
                "Central officer rejected the complaint."
            )) {
                throw new Exception("Status log insert failed.");
            }

            if (!cm_insert_citizen_notification(
                $conn,
                $citizenUserId,
                $centralUserId,
                $complaintId,
                "complaint_rejected",
                "Complaint Rejected",
                "Your complaint {$complaintCode} has been rejected by Central Officer. Please check the rejection reason."
            )) {
                throw new Exception("Citizen notification insert failed.");
            }
        }

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        setComplaintFlash("error", $e->getMessage());
    }

    if ($action === "accept") {
        $mailSent = cm_send_citizen_mail(
            $citizenEmail,
            $citizenName,
            "DrainGuard Complaint Accepted",
            "
                <h2>Complaint Accepted</h2>
                <p>Dear " . safeText($citizenName) . ",</p>
                <p>Your complaint <strong>" . safeText($complaintCode) . "</strong> has been accepted by Central Officer.</p>
                <p>Current status: <strong>Received</strong></p>
                <p>Regards,<br>DrainGuard Support</p>
            ",
            "Your complaint {$complaintCode} has been accepted by Central Officer. Current status: Received."
        );

        setComplaintFlash(
            "success",
            $mailSent
                ? "Complaint accepted, status log saved, citizen notification inserted, and email sent."
                : "Complaint accepted, status log saved, and citizen notification inserted. Email was not sent."
        );
    }

    if ($action === "reject") {
        $mailSent = cm_send_citizen_mail(
            $citizenEmail,
            $citizenName,
            "DrainGuard Complaint Rejected by Central Officer",
            "
                <h2>Complaint Rejected</h2>
                <p>Dear " . safeText($citizenName) . ",</p>
                <p>Your complaint <strong>" . safeText($complaintCode) . "</strong> has been rejected by Central Officer.</p>
                <p><strong>Reason:</strong><br>" . nl2br(safeText($rejectReason)) . "</p>
                <p>Current status: <strong>Rejected by Central Officer</strong></p>
                <p>Regards,<br>DrainGuard Support</p>
            ",
            "Your complaint {$complaintCode} has been rejected by Central Officer. Reason: {$rejectReason}"
        );

        setComplaintFlash(
            "success",
            $mailSent
                ? "Complaint rejected, reason saved, status log saved, citizen notification inserted, and email sent."
                : "Complaint rejected, reason saved, status log saved, and citizen notification inserted. Email was not sent."
        );
    }
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
| Priority calculation:
| 1. Water Contamination = High
| 2. issue priority High OR affected area priority High = High
| 3. issue priority Medium OR affected area priority Medium = Medium
| 4. otherwise Low
|--------------------------------------------------------------------------
*/

$issueJoin = "";
$issueSelect = "
    'Unknown Issue' AS issue_type,
    'Low' AS issue_priority
";

if (
    cm_table_exists($conn, "issues") &&
    cm_column_exists($conn, "issues", "issue_id") &&
    cm_column_exists($conn, "issues", "issue_name")
) {
    $issuePriorityColumn = cm_column_exists($conn, "issues", "priority")
        ? "COALESCE(i.priority, 'Low')"
        : "'Low'";

    $issueJoin = "
        LEFT JOIN issues i
            ON c.issue_id = i.issue_id
    ";

    $issueSelect = "
        COALESCE(i.issue_name, 'Unknown Issue') AS issue_type,
        {$issuePriorityColumn} AS issue_priority
    ";
} elseif (
    cm_table_exists($conn, "issue_types") &&
    cm_column_exists($conn, "issue_types", "issue_id") &&
    cm_column_exists($conn, "issue_types", "issue_name")
) {
    $issuePriorityColumn = cm_column_exists($conn, "issue_types", "priority")
        ? "COALESCE(it.priority, 'Low')"
        : "'Low'";

    $issueJoin = "
        LEFT JOIN issue_types it
            ON c.issue_id = it.issue_id
    ";

    $issueSelect = "
        COALESCE(it.issue_name, 'Unknown Issue') AS issue_type,
        {$issuePriorityColumn} AS issue_priority
    ";
}

$affectedAreaTable = "";

if (cm_table_exists($conn, "affected_areas")) {
    $affectedAreaTable = "affected_areas";
} elseif (cm_table_exists($conn, "affected_area")) {
    $affectedAreaTable = "affected_area";
}

$affectedAreaJoin = "";
$affectedAreaSelect = "
    'General Area' AS affected_area_name,
    'Low' AS affected_area_priority
";

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
        {$priorityColumn} AS affected_area_priority
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
        $issueNameLower = strtolower(trim((string)($row["issue_type"] ?? "")));
        $issuePriority = trim((string)($row["issue_priority"] ?? "Low"));
        $affectedPriority = trim((string)($row["affected_area_priority"] ?? "Low"));

        if ($issueNameLower === "water contamination") {
            $row["urgency_level"] = "High";
        } elseif ($issuePriority === "High" || $affectedPriority === "High") {
            $row["urgency_level"] = "High";
        } elseif ($issuePriority === "Medium" || $affectedPriority === "Medium") {
            $row["urgency_level"] = "Medium";
        } else {
            $row["urgency_level"] = "Low";
        }

        $row["complaint_status"] = normalizeCentralStatus($row["complaint_status"]);
        $row["media"] = [];
        $row["comment_count"] = 0;
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

    $commentSql = "
        SELECT complaint_id, COUNT(*) as comment_count 
        FROM comment_likes 
        WHERE complaint_id IN ($idList) AND is_deleted = 0 AND type = 'comment'
        GROUP BY complaint_id
    ";
    $commentResult = mysqli_query($conn, $commentSql);
    if ($commentResult) {
        while ($cRow = mysqli_fetch_assoc($commentResult)) {
            $cid = (int)$cRow["complaint_id"];
            if (isset($complaints[$cid])) {
                $complaints[$cid]["comment_count"] = (int)$cRow["comment_count"];
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
    <link rel="stylesheet" href="../../css/commentSystem/commentSystem.css">
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
                    <span id="visibleComplaintCount"><?php echo count($complaints); ?></span>
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
                        <option value="verified_by_ward">Verified by Ward Officer</option>
                        <option value="team_assigned">Assigned to Team</option>
                        <option value="in_progress">In Progress</option>
                        <option value="solved_by_team">Solved by Team</option>
                        <option value="inspector_verification">Inspector Verification</option>
                        <option value="closed">Closed</option>
                        <option value="rejected_by_central">Rejected by Central Officer</option>
                        <option value="rejected_by_ward">Rejected by Ward Officer</option>
                        <option value="duplicate">Duplicate</option>
                        <option value="reopened">Reopened</option>
                        <option value="disputed">Disputed</option>
                        <option value="final_rejected">Final Rejected</option>
                    </select>
                </div>

                <div class="cm-filter-group">
                    <label>Priority</label>
                    <select id="priorityFilter">
                        <option value="all">All Priority</option>
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High">High</option>
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
                <button type="button" class="cm-tab" data-priority="High">Emergency</button>
                <button type="button" class="cm-tab" data-filter="rejected_by_central">Rejected</button>
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
                                        $rawPriority = urgencyLabel($complaint["urgency_level"] ?? "Low");
                                        $priority = safeText($rawPriority);

                                        $rawStatus = normalizeCentralStatus($complaint["complaint_status"]);
                                        $status = safeText($rawStatus);
                                        $statusText = safeText(formatStatus($rawStatus));

                                        $date = !empty($complaint["submitted_at"])
                                            ? date("M d", strtotime($complaint["submitted_at"]))
                                            : "N/A";

                                        $fullDate = !empty($complaint["submitted_at"])
                                            ? date("M d, Y h:i A", strtotime($complaint["submitted_at"]))
                                            : "N/A";

                                        $canCentralAct = ($rawStatus === "submitted");

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
                                            <span class="cm-priority <?php echo urgencyClass($rawPriority); ?>">
                                                <?php echo $priority; ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="cm-status <?php echo statusClass($rawStatus); ?>">
                                                <?php echo $statusText; ?>
                                            </span>
                                        </td>

                                        <td><?php echo safeText($date); ?></td>

                                        <td>
                                            <div class="cm-actions">

                                                <?php if ($canCentralAct): ?>

                                                    <form method="POST" action="complaints.php" class="cm-action-form cm-accept-form">
                                                        <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
                                                        <input type="hidden" name="action" value="accept">

                                                        <button type="submit" class="cm-icon-btn accept" title="Accept / Mark as Received">
                                                            <i class="bi bi-check-circle"></i>
                                                        </button>
                                                    </form>

                                                    <button
                                                        type="button"
                                                        class="cm-icon-btn reject cm-reject-open-btn"
                                                        title="Reject"
                                                        data-complaint-id="<?php echo $complaintId; ?>"
                                                        data-complaint-code="<?php echo $complaintCode; ?>"
                                                    >
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>

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

                                                <?php 
                                                    $context = cs_get_discussion_context($conn, $complaintId);
                                                    $hasDiscussionAccess = cs_has_discussion_access($context, $userId, 'central_officer');
                                                ?>
                                                <?php if ($hasDiscussionAccess): ?>
                                                    <a
                                                        href="discussion.php?id=<?php echo $complaintId; ?>"
                                                        class="cm-discussion-btn"
                                                        title="Comment / Discussion"
                                                    >
                                                        <i class="bi bi-chat-dots"></i> Discussion <?php echo ($complaint["comment_count"] > 0) ? "(" . $complaint["comment_count"] . ")" : ""; ?>
                                                    </a>
                                                <?php endif; ?>

                                            </div>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="cm-empty cm-filter-empty" id="filterEmptyState">
                        <i class="bi bi-inbox"></i>
                        <h2>No matching complaints</h2>
                        <p>Try changing your search keyword or filter.</p>
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

<div class="cm-modal-overlay" id="rejectModal">
    <div class="cm-modal cm-reject-modal">

        <div class="cm-modal-header">
            <div>
                <h2>Reject Complaint</h2>
                <p id="rejectModalCode">Complaint ID</p>
            </div>

            <button type="button" id="rejectModalCloseBtn" class="cm-modal-close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form method="POST" action="complaints.php" id="centralRejectForm" class="cm-reject-form">
            <input type="hidden" name="complaint_id" id="rejectComplaintId">
            <input type="hidden" name="action" value="reject">

            <div class="cm-modal-body">
                <div class="cm-reject-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <span>Reject reason is required. Citizen will see this reason in Track Complaint.</span>
                </div>

                <label for="rejectReason">Reject Reason</label>
                <textarea
                    name="reject_reason"
                    id="rejectReason"
                    placeholder="Write a clear reason for rejecting this complaint..."
                    minlength="8"
                    maxlength="1000"
                    required
                ></textarea>

                <small id="rejectReasonError" class="cm-reject-error"></small>

                <div class="cm-reject-actions">
                    <button type="button" class="cm-reject-cancel" id="rejectCancelBtn">
                        Cancel
                    </button>

                    <button type="submit" class="cm-reject-submit">
                        <i class="bi bi-x-circle"></i>
                        Reject Complaint
                    </button>
                </div>
            </div>
        </form>

    </div>
</div>



<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/complaints.js"></script>
<script src="../../js/commentSystem/commentSystem.js"></script>

</body>
</html>