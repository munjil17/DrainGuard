<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================================
   DATABASE CONFIGURATION
========================================= */

$host = "localhost";
$username = "root";
$password = "";
$database = "drainguard";

/* =========================================
   DATABASE CONNECTION
========================================= */

$conn = mysqli_connect($host, $username, $password, $database);

/* =========================================
   CONNECTION CHECK
========================================= */

if (!$conn) {

    die("Database connection failed: " . mysqli_connect_error());

}

/* =========================================
   CHARACTER SET
========================================= */

mysqli_set_charset($conn, "utf8mb4");

?>