<?php
if (!function_exists('dg_maintenance_deny_access')) {
    function dg_maintenance_deny_access($message = 'Maintenance access is not available.')
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $isAjax = (
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
            || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        );

        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $message
            ]);
            exit();
        }

        header('Location: /DrainGuard/auth/login.php');
        exit();
    }
}

if (!isset($conn) || !$conn) {
    dg_maintenance_deny_access('Service is temporarily unavailable. Please try again.');
}

$dgMaintenanceUserId = (int)($_SESSION['user_id'] ?? 0);
$dgMaintenanceSessionRole = (string)($_SESSION['user_role'] ?? '');
$dgMaintenanceAllowedUserRoles = ['maintenance_team', 'maintenance_member', 'team_leader', 'assistant_team_leader'];

if ($dgMaintenanceUserId <= 0 || !in_array($dgMaintenanceSessionRole, $dgMaintenanceAllowedUserRoles, true)) {
    dg_maintenance_deny_access();
}

$dgMaintenanceAccessSql = "
    SELECT
        u.user_id,
        u.user_role,
        u.user_status,
        u.login_access,
        mtm.member_id,
        mtm.maintenance_team_id,
        mtm.role AS member_role,
        mtm.status AS member_status,
        mt.assistant_login_access
    FROM users u
    INNER JOIN maintenance_team_members mtm
        ON mtm.user_id = u.user_id
    INNER JOIN maintenance_teams mt
        ON mt.maintenance_team_id = mtm.maintenance_team_id
    WHERE u.user_id = ?
    LIMIT 1
";

$dgMaintenanceAccessStmt = mysqli_prepare($conn, $dgMaintenanceAccessSql);

if (!$dgMaintenanceAccessStmt) {
    dg_maintenance_deny_access('Service is temporarily unavailable. Please try again.');
}

mysqli_stmt_bind_param($dgMaintenanceAccessStmt, 'i', $dgMaintenanceUserId);
mysqli_stmt_execute($dgMaintenanceAccessStmt);
$dgMaintenanceAccessResult = mysqli_stmt_get_result($dgMaintenanceAccessStmt);
$dgMaintenanceAccess = $dgMaintenanceAccessResult ? mysqli_fetch_assoc($dgMaintenanceAccessResult) : null;
mysqli_stmt_close($dgMaintenanceAccessStmt);

if (!$dgMaintenanceAccess) {
    dg_maintenance_deny_access();
}

$dgUserRole = (string)$dgMaintenanceAccess['user_role'];
$dgUserStatus = strtolower(trim((string)$dgMaintenanceAccess['user_status']));
$dgLoginAccess = (int)$dgMaintenanceAccess['login_access'];
$dgMemberRole = strtolower(trim((string)$dgMaintenanceAccess['member_role']));
$dgMemberStatus = strtolower(trim((string)$dgMaintenanceAccess['member_status']));
$dgAssistantLoginAccess = strtolower(trim((string)($dgMaintenanceAccess['assistant_login_access'] ?? 'no')));

if (!in_array($dgUserRole, $dgMaintenanceAllowedUserRoles, true) || $dgUserStatus !== 'active' || $dgLoginAccess !== 1) {
    dg_maintenance_deny_access('Your maintenance access has been disabled. Please contact your team leader.');
}

if ($dgMemberStatus !== 'active') {
    dg_maintenance_deny_access('Your maintenance team membership is inactive.');
}

$dgIsTeamLeader = (
    $dgMemberRole === 'team_leader'
    && in_array($dgUserRole, ['maintenance_team', 'maintenance_member', 'team_leader'], true)
);

$dgIsActingAssistantTeamLeader = (
    $dgMemberRole === 'assistant_team_leader'
    && in_array($dgUserRole, ['maintenance_member', 'assistant_team_leader'], true)
    && $dgAssistantLoginAccess === 'yes'
);

if (!$dgIsTeamLeader && !$dgIsActingAssistantTeamLeader) {
    dg_maintenance_deny_access('You are not allowed to access the Maintenance panel.');
}

$maintenanceAccessContext = [
    'user_id' => $dgMaintenanceUserId,
    'maintenance_team_id' => (int)$dgMaintenanceAccess['maintenance_team_id'],
    'member_id' => (int)$dgMaintenanceAccess['member_id'],
    'member_role' => $dgMemberRole,
    'is_team_leader' => $dgIsTeamLeader,
    'is_acting_assistant_team_leader' => $dgIsActingAssistantTeamLeader
];
