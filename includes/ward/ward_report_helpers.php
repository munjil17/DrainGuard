<?php
require_once __DIR__ . "/../central/central_report_helpers.php";

function wr_safe($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function wr_report_types()
{
    return [
        "ward_complaint_summary" => "Ward Complaint Summary Report",
        "ward_verification_performance" => "Ward Verification Performance Report",
        "ward_area_complaint_analysis" => "Ward Area Complaint Analysis Report",
        "local_team_assignment_performance" => "Local Team Assignment Performance Report",
        "in_progress_delayed_work" => "In Progress and Delayed Work Report",
        "ward_reopened_disputed_cases" => "Ward Reopened and Disputed Cases Report",
        "ward_resolution_quality" => "Ward Resolution Quality Report",
    ];
}

function wr_report_type_label($type)
{
    $types = wr_report_types();
    return $types[$type] ?? ucwords(str_replace("_", " ", (string)$type));
}

function wr_period_label($period)
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

function wr_date_range($period, $startDate, $endDate)
{
    $today = date("Y-m-d");
    if ($period === "last_7_days") return [date("Y-m-d", strtotime("-6 days")), $today];
    if ($period === "last_30_days") return [date("Y-m-d", strtotime("-29 days")), $today];
    if ($period === "last_3_months") return [date("Y-m-d", strtotime("-3 months")), $today];
    if ($period === "last_6_months") return [date("Y-m-d", strtotime("-6 months")), $today];
    if ($period === "this_year") return [date("Y-01-01"), $today];

    return [$startDate, $endDate];
}

function wr_format_date($date)
{
    if (!$date) return "N/A";
    $time = strtotime($date);
    return $time ? date("M d, Y", $time) : "N/A";
}

function wr_format_number($value)
{
    if (is_numeric($value)) {
        return number_format((float)$value, ((float)$value == (int)$value) ? 0 : 1);
    }

    return (string)$value;
}

function wr_status_label($status)
{
    $status = trim((string)$status);
    return $status === "" ? "All Statuses" : ucwords(str_replace("_", " ", $status));
}

function wr_fetch_all($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new Exception("Unable to prepare report data. " . mysqli_error($conn));
    }

    if ($types !== "" && count($params) > 0) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception("Unable to load report data. " . $error);
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

function wr_fetch_one($conn, $sql, $types = "", $params = [])
{
    $rows = wr_fetch_all($conn, $sql, $types, $params);
    return $rows[0] ?? [];
}

function wr_get_ward_context($conn, $userId)
{
    $row = wr_fetch_one($conn, "
        SELECT
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
        INNER JOIN wards w ON wo.assigned_ward_id = w.ward_id
        LEFT JOIN city_corporations cc ON w.city_cor_id = cc.city_cor_id
        LEFT JOIN thanas t ON w.thana_id = t.thana_id
        WHERE wo.user_id = ?
        LIMIT 1
    ", "i", [(int)$userId]);

    if (!$row) {
        throw new Exception("No assigned ward found for this Ward Officer.");
    }

    return [
        "ward_officer_id" => (int)$row["ward_officer_id"],
        "user_id" => (int)$row["user_id"],
        "ward_id" => (int)$row["assigned_ward_id"],
        "ward_no" => $row["ward_no"] ?? "",
        "ward_name" => $row["ward_name"] ?? "",
        "city_cor_id" => (int)($row["city_cor_id"] ?? 0),
        "thana_id" => (int)($row["thana_id"] ?? 0),
        "city_cor_name" => $row["city_cor_name"] ?? "City Corporation",
        "thana_name" => $row["thana_name"] ?? "Thana",
        "full_name" => $row["full_name"] ?? "Ward Officer",
    ];
}

function wr_area_label($conn, $wardId, $areaId)
{
    if ((int)$areaId <= 0) return "All Areas";
    $row = wr_fetch_one($conn, "
        SELECT area_name
        FROM areas
        WHERE area_id = ? AND ward_id = ?
        LIMIT 1
    ", "ii", [(int)$areaId, (int)$wardId]);

    return $row["area_name"] ?? "Selected Area";
}

function wr_filter_summary($conn, $reportType, $period, $startDate, $endDate, $wardContext, $areaId, $status, $priority)
{
    $wardLabel = "Ward " . ($wardContext["ward_no"] ?? "");
    if (!empty($wardContext["ward_name"])) {
        $wardLabel .= " - " . $wardContext["ward_name"];
    }

    return [
        "Report Type" => wr_report_type_label($reportType),
        "Time Period" => wr_period_label($period),
        "Date Range" => wr_format_date($startDate) . " - " . wr_format_date($endDate),
        "Ward" => $wardLabel,
        "Area" => wr_area_label($conn, $wardContext["ward_id"], $areaId),
        "Status" => wr_status_label($status),
        "Priority / Risk Level" => trim((string)$priority) !== "" ? $priority : "All Priorities",
    ];
}

function wr_add_common_filters(&$where, &$types, &$params, $areaId, $status, $priority, $statusAlias = "c", $issueAlias = "i")
{
    if ((int)$areaId > 0) {
        $where[] = "l.area_id = ?";
        $types .= "i";
        $params[] = (int)$areaId;
    }

    if (trim((string)$status) !== "") {
        $where[] = "{$statusAlias}.complaint_status = ?";
        $types .= "s";
        $params[] = trim((string)$status);
    }

    if (trim((string)$priority) !== "") {
        $where[] = "{$issueAlias}.priority = ?";
        $types .= "s";
        $params[] = trim((string)$priority);
    }
}

function wr_build_report($conn, $reportType, $period, $startDate, $endDate, $wardContext, $areaId, $status, $priority)
{
    if (!array_key_exists($reportType, wr_report_types())) {
        throw new Exception("Please select a valid report type.");
    }

    $wardId = (int)$wardContext["ward_id"];
    $data = [
        "title" => wr_report_type_label($reportType),
        "filters" => wr_filter_summary($conn, $reportType, $period, $startDate, $endDate, $wardContext, $areaId, $status, $priority),
        "kpis" => [],
        "details" => [],
        "snapshot" => [],
    ];

    $where = ["l.ward_id = ?", "DATE(c.submitted_at) BETWEEN ? AND ?"];
    $types = "iss";
    $params = [$wardId, $startDate, $endDate];
    wr_add_common_filters($where, $types, $params, $areaId, $status, $priority);
    $complaintWhere = implode(" AND ", $where);

    if ($reportType === "ward_complaint_summary") {
        $kpi = wr_fetch_one($conn, "
            SELECT
                COUNT(*) AS total,
                SUM(c.complaint_status = 'pending_verification') AS pending_verification,
                SUM(c.complaint_status = 'verified_by_ward') AS verified,
                SUM(c.complaint_status = 'team_assigned') AS team_assigned,
                SUM(c.complaint_status = 'in_progress') AS in_progress,
                SUM(c.complaint_status = 'solved_by_team') AS solved_by_team,
                SUM(c.complaint_status = 'closed') AS closed,
                SUM(c.complaint_status = 'reopened') AS reopened,
                SUM(c.complaint_status IN ('rejected_by_central','rejected_by_ward','final_rejected')) AS rejected,
                SUM(c.complaint_status = 'duplicate') AS duplicate
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE $complaintWhere
        ", $types, $params);

        $data["kpis"] = [
            "Total Complaints" => (int)($kpi["total"] ?? 0),
            "Pending Verification" => (int)($kpi["pending_verification"] ?? 0),
            "Verified Complaints" => (int)($kpi["verified"] ?? 0),
            "Team Assigned" => (int)($kpi["team_assigned"] ?? 0),
            "In Progress" => (int)($kpi["in_progress"] ?? 0),
            "Solved by Team" => (int)($kpi["solved_by_team"] ?? 0),
            "Closed Complaints" => (int)($kpi["closed"] ?? 0),
            "Reopened Complaints" => (int)($kpi["reopened"] ?? 0),
            "Rejected Complaints" => (int)($kpi["rejected"] ?? 0),
            "Duplicate Complaints" => (int)($kpi["duplicate"] ?? 0),
        ];

        $data["details"] = wr_fetch_all($conn, "
            SELECT
                c.complaint_code AS `Complaint Code`,
                COALESCE(i.issue_name, 'N/A') AS `Issue Type`,
                COALESCE(a.area_name, 'N/A') AS `Area`,
                REPLACE(UCASE(REPLACE(c.complaint_status, '_', ' ')), 'BY', 'by') AS `Status`,
                COALESCE(mt.team_name, 'N/A') AS `Assigned Team`,
                COALESCE(DATE_FORMAT(ca.deadline_at, '%b %d, %Y'), 'N/A') AS `Deadline`,
                DATE_FORMAT(c.submitted_at, '%b %d, %Y') AS `Submitted Date`,
                COALESCE(i.priority, 'Low') AS `Priority`
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            LEFT JOIN complaint_assignments ca ON c.complaint_id = ca.complaint_id
            LEFT JOIN maintenance_teams mt ON ca.maintenance_team_id = mt.maintenance_team_id
            WHERE $complaintWhere
            GROUP BY c.complaint_id, ca.assignment_id, mt.team_name
            ORDER BY c.submitted_at DESC
            LIMIT 250
        ", $types, $params);
    }

    if ($reportType === "ward_verification_performance") {
        $kpi = wr_fetch_one($conn, "
            SELECT
                COUNT(DISTINCT CASE WHEN c.complaint_status = 'pending_verification' THEN c.complaint_id END) AS pending_verification,
                COUNT(DISTINCT CASE WHEN c.complaint_status = 'verified_by_ward' THEN c.complaint_id END) AS verified_by_ward,
                COUNT(DISTINCT CASE WHEN c.complaint_status = 'rejected_by_ward' OR cd.decision_type = 'ward_reject' THEN c.complaint_id END) AS rejected_by_ward,
                COUNT(DISTINCT CASE WHEN c.complaint_status = 'duplicate' OR cd.decision_type = 'duplicate' THEN c.complaint_id END) AS duplicate_marked
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            LEFT JOIN complaint_decisions cd ON c.complaint_id = cd.complaint_id
            WHERE $complaintWhere
        ", $types, $params);

        $data["kpis"] = [
            "Pending Verification" => (int)($kpi["pending_verification"] ?? 0),
            "Verified by Ward" => (int)($kpi["verified_by_ward"] ?? 0),
            "Rejected by Ward" => (int)($kpi["rejected_by_ward"] ?? 0),
            "Duplicate Marked" => (int)($kpi["duplicate_marked"] ?? 0),
        ];

        $verifyWhere = ["l.ward_id = ?", "DATE(c.updated_at) BETWEEN ? AND ?"];
        $verifyTypes = "iss";
        $verifyParams = [$wardId, $startDate, $endDate];
        wr_add_common_filters($verifyWhere, $verifyTypes, $verifyParams, $areaId, $status, $priority);
        $verifyWhereSql = implode(" AND ", $verifyWhere);

        $data["details"] = wr_fetch_all($conn, "
            SELECT
                c.complaint_code AS `Complaint Code`,
                COALESCE(i.issue_name, 'N/A') AS `Issue Type`,
                COALESCE(a.area_name, 'N/A') AS `Area`,
                REPLACE(UCASE(REPLACE(c.complaint_status, '_', ' ')), 'BY', 'by') AS `Verification Status`,
                COALESCE(REPLACE(UCASE(REPLACE(cd.decision_type, '_', ' ')), 'WARD', 'Ward'), 'N/A') AS `Decision Type`,
                COALESCE(DATE_FORMAT(cd.created_at, '%b %d, %Y'), 'N/A') AS `Decision Date`,
                DATE_FORMAT(c.updated_at, '%b %d, %Y') AS `Last Updated`
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            LEFT JOIN complaint_decisions cd ON c.complaint_id = cd.complaint_id
            WHERE $verifyWhereSql
            AND c.complaint_status IN ('pending_verification','verified_by_ward','rejected_by_ward','duplicate')
            ORDER BY c.updated_at DESC
            LIMIT 250
        ", $verifyTypes, $verifyParams);
    }

    if ($reportType === "ward_area_complaint_analysis") {
        $kpi = wr_fetch_one($conn, "
            SELECT
                COUNT(*) AS total_areas,
                SUM(high_count) AS high_count,
                SUM(reopened_disputed) AS reopened_disputed
            FROM (
                SELECT l.area_id, COUNT(c.complaint_id) AS total_complaints, SUM(i.priority = 'High') AS high_count,
                    SUM(c.complaint_status IN ('reopened','disputed')) AS reopened_disputed
                FROM complaints c
                INNER JOIN locations l ON c.loc_id = l.loc_id
                LEFT JOIN issues i ON c.issue_id = i.issue_id
                WHERE $complaintWhere
                GROUP BY l.area_id
            ) area_totals
        ", $types, $params);

        $most = wr_fetch_one($conn, "
            SELECT COALESCE(a.area_name, 'N/A') AS area_name, COUNT(c.complaint_id) AS total
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE $complaintWhere
            GROUP BY l.area_id, a.area_name
            ORDER BY total DESC
            LIMIT 1
        ", $types, $params);

        $data["kpis"] = [
            "Total Areas" => (int)($kpi["total_areas"] ?? 0),
            "Most Affected Area" => $most["area_name"] ?? "N/A",
            "High / Emergency Complaints" => (int)($kpi["high_count"] ?? 0),
            "Reopened / Disputed Cases" => (int)($kpi["reopened_disputed"] ?? 0),
        ];

        $data["details"] = wr_fetch_all($conn, "
            SELECT
                COALESCE(a.area_name, 'N/A') AS `Area Name`,
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
                ), 'N/A') AS `Most Common Issue`,
                SUM(i.priority = 'High') AS `High / Emergency Count`,
                SUM(c.complaint_status IN ('solved_by_team','closed')) AS `Solved Count`,
                SUM(c.complaint_status IN ('reopened','disputed')) AS `Reopened / Disputed Count`,
                DATE_FORMAT(MAX(c.submitted_at), '%b %d, %Y') AS `Last Complaint Date`
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE $complaintWhere
            GROUP BY l.area_id, a.area_name
            ORDER BY COUNT(c.complaint_id) DESC
        ", $types, $params);
    }

    if ($reportType === "local_team_assignment_performance") {
        $assignWhere = ["ca.ward_id = ?", "DATE(ca.assigned_at) BETWEEN ? AND ?"];
        $assignTypes = "iss";
        $assignParams = [$wardId, $startDate, $endDate];
        wr_add_common_filters($assignWhere, $assignTypes, $assignParams, $areaId, $status, $priority);
        $assignWhereSql = implode(" AND ", $assignWhere);

        $kpi = wr_fetch_one($conn, "
            SELECT
                COUNT(DISTINCT ca.maintenance_team_id) AS total_teams,
                COUNT(DISTINCT ca.assignment_id) AS assigned_tasks,
                COUNT(DISTINCT CASE WHEN ca.assignment_status = 'in_progress' OR c.complaint_status = 'in_progress' THEN ca.assignment_id END) AS in_progress,
                COUNT(DISTINCT CASE WHEN ca.assignment_status = 'completed' OR c.complaint_status IN ('solved_by_team','closed') THEN ca.assignment_id END) AS completed,
                COUNT(DISTINCT CASE WHEN ca.deadline_at < CURDATE() AND c.complaint_status NOT IN ('solved_by_team','closed','final_rejected','duplicate') THEN ca.assignment_id END) AS delayed_count,
                COUNT(DISTINCT mp.proof_id) AS proof_submitted,
                AVG(mtr.rating) AS avg_rating
            FROM complaint_assignments ca
            INNER JOIN complaints c ON ca.complaint_id = c.complaint_id
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            LEFT JOIN maintenance_proofs mp ON ca.assignment_id = mp.assignment_id AND mp.proof_stage = 'after'
            LEFT JOIN maintenance_team_reviews mtr ON ca.maintenance_team_id = mtr.maintenance_team_id
            WHERE $assignWhereSql
        ", $assignTypes, $assignParams);

        $data["kpis"] = [
            "Total Local Teams" => (int)($kpi["total_teams"] ?? 0),
            "Assigned Tasks" => (int)($kpi["assigned_tasks"] ?? 0),
            "In Progress Tasks" => (int)($kpi["in_progress"] ?? 0),
            "Completed Tasks" => (int)($kpi["completed"] ?? 0),
            "Delayed Tasks" => (int)($kpi["delayed_count"] ?? 0),
            "Proof Submitted" => (int)($kpi["proof_submitted"] ?? 0),
            "Average Rating" => round((float)($kpi["avg_rating"] ?? 0), 1),
        ];

        $data["details"] = wr_fetch_all($conn, "
            SELECT
                COALESCE(mt.team_name, 'N/A') AS `Team Name`,
                COUNT(DISTINCT ca.assignment_id) AS `Assigned Tasks`,
                SUM(ca.assignment_status = 'in_progress' OR c.complaint_status = 'in_progress') AS `In Progress Tasks`,
                SUM(ca.assignment_status = 'completed' OR c.complaint_status IN ('solved_by_team','closed')) AS `Completed Tasks`,
                SUM(ca.deadline_at < CURDATE() AND c.complaint_status NOT IN ('solved_by_team','closed','final_rejected','duplicate')) AS `Delayed Tasks`,
                COUNT(DISTINCT mp.proof_id) AS `Proof Submitted Count`,
                SUM(c.complaint_status = 'reopened') AS `Reopened After Team Completion`,
                ROUND(AVG(mtr.rating), 1) AS `Average Rating`
            FROM complaint_assignments ca
            INNER JOIN complaints c ON ca.complaint_id = c.complaint_id
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            LEFT JOIN maintenance_teams mt ON ca.maintenance_team_id = mt.maintenance_team_id
            LEFT JOIN maintenance_proofs mp ON ca.assignment_id = mp.assignment_id AND mp.proof_stage = 'after'
            LEFT JOIN maintenance_team_reviews mtr ON ca.maintenance_team_id = mtr.maintenance_team_id
            WHERE $assignWhereSql
            GROUP BY ca.maintenance_team_id, mt.team_name
            ORDER BY COUNT(DISTINCT ca.assignment_id) DESC
        ", $assignTypes, $assignParams);
    }

    if ($reportType === "in_progress_delayed_work") {
        $workWhere = ["ca.ward_id = ?", "DATE(ca.assigned_at) BETWEEN ? AND ?"];
        $workTypes = "iss";
        $workParams = [$wardId, $startDate, $endDate];
        wr_add_common_filters($workWhere, $workTypes, $workParams, $areaId, $status, $priority);
        $workWhereSql = implode(" AND ", $workWhere);

        $kpi = wr_fetch_one($conn, "
            SELECT
                SUM(ca.assignment_status = 'in_progress' OR c.complaint_status = 'in_progress') AS in_progress,
                SUM(ca.deadline_at < CURDATE() AND c.complaint_status NOT IN ('solved_by_team','closed','final_rejected','duplicate')) AS delayed_count,
                SUM(ca.deadline_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                    AND c.complaint_status NOT IN ('solved_by_team','closed','final_rejected','duplicate')) AS near_deadline,
                SUM(ca.assignment_status = 'completed' OR c.complaint_status IN ('solved_by_team','closed')) AS completed_period
            FROM complaint_assignments ca
            INNER JOIN complaints c ON ca.complaint_id = c.complaint_id
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE $workWhereSql
        ", $workTypes, $workParams);

        $data["kpis"] = [
            "In Progress Tasks" => (int)($kpi["in_progress"] ?? 0),
            "Delayed Tasks" => (int)($kpi["delayed_count"] ?? 0),
            "Near Deadline" => (int)($kpi["near_deadline"] ?? 0),
            "Completed This Period" => (int)($kpi["completed_period"] ?? 0),
        ];

        $data["details"] = wr_fetch_all($conn, "
            SELECT
                c.complaint_code AS `Complaint Code`,
                COALESCE(i.issue_name, 'N/A') AS `Issue Type`,
                COALESCE(a.area_name, 'N/A') AS `Area`,
                COALESCE(mt.team_name, 'N/A') AS `Assigned Team`,
                DATE_FORMAT(ca.assigned_at, '%b %d, %Y') AS `Assigned Date`,
                COALESCE(DATE_FORMAT(ca.deadline_at, '%b %d, %Y'), 'N/A') AS `Deadline`,
                REPLACE(UCASE(REPLACE(ca.assignment_status, '_', ' ')), 'TEAM', 'Team') AS `Work Status`,
                CASE
                    WHEN ca.deadline_at < CURDATE() AND c.complaint_status NOT IN ('solved_by_team','closed','final_rejected','duplicate') THEN 'Delayed'
                    WHEN ca.deadline_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                        AND c.complaint_status NOT IN ('solved_by_team','closed','final_rejected','duplicate') THEN 'Near Deadline'
                    ELSE 'On Schedule'
                END AS `Delay Status`
            FROM complaint_assignments ca
            INNER JOIN complaints c ON ca.complaint_id = c.complaint_id
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            LEFT JOIN maintenance_teams mt ON ca.maintenance_team_id = mt.maintenance_team_id
            WHERE $workWhereSql
            AND c.complaint_status IN ('team_assigned','in_progress','solved_by_team','closed')
            ORDER BY ca.deadline_at IS NULL, ca.deadline_at ASC, ca.assigned_at DESC
            LIMIT 250
        ", $workTypes, $workParams);
    }

    if ($reportType === "ward_reopened_disputed_cases") {
        $rrWhere = ["l.ward_id = ?", "DATE(rr.created_at) BETWEEN ? AND ?"];
        $rrTypes = "iss";
        $rrParams = [$wardId, $startDate, $endDate];
        wr_add_common_filters($rrWhere, $rrTypes, $rrParams, $areaId, $status, $priority);
        $rrWhereSql = implode(" AND ", $rrWhere);

        $kpi = wr_fetch_one($conn, "
            SELECT
                SUM(rr.request_type IN ('reopened','inspector_reopen_request')) AS total_reopened,
                SUM(rr.request_type IN ('disputed','citizen_objection','false_completion')) AS total_disputed,
                SUM(rr.request_status IN ('pending','sent_to_inspector','sent_to_ward_for_reassign')) AS pending_review,
                SUM(rr.request_status IN ('resolved','reassigned_same_team','reassigned_different_team','rejected')) AS resolved_cases
            FROM reopen_requests rr
            INNER JOIN complaints c ON rr.complaint_id = c.complaint_id
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            WHERE $rrWhereSql
        ", $rrTypes, $rrParams);

        $data["kpis"] = [
            "Total Reopened" => (int)($kpi["total_reopened"] ?? 0),
            "Total Disputed" => (int)($kpi["total_disputed"] ?? 0),
            "Pending Review" => (int)($kpi["pending_review"] ?? 0),
            "Resolved Cases" => (int)($kpi["resolved_cases"] ?? 0),
        ];

        $data["details"] = wr_fetch_all($conn, "
            SELECT
                c.complaint_code AS `Complaint Code`,
                COALESCE(i.issue_name, 'N/A') AS `Issue Type`,
                COALESCE(a.area_name, 'N/A') AS `Area`,
                COALESCE(rr.reason, 'N/A') AS `Reopen / Dispute Reason`,
                REPLACE(UCASE(REPLACE(rr.request_status, '_', ' ')), 'TO', 'to') AS `Request Status`,
                COALESCE(rr.ward_note, 'N/A') AS `Ward Note`,
                REPLACE(UCASE(REPLACE(c.complaint_status, '_', ' ')), 'BY', 'by') AS `Current Complaint Status`,
                COALESCE(mt.team_name, 'N/A') AS `Related Team`,
                DATE_FORMAT(rr.created_at, '%b %d, %Y') AS `Submitted Date`
            FROM reopen_requests rr
            INNER JOIN complaints c ON rr.complaint_id = c.complaint_id
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            LEFT JOIN complaint_assignments ca ON c.complaint_id = ca.complaint_id
            LEFT JOIN maintenance_teams mt ON ca.maintenance_team_id = mt.maintenance_team_id
            WHERE $rrWhereSql
            GROUP BY rr.reopen_id, c.complaint_id, mt.team_name
            ORDER BY rr.created_at DESC
            LIMIT 250
        ", $rrTypes, $rrParams);
    }

    if ($reportType === "ward_resolution_quality") {
        $qualityWhere = ["l.ward_id = ?", "DATE(COALESCE(c.closed_at, c.updated_at, c.submitted_at)) BETWEEN ? AND ?"];
        $qualityTypes = "iss";
        $qualityParams = [$wardId, $startDate, $endDate];
        wr_add_common_filters($qualityWhere, $qualityTypes, $qualityParams, $areaId, $status, $priority);
        $qualityWhereSql = implode(" AND ", $qualityWhere);

        $kpi = wr_fetch_one($conn, "
            SELECT
                COUNT(DISTINCT CASE WHEN c.complaint_status = 'closed' THEN c.complaint_id END) AS closed_cases,
                COUNT(DISTINCT f.feedback_id) AS feedback_count,
                AVG(COALESCE(mtr.rating, f.rating)) AS avg_rating,
                COUNT(DISTINCT rr.reopen_id) AS objection_count,
                COUNT(DISTINCT CASE WHEN c.complaint_status = 'reopened' THEN c.complaint_id END) AS reopened_count,
                COUNT(DISTINCT fcr.review_id) AS false_completion_count,
                COUNT(DISTINCT CASE WHEN mp.proof_status = 'rejected' THEN mp.proof_id END) AS rejected_proof_count
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            LEFT JOIN feedbacks f ON c.complaint_id = f.complaint_id
            LEFT JOIN maintenance_team_reviews mtr ON c.complaint_id = mtr.complaint_id
            LEFT JOIN reopen_requests rr ON c.complaint_id = rr.complaint_id
            LEFT JOIN false_completion_reviews fcr ON c.complaint_id = fcr.complaint_id
            LEFT JOIN maintenance_proofs mp ON c.complaint_id = mp.complaint_id
            WHERE $qualityWhereSql
        ", $qualityTypes, $qualityParams);

        $data["kpis"] = [
            "Closed Cases" => (int)($kpi["closed_cases"] ?? 0),
            "Feedback Count" => (int)($kpi["feedback_count"] ?? 0),
            "Average Rating" => round((float)($kpi["avg_rating"] ?? 0), 1),
            "Objection Count" => (int)($kpi["objection_count"] ?? 0),
            "Reopened Count" => (int)($kpi["reopened_count"] ?? 0),
            "False Completion Claim Count" => (int)($kpi["false_completion_count"] ?? 0),
            "Rejected Proof Count" => (int)($kpi["rejected_proof_count"] ?? 0),
        ];

        $data["details"] = wr_fetch_all($conn, "
            SELECT
                c.complaint_code AS `Complaint Code`,
                COALESCE(i.issue_name, 'N/A') AS `Issue Type`,
                COALESCE(a.area_name, 'N/A') AS `Area`,
                COALESCE(mt.team_name, 'N/A') AS `Team Name`,
                COALESCE(DATE_FORMAT(c.closed_at, '%b %d, %Y'), 'N/A') AS `Closed Date`,
                COALESCE(mtr.rating, f.rating, 'N/A') AS `Citizen Rating`,
                COALESCE(REPLACE(UCASE(REPLACE(f.feedback_type, '_', ' ')), 'FALSE', 'False'), REPLACE(UCASE(REPLACE(mtr.review_type, '_', ' ')), 'GOOD', 'Good'), 'N/A') AS `Feedback Type`,
                COALESCE(REPLACE(UCASE(REPLACE(rr.request_status, '_', ' ')), 'TO', 'to'), 'N/A') AS `Objection Status`,
                CASE WHEN c.complaint_status = 'reopened' OR rr.request_type IN ('reopened','inspector_reopen_request') THEN 'Reopened' ELSE 'N/A' END AS `Reopen Status`,
                REPLACE(UCASE(REPLACE(c.complaint_status, '_', ' ')), 'BY', 'by') AS `Final Status`
            FROM complaints c
            INNER JOIN locations l ON c.loc_id = l.loc_id
            LEFT JOIN areas a ON l.area_id = a.area_id
            LEFT JOIN issues i ON c.issue_id = i.issue_id
            LEFT JOIN complaint_assignments ca ON c.complaint_id = ca.complaint_id
            LEFT JOIN maintenance_teams mt ON ca.maintenance_team_id = mt.maintenance_team_id
            LEFT JOIN feedbacks f ON c.complaint_id = f.complaint_id
            LEFT JOIN maintenance_team_reviews mtr ON c.complaint_id = mtr.complaint_id
            LEFT JOIN reopen_requests rr ON c.complaint_id = rr.complaint_id
            WHERE $qualityWhereSql
            GROUP BY c.complaint_id, mt.team_name, f.feedback_id, mtr.review_id, rr.reopen_id
            ORDER BY COALESCE(c.closed_at, c.updated_at, c.submitted_at) DESC
            LIMIT 250
        ", $qualityTypes, $qualityParams);
    }

    $data["snapshot"] = array_slice($data["kpis"], 0, 6, true);
    return $data;
}

function wr_render_colgroup($headers)
{
    $count = count($headers);
    if ($count <= 0) return "";

    $widthMaps = [
        "Complaint Code" => 13,
        "Issue Type" => 15,
        "Area" => 13,
        "Status" => 12,
        "Assigned Team" => 13,
        "Deadline" => 11,
        "Submitted Date" => 12,
        "Priority" => 11,
    ];

    $widths = [];
    foreach ($headers as $header) {
        $widths[] = $widthMaps[$header] ?? null;
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

function wr_render_report_html($report)
{
    $html = "<article class='wr-preview-report'>";
    $html .= "<header class='wr-report-title'><h2>" . wr_safe($report["title"]) . "</h2></header>";

    $html .= "<section class='wr-section'><h3>Filter Summary</h3><table class='wr-filter-table'><thead><tr><th>Filter</th><th>Selected Value</th></tr></thead><tbody>";
    foreach ($report["filters"] as $label => $value) {
        $html .= "<tr><td>" . wr_safe($label) . "</td><td>" . wr_safe($value) . "</td></tr>";
    }
    $html .= "</tbody></table></section>";

    $html .= "<section class='wr-section'><h3>KPI Summary</h3><div class='wr-kpi-grid'>";
    foreach ($report["kpis"] as $label => $value) {
        $html .= "<div class='wr-kpi-card'><span>" . wr_safe($label) . "</span><strong>" . wr_safe(wr_format_number($value)) . "</strong></div>";
    }
    $html .= "</div></section>";

    $html .= "<section class='wr-section'><h3>Data Snapshot</h3><div class='wr-snapshot'>";
    foreach ($report["snapshot"] as $label => $value) {
        $html .= "<div><span>" . wr_safe($label) . "</span><strong>" . wr_safe(wr_format_number($value)) . "</strong></div>";
    }
    $html .= "</div></section>";

    $html .= "<section class='wr-section'><h3>Report Details</h3><div class='wr-detail-wrap'><table class='wr-detail-table'>";
    if (!empty($report["details"])) {
        $headers = array_keys($report["details"][0]);
        $html .= wr_render_colgroup($headers);
        $html .= "<thead><tr>";
        foreach ($headers as $header) {
            $html .= "<th>" . wr_safe($header) . "</th>";
        }
        $html .= "</tr></thead><tbody>";
        foreach ($report["details"] as $row) {
            $html .= "<tr>";
            foreach ($headers as $header) {
                $html .= "<td>" . wr_safe($row[$header] ?? "") . "</td>";
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

function wr_report_css()
{
    return "
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef2f7; color: #172033; font-family: Arial, sans-serif; padding: 24px; }
        .wr-preview-report { width: 210mm; max-width: 100%; margin: 0 auto; background: #fff; border: 1px solid #d7dee8; padding: 14mm; }
        .wr-report-title { padding-bottom: 14px; margin-bottom: 16px; border-bottom: 3px solid #0f766e; }
        .wr-report-title h2 { margin: 0; text-align: center; font-size: 22px; line-height: 1.25; color: #0f2d3f; font-weight: 800; }
        .wr-section { margin-top: 16px; page-break-inside: avoid; }
        .wr-section h3 { font-size: 12px; text-transform: uppercase; color: #0f766e; margin: 0 0 8px; letter-spacing: 0; font-weight: 800; }
        .wr-filter-table, .wr-detail-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .wr-filter-table th, .wr-filter-table td, .wr-detail-table th, .wr-detail-table td { border: 1px solid #cbd5e1; padding: 7px 8px; text-align: left; vertical-align: top; font-size: 10.5px; line-height: 1.35; word-wrap: break-word; overflow-wrap: anywhere; white-space: normal; }
        .wr-filter-table th, .wr-detail-table th { background: #e7f3f5; color: #12333f; font-weight: 800; }
        .wr-filter-table th:first-child, .wr-filter-table td:first-child { width: 34%; }
        .wr-kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }
        .wr-kpi-card { min-height: 72px; border: 1px solid #cbd5e1; background: #f8fafc; padding: 10px; display: flex; flex-direction: column; justify-content: space-between; }
        .wr-kpi-card span, .wr-snapshot span { display: block; font-size: 9.5px; line-height: 1.25; color: #64748b; font-weight: 800; text-transform: uppercase; overflow-wrap: anywhere; }
        .wr-kpi-card strong { display: block; margin-top: 8px; font-size: 20px; line-height: 1.1; color: #0f766e; font-weight: 800; overflow-wrap: anywhere; }
        .wr-snapshot { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; border: 1px solid #cbd5e1; background: #f8fafc; padding: 10px; }
        .wr-snapshot div { border: 1px solid #dde6f0; background: #fff; padding: 8px; min-height: 48px; }
        .wr-snapshot strong { display: block; margin-top: 5px; color: #0f172a; font-size: 14px; line-height: 1.2; font-weight: 800; overflow-wrap: anywhere; }
        .wr-detail-wrap { width: 100%; overflow: visible; }
        .wr-detail-table { min-width: 0; font-size: 10px; }
        .wr-detail-table tbody tr:nth-child(even) { background: #f8fafc; }
        @media screen and (max-width: 900px) {
            body { padding: 12px; }
            .wr-preview-report { padding: 18px; }
            .wr-kpi-grid, .wr-snapshot { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media screen and (max-width: 620px) {
            .wr-kpi-grid, .wr-snapshot { grid-template-columns: 1fr; }
        }
    ";
}

function wr_export_html_document($report)
{
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>" . wr_safe($report["title"]) . "</title>"
        . "<style>" . wr_report_css() . "</style></head><body>" . wr_render_report_html($report) . "</body></html>";
}

function wr_write_csv($path, $report)
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

function wr_safe_filename($value)
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace("/[^a-z0-9_\\-]+/", "_", $value);
    return trim($value, "_") ?: "ward_report";
}

function wr_write_export_file($path, $format, $report)
{
    if ($format === "CSV") {
        wr_write_csv($path, $report);
        return;
    }

    if ($format === "PDF") {
        cr_write_pdf($path, [
            "title" => $report["title"],
            "filters" => $report["filters"],
            "kpis" => $report["kpis"],
            "details" => $report["details"],
            "snapshot" => $report["snapshot"],
        ]);
        return;
    }

    file_put_contents($path, wr_export_html_document($report));
}

function wr_export_extension($format)
{
    if ($format === "CSV") return "csv";
    if ($format === "Excel") return "xls";
    if ($format === "DOCS") return "doc";
    return "pdf";
}

function wr_export_mime($format)
{
    $types = [
        "PDF" => "application/pdf",
        "Excel" => "application/vnd.ms-excel",
        "CSV" => "text/csv",
        "DOCS" => "application/msword",
    ];

    return $types[$format] ?? "application/octet-stream";
}
