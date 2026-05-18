<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "user-management";
$pageTitle = "User Management";
$pageParent = "Central Control";
$pageChild = "User Management";

$centralBaseUrl = "/DrainGuard/pages/central";

$successMessage = "";
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

function formatRoleName($role)
{
    return ucwords(str_replace("_", " ", $role));
}

function formatStatusName($status)
{
    return ucfirst(strtolower($status));
}

function formatLastActive($lastActive)
{
    if (empty($lastActive)) {
        return "Not tracked";
    }

    return date("d M Y, h:i A", strtotime($lastActive));
}

function tableExists($conn, $tableName)
{
    $sql = "
        SELECT TABLE_NAME
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "s", $tableName);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $exists = ($result && mysqli_num_rows($result) > 0);

    mysqli_stmt_close($stmt);

    return $exists;
}

function columnExists($conn, $tableName, $columnName)
{
    $sql = "
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ss", $tableName, $columnName);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $exists = ($result && mysqli_num_rows($result) > 0);

    mysqli_stmt_close($stmt);

    return $exists;
}

function redirectUserManagement()
{
    header("Location: /DrainGuard/pages/central/user-management.php");
    exit();
}

function setUserManagementFlash($type, $message)
{
    if ($type === "success") {
        $_SESSION["user_management_success"] = $message;
    } else {
        $_SESSION["user_management_error"] = $message;
    }

    redirectUserManagement();
}

/*
|--------------------------------------------------------------------------
| Assigned Area / Team Display Logic
|--------------------------------------------------------------------------
| Inspector              => Assigned Ward
| Ward Officer           => Assigned Ward
| Team Leader            => Team Name
| Assistant Team Leader  => Team Name
|--------------------------------------------------------------------------
*/

function getAssignedInfo($conn, $userId, $role)
{
    $userId = (int)$userId;

    if ($userId <= 0) {
        return "Not assigned";
    }

    /*
    |--------------------------------------------------------------------------
    | Team Leader / Assistant Team Leader
    | Show Maintenance Team Name
    |--------------------------------------------------------------------------
    */

    if (in_array($role, ["team_leader", "assistant_team_leader", "maintenance_team", "maintenance_member"], true)) {
        if (!tableExists($conn, "maintenance_team_members") || !tableExists($conn, "maintenance_teams")) {
            return "Not assigned";
        }

        $sql = "
            SELECT 
                mt.team_name
            FROM maintenance_team_members mtm
            LEFT JOIN maintenance_teams mt
                ON mtm.maintenance_team_id = mt.maintenance_team_id
            WHERE mtm.user_id = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            return "Not assigned";
        }

        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;

        mysqli_stmt_close($stmt);

        if (!$row || empty($row["team_name"])) {
            return "Not assigned";
        }

        return $row["team_name"];
    }

    /*
    |--------------------------------------------------------------------------
    | Ward Officer
    | Show Assigned Ward
    |--------------------------------------------------------------------------
    */

    if ($role === "ward_officer") {
        if (!tableExists($conn, "ward_officers")) {
            return "Not assigned";
        }

        $selectParts = [];
        $joinParts = "";

        if (columnExists($conn, "ward_officers", "assigned_ward_no")) {
            $selectParts[] = "wo.assigned_ward_no";
        } else {
            $selectParts[] = "NULL AS assigned_ward_no";
        }

        if (columnExists($conn, "ward_officers", "ward_id") && tableExists($conn, "wards")) {
            $selectParts[] = "w.ward_no";
            $selectParts[] = "w.ward_name";
            $joinParts .= " LEFT JOIN wards w ON wo.ward_id = w.ward_id ";
        } elseif (columnExists($conn, "ward_officers", "assigned_ward_id") && tableExists($conn, "wards")) {
            $selectParts[] = "w.ward_no";
            $selectParts[] = "w.ward_name";
            $joinParts .= " LEFT JOIN wards w ON wo.assigned_ward_id = w.ward_id ";
        } else {
            $selectParts[] = "NULL AS ward_no";
            $selectParts[] = "NULL AS ward_name";
        }

        $selectSql = implode(", ", $selectParts);

        $sql = "
            SELECT {$selectSql}
            FROM ward_officers wo
            {$joinParts}
            WHERE wo.user_id = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            return "Not assigned";
        }

        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;

        mysqli_stmt_close($stmt);

        if (!$row) {
            return "Not assigned";
        }

        if (!empty($row["assigned_ward_no"])) {
            return "Ward " . $row["assigned_ward_no"];
        }

        if (!empty($row["ward_no"])) {
            $wardText = "Ward " . $row["ward_no"];

            if (!empty($row["ward_name"])) {
                $wardText .= " - " . $row["ward_name"];
            }

            return $wardText;
        }

        return "Not assigned";
    }

    /*
    |--------------------------------------------------------------------------
    | Inspector
    | Show Assigned Ward
    |--------------------------------------------------------------------------
    */

    if ($role === "inspector") {
        if (!tableExists($conn, "inspectors")) {
            return "Not assigned";
        }

        $selectParts = [];
        $joinParts = "";

        if (columnExists($conn, "inspectors", "assigned_ward_no")) {
            $selectParts[] = "i.assigned_ward_no";
        } else {
            $selectParts[] = "NULL AS assigned_ward_no";
        }

        if (columnExists($conn, "inspectors", "ward_id") && tableExists($conn, "wards")) {
            $selectParts[] = "w.ward_no";
            $selectParts[] = "w.ward_name";
            $joinParts .= " LEFT JOIN wards w ON i.ward_id = w.ward_id ";
        } elseif (columnExists($conn, "inspectors", "assigned_ward_id") && tableExists($conn, "wards")) {
            $selectParts[] = "w.ward_no";
            $selectParts[] = "w.ward_name";
            $joinParts .= " LEFT JOIN wards w ON i.assigned_ward_id = w.ward_id ";
        } else {
            $selectParts[] = "NULL AS ward_no";
            $selectParts[] = "NULL AS ward_name";
        }

        $selectSql = implode(", ", $selectParts);

        $sql = "
            SELECT {$selectSql}
            FROM inspectors i
            {$joinParts}
            WHERE i.user_id = ?
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            return "Not assigned";
        }

        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;

        mysqli_stmt_close($stmt);

        if (!$row) {
            return "Not assigned";
        }

        if (!empty($row["assigned_ward_no"])) {
            return "Ward " . $row["assigned_ward_no"];
        }

        if (!empty($row["ward_no"])) {
            $wardText = "Ward " . $row["ward_no"];

            if (!empty($row["ward_name"])) {
                $wardText .= " - " . $row["ward_name"];
            }

            return $wardText;
        }

        return "Not assigned";
    }

    return "Not assigned";
}

/*
|--------------------------------------------------------------------------
| Flash Messages
|--------------------------------------------------------------------------
*/

if (isset($_SESSION["user_management_success"])) {
    $successMessage = $_SESSION["user_management_success"];
    unset($_SESSION["user_management_success"]);
}

if (isset($_SESSION["user_management_error"])) {
    $errorMessage = $_SESSION["user_management_error"];
    unset($_SESSION["user_management_error"]);
}

/*
|--------------------------------------------------------------------------
| Delete User
|--------------------------------------------------------------------------
| Deletes from:
| 1. related role table
| 2. users table
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_user_id"])) {

    $deleteUserId = (int) $_POST["delete_user_id"];

    if ($deleteUserId <= 0) {
        setUserManagementFlash("error", "Invalid user selected.");
    }

    $userSql = "
        SELECT user_id, user_name, user_role
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ";

    $userStmt = mysqli_prepare($conn, $userSql);

    if (!$userStmt) {
        setUserManagementFlash("error", "Database error while finding user.");
    }

    mysqli_stmt_bind_param($userStmt, "i", $deleteUserId);
    mysqli_stmt_execute($userStmt);

    $userResult = mysqli_stmt_get_result($userStmt);
    $deleteUser = mysqli_fetch_assoc($userResult);

    mysqli_stmt_close($userStmt);

    if (!$deleteUser) {
        setUserManagementFlash("error", "User not found.");
    }

    $deleteRole = $deleteUser["user_role"];

    $allowedDeleteRoles = [
        "inspector",
        "ward_officer",
        "team_leader",
        "assistant_team_leader",
        "maintenance_team",
        "maintenance_member"
    ];

    if (!in_array($deleteRole, $allowedDeleteRoles, true)) {
        setUserManagementFlash("error", "This user type cannot be deleted from this panel.");
    }

    mysqli_begin_transaction($conn);

    try {
        $deleteOwnSql = "";

        if ($deleteRole === "inspector") {
            if (tableExists($conn, "inspectors") && columnExists($conn, "inspectors", "user_id")) {
                $deleteOwnSql = "DELETE FROM inspectors WHERE user_id = ?";
            }
        } elseif ($deleteRole === "ward_officer") {
            if (tableExists($conn, "ward_officers") && columnExists($conn, "ward_officers", "user_id")) {
                $deleteOwnSql = "DELETE FROM ward_officers WHERE user_id = ?";
            }
        } else {
            if (tableExists($conn, "maintenance_team_members") && columnExists($conn, "maintenance_team_members", "user_id")) {
                $deleteOwnSql = "DELETE FROM maintenance_team_members WHERE user_id = ?";
            }
        }

        if ($deleteOwnSql !== "") {
            $deleteOwnStmt = mysqli_prepare($conn, $deleteOwnSql);

            if (!$deleteOwnStmt) {
                throw new Exception("Related table delete preparation failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($deleteOwnStmt, "i", $deleteUserId);

            if (!mysqli_stmt_execute($deleteOwnStmt)) {
                throw new Exception("Related table delete failed: " . mysqli_stmt_error($deleteOwnStmt));
            }

            mysqli_stmt_close($deleteOwnStmt);
        }

        $deleteUserSql = "DELETE FROM users WHERE user_id = ?";
        $deleteUserStmt = mysqli_prepare($conn, $deleteUserSql);

        if (!$deleteUserStmt) {
            throw new Exception("User delete preparation failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($deleteUserStmt, "i", $deleteUserId);

        if (!mysqli_stmt_execute($deleteUserStmt)) {
            throw new Exception("User delete failed: " . mysqli_stmt_error($deleteUserStmt));
        }

        mysqli_stmt_close($deleteUserStmt);

        mysqli_commit($conn);

        setUserManagementFlash("success", "User deleted successfully.");

    } catch (Exception $e) {
        mysqli_rollback($conn);
        setUserManagementFlash("error", $e->getMessage());
    }
}

/*
|--------------------------------------------------------------------------
| Fetch Users
|--------------------------------------------------------------------------
*/

$managedRoles = [
    "ward_officer",
    "inspector",
    "team_leader",
    "assistant_team_leader",
    "maintenance_team",
    "maintenance_member"
];

$roleListSql = "'" . implode("','", array_map(function ($role) use ($conn) {
    return mysqli_real_escape_string($conn, $role);
}, $managedRoles)) . "'";

$sql = "
    SELECT 
        user_id,
        user_name,
        user_mail,
        user_role,
        user_status,
        login_access,
        last_active
    FROM users
    WHERE user_role IN ({$roleListSql})
    ORDER BY user_id DESC
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("User query failed: " . mysqli_error($conn));
}

$users = [];

while ($row = mysqli_fetch_assoc($result)) {
    $row["assigned_info"] = getAssignedInfo($conn, (int)$row["user_id"], $row["user_role"]);
    $users[] = $row;
}

/*
|--------------------------------------------------------------------------
| Count Cards
|--------------------------------------------------------------------------
*/

$countSql = "
    SELECT
        COUNT(*) AS total_users,
        SUM(CASE WHEN user_status = 'active' THEN 1 ELSE 0 END) AS active_users,
        SUM(CASE WHEN user_status = 'inactive' THEN 1 ELSE 0 END) AS inactive_users
    FROM users
    WHERE user_role IN ({$roleListSql})
";

$countResult = mysqli_query($conn, $countSql);

$totalUsers = 0;
$activeUsers = 0;
$inactiveUsers = 0;

if ($countResult && mysqli_num_rows($countResult) > 0) {
    $countData = mysqli_fetch_assoc($countResult);

    $totalUsers = (int) ($countData["total_users"] ?? 0);
    $activeUsers = (int) ($countData["active_users"] ?? 0);
    $inactiveUsers = (int) ($countData["inactive_users"] ?? 0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo safeText($pageTitle); ?> | DrainGuard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">
    <link rel="stylesheet" href="../../css/central/footer.css">
    <link rel="stylesheet" href="../../css/central/user-management.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="um-page">

            <div class="um-header-card">
                <div>
                    <h1>User Management</h1>
                    <p>Manage ward officers, inspectors, maintenance teams, and team members.</p>
                </div>

                <div class="um-header-actions">
                    <div class="um-add-user-wrapper" id="addUserWrapper">
                        <button type="button" class="um-btn um-btn-primary" id="addUserBtn">
                            <i class="bi bi-person-plus"></i>
                            <span>Add User</span>
                            <i class="bi bi-chevron-down um-chevron"></i>
                        </button>

                        <div class="um-add-user-menu" id="addUserMenu">
                            <a href="<?php echo $centralBaseUrl; ?>/add-inspector.php">
                                <i class="bi bi-shield-check"></i>
                                <span>Add Inspector</span>
                            </a>

                            <a href="<?php echo $centralBaseUrl; ?>/add-ward-officer.php">
                                <i class="bi bi-building"></i>
                                <span>Add Ward Officer</span>
                            </a>

                            <a href="<?php echo $centralBaseUrl; ?>/add-maintenance-team.php">
                                <i class="bi bi-tools"></i>
                                <span>Add Maintenance Team</span>
                            </a>

                            <a href="<?php echo $centralBaseUrl; ?>/add-team-member.php">
                                <i class="bi bi-person-plus"></i>
                                <span>Add Team Member</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($successMessage !== ""): ?>
                <div class="um-alert success">
                    <i class="bi bi-check-circle"></i>
                    <span><?php echo safeText($successMessage); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ""): ?>
                <div class="um-alert error">
                    <i class="bi bi-exclamation-circle"></i>
                    <span><?php echo safeText($errorMessage); ?></span>
                </div>
            <?php endif; ?>

            <div class="um-stats-grid">

                <div class="um-stat-card">
                    <div class="um-stat-icon users">
                        <i class="bi bi-people"></i>
                    </div>
                    <div>
                        <h2><?php echo $totalUsers; ?></h2>
                        <p>Total Users</p>
                    </div>
                </div>

                <div class="um-stat-card">
                    <div class="um-stat-icon active">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div>
                        <h2><?php echo $activeUsers; ?></h2>
                        <p>Active Users</p>
                    </div>
                </div>

                <div class="um-stat-card">
                    <div class="um-stat-icon inactive">
                        <i class="bi bi-slash-circle"></i>
                    </div>
                    <div>
                        <h2><?php echo $inactiveUsers; ?></h2>
                        <p>Inactive Users</p>
                    </div>
                </div>

            </div>

            <div class="um-filter-card">
                <div class="um-search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" id="userSearchInput" placeholder="Search by name, email, role, assigned ward/team, or status...">
                </div>

                <select id="roleFilter" class="um-filter">
                    <option value="all">All Roles</option>
                    <option value="inspector">Inspector</option>
                    <option value="ward_officer">Ward Officer</option>
                    <option value="team_leader">Team Leader</option>
                    <option value="assistant_team_leader">Assistant Team Leader</option>
                    <option value="maintenance_team">Maintenance Team</option>
                    <option value="maintenance_member">Maintenance Member</option>
                </select>
            </div>

            <div class="um-table-card">
                <div class="um-table-header">
                    <h2>System Users</h2>
                    <p>Role-wise users with assigned ward or team information.</p>
                </div>

                <div class="um-table-wrapper">
                    <table class="um-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Assigned Area / Team</th>
                                <th>Status</th>
                                <th>Login Access</th>
                                <th>Last Active</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody id="userTableBody">

                        <?php if (count($users) > 0): ?>
                            <?php foreach ($users as $user): ?>
                                <?php
                                    $statusClass = strtolower($user["user_status"]) === "active" ? "active" : "inactive";
                                    $roleSlug = strtolower($user["user_role"]);
                                    $initial = strtoupper(substr($user["user_name"], 0, 1));
                                    $loginAccessClass = ((int)$user["login_access"] === 1) ? "active" : "inactive";
                                    $loginAccessText = ((int)$user["login_access"] === 1) ? "Allowed" : "Blocked";
                                    $assignedInfo = $user["assigned_info"] ?? "Not assigned";
                                ?>

                                <tr 
                                    class="um-user-row"
                                    data-name="<?php echo strtolower(safeText($user["user_name"])); ?>"
                                    data-email="<?php echo strtolower(safeText($user["user_mail"])); ?>"
                                    data-role="<?php echo safeText($roleSlug); ?>"
                                    data-status="<?php echo strtolower(safeText($user["user_status"])); ?>"
                                    data-assigned="<?php echo strtolower(safeText($assignedInfo)); ?>"
                                >
                                    <td>
                                        <div class="um-user-cell">
                                            <div class="um-avatar"><?php echo safeText($initial); ?></div>
                                            <div>
                                                <h4><?php echo safeText($user["user_name"]); ?></h4>
                                                <p>User ID: <?php echo (int) $user["user_id"]; ?></p>
                                            </div>
                                        </div>
                                    </td>

                                    <td><?php echo safeText($user["user_mail"]); ?></td>

                                    <td>
                                        <span class="um-role-badge">
                                            <?php echo safeText(formatRoleName($user["user_role"])); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="um-assigned-text">
                                            <?php echo safeText($assignedInfo); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="um-status-badge <?php echo $statusClass; ?>">
                                            <?php echo safeText(formatStatusName($user["user_status"])); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="um-status-badge <?php echo $loginAccessClass; ?>">
                                            <?php echo safeText($loginAccessText); ?>
                                        </span>
                                    </td>

                                    <td><?php echo safeText(formatLastActive($user["last_active"])); ?></td>

                                    <td>
                                        <div class="um-action-buttons">
                                            <form 
                                                method="POST" 
                                                class="um-delete-form" 
                                                onsubmit="return confirm('Are you sure you want to delete this user? This will delete the user from users table and related role table.');"
                                            >
                                                <input type="hidden" name="delete_user_id" value="<?php echo (int)$user["user_id"]; ?>">

                                                <button type="submit" class="um-icon-btn delete" title="Delete">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        </tbody>
                    </table>

                    <div class="um-empty-state" id="emptyState" style="<?php echo count($users) > 0 ? 'display:none;' : ''; ?>">
                        <i class="bi bi-people"></i>
                        <h3>No users found</h3>
                        <p>No ward officer, inspector, team leader, or assistant team leader exists yet.</p>
                    </div>
                </div>
            </div>

        </section>

        <?php include "../../includes/central/footer.php"; ?>

    </main>

</div>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/user-management.js"></script>

</body>
</html>