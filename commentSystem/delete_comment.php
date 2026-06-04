<?php
// C:\xampp8\htdocs\DrainGuard\commentSystem\delete_comment.php

require_once "../config.php";

header("Content-Type: application/json; charset=UTF-8");

function cs_delete_json_response($success, $message, $extra = [])
{
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $extra));
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    cs_delete_json_response(false, "Invalid request method.");
}

$userId = (int)($_SESSION["user_id"] ?? 0);

if ($userId <= 0) {
    cs_delete_json_response(false, "You must be logged in to delete comments.");
}

$commentId = (int)($_POST["comment_id"] ?? 0);

if ($commentId <= 0) {
    cs_delete_json_response(false, "Invalid comment.");
}

$checkSql = "
    SELECT user_id, type
    FROM comment_likes
    WHERE id = ?
      AND is_deleted = 0
    LIMIT 1
";

$checkStmt = mysqli_prepare($conn, $checkSql);

if (!$checkStmt) {
    cs_delete_json_response(false, "Failed to check comment.");
}

mysqli_stmt_bind_param($checkStmt, "i", $commentId);
mysqli_stmt_execute($checkStmt);

$checkResult = mysqli_stmt_get_result($checkStmt);

if (!$checkResult || mysqli_num_rows($checkResult) !== 1) {
    mysqli_stmt_close($checkStmt);
    cs_delete_json_response(false, "Comment not found or already deleted.");
}

$row = mysqli_fetch_assoc($checkResult);
$commentOwner = (int)$row["user_id"];

mysqli_stmt_close($checkStmt);

if ($commentOwner !== $userId) {
    cs_delete_json_response(false, "You don't have permission to delete this.");
}

$deleteSql = "
    UPDATE comment_likes
    SET is_deleted = 1,
        updated_at = NOW()
    WHERE id = ?
";

$deleteStmt = mysqli_prepare($conn, $deleteSql);

if (!$deleteStmt) {
    cs_delete_json_response(false, "Failed to prepare delete query.");
}

mysqli_stmt_bind_param($deleteStmt, "i", $commentId);
if (!mysqli_stmt_execute($deleteStmt)) {
    mysqli_stmt_close($deleteStmt);
    cs_delete_json_response(false, "Failed to delete comment.");
}

mysqli_stmt_close($deleteStmt);

cs_delete_json_response(true, "Comment deleted successfully.", [
    "comment_id" => $commentId
]);