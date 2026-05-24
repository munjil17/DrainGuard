<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config.php";

$error = "";
$success = "";

$old = [
    "full_name" => "",
    "email" => "",
    "phone" => "",
    "employee_id" => "",
    "address" => "",
    "gender" => "",
    "designation" => "",
    "department" => "",
    "office_address" => ""
];

function safeText($value)
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullName = trim($_POST["full_name"] ?? "");
    $email = strtolower(trim($_POST["email"] ?? ""));
    $phone = trim($_POST["phone"] ?? "");
    $employeeId = trim($_POST["employee_id"] ?? "");
    $gender = trim($_POST["gender"] ?? "");
    $designation = trim($_POST["designation"] ?? "");
    $department = trim($_POST["department"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $officeAddress = trim($_POST["office_address"] ?? "");
    $password = trim($_POST["password"] ?? "");
    $confirmPassword = trim($_POST["confirm_password"] ?? "");

    $old = [
        "full_name" => $fullName,
        "email" => $email,
        "phone" => $phone,
        "employee_id" => $employeeId,
        "address" => $address,
        "gender" => $gender,
        "designation" => $designation,
        "department" => $department,
        "office_address" => $officeAddress
    ];

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if (
        $fullName === "" ||
        $email === "" ||
        $phone === "" ||
        $employeeId === "" ||
        $gender === "" ||
        $designation === "" ||
        $department === "" ||
        $address === "" ||
        $officeAddress === "" ||
        $password === "" ||
        $confirmPassword === ""
    ) {
        $error = "Please fill up all required fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";

    } elseif (!preg_match("/^[a-zA-Z0-9._%+-]+@gmail\.com$/", $email)) {
        $error = "Only Gmail addresses are allowed.";

    } elseif (!preg_match("/^01[0-9]{9}$/", $phone)) {
        $error = "Phone number must start with 01 and contain exactly 11 digits.";

    } elseif (!in_array($gender, ["male", "female", "other"], true)) {
        $error = "Invalid gender selected.";

    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";

    } elseif ($password !== $confirmPassword) {
        $error = "Password and Confirm Password do not match.";
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate Email Check
    |--------------------------------------------------------------------------
    */

    if ($error === "") {
        $checkEmailSql = "SELECT user_id FROM users WHERE user_mail = ? LIMIT 1";
        $checkEmailStmt = mysqli_prepare($conn, $checkEmailSql);

        if (!$checkEmailStmt) {
            $error = "Database error while checking email.";
        } else {
            mysqli_stmt_bind_param($checkEmailStmt, "s", $email);
            mysqli_stmt_execute($checkEmailStmt);

            $emailResult = mysqli_stmt_get_result($checkEmailStmt);

            if (mysqli_num_rows($emailResult) > 0) {
                $error = "This email already exists.";
            }

            mysqli_stmt_close($checkEmailStmt);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate Employee ID Check
    |--------------------------------------------------------------------------
    */

    if ($error === "") {
        $checkEmployeeSql = "
            SELECT central_officer_id 
            FROM central_officers 
            WHERE employee_id = ? 
            LIMIT 1
        ";

        $checkEmployeeStmt = mysqli_prepare($conn, $checkEmployeeSql);

        if (!$checkEmployeeStmt) {
            $error = "Database error while checking employee ID.";
        } else {
            mysqli_stmt_bind_param($checkEmployeeStmt, "s", $employeeId);
            mysqli_stmt_execute($checkEmployeeStmt);

            $employeeResult = mysqli_stmt_get_result($checkEmployeeStmt);

            if (mysqli_num_rows($employeeResult) > 0) {
                $error = "This Employee ID already exists.";
            }

            mysqli_stmt_close($checkEmployeeStmt);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Insert Process
    |--------------------------------------------------------------------------
    */

    if ($error === "") {
        mysqli_begin_transaction($conn);

        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            /*
            |--------------------------------------------------------------------------
            | Step 1: Insert Into users Table
            |--------------------------------------------------------------------------
            */

            $userSql = "
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
                VALUES (?, ?, ?, 'central_officer', 'active', 1, NULL, NULL)
            ";

            $userStmt = mysqli_prepare($conn, $userSql);

            if (!$userStmt) {
                throw new Exception("User insert preparation failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param(
                $userStmt,
                "sss",
                $fullName,
                $email,
                $hashedPassword
            );

            if (!mysqli_stmt_execute($userStmt)) {
                throw new Exception("User insert failed: " . mysqli_stmt_error($userStmt));
            }

            $userId = mysqli_insert_id($conn);
            mysqli_stmt_close($userStmt);

            /*
            |--------------------------------------------------------------------------
            | Step 2: Insert Into central_officers Table
            |--------------------------------------------------------------------------
            */

            $officerSql = "
                INSERT INTO central_officers (
                    user_id,
                    user_mail,
                    full_name,
                    phone,
                    employee_id,
                    address,
                    gender,
                    designation,
                    department,
                    office_address
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $officerStmt = mysqli_prepare($conn, $officerSql);

            if (!$officerStmt) {
                throw new Exception("Central officer insert preparation failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param(
                $officerStmt,
                "isssssssss",
                $userId,
                $email,
                $fullName,
                $phone,
                $employeeId,
                $address,
                $gender,
                $designation,
                $department,
                $officeAddress
            );

            if (!mysqli_stmt_execute($officerStmt)) {
                throw new Exception("Central officer insert failed: " . mysqli_stmt_error($officerStmt));
            }

            mysqli_stmt_close($officerStmt);

            mysqli_commit($conn);

            $success = "Central officer registered successfully.";

            $old = [
                "full_name" => "",
                "email" => "",
                "phone" => "",
                "employee_id" => "",
                "address" => "",
                "gender" => "",
                "designation" => "",
                "department" => "",
                "office_address" => ""
            ];

        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Central Officer Registration | DrainGuard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="addCentralOfficer.css">
</head>

<body>

<main class="cor-page">

    <section class="cor-card">

        <div class="cor-header">
            <div class="cor-header-icon">
                <i class="bi bi-person-badge"></i>
            </div>

            <div>
                <h1>Central Officer Registration</h1>
                <p>Create login access and central command profile</p>
            </div>
        </div>

        <?php if ($error !== ""): ?>
            <div class="cor-alert error">
                <i class="bi bi-exclamation-circle"></i>
                <span><?php echo safeText($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success !== ""): ?>
            <div class="cor-alert success">
                <i class="bi bi-check-circle"></i>
                <span><?php echo safeText($success); ?></span>
            </div>
        <?php endif; ?>

        <form action="addCentralOfficer.php" method="POST" id="centralOfficerForm">

            <div class="cor-section">

                <div class="cor-section-title">
                    <i class="bi bi-people-fill"></i>
                    <h2>Officer Profile Information</h2>
                </div>

                <div class="cor-grid">

                    <div class="cor-field">
                        <label for="full_name">Full Name</label>
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            value="<?php echo safeText($old["full_name"]); ?>"
                            required
                        >
                    </div>

                    <div class="cor-field">
                        <label for="email">Email</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?php echo safeText($old["email"]); ?>"
                            required
                        >
                    </div>

                    <div class="cor-field">
                        <label for="phone">Phone</label>
                        <input
                            type="text"
                            id="phone"
                            name="phone"
                            value="<?php echo safeText($old["phone"]); ?>"
                            placeholder="01XXXXXXXXX"
                            required
                        >
                    </div>

                    <div class="cor-field">
                        <label for="employee_id">Employee ID</label>
                        <input
                            type="text"
                            id="employee_id"
                            name="employee_id"
                            value="<?php echo safeText($old["employee_id"]); ?>"
                            required
                        >
                    </div>

                    <div class="cor-field">
                        <label for="gender">Gender</label>
                        <select id="gender" name="gender" required>
                            <option value="">Select gender</option>
                            <option value="male" <?php echo ($old["gender"] === "male") ? "selected" : ""; ?>>Male</option>
                            <option value="female" <?php echo ($old["gender"] === "female") ? "selected" : ""; ?>>Female</option>
                            <option value="other" <?php echo ($old["gender"] === "other") ? "selected" : ""; ?>>Other</option>
                        </select>
                    </div>

                    <div class="cor-field">
                        <label for="designation">Designation</label>
                        <input
                            type="text"
                            id="designation"
                            name="designation"
                            value="<?php echo safeText($old["designation"]); ?>"
                            required
                        >
                    </div>

                    <div class="cor-field">
                        <label for="department">Department</label>
                        <input
                            type="text"
                            id="department"
                            name="department"
                            value="<?php echo safeText($old["department"]); ?>"
                            required
                        >
                    </div>

                    <div class="cor-field">
                        <label for="address">Address</label>
                        <textarea
                            id="address"
                            name="address"
                            required
                        ><?php echo safeText($old["address"]); ?></textarea>
                    </div>

                    <div class="cor-field full">
                        <label for="office_address">Office Address</label>
                        <textarea
                            id="office_address"
                            name="office_address"
                            required
                        ><?php echo safeText($old["office_address"]); ?></textarea>
                    </div>

                    <div class="cor-field">
                        <label for="password">Password</label>

                        <div class="cor-password-wrap">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                required
                            >

                            <button type="button" class="cor-password-toggle" data-target="password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="cor-field">
                        <label for="confirm_password">Confirm Password</label>

                        <div class="cor-password-wrap">
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                required
                            >

                            <button type="button" class="cor-password-toggle" data-target="confirm_password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                </div>

            </div>

            <div class="cor-actions">

                <a href="/DrainGuard/auth/login.php" class="cor-cancel-btn">
                    <i class="bi bi-x-circle"></i>
                    Cancel
                </a>

                <button type="submit" class="cor-submit-btn">
                    <i class="bi bi-person-plus"></i>
                    Register Officer
                </button>

            </div>

        </form>

    </section>

</main>

<script src="addCentralOfficer.js"></script>

</body>
</html>