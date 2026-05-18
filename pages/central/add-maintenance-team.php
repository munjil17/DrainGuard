<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "user-management";
$pageTitle = "Add Maintenance Team";
$pageParent = "Central Control";
$pageChild = "Add Maintenance Team";

$successMessage = "";
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

function redirectAddMaintenanceTeam()
{
    header("Location: /DrainGuard/pages/central/add-maintenance-team.php");
    exit();
}

function setFlashMessage($type, $message)
{
    if ($type === "success") {
        $_SESSION["add_maintenance_success"] = $message;
    } else {
        $_SESSION["add_maintenance_error"] = $message;
    }

    redirectAddMaintenanceTeam();
}

if (isset($_SESSION["add_maintenance_success"])) {
    $successMessage = $_SESSION["add_maintenance_success"];
    unset($_SESSION["add_maintenance_success"]);
}

if (isset($_SESSION["add_maintenance_error"])) {
    $errorMessage = $_SESSION["add_maintenance_error"];
    unset($_SESSION["add_maintenance_error"]);
}

/*
|--------------------------------------------------------------------------
| Fetch Thanas
|--------------------------------------------------------------------------
*/

$thanas = [];

$thanaSql = "
    SELECT thana_id, thana_name
    FROM thanas
    ORDER BY thana_name ASC
";

$thanaResult = mysqli_query($conn, $thanaSql);

if ($thanaResult) {
    while ($row = mysqli_fetch_assoc($thanaResult)) {
        $thanas[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Backend Process
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $teamName = trim($_POST["team_name"] ?? "");
    $teamName = preg_replace("/\s+/", " ", $teamName);

    $assignedThanaId = trim($_POST["assigned_thana_id"] ?? "");

    if ($teamName === "" || $assignedThanaId === "") {
        setFlashMessage("error", "Please fill up all required fields.");
    }

    if (!is_numeric($assignedThanaId) || (int)$assignedThanaId <= 0) {
        setFlashMessage("error", "Invalid thana selected.");
    }

    $assignedThanaId = (int)$assignedThanaId;

    /*
        Team create only.
        assistant_login_access is controlled later from Add Team Member page.
        availability_status is system controlled.
        Default: available
    */

    $assistantLoginAccess = "no";
    $availabilityStatus = "available";

    mysqli_begin_transaction($conn);

    try {
        /*
        |--------------------------------------------------------------------------
        | Duplicate Check
        |--------------------------------------------------------------------------
        */

        $checkSql = "
            SELECT maintenance_team_id
            FROM maintenance_teams
            WHERE team_name = ?
            AND assigned_thana_id = ?
            LIMIT 1
        ";

        $checkStmt = mysqli_prepare($conn, $checkSql);

        if (!$checkStmt) {
            throw new Exception("Team check preparation failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($checkStmt, "si", $teamName, $assignedThanaId);
        mysqli_stmt_execute($checkStmt);

        $checkResult = mysqli_stmt_get_result($checkStmt);

        if ($checkResult && mysqli_num_rows($checkResult) > 0) {
            mysqli_stmt_close($checkStmt);
            throw new Exception("This maintenance team already exists for selected thana.");
        }

        mysqli_stmt_close($checkStmt);

        /*
        |--------------------------------------------------------------------------
        | Insert Maintenance Team
        |--------------------------------------------------------------------------
        | Skill type removed.
        | Availability status will always start as available.
        |--------------------------------------------------------------------------
        */

        $insertSql = "
            INSERT INTO maintenance_teams (
                team_name,
                assigned_thana_id,
                availability_status,
                assistant_login_access
            )
            VALUES (?, ?, ?, ?)
        ";

        $insertStmt = mysqli_prepare($conn, $insertSql);

        if (!$insertStmt) {
            throw new Exception("Team insert preparation failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param(
            $insertStmt,
            "siss",
            $teamName,
            $assignedThanaId,
            $availabilityStatus,
            $assistantLoginAccess
        );

        if (!mysqli_stmt_execute($insertStmt)) {
            throw new Exception("Team insert failed: " . mysqli_stmt_error($insertStmt));
        }

        mysqli_stmt_close($insertStmt);

        mysqli_commit($conn);

        setFlashMessage("success", "Maintenance team created successfully. Now add team members.");

    } catch (Exception $e) {
        mysqli_rollback($conn);
        setFlashMessage("error", $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo safeText($pageTitle); ?> | DrainGuard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">
    <link rel="stylesheet" href="../../css/central/footer.css">
    <link rel="stylesheet" href="../../css/central/add-maintenance-team.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="amt-page">

            <div class="amt-header-card">
                <div>
                    <h1>Add Maintenance Team</h1>
                    <p>Create maintenance team only. Team members will be added separately.</p>
                </div>

                <a href="user-management.php" class="amt-back-btn">
                    <i class="bi bi-arrow-left"></i>
                    Back to Users
                </a>
            </div>

            <?php if ($successMessage !== ""): ?>
                <div class="amt-alert success">
                    <i class="bi bi-check-circle"></i>
                    <span><?php echo safeText($successMessage); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ""): ?>
                <div class="amt-alert error">
                    <i class="bi bi-exclamation-circle"></i>
                    <span><?php echo safeText($errorMessage); ?></span>
                </div>
            <?php endif; ?>

            <form action="add-maintenance-team.php" method="POST" class="amt-form" id="maintenanceTeamForm" autocomplete="off">

                <div class="amt-card">
                    <div class="amt-section-title">
                        <div class="amt-section-icon">
                            <i class="bi bi-tools"></i>
                        </div>

                        <div>
                            <h2>Maintenance Team Information</h2>
                            <p>Only team name and assigned thana will be saved here.</p>
                        </div>
                    </div>

                    <div class="amt-grid">

                        <div class="amt-field">
                            <label for="team_name">Team Name <span>*</span></label>
                            <input
                                type="text"
                                id="team_name"
                                name="team_name"
                                placeholder="Drain Cleaning Team Alpha"
                                required
                            >
                        </div>

                        <div class="amt-field">
                            <label for="assigned_thana_id">Assigned Thana <span>*</span></label>
                            <select id="assigned_thana_id" name="assigned_thana_id" required>
                                <option value="">Select thana</option>

                                <?php foreach ($thanas as $thana): ?>
                                    <option value="<?php echo (int)$thana["thana_id"]; ?>">
                                        <?php echo safeText($thana["thana_name"]); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>
                </div>

                <div class="amt-actions">
                    <a href="user-management.php" class="amt-cancel-btn">Cancel</a>

                    <button type="submit" class="amt-submit-btn">
                        <i class="bi bi-tools"></i>
                        Save Maintenance Team
                    </button>
                </div>

            </form>

        </section>

        <?php include "../../includes/central/footer.php"; ?>

    </main>

</div>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/add-maintenance-team.js"></script>

</body>
</html>