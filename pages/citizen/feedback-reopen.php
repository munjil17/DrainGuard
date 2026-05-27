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
    die("Database connection not found.");
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
        $errorMessage = "Invalid request.";
    } else {
        $checkSql = "
            SELECT complaint_id, complaint_status
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
                                        VALUES (?, ?, NULL, ?, 'false_completion')
                                    ";

                                    $feedbackStmt = mysqli_prepare($conn, $insertFeedbackSql);

                                    if (!$feedbackStmt) {
                                        throw new Exception("Feedback insert prepare failed.");
                                    }

                                    mysqli_stmt_bind_param($feedbackStmt, "iis", $complaintId, $userId, $feedbackText);
                                    mysqli_stmt_execute($feedbackStmt);
                                    mysqli_stmt_close($feedbackStmt);

                                    $insertReopenSql = "
                                        INSERT INTO reopen_requests
                                        (complaint_id, requested_by, request_type, reason, request_status)
                                        VALUES (?, ?, 'citizen_objection', ?, 'pending')
                                    ";

                                    $reopenStmt = mysqli_prepare($conn, $insertReopenSql);

                                    if (!$reopenStmt) {
                                        throw new Exception("Reopen request insert prepare failed.");
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
                                        throw new Exception("Complaint update prepare failed.");
                                    }

                                    mysqli_stmt_bind_param($updateStmt, "ii", $complaintId, $userId);
                                    mysqli_stmt_execute($updateStmt);
                                    mysqli_stmt_close($updateStmt);

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
   FETCH CLOSED COMPLAINTS + ACTIVE OBJECTION INFO
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

        d.drain_name,

        rr.reopen_id AS active_reopen_id,
        rr.request_status AS active_reopen_status,
        rr.reason AS active_reopen_reason,
        rr.created_at AS active_reopen_created_at

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

    LEFT JOIN reopen_requests rr
        ON rr.complaint_id = c.complaint_id
        AND rr.requested_by = c.user_id
        AND rr.request_type = 'citizen_objection'
        AND rr.request_status IN ('pending', 'sent_to_inspector')

    WHERE c.user_id = ?
    AND c.complaint_status IN ('closed', 'disputed')

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
                        $hasPendingObjection = !empty($complaint['active_reopen_id']);
                        $isClosed = ($complaint['complaint_status'] === 'closed');
                        $isDisputed = ($complaint['complaint_status'] === 'disputed');
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

                                <span class="fr-status <?php echo $isDisputed ? 'fr-status-disputed' : ''; ?>">
                                    <?php echo frText(frStatusLabel($complaint['complaint_status'])); ?>
                                </span>
                            </div>

                            <?php if (!empty($complaint['problem_description'])): ?>
                                <div class="fr-complaint-note">
                                    <span>Original Problem</span>
                                    <p><?php echo frText($complaint['problem_description']); ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if ($hasPendingObjection): ?>

                                <div class="fr-pending-objection">
                                    <i class="bi bi-hourglass-split"></i>
                                    <div>
                                        <strong>
                                            <?php echo frText(frReopenStatusLabel($complaint['active_reopen_status'])); ?>
                                        </strong>

                                        <p>
                                            Submitted on:
                                            <?php echo frText(frDate($complaint['active_reopen_created_at'])); ?>
                                        </p>

                                        <?php if (!empty($complaint['active_reopen_reason'])): ?>
                                            <p>
                                                Reason:
                                                <?php echo frText($complaint['active_reopen_reason']); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            <?php elseif ($isClosed): ?>

                                <form class="fr-form" method="POST" action="feedback-reopen.php">
                                    <input type="hidden" name="complaint_id" value="<?php echo (int) $complaint['complaint_id']; ?>">
                                    <input type="hidden" name="rating" class="rating-input" value="0">
                                    <input type="hidden" name="action_type" class="action-type" value="feedback">

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
                                        <label>Feedback / Objection Reason</label>
                                        <textarea
                                            name="feedback_text"
                                            placeholder="Write feedback. If the issue is still not solved, clearly explain the problem for Ward Officer review..."
                                        ></textarea>
                                    </div>

                                    <div class="fr-actions">
                                        <button type="submit" class="fr-submit-btn" data-action="feedback">
                                            <i class="bi bi-send"></i>
                                            Submit Feedback
                                        </button>

                                        <button type="submit" class="fr-reopen-btn" data-action="citizen_objection">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            Raise Objection
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

</body>
</html>