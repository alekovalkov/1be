<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['client_id'])) {
  $next = $_SERVER['REQUEST_URI'] ?? '/client/';
  header('Location: /login.php?next=' . urlencode($next), true, 302);
  exit;
}
