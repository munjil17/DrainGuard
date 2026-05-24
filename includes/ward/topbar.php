<?php
$userName = $_SESSION['user_name'] ?? 'Ward Officer';
$userRoleLabel = $_SESSION['user_role_label'] ?? 'Ward Officer';
$topbarProfileImage = "";

function ward_topbar_profile_image_path($path)
{
    $path = trim((string)$path);

    if ($path === "") {
        return "";
    }

    $path = str_replace("\\", "/", $path);

    if (preg_match("/^https?:\/\//i", $path)) {
        return $path;
    }

    if (str_starts_with($path, "../../")) {
        return $path;
    }

    if (str_starts_with($path, "assets/")) {
        return "../../" . $path;
    }

    if (str_starts_with($path, "uploads/")) {
        return "../../assets/" . $path;
    }

    if (!str_contains($path, "/")) {
        return "../../assets/uploads/ward_officers/" . $path;
    }

    return "../../" . ltrim($path, "/");
}

if (isset($conn) && $conn && isset($_SESSION["user_id"])) {
    $currentTopbarUserId = (int)$_SESSION["user_id"];

    $hasProfileImageColumn = false;
    $columnCheckResult = mysqli_query($conn, "SHOW COLUMNS FROM ward_officers LIKE 'profile_image'");

    if ($columnCheckResult && mysqli_num_rows($columnCheckResult) > 0) {
        $hasProfileImageColumn = true;
    }

    $profileImageSelect = $hasProfileImageColumn ? ", profile_image" : ", NULL AS profile_image";

    $topbarSql = "
        SELECT
            full_name,
            designation
            $profileImageSelect
        FROM ward_officers
        WHERE user_id = ?
        LIMIT 1
    ";

    $topbarStmt = mysqli_prepare($conn, $topbarSql);

    if ($topbarStmt) {
        mysqli_stmt_bind_param($topbarStmt, "i", $currentTopbarUserId);
        mysqli_stmt_execute($topbarStmt);

        $topbarResult = mysqli_stmt_get_result($topbarStmt);
        $topbarRow = $topbarResult ? mysqli_fetch_assoc($topbarResult) : null;

        if ($topbarRow) {
            if (!empty($topbarRow["full_name"])) {
                $userName = $topbarRow["full_name"];
            }

            if (!empty($topbarRow["designation"])) {
                $userRoleLabel = $topbarRow["designation"];
            }

            if (!empty($topbarRow["profile_image"])) {
                $topbarProfileImage = ward_topbar_profile_image_path($topbarRow["profile_image"]);
            }
        }

        mysqli_stmt_close($topbarStmt);
    }
}
?>

<header class="ward-topbar">

    <div class="topbar-left">
        <button class="mobile-toggle"
                id="mobileToggle"
                type="button"
                data-bs-toggle="offcanvas"
                data-bs-target="#sidebarOffcanvas"
                aria-controls="sidebarOffcanvas"
                aria-label="Open sidebar menu">
            <i class="bi bi-list"></i>
        </button>
    </div>

    <div class="topbar-right">
        <button class="notification-btn" type="button" aria-label="Notifications">
            <i class="bi bi-bell"></i>
            <span></span>
        </button>

        <div class="topbar-user">
            <div class="topbar-user-info">
                <h4><?php echo htmlspecialchars($userName, ENT_QUOTES, "UTF-8"); ?></h4>
                <p><?php echo htmlspecialchars($userRoleLabel, ENT_QUOTES, "UTF-8"); ?></p>
            </div>

            <a href="profile.php" class="topbar-avatar" title="Profile">
                <?php if ($topbarProfileImage !== ""): ?>
                    <img src="<?php echo htmlspecialchars($topbarProfileImage, ENT_QUOTES, "UTF-8"); ?>" alt="Ward Officer Profile Photo">
                <?php else: ?>
                    <i class="bi bi-person"></i>
                <?php endif; ?>
            </a>
        </div>
    </div>

</header>