<?php
declare(strict_types=1);

// общий include для админки
header('X-Frame-Options: SAMEORIGIN');

require __DIR__ . '/../config.php'; // должен предоставлять pdo():PDO

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) { $pdo = pdo(); }
  return $pdo;
}

function json_out($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function read_json(): array {
  $raw = file_get_contents('php://input');
  $d = json_decode($raw, true);
  return is_array($d) ? $d : [];
}
