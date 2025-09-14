<?php
// app/_db.php
$host = getenv('DB_HOST') ?: 'db';
$db   = getenv('DB_NAME') ?: 'booking';
$user = getenv('DB_USER') ?: 'appuser';
$pass = getenv('DB_PASS') ?: 'apppass';

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4",$user,$pass,[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
date_default_timezone_set('Europe/Tallinn');
