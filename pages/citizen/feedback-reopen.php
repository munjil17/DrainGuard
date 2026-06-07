<?php
$activePage = 'feedback-reopen';
$pageTitle = 'Feedback / Objection';
$pageParent = 'Citizen';
$pageChild = 'Feedback / Objection';

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'citizen') {
    header("Location: ../../index.php");
    exit();
}

if (!isset($conn) && isset($connection)) {
    $conn = $connection;
}

if (!isset($conn) || !$conn) {
    die("Service is temporarily unavailable. Please try again.");
}

$userId = (int) $_SESSION['user_id'];
$successMessage = "";
$errorMessage = "";

/* ===============================
   HELPER FUNCTIONS
================================ */

function frText($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function frDate($datetime)
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

function frStatusLabel($status)
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
        'closed' => 'Solved / Closed',
        'reopened' => 'Reopened',
        'disputed' => 'Objection Submitted',
        'rejected' => 'Rejected',
        'duplicate' => 'Duplicate'
    ];

    return $map[$status] ?? ucwords(str_replace('_', ' ', (string) $status));
}

function frReopenStatusLabel($status)
{
    $map = [
        'pending' => 'Waiting for Ward Officer Review',
        'sent_to_inspector' => 'Forwarded to Inspector',
        'reassigned_same_team' => 'Reassigned Same Team',
        'reassigned_different_team' => 'Reassigned Different Team',
        'rejected' => 'Objection Rejected',
        'resolved' => 'Resolved'
    ];

    return $map[$status] ?? ucwords(str_replace('_', ' ', (string) $status));
}

/* ===============================
   HANDLE FEEDBACK / CITIZEN OBJECTION
================================ */

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $complaintId = isset($_POST['complaint_id']) ? (int) $_POST['complaint_id'] : 0;
    $actionType = trim($_POST['action_type'] ?? '');
    $rating = isset($_POST['rating']) ? (int) $_POST['rating'] : 0;
    $feedbackText = trim($_POST['feedback_text'] ?? '');

    if ($complaintId <= 0 || $actionType === '') {
        $errorMessage = "Invalid request. Please try again.";
    } else {
        $checkSql = "
            SELECT complaint_id, complaint_code, complaint_status
            FROM complaints
            WHERE complaint_id = ?
            AND user_id = ?
            LIMIT 1
        ";

        $checkStmt = mysqli_prepare($conn, $checkSql);

        if (!$checkStmt) {
            $errorMessage = "Something went wrong. Please try again.";
        } else {
            mysqli_stmt_bind_param($checkStmt, "ii", $complaintId, $userId);
            mysqli_stmt_execute($checkStmt);
            $checkResult = mysqli_stmt_get_result($checkStmt);
            $complaintRow = $checkResult ? mysqli_fetch_assoc($checkResult) : null;
            mysqli_stmt_close($checkStmt);

            if (!$complaintRow) {
                $errorMessage = "Complaint not found.";
            } else {
                $currentStatus = $complaintRow['complaint_status'];

                /* ===============================
                   NORMAL FEEDBACK
                ================================ */

                if ($actionType === "feedback") {
                    if ($currentStatus !== 'closed') {
                        $errorMessage = "Feedback can be submitted only after the complaint is solved.";
                    } elseif ($rating < 1 || $rating > 5) {
                        $errorMessage = "Please select a rating.";
                    } elseif ($feedbackText === '') {
                        $errorMessage = "Please write your feedback.";
                    } else {
                        $duplicateFeedbackSql = "
                            SELECT feedback_id
                            FROM feedbacks
                            WHERE complaint_id = ?
                            AND user_id = ?
                            AND feedback_type = 'feedback'
                            LIMIT 1
                        ";

                        $duplicateStmt = mysqli_prepare($conn, $duplicateFeedbackSql);

                        if (!$duplicateStmt) {
                            $errorMessage = "Feedback submission failed.";
                        } else {
                            mysqli_stmt_bind_param($duplicateStmt, "ii", $complaintId, $userId);
                            mysqli_stmt_execute($duplicateStmt);
                            $duplicateResult = mysqli_stmt_get_result($duplicateStmt);
                            $alreadyFeedback = $duplicateResult && mysqli_num_rows($duplicateResult) > 0;
                            mysqli_stmt_close($duplicateStmt);

                            if ($alreadyFeedback) {
                                $errorMessage = "You already submitted feedback for this complaint.";
                            } else {
                                $insertSql = "
                                    INSERT INTO feedbacks
                                    (complaint_id, user_id, rating, feedback_text, feedback_type)
                                    VALUES (?, ?, ?, ?, 'feedback')
                                ";

                                $insertStmt = mysqli_prepare($conn, $insertSql);

                                if (!$insertStmt) {
                                    $errorMessage = "Feedback submission failed.";
                                } else {
                                    mysqli_stmt_bind_param($insertStmt, "iiis", $complaintId, $userId, $rating, $feedbackText);

                                    if (mysqli_stmt_execute($insertStmt)) {
                                        $successMessage = "Feedback submitted successfully.";
                                        
                                        $teamSql = "SELECT maintenance_team_id FROM complaint_assignments WHERE complaint_id = ? AND maintenance_team_id IS NOT NULL ORDER BY assigned_at DESC LIMIT 1";
                                        $teamStmt = mysqli_prepare($conn, $teamSql);
                                        if ($teamStmt) {
                                            mysqli_stmt_bind_param($teamStmt, "i", $complaintId);
                                            mysqli_stmt_execute($teamStmt);
                                            $teamRes = mysqli_stmt_get_result($teamStmt);
                                            if ($teamRes && $tRow = mysqli_fetch_assoc($teamRes)) {
                                                $teamId = (int)$tRow['maintenance_team_id'];
                                                
                                                $revDupSql = "SELECT review_id FROM maintenance_team_reviews WHERE complaint_id = ? AND citizen_user_id = ? AND maintenance_team_id = ?";
                                                $revDupStmt = mysqli_prepare($conn, $revDupSql);
                                                if ($revDupStmt) {
                                                    mysqli_stmt_bind_param($revDupStmt, "iii", $complaintId, $userId, $teamId);
                                                    mysqli_stmt_execute($revDupStmt);
                                                    $rdupRes = mysqli_stmt_get_result($revDupStmt);
                                                    if (!$rdupRes || mysqli_num_rows($rdupRes) === 0) {
                                                        $rType = ($rating >= 4) ? 'good_work' : 'satisfied';
                                                        $revIns = mysqli_prepare($conn, "INSERT INTO maintenance_team_reviews (complaint_id, citizen_user_id, maintenance_team_id, rating, review_text, review_type) VALUES (?, ?, ?, ?, ?, ?)");
                                                        if ($revIns) {
                                                            mysqli_stmt_bind_param($revIns, "iiiiss", $complaintId, $userId, $teamId, $rating, $feedbackText, $rType);
                                                            mysqli_stmt_execute($revIns);
                                                            mysqli_stmt_close($revIns);
                                                            
                                                            $tlSql = "SELECT user_id FROM maintenance_team_members WHERE maintenance_team_id = ? AND role = 'team_leader' LIMIT 1";
                                                            $tlStmt = mysqli_prepare($conn, $tlSql);
                                                            if ($tlStmt) {
                                                                mysqli_stmt_bind_param($tlStmt, "i", $teamId);
                                                                mysqli_stmt_execute($tlStmt);
                                                                $tlRes = mysqli_stmt_get_result($tlStmt);
                                                                if ($tlRes && $tlRow = mysqli_fetch_assoc($tlRes)) {
                                                                    $tlId = (int)$tlRow['user_id'];
                                                                    $cCode = $complaintRow['complaint_code'] ?? "ID:$complaintId";
                                                                    $msg = "Citizen marked complaint {$cCode} as satisfied. Please check the feedback.";
                                                                    $notifIns = mysqli_prepare($conn, "INSERT INTO maintenance_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'citizen_feedback_satisfied', 'Citizen Satisfied with Work', ?, 0, NOW())");
                                                                    if ($notifIns) {
                                                                        mysqli_stmt_bind_param($notifIns, "iiis", $tlId, $userId, $complaintId, $msg);
                                                                        mysqli_stmt_execute($notifIns);
                                                                        mysqli_stmt_close($notifIns);
                                                                    }
                                                                }
                                                                mysqli_stmt_close($tlStmt);
                                                            }
                                                        }
                                                    }
                                                    mysqli_stmt_close($revDupStmt);
                                                }
                                            }
                                            mysqli_stmt_close($teamStmt);
                                        }

                                    } else {
                                        $errorMessage = "Feedback submission failed.";
                                    }

                                    mysqli_stmt_close($insertStmt);
                                }
                            }
                        }
                    }
                }

                /* ===============================
                   CITIZEN OBJECTION
                ================================ */

                if ($actionType === "citizen_objection") {
                    if ($currentStatus !== 'closed') {
                        $errorMessage = "Objection can be submitted only after the complaint is solved.";
                    } elseif ($rating < 1 || $rating > 5) {
                        $errorMessage = "Please select a rating.";
                    } elseif ($feedbackText === '') {
                        $errorMessage = "Please write your objection reason.";
                    } else {
                        $pendingCheckSql = "
                            SELECT reopen_id
                            FROM reopen_requests
                            WHERE complaint_id = ?
                            AND requested_by = ?
                            AND request_type = 'citizen_objection'
                            AND request_status IN ('pending', 'sent_to_inspector')
                            LIMIT 1
                        ";

                        $pendingStmt = mysqli_prepare($conn, $pendingCheckSql);

                        if (!$pendingStmt) {
                            $errorMessage = "Objection submission failed.";
                        } else {
                            mysqli_stmt_bind_param($pendingStmt, "ii", $complaintId, $userId);
                            mysqli_stmt_execute($pendingStmt);
                            $pendingResult = mysqli_stmt_get_result($pendingStmt);
                            $alreadyPending = $pendingResult && mysqli_num_rows($pendingResult) > 0;
                            mysqli_stmt_close($pendingStmt);

                            if ($alreadyPending) {
                                $errorMessage = "You already have a pending objection for this complaint.";
                            } else {
                                mysqli_begin_transaction($conn);

                                try {
                                    $insertFeedbackSql = "
                                        INSERT INTO feedbacks
                                        (complaint_id, user_id, rating, feedback_text, feedback_type)
                                        VALUES (?, ?, ?, ?, 'false_completion')
                                    ";

                                    $feedbackStmt = mysqli_prepare($conn, $insertFeedbackSql);

                                    if (!$feedbackStmt) {
                                        throw new Exception("Unable to complete this action. Please try again.");
                                    }

                                    mysqli_stmt_bind_param($feedbackStmt, "iiis", $complaintId, $userId, $rating, $feedbackText);
                                    mysqli_stmt_execute($feedbackStmt);
                                    mysqli_stmt_close($feedbackStmt);

                                    $insertReopenSql = "
                                        INSERT INTO reopen_requests
                                        (complaint_id, requested_by, request_type, reason, request_status)
                                        VALUES (?, ?, 'citizen_objection', ?, 'pending')
                                    ";

                                    $reopenStmt = mysqli_prepare($conn, $insertReopenSql);

                                    if (!$reopenStmt) {
                                        throw new Exception("Unable to complete this action. Please try again.");
                                    }

                                    mysqli_stmt_bind_param($reopenStmt, "iis", $complaintId, $userId, $feedbackText);
                                    mysqli_stmt_execute($reopenStmt);
                                    mysqli_stmt_close($reopenStmt);

                                    $updateComplaintSql = "
                                        UPDATE complaints
                                        SET complaint_status = 'disputed',
                                            updated_at = CURRENT_TIMESTAMP
                                        WHERE complaint_id = ?
                                        AND user_id = ?
                                        AND complaint_status = 'closed'
                                    ";

                                    $updateStmt = mysqli_prepare($conn, $updateComplaintSql);

                                    if (!$updateStmt) {
                                        throw new Exception("Unable to complete this action. Please try again.");
                                    }

                                    mysqli_stmt_bind_param($updateStmt, "ii", $complaintId, $userId);
                                    mysqli_stmt_execute($updateStmt);
                                    mysqli_stmt_close($updateStmt);

                                    // === CITIZEN OBJECTION: Ward Officer Notification ===
                                    $cCode = $complaintRow['complaint_code'] ?? "ID:$complaintId";
                                    $objMsg = "Citizen submitted an objection for complaint {$cCode}. Please review the objection details.";
                                    $objNotifTime = date('Y-m-d H:i:s');

                                    // 1. Ward Officer → ward_notifications
                                    $woSql = "SELECT wo.user_id FROM ward_officers wo
                                        INNER JOIN complaint_assignments ca ON ca.ward_id = wo.assigned_ward_id
                                        WHERE ca.complaint_id = ? LIMIT 1";
                                    $woStmt = mysqli_prepare($conn, $woSql);
                                    if ($woStmt) {
                                        mysqli_stmt_bind_param($woStmt, "i", $complaintId);
                                        mysqli_stmt_execute($woStmt);
                                        $woResult = mysqli_stmt_get_result($woStmt);
                                        if ($woRow = mysqli_fetch_assoc($woResult)) {
                                            $wardUserId = (int)$woRow['user_id'];
                                            $woNotif = mysqli_prepare($conn, "INSERT INTO ward_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'citizen_objection_submitted', 'Citizen Submitted Objection', ?, 0, ?)");
                                            if ($woNotif) {
                                                mysqli_stmt_bind_param($woNotif, "iiiss", $wardUserId, $userId, $complaintId, $objMsg, $objNotifTime);
                                                mysqli_stmt_execute($woNotif);
                                                mysqli_stmt_close($woNotif);
                                            }
                                        }
                                        mysqli_stmt_close($woStmt);
                                    }

                                    // 2. Central Officer → central_notifications
                                    $coSql = "SELECT ca.assigned_by FROM complaint_assignments ca
                                        JOIN users u ON u.user_id = ca.assigned_by
                                        WHERE ca.complaint_id = ? AND u.user_role = 'central_officer' LIMIT 1";
                                    $coStmt = mysqli_prepare($conn, $coSql);
                                    if ($coStmt) {
                                        mysqli_stmt_bind_param($coStmt, "i", $complaintId);
                                        mysqli_stmt_execute($coStmt);
                                        $coResult = mysqli_stmt_get_result($coStmt);
                                        if ($coRow = mysqli_fetch_assoc($coResult)) {
                                            $centralUserId = (int)$coRow['assigned_by'];
                                            $coNotif = mysqli_prepare($conn, "INSERT INTO central_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'citizen_objection_submitted', 'Citizen Submitted Objection', ?, 0, ?)");
                                            if ($coNotif) {
                                                mysqli_stmt_bind_param($coNotif, "iiiss", $centralUserId, $userId, $complaintId, $objMsg, $objNotifTime);
                                                mysqli_stmt_execute($coNotif);
                                                mysqli_stmt_close($coNotif);
                                            }
                                        }
                                        mysqli_stmt_close($coStmt);
                                    }

                                    // 3. Inspector → inspector_notifications
                                    $insSqlO = "SELECT inspector_user_id FROM inspection_logs WHERE complaint_id = ? ORDER BY log_id DESC LIMIT 1";
                                    $insStmtO = mysqli_prepare($conn, $insSqlO);
                                    if ($insStmtO) {
                                        mysqli_stmt_bind_param($insStmtO, "i", $complaintId);
                                        mysqli_stmt_execute($insStmtO);
                                        $insResultO = mysqli_stmt_get_result($insStmtO);
                                        if ($insRowO = mysqli_fetch_assoc($insResultO)) {
                                            $insUserIdO = (int)$insRowO['inspector_user_id'];
                                            $insNotif = mysqli_prepare($conn, "INSERT INTO inspector_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'citizen_objection_submitted', 'Citizen Submitted Objection', ?, 0, ?)");
                                            if ($insNotif) {
                                                mysqli_stmt_bind_param($insNotif, "iiiss", $insUserIdO, $userId, $complaintId, $objMsg, $objNotifTime);
                                                mysqli_stmt_execute($insNotif);
                                                mysqli_stmt_close($insNotif);
                                            }
                                        }
                                        mysqli_stmt_close($insStmtO);
                                    }

                                    // 4. Maintenance Team Leader → maintenance_notifications
                                    $teamSqlO = "SELECT maintenance_team_id FROM complaint_assignments WHERE complaint_id = ? AND maintenance_team_id IS NOT NULL ORDER BY assigned_at DESC LIMIT 1";
                                    $teamStmtO = mysqli_prepare($conn, $teamSqlO);
                                    if ($teamStmtO) {
                                        mysqli_stmt_bind_param($teamStmtO, "i", $complaintId);
                                        mysqli_stmt_execute($teamStmtO);
                                        $teamRes = mysqli_stmt_get_result($teamStmtO);
                                        if ($teamRes && $tRow = mysqli_fetch_assoc($teamRes)) {
                                            $teamId = (int)$tRow['maintenance_team_id'];
                                            $revDupSql = "SELECT review_id FROM maintenance_team_reviews WHERE complaint_id = ? AND citizen_user_id = ? AND maintenance_team_id = ?";
                                            $revDupStmt = mysqli_prepare($conn, $revDupSql);
                                            if ($revDupStmt) {
                                                mysqli_stmt_bind_param($revDupStmt, "iii", $complaintId, $userId, $teamId);
                                                mysqli_stmt_execute($revDupStmt);
                                                $rdupRes = mysqli_stmt_get_result($revDupStmt);
                                                if (!$rdupRes || mysqli_num_rows($rdupRes) === 0) {
                                                    $rType = 'objection';
                                                    $revIns = mysqli_prepare($conn, "INSERT INTO maintenance_team_reviews (complaint_id, citizen_user_id, maintenance_team_id, rating, review_text, review_type) VALUES (?, ?, ?, ?, ?, ?)");
                                                    if ($revIns) {
                                                        mysqli_stmt_bind_param($revIns, "iiiiss", $complaintId, $userId, $teamId, $rating, $feedbackText, $rType);
                                                        mysqli_stmt_execute($revIns);
                                                        mysqli_stmt_close($revIns);
                                                    }
                                                }
                                                mysqli_stmt_close($revDupStmt);
                                            }
                                            $tlSqlO = "SELECT user_id FROM maintenance_team_members WHERE maintenance_team_id = ? AND role = 'team_leader' LIMIT 1";
                                            $tlStmtO = mysqli_prepare($conn, $tlSqlO);
                                            if ($tlStmtO) {
                                                mysqli_stmt_bind_param($tlStmtO, "i", $teamId);
                                                mysqli_stmt_execute($tlStmtO);
                                                $tlResO = mysqli_stmt_get_result($tlStmtO);
                                                if ($tlResO && $tlRowO = mysqli_fetch_assoc($tlResO)) {
                                                    $tlIdO = (int)$tlRowO['user_id'];
                                                    $tmMsg = "Citizen submitted an objection for complaint {$cCode}. Please review the objection details.";
                                                    $tmNotif = mysqli_prepare($conn, "INSERT INTO maintenance_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, 'citizen_objection_submitted', 'Citizen Submitted Objection', ?, 0, ?)");
                                                    if ($tmNotif) {
                                                        mysqli_stmt_bind_param($tmNotif, "iiiss", $tlIdO, $userId, $complaintId, $tmMsg, $objNotifTime);
                                                        mysqli_stmt_execute($tmNotif);
                                                        mysqli_stmt_close($tmNotif);
                                                    }
                                                }
                                                mysqli_stmt_close($tlStmtO);
                                            }
                                        }
                                        mysqli_stmt_close($teamStmtO);
                                    }

                                    mysqli_commit($conn);

                                    $successMessage = "Objection submitted successfully. Ward Officer will review it first.";

                                } catch (Exception $e) {
                                    mysqli_rollback($conn);
                                    $errorMessage = "Objection submission failed.";
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

/* ===============================
   FETCH CLOSED COMPLAINTS READY FOR FEEDBACK / OBJECTION
================================ */

$complaints = [];

$sql = "
    SELECT
        c.complaint_id,
        c.complaint_code,
        c.problem_description,
        c.address_description,
        c.complaint_status,
        c.updated_at,
        c.submitted_at,

        i.issue_name,

        cc.city_cor_name,
        t.thana_name,
        w.ward_no,
        a.area_name,

        d.drain_name

    FROM complaints c

    LEFT JOIN issues i
        ON i.issue_id = c.issue_id

    LEFT JOIN drains d
        ON d.drain_id = c.drain_id

    INNER JOIN locations l
        ON c.loc_id = l.loc_id

    INNER JOIN city_corporations cc
        ON l.city_cor_id = cc.city_cor_id

    INNER JOIN thanas t
        ON l.thana_id = t.thana_id

    INNER JOIN wards w
        ON l.ward_id = w.ward_id

    INNER JOIN areas a
        ON l.area_id = a.area_id

    LEFT JOIN feedbacks f
        ON f.complaint_id = c.complaint_id 
        AND f.user_id = c.user_id 
        AND f.feedback_type = 'feedback'

    WHERE c.user_id = ?
    AND c.complaint_status = 'closed'
    AND f.feedback_id IS NULL
    AND NOT EXISTS (
        SELECT 1
        FROM reopen_requests rr_submitted
        WHERE rr_submitted.complaint_id = c.complaint_id
        AND rr_submitted.requested_by = c.user_id
        AND rr_submitted.request_type = 'citizen_objection'
    )

    ORDER BY c.updated_at DESC
";

$stmt = mysqli_prepare($conn, $sql);

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $complaints[] = $row;
    }

    mysqli_stmt_close($stmt);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Feedback & Objection | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <!-- Global CSS -->
    <link rel="stylesheet" href="../../css/global/global.css">

    <!-- Reusable Citizen Layout CSS -->
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">

    <!-- Page CSS -->
    <link rel="stylesheet" href="../../css/citizen/feedback-reopen.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="citizen">

<div class="citizen-layout">

    <?php include "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main main-content">

        <?php include "../../includes/citizen/topbar.php"; ?>

        <section class="fr-page">

            <div class="fr-header">
                <div>
                    <h1>Feedback & Objection</h1>
                    <p>Submit feedback for solved complaints or raise an objection if the issue is still not fixed.</p>
                </div>

                <div class="fr-count-card">
                    <span><?php echo count($complaints); ?></span>
                    <small>Available</small>
                </div>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="fr-alert fr-success">
                    <i class="bi bi-check-circle"></i>
                    <?php echo frText($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="fr-alert fr-error">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo frText($errorMessage); ?>
                </div>
            <?php endif; ?>

            <div class="fr-list">

                <?php if (count($complaints) > 0): ?>

                    <?php foreach ($complaints as $complaint): ?>

                        <?php
                        $isClosed = ($complaint['complaint_status'] === 'closed');
                        ?>

                        <div class="fr-card">
                            <div class="fr-card-top">
                                <div>
                                    <h2><?php echo frText($complaint['issue_name'] ?: 'Drainage Issue'); ?></h2>

                                    <p>
                                        <strong><?php echo frText($complaint['complaint_code']); ?></strong>
                                        —
                                        <?php echo frText("Ward " . $complaint['ward_no'] . ", " . $complaint['area_name']); ?>
                                        —
                                        <?php echo frText($complaint['thana_name']); ?>
                                    </p>

                                    <p class="fr-small-line">
                                        Last updated:
                                        <strong><?php echo frText(frDate($complaint['updated_at'])); ?></strong>
                                    </p>
                                </div>

                                <span class="fr-status">
                                    <?php echo frText(frStatusLabel($complaint['complaint_status'])); ?>
                                </span>
                            </div>

                            <?php if (!empty($complaint['problem_description'])): ?>
                                <div class="fr-complaint-note">
                                    <span>Original Problem</span>
                                    <p><?php echo frText($complaint['problem_description']); ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if ($isClosed): ?>

                                <form class="fr-form" method="POST" action="feedback-reopen.php">
                                    <input type="hidden" name="complaint_id" value="<?php echo (int) $complaint['complaint_id']; ?>">
                                    <input type="hidden" name="rating" class="rating-input" value="0">

                                    <div class="fr-input-block">
                                        <label>Feedback Type</label>
                                        <select name="action_type" class="action-type fr-select" style="width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 0.95rem; background-color: var(--white); margin-bottom: 20px;">
                                            <option value="feedback">Satisfied (Good Review)</option>
                                            <option value="citizen_objection">Still Unresolved (Objection)</option>
                                        </select>
                                    </div>

                                    <div class="fr-rating-block">
                                        <label>Rate the maintenance work</label>

                                        <div class="fr-stars">
                                            <button type="button" data-value="1">
                                                <i class="bi bi-star-fill"></i>
                                            </button>

                                            <button type="button" data-value="2">
                                                <i class="bi bi-star-fill"></i>
                                            </button>

                                            <button type="button" data-value="3">
                                                <i class="bi bi-star-fill"></i>
                                            </button>

                                            <button type="button" data-value="4">
                                                <i class="bi bi-star-fill"></i>
                                            </button>

                                            <button type="button" data-value="5">
                                                <i class="bi bi-star-fill"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="fr-input-block">
                                        <label>Reason / Comments</label>
                                        <textarea
                                            name="feedback_text"
                                            placeholder="Write feedback. If the issue is still not solved, clearly explain the problem for Ward Officer review..."
                                        ></textarea>
                                    </div>

                                    <div class="fr-actions">
                                        <button type="submit" class="fr-submit-btn">
                                            <i class="bi bi-send"></i>
                                            Submit
                                        </button>
                                    </div>
                                </form>

                            <?php else: ?>

                                <div class="fr-pending-objection">
                                    <i class="bi bi-info-circle"></i>
                                    <div>
                                        <strong>Complaint is under review</strong>
                                        <p>This complaint is not available for new feedback or objection right now.</p>
                                    </div>
                                </div>

                            <?php endif; ?>
                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="fr-empty">
                        <i class="bi bi-chat-square-text"></i>
                        <h2>No solved complaints ready for feedback</h2>
                        <p>You can submit feedback or raise an objection after a complaint is marked as solved/closed.</p>
                    </div>

                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/citizen/feedback-reopen.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>
