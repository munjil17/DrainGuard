<?php
$activePage = 'feedback-reopen';
$pageTitle = 'Feedback / Reopen';
$pageParent = 'Citizen';
$pageChild = 'Feedback / Reopen';

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'citizen') {
    header("Location: ../../index.php");
    exit();
}

$userId = (int) $_SESSION['user_id'];
$successMessage = "";
$errorMessage = "";

/* ===============================
   HANDLE FEEDBACK / FALSE COMPLETION
================================ */

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $complaintId = (int) ($_POST['complaint_id'] ?? 0);
    $actionType = trim($_POST['action_type'] ?? '');
    $rating = (int) ($_POST['rating'] ?? 0);
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

        if ($checkStmt) {
            mysqli_stmt_bind_param($checkStmt, "ii", $complaintId, $userId);
            mysqli_stmt_execute($checkStmt);

            $checkResult = mysqli_stmt_get_result($checkStmt);

            if ($checkResult && mysqli_num_rows($checkResult) === 1) {
                if ($actionType === "feedback") {
                    if ($rating < 1 || $rating > 5) {
                        $errorMessage = "Please select a rating.";
                    } elseif ($feedbackText === '') {
                        $errorMessage = "Please write your feedback.";
                    } else {
                        $insertSql = "
                            INSERT INTO feedbacks
                            (complaint_id, user_id, rating, feedback_text, feedback_type)
                            VALUES (?, ?, ?, ?, 'feedback')
                        ";

                        $insertStmt = mysqli_prepare($conn, $insertSql);

                        if ($insertStmt) {
                            mysqli_stmt_bind_param($insertStmt, "iiis", $complaintId, $userId, $rating, $feedbackText);

                            if (mysqli_stmt_execute($insertStmt)) {
                                $successMessage = "Feedback submitted successfully.";
                            } else {
                                $errorMessage = "Feedback submission failed.";
                            }

                            mysqli_stmt_close($insertStmt);
                        } else {
                            $errorMessage = "Feedback submission failed.";
                        }
                    }
                }

                if ($actionType === "false_completion") {
                    $reportText = $feedbackText !== '' ? $feedbackText : "Citizen reported false completion.";

                    mysqli_begin_transaction($conn);

                    try {
                        $insertSql = "
                            INSERT INTO feedbacks
                            (complaint_id, user_id, rating, feedback_text, feedback_type)
                            VALUES (?, ?, NULL, ?, 'false_completion')
                        ";

                        $insertStmt = mysqli_prepare($conn, $insertSql);

                        if (!$insertStmt) {
                            throw new Exception("Feedback insert prepare failed.");
                        }

                        mysqli_stmt_bind_param($insertStmt, "iis", $complaintId, $userId, $reportText);
                        mysqli_stmt_execute($insertStmt);
                        mysqli_stmt_close($insertStmt);

                        $updateSql = "
                            UPDATE complaints
                            SET complaint_status = 'reopened'
                            WHERE complaint_id = ?
                            AND user_id = ?
                        ";

                        $updateStmt = mysqli_prepare($conn, $updateSql);

                        if (!$updateStmt) {
                            throw new Exception("Complaint update prepare failed.");
                        }

                        mysqli_stmt_bind_param($updateStmt, "ii", $complaintId, $userId);
                        mysqli_stmt_execute($updateStmt);
                        mysqli_stmt_close($updateStmt);

                        mysqli_commit($conn);
                        $successMessage = "False completion reported. Complaint reopened successfully.";

                    } catch (Exception $e) {
                        mysqli_rollback($conn);
                        $errorMessage = "False completion report failed.";
                    }
                }

            } else {
                $errorMessage = "Complaint not found.";
            }

            mysqli_stmt_close($checkStmt);
        } else {
            $errorMessage = "Something went wrong. Please try again.";
        }
    }
}

/* ===============================
   FETCH COMPLETED/SOLVED COMPLAINTS
================================ */

$complaints = [];

$sql = "
    SELECT
        c.complaint_id,
        c.complaint_code,
        c.issue_type,
        c.problem_description,
        c.complaint_status,
        c.updated_at,

        cc.city_cor_name,
        t.thana_name,
        w.ward_no,
        a.area_name

    FROM complaints c

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

    WHERE c.user_id = ?
    AND c.complaint_status IN ('completed', 'under_inspection', 'solved')

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

function safeText($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatStatus($status) {
    if ($status === 'completed') {
        return 'Solved by Team';
    }

    if ($status === 'under_inspection') {
        return 'Under Inspection';
    }

    if ($status === 'solved') {
        return 'Solved / Closed';
    }

    return ucwords(str_replace('_', ' ', (string)$status));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Feedback & Reopen | DrainGuard</title>
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
                    <h1>Feedback & Reopen</h1>
                    <p>Review completed work and provide feedback</p>
                </div>

                <div class="fr-count-card">
                    <span><?php echo count($complaints); ?></span>
                    <small>Reviewable</small>
                </div>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="fr-alert fr-success">
                    <i class="bi bi-check-circle"></i>
                    <?php echo safeText($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="fr-alert fr-error">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo safeText($errorMessage); ?>
                </div>
            <?php endif; ?>

            <div class="fr-list">

                <?php if (count($complaints) > 0): ?>

                    <?php foreach ($complaints as $complaint): ?>

                        <div class="fr-card">
                            <div class="fr-card-top">
                                <div>
                                    <h2><?php echo safeText($complaint['issue_type']); ?></h2>
                                    <p>
                                        <strong><?php echo safeText($complaint['complaint_code']); ?></strong>
                                        —
                                        <?php echo safeText("Ward " . $complaint['ward_no'] . ", " . $complaint['area_name']); ?>
                                        —
                                        <?php echo safeText($complaint['thana_name']); ?>
                                    </p>
                                </div>

                                <span class="fr-status">
                                    <?php echo safeText(formatStatus($complaint['complaint_status'])); ?>
                                </span>
                            </div>

                            <form class="fr-form" method="POST" action="feedback-reopen.php">
                                <input type="hidden" name="complaint_id" value="<?php echo (int)$complaint['complaint_id']; ?>">
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
                                    <label>Feedback / Reopen Reason</label>
                                    <textarea
                                        name="feedback_text"
                                        placeholder="Write your feedback. If the issue is not actually solved, explain why you want to reopen it..."
                                    ></textarea>
                                </div>

                                <div class="fr-actions">
                                    <button type="submit" class="fr-submit-btn" data-action="feedback">
                                        <i class="bi bi-send"></i>
                                        Submit Feedback
                                    </button>

                                    <button type="submit" class="fr-reopen-btn" data-action="false_completion">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                        Report False Completion
                                    </button>
                                </div>
                            </form>
                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="fr-empty">
                        <i class="bi bi-chat-square-text"></i>
                        <h2>No complaints ready for feedback</h2>
                        <p>You can give feedback after a complaint is marked completed, under inspection, or solved.</p>
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