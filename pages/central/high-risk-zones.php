<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "high-risk-zones";
$pageTitle = "Risk Zones";
$pageParent = "Central Control";
$pageChild = "Risk Zones";

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function riskClass($level)
{
    $level = strtolower((string)$level);

    if ($level === "high") return "risk-high";
    if ($level === "medium") return "risk-medium";
    if ($level === "low") return "risk-low";

    return "risk-low";
}

function riskLabel($level)
{
    $level = ucfirst(strtolower((string)$level));

    if (!in_array($level, ["High", "Medium", "Low"], true)) {
        return "Low";
    }

    return $level;
}

function wardDisplayName($wardNo, $wardName)
{
    $wardName = trim((string)$wardName);

    if ($wardName !== "") {
        return $wardName;
    }

    return "Ward " . $wardNo;
}

function formatDateOnly($date)
{
    if (empty($date)) {
        return "N/A";
    }

    return date("M d, Y", strtotime($date));
}

function formatDateTime($date)
{
    if (empty($date)) {
        return "N/A";
    }

    return date("M d, Y h:i A", strtotime($date));
}

/*
|--------------------------------------------------------------------------
| FILTER MASTER DATA
|--------------------------------------------------------------------------
*/

$cities = [];
$cityCorporations = [];
$thanas = [];
$wardsForFilter = [];
$areasForFilter = [];

$cityResult = mysqli_query($conn, "
    SELECT city_id, city_name
    FROM cities
    ORDER BY city_name ASC
");

if ($cityResult) {
    while ($row = mysqli_fetch_assoc($cityResult)) {
        $cities[] = $row;
    }
}

$corpResult = mysqli_query($conn, "
    SELECT city_cor_id, city_cor_name, city_id
    FROM city_corporations
    ORDER BY city_cor_name ASC
");

if ($corpResult) {
    while ($row = mysqli_fetch_assoc($corpResult)) {
        $cityCorporations[] = $row;
    }
}

$thanaResult = mysqli_query($conn, "
    SELECT thana_id, thana_name, city_cor_id
    FROM thanas
    ORDER BY thana_name ASC
");

if ($thanaResult) {
    while ($row = mysqli_fetch_assoc($thanaResult)) {
        $thanas[] = $row;
    }
}

$wardResult = mysqli_query($conn, "
    SELECT ward_id, ward_no, ward_name, thana_id
    FROM wards
    ORDER BY CAST(ward_no AS UNSIGNED), ward_no ASC
");

if ($wardResult) {
    while ($row = mysqli_fetch_assoc($wardResult)) {
        $wardsForFilter[] = $row;
    }
}

$areaResult = mysqli_query($conn, "
    SELECT area_id, ward_id, area_name
    FROM areas
    ORDER BY area_name ASC
");

if ($areaResult) {
    while ($row = mysqli_fetch_assoc($areaResult)) {
        $areasForFilter[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| FETCH ACTIVE RISK ZONES
|--------------------------------------------------------------------------
| Shows High + Medium + Low risk zones.
| High Risk cards appear first.
|--------------------------------------------------------------------------
*/

$riskZones = [];

$sql = "
    SELECT
        r.risk_id,
        r.risk_area_key,
        r.city_id,
        r.city_cor_id,
        r.thana_id,
        r.ward_id,
        r.area_id,
        r.urgency_level,
        r.risk_status,
        r.complaint_count_7_days,
        r.complaint_count_30_days,
        r.complaint_count_this_week,
        r.last_complaint_id,
        r.first_reported_at,
        r.last_reported_at,

        city.city_name,
        cc.city_cor_name,
        t.thana_name,
        w.ward_no,
        w.ward_name,
        a.area_name,

        lc.complaint_code AS last_complaint_code,
        lc.address_description AS last_address_description,
        lc.problem_description AS last_problem_description,
        lc.submitted_at AS last_complaint_submitted_at,

        i.issue_name AS last_issue_name,
        u.user_name AS last_citizen_name,
        u.user_mail AS last_citizen_email

    FROM risk r

    LEFT JOIN complaints lc
        ON r.last_complaint_id = lc.complaint_id

    LEFT JOIN locations l
        ON lc.loc_id = l.loc_id

    LEFT JOIN cities city
        ON l.city_id = city.city_id

    LEFT JOIN city_corporations cc
        ON l.city_cor_id = cc.city_cor_id

    LEFT JOIN thanas t
        ON l.thana_id = t.thana_id

    LEFT JOIN wards w
        ON l.ward_id = w.ward_id

    LEFT JOIN areas a
        ON l.area_id = a.area_id

    LEFT JOIN issues i
        ON lc.issue_id = i.issue_id

    LEFT JOIN users u
        ON lc.user_id = u.user_id

    WHERE r.risk_status = 'Active'
    AND r.urgency_level IN ('High', 'Medium', 'Low')

    ORDER BY
        CASE
            WHEN r.urgency_level = 'High' THEN 1
            WHEN r.urgency_level = 'Medium' THEN 2
            WHEN r.urgency_level = 'Low' THEN 3
            ELSE 4
        END,
        r.complaint_count_30_days DESC,
        r.last_reported_at DESC
";

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $riskZones[] = $row;
    }
}

$totalHighRiskZones = 0;
$complaintsInRiskZones = 0;
$zonesEscalating = 0;

foreach ($riskZones as $zone) {
    $riskLevel = riskLabel($zone["urgency_level"] ?? "Low");

    if ($riskLevel === "High") {
        $totalHighRiskZones++;
        $complaintsInRiskZones += (int)($zone["complaint_count_30_days"] ?? 0);

        if ((int)($zone["complaint_count_this_week"] ?? 0) > 0) {
            $zonesEscalating++;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>Risk Zones | DrainGuard</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">
    <link rel="stylesheet" href="../../css/central/high-risk-zones.css">
    <link rel="stylesheet" href="../../css/central/centralTextFix.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="hrz-page">

            <div class="hrz-header">
                <div>
                    <h1>Risk Zones</h1>
                    <p>Monitor areas with repeated drainage complaints and high waterlogging risk.</p>
                </div>
            </div>



            <div class="hrz-filter-card">
                <select id="cityFilter">
                    <option value="">All City</option>
                    <?php foreach ($cities as $city): ?>
                        <option value="<?php echo (int)$city["city_id"]; ?>">
                            <?php echo safeText($city["city_name"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="cityCorporationFilter" disabled>
                    <option value="">All City Corporation</option>
                </select>

                <select id="thanaFilter" disabled>
                    <option value="">All Thana</option>
                </select>

                <select id="wardFilter" disabled>
                    <option value="">All Ward</option>
                </select>

                <select id="areaFilter" disabled>
                    <option value="">All Area</option>
                </select>

                <select id="riskFilter">
                    <option value="">All Risk</option>
                    <option value="High">High Risk</option>
                    <option value="Medium">Medium Risk</option>
                    <option value="Low">Low Risk</option>
                </select>

                <button type="button" id="clearRiskFilters" class="hrz-clear-btn">
                    Clear
                </button>
            </div>

            <div class="hrz-zone-grid" id="riskZoneGrid">

                <?php if (count($riskZones) > 0): ?>

                    <?php foreach ($riskZones as $zone): ?>
                        <?php
                            $riskLevel = riskLabel($zone["urgency_level"] ?? "Low");
                            $riskClass = riskClass($riskLevel);

                            $areaName = $zone["area_name"] ?: "Unknown Area";
                            $wardText = wardDisplayName($zone["ward_no"], $zone["ward_name"]);
                            $thanaName = $zone["thana_name"] ?: "Unknown Thana";
                            $cityCorName = $zone["city_cor_name"] ?: "Unknown Corporation";
                            $cityName = $zone["city_name"] ?: "Unknown City";

                            $count30 = (int)($zone["complaint_count_30_days"] ?? 0);
                            $countWeek = (int)($zone["complaint_count_this_week"] ?? 0);
                            $lastReportedAt = $zone["last_reported_at"] ?? null;

                            $lastIssue = $zone["last_issue_name"] ?: "N/A";
                            $lastComplaintCode = $zone["last_complaint_code"] ?: "N/A";
                            $lastProblem = $zone["last_problem_description"] ?: "N/A";
                            $lastAddress = $zone["last_address_description"] ?: "N/A";
                            $lastCitizen = $zone["last_citizen_name"] ?: "N/A";
                            $lastCitizenEmail = $zone["last_citizen_email"] ?: "N/A";
                        ?>

                        <article
                            class="hrz-zone-card <?php echo $riskClass; ?>"
                            data-city-id="<?php echo (int)$zone["city_id"]; ?>"
                            data-city-cor-id="<?php echo (int)$zone["city_cor_id"]; ?>"
                            data-thana-id="<?php echo (int)$zone["thana_id"]; ?>"
                            data-ward-id="<?php echo (int)$zone["ward_id"]; ?>"
                            data-area-id="<?php echo (int)$zone["area_id"]; ?>"
                            data-risk="<?php echo safeText($riskLevel); ?>"
                        >
                            <div class="hrz-card-top">
                                <div class="hrz-place">
                                    <div class="hrz-card-icon">
                                        <i class="bi bi-geo-alt"></i>
                                    </div>

                                    <div>
                                        <h2><?php echo safeText($areaName); ?></h2>
                                        <p><?php echo safeText($wardText); ?></p>
                                    </div>
                                </div>

                                <span class="hrz-risk-badge <?php echo $riskClass; ?>">
                                    <?php echo safeText($riskLevel); ?> Risk
                                </span>
                            </div>

                            <div class="hrz-stat-grid">
                                <div>
                                    <span>Total Complaints (30 days)</span>
                                    <strong><?php echo $count30; ?></strong>
                                </div>

                                <div>
                                    <span>Trend</span>
                                    <strong class="hrz-trend">
                                        +<?php echo $countWeek; ?> this week
                                    </strong>
                                </div>

                                <div>
                                    <span>Last Incident</span>
                                    <strong><?php echo safeText(formatDateOnly($lastReportedAt)); ?></strong>
                                </div>

                                <div>
                                    <span>Last Issue</span>
                                    <strong><?php echo safeText($lastIssue); ?></strong>
                                </div>
                            </div>

                            <div class="hrz-card-actions">
                                <button
                                    type="button"
                                    class="hrz-details-btn"
                                    data-area="<?php echo safeText($areaName); ?>"
                                    data-risk="<?php echo safeText($riskLevel); ?>"
                                    data-city="<?php echo safeText($cityName); ?>"
                                    data-corporation="<?php echo safeText($cityCorName); ?>"
                                    data-thana="<?php echo safeText($thanaName); ?>"
                                    data-ward="<?php echo safeText($wardText); ?>"
                                    data-count30="<?php echo $count30; ?>"
                                    data-count7="<?php echo (int)($zone["complaint_count_7_days"] ?? 0); ?>"
                                    data-countweek="<?php echo $countWeek; ?>"
                                    data-firstreported="<?php echo safeText(formatDateTime($zone["first_reported_at"] ?? null)); ?>"
                                    data-lastreported="<?php echo safeText(formatDateTime($lastReportedAt)); ?>"
                                    data-complaint="<?php echo safeText($lastComplaintCode); ?>"
                                    data-issue="<?php echo safeText($lastIssue); ?>"
                                    data-problem="<?php echo safeText($lastProblem); ?>"
                                    data-address="<?php echo safeText($lastAddress); ?>"
                                    data-citizen="<?php echo safeText($lastCitizen); ?>"
                                    data-email="<?php echo safeText($lastCitizenEmail); ?>"
                                >
                                    View Details
                                </button>

                                <button
                                    type="button"
                                    class="hrz-alert-btn"
                                    data-area="<?php echo safeText($areaName); ?>"
                                    data-ward="<?php echo safeText($wardText); ?>"
                                >
                                    Send Alert
                                </button>
                            </div>
                        </article>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="hrz-empty">
                        <i class="bi bi-shield-check"></i>
                        <h2>No active risk zones found</h2>
                        <p>Risk zones will appear here after complaints are recorded.</p>
                    </div>

                <?php endif; ?>

            </div>

            <div class="hrz-no-result" id="hrzNoResult" hidden>
                <i class="bi bi-search"></i>
                <h2>No matching risk zone found</h2>
                <p>Try changing the location or risk filter.</p>
            </div>

        </section>


    </main>

</div>

<div class="hrz-modal-overlay" id="riskDetailsModal">
    <div class="hrz-modal">
        <div class="hrz-modal-header">
            <div>
                <h2 id="modalAreaName">Risk Zone Details</h2>
                <p id="modalRiskLevel">Risk Level</p>
            </div>

            <button type="button" class="hrz-modal-close" id="closeRiskModal">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="hrz-modal-body">
            <div class="hrz-detail-grid">
                <div><span>City</span><strong id="modalCity"></strong></div>
                <div><span>City Corporation</span><strong id="modalCorporation"></strong></div>
                <div><span>Thana</span><strong id="modalThana"></strong></div>
                <div><span>Ward</span><strong id="modalWard"></strong></div>
                <div><span>30 Days Complaints</span><strong id="modalCount30"></strong></div>
                <div><span>7 Days Complaints</span><strong id="modalCount7"></strong></div>
                <div><span>This Week Trend</span><strong id="modalCountWeek"></strong></div>
                <div><span>First Reported</span><strong id="modalFirstReported"></strong></div>
                <div><span>Last Reported</span><strong id="modalLastReported"></strong></div>
                <div><span>Last Complaint</span><strong id="modalComplaint"></strong></div>
                <div><span>Last Issue</span><strong id="modalIssue"></strong></div>
                <div><span>Citizen</span><strong id="modalCitizen"></strong></div>
                <div class="hrz-detail-wide"><span>Address</span><strong id="modalAddress"></strong></div>
                <div class="hrz-detail-wide"><span>Problem Description</span><strong id="modalProblem"></strong></div>
            </div>
        </div>
    </div>
</div>

<script>
    window.highRiskFilterData = {
        cityCorporations: <?php echo json_encode($cityCorporations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        thanas: <?php echo json_encode($thanas, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        wards: <?php echo json_encode($wardsForFilter, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        areas: <?php echo json_encode($areasForFilter, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
    };
</script>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/high-risk-zones.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>