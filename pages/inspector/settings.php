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
$activePage = 'settings';
$pageTitle = 'Settings';

$successMessage = '';
$errorMessage = '';

/* =========================
   Helper Functions
========================= */

function isText($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function isBindParams($stmt, $types, &$params)
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

function isFetchOne($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    isBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function isFetchAll($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        die("SQL Prepare Failed: " . mysqli_error($conn));
    }

    isBindParams($stmt, $types, $params);

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    mysqli_stmt_close($stmt);

    return $rows;
}

function isCleanPhone($phone)
{
    return preg_replace('/\s+/', '', trim((string) $phone));
}

/* =========================
   Inspector Data
========================= */

$inspector = isFetchOne(
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

        u.user_name,
        u.user_mail AS login_email,
        u.user_password,
        u.user_role,
        u.user_status,
        u.login_access,

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

/* =========================
   Default Inspector Settings
========================= */

$settings = isFetchOne(
    $conn,
    "SELECT
        setting_id,
        user_id,
        email_alerts,
        sms_alerts,
        push_alerts,
        daily_summary,
        weekly_digest,
        false_completion_alerts
    FROM inspector_settings
    WHERE user_id = ?
    LIMIT 1",
    "i",
    [$userId]
);

if (!$settings) {
    $insertSettingsStmt = mysqli_prepare(
        $conn,
        "INSERT INTO inspector_settings
        (user_id, email_alerts, sms_alerts, push_alerts, daily_summary, weekly_digest, false_completion_alerts)
        VALUES (?, 1, 1, 1, 1, 1, 1)"
    );

    if ($insertSettingsStmt) {
        mysqli_stmt_bind_param($insertSettingsStmt, "i", $userId);
        mysqli_stmt_execute($insertSettingsStmt);
        mysqli_stmt_close($insertSettingsStmt);
    }

    $settings = [
        'email_alerts' => 1,
        'sms_alerts' => 1,
        'push_alerts' => 1,
        'daily_summary' => 1,
        'weekly_digest' => 1,
        'false_completion_alerts' => 1
    ];
}

/* =========================
   Handle POST Actions
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = trim($_POST['form_type'] ?? '');

    if ($formType === 'profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = isCleanPhone($_POST['phone_number'] ?? '');

        if ($fullName === '' || $email === '' || $phone === '') {
            $errorMessage = "Full name, email, and phone are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = "Please enter a valid email address.";
        } elseif (strlen($phone) < 8 || strlen($phone) > 20) {
            $errorMessage = "Phone number must be between 8 and 20 characters.";
        } else {
            $duplicateEmail = isFetchOne(
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
                    $successMessage = "Profile updated successfully.";

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

    if ($formType === 'password') {
        $currentPassword = trim($_POST['current_password'] ?? '');
        $newPassword = trim($_POST['new_password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $errorMessage = "All password fields are required.";
        } elseif (strlen($newPassword) < 8) {
            $errorMessage = "New password must be at least 8 characters.";
        } elseif ($newPassword !== $confirmPassword) {
            $errorMessage = "New password and confirm password do not match.";
        } elseif (!password_verify($currentPassword, $inspector['user_password'])) {
            $errorMessage = "Current password is incorrect.";
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE users
                SET user_password = ?
                WHERE user_id = ?"
            );

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "si", $newHash, $userId);

                if (mysqli_stmt_execute($stmt)) {
                    $successMessage = "Password changed successfully.";
                    $inspector['user_password'] = $newHash;
                } else {
                    $errorMessage = "Password change failed.";
                }

                mysqli_stmt_close($stmt);
            } else {
                $errorMessage = "Password change failed.";
            }
        }
    }

    if ($formType === 'notifications') {
        $emailAlerts = isset($_POST['email_alerts']) ? 1 : 0;
        $smsAlerts = isset($_POST['sms_alerts']) ? 1 : 0;
        $pushAlerts = isset($_POST['push_alerts']) ? 1 : 0;
        $dailySummary = isset($_POST['daily_summary']) ? 1 : 0;
        $weeklyDigest = isset($_POST['weekly_digest']) ? 1 : 0;
        $falseCompletionAlerts = isset($_POST['false_completion_alerts']) ? 1 : 0;

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO inspector_settings
            (user_id, email_alerts, sms_alerts, push_alerts, daily_summary, weekly_digest, false_completion_alerts)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                email_alerts = VALUES(email_alerts),
                sms_alerts = VALUES(sms_alerts),
                push_alerts = VALUES(push_alerts),
                daily_summary = VALUES(daily_summary),
                weekly_digest = VALUES(weekly_digest),
                false_completion_alerts = VALUES(false_completion_alerts)"
        );

        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                "iiiiiii",
                $userId,
                $emailAlerts,
                $smsAlerts,
                $pushAlerts,
                $dailySummary,
                $weeklyDigest,
                $falseCompletionAlerts
            );

            if (mysqli_stmt_execute($stmt)) {
                $successMessage = "Notification preferences updated successfully.";

                $settings['email_alerts'] = $emailAlerts;
                $settings['sms_alerts'] = $smsAlerts;
                $settings['push_alerts'] = $pushAlerts;
                $settings['daily_summary'] = $dailySummary;
                $settings['weekly_digest'] = $weeklyDigest;
                $settings['false_completion_alerts'] = $falseCompletionAlerts;
            } else {
                $errorMessage = "Notification update failed.";
            }

            mysqli_stmt_close($stmt);
        } else {
            $errorMessage = "Notification update failed.";
        }
    }
}

/* =========================
   Coverage Areas
========================= */

$coverageAreas = isFetchAll(
    $conn,
    "SELECT area_id, area_name
    FROM areas
    WHERE ward_id = ?
    ORDER BY area_name ASC",
    "i",
    [(int) $inspector['assigned_ward_id']]
);

$wardLabel = 'Ward ' . ($inspector['ward_no'] ?? 'N/A');

if (!empty($inspector['ward_name'])) {
    $wardLabel .= ' - ' . $inspector['ward_name'];
}

$cityCorporationLabel = $inspector['city_cor_name'] ?: 'Not assigned';
$roleLabel = trim(($inspector['designation'] ?: 'Field Inspector') . ' - Quality Control');

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Settings | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/inspector/sidebar.css">
    <link rel="stylesheet" href="../../css/inspector/topbar.css">
    <link rel="stylesheet" href="../../css/inspector/settings.css">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>

<body class="inspector">

    <div class="inspector-layout">

        <?php include __DIR__ . '/../../includes/inspector/sidebar.php'; ?>

        <main class="inspector-main">

            <?php include __DIR__ . '/../../includes/inspector/topbar.php'; ?>

            <section class="inspector-settings-page">

                <div class="page-heading">
                    <h1>Settings</h1>
                    <p>Manage inspector account settings and preferences.</p>
                </div>

                <?php if ($successMessage !== ''): ?>
                    <div class="settings-alert success">
                        <i class="bi bi-check-circle"></i>
                        <span><?php echo isText($successMessage); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($errorMessage !== ''): ?>
                    <div class="settings-alert error">
                        <i class="bi bi-exclamation-circle"></i>
                        <span><?php echo isText($errorMessage); ?></span>
                    </div>
                <?php endif; ?>

                <div class="settings-stack">

                    <form method="POST" action="settings.php" class="settings-card" id="inspectorProfileForm">
                        <input type="hidden" name="form_type" value="profile">

                        <div class="card-title-row">
                            <div class="card-icon profile-icon">
                                <i class="bi bi-person"></i>
                            </div>

                            <div>
                                <h2>Inspector Profile</h2>
                                <p>Update basic contact information used across the Inspector panel.</p>
                            </div>
                        </div>

                        <div class="form-grid">

                            <div class="form-group">
                                <label for="full_name">Full Name</label>
                                <input
                                    type="text"
                                    id="full_name"
                                    name="full_name"
                                    value="<?php echo isText($inspector['full_name']); ?>"
                                    required>
                            </div>

                            <div class="form-group">
                                <label for="email">Email</label>
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    value="<?php echo isText($inspector['user_mail'] ?: $inspector['login_email']); ?>"
                                    required>
                            </div>

                            <div class="form-group">
                                <label for="phone_number">Phone</label>
                                <input
                                    type="text"
                                    id="phone_number"
                                    name="phone_number"
                                    value="<?php echo isText($inspector['phone_number']); ?>"
                                    required>
                            </div>

                            <div class="form-group">
                                <label>Role</label>
                                <input
                                    type="text"
                                    value="<?php echo isText($roleLabel); ?>"
                                    readonly>
                            </div>

                            <div class="form-group">
                                <label>Employee Code</label>
                                <input
                                    type="text"
                                    value="<?php echo isText($inspector['employee_code'] ?: 'N/A'); ?>"
                                    readonly>
                            </div>

                            <div class="form-group">
                                <label>Office Address</label>
                                <input
                                    type="text"
                                    value="<?php echo isText($inspector['office_address'] ?: 'N/A'); ?>"
                                    readonly>
                            </div>

                        </div>

                        <button type="submit" class="primary-btn">
                            <i class="bi bi-check2-circle"></i>
                            Update Profile
                        </button>
                    </form>

                    <div class="settings-card">
                        <div class="card-title-row">
                            <div class="card-icon coverage-icon">
                                <i class="bi bi-geo-alt"></i>
                            </div>

                            <div>
                                <h2>Assigned Coverage Area</h2>
                                <p>Coverage details are assigned by Central Control and cannot be edited here.</p>
                            </div>
                        </div>

                        <div class="coverage-grid">

                            <div class="coverage-block">
                                <label>City Corporation</label>
                                <div class="readonly-box">
                                    <?php echo isText($cityCorporationLabel); ?>
                                </div>
                            </div>

                            <div class="coverage-block">
                                <label>Assigned Ward</label>
                                <div class="readonly-box">
                                    <?php echo isText($wardLabel); ?>
                                </div>
                            </div>

                            <div class="coverage-block full">
                                <label>Coverage Areas</label>

                                <div class="area-chip-box">
                                    <?php if (!empty($coverageAreas)): ?>
                                        <?php foreach ($coverageAreas as $area): ?>
                                            <span class="area-chip">
                                                <?php echo isText($area['area_name']); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="empty-chip">No area found under this ward.</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>
                    </div>

                    <form method="POST" action="settings.php" class="settings-card" id="passwordForm">
                        <input type="hidden" name="form_type" value="password">

                        <div class="card-title-row">
                            <div class="card-icon password-icon">
                                <i class="bi bi-lock"></i>
                            </div>

                            <div>
                                <h2>Change Password</h2>
                                <p>Use at least 8 characters for better account security.</p>
                            </div>
                        </div>

                        <div class="form-grid single">

                            <div class="form-group password-wrap">
                                <label for="current_password">Current Password</label>
                                <input
                                    type="password"
                                    id="current_password"
                                    name="current_password"
                                    autocomplete="current-password">

                                <button type="button" class="toggle-password" data-target="current_password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>

                            <div class="form-group password-wrap">
                                <label for="new_password">New Password</label>
                                <input
                                    type="password"
                                    id="new_password"
                                    name="new_password"
                                    autocomplete="new-password">

                                <button type="button" class="toggle-password" data-target="new_password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>

                            <div class="form-group password-wrap">
                                <label for="confirm_password">Confirm New Password</label>
                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    autocomplete="new-password">

                                <button type="button" class="toggle-password" data-target="confirm_password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>

                        </div>

                        <button type="submit" class="warning-btn">
                            <i class="bi bi-shield-lock"></i>
                            Change Password
                        </button>
                    </form>

                    <form method="POST" action="settings.php" class="settings-card" id="notificationForm">
                        <input type="hidden" name="form_type" value="notifications">

                        <div class="card-title-row">
                            <div class="card-icon notification-icon">
                                <i class="bi bi-bell"></i>
                            </div>

                            <div>
                                <h2>Notification Preferences</h2>
                                <p>Control what type of alert should be active for your inspector account.</p>
                            </div>
                        </div>

                        <div class="preference-list">

                            <label class="preference-item">
                                <input
                                    type="checkbox"
                                    name="email_alerts"
                                    <?php echo ((int) $settings['email_alerts'] === 1) ? 'checked' : ''; ?>>
                                <span>Email alerts for new inspection requests</span>
                            </label>

                            <label class="preference-item">
                                <input
                                    type="checkbox"
                                    name="sms_alerts"
                                    <?php echo ((int) $settings['sms_alerts'] === 1) ? 'checked' : ''; ?>>
                                <span>SMS notifications for urgent cases</span>
                            </label>

                            <label class="preference-item">
                                <input
                                    type="checkbox"
                                    name="push_alerts"
                                    <?php echo ((int) $settings['push_alerts'] === 1) ? 'checked' : ''; ?>>
                                <span>Push notifications for citizen objections</span>
                            </label>

                            <label class="preference-item">
                                <input
                                    type="checkbox"
                                    name="daily_summary"
                                    <?php echo ((int) $settings['daily_summary'] === 1) ? 'checked' : ''; ?>>
                                <span>Daily inspection summary report</span>
                            </label>

                            <label class="preference-item">
                                <input
                                    type="checkbox"
                                    name="weekly_digest"
                                    <?php echo ((int) $settings['weekly_digest'] === 1) ? 'checked' : ''; ?>>
                                <span>Weekly performance digest</span>
                            </label>

                            <label class="preference-item">
                                <input
                                    type="checkbox"
                                    name="false_completion_alerts"
                                    <?php echo ((int) $settings['false_completion_alerts'] === 1) ? 'checked' : ''; ?>>
                                <span>False completion report alerts</span>
                            </label>

                        </div>

                        <button type="submit" class="primary-btn">
                            <i class="bi bi-save"></i>
                            Save Preferences
                        </button>
                    </form>

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
    <script src="../../js/inspector/settings.js"></script>

</body>

</html>