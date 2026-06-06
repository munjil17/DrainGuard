<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "user-management";
$pageTitle = "Edit Team Member";

$memberId = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function redirectUserManagement()
{
    header("Location: /DrainGuard/pages/central/user-management.php");
    exit();
}

function redirectEditTeamMember($id)
{
    header("Location: /DrainGuard/pages/central/edit-team-member.php?id=" . (int)$id);
    exit();
}

if ($memberId <= 0) {
    $_SESSION["user_management_error"] = "Invalid team member selected.";
    redirectUserManagement();
}

$teams = [];

$teamSql = "
    SELECT
        mt.maintenance_team_id,
        mt.team_name,
        cc.city_cor_name,
        a.anchal_name
    FROM maintenance_teams mt
    LEFT JOIN city_corporations cc
        ON mt.city_cor_id = cc.city_cor_id
    LEFT JOIN anchals a
        ON mt.anchal_id = a.anchal_id
    ORDER BY mt.team_name ASC
";

$teamResult = mysqli_query($conn, $teamSql);

if ($teamResult) {
    while ($row = mysqli_fetch_assoc($teamResult)) {
        $teams[] = $row;
    }
}

$memberSql = "
    SELECT
        member_id,
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
    FROM maintenance_team_members
    WHERE member_id = ?
    LIMIT 1
";

$memberStmt = mysqli_prepare($conn, $memberSql);

if (!$memberStmt) {
    $_SESSION["user_management_error"] = "Unable to load team member details. Please try again.";
    redirectUserManagement();
}

mysqli_stmt_bind_param($memberStmt, "i", $memberId);
mysqli_stmt_execute($memberStmt);

$memberResult = mysqli_stmt_get_result($memberStmt);
$member = mysqli_fetch_assoc($memberResult);

mysqli_stmt_close($memberStmt);

if (!$member) {
    $_SESSION["user_management_error"] = "Team member not found.";
    redirectUserManagement();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $maintenanceTeamId = (int)($_POST["maintenance_team_id"] ?? 0);
    $memberRole = trim($_POST["member_role"] ?? "");
    $fullName = trim($_POST["full_name"] ?? "");
    $phoneNumber = trim($_POST["phone_number"] ?? "");
    $gmail = strtolower(trim($_POST["gmail"] ?? ""));
    $gender = trim($_POST["gender"] ?? "");
    $address = trim($_POST["address"] ?? "");
    $status = trim($_POST["member_status"] ?? "");

    if (
        $maintenanceTeamId <= 0 ||
        $memberRole === "" ||
        $fullName === "" ||
        $phoneNumber === "" ||
        $gmail === "" ||
        $gender === "" ||
        $address === "" ||
        $status === ""
    ) {
        $_SESSION["user_management_error"] = "Please complete all required fields.";
        redirectEditTeamMember($memberId);
    }

    if (!filter_var($gmail, FILTER_VALIDATE_EMAIL)) {
        $_SESSION["user_management_error"] = "Invalid email address.";
        redirectEditTeamMember($memberId);
    }

    if (!preg_match("/^[a-zA-Z0-9._%+-]+@gmail\.com$/", $gmail)) {
        $_SESSION["user_management_error"] = "Only Gmail addresses are allowed.";
        redirectEditTeamMember($memberId);
    }

    if (!in_array($memberRole, ["team_leader", "assistant_team_leader", "worker"], true)) {
        $_SESSION["user_management_error"] = "Invalid member role selected.";
        redirectEditTeamMember($memberId);
    }

    if (!in_array($gender, ["male", "female", "other"], true)) {
        $_SESSION["user_management_error"] = "Invalid gender selected.";
        redirectEditTeamMember($memberId);
    }

    if (!in_array($status, ["active", "inactive"], true)) {
        $_SESSION["user_management_error"] = "Invalid status selected.";
        redirectEditTeamMember($memberId);
    }

    $teamCheckSql = "
        SELECT maintenance_team_id
        FROM maintenance_teams
        WHERE maintenance_team_id = ?
        LIMIT 1
    ";

    $teamCheckStmt = mysqli_prepare($conn, $teamCheckSql);

    if (!$teamCheckStmt) {
        $_SESSION["user_management_error"] = "Unable to verify the selected team. Please try again.";
        redirectEditTeamMember($memberId);
    }

    mysqli_stmt_bind_param($teamCheckStmt, "i", $maintenanceTeamId);
    mysqli_stmt_execute($teamCheckStmt);

    $teamCheckResult = mysqli_stmt_get_result($teamCheckStmt);

    if (!$teamCheckResult || mysqli_num_rows($teamCheckResult) !== 1) {
        mysqli_stmt_close($teamCheckStmt);
        $_SESSION["user_management_error"] = "Invalid maintenance team selected.";
        redirectEditTeamMember($memberId);
    }

    mysqli_stmt_close($teamCheckStmt);

    if ($memberRole === "team_leader" || $memberRole === "assistant_team_leader") {
        $roleCheckSql = "
            SELECT member_id
            FROM maintenance_team_members
            WHERE maintenance_team_id = ?
            AND role = ?
            AND member_id <> ?
            LIMIT 1
        ";

        $roleCheckStmt = mysqli_prepare($conn, $roleCheckSql);

        if (!$roleCheckStmt) {
            $_SESSION["user_management_error"] = "Unable to verify this role assignment. Please try again.";
            redirectEditTeamMember($memberId);
        }

        mysqli_stmt_bind_param($roleCheckStmt, "isi", $maintenanceTeamId, $memberRole, $memberId);
        mysqli_stmt_execute($roleCheckStmt);

        $roleCheckResult = mysqli_stmt_get_result($roleCheckStmt);

        if ($roleCheckResult && mysqli_num_rows($roleCheckResult) > 0) {
            mysqli_stmt_close($roleCheckStmt);
            $_SESSION["user_management_error"] = "This team already has this role.";
            redirectEditTeamMember($memberId);
        }

        mysqli_stmt_close($roleCheckStmt);
    }

    $emailCheckSql = "
        SELECT member_id
        FROM maintenance_team_members
        WHERE user_mail = ?
        AND member_id <> ?
        LIMIT 1
    ";

    $emailCheckStmt = mysqli_prepare($conn, $emailCheckSql);

    if (!$emailCheckStmt) {
        $_SESSION["user_management_error"] = "Unable to check this email right now. Please try again.";
        redirectEditTeamMember($memberId);
    }

    mysqli_stmt_bind_param($emailCheckStmt, "si", $gmail, $memberId);
    mysqli_stmt_execute($emailCheckStmt);

    $emailCheckResult = mysqli_stmt_get_result($emailCheckStmt);

    if ($emailCheckResult && mysqli_num_rows($emailCheckResult) > 0) {
        mysqli_stmt_close($emailCheckStmt);
        $_SESSION["user_management_error"] = "This Gmail already exists in maintenance team members.";
        redirectEditTeamMember($memberId);
    }

    mysqli_stmt_close($emailCheckStmt);

    mysqli_begin_transaction($conn);

    try {
        $oldUserId = !empty($member["user_id"]) ? (int)$member["user_id"] : null;

        if ($oldUserId !== null) {
            $userRole = ($memberRole === "worker") ? null : $memberRole;

            if ($memberRole === "worker") {
                $deleteUserSql = "DELETE FROM users WHERE user_id = ?";
                $deleteUserStmt = mysqli_prepare($conn, $deleteUserSql);

                if (!$deleteUserStmt) {
                    throw new Exception("User delete preparation failed: " . mysqli_error($conn));
                }

                mysqli_stmt_bind_param($deleteUserStmt, "i", $oldUserId);

                if (!mysqli_stmt_execute($deleteUserStmt)) {
                    throw new Exception("User delete failed: " . mysqli_stmt_error($deleteUserStmt));
                }

                mysqli_stmt_close($deleteUserStmt);

                $oldUserId = null;
            } else {
                $updateUserSql = "
                    UPDATE users
                    SET user_name = ?,
                        user_mail = ?,
                        user_role = ?,
                        user_status = ?
                    WHERE user_id = ?
                    LIMIT 1
                ";

                $updateUserStmt = mysqli_prepare($conn, $updateUserSql);

                if (!$updateUserStmt) {
                    throw new Exception("Unable to complete this action. Please try again.");
                }

                mysqli_stmt_bind_param(
                    $updateUserStmt,
                    "ssssi",
                    $fullName,
                    $gmail,
                    $userRole,
                    $status,
                    $oldUserId
                );

                if (!mysqli_stmt_execute($updateUserStmt)) {
                    throw new Exception("Unable to complete this action. Please try again.");
                }

                mysqli_stmt_close($updateUserStmt);
            }
        }

        if ($oldUserId === null) {
            $updateMemberSql = "
                UPDATE maintenance_team_members
                SET maintenance_team_id = ?,
                    user_id = NULL,
                    full_name = ?,
                    phone_number = ?,
                    user_mail = ?,
                    gender = ?,
                    address = ?,
                    role = ?,
                    status = ?
                WHERE member_id = ?
                LIMIT 1
            ";

            $updateMemberStmt = mysqli_prepare($conn, $updateMemberSql);

            if (!$updateMemberStmt) {
                throw new Exception("Unable to complete this action. Please try again.");
            }

            mysqli_stmt_bind_param(
                $updateMemberStmt,
                "isssssssi",
                $maintenanceTeamId,
                $fullName,
                $phoneNumber,
                $gmail,
                $gender,
                $address,
                $memberRole,
                $status,
                $memberId
            );
        } else {
            $updateMemberSql = "
                UPDATE maintenance_team_members
                SET maintenance_team_id = ?,
                    user_id = ?,
                    full_name = ?,
                    phone_number = ?,
                    user_mail = ?,
                    gender = ?,
                    address = ?,
                    role = ?,
                    status = ?
                WHERE member_id = ?
                LIMIT 1
            ";

            $updateMemberStmt = mysqli_prepare($conn, $updateMemberSql);

            if (!$updateMemberStmt) {
                throw new Exception("Unable to complete this action. Please try again.");
            }

            mysqli_stmt_bind_param(
                $updateMemberStmt,
                "iisssssssi",
                $maintenanceTeamId,
                $oldUserId,
                $fullName,
                $phoneNumber,
                $gmail,
                $gender,
                $address,
                $memberRole,
                $status,
                $memberId
            );
        }

        if (!mysqli_stmt_execute($updateMemberStmt)) {
            throw new Exception("Unable to complete this action. Please try again.");
        }

        mysqli_stmt_close($updateMemberStmt);

        mysqli_commit($conn);

        $_SESSION["user_management_success"] = "Team member updated successfully.";
        redirectUserManagement();

    } catch (Exception $e) {
        mysqli_rollback($conn);

        $_SESSION["user_management_error"] = $e->getMessage();
        redirectEditTeamMember($memberId);
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
    <link rel="stylesheet" href="../../css/central/add-team-member.css">
    <link rel="stylesheet" href="../../css/central/centralTextFix.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="atm-page">

            <div class="atm-header-card">
                <div>
                    <h1>Edit Team Member</h1>
                    <p>Update team member information. Password and login access are not editable here.</p>
                </div>

                <a href="user-management.php" class="atm-back-btn">
                    <i class="bi bi-arrow-left"></i>
                    Back to Users
                </a>
            </div>

            <form action="edit-team-member.php?id=<?php echo (int)$memberId; ?>" method="POST" class="atm-form">

                <div class="atm-card">
                    <div class="atm-section-title">
                        <div class="atm-section-icon">
                            <i class="bi bi-people"></i>
                        </div>

                        <div>
                            <h2>Select Team & Role</h2>
                            <p>Emp Code: <?php echo safeText($member["employee_code"] ?: "N/A"); ?></p>
                        </div>
                    </div>

                    <div class="atm-grid">

                        <div class="atm-field">
                            <label for="maintenance_team_id">Maintenance Team <span>*</span></label>
                            <select id="maintenance_team_id" name="maintenance_team_id" required>
                                <option value="">Select maintenance team</option>
                                <?php foreach ($teams as $team): ?>
                                    <option value="<?php echo (int)$team["maintenance_team_id"]; ?>" <?php echo ((int)$member["maintenance_team_id"] === (int)$team["maintenance_team_id"]) ? "selected" : ""; ?>>
                                        <?php echo safeText($team["team_name"]); ?> — <?php echo safeText($team["city_cor_name"] ?? "No City Corporation"); ?> — <?php echo safeText($team["anchal_name"] ?? "No Anchal"); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="atm-field">
                            <label for="member_role">Member Role <span>*</span></label>
                            <select id="member_role" name="member_role" required>
                                <option value="">Select role</option>
                                <option value="team_leader" <?php echo ($member["role"] === "team_leader") ? "selected" : ""; ?>>Team Leader</option>
                                <option value="assistant_team_leader" <?php echo ($member["role"] === "assistant_team_leader") ? "selected" : ""; ?>>Assistant Team Leader</option>
                                <option value="worker" <?php echo ($member["role"] === "worker") ? "selected" : ""; ?>>Worker</option>
                            </select>
                            <small>Changing a login user to Worker will remove that user's users-table login record.</small>
                        </div>

                        <div class="atm-field">
                            <label for="member_status">Member Status <span>*</span></label>
                            <select id="member_status" name="member_status" required>
                                <option value="active" <?php echo ($member["status"] === "active") ? "selected" : ""; ?>>Active</option>
                                <option value="inactive" <?php echo ($member["status"] === "inactive") ? "selected" : ""; ?>>Inactive</option>
                            </select>
                        </div>

                    </div>
                </div>

                <div class="atm-card show">
                    <div class="atm-section-title">
                        <div class="atm-section-icon">
                            <i class="bi bi-person-badge"></i>
                        </div>

                        <div>
                            <h2>Member Information</h2>
                            <p>Full form except password and login access.</p>
                        </div>
                    </div>

                    <div class="atm-grid">

                        <div class="atm-field">
                            <label for="full_name">Full Name <span>*</span></label>
                            <input type="text" id="full_name" name="full_name" value="<?php echo safeText($member["full_name"]); ?>" required>
                        </div>

                        <div class="atm-field">
                            <label for="phone_number">Phone Number <span>*</span></label>
                            <input type="text" id="phone_number" name="phone_number" value="<?php echo safeText($member["phone_number"]); ?>" required>
                        </div>

                        <div class="atm-field">
                            <label for="gmail">Gmail <span>*</span></label>
                            <input type="email" id="gmail" name="gmail" value="<?php echo safeText($member["user_mail"]); ?>" required>
                        </div>

                        <div class="atm-field">
                            <label for="gender">Gender <span>*</span></label>
                            <select id="gender" name="gender" required>
                                <option value="">Select gender</option>
                                <option value="male" <?php echo ($member["gender"] === "male") ? "selected" : ""; ?>>Male</option>
                                <option value="female" <?php echo ($member["gender"] === "female") ? "selected" : ""; ?>>Female</option>
                                <option value="other" <?php echo ($member["gender"] === "other") ? "selected" : ""; ?>>Other</option>
                            </select>
                        </div>

                        <div class="atm-field full">
                            <label for="address">Address <span>*</span></label>
                            <textarea id="address" name="address" required><?php echo safeText($member["address"]); ?></textarea>
                        </div>

                    </div>
                </div>

                <div class="atm-actions">
                    <a href="user-management.php" class="atm-cancel-btn">Cancel</a>

                    <button type="submit" class="atm-submit-btn">
                        <i class="bi bi-check-circle"></i>
                        Update Team Member
                    </button>
                </div>

            </form>

        </section>


    </main>

</div>

<script src="../../js/central/sidebar.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>