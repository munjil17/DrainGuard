<?php
// C:\xampp\htdocs\DrainGuard\pages\central\drain-records.php

$activePage = "drain-records";
$pageTitle = "Drain Records Management";
$pageParent = "Central Control";
$pageChild = "Drain Records";

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "central_officer") {
    header("Location: ../../index.php");
    exit();
}

function dr_safe($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function dr_condition_label($condition)
{
    $condition = strtolower(trim((string)$condition));

    $labels = [
        "good" => "Good",
        "moderate" => "Moderate",
        "blocked" => "Blocked",
        "damaged" => "Damaged",
        "overflow" => "Overflow"
    ];

    return $labels[$condition] ?? "Moderate";
}

function dr_risk_label($risk)
{
    $risk = ucfirst(strtolower(trim((string)$risk)));

    if (!in_array($risk, ["Low", "Medium", "High"], true)) {
        return "Low";
    }

    return $risk;
}

/*
|--------------------------------------------------------------------------
| Dropdown master data
|--------------------------------------------------------------------------
*/

$cities = [];
$cityCorporations = [];
$thanas = [];
$wards = [];
$areas = [];

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
        $wards[] = $row;
    }
}

$areaResult = mysqli_query($conn, "
    SELECT area_id, ward_id, area_name
    FROM areas
    ORDER BY area_name ASC
");

if ($areaResult) {
    while ($row = mysqli_fetch_assoc($areaResult)) {
        $areas[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Drain data
|--------------------------------------------------------------------------
*/

$drains = [];

$sql = "
    SELECT
        d.drain_id,
        d.drain_code,
        d.drain_name,
        d.drain_address_description,
        d.drain_condition,
        d.condition_updated_by_role,
        d.condition_updated_at,
        d.created_at,
        d.updated_at,

        l.loc_id,
        l.city_id,
        l.city_cor_id,
        l.thana_id,
        l.ward_id,
        l.area_id,

        c.city_name,
        cc.city_cor_name,
        t.thana_name,
        w.ward_no,
        w.ward_name,
        a.area_name,

        COALESCE(r.urgency_level, 'Low') AS risk_level,

        u.user_name AS condition_updated_by_name

    FROM drains d

    INNER JOIN locations l
        ON d.loc_id = l.loc_id

    INNER JOIN cities c
        ON l.city_id = c.city_id

    INNER JOIN city_corporations cc
        ON l.city_cor_id = cc.city_cor_id

    INNER JOIN thanas t
        ON l.thana_id = t.thana_id

    INNER JOIN wards w
        ON l.ward_id = w.ward_id

    INNER JOIN areas a
        ON l.area_id = a.area_id

    LEFT JOIN risk r
        ON r.city_id = l.city_id
        AND r.city_cor_id = l.city_cor_id
        AND r.thana_id = l.thana_id
        AND r.ward_id = l.ward_id
        AND r.area_id = l.area_id
        AND r.risk_status = 'Active'

    LEFT JOIN users u
        ON d.condition_updated_by_user_id = u.user_id

    ORDER BY
        FIELD(COALESCE(r.urgency_level, 'Low'), 'High', 'Medium', 'Low'),
        d.drain_id DESC
";

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $drains[] = $row;
    }
}

$totalDrains = count($drains);
$goodCount = 0;
$problemCount = 0;
$highRiskCount = 0;

foreach ($drains as $drain) {
    $condition = strtolower($drain["drain_condition"] ?? "moderate");
    $risk = dr_risk_label($drain["risk_level"] ?? "Low");

    if ($condition === "good") {
        $goodCount++;
    } else {
        $problemCount++;
    }

    if ($risk === "High") {
        $highRiskCount++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Drain Records Management | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">
    <link rel="stylesheet" href="../../css/central/drain-records.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="dr-page">

            <div class="dr-header">
                <div>
                    <h1>Drain Records Management</h1>
                    <p>Track registered drains, physical condition, area mapping, and risk level.</p>
                </div>
            </div>

            <div class="dr-kpi-grid">
                <div class="dr-kpi-card total">
                    <span>Total Drains</span>
                    <strong><?php echo (int)$totalDrains; ?></strong>
                    <small>Registered drain records</small>
                </div>

                <div class="dr-kpi-card good">
                    <span>Good Condition</span>
                    <strong><?php echo (int)$goodCount; ?></strong>
                    <small>Inspector-approved / normal</small>
                </div>

                <div class="dr-kpi-card warning">
                    <span>Needs Attention</span>
                    <strong><?php echo (int)$problemCount; ?></strong>
                    <small>Moderate, blocked, damaged, overflow</small>
                </div>

                <div class="dr-kpi-card danger">
                    <span>High Risk</span>
                    <strong><?php echo (int)$highRiskCount; ?></strong>
                    <small>Sorted first from risk table</small>
                </div>
            </div>

            <div class="dr-filter-card">
                <select id="cityFilter">
                    <option value="">All City</option>
                    <?php foreach ($cities as $city): ?>
                        <option value="<?php echo (int)$city["city_id"]; ?>">
                            <?php echo dr_safe($city["city_name"]); ?>
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

                <select id="conditionFilter">
                    <option value="">All Conditions</option>
                    <option value="good">Good</option>
                    <option value="moderate">Moderate</option>
                    <option value="blocked">Blocked</option>
                    <option value="damaged">Damaged</option>
                    <option value="overflow">Overflow</option>
                </select>

                <select id="riskFilter">
                    <option value="">All Risk</option>
                    <option value="High">High</option>
                    <option value="Medium">Medium</option>
                    <option value="Low">Low</option>
                </select>

                <button type="button" id="clearDrainFilters">
                    <i class="bi bi-x-circle"></i>
                    Clear
                </button>
            </div>

            <div class="dr-table-card">
                <div class="dr-table-responsive">
                    <table class="dr-table">
                        <thead>
                            <tr>
                                <th>Drain Code</th>
                                <th>Drain Name</th>
                                <th>Location</th>
                                <th>Ward</th>
                                <th>Condition</th>
                                <th>Risk Level</th>
                                <th>Updated By</th>
                                <th>Updated At</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody id="drainTableBody">
                            <?php if (empty($drains)): ?>
                                <tr>
                                    <td colspan="9" class="dr-empty-row">
                                        No drain records found.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($drains as $drain): ?>
                                <?php
                                    $condition = strtolower($drain["drain_condition"] ?? "moderate");
                                    $conditionLabel = dr_condition_label($condition);
                                    $riskLevel = dr_risk_label($drain["risk_level"] ?? "Low");

                                    $wardDisplay = $drain["ward_name"] ?: ("Ward " . $drain["ward_no"]);
                                    $locationText = trim(($drain["area_name"] ?? "") . ", " . ($drain["thana_name"] ?? ""));
                                    $updatedByRole = $drain["condition_updated_by_role"] ?: "system";
                                    $updatedByName = $drain["condition_updated_by_name"] ?: ucfirst(str_replace("_", " ", $updatedByRole));
                                    $updatedAt = $drain["condition_updated_at"] ?: $drain["updated_at"];
                                ?>

                                <tr
                                    class="dr-row"
                                    data-city-id="<?php echo (int)$drain["city_id"]; ?>"
                                    data-city-cor-id="<?php echo (int)$drain["city_cor_id"]; ?>"
                                    data-thana-id="<?php echo (int)$drain["thana_id"]; ?>"
                                    data-ward-id="<?php echo (int)$drain["ward_id"]; ?>"
                                    data-area-id="<?php echo (int)$drain["area_id"]; ?>"
                                    data-condition="<?php echo dr_safe($condition); ?>"
                                    data-risk="<?php echo dr_safe($riskLevel); ?>"
                                >
                                    <td>
                                        <span class="dr-code">
                                            <?php echo dr_safe($drain["drain_code"]); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="dr-name-cell">
                                            <strong><?php echo dr_safe($drain["drain_name"]); ?></strong>
                                            <span><?php echo dr_safe($drain["drain_address_description"]); ?></span>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="dr-location-cell">
                                            <strong><?php echo dr_safe($drain["area_name"]); ?></strong>
                                            <span><?php echo dr_safe($drain["thana_name"] . " • " . $drain["city_cor_name"]); ?></span>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="dr-ward-badge">
                                            <?php echo dr_safe($wardDisplay); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="dr-condition-badge condition-<?php echo dr_safe($condition); ?>">
                                            <?php echo dr_safe($conditionLabel); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="dr-risk-badge risk-<?php echo strtolower(dr_safe($riskLevel)); ?>">
                                            <?php echo dr_safe($riskLevel); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="dr-updated-by">
                                            <strong><?php echo dr_safe($updatedByName); ?></strong>
                                            <span><?php echo dr_safe(ucfirst(str_replace("_", " ", $updatedByRole))); ?></span>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="dr-date">
                                            <?php echo dr_safe(date("M d, Y", strtotime($updatedAt))); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <button
                                            type="button"
                                            class="dr-view-btn"
                                            data-code="<?php echo dr_safe($drain["drain_code"]); ?>"
                                            data-name="<?php echo dr_safe($drain["drain_name"]); ?>"
                                            data-location="<?php echo dr_safe($locationText); ?>"
                                            data-ward="<?php echo dr_safe($wardDisplay); ?>"
                                            data-condition="<?php echo dr_safe($conditionLabel); ?>"
                                            data-risk="<?php echo dr_safe($riskLevel); ?>"
                                            data-address="<?php echo dr_safe($drain["drain_address_description"]); ?>"
                                            data-city="<?php echo dr_safe($drain["city_name"]); ?>"
                                            data-corporation="<?php echo dr_safe($drain["city_cor_name"]); ?>"
                                            data-updated-by="<?php echo dr_safe($updatedByName); ?>"
                                            data-updated-role="<?php echo dr_safe(ucfirst(str_replace("_", " ", $updatedByRole))); ?>"
                                            data-updated-at="<?php echo dr_safe(date("M d, Y h:i A", strtotime($updatedAt))); ?>"
                                        >
                                            View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="dr-no-result" id="drNoResult" hidden>
                    <i class="bi bi-search"></i>
                    <h3>No matching drain found</h3>
                    <p>Try changing dropdown filters.</p>
                </div>
            </div>

        </section>

    </main>

</div>

<div class="dr-modal-overlay" id="drainDetailsModal">
    <div class="dr-modal">
        <div class="dr-modal-header">
            <div>
                <h2 id="modalDrainName">Drain Details</h2>
                <p id="modalDrainCode">Drain Code</p>
            </div>

            <button type="button" class="dr-modal-close" id="closeDrainModal">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="dr-modal-body">
            <div class="dr-detail-grid">
                <div>
                    <span>Location</span>
                    <strong id="modalDrainLocation">-</strong>
                </div>

                <div>
                    <span>Ward</span>
                    <strong id="modalDrainWard">-</strong>
                </div>

                <div>
                    <span>Condition</span>
                    <strong id="modalDrainCondition">-</strong>
                </div>

                <div>
                    <span>Risk Level</span>
                    <strong id="modalDrainRisk">-</strong>
                </div>

                <div>
                    <span>City</span>
                    <strong id="modalDrainCity">-</strong>
                </div>

                <div>
                    <span>City Corporation</span>
                    <strong id="modalDrainCorporation">-</strong>
                </div>

                <div>
                    <span>Updated By</span>
                    <strong id="modalDrainUpdatedBy">-</strong>
                </div>

                <div>
                    <span>Updated Role</span>
                    <strong id="modalDrainUpdatedRole">-</strong>
                </div>

                <div class="dr-detail-wide">
                    <span>Updated At</span>
                    <strong id="modalDrainUpdatedAt">-</strong>
                </div>

                <div class="dr-detail-wide">
                    <span>Address Description</span>
                    <strong id="modalDrainAddress">-</strong>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.drainFilterData = {
        cityCorporations: <?php echo json_encode($cityCorporations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        thanas: <?php echo json_encode($thanas, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        wards: <?php echo json_encode($wards, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        areas: <?php echo json_encode($areas, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
    };
</script>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/drain-records.js"></script>

</body>
</html>