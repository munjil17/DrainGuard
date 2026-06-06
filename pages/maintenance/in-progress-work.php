<?php
$pageTitle = "In Progress Work";
$activePage = "in-progress-work";

require_once "../../config.php";
require_once "../../auth/session_check.php";

$userId = $_SESSION['user_id'] ?? 0;

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function priorityClass($priority)
{
    $priority = strtolower((string)$priority);

    if ($priority === 'high') {
        return 'priority-high';
    }

    if ($priority === 'medium') {
        return 'priority-medium';
    }

    return 'priority-low';
}

$teamInfo = [
    'team_id' => 0,
    'team_name' => 'Maintenance Team',
    'member_name' => $_SESSION['user_name'] ?? 'Maintenance User'
];

$tasks = [];

if ($userId > 0) {
    $teamSql = "
        SELECT
            mt.maintenance_team_id,
            mt.team_name,
            mtm.full_name
        FROM maintenance_team_members mtm
        INNER JOIN maintenance_teams mt
            ON mt.maintenance_team_id = mtm.maintenance_team_id
        WHERE mtm.user_id = ?
        LIMIT 1
    ";

    $teamStmt = mysqli_prepare($conn, $teamSql);

    if ($teamStmt) {
        mysqli_stmt_bind_param($teamStmt, "i", $userId);
        mysqli_stmt_execute($teamStmt);
        $teamResult = mysqli_stmt_get_result($teamStmt);

        if ($teamResult && mysqli_num_rows($teamResult) > 0) {
            $teamRow = mysqli_fetch_assoc($teamResult);

            $teamInfo['team_id'] = (int)$teamRow['maintenance_team_id'];
            $teamInfo['team_name'] = $teamRow['team_name'] ?? $teamInfo['team_name'];
            $teamInfo['member_name'] = $teamRow['full_name'] ?? $teamInfo['member_name'];
        }

        mysqli_stmt_close($teamStmt);
    }
}

$teamId = (int)$teamInfo['team_id'];

if ($teamId > 0) {
    $taskSql = "
        SELECT
            ca.assignment_id,
            ca.complaint_id,
            ca.ward_id,
            ca.maintenance_team_id,
            ca.assigned_by,
            ca.assignment_status,
            ca.assigned_at,
            ca.deadline_at,
            ca.assignment_priority,
            ca.task_note,

            c.complaint_code,
            c.complaint_status,
            c.address_description,
            c.problem_description,
            c.work_started_at,
            c.submitted_at,
            c.updated_at,

            cm.media_path,
            cm.media_type,
            cm.original_name,
            cm.file_size,
            cm.mime_type,

            w.ward_no,
            w.ward_name,

            a.area_id,
            a.area_name,

            u.user_name AS assigned_by_name,

            msr.request_status AS support_status,
            msr.support_reason,
            msr.other_reason,
            msr.support_details,
            msr.ward_reply,
            msr.replied_at
        FROM complaint_assignments ca
        INNER JOIN complaints c
            ON c.complaint_id = ca.complaint_id
        LEFT JOIN wards w
            ON w.ward_id = ca.ward_id
        LEFT JOIN locations l
            ON l.loc_id = c.loc_id
        LEFT JOIN areas a
            ON a.area_id = l.area_id
        LEFT JOIN users u
            ON u.user_id = ca.assigned_by
        LEFT JOIN (
            SELECT complaint_id, MIN(media_id) AS first_media_id
            FROM complaint_media
            GROUP BY complaint_id
        ) first_media
            ON first_media.complaint_id = c.complaint_id
        LEFT JOIN complaint_media cm
            ON cm.media_id = first_media.first_media_id
        LEFT JOIN (
            SELECT msr1.*
            FROM maintenance_support_requests msr1
            INNER JOIN (
                SELECT assignment_id, MAX(support_request_id) as latest_id
                FROM maintenance_support_requests
                WHERE request_status IN ('pending', 'seen', 'replied')
                GROUP BY assignment_id
            ) msr2 ON msr1.support_request_id = msr2.latest_id
        ) msr
            ON msr.assignment_id = ca.assignment_id
        WHERE ca.maintenance_team_id = ?
        AND ca.assignment_status = 'in_progress'
        AND c.complaint_status = 'in_progress'
        ORDER BY c.work_started_at DESC, ca.assigned_at DESC
    ";

    $taskStmt = mysqli_prepare($conn, $taskSql);

    if ($taskStmt) {
        mysqli_stmt_bind_param($taskStmt, "i", $teamId);
        mysqli_stmt_execute($taskStmt);
        $taskResult = mysqli_stmt_get_result($taskStmt);

        while ($taskResult && $row = mysqli_fetch_assoc($taskResult)) {
            $tasks[] = $row;
        }

        mysqli_stmt_close($taskStmt);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>In Progress Work | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/maintenance/sidebar.css">
    <link rel="stylesheet" href="../../css/maintenance/topbar.css">
    <link rel="stylesheet" href="../../css/maintenance/in-progress-work.css?v=1.1">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="maintenance">
    <div class="maintenance-layout">
        <?php include "../../includes/maintenance/sidebar.php"; ?>

        <main class="maintenance-main">
            <?php include "../../includes/maintenance/topbar.php"; ?>

            <section class="inprogress-page">
                <div class="page-heading">
                    <h1>In Progress Work</h1>
                    <p>Active tasks currently being worked on by <?php echo e($teamInfo['team_name']); ?></p>
                </div>

                <div class="inprogress-task-list">
                    <?php if (count($tasks) > 0): ?>
                        <?php foreach ($tasks as $task): ?>
                            <?php
                            $deadlineText = 'No deadline';

                            if (!empty($task['deadline_at'])) {
                                $deadlineText = date("M d, Y", strtotime($task['deadline_at']));
                            }

                            $startedText = 'Not available';

                            if (!empty($task['work_started_at'])) {
                                $startedTimestamp = strtotime($task['work_started_at']);
                                $diffSeconds = time() - $startedTimestamp;

                                if ($diffSeconds < 60) {
                                    $startedText = 'Just now';
                                } elseif ($diffSeconds < 3600) {
                                    $startedText = floor($diffSeconds / 60) . ' minutes ago';
                                } elseif ($diffSeconds < 86400) {
                                    $startedText = floor($diffSeconds / 3600) . ' hours ago';
                                } else {
                                    $startedText = date("M d, Y h:i A", $startedTimestamp);
                                }
                            }

                            $mediaPath = $task['media_path'] ?? '';
                            $mediaType = $task['media_type'] ?? '';
                            $hasMedia = !empty($mediaPath);
                            $hasImage = $hasMedia && $mediaType === 'image';
                            $hasVideo = $hasMedia && $mediaType === 'video';

                            $downloadPath = $hasMedia ? "../../" . $mediaPath : "#";

                        $wardText = 'Ward not found';

if (!empty($task['ward_name'])) {
    $wardText = $task['ward_name'];
} elseif (!empty($task['ward_no'])) {
    $wardText = 'Ward ' . $task['ward_no'];
}
                            $areaText = !empty($task['area_name'])
                                ? $task['area_name']
                                : 'Area not found';

                            $issueTitle = 'Drainage Complaint';
                            ?>

                            <article
                                class="task-card"
                                data-complaint-id="<?php echo e($task['complaint_id']); ?>"
                                data-complaint-code="<?php echo e($task['complaint_code']); ?>"
                                data-notification-target="<?php echo e($task['complaint_id']); ?>"
                                data-assignment-id="<?php echo e($task['assignment_id']); ?>"
                            >
                                <div class="task-media">
                                    <?php if ($hasImage): ?>
                                        <a href="<?php echo e($downloadPath); ?>" download class="media-download-link" title="Download complaint photo">
                                            <img src="../../<?php echo e($mediaPath); ?>" alt="Complaint evidence">
                                            <span class="download-hover">
                                                <i class="bi bi-download"></i>
                                                Download
                                            </span>
                                        </a>
                                    <?php elseif ($hasVideo): ?>
                                        <a href="<?php echo e($downloadPath); ?>" download class="media-download-link" title="Download complaint video">
                                            <video src="../../<?php echo e($mediaPath); ?>" muted></video>
                                            <span class="video-mark">
                                                <i class="bi bi-play-fill"></i>
                                            </span>
                                            <span class="download-hover">
                                                <i class="bi bi-download"></i>
                                                Download
                                            </span>
                                        </a>
                                    <?php else: ?>
                                        <div class="no-media">
                                            <i class="bi bi-image"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="task-content">
                                    <div class="task-topline">
                                        <div class="task-badges">
                                            <span class="code-badge"><?php echo e($task['complaint_code']); ?></span>

                                            <span class="priority-badge <?php echo e(priorityClass($task['assignment_priority'])); ?>">
                                                <?php echo e($task['assignment_priority']); ?>
                                            </span>

                                            <span class="status-badge status-progress">
                                                In Progress
                                            </span>
                                        </div>
                                    </div>

                                    <?php if ($task['support_status']): ?>
                                        <div style="background: #e9ecef; border-left: 4px solid #6c757d; padding: 10px; margin-bottom: 15px; border-radius: 4px;">
                                            <p style="margin: 0; font-size: 13px;"><strong><i class="bi bi-info-circle"></i> Support Request:</strong> <?= e($task["support_reason"] === 'others' ? $task["other_reason"] : str_replace('_', ' ', $task["support_reason"])); ?></p>
                                            <p style="margin: 2px 0 0 0; font-size: 13px;"><strong>Status:</strong> <span style="text-transform: capitalize;"><?= e($task["support_status"]); ?></span></p>
                                            
                                            <?php if ($task['support_status'] === 'replied' && !empty($task['ward_reply'])): ?>
                                                <div style="background: #fff; padding: 8px; margin-top: 8px; border-radius: 4px; border: 1px solid #ced4da;">
                                                    <p style="margin: 0; font-size: 12px; color: #495057;"><strong>Ward Officer Reply:</strong></p>
                                                    <p style="margin: 0; font-size: 13px;"><?= e($task["ward_reply"]); ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <h2><?php echo e($issueTitle); ?></h2>

                                    <div class="task-info-grid">
                                        <span>
                                            <i class="bi bi-geo-alt"></i>
                                            Location:
                                            <strong><?php echo e($task['address_description'] ?: 'Address not provided'); ?></strong>
                                        </span>

                                        <span>
                                            <i class="bi bi-people"></i>
                                            Assigned From:
                                            <strong><?php echo e($task['assigned_by_name'] ?: 'Ward Officer'); ?></strong>
                                        </span>

                                        <span>
                                            <i class="bi bi-map"></i>
                                            Ward:
                                            <strong><?php echo e($wardText); ?></strong>
                                        </span>

                                        <span>
                                            <i class="bi bi-pin-map"></i>
                                            Area:
                                            <strong><?php echo e($areaText); ?></strong>
                                        </span>

                                        <span class="deadline-line">
                                            <i class="bi bi-calendar-event"></i>
                                            Deadline:
                                            <strong><?php echo e($deadlineText); ?></strong>
                                        </span>

                                        <span>
                                            <i class="bi bi-calendar-check"></i>
                                            Current Status:
                                            <strong>In Progress</strong>
                                        </span>
                                    </div>

                                    <p class="problem-text">
                                        <?php echo e($task['problem_description'] ?: 'No problem description provided.'); ?>
                                    </p>

                                    <div class="inprogress-extra-info">
                                        <p>
                                            Started:
                                            <strong><?php echo e($startedText); ?></strong>
                                        </p>

                                        <p>
                                            Expected Completion:
                                            <strong><?php echo e($deadlineText); ?></strong>
                                        </p>
                                    </div>

                                    <div class="workflow-box">
                                        <p>Workflow Timeline</p>

                                        <div class="workflow-line">
                                            <span class="done">Submitted</span>
                                            <span class="done">Verified</span>
                                            <span class="done">Assigned</span>
                                            <span class="current">In Progress</span>
                                            <span>Solved by Team</span>
                                            <span>Waiting Inspection</span>
                                            <span>Citizen Feedback</span>
                                            <span>Closed</span>
                                        </div>
                                    </div>

                                    <div class="task-actions">
                                        <button type="button" class="task-btn work-started-btn" disabled>
                                            <i class="bi bi-hourglass-split"></i>
                                            Work Started
                                        </button>

                                        <button
                                            type="button"
                                            class="task-btn details-btn"
                                            data-issue="<?php echo e($issueTitle); ?>"
                                            data-priority="<?php echo e($task['assignment_priority']); ?>"
                                            data-address="<?php echo e($task['address_description'] ?: 'Address not provided'); ?>"
                                            data-ward="<?php echo e($wardText); ?>"
                                            data-area="<?php echo e($areaText); ?>"
                                            data-problem="<?php echo e($task['problem_description'] ?: 'No problem description provided.'); ?>"
                                            data-note="<?php echo e($task['task_note'] ?: 'No task note provided.'); ?>"
                                            data-assigned-by="<?php echo e($task['assigned_by_name'] ?: 'Ward Officer'); ?>"
                                            data-started-at="<?php echo e($startedText); ?>"
                                            data-deadline="<?php echo e($deadlineText); ?>"
                                            data-media-path="<?php echo e($hasMedia ? '../../' . $mediaPath : ''); ?>"
                                            data-media-type="<?php echo e($mediaType); ?>"
                                        >
                                            <i class="bi bi-eye"></i>
                                            View Details
                                        </button>

                                        <button 
                                            type="button" 
                                            class="task-btn support-btn need-support-btn"
                                            data-assignment-id="<?php echo e($task['assignment_id']); ?>"
                                        >
                                            <i class="bi bi-bell"></i>
                                            Need Support
                                        </button>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-hourglass-split"></i>
                            <h2>No in-progress task found</h2>
                            <p>No task has been started by this maintenance team yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <div class="progress-modal" id="progressModal" aria-hidden="true">
        <div class="progress-modal-backdrop" data-close-progress-modal></div>

        <div class="progress-modal-card">
            <div class="modal-head">
                <div>
                    <span id="modalComplaintCode">Complaint</span>
                    <h2 id="modalIssue">In Progress Task Details</h2>
                </div>

                <button type="button" class="modal-close" data-close-progress-modal>
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="modal-media" id="modalMediaBox">
                    <div class="modal-no-media">
                        <i class="bi bi-image"></i>
                        <span>No media available</span>
                    </div>
                </div>

                <div class="modal-details">
                    <div class="detail-row"><strong>Priority</strong><span id="modalPriority">N/A</span></div>
                    <div class="detail-row"><strong>Status</strong><span>In Progress</span></div>
                    <div class="detail-row"><strong>Address</strong><span id="modalAddress">N/A</span></div>
                    <div class="detail-row"><strong>Ward</strong><span id="modalWard">N/A</span></div>
                    <div class="detail-row"><strong>Area</strong><span id="modalArea">N/A</span></div>
                    <div class="detail-row"><strong>Assigned By</strong><span id="modalAssignedBy">N/A</span></div>
                    <div class="detail-row"><strong>Started</strong><span id="modalStartedAt">N/A</span></div>
                    <div class="detail-row"><strong>Expected Completion</strong><span id="modalDeadline">N/A</span></div>
                </div>

                <div class="modal-text-block">
                    <h3>Problem Description</h3>
                    <p id="modalProblem">N/A</p>
                </div>

                <div class="modal-text-block">
                    <h3>Ward Officer Task Note</h3>
                    <p id="modalNote">N/A</p>
                </div>
            </div>

            <div class="modal-actions">
                <a href="#" id="modalDownloadBtn" class="modal-download-btn" download>
                    <i class="bi bi-download"></i>
                    Download Complaint Photo
                </a>

                <button type="button" class="modal-secondary-btn" data-close-progress-modal>
                    Close
                </button>
            </div>
        </div>
    </div>

    <div class="support-modal" id="supportModal" aria-hidden="true">
        <div class="support-modal-backdrop" data-close-support-modal></div>

        <div class="support-modal-card">
            <div class="support-modal-head">
                <div>
                    <span id="supportComplaintCode">Complaint</span>
                    <h2>Need Support</h2>
                    <p>Select why your team needs support for this in-progress task.</p>
                </div>

                <button type="button" class="support-modal-close" data-close-support-modal>
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <form id="supportRequestForm">
                <div class="support-form-group">
                    <label for="selectedSupportReason" class="support-label">Support Reason (Subject)</label>
                    <select id="selectedSupportReason" name="support_reason" class="support-input" required>
                        <option value="" disabled selected>Select a reason...</option>
                        <option value="equipment_needed">Equipment Needed</option>
                        <option value="extra_manpower_needed">Extra Manpower Needed</option>
                        <option value="location_access_problem">Location / Access Problem</option>
                        <option value="complaint_info_unclear">Complaint Info Unclear</option>
                        <option value="safety_risk">Safety Risk</option>
                        <option value="large_work_scope">Large Work Scope</option>
                        <option value="others">Others</option>
                    </select>
                </div>

                <div class="support-form-group" id="otherReasonGroup" style="display: none; margin-top: 15px;">
                    <label for="otherReasonInput" class="support-label">Write your support reason</label>
                    <input type="text" id="otherReasonInput" name="other_reason" class="support-input" placeholder="Specify your reason">
                </div>

                <div class="support-form-group" id="supportDetailsGroup" style="display: none; margin-top: 15px;">
                    <label for="supportDetailsInput" class="support-label">Support Details / Issue Description</label>
                    <textarea id="supportDetailsInput" name="support_details" class="support-textarea" placeholder="Explain what support is needed for this task..." required rows="4"></textarea>
                </div>

                <div class="support-modal-actions" style="margin-top: 20px;">
                    <button type="button" class="support-cancel-btn" data-close-support-modal>
                        Cancel
                    </button>
                    <button type="submit" class="support-submit-btn" id="submitSupportBtn" disabled>
                        Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="../../js/maintenance/sidebar.js"></script>
    <script src="../../js/maintenance/in-progress-work.js"></script>
<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>
