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
$activePage = 'inspection-queue';
$pageTitle = 'Inspection Queue';

/* =========================
   Helper Functions
========================= */

function iqBindParams($stmt, $types, &$params)
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

function iqFetchOne($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    iqBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function iqFetchAll($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    iqBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function iqText($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function iqMediaPath($path)
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

function iqDate($datetime)
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

function iqPriorityClass($priority)
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

function iqStatusLabel($status)
{
    $map = [
        'submitted' => 'Submitted',
        'received' => 'Received',
        'pending_verification' => 'Ward Officer Pending Verification',
        'verified' => 'Verified / Waiting for Ward Team Assignment',
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

function iqBuildQuery($search, $teamId, $areaId, $sort, $page = 1, $status = '', $error = '')
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

function iqBuildPageUrl($page, $search, $teamId, $areaId, $sort)
{
    return 'inspection-queue.php' . iqBuildQuery($search, $teamId, $areaId, $sort, (int) $page);
}

function iqUpdateMemberPenalty($conn, $member, $complaintId, $assignmentId, $teamId, $issuedBy, $reason)
{
    $memberId = (int) $member['member_id'];
    $role = $member['role'];
    $currentDemerit = (int) $member['demerit_points'];
    $currentWarning = (int) $member['warning_count'];

    $penaltyType = 'warning';

    if ($role === 'team_leader') {
        $newDemerit = $currentDemerit + 1;
        $newWarning = $currentWarning;

        if ($newDemerit >= 3) {
            $newStatus = 'suspended';
            $penaltyType = 'suspension';
        } elseif ($newDemerit >= 2) {
            $newStatus = 'under_review';
            $penaltyType = 'demerit';
        } else {
            $newStatus = 'active';
            $penaltyType = 'demerit';
        }
    } else {
        $newWarning = $currentWarning + 1;

        if ($currentWarning >= 1) {
            $newDemerit = $currentDemerit + 1;

            if ($newDemerit >= 3) {
                $newStatus = 'suspended';
                $penaltyType = 'suspension';
            } elseif ($newDemerit >= 2) {
                $newStatus = 'under_review';
                $penaltyType = 'demerit';
            } else {
                $newStatus = 'active';
                $penaltyType = 'demerit';
            }
        } else {
            $newDemerit = $currentDemerit;
            $newStatus = 'active';
            $penaltyType = 'warning';
        }
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE maintenance_team_members
        SET demerit_points = ?,
            warning_count = ?,
            member_status = ?
        WHERE member_id = ?"
    );

    if (!$stmt) {
        throw new Exception(mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, "iisi", $newDemerit, $newWarning, $newStatus, $memberId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $logStmt = mysqli_prepare(
        $conn,
        "INSERT INTO maintenance_member_penalties
        (complaint_id, assignment_id, maintenance_team_id, member_id, issued_by, penalty_type, penalty_reason)
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$logStmt) {
        throw new Exception(mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $logStmt,
        "iiiiiss",
        $complaintId,
        $assignmentId,
        $teamId,
        $memberId,
        $issuedBy,
        $penaltyType,
        $reason
    );

    mysqli_stmt_execute($logStmt);
    mysqli_stmt_close($logStmt);

    if ($role === 'team_leader' && $newStatus === 'suspended' && !empty($member['user_id'])) {
        $memberUserId = (int) $member['user_id'];

        $loginStmt = mysqli_prepare(
            $conn,
            "UPDATE users
            SET login_access = 0
            WHERE user_id = ?"
        );

        if (!$loginStmt) {
            throw new Exception(mysqli_error($conn));
        }

        mysqli_stmt_bind_param($loginStmt, "i", $memberUserId);
        mysqli_stmt_execute($loginStmt);
        mysqli_stmt_close($loginStmt);
    }
}

/* =========================
   Inspector Info
========================= */

$inspector = iqFetchOne(
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
$teamId = trim($_GET['team_id'] ?? $_POST['team_id'] ?? '');
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
    if ($_GET['status'] === 'approved') {
        $successMessage = "Complaint approved successfully. Status changed to Solved.";
    } elseif ($_GET['status'] === 'false_completion') {
        $successMessage = "False completion confirmed. Case sent to Ward Officer for local team assignment.";
    }
}

if (isset($_GET['error'])) {
    $errorMessage = "Could not complete inspection decision. Please try again.";
}

/* =========================
   Inspector Decision Action
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $complaintId = isset($_POST['complaint_id']) ? (int) $_POST['complaint_id'] : 0;
    $action = trim($_POST['inspection_action'] ?? '');
    $inspectionNote = trim($_POST['inspection_note'] ?? '');

    $allowedActions = ['approve', 'false_completion'];

    if ($complaintId <= 0 || !in_array($action, $allowedActions, true)) {
        header("Location: inspection-queue.php" . iqBuildQuery($search, $teamId, $areaId, $sort, $page, '', 'invalid_action'));
        exit();
    }

    mysqli_begin_transaction($conn);

    try {
        $allowedComplaint = iqFetchOne(
            $conn,
            "SELECT 
                c.complaint_id,
                c.complaint_status,
                ca.assignment_id,
                ca.maintenance_team_id
            FROM complaints c
            INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
            WHERE c.complaint_id = ?
            AND ca.ward_id = ?
            AND c.complaint_status = 'inspector_verification'
            LIMIT 1",
            "ii",
            [$complaintId, $assignedWardId]
        );

        if (!$allowedComplaint) {
            throw new Exception("This complaint is not available in your inspection queue.");
        }

        $assignmentId = (int) $allowedComplaint['assignment_id'];
        $maintenanceTeamId = (int) $allowedComplaint['maintenance_team_id'];

        if ($action === 'approve') {
            $newStatus = 'closed';

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE complaints
                SET complaint_status = ?,
                    updated_at = CURRENT_TIMESTAMP,
                    closed_at = CURRENT_TIMESTAMP
                WHERE complaint_id = ?"
            );

            if (!$stmt) {
                throw new Exception(mysqli_error($conn));
            }

            mysqli_stmt_bind_param($stmt, "si", $newStatus, $complaintId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $proofStatus = 'accepted';

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE maintenance_proofs
                SET proof_status = ?
                WHERE complaint_id = ?
                AND proof_stage = 'after'"
            );

            if (!$stmt) {
                throw new Exception(mysqli_error($conn));
            }

            mysqli_stmt_bind_param($stmt, "si", $proofStatus, $complaintId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $assignmentStatus = 'completed';
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE complaint_assignments
                SET assignment_status = ?
                WHERE assignment_id = ?"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "si", $assignmentStatus, $assignmentId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }


            // Fetch notification recipients
            $citizenUserId = 0;
            $centralOfficerUserId = 0;
            $wardOfficerUserId = 0;
            $maintenanceTeamMembers = [];
            $complaintCode = '';
            $locId = 0;

            $fetchSql = "
                SELECT 
                    c.complaint_code, 
                    c.user_id AS citizen_id, 
                    c.loc_id,
                    (SELECT assigned_by FROM complaint_assignments WHERE complaint_id = c.complaint_id AND assignment_status = 'ward_assigned' LIMIT 1) AS central_officer_id
                FROM complaints c
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
                $msg = "Your complaint has been approved by Inspector and marked as solved.";
                $ins = mysqli_prepare($conn, "INSERT INTO citizen_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'system', 'Complaint Solved', ?, 0, ?)");
                if ($ins) {
                    mysqli_stmt_bind_param($ins, "iiiss", $citizenUserId, $userId, $complaintId, $msg, $notifTime);
                    mysqli_stmt_execute($ins);
                    mysqli_stmt_close($ins);
                }
            }

            // Central Officer
            if ($centralOfficerUserId > 0) {
                $msg = "Inspector approved work for a complaint routed through your panel. ({$complaintCode})";
                $ins = mysqli_prepare($conn, "INSERT INTO central_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'system', 'Work Approved', ?, 0, ?)");
                if ($ins) {
                    mysqli_stmt_bind_param($ins, "iiiss", $centralOfficerUserId, $userId, $complaintId, $msg, $notifTime);
                    mysqli_stmt_execute($ins);
                    mysqli_stmt_close($ins);
                }
            }

            // Ward Officer
            if ($wardOfficerUserId > 0) {
                $msg = "Inspector approved the completed work for your assigned ward complaint {$complaintCode}.";
                $ins = mysqli_prepare($conn, "INSERT INTO ward_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'system', 'Work Approved', ?, 0, ?)");
                if ($ins) {
                    mysqli_stmt_bind_param($ins, "iiiss", $wardOfficerUserId, $userId, $complaintId, $msg, $notifTime);
                    mysqli_stmt_execute($ins);
                    mysqli_stmt_close($ins);
                }
            }

            // Maintenance Team
            foreach ($maintenanceTeamMembers as $memberId) {
                $msg = "Inspector approved your completed work for complaint {$complaintCode}.";
                $ins = mysqli_prepare($conn, "INSERT INTO maintenance_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'system', 'Work Approved', ?, 0, ?)");
                if ($ins) {
                    mysqli_stmt_bind_param($ins, "iiiss", $memberId, $userId, $complaintId, $msg, $notifTime);
                    mysqli_stmt_execute($ins);
                    mysqli_stmt_close($ins);
                }
            }

            mysqli_commit($conn);

            header("Location: inspection-queue.php" . iqBuildQuery($search, $teamId, $areaId, $sort, $page, 'approved', ''));
            exit();
        }

        if ($action === 'false_completion') {
            if ($inspectionNote === '') {
                throw new Exception("Inspector note is required for false completion.");
            }

            // 1. Change status to disputed
            $newStatus = 'disputed';
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
            mysqli_stmt_bind_param($stmt, "si", $newStatus, $complaintId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Fetch notification recipients & data
            $wardOfficerUserId = 0;
            $maintenanceTeamMembers = [];
            $teamLeaderUserId = 0;
            $complaintCode = '';
            $locId = 0;

            $fetchSql = "SELECT complaint_code, loc_id FROM complaints WHERE complaint_id = ? LIMIT 1";
            $stmtC = mysqli_prepare($conn, $fetchSql);
            if ($stmtC) {
                mysqli_stmt_bind_param($stmtC, "i", $complaintId);
                mysqli_stmt_execute($stmtC);
                $resC = mysqli_stmt_get_result($stmtC);
                if ($rowC = mysqli_fetch_assoc($resC)) {
                    $complaintCode = $rowC['complaint_code'];
                    $locId = (int)$rowC['loc_id'];
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
                $mtSql = "SELECT user_id, role FROM maintenance_team_members WHERE maintenance_team_id = ? AND status = 'active'";
                $stmtMt = mysqli_prepare($conn, $mtSql);
                if ($stmtMt) {
                    mysqli_stmt_bind_param($stmtMt, "i", $maintenanceTeamId);
                    mysqli_stmt_execute($stmtMt);
                    $resMt = mysqli_stmt_get_result($stmtMt);
                    while ($rowMt = mysqli_fetch_assoc($resMt)) {
                        $maintenanceTeamMembers[] = (int)$rowMt['user_id'];
                        if ($rowMt['role'] === 'team_leader') {
                            $teamLeaderUserId = (int)$rowMt['user_id'];
                        }
                    }
                    mysqli_stmt_close($stmtMt);
                }
            }

            // Insert false completion review
            $reviewStmt = mysqli_prepare(
                $conn,
                "INSERT INTO false_completion_reviews 
                (complaint_id, inspector_user_id, ward_officer_user_id, maintenance_team_id, team_leader_user_id, inspector_claim_status, inspector_claim_note) 
                VALUES (?, ?, ?, ?, ?, 'pending', ?)"
            );
            if (!$reviewStmt) {
                throw new Exception(mysqli_error($conn));
            }
            $woIdForDb = $wardOfficerUserId > 0 ? $wardOfficerUserId : null;
            mysqli_stmt_bind_param($reviewStmt, "iiiiis", $complaintId, $userId, $woIdForDb, $maintenanceTeamId, $teamLeaderUserId, $inspectionNote);
            mysqli_stmt_execute($reviewStmt);
            mysqli_stmt_close($reviewStmt);

            // Notifications
            $notifTime = date('Y-m-d H:i:s');

            if ($wardOfficerUserId > 0) {
                $msg = "Inspector reported possible false completion for complaint {$complaintCode}. Please review this claim.";
                $ins = mysqli_prepare($conn, "INSERT INTO ward_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'system', 'False Completion Claim', ?, 0, ?)");
                if ($ins) {
                    mysqli_stmt_bind_param($ins, "iiiss", $wardOfficerUserId, $userId, $complaintId, $msg, $notifTime);
                    mysqli_stmt_execute($ins);
                    mysqli_stmt_close($ins);
                }
            }

            foreach ($maintenanceTeamMembers as $memberId) {
                $msg = "Inspector reported possible false completion for your submitted work on complaint {$complaintCode}. Ward Officer will review the claim.";
                $ins = mysqli_prepare($conn, "INSERT INTO maintenance_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'system', 'False Completion Claim', ?, 0, ?)");
                if ($ins) {
                    mysqli_stmt_bind_param($ins, "iiiss", $memberId, $userId, $complaintId, $msg, $notifTime);
                    mysqli_stmt_execute($ins);
                    mysqli_stmt_close($ins);
                }
            }

            mysqli_commit($conn);

            header("Location: inspection-queue.php" . iqBuildQuery($search, $teamId, $areaId, $sort, $page, 'false_completion', ''));
            exit();
        }

    } catch (Exception $e) {
        mysqli_rollback($conn);
        header("Location: inspection-queue.php" . iqBuildQuery($search, $teamId, $areaId, $sort, $page, '', 'action_failed'));
        exit();
    }
}

/* =========================
   Dropdown Data
========================= */

$teamRows = iqFetchAll(
    $conn,
    "SELECT DISTINCT
        mt.maintenance_team_id,
        mt.team_name
    FROM maintenance_teams mt
    INNER JOIN complaint_assignments ca ON ca.maintenance_team_id = mt.maintenance_team_id
    INNER JOIN complaints c ON c.complaint_id = ca.complaint_id
    WHERE ca.ward_id = ?
    AND c.complaint_status = 'inspector_verification'
    ORDER BY mt.team_name ASC",
    "i",
    [$assignedWardId]
);

$areaRows = iqFetchAll(
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
    AND c.complaint_status = 'inspector_verification'
";

$countTypes = "i";
$countParams = [$assignedWardId];

if ($search !== '') {
    $countWhereSql .= "
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

    $countTypes .= "ssssss";
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
    $countParams[] = $searchTerm;
}

if ($teamId !== '' && ctype_digit($teamId)) {
    $countWhereSql .= " AND ca.maintenance_team_id = ?";
    $countTypes .= "i";
    $countParams[] = (int) $teamId;
}

if ($areaId !== '' && ctype_digit($areaId)) {
    $countWhereSql .= " AND a.area_id = ?";
    $countTypes .= "i";
    $countParams[] = (int) $areaId;
}

$countRow = iqFetchOne(
    $conn,
    "SELECT COUNT(DISTINCT c.complaint_id) AS total
    FROM complaints c
    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN issues issue ON issue.issue_id = c.issue_id
    LEFT JOIN locations l ON l.loc_id = c.loc_id
    LEFT JOIN areas a ON a.area_id = l.area_id
    LEFT JOIN drains d ON d.drain_id = c.drain_id
    $countWhereSql",
    $countTypes,
    $countParams
);

$totalQueue = $countRow ? (int) $countRow['total'] : 0;
$totalPages = (int) ceil($totalQueue / $perPage);

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
    AND c.complaint_status = 'inspector_verification'
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

$queueSql = "
    SELECT
        c.complaint_id,
        c.complaint_code,
        c.problem_description,
        c.address_description,
        c.complaint_status,
        c.submitted_at,
        c.updated_at,

        ca.assignment_id,
        ca.assignment_priority,
        ca.task_note,
        ca.assigned_at,
        ca.deadline_at,
        ca.maintenance_team_id,

        mt.team_name,

        issue.issue_name,

        w.ward_no,
        w.ward_name,

        a.area_id,
        a.area_name,

        d.drain_code,
        d.drain_name,
        d.drain_condition,

        after_proof.proof_id,
        after_proof.media_path AS after_media_path,
        after_proof.media_type AS after_media_type,
        after_proof.proof_note,
        after_proof.uploaded_at AS after_uploaded_at,

        COALESCE(proof_count.total_proofs, 0) AS total_proofs

    FROM complaints c

    INNER JOIN complaint_assignments ca ON ca.complaint_id = c.complaint_id

    LEFT JOIN maintenance_teams mt ON mt.maintenance_team_id = ca.maintenance_team_id

    LEFT JOIN issues issue ON issue.issue_id = c.issue_id

    LEFT JOIN locations l ON l.loc_id = c.loc_id

    LEFT JOIN areas a ON a.area_id = l.area_id

    LEFT JOIN wards w ON w.ward_id = ca.ward_id

    LEFT JOIN drains d ON d.drain_id = c.drain_id

    LEFT JOIN (
        SELECT mp1.*
        FROM maintenance_proofs mp1
        INNER JOIN (
            SELECT complaint_id, MAX(proof_id) AS latest_proof_id
            FROM maintenance_proofs
            WHERE proof_stage = 'after'
            AND media_path IS NOT NULL
            AND media_path <> ''
            GROUP BY complaint_id
        ) latest ON latest.latest_proof_id = mp1.proof_id
    ) after_proof ON after_proof.complaint_id = c.complaint_id

    LEFT JOIN (
        SELECT complaint_id, COUNT(proof_id) AS total_proofs
        FROM maintenance_proofs
        WHERE proof_stage = 'after'
        AND media_path IS NOT NULL
        AND media_path <> ''
        GROUP BY complaint_id
    ) proof_count ON proof_count.complaint_id = c.complaint_id

    $whereSql

    GROUP BY c.complaint_id

    $orderBy

    LIMIT ? OFFSET ?
";

$types .= "ii";
$params[] = $perPage;
$params[] = $offset;

$queueRows = iqFetchAll($conn, $queueSql, $types, $params);

/* =========================
   Media Fetch
========================= */

$caseBeforeMedia = [];
$caseAfterMedia = [];

foreach ($queueRows as $case) {
    $cid = (int) $case['complaint_id'];

    $caseBeforeMedia[$cid] = iqFetchAll(
        $conn,
        "SELECT media_id, media_type, media_path, original_name
        FROM complaint_media
        WHERE complaint_id = ?
        ORDER BY uploaded_at ASC
        LIMIT 4",
        "i",
        [$cid]
    );

    $caseAfterMedia[$cid] = iqFetchAll(
        $conn,
        "SELECT proof_id, media_type, media_path, original_name, proof_note
        FROM maintenance_proofs
        WHERE complaint_id = ?
        AND proof_stage = 'after'
        ORDER BY uploaded_at ASC
        LIMIT 4",
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
    <title>Inspection Queue | DrainGuard</title>

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/inspector/sidebar.css">
    <link rel="stylesheet" href="../../css/inspector/topbar.css">
    <link rel="stylesheet" href="../../css/inspector/inspection-queue.css">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>

<body class="inspector">

    <div class="inspector-layout">

        <?php include __DIR__ . '/../../includes/inspector/sidebar.php'; ?>

        <main class="inspector-main">

            <?php include __DIR__ . '/../../includes/inspector/topbar.php'; ?>

            <section class="inspection-queue-page">

                <div class="page-heading">
                    <h1>Inspection Queue</h1>
                    <p>Review maintenance team work, approve valid work, or confirm false completion</p>
                </div>

                <?php if (!empty($successMessage)): ?>
                    <div class="page-success-alert">
                        <?php echo iqText($successMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errorMessage)): ?>
                    <div class="page-error-alert">
                        <?php echo iqText($errorMessage); ?>
                    </div>
                <?php endif; ?>

                <div class="status-alert">
                    <div class="status-alert-icon">
                        <i class="bi bi-eye"></i>
                    </div>

                    <div>
                        <h3><?php echo $totalQueue; ?> Cases Awaiting Inspection</h3>
                        <p>Inspector must verify team completion proof before closing any complaint</p>
                    </div>
                </div>

                <form method="GET" class="filter-card" id="inspectionQueueFilterForm">

                    <input
                        type="text"
                        name="search"
                        class="filter-control"
                        placeholder="Search by complaint code, issue, drain, area..."
                        value="<?php echo iqText($search); ?>">

                    <select name="team_id" class="filter-control">
                        <option value="">All Teams</option>

                        <?php foreach ($teamRows as $team): ?>
                            <option value="<?php echo (int) $team['maintenance_team_id']; ?>"
                                <?php echo ($teamId !== '' && (int) $teamId === (int) $team['maintenance_team_id']) ? 'selected' : ''; ?>>
                                <?php echo iqText($team['team_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="area_id" class="filter-control">
                        <option value="">All Areas</option>

                        <?php foreach ($areaRows as $area): ?>
                            <option value="<?php echo (int) $area['area_id']; ?>"
                                <?php echo ($areaId !== '' && (int) $areaId === (int) $area['area_id']) ? 'selected' : ''; ?>>
                                <?php echo iqText($area['area_name']); ?>
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

                <div class="queue-list">

                    <?php if (!empty($queueRows)): ?>

                        <?php foreach ($queueRows as $case): ?>

                            <?php
                            $cid = (int) $case['complaint_id'];
                            $priority = $case['assignment_priority'] ?: 'Medium';
                            $proofNote = $case['proof_note'] ?: 'No proof note provided by maintenance team.';
                            $afterMedia = $caseAfterMedia[$cid] ?? [];
                            $beforeMedia = $caseBeforeMedia[$cid] ?? [];
                            ?>

                            <article class="queue-card">

                                <div class="queue-card-top">

                                    <div>
                                        <div class="case-meta-row">
                                            <span class="case-code">
                                                <?php echo iqText($case['complaint_code']); ?>
                                            </span>

                                            <span class="priority-badge <?php echo iqPriorityClass($priority); ?>">
                                                <?php echo iqText($priority); ?>
                                            </span>

                                            <span class="status-badge">
                                                <?php echo iqText(iqStatusLabel($case['complaint_status'])); ?>
                                            </span>
                                        </div>

                                        <h3><?php echo iqText($case['issue_name'] ?: 'Drainage Issue'); ?></h3>
                                    </div>

                                </div>

                                <div class="case-info-grid">

                                    <p>
                                        Area:
                                        <strong>
                                            <?php echo iqText($case['area_name'] ?: 'N/A'); ?>,
                                            Ward <?php echo iqText($case['ward_no'] ?: 'N/A'); ?>
                                        </strong>
                                    </p>

                                    <p>
                                        Team:
                                        <strong><?php echo iqText($case['team_name'] ?: 'No Team Assigned'); ?></strong>
                                    </p>

                                    <p>
                                        Completed:
                                        <strong><?php echo iqText(iqDate($case['after_uploaded_at'] ?: $case['updated_at'])); ?></strong>
                                    </p>

                                    <p>
                                        Proof Files:
                                        <strong class="proof-count"><?php echo (int) $case['total_proofs']; ?> uploaded</strong>
                                    </p>

                                </div>

                                <div class="media-section">
                                    <h4>
                                        <i class="bi bi-image"></i>
                                        Before Complaint Media
                                    </h4>

                                    <div class="media-grid">

                                        <?php if (!empty($beforeMedia)): ?>

                                            <?php foreach ($beforeMedia as $media): ?>
                                                <?php $mediaPath = iqMediaPath($media['media_path']); ?>

                                                <div class="media-box">
                                                    <?php if ($media['media_type'] === 'video'): ?>
                                                        <video controls>
                                                            <source src="<?php echo iqText($mediaPath); ?>">
                                                        </video>
                                                    <?php else: ?>
                                                        <img src="<?php echo iqText($mediaPath); ?>" alt="Before media">
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
                                        Team Completion Proof
                                    </h4>

                                    <div class="media-grid">

                                        <?php if (!empty($afterMedia)): ?>

                                            <?php foreach ($afterMedia as $proof): ?>
                                                <?php $proofPath = iqMediaPath($proof['media_path']); ?>

                                                <div class="media-box">
                                                    <?php if ($proof['media_type'] === 'video'): ?>
                                                        <video controls>
                                                            <source src="<?php echo iqText($proofPath); ?>">
                                                        </video>
                                                    <?php else: ?>
                                                        <img src="<?php echo iqText($proofPath); ?>" alt="After proof">
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>

                                        <?php else: ?>

                                            <div class="empty-media">
                                                <i class="bi bi-image"></i>
                                                <span>No completion proof</span>
                                            </div>

                                        <?php endif; ?>

                                    </div>
                                </div>

                                <div class="completion-note">
                                    <span>Maintenance Proof Note</span>
                                    <p><?php echo iqText($proofNote); ?></p>
                                </div>

                                <form method="POST" action="inspection-queue.php" class="inspection-actions">
                                    <input type="hidden" name="complaint_id" value="<?php echo $cid; ?>">
                                    <input type="hidden" name="search" value="<?php echo iqText($search); ?>">
                                    <input type="hidden" name="team_id" value="<?php echo iqText($teamId); ?>">
                                    <input type="hidden" name="area_id" value="<?php echo iqText($areaId); ?>">
                                    <input type="hidden" name="sort" value="<?php echo iqText($sort); ?>">
                                    <input type="hidden" name="page" value="<?php echo (int) $page; ?>">

                                    <label class="inspection-note-label" for="inspection_note_<?php echo $cid; ?>">
                                        Inspector Verification Note
                                    </label>

                                    <textarea
                                        id="inspection_note_<?php echo $cid; ?>"
                                        name="inspection_note"
                                        class="inspection-note"
                                        rows="3"
                                        placeholder="Write field verification note. Required if you confirm false completion..."></textarea>

                                    <div class="action-row">
                                        <button type="submit" name="inspection_action" value="approve" class="action-btn approve-btn">
                                            <i class="bi bi-check2-circle"></i>
                                            Approve Work
                                        </button>

                                        <button type="submit" name="inspection_action" value="false_completion" class="action-btn false-btn">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            Confirm False Completion
                                        </button>
                                    </div>
                                </form>

                            </article>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <div class="empty-state">
                            <i class="bi bi-check2-circle"></i>
                            <h3>No Case Awaiting Inspection</h3>
                            <p>No complaint is currently marked as Inspector Verification Pending.</p>
                        </div>

                    <?php endif; ?>

                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination-wrap">

                        <?php if ($page > 1): ?>
                            <a href="<?php echo iqText(iqBuildPageUrl($page - 1, $search, $teamId, $areaId, $sort)); ?>" class="page-btn">
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
                                <a href="<?php echo iqText(iqBuildPageUrl($i, $search, $teamId, $areaId, $sort)); ?>"
                                   class="page-number <?php echo ($i === $page) ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?php echo iqText(iqBuildPageUrl($page + 1, $search, $teamId, $areaId, $sort)); ?>" class="page-btn">
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
    <script src="../../js/inspector/inspection-queue.js"></script>

</body>

</html>