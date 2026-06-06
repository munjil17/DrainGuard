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
    "address" => "",
    "gender" => "",
    "designation" => "",
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
    $gender = trim($_POST["gender"] ?? "");
    $designation = trim($_POST["designation"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $officeAddress = trim($_POST["office_address"] ?? "");
    $password = trim($_POST["password"] ?? "");
    $confirmPassword = trim($_POST["confirm_password"] ?? "");

    $old = [
        "full_name" => $fullName,
        "email" => $email,
        "phone" => $phone,
        "address" => $address,
        "gender" => $gender,
        "designation" => $designation,
        "office_address" => $officeAddress
    ];

    $validDesignations = [
        'Central Control Officer', 
        'Complaint Routing Officer', 
        'Monitoring Officer', 
        'Data & Report Officer', 
        'System Administrator', 
        'Emergency Response Coordinator'
    ];

    /* Validation (Gmail restriction removed) */
    if (
        $fullName === "" || $email === "" || $phone === "" ||
        $gender === "" || $designation === "" || $address === "" || $officeAddress === "" ||
        $password === "" || $confirmPassword === ""
    ) {
        $error = "Please complete all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (!preg_match("/^01[0-9]{9}$/", $phone)) {
        $error = "Phone number must start with 01 and contain exactly 11 digits.";
    } elseif (!in_array($gender, ["male", "female", "other"], true)) {
        $error = "Invalid gender selected.";
    } elseif (!in_array($designation, $validDesignations, true)) {
        $error = "Invalid designation selected.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($password !== $confirmPassword) {
        $error = "Password and Confirm Password do not match.";
    }

    /* Duplicate Email Check */
    if ($error === "") {
        $checkEmailSql = "SELECT user_id FROM users WHERE user_mail = ? LIMIT 1";
        $checkEmailStmt = mysqli_prepare($conn, $checkEmailSql);
        if ($checkEmailStmt) {
            mysqli_stmt_bind_param($checkEmailStmt, "s", $email);
            mysqli_stmt_execute($checkEmailStmt);
            $emailResult = mysqli_stmt_get_result($checkEmailStmt);
            if (mysqli_num_rows($emailResult) > 0) {
                $error = "This email already exists in our system.";
            }
            mysqli_stmt_close($checkEmailStmt);
        }
    }

    /* Insert Process */
    if ($error === "") {
        mysqli_begin_transaction($conn);
        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // 1. Insert into Users table
            $userSql = "INSERT INTO users (user_name, user_mail, user_password, user_role, user_status, login_access) VALUES (?, ?, ?, 'central_officer', 'active', 1)";
            $userStmt = mysqli_prepare($conn, $userSql);
            mysqli_stmt_bind_param($userStmt, "sss", $fullName, $email, $hashedPassword);
            mysqli_stmt_execute($userStmt);
            
            $userId = mysqli_insert_id($conn);
            mysqli_stmt_close($userStmt);

            // 2. Generate Automatic Employee Code
            preg_match_all('/[A-Z]/', $designation, $matches);
            $prefix = implode('', $matches[0]);
            
            $suffix = str_pad($userId, 3, '0', STR_PAD_LEFT);
            $suffix = substr($suffix, -3); 
            
            $employeeCode = $prefix . '-' . $suffix;

            // 3. Insert into central_officers table
            $officerSql = "INSERT INTO central_officers (user_id, user_mail, full_name, phone, employee_code, address, gender, designation, office_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $officerStmt = mysqli_prepare($conn, $officerSql);
            mysqli_stmt_bind_param($officerStmt, "issssssss", $userId, $email, $fullName, $phone, $employeeCode, $address, $gender, $designation, $officeAddress);
            mysqli_stmt_execute($officerStmt);
            mysqli_stmt_close($officerStmt);

            mysqli_commit($conn);
            $success = "Officer registered successfully! Employee Code: " . $employeeCode;

            // Reset form fields
            $old = array_fill_keys(array_keys($old), "");

        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Unable to complete registration. Please try again. " . $e->getMessage();
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
                    <i class="bi bi-shield-check"></i>
                    <h2>Officer Profile Information</h2>
                </div>

                <div class="cor-grid">
                    <div class="cor-field">
                        <label for="full_name">Full Name <span class="text-danger">*</span></label>
                        <input type="text" id="full_name" name="full_name" value="<?php echo safeText($old["full_name"]); ?>" placeholder="Enter full name" required>
                    </div>

                    <div class="cor-field">
                        <label for="email">Email Address <span class="text-danger">*</span></label>
                        <input type="email" id="email" name="email" value="<?php echo safeText($old["email"]); ?>" placeholder="example@email.com" required>
                    </div>

                    <div class="cor-field">
                        <label for="phone">Phone <span class="text-danger">*</span></label>
                        <input type="text" id="phone" name="phone" value="<?php echo safeText($old["phone"]); ?>" placeholder="01XXXXXXXXX" required>
                    </div>

                    <div class="cor-field">
                        <label for="gender">Gender <span class="text-danger">*</span></label>
                        <select id="gender" name="gender" required>
                            <option value="">Select gender</option>
                            <option value="male" <?php echo ($old["gender"] === "male") ? "selected" : ""; ?>>Male</option>
                            <option value="female" <?php echo ($old["gender"] === "female") ? "selected" : ""; ?>>Female</option>
                            <option value="other" <?php echo ($old["gender"] === "other") ? "selected" : ""; ?>>Other</option>
                        </select>
                    </div>

                    <div class="cor-field">
                        <label for="designation">Designation <span class="text-danger">*</span></label>
                        <select id="designation" name="designation" required>
                            <option value="">Select Designation</option>
                            <option value="Central Control Officer" <?php echo ($old["designation"] === "Central Control Officer") ? "selected" : ""; ?>>Central Control Officer (CCO)</option>
                            <option value="Complaint Routing Officer" <?php echo ($old["designation"] === "Complaint Routing Officer") ? "selected" : ""; ?>>Complaint Routing Officer (CRO)</option>
                            <option value="Monitoring Officer" <?php echo ($old["designation"] === "Monitoring Officer") ? "selected" : ""; ?>>Monitoring Officer (MO)</option>
                            <option value="Data & Report Officer" <?php echo ($old["designation"] === "Data & Report Officer") ? "selected" : ""; ?>>Data & Report Officer (DRO)</option>
                            <option value="System Administrator" <?php echo ($old["designation"] === "System Administrator") ? "selected" : ""; ?>>System Administrator (SA)</option>
                            <option value="Emergency Response Coordinator" <?php echo ($old["designation"] === "Emergency Response Coordinator") ? "selected" : ""; ?>>Emergency Response Coordinator (ERC)</option>
                        </select>
                    </div>

                    <div class="cor-field full">
                        <label for="address">Home Address <span class="text-danger">*</span></label>
                        <textarea id="address" name="address" rows="1" placeholder="Detailed home address" required><?php echo safeText($old["address"]); ?></textarea>
                    </div>

                    <div class="cor-field full">
                        <label for="office_address">Office Address <span class="text-danger">*</span></label>
                        <textarea id="office_address" name="office_address" rows="1" placeholder="Detailed office location" required><?php echo safeText($old["office_address"]); ?></textarea>
                    </div>

                    <div class="cor-field">
                        <label for="password">Password <span class="text-danger">*</span></label>
                        <div class="cor-password-wrap">
                            <input type="password" id="password" name="password" placeholder="Min. 8 characters" minlength="8" required>
                            <button type="button" class="cor-password-toggle" data-target="password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="cor-field">
                        <label for="confirm_password">Confirm Password <span class="text-danger">*</span></label>
                        <div class="cor-password-wrap">
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Retype password" minlength="8" required>
                            <button type="button" class="cor-password-toggle" data-target="confirm_password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="cor-actions">
                <a href="/DrainGuard/auth/login.php" class="cor-cancel-btn">
                    <i class="bi bi-x-circle"></i> Cancel
                </a>
                <button type="submit" class="cor-submit-btn">
                    <i class="bi bi-person-plus"></i> Register Officer
                </button>
            </div>
        </form>
    </section>
</main>

<script src="addCentralOfficer.js"></script>

</body>
</html>