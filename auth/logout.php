<?php
// C:\xampp\htdocs\DrainGuard\auth\logout.php

require_once "../config.php";

/*
|--------------------------------------------------------------------------
| Clear Remember Me Token & Cookie
|--------------------------------------------------------------------------
| If the user logs out manually, we must delete their "Remember Me" 
| token from the database and remove the cookie from their browser.
|--------------------------------------------------------------------------
*/
if (isset($_COOKIE['remember_me'])) {
    $cookieParts = explode(':', $_COOKIE['remember_me']);
    
    if (count($cookieParts) === 2) {
        $selector = $cookieParts[0];
        
        // Delete token from database
        if (isset($conn)) {
            $delSql = "DELETE FROM remember_tokens WHERE selector = ?";
            $delStmt = mysqli_prepare($conn, $delSql);
            if ($delStmt) {
                mysqli_stmt_bind_param($delStmt, "s", $selector);
                mysqli_stmt_execute($delStmt);
                mysqli_stmt_close($delStmt);
            }
        }
    }
    
    // Expire the cookie in the browser
    setcookie(
        'remember_me',
        '',
        time() - 3600,
        '/',
        '',
        isset($_SERVER['HTTPS']),
        true
    );
}

/*
|--------------------------------------------------------------------------
| Clear all session data
|--------------------------------------------------------------------------
*/
$_SESSION = [];

/*
|--------------------------------------------------------------------------
| Delete session cookie
|--------------------------------------------------------------------------
*/
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

/*
|--------------------------------------------------------------------------
| Destroy session
|--------------------------------------------------------------------------
*/
session_destroy();

/*
|--------------------------------------------------------------------------
| Redirect to login
|--------------------------------------------------------------------------
*/
header("Location: " . $baseUrl . "auth/login.php");
exit();
?>