<?php
require_once "auth.php";

if (is_logged_in() && ($_SESSION["account_type"] ?? "") === "admin") {
    header("Location: admin/dashboard.php");
    exit;
}

header("Location: home.php");
exit;
