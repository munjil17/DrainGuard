<?php
$activePage = 'citizen-objection';
$pageTitle = 'Citizen Objection';
$pageParent = 'Citizen';
$pageChild = 'Citizen Objection';

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
$objections = [];

function coText($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function coDate($datetime)
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

function coRequestStatusLabel($status)
{
    $map = [
        'pending' => 'Waiting for Ward Officer Review',
        'sent_to_inspector' => 'Forwarded to Inspector',
        'sent_to_ward_for_reassign' => 'Sent to Ward for Team Assignment',
        'reassigned_same_team' => 'Reassigned Same Team',
        'reassigned_different_team' => 'Reassigned Different Team',
        'rejected' => 'Objection Rejected',
        'resolved' => 'Resolved'
    ];

    return $map[$status] ?? ucwords(str_replace('_', ' ', (string) $status));
}

function coPreview($value, $limit = 95)
{
    $text = trim(preg_replace('/\s+/', ' ', (string) ($value ?? '')));

    if ($text === '') {
        return 'No objection reason provided.';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '...' : $text;
    }

    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

$sql = "
    SELECT
        rr.reopen_id,
        rr.complaint_id,
        rr.reason,
        rr.request_status,
        rr.ward_note,
        rr.inspector_note,
        rr.created_at AS objection_created_at,
        rr.forwarded_at,
        rr.handled_at,

        f.feedback_text AS objection_details,

        c.complaint_code,
        c.problem_description,
        c.address_description,
        c.complaint_status,

        i.issue_name,
        w.ward_no,
        a.area_name,
        t.thana_name

    FROM reopen_requests rr
    INNER JOIN complaints c
        ON c.complaint_id = rr.complaint_id
    LEFT JOIN issues i
        ON i.issue_id = c.issue_id
    LEFT JOIN feedbacks f
        ON f.complaint_id = rr.complaint_id
        AND f.user_id = rr.requested_by
        AND f.feedback_type = 'false_completion'
    INNER JOIN locations l
        ON l.loc_id = c.loc_id
    INNER JOIN thanas t
        ON t.thana_id = l.thana_id
    INNER JOIN wards w
        ON w.ward_id = l.ward_id
    INNER JOIN areas a
        ON a.area_id = l.area_id

    WHERE rr.requested_by = ?
    AND c.user_id = ?
    AND rr.request_type = 'citizen_objection'
    AND rr.request_status IN ('pending', 'sent_to_inspector', 'sent_to_ward_for_reassign')

    ORDER BY rr.created_at DESC, rr.reopen_id DESC
";

$stmt = mysqli_prepare($conn, $sql);

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "ii", $userId, $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $objections[] = $row;
    }

    mysqli_stmt_close($stmt);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Citizen Objection | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">
    <link rel="stylesheet" href="../../css/citizen/citizen-objection.css">
</head>

<body class="citizen">

<div class="citizen-layout">

    <?php include "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main main-content">

        <?php include "../../includes/citizen/topbar.php"; ?>

        <section class="co-page">

            <div class="co-header">
                <div>
                    <h1>Citizen Objection</h1>
                    <p>Active objections you submitted for solved complaints.</p>
                </div>

                <div class="co-count-card">
                    <span><?php echo count($objections); ?></span>
                    <small>Active</small>
                </div>
            </div>

            <div class="co-list">
                <?php if (count($objections) > 0): ?>
                    <?php foreach ($objections as $item): ?>
                        <?php $detailsId = 'objection_details_' . (int) $item['reopen_id']; ?>
                        <article
                            class="co-card objection-card"
                            data-complaint-id="<?php echo (int) $item['complaint_id']; ?>"
                            data-complaint-code="<?php echo coText($item['complaint_code']); ?>"
                            data-notification-target="<?php echo (int) $item['complaint_id']; ?>"
                        >
                            <div class="co-card-top">
                                <div>
                                    <div class="co-meta-row">
                                        <span class="co-code"><?php echo coText($item['complaint_code']); ?></span>
                                        <span class="co-status"><?php echo coText(coRequestStatusLabel($item['request_status'])); ?></span>
                                    </div>

                                    <h2><?php echo coText($item['issue_name'] ?: 'Drainage Issue'); ?></h2>

                                    <p>
                                        <?php echo coText($item['area_name']); ?>,
                                        Ward <?php echo coText($item['ward_no']); ?>
                                        <?php if (!empty($item['thana_name'])): ?>
                                            - <?php echo coText($item['thana_name']); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <div class="co-summary-grid">
                                <div class="co-summary-item">
                                    <span>Submitted</span>
                                    <strong><?php echo coText(coDate($item['objection_created_at'])); ?></strong>
                                </div>

                                <div class="co-summary-item">
                                    <span>Complaint Status</span>
                                    <strong><?php echo coText(ucwords(str_replace('_', ' ', (string) $item['complaint_status']))); ?></strong>
                                </div>
                            </div>

                            <p class="co-reason-preview">
                                <strong>Reason:</strong>
                                <?php echo coText(coPreview($item['reason'] ?: $item['objection_details'])); ?>
                            </p>

                            <div class="co-card-actions">
                                <button
                                    type="button"
                                    class="co-details-toggle"
                                    aria-expanded="false"
                                    aria-controls="<?php echo coText($detailsId); ?>"
                                >
                                    <i class="bi bi-eye"></i>
                                    <span>View Details</span>
                                </button>
                            </div>

                            <div class="co-details-panel" id="<?php echo coText($detailsId); ?>" hidden>
                                <div class="co-info-grid">
                                    <div>
                                        <span>Complaint ID / Code</span>
                                        <strong><?php echo coText($item['complaint_code']); ?></strong>
                                    </div>

                                    <div>
                                        <span>Issue Type</span>
                                        <strong><?php echo coText($item['issue_name'] ?: 'Drainage Issue'); ?></strong>
                                    </div>

                                    <div>
                                        <span>Area</span>
                                        <strong><?php echo coText($item['area_name']); ?></strong>
                                    </div>

                                    <div>
                                        <span>Submitted Date</span>
                                        <strong><?php echo coText(coDate($item['objection_created_at'])); ?></strong>
                                    </div>

                                    <div>
                                        <span>Current Objection Status</span>
                                        <strong><?php echo coText(coRequestStatusLabel($item['request_status'])); ?></strong>
                                    </div>

                                    <div>
                                        <span>Complaint Status</span>
                                        <strong><?php echo coText(ucwords(str_replace('_', ' ', (string) $item['complaint_status']))); ?></strong>
                                    </div>
                                </div>

                                <div class="co-note">
                                    <span>Full Objection Reason</span>
                                    <p><?php echo coText($item['reason'] ?: 'No objection reason provided.'); ?></p>
                                </div>

                                <?php if (!empty($item['objection_details']) || !empty($item['reason'])): ?>
                                    <div class="co-note">
                                        <span>Full Objection Details</span>
                                        <p><?php echo coText($item['objection_details'] ?: $item['reason']); ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($item['problem_description'])): ?>
                                    <div class="co-note">
                                        <span>Complaint Details</span>
                                        <p><?php echo coText($item['problem_description']); ?></p>
                                    </div>
                                <?php endif; ?>

                                <div class="co-decision-grid">
                                    <div class="co-note">
                                        <span>Ward Officer Decision</span>
                                        <p><?php echo coText($item['ward_note'] ?: 'Decision not available yet.'); ?></p>
                                    </div>

                                    <div class="co-note">
                                        <span>Inspector Decision</span>
                                        <p><?php echo coText($item['inspector_note'] ?: 'Decision not available yet.'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="co-empty">
                        <i class="bi bi-check2-circle"></i>
                        <h2>No active citizen objections</h2>
                        <p>Submitted objections waiting for Ward Officer or Inspector review will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>

        </section>

    </main>

</div>

<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/citizen/citizen-objection.js"></script>
</body>
</html>
