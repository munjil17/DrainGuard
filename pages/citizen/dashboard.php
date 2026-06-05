<?php
// C:\xampp\htdocs\DrainGuard\pages\citizen\dashboard.php

require_once "../../config.php";
require_login(["citizen"]);

$activePage = "dashboard";
$pageTitle = "Citizen Dashboard";
$pageParent = "Citizen";
$pageChild = "Dashboard";

$userId = isset($_SESSION["user_id"]) ? (int)$_SESSION["user_id"] : 0;
$userName = $_SESSION["user_name"] ?? "Citizen User";
$_SESSION["user_role_label"] = $_SESSION["user_role_label"] ?? "Public Portal";

function citizen_dash_safe($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function citizen_dash_status_class($status)
{
    $status = strtolower(trim((string)$status));

    if (str_contains($status, "solve") || str_contains($status, "close") || str_contains($status, "complete")) {
        return "solved";
    }

    if (str_contains($status, "verify") || str_contains($status, "accept") || str_contains($status, "assign")) {
        return "verified";
    }
    
    if (str_contains($status, "reject") || str_contains($status, "duplicate")) {
        return "rejected"; // assuming there's a rejected style, else it might default to neutral
    }

    return "progress";
}

// 1. Fetch Recent Complaints
$recentComplaints = [];
$recentComplaintsSql = "
    SELECT 
        c.complaint_code,
        i.issue_name AS title,
        a.area_name AS area,
        c.complaint_status AS status_text,
        c.submitted_at AS created_date
    FROM complaints c
    LEFT JOIN issues i ON c.issue_id = i.issue_id
    LEFT JOIN locations l ON c.loc_id = l.loc_id
    LEFT JOIN areas a ON l.area_id = a.area_id
    WHERE c.user_id = ?
    ORDER BY c.submitted_at DESC
    LIMIT 5
";
$stmt = mysqli_prepare($conn, $recentComplaintsSql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $recentComplaints[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// 2. Check Pending Feedback
$pendingFeedbackCount = 0;
$feedbackSql = "
    SELECT COUNT(*) AS pending
    FROM complaints c
    LEFT JOIN feedbacks f ON c.complaint_id = f.complaint_id AND f.feedback_type = 'feedback'
    WHERE c.user_id = ? 
      AND c.complaint_status IN ('solved_by_team', 'closed')
      AND f.feedback_id IS NULL
";
$stmt = mysqli_prepare($conn, $feedbackSql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $pendingFeedbackCount = (int)$row['pending'];
    }
    mysqli_stmt_close($stmt);
}

// 3. Fetch High Risk Areas
$riskAreas = [];
$riskAreasSql = "
    SELECT 
        r.urgency_level,
        a.area_name,
        r.complaint_count_30_days,
        r.risk_status
    FROM risk r
    LEFT JOIN areas a ON r.area_id = a.area_id
    WHERE r.risk_status = 'Active'
    ORDER BY 
        CASE r.urgency_level 
            WHEN 'High' THEN 1 
            WHEN 'Medium' THEN 2 
            WHEN 'Low' THEN 3 
            ELSE 4 
        END ASC,
        r.complaint_count_30_days DESC
    LIMIT 3
";
$stmt = mysqli_prepare($conn, $riskAreasSql);
if ($stmt) {
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $class = "stable";
            if ($row['urgency_level'] === 'High') $class = "critical";
            elseif ($row['urgency_level'] === 'Medium') $class = "warning";
            
            $riskAreas[] = [
                "class" => $class,
                "title" => $row['area_name'] ?: "Unknown Area",
                "text" => "{$row['complaint_count_30_days']} recent complaints reported here."
            ];
        }
    }
    mysqli_stmt_close($stmt);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo citizen_dash_safe($pageTitle); ?> | DrainGuard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">
    <link rel="stylesheet" href="../../css/citizen/dashboard.css">
    <link rel="stylesheet" href="../../css/citizen/citizenTextFix.css">
    
    <style>
        /* Adjust layout to be clean without KPI cards */
        .dashboard-content {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .dashboard-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            align-items: stretch;
        }
    </style>
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="citizen">

<div class="citizen-layout">

    <?php require_once "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main">

        <?php require_once "../../includes/citizen/topbar.php"; ?>

        <section class="dashboard-content">

            <div class="welcome-card">
                <h1>Welcome back, <?php echo citizen_dash_safe($userName); ?></h1>
                <p>Track and manage your drainage complaints easily.</p>
            </div>

            <!-- Removed KPI Grid -->

            <div class="panel complaints-panel">
                <div class="panel-header">
                    <h2>My Complaints</h2>
                    <a href="my-complaints.php">View All <i class="bi bi-chevron-right"></i></a>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Issue</th>
                                <th>Area</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (!empty($recentComplaints)): ?>
                                <?php foreach ($recentComplaints as $complaint): ?>
                                    <?php
                                    $statusText = ucwords(str_replace('_', ' ', $complaint["status_text"] ?? "Pending"));
                                    $statusClass = citizen_dash_status_class($statusText);

                                    $dateText = "N/A";
                                    if (!empty($complaint["created_date"])) {
                                        $timestamp = strtotime($complaint["created_date"]);
                                        $dateText = $timestamp ? date("M d, Y", $timestamp) : $complaint["created_date"];
                                    }
                                    ?>

                                    <tr>
                                        <td><?php echo citizen_dash_safe($complaint["title"] ?: "Drainage Complaint"); ?></td>
                                        <td><?php echo citizen_dash_safe($complaint["area"] ?: "N/A"); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo citizen_dash_safe($statusClass); ?>">
                                                <?php echo citizen_dash_safe($statusText); ?>
                                            </span>
                                        </td>
                                        <td><?php echo citizen_dash_safe($dateText); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="empty-table-cell" style="text-align: center; padding: 30px; color: #64748b;">
                                        <i class="bi bi-inbox" style="font-size: 2rem; display: block; margin-bottom: 10px; color: #cbd5e1;"></i>
                                        No complaints found yet. Submit your first complaint to start tracking.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="dashboard-row">

                <div class="panel track-panel" style="flex: 1;">
                    <div class="mini-heading">
                        <div class="mini-icon">
                            <i class="bi bi-search"></i>
                        </div>

                        <h2>Track Complaint</h2>
                    </div>

                    <form class="track-form" id="trackComplaintForm" action="track-complaint.php" method="GET">
                        <input 
                            type="text" 
                            name="code" 
                            id="trackComplaintInput"
                            placeholder="Enter Complaint ID"
                            autocomplete="off"
                        >

                        <button type="submit">Track</button>
                    </form>

                    <small class="track-error" id="trackComplaintError"></small>
                </div>

                <?php if ($pendingFeedbackCount > 0): ?>
                <div class="feedback-card" style="flex: 1;">
                    <div class="feedback-icon">
                        <i class="bi bi-chat-left"></i>
                    </div>

                    <div>
                        <h2>Feedback Reminder</h2>
                        <p>You have <?php echo $pendingFeedbackCount; ?> solved complaint(s) waiting for your feedback.</p>
                        <a href="feedback-reopen.php">Give Feedback <i class="bi bi-chevron-right"></i></a>
                    </div>
                </div>
                <?php else: ?>
                <div class="feedback-card" style="flex: 1; opacity: 0.8; background: #f8fafc; border: 1px dashed #cbd5e1;">
                    <div class="feedback-icon" style="background: #e2e8f0; color: #64748b;">
                        <i class="bi bi-check2-circle"></i>
                    </div>

                    <div>
                        <h2 style="color: #475569;">All Caught Up!</h2>
                        <p style="color: #64748b;">No pending feedback at the moment.</p>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <div class="panel risk-panel">
                <div class="panel-header">
                    <h2>High Risk Areas</h2>
                    <a href="high-risk-areas.php">View All <i class="bi bi-chevron-right"></i></a>
                </div>

                <div class="risk-grid">
                    <?php if (!empty($riskAreas)): ?>
                        <?php foreach ($riskAreas as $risk): ?>
                            <div class="risk-item <?php echo citizen_dash_safe($risk["class"]); ?>">
                                <h3><?php echo citizen_dash_safe($risk["title"]); ?></h3>
                                <p><?php echo citizen_dash_safe($risk["text"]); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #64748b;">
                            No high risk areas identified currently.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    </main>

</div>

<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/citizen/dashboard.js"></script>
<script>
    document.getElementById('trackComplaintForm').addEventListener('submit', function(e) {
        var input = document.getElementById('trackComplaintInput').value.trim();
        if(!input) {
            e.preventDefault();
            window.location.href = 'track-complaint.php';
        }
    });
</script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>