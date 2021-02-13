<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Session Test</title>
</head>
<body>
	<h4>Please dont forget to set database info</h4>
	<h2>Session information from database is:</h2>
<?php
$db_name = 'mysession';
$db_user = 'root';
$db_password = 'secret';
use my_session\MysqlSessionHandler;
require_once '../MysqlSessionHandler.php';
$db = new PDO("mysql:host=localhost;dbname=${db_name}", $db_user, $db_password);
$handler = new MysqlSessionHandler($db);
session_set_save_handler($handler, true);
session_start();
var_dump($_SESSION);
?>

</body>
</html>
