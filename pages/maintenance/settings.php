<?php
$pageTitle = "Team Profile";
$activePage = "settings";

require_once "../../config.php";
require_once "../../auth/session_check.php";

$userId = $_SESSION['user_id'] ?? 0;

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function statusClass($status)
{
    $status = strtolower((string)$status);

    if ($status === 'active') {
        return 'status-active';
    }

    return 'status-inactive';
}

$teamInfo = [
    'maintenance_team_id' => 0,
    'team_name' => 'Maintenance Team',
    'availability_status' => 'available',
    'assistant_login_access' => 'no',
    'anchal_id' => null,
    'city_cor_id' => null
];

$currentMember = [
    'full_name' => $_SESSION['user_name'] ?? 'Maintenance User',
    'role' => 'maintenance_member'
];

if ($userId > 0) {
    $teamSql = "
        SELECT
            mt.maintenance_team_id,
            mt.team_name,
            mt.city_cor_id,
            mt.anchal_id,
            mt.availability_status,
            mt.assistant_login_access,
            mtm.full_name,
            mtm.role
        FROM maintenance_team_members mtm
        INNER JOIN maintenance_teams mt
            ON mt.maintenance_team_id = mtm.maintenance_team_id
        WHERE mtm.user_id = ?
        LIMIT 1
    ";

    $teamStmt = mysqli_prepare($conn, $teamSql);

    if ($teamStmt) {
        mysqli_stmt_bind_param($teamStmt, "i", $userId);
        mysqli_stmt_execute($teamStmt);
        $teamResult = mysqli_stmt_get_result($teamStmt);

        if ($teamResult && mysqli_num_rows($teamResult) > 0) {
            $row = mysqli_fetch_assoc($teamResult);

            $teamInfo['maintenance_team_id'] = (int)$row['maintenance_team_id'];
            $teamInfo['team_name'] = $row['team_name'] ?? $teamInfo['team_name'];
            $teamInfo['city_cor_id'] = isset($row['city_cor_id']) ? (int)$row['city_cor_id'] : null;
            $teamInfo['anchal_id'] = isset($row['anchal_id']) ? (int)$row['anchal_id'] : null;
            $teamInfo['availability_status'] = $row['availability_status'] ?? 'available';
            $teamInfo['assistant_login_access'] = $row['assistant_login_access'] ?? 'no';

            $currentMember['full_name'] = $row['full_name'] ?? $currentMember['full_name'];
            $currentMember['role'] = $row['role'] ?? $currentMember['role'];
        }

        mysqli_stmt_close($teamStmt);
    }
}

$teamId = (int)$teamInfo['maintenance_team_id'];
$anchalId = !empty($teamInfo['anchal_id']) ? (int)$teamInfo['anchal_id'] : 0;
$cityCorId = !empty($teamInfo['city_cor_id']) ? (int)$teamInfo['city_cor_id'] : 0;

$teamMembers = [];
$coverageWardAreas = [];

if ($teamId > 0) {
    $membersSql = "
        SELECT
            member_id,
            full_name,
            phone_number,
            user_mail,
            employee_code,
            role,
            status
        FROM maintenance_team_members
        WHERE maintenance_team_id = ?
        ORDER BY
            CASE role
                WHEN 'team_leader' THEN 1
                WHEN 'assistant_team_leader' THEN 2
                WHEN 'worker' THEN 3
                ELSE 4
            END,
            full_name ASC
    ";

    $membersStmt = mysqli_prepare($conn, $membersSql);

    if ($membersStmt) {
        mysqli_stmt_bind_param($membersStmt, "i", $teamId);
        mysqli_stmt_execute($membersStmt);
        $membersResult = mysqli_stmt_get_result($membersStmt);

        while ($membersResult && $member = mysqli_fetch_assoc($membersResult)) {
            $teamMembers[] = $member;
        }

        mysqli_stmt_close($membersStmt);
    }
}

/*
    Coverage Wards and Areas:
    Team's anchal_id -> all wards under that anchal -> all areas under those wards.
*/
if ($anchalId > 0 && $cityCorId > 0) {
    $coverageSql = "
        SELECT
            w.ward_id,
            w.ward_no,
            w.ward_name,
            a.area_id,
            a.area_name
        FROM wards w
        LEFT JOIN areas a
            ON a.ward_id = w.ward_id
        WHERE w.anchal_id = ?
        AND w.city_cor_id = ?
        ORDER BY w.ward_no ASC, a.area_name ASC
    ";

    $coverageStmt = mysqli_prepare($conn, $coverageSql);

    if ($coverageStmt) {
        mysqli_stmt_bind_param($coverageStmt, "ii", $anchalId, $cityCorId);
        mysqli_stmt_execute($coverageStmt);
        $coverageResult = mysqli_stmt_get_result($coverageStmt);

        while ($coverageResult && $row = mysqli_fetch_assoc($coverageResult)) {
            $wardId = (int)$row['ward_id'];

            if (!isset($coverageWardAreas[$wardId])) {
                $wardLabel = 'Ward not found';

                if (!empty($row['ward_no'])) {
                    $wardLabel = 'Ward ' . $row['ward_no'];
                } elseif (!empty($row['ward_name'])) {
                    $wardLabel = $row['ward_name'];
                }

                $coverageWardAreas[$wardId] = [
                    'ward_label' => $wardLabel,
                    'areas' => []
                ];
            }

            if (!empty($row['area_id']) && !empty($row['area_name'])) {
                $coverageWardAreas[$wardId]['areas'][] = $row['area_name'];
            }
        }

        mysqli_stmt_close($coverageStmt);
    }
}

$teamCode = $teamId > 0
    ? 'TEAM-' . str_pad((string)$teamId, 3, '0', STR_PAD_LEFT)
    : 'TEAM-000';

$availabilityLabel = ucfirst((string)$teamInfo['availability_status']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Team Profile | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/maintenance/sidebar.css">
    <link rel="stylesheet" href="../../css/maintenance/topbar.css">
    <link rel="stylesheet" href="../../css/maintenance/settings.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="maintenance">
    <div class="maintenance-layout">
        <?php include "../../includes/maintenance/sidebar.php"; ?>

        <main class="maintenance-main">
            <?php include "../../includes/maintenance/topbar.php"; ?>

            <section class="settings-page">
                <div class="page-heading">
                    <h1>Team Profile</h1>
                    <p>Manage team and member profiles</p>
                </div>

                <div class="settings-card">
                    <div class="section-head">
                        <div class="section-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <h2>Team Information</h2>
                    </div>

                    <div class="team-info-grid">
                        <div class="info-field">
                            <label>Team Name</label>
                            <div class="readonly-field"><?php echo e($teamInfo['team_name']); ?></div>
                        </div>

                        <div class="info-field">
                            <label>Team ID</label>
                            <div class="readonly-field"><?php echo e($teamCode); ?></div>
                        </div>

                        <div class="info-field">
                            <label>Team Availability</label>
                            <div class="readonly-field">
                                <span class="availability-pill <?php echo strtolower($teamInfo['availability_status']) === 'busy' ? 'busy' : 'available'; ?>">
                                    <?php echo e($availabilityLabel); ?>
                                </span>
                            </div>
                        </div>

                        <div class="info-field full-width">
                            <label>Coverage Wards and Areas</label>

                            <div class="coverage-group-box">
                                <?php if (count($coverageWardAreas) > 0): ?>
                                    <?php foreach ($coverageWardAreas as $coverage): ?>
                                        <div class="ward-area-group">
                                            <div class="ward-chip">
                                                <i class="bi bi-geo-alt"></i>
                                                <?php echo e($coverage['ward_label']); ?>
                                            </div>

                                            <div class="area-chip-row">
                                                <?php if (count($coverage['areas']) > 0): ?>
                                                    <?php foreach ($coverage['areas'] as $areaName): ?>
                                                        <span class="area-chip"><?php echo e($areaName); ?></span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="muted-text">No area found under this ward.</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="muted-text">No ward or area found under this team anchal.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="settings-card">
                    <div class="section-head">
                        <div class="section-icon blue-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <h2>Team Members</h2>
                    </div>

                    <div class="member-list">
                        <?php if (count($teamMembers) > 0): ?>
                            <?php foreach ($teamMembers as $member): ?>
                                <div class="member-row">
                                    <div class="member-left">
                                        <div class="member-avatar">
                                            <i class="bi bi-person"></i>
                                        </div>

                                        <div>
                                            <h3><?php echo e($member['full_name']); ?></h3>
                                            <p>
                                                <?php echo e(roleLabel($member['role'])); ?>

                                                <?php if (!empty($member['phone_number'])): ?>
                                                    <span>•</span>
                                                    <?php echo e($member['phone_number']); ?>
                                                <?php endif; ?>
                                            </p>

                                            <?php if (!empty($member['employee_code'])): ?>
                                                <small><?php echo e($member['employee_code']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <span class="member-status <?php echo e(statusClass($member['status'])); ?>">
                                        <?php echo e(ucfirst($member['status'])); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state small-empty">
                                <i class="bi bi-person-x"></i>
                                <h3>No team member found</h3>
                                <p>Team members will appear here after registration.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="settings-card">
                    <div class="section-head">
                        <div class="section-icon bell-icon">
                            <i class="bi bi-bell"></i>
                        </div>
                        <h2>Notification Preferences</h2>
                    </div>

                    <div class="notification-list">
                        <label class="notification-option">
                            <input type="checkbox" checked>
                            <span>SMS alerts for new task assignments</span>
                        </label>

                        <label class="notification-option">
                            <input type="checkbox" checked>
                            <span>Push notifications for urgent tasks</span>
                        </label>

                        <label class="notification-option">
                            <input type="checkbox" checked>
                            <span>Email notifications for deadline reminders</span>
                        </label>

                        <label class="notification-option">
                            <input type="checkbox" checked>
                            <span>Daily task summary</span>
                        </label>

                        <label class="notification-option">
                            <input type="checkbox" checked>
                            <span>Team coordination updates</span>
                        </label>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script src="../../js/maintenance/sidebar.js"></script>
    <script src="../../js/maintenance/settings.js"></script>
<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>