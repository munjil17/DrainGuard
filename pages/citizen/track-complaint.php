<?php
// C:\xampp\htdocs\DrainGuard\pages\citizen\track-complaint.php

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

$userId = (int)($_SESSION['user_id'] ?? 0);
$searchCode = trim($_GET['code'] ?? '');

$complaint = null;
$mediaFiles = [];
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function dgStartsWith($string, $prefix)
{
    return substr($string, 0, strlen($prefix)) === $prefix;
}

function normalizeComplaintStatus($status)
{
    $status = strtolower(trim((string)$status));

    $map = [
        'assigned' => 'team_assigned',
        'assigned_to_team' => 'team_assigned',
        'team_assigned' => 'team_assigned',
        'completed' => 'solved_by_team',
        'completed_by_team' => 'solved_by_team',
        'team_completed' => 'solved_by_team',
        'under_inspection' => 'inspector_verification',
        'pending_inspection' => 'inspector_verification',
        'solved' => 'closed',
        'resolved' => 'closed',
        'ward_verified' => 'verified'
    ];

    return $map[$status] ?? $status;
}

function normalizeWorkStatus($status)
{
    $status = strtolower(trim((string)$status));

    $map = [
        'assigned' => 'team_assigned',
        'started' => 'in_progress',
        'completed' => 'solved_by_team'
    ];

    return $map[$status] ?? $status;
}

function formatStatus($status)
{
    $status = normalizeComplaintStatus($status);

    $labels = [
        'submitted' => 'Submitted',
        'received' => 'Received',
        'pending_verification' => 'Pending Verification',
        'verified' => 'Verified',
        'team_assigned' => 'Assigned to Team',
        'in_progress' => 'In Progress',
        'solved_by_team' => 'Solved by Team',
        'inspector_verification' => 'Inspector Verification',
        'closed' => 'Closed / Solved',
        'rejected' => 'Rejected',
        'duplicate' => 'Duplicate',
        'reopened' => 'Reopened',
        'n/a' => 'N/A'
    ];

    return $labels[$status] ?? ucwords(str_replace('_', ' ', (string)$status));
}

function statusClass($status)
{
    $status = normalizeComplaintStatus($status);

    if ($status === 'submitted') return 'status-submitted';
    if ($status === 'received') return 'status-received';
    if ($status === 'pending_verification') return 'status-pending';
    if ($status === 'verified') return 'status-verified';
    if ($status === 'team_assigned') return 'status-assigned';
    if ($status === 'in_progress') return 'status-progress';
    if ($status === 'solved_by_team') return 'status-completed';
    if ($status === 'inspector_verification') return 'status-inspection';
    if ($status === 'closed') return 'status-solved';
    if ($status === 'reopened') return 'status-reopened';
    if ($status === 'rejected') return 'status-rejected';
    if ($status === 'duplicate') return 'status-duplicate';

    return 'status-submitted';
}

function formatFileSize($bytes)
{
    $bytes = (int)$bytes;

    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1) . " MB";
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . " KB";
    }

    return $bytes . " B";
}

function makeMediaPath($path)
{
    $path = trim((string)$path);

    if ($path === '') {
        return '';
    }

    $path = str_replace("\\", "/", $path);

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    if (dgStartsWith($path, '../../')) {
        return $path;
    }

    if (dgStartsWith($path, '/')) {
        return $path;
    }

    if (dgStartsWith($path, 'assets/')) {
        return '../../' . $path;
    }

    if (dgStartsWith($path, 'uploads/')) {
        return '../../assets/' . $path;
    }

    if (!strpos($path, '/')) {
        return '../../assets/uploads/complaints/' . $path;
    }

    return '../../' . ltrim($path, '/');
}

if ($searchCode !== '') {
    $sql = "
        SELECT
            c.complaint_id,
            c.complaint_code,
            c.issue_id,
            c.affected_area_id,
            c.address_description,
            c.problem_description,
            c.complaint_status,
            c.work_started_at,
            c.submitted_at,
            c.updated_at,

            i.issue_name,
            aa.affected_area_name,

            city.city_name,
            cc.city_cor_name,
            t.thana_name,
            w.ward_no,
            w.ward_name,
            a.area_name,

            ca.assignment_id,
            ca.assignment_status,
            ca.assigned_at,

            mu.work_status,
            mu.work_note,
            mu.proof_file_path,
            mu.proof_file_type,
            mu.started_at,
            mu.completed_at,
            mu.delayed_at,
            mu.delay_reason,
            mu.created_at AS work_update_created_at,
            mu.updated_at AS work_update_updated_at

        FROM complaints c

        LEFT JOIN issues i
            ON c.issue_id = i.issue_id

        LEFT JOIN affected_areas aa
            ON c.affected_area_id = aa.affected_area_id

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

            /*
            |--------------------------------------------------------------------------
            | Master tracking status
            |--------------------------------------------------------------------------
            | Citizen tracking follows complaints.complaint_status only.
            | Other tables are shown as supporting details.
            |--------------------------------------------------------------------------
            */
            $complaint['complaint_status'] = normalizeComplaintStatus($complaint['complaint_status']);

            /*
            |--------------------------------------------------------------------------
            | Uploaded complaint media
            |--------------------------------------------------------------------------
            */
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
                WHERE complaint_id = ?
                ORDER BY media_id ASC
            ";

            $mediaStmt = mysqli_prepare($conn, $mediaSql);

            if ($mediaStmt) {
                $complaintId = (int)$complaint['complaint_id'];

                mysqli_stmt_bind_param($mediaStmt, "i", $complaintId);
                mysqli_stmt_execute($mediaStmt);

                $mediaResult = mysqli_stmt_get_result($mediaStmt);

                while ($media = mysqli_fetch_assoc($mediaResult)) {
                    $media['display_path'] = makeMediaPath($media['media_path']);
                    $mediaFiles[] = $media;
                }

                mysqli_stmt_close($mediaStmt);
            }

            /*
            |--------------------------------------------------------------------------
            | Maintenance proof fallback
            |--------------------------------------------------------------------------
            | If maintenance update has proof_file_path but not in complaint_media,
            | show it separately in the same evidence card.
            |--------------------------------------------------------------------------
            */
            if (!empty($complaint['proof_file_path'])) {
                $mediaFiles[] = [
                    'media_id' => 0,
                    'complaint_id' => (int)$complaint['complaint_id'],
                    'media_type' => $complaint['proof_file_type'] ?: 'image',
                    'media_path' => $complaint['proof_file_path'],
                    'display_path' => makeMediaPath($complaint['proof_file_path']),
                    'original_name' => basename((string)$complaint['proof_file_path']),
                    'file_size' => 0,
                    'mime_type' => '',
                    'uploaded_at' => $complaint['work_update_created_at'] ?? null
                ];
            }
        } else {
            $errorMessage = "No complaint found with this Complaint ID.";
        }

        mysqli_stmt_close($stmt);
    } else {
        $errorMessage = "Track complaint query failed: " . mysqli_error($conn);
    }
}

/*
|--------------------------------------------------------------------------
| Tracking Workflow
|--------------------------------------------------------------------------
*/

$statusOrder = [
    'submitted',
    'received',
    'pending_verification',
    'verified',
    'team_assigned',
    'in_progress',
    'solved_by_team',
    'inspector_verification',
    'closed'
];

$currentStatus = $complaint['complaint_status'] ?? '';
$currentStatus = normalizeComplaintStatus($currentStatus);

$currentIndex = array_search($currentStatus, $statusOrder, true);

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
        'key' => 'team_assigned',
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

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">
    <link rel="stylesheet" href="../../css/citizen/track-complaint.css">
    <link rel="stylesheet" href="../../css/citizen/citizenTextFix.css">
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
                        placeholder="Enter Complaint ID, e.g. DG-20260523-81881"
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

            <?php if ($searchCode === '' && !$complaint): ?>
                <div class="tc-empty-card">
                    <i class="bi bi-search"></i>
                    <h2>Track your complaint</h2>
                    <p>Enter your complaint ID to view current progress, timeline, location, and uploaded evidence.</p>
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
                        <strong><?php echo safeText($complaint['issue_name'] ?? 'N/A'); ?></strong>
                    </div>

                    <div>
                        <span>Affected Area</span>
                        <strong><?php echo safeText($complaint['affected_area_name'] ?? 'N/A'); ?></strong>
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
                            <strong><?php echo safeText($complaint['city_name'] ?? 'N/A'); ?></strong>
                        </div>

                        <div>
                            <span>City Corporation</span>
                            <strong><?php echo safeText($complaint['city_cor_name'] ?? 'N/A'); ?></strong>
                        </div>

                        <div>
                            <span>Thana</span>
                            <strong><?php echo safeText($complaint['thana_name'] ?? 'N/A'); ?></strong>
                        </div>

                        <div>
                            <span>Ward</span>
                            <strong><?php echo safeText("Ward " . ($complaint['ward_no'] ?? 'N/A')); ?></strong>
                        </div>

                        <div>
                            <span>Area</span>
                            <strong><?php echo safeText($complaint['area_name'] ?? 'N/A'); ?></strong>
                        </div>

                        <div>
                            <span>Affected Area</span>
                            <strong><?php echo safeText($complaint['affected_area_name'] ?? 'N/A'); ?></strong>
                        </div>

                        <div>
                            <span>Issue Type</span>
                            <strong><?php echo safeText($complaint['issue_name'] ?? 'N/A'); ?></strong>
                        </div>

                        <div>
                            <span>Submitted At</span>
                            <strong>
                                <?php
                                    echo !empty($complaint['submitted_at'])
                                        ? safeText(date("M d, Y h:i A", strtotime($complaint['submitted_at'])))
                                        : "N/A";
                                ?>
                            </strong>
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

                        <div>
                            <span>Assignment Status</span>
                            <strong>
                                <?php
                                    $assignmentStatus = $complaint['assignment_status'] ?? 'N/A';
                                    echo safeText(formatStatus(normalizeComplaintStatus($assignmentStatus)));
                                ?>
                            </strong>
                        </div>

                        <div>
                            <span>Maintenance Status</span>
                            <strong>
                                <?php
                                    $workStatus = !empty($complaint['work_status'])
                                        ? normalizeWorkStatus($complaint['work_status'])
                                        : 'N/A';

                                    echo safeText(formatStatus($workStatus));
                                ?>
                            </strong>
                        </div>
                    </div>

                    <div class="tc-description-block">
                        <h3>Address Description</h3>
                        <p><?php echo safeText($complaint['address_description'] ?? ''); ?></p>
                    </div>

                    <div class="tc-description-block">
                        <h3>Problem Description</h3>
                        <p><?php echo safeText($complaint['problem_description'] ?? ''); ?></p>
                    </div>
                </div>

                <?php if (!empty($complaint['work_status']) || !empty($complaint['work_note']) || !empty($complaint['delay_reason'])): ?>
                    <div class="tc-work-update-card">
                        <h2>Maintenance Update</h2>

                        <div class="tc-work-grid">
                            <div>
                                <span>Work Status</span>
                                <strong>
                                    <?php
                                        $workStatus = normalizeWorkStatus($complaint['work_status'] ?? 'N/A');
                                        echo safeText(formatStatus($workStatus));
                                    ?>
                                </strong>
                            </div>

                            <div>
                                <span>Started At</span>
                                <strong>
                                    <?php
                                        echo !empty($complaint['started_at'])
                                            ? safeText(date("M d, Y h:i A", strtotime($complaint['started_at'])))
                                            : "N/A";
                                    ?>
                                </strong>
                            </div>

                            <div>
                                <span>Completed At</span>
                                <strong>
                                    <?php
                                        echo !empty($complaint['completed_at'])
                                            ? safeText(date("M d, Y h:i A", strtotime($complaint['completed_at'])))
                                            : "N/A";
                                    ?>
                                </strong>
                            </div>

                            <div>
                                <span>Delayed At</span>
                                <strong>
                                    <?php
                                        echo !empty($complaint['delayed_at'])
                                            ? safeText(date("M d, Y h:i A", strtotime($complaint['delayed_at'])))
                                            : "N/A";
                                    ?>
                                </strong>
                            </div>
                        </div>

                        <?php if (!empty($complaint['work_note'])): ?>
                            <div class="tc-work-note">
                                <span>Work Note</span>
                                <p><?php echo safeText($complaint['work_note']); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($complaint['delay_reason'])): ?>
                            <div class="tc-work-note">
                                <span>Delay Reason</span>
                                <p><?php echo safeText($complaint['delay_reason']); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="tc-media-card">
                    <h2>Uploaded Evidence</h2>

                    <?php if (count($mediaFiles) > 0): ?>
                        <div class="tc-media-grid">
                            <?php foreach ($mediaFiles as $media): ?>
                                <?php
                                    $mediaType = strtolower((string)($media['media_type'] ?? 'image'));
                                    $mediaPath = $media['display_path'];
                                    $originalName = $media['original_name'] ?: 'Evidence file';
                                    $fileSize = formatFileSize($media['file_size'] ?? 0);
                                    $mimeType = $media['mime_type'] ?? '';
                                ?>

                                <div class="tc-media-item">
                                    <?php if ($mediaType === 'video'): ?>
                                        <video controls class="tc-media-video">
                                            <source src="<?php echo safeText($mediaPath); ?>" type="<?php echo safeText($mimeType ?: 'video/mp4'); ?>">
                                            Your browser does not support the video tag.
                                        </video>

                                        <a href="<?php echo safeText($mediaPath); ?>" target="_blank" class="tc-media-link">
                                            <i class="bi bi-camera-video"></i>
                                            <?php echo safeText($originalName); ?>
                                        </a>
                                    <?php else: ?>
                                        <a href="<?php echo safeText($mediaPath); ?>" target="_blank" class="tc-media-preview">
                                            <img src="<?php echo safeText($mediaPath); ?>" alt="<?php echo safeText($originalName); ?>">
                                        </a>

                                        <a href="<?php echo safeText($mediaPath); ?>" target="_blank" class="tc-media-link">
                                            <i class="bi bi-image"></i>
                                            <?php echo safeText($originalName); ?>
                                        </a>
                                    <?php endif; ?>

                                    <small class="tc-media-meta">
                                        <?php echo safeText(strtoupper($mediaType)); ?>
                                        <?php if ((int)($media['file_size'] ?? 0) > 0): ?>
                                            · <?php echo safeText($fileSize); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="tc-media-empty">
                            No evidence file uploaded for this complaint.
                        </div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </section>

    </main>

</div>

<script src="../../js/citizen/sidebar.js"></script>

</body>
</html>