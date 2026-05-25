<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = $_SESSION['user_id'] ?? null;

$topbarName = $_SESSION['user_name'] ?? 'Maintenance User';
$topbarRole = 'Maintenance Team';
$topbarTeam = 'Maintenance Unit';
$topbarProfileImage = '';

if (isset($conn) && $userId) {
    $topbarSql = "
        SELECT 
            u.user_name,
            u.user_role,
            mtm.full_name,
            mtm.role AS member_role,
            mtm.profile_image,
            mt.team_name
        FROM users u
        LEFT JOIN maintenance_team_members mtm 
            ON mtm.user_id = u.user_id
        LEFT JOIN maintenance_teams mt 
            ON mt.maintenance_team_id = mtm.maintenance_team_id
        WHERE u.user_id = ?
        LIMIT 1
    ";

    $topbarStmt = mysqli_prepare($conn, $topbarSql);

    if ($topbarStmt) {
        mysqli_stmt_bind_param($topbarStmt, "i", $userId);
        mysqli_stmt_execute($topbarStmt);
        $topbarResult = mysqli_stmt_get_result($topbarStmt);

        if ($topbarResult && mysqli_num_rows($topbarResult) > 0) {
            $topbarUser = mysqli_fetch_assoc($topbarResult);

            $topbarName = !empty($topbarUser['full_name'])
                ? $topbarUser['full_name']
                : ($topbarUser['user_name'] ?? $topbarName);

            $topbarTeam = !empty($topbarUser['team_name'])
                ? $topbarUser['team_name']
                : $topbarTeam;

            $topbarProfileImage = !empty($topbarUser['profile_image'])
                ? "../../" . $topbarUser['profile_image']
                : '';

            if (!empty($topbarUser['member_role'])) {
                if ($topbarUser['member_role'] === 'team_leader') {
                    $topbarRole = 'Team Leader';
                } elseif ($topbarUser['member_role'] === 'assistant_team_leader') {
                    $topbarRole = 'Assistant Team Leader';
                } elseif ($topbarUser['member_role'] === 'worker') {
                    $topbarRole = 'Worker';
                }
            }
        }

        mysqli_stmt_close($topbarStmt);
    }
}

$pageTitle = $pageTitle ?? 'Maintenance Panel';
?>

<header class="topbar">
    <div class="topbar-left">
        <div>
            <h3><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
            <p><?php echo htmlspecialchars($topbarTeam, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </div>

    <div class="topbar-right">
        <button type="button" class="notification-btn" aria-label="Notifications">
            <i class="bi bi-bell"></i>
            <span></span>
        </button>

        <div class="topbar-user">
            <div class="topbar-user-text">
                <h4><?php echo htmlspecialchars($topbarName, ENT_QUOTES, 'UTF-8'); ?></h4>
                <p><?php echo htmlspecialchars($topbarRole, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="topbar-avatar">
                <?php if (!empty($topbarProfileImage)): ?>
                    <img src="<?php echo htmlspecialchars($topbarProfileImage, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile">
                <?php else: ?>
                    <i class="bi bi-person-gear"></i>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>