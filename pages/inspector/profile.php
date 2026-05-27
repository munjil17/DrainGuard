<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';

if (!isset($conn) && isset($connection)) {
    $conn = $connection;
}

if (!isset($conn) || !$conn) {
    die("Database connection not found.");
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

$userId = (int) $_SESSION['user_id'];
$activePage = 'profile';
$pageTitle = 'Profile';

$successMessage = '';
$errorMessage = '';

function ipText($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ipBindParams($stmt, $types, &$params)
{
    if ($types === '' || empty($params)) {
        return;
    }

    $bindValues = [];
    $bindValues[] = $types;

    for ($i = 0; $i < count($params); $i++) {
        $bindValues[] = &$params[$i];
    }

    call_user_func_array([$stmt, 'bind_param'], $bindValues);
}

function ipFetchOne($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    ipBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function ipFetchAll($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    ipBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function ipImagePath($path)
{
    if (empty($path)) {
        return "";
    }

    $path = trim($path);

    if (preg_match('/^https?:\/\//', $path)) {
        return $path;
    }

    if (substr($path, 0, 6) === "../../") {
        return $path;
    }

    return "../../" . ltrim($path, "/");
}

function ipUploadProfileImage($file, $userId)
{
    if (!isset($file) || empty($file['name'])) {
        return [false, "No image selected.", null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, "Image upload failed.", null];
    }

    $allowedMimeTypes = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp'
    ];

    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($fileInfo, $file['tmp_name']);
    finfo_close($fileInfo);

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        return [false, "Only JPG, PNG, or WEBP image is allowed.", null];
    }

    if ($file['size'] > 3 * 1024 * 1024) {
        return [false, "Profile image must be less than 3MB.", null];
    }

    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    $extension = $extensionMap[$mimeType] ?? 'jpg';

    $uploadDir = __DIR__ . '/../../assets/uploads/inspectors/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $fileName = 'inspector_' . $userId . '_' . time() . '.' . $extension;
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return [false, "Could not save uploaded image.", null];
    }

    $dbPath = 'assets/uploads/inspectors/' . $fileName;

    return [true, "Profile image updated successfully.", $dbPath];
}

$inspector = ipFetchOne(
    $conn,
    "SELECT
        i.inspector_id,
        i.user_id,
        i.city_cor_id,
        i.assigned_ward_id,
        i.user_mail,
        i.full_name,
        i.phone_number,
        i.employee_code,
        i.address,
        i.gender,
        i.designation,
        i.office_address,
        i.profile_image,

        u.user_name,
        u.user_mail AS login_email,
        u.user_role,
        u.user_status,
        u.login_access,
        u.last_active,

        w.ward_no,
        w.ward_name,

        cc.city_cor_name
    FROM inspectors i
    INNER JOIN users u ON u.user_id = i.user_id
    LEFT JOIN wards w ON w.ward_id = i.assigned_ward_id
    LEFT JOIN city_corporations cc ON cc.city_cor_id = i.city_cor_id
    WHERE i.user_id = ?
    LIMIT 1",
    "i",
    [$userId]
);

if (!$inspector) {
    die("Inspector profile not found.");
}

$_SESSION['user_name'] = $inspector['full_name'] ?: ($inspector['user_name'] ?? 'Inspector');
$_SESSION['user_role_label'] = 'Inspector Verification';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = trim($_POST['form_type'] ?? '');

    if ($formType === 'profile_image') {
        [$uploadOk, $uploadMessage, $newImagePath] = ipUploadProfileImage($_FILES['profile_image'] ?? null, $userId);

        if (!$uploadOk) {
            $errorMessage = $uploadMessage;
        } else {
            $oldImage = $inspector['profile_image'] ?? '';

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE inspectors
                SET profile_image = ?
                WHERE user_id = ?"
            );

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "si", $newImagePath, $userId);

                if (mysqli_stmt_execute($stmt)) {
                    if (!empty($oldImage)) {
                        $oldFullPath = __DIR__ . '/../../' . ltrim($oldImage, '/');

                        if (is_file($oldFullPath)) {
                            @unlink($oldFullPath);
                        }
                    }

                    $successMessage = $uploadMessage;
                    $inspector['profile_image'] = $newImagePath;
                } else {
                    $errorMessage = "Profile image update failed.";
                }

                mysqli_stmt_close($stmt);
            } else {
                $errorMessage = "Profile image update failed.";
            }
        }
    }

    if ($formType === 'profile_info') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = preg_replace('/\s+/', '', trim($_POST['phone_number'] ?? ''));

        if ($fullName === '' || $email === '' || $phone === '') {
            $errorMessage = "Full name, email, and phone are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = "Please enter a valid email address.";
        } elseif (strlen($phone) < 8 || strlen($phone) > 20) {
            $errorMessage = "Phone number must be between 8 and 20 characters.";
        } else {
            $duplicateEmail = ipFetchOne(
                $conn,
                "SELECT user_id
                FROM users
                WHERE user_mail = ?
                AND user_id <> ?
                LIMIT 1",
                "si",
                [$email, $userId]
            );

            if ($duplicateEmail) {
                $errorMessage = "This email is already used by another account.";
            } else {
                mysqli_begin_transaction($conn);

                try {
                    $stmt = mysqli_prepare(
                        $conn,
                        "UPDATE inspectors
                        SET full_name = ?,
                            user_mail = ?,
                            phone_number = ?
                        WHERE user_id = ?"
                    );

                    if (!$stmt) {
                        throw new Exception(mysqli_error($conn));
                    }

                    mysqli_stmt_bind_param($stmt, "sssi", $fullName, $email, $phone, $userId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    $stmt = mysqli_prepare(
                        $conn,
                        "UPDATE users
                        SET user_name = ?,
                            user_mail = ?
                        WHERE user_id = ?"
                    );

                    if (!$stmt) {
                        throw new Exception(mysqli_error($conn));
                    }

                    mysqli_stmt_bind_param($stmt, "ssi", $fullName, $email, $userId);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    mysqli_commit($conn);

                    $_SESSION['user_name'] = $fullName;

                    $successMessage = "Profile information updated successfully.";

                    $inspector['full_name'] = $fullName;
                    $inspector['user_mail'] = $email;
                    $inspector['login_email'] = $email;
                    $inspector['phone_number'] = $phone;

                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $errorMessage = "Profile update failed.";
                }
            }
        }
    }
}

$coverageAreas = ipFetchAll(
    $conn,
    "SELECT area_id, area_name
    FROM areas
    WHERE ward_id = ?
    ORDER BY area_name ASC",
    "i",
    [(int) $inspector['assigned_ward_id']]
);

$profileImage = ipImagePath($inspector['profile_image'] ?? '');
$hasProfileImage = $profileImage !== '';

$wardLabel = 'Ward ' . ($inspector['ward_no'] ?? 'N/A');

if (!empty($inspector['ward_name'])) {
    $wardLabel .= ' - ' . $inspector['ward_name'];
}

$roleLabel = trim(($inspector['designation'] ?: 'Field Inspector') . ' - Quality Control');
$cityCorporationLabel = $inspector['city_cor_name'] ?: 'Not assigned';
$statusLabel = ucwords((string) ($inspector['user_status'] ?? 'active'));
$loginAccessLabel = ((int) ($inspector['login_access'] ?? 1) === 1) ? 'Enabled' : 'Disabled';

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Profile | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/inspector/sidebar.css">
    <link rel="stylesheet" href="../../css/inspector/topbar.css">
    <link rel="stylesheet" href="../../css/inspector/profile.css">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>

<body class="inspector">

    <div class="inspector-layout">

        <?php include __DIR__ . '/../../includes/inspector/sidebar.php'; ?>

        <main class="inspector-main">

            <?php include __DIR__ . '/../../includes/inspector/topbar.php'; ?>

            <section class="inspector-profile-page">

                <div class="page-heading">
                    <h1>Inspector Profile</h1>
                    <p>View and update your inspector profile information.</p>
                </div>

                <?php if ($successMessage !== ''): ?>
                    <div class="profile-alert success">
                        <i class="bi bi-check-circle"></i>
                        <span><?php echo ipText($successMessage); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="profile-alert error">
                        <i class="bi bi-exclamation-circle"></i>
                        <span><?php echo ipText($errorMessage); ?></span>
                    </div>
                <?php endif; ?>

                <div class="profile-grid">

                    <div class="profile-card profile-summary-card">

                        <form method="POST" action="profile.php" enctype="multipart/form-data" id="profileImageForm">
                            <input type="hidden" name="form_type" value="profile_image">

                            <label class="profile-photo-wrap" for="profile_image">
                                <?php if ($hasProfileImage): ?>
                                    <img src="<?php echo ipText($profileImage); ?>" alt="Inspector profile photo" id="profilePreview">
                                <?php else: ?>
                                    <span class="profile-placeholder" id="profilePlaceholder">
                                        <i class="bi bi-person"></i>
                                    </span>
                                    <img src="" alt="Inspector profile photo" id="profilePreview" class="hidden-preview">
                                <?php endif; ?>

                                <span class="photo-edit-overlay">
                                    <i class="bi bi-camera"></i>
                                    Change Photo
                                </span>
                            </label>

                            <input
                                type="file"
                                id="profile_image"
                                name="profile_image"
                                accept="image/jpeg,image/jpg,image/png,image/webp"
                                hidden>
                        </form>

                        <h2><?php echo ipText($inspector['full_name']); ?></h2>
                        <p><?php echo ipText($roleLabel); ?></p>

                        <div class="summary-badges">
                            <span>
                                <i class="bi bi-person-badge"></i>
                                <?php echo ipText($inspector['employee_code'] ?: 'N/A'); ?>
                            </span>

                            <span>
                                <i class="bi bi-shield-check"></i>
                                <?php echo ipText($statusLabel); ?>
                            </span>

                            <span>
                                <i class="bi bi-key"></i>
                                Login <?php echo ipText($loginAccessLabel); ?>
                            </span>
                        </div>

                    </div>

                    <form method="POST" action="profile.php" class="profile-card profile-form-card" id="inspectorProfileForm">
                        <input type="hidden" name="form_type" value="profile_info">

                        <div class="card-title-row">
                            <div class="card-icon">
                                <i class="bi bi-person-lines-fill"></i>
                            </div>

                            <div>
                                <h2>Personal Information</h2>
                                <p>Only basic contact information can be updated here.</p>
                            </div>
                        </div>

                        <div class="form-grid">

                            <div class="form-group">
                                <label for="full_name">Full Name</label>
                                <input
                                    type="text"
                                    id="full_name"
                                    name="full_name"
                                    value="<?php echo ipText($inspector['full_name']); ?>"
                                    required>
                            </div>

                            <div class="form-group">
                                <label for="email">Email</label>
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    value="<?php echo ipText($inspector['user_mail'] ?: $inspector['login_email']); ?>"
                                    required>
                            </div>

                            <div class="form-group">
                                <label for="phone_number">Phone</label>
                                <input
                                    type="text"
                                    id="phone_number"
                                    name="phone_number"
                                    value="<?php echo ipText($inspector['phone_number']); ?>"
                                    required>
                            </div>

                            <div class="form-group">
                                <label>Gender</label>
                                <input
                                    type="text"
                                    value="<?php echo ipText(ucwords($inspector['gender'] ?: 'N/A')); ?>"
                                    readonly>
                            </div>

                            <div class="form-group">
                                <label>Employee Code</label>
                                <input
                                    type="text"
                                    value="<?php echo ipText($inspector['employee_code'] ?: 'N/A'); ?>"
                                    readonly>
                            </div>

                            <div class="form-group">
                                <label>Designation</label>
                                <input
                                    type="text"
                                    value="<?php echo ipText($inspector['designation'] ?: 'N/A'); ?>"
                                    readonly>
                            </div>

                            <div class="form-group full">
                                <label>Address</label>
                                <input
                                    type="text"
                                    value="<?php echo ipText($inspector['address'] ?: 'N/A'); ?>"
                                    readonly>
                            </div>

                            <div class="form-group full">
                                <label>Office Address</label>
                                <input
                                    type="text"
                                    value="<?php echo ipText($inspector['office_address'] ?: 'N/A'); ?>"
                                    readonly>
                            </div>

                        </div>

                        <button type="submit" class="primary-btn">
                            <i class="bi bi-check2-circle"></i>
                            Update Profile
                        </button>
                    </form>

                    <div class="profile-card coverage-card">
                        <div class="card-title-row">
                            <div class="card-icon location-icon">
                                <i class="bi bi-geo-alt"></i>
                            </div>

                            <div>
                                <h2>Assigned Coverage</h2>
                                <p>Coverage area is assigned by Central Control.</p>
                            </div>
                        </div>

                        <div class="coverage-grid">

                            <div class="info-box">
                                <span>City Corporation</span>
                                <strong><?php echo ipText($cityCorporationLabel); ?></strong>
                            </div>

                            <div class="info-box">
                                <span>Assigned Ward</span>
                                <strong><?php echo ipText($wardLabel); ?></strong>
                            </div>

                            <div class="info-box full">
                                <span>Coverage Areas</span>

                                <div class="area-chip-box">
                                    <?php if (!empty($coverageAreas)): ?>
                                        <?php foreach ($coverageAreas as $area): ?>
                                            <span class="area-chip">
                                                <?php echo ipText($area['area_name']); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="empty-chip">No area found under this ward.</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>
                    </div>

                </div>

            </section>

            <?php
            $footerPath = __DIR__ . '/../../includes/inspector/footer.php';

            if (file_exists($footerPath)) {
                include $footerPath;
            }
            ?>

        </main>

    </div>

    <script src="../../js/inspector/sidebar.js"></script>
    <script src="../../js/inspector/profile.js"></script>

</body>

</html>