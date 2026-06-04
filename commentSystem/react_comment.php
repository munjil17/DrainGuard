<?php
// C:\xampp8\htdocs\DrainGuard\commentSystem\react_comment.php

require_once "../config.php";

header("Content-Type: application/json; charset=UTF-8");

function cs_react_json_response($success, $message, $extra = [])
{
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $extra));
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    cs_react_json_response(false, "Invalid request method.");
}

$userId = (int)($_SESSION["user_id"] ?? 0);

if ($userId <= 0) {
    cs_react_json_response(false, "You must be logged in to react.");
}

$commentId = (int)($_POST["comment_id"] ?? 0);
$reactionType = strtolower(trim((string)($_POST["reaction_type"] ?? "")));

if ($commentId <= 0) {
    cs_react_json_response(false, "Invalid comment.");
}

if (!in_array($reactionType, ["like", "dislike"], true)) {
    cs_react_json_response(false, "Invalid reaction type.");
}

$commentSql = "
    SELECT id
    FROM comment_likes
    WHERE id = ?
      AND is_deleted = 0
    LIMIT 1
";

$commentStmt = mysqli_prepare($conn, $commentSql);

if (!$commentStmt) {
    cs_react_json_response(false, "Comment check failed.");
}

mysqli_stmt_bind_param($commentStmt, "i", $commentId);
mysqli_stmt_execute($commentStmt);

$commentResult = mysqli_stmt_get_result($commentStmt);

if (!$commentResult || mysqli_num_rows($commentResult) !== 1) {
    mysqli_stmt_close($commentStmt);
    cs_react_json_response(false, "Comment not found.");
}

mysqli_stmt_close($commentStmt);

$existingSql = "
    SELECT id, type
    FROM comment_likes
    WHERE parent_id = ?
      AND user_id = ?
      AND type IN ('like', 'dislike')
    LIMIT 1
";

$existingStmt = mysqli_prepare($conn, $existingSql);

if (!$existingStmt) {
    cs_react_json_response(false, "Reaction check failed.");
}

mysqli_stmt_bind_param($existingStmt, "ii", $commentId, $userId);
mysqli_stmt_execute($existingStmt);

$existingResult = mysqli_stmt_get_result($existingStmt);
$action = "created";

if ($existingResult && mysqli_num_rows($existingResult) === 1) {
    $existingRow = mysqli_fetch_assoc($existingResult);
    $existingReaction = (string)$existingRow["type"];
    $reactionId = (int)$existingRow["id"];

    mysqli_stmt_close($existingStmt);

    if ($existingReaction === $reactionType) {
        $deleteSql = "
            DELETE FROM comment_likes
            WHERE id = ?
        ";

        $deleteStmt = mysqli_prepare($conn, $deleteSql);

        if (!$deleteStmt) {
            cs_react_json_response(false, "Reaction delete failed.");
        }

        mysqli_stmt_bind_param($deleteStmt, "i", $reactionId);
        mysqli_stmt_execute($deleteStmt);
        mysqli_stmt_close($deleteStmt);

        $action = "removed";
    } else {
        $updateSql = "
            UPDATE comment_likes
            SET type = ?,
                created_at = NOW()
            WHERE id = ?
        ";

        $updateStmt = mysqli_prepare($conn, $updateSql);

        if (!$updateStmt) {
            cs_react_json_response(false, "Reaction update failed.");
        }

        mysqli_stmt_bind_param($updateStmt, "si", $reactionType, $reactionId);
        mysqli_stmt_execute($updateStmt);
        mysqli_stmt_close($updateStmt);

        $action = "updated";
    }
} else {
    mysqli_stmt_close($existingStmt);

    $insertSql = "
        INSERT INTO comment_likes (
            parent_id,
            user_id,
            type,
            created_at
        )
        VALUES (?, ?, ?, NOW())
    ";

    $insertStmt = mysqli_prepare($conn, $insertSql);

    if (!$insertStmt) {
        cs_react_json_response(false, "Reaction insert failed.");
    }

    mysqli_stmt_bind_param($insertStmt, "iis", $commentId, $userId, $reactionType);
    mysqli_stmt_execute($insertStmt);
    mysqli_stmt_close($insertStmt);

    $action = "created";
}

$countSql = "
    SELECT
        SUM(CASE WHEN type = 'like' THEN 1 ELSE 0 END) AS like_count,
        SUM(CASE WHEN type = 'dislike' THEN 1 ELSE 0 END) AS dislike_count
    FROM comment_likes
    WHERE parent_id = ?
";

$countStmt = mysqli_prepare($conn, $countSql);

$likeCount = 0;
$dislikeCount = 0;

if ($countStmt) {
    mysqli_stmt_bind_param($countStmt, "i", $commentId);
    mysqli_stmt_execute($countStmt);

    $countResult = mysqli_stmt_get_result($countStmt);

    if ($countResult) {
        $countRow = mysqli_fetch_assoc($countResult);
        $likeCount = (int)($countRow["like_count"] ?? 0);
        $dislikeCount = (int)($countRow["dislike_count"] ?? 0);
    }

    mysqli_stmt_close($countStmt);
}

$myReaction = "";

if ($action !== "removed") {
    $myReaction = $reactionType;
}

cs_react_json_response(true, "Reaction updated.", [
    "action" => $action,
    "comment_id" => $commentId,
    "like_count" => $likeCount,
    "dislike_count" => $dislikeCount,
    "my_reaction" => $myReaction
]);