<?php
$pageTitle = "Profile";
$activePage = "profile";

require_once "../../config.php";
require_once "../../auth/session_check.php";

$userId = $_SESSION['user_id'] ?? 0;

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function jsonResponse($success, $message, $extra = [])
{
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit;
}

function roleLabel($role)
{
    $role = strtolower((string)$role);

    if ($role === 'team_leader') {
        return 'Team Leader';
    }

    if ($role === 'assistant_team_leader') {
        return 'Assistant Team Leader';
    }

    if ($role === 'worker') {
        return 'Field Technician';
    }

    return ucwords(str_replace('_', ' ', $role));
}

function fetchMaintenanceProfile($conn, $userId)
{
    $profileSql = "
        SELECT
            mtm.member_id,
            mtm.maintenance_team_id,
            mtm.user_id,
            mtm.full_name,
            mtm.phone_number,
            mtm.user_mail,
            mtm.employee_code,
            mtm.gender,
            mtm.address,
            mtm.profile_image,
            mtm.role,
            mtm.status,

            u.user_name,
            u.user_mail AS login_email,
            u.user_password,
            u.login_access,

            mt.team_name,
            mt.availability_status,
            mt.assistant_login_access,
            mt.city_cor_id,
            mt.anchal_id
        FROM maintenance_team_members mtm
        INNER JOIN maintenance_teams mt
            ON mt.maintenance_team_id = mtm.maintenance_team_id
        LEFT JOIN users u
            ON u.user_id = mtm.user_id
        WHERE mtm.user_id = ?
        LIMIT 1
    ";

    $profileStmt = mysqli_prepare($conn, $profileSql);

    if (!$profileStmt) {
        return null;
    }

    mysqli_stmt_bind_param($profileStmt, "i", $userId);
    mysqli_stmt_execute($profileStmt);
    $profileResult = mysqli_stmt_get_result($profileStmt);

    $profile = null;

    if ($profileResult && mysqli_num_rows($profileResult) > 0) {
        $profile = mysqli_fetch_assoc($profileResult);
    }

    mysqli_stmt_close($profileStmt);

    return $profile;
}

$profile = fetchMaintenanceProfile($conn, $userId);

/* =========================================================
   AJAX: UPDATE PROFILE PHOTO
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_profile_photo') {
    if (!$profile) {
        jsonResponse(false, 'Profile not found.');
    }

    if (empty($_FILES['profile_image']) || $_FILES['profile_image']['error'] === UPLOAD_ERR_NO_FILE) {
        jsonResponse(false, 'Please select a profile photo.');
    }

    $file = $_FILES['profile_image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(false, 'Profile photo upload failed.');
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];

    $maxSize = 5 * 1024 * 1024;

    if ($file['size'] > $maxSize) {
        jsonResponse(false, 'Profile photo must be 5MB or less.');
    }

    $detectedMime = $file['type'];

    if (function_exists('mime_content_type')) {
        $detectedMime = mime_content_type($file['tmp_name']);
    }

    if (!isset($allowedTypes[$detectedMime])) {
        jsonResponse(false, 'Only JPG, PNG, WEBP, or GIF image is allowed.');
    }

    $uploadDirRelative = "assets/uploads/maintenance_profiles/";
    $uploadDirAbsolute = "../../" . $uploadDirRelative;

    if (!is_dir($uploadDirAbsolute)) {
        mkdir($uploadDirAbsolute, 0777, true);
    }

    $extension = $allowedTypes[$detectedMime];
    $newFileName = "maintenance_profile_" . $profile['member_id'] . "_" . time() . "." . $extension;

    $targetAbsolute = $uploadDirAbsolute . $newFileName;
    $targetRelative = $uploadDirRelative . $newFileName;

    if (!move_uploaded_file($file['tmp_name'], $targetAbsolute)) {
        jsonResponse(false, 'Failed to save profile photo.');
    }

    $oldImage = $profile['profile_image'] ?? '';

    $updateSql = "
        UPDATE maintenance_team_members
        SET profile_image = ?
        WHERE member_id = ?
        AND user_id = ?
    ";

    $updateStmt = mysqli_prepare($conn, $updateSql);

    if (!$updateStmt) {
        if (file_exists($targetAbsolute)) {
            unlink($targetAbsolute);
        }

        jsonResponse(false, 'Profile photo update prepare failed.');
    }

    $memberId = (int)$profile['member_id'];

    mysqli_stmt_bind_param($updateStmt, "sii", $targetRelative, $memberId, $userId);

    if (!mysqli_stmt_execute($updateStmt)) {
        mysqli_stmt_close($updateStmt);

        if (file_exists($targetAbsolute)) {
            unlink($targetAbsolute);
        }

        jsonResponse(false, 'Failed to update profile photo.');
    }

    mysqli_stmt_close($updateStmt);

    if (!empty($oldImage)) {
        $oldAbsolute = "../../" . $oldImage;

        if (file_exists($oldAbsolute) && is_file($oldAbsolute)) {
            unlink($oldAbsolute);
        }
    }

    jsonResponse(true, 'Profile photo updated successfully.', [
        'image_path' => "../../" . $targetRelative
    ]);
}

/* =========================================================
   AJAX: UPDATE PROFILE INFO
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile_info') {
    if (!$profile) {
        jsonResponse(false, 'Profile not found.');
    }

    $memberId = (int)$profile['member_id'];
    $teamId = (int)$profile['maintenance_team_id'];
    $role = strtolower((string)$profile['role']);

    $fullName = trim($_POST['full_name'] ?? '');
    $phoneNumber = trim($_POST['phone_number'] ?? '');
    $userMail = trim($_POST['user_mail'] ?? '');
    $employeeCode = trim($_POST['employee_code'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $assistantLoginAccess = trim($_POST['assistant_login_access'] ?? '');

    if ($fullName === '') {
        jsonResponse(false, 'Full name is required.');
    }

    if ($phoneNumber === '') {
        jsonResponse(false, 'Phone number is required.');
    }

    if ($userMail === '' || !filter_var($userMail, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, 'Valid email is required.');
    }

    if ($employeeCode === '') {
        jsonResponse(false, 'Employee code is required.');
    }

    if (!in_array($gender, ['male', 'female', 'other'], true)) {
        jsonResponse(false, 'Invalid gender selected.');
    }

    if ($address === '') {
        jsonResponse(false, 'Address is required.');
    }

    if ($role === 'team_leader' && !in_array($assistantLoginAccess, ['yes', 'no'], true)) {
        jsonResponse(false, 'Invalid assistant login access value.');
    }

    $duplicateSql = "
        SELECT member_id
        FROM maintenance_team_members
        WHERE employee_code = ?
        AND member_id <> ?
        LIMIT 1
    ";

    $duplicateStmt = mysqli_prepare($conn, $duplicateSql);

    if (!$duplicateStmt) {
        jsonResponse(false, 'Employee duplicate check prepare failed.');
    }

    mysqli_stmt_bind_param($duplicateStmt, "si", $employeeCode, $memberId);
    mysqli_stmt_execute($duplicateStmt);
    $duplicateResult = mysqli_stmt_get_result($duplicateStmt);

    if ($duplicateResult && mysqli_num_rows($duplicateResult) > 0) {
        mysqli_stmt_close($duplicateStmt);
        jsonResponse(false, 'Employee code already exists.');
    }

    mysqli_stmt_close($duplicateStmt);

    $emailDuplicateSql = "
        SELECT user_id
        FROM users
        WHERE user_mail = ?
        AND user_id <> ?
        LIMIT 1
    ";

    $emailDuplicateStmt = mysqli_prepare($conn, $emailDuplicateSql);

    if (!$emailDuplicateStmt) {
        jsonResponse(false, 'Email duplicate check prepare failed.');
    }

    mysqli_stmt_bind_param($emailDuplicateStmt, "si", $userMail, $userId);
    mysqli_stmt_execute($emailDuplicateStmt);
    $emailDuplicateResult = mysqli_stmt_get_result($emailDuplicateStmt);

    if ($emailDuplicateResult && mysqli_num_rows($emailDuplicateResult) > 0) {
        mysqli_stmt_close($emailDuplicateStmt);
        jsonResponse(false, 'Email already exists in another account.');
    }

    mysqli_stmt_close($emailDuplicateStmt);

    mysqli_begin_transaction($conn);

    try {
        $updateMemberSql = "
            UPDATE maintenance_team_members
            SET
                full_name = ?,
                phone_number = ?,
                user_mail = ?,
                employee_code = ?,
                gender = ?,
                address = ?
            WHERE member_id = ?
            AND user_id = ?
        ";

        $updateMemberStmt = mysqli_prepare($conn, $updateMemberSql);

        if (!$updateMemberStmt) {
            throw new Exception('Profile update prepare failed.');
        }

        mysqli_stmt_bind_param(
            $updateMemberStmt,
            "ssssssii",
            $fullName,
            $phoneNumber,
            $userMail,
            $employeeCode,
            $gender,
            $address,
            $memberId,
            $userId
        );

        if (!mysqli_stmt_execute($updateMemberStmt)) {
            throw new Exception('Failed to update profile information.');
        }

        mysqli_stmt_close($updateMemberStmt);

        $updateUserSql = "
            UPDATE users
            SET
                user_name = ?,
                user_mail = ?
            WHERE user_id = ?
        ";

        $updateUserStmt = mysqli_prepare($conn, $updateUserSql);

        if (!$updateUserStmt) {
            throw new Exception('User update prepare failed.');
        }

        mysqli_stmt_bind_param($updateUserStmt, "ssi", $fullName, $userMail, $userId);

        if (!mysqli_stmt_execute($updateUserStmt)) {
            throw new Exception('Failed to update login account.');
        }

        mysqli_stmt_close($updateUserStmt);

        if ($role === 'team_leader') {
            $updateTeamSql = "
                UPDATE maintenance_teams
                SET assistant_login_access = ?
                WHERE maintenance_team_id = ?
            ";

            $updateTeamStmt = mysqli_prepare($conn, $updateTeamSql);

            if (!$updateTeamStmt) {
                throw new Exception('Assistant login access update prepare failed.');
            }

            mysqli_stmt_bind_param($updateTeamStmt, "si", $assistantLoginAccess, $teamId);

            if (!mysqli_stmt_execute($updateTeamStmt)) {
                throw new Exception('Failed to update assistant login access.');
            }

            mysqli_stmt_close($updateTeamStmt);

            $assistantLoginValue = $assistantLoginAccess === 'yes' ? 1 : 0;

            $assistantSql = "
                UPDATE users u
                INNER JOIN maintenance_team_members mtm
                    ON mtm.user_id = u.user_id
                SET u.login_access = ?
                WHERE mtm.maintenance_team_id = ?
                AND mtm.role = 'assistant_team_leader'
            ";

            $assistantStmt = mysqli_prepare($conn, $assistantSql);

            if (!$assistantStmt) {
                throw new Exception('Assistant user access update prepare failed.');
            }

            mysqli_stmt_bind_param($assistantStmt, "ii", $assistantLoginValue, $teamId);

            if (!mysqli_stmt_execute($assistantStmt)) {
                throw new Exception('Failed to update assistant login permission.');
            }

            mysqli_stmt_close($assistantStmt);
        }

        mysqli_commit($conn);

        $_SESSION['user_name'] = $fullName;

        jsonResponse(true, 'Profile information updated successfully.');
    } catch (Exception $e) {
        mysqli_rollback($conn);
        jsonResponse(false, $e->getMessage());
    }
}

/* =========================================================
   AJAX: CHANGE PASSWORD
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    if (!$profile) {
        jsonResponse(false, 'Profile not found.');
    }

    if (empty($profile['user_password'])) {
        jsonResponse(false, 'No login account found for this member.');
    }

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        jsonResponse(false, 'All password fields are required.');
    }

    if (!password_verify($currentPassword, $profile['user_password'])) {
        jsonResponse(false, 'Current password is incorrect.');
    }

    if (strlen($newPassword) < 8) {
        jsonResponse(false, 'New password must be at least 8 characters.');
    }

    if ($newPassword !== $confirmPassword) {
        jsonResponse(false, 'New password and confirm password do not match.');
    }

    if (password_verify($newPassword, $profile['user_password'])) {
        jsonResponse(false, 'New password must be different from current password.');
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    $updatePasswordSql = "
        UPDATE users
        SET user_password = ?
        WHERE user_id = ?
    ";

    $updatePasswordStmt = mysqli_prepare($conn, $updatePasswordSql);

    if (!$updatePasswordStmt) {
        jsonResponse(false, 'Password update prepare failed.');
    }

    mysqli_stmt_bind_param($updatePasswordStmt, "si", $hashedPassword, $userId);

    if (!mysqli_stmt_execute($updatePasswordStmt)) {
        mysqli_stmt_close($updatePasswordStmt);
        jsonResponse(false, 'Failed to update password.');
    }

    mysqli_stmt_close($updatePasswordStmt);

    jsonResponse(true, 'Password changed successfully.');
}

if (!$profile) {
    $profile = [
        'member_id' => 0,
        'full_name' => 'Maintenance User',
        'phone_number' => '',
        'user_mail' => '',
        'employee_code' => '',
        'gender' => 'male',
        'address' => '',
        'role' => 'maintenance_member',
        'status' => 'inactive',
        'profile_image' => '',
        'team_name' => 'Maintenance Team',
        'availability_status' => 'available',
        'assistant_login_access' => 'no'
    ];
}

$profileImage = !empty($profile['profile_image']) ? "../../" . $profile['profile_image'] : "";
$initial = strtoupper(substr((string)$profile['full_name'], 0, 1));
$isTeamLeader = strtolower((string)$profile['role']) === 'team_leader';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profile | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/maintenance/sidebar.css">
    <link rel="stylesheet" href="../../css/maintenance/topbar.css">
    <link rel="stylesheet" href="../../css/maintenance/profile.css">
</head>

<body class="maintenance">
    <div class="maintenance-layout">
        <?php include "../../includes/maintenance/sidebar.php"; ?>

        <main class="maintenance-main">
            <?php include "../../includes/maintenance/topbar.php"; ?>

            <section class="profile-page">
                <div class="page-heading">
                    <h1>Profile</h1>
                    <p>Update your profile, profile photo, and password</p>
                </div>

                <div class="profile-card">
                    <div class="profile-hero">
                        <form id="profilePhotoForm" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="upload_profile_photo">
                            <input type="file" id="profileImageInput" name="profile_image" accept="image/*" hidden>

                            <button type="button" class="profile-photo" id="profilePhotoButton" title="Click to change profile photo">
                                <span class="photo-overlay">
                                    <i class="bi bi-camera"></i>
                                    Change
                                </span>

                                <span id="profilePhotoPreview">
                                    <?php if (!empty($profileImage)): ?>
                                        <img src="<?php echo e($profileImage); ?>" alt="Profile photo">
                                    <?php else: ?>
                                        <span class="profile-initial"><?php echo e($initial); ?></span>
                                    <?php endif; ?>
                                </span>
                            </button>
                        </form>

                        <div class="profile-main-info">
                            <h2><?php echo e($profile['full_name']); ?></h2>
                            <p><?php echo e(roleLabel($profile['role'])); ?> • <?php echo e($profile['team_name']); ?></p>
                        </div>
                    </div>

                    <form id="profileInfoForm" class="profile-form">
                        <input type="hidden" name="action" value="update_profile_info">

                        <div class="profile-info-grid">
                            <div class="input-box">
                                <label>Full Name</label>
                                <input type="text" name="full_name" value="<?php echo e($profile['full_name']); ?>">
                            </div>

                            <div class="input-box readonly-box">
                                <label>Role</label>
                                <input type="text" value="<?php echo e(roleLabel($profile['role'])); ?>" readonly>
                            </div>

                            <div class="input-box">
                                <label>Phone Number</label>
                                <input type="text" name="phone_number" value="<?php echo e($profile['phone_number']); ?>">
                            </div>

                            <div class="input-box">
                                <label>Email</label>
                                <input type="email" name="user_mail" value="<?php echo e($profile['user_mail']); ?>">
                            </div>

                            <div class="input-box">
                                <label>Employee Code</label>
                                <input type="text" name="employee_code" value="<?php echo e($profile['employee_code']); ?>">
                            </div>

                            <div class="input-box readonly-box">
                                <label>Team Name</label>
                                <input type="text" value="<?php echo e($profile['team_name']); ?>" readonly>
                            </div>

                            <div class="input-box">
                                <label>Gender</label>
                                <select name="gender">
                                    <option value="male" <?php echo strtolower((string)$profile['gender']) === 'male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo strtolower((string)$profile['gender']) === 'female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="other" <?php echo strtolower((string)$profile['gender']) === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>

                            <?php if ($isTeamLeader): ?>
                                <div class="input-box">
                                    <label>Assistant Login Access</label>
                                    <select name="assistant_login_access">
                                        <option value="yes" <?php echo strtolower((string)$profile['assistant_login_access']) === 'yes' ? 'selected' : ''; ?>>Yes</option>
                                        <option value="no" <?php echo strtolower((string)$profile['assistant_login_access']) === 'no' ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="input-box full-width">
                                <label>Address</label>
                                <textarea name="address"><?php echo e($profile['address']); ?></textarea>
                            </div>
                        </div>

                        <button type="submit" class="update-btn">
                            <i class="bi bi-check2-circle"></i>
                            Update Profile
                        </button>
                    </form>
                </div>

                <div class="password-card">
                    <div class="password-head">
                        <div class="password-icon">
                            <i class="bi bi-shield-lock"></i>
                        </div>

                        <div>
                            <h2>Change Password</h2>
                            <p>Use at least 8 characters for the new password.</p>
                        </div>
                    </div>

                    <form id="passwordForm" class="password-form">
                        <input type="hidden" name="action" value="change_password">

                        <div class="password-grid">
                            <div class="input-box password-input-box">
                                <label>Current Password</label>
                                <input type="password" name="current_password" id="currentPassword">
                                <button type="button" class="toggle-password" data-target="currentPassword">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>

                            <div class="input-box password-input-box">
                                <label>New Password</label>
                                <input type="password" name="new_password" id="newPassword">
                                <button type="button" class="toggle-password" data-target="newPassword">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>

                            <div class="input-box password-input-box">
                                <label>Confirm Password</label>
                                <input type="password" name="confirm_password" id="confirmPassword">
                                <button type="button" class="toggle-password" data-target="confirmPassword">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="password-btn">
                            <i class="bi bi-key"></i>
                            Change Password
                        </button>
                    </form>
                </div>
            </section>
        </main>
    </div>

    <script src="../../js/maintenance/sidebar.js"></script>
    <script src="../../js/maintenance/profile.js"></script>
</body>
</html>