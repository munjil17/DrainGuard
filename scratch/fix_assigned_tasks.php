<?php
$file_path = "c:/xampp/htdocs/DrainGuard/pages/maintenance/assigned-tasks.php";
$content = file_get_contents($file_path);

// The old block we want to replace
$old_block = '        // Fetch central officer user ID (assigned_by from complaint_assignments if they are a central officer)
        $centralOfficerUserId = 0;
        $assignedByUserId = (int)$taskRow[\'assigned_by_user_id\'];
        if ($assignedByUserId > 0) {
            $fetchRoleSql = "SELECT user_role FROM users WHERE user_id = ? LIMIT 1";
            $stmtRole = mysqli_prepare($conn, $fetchRoleSql);
            if ($stmtRole) {
                mysqli_stmt_bind_param($stmtRole, "i", $assignedByUserId);
                mysqli_stmt_execute($stmtRole);
                $resRole = mysqli_stmt_get_result($stmtRole);
                if ($rowRole = mysqli_fetch_assoc($resRole)) {
                    if ($rowRole[\'user_role\'] === \'central_officer\') {
                        $centralOfficerUserId = $assignedByUserId;
                    }
                }
                mysqli_stmt_close($stmtRole);
            }
        }';

$new_block = '        // Fetch central officer user ID
        $centralOfficerUserId = 0;
        $fetchCentralSql = "SELECT ca.assigned_by FROM complaint_assignments ca JOIN users u ON u.user_id = ca.assigned_by WHERE ca.complaint_id = ? AND u.user_role = \'central_officer\' LIMIT 1";
        $stmtCentral = mysqli_prepare($conn, $fetchCentralSql);
        if ($stmtCentral) {
            mysqli_stmt_bind_param($stmtCentral, "i", $complaintId);
            mysqli_stmt_execute($stmtCentral);
            $resCentral = mysqli_stmt_get_result($stmtCentral);
            if ($rowCentral = mysqli_fetch_assoc($resCentral)) {
                $centralOfficerUserId = (int)$rowCentral[\'assigned_by\'];
            }
            mysqli_stmt_close($stmtCentral);
        }';

// replace it globally in the file (it appears for start_work and need_support)
$count = 0;
$content = str_replace($old_block, $new_block, $content, $count);

if ($count > 0) {
    file_put_contents($file_path, $content);
    echo "Replaced in $count places in assigned-tasks.php\n";
} else {
    echo "Could not find the block in assigned-tasks.php\n";
}
?>
