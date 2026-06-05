<?php
require_once "../../config.php";
require_once "../../auth/session_check.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $supportRequestId = isset($_POST["support_request_id"]) ? (int)$_POST["support_request_id"] : 0;
    $wardReply = trim($_POST["ward_reply"] ?? "");
    $redirectTo = $_POST["redirect_to"] ?? "local-team-assignment.php";
    
    $wardOfficerUserId = $_SESSION['user_id'] ?? 0;
    
    if ($supportRequestId > 0 && !empty($wardReply) && $wardOfficerUserId > 0) {
        mysqli_begin_transaction($conn);
        try {
            $updateSql = "UPDATE maintenance_support_requests 
                          SET ward_reply = ?, request_status = 'replied', replied_at = NOW() 
                          WHERE support_request_id = ? AND ward_officer_user_id = ?";
            $updateStmt = mysqli_prepare($conn, $updateSql);
            mysqli_stmt_bind_param($updateStmt, "sii", $wardReply, $supportRequestId, $wardOfficerUserId);
            mysqli_stmt_execute($updateStmt);
            
            if (mysqli_stmt_affected_rows($updateStmt) > 0) {
                $fetchSql = "SELECT msr.maintenance_team_id, msr.complaint_id, c.complaint_code, c.complaint_status
                             FROM maintenance_support_requests msr
                             INNER JOIN complaints c ON c.complaint_id = msr.complaint_id
                             WHERE msr.support_request_id = ?";
                $fetchStmt = mysqli_prepare($conn, $fetchSql);
                mysqli_stmt_bind_param($fetchStmt, "i", $supportRequestId);
                mysqli_stmt_execute($fetchStmt);
                $fetchResult = mysqli_stmt_get_result($fetchStmt);
                $row = mysqli_fetch_assoc($fetchResult);
                
                if ($row) {
                    $teamId = $row["maintenance_team_id"];
                    $complaintStatus = $row["complaint_status"];
                    $complaintId = $row["complaint_id"];
                    
                    $leaderSql = "SELECT user_id FROM maintenance_team_members WHERE maintenance_team_id = ? AND role = 'team_leader' LIMIT 1";
                    $leaderStmt = mysqli_prepare($conn, $leaderSql);
                    mysqli_stmt_bind_param($leaderStmt, "i", $teamId);
                    mysqli_stmt_execute($leaderStmt);
                    $leaderResult = mysqli_stmt_get_result($leaderStmt);
                    $leaderRow = mysqli_fetch_assoc($leaderResult);
                    
                    if ($leaderRow) {
                        $leaderUserId = $leaderRow["user_id"];
                        $url = "/DrainGuard/pages/maintenance/assigned-tasks.php?highlight=" . $row["complaint_code"];
                        if ($complaintStatus === 'in_progress') {
                            $url = "/DrainGuard/pages/maintenance/in-progress-work.php?highlight=" . $row["complaint_code"];
                        }
                        
                        $notifSql = "INSERT INTO maintenance_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read) 
                                     VALUES (?, ?, ?, 'ward_reply_support_request', 'Ward Officer Replied to Support Request', ?, 0)";
                        $notifStmt = mysqli_prepare($conn, $notifSql);
                        $msg = "Ward Officer replied to your support request for complaint " . $row["complaint_code"] . ". Please check the related complaint.";
                        mysqli_stmt_bind_param($notifStmt, "iiis", $leaderUserId, $wardOfficerUserId, $complaintId, $msg);
                        mysqli_stmt_execute($notifStmt);
                    }
                }
            }
            mysqli_commit($conn);
        } catch (Exception $e) {
            mysqli_rollback($conn);
        }
    }
    
    header("Location: " . $redirectTo);
    exit;
}
?>
