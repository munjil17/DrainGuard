<?php
// C:\xampp\htdocs\DrainGuard\pages\citizen\public-board.php

require_once "../../config.php";
require_login(["citizen"]);

$activePage = "public-board";
$pageTitle = "Public Complaint Board";
$pageParent = "Citizen";
$pageChild = "Public Complaint Board";

function pb_safe($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function pb_status_class($status)
{
    $status = strtolower(trim((string)$status));

    if ($status === "submitted") return "status-submitted";
    if ($status === "pending_verification") return "status-pending";
    if ($status === "verified") return "status-verified";
    if ($status === "assigned") return "status-assigned";
    if ($status === "in_progress") return "status-progress";
    if ($status === "completed") return "status-completed";
    if ($status === "under_inspection") return "status-inspection";
    if ($status === "solved") return "status-solved";
    if ($status === "reopened") return "status-reopened";
    if ($status === "rejected") return "status-rejected";

    return "status-submitted";
}

function pb_urgency_class($urgency)
{
    $urgency = strtolower(trim((string)$urgency));

    if ($urgency === "low") return "urgency-low";
    if ($urgency === "medium") return "urgency-medium";
    if ($urgency === "high") return "urgency-high";
    if ($urgency === "critical") return "urgency-critical";

    return "urgency-low";
}

function pb_format_status($status)
{
    return ucwords(str_replace("_", " ", (string)$status));
}

function pb_short_text($text, $limit = 150)
{
    $text = trim((string)$text);

    if (function_exists("mb_strimwidth")) {
        return mb_strimwidth($text, 0, $limit, "...");
    }

    return strlen($text) > $limit ? substr($text, 0, $limit) . "..." : $text;
}

$complaints = [];

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

        u.user_name,

        city.city_name,
        cc.city_cor_id,
        cc.city_cor_name,

        t.thana_id,
        t.thana_name,

        w.ward_id,
        w.ward_no,
        w.ward_name,

        a.area_id,
        a.area_name

    FROM complaints c

    INNER JOIN users u
        ON c.user_id = u.user_id

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

    ORDER BY c.submitted_at DESC
";

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $row["media"] = [];
        $complaints[(int)$row["complaint_id"]] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Load complaint media
|--------------------------------------------------------------------------
*/

if (!empty($complaints)) {
    $complaintIds = array_keys($complaints);
    $safeIds = array_map("intval", $complaintIds);
    $idList = implode(",", $safeIds);

    $mediaSql = "
        SELECT
            media_id,
            complaint_id,
            media_type,
            media_path,
            original_name,
            file_size,
            mime_type
        FROM complaint_media
        WHERE complaint_id IN ($idList)
        ORDER BY media_id ASC
    ";

    $mediaResult = mysqli_query($conn, $mediaSql);

    if ($mediaResult) {
        while ($media = mysqli_fetch_assoc($mediaResult)) {
            $complaintId = (int)$media["complaint_id"];

            if (isset($complaints[$complaintId])) {
                $complaints[$complaintId]["media"][] = [
                    "media_id" => (int)$media["media_id"],
                    "media_type" => $media["media_type"],
                    "media_path" => "../../" . ltrim($media["media_path"], "/"),
                    "original_name" => $media["original_name"] ?: "Evidence file",
                    "file_size" => (int)($media["file_size"] ?? 0),
                    "mime_type" => $media["mime_type"] ?? ""
                ];
            }
        }
    }
}

$complaints = array_values($complaints);

/*
|--------------------------------------------------------------------------
| Ward filter options
| Only wards where complaints exist.
|--------------------------------------------------------------------------
*/

$wardOptions = [];

foreach ($complaints as $complaint) {
    $wardId = (int)$complaint["ward_id"];

    if (!isset($wardOptions[$wardId])) {
        $wardOptions[$wardId] = [
            "ward_id" => $wardId,
            "label" => $complaint["city_cor_name"] . " - " . $complaint["ward_name"]
        ];
    }
}

uasort($wardOptions, function ($a, $b) {
    return strcasecmp($a["label"], $b["label"]);
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title><?php echo pb_safe($pageTitle); ?> | DrainGuard</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">
    <link rel="stylesheet" href="../../css/citizen/public-board.css">
    <link rel="stylesheet" href="../../css/citizen/citizenTextFix.css">
</head>

<body class="citizen">

<div class="citizen-layout">

    <?php require_once "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main main-content">

        <?php require_once "../../includes/citizen/topbar.php"; ?>

        <section class="pb-page">

            <div class="pb-header">
                <div>
                    <h1>Public Complaint Board</h1>
                    <p>View all public drainage complaints and their status</p>
                </div>

                <div class="pb-count-card">
                    <span id="visibleComplaintCount"><?php echo count($complaints); ?></span>
                    <small>Total Complaints</small>
                </div>
            </div>

            <div class="pb-toolbar">
                <div class="pb-search-box">
                    <i class="bi bi-search"></i>

                    <input
                        type="text"
                        id="complaintSearch"
                        placeholder="Search by complaint ID, issue, area, thana, ward..."
                    >
                </div>

                <button type="button" class="pb-filter-btn" id="filterToggleBtn">
                    <i class="bi bi-funnel"></i>
                    <span>Filter</span>
                </button>
            </div>

            <div class="pb-filter-panel" id="filterPanel">
                <div class="pb-filter-group">
                    <label for="statusFilter">Status</label>

                    <select id="statusFilter">
                        <option value="all">All Status</option>
                        <option value="submitted">Submitted</option>
                        <option value="pending_verification">Pending Verification</option>
                        <option value="verified">Verified</option>
                        <option value="assigned">Assigned</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="under_inspection">Under Inspection</option>
                        <option value="solved">Solved</option>
                        <option value="reopened">Reopened</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>

                <div class="pb-filter-group">
                    <label for="urgencyFilter">Urgency</label>

                    <select id="urgencyFilter">
                        <option value="all">All Urgency</option>
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High">High</option>
                        <option value="Critical">Critical</option>
                    </select>
                </div>

                <div class="pb-filter-group">
                    <label for="wardFilter">Ward</label>

                    <select id="wardFilter">
                        <option value="all">All Wards</option>

                        <?php foreach ($wardOptions as $ward): ?>
                            <option value="<?php echo (int)$ward["ward_id"]; ?>">
                                <?php echo pb_safe($ward["label"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="button" class="pb-clear-btn" id="clearFilterBtn">
                    Clear Filter
                </button>
            </div>

            <div class="pb-list" id="complaintList">

                <?php if (count($complaints) > 0): ?>

                    <?php foreach ($complaints as $complaint): ?>

                        <?php
                        $complaintCode = $complaint["complaint_code"];
                        $issueType = $complaint["issue_type"];
                        $status = $complaint["complaint_status"];
                        $urgency = $complaint["urgency_level"];

                        $cityName = $complaint["city_name"];
                        $cityCorName = $complaint["city_cor_name"];
                        $thanaName = $complaint["thana_name"];
                        $wardId = (int)$complaint["ward_id"];
                        $wardName = $complaint["ward_name"];
                        $areaName = $complaint["area_name"];

                        $addressDescription = $complaint["address_description"];
                        $problemDescription = $complaint["problem_description"];

                        $submittedAt = "N/A";

                        if (!empty($complaint["submitted_at"])) {
                            $timestamp = strtotime($complaint["submitted_at"]);
                            $submittedAt = $timestamp ? date("M d, Y h:i A", $timestamp) : $complaint["submitted_at"];
                        }

                        $mediaJson = json_encode($complaint["media"], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                        $mediaCount = count($complaint["media"]);
                        ?>

                        <article
                            class="pb-card"
                            data-code="<?php echo pb_safe(strtolower($complaintCode)); ?>"
                            data-issue="<?php echo pb_safe(strtolower($issueType)); ?>"
                            data-city="<?php echo pb_safe(strtolower($cityName)); ?>"
                            data-corporation="<?php echo pb_safe(strtolower($cityCorName)); ?>"
                            data-thana="<?php echo pb_safe(strtolower($thanaName)); ?>"
                            data-ward-id="<?php echo $wardId; ?>"
                            data-ward="<?php echo pb_safe(strtolower($cityCorName . ' ' . $wardName)); ?>"
                            data-area="<?php echo pb_safe(strtolower($areaName)); ?>"
                            data-status="<?php echo pb_safe($status); ?>"
                            data-urgency="<?php echo pb_safe($urgency); ?>"
                        >

                            <div class="pb-card-main">
                                <div class="pb-card-icon">
                                    <i class="bi bi-droplet-half"></i>
                                </div>

                                <div class="pb-card-content">
                                    <div class="pb-card-top">
                                        <h3><?php echo pb_safe($issueType); ?></h3>

                                        <div class="pb-badge-group">
                                            <span class="pb-urgency <?php echo pb_safe(pb_urgency_class($urgency)); ?>">
                                                <?php echo pb_safe($urgency); ?>
                                            </span>

                                            <span class="pb-status <?php echo pb_safe(pb_status_class($status)); ?>">
                                                <?php echo pb_safe(pb_format_status($status)); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="pb-meta">
                                        <span>
                                            <i class="bi bi-hash"></i>
                                            <?php echo pb_safe($complaintCode); ?>
                                        </span>

                                        <span>
                                            <i class="bi bi-geo-alt"></i>
                                            <?php echo pb_safe($cityCorName . " - " . $wardName . ", " . $areaName); ?>
                                        </span>

                                        <span>
                                            <i class="bi bi-clock"></i>
                                            <?php echo pb_safe($submittedAt); ?>
                                        </span>
                                    </div>

                                    <p class="pb-description">
                                        <?php echo pb_safe(pb_short_text($problemDescription, 150)); ?>
                                    </p>

                                    <div class="pb-footer">
                                        <div class="pb-support">
                                            <span>
                                                <i class="bi bi-building"></i>
                                                <?php echo pb_safe($cityCorName); ?>
                                            </span>

                                            <span>
                                                <i class="bi bi-map"></i>
                                                <?php echo pb_safe($thanaName); ?>
                                            </span>

                                            <?php if ($mediaCount > 0): ?>
                                                <span>
                                                    <i class="bi bi-paperclip"></i>
                                                    <?php echo $mediaCount; ?> evidence file(s)
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="pb-action-group">
                                            <a
                                                href="track-complaint.php?code=<?php echo urlencode($complaintCode); ?>"
                                                class="pb-track-btn"
                                            >
                                                <i class="bi bi-geo-alt"></i>
                                                Track
                                            </a>

                                            <button
                                                type="button"
                                                class="pb-details-btn"
                                                data-code="<?php echo pb_safe($complaintCode); ?>"
                                                data-issue="<?php echo pb_safe($issueType); ?>"
                                                data-status="<?php echo pb_safe(pb_format_status($status)); ?>"
                                                data-urgency="<?php echo pb_safe($urgency); ?>"
                                                data-city="<?php echo pb_safe($cityName); ?>"
                                                data-corporation="<?php echo pb_safe($cityCorName); ?>"
                                                data-thana="<?php echo pb_safe($thanaName); ?>"
                                                data-ward="<?php echo pb_safe($cityCorName . ' - ' . $wardName); ?>"
                                                data-area="<?php echo pb_safe($areaName); ?>"
                                                data-address="<?php echo pb_safe($addressDescription); ?>"
                                                data-problem="<?php echo pb_safe($problemDescription); ?>"
                                                data-date="<?php echo pb_safe($submittedAt); ?>"
                                                data-media="<?php echo pb_safe($mediaJson); ?>"
                                            >
                                                View Details
                                                <i class="bi bi-arrow-right"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </article>

                    <?php endforeach; ?>

                    <div class="pb-empty pb-filter-empty" id="filterEmptyState">
                        <i class="bi bi-inbox"></i>
                        <h3>No matching complaints found</h3>
                        <p>Try changing your search keyword or filter selection.</p>
                    </div>

                <?php else: ?>

                    <div class="pb-empty">
                        <i class="bi bi-inbox"></i>
                        <h3>No complaints found</h3>
                        <p>No public drainage complaints have been submitted yet.</p>
                    </div>

                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

<div class="pb-modal-overlay" id="detailsModal">
    <div class="pb-modal">
        <div class="pb-modal-header">
            <div>
                <h2 id="modalIssue">Complaint Details</h2>
                <p id="modalCode">Complaint ID</p>
            </div>

            <button type="button" class="pb-modal-close" id="modalCloseBtn">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="pb-modal-body">

            <div class="pb-detail-grid">
                <div class="pb-detail-item">
                    <span>Status</span>
                    <strong id="modalStatus"></strong>
                </div>

                <div class="pb-detail-item">
                    <span>Urgency</span>
                    <strong id="modalUrgency"></strong>
                </div>

                <div class="pb-detail-item">
                    <span>Submitted At</span>
                    <strong id="modalDate"></strong>
                </div>

                <div class="pb-detail-item">
                    <span>City Corporation</span>
                    <strong id="modalCorporation"></strong>
                </div>

                <div class="pb-detail-item">
                    <span>Thana</span>
                    <strong id="modalThana"></strong>
                </div>

                <div class="pb-detail-item">
                    <span>Ward</span>
                    <strong id="modalWard"></strong>
                </div>

                <div class="pb-detail-item">
                    <span>Area</span>
                    <strong id="modalArea"></strong>
                </div>

                <div class="pb-detail-item">
                    <span>City</span>
                    <strong id="modalCity"></strong>
                </div>
            </div>

            <div class="pb-modal-section">
                <h4>Address Description</h4>
                <p id="modalAddress"></p>
            </div>

            <div class="pb-modal-section">
                <h4>Problem Description</h4>
                <p id="modalProblem"></p>
            </div>

            <div class="pb-modal-section" id="modalMediaWrapper">
                <h4>Uploaded Evidence</h4>
                <div class="pb-media-grid" id="modalMediaGrid"></div>
            </div>

        </div>
    </div>
</div>

<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/citizen/public-board.js"></script>

</body>
</html>