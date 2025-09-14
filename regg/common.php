<?php
declare(strict_types=1);

// общий include для админки
header('X-Frame-Options: SAMEORIGIN');

/**
 * Находим config.php в типичных местах:
 * - рядом с корнем сайта: /config.php
 * - в родительской папке от текущей: ../config.php
 * - в booking/: /booking/config.php
 */
(function () {
  $candidates = [
    __DIR__ . '/../config.php',
    __DIR__ . '/../../config.php',
    $_SERVER['DOCUMENT_ROOT'] . '/config.php',
    $_SERVER['DOCUMENT_ROOT'] . '/booking/config.php',
  ];
  foreach ($candidates as $p) {
    if ($p && @is_file($p)) { require_once $p; return; }
  }
  http_response_code(500);
  echo "config.php не найден. Пробовал:\n<pre>" .
       htmlspecialchars(implode("\n", $candidates), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') .
       "</pre>";
  exit;
})();

/**
 * База для ссылок админки.
 * Если перенёс в /regg — префикс будет /regg/.
 * Можно переопределить через ENV ADMIN_BASE.
 */
if (!defined('ADMIN_BASE')) {
  $guess = rtrim(str_replace($_SERVER['DOCUMENT_ROOT'], '', str_replace('\\', '/', __DIR__)), '/');
  if ($guess === '' || $guess[0] !== '/') $guess = '/regg'; // безопасная догадка
  define('ADMIN_BASE', rtrim(getenv('ADMIN_BASE') ?: $guess, '/') . '/');
}

/** PDO из глобального config.php */
function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    // В твоём config.php должна быть функция pdo():PDO
    $pdo = pdo();
  }
  return $pdo;
}

/** Удобные хелперы */
function admin_url(string $path = ''): string {
  $path = ltrim($path, '/');
  return ADMIN_BASE . $path;
}
function redirect_admin(string $path = ''): void {
  header('Location: ' . admin_url($path));
  exit;
}
function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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