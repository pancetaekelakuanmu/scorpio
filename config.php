<?php
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', 'mukegile');
define('DB_NAME', 'casino');

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($mysqli->connect_error) {
    die('Connection failed: ' . $mysqli->connect_error);
}

define('CALLBACK_TOKEN', '85c268d3-6eb8-4c91-ace6-17b7a7d28616');

mysqli_set_charset($mysqli, "utf8");

?>
