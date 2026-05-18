<?php
$topbarUserName = $_SESSION['user_name'] ?? 'Citizen User';
$topbarRoleLabel = $_SESSION['user_role_label'] ?? 'Public Portal';
$topbarUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

$topbarPhotoPath = "";

/*
    Profile photo load safely.
    It checks which photo column exists in citizens table.
    Supported columns:
    profile_photo, profile_image, citizen_photo, citizen_image, photo, image
*/

if ($topbarUserId > 0 && isset($conn)) {
    $photoColumnCandidates = [
        "profile_photo",
        "profile_image",
        "citizen_photo",
        "citizen_image",
        "photo",
        "image"
    ];

    $foundPhotoColumn = "";

    foreach ($photoColumnCandidates as $candidateColumn) {
        $columnCheckSql = "
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'citizens'
            AND COLUMN_NAME = ?
            LIMIT 1
        ";

        $columnCheckStmt = mysqli_prepare($conn, $columnCheckSql);

        if ($columnCheckStmt) {
            mysqli_stmt_bind_param($columnCheckStmt, "s", $candidateColumn);
            mysqli_stmt_execute($columnCheckStmt);

            $columnCheckResult = mysqli_stmt_get_result($columnCheckStmt);

            if ($columnCheckResult && mysqli_num_rows($columnCheckResult) > 0) {
                $foundPhotoColumn = $candidateColumn;
                mysqli_stmt_close($columnCheckStmt);
                break;
            }

            mysqli_stmt_close($columnCheckStmt);
        }
    }

    if ($foundPhotoColumn !== "") {
        $photoSql = "
            SELECT {$foundPhotoColumn} AS profile_photo
            FROM citizens
            WHERE user_id = ?
            LIMIT 1
        ";

        $photoStmt = mysqli_prepare($conn, $photoSql);

        if ($photoStmt) {
            mysqli_stmt_bind_param($photoStmt, "i", $topbarUserId);
            mysqli_stmt_execute($photoStmt);

            $photoResult = mysqli_stmt_get_result($photoStmt);

            if ($photoResult && mysqli_num_rows($photoResult) === 1) {
                $photoRow = mysqli_fetch_assoc($photoResult);
                $dbPhotoPath = trim((string)($photoRow['profile_photo'] ?? ''));

                if ($dbPhotoPath !== "") {
                    $topbarPhotoPath = "../../" . $dbPhotoPath;
                }
            }

            mysqli_stmt_close($photoStmt);
        }
    }
}
?>

<header class="citizen-topbar">

    <div class="topbar-left">
        <button class="mobile-toggle" id="mobileToggle" type="button" aria-label="Open sidebar">
            <i class="bi bi-list"></i>
        </button>

        <div class="topbar-page-title">
            <h3><?php echo htmlspecialchars($pageTitle ?? 'Citizen Panel', ENT_QUOTES, 'UTF-8'); ?></h3>
            <p>
                <?php echo htmlspecialchars($pageParent ?? 'Citizen', ENT_QUOTES, 'UTF-8'); ?>
                <?php if (!empty($pageChild)): ?>
                    <span>/</span>
                    <?php echo htmlspecialchars($pageChild, ENT_QUOTES, 'UTF-8'); ?>
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
                <h4><?php echo htmlspecialchars($topbarUserName, ENT_QUOTES, 'UTF-8'); ?></h4>
                <p><?php echo htmlspecialchars($topbarRoleLabel, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <a href="profile.php" class="topbar-avatar" title="View Profile">
                <?php if ($topbarPhotoPath !== ""): ?>
                    <img src="<?php echo htmlspecialchars($topbarPhotoPath, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile Photo">
                <?php else: ?>
                    <i class="bi bi-person"></i>
                <?php endif; ?>
            </a>
        </div>

    </div>

</header>