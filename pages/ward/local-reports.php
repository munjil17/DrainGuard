<?php
$activePage = "local-reports";
$pageTitle = "Local Reports";

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
$successMessage = "";
$errorMessage = "";
$autoDownloadPath = "";

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

function reportTypeLabel($type)
{
    $labels = [
        "ward_complaint_summary" => "Ward Complaint Summary",
        "area_complaint_analysis" => "Area Analysis",
        "maintenance_team_assignment" => "Team Assignment",
        "in_progress_delayed_work" => "In Progress Work",
        "risk_zone_report" => "Risk Analysis",
        "reopened_disputed_cases" => "Reopened Cases",
        "verification_performance" => "Verification Report"
    ];

    return $labels[$type] ?? ucwords(str_replace("_", " ", (string)$type));
}

function reportTypeBadgeLabel($type)
{
    $labels = [
        "ward_complaint_summary" => "Ward Report",
        "area_complaint_analysis" => "Area Report",
        "maintenance_team_assignment" => "Team Report",
        "in_progress_delayed_work" => "Work Report",
        "risk_zone_report" => "Risk Analysis",
        "reopened_disputed_cases" => "Reopen Report",
        "verification_performance" => "Verify Report"
    ];

    return $labels[$type] ?? "Ward Report";
}

function periodLabel($period)
{
    $labels = [
        "last_7_days" => "Last 7 Days",
        "last_30_days" => "Last 30 Days",
        "last_3_months" => "Last 3 Months",
        "last_6_months" => "Last 6 Months",
        "this_year" => "This Year",
        "custom_range" => "Custom Range"
    ];

    return $labels[$period] ?? ucwords(str_replace("_", " ", (string)$period));
}

function getDateRangeByPeriod($period, $customStart, $customEnd)
{
    $today = date("Y-m-d");

    if ($period === "last_7_days") {
        return [date("Y-m-d", strtotime("-7 days")), $today];
    }

    if ($period === "last_30_days") {
        return [date("Y-m-d", strtotime("-30 days")), $today];
    }

    if ($period === "last_3_months") {
        return [date("Y-m-d", strtotime("-3 months")), $today];
    }

    if ($period === "last_6_months") {
        return [date("Y-m-d", strtotime("-6 months")), $today];
    }

    if ($period === "this_year") {
        return [date("Y-01-01"), $today];
    }

    return [$customStart, $customEnd];
}

function formatDateTime($date)
{
    if (!$date) {
        return "N/A";
    }

    $time = strtotime($date);

    if (!$time) {
        return "N/A";
    }

    return date("M d, g:i A", $time);
}

function formatPeriodForTable($period, $startDate, $endDate)
{
    if ($period === "custom_range" && $startDate && $endDate) {
        return date("M d", strtotime($startDate)) . " - " . date("M d, Y", strtotime($endDate));
    }

    if ($period === "last_7_days") return "Last 7 days";
    if ($period === "last_30_days") return "Last 30 days";
    if ($period === "last_3_months") return "Last 3 months";
    if ($period === "last_6_months") return "Last 6 months";
    if ($period === "this_year") return date("Y");

    return periodLabel($period);
}

function buildReportRows($conn, $reportType, $wardId, $areaId, $startDate, $endDate)
{
    $params = [$wardId, $startDate . " 00:00:00", $endDate . " 23:59:59"];
    $types = "iss";
    $areaCondition = "";

    if ($areaId !== null && $areaId > 0) {
        $areaCondition = " AND l.area_id = ? ";
        $params[] = $areaId;
        $types .= "i";
    }

    if ($reportType === "ward_complaint_summary") {
        $sql = "
            SELECT
                c.complaint_code AS `Complaint ID`,
                COALESCE(i.issue_name, 'N/A') AS `Issue`,
                COALESCE(a.area_name, 'N/A') AS `Area`,
                c.complaint_status AS `Status`,
                DATE_FORMAT(c.submitted_at, '%b %d, %Y') AS `Submitted`
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            WHERE l.ward_id = ?
            AND c.submitted_at BETWEEN ? AND ?
            $areaCondition
            ORDER BY c.submitted_at DESC
        ";

        return fetchAllRows($conn, $sql, $types, $params);
    }

    if ($reportType === "area_complaint_analysis") {
        $sql = "
            SELECT
                COALESCE(a.area_name, 'N/A') AS `Area`,
                COUNT(c.complaint_id) AS `Total Complaints`,
                SUM(CASE WHEN c.complaint_status IN ('closed', 'solved_by_team') THEN 1 ELSE 0 END) AS `Solved`,
                SUM(CASE WHEN c.complaint_status IN ('reopened', 'disputed') THEN 1 ELSE 0 END) AS `Reopened/Disputed`
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            WHERE l.ward_id = ?
            AND c.submitted_at BETWEEN ? AND ?
            $areaCondition
            GROUP BY l.area_id, a.area_name
            ORDER BY COUNT(c.complaint_id) DESC
        ";

        return fetchAllRows($conn, $sql, $types, $params);
    }

    if ($reportType === "maintenance_team_assignment") {
        $sql = "
            SELECT
                COALESCE(mt.team_name, 'N/A') AS `Team`,
                COUNT(ca.assignment_id) AS `Assigned Tasks`,
                SUM(CASE WHEN ca.assignment_status = 'in_progress' THEN 1 ELSE 0 END) AS `In Progress`,
                SUM(CASE WHEN c.complaint_status IN ('closed', 'solved_by_team') THEN 1 ELSE 0 END) AS `Completed`,
                SUM(CASE WHEN ca.deadline_at < CURDATE() AND c.complaint_status NOT IN ('closed', 'solved_by_team') THEN 1 ELSE 0 END) AS `Delayed`
            FROM complaint_assignments ca
            INNER JOIN complaints c ON ca.complaint_id = c.complaint_id
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN maintenance_teams mt ON ca.maintenance_team_id = mt.maintenance_team_id
            WHERE l.ward_id = ?
            AND ca.assigned_at BETWEEN ? AND ?
            $areaCondition
            GROUP BY ca.maintenance_team_id, mt.team_name
            ORDER BY COUNT(ca.assignment_id) DESC
        ";

        return fetchAllRows($conn, $sql, $types, $params);
    }

    if ($reportType === "in_progress_delayed_work") {
        $sql = "
            SELECT
                c.complaint_code AS `Complaint ID`,
                COALESCE(i.issue_name, 'N/A') AS `Issue`,
                COALESCE(a.area_name, 'N/A') AS `Area`,
                COALESCE(mt.team_name, 'N/A') AS `Team`,
                ca.deadline_at AS `Deadline`,
                CASE
                    WHEN ca.deadline_at < CURDATE() THEN 'Delayed'
                    ELSE 'On Schedule'
                END AS `Schedule Status`
            FROM complaint_assignments ca
            INNER JOIN complaints c ON ca.complaint_id = c.complaint_id
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            LEFT JOIN maintenance_teams mt ON ca.maintenance_team_id = mt.maintenance_team_id
            WHERE l.ward_id = ?
            AND ca.assigned_at BETWEEN ? AND ?
            AND c.complaint_status IN ('team_assigned', 'in_progress')
            $areaCondition
            ORDER BY ca.deadline_at ASC
        ";

        return fetchAllRows($conn, $sql, $types, $params);
    }

    if ($reportType === "risk_zone_report") {
        $params = [$wardId];
        $types = "i";
        $areaCondition = "";

        if ($areaId !== null && $areaId > 0) {
            $areaCondition = " AND r.area_id = ? ";
            $params[] = $areaId;
            $types .= "i";
        }

        $sql = "
            SELECT
                COALESCE(a.area_name, 'N/A') AS `Area`,
                r.urgency_level AS `Risk Level`,
                r.complaint_count_7_days AS `7 Days`,
                r.complaint_count_30_days AS `30 Days`,
                r.complaint_count_this_week AS `This Week`,
                DATE_FORMAT(r.last_reported_at, '%b %d, %Y') AS `Last Incident`
            FROM risk r
            LEFT JOIN areas a ON r.area_id = a.area_id
            WHERE r.ward_id = ?
            AND r.risk_status = 'Active'
            $areaCondition
            ORDER BY r.complaint_count_30_days DESC
        ";

        return fetchAllRows($conn, $sql, $types, $params);
    }

    if ($reportType === "reopened_disputed_cases") {
        $sql = "
            SELECT
                c.complaint_code AS `Complaint ID`,
                COALESCE(i.issue_name, 'N/A') AS `Issue`,
                COALESCE(a.area_name, 'N/A') AS `Area`,
                rr.request_type AS `Request Type`,
                rr.reason AS `Reason`,
                rr.request_status AS `Request Status`
            FROM reopen_requests rr
            INNER JOIN complaints c ON rr.complaint_id = c.complaint_id
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE l.ward_id = ?
            AND rr.created_at BETWEEN ? AND ?
            $areaCondition
            ORDER BY rr.created_at DESC
        ";

        return fetchAllRows($conn, $sql, $types, $params);
    }

    if ($reportType === "verification_performance") {
        $sql = "
            SELECT
                c.complaint_code AS `Complaint ID`,
                COALESCE(i.issue_name, 'N/A') AS `Issue`,
                COALESCE(a.area_name, 'N/A') AS `Area`,
                c.complaint_status AS `Verification Status`,
                DATE_FORMAT(c.updated_at, '%b %d, %Y') AS `Last Updated`
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE l.ward_id = ?
            AND c.updated_at BETWEEN ? AND ?
            $areaCondition
            AND c.complaint_status IN ('pending_verification', 'verified', 'rejected', 'duplicate')
            ORDER BY c.updated_at DESC
        ";

        return fetchAllRows($conn, $sql, $types, $params);
    }

    return [];
}

function saveReportFile($reportRows, $reportName, $exportFormat, $reportDirAbsolute, $reportDirRelative)
{
    if (!is_dir($reportDirAbsolute)) {
        mkdir($reportDirAbsolute, 0777, true);
    }

    $safeName = preg_replace("/[^A-Za-z0-9_\-]/", "_", strtolower($reportName));
    $timestamp = date("Ymd_His");

    if ($exportFormat === "CSV") {
        $fileName = $safeName . "_" . $timestamp . ".csv";
        $filePath = $reportDirAbsolute . "/" . $fileName;

        $fp = fopen($filePath, "w");

        if (!empty($reportRows)) {
            fputcsv($fp, array_keys($reportRows[0]));

            foreach ($reportRows as $row) {
                fputcsv($fp, $row);
            }
        } else {
            fputcsv($fp, ["Message"]);
            fputcsv($fp, ["No data found for selected report."]);
        }

        fclose($fp);

        return $reportDirRelative . "/" . $fileName;
    }

    $tableHtml = "<table border='1' cellspacing='0' cellpadding='8' style='border-collapse:collapse;width:100%;font-family:Arial;font-size:13px;'>";
    $tableHtml .= "<thead><tr style='background:#f1f5f9;'>";

    if (!empty($reportRows)) {
        foreach (array_keys($reportRows[0]) as $heading) {
            $tableHtml .= "<th>" . htmlspecialchars($heading) . "</th>";
        }

        $tableHtml .= "</tr></thead><tbody>";

        foreach ($reportRows as $row) {
            $tableHtml .= "<tr>";

            foreach ($row as $value) {
                $tableHtml .= "<td>" . htmlspecialchars((string)$value) . "</td>";
            }

            $tableHtml .= "</tr>";
        }
    } else {
        $tableHtml .= "<th>Message</th></tr></thead><tbody><tr><td>No data found for selected report.</td></tr>";
    }

    $tableHtml .= "</tbody></table>";

   $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>" . htmlspecialchars($reportName) . "</title>
        <link rel='stylesheet' href='../../css/global/confirm-modal.css'>
    </head>
    <body style='font-family:Arial, sans-serif;padding:24px;color:#0f172a;'>
        <h2 style='margin-bottom:6px;'>" . htmlspecialchars($reportName) . "</h2>
        <p style='color:#475569;margin-top:0;'>Generated by DrainGuard Ward Officer Panel</p>
        $tableHtml
        <script src='../../js/global/confirm-modal.js'></script>
    </body>
    </html>
";

    if ($exportFormat === "XLSX") {
        $fileName = $safeName . "_" . $timestamp . ".xls";
    } elseif ($exportFormat === "DOCX") {
        $fileName = $safeName . "_" . $timestamp . ".doc";
    } else {
        $fileName = $safeName . "_" . $timestamp . ".html";
    }

    $filePath = $reportDirAbsolute . "/" . $fileName;
    file_put_contents($filePath, $html);

    return $reportDirRelative . "/" . $fileName;
}

/*
|--------------------------------------------------------------------------
| Get logged-in Ward Officer assigned ward
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
            w.ward_name,
            w.city_cor_id,
            w.thana_id,

            cc.city_cor_name,
            t.thana_name

        FROM ward_officers wo

        INNER JOIN wards w
            ON wo.assigned_ward_id = w.ward_id

        LEFT JOIN city_corporations cc
            ON w.city_cor_id = cc.city_cor_id

        LEFT JOIN thanas t
            ON w.thana_id = t.thana_id

        WHERE wo.user_id = ?
        LIMIT 1",
        "i",
        [$currentUserId]
    );

    if (!$wardOfficer) {
        throw new Exception("No assigned ward found for this Ward Officer.");
    }

    $wardId = (int)$wardOfficer["assigned_ward_id"];
    $cityCorId = (int)($wardOfficer["city_cor_id"] ?? 0);
    $thanaId = (int)($wardOfficer["thana_id"] ?? 0);
    $wardNo = $wardOfficer["ward_no"] ?? "";
    $wardName = $wardOfficer["ward_name"] ?? "";
    $cityCorName = $wardOfficer["city_cor_name"] ?? "City Corporation";
    $thanaName = $wardOfficer["thana_name"] ?? "Thana";
    $userName = $wardOfficer["full_name"] ?? ($_SESSION["user_name"] ?? "Ward Officer");

    $_SESSION["user_name"] = $userName;
    $_SESSION["user_role_label"] = "Ward Operations";
} catch (Exception $e) {
    die($e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Fetch areas under this assigned ward only
|--------------------------------------------------------------------------
*/

try {
    $areas = fetchAllRows(
        $conn,
        "SELECT DISTINCT
            a.area_id,
            a.area_name
        FROM locations l
        INNER JOIN areas a
            ON l.area_id = a.area_id
        WHERE l.ward_id = ?
        ORDER BY a.area_name ASC",
        "i",
        [$wardId]
    );
} catch (Exception $e) {
    $areas = [];
}

/*
|--------------------------------------------------------------------------
| Generate report + auto-download file
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $reportType = trim($_POST["report_type"] ?? "");
    $reportPeriod = trim($_POST["report_period"] ?? "");
    $areaId = (int)($_POST["area_id"] ?? 0);
    $exportFormat = trim($_POST["export_format"] ?? "PDF");
    $customStart = trim($_POST["start_date"] ?? "");
    $customEnd = trim($_POST["end_date"] ?? "");

    $allowedReportTypes = [
        "ward_complaint_summary",
        "area_complaint_analysis",
        "maintenance_team_assignment",
        "in_progress_delayed_work",
        "risk_zone_report",
        "reopened_disputed_cases",
        "verification_performance"
    ];

    $allowedPeriods = [
        "last_7_days",
        "last_30_days",
        "last_3_months",
        "last_6_months",
        "this_year",
        "custom_range"
    ];

    $allowedFormats = ["PDF", "XLSX", "CSV", "DOCX"];

    if (!in_array($reportType, $allowedReportTypes, true)) {
        $errorMessage = "Invalid report type selected.";
    } elseif (!in_array($reportPeriod, $allowedPeriods, true)) {
        $errorMessage = "Invalid report period selected.";
    } elseif (!in_array($exportFormat, $allowedFormats, true)) {
        $errorMessage = "Invalid export format selected.";
    } else {
        try {
            [$startDate, $endDate] = getDateRangeByPeriod($reportPeriod, $customStart, $customEnd);

            if ($reportPeriod === "custom_range") {
                if ($startDate === "" || $endDate === "") {
                    throw new Exception("Please select start date and end date for custom range.");
                }

                if (strtotime($startDate) > strtotime($endDate)) {
                    throw new Exception("Start date cannot be after end date.");
                }
            }

            if ($areaId > 0) {
                $areaCheck = fetchOne(
                    $conn,
                    "SELECT DISTINCT a.area_id
                    FROM locations l
                    INNER JOIN areas a
                        ON l.area_id = a.area_id
                    WHERE l.ward_id = ?
                    AND a.area_id = ?
                    LIMIT 1",
                    "ii",
                    [$wardId, $areaId]
                );

                if (!$areaCheck) {
                    throw new Exception("Invalid area selected for your assigned ward.");
                }
            } else {
                $areaId = null;
            }

            $reportName = reportTypeLabel($reportType) . " Ward " . $wardNo . " " . date("M Y");

            $reportRows = buildReportRows($conn, $reportType, $wardId, $areaId, $startDate, $endDate);

            $reportDirAbsolute = "../../assets/reports/ward";
            $reportDirRelative = "../../assets/reports/ward";
            $filePath = saveReportFile($reportRows, $reportName, $exportFormat, $reportDirAbsolute, $reportDirRelative);

            $insertSql = "
                INSERT INTO ward_generated_reports
                (
                    report_name,
                    report_type,
                    report_period,
                    city_cor_id,
                    thana_id,
                    ward_id,
                    area_id,
                    start_date,
                    end_date,
                    export_format,
                    file_path,
                    generated_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $insertStmt = mysqli_prepare($conn, $insertSql);

            if (!$insertStmt) {
                throw new Exception("Report insert failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param(
                $insertStmt,
                "sssiiiissssi",
                $reportName,
                $reportType,
                $reportPeriod,
                $cityCorId,
                $thanaId,
                $wardId,
                $areaId,
                $startDate,
                $endDate,
                $exportFormat,
                $filePath,
                $currentUserId
            );

            if (!mysqli_stmt_execute($insertStmt)) {
                throw new Exception("Report generation failed: " . mysqli_stmt_error($insertStmt));
            }

            mysqli_stmt_close($insertStmt);

            $successMessage = "Report generated successfully. Download will start automatically.";
            $autoDownloadPath = $filePath;
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Recent generated reports
|--------------------------------------------------------------------------
*/

try {
    $recentReports = fetchAllRows(
        $conn,
        "SELECT
            wr.report_id,
            wr.report_name,
            wr.report_type,
            wr.report_period,
            wr.start_date,
            wr.end_date,
            wr.export_format,
            wr.file_path,
            wr.generated_at,
            a.area_name
        FROM ward_generated_reports wr
        LEFT JOIN areas a
            ON wr.area_id = a.area_id
        WHERE wr.ward_id = ?
        AND wr.generated_by = ?
        ORDER BY wr.generated_at DESC, wr.report_id DESC
        LIMIT 10",
        "ii",
        [$wardId, $currentUserId]
    );
} catch (Exception $e) {
    $recentReports = [];
    $errorMessage = $e->getMessage();
}
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

        <?php if ($successMessage !== ""): ?>
            <div class="lr-alert lr-success">
                <i class="bi bi-check-circle"></i>
                <?= safeText($successMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ""): ?>
            <div class="lr-alert lr-error">
                <i class="bi bi-exclamation-circle"></i>
                <?= safeText($errorMessage); ?>
            </div>
        <?php endif; ?>

        <div class="lr-form-card">
            <h2>Custom Ward Report</h2>

            <form method="POST" action="local-reports.php" id="localReportForm">
                <div class="lr-form-grid">
                    <div class="lr-form-group">
                        <label>Report Type</label>
                        <select name="report_type" required>
                            <option value="">Select report type</option>
                            <option value="ward_complaint_summary">Ward Complaint Summary</option>
                            <option value="area_complaint_analysis">Area Analysis</option>
                            <option value="maintenance_team_assignment">Team Performance</option>
                            <option value="in_progress_delayed_work">In Progress & Delayed Work</option>
                            <option value="risk_zone_report">Risk Zone Report</option>
                            <option value="reopened_disputed_cases">Reopened & Disputed Cases</option>
                            <option value="verification_performance">Verification Performance</option>
                        </select>
                    </div>

                    <div class="lr-form-group">
                        <label>Time Period</label>
                        <select name="report_period" id="reportPeriod" required>
                            <option value="">Select time period</option>
                            <option value="last_7_days">Last 7 days</option>
                            <option value="last_30_days">Last 30 days</option>
                            <option value="last_3_months">Last 3 months</option>
                            <option value="last_6_months">Last 6 months</option>
                            <option value="this_year">This Year</option>
                            <option value="custom_range">Custom date range</option>
                        </select>
                    </div>

                    <div class="lr-form-group">
                        <label>Area Filter</label>
                        <select name="area_id">
                            <option value="0">All Areas in Ward <?= safeText($wardNo); ?></option>
                            <?php foreach ($areas as $area): ?>
                                <option value="<?= (int)$area["area_id"]; ?>">
                                    <?= safeText($area["area_name"]); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="lr-form-group">
                        <label>Export Format</label>
                        <select name="export_format" required>
                            <option value="PDF">PDF</option>
                            <option value="XLSX">Excel (XLSX)</option>
                            <option value="CSV">CSV</option>
                            <option value="DOCX">DOCX</option>
                        </select>
                    </div>
                </div>

                <div class="lr-custom-range d-none" id="customRangeBox">
                    <div class="lr-form-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" id="startDate">
                    </div>

                    <div class="lr-form-group">
                        <label>End Date</label>
                        <input type="date" name="end_date" id="endDate">
                    </div>
                </div>

                <button type="submit" class="lr-generate-btn">
                    <i class="bi bi-download"></i>
                    Generate Custom Report
                </button>
            </form>
        </div>

        <div class="lr-recent-card">
            <h2>Recent Reports</h2>

            <div class="lr-table-responsive">
                <table class="lr-table">
                    <thead>
                        <tr>
                            <th>Report Name</th>
                            <th>Type</th>
                            <th>Period</th>
                            <th>Generated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!empty($recentReports)): ?>
                            <?php foreach ($recentReports as $report): ?>
                                <tr>
                                    <td>
                                        <strong><?= safeText($report["report_name"]); ?></strong>
                                    </td>

                                    <td>
                                        <span class="lr-type-badge">
                                            <?= safeText(reportTypeBadgeLabel($report["report_type"])); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= safeText(formatPeriodForTable($report["report_period"], $report["start_date"], $report["end_date"])); ?>
                                    </td>

                                    <td>
                                        <?= safeText(formatDateTime($report["generated_at"])); ?>
                                    </td>

                                    <td>
                                        <?php if (!empty($report["file_path"])): ?>
                                            <a href="<?= safeText($report["file_path"]); ?>" download class="lr-download-link">
                                                <i class="bi bi-download"></i>
                                                Download
                                            </a>
                                        <?php else: ?>
                                            <span class="lr-no-file">No file</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="lr-empty-row">
                                    No reports generated yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </section>

</main>

<?php if ($autoDownloadPath !== ""): ?>
    <a href="<?= safeText($autoDownloadPath); ?>" download id="autoDownloadReport" class="d-none">Download</a>
<?php endif; ?>

<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/local-reports.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>