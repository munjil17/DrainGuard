<?php
// pages/central/dashboard.php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "dashboard";
$pageTitle = "Central Command Dashboard";

function cd_safe($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function cd_count_query($conn, $sql)
{
    $result = mysqli_query($conn, $sql);
    if (!$result) return 0;
    $row = mysqli_fetch_assoc($result);
    return (int)($row["total"] ?? 0);
}

function cd_fetch_all($conn, $sql)
{
    $rows = [];
    $result = mysqli_query($conn, $sql);
    if (!$result) return $rows;
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    return $rows;
}

// KPI 1: Total Complaints
$totalComplaints = cd_count_query($conn, "SELECT COUNT(DISTINCT complaint_id) AS total FROM complaints");

// KPI 2: Pending Verification
$pendingVerification = cd_count_query($conn, "SELECT COUNT(DISTINCT complaint_id) AS total FROM complaints WHERE complaint_status = 'pending_verification'");

// KPI 3: In Progress
$inProgressCases = cd_count_query($conn, "SELECT COUNT(DISTINCT complaint_id) AS total FROM complaints WHERE complaint_status = 'in_progress'");

// KPI 4: Solved Cases
$solvedCases = cd_count_query($conn, "SELECT COUNT(DISTINCT complaint_id) AS total FROM complaints WHERE complaint_status = 'closed'");

// KPI 5: Reopen / Disputed
$reopenDisputedCases = cd_count_query($conn, "SELECT COUNT(DISTINCT complaint_id) AS total FROM complaints WHERE complaint_status IN ('reopened', 'disputed')");

// KPI 6: High Risk Zone
$highRiskZones = cd_count_query($conn, "SELECT COUNT(DISTINCT risk_id) AS total FROM risk WHERE risk_status = 'Active' AND urgency_level IN ('High', 'Critical')");

$kpiCards = [
    [
        "title" => "Total Complaints",
        "value" => $totalComplaints,
        "icon" => "bi-file-earmark-text",
        "color" => "cyan"
    ],
    [
        "title" => "Pending Verification",
        "value" => $pendingVerification,
        "icon" => "bi-clock",
        "color" => "orange"
    ],
    [
        "title" => "In Progress",
        "value" => $inProgressCases,
        "icon" => "bi-graph-up-arrow",
        "color" => "blue"
    ],
    [
        "title" => "Solved Cases",
        "value" => $solvedCases,
        "icon" => "bi-check-circle",
        "color" => "green"
    ],
    [
        "title" => "Reopen / Disputed",
        "value" => $reopenDisputedCases,
        "icon" => "bi-exclamation-triangle",
        "color" => "red"
    ],
    [
        "title" => "High Risk Zone",
        "value" => $highRiskZones,
        "icon" => "bi-geo-alt",
        "color" => "red"
    ]
];

// Summary Sections Data

// 1. Recent Complaints
$recentComplaints = cd_fetch_all($conn, "
    SELECT c.complaint_code, i.issue_name, a.area_name, c.complaint_status, c.submitted_at 
    FROM complaints c 
    LEFT JOIN issues i ON c.issue_id = i.issue_id 
    LEFT JOIN locations l ON c.loc_id = l.loc_id 
    LEFT JOIN areas a ON l.area_id = a.area_id 
    ORDER BY c.submitted_at DESC 
    LIMIT 3
");

// 2. Ward Verification
$wardVerification = cd_fetch_all($conn, "
    SELECT c.complaint_code, w.ward_name, a.area_name, c.complaint_status, c.submitted_at 
    FROM complaints c 
    LEFT JOIN locations l ON c.loc_id = l.loc_id 
    LEFT JOIN wards w ON l.ward_id = w.ward_id 
    LEFT JOIN areas a ON l.area_id = a.area_id 
    WHERE c.complaint_status = 'pending_verification' 
    ORDER BY c.submitted_at DESC 
    LIMIT 3
");

// 3. Drain Record
$drainRecords = cd_fetch_all($conn, "
    SELECT d.drain_code, d.drain_name, a.area_name, d.drain_condition 
    FROM drains d 
    LEFT JOIN locations l ON d.loc_id = l.loc_id 
    LEFT JOIN areas a ON l.area_id = a.area_id 
    ORDER BY d.created_at DESC 
    LIMIT 3
");

// 4. Risk Zone
$riskZones = cd_fetch_all($conn, "
    SELECT t.thana_name, w.ward_name, a.area_name, r.urgency_level, r.complaint_count_30_days 
    FROM risk r 
    LEFT JOIN thanas t ON r.thana_id = t.thana_id 
    LEFT JOIN wards w ON r.ward_id = w.ward_id 
    LEFT JOIN areas a ON r.area_id = a.area_id 
    WHERE r.risk_status = 'Active' 
    ORDER BY CASE WHEN r.urgency_level = 'High' THEN 1 ELSE 0 END DESC, r.complaint_count_30_days DESC 
    LIMIT 3
");

// 5. Recent Reports
$recentReports = cd_fetch_all($conn, "
    SELECT report_name, generated_at 
    FROM generated_reports 
    ORDER BY generated_at DESC 
    LIMIT 3
");

// 6. Team Ratings
$tableExists = mysqli_query($conn, "SHOW TABLES LIKE 'maintenance_team_reviews'");
$teamRatings = [];
if ($tableExists && mysqli_num_rows($tableExists) > 0) {
    $teamRatings = cd_fetch_all($conn, "
        SELECT t.team_name, AVG(r.rating) as avg_rating, COUNT(r.review_id) as total_reviews 
        FROM maintenance_team_reviews r 
        LEFT JOIN maintenance_teams t ON r.maintenance_team_id = t.maintenance_team_id 
        GROUP BY r.maintenance_team_id 
        ORDER BY avg_rating DESC, total_reviews DESC 
        LIMIT 3
    ");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo cd_safe($pageTitle); ?> | DrainGuard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">

    <link rel="stylesheet" href="../../css/central/dashboard.css">
    <link rel="stylesheet" href="../../css/central/centralTextFix.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="central-dashboard-page">

            <div class="cd-header-card">
                <h1>Central Command Dashboard</h1>
                <p>City-wide drainage system oversight and control</p>
            </div>

            <div class="cd-kpi-grid">
                <?php foreach ($kpiCards as $card): ?>
                    <div class="cd-kpi-card">
                        <div class="cd-kpi-icon <?php echo cd_safe($card["color"]); ?>">
                            <i class="bi <?php echo cd_safe($card["icon"]); ?>"></i>
                        </div>
                        <h2><?php echo number_format((int)$card["value"]); ?></h2>
                        <p><?php echo cd_safe($card["title"]); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="cd-section-grid">

                <!-- 1. Recent Complaints -->
                <div class="cd-summary-card">
                    <div class="cd-summary-header">
                        <h2>Recent Complaints</h2>
                        <a href="complaints.php">View All</a>
                    </div>
                    <div class="cd-summary-body">
                        <?php if (count($recentComplaints) > 0): ?>
                            <?php foreach ($recentComplaints as $row): ?>
                                <div class="cd-summary-item">
                                    <div class="cd-summary-info">
                                        <strong><?php echo cd_safe($row['complaint_code']); ?></strong>
                                        <span><?php echo cd_safe($row['issue_name'] ?? 'Unknown Issue'); ?> &bull; <?php echo cd_safe($row['area_name'] ?? 'Unknown Area'); ?></span>
                                    </div>
                                    <div class="cd-summary-meta">
                                        <span class="status-badge status-<?php echo strtolower(cd_safe($row['complaint_status'])); ?>"><?php echo ucfirst(str_replace('_', ' ', cd_safe($row['complaint_status']))); ?></span>
                                        <small><?php echo date('M d, Y', strtotime($row['submitted_at'])); ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="cd-empty-state">No recent complaints.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 2. Ward Verification -->
                <div class="cd-summary-card">
                    <div class="cd-summary-header">
                        <h2>Ward Verification</h2>
                        <a href="routing-assignment.php">View All</a>
                    </div>
                    <div class="cd-summary-body">
                        <?php if (count($wardVerification) > 0): ?>
                            <?php foreach ($wardVerification as $row): ?>
                                <div class="cd-summary-item">
                                    <div class="cd-summary-info">
                                        <strong><?php echo cd_safe($row['complaint_code']); ?></strong>
                                        <span><?php echo cd_safe($row['ward_name'] ?? 'Unknown Ward'); ?> &bull; <?php echo cd_safe($row['area_name'] ?? 'Unknown Area'); ?></span>
                                    </div>
                                    <div class="cd-summary-meta">
                                        <span class="status-badge status-pending_verification"><?php echo ucfirst(str_replace('_', ' ', cd_safe($row['complaint_status']))); ?></span>
                                        <small><?php echo date('M d, Y', strtotime($row['submitted_at'])); ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="cd-empty-state">No pending verifications.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 3. Drain Record -->
                <div class="cd-summary-card">
                    <div class="cd-summary-header">
                        <h2>Drain Record</h2>
                        <a href="drain-records.php">View All</a>
                    </div>
                    <div class="cd-summary-body">
                        <?php if (count($drainRecords) > 0): ?>
                            <?php foreach ($drainRecords as $row): ?>
                                <div class="cd-summary-item">
                                    <div class="cd-summary-info">
                                        <strong><?php echo cd_safe($row['drain_code'] ?: $row['drain_name']); ?></strong>
                                        <span><?php echo cd_safe($row['area_name'] ?? 'Unknown Area'); ?></span>
                                    </div>
                                    <div class="cd-summary-meta">
                                        <span class="status-badge"><?php echo ucfirst(cd_safe($row['drain_condition'] ?? 'Unknown')); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="cd-empty-state">No drain records found.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 4. Risk Zone -->
                <div class="cd-summary-card">
                    <div class="cd-summary-header">
                        <h2>Risk Zone</h2>
                        <a href="high-risk-zones.php">View All</a>
                    </div>
                    <div class="cd-summary-body">
                        <?php if (count($riskZones) > 0): ?>
                            <?php foreach ($riskZones as $row): ?>
                                <?php 
                                    $loc = array_filter([$row['thana_name'], $row['ward_name'], $row['area_name']]);
                                    $locText = !empty($loc) ? implode(", ", $loc) : "Unknown Location";
                                ?>
                                <div class="cd-summary-item">
                                    <div class="cd-summary-info">
                                        <strong><?php echo cd_safe($locText); ?></strong>
                                        <span><?php echo (int)$row['complaint_count_30_days']; ?> recent complaints</span>
                                    </div>
                                    <div class="cd-summary-meta">
                                        <span class="status-badge status-critical"><?php echo ucfirst(cd_safe($row['urgency_level'])); ?> Risk</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="cd-empty-state">No active risk zones.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 5. Recent Reports -->
                <div class="cd-summary-card">
                    <div class="cd-summary-header">
                        <h2>Recent Reports</h2>
                        <a href="reports.php">View All</a>
                    </div>
                    <div class="cd-summary-body">
                        <?php if (count($recentReports) > 0): ?>
                            <?php foreach ($recentReports as $row): ?>
                                <div class="cd-summary-item">
                                    <div class="cd-summary-info">
                                        <strong><?php echo cd_safe($row['report_name']); ?></strong>
                                    </div>
                                    <div class="cd-summary-meta">
                                        <small><?php echo date('M d, Y', strtotime($row['generated_at'])); ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="cd-empty-state">No recent reports.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 6. Team Ratings -->
                <div class="cd-summary-card">
                    <div class="cd-summary-header">
                        <h2>Team Ratings</h2>
                        <a href="team-feedback.php">View All</a>
                    </div>
                    <div class="cd-summary-body">
                        <?php if (count($teamRatings) > 0): ?>
                            <?php foreach ($teamRatings as $row): ?>
                                <div class="cd-summary-item">
                                    <div class="cd-summary-info">
                                        <strong><?php echo cd_safe($row['team_name'] ?? 'Unknown Team'); ?></strong>
                                        <span><?php echo (int)$row['total_reviews']; ?> Reviews</span>
                                    </div>
                                    <div class="cd-summary-meta">
                                        <span class="rating-badge"><i class="bi bi-star-fill"></i> <?php echo number_format($row['avg_rating'], 1); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="cd-empty-state">No team ratings available.</div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </section>

        

    </main>

</div>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/dashboard.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>