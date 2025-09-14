<?php
declare(strict_types=1);

/**
 * Редактирование одной записи (appointment)
 * Путь: /booking/admin/appointments_edit.php?id=123
 */

require __DIR__ . '/../config.php';

const DEFAULT_TZ = 'Europe/Tallinn';
date_default_timezone_set(DEFAULT_TZ);

/* ==== helpers ==== */
function pdo2(): PDO { return pdo(); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function qcol(PDO $db, string $table, string $col): bool {
  return (bool)$db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($col))->fetch();
}
function pick_col(PDO $db, string $table, array $cands): ?string {
  foreach ($cands as $c) if (qcol($db,$table,$c)) return $c; return null;
}
function minutes_between(string $start, string $end): int {
  try{
    $a = new DateTime($start);
    $b = new DateTime($end);
    return max(0, (int)round(($b->getTimestamp()-$a->getTimestamp())/60));
  }catch(Throwable $e){ return 60; }
}

/* === quiz meta preview (не обязательно, но красиво) === */
function render_quiz_meta(?string $metaJson): string {
  if (!$metaJson) return '';
  $arr = json_decode($metaJson, true);
  $quiz = (is_array($arr) && isset($arr['quiz']) && is_array($arr['quiz'])) ? $arr['quiz'] : [];
  if (!$quiz) return '';

  $byArea = ['MANICURE'=>[],'PEDICURE'=>[],'OTHER'=>[]];
  foreach ($quiz as $k=>$v){
    if (strpos($k,'manicure_')===0) $byArea['MANICURE'][]=[$k,$v];
    elseif (strpos($k,'pedicure_')===0) $byArea['PEDICURE'][]=[$k,$v];
    else $byArea['OTHER'][]=[$k,$v];
  }
  $labels = ['oldcover'=>'Что на ногтях сейчас','service'=>'Услуга','cover'=>'Покрытие','length'=>'Длина','design'=>'Дизайн','spa'=>'SPA'];

  ob_start(); ?>
  <div style="border:1px dashed #e5e7eb;border-radius:10px;padding:10px;margin:10px 0">
    <div style="font-weight:700;margin-bottom:6px">Квиз — итог выбора</div>
    <?php foreach ($byArea as $area=>$rows): if(!$rows) continue; ?>
      <div style="margin:6px 0 2px"><b><?=h($area==='OTHER'?'Другое':$area)?></b></div>
      <ul style="margin:0 0 6px 18px;padding:0">
        <?php foreach ($rows as [$k,$v]):
          $part = strtolower(substr($k, strpos($k,'_')!==false ? strpos($k,'_')+1 : 0));
          $label = $labels[$part] ?? $k;
        ?>
          <li><?=h($label)?>: <?=h((string)$v)?></li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>
  </div>
  <?php
  return (string)ob_get_clean();
}

/* ==== load data ==== */
$db = pdo2();

$colStart = pick_col($db,'appointments',['starts','start_dt','start_at','start','begin_at']) ?: 'starts';
$colEnd   = pick_col($db,'appointments',['ends','end_dt','end_at','end','finish_at']) ?: 'ends';
$colPrice = pick_col($db,'appointments',['price_eur','total_eur','price','amount']);
$colDur   = pick_col($db,'appointments',['duration_min','minutes','duration']);

$id = max(0, (int)($_GET['id'] ?? 0));
if ($id <= 0) { http_response_code(400); echo "Bad id"; exit; }

$err=''; $msg='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  try{
    // читаем поля
    $startISO = trim((string)($_POST['start_dt'] ?? '')); // YYYY-MM-DDTHH:MM
    $duration = (int)($_POST['duration_min'] ?? 60);
    $status   = (string)($_POST['status'] ?? 'confirmed');
    $price    = (float)($_POST['price_eur'] ?? 0);
    $service_id = (int)($_POST['service_id'] ?? 0);
    $salon_id   = (int)($_POST['salon_id'] ?? 0);
    $staff_id   = (int)($_POST['staff_id'] ?? 0);

    // нормализуем старт и конец
    $start = '';
    if ($startISO !== '') {
      $start = str_replace('T',' ',$startISO);
      if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/',$start)) $start.=':00';
    }
    if ($start==='') $start = (new DateTime('now', new DateTimeZone(DEFAULT_TZ)))->format('Y-m-d H:i:s');
    $end = (new DateTime($start, new DateTimeZone(DEFAULT_TZ)))->modify('+'.max(1,$duration).' minutes')->format('Y-m-d H:i:s');

    // собираем UPDATE
    $sets=[]; $par=[];
    $sets[] = "`$colStart`=:s"; $par[':s']=$start;
    $sets[] = "`$colEnd`=:e";   $par[':e']=$end;
    $sets[] = "`status`=:st";   $par[':st']=$status;
    if ($colDur)   { $sets[]="`$colDur`=:dur";   $par[':dur']=$duration; }
    if ($colPrice) { $sets[]="`$colPrice`=:pr";  $par[':pr']=$price; }
    if (qcol($db,'appointments','service_id')) { $sets[]="`service_id`=:svc"; $par[':svc']=$service_id; }
    if (qcol($db,'appointments','salon_id'))   { $sets[]="`salon_id`=:sal";   $par[':sal']=$salon_id; }
    if (qcol($db,'appointments','staff_id'))   { $sets[]="`staff_id`=:sid";   $par[':sid']=$staff_id; }

    $par[':id'] = $id;
    $sql = "UPDATE appointments SET ".implode(', ',$sets)." WHERE id=:id";
    $st  = $db->prepare($sql);
    $st->execute($par);

    $msg = 'Сохранено.';
  }catch(Throwable $e){ $err=$e->getMessage(); }
}

/* взять запись заново */
$sql = "
  SELECT a.*, 
         a.`$colStart` AS s, 
         a.`$colEnd`   AS e,
         ".($colPrice ? "a.`$colPrice` AS price_val" : "NULL AS price_val").",
         ".($colDur   ? "a.`$colDur`   AS dur_val"   : "NULL AS dur_val")."
  FROM appointments a
  WHERE a.id = :id
  LIMIT 1
";
$st = $db->prepare($sql);
$st->execute([':id'=>$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); echo "Not found"; exit; }

$services = $db->query("SELECT id, COALESCE(title_ru,title_en,title_et,code) AS title FROM services ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$salons   = $db->query("SELECT id,name FROM salons ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$staff    = $db->query("SELECT id,name FROM staff ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

/* старт/длительность для формы */
$startLocal = '';
try { $startLocal = (new DateTime((string)$row['s']))->format('Y-m-d\TH:i'); } catch(Throwable $e){}
$durVal = $row['dur_val'] !== null ? (int)$row['dur_val'] : minutes_between((string)$row['s'], (string)$row['e']);
$priceVal = $row['price_val'] !== null ? (float)$row['price_val'] : 0.0;

/* meta для превью (если колонка есть) */
$metaJson = null;
if (qcol($db,'appointments','meta')) {
  $metaJson = (string)($row['meta'] ?? '');
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Изменить запись #<?= (int)$id ?></title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:24px;}
    h1{margin:0 0 16px}
    form{border:1px solid #e5e7eb;padding:16px;border-radius:12px;max-width:720px}
    label{display:block;margin:8px 0 4px;font-size:14px}
    input,select{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .btn{padding:8px 12px;border-radius:8px;border:1px solid #cbd5e1;background:#111;color:#fff;cursor:pointer}
    .link{display:inline-block;padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px;text-decoration:none;color:#111;background:#fff}
    .ok{background:#ecfdf5;border:1px solid #a7f3d0;padding:10px;border-radius:8px;margin-bottom:10px}
    .err{background:#fef2f2;border:1px solid #fecaca;padding:10px;border-radius:8px;margin-bottom:10px}
  </style>
</head>
<body>
  <h1>Изменить запись #<?= (int)$id ?></h1>
  <div style="margin-bottom:12px">
    <a class="link" href="/booking/admin/appointments.php">← к созданию</a>
    <a class="link" href="/booking/admin/appointments_list.php">Список записей</a>
  </div>

  <?php if($msg): ?><div class="ok"><?=h($msg)?></div><?php endif; ?>
  <?php if($err): ?><div class="err"><?=h($err)?></div><?php endif; ?>

  <form method="post">
    <label>Дата и время (локальные)</label>
    <input type="datetime-local" name="start_dt" value="<?=h($startLocal)?>" step="60">

    <div class="grid">
      <div>
        <label>Длительность, минут</label>
        <input type="number" name="duration_min" value="<?=h($durVal)?>" min="5" step="5">
      </div>
      <div>
        <label>Статус</label>
        <select name="status">
          <?php foreach (['confirmed','pending','cancelled'] as $st): ?>
            <option value="<?=$st?>" <?= ($row['status']??'')===$st?'selected':'' ?>><?=$st?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid">
      <div>
        <label>Цена, €</label>
        <input type="number" name="price_eur" step="0.01" value="<?=h($priceVal)?>">
      </div>
      <div>
        <label>Услуга</label>
        <select name="service_id">
          <?php foreach($services as $s): ?>
            <option value="<?=$s['id']?>" <?= (int)$row['service_id']===(int)$s['id']?'selected':'' ?>>
              <?=h($s['id'].' — '.$s['title'])?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid">
      <div>
        <label>Салон</label>
        <select name="salon_id">
          <option value="0">— не выбран —</option>
          <?php foreach($salons as $s): ?>
            <option value="<?=$s['id']?>" <?= (int)$row['salon_id']===(int)$s['id']?'selected':'' ?>>
              <?=h($s['id'].' — '.$s['name'])?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Сотрудник</label>
        <select name="staff_id">
          <option value="0">— не выбран —</option>
          <?php foreach($staff as $s): ?>
            <option value="<?=$s['id']?>" <?= (int)$row['staff_id']===(int)$s['id']?'selected':'' ?>>
              <?=h($s['id'].' — '.$s['name'])?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <?php if ($metaJson): ?>
      <label style="margin-top:10px">Итог квиза (для справки)</label>
      <?= render_quiz_meta($metaJson) ?>
    <?php endif; ?>

    <div style="margin-top:12px">
      <button class="btn" type="submit">Сохранить</button>
    </div>
  </form>
</body>
</html>
