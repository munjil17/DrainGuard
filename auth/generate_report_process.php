<?php
require_once "../config.php";
require_once "../includes/central/central_report_helpers.php";

$allowed_role = "central_officer";
require_once "session_check.php";

function cr_redirect_error($message)
{
    $_SESSION["report_error"] = $message;
    header("Location: ../pages/central/reports.php");
    exit();
}

function cr_download_file($serverPath, $downloadName, $format)
{
    if (!file_exists($serverPath)) {
        cr_redirect_error("Unable to create the report. Please try again.");
    }

    if (ob_get_length()) {
        ob_end_clean();
    }

    header("Content-Description: File Transfer");
    header("Content-Type: " . cr_export_mime($format));
    header("Content-Disposition: attachment; filename=\"" . $downloadName . "\"");
    header("Content-Transfer-Encoding: binary");
    header("Content-Length: " . filesize($serverPath));
    header("Cache-Control: must-revalidate");
    header("Pragma: public");
    header("Expires: 0");

    readfile($serverPath);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    cr_redirect_error("Invalid request. Please try again.");
}

$userId = (int)($_SESSION["user_id"] ?? 0);
if ($userId <= 0) {
    cr_redirect_error("Your session has expired. Please sign in again.");
}

$reportType = trim($_POST["report_type"] ?? "");
$reportPeriod = trim($_POST["report_period"] ?? "");
$exportFormat = trim($_POST["export_format"] ?? "PDF");
$cityCorId = (int)($_POST["city_cor_id"] ?? 0);
$thanaId = (int)($_POST["thana_id"] ?? 0);
$wardId = (int)($_POST["ward_id"] ?? 0);
$areaId = (int)($_POST["area_id"] ?? 0);
$customStart = trim($_POST["start_date"] ?? "");
$customEnd = trim($_POST["end_date"] ?? "");

$validPeriods = ["last_7_days", "last_30_days", "last_3_months", "last_6_months", "this_year", "custom_range"];
$validFormats = ["PDF", "Excel", "DOCS", "CSV"];

if (!array_key_exists($reportType, cr_report_types())) {
    cr_redirect_error("Please select a valid report type.");
}

if (!in_array($reportPeriod, $validPeriods, true)) {
    cr_redirect_error("Please select a valid time period.");
}

if (!in_array($exportFormat, $validFormats, true)) {
    cr_redirect_error("Please select a valid export format.");
}

if ($reportPeriod === "custom_range") {
    if ($customStart === "" || $customEnd === "") {
        cr_redirect_error("Please select both start and end dates.");
    }

    if ($customStart > $customEnd) {
        cr_redirect_error("Start date cannot be after the end date.");
    }
}

[$startDate, $endDate] = cr_date_range($reportPeriod, $customStart, $customEnd);

try {
    $report = cr_build_report(
        $conn,
        $reportType,
        $reportPeriod,
        $startDate,
        $endDate,
        $cityCorId,
        $thanaId,
        $wardId,
        $areaId
    );

    $reportDir = "../assets/reports/central";
    if (!is_dir($reportDir)) {
        mkdir($reportDir, 0777, true);
    }

    $extension = cr_export_extension($exportFormat);
    $fileName = cr_safe_filename($reportType) . "_" . date("Ymd_His") . "." . $extension;
    $serverPath = $reportDir . "/" . $fileName;

    cr_write_export_file($serverPath, $exportFormat, $report);
    cr_download_file($serverPath, $fileName, $exportFormat);
} catch (Exception $e) {
    cr_redirect_error($e->getMessage());
}
