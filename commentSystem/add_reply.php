<?php
// C:\xampp8\htdocs\DrainGuard\commentSystem\add_reply.php

require_once "../config.php";

header("Content-Type: application/json; charset=UTF-8");

function cs_reply_json_response($success, $message, $extra = [])
{
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $extra));
    exit;
}

function cs_reply_clean_text($value)
{
    return trim((string)($value ?? ""));
}

function cs_reply_is_discussion_allowed($conn, $complaintId)
{
    $allowedStatuses = [
        "closed",
        "rejected_by_central",
        "rejected_by_ward",
        "duplicate",
        "final_rejected",
        "reopened",
        "disputed"
    ];

    $sql = "
        SELECT complaint_status
        FROM complaints
        WHERE complaint_id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $complaintId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if (!$result || mysqli_num_rows($result) !== 1) {
        mysqli_stmt_close($stmt);
        return false;
    }

    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return in_array((string)$row["complaint_status"], $allowedStatuses, true);
}

function cs_reply_notification_table($role)
{
    $role = strtolower(trim((string)$role));

    if ($role === "citizen") {
        return "citizen_notifications";
    }

    if ($role === "central_officer") {
        return "central_notifications";
    }

    if ($role === "ward_officer") {
        return "ward_notifications";
    }

    if (
        $role === "maintenance_team" ||
        $role === "maintenance_member" ||
        $role === "team_leader" ||
        $role === "assistant_team_leader"
    ) {
        return "maintenance_notifications";
    }

    if ($role === "inspector") {
        return "inspector_notifications";
    }

    return "";
}

function cs_reply_insert_notification($conn, $recipientUserId, $senderUserId, $complaintId, $complaintCode, $recipientRole)
{
    $table = cs_reply_notification_table($recipientRole);

    if ($table === "") {
        return;
    }

    $title = "New Reply to Your Comment";
    $message = "Someone replied to your comment on complaint " . ($complaintCode ?: "#" . $complaintId) . ".";

    $sql = "
        INSERT INTO {$table} (
            recipient_user_id,
            sender_user_id,
            related_complaint_id,
            notification_type,
            notification_title,
            notification_message,
            is_read,
            created_at
        )
        VALUES (?, ?, ?, 'comment_reply', ?, ?, 0, NOW())
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return;
    }

    mysqli_stmt_bind_param($stmt, "iiiss", $recipientUserId, $senderUserId, $complaintId, $title, $message);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    cs_reply_json_response(false, "Invalid request method.");
}

$userId = (int)($_SESSION["user_id"] ?? 0);

if ($userId <= 0) {
    cs_reply_json_response(false, "You must be logged in to reply.");
}

$complaintId = (int)($_POST["complaint_id"] ?? 0);
$parentCommentId = (int)($_POST["parent_comment_id"] ?? 0);
$commentText = cs_reply_clean_text($_POST["comment_text"] ?? "");

if ($complaintId <= 0 || $parentCommentId <= 0) {
    cs_reply_json_response(false, "Invalid reply target.");
}

if ($commentText === "") {
    cs_reply_json_response(false, "Reply cannot be empty.");
}

if (mb_strlen($commentText) > 1000) {
    cs_reply_json_response(false, "Reply cannot exceed 1000 characters.");
}

if (!cs_reply_is_discussion_allowed($conn, $complaintId)) {
    cs_reply_json_response(false, "Discussion is available only after complaint is closed, rejected, duplicate, or final rejected.");
}

$parentSql = "
    SELECT
        cc.id AS comment_id,
        cc.user_id AS parent_user_id,
        u.user_role AS parent_user_role,
        c.complaint_code
    FROM comment_likes cc
    INNER JOIN users u
        ON cc.user_id = u.user_id
    INNER JOIN complaints c
        ON cc.complaint_id = c.complaint_id
    WHERE cc.id = ?
      AND cc.complaint_id = ?
      AND cc.parent_id IS NULL
      AND cc.is_deleted = 0
    LIMIT 1
";

$parentStmt = mysqli_prepare($conn, $parentSql);

if (!$parentStmt) {
    cs_reply_json_response(false, "Reply prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($parentStmt, "ii", $parentCommentId, $complaintId);
mysqli_stmt_execute($parentStmt);

$parentResult = mysqli_stmt_get_result($parentStmt);

if (!$parentResult || mysqli_num_rows($parentResult) !== 1) {
    mysqli_stmt_close($parentStmt);
    cs_reply_json_response(false, "Parent comment not found.");
}

$parentRow = mysqli_fetch_assoc($parentResult);
mysqli_stmt_close($parentStmt);

$sql = "
    INSERT INTO comment_likes (
        complaint_id,
        user_id,
        parent_id,
        type,
        comment_text,
        created_at
    )
    VALUES (?, ?, ?, 'comment', ?, NOW())
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    cs_reply_json_response(false, "Failed to prepare reply insert.");
}

mysqli_stmt_bind_param($stmt, "iiis", $complaintId, $userId, $parentCommentId, $commentText);
mysqli_stmt_execute($stmt);

if (mysqli_stmt_affected_rows($stmt) <= 0) {
    mysqli_stmt_close($stmt);
    cs_reply_json_response(false, "Failed to insert reply.");
}

$replyId = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

$parentUserId = (int)$parentRow["parent_user_id"];

if ($parentUserId !== $userId) {
    cs_reply_insert_notification(
        $conn,
        $parentUserId,
        $userId,
        $complaintId,
        $parentRow["complaint_code"] ?? "",
        $parentRow["parent_user_role"] ?? ""
    );
}

cs_reply_json_response(true, "Reply added successfully.", [
    "reply_id" => $replyId
]);