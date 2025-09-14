<?php
declare(strict_types=1);

function env(string $k, $d=null){ $v=getenv($k); return ($v===false)?$d:$v; }

$DB_HOST = env('DB_HOST','db');
$DB_NAME = env('DB_NAME','booking');
$DB_USER = env('DB_USER','app');
$DB_PASS = env('DB_PASS','app');
$TZ      = env('APP_TZ','Europe/Tallinn');
date_default_timezone_set($TZ);

function pdo(): PDO {
  static $pdo=null; if ($pdo) return $pdo;
  global $DB_HOST,$DB_NAME,$DB_USER,$DB_PASS;
  $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

function getServiceByCode(string $code){
  $st = pdo()->prepare("SELECT * FROM services WHERE code=? LIMIT 1");
  $st->execute([$code]);
  return $st->fetch();
}

function getSalons(): array {
  return pdo()->query("SELECT id,name FROM salons ORDER BY id")->fetchAll();
}
function getStaff(?int $salonId=null): array {
  if ($salonId){
    $st = pdo()->prepare("SELECT s.id,s.name FROM staff s
      JOIN staff_salons ss ON ss.staff_id=s.id
      WHERE s.is_active=1 AND ss.salon_id=? ORDER BY s.name");
    $st->execute([$salonId]);
    return $st->fetchAll();
  }
  return pdo()->query("SELECT id,name FROM staff WHERE is_active=1 ORDER BY name")->fetchAll();
}
function overlaps(DateTime $a1, DateTime $a2, DateTime $b1, DateTime $b2): bool {
  return ($a1 < $b2) && ($b1 < $a2);
}
