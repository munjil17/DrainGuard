<?php
$file_path = "c:/xampp/htdocs/DrainGuard/pages/inspector/solved-cases.php";
$content = file_get_contents($file_path);

$pattern = '/\/\/ Citizen\s*if \(\$citizenUserId > 0\) \{.*?\/\/ Maintenance Team.*?mysqli_stmt_close\(\$ins\);\s*\}\s*\}/s';

$new_block = '        $notifType = \'inspector_review_started\';
        $notifTitle = \'Inspector Review Started\';
        $baseMsg = "Inspector has started reviewing complaint {$complaintCode}.";

        // Citizen
        if ($citizenUserId > 0) {
            $ins = mysqli_prepare($conn, "INSERT INTO citizen_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
            if ($ins) {
                mysqli_stmt_bind_param($ins, "iiissss", $citizenUserId, $userId, $complaintId, $notifType, $notifTitle, $baseMsg, $notifTime);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        }

        // Central Officer
        if ($centralOfficerUserId > 0) {
            $ins = mysqli_prepare($conn, "INSERT INTO central_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
            if ($ins) {
                mysqli_stmt_bind_param($ins, "iiissss", $centralOfficerUserId, $userId, $complaintId, $notifType, $notifTitle, $baseMsg, $notifTime);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        }

        // Ward Officer
        if ($wardOfficerUserId > 0) {
            $ins = mysqli_prepare($conn, "INSERT INTO ward_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
            if ($ins) {
                mysqli_stmt_bind_param($ins, "iiissss", $wardOfficerUserId, $userId, $complaintId, $notifType, $notifTitle, $baseMsg, $notifTime);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        }

        // Maintenance Team
        foreach ($maintenanceTeamMembers as $memberId) {
            $ins = mysqli_prepare($conn, "INSERT INTO maintenance_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
            if ($ins) {
                mysqli_stmt_bind_param($ins, "iiissss", $memberId, $userId, $complaintId, $notifType, $notifTitle, $baseMsg, $notifTime);
                mysqli_stmt_execute($ins);
                mysqli_stmt_close($ins);
            }
        }';

$content = preg_replace($pattern, ltrim($new_block), $content);
file_put_contents($file_path, $content);
echo "solved-cases.php replaced successfully using regex.\n";
?>
