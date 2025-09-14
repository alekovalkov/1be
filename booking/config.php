<?php
declare(strict_types=1);

/**
 * booking/config.php — единая точка подключения к БД
 * Работает как в Docker (docker-compose), так и вне его.
 *
 * ВАЖНО:
 * - Никакого вывода (echo/var_dump) здесь быть не должно.
 * - НЕ ставь закрывающий тег PHP "?>", чтобы случайные пробелы не ломали JSON/заголовки.
 */

// Таймзона: сначала из переменной окружения APP_TZ, иначе — Tallinn.
date_default_timezone_set(getenv('APP_TZ') ?: 'Europe/Tallinn');

// ---------- Параметры БД ----------
// 1) Если задан DB_DSN — используем как есть.
// 2) Иначе собираем DSN из DB_HOST/DB_PORT/DB_NAME (по умолчанию — docker-compose значения).
$DB_DSN  = getenv('DB_DSN') ?: '';
$DB_HOST = getenv('DB_HOST') ?: 'db';
$DB_PORT = (int)(getenv('DB_PORT') ?: 3306);
$DB_NAME = getenv('DB_NAME') ?: 'booking';
$DB_USER = getenv('DB_USER') ?: 'app';
$DB_PASS = getenv('DB_PASS') ?: 'app';

// Если DSN не задан, соберём стандартный mysql DSN с utf8mb4:
if ($DB_DSN === '') {
  $DB_DSN = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $DB_HOST, $DB_PORT, $DB_NAME);
}

/**
 * Единственный PDO-инстанс на запрос.
 * @return PDO
 */
function pdo(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) {
    return $pdo;
  }

  $dsn  = $GLOBALS['DB_DSN'];
  $user = $GLOBALS['DB_USER'];
  $pass = $GLOBALS['DB_PASS'];

  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    // charset уже в DSN; init-команда не требуется
  ]);

  return $pdo;
}

/**
 * Выполнить SELECT и вернуть все строки
 * @param string $sql
 * @param array $params
 * @return array<int, array<string, mixed>>
 */
function db_all(string $sql, array $params = []): array {
  $st = pdo()->prepare($sql);
  $st->execute($params);
  return $st->fetchAll();
}

/**
 * Выполнить SELECT и вернуть одну строку или null
 * @param string $sql
 * @param array $params
 * @return array<string, mixed>|null
 */
function db_one(string $sql, array $params = []): ?array {
  $st = pdo()->prepare($sql);
  $st->execute($params);
  $row = $st->fetch();
  return $row === false ? null : $row;
}

/* =========================
   ХЕЛПЕРЫ ДЛЯ ТЕЛЕФОНОВ
   ========================= */

/**
 * Привести телефон к формату E.164.
 * Если номер без кода страны — добавим $defaultCountry (по умолчанию +372).
 * Примеры:
 *   "555 12 345"      -> "+37255512345"
 *   "+7 (999) 123-45" -> "+799912345"
 */
function phone_to_e164(string $raw, string $defaultCountry = '+372'): string {
  $raw = trim($raw);
  if ($raw === '') return '';

  // Оставляем только цифры и плюс
  $s = preg_replace('/[^\d+]+/', '', $raw) ?? '';
  if ($s === '') return '';

  // Если нет плюса — подставим код страны и оставим одни цифры после него
  if ($s[0] !== '+') {
    $digits = preg_replace('/\D+/', '', $s) ?? '';
    $s = $defaultCountry . $digits;
  }

  // Нормализуем: ведущий плюс + только цифры
  $s = '+' . (preg_replace('/\D+/', '', $s) ?? '');
  return $s;
}

/**
 * Простая проверка формата E.164.
 * Допускаем + и 7–15 цифр.
 */
function phone_is_valid_e164(string $e164): bool {
  return (bool)preg_match('/^\+\d{7,15}$/', $e164);
}
