<?php
require_once "auth.php";

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

$redirect = isset($_GET["redirect"]) && $_GET["redirect"] === "admin" ? "login.php" : "login.php";
header("Location: " . $redirect);
exit;
