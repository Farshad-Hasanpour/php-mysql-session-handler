<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Session Test</title>
</head>
<body>
<?php
use my_session\MysqlSessionHandler;
require_once '../MysqlSessionHandler.php';

$db = new PDO("mysql:host=localhost;dbname=mysession", "root", "secret");
$handler = new MysqlSessionHandler($db);
$test = new SessionHandler();
session_set_save_handler($handler, true);
session_start();
$_SESSION['username']='farshad';
$_SESSION['authenticated'] = 'true';
session_write_close();
session_start();
$_SESSION['new']='test';
$_SESSION['information'] = 'true';
session_write_close();
$_SESSION['information'] = 'false';
session_start();
session_write_close();
?>
<h2>PHP MySQL Session Handler</h2>
<ul>
	<li>
		<a href="read_session_info.php" style="text-decoration: none;"><h3>read session info from database</h3></a>
	</li>
</ul>
<br>
</body>
</html>
