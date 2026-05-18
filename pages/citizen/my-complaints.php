<?php
$activePage = 'my-complaints';
$pageTitle = 'My Complaints';
$pageParent = 'Citizen';
$pageChild = 'My Complaints';

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'citizen') {
    header("Location: ../../index.php");
    exit();
}

$userId = (int) $_SESSION['user_id'];
$complaints = [];

$sql = "
    SELECT
        c.complaint_id,
        c.complaint_code,
        c.issue_type,
        c.problem_description,
        c.urgency_level,
        c.complaint_status,
        c.submitted_at,

        cc.city_cor_name,
        t.thana_name,
        w.ward_no,
        w.ward_name,
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

    ORDER BY c.submitted_at DESC
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
    return ucwords(str_replace('_', ' ', (string)$status));
}

function statusClass($status) {
    $status = strtolower((string)$status);

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Complaints | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <!-- Global CSS -->
    <link rel="stylesheet" href="../../css/global/global.css">

    <!-- Reusable Citizen Layout CSS -->
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">

    <!-- Page CSS -->
    <link rel="stylesheet" href="../../css/citizen/my-complaints.css">
</head>
<body class="citizen">

<div class="citizen-layout">

    <?php include "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main main-content">

        <?php include "../../includes/citizen/topbar.php"; ?>

        <section class="mc-page">

            <div class="mc-header">
                <div>
                    <h1>My Complaints</h1>
                    <p>View all your submitted complaints and their current status</p>
                </div>

                <div class="mc-count-card">
                    <span><?php echo count($complaints); ?></span>
                    <small>Total Submitted</small>
                </div>
            </div>

            <div class="mc-toolbar">
                <div class="mc-search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" id="myComplaintSearch" placeholder="Search by complaint ID, issue, area, ward...">
                </div>

                <select id="myStatusFilter">
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

            <div class="mc-table-card">

                <?php if (count($complaints) > 0): ?>

                    <div class="mc-table-wrap">
                        <table class="mc-table">
                            <thead>
                                <tr>
                                    <th>Complaint ID</th>
                                    <th>Issue</th>
                                    <th>Area</th>
                                    <th>Status</th>
                                    <th>Urgency</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($complaints as $complaint): ?>
                                    <?php
                                        $complaintCode = safeText($complaint['complaint_code']);
                                        $issueType = safeText($complaint['issue_type']);
                                        $status = safeText($complaint['complaint_status']);
                                        $statusText = safeText(formatStatus($complaint['complaint_status']));
                                        $urgency = safeText($complaint['urgency_level']);

                                        $area = safeText("Ward " . $complaint['ward_no'] . ", " . $complaint['area_name']);
                                        $submittedDate = date("Y-m-d", strtotime($complaint['submitted_at']));
                                    ?>

                                    <tr
                                        class="mc-row"
                                        data-code="<?php echo strtolower($complaintCode); ?>"
                                        data-issue="<?php echo strtolower($issueType); ?>"
                                        data-area="<?php echo strtolower($area); ?>"
                                        data-status="<?php echo $status; ?>"
                                    >
                                        <td>
                                            <span class="mc-code"><?php echo $complaintCode; ?></span>
                                        </td>

                                        <td>
                                            <div class="mc-issue">
                                                <strong><?php echo $issueType; ?></strong>
                                                <small><?php echo safeText($complaint['problem_description']); ?></small>
                                            </div>
                                        </td>

                                        <td><?php echo $area; ?></td>

                                        <td>
                                            <span class="mc-status <?php echo statusClass($status); ?>">
                                                <?php echo $statusText; ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="mc-urgency urgency-<?php echo strtolower($urgency); ?>">
                                                <?php echo $urgency; ?>
                                            </span>
                                        </td>

                                        <td><?php echo $submittedDate; ?></td>

                                        <td>
                                            <a class="mc-track-link" href="track-complaint.php?code=<?php echo urlencode($complaintCode); ?>">
                                                Track
                                                <i class="bi bi-arrow-right"></i>
                                            </a>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php else: ?>

                    <div class="mc-empty">
                        <i class="bi bi-inbox"></i>
                        <h3>No complaints submitted yet</h3>
                        <p>Submit your first drainage complaint to see it here.</p>
                        <a href="submit-complaint.php">Submit Complaint</a>
                    </div>

                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/citizen/my-complaints.js"></script>

</body>
</html>