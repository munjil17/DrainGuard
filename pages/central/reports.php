<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "reports";
$pageTitle = "Reports & Analytics";
$pageParent = "Central Control";
$pageChild = "Reports";

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function reportTypeLabel($type)
{
    $labels = [
        "ward_complaint_performance" => "Ward-wise Complaint Performance",
        "ward_officer_performance" => "Ward Officer Performance",
        "area_complaint_analysis" => "Area-wise Complaint Analysis",
        "issue_type_analysis" => "Issue Type Analysis",
        "high_risk_zone" => "High Risk Zone Report",
        "drain_condition" => "Drain Condition Report",
        "maintenance_team_performance" => "Maintenance Team Performance",
        "complaint_trend" => "Complaint Trend Report",
        "repeat_reopened_complaint" => "Repeat/Reopened Complaint Report"
    ];

    return $labels[$type] ?? ucwords(str_replace("_", " ", (string)$type));
}

function periodLabel($period)
{
    $labels = [
        "last_7_days" => "Last 7 Days",
        "last_30_days" => "Last 30 Days",
        "last_3_months" => "Last 3 Months",
        "last_6_months" => "Last 6 Months",
        "this_year" => "This Year",
        "custom_range" => "Custom Date Range"
    ];

    return $labels[$period] ?? ucwords(str_replace("_", " ", (string)$period));
}

$citiesCorporations = [];
$thanas = [];
$wards = [];
$areas = [];
$recentReports = [];

$cityCorResult = mysqli_query($conn, "
    SELECT city_cor_id, city_cor_name, city_id
    FROM city_corporations
    ORDER BY city_cor_name ASC
");

if ($cityCorResult) {
    while ($row = mysqli_fetch_assoc($cityCorResult)) {
        $citiesCorporations[] = $row;
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
    SELECT ward_id, ward_no, ward_name, city_cor_id, thana_id
    FROM wards
    ORDER BY CAST(ward_no AS UNSIGNED), ward_no ASC
");

if ($wardResult) {
    while ($row = mysqli_fetch_assoc($wardResult)) {
        $wards[] = $row;
    }
}

$areaResult = mysqli_query($conn, "
    SELECT area_id, area_name, ward_id
    FROM areas
    ORDER BY area_name ASC
");

if ($areaResult) {
    while ($row = mysqli_fetch_assoc($areaResult)) {
        $areas[] = $row;
    }
}

$recentSql = "
    SELECT
        gr.report_id,
        gr.report_name,
        gr.report_type,
        gr.report_period,
        gr.export_format,
        gr.file_path,
        gr.generated_at,
        u.user_name AS generated_by_name
    FROM generated_reports gr
    LEFT JOIN users u
        ON gr.generated_by = u.user_id
    ORDER BY gr.generated_at DESC
    LIMIT 10
";

$recentResult = mysqli_query($conn, $recentSql);

if ($recentResult) {
    while ($row = mysqli_fetch_assoc($recentResult)) {
        $recentReports[] = $row;
    }
}

$successMessage = $_SESSION["report_success"] ?? "";
$errorMessage = $_SESSION["report_error"] ?? "";

unset($_SESSION["report_success"], $_SESSION["report_error"]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>Reports & Analytics | DrainGuard</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">
    <link rel="stylesheet" href="../../css/central/footer.css">
    <link rel="stylesheet" href="../../css/central/reports.css">
    <link rel="stylesheet" href="../../css/central/centralTextFix.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="reports-page">

            <div class="reports-header">
                <h1>Reports & Analytics</h1>
                <p>Generate comprehensive reports on city-wide drainage operations.</p>
            </div>

            <?php if ($successMessage !== ""): ?>
                <div class="reports-alert success">
                    <i class="bi bi-check-circle"></i>
                    <?php echo safeText($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ""): ?>
                <div class="reports-alert error">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo safeText($errorMessage); ?>
                </div>
            <?php endif; ?>

            <form
                method="POST"
                action="../../auth/generate_report_process.php"
                class="reports-builder-card"
                id="reportBuilderForm"
            >
                <div class="reports-builder-title">
                    <div>
                        <h2>Custom Report Builder</h2>
                        <p>Select report type, period, location filter, and export format.</p>
                    </div>
                </div>

                <div class="reports-builder-grid">

                    <div class="reports-form-group">
                        <label for="report_type">Report Type</label>
                        <select id="report_type" name="report_type" required>
                            <option value="">Select report type</option>
                            <option value="ward_complaint_performance">Ward-wise Complaint Performance</option>
                            <option value="ward_officer_performance">Ward Officer Performance</option>
                            <option value="area_complaint_analysis">Area-wise Complaint Analysis</option>
                            <option value="issue_type_analysis">Issue Type Analysis</option>
                            <option value="high_risk_zone">High Risk Zone Report</option>
                            <option value="drain_condition">Drain Condition Report</option>
                            <option value="maintenance_team_performance">Maintenance Team Performance</option>
                            <option value="complaint_trend">Complaint Trend Report</option>
                            <option value="repeat_reopened_complaint">Repeat/Reopened Complaint Report</option>
                        </select>
                    </div>

                    <div class="reports-form-group">
                        <label for="report_period">Time Period</label>
                        <select id="report_period" name="report_period" required>
                            <option value="last_7_days">Last 7 days</option>
                            <option value="last_30_days">Last 30 days</option>
                            <option value="last_3_months">Last 3 months</option>
                            <option value="last_6_months">Last 6 months</option>
                            <option value="this_year">This year</option>
                            <option value="custom_range">Custom date range</option>
                        </select>
                    </div>

                    <div class="reports-form-group custom-date-field" hidden>
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date">
                    </div>

                    <div class="reports-form-group custom-date-field" hidden>
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date">
                    </div>

                    <div class="reports-form-group">
                        <label for="city_cor_id">City Corporation</label>
                        <select id="city_cor_id" name="city_cor_id">
                            <option value="">All City Corporations</option>
                            <?php foreach ($citiesCorporations as $cityCor): ?>
                                <option value="<?php echo (int)$cityCor["city_cor_id"]; ?>">
                                    <?php echo safeText($cityCor["city_cor_name"]); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="reports-form-group">
                        <label for="thana_id">Thana</label>
                        <select id="thana_id" name="thana_id" disabled>
                            <option value="">All Thanas</option>
                        </select>
                    </div>

                    <div class="reports-form-group">
                        <label for="ward_id">Ward</label>
                        <select id="ward_id" name="ward_id" disabled>
                            <option value="">All Wards</option>
                        </select>
                    </div>

                    <div class="reports-form-group">
                        <label for="area_id">Area</label>
                        <select id="area_id" name="area_id" disabled>
                            <option value="">All Areas</option>
                        </select>
                    </div>

                    <div class="reports-form-group">
                        <label for="export_format">Export Format</label>
                        <select id="export_format" name="export_format" required>
                            <option value="PDF">PDF</option>
                            <option value="Excel">Excel</option>
                            <option value="CSV">CSV</option>
                            <option value="DOCS">DOCS</option>
                        </select>
                    </div>

                </div>

                <button type="submit" class="reports-generate-btn">
                    <i class="bi bi-download"></i>
                    Generate & Download Report
                </button>
            </form>

            <div class="reports-table-card">

                <div class="reports-table-header">
                    <h2>Recent Reports</h2>
                    <p>Previously generated reports from the central control panel.</p>
                </div>

                <div class="reports-table-wrap">
                    <table class="reports-table">
                        <thead>
                            <tr>
                                <th>Report Name</th>
                                <th>Type</th>
                                <th>Period</th>
                                <th>Format</th>
                                <th>Generated</th>
                                <th>Generated By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (count($recentReports) > 0): ?>
                                <?php foreach ($recentReports as $report): ?>
                                    <?php
                                        $filePath = trim((string)($report["file_path"] ?? ""));
                                        $downloadPath = $filePath !== "" ? "../../" . ltrim(str_replace("\\", "/", $filePath), "/") : "";
                                    ?>

                                    <tr>
                                        <td>
                                            <strong><?php echo safeText($report["report_name"]); ?></strong>
                                        </td>

                                        <td>
                                            <span class="reports-badge">
                                                <?php echo safeText(reportTypeLabel($report["report_type"])); ?>
                                            </span>
                                        </td>

                                        <td><?php echo safeText(periodLabel($report["report_period"])); ?></td>

                                        <td><?php echo safeText($report["export_format"]); ?></td>

                                        <td><?php echo safeText(date("M d, Y h:i A", strtotime($report["generated_at"]))); ?></td>

                                        <td><?php echo safeText($report["generated_by_name"] ?? "Central Officer"); ?></td>

                                        <td>
                                            <?php if ($downloadPath !== ""): ?>
                                                <a href="<?php echo safeText($downloadPath); ?>" class="reports-download-link" download>
                                                    <i class="bi bi-download"></i>
                                                    Download
                                                </a>
                                            <?php else: ?>
                                                <span class="reports-muted">No file</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="reports-empty">
                                            <i class="bi bi-file-earmark-bar-graph"></i>
                                            <h3>No reports generated yet</h3>
                                            <p>Generated reports will appear here.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>

        </section>

        <?php include "../../includes/central/footer.php"; ?>

    </main>

</div>

<script>
    window.reportFilterData = {
        thanas: <?php echo json_encode($thanas, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        wards: <?php echo json_encode($wards, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        areas: <?php echo json_encode($areas, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
    };
</script>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/reports.js"></script>

</body>
</html>