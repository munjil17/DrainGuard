<?php
require_once "../../config.php";
require_once "../../auth/session_check.php";

$activePage = "ward-complaints";
$pageTitle = "Ward Complaints";

if (!isset($conn) || !$conn) {
    die("Service is temporarily unavailable. Please try again.");
}

$currentUserId = $_SESSION['user_id'] ?? 0;
$currentUserMail = $_SESSION['user_mail'] ?? '';

function wc_fetchOne($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("Unable to load records. Please try again.");
    }

    if (!empty($types) && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function wc_fetchAll($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("Unable to load records. Please try again.");
    }

    if (!empty($types) && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function wc_tableColumns($conn, $tableName)
{
    $columns = [];
    $safeTable = mysqli_real_escape_string($conn, $tableName);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$safeTable`");

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $columns[] = $row['Field'];
        }
    }

    return $columns;
}

function wc_firstExistingColumn($columns, $possibleColumns)
{
    foreach ($possibleColumns as $column) {
        if (in_array($column, $columns, true)) {
            return $column;
        }
    }

    return null;
}

function wc_startsWith($string, $prefix)
{
    return substr($string, 0, strlen($prefix)) === $prefix;
}

function wc_mediaWebPath($path)
{
    $path = trim((string)$path);

    if ($path === '') {
        return '';
    }

    $path = str_replace('\\', '/', $path);

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    if (wc_startsWith($path, '/')) {
        return $path;
    }

    if (wc_startsWith($path, '../../')) {
        return $path;
    }

    if (wc_startsWith($path, 'assets/')) {
        return '../../' . $path;
    }

    if (wc_startsWith($path, 'uploads/')) {
        return '../../assets/' . $path;
    }

    if (!str_contains($path, '/')) {
        return '../../assets/uploads/complaints/' . $path;
    }

    return '../../' . ltrim($path, '/');
}

function wc_mediaType($path, $type = '')
{
    $type = strtolower(trim((string)$type));

    if (str_contains($type, 'image')) {
        return 'image';
    }

    if (str_contains($type, 'video')) {
        return 'video';
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return 'image';
    }

    if (in_array($extension, ['mp4', 'webm', 'ogg', 'mov'], true)) {
        return 'video';
    }

    return 'file';
}

function wc_priorityClass($priority)
{
    $priority = strtolower(trim($priority ?? ''));

    if ($priority === 'high') {
        return 'high';
    }

    if ($priority === 'medium') {
        return 'medium';
    }

    return 'low';
}

function wc_formatDate($datetime)
{
    if (!$datetime) {
        return "N/A";
    }

    $timestamp = strtotime($datetime);

    if (!$timestamp) {
        return "N/A";
    }

    return date("M d", $timestamp);
}

/*
|--------------------------------------------------------------------------
| Status Logic
|--------------------------------------------------------------------------
| Main source: complaints.complaint_status ONLY
| Do not override status using complaint_assignments.assignment_status
| or maintenance_updates.work_status.
|--------------------------------------------------------------------------
*/
function wc_statusData($complaintStatus)
{
    $status = strtolower(trim((string)$complaintStatus));

    if ($status === '') {
        $status = 'unknown';
    }

    $labels = [
        "submitted" => "Submitted",
        "received" => "Received",
        "pending_verification" => "Pending Verification",

        "verified" => "Verified By Ward",
        "ward_verified" => "Verified By Ward",
        "verified_by_ward" => "Verified By Ward",

        "rejected_by_central" => "Rejected By Central",
        "rejected_by_ward" => "Rejected By Ward",
        "rejected" => "Rejected",

        "duplicate" => "Duplicate",

        "team_assigned" => "Assigned to Team",
        "assigned_to_team" => "Assigned to Team",

        "in_progress" => "In Progress",
        "solved_by_team" => "Solved By Team",
        "inspector_verification" => "Inspector Verification",

        "closed" => "Closed / Solved",
        "reopened" => "Reopened",
        "disputed" => "Disputed",
        "final_rejected" => "Final Rejected",

        "unknown" => "Unknown Status"
    ];

    $classes = [
        "submitted" => "status-submitted",
        "received" => "status-received",
        "pending_verification" => "status-pending-verification",

        "verified" => "status-verified-by-ward",
        "ward_verified" => "status-verified-by-ward",
        "verified_by_ward" => "status-verified-by-ward",

        "rejected_by_central" => "status-rejected-by-central",
        "rejected_by_ward" => "status-rejected-by-ward",
        "rejected" => "status-rejected-by-ward",

        "duplicate" => "status-duplicate",

        "team_assigned" => "status-team-assigned",
        "assigned_to_team" => "status-team-assigned",

        "in_progress" => "status-in-progress",
        "solved_by_team" => "status-solved-by-team",
        "inspector_verification" => "status-inspector-verification",

        "closed" => "status-closed",
        "reopened" => "status-reopened",
        "disputed" => "status-disputed",
        "final_rejected" => "status-final-rejected",

        "unknown" => "status-submitted"
    ];

    $filters = [
        "submitted" => "pending",
        "received" => "pending",
        "pending_verification" => "pending",

        "verified" => "verified",
        "ward_verified" => "verified",
        "verified_by_ward" => "verified",

        "team_assigned" => "assigned-team",
        "assigned_to_team" => "assigned-team",

        "in_progress" => "in-progress",
        "solved_by_team" => "solved-by-team",
        "inspector_verification" => "inspector-verification",
        "closed" => "closed-solved",

        "rejected_by_ward" => "rejected-duplicate",
        "rejected" => "rejected-duplicate",
        "duplicate" => "rejected-duplicate",

        "reopened" => "reopened-disputed",
        "disputed" => "reopened-disputed",
        "final_rejected" => "rejected-duplicate"
    ];

    return [
        'raw' => $status,
        'label' => $labels[$status] ?? ucwords(str_replace("_", " ", $status)),
        'class' => $classes[$status] ?? "status-submitted",
        'filter' => $filters[$status] ?? "all"
    ];
}

/*
|--------------------------------------------------------------------------
| Get current Ward Officer
|--------------------------------------------------------------------------
*/
$wardOfficer = null;

if ($currentUserId) {
    $wardOfficer = wc_fetchOne(
        $conn,
        "SELECT 
            wo.ward_officer_id,
            wo.user_id,
            wo.city_cor_id,
            wo.assigned_ward_id,
            wo.full_name,
            wo.user_mail,
            w.ward_id,
            w.ward_no,
            w.ward_name,
            cc.city_cor_name
        FROM ward_officers wo
        INNER JOIN wards w ON wo.assigned_ward_id = w.ward_id
        LEFT JOIN city_corporations cc ON wo.city_cor_id = cc.city_cor_id
        WHERE wo.user_id = ?
        LIMIT 1",
        "i",
        [$currentUserId]
    );
}

if (!$wardOfficer && !empty($currentUserMail)) {
    $wardOfficer = wc_fetchOne(
        $conn,
        "SELECT 
            wo.ward_officer_id,
            wo.user_id,
            wo.city_cor_id,
            wo.assigned_ward_id,
            wo.full_name,
            wo.user_mail,
            w.ward_id,
            w.ward_no,
            w.ward_name,
            cc.city_cor_name
        FROM ward_officers wo
        INNER JOIN wards w ON wo.assigned_ward_id = w.ward_id
        LEFT JOIN city_corporations cc ON wo.city_cor_id = cc.city_cor_id
        WHERE wo.user_mail = ?
        LIMIT 1",
        "s",
        [$currentUserMail]
    );
}

if (!$wardOfficer) {
    die("Your ward assignment could not be found. Please sign in again.");
}

$wardId = (int)$wardOfficer['assigned_ward_id'];
$cityCorId = (int)($wardOfficer['city_cor_id'] ?? 0);
$wardNo = $wardOfficer['ward_no'] ?? '';
$wardName = $wardOfficer['ward_name'] ?? '';
$cityCorporationName = $wardOfficer['city_cor_name'] ?? 'City Corporation';
$userName = $wardOfficer['full_name'] ?? ($_SESSION['user_name'] ?? 'Ward Officer');

$_SESSION['user_name'] = $userName;
$_SESSION['user_role_label'] = "Ward Operations";

/*
|--------------------------------------------------------------------------
| Fetch Ward Complaints
|--------------------------------------------------------------------------
| Main status display source: complaints.complaint_status
| Ward officer sees complaints from assigned ward and city corporation only.
|--------------------------------------------------------------------------
*/
$complaints = wc_fetchAll(
    $conn,
    "SELECT 
        c.complaint_id,
        c.complaint_code,
        c.problem_description,
        c.address_description,
        c.complaint_status,
        c.submitted_at,

        i.issue_name,
        i.priority,

        a.area_name,

        ca.assignment_id,
        ca.assignment_status,
        ca.assigned_at,

        mu.work_status,
        mu.started_at,
        mu.completed_at,
        mu.delayed_at
    FROM complaints c
    INNER JOIN locations l ON c.loc_id = l.loc_id
    LEFT JOIN areas a ON l.area_id = a.area_id
    LEFT JOIN issues i ON c.issue_id = i.issue_id
    LEFT JOIN complaint_assignments ca ON c.complaint_id = ca.complaint_id
    LEFT JOIN (
        SELECT mu1.*
        FROM maintenance_updates mu1
        INNER JOIN (
            SELECT assignment_id, MAX(update_id) AS latest_update_id
            FROM maintenance_updates
            WHERE assignment_id IS NOT NULL
            GROUP BY assignment_id
        ) latest_mu ON mu1.update_id = latest_mu.latest_update_id
    ) mu ON ca.assignment_id = mu.assignment_id
    WHERE l.ward_id = ?
    AND l.city_cor_id = ?
    AND c.complaint_status NOT IN ('submitted', 'received', 'rejected_by_central')
    ORDER BY c.submitted_at DESC, c.complaint_id DESC",
    "ii",
    [$wardId, $cityCorId]
);

/*
|--------------------------------------------------------------------------
| Fetch Complaint Media
|--------------------------------------------------------------------------
*/
$mediaByComplaint = [];

if (!empty($complaints)) {
    $complaintIds = array_map(function ($row) {
        return (int)$row['complaint_id'];
    }, $complaints);

    $complaintIds = array_values(array_unique(array_filter($complaintIds)));

    if (!empty($complaintIds)) {
        $mediaColumns = wc_tableColumns($conn, 'complaint_media');

        $mediaComplaintColumn = wc_firstExistingColumn($mediaColumns, ['complaint_id']);

        $mediaPathColumn = wc_firstExistingColumn($mediaColumns, [
            'file_path',
            'media_path',
            'media_url',
            'file_name',
            'media_file',
            'complaint_file',
            'path'
        ]);

        $mediaTypeColumn = wc_firstExistingColumn($mediaColumns, [
            'file_type',
            'media_type',
            'type',
            'mime_type'
        ]);

        if ($mediaComplaintColumn && $mediaPathColumn) {
            $idList = implode(',', $complaintIds);

            $typeSelect = $mediaTypeColumn
                ? "`$mediaTypeColumn` AS media_type"
                : "'' AS media_type";

            $mediaSql = "
                SELECT 
                    `$mediaComplaintColumn` AS complaint_id,
                    `$mediaPathColumn` AS media_path,
                    $typeSelect
                FROM complaint_media
                WHERE `$mediaComplaintColumn` IN ($idList)
                ORDER BY `$mediaComplaintColumn` ASC
            ";

            $mediaResult = mysqli_query($conn, $mediaSql);

            if ($mediaResult) {
                while ($mediaRow = mysqli_fetch_assoc($mediaResult)) {
                    $complaintId = (int)$mediaRow['complaint_id'];
                    $rawPath = $mediaRow['media_path'] ?? '';
                    $webPath = wc_mediaWebPath($rawPath);
                    $mediaType = wc_mediaType($webPath, $mediaRow['media_type'] ?? '');

                    if ($webPath !== '') {
                        $mediaByComplaint[$complaintId][] = [
                            'path' => $webPath,
                            'type' => $mediaType,
                            'name' => basename($webPath)
                        ];
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Counts
|--------------------------------------------------------------------------
*/
$totalComplaints = count($complaints);

$statusCounts = [
    'pending' => 0,
    'verified' => 0,
    'assigned-team' => 0,
    'in-progress' => 0,
    'solved-by-team' => 0,
    'inspector-verification' => 0,
    'closed-solved' => 0,
    'rejected-duplicate' => 0,
    'reopened-disputed' => 0
];

foreach ($complaints as $item) {
    $statusData = wc_statusData($item['complaint_status'] ?? '');

    if (isset($statusCounts[$statusData['filter']])) {
        $statusCounts[$statusData['filter']]++;
    }
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
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
    <link rel="stylesheet" href="../../css/ward/ward-complaints.css">
    <link rel="stylesheet" href="../../css/ward/wardTextFix.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="ward">

<?php include "../../includes/ward/sidebar.php"; ?>

<main class="ward-main">
    <?php include "../../includes/ward/topbar.php"; ?>

    <section class="ward-complaints-page">

        <div class="wc-page-header">
            <div>
                <h1>Ward Complaints</h1>
                <p>
                    View and manage all complaints for
                    <?= htmlspecialchars($cityCorporationName); ?>,
                    Ward <?= htmlspecialchars($wardNo); ?>
                    <?= !empty($wardName) ? " - " . htmlspecialchars($wardName) : ""; ?>
                </p>
            </div>
        </div>

        <div class="wc-search-card">
            <div class="wc-search-box">
                <i class="bi bi-search"></i>
                <input type="text"
                       id="wardComplaintSearch"
                       placeholder="Search by complaint ID, area, issue, status, or urgency">
            </div>

            <div class="wc-filter-dropdown">
                <button class="wc-filter-btn" type="button" id="wardFilterButton">
                    <i class="bi bi-funnel"></i>
                    <span id="urgencyFilterLabel">Filter</span>
                </button>

                <div class="wc-filter-menu" id="urgencyFilterMenu">
                    <button type="button" data-urgency="all">All Urgency</button>
                    <button type="button" data-urgency="high">High</button>
                    <button type="button" data-urgency="medium">Medium</button>
                    <button type="button" data-urgency="low">Low</button>
                </div>
            </div>
        </div>

        <div class="wc-tabs" id="wardComplaintTabs">
            <button class="wc-tab active" type="button" data-filter="all">
                All
                <span><?= $totalComplaints; ?></span>
            </button>

            <button class="wc-tab" type="button" data-filter="pending">
                Pending
                <span><?= $statusCounts['pending']; ?></span>
            </button>

            <button class="wc-tab" type="button" data-filter="verified">
                Verified
                <span><?= $statusCounts['verified']; ?></span>
            </button>

            <button class="wc-tab" type="button" data-filter="assigned-team">
                Assigned to Team
                <span><?= $statusCounts['assigned-team']; ?></span>
            </button>

            <button class="wc-tab" type="button" data-filter="in-progress">
                In Progress
                <span><?= $statusCounts['in-progress']; ?></span>
            </button>

            <button class="wc-tab" type="button" data-filter="solved-by-team">
                Solved by Team
                <span><?= $statusCounts['solved-by-team']; ?></span>
            </button>

            <button class="wc-tab" type="button" data-filter="inspector-verification">
                Inspector Verification
                <span><?= $statusCounts['inspector-verification']; ?></span>
            </button>

            <button class="wc-tab" type="button" data-filter="closed-solved">
                Closed / Solved
                <span><?= $statusCounts['closed-solved']; ?></span>
            </button>

            <button class="wc-tab" type="button" data-filter="rejected-duplicate">
                Rejected / Duplicate
                <span><?= $statusCounts['rejected-duplicate']; ?></span>
            </button>

            <button class="wc-tab" type="button" data-filter="reopened-disputed">
                Reopened / Disputed
                <span><?= $statusCounts['reopened-disputed']; ?></span>
            </button>
        </div>

        <div class="wc-table-card">
            <div class="wc-table-responsive">
                <table class="wc-table">
                    <thead>
                        <tr>
                            <th>Complaint ID</th>
                            <th>Issue Type</th>
                            <th>Area</th>
                            <th>Urgency</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody id="wardComplaintTableBody">
                        <?php if (!empty($complaints)): ?>
                            <?php foreach ($complaints as $complaint): ?>
                                <?php
                                $complaintId = (int)$complaint['complaint_id'];

                                $statusData = wc_statusData($complaint['complaint_status'] ?? '');

                                $issueName = $complaint['issue_name'] ?: 'Unknown Issue';
                                $areaName = $complaint['area_name'] ?: 'Area not specified';
                                $priority = $complaint['priority'] ?: 'Low';
                                $priorityClass = wc_priorityClass($priority);
                                $urgencyFilter = strtolower($priority);

                                $complaintMedia = $mediaByComplaint[$complaintId] ?? [];
                                $mediaJson = htmlspecialchars(
                                    json_encode($complaintMedia, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
                                    ENT_QUOTES,
                                    'UTF-8'
                                );

                                $searchText = strtolower(
                                    ($complaint['complaint_code'] ?? '') . ' ' .
                                    $issueName . ' ' .
                                    $areaName . ' ' .
                                    $priority . ' ' .
                                    $statusData['label'] . ' ' .
                                    $statusData['raw'] . ' ' .
                                    ($complaint['problem_description'] ?? '') . ' ' .
                                    ($complaint['address_description'] ?? '')
                                );

                                $discussionAllowedStatuses = [
                                    'rejected_by_ward',
                                    'duplicate'
                                ];

                                $showDiscussion = in_array($statusData['raw'], $discussionAllowedStatuses, true);
                                ?>

                                <tr class="wc-complaint-row"
                                    data-complaint-id="<?= $complaintId; ?>"
                                    data-complaint-code="<?= htmlspecialchars($complaint['complaint_code']); ?>"
                                    data-notification-target="<?= $complaintId; ?>"
                                    data-filter="<?= htmlspecialchars($statusData['filter']); ?>"
                                    data-urgency="<?= htmlspecialchars($urgencyFilter); ?>"
                                    data-search="<?= htmlspecialchars($searchText); ?>">

                                    <td>
                                        <span class="wc-complaint-code">
                                            <?= htmlspecialchars($complaint['complaint_code']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="wc-issue-name">
                                            <?= htmlspecialchars($issueName); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="wc-area-name">
                                            <?= htmlspecialchars($areaName); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="wc-priority-badge <?= htmlspecialchars($priorityClass); ?>">
                                            <?= htmlspecialchars($priority); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="wc-status-badge <?= htmlspecialchars($statusData['class']); ?>">
                                            <?= htmlspecialchars($statusData['label']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="wc-date">
                                            <?= htmlspecialchars(wc_formatDate($complaint['submitted_at'])); ?>
                                        </span>
                                    </td>

                                    <td class="wc-actions-cell">
                                        <button type="button"
                                                class="wc-view-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#complaintDetailsModal"
                                                data-issue="<?= htmlspecialchars($issueName); ?>"
                                                data-area="<?= htmlspecialchars($areaName); ?>"
                                                data-priority="<?= htmlspecialchars($priority); ?>"
                                                data-status="<?= htmlspecialchars($statusData['label']); ?>"
                                                data-date="<?= htmlspecialchars(wc_formatDate($complaint['submitted_at'])); ?>"
                                                data-address="<?= htmlspecialchars($complaint['address_description'] ?? 'Not provided'); ?>"
                                                data-description="<?= htmlspecialchars($complaint['problem_description'] ?? 'No description'); ?>"
                                                data-media="<?= $mediaJson; ?>">
                                            View Details
                                            <i class="bi bi-arrow-right"></i>
                                        </button>

                                        <?php if ($showDiscussion): ?>
                                            <a href="discussion.php?id=<?= $complaintId; ?>"
                                               class="wc-discussion-btn"
                                               title="Open Discussion">
                                                <i class="bi bi-chat-dots"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>

                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="wc-empty-state <?= !empty($complaints) ? 'd-none' : ''; ?>" id="wardComplaintEmptyState">
                <i class="bi bi-inbox"></i>
                <h3>No complaints found</h3>
                <p>No complaints match your current filter or search.</p>
            </div>
        </div>

    </section>
</main>

<div class="modal fade" id="complaintDetailsModal" tabindex="-1" aria-labelledby="complaintDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content wc-modal-content">
            <div class="modal-header wc-modal-header">
                <div>
                    <h5 class="modal-title" id="complaintDetailsModalLabel">Complaint Details</h5>
                    <p id="modalComplaintCode">Complaint ID</p>
                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body wc-modal-body">
                <div class="wc-detail-grid">
                    <div class="wc-detail-item">
                        <span>Issue Type</span>
                        <strong id="modalIssue">N/A</strong>
                    </div>

                    <div class="wc-detail-item">
                        <span>Area</span>
                        <strong id="modalArea">N/A</strong>
                    </div>

                    <div class="wc-detail-item">
                        <span>Urgency</span>
                        <strong id="modalPriority">N/A</strong>
                    </div>

                    <div class="wc-detail-item">
                        <span>Status</span>
                        <strong id="modalStatus">N/A</strong>
                    </div>

                    <div class="wc-detail-item">
                        <span>Date</span>
                        <strong id="modalDate">N/A</strong>
                    </div>

                    <div class="wc-detail-item">
                        <span>Address</span>
                        <strong id="modalAddress">N/A</strong>
                    </div>
                </div>

                <div class="wc-description-box">
                    <span>Problem Description</span>
                    <p id="modalDescription">N/A</p>
                </div>

                <div class="wc-media-box">
                    <div class="wc-media-title">
                        <span>Complaint Media</span>
                    </div>

                    <div class="wc-media-grid" id="modalMediaGrid"></div>

                    <div class="wc-media-empty d-none" id="modalMediaEmpty">
                        <i class="bi bi-image"></i>
                        <p>No media uploaded for this complaint.</p>
                    </div>
                </div>
            </div>

            <div class="modal-footer wc-modal-footer">
                <button type="button" class="btn wc-close-btn" data-bs-dismiss="modal">Close</button>
                <a href="verification-queue.php" class="btn wc-primary-btn">
                    Go to Verification Queue
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/ward-complaints.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>
