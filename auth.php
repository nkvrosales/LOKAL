<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_logged_in(): bool
{
    return isset($_SESSION["user_id"]);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header("Location: login.php");
        exit;
    }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}
