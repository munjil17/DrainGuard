<?php
$activePage = 'public-board';
$pageTitle = 'Public Complaint Board';
$pageParent = 'Citizen';
$pageChild = 'Public Complaint Board';

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'citizen') {
    header("Location: ../../index.php");
    exit();
}

$userName = $_SESSION['user_name'] ?? 'Citizen User';

$complaints = [];

$sql = "
    SELECT
        c.complaint_id,
        c.complaint_code,
        c.issue_type,
        c.address_description,
        c.problem_description,
        c.complaint_image,
        c.urgency_level,
        c.complaint_status,
        c.submitted_at,

        u.user_name,

        city.city_name,
        cc.city_cor_name,
        t.thana_name,
        w.ward_no,
        w.ward_name,
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
        $complaints[] = $row;
    }
}

function statusClass($status) {
    $status = strtolower($status);

    if ($status === 'submitted') return 'status-submitted';
    if ($status === 'pending_verification') return 'status-pending';
    if ($status === 'verified') return 'status-verified';
    if ($status === 'assigned') return 'status-assigned';
    if ($status === 'in_progress') return 'status-progress';
    if ($status === 'completed') return 'status-completed';
    if ($status === 'under_inspection') return 'status-inspection';
    if ($status === 'solved') return 'status-solved';
    if ($status === 'reopened') return 'status-reopened';
    if ($status === 'rejected') return 'status-rejected';

    return 'status-submitted';
}

function urgencyClass($urgency) {
    $urgency = strtolower($urgency);

    if ($urgency === 'low') return 'urgency-low';
    if ($urgency === 'medium') return 'urgency-medium';
    if ($urgency === 'high') return 'urgency-high';
    if ($urgency === 'critical') return 'urgency-critical';

    return 'urgency-low';
}

function formatStatus($status) {
    return ucwords(str_replace('_', ' ', $status));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Public Complaint Board | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <!-- Global CSS -->
    <link rel="stylesheet" href="../../css/global/global.css">

    <!-- Existing Citizen Layout CSS -->
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">

    <!-- Page CSS -->
    <link rel="stylesheet" href="../../css/citizen/public-board.css">
</head>
<body class="citizen">

<div class="citizen-layout">

    <?php include "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main main-content">

        <?php include "../../includes/citizen/topbar.php"; ?>

        <section class="pb-page">

            <div class="pb-header">
                <div>
                    <h1>Public Complaint Board</h1>
                    <p>View all public drainage complaints and their status</p>
                </div>

                <div class="pb-count-card">
                    <span><?php echo count($complaints); ?></span>
                    <small>Total Complaints</small>
                </div>
            </div>

            <div class="pb-toolbar">
                <div class="pb-search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" id="complaintSearch" placeholder="Search by complaint ID, issue, area, thana, ward...">
                </div>

                <button type="button" class="pb-filter-btn" id="filterToggleBtn">
                    <i class="bi bi-funnel"></i>
                    <span>Filter</span>
                </button>
            </div>

            <div class="pb-filter-panel" id="filterPanel">
                <div class="pb-filter-group">
                    <label>Status</label>
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
                    <label>Urgency</label>
                    <select id="urgencyFilter">
                        <option value="all">All Urgency</option>
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High">High</option>
                        <option value="Critical">Critical</option>
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
                            $complaintCode = htmlspecialchars($complaint['complaint_code']);
                            $issueType = htmlspecialchars($complaint['issue_type']);
                            $status = htmlspecialchars($complaint['complaint_status']);
                            $urgency = htmlspecialchars($complaint['urgency_level']);

                            $cityName = htmlspecialchars($complaint['city_name']);
                            $cityCorName = htmlspecialchars($complaint['city_cor_name']);
                            $thanaName = htmlspecialchars($complaint['thana_name']);
                            $wardNo = htmlspecialchars($complaint['ward_no']);
                            $wardName = htmlspecialchars($complaint['ward_name']);
                            $areaName = htmlspecialchars($complaint['area_name']);

                            $addressDescription = htmlspecialchars($complaint['address_description']);
                            $problemDescription = htmlspecialchars($complaint['problem_description']);
                            $submittedAt = date("M d, Y h:i A", strtotime($complaint['submitted_at']));

                            $imagePath = $complaint['complaint_image'] ? "../../" . $complaint['complaint_image'] : "";
                        ?>

                        <article
                            class="pb-card"
                            data-code="<?php echo strtolower($complaintCode); ?>"
                            data-issue="<?php echo strtolower($issueType); ?>"
                            data-city="<?php echo strtolower($cityName); ?>"
                            data-corporation="<?php echo strtolower($cityCorName); ?>"
                            data-thana="<?php echo strtolower($thanaName); ?>"
                            data-ward="<?php echo strtolower('Ward ' . $wardNo . ' ' . $wardName); ?>"
                            data-area="<?php echo strtolower($areaName); ?>"
                            data-status="<?php echo $status; ?>"
                            data-urgency="<?php echo $urgency; ?>"
                        >

                            <div class="pb-card-main">
                                <div class="pb-card-icon">
                                    <i class="bi bi-droplet-half"></i>
                                </div>

                                <div class="pb-card-content">
                                    <div class="pb-card-top">
                                        <h3><?php echo $issueType; ?></h3>

                                        <div class="pb-badge-group">
                                            <span class="pb-urgency <?php echo urgencyClass($urgency); ?>">
                                                <?php echo $urgency; ?>
                                            </span>

                                            <span class="pb-status <?php echo statusClass($status); ?>">
                                                <?php echo formatStatus($status); ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="pb-meta">
                                        <span>
                                            <i class="bi bi-hash"></i>
                                            <?php echo $complaintCode; ?>
                                        </span>

                                        <span>
                                            <i class="bi bi-geo-alt"></i>
                                            <?php echo "Ward " . $wardNo . ", " . $areaName; ?>
                                        </span>

                                        <span>
                                            <i class="bi bi-clock"></i>
                                            <?php echo $submittedAt; ?>
                                        </span>
                                    </div>

                                    <p class="pb-description">
                                        <?php echo mb_strimwidth($problemDescription, 0, 150, "..."); ?>
                                    </p>

                                    <div class="pb-footer">
                                        <div class="pb-support">
                                            <span>
                                                <i class="bi bi-building"></i>
                                                <?php echo $cityCorName; ?>
                                            </span>

                                            <span>
                                                <i class="bi bi-map"></i>
                                                <?php echo $thanaName; ?>
                                            </span>
                                        </div>

                                        <button
                                            type="button"
                                            class="pb-details-btn"
                                            data-code="<?php echo $complaintCode; ?>"
                                            data-issue="<?php echo $issueType; ?>"
                                            data-status="<?php echo formatStatus($status); ?>"
                                            data-urgency="<?php echo $urgency; ?>"
                                            data-city="<?php echo $cityName; ?>"
                                            data-corporation="<?php echo $cityCorName; ?>"
                                            data-thana="<?php echo $thanaName; ?>"
                                            data-ward="<?php echo 'Ward ' . $wardNo . ' - ' . $wardName; ?>"
                                            data-area="<?php echo $areaName; ?>"
                                            data-address="<?php echo $addressDescription; ?>"
                                            data-problem="<?php echo $problemDescription; ?>"
                                            data-date="<?php echo $submittedAt; ?>"
                                            data-image="<?php echo $imagePath; ?>"
                                        >
                                            View Details
                                            <i class="bi bi-arrow-right"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                        </article>

                    <?php endforeach; ?>

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

<!-- Details Modal -->
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

            <div class="pb-modal-section" id="modalImageWrapper">
                <h4>Uploaded Photo</h4>
                <img id="modalImage" src="" alt="Complaint image">
            </div>

        </div>
    </div>
</div>

<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/citizen/public-board.js"></script>
</body>
</html>