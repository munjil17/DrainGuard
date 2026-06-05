<?php
$activePage = "reopened-cases";
$pageTitle = "Reopened Cases";

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "ward_officer") {
    header("Location: ../../index.php");
    exit();
}

if (!isset($conn) || !$conn) {
    die("Database connection not found.");
}

$currentUserId = (int)($_SESSION["user_id"] ?? 0);
$successMessage = "";
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function fetchOne($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception("SQL Prepare Failed: " . mysqli_error($conn));
    }

    if ($types !== "" && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function fetchAllRows($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception("SQL Prepare Failed: " . mysqli_error($conn));
    }

    if ($types !== "" && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function tableColumns($conn, $tableName)
{
    $columns = [];
    $safeTable = mysqli_real_escape_string($conn, $tableName);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$safeTable`");

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $columns[] = $row["Field"];
        }
    }

    return $columns;
}

function firstExistingColumn($columns, $possibleColumns)
{
    foreach ($possibleColumns as $column) {
        if (in_array($column, $columns, true)) {
            return $column;
        }
    }

    return null;
}

function formatDateOnly($date)
{
    if (!$date) {
        return "N/A";
    }

    $time = strtotime($date);

    if (!$time) {
        return "N/A";
    }

    return date("M d", $time);
}

function makeProofPath($path)
{
    $path = trim((string)$path);

    if ($path === "") {
        return "";
    }

    $path = str_replace("\\", "/", $path);

    if (preg_match("/^https?:\/\//i", $path)) {
        return $path;
    }

    if (str_starts_with($path, "../../")) {
        return $path;
    }

    if (str_starts_with($path, "/")) {
        return $path;
    }

    if (str_starts_with($path, "assets/")) {
        return "../../" . $path;
    }

    if (str_starts_with($path, "uploads/")) {
        return "../../assets/" . $path;
    }

    if (!str_contains($path, "/")) {
        return "../../assets/uploads/complaints/" . $path;
    }

    return "../../" . ltrim($path, "/");
}

function requestLabel($type)
{
    $type = strtolower(trim((string)$type));

    if ($type === "disputed") {
        return "Disputed";
    }

    if ($type === "false_completion") {
        return "False Completion";
    }

    return "Reopened";
}

function requestCardClass($type)
{
    $type = strtolower(trim((string)$type));

    if ($type === "disputed" || $type === "false_completion") {
        return "disputed";
    }

    return "reopened";
}

/*
|--------------------------------------------------------------------------
| Detect maintenance_teams columns
|--------------------------------------------------------------------------
*/

$teamColumns = tableColumns($conn, "maintenance_teams");

$teamIdColumn = firstExistingColumn($teamColumns, [
    "maintenance_team_id",
    "team_id",
    "id"
]);

$teamNameColumn = firstExistingColumn($teamColumns, [
    "team_name",
    "maintenance_team_name",
    "name"
]);

if (!$teamIdColumn || !$teamNameColumn) {
    die("maintenance_teams table must have a team id and team name column.");
}

/*
|--------------------------------------------------------------------------
| Get logged-in ward officer
|--------------------------------------------------------------------------
*/

try {
    $wardOfficer = fetchOne(
        $conn,
        "SELECT
            wo.ward_officer_id,
            wo.user_id,
            wo.assigned_ward_id,
            wo.full_name,
            w.ward_id,
            w.ward_no,
            w.ward_name
        FROM ward_officers wo
        INNER JOIN wards w
            ON wo.assigned_ward_id = w.ward_id
        WHERE wo.user_id = ?
        LIMIT 1",
        "i",
        [$currentUserId]
    );

    if (!$wardOfficer) {
        throw new Exception("No assigned ward found for this Ward Officer.");
    }

    $wardId = (int)$wardOfficer["assigned_ward_id"];
    $wardNo = $wardOfficer["ward_no"] ?? "";
    $wardName = $wardOfficer["ward_name"] ?? "";
    $userName = $wardOfficer["full_name"] ?? ($_SESSION["user_name"] ?? "Ward Officer");

    $_SESSION["user_name"] = $userName;
    $_SESSION["user_role_label"] = "Ward Operations";
} catch (Exception $e) {
    die($e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Handle actions
|--------------------------------------------------------------------------
| Same team: back to team_assigned.
| Different team: back to verified so Local Team Assignment can assign another team.
| Inspector: send to inspector_verification.
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $reopenId = (int)($_POST["reopen_id"] ?? 0);
    $reviewId = (int)($_POST["review_id"] ?? 0);
    $complaintId = (int)($_POST["complaint_id"] ?? 0);
    $action = trim($_POST["action"] ?? "");
    $decisionNote = trim($_POST["decision_note"] ?? "");

    $allowedActions = ["same_team", "different_team", "inspector", "inspector_claim_true", "inspector_claim_false"];

    if ($complaintId <= 0 || !in_array($action, $allowedActions, true)) {
        $errorMessage = "Invalid request.";
    } else {
        mysqli_begin_transaction($conn);

        try {
            if ($action === "inspector_claim_true" || $action === "inspector_claim_false") {
                $reviewCheck = fetchOne(
                    $conn,
                    "SELECT fcr.*, c.complaint_status, l.ward_id
                    FROM false_completion_reviews fcr
                    INNER JOIN complaints c ON fcr.complaint_id = c.complaint_id
                    INNER JOIN locations l ON c.loc_id = l.loc_id
                    WHERE fcr.review_id = ? AND l.ward_id = ? AND fcr.inspector_claim_status = 'pending' LIMIT 1",
                    "ii",
                    [$reviewId, $wardId]
                );

                if (!$reviewCheck) {
                    throw new Exception("This review is not pending or does not belong to your assigned ward.");
                }

                $inspectorUserId = (int)$reviewCheck['inspector_user_id'];
                $teamLeaderUserId = (int)$reviewCheck['team_leader_user_id'];
                $maintenanceTeamId = (int)$reviewCheck['maintenance_team_id'];

                if ($action === "inspector_claim_true") {
                    require_once "../../includes/disciplinary_helpers.php";
                    
                    // Team Leader gets 1 demerit
                    addDemerit($conn, $teamLeaderUserId, null, 'team_leader', 'team_leader', $complaintId, $maintenanceTeamId, 'false_completion_claim_true', $decisionNote, $currentUserId, 'ward_officer');

                    // Fetch other members for warnings or demerit
                    $members = fetchAllRows($conn, "SELECT user_id, member_id FROM maintenance_team_members WHERE maintenance_team_id = ? AND status = 'active' AND user_id != ?", "ii", [$maintenanceTeamId, $teamLeaderUserId]);
                    foreach ($members as $member) {
                        applyTeamMemberWarningOrDemerit($conn, $member['member_id'], $member['user_id'], $maintenanceTeamId, $complaintId, 'false_completion_claim_true', $decisionNote, $currentUserId, 'ward_officer');
                    }

                    // Reopen complaint
                    mysqli_query($conn, "UPDATE complaints SET complaint_status = 'reopened' WHERE complaint_id = $complaintId");
                    
                    // Add to reopen_requests
                    $insReq = mysqli_prepare($conn, "INSERT INTO reopen_requests (complaint_id, requested_by, request_type, reason, request_status, ward_note) VALUES (?, ?, 'false_completion', ?, 'pending', ?)");
                    mysqli_stmt_bind_param($insReq, "iiss", $complaintId, $currentUserId, $decisionNote, $decisionNote);
                    mysqli_stmt_execute($insReq);
                    
                    $claimStatus = 'true';
                    $successMessage = "Inspector claim confirmed true. Maintenance team penalized and complaint reopened.";
                } else {
                    require_once "../../includes/disciplinary_helpers.php";
                    
                    // Inspector gets 1 demerit
                    addDemerit($conn, $inspectorUserId, null, 'inspector', 'inspector', $complaintId, null, 'false_completion_claim_false', $decisionNote, $currentUserId, 'ward_officer');

                    // Restore complaint status
                    mysqli_query($conn, "UPDATE complaints SET complaint_status = 'inspector_verification' WHERE complaint_id = $complaintId");
                    
                    $claimStatus = 'false';
                    $successMessage = "Inspector claim marked false. Inspector penalized and complaint restored to Inspector Verification.";
                }

                $stmt = mysqli_prepare($conn, "UPDATE false_completion_reviews SET inspector_claim_status = ?, ward_decision_note = ?, ward_officer_user_id = ?, decided_at = NOW() WHERE review_id = ?");
                mysqli_stmt_bind_param($stmt, "ssii", $claimStatus, $decisionNote, $currentUserId, $reviewId);
                mysqli_stmt_execute($stmt);

                $notifTime = date('Y-m-d H:i:s');
                $complaintCode = fetchOne($conn, "SELECT complaint_code FROM complaints WHERE complaint_id = $complaintId")['complaint_code'] ?? '';
                
                if ($claimStatus === 'true') {
                    $msgTL = "You received 1 demerit point because the false completion claim for complaint {$complaintCode} was confirmed true.";
                    mysqli_query($conn, "INSERT INTO maintenance_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES ($teamLeaderUserId, $currentUserId, $complaintId, 'system', 'Demerit Issued', '$msgTL', 0, '$notifTime')");
                    $msgIns = "Your false completion claim for complaint {$complaintCode} was confirmed. Thank you.";
                    mysqli_query($conn, "INSERT INTO inspector_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES ($inspectorUserId, $currentUserId, $complaintId, 'system', 'Claim Confirmed', '$msgIns', 0, '$notifTime')");
                } else {
                    $msgIns = "Your false completion claim for complaint {$complaintCode} was rejected. You have received 1 demerit point.";
                    mysqli_query($conn, "INSERT INTO inspector_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES ($inspectorUserId, $currentUserId, $complaintId, 'system', 'Demerit Issued', '$msgIns', 0, '$notifTime')");
                    $msgTL = "The false completion claim for complaint {$complaintCode} against your team was rejected. No penalties were applied.";
                    mysqli_query($conn, "INSERT INTO maintenance_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES ($teamLeaderUserId, $currentUserId, $complaintId, 'system', 'Claim Rejected', '$msgTL', 0, '$notifTime')");
                }
                
                mysqli_commit($conn);
            } else {
                $requestCheck = fetchOne(
                $conn,
                "SELECT
                    rr.reopen_id,
                    rr.complaint_id,
                    rr.request_status,
                    c.complaint_status,
                    ca.assignment_id,
                    ca.ward_id,
                    ca.maintenance_team_id
                FROM reopen_requests rr
                INNER JOIN complaints c
                    ON rr.complaint_id = c.complaint_id
                INNER JOIN locations l
                    ON c.loc_id = l.loc_id
                LEFT JOIN complaint_assignments ca
                    ON c.complaint_id = ca.complaint_id
                WHERE rr.reopen_id = ?
                AND rr.complaint_id = ?
                AND l.ward_id = ?
                AND rr.request_status IN ('pending', 'accepted_by_ward')
                ORDER BY ca.assignment_id DESC
                LIMIT 1",
                "iii",
                [$reopenId, $complaintId, $wardId]
            );

            if (!$requestCheck) {
                throw new Exception("This request is not pending or does not belong to your assigned ward.");
            }

            if ($action === "same_team") {
                if (empty($requestCheck["maintenance_team_id"])) {
                    throw new Exception("No previous maintenance team found for reassignment.");
                }

                $requestStatus = "reassigned_same_team";
                $complaintStatus = "team_assigned";
                $assignmentStatus = "team_assigned";

                $updateAssignmentSql = "
                    UPDATE complaint_assignments
                    SET
                        assignment_status = ?,
                        assigned_at = CURRENT_TIMESTAMP
                    WHERE assignment_id = ?
                ";

                $updateAssignmentStmt = mysqli_prepare($conn, $updateAssignmentSql);

                if (!$updateAssignmentStmt) {
                    throw new Exception("Assignment update failed: " . mysqli_error($conn));
                }

                $assignmentId = (int)$requestCheck["assignment_id"];

                mysqli_stmt_bind_param($updateAssignmentStmt, "si", $assignmentStatus, $assignmentId);

                if (!mysqli_stmt_execute($updateAssignmentStmt)) {
                    throw new Exception("Assignment update failed: " . mysqli_stmt_error($updateAssignmentStmt));
                }

                mysqli_stmt_close($updateAssignmentStmt);
            } elseif ($action === "different_team") {
                $requestStatus = "reassigned_different_team";
                $complaintStatus = "reopened";

                $updateAssignmentSql = "
                    UPDATE complaint_assignments
                    SET
                        maintenance_team_id = NULL,
                        assignment_status = 'ward_assigned',
                        assigned_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                    AND ward_id = ?
                ";

                $updateAssignmentStmt = mysqli_prepare($conn, $updateAssignmentSql);

                if (!$updateAssignmentStmt) {
                    throw new Exception("Assignment update failed: " . mysqli_error($conn));
                }

                mysqli_stmt_bind_param($updateAssignmentStmt, "ii", $complaintId, $wardId);

                if (!mysqli_stmt_execute($updateAssignmentStmt)) {
                    throw new Exception("Assignment update failed: " . mysqli_stmt_error($updateAssignmentStmt));
                }

                mysqli_stmt_close($updateAssignmentStmt);


            $updateRequestSql = "
                UPDATE reopen_requests
                SET
                    request_status = ?,
                    handled_by = ?,
                    handled_at = NOW()
                WHERE reopen_id = ?
            ";

            $updateRequestStmt = mysqli_prepare($conn, $updateRequestSql);

            if (!$updateRequestStmt) {
                throw new Exception("Reopen request update failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($updateRequestStmt, "sii", $requestStatus, $currentUserId, $reopenId);

            if (!mysqli_stmt_execute($updateRequestStmt)) {
                throw new Exception("Reopen request update failed: " . mysqli_stmt_error($updateRequestStmt));
            }

            mysqli_stmt_close($updateRequestStmt);

            $updateComplaintSql = "
                UPDATE complaints
                SET
                    complaint_status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE complaint_id = ?
            ";

            $updateComplaintStmt = mysqli_prepare($conn, $updateComplaintSql);

            if (!$updateComplaintStmt) {
                throw new Exception("Complaint status update failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($updateComplaintStmt, "si", $complaintStatus, $complaintId);

            if (!mysqli_stmt_execute($updateComplaintStmt)) {
                throw new Exception("Complaint status update failed: " . mysqli_stmt_error($updateComplaintStmt));
            }

            mysqli_stmt_close($updateComplaintStmt);

            mysqli_commit($conn);

            if ($action === "same_team") {
                $successMessage = "Complaint reassigned to the same team successfully.";
            } elseif ($action === "different_team") {
                $successMessage = "Complaint moved back to Local Team Assignment for different team selection.";
            }

            // === NOTIFICATIONS: ward_reopen_assign_team ===
            $reopenInfo = fetchOne($conn, "SELECT complaint_code, user_id AS citizen_id FROM complaints WHERE complaint_id = ?", "i", [$complaintId]);
            $rNotifCode = $reopenInfo['complaint_code'] ?? "ID:$complaintId";
            $rCitizenId = (int)($reopenInfo['citizen_id'] ?? 0);
            $rNotifTime = date('Y-m-d H:i:s');
            $rNotifMsg = "Ward Officer assigned a team for reopened complaint {$rNotifCode}. The complaint has moved forward again.";

            if ($rCitizenId > 0) {
                $stmtCit = mysqli_prepare($conn, "INSERT INTO citizen_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'ward_reopen_assign_team', 'Reopened Complaint Assigned', ?, 0, ?)");
                if ($stmtCit) { mysqli_stmt_bind_param($stmtCit, "iiiss", $rCitizenId, $currentUserId, $complaintId, $rNotifMsg, $rNotifTime); mysqli_stmt_execute($stmtCit); mysqli_stmt_close($stmtCit); }
            }

            $cenRow = fetchOne($conn, "SELECT ca.assigned_by FROM complaint_assignments ca JOIN users u ON u.user_id = ca.assigned_by WHERE ca.complaint_id = ? AND u.user_role = 'central_officer' LIMIT 1", "i", [$complaintId]);
            if ($cenRow) {
                $cenUserId = (int)$cenRow['assigned_by'];
                $stmtCen = mysqli_prepare($conn, "INSERT INTO central_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'ward_reopen_assign_team', 'Reopened Complaint Assigned', ?, 0, ?)");
                if ($stmtCen) { mysqli_stmt_bind_param($stmtCen, "iiiss", $cenUserId, $currentUserId, $complaintId, $rNotifMsg, $rNotifTime); mysqli_stmt_execute($stmtCen); mysqli_stmt_close($stmtCen); }
            }

            } // Close else block
            }
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $errorMessage = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Fetch pending reopened/disputed requests for assigned ward
|--------------------------------------------------------------------------
*/

try {
    $requestsSql = "
        SELECT
            rr.reopen_id,
            rr.complaint_id,
            rr.requested_by,
            rr.request_type,
            rr.reason,
            rr.request_status,
            rr.created_at,
            rr.handled_at,

            c.complaint_code,
            c.complaint_status,
            c.problem_description,
            c.updated_at,

            u.user_name AS requested_by_name,
            u.user_mail AS requested_by_email,

            i.issue_name,
            i.priority AS issue_priority,

            a.area_name,

            ca.assignment_id,
            ca.maintenance_team_id,
            ca.assignment_status,
            ca.deadline_at,
            ca.assignment_priority,
            ca.task_note,
            ca.assigned_at,

            mt.`$teamNameColumn` AS team_name,

            mu.update_id,
            mu.work_status,
            mu.work_note,
            mp.media_path AS proof_file_path,
            mp.media_type AS proof_file_type,
            mu.completed_at,
            mp.uploaded_at AS proof_updated_at,
            
            cm.media_path AS citizen_proof_path,
            cm.media_type AS citizen_proof_type

        FROM reopen_requests rr

        INNER JOIN complaints c
            ON rr.complaint_id = c.complaint_id

        INNER JOIN users u
            ON rr.requested_by = u.user_id

        INNER JOIN locations l
            ON c.loc_id = l.loc_id

        LEFT JOIN areas a
            ON l.area_id = a.area_id

        LEFT JOIN issues i
            ON c.issue_id = i.issue_id

        LEFT JOIN (
            SELECT ca1.*
            FROM complaint_assignments ca1
            INNER JOIN (
                SELECT
                    complaint_id,
                    MAX(assignment_id) AS latest_assignment_id
                FROM complaint_assignments
                GROUP BY complaint_id
            ) latest_ca
                ON ca1.assignment_id = latest_ca.latest_assignment_id
        ) ca
            ON c.complaint_id = ca.complaint_id

        LEFT JOIN maintenance_teams mt
            ON ca.maintenance_team_id = mt.`$teamIdColumn`

        LEFT JOIN (
            SELECT mu1.*
            FROM maintenance_updates mu1
            INNER JOIN (
                SELECT
                    complaint_id,
                    MAX(update_id) AS latest_update_id
                FROM maintenance_updates
                GROUP BY complaint_id
            ) latest_mu
                ON mu1.update_id = latest_mu.latest_update_id
        ) mu
            ON c.complaint_id = mu.complaint_id
            
        LEFT JOIN (
            SELECT mp1.*
            FROM maintenance_proofs mp1
            INNER JOIN (
                SELECT complaint_id, MAX(proof_id) AS latest_proof_id
                FROM maintenance_proofs
                WHERE proof_stage = 'after'
                GROUP BY complaint_id
            ) latest_mp
                ON mp1.proof_id = latest_mp.latest_proof_id
        ) mp ON c.complaint_id = mp.complaint_id
            
        LEFT JOIN (
            SELECT cm1.* FROM complaint_media cm1
            INNER JOIN (
                SELECT complaint_id, MIN(media_id) AS first_media_id
                FROM complaint_media GROUP BY complaint_id
            ) first_cm ON cm1.media_id = first_cm.first_media_id
        ) cm ON c.complaint_id = cm.complaint_id

        WHERE l.ward_id = ?
        AND (
            (rr.request_status = 'pending' AND rr.request_type IN ('reopened', 'false_completion'))
            OR (rr.request_status = 'accepted_by_ward' AND rr.request_type = 'citizen_objection')
        )
    ";

    $reopenRequests = fetchAllRows($conn, $requestsSql, "i", [$wardId]);

    $requests = $reopenRequests;
    usort($reopenRequests, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

} catch (Exception $e) {
    $reopenRequests = [];
    $errorMessage = $e->getMessage();
}

$totalReopened = 0;
$totalDisputed = 0;

foreach ($reopenRequests as $item) {
    $type = strtolower((string)$item["request_type"]);

    if ($type === "reopened") {
        $totalReopened++;
    } else {
        $totalDisputed++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reopened Cases | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
   
    <link rel="stylesheet" href="../../css/ward/reopened-disputed.css">
    <link rel="stylesheet" href="../../css/ward/wardTextFix.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="ward">

<?php include "../../includes/ward/sidebar.php"; ?>

<main class="ward-main">
    <?php include "../../includes/ward/topbar.php"; ?>

    <section class="rd-page">

        <div class="rd-header">
            <div>
                <h1>Reopened Cases</h1>
                <p>Manage complaints reopened by citizens or confirmed as false completion.</p>
            </div>
        </div>

        <?php if ($successMessage !== ""): ?>
            <div class="rd-alert rd-success">
                <i class="bi bi-check-circle"></i>
                <?= safeText($successMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ""): ?>
            <div class="rd-alert rd-error">
                <i class="bi bi-exclamation-circle"></i>
                <?= safeText($errorMessage); ?>
            </div>
        <?php endif; ?>

        <div class="rd-summary-grid">
            <div class="rd-summary-card reopened">
                <div class="rd-summary-icon reopened">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div>
                    <h2><?= $totalReopened + $totalDisputed; ?></h2>
                    <p>Total Reopened Cases</p>
                </div>
            </div>
        </div>

        <div class="rd-toolbar">
            <div class="rd-search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="rdSearch" placeholder="Search by complaint ID, issue, area, team...">
            </div>
        </div>

        <div class="rd-list" id="rdList">

            <?php if (!empty($reopenRequests)): ?>
                <?php foreach ($reopenRequests as $request): ?>
                    <?php
                        $requestType = strtolower((string)$request["request_type"]);
                        $cardClass = requestCardClass($requestType);
                        $requestLabel = requestLabel($requestType);

                        $reopenId = (int)$request["reopen_id"];
                        $complaintId = (int)$request["complaint_id"];
                        $complaintCode = $request["complaint_code"] ?? "";
                        $issueName = $request["issue_name"] ?: "Unknown Issue";
                        $areaName = $request["area_name"] ?: "Area not specified";
                        $teamName = $request["team_name"] ?: "No team found";
                        $reason = $request["reason"] ?: "No reason provided.";
                        $completedAt = formatDateOnly($request["completed_at"] ?? null);
                        $reopenedAt = formatDateOnly($request["created_at"] ?? null);
                        $proofPath = makeProofPath($request["proof_file_path"] ?? "");
                        $proofType = strtolower((string)($request["proof_file_type"] ?? ""));
                        $proofText = $request["proof_file_path"]
                            ? "Proof submitted by " . $teamName . " on " . $completedAt
                            : "No previous completion proof found.";
                            
                        $citProofPath = makeProofPath($request["citizen_proof_path"] ?? "");
                        $citProofType = strtolower((string)($request["citizen_proof_type"] ?? ""));

                        $searchText = strtolower(
                            $complaintCode . " " .
                            $issueName . " " .
                            $areaName . " " .
                            $teamName . " " .
                            $requestLabel . " " .
                            $reason
                        );
                    ?>

                    <article class="rd-card <?= safeText($cardClass); ?>"
                             data-search="<?= safeText($searchText); ?>"
                             data-type="<?= safeText($requestType); ?>">

                        <div class="rd-card-top">
                            <div>
                                <span class="rd-code"><?= safeText($complaintCode); ?></span>
                                <span class="rd-badge <?= safeText($cardClass); ?>">
                                    <?= safeText($requestLabel); ?>
                                </span>
                            </div>
                        </div>

                        <h2>
                            <?= safeText($issueName); ?>
                            -
                            <?= $requestType === "false_completion"
                                ? "false completion report"
                                : "citizen reported incomplete work"; ?>
                        </h2>

                        <div class="rd-meta-grid">
                            <div>
                                <span>Area:</span>
                                <strong><?= safeText($areaName); ?></strong>
                            </div>

                            <div>
                                <span>Team:</span>
                                <strong><?= safeText($teamName); ?></strong>
                            </div>

                            <div>
                                <span>Completed:</span>
                                <strong><?= safeText($completedAt); ?></strong>
                            </div>

                            <div>
                                <span>Reopened:</span>
                                <strong><?= safeText($reopenedAt); ?></strong>
                            </div>
                        </div>

                        <div class="rd-reason-box">
                            <div class="rd-box-title">
                                <i class="bi bi-chat-square"></i>
                                <span>Dispute Reason</span>
                            </div>
                            <p><?= safeText($reason); ?></p>
                        </div>

                        <div class="rd-proof-box">
                            <div class="rd-box-title">
                                <span>Before & After Proofs</span>
                            </div>

                            <div class="rd-proof-grid">
                                <!-- Citizen Proof (Before) -->
                                <div class="rd-proof-col">
                                    <span class="rd-proof-col-title">Before (Citizen Proof)</span>
                                    <div class="rd-proof-content">
                                        <?php if ($citProofPath !== "" && ($citProofType === "image" || preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $citProofPath))): ?>
                                            <a href="<?= safeText($citProofPath); ?>" target="_blank" class="rd-proof-thumb">
                                                <img src="<?= safeText($citProofPath); ?>" alt="Citizen Proof">
                                            </a>
                                        <?php elseif ($citProofPath !== "" && ($citProofType === "video" || preg_match('/\.(mp4|webm|ogg|mov)$/i', $citProofPath))): ?>
                                            <video class="rd-proof-video" controls>
                                                <source src="<?= safeText($citProofPath); ?>">
                                            </video>
                                        <?php else: ?>
                                            <div class="rd-proof-placeholder"></div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Maintenance Proof (After) -->
                                <div class="rd-proof-col">
                                    <span class="rd-proof-col-title">After (Team Proof)</span>
                                    <div class="rd-proof-content">
                                        <?php if ($proofPath !== "" && ($proofType === "image" || preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $proofPath))): ?>
                                            <a href="<?= safeText($proofPath); ?>" target="_blank" class="rd-proof-thumb">
                                                <img src="<?= safeText($proofPath); ?>" alt="Completion Proof">
                                            </a>
                                        <?php elseif ($proofPath !== "" && ($proofType === "video" || preg_match('/\.(mp4|webm|ogg|mov)$/i', $proofPath))): ?>
                                            <video class="rd-proof-video" controls>
                                                <source src="<?= safeText($proofPath); ?>">
                                            </video>
                                        <?php else: ?>
                                            <div class="rd-proof-placeholder"></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <p style="margin-top:14px; color:#334155; font-size:14px;"><?= safeText($proofText); ?></p>
                        </div>

                        <div class="rd-actions">
                                <form method="POST" action="reopened-cases.php" class="rd-action-form">
                                    <input type="hidden" name="reopen_id" value="<?= $reopenId; ?>">
                                    <input type="hidden" name="complaint_id" value="<?= $complaintId; ?>">
                                    <input type="hidden" name="action" value="same_team">

                                    <button type="submit" class="rd-btn same-team">
                                        <i class="bi bi-send"></i>
                                        Reassign to Same Team
                                    </button>
                                </form>

                                <form method="POST" action="reopened-cases.php" class="rd-action-form">
                                    <input type="hidden" name="reopen_id" value="<?= $reopenId; ?>">
                                    <input type="hidden" name="complaint_id" value="<?= $complaintId; ?>">
                                    <input type="hidden" name="action" value="different_team">

                                    <button type="submit" class="rd-btn different-team">
                                        <i class="bi bi-people"></i>
                                        Assign to Different Team
                                    </button>
                                </form>


                        </div>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="rd-empty">
                    <i class="bi bi-check-circle"></i>
                    <h2>No reopened cases</h2>
                    <p>Reopened complaints will appear here.</p>
                </div>
            <?php endif; ?>

        </div>

    </section>

</main>

<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/reopened-disputed.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>