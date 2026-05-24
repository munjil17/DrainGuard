<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "user-management";
$pageTitle = "Edit Ward Officer";

$wardOfficerId = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function redirectUserManagement()
{
    header("Location: /DrainGuard/pages/central/user-management.php");
    exit();
}

function redirectEditWardOfficer($id)
{
    header("Location: /DrainGuard/pages/central/edit-ward-officer.php?id=" . (int)$id);
    exit();
}

if ($wardOfficerId <= 0) {
    $_SESSION["user_management_error"] = "Invalid ward officer selected.";
    redirectUserManagement();
}

$cityCorporations = [];
$citySql = "SELECT city_cor_id, city_cor_name FROM city_corporations ORDER BY city_cor_name ASC";
$cityResult = mysqli_query($conn, $citySql);

if ($cityResult) {
    while ($row = mysqli_fetch_assoc($cityResult)) {
        $cityCorporations[] = $row;
    }
}

$wards = [];
$wardSql = "
    SELECT ward_id, city_cor_id, ward_no, ward_name
    FROM wards
    ORDER BY city_cor_id ASC, ward_no ASC
";
$wardResult = mysqli_query($conn, $wardSql);

if ($wardResult) {
    while ($row = mysqli_fetch_assoc($wardResult)) {
        $wards[] = $row;
    }
}

$wardJson = json_encode($wards, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$allowedDesignation = [
    "Ward Officer",
    "Senior Ward Officer",
    "Ward Operations Officer",
    "Ward Drainage Coordinator",
    "Ward Verification Officer",
    "Zone Ward Supervisor",
    "Emergency Ward Response Officer"
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $designation = trim($_POST["designation"] ?? "");
    $cityCorId = (int)($_POST["city_cor_id"] ?? 0);
    $assignedWardId = (int)($_POST["assigned_ward_id"] ?? 0);
    $officeAddress = trim($_POST["office_address"] ?? "");

    if ($designation === "" || $cityCorId <= 0 || $assignedWardId <= 0 || $officeAddress === "") {
        $_SESSION["user_management_error"] = "Please fill up all required fields.";
        redirectEditWardOfficer($wardOfficerId);
    }

    if (!in_array($designation, $allowedDesignation, true)) {
        $_SESSION["user_management_error"] = "Invalid designation selected.";
        redirectEditWardOfficer($wardOfficerId);
    }

    $checkWardSql = "
        SELECT ward_id
        FROM wards
        WHERE ward_id = ?
        AND city_cor_id = ?
        LIMIT 1
    ";

    $checkWardStmt = mysqli_prepare($conn, $checkWardSql);

    if (!$checkWardStmt) {
        $_SESSION["user_management_error"] = "Database error while checking ward.";
        redirectEditWardOfficer($wardOfficerId);
    }

    mysqli_stmt_bind_param($checkWardStmt, "ii", $assignedWardId, $cityCorId);
    mysqli_stmt_execute($checkWardStmt);

    $checkWardResult = mysqli_stmt_get_result($checkWardStmt);

    if (!$checkWardResult || mysqli_num_rows($checkWardResult) !== 1) {
        mysqli_stmt_close($checkWardStmt);
        $_SESSION["user_management_error"] = "Invalid ward selected for this city corporation.";
        redirectEditWardOfficer($wardOfficerId);
    }

    mysqli_stmt_close($checkWardStmt);

    $updateSql = "
        UPDATE ward_officers
        SET designation = ?,
            city_cor_id = ?,
            assigned_ward_id = ?,
            office_address = ?
        WHERE ward_officer_id = ?
        LIMIT 1
    ";

    $updateStmt = mysqli_prepare($conn, $updateSql);

    if (!$updateStmt) {
        $_SESSION["user_management_error"] = "Ward officer update preparation failed.";
        redirectEditWardOfficer($wardOfficerId);
    }

    mysqli_stmt_bind_param(
        $updateStmt,
        "siisi",
        $designation,
        $cityCorId,
        $assignedWardId,
        $officeAddress,
        $wardOfficerId
    );

    if (!mysqli_stmt_execute($updateStmt)) {
        mysqli_stmt_close($updateStmt);
        $_SESSION["user_management_error"] = "Ward officer update failed.";
        redirectEditWardOfficer($wardOfficerId);
    }

    mysqli_stmt_close($updateStmt);

    $_SESSION["user_management_success"] = "Ward officer updated successfully.";
    redirectUserManagement();
}

$wardOfficerSql = "
    SELECT
        ward_officer_id,
        full_name,
        user_mail,
        employee_code,
        designation,
        city_cor_id,
        assigned_ward_id,
        office_address
    FROM ward_officers
    WHERE ward_officer_id = ?
    LIMIT 1
";

$wardOfficerStmt = mysqli_prepare($conn, $wardOfficerSql);

if (!$wardOfficerStmt) {
    $_SESSION["user_management_error"] = "Ward officer query failed.";
    redirectUserManagement();
}

mysqli_stmt_bind_param($wardOfficerStmt, "i", $wardOfficerId);
mysqli_stmt_execute($wardOfficerStmt);

$wardOfficerResult = mysqli_stmt_get_result($wardOfficerStmt);
$wardOfficer = mysqli_fetch_assoc($wardOfficerResult);

mysqli_stmt_close($wardOfficerStmt);

if (!$wardOfficer) {
    $_SESSION["user_management_error"] = "Ward officer not found.";
    redirectUserManagement();
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
    <link rel="stylesheet" href="../../css/central/add-ward-officer.css">
    <link rel="stylesheet" href="../../css/central/centralTextFix.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="awo-page">

            <div class="awo-header-card">
                <div>
                    <h1>Edit Ward Officer</h1>
                    <p>Update designation, city corporation, assigned ward, and office address only.</p>
                </div>

                <a href="user-management.php" class="awo-back-btn">
                    <i class="bi bi-arrow-left"></i>
                    Back to Users
                </a>
            </div>

            <form action="edit-ward-officer.php?id=<?php echo (int)$wardOfficerId; ?>" method="POST" class="awo-form">

                <div class="awo-card">
                    <div class="awo-section-title">
                        <div class="awo-section-icon">
                            <i class="bi bi-building"></i>
                        </div>

                        <div>
                            <h2><?php echo safeText($wardOfficer["full_name"]); ?></h2>
                            <p>Emp Code: <?php echo safeText($wardOfficer["employee_code"] ?: "N/A"); ?> | Email: <?php echo safeText($wardOfficer["user_mail"]); ?></p>
                        </div>
                    </div>

                    <div class="awo-grid">

                        <div class="awo-field">
                            <label for="designation">Designation <span>*</span></label>
                            <select id="designation" name="designation" required>
                                <option value="">Select designation</option>
                                <?php foreach ($allowedDesignation as $designation): ?>
                                    <option value="<?php echo safeText($designation); ?>" <?php echo ($wardOfficer["designation"] === $designation) ? "selected" : ""; ?>>
                                        <?php echo safeText($designation); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="awo-field">
                            <label for="city_cor_id">City Corporation <span>*</span></label>
                            <select id="city_cor_id" name="city_cor_id" required>
                                <option value="">Select city corporation</option>
                                <?php foreach ($cityCorporations as $city): ?>
                                    <option value="<?php echo (int)$city["city_cor_id"]; ?>" <?php echo ((int)$wardOfficer["city_cor_id"] === (int)$city["city_cor_id"]) ? "selected" : ""; ?>>
                                        <?php echo safeText($city["city_cor_name"]); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="awo-field">
                            <label for="assigned_ward_id">Assigned Ward <span>*</span></label>
                            <select
                                id="assigned_ward_id"
                                name="assigned_ward_id"
                                data-selected-ward="<?php echo (int)$wardOfficer["assigned_ward_id"]; ?>"
                                required
                            >
                                <option value="">Select ward</option>
                            </select>
                        </div>

                        <div class="awo-field">
                            <label for="office_address">Office Address <span>*</span></label>
                            <input type="text" id="office_address" name="office_address" value="<?php echo safeText($wardOfficer["office_address"]); ?>" required>
                        </div>

                    </div>
                </div>

                <div class="awo-actions">
                    <a href="user-management.php" class="awo-cancel-btn">Cancel</a>

                    <button type="submit" class="awo-submit-btn">
                        <i class="bi bi-check-circle"></i>
                        Update Ward Officer
                    </button>
                </div>

            </form>

        </section>

        <?php include "../../includes/central/footer.php"; ?>

    </main>

</div>

<script>
    window.DG_WARD_OFFICER_WARDS = <?php echo $wardJson ?: "[]"; ?>;
</script>
<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/edit-ward-officer.js"></script>

</body>
</html>