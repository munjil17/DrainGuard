<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/notification_workflow_cleanup.php';

if (!isset($conn) && isset($connection)) {
    $conn = $connection;
}

if (!isset($conn) || !$conn) {
    die("Service is temporarily unavailable. Please try again.");
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

function woBindParams($stmt, $types, &$params)
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

function woLogDbFailure($context, $conn, $stmt = null)
{
    $message = "[DrainGuard citizen-objections] " . $context;
    $dbError = mysqli_error($conn);

    if ($dbError !== '') {
        $message .= " Details were logged. ". $dbError;
    }

    if ($stmt) {
        $stmtError = mysqli_stmt_error($stmt);
        if ($stmtError !== '') {
            $message .= " | stmt_error: " . $stmtError;
        }
    }

    error_log($message);
}

function woPrepareOrThrow($conn, $sql, $context)
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        woLogDbFailure($context . " prepare failed | SQL: " . preg_replace('/\s+/', ' ', trim($sql)), $conn);
        throw new Exception("Unable to load records. Please try again.");
    }

    return $stmt;
}

function woExecuteOrThrow($conn, $stmt, $context, $sql = '')
{
    if (!mysqli_stmt_execute($stmt)) {
        $sqlContext = $sql !== '' ? " | SQL: " . preg_replace('/\s+/', ' ', trim($sql)) : "";
        woLogDbFailure($context . " execute failed" . $sqlContext, $conn, $stmt);
        throw new Exception("Unable to complete this action. Please try again.");
    }
}

function woQueryOrThrow($conn, $sql, $context)
{
    if (!mysqli_query($conn, $sql)) {
        woLogDbFailure($context . " query failed | SQL: " . preg_replace('/\s+/', ' ', trim($sql)), $conn);
        throw new Exception("Unable to load records. Please try again.");
    }
}

function woFetchOne($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        woLogDbFailure("Fetch one prepare failed", $conn);
        die("Unable to load records. Please try again.");
    }

    woBindParams($stmt, $types, $params);

    if (!mysqli_stmt_execute($stmt)) {
        woLogDbFailure("Fetch one execute failed", $conn, $stmt);
        die("Unable to load records. Please try again.");
    }
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function woFetchAll($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        woLogDbFailure("Fetch all prepare failed", $conn);
        die("Unable to load records. Please try again.");
    }

    woBindParams($stmt, $types, $params);

    if (!mysqli_stmt_execute($stmt)) {
        woLogDbFailure("Fetch all execute failed", $conn, $stmt);
        die("Unable to load records. Please try again.");
    }
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function woText($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function woDate($datetime)
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

function woMediaPath($path)
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

function woPriorityClass($priority)
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

function woStatusLabel($status)
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

function woRequestStatusLabel($status)
{
    $map = [
        'pending' => 'Pending Ward Review',
        'sent_to_inspector' => 'Forwarded to Inspector',
        'reassigned_same_team' => 'Reassigned Same Team',
        'reassigned_different_team' => 'Reassigned Different Team',
        'rejected' => 'Objection Rejected',
        'resolved' => 'Resolved'
    ];

    return $map[$status] ?? ucwords(str_replace('_', ' ', (string) $status));
}

function woBuildQuery($search, $areaId, $sort, $page = 1, $status = '', $error = '')
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

function woBuildPageUrl($page, $search, $areaId, $sort)
{
    return 'citizen-objections.php' . woBuildQuery($search, $areaId, $sort, (int) $page);
}

function woNotifyMaintenanceObjectionDecision($conn, $complaintId, $senderUserId, $decision, $wardNote)
{
    $assignment = woFetchOne(
        $conn,
        "SELECT
            ca.maintenance_team_id,
            c.complaint_code
        FROM complaint_assignments ca
        INNER JOIN complaints c ON c.complaint_id = ca.complaint_id
        WHERE ca.complaint_id = ?
        AND ca.maintenance_team_id IS NOT NULL
        ORDER BY ca.assignment_id DESC
        LIMIT 1",
        "i",
        [$complaintId]
    );

    $maintenanceTeamId = (int)($assignment['maintenance_team_id'] ?? 0);
    if ($maintenanceTeamId <= 0) {
        return;
    }

    $teamLeader = woFetchOne(
        $conn,
        "SELECT user_id
        FROM maintenance_team_members
        WHERE maintenance_team_id = ?
        AND role = 'team_leader'
        AND status = 'active'
        LIMIT 1",
        "i",
        [$maintenanceTeamId]
    );

    $teamLeaderUserId = (int)($teamLeader['user_id'] ?? 0);
    if ($teamLeaderUserId <= 0) {
        return;
    }

    $complaintCode = (string)($assignment['complaint_code'] ?? ("#" . $complaintId));
    $isAccepted = ($decision === 'accept');
    $notificationType = $isAccepted ? 'ward_citizen_claim_true' : 'ward_citizen_claim_false';
    $notificationTitle = $isAccepted ? 'Citizen Claim Marked True' : 'Citizen Claim Marked False';
    $notificationMessage = $isAccepted
        ? "Ward Officer accepted the citizen objection for complaint {$complaintCode}. Please review the reopened task."
        : "Ward Officer rejected the citizen objection for complaint {$complaintCode}. No rework is required.";

    if ($wardNote !== '') {
        $notificationMessage .= " Note: " . $wardNote;
    }

    dg_cleanup_workflow_notifications($conn, "maintenance_notifications", $teamLeaderUserId, $complaintId, $notificationType);

    $notifSql = "INSERT INTO maintenance_notifications
        (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, NOW())";
    $notifStmt = mysqli_prepare($conn, $notifSql);
    if ($notifStmt) {
        mysqli_stmt_bind_param($notifStmt, "iiisss", $teamLeaderUserId, $senderUserId, $complaintId, $notificationType, $notificationTitle, $notificationMessage);
        mysqli_stmt_execute($notifStmt);
        mysqli_stmt_close($notifStmt);
    }
}

/* =========================
   Ward Officer Info
========================= */

$wardOfficer = woFetchOne(
    $conn,
    "SELECT 
        wo.ward_officer_id,
        wo.user_id,
        wo.assigned_ward_id,
        wo.full_name,
        u.user_name
    FROM ward_officers wo
    INNER JOIN users u ON u.user_id = wo.user_id
    WHERE wo.user_id = ?
    LIMIT 1",
    "i",
    [$userId]
);

if (!$wardOfficer) {
    die("Ward Officer profile not found for this logged-in user.");
}

$assignedWardId = (int) $wardOfficer['assigned_ward_id'];
$wardOfficerName = !empty($wardOfficer['full_name'])
    ? $wardOfficer['full_name']
    : ($wardOfficer['user_name'] ?? 'Ward Officer');

$_SESSION['user_name'] = $wardOfficerName;

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
    if ($_GET['status'] === 'forwarded') {
        $successMessage = "Citizen objection forwarded to Inspector successfully.";
    } elseif ($_GET['status'] === 'rejected') {
        $successMessage = "Citizen objection rejected successfully. Complaint remains Solved.";
    }
}

if (isset($_GET['error'])) {
    $errorMessage = "Could not process the objection. Please try again.";
}

/* =========================
   Ward Decision Action
========================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $reopenId = isset($_POST['reopen_id']) ? (int) $_POST['reopen_id'] : 0;
    $complaintId = isset($_POST['complaint_id']) ? (int) $_POST['complaint_id'] : 0;
    $wardNote = trim($_POST['ward_note'] ?? '');
    $decision = $_POST['ward_action'] ?? '';

    $allowedDecisions = ['accept', 'reject'];

    if ($reopenId <= 0 || $complaintId <= 0 || !in_array($decision, $allowedDecisions, true)) {
        header("Location: citizen-objections.php" . woBuildQuery($search, $areaId, $sort, $page, '', 'invalid_action'));
        exit();
    }

    mysqli_begin_transaction($conn);

    try {
        $allowedRequest = woFetchOne(
            $conn,
            "SELECT 
                rr.reopen_id,
                rr.complaint_id,
                rr.request_status,
                rr.requested_by,
                c.complaint_status
            FROM reopen_requests rr
            INNER JOIN complaints c ON c.complaint_id = rr.complaint_id
            INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
            WHERE rr.reopen_id = ?
            AND rr.complaint_id = ?
            AND ca.ward_id = ?
            AND rr.request_status = 'pending'
            AND c.complaint_status IN ('disputed', 'closed')
            LIMIT 1",
            "iii",
            [$reopenId, $complaintId, $assignedWardId]
        );

        if (!$allowedRequest) {
            throw new Exception("This objection is not available for ward review.");
        }

        if ($decision === 'accept') {
            $newRequestStatus = 'resolved';
            $newComplaintStatus = 'reopened';
            $redirectStatus = 'reopened';
            $updateReopenSql = "UPDATE reopen_requests
                SET request_status = ?,
                    ward_note = ?,
                    handled_by = ?,
                    forwarded_at = NOW()
                WHERE reopen_id = ?";

            $stmt = woPrepareOrThrow(
                $conn,
                $updateReopenSql,
                "Accept objection: update reopen_requests"
            );

            mysqli_stmt_bind_param($stmt, "ssii", $newRequestStatus, $wardNote, $userId, $reopenId);
            woExecuteOrThrow($conn, $stmt, "Accept objection: update reopen_requests", $updateReopenSql);
            mysqli_stmt_close($stmt);
            
            // CRITICAL FIX: Set maintenance proof to rejected so they can re-upload
            $rejectProofSql = "UPDATE maintenance_proofs SET proof_status = 'rejected' WHERE complaint_id = $complaintId AND proof_stage = 'after'";
            woQueryOrThrow($conn, $rejectProofSql, "Accept objection: reject after proof");

            require_once "../../includes/disciplinary_helpers.php";
            $inspectorId = woFetchOne($conn, "SELECT inspector_user_id FROM inspection_logs WHERE complaint_id = ? ORDER BY log_id DESC LIMIT 1", "i", [$complaintId])['inspector_user_id'] ?? null;
            $teamId = woFetchOne($conn, "SELECT maintenance_team_id FROM complaint_assignments WHERE complaint_id = ? ORDER BY assignment_id DESC LIMIT 1", "i", [$complaintId])['maintenance_team_id'] ?? null;
            
            if ($teamId) {
                $teamLeaderId = woFetchOne($conn, "SELECT user_id FROM maintenance_team_members WHERE maintenance_team_id = ? AND role = 'team_leader' AND status = 'active' LIMIT 1", "i", [$teamId])['user_id'] ?? null;
                if ($teamLeaderId) {
                    addDemerit($conn, $teamLeaderId, null, 'team_leader', 'team_leader', $complaintId, $teamId, 'citizen_objection_true', $wardNote, $userId, 'ward_officer');
                }
                
                $members = woFetchAll($conn, "SELECT user_id, member_id FROM maintenance_team_members WHERE maintenance_team_id = ? AND status = 'active' AND role != 'team_leader'", "i", [$teamId]);
                foreach ($members as $member) {
                    applyTeamMemberWarningOrDemerit($conn, $member['member_id'], $member['user_id'], $teamId, $complaintId, 'citizen_objection_true', $wardNote, $userId, 'ward_officer');
                }
            }
            if ($inspectorId) {
                addDemerit($conn, $inspectorId, null, 'inspector', 'inspector', $complaintId, $teamId, 'citizen_objection_true', $wardNote, $userId, 'ward_officer');
            }

            $citNotifMsg = "Ward Officer accepted your objection for complaint. Your claim has been marked as true. The complaint is reopened.";
            $citNotifSql = "INSERT INTO citizen_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'ward_citizen_claim_true', 'Citizen Claim Marked True', ?, 0, NOW())";
            $notifStmt = mysqli_prepare($conn, $citNotifSql);
            if ($notifStmt) {
                dg_cleanup_workflow_notifications($conn, "citizen_notifications", (int)$allowedRequest['requested_by'], $complaintId, "ward_citizen_claim_true");
                mysqli_stmt_bind_param($notifStmt, "iiis", $allowedRequest['requested_by'], $userId, $complaintId, $citNotifMsg);
                mysqli_stmt_execute($notifStmt);
                mysqli_stmt_close($notifStmt);
            }

            // Central Officer notification (Core Rule)
            $cenRowCO = woFetchOne($conn, "SELECT ca.assigned_by FROM complaint_assignments ca JOIN users u ON u.user_id = ca.assigned_by WHERE ca.complaint_id = ? AND u.user_role = 'central_officer' LIMIT 1", "i", [$complaintId]);
            if ($cenRowCO) {
                $cenUserIdCO = (int)$cenRowCO['assigned_by'];
                $cenMsgCO = "Ward Officer marked the citizen claim as true for complaint. The objection has been accepted and case reopened.";
                $cenNotifCO = mysqli_prepare($conn, "INSERT INTO central_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'ward_citizen_claim_true', 'Citizen Claim Marked True', ?, 0, NOW())");
                if ($cenNotifCO) { dg_cleanup_workflow_notifications($conn, "central_notifications", $cenUserIdCO, $complaintId, "ward_citizen_claim_true"); mysqli_stmt_bind_param($cenNotifCO, "iiis", $cenUserIdCO, $userId, $complaintId, $cenMsgCO); mysqli_stmt_execute($cenNotifCO); mysqli_stmt_close($cenNotifCO); }
            }

            // Inspector notification (Core Rule)
            $insRowCO = woFetchOne($conn, "SELECT inspector_user_id FROM inspection_logs WHERE complaint_id = ? ORDER BY log_id DESC LIMIT 1", "i", [$complaintId]);
            if ($insRowCO) {
                $insUserIdCO = (int)$insRowCO['inspector_user_id'];
                $insMsgCO = "Ward Officer marked the citizen claim as true for this complaint. Please check the updated case status in Inspection Logs.";
                $insNotifCO = mysqli_prepare($conn, "INSERT INTO inspector_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'ward_citizen_claim_true', 'Citizen Claim Marked True', ?, 0, NOW())");
                if ($insNotifCO) { dg_cleanup_workflow_notifications($conn, "inspector_notifications", $insUserIdCO, $complaintId, "ward_citizen_claim_true"); mysqli_stmt_bind_param($insNotifCO, "iiis", $insUserIdCO, $userId, $complaintId, $insMsgCO); mysqli_stmt_execute($insNotifCO); mysqli_stmt_close($insNotifCO); }
            }

        } else {
            $newRequestStatus = 'rejected';
            $newComplaintStatus = 'final_rejected';
            $redirectStatus = 'rejected';
            $updateReopenSql = "UPDATE reopen_requests
                SET request_status = ?,
                    ward_note = ?,
                    handled_by = ?,
                    handled_at = NOW()
                WHERE reopen_id = ?";

            $stmt = woPrepareOrThrow(
                $conn,
                $updateReopenSql,
                "Reject objection: update reopen_requests"
            );

            mysqli_stmt_bind_param($stmt, "ssii", $newRequestStatus, $wardNote, $userId, $reopenId);
            woExecuteOrThrow($conn, $stmt, "Reject objection: update reopen_requests", $updateReopenSql);
            mysqli_stmt_close($stmt);

            require_once "../../includes/disciplinary_helpers.php";
            $citizenUserId = $allowedRequest['requested_by'];
            if ($citizenUserId) {
                addDemerit($conn, $citizenUserId, null, 'citizen', 'citizen', $complaintId, null, 'citizen_objection_false', $wardNote, $userId, 'ward_officer');
            }

            $citNotifMsg = "Ward Officer reviewed your objection for this complaint. Your claim has been marked as false. The complaint remains closed.";
            $citNotifSql = "INSERT INTO citizen_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'ward_citizen_claim_false', 'Citizen Claim Marked False', ?, 0, NOW())";
            $notifStmt = mysqli_prepare($conn, $citNotifSql);
            if ($notifStmt) {
                dg_cleanup_workflow_notifications($conn, "citizen_notifications", $citizenUserId, $complaintId, "ward_citizen_claim_false");
                mysqli_stmt_bind_param($notifStmt, "iiis", $citizenUserId, $userId, $complaintId, $citNotifMsg);
                mysqli_stmt_execute($notifStmt);
                mysqli_stmt_close($notifStmt);
            }

            // Central Officer notification (Core Rule)
            $cenRowCF = woFetchOne($conn, "SELECT ca.assigned_by FROM complaint_assignments ca JOIN users u ON u.user_id = ca.assigned_by WHERE ca.complaint_id = ? AND u.user_role = 'central_officer' LIMIT 1", "i", [$complaintId]);
            if ($cenRowCF) {
                $cenUserIdCF = (int)$cenRowCF['assigned_by'];
                $cenMsgCF = "Ward Officer marked the citizen claim as false for this complaint. The objection was rejected.";
                $cenNotifCF = mysqli_prepare($conn, "INSERT INTO central_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'ward_citizen_claim_false', 'Citizen Claim Marked False', ?, 0, NOW())");
                if ($cenNotifCF) { dg_cleanup_workflow_notifications($conn, "central_notifications", $cenUserIdCF, $complaintId, "ward_citizen_claim_false"); mysqli_stmt_bind_param($cenNotifCF, "iiis", $cenUserIdCF, $userId, $complaintId, $cenMsgCF); mysqli_stmt_execute($cenNotifCF); mysqli_stmt_close($cenNotifCF); }
            }

            // Inspector notification (Core Rule)
            $insRowCF = woFetchOne($conn, "SELECT inspector_user_id FROM inspection_logs WHERE complaint_id = ? ORDER BY log_id DESC LIMIT 1", "i", [$complaintId]);
            if ($insRowCF) {
                $insUserIdCF = (int)$insRowCF['inspector_user_id'];
                $insMsgCF = "Ward Officer marked the citizen claim as false for this complaint. Please check the updated case in Inspection Logs.";
                $insNotifCF = mysqli_prepare($conn, "INSERT INTO inspector_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'ward_citizen_claim_false', 'Citizen Claim Marked False', ?, 0, NOW())");
                if ($insNotifCF) { dg_cleanup_workflow_notifications($conn, "inspector_notifications", $insUserIdCF, $complaintId, "ward_citizen_claim_false"); mysqli_stmt_bind_param($insNotifCF, "iiis", $insUserIdCF, $userId, $complaintId, $insMsgCF); mysqli_stmt_execute($insNotifCF); mysqli_stmt_close($insNotifCF); }
            }
        }

        woNotifyMaintenanceObjectionDecision($conn, $complaintId, $userId, $decision, $wardNote);

        $updateComplaintSql = "UPDATE complaints
            SET complaint_status = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE complaint_id = ?";

        $stmt = woPrepareOrThrow(
            $conn,
            $updateComplaintSql,
            "Ward objection: update complaints"
        );

        mysqli_stmt_bind_param($stmt, "si", $newComplaintStatus, $complaintId);
        woExecuteOrThrow($conn, $stmt, "Ward objection: update complaints", $updateComplaintSql);
        mysqli_stmt_close($stmt);

        if (!mysqli_commit($conn)) {
            woLogDbFailure("Ward objection: transaction commit failed", $conn);
            throw new Exception("Ward objection commit failed");
        }

        header("Location: citizen-objections.php" . woBuildQuery($search, $areaId, $sort, $page, $redirectStatus, ''));
        exit();

    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("[DrainGuard citizen-objections] action_failed decision={$decision} reopen_id={$reopenId} complaint_id={$complaintId}: " . $e->getMessage());
        header("Location: citizen-objections.php" . woBuildQuery($search, $areaId, $sort, $page, '', 'action_failed'));
        exit();
    }
}

/* =========================
   Area Dropdown
========================= */

$areaRows = woFetchAll(
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
    AND rr.request_status = 'pending'
    AND c.complaint_status IN ('disputed', 'closed')
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

$countRow = woFetchOne(
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
    AND rr.request_status = 'pending'
    AND c.complaint_status IN ('disputed', 'closed')
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

$orderBy = "ORDER BY rr.created_at DESC";

if ($sort === 'oldest') {
    $orderBy = "ORDER BY rr.created_at ASC";
} elseif ($sort === 'priority_high') {
    $orderBy = "ORDER BY FIELD(ca.assignment_priority, 'High', 'Medium', 'Low'), rr.created_at DESC";
} elseif ($sort === 'priority_low') {
    $orderBy = "ORDER BY FIELD(ca.assignment_priority, 'Low', 'Medium', 'High'), rr.created_at DESC";
}

$objectionSql = "
    SELECT
        rr.reopen_id,
        rr.complaint_id,
        rr.requested_by,
        rr.request_type,
        rr.reason,
        rr.ward_note,
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
        latest_proof.proof_note AS maintenance_proof_note,

        f.rating AS citizen_rating

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

        LEFT JOIN feedbacks f 
            ON f.complaint_id = rr.complaint_id 
            AND f.user_id = rr.requested_by 
            AND f.feedback_type = 'false_completion'

        $whereSql

    GROUP BY rr.reopen_id

    $orderBy

    LIMIT ? OFFSET ?
";

$types .= "ii";
$params[] = $perPage;
$params[] = $offset;

$objections = woFetchAll($conn, $objectionSql, $types, $params);

/* =========================
   Media Per Complaint
========================= */

$beforeMedia = [];
$afterMedia = [];

foreach ($objections as $item) {
    $cid = (int) $item['complaint_id'];

    $beforeMedia[$cid] = woFetchAll(
        $conn,
        "SELECT media_id, media_type, media_path, original_name
        FROM complaint_media
        WHERE complaint_id = ?
        ORDER BY uploaded_at ASC
        LIMIT 3",
        "i",
        [$cid]
    );

    $afterMedia[$cid] = woFetchAll(
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
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
    <link rel="stylesheet" href="../../css/ward/citizen-objections.css">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="ward">

    <div class="ward-layout">

        <?php include __DIR__ . '/../../includes/ward/sidebar.php'; ?>

        <main class="ward-main">

            <?php include __DIR__ . '/../../includes/ward/topbar.php'; ?>

            <section class="ward-objections-page">

                <div class="page-heading">
                    <h1>Citizen Objections</h1>
                    <p>Review citizen objections first, then forward valid cases to Inspector</p>
                </div>

                <?php if (!empty($successMessage)): ?>
                    <div class="page-success-alert">
                        <?php echo woText($successMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errorMessage)): ?>
                    <div class="page-error-alert">
                        <?php echo woText($errorMessage); ?>
                    </div>
                <?php endif; ?>

                <div class="status-alert">
                    <div class="status-alert-icon">
                        <i class="bi bi-chat-left-text"></i>
                    </div>

                    <div>
                        <h3><?php echo $totalObjections; ?> Pending Citizen Objections</h3>
                        <p>Forward only valid objections to Inspector for field recheck</p>
                    </div>
                </div>

                <form method="GET" class="filter-card" id="wardObjectionsFilterForm">

                    <input
                        type="text"
                        name="search"
                        class="filter-control"
                        placeholder="Search by complaint code, citizen, issue, area..."
                        value="<?php echo woText($search); ?>">

                    <select name="area_id" class="filter-control">
                        <option value="">All Areas</option>

                        <?php foreach ($areaRows as $area): ?>
                            <option value="<?php echo (int) $area['area_id']; ?>"
                                <?php echo ($areaId !== '' && (int) $areaId === (int) $area['area_id']) ? 'selected' : ''; ?>>
                                <?php echo woText($area['area_name']); ?>
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

                            <article class="objection-card"
                                     data-complaint-id="<?php echo (int) $item['complaint_id']; ?>"
                                     data-complaint-code="<?php echo woText($item['complaint_code']); ?>"
                                     data-notification-target="<?php echo (int) $item['complaint_id']; ?>">

                                <div class="objection-top">
                                    <div>
                                        <div class="case-meta-row">
                                            <span class="case-code">
                                                <?php echo woText($item['complaint_code']); ?>
                                            </span>

                                            <span class="priority-badge <?php echo woPriorityClass($priority); ?>">
                                                <?php echo woText($priority); ?>
                                            </span>

                                            <span class="status-badge">
                                                <?php echo woText(woRequestStatusLabel($item['request_status'])); ?>
                                            </span>
                                        </div>

                                        <h3><?php echo woText($item['issue_name'] ?: 'Drainage Issue'); ?></h3>

                                        <p class="location-line">
                                            <?php echo woText($item['area_name'] ?: 'N/A'); ?>,
                                            Ward <?php echo woText($item['ward_no'] ?: 'N/A'); ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="info-grid">

                                    <div>
                                        <span>Submitted By</span>
                                        <strong><?php echo woText($item['citizen_name'] ?: 'Citizen'); ?></strong>
                                    </div>

                                    <div>
                                        <span>Objection Date</span>
                                        <strong><?php echo woText(woDate($item['objection_created_at'])); ?></strong>
                                    </div>

                                    <div>
                                        <span>Previous Approved Date</span>
                                        <strong><?php echo woText(woDate($item['approved_at'] ?: $item['complaint_updated_at'])); ?></strong>
                                    </div>

                                    <div>
                                        <span>Maintenance Team</span>
                                        <strong><?php echo woText($item['team_name'] ?: 'No Team Assigned'); ?></strong>
                                    </div>

                                    <div>
                                        <span>Complaint Status</span>
                                        <strong><?php echo woText(woStatusLabel($item['complaint_status'])); ?></strong>
                                    </div>

                                    <div>
                                        <span>Citizen Phone</span>
                                        <strong><?php echo woText($item['citizen_phone'] ?: 'N/A'); ?></strong>
                                    </div>


                                    <div>
                                        <span>Citizen Rating</span>
                                        <strong>
                                            <?php 
                                            $rating = (int)($item['citizen_rating'] ?? 0);
                                            if ($rating > 0) {
                                                echo $rating . ' / 5 <i class="bi bi-star-fill text-warning"></i>';
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </strong>
                                    </div>

                                </div>

                                <div class="reason-box citizen-reason">
                                    <span>Citizen Objection Reason</span>
                                    <p><?php echo woText($item['reason'] ?: 'No objection reason provided.'); ?></p>
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
                                                    <?php $path = woMediaPath($media['media_path']); ?>

                                                    <div class="media-box">
                                                        <?php if ($media['media_type'] === 'video'): ?>
                                                            <video controls>
                                                                <source src="<?php echo woText($path); ?>">
                                                            </video>
                                                        <?php else: ?>
                                                            <img src="<?php echo woText($path); ?>" alt="Complaint media">
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
                                                    <?php $path = woMediaPath($proof['media_path']); ?>

                                                    <div class="media-box">
                                                        <?php if ($proof['media_type'] === 'video'): ?>
                                                            <video controls>
                                                                <source src="<?php echo woText($path); ?>">
                                                            </video>
                                                        <?php else: ?>
                                                            <img src="<?php echo woText($path); ?>" alt="Maintenance proof">
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
                                    <input type="hidden" name="search" value="<?php echo woText($search); ?>">
                                    <input type="hidden" name="area_id" value="<?php echo woText($areaId); ?>">
                                    <input type="hidden" name="sort" value="<?php echo woText($sort); ?>">
                                    <input type="hidden" name="page" value="<?php echo (int) $page; ?>">

                                    <label class="note-label" for="ward_note_<?php echo (int) $item['reopen_id']; ?>">
                                        Ward Officer Review Note
                                    </label>

                                    <textarea
                                        id="ward_note_<?php echo (int) $item['reopen_id']; ?>"
                                        name="ward_note"
                                        class="ward-note-input"
                                        rows="3"
                                        placeholder="Write why this objection is valid or why it should be rejected..."><?php echo woText($item['ward_note'] ?? ''); ?></textarea>

                                    <div class="action-row">
                                        <button type="submit" name="ward_action" value="accept" class="action-btn forward-btn" style="background-color: var(--warning-color); border-color: var(--warning-color);">
                                            <i class="bi bi-arrow-clockwise"></i>
                                            Accept & Reopen
                                        </button>

                                        <button type="submit" name="ward_action" value="reject" class="action-btn reject-btn">
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
                            <p>No pending citizen objection is waiting for ward review.</p>
                        </div>

                    <?php endif; ?>

                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination-wrap">

                        <?php if ($page > 1): ?>
                            <a href="<?php echo woText(woBuildPageUrl($page - 1, $search, $areaId, $sort)); ?>" class="page-btn">
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
                                <a href="<?php echo woText(woBuildPageUrl($i, $search, $areaId, $sort)); ?>"
                                   class="page-number <?php echo ($i === $page) ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?php echo woText(woBuildPageUrl($page + 1, $search, $areaId, $sort)); ?>" class="page-btn">
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

        </main>

    </div>

    <script src="../../js/ward/sidebar.js"></script>
    <script src="../../js/ward/citizen-objections.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>

</html>
