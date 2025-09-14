<?php
// booking/api.php
declare(strict_types=1);
require __DIR__.'/config.php';
header('Content-Type: application/json; charset=UTF-8');

try {
  $svcCode = ($_GET['svc'] ?? '');
  $dateStr = ($_GET['date'] ?? '');
  $salonId = isset($_GET['salon']) && $_GET['salon'] !== '' ? (int)$_GET['salon'] : null;
  $staffId = isset($_GET['staff']) && $_GET['staff'] !== '' ? (int)$_GET['staff'] : null;

  if ($dateStr === '') $dateStr = (new DateTime('today'))->format('Y-m-d');
  $date = DateTime::createFromFormat('Y-m-d', $dateStr) ?: new DateTime('today');

  if ($svcCode === '') { echo json_encode(['error'=>'svc required']); exit; }
  $svc = getServiceByCode($svcCode);
  if (!$svc) { echo json_encode(['error'=>'service not found']); exit; }

  $durMin = (int)$svc['duration_min'];
  $stepMin = 30; // шаг сетки

  // Список мастеров
  $sql = "SELECT s.id, s.name, s.tz
            FROM staff s
            ".($salonId ? "JOIN staff_salons ss ON ss.staff_id = s.id AND ss.salon_id = :salonId" : "")."
           WHERE s.is_active = 1
             ".($staffId ? "AND s.id = :staffId" : "")."
           ORDER BY s.name";
  $st = pdo()->prepare($sql);
  if ($salonId) $st->bindValue(':salonId',$salonId,PDO::PARAM_INT);
  if ($staffId) $st->bindValue(':staffId',$staffId,PDO::PARAM_INT);
  $st->execute();
  $staffs = $st->fetchAll();
  if (!$staffs) { echo json_encode(['slots'=>[], 'staff'=>[]]); exit; }

  $weekday = (int)$date->format('N'); // 1..7
  $dayStart = (clone $date)->setTime(0,0,0);
  $dayEnd   = (clone $date)->setTime(23,59,59);

  $result = [];
  foreach ($staffs as $s) {
    // Часы работы
    $st = pdo()->prepare('SELECT start, end FROM working_hours WHERE staff_id=? AND weekday=?');
    $st->execute([$s['id'],$weekday]);
    $wh = $st->fetch();
    if (!$wh) continue;

    [$whStartH,$whStartM] = array_map('intval', explode(':',$wh['start']));
    [$whEndH,$whEndM]     = array_map('intval', explode(':',$wh['end']));

    $ws = (clone $date)->setTime($whStartH,$whStartM);
    $we = (clone $date)->setTime($whEndH,$whEndM);

    // Диапазоны занятости (appointments + time_off)
    $busy = [];

    $st = pdo()->prepare("SELECT starts, ends FROM appointments
                           WHERE staff_id=? AND status IN ('pending','confirmed','noshow')
                             AND NOT (ends <= :ds OR starts >= :de)");
    $st->execute([$s['id'], ':ds'=>$dayStart->format('Y-m-d H:i:s'), ':de'=>$dayEnd->format('Y-m-d H:i:s')]);
    foreach ($st as $row) {
      $busy[] = [ new DateTime($row['starts']), new DateTime($row['ends']) ];
    }

    $st = pdo()->prepare("SELECT starts, ends FROM time_off
                           WHERE staff_id=? AND NOT (ends <= :ds OR starts >= :de)");
    $st->execute([$s['id'], ':ds'=>$dayStart->format('Y-m-d H:i:s'), ':de'=>$dayEnd->format('Y-m-d H:i:s')]);
    foreach ($st as $row) {
      $busy[] = [ new DateTime($row['starts']), new DateTime($row['ends']) ];
    }

    // Генерация слотов
    $slots = [];
    $now = new DateTime('now');
    $cursor = clone $ws;
    $slotDur = new DateInterval('PT'.$durMin.'M');
    $step = new DateInterval('PT'.$stepMin.'M');

    while ($cursor < $we) {
      $slotStart = clone $cursor;
      $slotEnd = (clone $slotStart)->add($slotDur);
      // слот должен уместиться в рабочем окне
      if ($slotEnd > $we) break;
      // не в прошлом
      if ($slotStart <= $now) { $cursor = $cursor->add($step); continue; }
      // проверка пересечений
      $ok = true;
      foreach ($busy as [$b1,$b2]) {
        if (overlaps($slotStart,$slotEnd,$b1,$b2)) { $ok=false; break; }
      }
      if ($ok) $slots[] = [
        'time' => $slotStart->format('H:i'),
        'start'=> $slotStart->format(DateTime::ATOM),
        'end'  => $slotEnd->format(DateTime::ATOM),
        'staff_id' => (int)$s['id'],
        'staff_name'=> $s['name'],
      ];
      $cursor = $cursor->add($step);
    }

    $result[] = ['staff'=>$s, 'slots'=>$slots];
  }

  echo json_encode([
    'date' => $date->format('Y-m-d'),
    'service' => ['code'=>$svc['code'],'duration_min'=>$durMin,'price_eur'=>$svc['price_eur']],
    'data' => $result
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()]);
}
