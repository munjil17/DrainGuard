<?php
$file_path = "c:/xampp/htdocs/DrainGuard/pages/maintenance/upload-completion-proof.php";
$content = file_get_contents($file_path);

$old_block = '        if ($citizenUserId > 0) {
            $msgC = "Your complaint completion proof has been submitted for inspection.";
            $insC = mysqli_prepare($conn, "INSERT INTO citizen_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, \'system\', \'Proof Submitted\', ?, 0, ?)");
            mysqli_stmt_bind_param($insC, "iiiss", $citizenUserId, $userId, $complaintId, $msgC, $notifTime);
            mysqli_stmt_execute($insC);
            mysqli_stmt_close($insC);
        }

        if ($centralOfficerUserId > 0) {
            $msgCent = "Maintenance team {$maintenanceTeamName} submitted completion proof for a complaint.";
            $insCent = mysqli_prepare($conn, "INSERT INTO central_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, \'system\', \'Proof Submitted\', ?, 0, ?)");
            mysqli_stmt_bind_param($insCent, "iiiss", $centralOfficerUserId, $userId, $complaintId, $msgCent, $notifTime);
            mysqli_stmt_execute($insCent);
            mysqli_stmt_close($insCent);
        }

        if ($wardOfficerUserId > 0) {
            $msgWard = "Maintenance team {$maintenanceTeamName} submitted completion proof for your assigned complaint.";
            $insWard = mysqli_prepare($conn, "INSERT INTO ward_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, \'system\', \'Proof Submitted\', ?, 0, ?)");
            mysqli_stmt_bind_param($insWard, "iiiss", $wardOfficerUserId, $userId, $complaintId, $msgWard, $notifTime);
            mysqli_stmt_execute($insWard);
            mysqli_stmt_close($insWard);
        }

        if ($inspectorUserId > 0) {
            $msgInsp = "A solved-by-team case is ready for inspection.";
            $insInsp = mysqli_prepare($conn, "INSERT INTO inspector_notifications (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, \'system\', \'Inspection Required\', ?, 0, ?)");
            mysqli_stmt_bind_param($insInsp, "iiiss", $inspectorUserId, $userId, $complaintId, $msgInsp, $notifTime);
            mysqli_stmt_execute($insInsp);
            mysqli_stmt_close($insInsp);
        }';

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

if (strpos($content, $old_block) !== false) {
    $content = str_replace($old_block, $new_block, $content);
    file_put_contents($file_path, $content);
    echo "upload-completion-proof.php replaced successfully.";
} else {
    echo "Could not find the target block in upload-completion-proof.php.\n";
    $lines = explode("\n", $content);
    for ($i=420; $i<460; $i++) {
        echo $i . ": " . ($lines[$i] ?? "") . "\n";
    }
}
?>
