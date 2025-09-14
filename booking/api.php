<?php
declare(strict_types=1);

/**
 * API для онлайн-календаря
 * Таблицы: salons, staff, staff_salons, services, working_hours, time_off, appointments, clients
 * Требует config.php с функцией pdo(): PDO
 */

/* CORS/контент */
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { echo json_encode(['ok'=>true]); exit; }

require __DIR__ . '/config.php'; // должна быть функция pdo():PDO
session_start();

/* ===== НАСТРОЙКИ ===== */
const SLOT_STEP_MIN = 30;            // шаг сетки в минутах
const LEAD_MIN      = 120;           // нельзя бронировать раньше чем через Х минут
const DEFAULT_TZ    = 'Europe/Tallinn';
date_default_timezone_set(DEFAULT_TZ); // страховка, чтобы "сейчас" было в нужной TZ

/* ===== ХЕЛПЕРЫ (общие) ===== */
function out(array $arr): void { echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function tz(): DateTimeZone { return new DateTimeZone(DEFAULT_TZ); }
function now_tz(): DateTimeImmutable { return new DateTimeImmutable('now', tz()); }
function today_ymd(): string { return now_tz()->format('Y-m-d'); }
function lead_cutoff(): DateTimeImmutable { return now_tz()->modify('+'.LEAD_MIN.' minutes'); }
function norm_time(string $t): string { if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/',$t)) return strlen($t)===5?$t.':00':$t; return $t; }
function ceil_to_step(DateTime $dt, int $stepMin): DateTime {
  $sec=(int)$dt->format('s'); if($sec>0) $dt->modify('+'.(60-$sec).' seconds');
  $m=(int)$dt->format('i'); $mod=$m%$stepMin; if($mod>0) $dt->modify('+'.($stepMin-$mod).' minutes'); return $dt;
}
function overlaps(DateTime $a1, DateTime $a2, DateTime $b1, DateTime $b2): bool { return ($a1 < $b2) && ($a2 > $b1); }
function weekday1(string $ymd): int { return (int)date('N', strtotime($ymd.' 00:00:00')); }

/* ---- JSON body helper ---- */
function read_json_body(): array {
  $ctype = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
  if (strpos($ctype, 'application/json') === false) return [];
  $raw = file_get_contents('php://input') ?: '';
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

/* ===== ХЕЛПЕРЫ по БД ===== */
function table_exists(PDO $pdo, string $table): bool {
  $q = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
  if (!$q) return false;
  return (bool)$q->fetchColumn();
}
function col_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$$pdo->quote($col)); // <-- опечатка в оригинале? исправим ниже
  return (bool)$st->fetch();
}
// Исправление безопасное: оригинальная функция могла упасть из-за $$pdo.
// Оставим корректную реализацию:
function col_exists_safe(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col));
  return (bool)$st->fetch();
}
function pick_col(PDO $pdo, string $table, array $cands): ?string {
  foreach ($cands as $c) {
    if (col_exists_safe($pdo,$table,$c)) return $c;
  }
  return null;
}
function pick_table(PDO $pdo, array $cands): ?string {
  foreach ($cands as $t) if (table_exists($pdo,$t)) return $t; return null;
}

/* ===== Справочники ===== */
function fetch_service(PDO $db, ?string $code, ?string $slug): ?array {
  if ($code) {
    $stmt=$db->prepare("SELECT id,code,COALESCE(title_ru,title_en,title_et,code) AS title,duration_min,price_eur FROM services WHERE code=? LIMIT 1");
    $stmt->execute([$code]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if ($row){ $row['id']=(int)$row['id']; $row['duration_min']=(int)$row['duration_min']; $row['price_eur']=(float)$row['price_eur']; return $row; }
  }
  if ($slug) {
    $stmt=$db->prepare("SELECT id,code,COALESCE(title_ru,title_en,title_et,slug_ru,slug_en,slug_et) AS title,duration_min,price_eur
                        FROM services WHERE slug_ru=:s OR slug_en=:s OR slug_et=:s LIMIT 1");
    $stmt->execute([':s'=>$slug]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if ($row){ $row['id']=(int)$row['id']; $row['duration_min']=(int)$row['duration_min']; $row['price_eur']=(float)$row['price_eur']; return $row; }
  }
  return null;
}
function ensure_client_id(PDO $db, string $name, string $phone, string $email): ?int {
  $tbl = pick_table($db, ['clients','customers']); if(!$tbl) return null;
  $idCol = pick_col($db,$tbl,['id','client_id','customer_id']) ?: 'id';
  $nameCol = pick_col($db,$tbl,['name','full_name','client_name']);
  $phoneCol= pick_col($db,$tbl,['phone','tel','client_phone','phone_number','mobile']);
  $emailCol= pick_col($db,$tbl,['email','mail']);
  $created = pick_col($db,$tbl,['created_at','created','dt_created']);

  if ($phoneCol && $phone!==''){ $st=$db->prepare("SELECT `$idCol` FROM `$tbl` WHERE `$phoneCol`=:p LIMIT 1"); $st->execute([':p'=>$phone]); $id=$st->fetchColumn(); if($id!==false) return (int)$id; }
  if ($emailCol && $email!==''){ $st=$db->prepare("SELECT `$idCol` FROM `$tbl` WHERE `$emailCol`=:e LIMIT 1"); $st->execute([':e'=>$email]); $id=$st->fetchColumn(); if($id!==false) return (int)$id; }

  $cols=[];$vals=[];$par=[];
  if($nameCol){ $cols[]="`$nameCol`";  $vals[]=':n'; $par[':n']=($name!==''?$name:'(no name)'); }
  if($phoneCol){$cols[]="`$phoneCol`"; $vals[]=':p'; $par[':p']=$phone; }
  if($emailCol){$cols[]="`$emailCol`"; $vals[]=':e'; $par[':e']=$email; }
  if($created){ $cols[]="`$created`";  $vals[]='NOW()'; }
  if(!$cols) return null;
  $sql="INSERT INTO `$tbl` (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
  $st=$db->prepare($sql); $st->execute($par); return (int)$db->lastInsertId();
}

/* ====== НОВОЕ: фильтр мастеров по pricing из quiz_option_overrides ====== */

/** Список возможных option_id для связи с услугой */
function service_option_candidates(?string $svcCode, ?string $svcSlug): array {
  $c = [];
  if ($svcCode) { $c[] = $svcCode; $c[] = 'svc:'.$svcCode; }
  if ($svcSlug) { $c[] = $svcSlug; $c[] = 'slug:'.$svcSlug; }
  // уберём пустые и дубли
  $c = array_values(array_unique(array_filter(array_map('strval',$c), fn($v)=>$v!=='')));
  return $c;
}

/**
 * Вернёт массив staff_id, у кого ЕСТЬ одновременно price_eur И duration_min
 * в quiz_option_overrides для step_key='service' и option_id ∈ candidates.
 * Если таблицы нет — вернёт исходный список без изменений (никакой фильтрации).
 */
function filter_staff_by_quiz_pricing(PDO $db, array $staffIds, ?string $svcCode, ?string $svcSlug): array {
  $staffIds = array_values(array_unique(array_map('intval', $staffIds)));
  if (!$staffIds) return [];

  if (!table_exists($db, 'quiz_option_overrides')) {
    return $staffIds; // таблицы нет — не фильтруем
  }

  $cands = service_option_candidates($svcCode, $svcSlug);
  if (!$cands) {
    return $staffIds; // нечем матчить — не фильтруем
  }

  // соберём плейсхолдеры
  $phStaff = implode(',', array_fill(0, count($staffIds), '?'));
  $phOpt   = implode(',', array_fill(0, count($cands), '?'));

  $sql = "SELECT DISTINCT staff_id
          FROM quiz_option_overrides
          WHERE step_key='service'
            AND option_id IN ($phOpt)
            AND staff_id IN ($phStaff)
            AND price_eur IS NOT NULL
            AND duration_min IS NOT NULL";
  $st = $db->prepare($sql);
  $st->execute(array_merge($cands, $staffIds));

  $ok = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $ok[] = (int)$r['staff_id'];
  }
  // Вернём пересечение, сохраняя сортировку как у исходного массива
  $okSet = array_fill_keys($ok, true);
  $res = [];
  foreach ($staffIds as $sid) { if (isset($okSet[$sid])) $res[] = $sid; }
  return $res;
}

/* ===== Авто-назначение мастера на бэкенде ===== */
function pick_available_staff(PDO $db, string $date, string $time, int $duration, ?int $salonId, ?string $svcCode = null, ?string $svcSlug = null): int {
  if (!preg_match('~^\d{4}-\d{2}-\d{2}$~',$date) || !preg_match('~^\d{2}:\d{2}$~',$time)) return 0;

  $tz = tz();
  $startDT = DateTime::createFromFormat('Y-m-d H:i', "$date $time", $tz);
  if(!$startDT) return 0;
  $endDT   = (clone $startDT)->modify("+{$duration} minutes");
  $weekday = (int)$startDT->format('N');

  $andSalon = $salonId ? " AND s.id IN (SELECT staff_id FROM staff_salons WHERE salon_id=:salon)" : "";
  $sql="SELECT s.id FROM staff s WHERE s.is_active=1 {$andSalon} ORDER BY s.id";
  $st=$db->prepare($sql);
  if($salonId) $st->bindValue(':salon', $salonId, PDO::PARAM_INT);
  $st->execute();
  $staffIds = array_map(fn($r)=>(int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC));
  if(!$staffIds) return 0;

  // НОВОЕ: фильтрация по наличию pricing/времени в quiz_option_overrides
  $staffIds = filter_staff_by_quiz_pricing($db, $staffIds, $svcCode, $svcSlug);
  if (!$staffIds) return 0;

  $ph = implode(',', array_fill(0, count($staffIds), '?'));

  // рабочие часы
  $wh=$db->prepare("SELECT staff_id, `start`,`end` FROM working_hours WHERE weekday=? AND staff_id IN ($ph)");
  $wh->execute(array_merge([$weekday], $staffIds));
  $work = [];
  foreach($wh as $r){
    $work[(int)$r['staff_id']] = [
      'start'=>substr(norm_time((string)$r['start']),0,5),
      'end'  =>substr(norm_time((string)$r['end']),0,5)
    ];
  }

  // time_off
  $aStart = $startDT->format('Y-m-d H:i:s');
  $aEnd   = $endDT->format('Y-m-d H:i:s');

  $off = [];
  $toS = pick_col($db,'time_off',['starts','start_dt','start_at','from_dt','from_at']);
  $toE = pick_col($db,'time_off',['ends','end_dt','end_at','to_dt','to_at']);
  if ($toS && $toE) {
    $to = $db->prepare("SELECT staff_id FROM time_off WHERE staff_id IN ($ph) AND NOT($toE<=? OR $toS>=?)");
    $to->execute(array_merge($staffIds, [$aStart, $aEnd]));
    foreach ($to as $r) { $off[(int)$r['staff_id']] = true; }
  }

  // занятость
  $apS = pick_col($db,'appointments',['starts','start_dt','start_at','start','begin_at']) ?: 'starts';
  $apE = pick_col($db,'appointments',['ends','end_dt','end_at','end','finish_at']) ?: 'ends';
  $ap=$db->prepare("SELECT staff_id FROM appointments WHERE staff_id IN ($ph) AND (status IS NULL OR status NOT IN ('cancelled','canceled')) AND NOT($apE<=? OR $apS>=?)");
  $ap->execute(array_merge($staffIds, [$aStart,$aEnd]));
  $busy=[]; foreach($ap as $r){ $busy[(int)$r['staff_id']] = true; }

  foreach($staffIds as $sid){
    if(empty($work[$sid])) continue;
    $whS = $work[$sid]['start']; $whE=$work[$sid]['end'];
    if(!($time >= $whS && $endDT->format('H:i') <= $whE)) continue;
    if(!empty($off[$sid])) continue;
    if(!empty($busy[$sid])) continue;
    return $sid; // первый подходящий по возрастанию id
  }
  return 0;
}

/* ===== DB ===== */
try { $db = pdo(); } catch (Throwable $e) { out(['ok'=>false,'error'=>'DB connect failed: '.$e->getMessage()]); }

/* ===== INPUT (общий) ===== */
$action = $_GET['action'] ?? '';
$svcCode = isset($_GET['svc'])  ? trim((string)$_GET['svc'])  : null;
$svcSlug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : null;

/* ================= META ================= */
if ($action === 'meta') {
  if (!$svcCode && !$svcSlug) out(['ok'=>false,'error'=>'param svc or slug required']);
  try {
    $service = fetch_service($db,$svcCode,$svcSlug); if(!$service) out(['ok'=>false,'error'=>'Service not found']);

    $salons = $db->query("SELECT id,name FROM salons ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach($salons as &$s){ $s['id']=(int)$s['id']; } unset($s);

    // Берём всех активных мастеров, затем фильтруем по pricing из quiz_option_overrides
    $staffRaw  = $db->query("SELECT id,name,is_active,COALESCE(tz,'".DEFAULT_TZ."') AS tz FROM staff WHERE is_active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $idsRaw = array_map(fn($r)=>(int)$r['id'], $staffRaw);
    $idsOk  = filter_staff_by_quiz_pricing($db, $idsRaw, $svcCode, $svcSlug);
    $okSet  = array_fill_keys($idsOk, true);
    $staff  = array_values(array_filter($staffRaw, fn($r)=> isset($okSet[(int)$r['id']])));

    foreach($staff as &$st){ $st['id']=(int)$st['id']; $st['is_active']=(int)$st['is_active']; } unset($st);

    $map    = $db->query("SELECT staff_id,salon_id FROM staff_salons")->fetchAll(PDO::FETCH_ASSOC);
    foreach($map as &$m){ $m['staff_id']=(int)$m['staff_id']; $m['salon_id']=(int)$m['salon_id']; } unset($m);

    out([
      'ok'=>true,'service'=>$service,'salons'=>$salons,'staff'=>$staff,'staff_salons'=>$map,
      'lead_min'=>LEAD_MIN,'tz'=>DEFAULT_TZ,'min_date'=>now_tz()->format('Y-m-d'),
      'allowed_from'=>lead_cutoff()->format(DATE_ATOM),
    ]);
  } catch (Throwable $e) { out(['ok'=>false,'error'=>$e->getMessage()]); }
}

/* ================= SLOTS ================= */
if ($action === 'slots') {
  if (!$svcCode && !$svcSlug) out(['ok'=>false,'error'=>'param svc or slug required']);
  $date = $_GET['date'] ?? ''; if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) out(['ok'=>false,'error'=>'date must be YYYY-MM-DD']);
  $salonId = (isset($_GET['salon_id']) && $_GET['salon_id']!=='') ? (int)$_GET['salon_id'] : null;
  $staffId = (isset($_GET['staff_id']) && $_GET['staff_id']!=='') ? (int)$_GET['staff_id'] : null;

  try {
    $service = fetch_service($db,$svcCode,$svcSlug); if(!$service) out(['ok'=>false,'error'=>'Service not found']);
    $tz = tz();

    $andSalon = $salonId ? " AND s.id IN (SELECT staff_id FROM staff_salons WHERE salon_id=:salon)" : "";
    $andStaff = $staffId ? " AND s.id=:staff" : "";
    $sql="SELECT s.id,s.name,COALESCE(s.tz,'".DEFAULT_TZ."') AS tz FROM staff s WHERE s.is_active=1 {$andSalon} {$andStaff} ORDER BY s.id";
    $st=$db->prepare($sql);
    if($salonId) $st->bindValue(':salon',$salonId,PDO::PARAM_INT);
    if($staffId) $st->bindValue(':staff',$staffId,PDO::PARAM_INT);
    $st->execute();
    $staff=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$staff) out(['ok'=>true,'slots'=>[]]);

    // НОВОЕ: отфильтровать мастеров без настроенных price/duration в quiz_option_overrides
    $idsAll = array_map(fn($r)=>(int)$r['id'], $staff);
    $idsOk  = filter_staff_by_quiz_pricing($db, $idsAll, $svcCode, $svcSlug);
    if (!$idsOk) out(['ok'=>true,'slots'=>[]]);
    $okSet = array_fill_keys($idsOk, true);
    $staff = array_values(array_filter($staff, fn($r)=> isset($okSet[(int)$r['id']])));

    $staffNames=[]; foreach($staff as $row){ $staffNames[(int)$row['id']] = (string)$row['name']; }
    $includeStaff = isset($_GET['include_staff']) && $_GET['include_staff']!=='0';

    $dayStart=new DateTime($date.' 00:00:00',$tz);
    $dayEnd  =(clone $dayStart)->modify('+1 day');
    $weekday =(int)$dayStart->format('N');

    $allowed = lead_cutoff();
    $today   = today_ymd();
    $leadDate= $allowed->format('Y-m-d');
    if($date<$today || $date<$leadDate) out(['ok'=>true,'slots'=>[]]);

    // --- IDs + защита от пустого IN ---
    $ids = array_map(fn($r)=>(int)$r['id'],$staff);
    if (!$ids) out(['ok'=>true,'slots'=>[]]); // ключевая защита
    $ph = implode(',',array_fill(0,count($ids),'?'));

    // --- рабочие часы ---
    $whStmt=$db->prepare("SELECT staff_id, `start`,`end` FROM working_hours WHERE weekday=? AND staff_id IN ($ph)");
    $whStmt->execute(array_merge([$weekday],$ids));
    $workMap=[];
    foreach($whStmt->fetchAll(PDO::FETCH_ASSOC) as $row){
      $sid=(int)$row['staff_id'];
      $workMap[$sid][]= ['start'=>norm_time((string)$row['start']), 'end'=>norm_time((string)$row['end'])];
    }

    // --- time_off (гибкие имена) ---
    $toS = pick_col($db,'time_off',['starts','start_dt','start_at','from_dt','from_at']);
    $toE = pick_col($db,'time_off',['ends','end_dt','end_at','to_dt','to_at']);
    $timeOff=[];
    if ($toS && $toE) {
      $toStmt=$db->prepare("SELECT staff_id, $toS AS starts, $toE AS ends
                            FROM time_off
                            WHERE staff_id IN ($ph) AND $toE > ? AND $toS < ?");
      $toStmt->execute(array_merge($ids,[$dayStart->format('Y-m-d H:i:s'),$dayEnd->format('Y-m-d H:i:s')]));
      foreach($toStmt as $row){
        $sid=(int)$row['staff_id'];
        $timeOff[$sid][]= ['from'=>new DateTime((string)$row['starts'],$tz),'to'=>new DateTime((string)$row['ends'],$tz)];
      }
    }

    // --- занятость (appointments) с гибкими именами ---
    $apS = pick_col($db,'appointments',['starts','start_dt','start_at','start','begin_at']) ?: 'starts';
    $apE = pick_col($db,'appointments',['ends','end_dt','end_at','end','finish_at'])       ?: 'ends';
    $apStmt=$db->prepare("SELECT staff_id, $apS AS starts, $apE AS ends
                          FROM appointments
                          WHERE staff_id IN ($ph)
                                AND ($apE > ? AND $apS < ?)
                                AND (status IS NULL OR status IN ('pending','confirmed'))");
    $apStmt->execute(array_merge($ids,[$dayStart->format('Y-m-d H:i:s'),$dayEnd->format('Y-m-d H:i:s')]));
    $busy=[];
    foreach($apStmt as $row){
      $sid=(int)$row['staff_id'];
      $busy[$sid][]= ['from'=>new DateTime((string)$row['starts'],$tz),'to'=>new DateTime((string)$row['ends'],$tz)];
    }

    // продолжительность: sum_min override из запроса имеет приоритет
    $override=0; foreach(['sum_min','duration_min','dur_min'] as $k){
      if(isset($_GET[$k]) && preg_match('/^\d+$/',(string)$_GET[$k])){ $override=(int)$_GET[$k]; break; }
    }
    $duration = $override>0 ? $override : max(0,(int)$service['duration_min']);
    $step = SLOT_STEP_MIN>0 ? SLOT_STEP_MIN : 30;

    $all=[]; $byTime=[];
    foreach($staff as $stf){
      $sid=(int)$stf['id']; $intervals=$workMap[$sid] ?? []; if(!$intervals) continue;
      foreach($intervals as $iv){
        $ws=new DateTime($date.' '.$iv['start'],$tz);
        $we=new DateTime($date.' '.$iv['end'],$tz);
        if($we<=$ws) continue;

        $allowedDT=new DateTime($allowed->format('Y-m-d H:i:s'),$tz);
        if($allowedDT >= $we) continue;

        $startCursor = ($allowedDT > $ws) ? clone $allowedDT : clone $ws;
        $cursor = ceil_to_step($startCursor,$step);

        while(true){
          $endSlot=(clone $cursor)->modify('+'.$duration.' minutes');
          if($endSlot > $we) break;

          $bad=false;
          foreach(($timeOff[$sid]??[]) as $b){
            if(overlaps($cursor,$endSlot,$b['from'],$b['to'])){ $bad=true; break; }
          }
          if(!$bad){
            foreach(($busy[$sid]??[]) as $b){
              if(overlaps($cursor,$endSlot,$b['from'],$b['to'])){ $bad=true; break; }
            }
          }
          if(!$bad){
            $t=$cursor->format('H:i'); $all[$t]=true;
            if($includeStaff){
              if(!isset($byTime[$t])) $byTime[$t]=[];
              $sidInt=(int)$sid; $exists=false; foreach($byTime[$t] as $row){ if($row['id']===$sidInt){$exists=true;break;} }
              if(!$exists) $byTime[$t][]= ['id'=>$sidInt,'name'=>$staffNames[$sidInt] ?? ('#'.$sidInt)];
            }
          }
          $cursor->modify('+'.$step.' minutes');
          if($cursor >= $we) break;
        }
      }
    }

    $list=array_keys($all); sort($list,SORT_STRING);
    $resp=['ok'=>true,'slots'=>$list]; if($includeStaff) $resp['by_time']=$byTime; out($resp);
  } catch (Throwable $e) {
    out(['ok'=>false,'error'=>$e->getMessage()]);
  }
}

/* ================= BOOK ================= */
if (($_GET['action'] ?? $_POST['action'] ?? '') === 'book') {
  try {
    /* входные: поддерживаем JSON и обычную форму */
    $J   = read_json_body();
    $SRC = $J ?: $_POST;       // приоритет JSON, иначе форма
    $GET = $_GET;

    $lang = $SRC['lang'] ?? 'ru';
    $svc  = trim((string)($SRC['svc']  ?? $GET['svc']  ?? ''));
    $slug = trim((string)($SRC['slug'] ?? $GET['slug'] ?? ''));
    $date = trim((string)($SRC['date'] ?? $GET['date'] ?? ''));
    $time = trim((string)($SRC['time'] ?? $GET['time'] ?? ''));
    $salon_id = (int)($SRC['salon_id'] ?? $GET['salon_id'] ?? 0);
    $staff_id = (int)($SRC['staff_id'] ?? $GET['staff_id'] ?? 0);

    $client_name   = trim((string)($SRC['client_name']  ?? $GET['client_name']  ?? ''));
    $client_phone  = trim((string)($SRC['client_phone'] ?? $GET['client_phone'] ?? ''));
    $client_email  = trim((string)($SRC['client_email'] ?? $GET['client_email'] ?? ''));
    $comment       = trim((string)($SRC['comment']      ?? $GET['comment']      ?? ''));

    // Нормализуем и валидируем телефон (по умолчанию добавляем код Эстонии +372)
    $client_phone = phone_to_e164($client_phone, '+372');
    if (!phone_is_valid_e164($client_phone)) {
      out(['ok'=>false,'error'=>'Телефон в неверном формате. Пример: +3725xxxxxxx']);
    }

    if (($svc==='' && $slug==='') || !preg_match('~^\d{4}-\d{2}-\d{2}$~',$date) || !preg_match('~^\d{2}:\d{2}$~',$time)) out(['ok'=>false,'error'=>'Параметры неполные']);
    if ($client_name==='' || $client_phone==='') out(['ok'=>false,'error'=>'Укажите имя и телефон']);

    $service = fetch_service($db,$svc,$slug); if(!$service) out(['ok'=>false,'error'=>'Услуга не найдена']);
    $service_id = (int)$service['id'];

    /* приоритет длительности: sum_min (или duration_min/dur_min) -> базовая */
    $overrideDur = null;
    foreach (['sum_min','duration_min','dur_min'] as $k) {
      if (isset($SRC[$k]) && preg_match('/^\d+$/',(string)$SRC[$k])) { $overrideDur = (int)$SRC[$k]; break; }
      if (isset($GET[$k]) && preg_match('/^\d+$/',(string)$GET[$k])) { $overrideDur = (int)$GET[$k]; break; }
    }
    $duration = $overrideDur && $overrideDur > 0 ? $overrideDur : max(5,(int)$service['duration_min']);

    $startDTi = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$date $time", tz());
    if(!$startDTi) out(['ok'=>false,'error'=>'Неверные дата/время']);
    $allowed=lead_cutoff();
    if ($startDTi->format('Y-m-d') < today_ymd()) out(['ok'=>false,'error'=>'Время уже прошло. Выберите другую дату.']);
    if ($startDTi < $allowed) out(['ok'=>false,'error'=>"Слишком близко к текущему времени. Минимум за ".LEAD_MIN." минут."]);

    $endDTi=$startDTi->modify('+'.$duration.' minutes');
    $startDT=$startDTi->format('Y-m-d H:i:s');
    $endDT  =$endDTi->format('Y-m-d H:i:s');

    /* проверка рабочего времени/отпуска/пересечений, если указан мастер */
    if ($staff_id>0){
      $wd=weekday1($date);
      $st=$db->prepare("SELECT `start`,`end` FROM working_hours WHERE staff_id=:sid AND weekday=:wd LIMIT 1");
      $st->execute([':sid'=>$staff_id, ':wd'=>$wd]); $wh=$st->fetch(PDO::FETCH_ASSOC);
      if(!$wh) out(['ok'=>false,'error'=>'В этот день мастер не работает']);
      $startHM=norm_time((string)$wh['start']); $endHM=norm_time((string)$wh['end']);
      if(!($time>=substr($startHM,0,5) && $endDTi->format('H:i')<=substr($endHM,0,5))) out(['ok'=>false,'error'=>'Вне рабочего времени']);

      $toStart=pick_col($db,'time_off',['starts','start_dt','start_at','from_dt','from_at']);
      $toEnd  =pick_col($db,'time_off',['ends','end_dt','end_at','to_dt','to_at']);
      if($toStart && $toEnd){
        $q="SELECT 1 FROM time_off WHERE staff_id=:sid AND NOT($toEnd<=:a OR $toStart>=:b) LIMIT 1";
        $st=$db->prepare($q); $st->execute([':sid'=>$staff_id, ':a'=>$startDT, ':b'=>$endDT]); if($st->fetch()) out(['ok'=>false,'error'=>'Мастер недоступен (отпуск/блокировка)']);
      }

      $apS=pick_col($db,'appointments',['starts','start_dt','start_at','start','begin_at']);
      $apE=pick_col($db,'appointments',['ends','end_dt','end_at','end','finish_at']);
      if($apS && $apE){
        $q="SELECT 1 FROM appointments WHERE staff_id=:sid AND (status IS NULL OR status NOT IN ('cancelled','canceled')) AND NOT($apE<=:a OR $apS>=:b) LIMIT 1";
        $st=$db->prepare($q); $st->execute([':sid'=>$staff_id, ':a'=>$startDT, ':b'=>$endDT]); if($st->fetch()) out(['ok'=>false,'error'=>'Слот уже занят']);
      }
    }

    /* если салон не указан — возьмём связку из staff_salons */
    if ($salon_id<=0 && $staff_id>0){ $q=$db->prepare("SELECT salon_id FROM staff_salons WHERE staff_id=:sid LIMIT 1"); $q->execute([':sid'=>$staff_id]); $salon_id=(int)$q->fetchColumn(); }
    $hasSalonCol=(bool)$db->query("SHOW COLUMNS FROM `appointments` LIKE 'salon_id'")->fetch(); if($hasSalonCol && $salon_id<=0) { /* допускаем пустой, если колонки нет */ }

    /* СПАСАТЕЛЬ: авто-назначение мастера, если его не прислали */
    if ($staff_id <= 0) {
      $autoSid = pick_available_staff($db, $date, $time, (int)$duration, $salon_id > 0 ? $salon_id : null, $svc, $slug);
      if ($autoSid > 0) {
        $staff_id = $autoSid;
        if ($salon_id <= 0) {
          $q=$db->prepare("SELECT salon_id FROM staff_salons WHERE staff_id=:sid LIMIT 1");
          $q->execute([':sid'=>$staff_id]);
          $salon_id = (int)$q->fetchColumn();
        }
      } else {
        out(['ok'=>false,'error'=>'Не удалось автоназначить сотрудника на это время. Выберите другое время/салон.']);
      }
    }

    /* список колонок appointments */
    $apTableCols=[]; foreach($db->query("SHOW COLUMNS FROM `appointments`") as $r) $apTableCols[]=$r['Field'];

    /* client_id: если колонка есть — берём из сессии, иначе ensure_client_id */
    $client_id=null;
    $needClientId=in_array('client_id',$apTableCols,true);
    if ($needClientId){
      $sessionClientId = isset($_SESSION['client_id']) ? (int)$_SESSION['client_id'] : 0;
      if ($sessionClientId > 0) {
        $client_id = $sessionClientId;
      } else {
        $client_id = ensure_client_id($db,$client_name,$client_phone,$client_email);
        if($client_id===null) out(['ok'=>false,'error'=>'Не удалось определить client_id']);
      }
    }

    /* цена: приоритет — из формы/квиза -> из услуги -> 0 */
    $postedPrice  = $SRC['price_eur'] ?? $SRC['price'] ?? null;
    $queryPrice   = $SRC['sum_eur']   ?? $GET['sum_eur'] ?? null;
    $servicePrice = isset($service['price_eur']) ? (float)$service['price_eur'] : null;
    $priceEur=null; foreach([$postedPrice,$queryPrice,$servicePrice] as $v){ if($v!==null && $v!==''){ $priceEur=(float)$v; break; } }
    if($priceEur===null) $priceEur=0.0;

    // === Сколько баллов дать за визит (1 € = 1 балл) ===
    $awardPoints = max(0, (int)floor((float)$priceEur));

    /* ===== собрать МЕТА из JSON/POST/GET (включая meta_b64 / quiz_*) ===== */
    $metaPayload = null;

    foreach (['meta','quiz'] as $k){
      if (isset($SRC[$k])) {
        $metaPayload = is_string($SRC[$k]) ? $SRC[$k] : json_encode([$k=>$SRC[$k]], JSON_UNESCAPED_UNICODE);
        break;
      }
    }
    if ($metaPayload === null && isset($SRC['meta_b64']) && is_string($SRC['meta_b64'])) {
      $b64 = strtr($SRC['meta_b64'], '-_', '+/'); 
      $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
      $metaDecoded = base64_decode($b64, true);
      if ($metaDecoded !== false) $metaPayload = $metaDecoded;
    }
    if ($metaPayload === null) {
      if (isset($GET['meta'])) {
        $metaPayload = is_string($GET['meta']) ? $GET['meta'] : json_encode($GET['meta'], JSON_UNESCAPED_UNICODE);
      } elseif (isset($GET['meta_b64']) && is_string($GET['meta_b64'])) {
        $b64 = strtr($GET['meta_b64'], '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $metaDecoded = base64_decode($b64, true);
        if ($metaDecoded !== false) $metaPayload = $metaDecoded;
      }
    }
    if ($metaPayload === null) {
      $quiz=[];
      $collect=function(array $arr) use (&$quiz){
        foreach($arr as $k=>$v){
          if(strpos((string)$k,'quiz_')===0){
            $key=substr((string)$k,5);
            $quiz[$key]=is_array($v)?array_values($v):(string)$v;
          }
        }
      };
      $collect($SRC);
      $collect($GET);
      if($quiz) $metaPayload = json_encode(['quiz'=>$quiz], JSON_UNESCAPED_UNICODE);
    }
    if ($metaPayload === null) $metaPayload = json_encode(new stdClass(), JSON_UNESCAPED_UNICODE);

    /* имена полей начала/конца */
    $apStartCol = pick_col($db,'appointments',['starts','start_dt','start_at','start','begin_at']) ?: 'starts';
    $apEndCol   = pick_col($db,'appointments',['ends','end_dt','end_at','end','finish_at'])        ?: 'ends';

    $cols=[]; $vals=[]; $params=[];
    $cols[]=$apStartCol; $vals[]=':start_dt'; $params[':start_dt']=$startDT;
    $cols[]=$apEndCol;   $vals[]=':end_dt';   $params[':end_dt']=$endDT;

    if (in_array('service_id',$apTableCols,true)) { $cols[]='service_id'; $vals[]=':service_id'; $params[':service_id']=$service_id; }
    if (in_array('salon_id',$apTableCols,true) && $salon_id) { $cols[]='salon_id'; $vals[]=':salon_id'; $params[':salon_id']=$salon_id; }
    if (in_array('staff_id',$apTableCols,true) && $staff_id) { $cols[]='staff_id'; $vals[]=':staff_id'; $params[':staff_id']=$staff_id; }
    if ($needClientId) { $cols[]='client_id'; $vals[]=':client_id'; $params[':client_id']=$client_id; }

    if (in_array('client_name',$apTableCols,true))  { $cols[]='client_name';  $vals[]=':client_name';  $params[':client_name']=$client_name; }
    if (in_array('client_phone',$apTableCols,true)) { $cols[]='client_phone'; $vals[]=':client_phone'; $params[':client_phone']=$client_phone; }
    if (in_array('client_email',$apTableCols,true)) { $cols[]='client_email'; $vals[]=':client_email'; $params[':client_email']=$client_email; }
    if (in_array('comment',$apTableCols,true))      { $cols[]='comment';      $vals[]=':comment';      $params[':comment']=$comment; }

    if (in_array('status',$apTableCols,true))     { $cols[]='status';     $vals[]=':st';   $params[':st']='confirmed'; }
    if (in_array('created_at',$apTableCols,true)) { $cols[]='created_at'; $vals[]='NOW()'; }

    $priceCol = pick_col($db,'appointments',['price_eur','total_eur','price','amount']);
    if($priceCol){ $cols[]=$priceCol; $vals[]=':price_eur'; $params[':price_eur']=$priceEur; }
    $durCol = pick_col($db,'appointments',['duration_min','minutes','duration']);
    if($durCol){ $cols[]=$durCol; $vals[]=':dur_min'; $params[':dur_min']=(int)$duration; }
    $titleCol = pick_col($db,'appointments',['service_title','title','service_name']);
    if($titleCol && isset($service['title'])){ $cols[]=$titleCol; $vals[]=':svc_title'; $params[':svc_title']=(string)$service['title']; }

    if (in_array('meta',$apTableCols,true)) {
      $cols[]='meta';
      $vals[]=':meta';
      $params[':meta'] = $metaPayload ?: '{}';
    }

    // Запишем план начисления баллов (если колонка есть)
    if (in_array('points_award', $apTableCols, true)) {
      $cols[] = 'points_award';
      $vals[] = ':points_award';
      $params[':points_award'] = max(0, (int)floor((float)$priceEur));
    }

    $sql="INSERT INTO `appointments` (`".implode('`,`',$cols)."`) VALUES (".implode(',',$vals).")";
    $st=$db->prepare($sql); $st->execute($params); $id=(int)$db->lastInsertId();
    out(['ok'=>true,'id'=>$id,'start'=>$startDT,'end'=>$endDT]);
  } catch (Throwable $e) { out(['ok'=>false,'error'=>'Ошибка: '.$e->getMessage()]); }
}

/* ===== неизвестное действие ===== */
out(['ok'=>false,'error'=>'unknown action']);

/* ====== Вспомогательные функции телефонов (из config.php, если нет — дефолт) ====== */
/* Ниже — страховочные реализации; если в твоём config.php уже есть — эти не заденут. */
if (!function_exists('phone_to_e164')) {
  function phone_to_e164(string $raw, string $default_cc = '+372'): string {
    $v = preg_replace('~[^0-9+]+~', '', $raw);
    if ($v === '') return '';
    if ($v[0] !== '+') {
      $v = $default_cc . ltrim($v, '0');
    }
    return $v;
  }
}
if (!function_exists('phone_is_valid_e164')) {
  function phone_is_valid_e164(string $v): bool {
    return (bool)preg_match('~^\+\d{7,15}$~', $v);
  }
}