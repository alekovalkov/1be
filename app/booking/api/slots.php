<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
header('Content-Type: application/json; charset=utf-8');

try{
  $svcCode = $_GET['svc'] ?? '';
  if ($svcCode==='') throw new RuntimeException('Missing svc');
  $service = getServiceByCode($svcCode);
  if (!$service) throw new RuntimeException('Service not found');

  $dateStr = $_GET['date'] ?? (new DateTime('today'))->format('Y-m-d');
  $date = DateTime::createFromFormat('Y-m-d', $dateStr);
  if (!$date) throw new RuntimeException('Bad date');

  $salonId = isset($_GET['salon']) && $_GET['salon']!=='' ? (int)$_GET['salon'] : null;
  $staffId = isset($_GET['staff']) && $_GET['staff']!=='' ? (int)$_GET['staff'] : null;

  $salons = getSalons();
  $staffList = getStaff($salonId);
  if ($staffId){ $staffList = array_values(array_filter($staffList, fn($r)=> (int)$r['id']===$staffId)); }

  $weekday = (int)$date->format('N');
  $durMin  = max(15, (int)$service['duration_min']);
  $stepMin = 30;

  $times = [];
  foreach ($staffList as $stf){
    $sid = (int)$stf['id'];

    $st = pdo()->prepare("SELECT start, `end` FROM working_hours WHERE staff_id=? AND weekday=? LIMIT 1");
    $st->execute([$sid,$weekday]);
    $wh = $st->fetch();
    if (!$wh) continue;

    $dayStart = new DateTime($date->format('Y-m-d').' '.$wh['start']);
    $dayEnd   = new DateTime($date->format('Y-m-d').' '.$wh['end']);
    if ($dayEnd <= $dayStart) continue;

    $st = pdo()->prepare("SELECT starts, ends FROM time_off
      WHERE staff_id=? AND DATE(starts) <= ? AND DATE(ends) >= ?");
    $st->execute([$sid,$date->format('Y-m-d'),$date->format('Y-m-d')]);
    $breaks=[];
    foreach($st as $r){
      $b1=new DateTime($r['starts']); $b2=new DateTime($r['ends']);
      if ($b1 < $dayStart) $b1=clone $dayStart;
      if ($b2 > $dayEnd)   $b2=clone $dayEnd;
      if ($b2>$b1) $breaks[]=[$b1,$b2];
    }

    $st = pdo()->prepare("SELECT starts, ends FROM appointments
      WHERE staff_id=? AND DATE(starts)=? AND status IN ('pending','confirmed')");
    $st->execute([$sid,$date->format('Y-m-d')]);
    $busy=[];
    foreach($st as $r){ $busy[]=[new DateTime($r['starts']), new DateTime($r['ends'])]; }

    $slot=clone $dayStart;
    $dur = new DateInterval('PT'.$durMin.'M');
    $step= new DateInterval('PT'.$stepMin.'M');
    while(true){
      $slotEnd=(clone $slot)->add($dur);
      if ($slotEnd > $dayEnd) break;

      $ok=true;
      foreach($breaks as [$b1,$b2]){ if (overlaps($slot,$slotEnd,$b1,$b2)) { $ok=false; break; } }
      if ($ok) foreach($busy as [$a1,$a2]){ if (overlaps($slot,$slotEnd,$a1,$a2)) { $ok=false; break; } }

      if ($ok) $times[$slot->format('H:i')]=true;
      $slot=$slot->add($step);
    }
  }

  ksort($times);
  echo json_encode([
    'success'=>true,
    'service'=>[
      'code'=>$service['code'],
      'title'=>$service['title_ru'],
      'duration_min'=>(int)$service['duration_min'],
      'price_eur'=>(float)$service['price_eur'],
    ],
    'salons'=>$salons,
    'staff'=>getStaff($salonId),
    'times'=>array_keys($times),
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
