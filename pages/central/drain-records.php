<?php
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

$cities = [];
$cityCorporations = [];
$thanas = [];
$wards = [];
$areas = [];

$cityResult = mysqli_query($conn, "SELECT city_id, city_name FROM cities ORDER BY city_name ASC");
if ($cityResult) {
    while ($row = mysqli_fetch_assoc($cityResult)) {
        $cities[] = $row;
    }
}

$corpResult = mysqli_query($conn, "SELECT city_cor_id, city_cor_name, city_id FROM city_corporations ORDER BY city_cor_name ASC");
if ($corpResult) {
    while ($row = mysqli_fetch_assoc($corpResult)) {
        $cityCorporations[] = $row;
    }
}

$thanaResult = mysqli_query($conn, "SELECT thana_id, thana_name, city_cor_id FROM thanas ORDER BY thana_name ASC");
if ($thanaResult) {
    while ($row = mysqli_fetch_assoc($thanaResult)) {
        $thanas[] = $row;
    }
}

$wardResult = mysqli_query($conn, "SELECT ward_id, ward_no, ward_name, thana_id FROM wards ORDER BY CAST(ward_no AS UNSIGNED), ward_no ASC");
if ($wardResult) {
    while ($row = mysqli_fetch_assoc($wardResult)) {
        $wards[] = $row;
    }
}

$areaResult = mysqli_query($conn, "SELECT area_id, ward_id, area_name FROM areas ORDER BY area_name ASC");
if ($areaResult) {
    while ($row = mysqli_fetch_assoc($areaResult)) {
        $areas[] = $row;
    }
}

$drains = [];
$sql = "
    SELECT
        d.drain_id,
        d.drain_code,
        d.drain_name,
        d.drain_address_description,
        d.drain_condition,
        d.created_at AS drain_created_at,
        d.updated_at AS drain_updated_at,
        l.loc_id, l.city_id, l.city_cor_id, l.thana_id, l.ward_id, l.area_id,
        c.city_name, cc.city_cor_name, t.thana_name, w.ward_no, w.ward_name, a.area_name
    FROM drains d
    INNER JOIN locations l ON d.loc_id = l.loc_id
    INNER JOIN cities c ON l.city_id = c.city_id
    INNER JOIN city_corporations cc ON l.city_cor_id = cc.city_cor_id
    INNER JOIN thanas t ON l.thana_id = t.thana_id
    INNER JOIN wards w ON l.ward_id = w.ward_id
    INNER JOIN areas a ON l.area_id = a.area_id
    ORDER BY d.drain_id DESC
";

$result = mysqli_query($conn, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $drains[] = $row;
    }
}

$latestComplaints = [];
$cmpResult = mysqli_query($conn, "
    SELECT c.drain_id, c.complaint_status, c.updated_at, c.closed_at,
           i.issue_name, i.priority,
           mu.created_at AS mu_created_at, mu.updated_at AS mu_updated_at
    FROM complaints c
    LEFT JOIN issues i ON c.issue_id = i.issue_id
    LEFT JOIN (
        SELECT complaint_id, MAX(created_at) AS mu_created_at, MAX(updated_at) AS mu_updated_at
        FROM maintenance_updates
        GROUP BY complaint_id
    ) mu ON c.complaint_id = mu.complaint_id
    WHERE c.complaint_status NOT IN ('submitted', 'received', 'rejected_by_central', 'rejected_by_ward', 'duplicate', 'final_rejected')
    AND c.drain_id IS NOT NULL
    ORDER BY c.drain_id, c.updated_at DESC
");

if ($cmpResult) {
    while ($row = mysqli_fetch_assoc($cmpResult)) {
        $did = $row['drain_id'];
        if (!isset($latestComplaints[$did])) {
            $latestComplaints[$did] = $row;
        }
    }
}

foreach ($drains as &$drain) {
    $did = $drain['drain_id'];
    $cmp = $latestComplaints[$did] ?? null;

    $condition = !empty($drain['drain_condition']) ? ucfirst(strtolower(trim((string)$drain['drain_condition']))) : 'Good';
    $updatedAt = $drain['drain_updated_at'] ?: $drain['drain_created_at'];

    if ($cmp) {
        $status = $cmp['complaint_status'];
        $issueName = $cmp['issue_name'];
        $priority = $cmp['priority'];

        if ($status === 'closed') {
            $updatedAt = $cmp['closed_at'] ?: $cmp['updated_at'];
        } elseif (!empty($cmp['mu_updated_at']) || !empty($cmp['mu_created_at'])) {
            $updatedAt = $cmp['mu_updated_at'] ?: $cmp['mu_created_at'];
        } else {
            $updatedAt = $cmp['updated_at'];
        }

        if ($status !== 'closed') {
            $issueLower = strtolower(trim((string)$issueName));
            $overrides = [
                'open manhole' => 'Damaged',
                'missing drain cover' => 'Damaged',
                'collapsed drain structure' => 'Damaged',
                'severe road flooding' => 'Overflow',
                'water contamination' => 'Damaged',
                'sewage leakage' => 'Damaged',
                'waterlogging' => 'Overflow',
                'overflowing drain' => 'Overflow',
                'blocked drain' => 'Blocked',
                'drain backflow' => 'Overflow',
                'broken drain cover' => 'Damaged',
                'mosquito breeding' => 'Moderate',
                'illegal waste dumping' => 'Blocked',
                'bad odor' => 'Moderate',
                'garbage accumulation' => 'Blocked',
                'slow drainage' => 'Moderate'
            ];

            if (array_key_exists($issueLower, $overrides)) {
                $condition = $overrides[$issueLower];
            } else {
                if (strcasecmp($priority, 'High') === 0) {
                    $condition = 'Damaged';
                } elseif (strcasecmp($priority, 'Medium') === 0) {
                    $condition = 'Moderate';
                } else {
                    $condition = 'Moderate';
                }
            }
        }
    }

    $drain['calculated_condition'] = $condition;
    $drain['calculated_updated_at'] = $updatedAt;
}
unset($drain);

$totalDrains = count($drains);
$goodCount = 0;
$problemCount = 0;

foreach ($drains as $drain) {
    $condition = strtolower($drain["calculated_condition"]);
    if ($condition === "good") {
        $goodCount++;
    } else {
        $problemCount++;
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
    <style>
        .dr-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
    </style>
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
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
                    <p>Track registered drains, physical condition, and area mapping.</p>
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
                                <th>Updated At</th>
                            </tr>
                        </thead>

                        <tbody id="drainTableBody">
                            <?php if (empty($drains)): ?>
                                <tr>
                                    <td colspan="6" class="dr-empty-row">
                                        No drain records found.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($drains as $drain): ?>
                                <?php
                                    $condition = strtolower($drain["calculated_condition"]);
                                    $conditionLabel = dr_condition_label($condition);
                                    
                                    $wardDisplay = $drain["ward_name"] ?: ("Ward " . $drain["ward_no"]);
                                    $locationText = trim(($drain["area_name"] ?? "") . ", " . ($drain["thana_name"] ?? ""));
                                    $updatedAt = $drain["calculated_updated_at"] ?: $drain["drain_updated_at"] ?: $drain["drain_created_at"];
                                ?>

                                <tr
                                    class="dr-row"
                                    data-city-id="<?php echo (int)$drain["city_id"]; ?>"
                                    data-city-cor-id="<?php echo (int)$drain["city_cor_id"]; ?>"
                                    data-thana-id="<?php echo (int)$drain["thana_id"]; ?>"
                                    data-ward-id="<?php echo (int)$drain["ward_id"]; ?>"
                                    data-area-id="<?php echo (int)$drain["area_id"]; ?>"
                                    data-condition="<?php echo dr_safe($condition); ?>"
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
                                        <span class="dr-date">
                                            <?php 
                                            if ($updatedAt) {
                                                echo dr_safe(date("M d, Y h:i A", strtotime($updatedAt))); 
                                            } else {
                                                echo "N/A";
                                            }
                                            ?>
                                        </span>
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

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>