<?php
$activePage = "local-team-assignment";
$pageTitle = "Local Team Assignment";

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

function tableColumns($conn, $tableName)
{
    $columns = [];
    $safeTable = mysqli_real_escape_string($conn, $tableName);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$safeTable`");

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $columns[] = $row["Field"];
        }
    }

    return $columns;
}

function firstExistingColumn($columns, $possibleColumns)
{
    foreach ($possibleColumns as $column) {
        if (in_array($column, $columns, true)) {
            return $column;
        }
    }

    return null;
}

function priorityClass($priority)
{
    $priority = strtolower(trim((string)$priority));

    if ($priority === "high") {
        return "high";
    }

    if ($priority === "low") {
        return "low";
    }

    return "medium";
}

function formatDateText($date)
{
    if (!$date) {
        return "N/A";
    }

    $time = strtotime($date);

    if (!$time) {
        return "N/A";
    }

    return date("M d, Y", $time);
}

/*
|--------------------------------------------------------------------------
| Detect maintenance_teams columns safely
|--------------------------------------------------------------------------
*/

$teamColumns = tableColumns($conn, "maintenance_teams");

$teamIdColumn = firstExistingColumn($teamColumns, [
    "maintenance_team_id",
    "team_id",
    "id"
]);

$teamNameColumn = firstExistingColumn($teamColumns, [
    "team_name",
    "maintenance_team_name",
    "name"
]);

$teamCityCorColumn = firstExistingColumn($teamColumns, [
    "city_cor_id",
    "city_corporation_id"
]);

$teamAnchalColumn = firstExistingColumn($teamColumns, [
    "anchal_id"
]);

$teamStatusColumn = firstExistingColumn($teamColumns, [
    "team_status",
    "status",
    "availability_status"
]);

$teamCodeColumn = firstExistingColumn($teamColumns, [
    "team_code",
    "maintenance_team_code",
    "code"
]);

$teamPhoneColumn = firstExistingColumn($teamColumns, [
    "team_phone",
    "phone",
    "phone_number",
    "contact_number"
]);

if (!$teamIdColumn || !$teamNameColumn || !$teamCityCorColumn || !$teamAnchalColumn) {
    die("maintenance_teams table must have team id, team name, city_cor_id and anchal_id columns.");
}

/*
|--------------------------------------------------------------------------
| Get logged-in ward officer and assigned ward
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
            wo.user_mail,
            w.ward_id,
            w.ward_no,
            w.ward_name,
            w.city_cor_id,
            w.anchal_id,
            an.anchal_name,
            cc.city_cor_name
        FROM ward_officers wo
        INNER JOIN wards w
            ON wo.assigned_ward_id = w.ward_id
        LEFT JOIN anchals an
            ON w.anchal_id = an.anchal_id
        LEFT JOIN city_corporations cc
            ON w.city_cor_id = cc.city_cor_id
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
    $cityCorId = (int)$wardOfficer["city_cor_id"];
    $anchalId = (int)$wardOfficer["anchal_id"];
    $anchalName = $wardOfficer["anchal_name"] ?? "Assigned Anchal";
    $cityCorName = $wardOfficer["city_cor_name"] ?? "City Corporation";
    $userName = $wardOfficer["full_name"] ?? ($_SESSION["user_name"] ?? "Ward Officer");

    $_SESSION["user_name"] = $userName;
    $_SESSION["user_role_label"] = "Ward Operations";
} catch (Exception $e) {
    die($e->getMessage());
}

/*
|--------------------------------------------------------------------------
| Process assignment
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $complaintId = (int)($_POST["complaint_id"] ?? 0);
    $maintenanceTeamId = (int)($_POST["maintenance_team_id"] ?? 0);
    $deadlineAt = trim($_POST["deadline_at"] ?? "");
    $assignmentPriority = trim($_POST["assignment_priority"] ?? "Medium");
    $taskNote = trim($_POST["task_note"] ?? "");

    $allowedPriorities = ["Low", "Medium", "High"];

    if ($complaintId <= 0 || $maintenanceTeamId <= 0 || $deadlineAt === "" || !in_array($assignmentPriority, $allowedPriorities, true)) {
        $errorMessage = "Please select a valid complaint, team, deadline, and priority.";
    } else {
        mysqli_begin_transaction($conn);

        try {
            $complaintCheck = fetchOne(
                $conn,
                "SELECT
                    c.complaint_id,
                    c.complaint_code,
                    c.complaint_status,
                    l.ward_id
                FROM complaints c
                INNER JOIN locations l
                    ON c.loc_id = l.loc_id
                WHERE c.complaint_id = ?
                AND l.ward_id = ?
                AND c.complaint_status = 'verified'
                LIMIT 1",
                "ii",
                [$complaintId, $wardId]
            );

            if (!$complaintCheck) {
                throw new Exception("This complaint is not verified or does not belong to your assigned ward.");
            }

            $teamCheckSql = "
                SELECT
                    `$teamIdColumn` AS maintenance_team_id,
                    `$teamNameColumn` AS team_name
                FROM maintenance_teams
                WHERE `$teamIdColumn` = ?
                AND `$teamCityCorColumn` = ?
                AND `$teamAnchalColumn` = ?
                LIMIT 1
            ";

            $teamCheck = fetchOne(
                $conn,
                $teamCheckSql,
                "iii",
                [$maintenanceTeamId, $cityCorId, $anchalId]
            );

            if (!$teamCheck) {
                throw new Exception("Selected team is not under this ward's city corporation and anchal.");
            }

            $assignmentRow = fetchOne(
                $conn,
                "SELECT assignment_id
                FROM complaint_assignments
                WHERE complaint_id = ?
                AND ward_id = ?
                ORDER BY assignment_id DESC
                LIMIT 1",
                "ii",
                [$complaintId, $wardId]
            );

            if ($assignmentRow) {
                $assignmentId = (int)$assignmentRow["assignment_id"];

                $updateAssignmentSql = "
                    UPDATE complaint_assignments
                    SET
                        maintenance_team_id = ?,
                        assignment_status = 'team_assigned',
                        deadline_at = ?,
                        assignment_priority = ?,
                        task_note = ?,
                        assigned_at = CURRENT_TIMESTAMP
                    WHERE assignment_id = ?
                ";

                $updateStmt = mysqli_prepare($conn, $updateAssignmentSql);

                if (!$updateStmt) {
                    throw new Exception("Assignment update failed: " . mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $updateStmt,
                    "isssi",
                    $maintenanceTeamId,
                    $deadlineAt,
                    $assignmentPriority,
                    $taskNote,
                    $assignmentId
                );

                if (!mysqli_stmt_execute($updateStmt)) {
                    throw new Exception("Assignment update failed: " . mysqli_stmt_error($updateStmt));
                }

                mysqli_stmt_close($updateStmt);
            } else {
                $insertAssignmentSql = "
                    INSERT INTO complaint_assignments
                    (
                        complaint_id,
                        ward_id,
                        maintenance_team_id,
                        assigned_by,
                        assignment_status,
                        assigned_at,
                        deadline_at,
                        assignment_priority,
                        task_note
                    )
                    VALUES
                    (?, ?, ?, ?, 'team_assigned', CURRENT_TIMESTAMP, ?, ?, ?)
                ";

                $insertStmt = mysqli_prepare($conn, $insertAssignmentSql);

                if (!$insertStmt) {
                    throw new Exception("Assignment insert failed: " . mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $insertStmt,
                    "iiiisss",
                    $complaintId,
                    $wardId,
                    $maintenanceTeamId,
                    $currentUserId,
                    $deadlineAt,
                    $assignmentPriority,
                    $taskNote
                );

                if (!mysqli_stmt_execute($insertStmt)) {
                    throw new Exception("Assignment insert failed: " . mysqli_stmt_error($insertStmt));
                }

                mysqli_stmt_close($insertStmt);
            }

            $updateComplaintSql = "
                UPDATE complaints
                SET
                    complaint_status = 'team_assigned',
                    updated_at = CURRENT_TIMESTAMP
                WHERE complaint_id = ?
            ";

            $updateComplaintStmt = mysqli_prepare($conn, $updateComplaintSql);

            if (!$updateComplaintStmt) {
                throw new Exception("Complaint status update failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($updateComplaintStmt, "i", $complaintId);

            if (!mysqli_stmt_execute($updateComplaintStmt)) {
                throw new Exception("Complaint status update failed: " . mysqli_stmt_error($updateComplaintStmt));
            }

            mysqli_stmt_close($updateComplaintStmt);

            mysqli_commit($conn);

            $successMessage = "Maintenance team assigned successfully. Citizen tracking status is now updated.";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $errorMessage = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Fetch verified complaints for this ward
|--------------------------------------------------------------------------
*/

try {
    $verifiedComplaints = fetchAllRows(
        $conn,
        "SELECT
            c.complaint_id,
            c.complaint_code,
            c.problem_description,
            c.address_description,
            c.complaint_status,
            c.submitted_at,
            i.issue_name,
            i.priority,
            a.area_name,
            ca.assignment_id,
            ca.assignment_status,
            ca.deadline_at,
            ca.assignment_priority,
            ca.task_note
        FROM complaints c
        INNER JOIN locations l
            ON c.loc_id = l.loc_id
        LEFT JOIN areas a
            ON l.area_id = a.area_id
        LEFT JOIN issues i
            ON c.issue_id = i.issue_id
        LEFT JOIN complaint_assignments ca
            ON c.complaint_id = ca.complaint_id
        WHERE l.ward_id = ?
        AND c.complaint_status = 'verified'
        ORDER BY
            CASE
                WHEN i.priority = 'High' THEN 1
                WHEN i.priority = 'Medium' THEN 2
                WHEN i.priority = 'Low' THEN 3
                ELSE 4
            END,
            c.submitted_at DESC",
        "i",
        [$wardId]
    );
} catch (Exception $e) {
    $verifiedComplaints = [];
    $errorMessage = $e->getMessage();
}

/*
|--------------------------------------------------------------------------
| Fetch teams under same city corporation and anchal
|--------------------------------------------------------------------------
*/

try {
    $statusSelect = $teamStatusColumn ? "`$teamStatusColumn` AS team_status" : "'Active' AS team_status";
    $codeSelect = $teamCodeColumn ? "`$teamCodeColumn` AS team_code" : "'' AS team_code";
    $phoneSelect = $teamPhoneColumn ? "`$teamPhoneColumn` AS team_phone" : "'' AS team_phone";

    $teamsSql = "
        SELECT
            `$teamIdColumn` AS maintenance_team_id,
            `$teamNameColumn` AS team_name,
            $statusSelect,
            $codeSelect,
            $phoneSelect
        FROM maintenance_teams
        WHERE `$teamCityCorColumn` = ?
        AND `$teamAnchalColumn` = ?
        ORDER BY `$teamNameColumn` ASC
    ";

    $maintenanceTeams = fetchAllRows($conn, $teamsSql, "ii", [$cityCorId, $anchalId]);
} catch (Exception $e) {
    $maintenanceTeams = [];
    $errorMessage = $e->getMessage();
}

$totalVerified = count($verifiedComplaints);
$totalTeams = count($maintenanceTeams);
$highPriorityCount = 0;

foreach ($verifiedComplaints as $complaintItem) {
    if (strtolower((string)($complaintItem["priority"] ?? "")) === "high") {
        $highPriorityCount++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Local Team Assignment | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
    <link rel="stylesheet" href="../../css/ward/footer.css">
    <link rel="stylesheet" href="../../css/ward/local-team-assignment.css">
    <link rel="stylesheet" href="../../css/ward/wardTextFix.css">
</head>

<body class="ward">

<?php include "../../includes/ward/sidebar.php"; ?>

<main class="ward-main">
    <?php include "../../includes/ward/topbar.php"; ?>

    <section class="lta-page">

        <div class="lta-header">
            <div>
                <h1>Local Team Assignment</h1>
                <p>
                    Assign verified complaints from Ward <?= safeText($wardNo); ?>
                    to maintenance teams under <?= safeText($anchalName); ?>.
                </p>
            </div>

            <div class="lta-location-card">
                <span><?= safeText($cityCorName); ?></span>
                <strong><?= safeText($anchalName); ?></strong>
            </div>
        </div>

        <?php if ($successMessage !== ""): ?>
            <div class="lta-alert lta-success">
                <i class="bi bi-check-circle"></i>
                <?= safeText($successMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ""): ?>
            <div class="lta-alert lta-error">
                <i class="bi bi-exclamation-circle"></i>
                <?= safeText($errorMessage); ?>
            </div>
        <?php endif; ?>

        <div class="lta-summary-grid">
            <div class="lta-summary-card">
                <div class="lta-summary-icon pending">
                    <i class="bi bi-check2-square"></i>
                </div>
                <div>
                    <h2><?= $totalVerified; ?></h2>
                    <p>Verified Complaints</p>
                </div>
            </div>

            <div class="lta-summary-card">
                <div class="lta-summary-icon team">
                    <i class="bi bi-people"></i>
                </div>
                <div>
                    <h2><?= $totalTeams; ?></h2>
                    <p>Available Teams</p>
                </div>
            </div>

            <div class="lta-summary-card">
                <div class="lta-summary-icon high">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div>
                    <h2><?= $highPriorityCount; ?></h2>
                    <p>High Priority</p>
                </div>
            </div>
        </div>

        <div class="lta-toolbar">
            <div class="lta-search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="ltaSearch" placeholder="Search by complaint ID, issue, or area...">
            </div>

            <select id="ltaPriorityFilter">
                <option value="all">All Priority</option>
                <option value="High">High</option>
                <option value="Medium">Medium</option>
                <option value="Low">Low</option>
            </select>
        </div>

        <div class="lta-list" id="ltaComplaintList">

            <?php if (!empty($verifiedComplaints)): ?>
                <?php foreach ($verifiedComplaints as $complaint): ?>
                    <?php
                        $complaintId = (int)$complaint["complaint_id"];
                        $complaintCode = $complaint["complaint_code"] ?? "";
                        $issueName = $complaint["issue_name"] ?: "Unknown Issue";
                        $priority = $complaint["priority"] ?: "Medium";
                        $areaName = $complaint["area_name"] ?: "Area not specified";
                        $problemDescription = $complaint["problem_description"] ?: "No description provided.";
                        $addressDescription = $complaint["address_description"] ?: "No address description.";
                        $submittedAt = formatDateText($complaint["submitted_at"] ?? null);

                        $searchText = strtolower($complaintCode . " " . $issueName . " " . $areaName . " " . $priority . " " . $problemDescription);
                    ?>

                    <article
                        class="lta-card"
                        data-search="<?= safeText($searchText); ?>"
                        data-priority="<?= safeText($priority); ?>"
                    >
                        <div class="lta-card-top">
                            <div>
                                <span class="lta-code"><?= safeText($complaintCode); ?></span>
                                <span class="lta-priority <?= priorityClass($priority); ?>">
                                    <?= safeText($priority); ?>
                                </span>
                            </div>

                            <span class="lta-status">Verified</span>
                        </div>

                        <div class="lta-card-body">
                            <div class="lta-complaint-info">
                                <h2><?= safeText($issueName); ?></h2>

                                <p><?= safeText($problemDescription); ?></p>

                                <div class="lta-meta">
                                    <span><i class="bi bi-geo-alt"></i> <?= safeText($areaName); ?></span>
                                    <span><i class="bi bi-calendar"></i> <?= safeText($submittedAt); ?></span>
                                    <span><i class="bi bi-signpost"></i> <?= safeText($addressDescription); ?></span>
                                </div>
                            </div>

                            <form method="POST" action="local-team-assignment.php" class="lta-assign-form">
                                <input type="hidden" name="complaint_id" value="<?= $complaintId; ?>">

                                <div class="lta-form-grid">
                                    <div class="lta-form-group">
                                        <label>Maintenance Team</label>
                                        <select name="maintenance_team_id" required>
                                            <option value="">Select team</option>
                                            <?php foreach ($maintenanceTeams as $team): ?>
                                                <?php
                                                    $teamId = (int)$team["maintenance_team_id"];
                                                    $teamName = $team["team_name"] ?? "Unnamed Team";
                                                    $teamCode = $team["team_code"] ?? "";
                                                    $teamStatus = $team["team_status"] ?? "";
                                                ?>
                                                <option value="<?= $teamId; ?>">
                                                    <?= safeText($teamName); ?>
                                                    <?= $teamCode !== "" ? " - " . safeText($teamCode) : ""; ?>
                                                    <?= $teamStatus !== "" ? " (" . safeText($teamStatus) . ")" : ""; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="lta-form-group">
                                        <label>Deadline</label>
                                        <input type="date" name="deadline_at" required>
                                    </div>

                                    <div class="lta-form-group">
                                        <label>Priority</label>
                                        <select name="assignment_priority" required>
                                            <option value="Low" <?= $priority === "Low" ? "selected" : ""; ?>>Low</option>
                                            <option value="Medium" <?= $priority === "Medium" ? "selected" : ""; ?>>Medium</option>
                                            <option value="High" <?= $priority === "High" ? "selected" : ""; ?>>High</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="lta-form-group">
                                    <label>Task Note</label>
                                    <textarea
                                        name="task_note"
                                        rows="3"
                                        placeholder="Write instruction for maintenance team..."
                                    ></textarea>
                                </div>

                                <button type="submit" class="lta-assign-btn" <?= empty($maintenanceTeams) ? "disabled" : ""; ?>>
                                    <i class="bi bi-send"></i>
                                    Assign Team
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>

            <?php else: ?>
                <div class="lta-empty">
                    <i class="bi bi-check-circle"></i>
                    <h2>No verified complaints waiting for team assignment</h2>
                    <p>After Ward Verification, verified complaints will appear here.</p>
                </div>
            <?php endif; ?>

        </div>

    </section>

    <?php include "../../includes/ward/footer.php"; ?>
</main>

<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/local-team-assignment.js"></script>

</body>
</html>