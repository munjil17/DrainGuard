<?php
/* ==============================================================
   Unified Disciplinary & Punishment System Helpers
   ============================================================== */

/**
 * Get or create the disciplinary state for a user/member.
 */
function getDisciplinaryState($conn, $userId, $memberId, $userRole, $subjectType) {
    $sql = "SELECT * FROM disciplinary_state 
            WHERE penalty_subject_type = ?";
    
    $params = [$subjectType];
    $types = "s";

    if ($userId !== null) {
        $sql .= " AND user_id = ?";
        $params[] = $userId;
        $types .= "i";
    } else {
        $sql .= " AND user_id IS NULL";
    }

    if ($memberId !== null) {
        $sql .= " AND member_id = ?";
        $params[] = $memberId;
        $types .= "i";
    } else {
        $sql .= " AND member_id IS NULL";
    }

    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        
        if ($row) return $row;
    }

    // If not exists, insert a default row
    $ins = mysqli_prepare($conn, "INSERT INTO disciplinary_state 
        (user_id, member_id, user_role, penalty_subject_type) 
        VALUES (?, ?, ?, ?)");
    if ($ins) {
        mysqli_stmt_bind_param($ins, "iiss", $userId, $memberId, $userRole, $subjectType);
        mysqli_stmt_execute($ins);
        $stateId = mysqli_stmt_insert_id($ins);
        mysqli_stmt_close($ins);

        // Fetch it again
        $sel = mysqli_prepare($conn, "SELECT * FROM disciplinary_state WHERE state_id = ?");
        mysqli_stmt_bind_param($sel, "i", $stateId);
        mysqli_stmt_execute($sel);
        $res2 = mysqli_stmt_get_result($sel);
        $newRow = mysqli_fetch_assoc($res2);
        mysqli_stmt_close($sel);
        return $newRow;
    }

    return null;
}

/**
 * Add a Demerit and automatically apply suspension if threshold met.
 */
function addDemerit($conn, $userId, $memberId, $userRole, $subjectType, $complaintId, $teamId, $sourceType, $reason, $createdByUserId, $createdByRole) {
    $state = getDisciplinaryState($conn, $userId, $memberId, $userRole, $subjectType);
    if (!$state || $state['is_permanently_banned']) return false; // Already banned

    $newDemerits = $state['active_demerit_points'] + 1;
    $newWarnings = $state['active_warning_count'];
    $actionType = 'demerit';
    $startAt = null;
    $endAt = null;

    // Check suspension sequence
    $oneMonthCount = $state['one_month_suspension_count'];
    $sixMonthCount = $state['six_month_suspension_count'];
    $isBanned = 0;
    $status = 'active';

    if ($newDemerits >= 3) {
        if ($sixMonthCount > 0) {
            // Already had a 6-month suspension -> Permanent Ban
            $actionType = 'permanent_ban';
            $isBanned = 1;
            $status = 'permanently_banned';
            $startAt = date('Y-m-d H:i:s');
            // Permanently lock user login
            if ($userId) {
                mysqli_query($conn, "UPDATE users SET login_access = 0, user_status = 'banned' WHERE user_id = " . (int)$userId);
            }
        } else {
            if ($oneMonthCount >= 2) {
                // 3rd time hitting 3 demerits -> 6-month suspension
                $actionType = 'suspension_6_month';
                $sixMonthCount++;
                $oneMonthCount = 0; // Reset
                $newDemerits = 0; // Reset
                $status = 'suspended_6_month';
                $startAt = date('Y-m-d H:i:s');
                $endAt = date('Y-m-d H:i:s', strtotime('+6 months'));
            } else {
                // 1-month suspension
                $actionType = 'suspension_1_month';
                $oneMonthCount++;
                $newDemerits = 0; // Reset
                $status = 'suspended_1_month';
                $startAt = date('Y-m-d H:i:s');
                $endAt = date('Y-m-d H:i:s', strtotime('+1 month'));
            }
            // Suspend user login
            if ($userId) {
                mysqli_query($conn, "UPDATE users SET login_access = 0, user_status = 'suspended' WHERE user_id = " . (int)$userId);
            }
        }
    }

    // Update state
    $updState = mysqli_prepare($conn, "UPDATE disciplinary_state 
        SET active_demerit_points = ?, one_month_suspension_count = ?, six_month_suspension_count = ?, is_permanently_banned = ?, current_penalty_status = ?, suspension_start_at = ?, suspension_end_at = ?, last_penalty_at = NOW() 
        WHERE state_id = ?");
    if (!$updState) {
        throw new Exception("Unable to complete this action. Please try again.");
    }
    mysqli_stmt_bind_param($updState, "iiiisssi", $newDemerits, $oneMonthCount, $sixMonthCount, $isBanned, $status, $startAt, $endAt, $state['state_id']);
    if (!mysqli_stmt_execute($updState)) {
        error_log("[DrainGuard disciplinary] addDemerit update disciplinary_state execute failed | stmt_error: " . mysqli_stmt_error($updState));
        throw new Exception("Unable to complete this action. Please try again.");
    }
    mysqli_stmt_close($updState);

    // Insert Record
    $insRecord = mysqli_prepare($conn, "INSERT INTO disciplinary_records 
        (user_id, user_role, related_complaint_id, related_team_id, penalty_subject_type, action_type, reason, demerit_points_added, total_demerit_points_after, suspension_count_after, ban_count_after, start_at, end_at, created_by_user_id, created_by_role) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)");
    if (!$insRecord) {
        throw new Exception("Unable to complete this action. Please try again.");
    }
    $recordSubjectType = ($subjectType === 'team_member') ? 'worker' : $subjectType;
    if (!in_array($recordSubjectType, ['inspector', 'team_leader', 'worker'], true)) {
        $recordSubjectType = 'worker';
    }
    $recordActionType = in_array($actionType, ['suspension_1_month', 'suspension_6_month'], true) ? 'suspension' : $actionType;
    $suspensionCountAfter = $oneMonthCount + $sixMonthCount;
    $banCountAfter = $isBanned ? 1 : 0;
    $recordUserId = $userId ?: 0;
    mysqli_stmt_bind_param($insRecord, "isiisssiiissis", 
        $recordUserId, $userRole, $complaintId, $teamId, 
        $recordSubjectType, $recordActionType, $reason, 
        $newDemerits, $suspensionCountAfter, $banCountAfter, 
        $startAt, $endAt, $createdByUserId, $createdByRole);
    if (!mysqli_stmt_execute($insRecord)) {
        error_log("[DrainGuard disciplinary] addDemerit insert disciplinary_records execute failed | stmt_error: " . mysqli_stmt_error($insRecord));
        throw new Exception("Unable to complete this action. Please try again.");
    }
    mysqli_stmt_close($insRecord);

    return $actionType;
}

/**
 * Apply Warning for Team Members
 */
function applyTeamMemberWarningOrDemerit($conn, $memberId, $userId, $teamId, $complaintId, $sourceType, $reason, $createdByUserId, $createdByRole) {
    $state = getDisciplinaryState($conn, $userId, $memberId, 'maintenance_team_member', 'team_member');
    if (!$state || $state['is_permanently_banned']) return false;

    // Has prior warning?
    if ($state['active_warning_count'] > 0 || $state['active_demerit_points'] > 0 || $state['one_month_suspension_count'] > 0 || $state['six_month_suspension_count'] > 0) {
        // Demerit
        return addDemerit($conn, $userId, $memberId, 'maintenance_team_member', 'team_member', $complaintId, $teamId, $sourceType, $reason, $createdByUserId, $createdByRole);
    } else {
        // Warning
        $newWarnings = $state['active_warning_count'] + 1;
        $updState = mysqli_prepare($conn, "UPDATE disciplinary_state 
            SET active_warning_count = ?, current_penalty_status = 'warned', last_penalty_at = NOW() 
            WHERE state_id = ?");
        if (!$updState) {
            throw new Exception("Unable to complete this action. Please try again.");
        }
        mysqli_stmt_bind_param($updState, "ii", $newWarnings, $state['state_id']);
        if (!mysqli_stmt_execute($updState)) {
            error_log("[DrainGuard disciplinary] warning update disciplinary_state execute failed | stmt_error: " . mysqli_stmt_error($updState));
            throw new Exception("Unable to complete this action. Please try again.");
        }
        mysqli_stmt_close($updState);

        $insRecord = mysqli_prepare($conn, "INSERT INTO disciplinary_records 
            (user_id, user_role, related_complaint_id, related_team_id, penalty_subject_type, action_type, reason, demerit_points_added, total_demerit_points_after, suspension_count_after, ban_count_after, created_by_user_id, created_by_role) 
            VALUES (?, 'maintenance_team_member', ?, ?, 'worker', 'warning', ?, 0, 0, 0, 0, ?, ?)");
        if (!$insRecord) {
            throw new Exception("Unable to complete this action. Please try again.");
        }
        $recordUserId = $userId ?: 0;
        mysqli_stmt_bind_param($insRecord, "iiisis", 
            $recordUserId, $complaintId, $teamId, $reason, $createdByUserId, $createdByRole);
        if (!mysqli_stmt_execute($insRecord)) {
            error_log("[DrainGuard disciplinary] warning insert disciplinary_records execute failed | stmt_error: " . mysqli_stmt_error($insRecord));
            throw new Exception("Unable to complete this action. Please try again.");
        }
        mysqli_stmt_close($insRecord);

        return 'warning';
    }
}

/**
 * Restore Suspensions
 */
function restoreExpiredSuspension($conn, $userId) {
    if (!$userId) return;

    $sel = mysqli_prepare($conn, "SELECT * FROM disciplinary_state 
        WHERE user_id = ? 
        AND is_permanently_banned = 0 
        AND suspension_end_at IS NOT NULL 
        AND suspension_end_at <= NOW()
        AND current_penalty_status LIKE 'suspended_%'");
    
    if ($sel) {
        mysqli_stmt_bind_param($sel, "i", $userId);
        mysqli_stmt_execute($sel);
        $res = mysqli_stmt_get_result($sel);
        
        while ($row = mysqli_fetch_assoc($res)) {
            $upd = mysqli_prepare($conn, "UPDATE disciplinary_state 
                SET current_penalty_status = 'active', suspension_start_at = NULL, suspension_end_at = NULL 
                WHERE state_id = ?");
            mysqli_stmt_bind_param($upd, "i", $row['state_id']);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
        }
        mysqli_stmt_close($sel);
        
        // Restore login
        mysqli_query($conn, "UPDATE users SET login_access = 1, user_status = 'active' WHERE user_id = " . (int)$userId . " AND user_status = 'suspended'");
    }
}
?>
