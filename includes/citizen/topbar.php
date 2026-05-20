<?php
// C:\xampp\htdocs\DrainGuard\includes\citizen\topbar.php

$topbarUserName = $_SESSION['user_name'] ?? 'Citizen User';
$topbarRoleLabel = $_SESSION['user_role_label'] ?? 'Public Portal';
$topbarUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

$topbarPhotoPath = "";

function citizen_safe_text_topbar($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function citizen_column_exists_topbar($conn, $tableName, $columnName)
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ss", $tableName, $columnName);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return ((int)($row['total'] ?? 0)) > 0;
}

function citizen_get_profile_photo_topbar($conn, $userId)
{
    if ($userId <= 0 || !$conn) {
        return "";
    }

    $photoColumns = [
        "profile_photo",
        "profile_image",
        "citizen_photo",
        "citizen_image",
        "photo",
        "image"
    ];

    $foundPhotoColumn = "";

    foreach ($photoColumns as $column) {
        if (citizen_column_exists_topbar($conn, "citizens", $column)) {
            $foundPhotoColumn = $column;
            break;
        }
    }

    if ($foundPhotoColumn === "") {
        return "";
    }

    $sql = "
        SELECT `$foundPhotoColumn` AS profile_photo
        FROM citizens
        WHERE user_id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return "";
    }

    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $photoPath = "";

    if ($result && mysqli_num_rows($result) === 1) {
        $row = mysqli_fetch_assoc($result);
        $photoPath = trim((string)($row['profile_photo'] ?? ""));
    }

    mysqli_stmt_close($stmt);

    return $photoPath;
}

/*
|--------------------------------------------------------------------------
| Topbar profile photo source
|--------------------------------------------------------------------------
| 1. First use session profile photo
| 2. If session empty, fetch from citizens table
|--------------------------------------------------------------------------
*/

if (!empty($_SESSION['profile_photo'])) {
    $topbarPhotoPath = "../../" . ltrim($_SESSION['profile_photo'], "/");
} elseif ($topbarUserId > 0 && isset($conn)) {
    $dbPhotoPath = citizen_get_profile_photo_topbar($conn, $topbarUserId);

    if ($dbPhotoPath !== "") {
        $_SESSION['profile_photo'] = $dbPhotoPath;
        $topbarPhotoPath = "../../" . ltrim($dbPhotoPath, "/");
    }
}
?>

<header class="citizen-topbar">

    <div class="topbar-left">
        <button 
            class="mobile-toggle" 
            id="mobileToggle" 
            type="button" 
            aria-label="Open sidebar"
            aria-controls="sidebar"
            aria-expanded="false"
        >
            <i class="bi bi-list"></i>
        </button>

        <div class="topbar-page-title">
            <h3><?php echo citizen_safe_text_topbar($pageTitle ?? 'Citizen Panel'); ?></h3>

            <p>
                <?php echo citizen_safe_text_topbar($pageParent ?? 'Citizen'); ?>

                <?php if (!empty($pageChild)): ?>
                    <span>/</span>
                    <?php echo citizen_safe_text_topbar($pageChild); ?>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="topbar-right">

        <button class="notification-btn" type="button" aria-label="Notifications">
            <i class="bi bi-bell"></i>
            <span></span>
        </button>

        <div class="topbar-user">
            <div>
                <h4><?php echo citizen_safe_text_topbar($topbarUserName); ?></h4>
                <p><?php echo citizen_safe_text_topbar($topbarRoleLabel); ?></p>
            </div>

            <a href="profile.php" class="topbar-avatar" title="View Profile">
                <?php if ($topbarPhotoPath !== ""): ?>
                    <img 
                        src="<?php echo citizen_safe_text_topbar($topbarPhotoPath); ?>" 
                        alt="Profile Photo"
                        loading="lazy"
                    >
                <?php else: ?>
                    <i class="bi bi-person"></i>
                <?php endif; ?>
            </a>
        </div>

    </div>

</header>