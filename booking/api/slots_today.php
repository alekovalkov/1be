<?php
declare(strict_types=1);

/**
 * /booking/api/slots_today.php
 * Возвращает ближайшие слоты с учётом выбранных услуг и персональных длительностей мастеров.
 *
 * GET:
 *   services  = comma-separated keys (напр. manicure_cover,gel_polish)
 *   sum_min   = число (фоллбэк, если не нашли персональные длительности)
 *   lang      = et|en|ru|ua  (не критично)
 *
 * Ответ JSON: { ok:true, items:[{time:"10:30", staff_id:3, staff:"Marina", salon:"Kassi 6"}...] }
 */

require __DIR__ . '/../../admin/common.php'; // даёт db():PDO

$pdo = db();

$services = array_values(array_filter(array_map(
  fn($s)=>trim((string)$s),
  explode(',', (string)($_GET['services'] ?? ''))
)));
$sumMinFallback = max(5, (int)($_GET['sum_min'] ?? 0)); // если 0 — позже заменим на сумму найденных

$today = new DateTimeImmutable('now'); // серверное время
$weekday = (int)$today->format('w');   // 0..6 (в БД подстрой)
$horizonHours = 12;                    // горизон поиска
$grid = 5;                             // сетка 5 минут
$limitPerStaff = 2;                    // максимум слотов на мастера
$totalLimit = 12;                      // максимум всего

// --- утилиты интервалов ---
function subIntervals(array $free, array $busy): array {
  // free: [[start,end],...] busy: [[start,end],...]
  $res=[];
  foreach($free as $F){
    [$fs,$fe]=$F; $segments=[[$fs,$fe]];
    foreach($busy as $B){
      [$bs,$be]=$B; $n=[];
      foreach($segments as [$s,$e]){
        if ($be <= $s || $bs >= $e){ $n[]=[$s,$e]; continue; }
        if ($bs > $s) $n[]=[$s,$bs];
        if ($be < $e) $n[]=[$be,$e];
      }
      $segments = $n;
      if (!$segments) break;
    }
    foreach($segments as $seg){ if ($seg[1]>$seg[0]) $res[]=$seg; }
  }
  return $res;
}
function ceilToGrid(DateTimeImmutable $t, int $gridMin): DateTimeImmutable {
  $m = (int)$t->format('i'); $r = $m % $gridMin;
  if ($r===0) return $t->setTime((int)$t->format('H'), $m, 0);
  $delta = $gridMin - $r;
  return $t->modify("+{$delta} minutes");
}

// --- загрузка справочников ---
function loadActiveStaff(PDO $pdo): array {
  $rows = $pdo->query("SELECT id, name, is_active FROM staff WHERE is_active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
  $out=[]; foreach($rows as $r){ $out[(int)$r['id']] = ['id'=>(int)$r['id'],'name'=>$r['name']]; }
  return $out;
}
function loadWorkingHours(PDO $pdo, int $weekday): array {
  // ожидаем: working_hours(staff_id, weekday 0..6, start TIME, end TIME, salon_id)
  $st = $pdo->prepare("SELECT staff_id, start, end, salon_id FROM working_hours WHERE weekday=?");
  $st->execute([$weekday]);
  $by=[]; foreach($st as $r){
    $by[(int)$r['staff_id']][] = [
      'start'=>$r['start'],
      'end'=>$r['end'],
      'salon_id'=> isset($r['salon_id'])?(int)$r['salon_id']:null,
    ];
  }
  return $by;
}
function loadSalonNames(PDO $pdo): array {
  $rows=$pdo->query("SELECT id,name FROM salons")->fetchAll(PDO::FETCH_ASSOC);
  $m=[]; foreach($rows as $r){ $m[(int)$r['id']]=$r['name']; } return $m;
}
function loadTimeOff(PDO $pdo, DateTimeImmutable $from, DateTimeImmutable $to): array {
  $st=$pdo->prepare("SELECT staff_id, start_at, end_at FROM time_off WHERE end_at > ? AND start_at < ?");
  $st->execute([$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
  $by=[]; foreach($st as $r){
    $by[(int)$r['staff_id']][] = [ new DateTimeImmutable($r['start_at']), new DateTimeImmutable($r['end_at']) ];
  } return $by;
}
function loadAppointments(PDO $pdo, DateTimeImmutable $from, DateTimeImmutable $to): array {
  // предполагаем appointments(start_at,end_at,status in ('booked','confirmed'…))
  $st=$pdo->prepare("SELECT staff_id, start_at, end_at FROM appointments WHERE end_at > ? AND start_at < ? AND status NOT IN ('cancelled','no_show')");
  $st->execute([$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
  $by=[]; foreach($st as $r){
    $by[(int)$r['staff_id']][] = [ new DateTimeImmutable($r['start_at']), new DateTimeImmutable($r['end_at']) ];
  } return $by;
}
function loadStaffServiceDurations(PDO $pdo, array $staffIds, array $services): array {
  // Ожидаем таблицу: staff_services(staff_id INT, service_key VARCHAR, duration_min INT, active TINYINT)
  if (!$staffIds || !$services) return [];
  $inStaff = implode(',', array_fill(0, count($staffIds), '?'));
  $inSvc   = implode(',', array_fill(0, count($services), '?'));
  $sql = "SELECT staff_id, service_key, duration_min, active FROM staff_services
          WHERE staff_id IN ($inStaff) AND service_key IN ($inSvc)";
  $st = $pdo->prepare($sql);
  $st->execute(array_merge($staffIds, $services));
  $out=[];
  foreach($st as $r){
    $sid=(int)$r['staff_id']; $k=$r['service_key']; $dur=(int)$r['duration_min']; $act=(int)$r['active'];
    $out[$sid][$k] = ['dur'=>$dur,'active'=>$act];
  }
  return $out;
}

// --- расчёт ---
$staff = loadActiveStaff($pdo);
if (!$staff){ echo json_encode(['ok'=>true,'items'=>[]]); exit; }

$from = new DateTimeImmutable('now');
$to   = $from->modify("+{$horizonHours} hours");
$work = loadWorkingHours($pdo, $weekday);
$off  = loadTimeOff($pdo, $from, $to);
$apps = loadAppointments($pdo, $from, $to);
$salonNames = loadSalonNames($pdo);

$staffIds = array_keys($staff);
$svcDur = loadStaffServiceDurations($pdo, $staffIds, $services);

$items=[];

foreach ($staff as $sid=>$S){
  // актив по услугам?
  $sumDur = 0;
  $supported = true;

  if ($services){
    foreach($services as $svc){
      if (isset($svcDur[$sid][$svc])){
        if ((int)$svcDur[$sid][$svc]['active'] !== 1){ $supported=false; break; }
        $sumDur += max(5, (int)$svcDur[$sid][$svc]['dur']);
      } else {
        // нет персонального — возьмём из sum_min (фоллбэк)
        $sumDur += max(5, $sumMinFallback ?: 0);
      }
    }
  } else {
    $sumDur = max(5, $sumMinFallback ?: 30);
  }

  if (!$supported || $sumDur<=0) continue;
  if (empty($work[$sid])) continue;

  // свободные интервалы на сегодня в пределах горизонта
  $free=[];
  foreach($work[$sid] as $w){
    $ws = new DateTimeImmutable($today->format('Y-m-d').' '.$w['start']);
    $we = new DateTimeImmutable($today->format('Y-m-d').' '.$w['end']);
    // ограничим горизонтом
    if ($we <= $from || $ws >= $to) continue;
    if ($ws < $from) $ws=$from;
    if ($we > $to)   $we=$to;
    $free[] = [$ws,$we,'salon_id'=>($w['salon_id']??null)];
  }

  if (!$free) continue;

  // занятое = time_off + appointments
  $busy = array_merge($off[$sid] ?? [], $apps[$sid] ?? []);
  // вычитаем
  $freeStripped=[];
  foreach($free as $interval){
    [$a,$b]=$interval;
    $res = subIntervals([[$a,$b]], $busy);
    foreach($res as $r){ $freeStripped[]=$r; }
  }

  if (!$freeStripped) continue;

  // ищем стартовые точки
  $found=0;
  foreach($freeStripped as [$fs,$fe]){
    // не раньше текущего времени и по сетке
    $start = ceilToGrid($fs, $grid);
    while ($start < $fe){
      $end = $start->modify("+{$sumDur} minutes");
      if ($end <= $fe){
        $items[]=[
          'time'=>$start->format('H:i'),
          'dt'  =>$start->format('Y-m-d H:i:s'),
          'staff_id'=>$sid,
          'staff'=>$S['name'],
          'salon'=> null, // если нужно — подтяни по working_hours в расширенную схему
        ];
        $found++;
        if ($found >= $limitPerStaff) break 2;
        // следующий поиск — через небольшой шаг, чтобы не слипались
        $start = $start->modify("+".max($grid, min(15,$sumDur/2))." minutes");
        continue;
      }
      break;
    }
  }
}

// сортируем и ограничиваем
usort($items, fn($a,$b)=>strcmp($a['dt'],$b['dt']));
$items = array_slice($items, 0, $totalLimit);

// чистим служебные поля
foreach($items as &$it){ unset($it['dt']); }

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE);