<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "user-management";
$pageTitle = "Add Maintenance Team";

$successMessage = "";
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function redirectAddMaintenanceTeam()
{
    header("Location: /DrainGuard/pages/central/add-maintenance-team.php");
    exit();
}

function setFlashMessage($type, $message)
{
    if ($type === "success") {
        $_SESSION["add_maintenance_team_success"] = $message;
    } else {
        $_SESSION["add_maintenance_team_error"] = $message;
    }

    redirectAddMaintenanceTeam();
}

if (isset($_SESSION["add_maintenance_team_success"])) {
    $successMessage = $_SESSION["add_maintenance_team_success"];
    unset($_SESSION["add_maintenance_team_success"]);
}

if (isset($_SESSION["add_maintenance_team_error"])) {
    $errorMessage = $_SESSION["add_maintenance_team_error"];
    unset($_SESSION["add_maintenance_team_error"]);
}

/*
|--------------------------------------------------------------------------
| Fetch City Corporations
|--------------------------------------------------------------------------
*/

$cityCorporations = [];

$cityCorSql = "
    SELECT city_cor_id, city_cor_name
    FROM city_corporations
    ORDER BY city_cor_name ASC
";

$cityCorResult = mysqli_query($conn, $cityCorSql);

if ($cityCorResult) {
    while ($row = mysqli_fetch_assoc($cityCorResult)) {
        $cityCorporations[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Fetch Anchals With City Corporation
|--------------------------------------------------------------------------
*/

$anchals = [];

$anchalSql = "
    SELECT
        anchal_id,
        city_cor_id,
        anchal_name
    FROM anchals
    ORDER BY city_cor_id ASC, anchal_name ASC
";

$anchalResult = mysqli_query($conn, $anchalSql);

if ($anchalResult) {
    while ($row = mysqli_fetch_assoc($anchalResult)) {
        $anchals[] = $row;
    }
}

$anchalJson = json_encode($anchals, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

/*
|--------------------------------------------------------------------------
| Backend Process
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $teamName = trim($_POST["team_name"] ?? "");
    $cityCorId = (int)($_POST["city_cor_id"] ?? 0);
    $anchalId = (int)($_POST["anchal_id"] ?? 0);

    if (
        $teamName === "" ||
        $cityCorId <= 0 ||
        $anchalId <= 0
    ) {
        setFlashMessage("error", "Please fill up all required fields.");
    }

    if (strlen($teamName) < 3) {
        setFlashMessage("error", "Team name must be at least 3 characters.");
    }

    /*
    |--------------------------------------------------------------------------
    | Validate City Corporation
    |--------------------------------------------------------------------------
    */

    $checkCityCorSql = "
        SELECT city_cor_id
        FROM city_corporations
        WHERE city_cor_id = ?
        LIMIT 1
    ";

    $checkCityCorStmt = mysqli_prepare($conn, $checkCityCorSql);

    if (!$checkCityCorStmt) {
        setFlashMessage("error", "Database error while checking city corporation.");
    }

    mysqli_stmt_bind_param($checkCityCorStmt, "i", $cityCorId);
    mysqli_stmt_execute($checkCityCorStmt);

    $checkCityCorResult = mysqli_stmt_get_result($checkCityCorStmt);

    if (!$checkCityCorResult || mysqli_num_rows($checkCityCorResult) !== 1) {
        mysqli_stmt_close($checkCityCorStmt);
        setFlashMessage("error", "Invalid city corporation selected.");
    }

    mysqli_stmt_close($checkCityCorStmt);

    /*
    |--------------------------------------------------------------------------
    | Validate Anchal Under Selected City Corporation
    |--------------------------------------------------------------------------
    */

    $checkAnchalSql = "
        SELECT anchal_id
        FROM anchals
        WHERE anchal_id = ?
        AND city_cor_id = ?
        LIMIT 1
    ";

    $checkAnchalStmt = mysqli_prepare($conn, $checkAnchalSql);

    if (!$checkAnchalStmt) {
        setFlashMessage("error", "Database error while checking anchal.");
    }

    mysqli_stmt_bind_param($checkAnchalStmt, "ii", $anchalId, $cityCorId);
    mysqli_stmt_execute($checkAnchalStmt);

    $checkAnchalResult = mysqli_stmt_get_result($checkAnchalStmt);

    if (!$checkAnchalResult || mysqli_num_rows($checkAnchalResult) !== 1) {
        mysqli_stmt_close($checkAnchalStmt);
        setFlashMessage("error", "Invalid anchal selected for this city corporation.");
    }

    mysqli_stmt_close($checkAnchalStmt);

    /*
    |--------------------------------------------------------------------------
    | Duplicate Team Check
    |--------------------------------------------------------------------------
    */

    $checkTeamSql = "
        SELECT maintenance_team_id
        FROM maintenance_teams
        WHERE team_name = ?
        LIMIT 1
    ";

    $checkTeamStmt = mysqli_prepare($conn, $checkTeamSql);

    if (!$checkTeamStmt) {
        setFlashMessage("error", "Database error while checking maintenance team.");
    }

    mysqli_stmt_bind_param($checkTeamStmt, "s", $teamName);
    mysqli_stmt_execute($checkTeamStmt);

    $checkTeamResult = mysqli_stmt_get_result($checkTeamStmt);

    if ($checkTeamResult && mysqli_num_rows($checkTeamResult) > 0) {
        mysqli_stmt_close($checkTeamStmt);
        setFlashMessage("error", "This maintenance team name already exists.");
    }

    mysqli_stmt_close($checkTeamStmt);

    mysqli_begin_transaction($conn);

    try {
        $insertTeamSql = "
            INSERT INTO maintenance_teams (
                team_name,
                city_cor_id,
                anchal_id,
                availability_status
            )
            VALUES (?, ?, ?, 'available')
        ";

        $insertTeamStmt = mysqli_prepare($conn, $insertTeamSql);

        if (!$insertTeamStmt) {
            throw new Exception("Maintenance team insert preparation failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param(
            $insertTeamStmt,
            "sii",
            $teamName,
            $cityCorId,
            $anchalId
        );

        if (!mysqli_stmt_execute($insertTeamStmt)) {
            throw new Exception("Maintenance team insert failed: " . mysqli_stmt_error($insertTeamStmt));
        }

        $maintenanceTeamId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($insertTeamStmt);

        mysqli_commit($conn);

        $_SESSION["add_maintenance_team_success"] = "Maintenance team added successfully. Now add team members.";

        header("Location: /DrainGuard/pages/central/add-team-member.php?team_id=" . $maintenanceTeamId);
        exit();

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
   
    <link rel="stylesheet" href="../../css/central/add-maintenance-team.css">
    <link rel="stylesheet" href="../../css/central/centralTextFix.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
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

            <form action="add-maintenance-team.php" method="POST" class="amt-form" id="maintenanceTeamForm">

                <div class="amt-card">
                    <div class="amt-section-title">
                        <div class="amt-section-icon">
                            <i class="bi bi-tools"></i>
                        </div>

                        <div>
                            <h2>Maintenance Team Information</h2>
                            <p>Team will be assigned by city corporation and anchal.</p>
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
                            <label for="city_cor_id">City Corporation <span>*</span></label>
                            <select id="city_cor_id" name="city_cor_id" required>
                                <option value="">Select city corporation</option>

                                <?php foreach ($cityCorporations as $cityCorporation): ?>
                                    <option value="<?php echo (int)$cityCorporation["city_cor_id"]; ?>">
                                        <?php echo safeText($cityCorporation["city_cor_name"]); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="amt-field">
                            <label for="anchal_id">Assigned Anchal <span>*</span></label>
                            <select id="anchal_id" name="anchal_id" required disabled>
                                <option value="">Select city corporation first</option>
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

      

    </main>

</div>

<script>
    window.DG_MAINTENANCE_ANCHALS = <?php echo $anchalJson ?: "[]"; ?>;
</script>
<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/add-maintenance-team.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>