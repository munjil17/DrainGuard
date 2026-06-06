<?php
require_once "../../config.php";
require_once "../../auth/session_check.php";

$activePage = "notifications";
$pageTitle = "Inspection Request Details";
$pageParent = "Field Inspection";
$pageChild = "Request Details";

$userId = (int)($_SESSION["user_id"] ?? 0);
$instructionId = (int)($_GET["id"] ?? 0);

if ($userId <= 0 || $instructionId <= 0) {
    header("Location: notifications.php");
    exit();
}

function id_safe($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$sql = "
    SELECT 
        ri.instruction_title, 
        ri.instruction_message, 
        ri.created_at,
        u.user_name AS sender_name,
        w.ward_no,
        w.ward_name
    FROM role_instructions ri
    LEFT JOIN users u ON ri.sender_user_id = u.user_id
    LEFT JOIN wards w ON ri.ward_id = w.ward_id
    WHERE ri.instruction_id = ? 
    AND ri.receiver_user_id = ? 
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    die("Unable to load this page. Please try again.");
}

mysqli_stmt_bind_param($stmt, "ii", $instructionId, $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$instruction = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$instruction) {
    echo "<script>showWarningModal('Inspection request not found or access denied.'); window.location.href='notifications.php';</script>";
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo id_safe($pageTitle); ?> | DrainGuard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/inspector/sidebar.css">
    <link rel="stylesheet" href="../../css/inspector/topbar.css">
    <style>
        .id-page {
            padding: 24px;
        }
        .id-header {
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .id-back-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #FFFFFF;
            color: #475569;
            text-decoration: none;
            border: 1px solid #E2E8F0;
            transition: all 0.2s ease;
        }
        .id-back-btn:hover {
            background: #F8FAFC;
            color: #0F766E;
            border-color: #CBD5E1;
        }
        .id-header h1 {
            margin: 0;
            font-size: 24px;
            color: #0F172A;
        }
        .id-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 32px;
            max-width: 800px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .id-type {
            display: inline-block;
            background: #FEF2F2;
            color: #DC2626;
            padding: 6px 16px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 20px;
        }
        .id-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            padding-bottom: 30px;
            border-bottom: 1px solid #E2E8F0;
        }
        .id-meta-item {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .id-meta-label {
            font-size: 13px;
            color: #64748B;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .id-meta-value {
            font-size: 15px;
            color: #0F172A;
            font-weight: 500;
        }
        .id-message-label {
            font-size: 16px;
            color: #0F172A;
            font-weight: 700;
            margin-bottom: 12px;
        }
        .id-message-body {
            font-size: 15px;
            color: #334155;
            line-height: 1.6;
            background: #F8FAFC;
            padding: 24px;
            border-radius: 8px;
            border-left: 4px solid #DC2626;
            white-space: pre-wrap;
        }
        @media (max-width: 768px) {
            .id-card { padding: 24px; }
        }
    </style>
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>
<body class="inspector">
<div class="inspector-layout">
    <?php include "../../includes/inspector/sidebar.php"; ?>
    <main class="inspector-main">
        <?php include "../../includes/inspector/topbar.php"; ?>
        <section class="id-page">
            <div class="id-header">
                <a href="notifications.php" class="id-back-btn" title="Back to Notifications">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <h1>Inspection Request Details</h1>
            </div>
            
            <div class="id-card">
                <div class="id-type">
                    <i class="bi bi-clipboard2-check"></i> 
                    <?php echo id_safe($instruction['instruction_title']); ?>
                </div>
                
                <div class="id-meta">
                    <div class="id-meta-item">
                        <span class="id-meta-label">Sender</span>
                        <span class="id-meta-value"><?php echo id_safe($instruction['sender_name']); ?> (Central Officer)</span>
                    </div>
                    <div class="id-meta-item">
                        <span class="id-meta-label">Ward</span>
                        <span class="id-meta-value"><?php echo id_safe($instruction['ward_name'] ?: 'Ward ' . $instruction['ward_no']); ?></span>
                    </div>
                    <div class="id-meta-item">
                        <span class="id-meta-label">Date & Time</span>
                        <span class="id-meta-value"><?php echo date("d M Y, h:i A", strtotime($instruction['created_at'])); ?></span>
                    </div>
                </div>
                
                <div class="id-message-label">Request Message:</div>
                <div class="id-message-body"><?php echo id_safe($instruction['instruction_message']); ?></div>
            </div>
        </section>
    </main>
</div>
<script src="../../js/inspector/sidebar.js"></script>
<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>
