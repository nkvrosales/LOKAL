<?php
require_once "auth.php";

if (is_logged_in()) {
    header("Location: home.php");
    exit;
}

header("Location: login.php");
exit;
