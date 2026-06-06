<?php
$pageTitle = "Upload Completion Proof";
$activePage = "upload-completion-proof";

require_once "../../config.php";
require_once "../../auth/session_check.php";

$userId = $_SESSION['user_id'] ?? 0;

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function jsonResponse($success, $message)
{
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);
    exit;
}

$allowedImageTypes = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
    'image/bmp',
    'image/svg+xml'
];

$allowedVideoTypes = [
    'video/mp4',
    'video/webm',
    'video/ogg',
    'video/quicktime',
    'video/x-msvideo',
    'video/x-matroska'
];

$allowedExtensions = [
    'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg',
    'mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv'
];

$maxFileSize = 25 * 1024 * 1024;

$teamInfo = [
    'team_id' => 0,
    'team_name' => 'Maintenance Team',
    'member_name' => $_SESSION['user_name'] ?? 'Maintenance User'
];

if ($userId > 0) {
    $teamSql = "
        SELECT
            mt.maintenance_team_id,
            mt.team_name,
            mtm.full_name
        FROM maintenance_team_members mtm
        INNER JOIN maintenance_teams mt
            ON mt.maintenance_team_id = mtm.maintenance_team_id
        WHERE mtm.user_id = ?
        LIMIT 1
    ";

    $teamStmt = mysqli_prepare($conn, $teamSql);

    if ($teamStmt) {
        mysqli_stmt_bind_param($teamStmt, "i", $userId);
        mysqli_stmt_execute($teamStmt);
        $teamResult = mysqli_stmt_get_result($teamStmt);

        if ($teamResult && mysqli_num_rows($teamResult) > 0) {
            $teamRow = mysqli_fetch_assoc($teamResult);

            $teamInfo['team_id'] = (int)$teamRow['maintenance_team_id'];
            $teamInfo['team_name'] = $teamRow['team_name'] ?? $teamInfo['team_name'];
            $teamInfo['member_name'] = $teamRow['full_name'] ?? $teamInfo['member_name'];
        }

        mysqli_stmt_close($teamStmt);
    }
}

$teamId = (int)$teamInfo['team_id'];

/* =========================================================
   AJAX HANDLER: SUBMIT PROOF TO INSPECTOR
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_completion_proof') {
    $assignmentId = isset($_POST['assignment_id']) ? (int)$_POST['assignment_id'] : 0;
    $proofNote = trim($_POST['proof_note'] ?? '');

    if ($userId <= 0 || $teamId <= 0) {
        jsonResponse(false, 'Invalid session. Please login again.');
    }

    if ($assignmentId <= 0) {
        jsonResponse(false, 'Invalid assignment selected.');
    }

    if ($proofNote === '') {
        jsonResponse(false, 'Please write work completion notes.');
    }

    if (empty($_FILES['after_files']) || empty($_FILES['after_files']['name'][0])) {
        jsonResponse(false, 'Please upload at least one after-work photo or video.');
    }

    $taskSql = "
        SELECT
            ca.assignment_id,
            ca.complaint_id,
            ca.maintenance_team_id,
            ca.assignment_status,
            c.complaint_status
        FROM complaint_assignments ca
        INNER JOIN complaints c
            ON c.complaint_id = ca.complaint_id
        WHERE ca.assignment_id = ?
        AND ca.maintenance_team_id = ?
        LIMIT 1
    ";

    $taskStmt = mysqli_prepare($conn, $taskSql);

    if (!$taskStmt) {
        jsonResponse(false, 'Unable to verify this task. Please try again.');
    }

    mysqli_stmt_bind_param($taskStmt, "ii", $assignmentId, $teamId);
    mysqli_stmt_execute($taskStmt);
    $taskResult = mysqli_stmt_get_result($taskStmt);

    if (!$taskResult || mysqli_num_rows($taskResult) === 0) {
        mysqli_stmt_close($taskStmt);
        jsonResponse(false, 'This task is not assigned to your team.');
    }

    $taskRow = mysqli_fetch_assoc($taskResult);
    mysqli_stmt_close($taskStmt);

    if ($taskRow['assignment_status'] !== 'in_progress' || $taskRow['complaint_status'] !== 'in_progress') {
        jsonResponse(false, 'Only in-progress tasks can submit proof to Inspector.');
    }

    $complaintId = (int)$taskRow['complaint_id'];
    $maintenanceTeamId = (int)$taskRow['maintenance_team_id'];

    $complaintCode = '';
    $citizenUserId = 0;
    $centralOfficerUserId = 0;
    $wardOfficerUserId = 0;
    $inspectorUserId = 0;

    $fetchComplaintSql = "SELECT complaint_code, user_id, loc_id FROM complaints WHERE complaint_id = ?";
    $stmtC = mysqli_prepare($conn, $fetchComplaintSql);
    mysqli_stmt_bind_param($stmtC, "i", $complaintId);
    mysqli_stmt_execute($stmtC);
    $resC = mysqli_stmt_get_result($stmtC);
    $locId = 0;
    if ($rowC = mysqli_fetch_assoc($resC)) {
        $complaintCode = $rowC['complaint_code'];
        $citizenUserId = (int)$rowC['user_id'];
        $locId = (int)$rowC['loc_id'];
    }
    mysqli_stmt_close($stmtC);

    $fetchCentralSql = "SELECT ca.assigned_by FROM complaint_assignments ca JOIN users u ON u.user_id = ca.assigned_by WHERE ca.complaint_id = ? AND u.user_role = 'central_officer' LIMIT 1";
    $stmtCentral = mysqli_prepare($conn, $fetchCentralSql);
    mysqli_stmt_bind_param($stmtCentral, "i", $complaintId);
    mysqli_stmt_execute($stmtCentral);
    $resCentral = mysqli_stmt_get_result($stmtCentral);
    if ($rowCentral = mysqli_fetch_assoc($resCentral)) {
        $centralOfficerUserId = (int)$rowCentral['assigned_by'];
    }
    mysqli_stmt_close($stmtCentral);

    if ($locId > 0) {
        $fetchWardOfficerSql = "
            SELECT wo.user_id 
            FROM locations l
            JOIN ward_officers wo ON wo.assigned_ward_id = l.ward_id AND wo.city_cor_id = l.city_cor_id
            WHERE l.loc_id = ? LIMIT 1
        ";
        $stmtWo = mysqli_prepare($conn, $fetchWardOfficerSql);
        mysqli_stmt_bind_param($stmtWo, "i", $locId);
        mysqli_stmt_execute($stmtWo);
        $resWo = mysqli_stmt_get_result($stmtWo);
        if ($rowWo = mysqli_fetch_assoc($resWo)) {
            $wardOfficerUserId = (int)$rowWo['user_id'];
        }
        mysqli_stmt_close($stmtWo);

        $fetchInspectorSql = "
            SELECT i.user_id 
            FROM locations l
            JOIN inspectors i ON i.assigned_ward_id = l.ward_id AND i.city_cor_id = l.city_cor_id
            WHERE l.loc_id = ? LIMIT 1
        ";
        $stmtInsp = mysqli_prepare($conn, $fetchInspectorSql);
        mysqli_stmt_bind_param($stmtInsp, "i", $locId);
        mysqli_stmt_execute($stmtInsp);
        $resInsp = mysqli_stmt_get_result($stmtInsp);
        if ($rowInsp = mysqli_fetch_assoc($resInsp)) {
            $inspectorUserId = (int)$rowInsp['user_id'];
        }
        mysqli_stmt_close($stmtInsp);
    }

    /* Duplicate proof prevention */
    $checkProofSql = "
        SELECT proof_id
        FROM maintenance_proofs
        WHERE assignment_id = ?
        AND proof_stage = 'after'
        AND proof_status = 'submitted'
        LIMIT 1
    ";

    $checkProofStmt = mysqli_prepare($conn, $checkProofSql);

    if (!$checkProofStmt) {
        jsonResponse(false, 'Unable to check this proof upload. Please try again.');
    }

    mysqli_stmt_bind_param($checkProofStmt, "i", $assignmentId);
    mysqli_stmt_execute($checkProofStmt);
    $checkProofResult = mysqli_stmt_get_result($checkProofStmt);

    if ($checkProofResult && mysqli_num_rows($checkProofResult) > 0) {
        mysqli_stmt_close($checkProofStmt);
        jsonResponse(false, 'Proof already submitted for this task.');
    }

    mysqli_stmt_close($checkProofStmt);

    $uploadDirRelative = "assets/uploads/maintenance_proofs/";
    $uploadDirAbsolute = "../../" . $uploadDirRelative;

    if (!is_dir($uploadDirAbsolute)) {
        mkdir($uploadDirAbsolute, 0777, true);
    }

    $preparedFiles = [];
    $fileCount = count($_FILES['after_files']['name']);

    for ($i = 0; $i < $fileCount; $i++) {
        $originalName = $_FILES['after_files']['name'][$i] ?? '';
        $tmpName = $_FILES['after_files']['tmp_name'][$i] ?? '';
        $error = $_FILES['after_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        $fileSize = $_FILES['after_files']['size'][$i] ?? 0;
        $mimeType = $_FILES['after_files']['type'][$i] ?? '';

        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($error !== UPLOAD_ERR_OK) {
            jsonResponse(false, 'File upload failed for: ' . $originalName);
        }

        if ($fileSize > $maxFileSize) {
            jsonResponse(false, 'Each file must be 25MB or less.');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            jsonResponse(false, 'Unsupported file extension: ' . $extension);
        }

        $detectedMime = $mimeType;

        if (function_exists('mime_content_type') && is_uploaded_file($tmpName)) {
            $detectedMime = mime_content_type($tmpName);
        }

        if (in_array($detectedMime, $allowedImageTypes, true)) {
            $mediaType = 'image';
        } elseif (in_array($detectedMime, $allowedVideoTypes, true)) {
            $mediaType = 'video';
        } else {
            jsonResponse(false, 'Unsupported file type: ' . $originalName);
        }

        $newFileName = 'proof_' . $assignmentId . '_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
        $targetAbsolute = $uploadDirAbsolute . $newFileName;
        $targetRelative = $uploadDirRelative . $newFileName;

        $preparedFiles[] = [
            'tmp_name' => $tmpName,
            'target_absolute' => $targetAbsolute,
            'target_relative' => $targetRelative,
            'original_name' => $originalName,
            'file_size' => $fileSize,
            'mime_type' => $detectedMime,
            'media_type' => $mediaType
        ];
    }

    if (count($preparedFiles) === 0) {
        jsonResponse(false, 'Please upload at least one valid after-work file.');
    }

    mysqli_begin_transaction($conn);

    try {
        $insertProofSql = "
            INSERT INTO maintenance_proofs (
                assignment_id,
                complaint_id,
                maintenance_team_id,
                uploaded_by,
                proof_stage,
                media_type,
                media_path,
                original_name,
                file_size,
                mime_type,
                proof_note,
                proof_status
            ) VALUES (?, ?, ?, ?, 'after', ?, ?, ?, ?, ?, ?, 'submitted')
        ";

        $insertProofStmt = mysqli_prepare($conn, $insertProofSql);

        if (!$insertProofStmt) {
            throw new Exception('Unable to submit completion proof. Please try again.');
        }

        foreach ($preparedFiles as $file) {
            if (!move_uploaded_file($file['tmp_name'], $file['target_absolute'])) {
                throw new Exception('Failed to move uploaded file: ' . $file['original_name']);
            }

            mysqli_stmt_bind_param(
                $insertProofStmt,
                "iiiisssiss",
                $assignmentId,
                $complaintId,
                $maintenanceTeamId,
                $userId,
                $file['media_type'],
                $file['target_relative'],
                $file['original_name'],
                $file['file_size'],
                $file['mime_type'],
                $proofNote
            );

            if (!mysqli_stmt_execute($insertProofStmt)) {
                throw new Exception('Failed to save proof file.');
            }
        }

        mysqli_stmt_close($insertProofStmt);

        $updateAssignmentSql = "
            UPDATE complaint_assignments
            SET assignment_status = 'completed'
            WHERE assignment_id = ?
        ";

        $updateAssignmentStmt = mysqli_prepare($conn, $updateAssignmentSql);

        if (!$updateAssignmentStmt) {
            throw new Exception('Unable to update this assignment. Please try again.');
        }

        mysqli_stmt_bind_param($updateAssignmentStmt, "i", $assignmentId);

        if (!mysqli_stmt_execute($updateAssignmentStmt)) {
            throw new Exception('Failed to update assignment status.');
        }

        mysqli_stmt_close($updateAssignmentStmt);

        $updateComplaintSql = "
            UPDATE complaints
            SET
                complaint_status = 'solved_by_team',
                updated_at = NOW()
            WHERE complaint_id = ?
        ";

        $updateComplaintStmt = mysqli_prepare($conn, $updateComplaintSql);

        if (!$updateComplaintStmt) {
            throw new Exception('Unable to update this complaint. Please try again.');
        }

        mysqli_stmt_bind_param($updateComplaintStmt, "i", $complaintId);

        if (!mysqli_stmt_execute($updateComplaintStmt)) {
            throw new Exception('Failed to update complaint status.');
        }

        mysqli_stmt_close($updateComplaintStmt);

        $updateTeamSql = "
            UPDATE maintenance_teams
            SET availability_status = 'available'
            WHERE maintenance_team_id = ?
        ";

        $updateTeamStmt = mysqli_prepare($conn, $updateTeamSql);

        if (!$updateTeamStmt) {
            throw new Exception('Unable to update team availability. Please try again.');
        }

        mysqli_stmt_bind_param($updateTeamStmt, "i", $maintenanceTeamId);

        if (!mysqli_stmt_execute($updateTeamStmt)) {
            throw new Exception('Failed to update team availability.');
        }

        mysqli_stmt_close($updateTeamStmt);

        $notifTime = date('Y-m-d H:i:s');
        $maintenanceTeamName = $teamInfo['team_name'];

        $notifType = 'maintenance_completion_proof_submitted';
        $notifTitle = 'Completion Proof Submitted';
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
        }

        mysqli_commit($conn);

        jsonResponse(true, 'Proof submitted to Inspector successfully.');
    } catch (Exception $e) {
        mysqli_rollback($conn);

        foreach ($preparedFiles as $file) {
            if (file_exists($file['target_absolute'])) {
                unlink($file['target_absolute']);
            }
        }

        jsonResponse(false, $e->getMessage());
    }
}

$tasks = [];

if ($teamId > 0) {
    $filterAssignmentSql = "";
    $params = [$teamId];
    $types = "i";

    if (isset($_GET['assignment_id']) && (int)$_GET['assignment_id'] > 0) {
        $filterAssignmentSql = " AND ca.assignment_id = ? ";
        $params[] = (int)$_GET['assignment_id'];
        $types .= "i";
    }

    $taskSql = "
        SELECT
            ca.assignment_id,
            ca.complaint_id,
            ca.ward_id,
            ca.maintenance_team_id,
            ca.assigned_by,
            ca.assignment_status,
            ca.assigned_at,
            ca.deadline_at,
            ca.assignment_priority,
            ca.task_note,

            c.complaint_code,
            c.complaint_status,
            c.address_description,
            c.problem_description,
            c.work_started_at,
            c.submitted_at,
            c.updated_at,

            cm.media_path,
            cm.media_type,
            cm.original_name,

            w.ward_no,
            w.ward_name,

            a.area_name,

            u.user_name AS assigned_by_name
        FROM complaint_assignments ca
        INNER JOIN complaints c
            ON c.complaint_id = ca.complaint_id
        LEFT JOIN wards w
            ON w.ward_id = ca.ward_id
        LEFT JOIN locations l
            ON l.loc_id = c.loc_id
        LEFT JOIN areas a
            ON a.area_id = l.area_id
        LEFT JOIN users u
            ON u.user_id = ca.assigned_by
        LEFT JOIN (
            SELECT complaint_id, MIN(media_id) AS first_media_id
            FROM complaint_media
            GROUP BY complaint_id
        ) first_media
            ON first_media.complaint_id = c.complaint_id
        LEFT JOIN complaint_media cm
            ON cm.media_id = first_media.first_media_id
        WHERE ca.maintenance_team_id = ?
        AND ca.assignment_status = 'in_progress'
        AND c.complaint_status = 'in_progress'
        $filterAssignmentSql
        ORDER BY c.work_started_at DESC, ca.assigned_at DESC
    ";

    $taskStmt = mysqli_prepare($conn, $taskSql);

    if ($taskStmt) {
        mysqli_stmt_bind_param($taskStmt, $types, ...$params);
        mysqli_stmt_execute($taskStmt);
        $taskResult = mysqli_stmt_get_result($taskStmt);

        while ($taskResult && $row = mysqli_fetch_assoc($taskResult)) {
            $tasks[] = $row;
        }

        mysqli_stmt_close($taskStmt);
    }
}

$totalTasks = count($tasks);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upload Completion Proof | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/maintenance/sidebar.css">
    <link rel="stylesheet" href="../../css/maintenance/topbar.css">
    <link rel="stylesheet" href="../../css/maintenance/upload-completion-proof.css">
</head>

<body class="maintenance">
    <div class="maintenance-layout">
        <?php include "../../includes/maintenance/sidebar.php"; ?>

        <main class="maintenance-main">
            <?php include "../../includes/maintenance/topbar.php"; ?>

            <section class="proof-page">
                <div class="page-heading">
                    <h1>Upload Completion Proof</h1>
                    <p>Submit completed work evidence to Inspector for verification</p>
                </div>

                <div class="proof-alert">
                    <div class="alert-icon">
                        <i class="bi bi-upload"></i>
                    </div>

                    <div>
                        <h2><?php echo e($totalTasks); ?> Tasks Waiting for Proof Upload</h2>
                        <p>Upload after-work photos or videos with completion notes</p>
                    </div>
                </div>

                <div class="proof-task-list">
                    <?php if (count($tasks) > 0): ?>
                        <?php foreach ($tasks as $task): ?>
                            <?php
                            $mediaPath = $task['media_path'] ?? '';
                            $mediaType = $task['media_type'] ?? '';
                            $hasMedia = !empty($mediaPath);

                            $wardText = 'Ward not found';

                            if (!empty($task['ward_no'])) {
                                $wardText = 'Ward ' . $task['ward_no'];
                            } elseif (!empty($task['ward_name'])) {
                                $wardText = $task['ward_name'];
                            }

                            $areaText = !empty($task['area_name']) ? $task['area_name'] : 'Area not found';

                            $markedText = 'Not available';

                            if (!empty($task['work_started_at'])) {
                                $markedText = date("M d, Y h:i A", strtotime($task['work_started_at']));
                            }
                            ?>

                            <article class="proof-card">
                                <div class="proof-card-head">
                                    <div>
                                        <div class="task-badges">
                                            <span class="code-badge"><?php echo e($task['complaint_code']); ?></span>
                                            <span class="status-badge">In Progress</span>
                                        </div>

                                        <h2>Drainage Complaint</h2>

                                        <p class="task-meta">
                                            Area:
                                            <strong><?php echo e($areaText); ?>, <?php echo e($wardText); ?></strong>
                                            <span>•</span>
                                            Started:
                                            <strong><?php echo e($markedText); ?></strong>
                                        </p>
                                    </div>
                                </div>

                                <div class="before-photo-box">
                                    <h3>
                                        <i class="bi bi-image"></i>
                                        Before Photo / Video From Complaint
                                    </h3>

                                    <div class="before-photo-content">
                                        <div class="before-media">
                                            <?php if ($hasMedia && $mediaType === 'image'): ?>
                                                <img src="../../<?php echo e($mediaPath); ?>" alt="Before complaint media">
                                            <?php elseif ($hasMedia && $mediaType === 'video'): ?>
                                                <video src="../../<?php echo e($mediaPath); ?>" controls></video>
                                            <?php else: ?>
                                                <div class="media-placeholder">
                                                    <i class="bi bi-image"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="before-info">
                                            <p><strong>Submitted by:</strong> Citizen</p>
                                            <p><strong>Location:</strong> <?php echo e($task['address_description'] ?: 'Address not provided'); ?></p>
                                            <p><strong>Description:</strong> <?php echo e($task['problem_description'] ?: 'No description provided'); ?></p>
                                        </div>
                                    </div>
                                </div>

                                <form class="proof-form" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="submit_completion_proof">
                                    <input type="hidden" name="assignment_id" value="<?php echo e($task['assignment_id']); ?>">

                                    <div class="after-upload-box">
                                        <h3>
                                            <i class="bi bi-upload"></i>
                                            Upload After Photos / Videos Required
                                        </h3>

                                        <label class="upload-drop-zone">
                                            <input type="file" name="after_files[]" class="after-files-input" accept="image/*,video/*" multiple>
                                            <i class="bi bi-cloud-arrow-up"></i>
                                            <strong>Click to upload completion files</strong>
                                            <span>Upload multiple images or videos showing completed work</span>
                                        </label>

                                        <div class="preview-grid"></div>
                                    </div>

                                    <div class="completion-note-box">
                                        <h3>
                                            <i class="bi bi-file-text"></i>
                                            Work Completion Notes
                                        </h3>

                                        <textarea 
                                            name="proof_note" 
                                            class="completion-note" 
                                            placeholder="Describe the work completed, additional findings, materials used, etc..."
                                        ></textarea>
                                    </div>

                                    <button type="submit" class="submit-proof-btn">
                                        <i class="bi bi-send"></i>
                                        Submit to Inspector
                                    </button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-check2-circle"></i>
                            <h2>No task waiting for proof upload</h2>
                            <p>Only in-progress tasks will appear here for completion proof submission.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <script src="../../js/maintenance/sidebar.js"></script>
    <script src="../../js/maintenance/upload-completion-proof.js"></script>
</body>
</html>