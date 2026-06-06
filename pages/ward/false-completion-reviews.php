<?php
$activePage = "false-completion-reviews";
$pageTitle = "False Completion Reviews";

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "ward_officer") {
    header("Location: ../../index.php");
    exit();
}

if (!isset($conn) || !$conn) {
    die("Database connection not found.");
}

$currentUserId = (int)($_SESSION["user_id"] ?? 0);
$successMessage = "";
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function fetchOne($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) throw new Exception("SQL Prepare Failed: " . mysqli_error($conn));
    if ($types !== "" && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function fetchAllRows($conn, $sql, $types = "", $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) throw new Exception("SQL Prepare Failed: " . mysqli_error($conn));
    if ($types !== "" && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function insertNotification($conn, $tableName, $recipientUserId, $senderUserId, $complaintId, $type, $title, $message, $createdAt)
{
    $allowedTables = [
        "maintenance_notifications",
        "inspector_notifications",
        "citizen_notifications",
        "central_notifications",
        "ward_notifications"
    ];

    if (!in_array($tableName, $allowedTables, true) || $recipientUserId <= 0) {
        return;
    }

    $sql = "INSERT INTO `$tableName` (recipient_user_id, sender_user_id, related_complaint_id, notification_type, notification_title, notification_message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?)";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new Exception("Notification prepare failed: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, "iiissss", $recipientUserId, $senderUserId, $complaintId, $type, $title, $message, $createdAt);
    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception("Notification insert failed: " . $err);
    }
    mysqli_stmt_close($stmt);
}

function tableColumns($conn, $tableName)
{
    $columns = [];
    $safeTable = mysqli_real_escape_string($conn, $tableName);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$safeTable`");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $columns[] = $row["Field"];
        }
    }
    return $columns;
}

function firstExistingColumn($columns, $possibleColumns)
{
    foreach ($possibleColumns as $column) {
        if (in_array($column, $columns, true)) {
            return $column;
        }
    }
    return null;
}

function formatDateOnly($date)
{
    if (!$date) return "N/A";
    $time = strtotime($date);
    if (!$time) return "N/A";
    return date("M d", $time);
}

function makeProofPath($path)
{
    $path = trim((string)$path);
    if ($path === "") return "";
    $path = str_replace("\\", "/", $path);
    if (preg_match("/^https?:\/\//i", $path)) return $path;
    if (str_starts_with($path, "../../")) return $path;
    if (str_starts_with($path, "/")) return $path;
    if (str_starts_with($path, "assets/")) return "../../" . $path;
    if (str_starts_with($path, "uploads/")) return "../../assets/" . $path;
    if (!str_contains($path, "/")) return "../../assets/uploads/complaints/" . $path;
    return "../../" . ltrim($path, "/");
}

$teamColumns = tableColumns($conn, "maintenance_teams");
$teamIdColumn = firstExistingColumn($teamColumns, ["maintenance_team_id", "team_id", "id"]);
$teamNameColumn = firstExistingColumn($teamColumns, ["team_name", "maintenance_team_name", "name"]);
if (!$teamIdColumn || !$teamNameColumn) {
    die("maintenance_teams table must have a team id and team name column.");
}

try {
    $wardOfficer = fetchOne(
        $conn,
        "SELECT
            wo.ward_officer_id,
            wo.user_id,
            wo.assigned_ward_id,
            wo.full_name,
            w.ward_id,
            w.ward_no,
            w.ward_name
        FROM ward_officers wo
        INNER JOIN wards w
            ON wo.assigned_ward_id = w.ward_id
        WHERE wo.user_id = ?
        LIMIT 1",
        "i",
        [$currentUserId]
    );

    if (!$wardOfficer) {
        throw new Exception("No assigned ward found for this Ward Officer.");
    }

    $wardId = (int)$wardOfficer["assigned_ward_id"];
    $wardNo = $wardOfficer["ward_no"] ?? "";
    $wardName = $wardOfficer["ward_name"] ?? "";
    $userName = $wardOfficer["full_name"] ?? ($_SESSION["user_name"] ?? "Ward Officer");
    $_SESSION["user_name"] = $userName;
    $_SESSION["user_role_label"] = "Ward Operations";
} catch (Exception $e) {
    die($e->getMessage());
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $reviewId = (int)($_POST["review_id"] ?? 0);
    $complaintId = (int)($_POST["complaint_id"] ?? 0);
    $action = trim($_POST["action"] ?? "");
    $decisionNote = trim($_POST["decision_note"] ?? "");

    $allowedActions = ["inspector_claim_true", "inspector_claim_false"];

    if ($reviewId <= 0 || $complaintId <= 0 || !in_array($action, $allowedActions, true)) {
        $errorMessage = "Invalid request.";
    } else {
        mysqli_begin_transaction($conn);
        try {
            $reviewCheck = fetchOne(
                $conn,
                "SELECT fcr.*, c.complaint_status, l.ward_id
                FROM false_completion_reviews fcr
                INNER JOIN complaints c ON fcr.complaint_id = c.complaint_id
                INNER JOIN locations l ON c.loc_id = l.loc_id
                WHERE fcr.review_id = ? AND l.ward_id = ? AND fcr.inspector_claim_status = 'pending' LIMIT 1",
                "ii",
                [$reviewId, $wardId]
            );

            if (!$reviewCheck) {
                throw new Exception("This review is not pending or does not belong to your assigned ward.");
            }

            $inspectorUserId = (int)$reviewCheck['inspector_user_id'];
            $teamLeaderUserId = (int)$reviewCheck['team_leader_user_id'];
            $maintenanceTeamId = (int)$reviewCheck['maintenance_team_id'];

            if ($action === "inspector_claim_true") {
                require_once "../../includes/disciplinary_helpers.php";
                
                // Team Leader gets 1 demerit
                addDemerit($conn, $teamLeaderUserId, null, 'team_leader', 'team_leader', $complaintId, $maintenanceTeamId, 'false_completion_claim_true', $decisionNote, $currentUserId, 'ward_officer');

                // Fetch other members for warnings or demerit
                $members = fetchAllRows($conn, "SELECT user_id, member_id FROM maintenance_team_members WHERE maintenance_team_id = ? AND status = 'active' AND user_id != ?", "ii", [$maintenanceTeamId, $teamLeaderUserId]);
                foreach ($members as $member) {
                    applyTeamMemberWarningOrDemerit($conn, $member['member_id'], $member['user_id'], $maintenanceTeamId, $complaintId, 'false_completion_claim_true', $decisionNote, $currentUserId, 'ward_officer');
                }

                // Reopen complaint
                mysqli_query($conn, "UPDATE complaints SET complaint_status = 'reopened' WHERE complaint_id = $complaintId");
                
                // Add to reopen_requests
                $insReq = mysqli_prepare($conn, "INSERT INTO reopen_requests (complaint_id, requested_by, request_type, reason, request_status, ward_note) VALUES (?, ?, 'false_completion', ?, 'pending', ?)");
                mysqli_stmt_bind_param($insReq, "iiss", $complaintId, $currentUserId, $decisionNote, $decisionNote);
                mysqli_stmt_execute($insReq);
                
                $claimStatus = 'true';
                $successMessage = "Inspector claim confirmed true. Maintenance team penalized and complaint reopened.";
            } else {
                require_once "../../includes/disciplinary_helpers.php";
                
                // Inspector gets 1 demerit
                addDemerit($conn, $inspectorUserId, null, 'inspector', 'inspector', $complaintId, null, 'false_completion_claim_false', $decisionNote, $currentUserId, 'ward_officer');

                // Restore complaint status
                mysqli_query($conn, "UPDATE complaints SET complaint_status = 'inspector_verification' WHERE complaint_id = $complaintId");
                
                $claimStatus = 'false';
                $successMessage = "Inspector claim marked false. Inspector penalized and complaint restored to Inspector Verification.";
            }

            // Update false_completion_reviews
            $stmt = mysqli_prepare($conn, "UPDATE false_completion_reviews SET inspector_claim_status = ?, ward_decision_note = ?, ward_officer_user_id = ?, decided_at = NOW() WHERE review_id = ?");
            mysqli_stmt_bind_param($stmt, "ssii", $claimStatus, $decisionNote, $currentUserId, $reviewId);
            mysqli_stmt_execute($stmt);

            // Fetch notifications
            $notifTime = date('Y-m-d H:i:s');
            $complaintCode = fetchOne($conn, "SELECT complaint_code FROM complaints WHERE complaint_id = $complaintId")['complaint_code'] ?? '';
            
            if ($claimStatus === 'true') {
                // Notify Team Leader
                $msgTL = "You received 1 demerit point because the false completion claim for complaint {$complaintCode} was confirmed true.";
                insertNotification($conn, "maintenance_notifications", $teamLeaderUserId, $currentUserId, $complaintId, "system", "Demerit Issued", $msgTL, $notifTime);
                
                // Notify Inspector
                $msgIns = "Your false completion claim for complaint {$complaintCode} was confirmed. Thank you.";
                insertNotification($conn, "inspector_notifications", $inspectorUserId, $currentUserId, $complaintId, "system", "Claim Confirmed", $msgIns, $notifTime);
            } else {
                // Notify Inspector
                $msgIns = "Your false completion claim for complaint {$complaintCode} was rejected. You have received 1 demerit point.";
                insertNotification($conn, "inspector_notifications", $inspectorUserId, $currentUserId, $complaintId, "system", "Demerit Issued", $msgIns, $notifTime);
                
                // Notify Team Leader
                $msgTL = "The false completion claim for complaint {$complaintCode} against your team was rejected. No penalties were applied.";
                insertNotification($conn, "maintenance_notifications", $teamLeaderUserId, $currentUserId, $complaintId, "system", "Claim Rejected", $msgTL, $notifTime);
            }

            mysqli_commit($conn);
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $errorMessage = $e->getMessage();
        }
    }
}

try {
    $requestsSql = "
        SELECT
            fcr.review_id,
            fcr.complaint_id,
            fcr.inspector_claim_note,
            fcr.created_at,
            c.complaint_code,
            c.problem_description,
            i.issue_name,
            a.area_name,
            mt.`$teamNameColumn` AS team_name,
            ui.user_name AS inspector_name,
            mu.proof_file_path,
            mu.proof_file_type,
            mu.completed_at
        FROM false_completion_reviews fcr
        INNER JOIN complaints c ON fcr.complaint_id = c.complaint_id
        INNER JOIN locations l ON c.loc_id = l.loc_id
        LEFT JOIN areas a ON l.area_id = a.area_id
        LEFT JOIN issues i ON c.issue_id = i.issue_id
        LEFT JOIN maintenance_teams mt ON fcr.maintenance_team_id = mt.`$teamIdColumn`
        LEFT JOIN users ui ON fcr.inspector_user_id = ui.user_id
        LEFT JOIN (
            SELECT mu1.* FROM maintenance_updates mu1
            INNER JOIN (
                SELECT complaint_id, MAX(update_id) AS latest_update_id
                FROM maintenance_updates GROUP BY complaint_id
            ) latest_mu ON mu1.update_id = latest_mu.latest_update_id
        ) mu ON c.complaint_id = mu.complaint_id
        WHERE l.ward_id = ? AND fcr.inspector_claim_status = 'pending'
        ORDER BY fcr.created_at DESC
    ";

    $reviews = fetchAllRows($conn, $requestsSql, "i", [$wardId]);
} catch (Exception $e) {
    $reviews = [];
    $errorMessage = $e->getMessage();
}
$totalReviews = count($reviews);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?> | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
    <link rel="stylesheet" href="../../css/ward/reopened-disputed.css">
    <style>
        .rd-card {
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            background: #fff;
            box-shadow: var(--shadow-sm);
        }
        .rd-card h2 {
            font-size: 1.25rem;
            margin-bottom: 1rem;
            color: var(--text-dark);
        }
        .rd-meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .rd-reason-box {
            background: var(--bg-light);
            padding: 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
        }
        .rd-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .rd-btn {
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .rd-btn.true-claim {
            background: var(--primary-color);
            color: #fff;
        }
        .rd-btn.false-claim {
            background: #dc3545;
            color: #fff;
        }
        .decision-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            margin-bottom: 1rem;
            resize: vertical;
        }
    </style>
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>

<body class="ward">

<?php include "../../includes/ward/sidebar.php"; ?>

<main class="ward-main">
    <?php include "../../includes/ward/topbar.php"; ?>

    <section class="rd-page">
        <div class="rd-header">
            <div>
                <h1><?= $pageTitle ?></h1>
                <p>
                    Review false completion claims submitted by Inspectors for Ward <?= safeText($wardNo); ?>.
                </p>
            </div>
        </div>

        <?php if ($successMessage !== ""): ?>
            <div class="rd-alert rd-success" style="background:#d4edda;color:#155724;padding:1rem;margin-bottom:1rem;border-radius:4px;">
                <i class="bi bi-check-circle"></i>
                <?= safeText($successMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ""): ?>
            <div class="rd-alert rd-error" style="background:#f8d7da;color:#721c24;padding:1rem;margin-bottom:1rem;border-radius:4px;">
                <i class="bi bi-exclamation-circle"></i>
                <?= safeText($errorMessage); ?>
            </div>
        <?php endif; ?>

        <div class="rd-list" id="rdList">
            <?php if (!empty($reviews)): ?>
                <?php foreach ($reviews as $review): ?>
                    <?php
                        $reviewId = (int)$review["review_id"];
                        $complaintId = (int)$review["complaint_id"];
                        $complaintCode = $review["complaint_code"] ?? "";
                        $issueName = $review["issue_name"] ?: "Unknown Issue";
                        $areaName = $review["area_name"] ?: "Area not specified";
                        $teamName = $review["team_name"] ?: "No team found";
                        $inspectorName = $review["inspector_name"] ?: "Unknown Inspector";
                        $claimNote = $review["inspector_claim_note"] ?: "No note provided.";
                        $completedAt = formatDateOnly($review["completed_at"] ?? null);
                        
                        $proofPath = makeProofPath($review["proof_file_path"] ?? "");
                        $proofType = strtolower((string)($review["proof_file_type"] ?? ""));
                        $proofText = $review["proof_file_path"]
                            ? "Proof submitted on " . $completedAt
                            : "No completion proof found.";
                    ?>
                    <article class="rd-card">
                        <div class="rd-card-top">
                            <div>
                                <span class="rd-code"><strong><?= safeText($complaintCode); ?></strong></span>
                                <span class="rd-badge disputed">Pending Review</span>
                            </div>
                        </div>

                        <h2><?= safeText($issueName); ?> - False Completion Claim</h2>

                        <div class="rd-meta-grid">
                            <div><span>Area:</span> <strong><?= safeText($areaName); ?></strong></div>
                            <div><span>Team:</span> <strong><?= safeText($teamName); ?></strong></div>
                            <div><span>Inspector:</span> <strong><?= safeText($inspectorName); ?></strong></div>
                        </div>

                        <div class="rd-reason-box">
                            <div class="rd-box-title">
                                <i class="bi bi-chat-square"></i>
                                <span>Inspector's Note</span>
                            </div>
                            <p><?= safeText($claimNote); ?></p>
                        </div>

                        <div class="rd-proof-box" style="margin-bottom:1.5rem;">
                            <div class="rd-box-title"><span>Completion Proof</span></div>
                            <div class="rd-proof-content">
                                <?php if ($proofPath !== "" && ($proofType === "image" || preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $proofPath))): ?>
                                    <a href="<?= safeText($proofPath); ?>" target="_blank" class="rd-proof-thumb">
                                        <img src="<?= safeText($proofPath); ?>" alt="Completion Proof" style="max-width:200px;border-radius:4px;">
                                    </a>
                                <?php elseif ($proofPath !== "" && ($proofType === "video" || preg_match('/\.(mp4|webm|ogg|mov)$/i', $proofPath))): ?>
                                    <video class="rd-proof-video" controls style="max-width:300px;">
                                        <source src="<?= safeText($proofPath); ?>">
                                    </video>
                                <?php else: ?>
                                    <p>No visual proof.</p>
                                <?php endif; ?>
                                <p><?= safeText($proofText); ?></p>
                            </div>
                        </div>

                        <form method="POST" action="false-completion-reviews.php" class="rd-action-form">
                            <input type="hidden" name="review_id" value="<?= $reviewId; ?>">
                            <input type="hidden" name="complaint_id" value="<?= $complaintId; ?>">
                            
                            <label>Ward Officer Decision Note:</label>
                            <textarea name="decision_note" class="decision-textarea" rows="3" required placeholder="Explain your decision..."></textarea>

                            <div class="rd-actions">
                                <button type="submit" name="action" value="inspector_claim_true" class="rd-btn true-claim">
                                    <i class="bi bi-check2-circle"></i> Confirm Inspector Claim (Penalize Team)
                                </button>
                                <button type="submit" name="action" value="inspector_claim_false" class="rd-btn false-claim">
                                    <i class="bi bi-x-circle"></i> Reject Inspector Claim (Penalize Inspector)
                                </button>
                            </div>
                        </form>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="rd-empty" style="text-align:center;padding:3rem;">
                    <i class="bi bi-check-circle" style="font-size:3rem;color:var(--success-color);"></i>
                    <h2>No pending false completion claims</h2>
                    <p>You're all caught up!</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

</main>

<script src="../../js/ward/sidebar.js"></script>

<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>
