<?php
$activePage = "my-complaints";
$pageTitle = "My Complaints";
$pageParent = "Citizen";
$pageChild = "My Complaints";

require_once "../../config.php";
require_login(["citizen"]);

$userId = (int)($_SESSION["user_id"] ?? 0);
$complaints = [];

$sql = "
    SELECT
        c.complaint_id,
        c.complaint_code,
        c.issue_id,
        c.affected_area_id,
        c.problem_description,
        c.address_description,
        c.complaint_status,
        c.submitted_at,
        c.updated_at,
        c.closed_at,

        i.issue_name,
        aa.affected_area_name,

        cc.city_cor_name,
        t.thana_name,
        w.ward_no,
        w.ward_name,
        a.area_name

    FROM complaints c

    LEFT JOIN issues i
        ON c.issue_id = i.issue_id

    LEFT JOIN affected_areas aa
        ON c.affected_area_id = aa.affected_area_id

    LEFT JOIN locations l
        ON c.loc_id = l.loc_id

    LEFT JOIN city_corporations cc
        ON l.city_cor_id = cc.city_cor_id

    LEFT JOIN thanas t
        ON l.thana_id = t.thana_id

    LEFT JOIN wards w
        ON l.ward_id = w.ward_id

    LEFT JOIN areas a
        ON l.area_id = a.area_id

    WHERE c.user_id = ?

    ORDER BY c.submitted_at DESC
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("My complaints query failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($result)) {
    $complaints[] = $row;
}

mysqli_stmt_close($stmt);

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function formatStatus($status)
{
    $status = strtolower(trim((string)$status));

    $labels = [
        "submitted" => "Submitted",
        "received" => "Received",
        "pending_verification" => "Pending Verification",
        "verified_by_ward" => "Verified by Ward Officer",
        "rejected_by_central" => "Rejected by Central Officer",
        "rejected_by_ward" => "Rejected by Ward Officer",
        "duplicate" => "Duplicate",
        "team_assigned" => "Assigned to Team",
        "in_progress" => "In Progress",
        "solved_by_team" => "Solved by Team",
        "inspector_verification" => "Inspector Verification",
        "closed" => "Closed / Solved",
        "reopened" => "Reopened",
        "disputed" => "Disputed",
        "final_rejected" => "Final Rejected"
    ];

    return $labels[$status] ?? ucwords(str_replace("_", " ", $status));
}

function statusClass($status)
{
    $status = strtolower(trim((string)$status));

    $classes = [
        "submitted" => "status-submitted",
        "received" => "status-received",
        "pending_verification" => "status-pending-verification",
        "verified_by_ward" => "status-verified-by-ward",
        "rejected_by_central" => "status-rejected-by-central",
        "rejected_by_ward" => "status-rejected-by-ward",
        "duplicate" => "status-duplicate",
        "team_assigned" => "status-team-assigned",
        "in_progress" => "status-in-progress",
        "solved_by_team" => "status-solved-by-team",
        "inspector_verification" => "status-inspector-verification",
        "closed" => "status-closed",
        "reopened" => "status-reopened",
        "disputed" => "status-disputed",
        "final_rejected" => "status-final-rejected"
    ];

    return $classes[$status] ?? "status-submitted";
}

function shortText($text, $limit = 90)
{
    $text = trim((string)$text);

    if (function_exists("mb_strimwidth")) {
        return mb_strimwidth($text, 0, $limit, "...");
    }

    return strlen($text) > $limit ? substr($text, 0, $limit) . "..." : $text;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Complaints | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">
    <link rel="stylesheet" href="../../css/citizen/my-complaints.css">
    <link rel="stylesheet" href="../../css/citizen/citizenTextFix.css">
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
                    <input
                        type="text"
                        id="myComplaintSearch"
                        placeholder="Search by complaint ID, issue, affected area, ward..."
                    >
                </div>

                <select id="myStatusFilter">
                    <option value="all">All Status</option>
                    <option value="submitted">Submitted</option>
                    <option value="received">Received</option>
                    <option value="pending_verification">Pending Verification</option>
                    <option value="verified_by_ward">Verified by Ward Officer</option>
                    <option value="rejected_by_central">Rejected by Central Officer</option>
                    <option value="rejected_by_ward">Rejected by Ward Officer</option>
                    <option value="duplicate">Duplicate</option>
                    <option value="team_assigned">Assigned to Team</option>
                    <option value="in_progress">In Progress</option>
                    <option value="solved_by_team">Solved by Team</option>
                    <option value="inspector_verification">Inspector Verification</option>
                    <option value="closed">Closed / Solved</option>
                    <option value="reopened">Reopened</option>
                    <option value="disputed">Disputed</option>
                    <option value="final_rejected">Final Rejected</option>
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
                                    <th>Affected Area</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($complaints as $complaint): ?>
                                    <?php
                                        $complaintCode = safeText($complaint["complaint_code"]);

                                        $issueName = safeText($complaint["issue_name"] ?? "N/A");
                                        $affectedAreaName = safeText($complaint["affected_area_name"] ?? "N/A");

                                        $statusRaw = (string)($complaint["complaint_status"] ?? "submitted");
                                        $status = safeText($statusRaw);
                                        $statusText = safeText(formatStatus($statusRaw));

                                        $wardNo = safeText($complaint["ward_no"] ?? "N/A");
                                        $wardName = safeText($complaint["ward_name"] ?? "");
                                        $areaName = safeText($complaint["area_name"] ?? "N/A");
                                        $thanaName = safeText($complaint["thana_name"] ?? "N/A");

                                        $locationText = "Ward " . $wardNo . ", " . $areaName;
                                        if ($wardName !== "") {
                                            $locationText = $wardName . ", " . $areaName;
                                        }

                                        $locationSearchText = $locationText . " " . $thanaName;

                                        $submittedDate = !empty($complaint["submitted_at"])
                                            ? date("Y-m-d", strtotime($complaint["submitted_at"]))
                                            : "N/A";

                                        $problemShort = shortText($complaint["problem_description"] ?? "", 95);
                                    ?>

                                    <tr
                                        class="mc-row"
                                        data-code="<?php echo strtolower($complaintCode); ?>"
                                        data-issue="<?php echo strtolower($issueName); ?>"
                                        data-area="<?php echo strtolower(safeText($affectedAreaName . ' ' . $locationSearchText)); ?>"
                                        data-status="<?php echo $status; ?>"
                                    >
                                        <td>
                                            <span class="mc-code"><?php echo $complaintCode; ?></span>
                                        </td>

                                        <td>
                                            <div class="mc-issue">
                                                <strong><?php echo $issueName; ?></strong>
                                                <small><?php echo safeText($problemShort); ?></small>
                                            </div>
                                        </td>

                                        <td><?php echo $affectedAreaName; ?></td>

                                        <td>
                                            <?php echo safeText($locationText); ?>
                                            <br>
                                            <small><?php echo $thanaName; ?></small>
                                        </td>

                                        <td>
                                            <span class="mc-status <?php echo statusClass($statusRaw); ?>">
                                                <?php echo $statusText; ?>
                                            </span>
                                        </td>

                                        <td><?php echo safeText($submittedDate); ?></td>

                                        <td>
                                            <a
                                                class="mc-track-link"
                                                href="track-complaint.php?code=<?php echo urlencode($complaintCode); ?>"
                                            >
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