<?php
// C:\xampp\htdocs\DrainGuard\pages\citizen\dashboard.php

require_once "../../config.php";
require_login(["citizen"]);

$activePage = "dashboard";
$pageTitle = "Citizen Dashboard";
$pageParent = "Citizen";
$pageChild = "Dashboard";

$userId = isset($_SESSION["user_id"]) ? (int)$_SESSION["user_id"] : 0;
$userName = $_SESSION["user_name"] ?? "Citizen User";
$_SESSION["user_role_label"] = $_SESSION["user_role_label"] ?? "Public Portal";

function citizen_dash_safe($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function citizen_dash_table_exists($conn, $tableName)
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "s", $tableName);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return ((int)($row["total"] ?? 0)) > 0;
}

function citizen_dash_column_exists($conn, $tableName, $columnName)
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ss", $tableName, $columnName);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return ((int)($row["total"] ?? 0)) > 0;
}

function citizen_dash_first_existing_column($conn, $tableName, $columns)
{
    foreach ($columns as $column) {
        if (citizen_dash_column_exists($conn, $tableName, $column)) {
            return $column;
        }
    }

    return "";
}

function citizen_dash_count_complaints($conn, $userId, $statusValues = [])
{
    if (!citizen_dash_table_exists($conn, "complaints")) {
        return 0;
    }

    $userColumn = citizen_dash_first_existing_column($conn, "complaints", [
        "citizen_id",
        "user_id",
        "created_by"
    ]);

    $statusColumn = citizen_dash_first_existing_column($conn, "complaints", [
        "complaint_status",
        "status"
    ]);

    $whereParts = [];
    $types = "";
    $params = [];

    if ($userColumn !== "" && $userId > 0) {
        $whereParts[] = "`$userColumn` = ?";
        $types .= "i";
        $params[] = $userId;
    }

    if ($statusColumn !== "" && !empty($statusValues)) {
        $placeholders = implode(",", array_fill(0, count($statusValues), "?"));
        $whereParts[] = "LOWER(`$statusColumn`) IN ($placeholders)";
        $types .= str_repeat("s", count($statusValues));

        foreach ($statusValues as $status) {
            $params[] = strtolower($status);
        }
    }

    $whereSql = !empty($whereParts) ? "WHERE " . implode(" AND ", $whereParts) : "";

    $sql = "SELECT COUNT(*) AS total FROM complaints $whereSql";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return 0;
    }

    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return (int)($row["total"] ?? 0);
}

function citizen_dash_recent_complaints($conn, $userId, $limit = 3)
{
    if (!citizen_dash_table_exists($conn, "complaints")) {
        return [];
    }

    $userColumn = citizen_dash_first_existing_column($conn, "complaints", [
        "citizen_id",
        "user_id",
        "created_by"
    ]);

    $titleColumn = citizen_dash_first_existing_column($conn, "complaints", [
        "complaint_title",
        "issue_title",
        "issue",
        "description",
        "complaint_description"
    ]);

    $areaColumn = citizen_dash_first_existing_column($conn, "complaints", [
        "area_name",
        "area",
        "complaint_area",
        "location",
        "address"
    ]);

    $statusColumn = citizen_dash_first_existing_column($conn, "complaints", [
        "complaint_status",
        "status"
    ]);

    $dateColumn = citizen_dash_first_existing_column($conn, "complaints", [
        "created_at",
        "complaint_date",
        "submitted_at",
        "date"
    ]);

    $idColumn = citizen_dash_first_existing_column($conn, "complaints", [
        "complaint_id",
        "id"
    ]);

    $selectParts = [];

    $selectParts[] = $titleColumn !== "" ? "`$titleColumn` AS title" : "'' AS title";
    $selectParts[] = $areaColumn !== "" ? "`$areaColumn` AS area" : "'' AS area";
    $selectParts[] = $statusColumn !== "" ? "`$statusColumn` AS status_text" : "'Pending' AS status_text";
    $selectParts[] = $dateColumn !== "" ? "`$dateColumn` AS created_date" : "NULL AS created_date";

    $whereSql = "";
    $types = "";
    $params = [];

    if ($userColumn !== "" && $userId > 0) {
        $whereSql = "WHERE `$userColumn` = ?";
        $types = "i";
        $params[] = $userId;
    }

    $orderSql = "";

    if ($dateColumn !== "") {
        $orderSql = "ORDER BY `$dateColumn` DESC";
    } elseif ($idColumn !== "") {
        $orderSql = "ORDER BY `$idColumn` DESC";
    }

    $sql = "
        SELECT " . implode(", ", $selectParts) . "
        FROM complaints
        $whereSql
        $orderSql
        LIMIT ?
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return [];
    }

    $types .= "i";
    $params[] = $limit;

    mysqli_stmt_bind_param($stmt, $types, ...$params);
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

function citizen_dash_status_class($status)
{
    $status = strtolower(trim((string)$status));

    if (str_contains($status, "solve") || str_contains($status, "close") || str_contains($status, "complete")) {
        return "solved";
    }

    if (str_contains($status, "verify") || str_contains($status, "accept")) {
        return "verified";
    }

    return "progress";
}

$totalComplaints = citizen_dash_count_complaints($conn, $userId);
$pendingComplaints = citizen_dash_count_complaints($conn, $userId, ["pending", "submitted", "waiting"]);
$progressComplaints = citizen_dash_count_complaints($conn, $userId, ["in progress", "assigned", "working", "processing"]);
$solvedComplaints = citizen_dash_count_complaints($conn, $userId, ["solved", "closed", "completed", "complete"]);

$recentComplaints = citizen_dash_recent_complaints($conn, $userId, 3);

$kpiCards = [
    [
        "icon" => "bi-file-earmark-text",
        "color" => "cyan",
        "value" => $totalComplaints,
        "label" => "Total Complaints"
    ],
    [
        "icon" => "bi-clock",
        "color" => "blue",
        "value" => $pendingComplaints,
        "label" => "Pending Verification"
    ],
    [
        "icon" => "bi-exclamation-triangle",
        "color" => "orange",
        "value" => $progressComplaints,
        "label" => "In Progress"
    ],
    [
        "icon" => "bi-check-circle",
        "color" => "green",
        "value" => $solvedComplaints,
        "label" => "Solved / Closed"
    ]
];

$riskAreas = [
    [
        "class" => "critical",
        "title" => "Sector 15",
        "text" => "Repeated waterlogging complaints"
    ],
    [
        "class" => "warning",
        "title" => "Ward 3",
        "text" => "Drainage overflow reported frequently"
    ],
    [
        "class" => "stable",
        "title" => "Park Avenue",
        "text" => "Recently resolved maintenance zone"
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo citizen_dash_safe($pageTitle); ?> | DrainGuard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">
    <link rel="stylesheet" href="../../css/citizen/footer.css">
    <link rel="stylesheet" href="../../css/citizen/dashboard.css">
    <link rel="stylesheet" href="../../css/citizen/citizenTextFix.css">
</head>

<body class="citizen">

<div class="citizen-layout">

    <?php require_once "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main">

        <?php require_once "../../includes/citizen/topbar.php"; ?>

        <section class="dashboard-content">

            <div class="welcome-card">
                <h1>Welcome back, <?php echo citizen_dash_safe($userName); ?></h1>
                <p>Track and manage your drainage complaints easily.</p>
            </div>

            <div class="kpi-grid">
                <?php foreach ($kpiCards as $card): ?>
                    <div class="kpi-card">
                        <div class="kpi-icon <?php echo citizen_dash_safe($card["color"]); ?>">
                            <i class="bi <?php echo citizen_dash_safe($card["icon"]); ?>"></i>
                        </div>

                        <h2><?php echo number_format((int)$card["value"]); ?></h2>
                        <p><?php echo citizen_dash_safe($card["label"]); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="panel complaints-panel">
                <div class="panel-header">
                    <h2>My Complaints</h2>
                    <a href="my-complaints.php">View All <i class="bi bi-chevron-right"></i></a>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Issue</th>
                                <th>Area</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if (!empty($recentComplaints)): ?>
                                <?php foreach ($recentComplaints as $complaint): ?>
                                    <?php
                                    $statusText = $complaint["status_text"] ?? "Pending";
                                    $statusClass = citizen_dash_status_class($statusText);

                                    $dateText = "N/A";
                                    if (!empty($complaint["created_date"])) {
                                        $timestamp = strtotime($complaint["created_date"]);
                                        $dateText = $timestamp ? date("M d", $timestamp) : $complaint["created_date"];
                                    }
                                    ?>

                                    <tr>
                                        <td><?php echo citizen_dash_safe($complaint["title"] ?: "Drainage Complaint"); ?></td>
                                        <td><?php echo citizen_dash_safe($complaint["area"] ?: "N/A"); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo citizen_dash_safe($statusClass); ?>">
                                                <?php echo citizen_dash_safe($statusText); ?>
                                            </span>
                                        </td>
                                        <td><?php echo citizen_dash_safe($dateText); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="empty-table-cell">
                                        No complaints found yet. Submit your first complaint to start tracking.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="dashboard-row">

                <div class="panel track-panel">
                    <div class="mini-heading">
                        <div class="mini-icon">
                            <i class="bi bi-search"></i>
                        </div>

                        <h2>Track Complaint</h2>
                    </div>

                    <form class="track-form" id="trackComplaintForm" action="track-complaint.php" method="GET">
                        <input 
                            type="text" 
                            name="code" 
                            id="trackComplaintInput"
                            placeholder="Enter Complaint ID"
                            autocomplete="off"
                        >

                        <button type="submit">Track</button>
                    </form>

                    <small class="track-error" id="trackComplaintError"></small>
                </div>

                <div class="feedback-card">
                    <div class="feedback-icon">
                        <i class="bi bi-chat-left"></i>
                    </div>

                    <div>
                        <h2>Feedback Reminder</h2>
                        <p>You may have solved complaints waiting for your feedback.</p>
                        <a href="feedback-reopen.php">Give Feedback <i class="bi bi-chevron-right"></i></a>
                    </div>
                </div>

            </div>

            <div class="panel risk-panel">
                <div class="panel-header">
                    <h2>High Risk Areas</h2>
                    <a href="high-risk-areas.php">View More <i class="bi bi-chevron-right"></i></a>
                </div>

                <div class="risk-grid">
                    <?php foreach ($riskAreas as $risk): ?>
                        <div class="risk-item <?php echo citizen_dash_safe($risk["class"]); ?>">
                            <h3><?php echo citizen_dash_safe($risk["title"]); ?></h3>
                            <p><?php echo citizen_dash_safe($risk["text"]); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </section>

        <?php require_once "../../includes/citizen/footer.php"; ?>

    </main>

</div>

<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/citizen/dashboard.js"></script>

</body>
</html>