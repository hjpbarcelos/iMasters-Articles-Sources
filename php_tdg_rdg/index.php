<?php
require_once 'Driver.php';
require_once 'Table.php';
require_once 'Row.php';

error_reporting(E_ALL | E_STRICT);

$connector = new MySQLi('localhost', '****', '****', '****');
$driver = new Driver($connector);
$table = new Table('usuario', $driver);
