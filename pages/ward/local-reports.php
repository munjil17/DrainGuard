<?php
require_once "../../config.php";
require_once "../../includes/ward/ward_report_helpers.php";

$allowed_role = "ward_officer";
require_once "../../auth/session_check.php";

$activePage = "local-reports";
$pageTitle = "Local Reports";

if (!isset($conn) || !$conn) {
    die("Service is temporarily unavailable. Please try again.");
}

$currentUserId = (int)($_SESSION["user_id"] ?? 0);
$reportTypes = wr_report_types();
$validPeriods = ["last_7_days", "last_30_days", "last_3_months", "last_6_months", "this_year", "custom_range"];
$validFormats = ["PDF", "Excel", "DOCS", "CSV"];
$validStatuses = [
    "" => "All Statuses",
    "pending_verification" => "Pending Verification",
    "verified_by_ward" => "Verified by Ward",
    "team_assigned" => "Team Assigned",
    "in_progress" => "In Progress",
    "solved_by_team" => "Solved by Team",
    "closed" => "Closed",
    "reopened" => "Reopened",
    "disputed" => "Disputed",
    "rejected_by_ward" => "Rejected by Ward",
    "duplicate" => "Duplicate",
];
$validPriorities = [
    "" => "All Priorities",
    "High" => "High",
    "Medium" => "Medium",
    "Low" => "Low",
];

$successMessage = "";
$errorMessage = "";
$previewError = "";
$previewReport = null;
$areas = [];
$recentReports = [];

try {
    $wardContext = wr_get_ward_context($conn, $currentUserId);
    $_SESSION["user_name"] = $wardContext["full_name"];
    $_SESSION["user_role_label"] = "Ward Operations";

    $areas = wr_fetch_all($conn, "
        SELECT area_id, area_name
        FROM areas
        WHERE ward_id = ?
        ORDER BY area_name ASC
    ", "i", [$wardContext["ward_id"]]);
} catch (Exception $e) {
    die($e->getMessage());
}

$selectedReportType = trim($_REQUEST["report_type"] ?? "ward_complaint_summary");
$selectedPeriod = trim($_REQUEST["report_period"] ?? "last_30_days");
$selectedFormat = trim($_REQUEST["export_format"] ?? "PDF");
$selectedAreaId = (int)($_REQUEST["area_id"] ?? 0);
$selectedStatus = trim($_REQUEST["status"] ?? "");
$selectedPriority = trim($_REQUEST["priority"] ?? "");
$selectedStartDate = trim($_REQUEST["start_date"] ?? "");
$selectedEndDate = trim($_REQUEST["end_date"] ?? "");
$showPreview = isset($_GET["preview"]);

if (!array_key_exists($selectedReportType, $reportTypes)) {
    $selectedReportType = "ward_complaint_summary";
}

if (!in_array($selectedPeriod, $validPeriods, true)) {
    $selectedPeriod = "last_30_days";
}

if (!in_array($selectedFormat, $validFormats, true)) {
    $selectedFormat = "PDF";
}

if (!array_key_exists($selectedStatus, $validStatuses)) {
    $selectedStatus = "";
}

if (!array_key_exists($selectedPriority, $validPriorities)) {
    $selectedPriority = "";
}

function lr_validate_area($conn, $wardId, $areaId)
{
    if ((int)$areaId <= 0) return 0;
    $row = wr_fetch_one($conn, "SELECT area_id FROM areas WHERE area_id = ? AND ward_id = ? LIMIT 1", "ii", [(int)$areaId, (int)$wardId]);
    if (!$row) {
        throw new Exception("Invalid area selected for your assigned ward.");
    }

    return (int)$areaId;
}

function lr_build_selected_report($conn, $wardContext, $reportType, $period, $startDate, $endDate, $areaId, $status, $priority)
{
    if ($period === "custom_range") {
        if ($startDate === "" || $endDate === "") {
            throw new Exception("Please select both start and end dates.");
        }

        if ($startDate > $endDate) {
            throw new Exception("Start date cannot be after the end date.");
        }
    }

    [$rangeStart, $rangeEnd] = wr_date_range($period, $startDate, $endDate);

    return wr_build_report(
        $conn,
        $reportType,
        $period,
        $rangeStart,
        $rangeEnd,
        $wardContext,
        $areaId,
        $status,
        $priority
    );
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        $downloadReportType = trim($_POST["report_type"] ?? "");
        $downloadPeriod = trim($_POST["report_period"] ?? "");
        $downloadFormat = trim($_POST["export_format"] ?? "");
        $downloadAreaId = lr_validate_area($conn, $wardContext["ward_id"], (int)($_POST["area_id"] ?? 0));
        $downloadStatus = trim($_POST["status"] ?? "");
        $downloadPriority = trim($_POST["priority"] ?? "");
        $downloadStart = trim($_POST["start_date"] ?? "");
        $downloadEnd = trim($_POST["end_date"] ?? "");

        if (!array_key_exists($downloadReportType, $reportTypes)) {
            throw new Exception("Please select a valid report type.");
        }

        if (!in_array($downloadPeriod, $validPeriods, true)) {
            throw new Exception("Please select a valid time period.");
        }

        if (!in_array($downloadFormat, $validFormats, true)) {
            throw new Exception("Please select a valid export format.");
        }

        if (!array_key_exists($downloadStatus, $validStatuses)) {
            throw new Exception("Please select a valid status.");
        }

        if (!array_key_exists($downloadPriority, $validPriorities)) {
            throw new Exception("Please select a valid priority.");
        }

        $report = lr_build_selected_report(
            $conn,
            $wardContext,
            $downloadReportType,
            $downloadPeriod,
            $downloadStart,
            $downloadEnd,
            $downloadAreaId,
            $downloadStatus,
            $downloadPriority
        );

        $reportDir = __DIR__ . "/../../assets/reports/ward";
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0777, true);
        }

        $extension = wr_export_extension($downloadFormat);
        $fileName = wr_safe_filename($downloadReportType . "_ward_" . $wardContext["ward_no"]) . "_" . date("Ymd_His") . "." . $extension;
        $filePath = $reportDir . "/" . $fileName;

        wr_write_export_file($filePath, $downloadFormat, $report);

        header("Content-Type: " . wr_export_mime($downloadFormat));
        header("Content-Disposition: attachment; filename=\"" . $fileName . "\"");
        header("Content-Length: " . filesize($filePath));
        header("Cache-Control: no-store, no-cache, must-revalidate");
        readfile($filePath);
        exit();
    } catch (Exception $e) {
        http_response_code(400);
        header("Content-Type: text/plain; charset=UTF-8");
        echo $e->getMessage();
        exit();
    }
}

try {
    $selectedAreaId = lr_validate_area($conn, $wardContext["ward_id"], $selectedAreaId);
} catch (Exception $e) {
    $selectedAreaId = 0;
    $previewError = $e->getMessage();
}

if ($showPreview && $previewError === "") {
    try {
        $previewReport = lr_build_selected_report(
            $conn,
            $wardContext,
            $selectedReportType,
            $selectedPeriod,
            $selectedStartDate,
            $selectedEndDate,
            $selectedAreaId,
            $selectedStatus,
            $selectedPriority
        );
    } catch (Exception $e) {
        $previewError = $e->getMessage();
    }
}

$wardReportDir = __DIR__ . "/../../assets/reports/ward";
if (is_dir($wardReportDir)) {
    foreach (glob($wardReportDir . "/*.{pdf,csv,xls,doc}", GLOB_BRACE) ?: [] as $file) {
        $baseName = basename($file);
        $typeKey = "";

        foreach (array_keys($reportTypes) as $candidateType) {
            if (str_starts_with($baseName, $candidateType . "_")) {
                $typeKey = $candidateType;
                break;
            }
        }

        if ($typeKey === "") continue;

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $format = "PDF";
        if ($extension === "csv") $format = "CSV";
        if ($extension === "xls") $format = "Excel";
        if ($extension === "doc") $format = "DOCS";

        $recentReports[] = [
            "report_name" => wr_report_type_label($typeKey),
            "report_type" => $typeKey,
            "report_period" => "generated_export",
            "export_format" => $format,
            "file_path" => "../../assets/reports/ward/" . $baseName,
            "generated_at" => date("Y-m-d H:i:s", filemtime($file)),
        ];
    }
}

usort($recentReports, function ($a, $b) {
    return strtotime($b["generated_at"] ?? "1970-01-01") <=> strtotime($a["generated_at"] ?? "1970-01-01");
});
$recentReports = array_slice($recentReports, 0, 10);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Local Reports | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
    <link rel="stylesheet" href="../../css/ward/local-reports.css">
    <link rel="stylesheet" href="../../css/ward/wardTextFix.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="ward">
<?php include "../../includes/ward/sidebar.php"; ?>

<main class="ward-main">
    <?php include "../../includes/ward/topbar.php"; ?>

    <section class="lr-page">
        <div class="lr-header">
            <h1>Local Reports</h1>
        </div>

        <?php if ($successMessage !== ""): ?>
            <div class="lr-alert lr-success">
                <i class="bi bi-check-circle"></i>
                <?= wr_safe($successMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== "" || $previewError !== ""): ?>
            <div class="lr-alert lr-error">
                <i class="bi bi-exclamation-circle"></i>
                <?= wr_safe($errorMessage !== "" ? $errorMessage : $previewError); ?>
            </div>
        <?php endif; ?>

        <form method="GET" action="local-reports.php" class="lr-form-card" id="localReportForm">
            <input type="hidden" name="preview" value="1">

            <div class="lr-form-title">
                <h2>Ward Local Report Builder</h2>
            </div>

            <div class="lr-form-grid">
                <div class="lr-form-group">
                    <label for="report_type">Report Type</label>
                    <select id="report_type" name="report_type" required>
                        <?php foreach ($reportTypes as $type => $label): ?>
                            <option value="<?= wr_safe($type); ?>" <?= $selectedReportType === $type ? "selected" : ""; ?>>
                                <?= wr_safe($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lr-form-group">
                    <label for="report_period">Time Period</label>
                    <select id="report_period" name="report_period" required>
                        <?php foreach ($validPeriods as $period): ?>
                            <option value="<?= wr_safe($period); ?>" <?= $selectedPeriod === $period ? "selected" : ""; ?>>
                                <?= wr_safe(wr_period_label($period)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lr-form-group custom-date-field" hidden>
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?= wr_safe($selectedStartDate); ?>">
                </div>

                <div class="lr-form-group custom-date-field" hidden>
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?= wr_safe($selectedEndDate); ?>">
                </div>

                <div class="lr-form-group">
                    <label for="area_id">Area</label>
                    <select id="area_id" name="area_id">
                        <option value="0">All Areas</option>
                        <?php foreach ($areas as $area): ?>
                            <option value="<?= (int)$area["area_id"]; ?>" <?= $selectedAreaId === (int)$area["area_id"] ? "selected" : ""; ?>>
                                <?= wr_safe($area["area_name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lr-form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <?php foreach ($validStatuses as $statusValue => $statusLabel): ?>
                            <option value="<?= wr_safe($statusValue); ?>" <?= $selectedStatus === $statusValue ? "selected" : ""; ?>>
                                <?= wr_safe($statusLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lr-form-group">
                    <label for="priority">Priority / Risk Level</label>
                    <select id="priority" name="priority">
                        <?php foreach ($validPriorities as $priorityValue => $priorityLabel): ?>
                            <option value="<?= wr_safe($priorityValue); ?>" <?= $selectedPriority === $priorityValue ? "selected" : ""; ?>>
                                <?= wr_safe($priorityLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lr-form-group">
                    <label for="export_format">Export Format</label>
                    <select id="export_format" name="export_format" required>
                        <?php foreach ($validFormats as $format): ?>
                            <option value="<?= wr_safe($format); ?>" <?= $selectedFormat === $format ? "selected" : ""; ?>>
                                <?= wr_safe($format); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="lr-action-row">
                <button type="submit" class="lr-generate-btn">
                    <i class="bi bi-eye"></i>
                    Generate & Preview
                </button>
            </div>
        </form>

        <?php if ($previewReport): ?>
            <div class="lr-preview-card">
                <div class="lr-preview-toolbar">
                    <h2>Report Preview</h2>
                    <form
                        method="POST"
                        action="local-reports.php"
                        class="lr-preview-download-form"
                        target="wardReportDownloadFrame"
                    >
                        <input type="hidden" name="report_type" value="<?= wr_safe($selectedReportType); ?>">
                        <input type="hidden" name="report_period" value="<?= wr_safe($selectedPeriod); ?>">
                        <input type="hidden" name="start_date" value="<?= wr_safe($selectedStartDate); ?>">
                        <input type="hidden" name="end_date" value="<?= wr_safe($selectedEndDate); ?>">
                        <input type="hidden" name="area_id" value="<?= (int)$selectedAreaId; ?>">
                        <input type="hidden" name="status" value="<?= wr_safe($selectedStatus); ?>">
                        <input type="hidden" name="priority" value="<?= wr_safe($selectedPriority); ?>">
                        <input type="hidden" name="export_format" value="<?= wr_safe($selectedFormat); ?>">
                        <button type="submit" class="lr-download-btn">
                            <i class="bi bi-download"></i>
                            Download
                        </button>
                    </form>
                </div>

                <?= wr_render_report_html($previewReport); ?>
            </div>
        <?php endif; ?>

        <div class="lr-recent-card">
            <div class="lr-table-header">
                <h2>Recent Generated Files</h2>
            </div>

            <div class="lr-table-responsive">
                <table class="lr-table">
                    <thead>
                        <tr>
                            <th>Report Name</th>
                            <th>Type</th>
                            <th>Format</th>
                            <th>Generated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!empty($recentReports)): ?>
                            <?php foreach ($recentReports as $report): ?>
                                <tr>
                                    <td><strong><?= wr_safe($report["report_name"]); ?></strong></td>
                                    <td><span class="lr-type-badge"><?= wr_safe(wr_report_type_label($report["report_type"])); ?></span></td>
                                    <td><?= wr_safe($report["export_format"]); ?></td>
                                    <td><?= wr_safe(date("M d, Y h:i A", strtotime($report["generated_at"]))); ?></td>
                                    <td>
                                        <a href="<?= wr_safe($report["file_path"]); ?>" download class="lr-download-link">
                                            <i class="bi bi-download"></i>
                                            Download
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5">
                                    <div class="lr-empty">
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

<iframe
    name="wardReportDownloadFrame"
    id="wardReportDownloadFrame"
    class="lr-download-frame"
    title="Ward report download"
></iframe>

<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/local-reports.js"></script>
<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>
