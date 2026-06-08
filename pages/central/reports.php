<?php
require_once "../../config.php";
require_once "../../includes/central/central_report_helpers.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "reports";
$pageTitle = "Reports & Analytics";
$pageParent = "Central Control";
$pageChild = "Reports";

$reportTypes = cr_report_types();
$validPeriods = ["last_7_days", "last_30_days", "last_3_months", "last_6_months", "this_year", "custom_range"];
$validFormats = ["PDF", "Excel", "DOCS", "CSV"];

$selectedReportType = trim($_GET["report_type"] ?? "system_complaint_monitoring");
$selectedPeriod = trim($_GET["report_period"] ?? "last_30_days");
$selectedFormat = trim($_GET["export_format"] ?? "PDF");
$selectedCityCorId = (int)($_GET["city_cor_id"] ?? 0);
$selectedThanaId = (int)($_GET["thana_id"] ?? 0);
$selectedWardId = (int)($_GET["ward_id"] ?? 0);
$selectedAreaId = (int)($_GET["area_id"] ?? 0);
$selectedStartDate = trim($_GET["start_date"] ?? "");
$selectedEndDate = trim($_GET["end_date"] ?? "");
$showPreview = isset($_GET["preview"]);

if (!array_key_exists($selectedReportType, $reportTypes)) {
    $selectedReportType = "system_complaint_monitoring";
}

if (!in_array($selectedPeriod, $validPeriods, true)) {
    $selectedPeriod = "last_30_days";
}

if (!in_array($selectedFormat, $validFormats, true)) {
    $selectedFormat = "PDF";
}

$citiesCorporations = [];
$thanas = [];
$wards = [];
$areas = [];
$recentReports = [];
$previewReport = null;
$previewError = "";

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
    ORDER BY ward_no ASC
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
    LEFT JOIN users u ON gr.generated_by = u.user_id
    ORDER BY gr.generated_at DESC
    LIMIT 8
";
$recentResult = mysqli_query($conn, $recentSql);
if ($recentResult) {
    while ($row = mysqli_fetch_assoc($recentResult)) {
        $recentReports[] = $row;
    }
}

$centralReportDir = __DIR__ . "/../../assets/reports/central";
if (is_dir($centralReportDir)) {
    foreach (glob($centralReportDir . "/*.{pdf,csv,xls,doc}", GLOB_BRACE) ?: [] as $file) {
        $baseName = basename($file);
        $typeKey = "";

        foreach (array_keys($reportTypes) as $candidateType) {
            if (str_starts_with($baseName, $candidateType . "_")) {
                $typeKey = $candidateType;
                break;
            }
        }

        if ($typeKey === "") {
            continue;
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $format = "PDF";
        if ($extension === "csv") $format = "CSV";
        if ($extension === "xls") $format = "Excel";
        if ($extension === "doc") $format = "DOCS";

        $recentReports[] = [
            "report_id" => 0,
            "report_name" => cr_report_type_label($typeKey),
            "report_type" => $typeKey,
            "report_period" => "generated_export",
            "export_format" => $format,
            "file_path" => "assets/reports/central/" . $baseName,
            "generated_at" => date("Y-m-d H:i:s", filemtime($file)),
            "generated_by_name" => "Central Officer",
        ];
    }
}

usort($recentReports, function ($a, $b) {
    return strtotime($b["generated_at"] ?? "1970-01-01") <=> strtotime($a["generated_at"] ?? "1970-01-01");
});

$recentReports = array_slice($recentReports, 0, 10);

if ($showPreview) {
    try {
        if ($selectedPeriod === "custom_range") {
            if ($selectedStartDate === "" || $selectedEndDate === "") {
                throw new Exception("Please select both start and end dates.");
            }

            if ($selectedStartDate > $selectedEndDate) {
                throw new Exception("Start date cannot be after the end date.");
            }
        }

        [$startDate, $endDate] = cr_date_range($selectedPeriod, $selectedStartDate, $selectedEndDate);
        $previewReport = cr_build_report(
            $conn,
            $selectedReportType,
            $selectedPeriod,
            $startDate,
            $endDate,
            $selectedCityCorId,
            $selectedThanaId,
            $selectedWardId,
            $selectedAreaId
        );
    } catch (Exception $e) {
        $previewError = $e->getMessage();
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
    <link rel="stylesheet" href="../../css/central/reports.css">
    <link rel="stylesheet" href="../../css/central/centralTextFix.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="central">
<div class="dg-central-layout">
    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">
        <?php include "../../includes/central/topbar.php"; ?>

        <section class="reports-page">
            <div class="reports-header">
                <h1>Reports & Analytics</h1>
            </div>

            <?php if ($successMessage !== ""): ?>
                <div class="reports-alert success">
                    <i class="bi bi-check-circle"></i>
                    <?php echo cr_safe($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== "" || $previewError !== ""): ?>
                <div class="reports-alert error">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo cr_safe($errorMessage !== "" ? $errorMessage : $previewError); ?>
                </div>
            <?php endif; ?>

            <form method="GET" action="reports.php" class="reports-builder-card" id="reportBuilderForm">
                <input type="hidden" name="preview" value="1">

                <div class="reports-builder-title">
                    <h2>Central Report Builder</h2>
                </div>

                <div class="reports-builder-grid">
                    <div class="reports-form-group">
                        <label for="report_type">Report Type</label>
                        <select id="report_type" name="report_type" required>
                            <?php foreach ($reportTypes as $type => $label): ?>
                                <option value="<?php echo cr_safe($type); ?>" <?php echo $selectedReportType === $type ? "selected" : ""; ?>>
                                    <?php echo cr_safe($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="reports-form-group">
                        <label for="report_period">Time Period</label>
                        <select id="report_period" name="report_period" required>
                            <?php foreach ($validPeriods as $period): ?>
                                <option value="<?php echo cr_safe($period); ?>" <?php echo $selectedPeriod === $period ? "selected" : ""; ?>>
                                    <?php echo cr_safe(cr_period_label($period)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="reports-form-group custom-date-field" hidden>
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo cr_safe($selectedStartDate); ?>">
                    </div>

                    <div class="reports-form-group custom-date-field" hidden>
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo cr_safe($selectedEndDate); ?>">
                    </div>

                    <div class="reports-form-group">
                        <label for="city_cor_id">City Corporation</label>
                        <select id="city_cor_id" name="city_cor_id">
                            <option value="">All City Corporations</option>
                            <?php foreach ($citiesCorporations as $cityCor): ?>
                                <option value="<?php echo (int)$cityCor["city_cor_id"]; ?>" <?php echo $selectedCityCorId === (int)$cityCor["city_cor_id"] ? "selected" : ""; ?>>
                                    <?php echo cr_safe($cityCor["city_cor_name"]); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="reports-form-group">
                        <label for="thana_id">Thana</label>
                        <select id="thana_id" name="thana_id" <?php echo $selectedCityCorId > 0 ? "" : "disabled"; ?>>
                            <option value="">All Thanas</option>
                        </select>
                    </div>

                    <div class="reports-form-group">
                        <label for="ward_id">Ward</label>
                        <select id="ward_id" name="ward_id" <?php echo $selectedThanaId > 0 ? "" : "disabled"; ?>>
                            <option value="">All Wards</option>
                        </select>
                    </div>

                    <div class="reports-form-group">
                        <label for="area_id">Area</label>
                        <select id="area_id" name="area_id" <?php echo $selectedWardId > 0 ? "" : "disabled"; ?>>
                            <option value="">All Areas</option>
                        </select>
                    </div>

                    <div class="reports-form-group">
                        <label for="export_format">Export Format</label>
                        <select id="export_format" name="export_format" required>
                            <?php foreach ($validFormats as $format): ?>
                                <option value="<?php echo cr_safe($format); ?>" <?php echo $selectedFormat === $format ? "selected" : ""; ?>>
                                    <?php echo cr_safe($format); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="reports-action-row">
                    <button type="submit" class="reports-generate-btn">
                        <i class="bi bi-eye"></i>
                        Generate & Preview
                    </button>
                </div>
            </form>

            <?php if ($previewReport): ?>
                <div class="reports-preview-card">
                    <div class="reports-preview-toolbar">
                        <h2>Report Preview</h2>
                        <form
                            method="POST"
                            action="../../auth/generate_report_process.php"
                            class="reports-preview-download-form"
                            target="centralReportDownloadFrame"
                        >
                            <input type="hidden" name="report_type" value="<?php echo cr_safe($selectedReportType); ?>">
                            <input type="hidden" name="report_period" value="<?php echo cr_safe($selectedPeriod); ?>">
                            <input type="hidden" name="start_date" value="<?php echo cr_safe($selectedStartDate); ?>">
                            <input type="hidden" name="end_date" value="<?php echo cr_safe($selectedEndDate); ?>">
                            <input type="hidden" name="city_cor_id" value="<?php echo (int)$selectedCityCorId; ?>">
                            <input type="hidden" name="thana_id" value="<?php echo (int)$selectedThanaId; ?>">
                            <input type="hidden" name="ward_id" value="<?php echo (int)$selectedWardId; ?>">
                            <input type="hidden" name="area_id" value="<?php echo (int)$selectedAreaId; ?>">
                            <input type="hidden" name="export_format" value="<?php echo cr_safe($selectedFormat); ?>">
                            <button type="submit" class="reports-download-btn">
                                <i class="bi bi-download"></i>
                                Download
                            </button>
                        </form>
                    </div>

                    <?php echo cr_render_report_html($previewReport); ?>
                </div>
            <?php endif; ?>

            <div class="reports-table-card">
                <div class="reports-table-header">
                    <h2>Recent Generated Files</h2>
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
                                        <td><strong><?php echo cr_safe($report["report_name"]); ?></strong></td>
                                        <td><span class="reports-badge"><?php echo cr_safe(cr_report_type_label($report["report_type"])); ?></span></td>
                                        <td><?php echo cr_safe(cr_period_label($report["report_period"])); ?></td>
                                        <td><?php echo cr_safe($report["export_format"]); ?></td>
                                        <td><?php echo cr_safe(date("M d, Y h:i A", strtotime($report["generated_at"]))); ?></td>
                                        <td><?php echo cr_safe($report["generated_by_name"] ?? "Central Officer"); ?></td>
                                        <td>
                                            <?php if ($downloadPath !== ""): ?>
                                                <a href="<?php echo cr_safe($downloadPath); ?>" class="reports-download-link" download>
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
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

<iframe
    name="centralReportDownloadFrame"
    id="centralReportDownloadFrame"
    class="reports-download-frame"
    title="Central report download"
></iframe>

<script>
    window.reportFilterData = {
        selected: {
            thanaId: "<?php echo (int)$selectedThanaId; ?>",
            wardId: "<?php echo (int)$selectedWardId; ?>",
            areaId: "<?php echo (int)$selectedAreaId; ?>"
        },
        thanas: <?php echo json_encode($thanas, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        wards: <?php echo json_encode($wards, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
        areas: <?php echo json_encode($areas, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
    };
</script>
<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/reports.js"></script>
<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>
