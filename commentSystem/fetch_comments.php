<?php
// C:\xampp8\htdocs\DrainGuard\commentSystem\fetch_comments.php

require_once "../config.php";

header("Content-Type: application/json; charset=UTF-8");

function cs_fetch_json_response($success, $message, $extra = [])
{
    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $extra));
    exit;
}

function cs_fetch_safe_role_label($role)
{
    $labels = [
        "citizen" => "Citizen",
        "central_officer" => "Central Officer",
        "ward_officer" => "Ward Officer",
        "maintenance_team" => "Maintenance Team",
        "maintenance_member" => "Maintenance Member",
        "team_leader" => "Maintenance Team Leader",
        "assistant_team_leader" => "Assistant Team Leader",
        "inspector" => "Inspector"
    ];

    return $labels[$role] ?? ucwords(str_replace("_", " ", (string)$role));
}

$userId = (int)($_SESSION["user_id"] ?? 0);

if ($userId <= 0) {
    cs_fetch_json_response(false, "You must be logged in to view comments.");
}

$complaintId = (int)($_GET["complaint_id"] ?? 0);

if ($complaintId <= 0) {
    cs_fetch_json_response(false, "Invalid complaint.");
}

$sql = "
    SELECT
        cc.comment_id,
        cc.complaint_id,
        cc.user_id,
        cc.parent_comment_id,
        cc.comment_text,
        cc.is_deleted,
        cc.created_at,
        cc.updated_at,

        u.user_name,
        u.user_role,

        COALESCE(SUM(CASE WHEN cl.reaction_type = 'like' THEN 1 ELSE 0 END), 0) AS like_count,
        COALESCE(SUM(CASE WHEN cl.reaction_type = 'dislike' THEN 1 ELSE 0 END), 0) AS dislike_count,

        my_reaction.reaction_type AS my_reaction

    FROM complaint_comments cc

    INNER JOIN users u
        ON cc.user_id = u.user_id

    LEFT JOIN comment_likes cl
        ON cc.comment_id = cl.comment_id

    LEFT JOIN comment_likes my_reaction
        ON cc.comment_id = my_reaction.comment_id
       AND my_reaction.user_id = ?

    WHERE cc.complaint_id = ?

    GROUP BY
        cc.comment_id,
        cc.complaint_id,
        cc.user_id,
        cc.parent_comment_id,
        cc.comment_text,
        cc.is_deleted,
        cc.created_at,
        cc.updated_at,
        u.user_name,
        u.user_role,
        my_reaction.reaction_type

    ORDER BY cc.created_at ASC, cc.comment_id ASC
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    cs_fetch_json_response(false, "Comment fetch prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "ii", $userId, $complaintId);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$commentsById = [];
$rootComments = [];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $commentId = (int)$row["comment_id"];
        $parentId = $row["parent_comment_id"] !== null ? (int)$row["parent_comment_id"] : null;

        $isDeleted = ((int)$row["is_deleted"] === 1);

        $commentData = [
            "comment_id" => $commentId,
            "complaint_id" => (int)$row["complaint_id"],
            "user_id" => (int)$row["user_id"],
            "parent_comment_id" => $parentId,
            "comment_text" => $isDeleted ? "This comment was deleted." : (string)$row["comment_text"],
            "is_deleted" => $isDeleted,
            "created_at" => (string)$row["created_at"],
            "updated_at" => $row["updated_at"],
            "user_name" => (string)$row["user_name"],
            "user_role" => (string)$row["user_role"],
            "user_role_label" => cs_fetch_safe_role_label((string)$row["user_role"]),
            "like_count" => (int)$row["like_count"],
            "dislike_count" => (int)$row["dislike_count"],
            "my_reaction" => $row["my_reaction"] ?? "",
            "can_delete" => (!$isDeleted && (int)$row["user_id"] === $userId),
            "replies" => []
        ];

        $commentsById[$commentId] = $commentData;
    }
}

mysqli_stmt_close($stmt);

foreach ($commentsById as $commentId => $commentData) {
    $parentId = $commentData["parent_comment_id"];

    if ($parentId === null) {
        $rootComments[$commentId] = $commentData;
    } else {
        if (isset($commentsById[$parentId])) {
            $commentsById[$parentId]["replies"][] = $commentData;
        }
    }
}

foreach ($commentsById as $commentId => $commentData) {
    if ($commentData["parent_comment_id"] === null) {
        $rootComments[$commentId] = $commentData;
    }
}

cs_fetch_json_response(true, "Comments loaded.", [
    "comments" => array_values($rootComments)
]);