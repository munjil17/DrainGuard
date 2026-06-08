<?php

function cr_safe($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function cr_report_types()
{
    return [
        "system_complaint_monitoring" => "System-wide Complaint Monitoring Report",
        "ward_complaint_performance" => "Ward-wise Complaint Performance Report",
        "area_complaint_analysis" => "Area-wise Complaint Analysis Report",
        "issue_type_analysis" => "Issue Type Analysis Report",
        "high_risk_zone" => "High Risk Zone Report",
        "maintenance_team_performance" => "Maintenance Team Performance Report",
        "reopened_disputed_complaint" => "Reopened and Disputed Complaint Report",
        "citizen_feedback_objection_summary" => "Citizen Feedback and Objection Summary Report",
    ];
}

function cr_report_type_label($type)
{
    $types = cr_report_types();
    return $types[$type] ?? ucwords(str_replace("_", " ", (string)$type));
}

function cr_period_label($period)
{
    $labels = [
        "last_7_days" => "Last 7 Days",
        "last_30_days" => "Last 30 Days",
        "last_3_months" => "Last 3 Months",
        "last_6_months" => "Last 6 Months",
        "this_year" => "This Year",
        "custom_range" => "Custom Date Range",
    ];

    return $labels[$period] ?? ucwords(str_replace("_", " ", (string)$period));
}

function cr_date_range($period, $startDate, $endDate)
{
    $today = date("Y-m-d");

    if ($period === "last_7_days") return [date("Y-m-d", strtotime("-6 days")), $today];
    if ($period === "last_30_days") return [date("Y-m-d", strtotime("-29 days")), $today];
    if ($period === "last_3_months") return [date("Y-m-d", strtotime("-3 months")), $today];
    if ($period === "last_6_months") return [date("Y-m-d", strtotime("-6 months")), $today];
    if ($period === "this_year") return [date("Y-01-01"), $today];

    return [$startDate, $endDate];
}

function cr_format_date($date)
{
    if (!$date) return "N/A";
    $time = strtotime($date);
    return $time ? date("M d, Y", $time) : "N/A";
}

function cr_format_number($value)
{
    if (is_numeric($value)) {
        return number_format((float)$value, ((float)$value == (int)$value) ? 0 : 1);
    }

    return (string)$value;
}

function cr_fetch_all($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new Exception("Unable to prepare report data.");
    }

    if ($types !== "" && count($params) > 0) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        throw new Exception("Unable to load report data.");
    }

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

function cr_fetch_one($conn, $sql, $types = "", $params = [])
{
    $rows = cr_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function cr_location_where(&$where, &$types, &$params, $alias, $cityCorId, $thanaId, $wardId, $areaId)
{
    if ($cityCorId > 0) {
        $where[] = "$alias.city_cor_id = ?";
        $types .= "i";
        $params[] = $cityCorId;
    }

    if ($thanaId > 0) {
        $where[] = "$alias.thana_id = ?";
        $types .= "i";
        $params[] = $thanaId;
    }

    if ($wardId > 0) {
        $where[] = "$alias.ward_id = ?";
        $types .= "i";
        $params[] = $wardId;
    }

    if ($areaId > 0) {
        $where[] = "$alias.area_id = ?";
        $types .= "i";
        $params[] = $areaId;
    }
}

function cr_location_labels($conn, $cityCorId, $thanaId, $wardId, $areaId)
{
    $labels = [
        "City Corporation" => "All City Corporations",
        "Thana" => "All Thanas",
        "Ward" => "All Wards",
        "Area" => "All Areas",
    ];

    if ($cityCorId > 0) {
        $row = cr_fetch_one($conn, "SELECT city_cor_name FROM city_corporations WHERE city_cor_id = ? LIMIT 1", "i", [$cityCorId]);
        $labels["City Corporation"] = $row["city_cor_name"] ?? $labels["City Corporation"];
    }

    if ($thanaId > 0) {
        $row = cr_fetch_one($conn, "SELECT thana_name FROM thanas WHERE thana_id = ? LIMIT 1", "i", [$thanaId]);
        $labels["Thana"] = $row["thana_name"] ?? $labels["Thana"];
    }

    if ($wardId > 0) {
        $row = cr_fetch_one($conn, "SELECT ward_no, ward_name FROM wards WHERE ward_id = ? LIMIT 1", "i", [$wardId]);
        $labels["Ward"] = isset($row["ward_no"]) ? ("Ward " . $row["ward_no"] . " - " . $row["ward_name"]) : $labels["Ward"];
    }

    if ($areaId > 0) {
        $row = cr_fetch_one($conn, "SELECT area_name FROM areas WHERE area_id = ? LIMIT 1", "i", [$areaId]);
        $labels["Area"] = $row["area_name"] ?? $labels["Area"];
    }

    return $labels;
}

function cr_filter_summary($conn, $reportType, $period, $startDate, $endDate, $cityCorId, $thanaId, $wardId, $areaId)
{
    return array_merge([
        "Report Type" => cr_report_type_label($reportType),
        "Time Period" => cr_period_label($period),
        "Date Range" => cr_format_date($startDate) . " - " . cr_format_date($endDate),
    ], cr_location_labels($conn, $cityCorId, $thanaId, $wardId, $areaId));
}

function cr_build_report($conn, $reportType, $period, $startDate, $endDate, $cityCorId, $thanaId, $wardId, $areaId)
{
    if (!array_key_exists($reportType, cr_report_types())) {
        throw new Exception("Please select a valid report type.");
    }

    $filterSummary = cr_filter_summary($conn, $reportType, $period, $startDate, $endDate, $cityCorId, $thanaId, $wardId, $areaId);

    $data = [
        "title" => cr_report_type_label($reportType),
        "filters" => $filterSummary,
        "kpis" => [],
        "details" => [],
        "snapshot" => [],
    ];

    $where = ["DATE(c.submitted_at) BETWEEN ? AND ?"];
    $types = "ss";
    $params = [$startDate, $endDate];
    cr_location_where($where, $types, $params, "l", $cityCorId, $thanaId, $wardId, $areaId);
    $complaintWhere = implode(" AND ", $where);

    if ($reportType === "system_complaint_monitoring") {
        $kpi = cr_fetch_one($conn, "
            SELECT
                COUNT(*) AS total,
                SUM(c.complaint_status = 'submitted') AS submitted,
                SUM(c.complaint_status = 'received') AS received,
                SUM(c.complaint_status = 'pending_verification') AS pending_verification,
                SUM(c.complaint_status = 'verified_by_ward') AS verified,
                SUM(c.complaint_status = 'team_assigned') AS team_assigned,
                SUM(c.complaint_status = 'in_progress') AS in_progress,
                SUM(c.complaint_status = 'solved_by_team') AS solved_by_team,
                SUM(c.complaint_status = 'inspector_verification') AS inspector_verification,
                SUM(c.complaint_status = 'closed') AS closed,
                SUM(c.complaint_status = 'reopened') AS reopened,
                SUM(c.complaint_status IN ('rejected_by_central','rejected_by_ward','final_rejected')) AS rejected,
                SUM(c.complaint_status = 'duplicate') AS duplicate
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            WHERE $complaintWhere
        ", $types, $params);

        $data["kpis"] = [
            "Total Complaints" => (int)($kpi["total"] ?? 0),
            "Submitted Complaints" => (int)($kpi["submitted"] ?? 0),
            "Received Complaints" => (int)($kpi["received"] ?? 0),
            "Pending Verification" => (int)($kpi["pending_verification"] ?? 0),
            "Verified Complaints" => (int)($kpi["verified"] ?? 0),
            "Team Assigned" => (int)($kpi["team_assigned"] ?? 0),
            "In Progress" => (int)($kpi["in_progress"] ?? 0),
            "Solved by Team" => (int)($kpi["solved_by_team"] ?? 0),
            "Inspector Verification" => (int)($kpi["inspector_verification"] ?? 0),
            "Closed Complaints" => (int)($kpi["closed"] ?? 0),
            "Reopened Complaints" => (int)($kpi["reopened"] ?? 0),
            "Rejected Complaints" => (int)($kpi["rejected"] ?? 0),
            "Duplicate Complaints" => (int)($kpi["duplicate"] ?? 0),
        ];

        $data["details"] = cr_fetch_all($conn, "
            SELECT
                c.complaint_code AS `Complaint Code`,
                COALESCE(i.issue_name, 'N/A') AS `Issue Type`,
                CONCAT('Ward ', w.ward_no) AS `Ward`,
                COALESCE(a.area_name, 'N/A') AS `Area`,
                REPLACE(UCASE(REPLACE(c.complaint_status, '_', ' ')), 'BY', 'by') AS `Current Status`,
                COALESCE(i.priority, 'Low') AS `Priority`,
                DATE_FORMAT(c.submitted_at, '%b %d, %Y') AS `Submitted Date`,
                DATE_FORMAT(c.updated_at, '%b %d, %Y') AS `Last Updated Date`
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            INNER JOIN wards w ON l.ward_id = w.ward_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE $complaintWhere
            ORDER BY c.submitted_at DESC
            LIMIT 250
        ", $types, $params);
    }

    if ($reportType === "ward_complaint_performance") {
        $kpi = cr_fetch_one($conn, "
            SELECT
                COUNT(DISTINCT l.ward_id) AS total_wards,
                COUNT(c.complaint_id) AS total_complaints,
                SUM(c.complaint_status = 'pending_verification') AS pending_verification,
                SUM(c.complaint_status = 'verified_by_ward') AS verified,
                SUM(c.complaint_status = 'team_assigned') AS team_assigned,
                SUM(c.complaint_status = 'in_progress') AS in_progress,
                SUM(c.complaint_status = 'closed') AS closed,
                SUM(c.complaint_status IN ('reopened','disputed')) AS reopened_disputed
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            WHERE $complaintWhere
        ", $types, $params);

        $data["kpis"] = [
            "Total Wards" => (int)($kpi["total_wards"] ?? 0),
            "Total Complaints" => (int)($kpi["total_complaints"] ?? 0),
            "Pending Verification" => (int)($kpi["pending_verification"] ?? 0),
            "Verified Complaints" => (int)($kpi["verified"] ?? 0),
            "Team Assigned" => (int)($kpi["team_assigned"] ?? 0),
            "In Progress" => (int)($kpi["in_progress"] ?? 0),
            "Closed Complaints" => (int)($kpi["closed"] ?? 0),
            "Reopened / Disputed Complaints" => (int)($kpi["reopened_disputed"] ?? 0),
        ];

        $data["details"] = cr_fetch_all($conn, "
            SELECT
                w.ward_no AS `Ward No`,
                w.ward_name AS `Ward Name`,
                COUNT(c.complaint_id) AS `Total Complaints`,
                SUM(c.complaint_status = 'pending_verification') AS `Pending Verification`,
                SUM(c.complaint_status = 'verified_by_ward') AS `Verified`,
                SUM(c.complaint_status = 'team_assigned') AS `Team Assigned`,
                SUM(c.complaint_status = 'in_progress') AS `In Progress`,
                SUM(c.complaint_status = 'closed') AS `Closed`,
                SUM(c.complaint_status IN ('reopened','disputed')) AS `Reopened / Disputed`,
                SUM(c.complaint_status IN ('rejected_by_central','rejected_by_ward','final_rejected')) AS `Rejected`
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            INNER JOIN wards w ON l.ward_id = w.ward_id
            WHERE $complaintWhere
            GROUP BY w.ward_id, w.ward_no, w.ward_name
            ORDER BY COUNT(c.complaint_id) DESC, w.ward_no ASC
        ", $types, $params);
    }

    if ($reportType === "area_complaint_analysis") {
        $kpi = cr_fetch_one($conn, "
            SELECT
                COUNT(*) AS total_areas,
                SUM(total_complaints >= 3) AS high_complaint_areas,
                SUM(high_count) AS high_count
            FROM (
                SELECT l.area_id, a.area_name, COUNT(c.complaint_id) AS total_complaints, SUM(i.priority = 'High') AS high_count
                FROM complaints c
                INNER JOIN locations l ON c.loc_id = l.loc_id
                LEFT JOIN areas a ON l.area_id = a.area_id
                LEFT JOIN issues i ON c.issue_id = i.issue_id
                WHERE $complaintWhere
                GROUP BY l.area_id, a.area_name
                ORDER BY total_complaints DESC
            ) area_totals
        ", $types, $params);

        $most = cr_fetch_one($conn, "
            SELECT COALESCE(a.area_name, 'N/A') AS area_name, COUNT(c.complaint_id) AS total
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            WHERE $complaintWhere
            GROUP BY l.area_id, a.area_name
            ORDER BY total DESC
            LIMIT 1
        ", $types, $params);

        $data["kpis"] = [
            "Total Areas" => (int)($kpi["total_areas"] ?? 0),
            "High Complaint Areas" => (int)($kpi["high_complaint_areas"] ?? 0),
            "Most Affected Area" => $most["area_name"] ?? "N/A",
            "High / Emergency Complaints" => (int)($kpi["high_count"] ?? 0),
        ];

        $data["details"] = cr_fetch_all($conn, "
            SELECT
                COALESCE(a.area_name, 'N/A') AS `Area Name`,
                w.ward_no AS `Ward No`,
                COUNT(c.complaint_id) AS `Total Complaints`,
                COALESCE((
                    SELECT i2.issue_name
                    FROM complaints c2
                    INNER JOIN locations l2 ON c2.loc_id = l2.loc_id
                    LEFT JOIN issues i2 ON c2.issue_id = i2.issue_id
                    WHERE l2.area_id = l.area_id
                    GROUP BY i2.issue_id, i2.issue_name
                    ORDER BY COUNT(*) DESC
                    LIMIT 1
                ), 'N/A') AS `Most Common Issue Type`,
                SUM(i.priority = 'High') AS `High / Emergency Complaint Count`,
                SUM(c.complaint_status = 'reopened') AS `Reopened Complaint Count`,
                DATE_FORMAT(MAX(c.submitted_at), '%b %d, %Y') AS `Last Complaint Date`
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            INNER JOIN wards w ON l.ward_id = w.ward_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE $complaintWhere
            GROUP BY l.area_id, a.area_name, w.ward_no
            ORDER BY COUNT(c.complaint_id) DESC
        ", $types, $params);
    }

    if ($reportType === "issue_type_analysis") {
        $kpi = cr_fetch_one($conn, "
            SELECT
                COUNT(DISTINCT i.issue_id) AS total_issue_types,
                SUM(i.priority = 'High') AS high_priority_count,
                SUM(i.priority = 'Medium') AS medium_priority_count,
                SUM(i.priority = 'Low') AS low_priority_count
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE $complaintWhere
        ", $types, $params);

        $most = cr_fetch_one($conn, "
            SELECT COALESCE(i.issue_name, 'Unknown Issue') AS issue_name, COUNT(c.complaint_id) AS total
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE $complaintWhere
            GROUP BY i.issue_id, i.issue_name
            ORDER BY total DESC
            LIMIT 1
        ", $types, $params);

        $data["kpis"] = [
            "Total Issue Types" => (int)($kpi["total_issue_types"] ?? 0),
            "Most Reported Issue" => $most["issue_name"] ?? "N/A",
            "High Priority Issues" => (int)($kpi["high_priority_count"] ?? 0),
            "Medium Priority Issues" => (int)($kpi["medium_priority_count"] ?? 0),
            "Low Priority Issues" => (int)($kpi["low_priority_count"] ?? 0),
        ];

        $data["details"] = cr_fetch_all($conn, "
            SELECT
                COALESCE(i.issue_name, 'Unknown Issue') AS `Issue Type`,
                CASE WHEN i.priority IN ('High','Medium','Low') THEN i.priority ELSE 'Low' END AS `Priority`,
                COUNT(c.complaint_id) AS `Total Complaints`,
                COUNT(DISTINCT l.ward_id) AS `Affected Wards`,
                COUNT(DISTINCT l.area_id) AS `Affected Areas`,
                DATE_FORMAT(MAX(c.submitted_at), '%b %d, %Y') AS `Last Reported Date`
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE $complaintWhere
            GROUP BY i.issue_id, i.issue_name, i.priority
            ORDER BY COUNT(c.complaint_id) DESC
        ", $types, $params);
    }

    if ($reportType === "high_risk_zone") {
        $riskWhere = ["r.risk_status = 'Active'", "DATE(r.last_reported_at) BETWEEN ? AND ?"];
        $riskTypes = "ss";
        $riskParams = [$startDate, $endDate];
        cr_location_where($riskWhere, $riskTypes, $riskParams, "r", $cityCorId, $thanaId, $wardId, $areaId);
        $riskWhereSql = implode(" AND ", $riskWhere);

        $kpi = cr_fetch_one($conn, "
            SELECT
                SUM(r.urgency_level = 'High') AS high_zones,
                SUM(r.complaint_count_7_days) AS seven_days,
                SUM(r.complaint_count_30_days) AS thirty_days,
                COUNT(DISTINCT r.ward_id) AS risk_wards
            FROM risk r
            WHERE $riskWhereSql
        ", $riskTypes, $riskParams);

        $teamWhere = [];
        $teamTypes = "";
        $teamParams = [];
        if ($cityCorId > 0) {
            $teamWhere[] = "city_cor_id = ?";
            $teamTypes .= "i";
            $teamParams[] = $cityCorId;
        }
        $teamSql = "SELECT COUNT(*) AS total FROM maintenance_teams";
        if ($teamWhere) $teamSql .= " WHERE " . implode(" AND ", $teamWhere);
        $teamRow = cr_fetch_one($conn, $teamSql, $teamTypes, $teamParams);

        $data["kpis"] = [
            "High Zones" => (int)($kpi["high_zones"] ?? 0),
            "7 Days Cases" => (int)($kpi["seven_days"] ?? 0),
            "30 Days Cases" => (int)($kpi["thirty_days"] ?? 0),
            "Total Active Team" => (int)($teamRow["total"] ?? 0),
            "Risk Ward" => (int)($kpi["risk_wards"] ?? 0),
        ];

        $data["details"] = cr_fetch_all($conn, "
            SELECT
                CONCAT('Ward ', w.ward_no) AS `Ward`,
                COALESCE(a.area_name, 'N/A') AS `Area`,
                r.urgency_level AS `Risk Level`,
                r.complaint_count_30_days AS `Total Complaints`,
                r.complaint_count_7_days AS `7 Days Cases`,
                r.complaint_count_30_days AS `30 Days Cases`,
                COALESCE((
                    SELECT i2.issue_name
                    FROM complaints c2
                    INNER JOIN locations l2 ON c2.loc_id = l2.loc_id
                    LEFT JOIN issues i2 ON c2.issue_id = i2.issue_id
                    WHERE l2.area_id = r.area_id
                    GROUP BY i2.issue_id, i2.issue_name
                    ORDER BY COUNT(*) DESC
                    LIMIT 1
                ), 'N/A') AS `Most Common Issue`,
                DATE_FORMAT(r.last_reported_at, '%b %d, %Y') AS `Last Incident Date`
            FROM risk r
            LEFT JOIN wards w ON r.ward_id = w.ward_id
            LEFT JOIN areas a ON r.area_id = a.area_id
            WHERE $riskWhereSql
            ORDER BY
                CASE WHEN r.urgency_level = 'High' THEN 1 WHEN r.urgency_level = 'Medium' THEN 2 ELSE 3 END,
                r.complaint_count_30_days DESC
        ", $riskTypes, $riskParams);
    }

    if ($reportType === "maintenance_team_performance") {
        $assignWhere = ["DATE(ca.assigned_at) BETWEEN ? AND ?"];
        $assignTypes = "ss";
        $assignParams = [$startDate, $endDate];
        cr_location_where($assignWhere, $assignTypes, $assignParams, "l", $cityCorId, $thanaId, $wardId, $areaId);
        $assignWhereSql = implode(" AND ", $assignWhere);

        $kpi = cr_fetch_one($conn, "
            SELECT
                COUNT(DISTINCT mt.maintenance_team_id) AS total_teams,
                COUNT(ca.assignment_id) AS assigned_tasks,
                SUM(ca.assignment_status = 'in_progress' OR c.complaint_status = 'in_progress') AS in_progress_count,
                SUM(ca.assignment_status = 'completed' OR c.complaint_status IN ('solved_by_team','closed')) AS completed_count,
                SUM(ca.deadline_at < CURDATE() AND c.complaint_status NOT IN ('solved_by_team','closed','final_rejected','duplicate')) AS delayed_count,
                COUNT(DISTINCT mp.proof_id) AS proof_submitted,
                ROUND(AVG(mtr.rating), 1) AS avg_rating
            FROM maintenance_teams mt
            LEFT JOIN complaint_assignments ca ON mt.maintenance_team_id = ca.maintenance_team_id
            LEFT JOIN complaints c ON ca.complaint_id = c.complaint_id
            LEFT JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN maintenance_proofs mp ON ca.assignment_id = mp.assignment_id AND mp.proof_stage = 'after'
            LEFT JOIN maintenance_team_reviews mtr ON mt.maintenance_team_id = mtr.maintenance_team_id
            WHERE $assignWhereSql
        ", $assignTypes, $assignParams);

        $data["kpis"] = [
            "Total Teams" => (int)($kpi["total_teams"] ?? 0),
            "Assigned Tasks" => (int)($kpi["assigned_tasks"] ?? 0),
            "In Progress Tasks" => (int)($kpi["in_progress_count"] ?? 0),
            "Completed Tasks" => (int)($kpi["completed_count"] ?? 0),
            "Delayed Tasks" => (int)($kpi["delayed_count"] ?? 0),
            "Proof Submitted" => (int)($kpi["proof_submitted"] ?? 0),
            "Average Rating" => $kpi["avg_rating"] !== null ? $kpi["avg_rating"] : "0.0",
        ];

        $data["details"] = cr_fetch_all($conn, "
            SELECT
                mt.team_name AS `Team Name`,
                COALESCE(GROUP_CONCAT(DISTINCT CONCAT('Ward ', w.ward_no, ' / ', a.area_name) ORDER BY w.ward_no, a.area_name SEPARATOR ', '), 'N/A') AS `Assigned Ward / Area`,
                COUNT(DISTINCT ca.assignment_id) AS `Assigned Tasks`,
                SUM(ca.assignment_status = 'in_progress' OR c.complaint_status = 'in_progress') AS `In Progress Tasks`,
                SUM(ca.assignment_status = 'completed' OR c.complaint_status IN ('solved_by_team','closed')) AS `Completed Tasks`,
                SUM(ca.deadline_at < CURDATE() AND c.complaint_status NOT IN ('solved_by_team','closed','final_rejected','duplicate')) AS `Delayed Tasks`,
                COUNT(DISTINCT mp.proof_id) AS `Proof Submitted Count`,
                SUM(c.complaint_status = 'reopened') AS `Reopened After Completion Count`,
                COALESCE(ROUND(AVG(mtr.rating), 1), 0) AS `Average Citizen Rating`
            FROM maintenance_teams mt
            LEFT JOIN complaint_assignments ca ON mt.maintenance_team_id = ca.maintenance_team_id
            LEFT JOIN complaints c ON ca.complaint_id = c.complaint_id
            LEFT JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN wards w ON l.ward_id = w.ward_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN maintenance_proofs mp ON ca.assignment_id = mp.assignment_id AND mp.proof_stage = 'after'
            LEFT JOIN maintenance_team_reviews mtr ON mt.maintenance_team_id = mtr.maintenance_team_id
            WHERE $assignWhereSql
            GROUP BY mt.maintenance_team_id, mt.team_name
            ORDER BY COUNT(DISTINCT ca.assignment_id) DESC, mt.team_name ASC
        ", $assignTypes, $assignParams);
    }

    if ($reportType === "reopened_disputed_complaint") {
        $rrWhere = ["DATE(rr.created_at) BETWEEN ? AND ?"];
        $rrTypes = "ss";
        $rrParams = [$startDate, $endDate];
        cr_location_where($rrWhere, $rrTypes, $rrParams, "l", $cityCorId, $thanaId, $wardId, $areaId);
        $rrWhereSql = implode(" AND ", $rrWhere);

        $kpi = cr_fetch_one($conn, "
            SELECT
                SUM(rr.request_type = 'reopened' OR c.complaint_status = 'reopened') AS reopened,
                SUM(rr.request_type IN ('disputed','citizen_objection','false_completion') OR c.complaint_status = 'disputed') AS disputed,
                SUM(rr.request_status = 'pending' OR rr.request_status = '') AS pending_review,
                SUM(rr.request_status IN ('resolved','reassigned_same_team','reassigned_different_team')) AS resolved_cases
            FROM reopen_requests rr
            INNER JOIN complaints c ON rr.complaint_id = c.complaint_id
            INNER JOIN locations l ON c.loc_id = l.loc_id
            WHERE $rrWhereSql
        ", $rrTypes, $rrParams);

        $data["kpis"] = [
            "Total Reopened" => (int)($kpi["reopened"] ?? 0),
            "Total Disputed" => (int)($kpi["disputed"] ?? 0),
            "Pending Review" => (int)($kpi["pending_review"] ?? 0),
            "Resolved Cases" => (int)($kpi["resolved_cases"] ?? 0),
        ];

        $data["details"] = cr_fetch_all($conn, "
            SELECT
                c.complaint_code AS `Complaint Code`,
                COALESCE(i.issue_name, 'N/A') AS `Issue Type`,
                CONCAT('Ward ', w.ward_no) AS `Ward`,
                COALESCE(a.area_name, 'N/A') AS `Area`,
                rr.reason AS `Reopen / Dispute Reason`,
                REPLACE(UCASE(REPLACE(rr.request_type, '_', ' ')), 'TO', 'to') AS `Request Type`,
                COALESCE(NULLIF(REPLACE(UCASE(REPLACE(rr.request_status, '_', ' ')), 'TO', 'to'), ''), 'Pending') AS `Request Status`,
                REPLACE(UCASE(REPLACE(c.complaint_status, '_', ' ')), 'BY', 'by') AS `Current Complaint Status`,
                COALESCE(DATE_FORMAT(rr.handled_at, '%b %d, %Y'), DATE_FORMAT(rr.forwarded_at, '%b %d, %Y'), 'N/A') AS `Handled Date`
            FROM reopen_requests rr
            INNER JOIN complaints c ON rr.complaint_id = c.complaint_id
            INNER JOIN locations l ON c.loc_id = l.loc_id
            INNER JOIN wards w ON l.ward_id = w.ward_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE $rrWhereSql
            ORDER BY rr.created_at DESC
        ", $rrTypes, $rrParams);
    }

    if ($reportType === "citizen_feedback_objection_summary") {
        $fbWhere = ["DATE(COALESCE(mtr.created_at, f.created_at, rr.created_at)) BETWEEN ? AND ?"];
        $fbTypes = "ss";
        $fbParams = [$startDate, $endDate];
        cr_location_where($fbWhere, $fbTypes, $fbParams, "l", $cityCorId, $thanaId, $wardId, $areaId);
        $fbWhereSql = implode(" AND ", $fbWhere);

        $kpi = cr_fetch_one($conn, "
            SELECT
                COUNT(DISTINCT f.feedback_id) AS feedback_count,
                SUM(mtr.review_type IN ('satisfied','good_work')) AS satisfied,
                SUM(mtr.review_type = 'objection' OR f.feedback_type = 'false_completion') AS objection,
                SUM(rr.request_type = 'citizen_objection' AND rr.request_status = 'rejected') AS false_objection,
                SUM(rr.request_status IN ('resolved','reassigned_same_team','reassigned_different_team') OR c.complaint_status = 'reopened') AS resolved_objection
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN feedbacks f ON c.complaint_id = f.complaint_id
            LEFT JOIN maintenance_team_reviews mtr ON c.complaint_id = mtr.complaint_id
            LEFT JOIN reopen_requests rr ON c.complaint_id = rr.complaint_id
            WHERE $fbWhereSql
        ", $fbTypes, $fbParams);

        $data["kpis"] = [
            "Feedback" => (int)($kpi["feedback_count"] ?? 0),
            "Satisfied" => (int)($kpi["satisfied"] ?? 0),
            "Unsatisfied / Objection" => (int)($kpi["objection"] ?? 0),
            "False Objection" => (int)($kpi["false_objection"] ?? 0),
            "Resolved Objection / Reopen" => (int)($kpi["resolved_objection"] ?? 0),
        ];

        $data["details"] = cr_fetch_all($conn, "
            SELECT
                c.complaint_code AS `Complaint Code`,
                CASE
                    WHEN COALESCE(mtr.review_type, '') = 'objection' OR COALESCE(f.feedback_type, '') = 'false_completion' THEN 'Objection'
                    ELSE 'Feedback'
                END AS `Citizen Feedback Type`,
                COALESCE(mtr.rating, f.rating, 'N/A') AS `Rating`,
                CASE
                    WHEN COALESCE(mtr.review_type, '') IN ('satisfied','good_work') THEN 'Satisfied'
                    WHEN COALESCE(mtr.review_type, '') = 'objection' OR COALESCE(f.feedback_type, '') = 'false_completion' THEN 'Unsatisfied'
                    ELSE 'N/A'
                END AS `Satisfied / Unsatisfied`,
                COALESCE(rr.reason, f.feedback_text, mtr.review_text, 'N/A') AS `Objection Reason`,
                CONCAT('Ward ', w.ward_no) AS `Ward`,
                COALESCE(a.area_name, 'N/A') AS `Area`,
                COALESCE(mt.team_name, 'N/A') AS `Maintenance Team`,
                REPLACE(UCASE(REPLACE(c.complaint_status, '_', ' ')), 'BY', 'by') AS `Current Status`
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            INNER JOIN wards w ON l.ward_id = w.ward_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN feedbacks f ON c.complaint_id = f.complaint_id
            LEFT JOIN maintenance_team_reviews mtr ON c.complaint_id = mtr.complaint_id
            LEFT JOIN reopen_requests rr ON c.complaint_id = rr.complaint_id
            LEFT JOIN complaint_assignments ca ON c.complaint_id = ca.complaint_id
            LEFT JOIN maintenance_teams mt ON ca.maintenance_team_id = mt.maintenance_team_id
            WHERE $fbWhereSql
            GROUP BY c.complaint_id, f.feedback_id, mtr.review_id, rr.reopen_id, mt.team_name
            ORDER BY COALESCE(mtr.created_at, f.created_at, rr.created_at) DESC
            LIMIT 250
        ", $fbTypes, $fbParams);
    }

    $data["snapshot"] = array_slice($data["kpis"], 0, 6, true);
    return $data;
}

function cr_render_report_html($report)
{
    $html = "<article class='cr-preview-report'>";
    $html .= "<header class='cr-report-title'><h2>" . cr_safe($report["title"]) . "</h2></header>";

    $html .= "<section class='cr-section'><h3>Filter Summary</h3><table class='cr-filter-table'><thead><tr><th>Filter</th><th>Selected Value</th></tr></thead><tbody>";
    foreach ($report["filters"] as $label => $value) {
        $html .= "<tr><td>" . cr_safe($label) . "</td><td>" . cr_safe($value) . "</td></tr>";
    }
    $html .= "</tbody></table></section>";

    $html .= "<section class='cr-section'><h3>KPI Summary</h3><div class='cr-kpi-grid'>";
    foreach ($report["kpis"] as $label => $value) {
        $html .= "<div class='cr-kpi-card'><span>" . cr_safe($label) . "</span><strong>" . cr_safe(cr_format_number($value)) . "</strong></div>";
    }
    $html .= "</div></section>";

    $html .= "<section class='cr-section'><h3>Data Snapshot</h3><div class='cr-snapshot'>";
    foreach ($report["snapshot"] as $label => $value) {
        $html .= "<div><span>" . cr_safe($label) . "</span><strong>" . cr_safe(cr_format_number($value)) . "</strong></div>";
    }
    $html .= "</div></section>";

    $html .= "<section class='cr-section'><h3>Report Details</h3><div class='cr-detail-wrap'><table class='cr-detail-table'>";
    if (!empty($report["details"])) {
        $headers = array_keys($report["details"][0]);
        $html .= cr_render_colgroup($headers);
        $html .= "<thead><tr>";
        foreach ($headers as $header) {
            $html .= "<th>" . cr_safe($header) . "</th>";
        }
        $html .= "</tr></thead><tbody>";
        foreach ($report["details"] as $row) {
            $html .= "<tr>";
            foreach ($headers as $header) {
                $html .= "<td>" . cr_safe($row[$header] ?? "") . "</td>";
            }
            $html .= "</tr>";
        }
        $html .= "</tbody>";
    } else {
        $html .= "<tbody><tr><td>No records found for the selected filters.</td></tr></tbody>";
    }
    $html .= "</table></div></section></article>";

    return $html;
}

function cr_render_colgroup($headers)
{
    $count = count($headers);
    if ($count <= 0) return "";

    $systemWidths = [
        "Complaint Code" => 13,
        "Issue Type" => 14,
        "Ward" => 8,
        "Area" => 13,
        "Current Status" => 15,
        "Priority" => 9,
        "Submitted Date" => 14,
        "Last Updated Date" => 14,
    ];

    $widths = [];
    foreach ($headers as $header) {
        $widths[] = $systemWidths[$header] ?? null;
    }

    if (in_array(null, $widths, true)) {
        $equal = floor(100 / $count);
        $widths = array_fill(0, $count, $equal);
        $widths[$count - 1] += 100 - array_sum($widths);
    }

    $html = "<colgroup>";
    foreach ($widths as $width) {
        $html .= "<col style='width:" . (float)$width . "%'>";
    }
    $html .= "</colgroup>";

    return $html;
}

function cr_report_css()
{
    return "
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef2f7; color: #172033; font-family: Arial, sans-serif; padding: 24px; }
        .cr-preview-report { width: 210mm; max-width: 100%; margin: 0 auto; background: #fff; border: 1px solid #d7dee8; padding: 14mm; }
        .cr-report-title { padding-bottom: 14px; margin-bottom: 16px; border-bottom: 3px solid #0f766e; }
        .cr-report-title h2 { margin: 0; text-align: center; font-size: 22px; line-height: 1.25; color: #0f2d3f; font-weight: 800; }
        .cr-section { margin-top: 16px; page-break-inside: avoid; }
        .cr-section h3 { font-size: 12px; text-transform: uppercase; color: #0f766e; margin: 0 0 8px; letter-spacing: 0; font-weight: 800; }
        .cr-filter-table, .cr-detail-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .cr-filter-table th, .cr-filter-table td, .cr-detail-table th, .cr-detail-table td { border: 1px solid #cbd5e1; padding: 7px 8px; text-align: left; vertical-align: top; font-size: 10.5px; line-height: 1.35; word-wrap: break-word; overflow-wrap: anywhere; white-space: normal; }
        .cr-filter-table th, .cr-detail-table th { background: #e7f3f5; color: #12333f; font-weight: 800; }
        .cr-filter-table th:first-child, .cr-filter-table td:first-child { width: 34%; }
        .cr-kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }
        .cr-kpi-card { min-height: 72px; border: 1px solid #cbd5e1; background: #f8fafc; padding: 10px; display: flex; flex-direction: column; justify-content: space-between; }
        .cr-kpi-card span, .cr-snapshot span { display: block; font-size: 9.5px; line-height: 1.25; color: #64748b; font-weight: 800; text-transform: uppercase; overflow-wrap: anywhere; }
        .cr-kpi-card strong { display: block; margin-top: 8px; font-size: 20px; line-height: 1.1; color: #0f766e; font-weight: 800; overflow-wrap: anywhere; }
        .cr-snapshot { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; border: 1px solid #cbd5e1; background: #f8fafc; padding: 10px; }
        .cr-snapshot div { border: 1px solid #dde6f0; background: #fff; padding: 8px; min-height: 48px; }
        .cr-snapshot strong { display: block; margin-top: 5px; color: #0f172a; font-size: 14px; line-height: 1.2; font-weight: 800; overflow-wrap: anywhere; }
        .cr-detail-wrap { width: 100%; overflow: visible; }
        .cr-detail-table { min-width: 0; font-size: 10px; }
        .cr-detail-table tbody tr:nth-child(even) { background: #f8fafc; }
        @media screen and (max-width: 900px) {
            body { padding: 12px; }
            .cr-preview-report { padding: 18px; }
            .cr-kpi-grid, .cr-snapshot { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media screen and (max-width: 620px) {
            .cr-kpi-grid, .cr-snapshot { grid-template-columns: 1fr; }
        }
    ";
}

function cr_export_html_document($report)
{
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>" . cr_safe($report["title"]) . "</title>"
        . "<style>" . cr_report_css() . "</style></head><body>" . cr_render_report_html($report) . "</body></html>";
}

function cr_write_csv($path, $report)
{
    $file = fopen($path, "w");
    if (!$file) throw new Exception("Unable to create CSV file.");

    fputcsv($file, [$report["title"]]);
    fputcsv($file, []);
    fputcsv($file, ["Filter", "Value"]);
    foreach ($report["filters"] as $label => $value) fputcsv($file, [$label, $value]);
    fputcsv($file, []);
    fputcsv($file, ["KPI", "Value"]);
    foreach ($report["kpis"] as $label => $value) fputcsv($file, [$label, $value]);
    fputcsv($file, []);

    if (!empty($report["details"])) {
        $headers = array_keys($report["details"][0]);
        fputcsv($file, $headers);
        foreach ($report["details"] as $row) {
            fputcsv($file, array_map(function ($header) use ($row) {
                return $row[$header] ?? "";
            }, $headers));
        }
    } else {
        fputcsv($file, ["No records found for the selected filters."]);
    }

    fclose($file);
}

function cr_pdf_escape($text)
{
    $original = (string)$text;
    $text = preg_replace('/[^\x20-\x7E]/', '', $original);
    if (trim($text) === "" && trim($original) !== "") {
        $text = "N/A";
    }
    return str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $text);
}

function cr_pdf_text($x, $y, $text, $size = 9, $font = "F1")
{
    return "BT /{$font} {$size} Tf {$x} {$y} Td (" . cr_pdf_escape($text) . ") Tj ET\n";
}

function cr_pdf_rect($x, $y, $w, $h, $fill = false)
{
    return "{$x} {$y} {$w} {$h} re " . ($fill ? "f" : "S") . "\n";
}

function cr_pdf_wrap($text, $maxChars)
{
    $text = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\x20-\x7E]/', ' ', (string)$text)));
    if ($text === "") return ["N/A"];

    $words = explode(" ", $text);
    $lines = [];
    $line = "";

    foreach ($words as $word) {
        while (strlen($word) > $maxChars) {
            if ($line !== "") {
                $lines[] = $line;
                $line = "";
            }
            $lines[] = substr($word, 0, $maxChars);
            $word = substr($word, $maxChars);
        }

        $candidate = $line === "" ? $word : $line . " " . $word;
        if (strlen($candidate) <= $maxChars) {
            $line = $candidate;
        } else {
            if ($line !== "") $lines[] = $line;
            $line = $word;
        }
    }

    if ($line !== "") $lines[] = $line;
    return array_slice($lines, 0, 6);
}

function cr_pdf_center_text($y, $text, $size = 14, $font = "F2")
{
    $clean = preg_replace('/[^\x20-\x7E]/', '', (string)$text);
    $width = strlen($clean) * $size * 0.47;
    $x = max(36, (595 - $width) / 2);
    return cr_pdf_text($x, $y, $clean, $size, $font);
}

function cr_write_pdf($path, $report)
{
    $pages = [];
    $content = "";
    $y = 0;
    $margin = 34;
    $pageBottom = 42;
    $pageWidth = 595;
    $usableWidth = $pageWidth - ($margin * 2);

    $newPage = function () use (&$pages, &$content, &$y, $margin, $usableWidth) {
        if ($content !== "") {
            $pages[] = $content;
        }

        $content = "1 1 1 rg\n" . cr_pdf_rect(0, 0, 595, 842, true);
        $content .= "0.96 0.98 0.99 rg\n" . cr_pdf_rect($margin, 36, $usableWidth, 770, true);
        $content .= "0.78 0.85 0.88 RG\n" . cr_pdf_rect($margin, 36, $usableWidth, 770);
        $y = 780;
    };

    $ensureSpace = function ($height) use (&$newPage, &$y, $pageBottom) {
        if ($y - $height < $pageBottom) {
            $newPage();
        }
    };

    $sectionTitle = function ($title) use (&$content, &$y, $margin, &$ensureSpace) {
        $ensureSpace(22);
        $content .= "0.02 0.36 0.34 rg\n" . cr_pdf_text($margin + 12, $y, $title, 10, "F2");
        $y -= 15;
    };

    $newPage();

    $content .= "0.05 0.24 0.28 rg\n";
    $content .= cr_pdf_center_text($y, $report["title"], 16, "F2");
    $y -= 18;
    $content .= "0.02 0.46 0.42 RG\n{$margin} {$y} m " . ($margin + $usableWidth) . " {$y} l S\n";
    $y -= 24;

    $sectionTitle("Filter Summary");
    $rowH = 20;
    $labelW = 170;
    $valueW = $usableWidth - $labelW;
    $content .= "0.90 0.96 0.97 rg\n" . cr_pdf_rect($margin, $y - $rowH + 5, $usableWidth, $rowH, true);
    $content .= "0.72 0.80 0.84 RG\n" . cr_pdf_rect($margin, $y - $rowH + 5, $usableWidth, $rowH);
    $content .= "0.05 0.14 0.18 rg\n" . cr_pdf_text($margin + 8, $y - 8, "Filter", 8, "F2");
    $content .= cr_pdf_text($margin + $labelW + 8, $y - 8, "Selected Value", 8, "F2");
    $y -= $rowH;

    foreach ($report["filters"] as $label => $value) {
        $lines = cr_pdf_wrap($value, 68);
        $height = max(22, (count($lines) * 9) + 12);
        $ensureSpace($height + 4);
        $content .= "1 1 1 rg\n" . cr_pdf_rect($margin, $y - $height + 5, $usableWidth, $height, true);
        $content .= "0.78 0.85 0.88 RG\n" . cr_pdf_rect($margin, $y - $height + 5, $usableWidth, $height);
        $content .= "{$margin} " . ($y - $height + 5) . " m {$margin} " . ($y + 5) . " l S\n";
        $content .= ($margin + $labelW) . " " . ($y - $height + 5) . " m " . ($margin + $labelW) . " " . ($y + 5) . " l S\n";
        $content .= "0.05 0.14 0.18 rg\n" . cr_pdf_text($margin + 8, $y - 8, $label, 8, "F2");
        foreach ($lines as $idx => $line) {
            $content .= cr_pdf_text($margin + $labelW + 8, $y - 8 - ($idx * 9), $line, 8);
        }
        $y -= $height;
    }

    $y -= 14;
    $sectionTitle("KPI Summary");
    $cardGap = 8;
    $cardCols = 4;
    $cardW = ($usableWidth - ($cardGap * ($cardCols - 1))) / $cardCols;
    $cardH = 54;
    $x = $margin;
    $col = 0;
    foreach ($report["kpis"] as $label => $value) {
        if ($col === 0) $ensureSpace($cardH + 8);
        $content .= "0.97 0.99 1 rg\n" . cr_pdf_rect($x, $y - $cardH + 5, $cardW, $cardH, true);
        $content .= "0.76 0.84 0.88 RG\n" . cr_pdf_rect($x, $y - $cardH + 5, $cardW, $cardH);
        $labelLines = array_slice(cr_pdf_wrap($label, 21), 0, 2);
        $content .= "0.34 0.42 0.50 rg\n";
        foreach ($labelLines as $idx => $line) {
            $content .= cr_pdf_text($x + 7, $y - 10 - ($idx * 8), $line, 7, "F2");
        }
        $content .= "0.02 0.46 0.42 rg\n" . cr_pdf_text($x + 7, $y - 39, cr_format_number($value), 13, "F2");
        $col++;
        if ($col >= $cardCols) {
            $col = 0;
            $x = $margin;
            $y -= $cardH + 8;
        } else {
            $x += $cardW + $cardGap;
        }
    }
    if ($col !== 0) $y -= $cardH + 8;

    $y -= 10;
    $sectionTitle("Data Snapshot");
    $snapH = 52;
    $ensureSpace($snapH + 8);
    $snapItems = array_slice($report["snapshot"], 0, 6, true);
    $snapCols = 3;
    $snapW = ($usableWidth - ($cardGap * ($snapCols - 1))) / $snapCols;
    $sx = $margin;
    $snapCol = 0;
    foreach ($snapItems as $label => $value) {
        if ($snapCol === 0) $ensureSpace($snapH + 6);
        $content .= "0.98 0.99 1 rg\n" . cr_pdf_rect($sx, $y - $snapH + 5, $snapW, $snapH, true);
        $content .= "0.78 0.85 0.88 RG\n" . cr_pdf_rect($sx, $y - $snapH + 5, $snapW, $snapH);
        $content .= "0.34 0.42 0.50 rg\n" . cr_pdf_text($sx + 7, $y - 12, cr_pdf_wrap($label, 22)[0], 7, "F2");
        $content .= "0.05 0.14 0.18 rg\n" . cr_pdf_text($sx + 7, $y - 31, cr_format_number($value), 11, "F2");
        $snapCol++;
        if ($snapCol >= $snapCols) {
            $snapCol = 0;
            $sx = $margin;
            $y -= $snapH + 6;
        } else {
            $sx += $snapW + $cardGap;
        }
    }
    if ($snapCol !== 0) $y -= $snapH + 6;

    $y -= 10;
    $sectionTitle("Report Details");
    $rows = $report["details"];
    if (empty($rows)) {
        $ensureSpace(28);
        $content .= "1 1 1 rg\n" . cr_pdf_rect($margin, $y - 24, $usableWidth, 24, true);
        $content .= "0.05 0.14 0.18 rg\n" . cr_pdf_text($margin + 8, $y - 15, "No records found for the selected filters.", 9);
    } else {
        $headers = array_keys($rows[0]);
        $colCount = count($headers);
        $colW = $usableWidth / $colCount;
        $headerH = 32;

        $drawHeader = function () use (&$content, &$y, $margin, $usableWidth, $headers, $colW, $headerH) {
            $content .= "0.05 0.24 0.28 rg\n" . cr_pdf_rect($margin, $y - $headerH + 5, $usableWidth, $headerH, true);
            $content .= "1 1 1 rg\n";
            foreach ($headers as $idx => $header) {
                $lines = array_slice(cr_pdf_wrap($header, max(8, (int)floor($colW / 4))), 0, 3);
                foreach ($lines as $lineIndex => $line) {
                    $content .= cr_pdf_text($margin + ($idx * $colW) + 4, $y - 9 - ($lineIndex * 8), $line, 6, "F2");
                }
            }
            $y -= $headerH;
        };

        $ensureSpace($headerH + 20);
        $drawHeader();

        foreach ($rows as $rowIndex => $row) {
            $cellLines = [];
            $maxLines = 1;
            foreach ($headers as $header) {
                $lines = array_slice(cr_pdf_wrap($row[$header] ?? "", max(8, (int)floor($colW / 3.8))), 0, 5);
                $cellLines[$header] = $lines;
                $maxLines = max($maxLines, count($lines));
            }

            $rowHeight = max(24, ($maxLines * 8) + 12);
            if ($y - $rowHeight < $pageBottom) {
                $newPage();
                $sectionTitle("Report Details");
                $drawHeader();
            }

            $content .= ($rowIndex % 2 === 0 ? "0.99 1 1 rg\n" : "1 1 1 rg\n") . cr_pdf_rect($margin, $y - $rowHeight + 5, $usableWidth, $rowHeight, true);
            $content .= "0.80 0.86 0.89 RG\n" . cr_pdf_rect($margin, $y - $rowHeight + 5, $usableWidth, $rowHeight);
            $content .= "0.05 0.14 0.18 rg\n";
            foreach ($headers as $idx => $header) {
                foreach ($cellLines[$header] as $lineIndex => $line) {
                    $content .= cr_pdf_text($margin + ($idx * $colW) + 4, $y - 8 - ($lineIndex * 8), $line, 6);
                }
            }
            $y -= $rowHeight;
        }
    }

    if ($content !== "") {
        $pages[] = $content;
    }

    cr_create_pdf($path, $pages);
}

function cr_create_pdf($path, $pages)
{
    $objects = [
        "<< /Type /Catalog /Pages 2 0 R >>",
    ];

    $pageCount = count($pages);
    $pageObjectIds = [];
    $contentObjectIds = [];
    $nextId = 3;

    for ($i = 0; $i < $pageCount; $i++) {
        $pageObjectIds[] = $nextId++;
        $contentObjectIds[] = $nextId++;
    }

    $fontRegularId = $nextId++;
    $fontBoldId = $nextId++;

    $objects[] = "<< /Type /Pages /Kids [" . implode(" ", array_map(function ($id) {
        return $id . " 0 R";
    }, $pageObjectIds)) . "] /Count {$pageCount} >>";

    foreach ($pages as $idx => $pageContent) {
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 {$fontRegularId} 0 R /F2 {$fontBoldId} 0 R >> >> /Contents " . $contentObjectIds[$idx] . " 0 R >>";
        $objects[] = "<< /Length " . strlen($pageContent) . " >>\nstream\n" . $pageContent . "\nendstream";
    }

    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= str_pad((string)$offsets[$i], 10, "0", STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    file_put_contents($path, $pdf);
}

function cr_safe_filename($value)
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace("/[^a-z0-9_\\-]+/", "_", $value);
    return trim($value, "_") ?: "central_report";
}

function cr_write_export_file($path, $format, $report)
{
    if ($format === "CSV") {
        cr_write_csv($path, $report);
        return;
    }

    if ($format === "PDF") {
        cr_write_pdf($path, $report);
        return;
    }

    file_put_contents($path, cr_export_html_document($report));
}

function cr_export_extension($format)
{
    if ($format === "CSV") return "csv";
    if ($format === "Excel") return "xls";
    if ($format === "DOCS") return "doc";
    return "pdf";
}

function cr_export_mime($format)
{
    $types = [
        "PDF" => "application/pdf",
        "Excel" => "application/vnd.ms-excel",
        "CSV" => "text/csv",
        "DOCS" => "application/msword",
    ];

    return $types[$format] ?? "application/octet-stream";
}
