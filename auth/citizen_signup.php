<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config.php";

$pageTitle = "Create Citizen Account | DrainGuard";
$error = "";

function safeText($value)
{
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

    $nationalIdBirthCertificate = trim($_POST["national_id_birth_certificate"] ?? "");

    $password = trim($_POST["password"] ?? "");
    $confirmPassword = trim($_POST["confirm_password"] ?? "");

    $profilePhotoPath = null;

    if (
        $fullName === "" ||
        $phoneNumber === "" ||
        $email === "" ||
        $gender === "" ||
        $division === "" ||
        $district === "" ||
        $upazilaThana === "" ||
        $unionArea === "" ||
        $streetVillage === "" ||
        $nationalIdBirthCertificate === "" ||
        $password === "" ||
        $confirmPassword === ""
    ) {
        $error = "Please fill up all required fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";

    } elseif (!preg_match("/^[a-zA-Z0-9._%+-]+@gmail\.com$/", $email)) {
        $error = "Only Gmail addresses are allowed.";

    } elseif (!preg_match("/^01[0-9]{9}$/", $phoneNumber)) {
        $error = "Phone number must start with 01 and contain exactly 11 digits.";

    } elseif (!in_array($gender, ["male", "female", "other"], true)) {
        $error = "Invalid gender selected.";

    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";

    } elseif ($password !== $confirmPassword) {
        $error = "Password and confirm password do not match.";
    }

    if ($error === "") {
        $checkEmailSql = "SELECT user_id FROM users WHERE user_mail = ? LIMIT 1";
        $checkEmailStmt = mysqli_prepare($conn, $checkEmailSql);

        if (!$checkEmailStmt) {
            $error = "Database error while checking email.";
        } else {
            mysqli_stmt_bind_param($checkEmailStmt, "s", $email);
            mysqli_stmt_execute($checkEmailStmt);

            $checkEmailResult = mysqli_stmt_get_result($checkEmailStmt);

            if (mysqli_num_rows($checkEmailResult) > 0) {
                $error = "This Gmail already exists.";
            }

            mysqli_stmt_close($checkEmailStmt);
        }
    }

    if ($error === "") {
        $checkNidSql = "SELECT citizen_id FROM citizens WHERE national_id_birth_certificate = ? LIMIT 1";
        $checkNidStmt = mysqli_prepare($conn, $checkNidSql);

        if (!$checkNidStmt) {
            $error = "Database error while checking NID / Birth Certificate.";
        } else {
            mysqli_stmt_bind_param($checkNidStmt, "s", $nationalIdBirthCertificate);
            mysqli_stmt_execute($checkNidStmt);

            $checkNidResult = mysqli_stmt_get_result($checkNidStmt);

            if (mysqli_num_rows($checkNidResult) > 0) {
                $error = "National ID / Birth Certificate already exists.";
            }

            mysqli_stmt_close($checkNidStmt);
        }
    }

    if ($error === "" && isset($_FILES["profile_photo"]) && $_FILES["profile_photo"]["error"] !== UPLOAD_ERR_NO_FILE) {

        if ($_FILES["profile_photo"]["error"] !== UPLOAD_ERR_OK) {
            $error = "Profile photo upload failed.";
        } else {
            $allowedExtensions = ["jpg", "jpeg", "png", "webp"];
            $fileName = $_FILES["profile_photo"]["name"];
            $fileTmp = $_FILES["profile_photo"]["tmp_name"];
            $fileSize = $_FILES["profile_photo"]["size"];
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (!in_array($extension, $allowedExtensions, true)) {
                $error = "Only JPG, JPEG, PNG, and WEBP files are allowed.";
            } elseif ($fileSize > 2 * 1024 * 1024) {
                $error = "Profile photo must be less than 2MB.";
            } else {
                $uploadFolder = __DIR__ . "/../assets/uploads/citizens/";

                if (!is_dir($uploadFolder)) {
                    mkdir($uploadFolder, 0777, true);
                }

                $uniqueName = "citizen_" . time() . "_" . rand(1000, 9999) . "." . $extension;
                $destination = $uploadFolder . $uniqueName;

                if (move_uploaded_file($fileTmp, $destination)) {
                    $profilePhotoPath = "assets/uploads/citizens/" . $uniqueName;
                } else {
                    $error = "Failed to upload profile photo.";
                }
            }
        }
    }

    if ($error === "") {
        mysqli_begin_transaction($conn);

        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

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
                VALUES (?, ?, ?, 'citizen', 'active', 1, NULL, NULL)
            ";

            $userStmt = mysqli_prepare($conn, $userSql);

            if (!$userStmt) {
                throw new Exception("User insert preparation failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($userStmt, "sss", $fullName, $email, $hashedPassword);

            if (!mysqli_stmt_execute($userStmt)) {
                throw new Exception("User insert failed: " . mysqli_stmt_error($userStmt));
            }

            $userId = mysqli_insert_id($conn);
            mysqli_stmt_close($userStmt);

            $citizenSql = "
                INSERT INTO citizens (
                    user_id,
                    full_name,
                    phone_number,
                    user_mail,
                    gender,
                    division,
                    district,
                    upazila_thana,
                    union_area,
                    street_village,
                    national_id_birth_certificate,
                    profile_photo
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $citizenStmt = mysqli_prepare($conn, $citizenSql);

            if (!$citizenStmt) {
                throw new Exception("Citizen insert preparation failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param(
                $citizenStmt,
                "isssssssssss",
                $userId,
                $fullName,
                $phoneNumber,
                $email,
                $gender,
                $division,
                $district,
                $upazilaThana,
                $unionArea,
                $streetVillage,
                $nationalIdBirthCertificate,
                $profilePhotoPath
            );

            if (!mysqli_stmt_execute($citizenStmt)) {
                throw new Exception("Citizen insert failed: " . mysqli_stmt_error($citizenStmt));
            }

            mysqli_stmt_close($citizenStmt);

            mysqli_commit($conn);

            $_SESSION["success_message"] = "Citizen account created successfully. Please login now.";
            header("Location: /DrainGuard/auth/login.php");
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
    <title><?php echo safeText($pageTitle); ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="citizen_signup.css">
</head>
<body>

<div class="signup-wrapper">
    <div class="signup-card">

        <div class="signup-header">
            <h1>Create Citizen Account</h1>
            <p>Join DrainGuard Smart Urban Drainage Platform</p>
        </div>

        <?php if ($error !== ""): ?>
            <div class="alert-box error-box">
                <?php echo safeText($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="signupForm">

            <div class="section-title">Citizen Information</div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name <span>*</span></label>
                    <input type="text" name="full_name" class="form-control" placeholder="Enter full name" required>
                </div>

                <div class="form-group">
                    <label>Phone Number <span>*</span></label>
                    <input type="text" name="phone_number" class="form-control" placeholder="01XXXXXXXXX" required>
                </div>

                <div class="form-group">
                    <label>Gmail Address <span>*</span></label>
                    <input type="email" name="email" class="form-control" placeholder="example@gmail.com" required>
                </div>

                <div class="form-group">
                    <label>Gender <span>*</span></label>
                    <select name="gender" class="form-control" required>
                        <option value="">Select Gender</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>

            <div class="section-title">Address Information</div>

            <div class="form-grid form-grid-address">
                <div class="form-group">
                    <label>Division <span>*</span></label>
                    <input type="text" name="division" class="form-control" placeholder="Division" required>
                </div>

                <div class="form-group">
                    <label>District <span>*</span></label>
                    <input type="text" name="district" class="form-control" placeholder="District" required>
                </div>

                <div class="form-group">
                    <label>Upazila / Thana <span>*</span></label>
                    <input type="text" name="upazila_thana" class="form-control" placeholder="Upazila / Thana" required>
                </div>

                <div class="form-group">
                    <label>Union / Area <span>*</span></label>
                    <input type="text" name="union_area" class="form-control" placeholder="Union / Area" required>
                </div>

                <div class="form-group full">
                    <label>Street / Village <span>*</span></label>
                    <input type="text" name="street_village" class="form-control" placeholder="Street / Village" required>
                </div>
            </div>

            <div class="form-grid form-grid-bottom">
                <div class="form-group">
                    <label>National ID / Birth Certificate <span>*</span></label>
                    <input type="text" name="national_id_birth_certificate" class="form-control" placeholder="Enter NID or birth certificate number" required>
                </div>

                <div class="form-group">
                    <label>Profile Photo</label>

                    <div class="custom-file-upload">
                        <input type="file" name="profile_photo" id="profile_photo" accept=".jpg,.jpeg,.png,.webp">
                        <label for="profile_photo" class="custom-file-label">
                            <span class="file-icon"><i class="bi bi-cloud-arrow-up"></i></span>
                            <span class="file-text">
                                <strong>Choose Photo</strong>
                                <small id="fileNameText">No file chosen</small>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="section-title">Login Information</div>

            <div class="form-grid">
                <div class="form-group password-group">
                    <label>Password <span>*</span></label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="password" class="form-control" placeholder="Create password" required>
                        <button type="button" class="toggle-password" data-target="password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group password-group">
                    <label>Confirm Password <span>*</span></label>
                    <div class="password-wrapper">
                        <input type="password" name="confirm_password" id="confirmPassword" class="form-control" placeholder="Confirm password" required>
                        <button type="button" class="toggle-password" data-target="confirmPassword">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="button-group">
                <a href="/DrainGuard/auth/login.php" class="cancel-btn">Cancel</a>
                <button type="submit" class="signup-btn">Complete Registration</button>
            </div>

        </form>

    </div>
</div>

<script src="citizen_signup.js"></script>
</body>
</html>