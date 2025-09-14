<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=UTF-8');

function out($ok, $data=[], $code=200){ http_response_code($code); echo json_encode(['ok'=>$ok]+$data, JSON_UNESCAPED_UNICODE); exit; }
function err($msg,$code=400){ out(false, ['error'=>$msg], $code); }

$act = $_GET['action'] ?? 'meta';
date_default_timezone_set($_ENV['TZ'] ?? 'Europe/Tallinn');

try {
  $pdo = pdo();

  if ($act === 'meta') {
    $svcCode = $_GET['svc'] ?? '';
    if ($svcCode==='') err('svc required');
    $svc = $pdo->prepare("SELECT id, code, title_ru AS title, duration_min, price_eur FROM services WHERE code=?");
    $svc->execute([$svcCode]);
    $service = $svc->fetch() ?: err('service not found',404);

    $salons = $pdo->query("SELECT id, name FROM salons ORDER BY name")->fetchAll();
    $staff  = $pdo->query("SELECT id, name FROM staff WHERE is_active=1 ORDER BY name")->fetchAll();
    $map    = $pdo->query("SELECT staff_id, salon_id FROM staff_salons")->fetchAll();

    out(true, ['service'=>$service, 'salons'=>$salons, 'staff'=>$staff, 'staff_salons'=>$map]);
  }

  if ($act === 'slots') {
    $date = $_GET['date'] ?? '';
    $svc  = $_GET['svc']  ?? '';
    if (!$date || !$svc) err('date & svc required');

    $salonId = isset($_GET['salon_id']) && $_GET['salon_id'] !== '' ? (int)$_GET['salon_id'] : null;
    $staffId = isset($_GET['staff_id']) && $_GET['staff_id'] !== '' ? (int)$_GET['staff_id'] : null;

    $service = $pdo->prepare("SELECT id, duration_min FROM services WHERE code=?");
    $service->execute([$svc]);
    $service = $service->fetch() ?: err('service not found',404);
    $dur = max(15, (int)$service['duration_min']);

    // Кого проверяем
    if ($staffId) {
      $staffIds = [$staffId];
    } else {
      if ($salonId){
        $q = $pdo->prepare("SELECT s.id FROM staff s JOIN staff_salons ss ON ss.staff_id=s.id WHERE s.is_active=1 AND ss.salon_id=?");
        $q->execute([$salonId]);
      } else {
        $q = $pdo->query("SELECT id FROM staff WHERE is_active=1");
      }
      $staffIds = array_map(fn($r)=>(int)$r['id'], $q->fetchAll());
    }
    if (!$staffIds) out(true, ['slots'=>[]]);

    $weekday = (int)date('N', strtotime($date)); // 1..7
    $all = [];

    foreach ($staffIds as $sid){
      // рабочие окна
      $wh = $pdo->prepare("SELECT start, `end` FROM working_hours WHERE staff_id=? AND weekday=?");
      $wh->execute([$sid, $weekday]);
      $wh = $wh->fetchAll();

      if (!$wh) continue;

      // занято/отпуск
      $apps = $pdo->prepare("SELECT starts, ends FROM appointments WHERE staff_id=? AND DATE(starts)=? AND status IN ('pending','confirmed')");
      $apps->execute([$sid, $date]);
      $busy = $apps->fetchAll();

      $offs = $pdo->prepare("SELECT starts, ends FROM time_off WHERE staff_id=? AND DATE(starts)<=? AND DATE(ends)>=?");
      $offs->execute([$sid, $date, $date]);
      $busy = array_merge($busy, $offs);

      // кандидаты
      foreach ($wh as $win){
        $winStart = new DateTime("$date {$win['start']}");
        $winEnd   = new DateTime("$date {$win['end']}");
        $step = new DateInterval('PT30M'); // шаг 30 мин
        for ($t=clone $winStart; (clone $t)->add(new DateInterval("PT{$dur}M")) <= $winEnd; $t->add($step)) {
          $start = clone $t;
          $end   = (clone $t)->add(new DateInterval("PT{$dur}M"));
          $free = true;
          foreach ($busy as $b){
            $bs = new DateTime($b['starts']);
            $be = new DateTime($b['ends']);
            if ($start < $be && $end > $bs) { $free=false; break; }
          }
          if ($free) {
            $key = $start->format('H:i');
            $all[$key] = true; // уникальность по времени
          }
        }
      }
    }

    $times = array_keys($all);
    sort($times);
    out(true, ['slots'=>$times]);
  }

  if ($act === 'book') {
    // тут можно сделать реальную запись; пока — заглушка
    out(true, ['result'=>'ok']);
  }

  err('unknown action',404);
} catch (Throwable $e){
  err($e->getMessage(), 500);
}
