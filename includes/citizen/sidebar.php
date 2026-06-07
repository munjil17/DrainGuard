<?php
// C:\xampp\htdocs\DrainGuard\includes\citizen\sidebar.php

$activePage    = $activePage ?? '';
$userId        = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$userName      = $_SESSION['user_name'] ?? 'Citizen User';
$userRoleLabel = 'Citizen';

/* -------------------------------------------------------
   HELPER: safe output
------------------------------------------------------- */
function citizen_safe_sidebar($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* -------------------------------------------------------
   PROFILE PHOTO + FULL NAME
   — Session cache check, না থাকলে DB থেকে আনে
------------------------------------------------------- */
$sidebarPhotoPath = '';

if ($userId > 0 && isset($conn)) {

    if (empty($_SESSION['profile_photo']) || empty($_SESSION['citizen_full_name'])) {

        $stmt = mysqli_prepare($conn,
            "SELECT full_name, profile_photo
             FROM citizens
             WHERE user_id = ?
             LIMIT 1"
        );

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $userId);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if ($row = mysqli_fetch_assoc($result)) {
                if (!empty($row['full_name'])) {
                    $_SESSION['citizen_full_name'] = $row['full_name'];
                    $_SESSION['user_name']         = $row['full_name'];
                    $userName                      = $row['full_name'];
                }
                if (!empty($row['profile_photo'])) {
                    $_SESSION['profile_photo'] = $row['profile_photo'];
                }
            }

            mysqli_stmt_close($stmt);
        }

    } else {
        $userName = $_SESSION['citizen_full_name'];
    }

    if (!empty($_SESSION['profile_photo'])) {
        $sidebarPhotoPath = '../../' . ltrim($_SESSION['profile_photo'], '/');
    }
}

/* -------------------------------------------------------
   MENU ITEMS
------------------------------------------------------- */
$menuItems = [
    ['page' => 'dashboard',        'href' => 'dashboard.php',        'icon' => 'bi-grid',                 'label' => 'Dashboard'],
    ['page' => 'submit-complaint', 'href' => 'submit-complaint.php', 'icon' => 'bi-plus-lg',              'label' => 'Submit Complaint'],
    ['page' => 'public-board',     'href' => 'public-board.php',     'icon' => 'bi-eye',                  'label' => 'Public Complaint Board'],
    ['page' => 'my-complaints',    'href' => 'my-complaints.php',    'icon' => 'bi-file-earmark-text',    'label' => 'My Complaints'],
    ['page' => 'track-complaint',  'href' => 'track-complaint.php',  'icon' => 'bi-geo-alt',              'label' => 'Track Complaint'],
    ['page' => 'high-risk-areas',  'href' => 'high-risk-areas.php',  'icon' => 'bi-exclamation-triangle', 'label' => 'Risk Areas'],
    ['page' => 'feedback-reopen',  'href' => 'feedback-reopen.php',  'icon' => 'bi-chat-left',            'label' => 'Feedback'],
    ['page' => 'citizen-objection','href' => 'citizen-objection.php','icon' => 'bi-exclamation-diamond',  'label' => 'Citizen Objection'],
];
?>

<aside class="citizen-sidebar" id="sidebar" aria-label="Citizen Sidebar">

    <!-- BRAND -->
    <div class="sidebar-brand">
        <div class="brand-icon">
            <i class="bi bi-droplet"></i>
        </div>
        <div>
            <h2>DrainGuard</h2>
            <p>Smart Drainage</p>
        </div>
    </div>

    <!-- ROLE BADGE -->
    <div class="sidebar-role">
        <span></span>
        <small>Citizen Access</small>
        <strong>Citizen</strong>
    </div>

    <!-- NAVIGATION MENU -->
    <nav class="sidebar-menu" aria-label="Citizen navigation">
        <?php foreach ($menuItems as $item): ?>
            <a
                href="<?php echo citizen_safe_sidebar($item['href']); ?>"
                class="menu-link <?php echo ($activePage === $item['page']) ? 'active' : ''; ?>"
                aria-current="<?php echo ($activePage === $item['page']) ? 'page' : 'false'; ?>"
            >
                <i class="bi <?php echo citizen_safe_sidebar($item['icon']); ?>"></i>
                <span><?php echo citizen_safe_sidebar($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- USER SECTION -->
    <div class="sidebar-user">
        <div class="user-profile-row">
            <a href="profile.php" class="user-avatar" title="View Profile">
                <?php if ($sidebarPhotoPath !== ''): ?>
                    <img
                        src="<?php echo citizen_safe_sidebar($sidebarPhotoPath); ?>"
                        alt="Profile Photo"
                        loading="lazy"
                    >
                <?php else: ?>
                    <i class="bi bi-person"></i>
                <?php endif; ?>
            </a>
            <div class="user-info">
                <h4><?php echo citizen_safe_sidebar($userName); ?></h4>
                <p><?php echo citizen_safe_sidebar($userRoleLabel); ?></p>
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

<!-- MOBILE OVERLAY -->
<div class="sidebar-overlay" id="sidebarOverlay" aria-hidden="true"></div>
