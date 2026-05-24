<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "profile";
$pageTitle = "Profile";
$pageParent = "Central Control";
$pageChild = "Profile";

$successMessage = "";
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function cleanUploadPath($path)
{
    $path = str_replace("\\", "/", (string)$path);
    $path = ltrim($path, "/");
    return $path;
}

$userId = (int)($_SESSION["user_id"] ?? 0);

if ($userId <= 0) {
    header("Location: ../../index.php");
    exit();
}

/*
|--------------------------------------------------------------------------
| UPDATE CENTRAL OFFICER PROFILE
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["form_type"] ?? "") === "profile_update") {
    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["user_mail"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $employeeId = trim($_POST["employee_id"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $gender = trim($_POST["gender"] ?? "");
    $designation = trim($_POST["designation"] ?? "");
    $department = trim($_POST["department"] ?? "");
    $officeAddress = trim($_POST["office_address"] ?? "");

    if ($fullName === "" || $email === "" || $phone === "" || $employeeId === "" || $address === "" || $gender === "" || $designation === "" || $department === "" || $officeAddress === "") {
        $errorMessage = "All profile fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = "Please enter a valid email address.";
    } elseif (!in_array($gender, ["male", "female", "other"], true)) {
        $errorMessage = "Invalid gender selected.";
    } else {
        mysqli_begin_transaction($conn);

        try {
            $emailCheckSql = "
                SELECT user_id
                FROM users
                WHERE user_mail = ?
                AND user_id <> ?
                LIMIT 1
            ";

            $emailCheckStmt = mysqli_prepare($conn, $emailCheckSql);

            if (!$emailCheckStmt) {
                throw new Exception("Email check failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($emailCheckStmt, "si", $email, $userId);
            mysqli_stmt_execute($emailCheckStmt);

            $emailCheckResult = mysqli_stmt_get_result($emailCheckStmt);

            if ($emailCheckResult && mysqli_num_rows($emailCheckResult) > 0) {
                mysqli_stmt_close($emailCheckStmt);
                throw new Exception("This email is already used by another user.");
            }

            mysqli_stmt_close($emailCheckStmt);

            $employeeCheckSql = "
                SELECT central_officer_id
                FROM central_officers
                WHERE employee_id = ?
                AND user_id <> ?
                LIMIT 1
            ";

            $employeeCheckStmt = mysqli_prepare($conn, $employeeCheckSql);

            if (!$employeeCheckStmt) {
                throw new Exception("Employee ID check failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($employeeCheckStmt, "si", $employeeId, $userId);
            mysqli_stmt_execute($employeeCheckStmt);

            $employeeCheckResult = mysqli_stmt_get_result($employeeCheckStmt);

            if ($employeeCheckResult && mysqli_num_rows($employeeCheckResult) > 0) {
                mysqli_stmt_close($employeeCheckStmt);
                throw new Exception("This employee ID is already used by another central officer.");
            }

            mysqli_stmt_close($employeeCheckStmt);

            $profilePicturePath = null;

            if (
                isset($_FILES["profile_picture"]) &&
                $_FILES["profile_picture"]["error"] !== UPLOAD_ERR_NO_FILE
            ) {
                if ($_FILES["profile_picture"]["error"] !== UPLOAD_ERR_OK) {
                    throw new Exception("Profile picture upload failed.");
                }

                $maxSize = 2 * 1024 * 1024;

                if ($_FILES["profile_picture"]["size"] > $maxSize) {
                    throw new Exception("Profile picture must be less than 2MB.");
                }

                $tmpFile = $_FILES["profile_picture"]["tmp_name"];
                $mimeType = mime_content_type($tmpFile);

                $allowedMimeTypes = [
                    "image/jpeg" => "jpg",
                    "image/png" => "png",
                    "image/webp" => "webp"
                ];

                if (!array_key_exists($mimeType, $allowedMimeTypes)) {
                    throw new Exception("Only JPG, PNG, and WEBP images are allowed.");
                }

                $uploadDir = "../../assets/uploads/central_officers/";

                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $extension = $allowedMimeTypes[$mimeType];
                $newFileName = "central_" . $userId . "_" . time() . "." . $extension;
                $destination = $uploadDir . $newFileName;

                if (!move_uploaded_file($tmpFile, $destination)) {
                    throw new Exception("Failed to save profile picture.");
                }

                $profilePicturePath = "assets/uploads/central_officers/" . $newFileName;
            }

            $updateUserSql = "
                UPDATE users
                SET user_name = ?,
                    user_mail = ?
                WHERE user_id = ?
            ";

            $updateUserStmt = mysqli_prepare($conn, $updateUserSql);

            if (!$updateUserStmt) {
                throw new Exception("User update failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($updateUserStmt, "ssi", $fullName, $email, $userId);

            if (!mysqli_stmt_execute($updateUserStmt)) {
                throw new Exception("User update failed: " . mysqli_stmt_error($updateUserStmt));
            }

            mysqli_stmt_close($updateUserStmt);

            if ($profilePicturePath !== null) {
                $updateCentralSql = "
                    UPDATE central_officers
                    SET
                        user_mail = ?,
                        full_name = ?,
                        phone = ?,
                        employee_id = ?,
                        address = ?,
                        gender = ?,
                        designation = ?,
                        department = ?,
                        office_address = ?,
                        profile_picture = ?
                    WHERE user_id = ?
                ";

                $updateCentralStmt = mysqli_prepare($conn, $updateCentralSql);

                if (!$updateCentralStmt) {
                    throw new Exception("Central officer update failed: " . mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $updateCentralStmt,
                    "ssssssssssi",
                    $email,
                    $fullName,
                    $phone,
                    $employeeId,
                    $address,
                    $gender,
                    $designation,
                    $department,
                    $officeAddress,
                    $profilePicturePath,
                    $userId
                );
            } else {
                $updateCentralSql = "
                    UPDATE central_officers
                    SET
                        user_mail = ?,
                        full_name = ?,
                        phone = ?,
                        employee_id = ?,
                        address = ?,
                        gender = ?,
                        designation = ?,
                        department = ?,
                        office_address = ?
                    WHERE user_id = ?
                ";

                $updateCentralStmt = mysqli_prepare($conn, $updateCentralSql);

                if (!$updateCentralStmt) {
                    throw new Exception("Central officer update failed: " . mysqli_error($conn));
                }

                mysqli_stmt_bind_param(
                    $updateCentralStmt,
                    "sssssssssi",
                    $email,
                    $fullName,
                    $phone,
                    $employeeId,
                    $address,
                    $gender,
                    $designation,
                    $department,
                    $officeAddress,
                    $userId
                );
            }

            if (!mysqli_stmt_execute($updateCentralStmt)) {
                throw new Exception("Central officer update failed: " . mysqli_stmt_error($updateCentralStmt));
            }

            mysqli_stmt_close($updateCentralStmt);

            mysqli_commit($conn);

            $_SESSION["user_name"] = $fullName;
            $_SESSION["user_mail"] = $email;

            $successMessage = "Profile updated successfully.";

        } catch (Exception $e) {
            mysqli_rollback($conn);
            $errorMessage = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| FETCH CENTRAL OFFICER PROFILE
|--------------------------------------------------------------------------
*/

$profile = [
    "central_officer_id" => "",
    "user_id" => $userId,
    "user_mail" => "",
    "full_name" => "",
    "phone" => "",
    "employee_id" => "",
    "address" => "",
    "gender" => "",
    "designation" => "",
    "department" => "",
    "office_address" => "",
    "profile_picture" => ""
];

$sql = "
    SELECT
        central_officer_id,
        user_id,
        user_mail,
        full_name,
        phone,
        employee_id,
        address,
        gender,
        designation,
        department,
        office_address,
        profile_picture
    FROM central_officers
    WHERE user_id = ?
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $sql);

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    if ($row) {
        $profile = array_merge($profile, $row);
    }

    mysqli_stmt_close($stmt);
}

$displayName = trim($profile["full_name"]) !== "" ? $profile["full_name"] : "Central Officer";
$displayEmail = trim($profile["user_mail"]) !== "" ? $profile["user_mail"] : "Not provided";
$displayPhone = trim($profile["phone"]) !== "" ? $profile["phone"] : "Not provided";
$displayRole = trim($profile["designation"]) !== "" ? $profile["designation"] : "Central Officer";
$displayEmployeeId = trim($profile["employee_id"]) !== "" ? $profile["employee_id"] : "N/A";
$displayAddress = trim($profile["address"]) !== "" ? $profile["address"] : "Not provided";
$displayGender = trim($profile["gender"]) !== "" ? $profile["gender"] : "Not provided";
$displayDepartment = trim($profile["department"]) !== "" ? $profile["department"] : "Not provided";
$displayOfficeAddress = trim($profile["office_address"]) !== "" ? $profile["office_address"] : "Not provided";
$displayStatus = "Active";

$profilePicture = cleanUploadPath($profile["profile_picture"] ?? "");
$profilePictureSrc = $profilePicture !== "" ? "../../" . $profilePicture : "";

$initial = strtoupper(substr($displayName, 0, 1));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>Profile | DrainGuard</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">
    <link rel="stylesheet" href="../../css/central/footer.css">
    <link rel="stylesheet" href="../../css/central/profile.css">
    <link rel="stylesheet" href="../../css/central/centralTextFix.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="central-profile-page">

            <div class="central-profile-header">
                <h1>Profile</h1>
                <p>View and update your central officer account information</p>
            </div>

            <?php if ($successMessage !== ""): ?>
                <div class="central-profile-alert success">
                    <i class="bi bi-check-circle"></i>
                    <?php echo safeText($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ""): ?>
                <div class="central-profile-alert error">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo safeText($errorMessage); ?>
                </div>
            <?php endif; ?>

            <div class="central-profile-card">

                <div class="central-profile-top">
                    <div class="central-profile-avatar-wrap">
                        <div class="central-profile-avatar" id="profileAvatarBox">
                            <?php if ($profilePictureSrc !== ""): ?>
                                <img
                                    src="<?php echo safeText($profilePictureSrc); ?>"
                                    alt="Profile Picture"
                                    class="central-profile-img"
                                    id="profileImagePreview"
                                >
                            <?php else: ?>
                                <span id="profileInitial"><?php echo safeText($initial); ?></span>
                            <?php endif; ?>
                        </div>

                        <button type="button" class="central-profile-camera-btn" id="profileCameraBtn">
                            <i class="bi bi-camera-fill"></i>
                        </button>
                    </div>

                    <div class="central-profile-identity">
                        <h2><?php echo safeText($displayName); ?></h2>
                        <p><?php echo safeText($displayEmail); ?></p>
                        <span>Central Officer Account</span>
                    </div>
                </div>

                <div class="central-profile-divider"></div>

                <div class="central-profile-view" id="profileViewMode">

                    <div class="central-profile-info-grid">

                        <div class="central-profile-info-box">
                            <span>Full Name</span>
                            <strong><?php echo safeText($displayName); ?></strong>
                        </div>

                        <div class="central-profile-info-box">
                            <span>Email</span>
                            <strong><?php echo safeText($displayEmail); ?></strong>
                        </div>

                        <div class="central-profile-info-box">
                            <span>Phone</span>
                            <strong><?php echo safeText($displayPhone); ?></strong>
                        </div>

                        <div class="central-profile-info-box">
                            <span>Role</span>
                            <strong><?php echo safeText($displayRole); ?></strong>
                        </div>

                        <div class="central-profile-info-box">
                            <span>Employee ID</span>
                            <strong><?php echo safeText($displayEmployeeId); ?></strong>
                        </div>

                        <div class="central-profile-info-box">
                            <span>Gender</span>
                            <strong><?php echo safeText(ucfirst($displayGender)); ?></strong>
                        </div>

                        <div class="central-profile-info-box">
                            <span>Department</span>
                            <strong><?php echo safeText($displayDepartment); ?></strong>
                        </div>

                        <div class="central-profile-info-box">
                            <span>Account Status</span>
                            <strong><?php echo safeText($displayStatus); ?></strong>
                        </div>

                        <div class="central-profile-info-box wide">
                            <span>Address</span>
                            <strong><?php echo safeText($displayAddress); ?></strong>
                        </div>

                        <div class="central-profile-info-box wide">
                            <span>Office Address</span>
                            <strong><?php echo safeText($displayOfficeAddress); ?></strong>
                        </div>

                    </div>

                    <button type="button" class="central-profile-edit-btn" id="editProfileBtn">
                        <i class="bi bi-pencil-square"></i>
                        Edit Profile
                    </button>

                </div>

                <form
                    method="POST"
                    action="profile.php"
                    class="central-profile-edit-form"
                    id="profileEditMode"
                    enctype="multipart/form-data"
                    hidden
                >
                    <input type="hidden" name="form_type" value="profile_update">

                    <input
                        type="file"
                        id="profile_picture"
                        name="profile_picture"
                        accept="image/jpeg,image/jpg,image/png,image/webp"
                        hidden
                    >

                    <div class="central-profile-form-grid">

                        <div class="central-profile-form-group">
                            <label for="full_name">Full Name</label>
                            <input
                                type="text"
                                id="full_name"
                                name="full_name"
                                value="<?php echo safeText($profile["full_name"]); ?>"
                                required
                            >
                        </div>

                        <div class="central-profile-form-group">
                            <label for="user_mail">Email</label>
                            <input
                                type="email"
                                id="user_mail"
                                name="user_mail"
                                value="<?php echo safeText($profile["user_mail"]); ?>"
                                required
                            >
                        </div>

                        <div class="central-profile-form-group">
                            <label for="phone">Phone</label>
                            <input
                                type="text"
                                id="phone"
                                name="phone"
                                value="<?php echo safeText($profile["phone"]); ?>"
                                required
                            >
                        </div>

                        <div class="central-profile-form-group">
                            <label for="employee_id">Employee ID</label>
                            <input
                                type="text"
                                id="employee_id"
                                name="employee_id"
                                value="<?php echo safeText($profile["employee_id"]); ?>"
                                required
                            >
                        </div>

                        <div class="central-profile-form-group">
                            <label for="gender">Gender</label>
                            <select id="gender" name="gender" required>
                                <option value="">Select Gender</option>
                                <option value="male" <?php echo $profile["gender"] === "male" ? "selected" : ""; ?>>Male</option>
                                <option value="female" <?php echo $profile["gender"] === "female" ? "selected" : ""; ?>>Female</option>
                                <option value="other" <?php echo $profile["gender"] === "other" ? "selected" : ""; ?>>Other</option>
                            </select>
                        </div>

                        <div class="central-profile-form-group">
                            <label for="designation">Designation</label>
                            <input
                                type="text"
                                id="designation"
                                name="designation"
                                value="<?php echo safeText($profile["designation"]); ?>"
                                required
                            >
                        </div>

                        <div class="central-profile-form-group">
                            <label for="department">Department</label>
                            <input
                                type="text"
                                id="department"
                                name="department"
                                value="<?php echo safeText($profile["department"]); ?>"
                                required
                            >
                        </div>

                        <div class="central-profile-form-group wide">
                            <label for="address">Address</label>
                            <textarea id="address" name="address" rows="3" required><?php echo safeText($profile["address"]); ?></textarea>
                        </div>

                        <div class="central-profile-form-group wide">
                            <label for="office_address">Office Address</label>
                            <textarea id="office_address" name="office_address" rows="3" required><?php echo safeText($profile["office_address"]); ?></textarea>
                        </div>

                    </div>

                    <div class="central-profile-form-actions">
                        <button type="button" class="central-profile-cancel-btn" id="cancelEditBtn">
                            Cancel
                        </button>

                        <button type="submit" class="central-profile-save-btn">
                            Save Changes
                        </button>
                    </div>
                </form>

            </div>

        </section>

        <?php include "../../includes/central/footer.php"; ?>

    </main>

</div>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/profile.js"></script>

</body>
</html>