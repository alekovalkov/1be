<?php
$pass = $_GET['p'] ?? 'test123';
echo password_hash($pass, PASSWORD_DEFAULT);
