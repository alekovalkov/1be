<?php
declare(strict_types=1);

/* ---------- Заголовки / CORS ---------- */
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { echo json_encode(['ok'=>true]); exit; }

error_reporting(EALL);
ini_set('display_errors','0');

require __DIR__ . '/_bootstrap.php';

/* utils */
function out(array $a, int $code=200){ http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function read_json(): array {
  static $j=null; if($j!==null) return $j;
  $ctype=strtolower($_SERVER['CONTENT_TYPE']??'');
  if(strpos($ctype,'application/json')===false) return $j=[];
  $raw=file_get_contents('php://input')?:''; $dec=json_decode($raw,true);
  return $j=is_array($dec)?$dec:[];
}
if(!function_exists('pick_col')){
  function pick_col(PDO $pdo, string $table, array $cands): ?string {
    foreach($cands as $c){ $q=$pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($c)); if($q&&$q->fetch()) return $c; }
    return null;
  }
}

/* auth + db */
try { $u=require_login(); if(!isset($u['staff_id'])) out(['ok'=>false,'error'=>'auth'],401); } catch(Throwable $e){ out(['ok'=>false,'error'=>'auth'],401); }
try { $db = function_exists('db')? db() : pdo(); } catch(Throwable $e){ out(['ok'=>false,'error'=>'db'],500); }

$in = read_json();
$action = $_GET['action'] ?? ($_POST['action'] ?? ($in['action'] ?? ''));

/* ---------------- appointments list ---------------- */
if ($action==='appointments'){
  try{
    $date = $_GET['date'] ?? date('Y-m-d');
    if(!preg_match('~^\d{4}-\d{2}-\d{2}$~',$date)) out(['ok'=>false,'error'=>'bad date'],400);

    $startCol = pick_col($db,'appointments',['starts','start_dt','start_at','start','begin_at']) ?: 'starts';
    $endCol   = pick_col($db,'appointments',['ends','end_dt','end_at','end','finish_at'])        ?: 'ends';

    $clientNameCol  = pick_col($db,'appointments',['client_name','name']) ?: null;
    $clientPhoneCol = pick_col($db,'appointments',['client_phone','phone']) ?: null;
    $durCol         = pick_col($db,'appointments',['duration_min','minutes','duration']) ?: null;
    $salonCol       = pick_col($db,'appointments',['salon_id']) ?: null;

    $svcTitleInAppt = pick_col($db,'appointments',['service_title','title','service_name']);
    $serviceIdCol   = pick_col($db,'appointments',['service_id']);
    $serviceTitleCol= pick_col($db,'services',['title','name']);
    $metaCol        = pick_col($db,'appointments',['meta']); // <-- для квиза

    $fields = [
      "a.id","a.staff_id",
      "DATE_FORMAT($startCol,'%H:%i') AS t",
      "TIMESTAMPDIFF(MINUTE,$startCol,$endCol) AS dur"
    ];
    if($clientNameCol)  $fields[]="a.`$clientNameCol` AS client_name";
    if($clientPhoneCol) $fields[]="a.`$clientPhoneCol` AS client_phone";
    if($durCol)         $fields[]="a.`$durCol` AS duration_min";
    if($salonCol)       $fields[]="a.`$salonCol` AS salon_id";
    if($metaCol)        $fields[]="a.`$metaCol` AS meta";

    $joinSql='';
    if($svcTitleInAppt){
      $fields[]="a.`$svcTitleInAppt` AS service_title";
    } elseif($serviceIdCol && $serviceTitleCol){
      $fields[]="s.`$serviceTitleCol` AS service_title";
      $joinSql=" LEFT JOIN services s ON s.id = a.`$serviceIdCol` ";
    } else {
      $fields[]="NULL AS service_title";
    }

    $sql = "SELECT ".implode(',', $fields)."
            FROM appointments a
            $joinSql
            WHERE a.staff_id=:sid
              AND DATE($startCol)=:d
              AND (a.status IS NULL OR a.status IN ('pending','confirmed'))
            ORDER BY $startCol ASC";
    $st=$db->prepare($sql);
    $st->execute([':sid'=>(int)$u['staff_id'], ':d'=>$date]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // salons map
    $salNames=[];
    if($salonCol && $rows){
      $ids=array_values(array_unique(array_filter(array_map(fn($r)=>(int)($r['salon_id']??0),$rows))));
      if($ids){
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $s2=$db->prepare("SELECT id,name FROM salons WHERE id IN ($ph)");
        $s2->execute($ids);
        foreach($s2->fetchAll(PDO::FETCH_ASSOC) as $r){ $salNames[(int)$r['id']] = (string)$r['name']; }
      }
    }

    // собрать quiz_summary из meta
    $items=[];
    foreach($rows as $r){
      $quizSummary='';
      if(!empty($r['meta'])){
        $m=json_decode((string)$r['meta'], true);
        if(is_array($m) && isset($m['quiz']) && is_array($m['quiz'])){
          // Читабельная строка “форма: миндаль • покрытие: гель • цвет: нюд”
          $parts=[];
          foreach($m['quiz'] as $k=>$v){
            $label = str_replace(['_','-'],' ',$k);
            if (is_array($v)) { $v = implode(', ', array_map('strval',$v)); }
            $parts[] = mb_strtolower($label).': '.(string)$v;
          }
          if($parts) $quizSummary = implode(' • ', $parts);
        }
      }

      $items[]=[
        'id'            => (int)$r['id'],
        'time'          => (string)$r['t'],
        'duration_min'  => isset($r['duration_min']) ? (int)$r['duration_min'] : (int)$r['dur'],
        'client_name'   => (string)($r['client_name'] ?? ''),
        'client_phone'  => (string)($r['client_phone'] ?? ''),
        'service_title' => (string)($r['service_title'] ?? ''),
        'quiz_summary'  => $quizSummary,             // <-- НОВОЕ
        'salon'         => isset($r['salon_id']) ? ($salNames[(int)$r['salon_id']] ?? '') : '',
      ];
    }

    out(['ok'=>true,'items'=>$items]);
  }catch(Throwable $e){
    out(['ok'=>false,'error'=>'server'],500);
  }
}

/* ---------------- set_status ---------------- */
if ($action==='set_status'){
  try{
    $in = $in ?: ($_POST+$_GET);
    $id=(int)($in['id']??0);
    $status=(string)($in['status']??'');
    if($id<=0) out(['ok'=>false,'error'=>'id required'],400);

    $map=['confirm'=>'confirmed','confirmed'=>'confirmed','done'=>'done','cancel'=>'cancelled','cancelled'=>'cancelled','canceled'=>'cancelled'];
    $newStatus=$map[strtolower($status)]??'';
    if($newStatus==='') out(['ok'=>false,'error'=>'bad status'],400);

    $startCol = pick_col($db,'appointments',['starts','start_dt','start_at','start','begin_at']) ?: 'starts';
    $st=$db->prepare("SELECT id,staff_id,$startCol AS start_time FROM appointments WHERE id=:id LIMIT 1");
    $st->execute([':id'=>$id]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row) out(['ok'=>false,'error'=>'not found'],404);
    if((int)$row['staff_id']!==(int)$u['staff_id']) out(['ok'=>false,'error'=>'forbidden'],403);

    if($newStatus==='cancelled'){
      $startDT=new DateTime((string)$row['start_time']);
      $allowDT=(clone $startDT)->modify('+15 minutes');
      if(new DateTime('now') < $allowDT) out(['ok'=>false,'error'=>'Отменить можно только через 15 минут после начала'],400);
    }

    $up=$db->prepare("UPDATE appointments SET status=:st WHERE id=:id");
    $up->execute([':st'=>$newStatus, ':id'=>$id]);

    out(['ok'=>true,'status'=>$newStatus]);
  }catch(Throwable $e){
    out(['ok'=>false,'error'=>'server'],500);
  }
}

/* fallback */
out(['ok'=>false,'error'=>'unknown'],404);
