<?php
require_once "../config.php";

$allowed_role = "central_officer";
require_once "session_check.php";

function redirectWithError($message)
{
    $_SESSION["report_error"] = $message;
    header("Location: ../pages/central/reports.php");
    exit();
}

function safeFilename($value)
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace("/[^a-z0-9_\\-]+/", "_", $value);
    $value = trim($value, "_");

    return $value !== "" ? $value : "report";
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

function getDateRange($period, $startDate, $endDate)
{
    $today = date("Y-m-d");

    if ($period === "last_7_days") {
        return [date("Y-m-d", strtotime("-6 days")), $today];
    }

    if ($period === "last_30_days") {
        return [date("Y-m-d", strtotime("-29 days")), $today];
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

    return [$startDate, $endDate];
}

function addDateAndLocationWhere(&$where, &$types, &$params, $dateColumn, $startDate, $endDate, $cityCorId, $thanaId, $wardId, $areaId, $locationAlias = "l")
{
    $where[] = "DATE($dateColumn) BETWEEN ? AND ?";
    $types .= "ss";
    $params[] = $startDate;
    $params[] = $endDate;

    if ($cityCorId > 0) {
        $where[] = "$locationAlias.city_cor_id = ?";
        $types .= "i";
        $params[] = $cityCorId;
    }

    if ($thanaId > 0) {
        $where[] = "$locationAlias.thana_id = ?";
        $types .= "i";
        $params[] = $thanaId;
    }

    if ($wardId > 0) {
        $where[] = "$locationAlias.ward_id = ?";
        $types .= "i";
        $params[] = $wardId;
    }

    if ($areaId > 0) {
        $where[] = "$locationAlias.area_id = ?";
        $types .= "i";
        $params[] = $areaId;
    }
}

function runReportQuery($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new Exception("Report query failed: " . mysqli_error($conn));
    }

    if ($types !== "" && count($params) > 0) {
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

function buildReportRows($conn, $reportType, $startDate, $endDate, $cityCorId, $thanaId, $wardId, $areaId)
{
    $where = [];
    $types = "";
    $params = [];

    if ($reportType === "ward_complaint_performance") {
        addDateAndLocationWhere($where, $types, $params, "c.submitted_at", $startDate, $endDate, $cityCorId, $thanaId, $wardId, $areaId);

        $sql = "
            SELECT
                cc.city_cor_name AS city_corporation,
                t.thana_name,
                COALESCE(w.ward_name, CONCAT('Ward ', w.ward_no)) AS ward,
                COUNT(c.complaint_id) AS total_complaints,
                SUM(c.complaint_status = 'submitted') AS submitted_count,
                SUM(c.complaint_status = 'received') AS received_count,
                SUM(c.complaint_status = 'pending_verification') AS pending_verification_count,
                SUM(c.complaint_status = 'team_assigned') AS team_assigned_count,
                SUM(c.complaint_status = 'in_progress') AS in_progress_count,
                SUM(c.complaint_status IN ('solved_by_team', 'closed')) AS solved_or_closed_count,
                SUM(c.complaint_status = 'rejected') AS rejected_count
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            INNER JOIN city_corporations cc ON l.city_cor_id = cc.city_cor_id
            INNER JOIN thanas t ON l.thana_id = t.thana_id
            INNER JOIN wards w ON l.ward_id = w.ward_id
            WHERE " . implode(" AND ", $where) . "
            GROUP BY cc.city_cor_id, t.thana_id, w.ward_id
            ORDER BY total_complaints DESC
        ";

        return runReportQuery($conn, $sql, $types, $params);
    }

    if ($reportType === "ward_officer_performance") {
        $where[] = "DATE(ca.assigned_at) BETWEEN ? AND ?";
        $types .= "ss";
        $params[] = $startDate;
        $params[] = $endDate;

        if ($cityCorId > 0) {
            $where[] = "wo.city_cor_id = ?";
            $types .= "i";
            $params[] = $cityCorId;
        }

        if ($wardId > 0) {
            $where[] = "wo.assigned_ward_id = ?";
            $types .= "i";
            $params[] = $wardId;
        }

        $sql = "
            SELECT
                cc.city_cor_name AS city_corporation,
                COALESCE(w.ward_name, CONCAT('Ward ', w.ward_no)) AS assigned_ward,
                wo.full_name AS ward_officer,
                wo.user_mail,
                wo.phone_number,
                COUNT(ca.assignment_id) AS routed_complaints,
                SUM(ca.assignment_status = 'ward_assigned') AS ward_assigned_count,
                SUM(ca.assignment_status = 'team_assigned') AS team_assigned_count,
                SUM(ca.assignment_status IN ('completed', 'closed')) AS completed_count
            FROM ward_officers wo
            INNER JOIN city_corporations cc ON wo.city_cor_id = cc.city_cor_id
            INNER JOIN wards w ON wo.assigned_ward_id = w.ward_id
            LEFT JOIN complaint_assignments ca ON ca.ward_id = wo.assigned_ward_id
            LEFT JOIN complaints c ON ca.complaint_id = c.complaint_id
            WHERE " . implode(" AND ", $where) . "
            GROUP BY wo.ward_officer_id
            ORDER BY routed_complaints DESC
        ";

        return runReportQuery($conn, $sql, $types, $params);
    }

    if ($reportType === "area_complaint_analysis") {
        addDateAndLocationWhere($where, $types, $params, "c.submitted_at", $startDate, $endDate, $cityCorId, $thanaId, $wardId, $areaId);

        $sql = "
            SELECT
                cc.city_cor_name AS city_corporation,
                t.thana_name,
                COALESCE(w.ward_name, CONCAT('Ward ', w.ward_no)) AS ward,
                a.area_name,
                COUNT(c.complaint_id) AS total_complaints,
                SUM(i.priority = 'High') AS high_priority_count,
                COALESCE(
                    (
                        SELECT i2.issue_name
                        FROM complaints c2
                        INNER JOIN issues i2 ON c2.issue_id = i2.issue_id
                        INNER JOIN locations l2 ON c2.loc_id = l2.loc_id
                        WHERE l2.area_id = a.area_id
                        GROUP BY i2.issue_id
                        ORDER BY COUNT(*) DESC
                        LIMIT 1
                    ),
                    'N/A'
                ) AS most_common_issue,
                MAX(c.submitted_at) AS last_complaint_at
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            INNER JOIN city_corporations cc ON l.city_cor_id = cc.city_cor_id
            INNER JOIN thanas t ON l.thana_id = t.thana_id
            INNER JOIN wards w ON l.ward_id = w.ward_id
            INNER JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE " . implode(" AND ", $where) . "
            GROUP BY a.area_id
            ORDER BY total_complaints DESC
        ";

        return runReportQuery($conn, $sql, $types, $params);
    }

    if ($reportType === "issue_type_analysis") {
        addDateAndLocationWhere($where, $types, $params, "c.submitted_at", $startDate, $endDate, $cityCorId, $thanaId, $wardId, $areaId);

        $sql = "
            SELECT
                COALESCE(i.issue_name, 'Unknown Issue') AS issue_name,
                COALESCE(i.priority, 'Low') AS priority,
                COUNT(c.complaint_id) AS total_complaints,
                COUNT(DISTINCT l.ward_id) AS affected_wards,
                COUNT(DISTINCT l.area_id) AS affected_areas,
                MAX(c.submitted_at) AS last_reported_at
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE " . implode(" AND ", $where) . "
            GROUP BY i.issue_id, i.issue_name, i.priority
            ORDER BY total_complaints DESC
        ";

        return runReportQuery($conn, $sql, $types, $params);
    }

    if ($reportType === "high_risk_zone") {
        $where[] = "r.risk_status = 'Active'";
        $where[] = "DATE(r.last_reported_at) BETWEEN ? AND ?";
        $types .= "ss";
        $params[] = $startDate;
        $params[] = $endDate;

        if ($cityCorId > 0) {
            $where[] = "r.city_cor_id = ?";
            $types .= "i";
            $params[] = $cityCorId;
        }

        if ($thanaId > 0) {
            $where[] = "r.thana_id = ?";
            $types .= "i";
            $params[] = $thanaId;
        }

        if ($wardId > 0) {
            $where[] = "r.ward_id = ?";
            $types .= "i";
            $params[] = $wardId;
        }

        if ($areaId > 0) {
            $where[] = "r.area_id = ?";
            $types .= "i";
            $params[] = $areaId;
        }

        $sql = "
            SELECT
                cc.city_cor_name AS city_corporation,
                t.thana_name,
                COALESCE(w.ward_name, CONCAT('Ward ', w.ward_no)) AS ward,
                a.area_name,
                r.urgency_level,
                r.complaint_count_7_days,
                r.complaint_count_30_days,
                r.complaint_count_this_week,
                r.first_reported_at,
                r.last_reported_at
            FROM risk r
            LEFT JOIN city_corporations cc ON r.city_cor_id = cc.city_cor_id
            LEFT JOIN thanas t ON r.thana_id = t.thana_id
            LEFT JOIN wards w ON r.ward_id = w.ward_id
            LEFT JOIN areas a ON r.area_id = a.area_id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY
                CASE
                    WHEN r.urgency_level = 'High' THEN 1
                    WHEN r.urgency_level = 'Medium' THEN 2
                    ELSE 3
                END,
                r.complaint_count_30_days DESC
        ";

        return runReportQuery($conn, $sql, $types, $params);
    }

    if ($reportType === "drain_condition") {
        $where[] = "DATE(d.updated_at) BETWEEN ? AND ?";
        $types .= "ss";
        $params[] = $startDate;
        $params[] = $endDate;

        if ($cityCorId > 0) {
            $where[] = "l.city_cor_id = ?";
            $types .= "i";
            $params[] = $cityCorId;
        }

        if ($thanaId > 0) {
            $where[] = "l.thana_id = ?";
            $types .= "i";
            $params[] = $thanaId;
        }

        if ($wardId > 0) {
            $where[] = "l.ward_id = ?";
            $types .= "i";
            $params[] = $wardId;
        }

        if ($areaId > 0) {
            $where[] = "l.area_id = ?";
            $types .= "i";
            $params[] = $areaId;
        }

        $sql = "
            SELECT
                d.drain_code,
                d.drain_name,
                d.drain_address_description,
                d.drain_condition,
                cc.city_cor_name AS city_corporation,
                t.thana_name,
                COALESCE(w.ward_name, CONCAT('Ward ', w.ward_no)) AS ward,
                a.area_name,
                d.condition_updated_by_role,
                d.condition_updated_at,
                d.updated_at
            FROM drains d
            INNER JOIN locations l ON d.loc_id = l.loc_id
            INNER JOIN city_corporations cc ON l.city_cor_id = cc.city_cor_id
            INNER JOIN thanas t ON l.thana_id = t.thana_id
            INNER JOIN wards w ON l.ward_id = w.ward_id
            INNER JOIN areas a ON l.area_id = a.area_id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY d.updated_at DESC
        ";

        return runReportQuery($conn, $sql, $types, $params);
    }

    if ($reportType === "maintenance_team_performance") {
        $where[] = "DATE(mu.created_at) BETWEEN ? AND ?";
        $types .= "ss";
        $params[] = $startDate;
        $params[] = $endDate;

        if ($cityCorId > 0) {
            $where[] = "mt.city_cor_id = ?";
            $types .= "i";
            $params[] = $cityCorId;
        }

        $sql = "
            SELECT
                mt.team_name,
                cc.city_cor_name AS city_corporation,
                mt.availability_status,
                COUNT(mu.update_id) AS total_updates,
                SUM(mu.work_status = 'assigned') AS assigned_count,
                SUM(mu.work_status IN ('started', 'in_progress')) AS in_progress_count,
                SUM(mu.work_status = 'completed') AS completed_count,
                SUM(mu.work_status = 'delayed') AS delayed_count,
                ROUND(AVG(TIMESTAMPDIFF(HOUR, mu.started_at, mu.completed_at)), 2) AS avg_completion_hours
            FROM maintenance_teams mt
            LEFT JOIN city_corporations cc ON mt.city_cor_id = cc.city_cor_id
            LEFT JOIN maintenance_updates mu ON mt.maintenance_team_id = mu.maintenance_team_id
            WHERE " . implode(" AND ", $where) . "
            GROUP BY mt.maintenance_team_id
            ORDER BY completed_count DESC
        ";

        return runReportQuery($conn, $sql, $types, $params);
    }

    if ($reportType === "complaint_trend") {
        addDateAndLocationWhere($where, $types, $params, "c.submitted_at", $startDate, $endDate, $cityCorId, $thanaId, $wardId, $areaId);

        $sql = "
            SELECT
                DATE(c.submitted_at) AS report_date,
                COUNT(c.complaint_id) AS total_complaints,
                SUM(i.priority = 'High') AS high_priority_count,
                SUM(i.priority = 'Medium') AS medium_priority_count,
                SUM(i.priority = 'Low') AS low_priority_count,
                COUNT(DISTINCT l.ward_id) AS affected_wards,
                COUNT(DISTINCT l.area_id) AS affected_areas
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE " . implode(" AND ", $where) . "
            GROUP BY DATE(c.submitted_at)
            ORDER BY report_date ASC
        ";

        return runReportQuery($conn, $sql, $types, $params);
    }

    if ($reportType === "repeat_reopened_complaint") {
        addDateAndLocationWhere($where, $types, $params, "c.submitted_at", $startDate, $endDate, $cityCorId, $thanaId, $wardId, $areaId);
        $where[] = "(c.is_repeat_complaint = 1 OR c.parent_complaint_id IS NOT NULL)";

        $sql = "
            SELECT
                c.complaint_code,
                pc.complaint_code AS parent_complaint_code,
                COALESCE(i.issue_name, 'Unknown Issue') AS issue_name,
                c.complaint_status,
                cc.city_cor_name AS city_corporation,
                t.thana_name,
                COALESCE(w.ward_name, CONCAT('Ward ', w.ward_no)) AS ward,
                a.area_name,
                c.problem_description,
                c.submitted_at
            FROM complaints c
            LEFT JOIN complaints pc ON c.parent_complaint_id = pc.complaint_id
            INNER JOIN locations l ON c.loc_id = l.loc_id
            INNER JOIN city_corporations cc ON l.city_cor_id = cc.city_cor_id
            INNER JOIN thanas t ON l.thana_id = t.thana_id
            INNER JOIN wards w ON l.ward_id = w.ward_id
            INNER JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY c.submitted_at DESC
        ";

        return runReportQuery($conn, $sql, $types, $params);
    }

    throw new Exception("Invalid report type selected.");
}

/* CSV / EXCEL / DOCS */

function rowsToTableHtml($title, $rows)
{
    $html = "<h1>" . htmlspecialchars($title) . "</h1>";

    if (count($rows) === 0) {
        return $html . "<p>No data found for the selected filters.</p>";
    }

    $headers = array_keys($rows[0]);

    $html .= "<table>";
    $html .= "<thead><tr>";

    foreach ($headers as $header) {
        $html .= "<th>" . htmlspecialchars(ucwords(str_replace("_", " ", $header))) . "</th>";
    }

    $html .= "</tr></thead><tbody>";

    foreach ($rows as $row) {
        $html .= "<tr>";

        foreach ($headers as $header) {
            $html .= "<td>" . htmlspecialchars((string)($row[$header] ?? "")) . "</td>";
        }

        $html .= "</tr>";
    }

    $html .= "</tbody></table>";

    return $html;
}

function writeCsvFile($path, $rows)
{
    $file = fopen($path, "w");

    if (!$file) {
        throw new Exception("Unable to create CSV file.");
    }

    if (count($rows) > 0) {
        fputcsv($file, array_keys($rows[0]));

        foreach ($rows as $row) {
            fputcsv($file, $row);
        }
    } else {
        fputcsv($file, ["No data found"]);
    }

    fclose($file);
}

function writeHtmlFile($path, $title, $rows)
{
    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'>";
    $html .= "<title>" . htmlspecialchars($title) . "</title>";
    $html .= "<style>";
    $html .= "body{font-family:Arial,sans-serif;padding:22px;color:#111827;}";
    $html .= ".report-header{background:#082f49;color:#ffffff;padding:18px 22px;border-radius:12px;margin-bottom:20px;}";
    $html .= ".report-header h1{font-size:22px;margin:0 0 6px;}";
    $html .= ".report-header p{font-size:13px;margin:0;color:#dbeafe;}";
    $html .= "table{border-collapse:collapse;width:100%;}";
    $html .= "th{background:#0f172a;color:#ffffff;}";
    $html .= "th,td{border:1px solid #cbd5e1;padding:9px;text-align:left;font-size:12px;}";
    $html .= "tr:nth-child(even){background:#f8fafc;}";
    $html .= "</style>";
    $html .= "</head><body>";
    $html .= "<div class='report-header'>";
    $html .= "<h1>" . htmlspecialchars($title) . "</h1>";
    $html .= "<p>Generated by DrainGuard Central Control on " . date("M d, Y h:i A") . "</p>";
    $html .= "</div>";
    $html .= rowsToTableHtml($title, $rows);
    $html .= "</body></html>";

    file_put_contents($path, $html);
}

/* PDF */

function pdfEscape($text)
{
    $text = (string)$text;
    $text = str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $text);
    $text = preg_replace('/[^\x20-\x7E]/', '', $text);

    return $text;
}

function pdfText($x, $y, $text, $fontSize = 10)
{
    return "BT /F1 {$fontSize} Tf {$x} {$y} Td (" . pdfEscape($text) . ") Tj ET\n";
}

function pdfLine($x1, $y1, $x2, $y2)
{
    return "{$x1} {$y1} m {$x2} {$y2} l S\n";
}

function pdfRect($x, $y, $w, $h)
{
    return "{$x} {$y} {$w} {$h} re S\n";
}

function pdfFillRect($x, $y, $w, $h)
{
    return "{$x} {$y} {$w} {$h} re f\n";
}

function truncatePdfText($text, $maxLength = 22)
{
    $text = trim((string)$text);

    if (strlen($text) <= $maxLength) {
        return $text;
    }

    return substr($text, 0, $maxLength - 3) . "...";
}

function writeSimplePdfFile($path, $title, $rows)
{
    $content = "";

    $content .= "0.02 0.18 0.25 rg\n";
    $content .= pdfFillRect(0, 790, 595, 52);

    $content .= "1 1 1 rg\n";
    $content .= pdfText(38, 817, "DrainGuard", 18);
    $content .= pdfText(38, 800, "Smart Urban Drainage Issue Management System", 9);

    $content .= "0.94 0.99 1 rg\n";
    $content .= pdfFillRect(38, 720, 519, 52);

    $content .= "0.12 0.72 0.72 RG\n";
    $content .= pdfRect(38, 720, 519, 52);

    $content .= "0 0 0 rg\n";
    $content .= pdfText(54, 752, $title, 15);
    $content .= pdfText(54, 733, "Generated: " . date("M d, Y h:i A"), 10);

    $totalRows = count($rows);

    $content .= "0.98 0.98 0.98 rg\n";
    $content .= pdfFillRect(58, 660, 230, 42);
    $content .= pdfFillRect(308, 660, 230, 42);

    $content .= "0.82 0.87 0.92 RG\n";
    $content .= pdfRect(58, 660, 230, 42);
    $content .= pdfRect(308, 660, 230, 42);

    $content .= "0 0 0 rg\n";
    $content .= pdfText(78, 686, "Total Records", 9);
    $content .= pdfText(78, 669, (string)$totalRows, 16);

    $content .= pdfText(328, 686, "Report Format", 9);
    $content .= pdfText(328, 669, "PDF", 16);

    if ($totalRows === 0) {
        $content .= "0.98 0.98 0.98 rg\n";
        $content .= pdfFillRect(38, 590, 519, 44);

        $content .= "0.82 0.87 0.92 RG\n";
        $content .= pdfRect(38, 590, 519, 44);

        $content .= "0 0 0 rg\n";
        $content .= pdfText(54, 613, "No data found for the selected filters.", 11);

        $content .= "0.45 0.50 0.56 rg\n";
        $content .= pdfText(38, 44, "Generated by DrainGuard Central Control", 8);
        $content .= pdfText(430, 44, "Page 1", 8);

        createPdfFile($path, $content);
        return;
    }

    $headers = array_keys($rows[0]);

    $maxColumns = min(count($headers), 6);
    $visibleHeaders = array_slice($headers, 0, $maxColumns);

    $tableX = 38;
    $tableY = 620;
    $tableWidth = 519;
    $headerHeight = 28;
    $rowHeight = 26;

    $colWidth = $tableWidth / $maxColumns;

    $content .= "0.02 0.18 0.25 rg\n";
    $content .= pdfFillRect($tableX, $tableY, $tableWidth, $headerHeight);

    $content .= "1 1 1 rg\n";

    foreach ($visibleHeaders as $index => $header) {
        $x = $tableX + ($index * $colWidth) + 6;
        $label = ucwords(str_replace("_", " ", $header));
        $content .= pdfText($x, $tableY + 10, truncatePdfText($label, 18), 8);
    }

    $currentY = $tableY - $rowHeight;
    $rowLimit = 18;
    $printedRows = 0;

    foreach ($rows as $rowIndex => $row) {
        if ($printedRows >= $rowLimit) {
            break;
        }

        if ($rowIndex % 2 === 0) {
            $content .= "0.98 0.99 1 rg\n";
        } else {
            $content .= "1 1 1 rg\n";
        }

        $content .= pdfFillRect($tableX, $currentY, $tableWidth, $rowHeight);

        $content .= "0.82 0.87 0.92 RG\n";
        $content .= pdfRect($tableX, $currentY, $tableWidth, $rowHeight);

        $content .= "0 0 0 rg\n";

        foreach ($visibleHeaders as $index => $header) {
            $x = $tableX + ($index * $colWidth) + 6;
            $value = truncatePdfText($row[$header] ?? "", 18);
            $content .= pdfText($x, $currentY + 10, $value, 8);
        }

        for ($i = 1; $i < $maxColumns; $i++) {
            $lineX = $tableX + ($i * $colWidth);
            $content .= "0.82 0.87 0.92 RG\n";
            $content .= pdfLine($lineX, $currentY, $lineX, $currentY + $rowHeight);
        }

        $currentY -= $rowHeight;
        $printedRows++;
    }

    if ($totalRows > $rowLimit) {
        $content .= "0 0 0 rg\n";
        $content .= pdfText(38, 88, "Note: Showing first {$rowLimit} records in PDF. Use Excel/CSV for full data.", 9);
    }

    $content .= "0.45 0.50 0.56 rg\n";
    $content .= pdfText(38, 44, "Generated by DrainGuard Central Control", 8);
    $content .= pdfText(430, 44, "Page 1", 8);

    createPdfFile($path, $content);
}

function createPdfFile($path, $content)
{
    $objects = [];

    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);

    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= str_pad((string)$offsets[$i], 10, "0", STR_PAD_LEFT) . " 00000 n \n";
    }

    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

    file_put_contents($path, $pdf);
}

function downloadGeneratedFile($serverPath, $downloadName, $exportFormat)
{
    if (!file_exists($serverPath)) {
        redirectWithError("Report file was not created properly.");
    }

    $mimeTypes = [
        "PDF" => "application/pdf",
        "Excel" => "application/vnd.ms-excel",
        "CSV" => "text/csv",
        "DOCS" => "application/msword"
    ];

    $contentType = $mimeTypes[$exportFormat] ?? "application/octet-stream";

    if (ob_get_length()) {
        ob_end_clean();
    }

    header("Content-Description: File Transfer");
    header("Content-Type: " . $contentType);
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
    redirectWithError("Invalid request method.");
}

$userId = (int)($_SESSION["user_id"] ?? 0);

if ($userId <= 0) {
    redirectWithError("User session not found.");
}

$validReportTypes = [
    "ward_complaint_performance",
    "ward_officer_performance",
    "area_complaint_analysis",
    "issue_type_analysis",
    "high_risk_zone",
    "drain_condition",
    "maintenance_team_performance",
    "complaint_trend",
    "repeat_reopened_complaint"
];

$validPeriods = [
    "last_7_days",
    "last_30_days",
    "last_3_months",
    "last_6_months",
    "this_year",
    "custom_range"
];

$validFormats = ["PDF", "Excel", "CSV", "DOCS"];

$reportType = trim($_POST["report_type"] ?? "");
$reportPeriod = trim($_POST["report_period"] ?? "");
$exportFormat = trim($_POST["export_format"] ?? "");

$cityCorId = (int)($_POST["city_cor_id"] ?? 0);
$thanaId = (int)($_POST["thana_id"] ?? 0);
$wardId = (int)($_POST["ward_id"] ?? 0);
$areaId = (int)($_POST["area_id"] ?? 0);

$customStart = trim($_POST["start_date"] ?? "");
$customEnd = trim($_POST["end_date"] ?? "");

if (!in_array($reportType, $validReportTypes, true)) {
    redirectWithError("Invalid report type selected.");
}

if (!in_array($reportPeriod, $validPeriods, true)) {
    redirectWithError("Invalid time period selected.");
}

if (!in_array($exportFormat, $validFormats, true)) {
    redirectWithError("Invalid export format selected.");
}

if ($reportPeriod === "custom_range") {
    if ($customStart === "" || $customEnd === "") {
        redirectWithError("Custom start date and end date are required.");
    }

    if ($customStart > $customEnd) {
        redirectWithError("Start date cannot be after end date.");
    }
}

[$startDate, $endDate] = getDateRange($reportPeriod, $customStart, $customEnd);

try {
    $rows = buildReportRows(
        $conn,
        $reportType,
        $startDate,
        $endDate,
        $cityCorId,
        $thanaId,
        $wardId,
        $areaId
    );

    $reportTitle = reportTypeLabel($reportType) . " - " . periodLabel($reportPeriod);
    $timestamp = date("Ymd_His");

    $extension = "pdf";

    if ($exportFormat === "CSV") {
        $extension = "csv";
    } elseif ($exportFormat === "Excel") {
        $extension = "xls";
    } elseif ($exportFormat === "DOCS") {
        $extension = "doc";
    }

    $reportDir = "../assets/reports/";

    if (!is_dir($reportDir)) {
        mkdir($reportDir, 0777, true);
    }

    $fileName = safeFilename($reportType) . "_" . $timestamp . "." . $extension;
    $serverPath = $reportDir . $fileName;
    $dbPath = "assets/reports/" . $fileName;

    if ($exportFormat === "CSV") {
        writeCsvFile($serverPath, $rows);
    } elseif ($exportFormat === "Excel") {
        writeHtmlFile($serverPath, $reportTitle, $rows);
    } elseif ($exportFormat === "DOCS") {
        writeHtmlFile($serverPath, $reportTitle, $rows);
    } else {
        writeSimplePdfFile($serverPath, $reportTitle, $rows);
    }

    $insertSql = "
        INSERT INTO generated_reports (
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
        throw new Exception("Report history save failed: " . mysqli_error($conn));
    }

    $cityCorIdDb = $cityCorId > 0 ? $cityCorId : null;
    $thanaIdDb = $thanaId > 0 ? $thanaId : null;
    $wardIdDb = $wardId > 0 ? $wardId : null;
    $areaIdDb = $areaId > 0 ? $areaId : null;

    mysqli_stmt_bind_param(
        $insertStmt,
        "sssiiiissssi",
        $reportTitle,
        $reportType,
        $reportPeriod,
        $cityCorIdDb,
        $thanaIdDb,
        $wardIdDb,
        $areaIdDb,
        $startDate,
        $endDate,
        $exportFormat,
        $dbPath,
        $userId
    );

    if (!mysqli_stmt_execute($insertStmt)) {
        throw new Exception("Report history save failed: " . mysqli_stmt_error($insertStmt));
    }

    mysqli_stmt_close($insertStmt);

    downloadGeneratedFile($serverPath, basename($serverPath), $exportFormat);

} catch (Exception $e) {
    redirectWithError($e->getMessage());
}