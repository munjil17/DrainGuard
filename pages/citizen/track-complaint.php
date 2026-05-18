<?php
$activePage = 'track-complaint';
$pageTitle = 'Track Complaint';
$pageParent = 'Citizen';
$pageChild = 'Track Complaint';

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'citizen') {
    header("Location: ../../index.php");
    exit();
}

$userId = (int) $_SESSION['user_id'];
$searchCode = trim($_GET['code'] ?? '');
$complaint = null;
$errorMessage = "";

function safeText($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatStatus($status) {
    $status = normalizeComplaintStatus($status);

    $labels = [
        'submitted' => 'Submitted',
        'received' => 'Received',
        'pending_verification' => 'Pending Verification',
        'verified' => 'Verified',
        'assigned_to_team' => 'Assigned to Team',
        'in_progress' => 'In Progress',
        'solved_by_team' => 'Solved by Team',
        'inspector_verification' => 'Inspector Verification',
        'closed' => 'Closed / Solved',
        'rejected' => 'Rejected',
        'duplicate' => 'Duplicate',
        'reopened' => 'Reopened'
    ];

    return $labels[$status] ?? ucwords(str_replace('_', ' ', (string)$status));
}

/*
|--------------------------------------------------------------------------
| Normalize old status names to new workflow names
|--------------------------------------------------------------------------
*/

function normalizeComplaintStatus($status) {
    $status = strtolower(trim((string)$status));

    $map = [
        'assigned' => 'assigned_to_team',
        'completed' => 'solved_by_team',
        'under_inspection' => 'inspector_verification',
        'solved' => 'closed'
    ];

    return $map[$status] ?? $status;
}

function statusClass($status) {
    $status = normalizeComplaintStatus($status);

    if ($status === 'submitted') return 'status-submitted';
    if ($status === 'received') return 'status-received';
    if ($status === 'pending_verification') return 'status-pending';
    if ($status === 'verified') return 'status-verified';
    if ($status === 'assigned_to_team') return 'status-assigned';
    if ($status === 'in_progress') return 'status-progress';
    if ($status === 'solved_by_team') return 'status-completed';
    if ($status === 'inspector_verification') return 'status-inspection';
    if ($status === 'closed') return 'status-solved';
    if ($status === 'reopened') return 'status-reopened';
    if ($status === 'rejected') return 'status-rejected';
    if ($status === 'duplicate') return 'status-duplicate';

    return 'status-submitted';
}

if ($searchCode !== '') {
    $sql = "
        SELECT
            c.complaint_id,
            c.complaint_code,
            c.issue_type,
            c.address_description,
            c.problem_description,
            c.urgency_level,
            c.complaint_status,
            c.submitted_at,
            c.updated_at,

            city.city_name,
            cc.city_cor_name,
            t.thana_name,
            w.ward_no,
            w.ward_name,
            a.area_name

        FROM complaints c

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

        WHERE c.complaint_code = ?
        AND c.user_id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "si", $searchCode, $userId);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) === 1) {
            $complaint = mysqli_fetch_assoc($result);
            $complaint['complaint_status'] = normalizeComplaintStatus($complaint['complaint_status']);
        } else {
            $errorMessage = "No complaint found with this Complaint ID.";
        }

        mysqli_stmt_close($stmt);
    } else {
        $errorMessage = "Something went wrong. Please try again.";
    }
}

/*
|--------------------------------------------------------------------------
| Final Tracking Workflow
|--------------------------------------------------------------------------
*/

$statusOrder = [
    'submitted',
    'received',
    'pending_verification',
    'verified',
    'assigned_to_team',
    'in_progress',
    'solved_by_team',
    'inspector_verification',
    'closed'
];

$currentStatus = $complaint['complaint_status'] ?? '';
$currentStatus = normalizeComplaintStatus($currentStatus);

$currentIndex = array_search($currentStatus, $statusOrder, true);

/*
|--------------------------------------------------------------------------
| Exceptional status positioning
|--------------------------------------------------------------------------
| rejected   = rejected at submitted stage
| duplicate  = stopped at pending verification stage
| reopened   = returned to received stage
|--------------------------------------------------------------------------
*/

if ($currentStatus === 'rejected') {
    $currentIndex = 0;
}

if ($currentStatus === 'duplicate') {
    $currentIndex = 2;
}

if ($currentStatus === 'reopened') {
    $currentIndex = 1;
}

if ($currentIndex === false) {
    $currentIndex = -1;
}

$timelineSteps = [
    [
        'key' => 'submitted',
        'title' => 'Submitted',
        'description' => 'Complaint submitted successfully'
    ],
    [
        'key' => 'received',
        'title' => 'Received',
        'description' => 'Central officer accepted and received the complaint'
    ],
    [
        'key' => 'pending_verification',
        'title' => 'Pending Verification',
        'description' => 'Complaint sent to ward officer for problem verification'
    ],
    [
        'key' => 'verified',
        'title' => 'Verified',
        'description' => 'Ward officer verified the problem'
    ],
    [
        'key' => 'assigned_to_team',
        'title' => 'Assigned to Team',
        'description' => 'Maintenance team has been assigned'
    ],
    [
        'key' => 'in_progress',
        'title' => 'In Progress',
        'description' => 'Maintenance work is ongoing'
    ],
    [
        'key' => 'solved_by_team',
        'title' => 'Solved by Team',
        'description' => 'Maintenance team submitted completion proof'
    ],
    [
        'key' => 'inspector_verification',
        'title' => 'Inspector Verification',
        'description' => 'Inspector is reviewing before/after proof'
    ],
    [
        'key' => 'closed',
        'title' => 'Closed / Solved',
        'description' => 'Complaint solved and closed'
    ]
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Track Complaint | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <!-- Global CSS -->
    <link rel="stylesheet" href="../../css/global/global.css">

    <!-- Reusable Citizen Layout CSS -->
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">

    <!-- Page CSS -->
    <link rel="stylesheet" href="../../css/citizen/track-complaint.css">
</head>

<body class="citizen">

<div class="citizen-layout">

    <?php include "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main main-content">

        <?php include "../../includes/citizen/topbar.php"; ?>

        <section class="tc-page">

            <div class="tc-header">
                <h1>Track Complaint</h1>
                <p>Enter complaint ID to track its progress</p>
            </div>

            <form class="tc-search-card" method="GET" action="track-complaint.php">
                <div class="tc-search-box">
                    <i class="bi bi-search"></i>
                    <input
                        type="text"
                        name="code"
                        value="<?php echo safeText($searchCode); ?>"
                        placeholder="Enter Complaint ID, e.g. DG-20260514-67727"
                        required
                    >
                </div>

                <button type="submit">
                    Track
                    <i class="bi bi-arrow-right"></i>
                </button>
            </form>

            <?php if ($errorMessage !== ''): ?>
                <div class="tc-alert tc-error">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo safeText($errorMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($complaint): ?>

                <div class="tc-summary-card">
                    <div>
                        <span>Complaint ID</span>
                        <strong><?php echo safeText($complaint['complaint_code']); ?></strong>
                    </div>

                    <div>
                        <span>Issue Type</span>
                        <strong><?php echo safeText($complaint['issue_type']); ?></strong>
                    </div>

                    <div>
                        <span>Area</span>
                        <strong>
                            <?php echo safeText("Ward " . $complaint['ward_no'] . ", " . $complaint['area_name']); ?>
                        </strong>
                    </div>

                    <div>
                        <span>Status</span>
                        <strong class="tc-status <?php echo statusClass($currentStatus); ?>">
                            <?php echo safeText(formatStatus($currentStatus)); ?>
                        </strong>
                    </div>
                </div>

                <?php if ($currentStatus === 'rejected'): ?>
                    <div class="tc-alert tc-error">
                        <i class="bi bi-x-circle"></i>
                        This complaint has been rejected by the authority.
                    </div>
                <?php endif; ?>

                <?php if ($currentStatus === 'duplicate'): ?>
                    <div class="tc-alert tc-duplicate">
                        <i class="bi bi-files"></i>
                        This complaint has been marked as duplicate because a similar issue was already reported for this area.
                    </div>
                <?php endif; ?>

                <?php if ($currentStatus === 'reopened'): ?>
                    <div class="tc-alert tc-warning">
                        <i class="bi bi-arrow-clockwise"></i>
                        This complaint has been reopened and is waiting for review.
                    </div>
                <?php endif; ?>

                <div class="tc-card">
                    <div class="tc-card-header">
                        <h2>Progress Timeline</h2>
                        <p>Current complaint tracking status</p>
                    </div>

                    <div class="tc-timeline">

                        <?php foreach ($timelineSteps as $index => $step): ?>
                            <?php
                                $stepClass = "";

                                if ($index < $currentIndex) {
                                    $stepClass = "completed";
                                }

                                if ($index === $currentIndex) {
                                    $stepClass = "active";
                                }

                                if ($currentStatus === 'rejected' && $index === 0) {
                                    $stepClass = "rejected";
                                }

                                if ($currentStatus === 'duplicate' && $index === 2) {
                                    $stepClass = "duplicate";
                                }

                                if ($currentStatus === 'reopened' && $index === 1) {
                                    $stepClass = "active";
                                }
                            ?>

                            <div class="tc-timeline-item <?php echo $stepClass; ?>">
                                <div class="tc-timeline-icon">
                                    <?php if ($stepClass === "completed"): ?>
                                        <i class="bi bi-check-lg"></i>
                                    <?php elseif ($stepClass === "active"): ?>
                                        <i class="bi bi-clock"></i>
                                    <?php elseif ($stepClass === "rejected"): ?>
                                        <i class="bi bi-x-lg"></i>
                                    <?php elseif ($stepClass === "duplicate"): ?>
                                        <i class="bi bi-files"></i>
                                    <?php else: ?>
                                        <i class="bi bi-circle"></i>
                                    <?php endif; ?>
                                </div>

                                <div class="tc-timeline-content">
                                    <?php if ($currentStatus === 'duplicate' && $step['key'] === 'pending_verification'): ?>
                                        <h3>Duplicate</h3>
                                        <p>A similar complaint already exists for this area/problem</p>
                                    <?php else: ?>
                                        <h3><?php echo safeText($step['title']); ?></h3>
                                        <p><?php echo safeText($step['description']); ?></p>
                                    <?php endif; ?>
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
                            <strong><?php echo safeText($complaint['city_name']); ?></strong>
                        </div>

                        <div>
                            <span>City Corporation</span>
                            <strong><?php echo safeText($complaint['city_cor_name']); ?></strong>
                        </div>

                        <div>
                            <span>Thana</span>
                            <strong><?php echo safeText($complaint['thana_name']); ?></strong>
                        </div>

                        <div>
                            <span>Ward</span>
                            <strong><?php echo safeText("Ward " . $complaint['ward_no']); ?></strong>
                        </div>

                        <div>
                            <span>Area</span>
                            <strong><?php echo safeText($complaint['area_name']); ?></strong>
                        </div>

                        <div>
                            <span>Urgency</span>
                            <strong><?php echo safeText($complaint['urgency_level']); ?></strong>
                        </div>

                        <div>
                            <span>Submitted At</span>
                            <strong><?php echo safeText(date("M d, Y h:i A", strtotime($complaint['submitted_at']))); ?></strong>
                        </div>

                        <div>
                            <span>Last Updated</span>
                            <strong>
                                <?php
                                    echo !empty($complaint['updated_at'])
                                        ? safeText(date("M d, Y h:i A", strtotime($complaint['updated_at'])))
                                        : "Not updated";
                                ?>
                            </strong>
                        </div>
                    </div>

                    <div class="tc-description-block">
                        <h3>Address Description</h3>
                        <p><?php echo safeText($complaint['address_description']); ?></p>
                    </div>

                    <div class="tc-description-block">
                        <h3>Problem Description</h3>
                        <p><?php echo safeText($complaint['problem_description']); ?></p>
                    </div>
                </div>

            <?php endif; ?>

        </section>

    </main>

</div>

<script src="../../js/citizen/sidebar.js"></script>

</body>
</html>