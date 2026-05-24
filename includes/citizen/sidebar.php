<?php
// C:\xampp\htdocs\DrainGuard\includes\citizen\sidebar.php

$userName = $_SESSION['user_name'] ?? 'Citizen User';
$userRoleLabel = $_SESSION['user_role_label'] ?? 'Public Portal';
$activePage = $activePage ?? '';

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$sidebarPhotoPath = "";

function citizen_safe_text_sidebar($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function citizen_column_exists_sidebar($conn, $tableName, $columnName)
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

function citizen_get_profile_photo_sidebar($conn, $userId)
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
        if (citizen_column_exists_sidebar($conn, "citizens", $column)) {
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
| Sidebar profile photo source
|--------------------------------------------------------------------------
| 1. First use session profile photo
| 2. If session empty, fetch from citizens table
|--------------------------------------------------------------------------
*/

if (!empty($_SESSION['profile_photo'])) {
    $sidebarPhotoPath = "../../" . ltrim($_SESSION['profile_photo'], "/");
} elseif ($userId > 0 && isset($conn)) {
    $dbPhotoPath = citizen_get_profile_photo_sidebar($conn, $userId);

    if ($dbPhotoPath !== "") {
        $_SESSION['profile_photo'] = $dbPhotoPath;
        $sidebarPhotoPath = "../../" . ltrim($dbPhotoPath, "/");
    }
}

$menuItems = [
    [
        "page" => "dashboard",
        "href" => "dashboard.php",
        "icon" => "bi-grid",
        "label" => "Dashboard"
    ],
    [
        "page" => "submit-complaint",
        "href" => "submit-complaint.php",
        "icon" => "bi-plus-lg",
        "label" => "Submit Complaint"
    ],
    [
        "page" => "public-board",
        "href" => "public-board.php",
        "icon" => "bi-eye",
        "label" => "Public Complaint Board"
    ],
    [
        "page" => "my-complaints",
        "href" => "my-complaints.php",
        "icon" => "bi-file-earmark-text",
        "label" => "My Complaints"
    ],
    [
        "page" => "track-complaint",
        "href" => "track-complaint.php",
        "icon" => "bi-geo-alt",
        "label" => "Track Complaint"
    ],
    [
        "page" => "high-risk-areas",
        "href" => "high-risk-areas.php",
        "icon" => "bi-exclamation-triangle",
        "label" => "High Risk Areas"
    ],
    [
        "page" => "feedback-reopen",
        "href" => "feedback-reopen.php",
        "icon" => "bi-chat-left",
        "label" => "Feedback / Reopen"
    ],
    [
        "page" => "settings",
        "href" => "settings.php",
        "icon" => "bi-gear",
        "label" => "Settings"
    ]
];
?>

<aside class="citizen-sidebar" id="sidebar">

    <div class="sidebar-brand">
        <div class="brand-icon">
            <i class="bi bi-droplet"></i>
        </div>

        <div>
            <h2>DrainGuard</h2>
            <p>Smart Drainage</p>
        </div>
    </div>

    <div class="sidebar-role">
        <span></span>
        <small>Citizen Access</small>
        <strong>Public Portal</strong>
    </div>

    <nav class="sidebar-menu" aria-label="Citizen navigation">

        <?php foreach ($menuItems as $item): ?>
            <a 
                href="<?php echo citizen_safe_text_sidebar($item['href']); ?>" 
                class="menu-link <?php echo ($activePage === $item['page']) ? 'active' : ''; ?>"
            >
                <i class="bi <?php echo citizen_safe_text_sidebar($item['icon']); ?>"></i>
                <span><?php echo citizen_safe_text_sidebar($item['label']); ?></span>
            </a>
        <?php endforeach; ?>

    </nav>

    <div class="sidebar-user">

        <div class="user-profile-row">

            <a href="profile.php" class="user-avatar" title="View Profile">
                <?php if ($sidebarPhotoPath !== ""): ?>
                    <img 
                        src="<?php echo citizen_safe_text_sidebar($sidebarPhotoPath); ?>" 
                        alt="Profile Photo"
                        loading="lazy"
                    >
                <?php else: ?>
                    <i class="bi bi-person"></i>
                <?php endif; ?>
            </a>

            <div class="user-info">
                <h4><?php echo citizen_safe_text_sidebar($userName); ?></h4>
                <p><?php echo citizen_safe_text_sidebar($userRoleLabel); ?></p>
            </div>

        </div>

        <div class="user-actions">

            <a href="profile.php" class="profile-btn">
                <i class="bi bi-person"></i>
                <span>Profile</span>
            </a>

            <a href="../../auth/logout.php" class="logout-btn">
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>

        </div>

    </div>

</aside>