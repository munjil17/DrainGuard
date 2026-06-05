<?php
$file_path = "c:/xampp/htdocs/DrainGuard/pages/inspector/inspection-queue.php";
$content = file_get_contents($file_path);

// Replace approve block
$pattern1 = '/\/\/ Citizen\s*if \(\$citizenUserId > 0\) \{.*?\$msg = "Your complaint has been approved.*?mysqli_stmt_close\(\$ins\);\s*\}\s*\}/s';

$new_block1 = '        $notifTypeApprove = \'inspector_work_approved\';
        $notifTitleApprove = \'Work Approved by Inspector\';
        $baseMsgApprove = "Inspector approved the completed work for complaint {$complaintCode}. The complaint is now closed/solved.";

        // Citizen
        if ($citizenUserId > 0) {
            $ins = mysqli_prepare($conn, "INSERT INTO citizen_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
            if ($ins) {
                mysqli_stmt_bind_param($ins, "iiissss", $citizenUserId, $userId, $complaintId, $notifTypeApprove, $notifTitleApprove, $baseMsgApprove, $notifTime);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        }

        // Central Officer
        if ($centralOfficerUserId > 0) {
            $ins = mysqli_prepare($conn, "INSERT INTO central_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
            if ($ins) {
                mysqli_stmt_bind_param($ins, "iiissss", $centralOfficerUserId, $userId, $complaintId, $notifTypeApprove, $notifTitleApprove, $baseMsgApprove, $notifTime);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        }

        // Ward Officer
        if ($wardOfficerUserId > 0) {
            $ins = mysqli_prepare($conn, "INSERT INTO ward_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
            if ($ins) {
                mysqli_stmt_bind_param($ins, "iiissss", $wardOfficerUserId, $userId, $complaintId, $notifTypeApprove, $notifTitleApprove, $baseMsgApprove, $notifTime);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        }

        // Maintenance Team
        foreach ($maintenanceTeamMembers as $memberId) {
            $ins = mysqli_prepare($conn, "INSERT INTO maintenance_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
            if ($ins) {
                mysqli_stmt_bind_param($ins, "iiissss", $memberId, $userId, $complaintId, $notifTypeApprove, $notifTitleApprove, $baseMsgApprove, $notifTime);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        }';

// Note: we're only replacing up to the end of Maintenance Team in approve block
$pattern_approve = '/\/\/ Citizen.*?mysqli_stmt_close\(\$ins\);\s*\}\s*\}/s';
// Wait, regex might match the false completion block instead.
// Let's use string replace for safety.
?>
