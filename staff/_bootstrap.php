<?php
declare(strict_types=1);

/**
 * Общий бутстрап для раздела staff.
 * - тянет PDO из ../booking/config.php (ожидается функция pdo(): PDO)
 * - настраивает сессии
 * - предоставляет require_login() и совместимую require_staff_auth()
 * - функции подстрахованы через function_exists, чтобы не было конфликтов
 */

require_once __DIR__ . '/../booking/config.php'; // должна объявлять pdo(): PDO

/* ---------- Полезные полифилы (безопасно) ---------- */
if (!function_exists('str_contains')) {
  function str_contains(string $haystack, string $needle): bool {
    return $needle === '' || mb_strpos($haystack, $needle) !== false;
  }
}
if (!function_exists('str_ends_with')) {
  function str_ends_with(string $haystack, string $needle): bool {
    if ($needle === '') return true;
    return mb_substr($haystack, -mb_strlen($needle)) === $needle;
  }
}

/* ---------- DB ---------- */
if (!function_exists('db')) {
  function db(): PDO {
    static $dbh = null;
    if ($dbh instanceof PDO) return $dbh;
    $dbh = pdo(); // из booking/config.php
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $dbh;
  }
}
if (!function_exists('pdo_wrap')) {
  function pdo_wrap(): PDO { return db(); }
}

/* ---------- Хелперы ---------- */
if (!function_exists('h')) {
  function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

/* Гибкий подбор колонки (подстрахован от повторного объявления) */
if (!function_exists('pick_col')) {
  function pick_col(PDO $pdo, string $table, array $cands): ?string {
    foreach ($cands as $c) {
      $q = $pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($c));
      if ($q && $q->fetch()) return $c;
    }
    return null;
  }
}

/* ---------- Сессии ---------- */
ini_set('session.cookie_httponly', '1'); // кука недоступна JS
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/* ---------- Определяем: это API-запрос? ---------- */
if (!function_exists('is_api_request')) {
  function is_api_request(): bool {
    $path   = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    $xhr    = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    return (
      str_ends_with($path, 'api.php') ||
      str_contains($accept, 'application/json') ||
      $xhr === 'xmlhttprequest'
    );
  }
}

/* ---------- Auth в сессии ---------- */
if (!function_exists('staff_auth')) {
  function staff_auth(): ?array {
    $u = $_SESSION['staff_auth'] ?? null;
    if (!$u) return null;
    if (!isset($u['staff_id']) || (int)$u['staff_id'] <= 0) return null;
    return $u;
  }
}

/**
 * Требует авторизацию мастера.
 * - Если сессия валидна — вернёт массив пользователя.
 * - Иначе попытается авторизовать по Bearer-токену (колонка staff.api_token, если существует).
 * - Если не авторизован:
 *     - для API: JSON 401 {"ok":false,"error":"auth"}
 *     - для страниц: редирект на /staff/login.php
 */
if (!function_exists('require_login')) {
  function require_login(): array {
    // 1) Сессия
    $u = staff_auth();
    if ($u) return $u;

    // 2) Bearer-токен (опционально)
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth && preg_match('~Bearer\s+(.+)~i', $auth, $m)) {
      $token = trim($m[1]);
      if ($token !== '') {
        try {
          $pdo = db();
          $hasApiToken = (pick_col($pdo, 'staff', ['api_token']) !== null);
          if ($hasApiToken) {
            $st = $pdo->prepare("SELECT id FROM staff WHERE api_token = :t AND is_active = 1 LIMIT 1");
            $st->execute([':t' => $token]);
            $sid = $st->fetchColumn();
            if ($sid) {
              $user = ['staff_id' => (int)$sid, 'username' => '', 'role' => 'master'];
              $_SESSION['staff_auth'] = $user; // закэшируем
              return $user;
            }
          }
        } catch (Throwable $e) {
          // игнорируем и пойдём как неавторизованные
        }
      }
    }

    // 3) Не авторизован
    if (is_api_request()) {
      http_response_code(401);
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['ok'=>false,'error'=>'auth'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    header('Location: /staff/login.php');
    exit;
  }
}

/* Совместимость: в index.php вызывается require_staff_auth() */
if (!function_exists('require_staff_auth')) {
  function require_staff_auth(): array {
    return require_login();
  }
}

/* Логин/лог-аут */
if (!function_exists('login_staff')) {
  function login_staff(int $staff_id, string $username, string $role = 'master'): void {
    $_SESSION['staff_auth'] = [
      'staff_id' => $staff_id,
      'username' => $username,
      'role'     => $role,
    ];
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_regenerate_id(true);
    }
  }
}
if (!function_exists('logout_staff')) {
  function logout_staff(): void {
    unset($_SESSION['staff_auth']);
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_regenerate_id(true);
    }
  }
}
