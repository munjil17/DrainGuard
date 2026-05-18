<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "user-management";
$pageTitle = "Add Team Member";
$pageParent = "Central Control";
$pageChild = "Add Team Member";

$successMessage = "";
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}

function generateEmployeeCode($role, $id)
{
    $cleanRole = preg_replace("/[^A-Za-z]/", "", $role);
    $prefix = strtoupper(substr($cleanRole, 0, 3));

    if ($prefix === "") {
        $prefix = "MEM";
    }

    return $prefix . "-" . str_pad($id, 3, "0", STR_PAD_LEFT);
}

function redirectAddTeamMember()
{
    header("Location: /DrainGuard/pages/central/add-team-member.php");
    exit();
}

function setFlashMessage($type, $message)
{
    if ($type === "success") {
        $_SESSION["add_team_member_success"] = $message;
    } else {
        $_SESSION["add_team_member_error"] = $message;
    }

    redirectAddTeamMember();
}

/*
|--------------------------------------------------------------------------
| Flash Messages
|--------------------------------------------------------------------------
*/

if (isset($_SESSION["add_team_member_success"])) {
    $successMessage = $_SESSION["add_team_member_success"];
    unset($_SESSION["add_team_member_success"]);
}

if (isset($_SESSION["add_team_member_error"])) {
    $errorMessage = $_SESSION["add_team_member_error"];
    unset($_SESSION["add_team_member_error"]);
}

/*
|--------------------------------------------------------------------------
| Fetch Maintenance Teams
|--------------------------------------------------------------------------
| skill_type removed from maintenance_teams.
|--------------------------------------------------------------------------
*/

$maintenanceTeams = [];

$teamSql = "
    SELECT
        mt.maintenance_team_id,
        mt.team_name,
        mt.assigned_thana_id,
        mt.availability_status,
        mt.assistant_login_access,
        t.thana_name
    FROM maintenance_teams mt
    LEFT JOIN thanas t
        ON mt.assigned_thana_id = t.thana_id
    ORDER BY mt.team_name ASC
";

$teamResult = mysqli_query($conn, $teamSql);

if ($teamResult) {
    while ($row = mysqli_fetch_assoc($teamResult)) {
        $maintenanceTeams[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Backend Process
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $maintenanceTeamId = trim($_POST["maintenance_team_id"] ?? "");
    $memberRole = trim($_POST["member_role"] ?? "");
    $assistantLoginAccess = trim($_POST["assistant_login_access"] ?? "");

    $fullName = trim($_POST["full_name"] ?? "");
    $phoneNumber = trim($_POST["phone_number"] ?? "");
    $gmail = strtolower(trim($_POST["gmail"] ?? ""));
    $gender = trim($_POST["gender"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $memberStatus = trim($_POST["member_status"] ?? "");

    $password = trim($_POST["password"] ?? "");
    $confirmPassword = trim($_POST["confirm_password"] ?? "");

    /*
    |--------------------------------------------------------------------------
    | Basic Validation
    |--------------------------------------------------------------------------
    */

    if (
        $maintenanceTeamId === "" ||
        $memberRole === "" ||
        $assistantLoginAccess === "" ||
        $fullName === "" ||
        $phoneNumber === "" ||
        $gmail === "" ||
        $gender === "" ||
        $address === "" ||
        $memberStatus === ""
    ) {
        setFlashMessage("error", "Please fill up all required fields.");
    }

    if (!is_numeric($maintenanceTeamId) || (int)$maintenanceTeamId <= 0) {
        setFlashMessage("error", "Invalid maintenance team selected.");
    }

    $maintenanceTeamId = (int)$maintenanceTeamId;

    if (!filter_var($gmail, FILTER_VALIDATE_EMAIL)) {
        setFlashMessage("error", "Invalid Gmail address.");
    }

    if (!preg_match("/^[a-zA-Z0-9._%+-]+@gmail\.com$/", $gmail)) {
        setFlashMessage("error", "Only Gmail addresses are allowed.");
    }

    if (!in_array($memberRole, ["team_leader", "assistant_team_leader", "worker"], true)) {
        setFlashMessage("error", "Invalid team member role selected.");
    }

    if (!in_array($assistantLoginAccess, ["yes", "no"], true)) {
        setFlashMessage("error", "Invalid login access selected.");
    }

    if (!in_array($gender, ["male", "female", "other"], true)) {
        setFlashMessage("error", "Invalid gender selected.");
    }

    if (!in_array($memberStatus, ["active", "inactive"], true)) {
        setFlashMessage("error", "Invalid member status selected.");
    }

    /*
    |--------------------------------------------------------------------------
    | User/Login Access Logic
    |--------------------------------------------------------------------------
    | Team Leader:
    |   users insert, user_role = team_leader, login_access = 1
    |
    | Assistant Team Leader:
    |   users insert, user_role = assistant_team_leader
    |   login_access = yes -> login_access = 1, password required
    |   login_access = no  -> login_access = 0, dummy password
    |
    | Worker:
    |   no users insert
    |--------------------------------------------------------------------------
    */

    $shouldCreateUser = false;
    $shouldRequirePassword = false;
    $loginAccessValue = 0;
    $userRoleForUsers = null;

    if ($memberRole === "team_leader") {
        $shouldCreateUser = true;
        $shouldRequirePassword = true;
        $loginAccessValue = 1;
        $userRoleForUsers = "team_leader";
    }

    if ($memberRole === "assistant_team_leader") {
        $shouldCreateUser = true;
        $userRoleForUsers = "assistant_team_leader";

        if ($assistantLoginAccess === "yes") {
            $shouldRequirePassword = true;
            $loginAccessValue = 1;
        } else {
            $shouldRequirePassword = false;
            $loginAccessValue = 0;
        }
    }

    if ($memberRole === "worker") {
        $shouldCreateUser = false;
        $shouldRequirePassword = false;
        $loginAccessValue = 0;
        $userRoleForUsers = null;
    }

    if ($shouldRequirePassword) {
        if ($password === "" || $confirmPassword === "") {
            setFlashMessage("error", "Password and confirm password are required for login access.");
        }

        if ($password !== $confirmPassword) {
            setFlashMessage("error", "Password and confirm password do not match.");
        }

        if (strlen($password) < 6) {
            setFlashMessage("error", "Password must be at least 6 characters.");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate Email Check
    |--------------------------------------------------------------------------
    */

    if ($shouldCreateUser) {
        $checkUserSql = "SELECT user_id FROM users WHERE user_mail = ? LIMIT 1";
        $checkUserStmt = mysqli_prepare($conn, $checkUserSql);

        if (!$checkUserStmt) {
            setFlashMessage("error", "Database error while checking users table email.");
        }

        mysqli_stmt_bind_param($checkUserStmt, "s", $gmail);
        mysqli_stmt_execute($checkUserStmt);

        $checkUserResult = mysqli_stmt_get_result($checkUserStmt);

        if ($checkUserResult && mysqli_num_rows($checkUserResult) > 0) {
            mysqli_stmt_close($checkUserStmt);
            setFlashMessage("error", "This Gmail already exists in users table.");
        }

        mysqli_stmt_close($checkUserStmt);
    }

    $checkMemberSql = "
        SELECT member_id
        FROM maintenance_team_members
        WHERE user_mail = ?
        LIMIT 1
    ";

    $checkMemberStmt = mysqli_prepare($conn, $checkMemberSql);

    if (!$checkMemberStmt) {
        setFlashMessage("error", "Database error while checking maintenance member email.");
    }

    mysqli_stmt_bind_param($checkMemberStmt, "s", $gmail);
    mysqli_stmt_execute($checkMemberStmt);

    $checkMemberResult = mysqli_stmt_get_result($checkMemberStmt);

    if ($checkMemberResult && mysqli_num_rows($checkMemberResult) > 0) {
        mysqli_stmt_close($checkMemberStmt);
        setFlashMessage("error", "This Gmail already exists in maintenance team members table.");
    }

    mysqli_stmt_close($checkMemberStmt);

    /*
    |--------------------------------------------------------------------------
    | Insert Process
    |--------------------------------------------------------------------------
    */

    mysqli_begin_transaction($conn);

    try {
        /*
        |--------------------------------------------------------------------------
        | Step 1: Check selected team exists
        |--------------------------------------------------------------------------
        */

        $teamCheckSql = "
            SELECT maintenance_team_id
            FROM maintenance_teams
            WHERE maintenance_team_id = ?
            LIMIT 1
        ";

        $teamCheckStmt = mysqli_prepare($conn, $teamCheckSql);

        if (!$teamCheckStmt) {
            throw new Exception("Team check preparation failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($teamCheckStmt, "i", $maintenanceTeamId);
        mysqli_stmt_execute($teamCheckStmt);

        $teamCheckResult = mysqli_stmt_get_result($teamCheckStmt);

        if (!$teamCheckResult || mysqli_num_rows($teamCheckResult) !== 1) {
            mysqli_stmt_close($teamCheckStmt);
            throw new Exception("Selected maintenance team does not exist.");
        }

        mysqli_stmt_close($teamCheckStmt);

        /*
        |--------------------------------------------------------------------------
        | Step 2: Update assistant login access in maintenance_teams
        |--------------------------------------------------------------------------
        */

        $updateTeamSql = "
            UPDATE maintenance_teams
            SET assistant_login_access = ?
            WHERE maintenance_team_id = ?
        ";

        $updateTeamStmt = mysqli_prepare($conn, $updateTeamSql);

        if (!$updateTeamStmt) {
            throw new Exception("Team login access update preparation failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($updateTeamStmt, "si", $assistantLoginAccess, $maintenanceTeamId);

        if (!mysqli_stmt_execute($updateTeamStmt)) {
            throw new Exception("Team login access update failed: " . mysqli_stmt_error($updateTeamStmt));
        }

        mysqli_stmt_close($updateTeamStmt);

        /*
        |--------------------------------------------------------------------------
        | Step 3: Prevent duplicate Team Leader / Assistant Team Leader
        |--------------------------------------------------------------------------
        */

        if ($memberRole === "team_leader" || $memberRole === "assistant_team_leader") {
            $checkRoleSql = "
                SELECT member_id
                FROM maintenance_team_members
                WHERE maintenance_team_id = ?
                AND role = ?
                LIMIT 1
            ";

            $checkRoleStmt = mysqli_prepare($conn, $checkRoleSql);

            if (!$checkRoleStmt) {
                throw new Exception("Role check preparation failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($checkRoleStmt, "is", $maintenanceTeamId, $memberRole);
            mysqli_stmt_execute($checkRoleStmt);

            $checkRoleResult = mysqli_stmt_get_result($checkRoleStmt);

            if ($checkRoleResult && mysqli_num_rows($checkRoleResult) > 0) {
                mysqli_stmt_close($checkRoleStmt);

                if ($memberRole === "team_leader") {
                    throw new Exception("This team already has one Team Leader.");
                }

                if ($memberRole === "assistant_team_leader") {
                    throw new Exception("This team already has one Assistant Team Leader.");
                }
            }

            mysqli_stmt_close($checkRoleStmt);
        }

        /*
        |--------------------------------------------------------------------------
        | Step 4: Insert into users table if needed
        |--------------------------------------------------------------------------
        */

        $userId = null;

        if ($shouldCreateUser) {
            if ($userRoleForUsers === null) {
                throw new Exception("User role mapping failed.");
            }

            if ($shouldRequirePassword) {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            } else {
                $hashedPassword = password_hash("NO_LOGIN_ACCESS_" . time(), PASSWORD_DEFAULT);
            }

            $insertUserSql = "
                INSERT INTO users (
                    user_name,
                    user_mail,
                    user_password,
                    user_role,
                    user_status,
                    login_access,
                    reset_token,
                    reset_time
                )
                VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)
            ";

            $insertUserStmt = mysqli_prepare($conn, $insertUserSql);

            if (!$insertUserStmt) {
                throw new Exception("User insert preparation failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param(
                $insertUserStmt,
                "sssssi",
                $fullName,
                $gmail,
                $hashedPassword,
                $userRoleForUsers,
                $memberStatus,
                $loginAccessValue
            );

            if (!mysqli_stmt_execute($insertUserStmt)) {
                throw new Exception("User insert failed: " . mysqli_stmt_error($insertUserStmt));
            }

            $userId = mysqli_insert_id($conn);
            mysqli_stmt_close($insertUserStmt);
        }

        /*
        |--------------------------------------------------------------------------
        | Step 5: Insert into maintenance_team_members table
        |--------------------------------------------------------------------------
        */

        $insertMemberSql = "
            INSERT INTO maintenance_team_members (
                maintenance_team_id,
                user_id,
                full_name,
                phone_number,
                user_mail,
                employee_code,
                gender,
                address,
                role,
                status
            )
            VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)
        ";

        $insertMemberStmt = mysqli_prepare($conn, $insertMemberSql);

        if (!$insertMemberStmt) {
            throw new Exception("Member insert preparation failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param(
            $insertMemberStmt,
            "iisssssss",
            $maintenanceTeamId,
            $userId,
            $fullName,
            $phoneNumber,
            $gmail,
            $gender,
            $address,
            $memberRole,
            $memberStatus
        );

        if (!mysqli_stmt_execute($insertMemberStmt)) {
            throw new Exception("Member insert failed: " . mysqli_stmt_error($insertMemberStmt));
        }

        $memberId = mysqli_insert_id($conn);
        mysqli_stmt_close($insertMemberStmt);

        /*
        |--------------------------------------------------------------------------
        | Step 6: Generate Employee Code
        |--------------------------------------------------------------------------
        */

        $employeeCode = generateEmployeeCode($memberRole, $memberId);

        $updateCodeSql = "
            UPDATE maintenance_team_members
            SET employee_code = ?
            WHERE member_id = ?
        ";

        $updateCodeStmt = mysqli_prepare($conn, $updateCodeSql);

        if (!$updateCodeStmt) {
            throw new Exception("Employee code update preparation failed: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($updateCodeStmt, "si", $employeeCode, $memberId);

        if (!mysqli_stmt_execute($updateCodeStmt)) {
            throw new Exception("Employee code update failed: " . mysqli_stmt_error($updateCodeStmt));
        }

        mysqli_stmt_close($updateCodeStmt);

        mysqli_commit($conn);

        setFlashMessage("success", "Team member added successfully. Employee Code: " . $employeeCode);

    } catch (Exception $e) {
        mysqli_rollback($conn);
        setFlashMessage("error", $e->getMessage());
    }
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
    <link rel="stylesheet" href="../../css/central/add-team-member.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="atm-page">

            <div class="atm-header-card">
                <div>
                    <h1>Add Team Member</h1>
                    <p>Select an existing maintenance team, then add leader, assistant, or worker.</p>
                </div>

                <a href="user-management.php" class="atm-back-btn">
                    <i class="bi bi-arrow-left"></i>
                    Back to Users
                </a>
            </div>

            <?php if ($successMessage !== ""): ?>
                <div class="atm-alert success">
                    <i class="bi bi-check-circle"></i>
                    <span><?php echo safeText($successMessage); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ""): ?>
                <div class="atm-alert error">
                    <i class="bi bi-exclamation-circle"></i>
                    <span><?php echo safeText($errorMessage); ?></span>
                </div>
            <?php endif; ?>

            <form action="add-team-member.php" method="POST" class="atm-form" id="addTeamMemberForm" autocomplete="off">

                <div class="atm-card">
                    <div class="atm-section-title">
                        <div class="atm-section-icon">
                            <i class="bi bi-people"></i>
                        </div>

                        <div>
                            <h2>Select Team & Role</h2>
                            <p>Team names come from maintenance_teams table.</p>
                        </div>
                    </div>

                    <div class="atm-grid">

                        <div class="atm-field">
                            <label for="maintenance_team_id">Maintenance Team <span>*</span></label>

                            <select id="maintenance_team_id" name="maintenance_team_id" required>
                                <option value="">Select maintenance team</option>

                                <?php foreach ($maintenanceTeams as $team): ?>
                                    <option
                                        value="<?php echo (int)$team["maintenance_team_id"]; ?>"
                                        data-team-name="<?php echo safeText($team["team_name"]); ?>"
                                        data-thana="<?php echo safeText($team["thana_name"] ?? "N/A"); ?>"
                                        data-status="<?php echo safeText($team["availability_status"]); ?>"
                                        data-assistant-access="<?php echo safeText($team["assistant_login_access"]); ?>"
                                    >
                                        <?php echo safeText($team["team_name"]); ?>
                                        —
                                        <?php echo safeText($team["thana_name"] ?? "No Thana"); ?>
                                        —
                                        <?php echo safeText(ucfirst($team["availability_status"])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="atm-field">
                            <label for="member_role">Member Role <span>*</span></label>

                            <select id="member_role" name="member_role" required>
                                <option value="">Select role</option>
                                <option value="team_leader">Team Leader</option>
                                <option value="assistant_team_leader">Assistant Team Leader</option>
                                <option value="worker">Worker</option>
                            </select>
                        </div>

                        <div class="atm-field">
                            <label for="assistant_login_access">Assistant Login Access <span>*</span></label>

                            <select id="assistant_login_access" name="assistant_login_access" required>
                                <option value="no">No</option>
                                <option value="yes">Yes</option>
                            </select>
                        </div>

                        <div class="atm-field">
                            <label for="member_status">Member Status <span>*</span></label>

                            <select id="member_status" name="member_status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                    </div>
                </div>

                <div class="atm-card">
                    <div class="atm-section-title">
                        <div class="atm-section-icon">
                            <i class="bi bi-person-badge"></i>
                        </div>

                        <div>
                            <h2>Member Information</h2>
                            <p>Enter personal and contact information.</p>
                        </div>
                    </div>

                    <div class="atm-grid">

                        <div class="atm-field">
                            <label for="full_name">Full Name <span>*</span></label>
                            <input type="text" id="full_name" name="full_name" placeholder="Enter full name" required>
                        </div>

                        <div class="atm-field">
                            <label for="phone_number">Phone Number <span>*</span></label>
                            <input type="text" id="phone_number" name="phone_number" placeholder="01XXXXXXXXX" required>
                        </div>

                        <div class="atm-field">
                            <label for="gmail">Gmail <span>*</span></label>
                            <input type="email" id="gmail" name="gmail" placeholder="example@gmail.com" required>
                        </div>

                        <div class="atm-field">
                            <label for="gender">Gender <span>*</span></label>

                            <select id="gender" name="gender" required>
                                <option value="">Select gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <div class="atm-field atm-full">
                            <label for="address">Address <span>*</span></label>
                            <textarea id="address" name="address" placeholder="Enter address" required></textarea>
                        </div>

                    </div>
                </div>

                <div class="atm-card" id="loginAccessCard">
                    <div class="atm-section-title">
                        <div class="atm-section-icon">
                            <i class="bi bi-key"></i>
                        </div>

                        <div>
                            <h2>Login Access</h2>
                            <p>Password is required for Team Leader and Assistant with login access.</p>
                        </div>
                    </div>

                    <div class="atm-grid">

                        <div class="atm-field">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" placeholder="Minimum 6 characters">
                        </div>

                        <div class="atm-field">
                            <label for="confirm_password">Confirm Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm password">
                        </div>

                    </div>
                </div>

                <div class="atm-actions">
                    <a href="user-management.php" class="atm-cancel-btn">Cancel</a>

                    <button type="submit" class="atm-submit-btn">
                        <i class="bi bi-person-plus"></i>
                        Save Team Member
                    </button>
                </div>

            </form>

        </section>

        <?php include "../../includes/central/footer.php"; ?>

    </main>

</div>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/add-team-member.js"></script>

</body>
</html>