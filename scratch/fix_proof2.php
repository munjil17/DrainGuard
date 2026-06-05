<?php
$file_path = "c:/xampp/htdocs/DrainGuard/pages/maintenance/upload-completion-proof.php";
$content = file_get_contents($file_path);

$pattern = '/if \(\$citizenUserId > 0\) \{.*?if \(\$inspectorUserId > 0\) \{.*?mysqli_stmt_close\(\$insInsp\);\s*\}/s';

$new_block = '        $notifType = \'maintenance_completion_proof_submitted\';
        $notifTitle = \'Completion Proof Submitted\';
        $baseMsg = "Maintenance Team submitted completion proof for complaint {$complaintCode}. The complaint is now waiting for inspector review.";

        if ($citizenUserId > 0) {
            $insC = mysqli_prepare($conn, "INSERT INTO citizen_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
            mysqli_stmt_bind_param($insC, "iiissss", $citizenUserId, $userId, $complaintId, $notifType, $notifTitle, $baseMsg, $notifTime);
            mysqli_stmt_execute($insC);
            mysqli_stmt_close($insC);
        }

        if ($centralOfficerUserId > 0) {
            $insCent = mysqli_prepare($conn, "INSERT INTO central_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
            mysqli_stmt_bind_param($insCent, "iiissss", $centralOfficerUserId, $userId, $complaintId, $notifType, $notifTitle, $baseMsg, $notifTime);
            mysqli_stmt_execute($insCent);
            mysqli_stmt_close($insCent);
        }

        if ($wardOfficerUserId > 0) {
            $insWard = mysqli_prepare($conn, "INSERT INTO ward_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
            mysqli_stmt_bind_param($insWard, "iiissss", $wardOfficerUserId, $userId, $complaintId, $notifType, $notifTitle, $baseMsg, $notifTime);
            mysqli_stmt_execute($insWard);
            mysqli_stmt_close($insWard);
        }

        if ($inspectorUserId > 0) {
            $insInsp = mysqli_prepare($conn, "INSERT INTO inspector_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
            mysqli_stmt_bind_param($insInsp, "iiissss", $inspectorUserId, $userId, $complaintId, $notifType, $notifTitle, $baseMsg, $notifTime);
            mysqli_stmt_execute($insInsp);
            mysqli_stmt_close($insInsp);
        }';

$content = preg_replace($pattern, ltrim($new_block), $content);
file_put_contents($file_path, $content);
echo "upload-completion-proof.php replaced successfully using regex.\n";
?>
