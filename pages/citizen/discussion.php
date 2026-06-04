<?php
$activePage = "my-complaints";
$pageTitle = "Complaint Discussion";

require_once "../../config.php";
require_once "../../auth/session_check.php";
require_once "../../commentSystem/discussion_logic.php";

$citizenUserId = (int)($_SESSION["user_id"] ?? 0);
$complaintId = (int)($_GET["id"] ?? 0);

if ($citizenUserId <= 0 || $complaintId <= 0) {
    header("Location: my-complaints.php");
    exit();
}

// Fetch current user role
$roleSql = "SELECT user_role FROM users WHERE user_id = ? LIMIT 1";
$roleStmt = mysqli_prepare($conn, $roleSql);
$currentUserRole = "";
if ($roleStmt) {
    mysqli_stmt_bind_param($roleStmt, "i", $citizenUserId);
    mysqli_stmt_execute($roleStmt);
    $roleRes = mysqli_stmt_get_result($roleStmt);
    if ($roleRes && $roleRow = mysqli_fetch_assoc($roleRes)) {
        $currentUserRole = (string)$roleRow["user_role"];
    }
    mysqli_stmt_close($roleStmt);
}

$context = cs_get_discussion_context($conn, $complaintId);
if (!cs_has_discussion_access($context, $citizenUserId, $currentUserRole)) {
    die("Access denied or discussion not available for this complaint.");
}

// Fetch basic info for UI
$infoSql = "SELECT c.complaint_code, c.complaint_status, i.issue_name 
            FROM complaints c LEFT JOIN issues i ON c.issue_id = i.issue_id 
            WHERE c.complaint_id = ? LIMIT 1";
$infoStmt = mysqli_prepare($conn, $infoSql);
$complaintData = null;
if ($infoStmt) {
    mysqli_stmt_bind_param($infoStmt, "i", $complaintId);
    mysqli_stmt_execute($infoStmt);
    $infoRes = mysqli_stmt_get_result($infoStmt);
    $complaintData = mysqli_fetch_assoc($infoRes);
    mysqli_stmt_close($infoStmt);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle); ?> | DrainGuard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">
    <link rel="stylesheet" href="../../css/citizen/discussion.css">
    <link rel="stylesheet" href="../../css/commentSystem/commentSystem.css">
</head>
<body class="citizen">

<div class="citizen-layout">

<?php include "../../includes/citizen/sidebar.php"; ?>

<main class="citizen-main main-content">
    <?php include "../../includes/citizen/topbar.php"; ?>

    <section class="citizen-discussion-page">
        <div class="ct-header">
            <a href="my-complaints.php" class="ct-back-btn">
                <i class="bi bi-arrow-left"></i> Back to Complaints
            </a>
            <h1>Discussion: <?= htmlspecialchars($complaintData['complaint_code']); ?></h1>
            <p>Issue: <?= htmlspecialchars($complaintData['issue_name'] ?? 'Unknown Issue'); ?></p>
            <span class="ct-status-badge <?= htmlspecialchars($complaintData['complaint_status']); ?>">
                <?= ucwords(str_replace('_', ' ', $complaintData['complaint_status'])); ?>
            </span>
        </div>

        <div class="ct-discussion-card">
            <div 
                class="dg-comment-system" 
                id="citizenDiscussionContainer" 
                data-complaint-id="<?= $complaintId; ?>" 
                data-comment-system="true"
                data-base-path="../../"
            >
                <div class="dg-comment-header">
                    <div>
                        <h2>Discussion</h2>
                        <p>All citizens and authorized panels can comment, reply, like, and dislike.</p>
                    </div>
                    <span class="dg-comment-count" data-comment-count>0</span>
                </div>

                <div class="dg-comment-body">
                    <div class="dg-comment-alert" data-comment-alert></div>

                    <form class="dg-comment-form" data-comment-form>
                        <textarea
                            name="comment_text"
                            data-comment-text
                            placeholder="Write your comment..."
                            maxlength="1000"
                            required
                        ></textarea>

                        <div class="dg-comment-actions">
                            <button type="submit" class="dg-comment-submit">
                                <i class="bi bi-send"></i>
                                Post Comment
                            </button>
                        </div>
                    </form>

                    <div class="dg-comment-list" data-comment-list>
                        <div class="dg-comment-loading">
                            Loading comments...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

</main>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/commentSystem/commentSystem.js"></script>
</body>
</html>
