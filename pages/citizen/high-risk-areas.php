<?php
// C:\xampp\htdocs\DrainGuard\pages\citizen\high-risk-areas.php

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

$userId = (int)($_SESSION['user_id'] ?? 0);
$riskAreas = [];

function hra_safe($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function hra_format_date($date)
{
    if (empty($date)) {
        return "N/A";
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return "N/A";
    }

    return date("M d, Y", $timestamp);
}

function hra_level_label($level)
{
    $level = strtolower(trim((string)$level));

    if ($level === "high") {
        return "High Risk";
    }

    if ($level === "medium") {
        return "Medium Risk";
    }

    return "Low Risk";
}

function hra_level_class($level)
{
    $level = strtolower(trim((string)$level));

    if ($level === "high") {
        return "hra-high";
    }

    if ($level === "medium") {
        return "hra-medium";
    }

    return "hra-low";
}

function hra_level_icon($level)
{
    $level = strtolower(trim((string)$level));

    if ($level === "high") {
        return "bi-geo-alt";
    }

    if ($level === "medium") {
        return "bi-exclamation-triangle";
    }

    return "bi-info-circle";
}

function hra_get_drain_breakdown($conn, $thanaId, $wardId, $areaId)
{
    $drains = [];

    $sql = "
        SELECT
            d.drain_id,
            d.drain_name,
            d.drain_address_description,
            COUNT(c.complaint_id) AS total_complaints,
            MAX(c.submitted_at) AS last_incident
        FROM complaints c
        INNER JOIN drains d
            ON c.drain_id = d.drain_id
        INNER JOIN locations l
            ON c.loc_id = l.loc_id
        WHERE l.thana_id = ?
        AND l.ward_id = ?
        AND l.area_id = ?
        AND c.complaint_status NOT IN ('rejected')
        AND c.submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY
            d.drain_id,
            d.drain_name,
            d.drain_address_description
        ORDER BY
            total_complaints DESC,
            last_incident DESC
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return $drains;
    }

    mysqli_stmt_bind_param($stmt, "iii", $thanaId, $wardId, $areaId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $drains[] = [
            "drain_id" => (int)$row["drain_id"],
            "drain_name" => $row["drain_name"] ?: "Unnamed Drain",
            "drain_address_description" => $row["drain_address_description"] ?: "No address description",
            "total_complaints" => (int)$row["total_complaints"],
            "last_incident" => hra_format_date($row["last_incident"])
        ];
    }

    mysqli_stmt_close($stmt);

    return $drains;
}

/*
|--------------------------------------------------------------------------
| Citizen related thana detection
|--------------------------------------------------------------------------
*/

$citizenThanaIds = [];

$thanaSql = "
    SELECT DISTINCT l.thana_id
    FROM complaints c
    INNER JOIN locations l
        ON c.loc_id = l.loc_id
    WHERE c.user_id = ?
";

$thanaStmt = mysqli_prepare($conn, $thanaSql);

if ($thanaStmt) {
    mysqli_stmt_bind_param($thanaStmt, "i", $userId);
    mysqli_stmt_execute($thanaStmt);

    $thanaResult = mysqli_stmt_get_result($thanaStmt);

    while ($row = mysqli_fetch_assoc($thanaResult)) {
        $citizenThanaIds[] = (int)$row["thana_id"];
    }

    mysqli_stmt_close($thanaStmt);
}

/*
|--------------------------------------------------------------------------
| Fetch active risk areas
|--------------------------------------------------------------------------
*/

$whereThana = "";

if (!empty($citizenThanaIds)) {
    $safeThanaIds = array_map("intval", $citizenThanaIds);
    $whereThana = " AND l.thana_id IN (" . implode(",", $safeThanaIds) . ") ";
}

$sql = "
    SELECT
        r.risk_id,
        r.risk_area_key,
        r.urgency_level,
        r.risk_status,
        r.complaint_count_7_days,
        r.complaint_count_30_days,
        r.complaint_count_this_week,
        r.last_complaint_id,
        r.first_reported_at,
        r.last_reported_at,

        c.complaint_code,
        c.problem_description,
        c.address_description,
        c.submitted_at,

        i.issue_name,
        aa.affected_area_name,

        city.city_name,
        cc.city_cor_name,

        t.thana_id,
        t.thana_name,

        w.ward_id,
        w.ward_no,
        w.ward_name,

        a.area_id,
        a.area_name

    FROM risk r

    INNER JOIN complaints c
        ON r.last_complaint_id = c.complaint_id

    LEFT JOIN issues i
        ON c.issue_id = i.issue_id

    LEFT JOIN affected_areas aa
        ON c.affected_area_id = aa.affected_area_id

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

    WHERE r.risk_status = 'Active'
    $whereThana

    ORDER BY
        CASE r.urgency_level
            WHEN 'High' THEN 1
            WHEN 'Medium' THEN 2
            WHEN 'Low' THEN 3
            ELSE 4
        END,
        r.complaint_count_30_days DESC,
        r.last_reported_at DESC
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("High risk area query failed: " . mysqli_error($conn));
}

while ($row = mysqli_fetch_assoc($result)) {
    $riskAreas[] = $row;
}

/*
|--------------------------------------------------------------------------
| Filter options
|--------------------------------------------------------------------------
*/

$thanaOptions = [];
$wardOptions = [];
$areaOptions = [];

foreach ($riskAreas as $risk) {
    $thanaId = (int)($risk["thana_id"] ?? 0);
    $wardId = (int)($risk["ward_id"] ?? 0);
    $areaId = (int)($risk["area_id"] ?? 0);

    if ($thanaId > 0 && !isset($thanaOptions[$thanaId])) {
        $thanaOptions[$thanaId] = $risk["thana_name"];
    }

    if ($wardId > 0 && !isset($wardOptions[$wardId])) {
        $wardOptions[$wardId] = "Ward " . $risk["ward_no"];
    }

    if ($areaId > 0 && !isset($areaOptions[$areaId])) {
        $areaOptions[$areaId] = $risk["area_name"];
    }
}

asort($thanaOptions);
asort($wardOptions);
asort($areaOptions);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>High Risk Areas | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">
    <link rel="stylesheet" href="../../css/citizen/high-risk-areas.css">
    <link rel="stylesheet" href="../../css/citizen/citizenTextFix.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
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
                    <p>Area-wise drainage risk summary with drain-wise complaint breakdown.</p>
                </div>

                <div class="hra-count-card">
                    <span id="visibleRiskCount"><?php echo count($riskAreas); ?></span>
                    <small>Visible Areas</small>
                </div>
            </div>

            <div class="hra-toolbar">
                <select id="thanaFilter">
                    <option value="all">All Thana</option>
                    <?php foreach ($thanaOptions as $thanaId => $thanaName): ?>
                        <option value="<?php echo (int)$thanaId; ?>">
                            <?php echo hra_safe($thanaName); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="wardFilter">
                    <option value="all">All Ward</option>
                    <?php foreach ($wardOptions as $wardId => $wardName): ?>
                        <option value="<?php echo (int)$wardId; ?>">
                            <?php echo hra_safe($wardName); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="areaFilter">
                    <option value="all">All Area</option>
                    <?php foreach ($areaOptions as $areaId => $areaName): ?>
                        <option value="<?php echo (int)$areaId; ?>">
                            <?php echo hra_safe($areaName); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="riskFilter">
                    <option value="all">All Risk</option>
                    <option value="High">High Risk</option>
                    <option value="Medium">Medium Risk</option>
                    <option value="Low">Low Risk</option>
                </select>

                <button type="button" class="hra-clear-btn" id="clearRiskFilterBtn">
                    Clear
                </button>
            </div>

            <div class="hra-grid" id="riskAreaGrid">

                <?php if (count($riskAreas) > 0): ?>

                    <?php foreach ($riskAreas as $area): ?>
                        <?php
                            $riskId = (int)$area["risk_id"];

                            $levelRaw = $area["urgency_level"] ?: "Low";
                            $levelLabel = hra_level_label($levelRaw);
                            $levelClass = hra_level_class($levelRaw);
                            $levelIcon = hra_level_icon($levelRaw);

                            $areaName = $area["area_name"] ?: "Unknown Area";
                            $wardText = "Ward " . ($area["ward_no"] ?? "N/A");
                            $thanaName = $area["thana_name"] ?: "N/A";
                            $cityCorName = $area["city_cor_name"] ?: "N/A";

                            $thanaId = (int)($area["thana_id"] ?? 0);
                            $wardId = (int)($area["ward_id"] ?? 0);
                            $areaId = (int)($area["area_id"] ?? 0);

                            $count7 = (int)($area["complaint_count_7_days"] ?? 0);
                            $count30 = (int)($area["complaint_count_30_days"] ?? 0);
                            $countWeek = (int)($area["complaint_count_this_week"] ?? 0);

                            $lastIncident = hra_format_date($area["last_reported_at"]);
                            $firstReported = hra_format_date($area["first_reported_at"]);

                            $issueName = $area["issue_name"] ?: "N/A";
                            $affectedAreaName = $area["affected_area_name"] ?: "N/A";
                            $complaintCode = $area["complaint_code"] ?: "N/A";
                            $problemDescription = $area["problem_description"] ?: "";
                            $addressDescription = $area["address_description"] ?: "";

                            $drainBreakdown = hra_get_drain_breakdown($conn, $thanaId, $wardId, $areaId);

                            $drainJson = json_encode(
                                $drainBreakdown,
                                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                            );
                        ?>

                        <article
                            class="hra-card <?php echo hra_safe($levelClass); ?>"
                            data-risk="<?php echo hra_safe($levelRaw); ?>"
                            data-thana-id="<?php echo $thanaId; ?>"
                            data-ward-id="<?php echo $wardId; ?>"
                            data-area-id="<?php echo $areaId; ?>"
                            data-thana-name="<?php echo hra_safe($thanaName); ?>"
                            data-ward-name="<?php echo hra_safe($wardText); ?>"
                            data-area-name="<?php echo hra_safe($areaName); ?>"
                        >
                            <div class="hra-card-top">
                                <div class="hra-title-wrap">
                                    <div class="hra-icon">
                                        <i class="bi <?php echo hra_safe($levelIcon); ?>"></i>
                                    </div>

                                    <div>
                                        <h2><?php echo hra_safe($areaName); ?></h2>
                                        <p><?php echo hra_safe($wardText); ?></p>
                                    </div>
                                </div>

                                <span class="hra-badge">
                                    <?php echo hra_safe($levelLabel); ?>
                                </span>
                            </div>

                            <div class="hra-card-body">
                                <div class="hra-stat-row">
                                    <div>
                                        <span>Total Complaints (30 days)</span>
                                        <strong><?php echo $count30; ?></strong>
                                    </div>

                                    <div>
                                        <span>Trend</span>
                                        <strong class="hra-trend">+<?php echo $countWeek; ?> this week</strong>
                                    </div>
                                </div>

                                <div class="hra-stat-row">
                                    <div>
                                        <span>Last Incident</span>
                                        <strong><?php echo hra_safe($lastIncident); ?></strong>
                                    </div>

                                    <div>
                                        <span>Last Issue</span>
                                        <strong><?php echo hra_safe($issueName); ?></strong>
                                    </div>
                                </div>
                            </div>

                            <div class="hra-actions">
                                <button
                                    type="button"
                                    class="hra-view-btn"
                                    data-risk-id="<?php echo $riskId; ?>"
                                    data-area="<?php echo hra_safe($areaName); ?>"
                                    data-ward="<?php echo hra_safe($wardText); ?>"
                                    data-thana="<?php echo hra_safe($thanaName); ?>"
                                    data-corporation="<?php echo hra_safe($cityCorName); ?>"
                                    data-risk="<?php echo hra_safe($levelLabel); ?>"
                                    data-count7="<?php echo $count7; ?>"
                                    data-count30="<?php echo $count30; ?>"
                                    data-week="<?php echo $countWeek; ?>"
                                    data-last="<?php echo hra_safe($lastIncident); ?>"
                                    data-first="<?php echo hra_safe($firstReported); ?>"
                                    data-issue="<?php echo hra_safe($issueName); ?>"
                                    data-affected="<?php echo hra_safe($affectedAreaName); ?>"
                                    data-code="<?php echo hra_safe($complaintCode); ?>"
                                    data-address="<?php echo hra_safe($addressDescription); ?>"
                                    data-problem="<?php echo hra_safe($problemDescription); ?>"
                                    data-drains="<?php echo hra_safe($drainJson); ?>"
                                >
                                    View Details
                                </button>

                                <button type="button" class="hra-alert-btn">
                                    Safety Notice
                                </button>
                            </div>
                        </article>

                    <?php endforeach; ?>

                    <div class="hra-empty hra-filter-empty" id="riskEmptyState">
                        <i class="bi bi-search"></i>
                        <h2>No matching risk area found</h2>
                        <p>Try changing your filter selection.</p>
                    </div>

                <?php else: ?>

                    <div class="hra-empty">
                        <i class="bi bi-shield-check"></i>
                        <h2>No high risk areas found</h2>
                        <p>No active risk area was found for your related thana.</p>
                    </div>

                <?php endif; ?>

            </div>

            <?php if (count($riskAreas) > 6): ?>
                <div class="hra-load-more-wrap">
                    <button type="button" id="loadMoreRiskBtn" class="hra-load-more-btn">
                        Load More
                    </button>
                </div>
            <?php endif; ?>

        </section>

    </main>

</div>

<div class="hra-modal-overlay" id="riskDetailsModal">
    <div class="hra-modal">
        <div class="hra-modal-header">
            <div>
                <h2 id="modalRiskArea">Risk Area Details</h2>
                <p id="modalRiskWard">Ward</p>
            </div>

            <button type="button" class="hra-modal-close" id="riskModalClose">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="hra-modal-body">
            <div class="hra-detail-grid">
                <div>
                    <span>Risk Level</span>
                    <strong id="modalRiskLevel"></strong>
                </div>

                <div>
                    <span>30 Days Complaints</span>
                    <strong id="modalCount30"></strong>
                </div>

                <div>
                    <span>7 Days Complaints</span>
                    <strong id="modalCount7"></strong>
                </div>

                <div>
                    <span>This Week</span>
                    <strong id="modalWeek"></strong>
                </div>

                <div>
                    <span>Last Incident</span>
                    <strong id="modalLast"></strong>
                </div>

                <div>
                    <span>First Reported</span>
                    <strong id="modalFirst"></strong>
                </div>

                <div>
                    <span>Thana</span>
                    <strong id="modalThana"></strong>
                </div>

                <div>
                    <span>City Corporation</span>
                    <strong id="modalCorporation"></strong>
                </div>

                <div>
                    <span>Last Issue Type</span>
                    <strong id="modalIssue"></strong>
                </div>

                <div>
                    <span>Affected Area</span>
                    <strong id="modalAffected"></strong>
                </div>

                <div>
                    <span>Last Complaint Code</span>
                    <strong id="modalCode"></strong>
                </div>
            </div>

            <div class="hra-modal-section">
                <h3>Drain-wise Complaint Breakdown</h3>
                <div class="hra-drain-list" id="modalDrainList"></div>
            </div>

            <div class="hra-modal-section">
                <h3>Last Complaint Address</h3>
                <p id="modalAddress"></p>
            </div>

            <div class="hra-modal-section">
                <h3>Last Complaint Problem</h3>
                <p id="modalProblem"></p>
            </div>
        </div>
    </div>
</div>

<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/citizen/high-risk-areas.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>