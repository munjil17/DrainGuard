<?php
// C:\xampp\htdocs\DrainGuard\auth\check_email.php

require_once "../config.php";

header("Content-Type: text/plain; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo "invalid_request";
    exit();
}

$email = strtolower(trim($_POST["email"] ?? ""));
$selectedRole = trim($_POST["selected_role"] ?? $_POST["role"] ?? "");

if ($email === "") {
    echo "empty";
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "invalid_email";
    exit();
}

/*
|--------------------------------------------------------------------------
| Fake Domain Block
|--------------------------------------------------------------------------
*/
$blockedDomains = ['example.com', 'test.com', 'localhost', 'fake.com'];
$domain = substr(strrchr($email, "@"), 1);

if (in_array($domain, $blockedDomains)) {
    echo "blocked_domain";
    exit();
}

/*
|--------------------------------------------------------------------------
| Role Mapping: Frontend Role -> Database Role
|--------------------------------------------------------------------------
*/
$roleMap = [
    "citizen" => ["citizen"],

    "central" => ["central_officer"],
    "central_officer" => ["central_officer"],

    "ward" => ["ward_officer"],
    "ward_officer" => ["ward_officer"],

    "maintenance" => [
        "maintenance_team",
        "maintenance_member",
        "team_leader",
        "assistant_team_leader"
    ],
    "maintenance_team" => ["maintenance_team"],
    "maintenance_member" => ["maintenance_member"],
    "team_leader" => ["team_leader"],
    "assistant_team_leader" => ["assistant_team_leader"],

    "inspector" => ["inspector"]
];

$allowedRoles = [];

if ($selectedRole !== "") {
    $allowedRoles = $roleMap[$selectedRole] ?? [];

    if (empty($allowedRoles)) {
        echo "invalid_role";
        exit();
    }
}

/*
|--------------------------------------------------------------------------
| Query Build
|--------------------------------------------------------------------------
*/
if (!empty($allowedRoles)) {
    $placeholders = implode(",", array_fill(0, count($allowedRoles), "?"));

    $sql = "
        SELECT 
            user_id,
            user_status,
            login_access
        FROM users
        WHERE user_mail = ?
        AND user_role IN ($placeholders)
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        echo "sql_error";
        exit();
    }

    $types = "s" . str_repeat("s", count($allowedRoles));
    $params = array_merge([$email], $allowedRoles);

    mysqli_stmt_bind_param($stmt, $types, ...$params);
} else {
    $sql = "
        SELECT 
            user_id,
            user_status,
            login_access
        FROM users
        WHERE user_mail = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        echo "sql_error";
        exit();
    }

    mysqli_stmt_bind_param($stmt, "s", $email);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) !== 1) {
    mysqli_stmt_close($stmt);
    echo "not_found";
    exit();
}

$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| Account Status Validation
|--------------------------------------------------------------------------
*/
$userStatus = strtolower(trim($user["user_status"] ?? ""));

if ($userStatus !== "active") {
    echo "inactive";
    exit();
}

if (isset($user["login_access"]) && (int)$user["login_access"] !== 1) {
    echo "access_disabled";
    exit();
}

echo "exists";
exit();
?>