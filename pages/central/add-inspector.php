<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "user-management";
$pageTitle = "Add Inspector";

$successMessage = "";
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function generateEmployeeCode($designation, $id)
{
    $cleanDesignation = preg_replace("/[^A-Za-z]/", "", $designation);
    $prefix = strtoupper(substr($cleanDesignation, 0, 3));

    if ($prefix === "") {
        $prefix = "EMP";
    }

    $number = str_pad($id, 3, "0", STR_PAD_LEFT);

    return $prefix . "-" . $number;
}

function redirectAddInspector()
{
    header("Location: /DrainGuard/pages/central/add-inspector.php");
    exit();
}

function setFlashMessage($type, $message)
{
    if ($type === "success") {
        $_SESSION["add_inspector_success"] = $message;
    } else {
        $_SESSION["add_inspector_error"] = $message;
    }

    redirectAddInspector();
}

if (isset($_SESSION["add_inspector_success"])) {
    $successMessage = $_SESSION["add_inspector_success"];
    unset($_SESSION["add_inspector_success"]);
}

if (isset($_SESSION["add_inspector_error"])) {
    $errorMessage = $_SESSION["add_inspector_error"];
    unset($_SESSION["add_inspector_error"]);
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
| Fetch Wards With City Corporation
|--------------------------------------------------------------------------
*/

$wards = [];

$wardSql = "
    SELECT
        ward_id,
        city_cor_id,
        ward_no,
        ward_name
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

/*
|--------------------------------------------------------------------------
| Backend Process
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullName = trim($_POST["full_name"] ?? "");
    $email = strtolower(trim($_POST["email"] ?? ""));
    $phoneNumber = trim($_POST["phone_number"] ?? "");
    $gender = trim($_POST["gender"] ?? "");
    $designation = trim($_POST["designation"] ?? "");
    $cityCorId = (int)($_POST["city_cor_id"] ?? 0);
    $assignedWardId = (int)($_POST["assigned_ward_id"] ?? 0);
    $officeAddress = trim($_POST["office_address"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $loginAccess = trim($_POST["login_access"] ?? "");

    $password = trim($_POST["password"] ?? "");
    $confirmPassword = trim($_POST["confirm_password"] ?? "");

    if (
        $fullName === "" ||
        $email === "" ||
        $phoneNumber === "" ||
        $gender === "" ||
        $designation === "" ||
        $cityCorId <= 0 ||
        $assignedWardId <= 0 ||
        $officeAddress === "" ||
        $address === "" ||
        $loginAccess === ""
    ) {
        setFlashMessage("error", "Please fill up all required fields.");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlashMessage("error", "Invalid email address.");
    }

    $allowedGender = ["male", "female", "other"];

    if (!in_array($gender, $allowedGender, true)) {
        setFlashMessage("error", "Invalid gender selected.");
    }

    $allowedDesignation = [
        "Field Inspector",
        "Senior Field Inspector",
        "Drainage Inspection Officer",
        "Quality Assurance Inspector",
        "Inspection Supervisor",
        "Zone Inspection Officer",
        "Emergency Response Inspector"
    ];

    if (!in_array($designation, $allowedDesignation, true)) {
        setFlashMessage("error", "Invalid designation selected.");
    }

    if (!in_array($loginAccess, ["yes", "no"], true)) {
        setFlashMessage("error", "Invalid login access value.");
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
    | Validate Ward Under Selected City Corporation
    |--------------------------------------------------------------------------
    */

    $checkWardSql = "
        SELECT ward_id
        FROM wards
        WHERE ward_id = ?
        AND city_cor_id = ?
        LIMIT 1
    ";

    $checkWardStmt = mysqli_prepare($conn, $checkWardSql);

    if (!$checkWardStmt) {
        setFlashMessage("error", "Database error while checking ward.");
    }

    mysqli_stmt_bind_param($checkWardStmt, "ii", $assignedWardId, $cityCorId);
    mysqli_stmt_execute($checkWardStmt);

    $checkWardResult = mysqli_stmt_get_result($checkWardStmt);

    if (!$checkWardResult || mysqli_num_rows($checkWardResult) !== 1) {
        mysqli_stmt_close($checkWardStmt);
        setFlashMessage("error", "Invalid ward selected for this city corporation.");
    }

    mysqli_stmt_close($checkWardStmt);

    if ($loginAccess === "yes") {
        if ($password === "" || $confirmPassword === "") {
            setFlashMessage("error", "Password and confirm password are required when login access is yes.");
        }

        if ($password !== $confirmPassword) {
            setFlashMessage("error", "Password and confirm password do not match.");
        }

        if (strlen($password) < 6) {
            setFlashMessage("error", "Password must be at least 6 characters.");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate Email Check In users Table
    |--------------------------------------------------------------------------
    */

    $checkUserSql = "
        SELECT user_id
        FROM users
        WHERE user_mail = ?
        LIMIT 1
    ";

    $checkUserStmt = mysqli_prepare($conn, $checkUserSql);

    if (!$checkUserStmt) {
        setFlashMessage("error", "Database error while checking users table email.");
    }

    mysqli_stmt_bind_param($checkUserStmt, "s", $email);
    mysqli_stmt_execute($checkUserStmt);

    $checkUserResult = mysqli_stmt_get_result($checkUserStmt);

    if ($checkUserResult && mysqli_num_rows($checkUserResult) > 0) {
        mysqli_stmt_close($checkUserStmt);
        setFlashMessage("error", "This email already exists in users table.");
    }

    mysqli_stmt_close($checkUserStmt);

    /*
    |--------------------------------------------------------------------------
    | Duplicate Email Check In inspectors Table
    |--------------------------------------------------------------------------
    */

    $checkInspectorSql = "
        SELECT inspector_id
        FROM inspectors
        WHERE user_mail = ?
        LIMIT 1
    ";

    $checkInspectorStmt = mysqli_prepare($conn, $checkInspectorSql);

    if (!$checkInspectorStmt) {
        setFlashMessage("error", "Database error while checking inspectors table email.");
    }

    mysqli_stmt_bind_param($checkInspectorStmt, "s", $email);
    mysqli_stmt_execute($checkInspectorStmt);

    $checkInspectorResult = mysqli_stmt_get_result($checkInspectorStmt);

    if ($checkInspectorResult && mysqli_num_rows($checkInspectorResult) > 0) {
        mysqli_stmt_close($checkInspectorStmt);
        setFlashMessage("error", "This email already exists in inspectors table.");
    }

    mysqli_stmt_close($checkInspectorStmt);

    mysqli_begin_transaction($conn);

    try {
        $loginAccessValue = ($loginAccess === "yes") ? 1 : 0;

        if ($loginAccess === "yes") {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        } else {
            $hashedPassword = password_hash("NO_LOGIN_ACCESS_" . time(), PASSWORD_DEFAULT);
        }

        /*
        |--------------------------------------------------------------------------
        | Insert user
        |--------------------------------------------------------------------------
        */

        $insertUserSql = "
            INSERT INTO users (
                user_name,
                user_mail,
                user_password,
                user_role,
                user_status,
                login_access,
                reset_token,
                reset_time
            )
            VALUES (?, ?, ?, 'inspector', 'active', ?, NULL, NULL)
        ";

        $insertUserStmt = mysqli_prepare($conn, $insertUserSql);

        if (!$insertUserStmt) {
            throw new Exception("User insert preparation failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param(
            $insertUserStmt,
            "sssi",
            $fullName,
            $email,
            $hashedPassword,
            $loginAccessValue
        );

        if (!mysqli_stmt_execute($insertUserStmt)) {
            throw new Exception("User insert failed: " . mysqli_stmt_error($insertUserStmt));
        }

        $userId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($insertUserStmt);

        /*
        |--------------------------------------------------------------------------
        | Insert inspector
        |--------------------------------------------------------------------------
        */

        $insertInspectorSql = "
            INSERT INTO inspectors (
                user_id,
                city_cor_id,
                assigned_ward_id,
                user_mail,
                full_name,
                phone_number,
                employee_code,
                address,
                gender,
                designation,
                office_address
            )
            VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)
        ";

        $insertInspectorStmt = mysqli_prepare($conn, $insertInspectorSql);

        if (!$insertInspectorStmt) {
            throw new Exception("Inspector insert preparation failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param(
            $insertInspectorStmt,
            "iiisssssss",
            $userId,
            $cityCorId,
            $assignedWardId,
            $email,
            $fullName,
            $phoneNumber,
            $address,
            $gender,
            $designation,
            $officeAddress
        );

        if (!mysqli_stmt_execute($insertInspectorStmt)) {
            throw new Exception("Inspector insert failed: " . mysqli_stmt_error($insertInspectorStmt));
        }

        $inspectorId = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($insertInspectorStmt);

        /*
        |--------------------------------------------------------------------------
        | Generate and update employee code
        |--------------------------------------------------------------------------
        */

        $employeeCode = generateEmployeeCode($designation, $inspectorId);

        $updateCodeSql = "
            UPDATE inspectors
            SET employee_code = ?
            WHERE inspector_id = ?
        ";

        $updateCodeStmt = mysqli_prepare($conn, $updateCodeSql);

        if (!$updateCodeStmt) {
            throw new Exception("Employee code update preparation failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($updateCodeStmt, "si", $employeeCode, $inspectorId);

        if (!mysqli_stmt_execute($updateCodeStmt)) {
            throw new Exception("Employee code update failed: " . mysqli_stmt_error($updateCodeStmt));
        }

        mysqli_stmt_close($updateCodeStmt);

        mysqli_commit($conn);

        setFlashMessage("success", "Inspector added successfully. Employee Code: " . $employeeCode);

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
    <link rel="stylesheet" href="../../css/central/add-inspector.css">
    <link rel="stylesheet" href="../../css/central/centralTextFix.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="ai-page">

            <div class="ai-header-card">
                <div>
                    <h1>Add Inspector</h1>
                    <p>Create inspector information with optional login access.</p>
                </div>

                <a href="user-management.php" class="ai-back-btn">
                    <i class="bi bi-arrow-left"></i>
                    Back to Users
                </a>
            </div>

            <?php if ($successMessage !== ""): ?>
                <div class="ai-alert success">
                    <i class="bi bi-check-circle"></i>
                    <span><?php echo safeText($successMessage); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ""): ?>
                <div class="ai-alert error">
                    <i class="bi bi-exclamation-circle"></i>
                    <span><?php echo safeText($errorMessage); ?></span>
                </div>
            <?php endif; ?>

            <form action="add-inspector.php" method="POST" class="ai-form" id="inspectorForm">

                <div class="ai-card">
                    <div class="ai-section-title">
                        <div class="ai-section-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>

                        <div>
                            <h2>Inspector Information</h2>
                            <p>Employee code will be generated automatically after submission.</p>
                        </div>
                    </div>

                    <div class="ai-grid">

                        <div class="ai-field">
                            <label for="full_name">Full Name <span>*</span></label>
                            <input type="text" id="full_name" name="full_name" placeholder="Enter full name" required>
                        </div>

                        <div class="ai-field">
                            <label for="email">Email <span>*</span></label>
                            <input type="email" id="email" name="email" placeholder="example@gmail.com" required>
                        </div>

                        <div class="ai-field">
                            <label for="phone_number">Phone Number <span>*</span></label>
                            <input type="text" id="phone_number" name="phone_number" placeholder="01XXXXXXXXX" required>
                        </div>

                        <div class="ai-field">
                            <label for="gender">Gender <span>*</span></label>
                            <select id="gender" name="gender" required>
                                <option value="">Select gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <div class="ai-field">
                            <label for="designation">Designation <span>*</span></label>
                            <select id="designation" name="designation" required>
                                <option value="">Select designation</option>
                                <option value="Field Inspector">Field Inspector</option>
                                <option value="Senior Field Inspector">Senior Field Inspector</option>
                                <option value="Drainage Inspection Officer">Drainage Inspection Officer</option>
                                <option value="Quality Assurance Inspector">Quality Assurance Inspector</option>
                                <option value="Inspection Supervisor">Inspection Supervisor</option>
                                <option value="Zone Inspection Officer">Zone Inspection Officer</option>
                                <option value="Emergency Response Inspector">Emergency Response Inspector</option>
                            </select>
                        </div>

                        <div class="ai-field">
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

                        <div class="ai-field">
                            <label for="assigned_ward_id">Assigned Ward <span>*</span></label>
                            <select id="assigned_ward_id" name="assigned_ward_id" required disabled>
                                <option value="">Select city corporation first</option>
                            </select>
                        </div>

                        <div class="ai-field">
                            <label for="office_address">Office Address <span>*</span></label>
                            <input type="text" id="office_address" name="office_address" placeholder="Enter office address" required>
                        </div>

                        <div class="ai-field">
                            <label for="login_access">Login Access <span>*</span></label>
                            <select id="login_access" name="login_access" required>
                                <option value="">Select login access</option>
                                <option value="yes">Yes</option>
                                <option value="no">No</option>
                            </select>
                        </div>

                        <div class="ai-field full">
                            <label for="address">Personal Address <span>*</span></label>
                            <textarea id="address" name="address" rows="3" placeholder="Enter personal address" required></textarea>
                        </div>

                    </div>
                </div>

                <div class="ai-card ai-login-card" id="loginAccessCard">
                    <div class="ai-section-title">
                        <div class="ai-section-icon">
                            <i class="bi bi-person-lock"></i>
                        </div>

                        <div>
                            <h2>Login Information</h2>
                            <p>Password is required only when login access is yes.</p>
                        </div>
                    </div>

                    <div class="ai-grid">

                        <div class="ai-field ai-password-field">
                            <label for="password">Password <span>*</span></label>
                            <div class="ai-password-wrap">
                                <input type="password" id="password" name="password" placeholder="Create password">
                                <button type="button" class="ai-password-toggle" data-target="password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="ai-field ai-password-field">
                            <label for="confirm_password">Confirm Password <span>*</span></label>
                            <div class="ai-password-wrap">
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm password">
                                <button type="button" class="ai-password-toggle" data-target="confirm_password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="ai-actions">
                    <a href="user-management.php" class="ai-cancel-btn">Cancel</a>

                    <button type="submit" class="ai-submit-btn">
                        <i class="bi bi-person-plus"></i>
                        Save Inspector
                    </button>
                </div>

            </form>

        </section>

        <?php include "../../includes/central/footer.php"; ?>

    </main>

</div>

<script>
    window.DG_INSPECTOR_WARDS = <?php echo $wardJson ?: "[]"; ?>;
</script>
<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/add-inspector.js"></script>

</body>
</html>