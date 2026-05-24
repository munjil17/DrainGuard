<?php
$activePage = "ward-risk-zones";
$pageTitle = "Ward Risk Zones";

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "ward_officer") {
    header("Location: ../../index.php");
    exit();
}

if (!isset($conn) || !$conn) {
    die("Database connection not found.");
}

$currentUserId = (int)($_SESSION["user_id"] ?? 0);
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function fetchOne($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception("SQL Prepare Failed: " . mysqli_error($conn));
    }

    if ($types !== "" && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function fetchAllRows($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception("SQL Prepare Failed: " . mysqli_error($conn));
    }

    if ($types !== "" && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function riskLevel($urgency, $count30)
{
    $urgency = strtolower(trim((string)$urgency));
    $count30 = (int)$count30;

    if ($urgency === "high" || $count30 >= 10) {
        return "High";
    }

    if ($urgency === "medium" || $count30 >= 5) {
        return "Medium";
    }

    return "Low";
}

function riskClass($riskLevel)
{
    $riskLevel = strtolower(trim((string)$riskLevel));

    if ($riskLevel === "high") {
        return "high";
    }

    if ($riskLevel === "medium") {
        return "medium";
    }

    return "low";
}

function suggestedAction($riskLevel, $count30)
{
    $riskLevel = strtolower(trim((string)$riskLevel));
    $count30 = (int)$count30;

    if ($riskLevel === "high" || $count30 >= 10) {
        return "Schedule preventive maintenance";
    }

    if ($riskLevel === "medium" || $count30 >= 5) {
        return "Inspect drainage system";
    }

    return "Monitor closely";
}

function formatDateOnly($date)
{
    if (!$date) {
        return "N/A";
    }

    $time = strtotime($date);

    if (!$time) {
        return "N/A";
    }

    return date("M d", $time);
}

function trendText($countThisWeek)
{
    $countThisWeek = (int)$countThisWeek;

    if ($countThisWeek > 0) {
        return "+" . $countThisWeek . " this week";
    }

    return "No change";
}

function trendClass($countThisWeek)
{
    return ((int)$countThisWeek > 0) ? "up" : "same";
}

/*
|--------------------------------------------------------------------------
| Get logged-in ward officer
|--------------------------------------------------------------------------
*/

try {
    $wardOfficer = fetchOne(
        $conn,
        "SELECT
            wo.ward_officer_id,
            wo.user_id,
            wo.assigned_ward_id,
            wo.full_name,
            w.ward_id,
            w.ward_no,
            w.ward_name
        FROM ward_officers wo
        INNER JOIN wards w
            ON wo.assigned_ward_id = w.ward_id
        WHERE wo.user_id = ?
        LIMIT 1",
        "i",
        [$currentUserId]
    );

    if (!$wardOfficer) {
        throw new Exception("No assigned ward found for this Ward Officer.");
    }

    $wardId = (int)$wardOfficer["assigned_ward_id"];
    $wardNo = $wardOfficer["ward_no"] ?? "";
    $wardName = $wardOfficer["ward_name"] ?? "";
    $userName = $wardOfficer["full_name"] ?? ($_SESSION["user_name"] ?? "Ward Officer");

    $_SESSION["user_name"] = $userName;
    $_SESSION["user_role_label"] = "Ward Operations";
} catch (Exception $e) {
    die($e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Fetch risk zones
|--------------------------------------------------------------------------
*/

try {
    $riskSql = "
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

            a.area_name,
            w.ward_no,
            w.ward_name,
            c.complaint_code AS last_complaint_code,
            i.issue_name AS last_issue_name

        FROM risk r

        INNER JOIN wards w
            ON r.ward_id = w.ward_id

        LEFT JOIN areas a
            ON r.area_id = a.area_id

        LEFT JOIN complaints c
            ON r.last_complaint_id = c.complaint_id

        LEFT JOIN issues i
            ON c.issue_id = i.issue_id

        WHERE r.ward_id = ?
        AND r.risk_status = 'Active'

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

    $riskZones = fetchAllRows($conn, $riskSql, "i", [$wardId]);
} catch (Exception $e) {
    $riskZones = [];
    $errorMessage = $e->getMessage();
}

$totalRiskZones = count($riskZones);
$highRiskCount = 0;
$totalComplaintsInRiskZones = 0;

foreach ($riskZones as $zone) {
    $level = riskLevel($zone["urgency_level"] ?? "Low", $zone["complaint_count_30_days"] ?? 0);

    if ($level === "High") {
        $highRiskCount++;
    }

    $totalComplaintsInRiskZones += (int)($zone["complaint_count_30_days"] ?? 0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ward Risk Zones | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
    <link rel="stylesheet" href="../../css/ward/footer.css">
    <link rel="stylesheet" href="../../css/ward/ward-risk-zones.css">
    <link rel="stylesheet" href="../../css/ward/wardTextFix.css">
</head>

<body class="ward">

<?php include "../../includes/ward/sidebar.php"; ?>

<main class="ward-main">
    <?php include "../../includes/ward/topbar.php"; ?>

    <section class="wrz-page">

        <div class="wrz-header">
            <div>
                <h1>Ward Risk Zones</h1>
                <p>
                    Monitor areas with repeated drainage complaints in
                    Ward <?= safeText($wardNo); ?><?= $wardName ? " - " . safeText($wardName) : ""; ?>.
                </p>
            </div>
        </div>

        <?php if ($errorMessage !== ""): ?>
            <div class="wrz-alert wrz-error">
                <i class="bi bi-exclamation-circle"></i>
                <?= safeText($errorMessage); ?>
            </div>
        <?php endif; ?>

        <div class="wrz-summary-grid">
            <div class="wrz-summary-card">
                <div class="wrz-summary-icon total">
                    <i class="bi bi-geo-alt"></i>
                </div>
                <div>
                    <h2><?= $totalRiskZones; ?></h2>
                    <p>Total Risk Zones</p>
                </div>
            </div>

            <div class="wrz-summary-card">
                <div class="wrz-summary-icon high">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div>
                    <h2><?= $highRiskCount; ?></h2>
                    <p>High Risk Areas</p>
                </div>
            </div>

            <div class="wrz-summary-card">
                <div class="wrz-summary-icon complaint">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
                <div>
                    <h2><?= $totalComplaintsInRiskZones; ?></h2>
                    <p>Total Complaints in Risk Zones</p>
                </div>
            </div>
        </div>

        <div class="wrz-toolbar">
            <div class="wrz-search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="wrzSearch" placeholder="Search by area, risk level, or complaint ID...">
            </div>

            <select id="wrzRiskFilter">
                <option value="all">All Risk Levels</option>
                <option value="high">High Risk</option>
                <option value="medium">Medium Risk</option>
                <option value="low">Low Risk</option>
            </select>
        </div>

        <div class="wrz-grid" id="wrzGrid">

            <?php if (!empty($riskZones)): ?>
                <?php foreach ($riskZones as $zone): ?>
                    <?php
                        $areaName = $zone["area_name"] ?: "Unknown Area";
                        $level = riskLevel($zone["urgency_level"] ?? "Low", $zone["complaint_count_30_days"] ?? 0);
                        $class = riskClass($level);
                        $count30 = (int)($zone["complaint_count_30_days"] ?? 0);
                        $countWeek = (int)($zone["complaint_count_this_week"] ?? 0);
                        $lastIncident = formatDateOnly($zone["last_reported_at"] ?? null);
                        $action = suggestedAction($level, $count30);
                        $lastIssueName = $zone["last_issue_name"] ?: "No issue recorded";
                        $lastComplaintCode = $zone["last_complaint_code"] ?: "N/A";

                        $searchText = strtolower(
                            $areaName . " " .
                            $level . " " .
                            $lastIssueName . " " .
                            $lastComplaintCode . " " .
                            $action
                        );
                    ?>

                    <article class="wrz-card <?= safeText($class); ?>"
                             data-risk="<?= safeText($class); ?>"
                             data-search="<?= safeText($searchText); ?>">

                        <div class="wrz-card-top">
                            <div class="wrz-zone-title">
                                <div class="wrz-zone-icon <?= safeText($class); ?>">
                                    <i class="bi bi-geo-alt"></i>
                                </div>

                                <div>
                                    <h2><?= safeText($areaName); ?></h2>
                                    <p>Ward <?= safeText($zone["ward_no"] ?? $wardNo); ?></p>
                                </div>
                            </div>

                            <span class="wrz-risk-badge <?= safeText($class); ?>">
                                <?= safeText($level); ?> Risk
                            </span>
                        </div>

                        <div class="wrz-metrics">
                            <div>
                                <span>Complaints (30 days)</span>
                                <strong><?= $count30; ?></strong>
                            </div>

                            <div>
                                <span>Trend</span>
                                <strong class="wrz-trend <?= trendClass($countWeek); ?>">
                                    <?= safeText(trendText($countWeek)); ?>
                                </strong>
                            </div>

                            <div>
                                <span>Last Incident</span>
                                <strong><?= safeText($lastIncident); ?></strong>
                            </div>

                            <div>
                                <span>Last Issue</span>
                                <strong><?= safeText($lastIssueName); ?></strong>
                            </div>
                        </div>

                        <div class="wrz-action-box">
                            <span>Suggested Action</span>
                            <p><?= safeText($action); ?></p>
                        </div>

                        <div class="wrz-actions">
                            <button type="button"
                                    class="wrz-btn view-details"
                                    data-area="<?= safeText($areaName); ?>"
                                    data-risk="<?= safeText($level); ?>"
                                    data-complaints="<?= $count30; ?>"
                                    data-trend="<?= safeText(trendText($countWeek)); ?>"
                                    data-last-incident="<?= safeText($lastIncident); ?>"
                                    data-last-issue="<?= safeText($lastIssueName); ?>"
                                    data-last-code="<?= safeText($lastComplaintCode); ?>"
                                    data-action="<?= safeText($action); ?>">
                                View Details
                            </button>

                            <a href="local-team-assignment.php" class="wrz-btn take-action">
                                Take Action
                            </a>
                        </div>

                    </article>
                <?php endforeach; ?>

            <?php else: ?>
                <div class="wrz-empty">
                    <i class="bi bi-check-circle"></i>
                    <h2>No active risk zones found</h2>
                    <p>Repeated complaint areas will appear here after risk analysis.</p>
                </div>
            <?php endif; ?>

        </div>

    </section>

    <?php include "../../includes/ward/footer.php"; ?>
</main>

<div class="wrz-modal-overlay" id="wrzModalOverlay">
    <div class="wrz-modal">
        <div class="wrz-modal-header">
            <div>
                <h2 id="modalAreaName">Risk Zone Details</h2>
                <p id="modalRiskLevel">Risk Level</p>
            </div>

            <button type="button" id="wrzModalClose">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="wrz-modal-body">
            <div class="wrz-modal-grid">
                <div>
                    <span>Complaints in 30 Days</span>
                    <strong id="modalComplaints">0</strong>
                </div>

                <div>
                    <span>Trend</span>
                    <strong id="modalTrend">N/A</strong>
                </div>

                <div>
                    <span>Last Incident</span>
                    <strong id="modalLastIncident">N/A</strong>
                </div>

                <div>
                    <span>Last Issue</span>
                    <strong id="modalLastIssue">N/A</strong>
                </div>

                <div>
                    <span>Last Complaint ID</span>
                    <strong id="modalLastCode">N/A</strong>
                </div>
            </div>

            <div class="wrz-modal-action">
                <span>Suggested Action</span>
                <p id="modalSuggestedAction">N/A</p>
            </div>
        </div>
    </div>
</div>

<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/ward-risk-zones.js"></script>

</body>
</html>