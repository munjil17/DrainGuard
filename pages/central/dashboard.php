<?php
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

    if (!$result) {
        return 0;
    }

    $row = mysqli_fetch_assoc($result);

    return (int)($row["total"] ?? 0);
}

function cd_fetch_all($conn, $sql)
{
    $rows = [];
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        return $rows;
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    return $rows;
}

$totalComplaints = cd_count_query($conn, "
    SELECT COUNT(*) AS total
    FROM complaints
");

$pendingVerification = cd_count_query($conn, "
    SELECT COUNT(*) AS total
    FROM complaints
    WHERE LOWER(complaint_status) IN ('submitted', 'pending_verification')
");

$emergencyCases = cd_count_query($conn, "
    SELECT COUNT(*) AS total
    FROM complaints
    WHERE LOWER(urgency_level) = 'critical'
    AND LOWER(complaint_status) NOT IN ('solved', 'closed', 'rejected')
");

$inProgressCases = cd_count_query($conn, "
    SELECT COUNT(*) AS total
    FROM complaints
    WHERE LOWER(complaint_status) IN ('assigned', 'in_progress')
");

$solvedCases = cd_count_query($conn, "
    SELECT COUNT(*) AS total
    FROM complaints
    WHERE LOWER(complaint_status) IN ('solved', 'closed', 'completed')
");

$highRiskZones = cd_count_query($conn, "
    SELECT COUNT(*) AS total
    FROM (
        SELECT loc_id
        FROM complaints
        WHERE LOWER(urgency_level) IN ('high', 'critical')
        GROUP BY loc_id
        HAVING COUNT(*) >= 2
    ) risk_locations
");

$redAlertCases = cd_count_query($conn, "
    SELECT COUNT(*) AS total
    FROM complaints
    WHERE LOWER(urgency_level) = 'critical'
    AND LOWER(complaint_status) NOT IN ('solved', 'closed', 'rejected')
    AND TIMESTAMPDIFF(HOUR, submitted_at, NOW()) >= 8
");

$teamDelaySummary = cd_count_query($conn, "
    SELECT COUNT(*) AS total
    FROM complaints
    WHERE LOWER(complaint_status) IN ('assigned', 'in_progress')
    AND TIMESTAMPDIFF(DAY, submitted_at, NOW()) >= 2
");

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
        "color" => "blue"
    ],
    [
        "title" => "Emergency Cases",
        "value" => $emergencyCases,
        "icon" => "bi-exclamation-triangle",
        "color" => "red"
    ],
    [
        "title" => "In Progress",
        "value" => $inProgressCases,
        "icon" => "bi-graph-up-arrow",
        "color" => "orange"
    ],
    [
        "title" => "Solved",
        "value" => $solvedCases,
        "icon" => "bi-check-circle",
        "color" => "green"
    ],
    [
        "title" => "High Risk Zones",
        "value" => $highRiskZones,
        "icon" => "bi-geo-alt",
        "color" => "red"
    ],
    [
        "title" => "Red Alert Cases",
        "value" => $redAlertCases,
        "icon" => "bi-bell",
        "color" => "red"
    ],
    [
        "title" => "Team Delay Summary",
        "value" => $teamDelaySummary,
        "icon" => "bi-clock-history",
        "color" => "yellow"
    ]
];

$redAlerts = cd_fetch_all($conn, "
    SELECT
        c.complaint_code,
        c.address_description,
        c.submitted_at,
        c.urgency_level,
        cc.city_cor_name,
        w.ward_name,
        a.area_name,
        TIMESTAMPDIFF(HOUR, c.submitted_at, NOW()) AS overdue_hours
    FROM complaints c
    LEFT JOIN locations l ON c.loc_id = l.loc_id
    LEFT JOIN city_corporations cc ON l.city_cor_id = cc.city_cor_id
    LEFT JOIN wards w ON l.ward_id = w.ward_id
    LEFT JOIN areas a ON l.area_id = a.area_id
    WHERE LOWER(c.urgency_level) = 'critical'
    AND LOWER(c.complaint_status) NOT IN ('solved', 'closed', 'rejected')
    AND TIMESTAMPDIFF(HOUR, c.submitted_at, NOW()) >= 8
    ORDER BY c.submitted_at ASC
    LIMIT 5
");

$wardOverview = cd_fetch_all($conn, "
    SELECT
        w.ward_id,
        w.ward_name,
        cc.city_cor_name,
        COUNT(c.complaint_id) AS total_complaints,
        SUM(CASE WHEN LOWER(c.complaint_status) IN ('solved', 'closed', 'completed') THEN 1 ELSE 0 END) AS solved_complaints,
        SUM(CASE WHEN LOWER(c.urgency_level) = 'critical' THEN 1 ELSE 0 END) AS critical_complaints
    FROM complaints c
    INNER JOIN locations l ON c.loc_id = l.loc_id
    INNER JOIN wards w ON l.ward_id = w.ward_id
    INNER JOIN city_corporations cc ON l.city_cor_id = cc.city_cor_id
    GROUP BY w.ward_id, w.ward_name, cc.city_cor_name
    ORDER BY critical_complaints DESC, total_complaints DESC
    LIMIT 3
");
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
    <link rel="stylesheet" href="../../css/central/footer.css">
    <link rel="stylesheet" href="../../css/central/dashboard.css">
    <link rel="stylesheet" href="../../css/central/centralTextFix.css">
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

            <div class="cd-red-alert-panel">

                <div class="cd-alert-header">
                    <div class="cd-alert-left">
                        <div class="cd-alert-icon">
                            <i class="bi bi-bell"></i>
                        </div>

                        <div>
                            <h2>Red Alert: Emergency Cases</h2>
                            <p><?php echo number_format($redAlertCases); ?> emergency complaint(s) not handled within SLA</p>
                        </div>
                    </div>

                    <a href="complaints.php" class="cd-review-btn">Review Now</a>
                </div>

                <div class="cd-alert-list">

                    <?php if (count($redAlerts) > 0): ?>
                        <?php foreach ($redAlerts as $alert): ?>
                            <?php
                            $locationTextParts = [];

                            if (!empty($alert["city_cor_name"])) {
                                $locationTextParts[] = $alert["city_cor_name"];
                            }

                            if (!empty($alert["ward_name"])) {
                                $locationTextParts[] = $alert["ward_name"];
                            }

                            if (!empty($alert["area_name"])) {
                                $locationTextParts[] = $alert["area_name"];
                            }

                            $locationText = count($locationTextParts) > 0
                                ? implode(", ", $locationTextParts)
                                : ($alert["address_description"] ?? "Location unavailable");

                            $overdueHours = (int)($alert["overdue_hours"] ?? 0);
                            ?>
                            <div class="cd-alert-item">
                                <div>
                                    <h3><?php echo cd_safe($alert["complaint_code"]); ?></h3>
                                    <p><?php echo cd_safe($locationText); ?></p>
                                </div>

                                <span><?php echo $overdueHours; ?> hrs overdue</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="cd-alert-empty">
                            <i class="bi bi-check-circle"></i>
                            <span>No red alert cases right now.</span>
                        </div>
                    <?php endif; ?>

                </div>

            </div>

            <div class="cd-panel">

                <div class="cd-panel-header">
                    <h2>Ward-wise Overview</h2>

                    <a href="reports.php">
                        View Detailed Reports <i class="bi bi-chevron-right"></i>
                    </a>
                </div>

                <div class="cd-ward-grid">

                    <?php if (count($wardOverview) > 0): ?>
                        <?php foreach ($wardOverview as $ward): ?>
                            <?php
                            $totalWardComplaints = (int)($ward["total_complaints"] ?? 0);
                            $solvedWardComplaints = (int)($ward["solved_complaints"] ?? 0);
                            $criticalWardComplaints = (int)($ward["critical_complaints"] ?? 0);

                            $resolvedPercent = $totalWardComplaints > 0
                                ? round(($solvedWardComplaints / $totalWardComplaints) * 100)
                                : 0;

                            if ($criticalWardComplaints >= 2) {
                                $wardClass = "danger";
                                $wardNote = "Emergency attention";
                            } elseif ($resolvedPercent < 50) {
                                $wardClass = "warning";
                                $wardNote = "High pending load";
                            } else {
                                $wardClass = "stable";
                                $wardNote = $resolvedPercent . "% resolved";
                            }
                            ?>

                            <div class="cd-ward-card <?php echo cd_safe($wardClass); ?>">
                                <h3><?php echo cd_safe($ward["city_cor_name"] . " - " . $ward["ward_name"]); ?></h3>
                                <p><?php echo number_format($totalWardComplaints); ?> complaints</p>
                                <span><?php echo cd_safe($wardNote); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="cd-ward-empty">
                            <i class="bi bi-inbox"></i>
                            <span>No ward complaint data found yet.</span>
                        </div>
                    <?php endif; ?>

                </div>

            </div>

        </section>

        <?php include "../../includes/central/footer.php"; ?>

    </main>

</div>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/dashboard.js"></script>

</body>
</html>