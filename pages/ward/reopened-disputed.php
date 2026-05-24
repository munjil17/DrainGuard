<?php
$activePage = "reopened-disputed";
$pageTitle = "Reopened & Disputed Cases";

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

function makeProofPath($path)
{
    $path = trim((string)$path);

    if ($path === "") {
        return "";
    }

    $path = str_replace("\\", "/", $path);

    if (preg_match("/^https?:\/\//i", $path)) {
        return $path;
    }

    if (str_starts_with($path, "../../")) {
        return $path;
    }

    if (str_starts_with($path, "/")) {
        return $path;
    }

    if (str_starts_with($path, "assets/")) {
        return "../../" . $path;
    }

    if (str_starts_with($path, "uploads/")) {
        return "../../assets/" . $path;
    }

    if (!str_contains($path, "/")) {
        return "../../assets/uploads/complaints/" . $path;
    }

    return "../../" . ltrim($path, "/");
}

function requestLabel($type)
{
    $type = strtolower(trim((string)$type));

    if ($type === "disputed") {
        return "Disputed";
    }

    if ($type === "false_completion") {
        return "False Completion";
    }

    return "Reopened";
}

function requestCardClass($type)
{
    $type = strtolower(trim((string)$type));

    if ($type === "disputed" || $type === "false_completion") {
        return "disputed";
    }

    return "reopened";
}

/*
|--------------------------------------------------------------------------
| Detect maintenance_teams columns
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

if (!$teamIdColumn || !$teamNameColumn) {
    die("maintenance_teams table must have a team id and team name column.");
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
| Handle actions
|--------------------------------------------------------------------------
| Same team: back to team_assigned.
| Different team: back to verified so Local Team Assignment can assign another team.
| Inspector: send to inspector_verification.
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $reopenId = (int)($_POST["reopen_id"] ?? 0);
    $complaintId = (int)($_POST["complaint_id"] ?? 0);
    $action = trim($_POST["action"] ?? "");

    $allowedActions = ["same_team", "different_team", "inspector"];

    if ($reopenId <= 0 || $complaintId <= 0 || !in_array($action, $allowedActions, true)) {
        $errorMessage = "Invalid request.";
    } else {
        mysqli_begin_transaction($conn);

        try {
            $requestCheck = fetchOne(
                $conn,
                "SELECT
                    rr.reopen_id,
                    rr.complaint_id,
                    rr.request_status,
                    c.complaint_status,
                    ca.assignment_id,
                    ca.ward_id,
                    ca.maintenance_team_id
                FROM reopen_requests rr
                INNER JOIN complaints c
                    ON rr.complaint_id = c.complaint_id
                INNER JOIN locations l
                    ON c.loc_id = l.loc_id
                LEFT JOIN complaint_assignments ca
                    ON c.complaint_id = ca.complaint_id
                WHERE rr.reopen_id = ?
                AND rr.complaint_id = ?
                AND l.ward_id = ?
                AND rr.request_status = 'pending'
                ORDER BY ca.assignment_id DESC
                LIMIT 1",
                "iii",
                [$reopenId, $complaintId, $wardId]
            );

            if (!$requestCheck) {
                throw new Exception("This request is not pending or does not belong to your assigned ward.");
            }

            if ($action === "same_team") {
                if (empty($requestCheck["maintenance_team_id"])) {
                    throw new Exception("No previous maintenance team found for reassignment.");
                }

                $requestStatus = "reassigned_same_team";
                $complaintStatus = "team_assigned";
                $assignmentStatus = "team_assigned";

                $updateAssignmentSql = "
                    UPDATE complaint_assignments
                    SET
                        assignment_status = ?,
                        assigned_at = CURRENT_TIMESTAMP
                    WHERE assignment_id = ?
                ";

                $updateAssignmentStmt = mysqli_prepare($conn, $updateAssignmentSql);

                if (!$updateAssignmentStmt) {
                    throw new Exception("Assignment update failed: " . mysqli_error($conn));
                }

                $assignmentId = (int)$requestCheck["assignment_id"];

                mysqli_stmt_bind_param($updateAssignmentStmt, "si", $assignmentStatus, $assignmentId);

                if (!mysqli_stmt_execute($updateAssignmentStmt)) {
                    throw new Exception("Assignment update failed: " . mysqli_stmt_error($updateAssignmentStmt));
                }

                mysqli_stmt_close($updateAssignmentStmt);
            } elseif ($action === "different_team") {
                $requestStatus = "reassigned_different_team";
                $complaintStatus = "verified";

                $updateAssignmentSql = "
                    UPDATE complaint_assignments
                    SET
                        maintenance_team_id = NULL,
                        assignment_status = 'ward_assigned',
                        assigned_at = CURRENT_TIMESTAMP
                    WHERE complaint_id = ?
                    AND ward_id = ?
                ";

                $updateAssignmentStmt = mysqli_prepare($conn, $updateAssignmentSql);

                if (!$updateAssignmentStmt) {
                    throw new Exception("Assignment update failed: " . mysqli_error($conn));
                }

                mysqli_stmt_bind_param($updateAssignmentStmt, "ii", $complaintId, $wardId);

                if (!mysqli_stmt_execute($updateAssignmentStmt)) {
                    throw new Exception("Assignment update failed: " . mysqli_stmt_error($updateAssignmentStmt));
                }

                mysqli_stmt_close($updateAssignmentStmt);
            } else {
                $requestStatus = "sent_to_inspector";
                $complaintStatus = "inspector_verification";
            }

            $updateRequestSql = "
                UPDATE reopen_requests
                SET
                    request_status = ?,
                    handled_by = ?,
                    handled_at = NOW()
                WHERE reopen_id = ?
            ";

            $updateRequestStmt = mysqli_prepare($conn, $updateRequestSql);

            if (!$updateRequestStmt) {
                throw new Exception("Reopen request update failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($updateRequestStmt, "sii", $requestStatus, $currentUserId, $reopenId);

            if (!mysqli_stmt_execute($updateRequestStmt)) {
                throw new Exception("Reopen request update failed: " . mysqli_stmt_error($updateRequestStmt));
            }

            mysqli_stmt_close($updateRequestStmt);

            $updateComplaintSql = "
                UPDATE complaints
                SET
                    complaint_status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE complaint_id = ?
            ";

            $updateComplaintStmt = mysqli_prepare($conn, $updateComplaintSql);

            if (!$updateComplaintStmt) {
                throw new Exception("Complaint status update failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($updateComplaintStmt, "si", $complaintStatus, $complaintId);

            if (!mysqli_stmt_execute($updateComplaintStmt)) {
                throw new Exception("Complaint status update failed: " . mysqli_stmt_error($updateComplaintStmt));
            }

            mysqli_stmt_close($updateComplaintStmt);

            mysqli_commit($conn);

            if ($action === "same_team") {
                $successMessage = "Complaint reassigned to the same team successfully.";
            } elseif ($action === "different_team") {
                $successMessage = "Complaint moved back to Local Team Assignment for different team selection.";
            } else {
                $successMessage = "Complaint sent to inspector verification successfully.";
            }
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $errorMessage = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Fetch pending reopened/disputed requests for assigned ward
|--------------------------------------------------------------------------
*/

try {
    $requestsSql = "
        SELECT
            rr.reopen_id,
            rr.complaint_id,
            rr.requested_by,
            rr.request_type,
            rr.reason,
            rr.request_status,
            rr.created_at,
            rr.handled_at,

            c.complaint_code,
            c.complaint_status,
            c.problem_description,
            c.updated_at,

            u.user_name AS requested_by_name,
            u.user_mail AS requested_by_email,

            i.issue_name,
            i.priority AS issue_priority,

            a.area_name,

            ca.assignment_id,
            ca.maintenance_team_id,
            ca.assignment_status,
            ca.deadline_at,
            ca.assignment_priority,
            ca.task_note,
            ca.assigned_at,

            mt.`$teamNameColumn` AS team_name,

            mu.update_id,
            mu.work_status,
            mu.work_note,
            mu.proof_file_path,
            mu.proof_file_type,
            mu.completed_at,
            mu.updated_at AS proof_updated_at

        FROM reopen_requests rr

        INNER JOIN complaints c
            ON rr.complaint_id = c.complaint_id

        INNER JOIN users u
            ON rr.requested_by = u.user_id

        INNER JOIN locations l
            ON c.loc_id = l.loc_id

        LEFT JOIN areas a
            ON l.area_id = a.area_id

        LEFT JOIN issues i
            ON c.issue_id = i.issue_id

        LEFT JOIN (
            SELECT ca1.*
            FROM complaint_assignments ca1
            INNER JOIN (
                SELECT
                    complaint_id,
                    MAX(assignment_id) AS latest_assignment_id
                FROM complaint_assignments
                GROUP BY complaint_id
            ) latest_ca
                ON ca1.assignment_id = latest_ca.latest_assignment_id
        ) ca
            ON c.complaint_id = ca.complaint_id

        LEFT JOIN maintenance_teams mt
            ON ca.maintenance_team_id = mt.`$teamIdColumn`

        LEFT JOIN (
            SELECT mu1.*
            FROM maintenance_updates mu1
            INNER JOIN (
                SELECT
                    complaint_id,
                    MAX(update_id) AS latest_update_id
                FROM maintenance_updates
                GROUP BY complaint_id
            ) latest_mu
                ON mu1.update_id = latest_mu.latest_update_id
        ) mu
            ON c.complaint_id = mu.complaint_id

        WHERE l.ward_id = ?
        AND rr.request_status = 'pending'
        AND rr.request_type IN ('reopened', 'disputed', 'false_completion')

        ORDER BY rr.created_at DESC, rr.reopen_id DESC
    ";

    $reopenRequests = fetchAllRows($conn, $requestsSql, "i", [$wardId]);
} catch (Exception $e) {
    $reopenRequests = [];
    $errorMessage = $e->getMessage();
}

$totalReopened = 0;
$totalDisputed = 0;

foreach ($reopenRequests as $item) {
    $type = strtolower((string)$item["request_type"]);

    if ($type === "reopened") {
        $totalReopened++;
    } else {
        $totalDisputed++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reopened & Disputed Cases | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
    <link rel="stylesheet" href="../../css/ward/footer.css">
    <link rel="stylesheet" href="../../css/ward/reopened-disputed.css">
    <link rel="stylesheet" href="../../css/ward/wardTextFix.css">
</head>

<body class="ward">

<?php include "../../includes/ward/sidebar.php"; ?>

<main class="ward-main">
    <?php include "../../includes/ward/topbar.php"; ?>

    <section class="rd-page">

        <div class="rd-header">
            <div>
                <h1>Reopened & Disputed Cases</h1>
                <p>
                    Handle citizen objections and false completion reports for
                    Ward <?= safeText($wardNo); ?><?= $wardName ? " - " . safeText($wardName) : ""; ?>.
                </p>
            </div>
        </div>

        <?php if ($successMessage !== ""): ?>
            <div class="rd-alert rd-success">
                <i class="bi bi-check-circle"></i>
                <?= safeText($successMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ""): ?>
            <div class="rd-alert rd-error">
                <i class="bi bi-exclamation-circle"></i>
                <?= safeText($errorMessage); ?>
            </div>
        <?php endif; ?>

        <div class="rd-summary-grid">
            <div class="rd-summary-card reopened">
                <div class="rd-summary-icon reopened">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div>
                    <h2><?= $totalReopened; ?></h2>
                    <p>Reopened Complaints</p>
                </div>
            </div>

            <div class="rd-summary-card disputed">
                <div class="rd-summary-icon disputed">
                    <i class="bi bi-flag"></i>
                </div>
                <div>
                    <h2><?= $totalDisputed; ?></h2>
                    <p>Disputed Cases</p>
                </div>
            </div>
        </div>

        <div class="rd-toolbar">
            <div class="rd-search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="rdSearch" placeholder="Search by complaint ID, issue, area, team...">
            </div>

            <select id="rdTypeFilter">
                <option value="all">All Types</option>
                <option value="reopened">Reopened</option>
                <option value="disputed">Disputed</option>
                <option value="false_completion">False Completion</option>
            </select>
        </div>

        <div class="rd-list" id="rdList">

            <?php if (!empty($reopenRequests)): ?>
                <?php foreach ($reopenRequests as $request): ?>
                    <?php
                        $requestType = strtolower((string)$request["request_type"]);
                        $cardClass = requestCardClass($requestType);
                        $requestLabel = requestLabel($requestType);

                        $reopenId = (int)$request["reopen_id"];
                        $complaintId = (int)$request["complaint_id"];
                        $complaintCode = $request["complaint_code"] ?? "";
                        $issueName = $request["issue_name"] ?: "Unknown Issue";
                        $areaName = $request["area_name"] ?: "Area not specified";
                        $teamName = $request["team_name"] ?: "No team found";
                        $reason = $request["reason"] ?: "No reason provided.";
                        $completedAt = formatDateOnly($request["completed_at"] ?? null);
                        $reopenedAt = formatDateOnly($request["created_at"] ?? null);
                        $proofPath = makeProofPath($request["proof_file_path"] ?? "");
                        $proofType = strtolower((string)($request["proof_file_type"] ?? ""));
                        $proofText = $request["proof_file_path"]
                            ? "Proof submitted by " . $teamName . " on " . $completedAt
                            : "No previous completion proof found.";

                        $searchText = strtolower(
                            $complaintCode . " " .
                            $issueName . " " .
                            $areaName . " " .
                            $teamName . " " .
                            $requestLabel . " " .
                            $reason
                        );
                    ?>

                    <article class="rd-card <?= safeText($cardClass); ?>"
                             data-search="<?= safeText($searchText); ?>"
                             data-type="<?= safeText($requestType); ?>">

                        <div class="rd-card-top">
                            <div>
                                <span class="rd-code"><?= safeText($complaintCode); ?></span>
                                <span class="rd-badge <?= safeText($cardClass); ?>">
                                    <?= safeText($requestLabel); ?>
                                </span>
                            </div>
                        </div>

                        <h2>
                            <?= safeText($issueName); ?>
                            -
                            <?= $requestType === "false_completion"
                                ? "false completion report"
                                : "citizen reported incomplete work"; ?>
                        </h2>

                        <div class="rd-meta-grid">
                            <div>
                                <span>Area:</span>
                                <strong><?= safeText($areaName); ?></strong>
                            </div>

                            <div>
                                <span>Team:</span>
                                <strong><?= safeText($teamName); ?></strong>
                            </div>

                            <div>
                                <span>Completed:</span>
                                <strong><?= safeText($completedAt); ?></strong>
                            </div>

                            <div>
                                <span>Reopened:</span>
                                <strong><?= safeText($reopenedAt); ?></strong>
                            </div>
                        </div>

                        <div class="rd-reason-box">
                            <div class="rd-box-title">
                                <i class="bi bi-chat-square"></i>
                                <span>Dispute Reason</span>
                            </div>
                            <p><?= safeText($reason); ?></p>
                        </div>

                        <div class="rd-proof-box">
                            <div class="rd-box-title">
                                <span>Previous Completion Proof</span>
                            </div>

                            <div class="rd-proof-content">
                                <?php if ($proofPath !== "" && ($proofType === "image" || preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $proofPath))): ?>
                                    <a href="<?= safeText($proofPath); ?>" target="_blank" class="rd-proof-thumb">
                                        <img src="<?= safeText($proofPath); ?>" alt="Completion Proof">
                                    </a>
                                <?php elseif ($proofPath !== "" && ($proofType === "video" || preg_match('/\.(mp4|webm|ogg|mov)$/i', $proofPath))): ?>
                                    <video class="rd-proof-video" controls>
                                        <source src="<?= safeText($proofPath); ?>">
                                    </video>
                                <?php else: ?>
                                    <div class="rd-proof-placeholder"></div>
                                    <div class="rd-proof-placeholder"></div>
                                <?php endif; ?>

                                <p><?= safeText($proofText); ?></p>
                            </div>
                        </div>

                        <div class="rd-actions">
                            <form method="POST" action="reopened-disputed.php" class="rd-action-form">
                                <input type="hidden" name="reopen_id" value="<?= $reopenId; ?>">
                                <input type="hidden" name="complaint_id" value="<?= $complaintId; ?>">
                                <input type="hidden" name="action" value="same_team">

                                <button type="submit" class="rd-btn same-team">
                                    <i class="bi bi-send"></i>
                                    Reassign to Same Team
                                </button>
                            </form>

                            <form method="POST" action="reopened-disputed.php" class="rd-action-form">
                                <input type="hidden" name="reopen_id" value="<?= $reopenId; ?>">
                                <input type="hidden" name="complaint_id" value="<?= $complaintId; ?>">
                                <input type="hidden" name="action" value="different_team">

                                <button type="submit" class="rd-btn different-team">
                                    <i class="bi bi-people"></i>
                                    Assign to Different Team
                                </button>
                            </form>

                            <form method="POST" action="reopened-disputed.php" class="rd-action-form">
                                <input type="hidden" name="reopen_id" value="<?= $reopenId; ?>">
                                <input type="hidden" name="complaint_id" value="<?= $complaintId; ?>">
                                <input type="hidden" name="action" value="inspector">

                                <button type="submit" class="rd-btn inspector">
                                    <i class="bi bi-flag"></i>
                                    Send to Inspector
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="rd-empty">
                    <i class="bi bi-check-circle"></i>
                    <h2>No reopened or disputed cases</h2>
                    <p>Citizen objections and false completion reports will appear here.</p>
                </div>
            <?php endif; ?>

        </div>

    </section>

    <?php include "../../includes/ward/footer.php"; ?>
</main>

<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/reopened-disputed.js"></script>

</body>
</html>