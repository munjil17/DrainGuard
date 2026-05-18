<?php
$activePage = 'high-risk-areas';
$pageTitle = 'High Risk Areas';
$pageParent = 'Citizen';
$pageChild = 'High Risk Areas';

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'citizen') {
    header("Location: ../../index.php");
    exit();
}

$riskAreas = [];

$sql = "
    SELECT
        a.area_id,
        a.area_name,
        w.ward_no,
        w.ward_name,
        t.thana_name,
        cc.city_cor_name,

        COUNT(c.complaint_id) AS total_complaints,

        GROUP_CONCAT(DISTINCT c.issue_type ORDER BY c.issue_type SEPARATOR ', ') AS issue_types,

        MAX(c.submitted_at) AS latest_complaint_date

    FROM complaints c

    INNER JOIN locations l
        ON c.loc_id = l.loc_id

    INNER JOIN areas a
        ON l.area_id = a.area_id

    INNER JOIN wards w
        ON l.ward_id = w.ward_id

    INNER JOIN thanas t
        ON l.thana_id = t.thana_id

    INNER JOIN city_corporations cc
        ON l.city_cor_id = cc.city_cor_id

    WHERE c.submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)

    GROUP BY
        a.area_id,
        a.area_name,
        w.ward_no,
        w.ward_name,
        t.thana_name,
        cc.city_cor_name

    HAVING total_complaints >= 1

    ORDER BY total_complaints DESC
";

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $riskAreas[] = $row;
    }
}

function safeText($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function riskLevel($count) {
    $count = (int)$count;

    if ($count >= 10) {
        return "High Risk";
    }

    if ($count >= 5) {
        return "Medium Risk";
    }

    return "Low Risk";
}

function riskClass($count) {
    $count = (int)$count;

    if ($count >= 10) {
        return "hra-high";
    }

    if ($count >= 5) {
        return "hra-medium";
    }

    return "hra-low";
}

function riskIcon($count) {
    $count = (int)$count;

    if ($count >= 10) {
        return "bi-exclamation-octagon";
    }

    if ($count >= 5) {
        return "bi-exclamation-triangle";
    }

    return "bi-info-circle";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>High Risk Areas | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <!-- Global CSS -->
    <link rel="stylesheet" href="../../css/global/global.css">

    <!-- Reusable Citizen Layout CSS -->
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">

    <!-- Page CSS -->
    <link rel="stylesheet" href="../../css/citizen/high-risk-areas.css">
</head>
<body class="citizen">

<div class="citizen-layout">

    <?php include "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main main-content">

        <?php include "../../includes/citizen/topbar.php"; ?>

        <section class="hra-page">

            <div class="hra-header">
                <div>
                    <h1>High Risk Areas</h1>
                    <p>Areas with repeated waterlogging or drainage complaints in the last 30 days</p>
                </div>

                <div class="hra-count-card">
                    <span><?php echo count($riskAreas); ?></span>
                    <small>Risk Areas</small>
                </div>
            </div>

            <div class="hra-toolbar">
                <div class="hra-search-box">
                    <i class="bi bi-search"></i>
                    <input
                        type="text"
                        id="riskSearch"
                        placeholder="Search by area, ward, thana, city corporation, or issue type..."
                    >
                </div>

                <select id="riskFilter">
                    <option value="all">All Risk</option>
                    <option value="High Risk">High Risk</option>
                    <option value="Medium Risk">Medium Risk</option>
                    <option value="Low Risk">Low Risk</option>
                </select>
            </div>

            <div class="hra-grid" id="riskAreaGrid">

                <?php if (count($riskAreas) > 0): ?>

                    <?php foreach ($riskAreas as $area): ?>
                        <?php
                            $count = (int)$area['total_complaints'];
                            $level = riskLevel($count);
                            $class = riskClass($count);
                            $icon = riskIcon($count);

                            $areaName = safeText($area['area_name']);
                            $wardText = safeText("Ward " . $area['ward_no']);
                            $thanaName = safeText($area['thana_name']);
                            $cityCorName = safeText($area['city_cor_name']);
                            $issueTypes = safeText($area['issue_types'] ?? 'Not specified');

                            $latestDate = !empty($area['latest_complaint_date'])
                                ? date("M d, Y", strtotime($area['latest_complaint_date']))
                                : "N/A";
                        ?>

                        <article
                            class="hra-card <?php echo $class; ?>"
                            data-area="<?php echo strtolower($areaName); ?>"
                            data-ward="<?php echo strtolower($wardText); ?>"
                            data-thana="<?php echo strtolower($thanaName); ?>"
                            data-corporation="<?php echo strtolower($cityCorName); ?>"
                            data-issues="<?php echo strtolower($issueTypes); ?>"
                            data-risk="<?php echo $level; ?>"
                        >
                            <div class="hra-card-top">
                                <div class="hra-title-wrap">
                                    <div class="hra-icon">
                                        <i class="bi <?php echo $icon; ?>"></i>
                                    </div>

                                    <div>
                                        <h2><?php echo $areaName; ?></h2>
                                        <p><?php echo $wardText . " • " . $thanaName; ?></p>
                                    </div>
                                </div>

                                <span class="hra-badge">
                                    <?php echo $level; ?>
                                </span>
                            </div>

                            <div class="hra-stats">
                                <div>
                                    <strong><?php echo $count; ?></strong>
                                    <span>complaints in last 30 days</span>
                                </div>

                                <div>
                                    <strong><?php echo $latestDate; ?></strong>
                                    <span>latest complaint</span>
                                </div>
                            </div>

                            <div class="hra-details">
                                <p>
                                    <i class="bi bi-building"></i>
                                    <?php echo $cityCorName; ?>
                                </p>

                                <p>
                                    <i class="bi bi-tools"></i>
                                    Problem types: <?php echo $issueTypes; ?>
                                </p>
                            </div>
                        </article>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="hra-empty">
                        <i class="bi bi-shield-check"></i>
                        <h2>No high risk areas found</h2>
                        <p>No repeated complaints were found in the last 30 days.</p>
                    </div>

                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/citizen/high-risk-areas.js"></script>

</body>
</html>