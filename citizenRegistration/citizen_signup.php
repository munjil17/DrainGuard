<?php
// Path: citizenRegistration/citizen_signup.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config.php";

$pageTitle = "Create Citizen Account | DrainGuard";
$error = "";

function safeText($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $fullName = trim($_POST["full_name"] ?? "");
    $phoneNumber = trim($_POST["phone_number"] ?? "");
    $email = strtolower(trim($_POST["email"] ?? ""));
    $gender = trim($_POST["gender"] ?? "");

    $division = trim($_POST["division"] ?? "");
    $district = trim($_POST["district"] ?? "");
    $upazilaThana = trim($_POST["upazila_thana"] ?? "");
    $unionArea = trim($_POST["union_area"] ?? "");
    $streetVillage = trim($_POST["street_village"] ?? "");

    $password = trim($_POST["password"] ?? "");
    $confirmPassword = trim($_POST["confirm_password"] ?? "");

    if (empty($fullName) || empty($phoneNumber) || empty($email) || empty($password) || empty($division) || empty($district)) {
        $error = "Please fill in all the required fields.";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } else {
        mysqli_begin_transaction($conn);

        try {
           
            $checkEmail = "SELECT user_id FROM users WHERE user_mail = ?";
            $stmtCheck = mysqli_prepare($conn, $checkEmail);
            mysqli_stmt_bind_param($stmtCheck, "s", $email);
            mysqli_stmt_execute($stmtCheck);
            mysqli_stmt_store_result($stmtCheck);

            if (mysqli_stmt_num_rows($stmtCheck) > 0) {
                throw new Exception("Email address is already registered.");
            }
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $role = 'citizen';
            
            $insertUser = "INSERT INTO users (user_name, user_mail, user_password, user_role, user_status) VALUES (?, ?, ?, ?, 'active')";
            $stmtUser = mysqli_prepare($conn, $insertUser);
            mysqli_stmt_bind_param($stmtUser, "ssss", $fullName, $email, $hashedPassword, $role);
            mysqli_stmt_execute($stmtUser);
            
            $userId = mysqli_insert_id($conn);

            $insertCitizen = "INSERT INTO citizens (user_id, full_name, phone_number, gender, division, district, upazila_thana, union_area, street_village) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtCitizen = mysqli_prepare($conn, $insertCitizen);
            mysqli_stmt_bind_param($stmtCitizen, "issssssss", $userId, $fullName, $phoneNumber, $gender, $division, $district, $upazilaThana, $unionArea, $streetVillage);
            mysqli_stmt_execute($stmtCitizen);

            mysqli_commit($conn);
            header("Location: ../auth/login.php?signup=success");
            exit();

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
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="citizen_signup.css">
</head>
<body>

<div class="signup-page">
    <div class="signup-card">
        
        <div class="header-section text-center">
            <h1>Create Citizen Account</h1>
           
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="background: rgba(255, 48, 69, 0.15); color: #FF4D6D; border: 1px solid rgba(255, 48, 69, 0.3); border-radius: 8px; padding: 12px; text-align: center; font-weight: 600; margin-bottom: 20px;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">

            <!-- Section: Citizen Information -->
            <div class="section-title">Citizen Information</div>
            <div class="row g-3 mb-compact">
                <div class="col-md-6">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" placeholder="Enter full name" value="<?php echo safeText($_POST['full_name'] ?? ''); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                    <input type="text" name="phone_number" class="form-control" placeholder="01XXXXXXXXX" value="<?php echo safeText($_POST['phone_number'] ?? ''); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Gmail Address <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" placeholder="example@gmail.com" value="<?php echo safeText($_POST['email'] ?? ''); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                    <select name="gender" class="form-select" required>
                        <option value="" disabled <?php echo empty($_POST['gender']) ? 'selected' : ''; ?>>Select Gender</option>
                        <option value="Male" <?php echo ($_POST['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?php echo ($_POST['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                        <option value="Other" <?php echo ($_POST['gender'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
            </div>

            <!-- Section: Address Information -->
            <div class="section-title">Address Information</div>
            <div class="row g-3 mb-compact">
                <div class="col-md-6">
                    <label class="form-label">Division <span class="text-danger">*</span></label>
                    <input type="text" name="division" class="form-control" placeholder="Division" value="<?php echo safeText($_POST['division'] ?? ''); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">District <span class="text-danger">*</span></label>
                    <input type="text" name="district" class="form-control" placeholder="District" value="<?php echo safeText($_POST['district'] ?? ''); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Upazila / Thana <span class="text-danger">*</span></label>
                    <input type="text" name="upazila_thana" class="form-control" placeholder="Upazila / Thana" value="<?php echo safeText($_POST['upazila_thana'] ?? ''); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Union / Area <span class="text-danger">*</span></label>
                    <input type="text" name="union_area" class="form-control" placeholder="Union / Area" value="<?php echo safeText($_POST['union_area'] ?? ''); ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Street Address / Village</label>
                    <input type="text" name="street_village" class="form-control" placeholder="Detailed address..." value="<?php echo safeText($_POST['street_village'] ?? ''); ?>">
                </div>
            </div>

            <!-- Section: Account Security -->
            <div class="section-title">Login Credentials</div>
            <div class="row g-3 mb-compact">
                <div class="col-md-6">
                    <label class="form-label">Password <span class="text-danger">*</span></label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="signupPassword" class="form-control" placeholder="Min. 8 characters" minlength="8" required>
                        <button type="button" class="toggle-password" data-target="signupPassword"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                    <div class="password-wrapper">
                        <input type="password" name="confirm_password" id="signupConfirmPassword" class="form-control" placeholder="Retype password" minlength="8" required>
                        <button type="button" class="toggle-password" data-target="signupConfirmPassword"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
            </div>

            <div class="button-group mt-3">
                <a href="../auth/login.php" class="cancel-btn">Cancel</a>
                <button type="submit" class="signup-btn">Complete Registration</button>
            </div>

        </form>
    </div>
</div>

<script src="citizen_signup.js"></script>
</body>
</html>