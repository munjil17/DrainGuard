<?php
// C:\xampp8\htdocs\DrainGuard\commentSystem\add_comment.php

require_once "../config.php";

header("Content-Type: application/json; charset=UTF-8");

function cs_json_response($success, $message, $extra = [])
{
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $extra));
    exit;
}

function cs_clean_text($value)
{
    return trim((string)($value ?? ""));
}

function cs_is_discussion_allowed($conn, $complaintId)
{
    $allowedStatuses = [
        "closed",
        "rejected_by_central",
        "rejected_by_ward",
        "duplicate",
        "final_rejected"
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

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    cs_json_response(false, "Invalid request method.");
}

$userId = (int)($_SESSION["user_id"] ?? 0);

if ($userId <= 0) {
    cs_json_response(false, "You must be logged in to comment.");
}

$complaintId = (int)($_POST["complaint_id"] ?? 0);
$commentText = cs_clean_text($_POST["comment_text"] ?? "");

if ($complaintId <= 0) {
    cs_json_response(false, "Invalid complaint.");
}

if ($commentText === "") {
    cs_json_response(false, "Comment cannot be empty.");
}

if (mb_strlen($commentText) > 1000) {
    cs_json_response(false, "Comment cannot exceed 1000 characters.");
}

if (!cs_is_discussion_allowed($conn, $complaintId)) {
    cs_json_response(false, "Discussion is available only after complaint is closed, rejected, duplicate, or final rejected.");
}

$sql = "
    INSERT INTO complaint_comments (
        complaint_id,
        user_id,
        parent_comment_id,
        comment_text,
        is_deleted,
        created_at,
        updated_at
    )
    VALUES (?, ?, NULL, ?, 0, NOW(), NULL)
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    cs_json_response(false, "Comment prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "iis", $complaintId, $userId, $commentText);

if (!mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    cs_json_response(false, "Failed to add comment.");
}

$commentId = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

cs_json_response(true, "Comment added successfully.", [
    "comment_id" => $commentId
]);