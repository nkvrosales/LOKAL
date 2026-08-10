<?php
// connect
require_once 'db_connect.php';
$conn = open_database_connection($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$mysqli = $conn;
require_once __DIR__ . "/db_connect.php";
