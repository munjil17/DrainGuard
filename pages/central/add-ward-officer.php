<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "user-management";
$pageTitle = "Add Ward Officer";

$successMessage = "";
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

function generateEmployeeCode($designation, $id)
{
    $cleanDesignation = preg_replace("/[^A-Za-z]/", "", $designation);
    $prefix = strtoupper(substr($cleanDesignation, 0, 3));

    if ($prefix === "") {
        $prefix = "EMP";
    }

    return $prefix . "-" . str_pad($id, 3, "0", STR_PAD_LEFT);
}

function redirectAddWardOfficer()
{
    header("Location: /DrainGuard/pages/central/add-ward-officer.php");
    exit();
}

function setFlashMessage($type, $message)
{
    if ($type === "success") {
        $_SESSION["add_ward_success"] = $message;
    } else {
        $_SESSION["add_ward_error"] = $message;
    }

    redirectAddWardOfficer();
}

if (isset($_SESSION["add_ward_success"])) {
    $successMessage = $_SESSION["add_ward_success"];
    unset($_SESSION["add_ward_success"]);
}

if (isset($_SESSION["add_ward_error"])) {
    $errorMessage = $_SESSION["add_ward_error"];
    unset($_SESSION["add_ward_error"]);
}

/*
|--------------------------------------------------------------------------
| Fetch Wards
|--------------------------------------------------------------------------
*/

$wards = [];

$wardSql = "
    SELECT ward_id, ward_no
    FROM wards
    ORDER BY ward_no ASC
";

$wardResult = mysqli_query($conn, $wardSql);

if ($wardResult) {
    while ($row = mysqli_fetch_assoc($wardResult)) {
        $wards[] = $row;
    }
}

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
    $assignedWardNo = trim($_POST["assigned_ward_no"] ?? "");
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
        $assignedWardNo === "" ||
        $officeAddress === "" ||
        $address === "" ||
        $loginAccess === ""
    ) {
        setFlashMessage("error", "Please fill up all required fields.");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlashMessage("error", "Invalid email address.");
    }

    if (!in_array($gender, ["male", "female", "other"], true)) {
        setFlashMessage("error", "Invalid gender selected.");
    }

    if (!in_array($designation, ["Ward Officer", "Assistant Ward Officer"], true)) {
        setFlashMessage("error", "Invalid designation selected.");
    }

    if (!is_numeric($assignedWardNo) || (int)$assignedWardNo <= 0) {
        setFlashMessage("error", "Invalid ward selected.");
    }

    $assignedWardNo = (int)$assignedWardNo;

    if (!in_array($loginAccess, ["yes", "no"], true)) {
        setFlashMessage("error", "Invalid login access value.");
    }

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
    | Duplicate Email Check: users
    |--------------------------------------------------------------------------
    */

    $checkUserSql = "SELECT user_id FROM users WHERE user_mail = ? LIMIT 1";
    $checkUserStmt = mysqli_prepare($conn, $checkUserSql);

    if (!$checkUserStmt) {
        setFlashMessage("error", "Database error while checking users table email.");
    }

    mysqli_stmt_bind_param($checkUserStmt, "s", $email);
    mysqli_stmt_execute($checkUserStmt);

    $checkUserResult = mysqli_stmt_get_result($checkUserStmt);

    if (mysqli_num_rows($checkUserResult) > 0) {
        mysqli_stmt_close($checkUserStmt);
        setFlashMessage("error", "This email already exists in users table.");
    }

    mysqli_stmt_close($checkUserStmt);

    /*
    |--------------------------------------------------------------------------
    | Duplicate Email Check: ward_officers
    |--------------------------------------------------------------------------
    */

    $checkWardSql = "SELECT ward_officer_id FROM ward_officers WHERE user_mail = ? LIMIT 1";
    $checkWardStmt = mysqli_prepare($conn, $checkWardSql);

    if (!$checkWardStmt) {
        setFlashMessage("error", "Database error while checking ward officers table email.");
    }

    mysqli_stmt_bind_param($checkWardStmt, "s", $email);
    mysqli_stmt_execute($checkWardStmt);

    $checkWardResult = mysqli_stmt_get_result($checkWardStmt);

    if (mysqli_num_rows($checkWardResult) > 0) {
        mysqli_stmt_close($checkWardStmt);
        setFlashMessage("error", "This email already exists in ward officers table.");
    }

    mysqli_stmt_close($checkWardStmt);

    mysqli_begin_transaction($conn);

    try {
        /*
        |--------------------------------------------------------------------------
        | Step 1: Insert users
        |--------------------------------------------------------------------------
        */

        $loginAccessValue = ($loginAccess === "yes") ? 1 : 0;

        if ($loginAccess === "yes") {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        } else {
            $hashedPassword = password_hash("NO_LOGIN_ACCESS_" . time(), PASSWORD_DEFAULT);
        }

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
            VALUES (?, ?, ?, 'ward_officer', 'active', ?, NULL, NULL)
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

        $userId = mysqli_insert_id($conn);
        mysqli_stmt_close($insertUserStmt);

        /*
        |--------------------------------------------------------------------------
        | Step 2: Insert ward_officers
        |--------------------------------------------------------------------------
        */

        $insertWardSql = "
            INSERT INTO ward_officers (
                user_id,
                user_mail,
                full_name,
                phone_number,
                employee_code,
                address,
                gender,
                designation,
                assigned_ward_no,
                office_address
            )
            VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)
        ";

        $insertWardStmt = mysqli_prepare($conn, $insertWardSql);

        if (!$insertWardStmt) {
            throw new Exception("Ward officer insert preparation failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param(
            $insertWardStmt,
            "issssssis",
            $userId,
            $email,
            $fullName,
            $phoneNumber,
            $address,
            $gender,
            $designation,
            $assignedWardNo,
            $officeAddress
        );

        if (!mysqli_stmt_execute($insertWardStmt)) {
            throw new Exception("Ward officer insert failed: " . mysqli_stmt_error($insertWardStmt));
        }

        $wardOfficerId = mysqli_insert_id($conn);
        mysqli_stmt_close($insertWardStmt);

        /*
        |--------------------------------------------------------------------------
        | Step 3: Generate employee_code
        |--------------------------------------------------------------------------
        */

        $employeeCode = generateEmployeeCode($designation, $wardOfficerId);

        $updateCodeSql = "
            UPDATE ward_officers
            SET employee_code = ?
            WHERE ward_officer_id = ?
        ";

        $updateCodeStmt = mysqli_prepare($conn, $updateCodeSql);

        if (!$updateCodeStmt) {
            throw new Exception("Employee code update preparation failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($updateCodeStmt, "si", $employeeCode, $wardOfficerId);

        if (!mysqli_stmt_execute($updateCodeStmt)) {
            throw new Exception("Employee code update failed: " . mysqli_stmt_error($updateCodeStmt));
        }

        mysqli_stmt_close($updateCodeStmt);

        mysqli_commit($conn);

        setFlashMessage("success", "Ward officer added successfully. Employee Code: " . $employeeCode);

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
    <link rel="stylesheet" href="../../css/central/add-ward-officer.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="awo-page">

            <div class="awo-header-card">
                <div>
                    <h1>Add Ward Officer</h1>
                    <p>Create ward officer information with optional login access.</p>
                </div>

                <a href="user-management.php" class="awo-back-btn">
                    <i class="bi bi-arrow-left"></i>
                    Back to Users
                </a>
            </div>

            <?php if ($successMessage !== ""): ?>
                <div class="awo-alert success">
                    <i class="bi bi-check-circle"></i>
                    <span><?php echo safeText($successMessage); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ""): ?>
                <div class="awo-alert error">
                    <i class="bi bi-exclamation-circle"></i>
                    <span><?php echo safeText($errorMessage); ?></span>
                </div>
            <?php endif; ?>

            <form action="add-ward-officer.php" method="POST" class="awo-form" id="wardOfficerForm">

                <div class="awo-card">
                    <div class="awo-section-title">
                        <div class="awo-section-icon">
                            <i class="bi bi-building"></i>
                        </div>

                        <div>
                            <h2>Ward Officer Information</h2>
                            <p>Employee code will be generated automatically after submission.</p>
                        </div>
                    </div>

                    <div class="awo-grid">

                        <div class="awo-field">
                            <label for="full_name">Full Name <span>*</span></label>
                            <input type="text" id="full_name" name="full_name" placeholder="Enter full name" required>
                        </div>

                        <div class="awo-field">
                            <label for="email">Email <span>*</span></label>
                            <input type="email" id="email" name="email" placeholder="example@gmail.com" required>
                        </div>

                        <div class="awo-field">
                            <label for="phone_number">Phone Number <span>*</span></label>
                            <input type="text" id="phone_number" name="phone_number" placeholder="01XXXXXXXXX" required>
                        </div>

                        <div class="awo-field">
                            <label for="gender">Gender <span>*</span></label>
                            <select id="gender" name="gender" required>
                                <option value="">Select gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <div class="awo-field">
                            <label for="designation">Designation <span>*</span></label>
                            <select id="designation" name="designation" required>
                                <option value="">Select designation</option>
                                <option value="Ward Officer">Ward Officer</option>
                                <option value="Assistant Ward Officer">Assistant Ward Officer</option>
                            </select>
                        </div>

                        <div class="awo-field">
                            <label for="assigned_ward_no">Assigned Ward No <span>*</span></label>

                            <select id="assigned_ward_no" name="assigned_ward_no" required>
                                <option value="">Select ward</option>

                                <?php foreach ($wards as $ward): ?>
                                    <option value="<?php echo (int)$ward["ward_no"]; ?>">
                                        Ward <?php echo safeText($ward["ward_no"]); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="awo-field">
                            <label for="office_address">Office Address <span>*</span></label>
                            <input type="text" id="office_address" name="office_address" placeholder="Enter office address" required>
                        </div>

                        <div class="awo-field">
                            <label for="login_access">Login Access <span>*</span></label>
                            <select id="login_access" name="login_access" required>
                                <option value="">Select login access</option>
                                <option value="yes">Yes</option>
                                <option value="no">No</option>
                            </select>
                        </div>

                        <div class="awo-field full">
                            <label for="address">Personal Address <span>*</span></label>
                            <textarea id="address" name="address" rows="3" placeholder="Enter personal address" required></textarea>
                        </div>

                    </div>
                </div>

                <div class="awo-card awo-login-card" id="loginAccessCard">
                    <div class="awo-section-title">
                        <div class="awo-section-icon">
                            <i class="bi bi-person-lock"></i>
                        </div>

                        <div>
                            <h2>Login Information</h2>
                            <p>Password is required only when login access is yes.</p>
                        </div>
                    </div>

                    <div class="awo-grid">

                        <div class="awo-field">
                            <label for="password">Password <span>*</span></label>
                            <div class="awo-password-wrap">
                                <input type="password" id="password" name="password" placeholder="Create password">
                                <button type="button" class="awo-password-toggle" data-target="password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="awo-field">
                            <label for="confirm_password">Confirm Password <span>*</span></label>
                            <div class="awo-password-wrap">
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm password">
                                <button type="button" class="awo-password-toggle" data-target="confirm_password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="awo-actions">
                    <a href="user-management.php" class="awo-cancel-btn">Cancel</a>

                    <button type="submit" class="awo-submit-btn">
                        <i class="bi bi-person-plus"></i>
                        Save Ward Officer
                    </button>
                </div>

            </form>

        </section>

        <?php include "../../includes/central/footer.php"; ?>

    </main>

</div>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/add-ward-officer.js"></script>

</body>
</html>