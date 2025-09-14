<?php
declare(strict_types=1);

// Простая админ-страница для списка и создания записей
// Требует booking/config.php (pdo())
require __DIR__ . '/../config.php';

const DEFAULT_TZ = 'Europe/Tallinn';
date_default_timezone_set(DEFAULT_TZ);

/* ===== мини-хелперы (чтобы не тянуть весь api.php) ===== */
function pdo2(): PDO { return pdo(); }
function qcol(PDO $db, string $table, string $col): bool {
  return (bool)$db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($col))->fetch();
}
function pick_col(PDO $db, string $table, array $cands): ?string {
  foreach ($cands as $c) if (qcol($db,$table,$c)) return $c; return null;
}
function ensure_client(PDO $db, string $name, string $phone, string $email): int {
  // Пытаемся «найти или создать» в таблице clients
  $id = (int)($db->query("SELECT id FROM clients WHERE phone=" . $db->quote($phone) . " OR email=" . $db->quote($email) . " LIMIT 1")->fetchColumn() ?: 0);
  if ($id > 0) return $id;
  $stmt = $db->prepare("INSERT INTO clients (name, phone, email, created_at) VALUES (:n, :p, :e, NOW())");
  $stmt->execute([':n'=>$name!==''?$name:'(no name)', ':p'=>$phone, ':e'=>$email]);
  return (int)$db->lastInsertId();
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

/* === QUIZ helpers for admin preview === */
function b64url_decode_str(?string $s): ?string {
  if (!$s) return null;
  $b64 = strtr($s, '-_', '+/');
  $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
  $decoded = base64_decode($b64, true);
  return ($decoded === false) ? null : $decoded;
}

/** Возвращает [ 'quiz'=>[key=>val,...] ] или null */
function quiz_meta_from_b64(?string $meta_b64): ?array {
  $json = b64url_decode_str($meta_b64);
  if ($json === null) return null;
  $arr = json_decode($json, true);
  return (is_array($arr) ? $arr : null);
}

/** Строит HTML-блок «Итог» для админки по meta_b64 + суммам */
function render_quiz_summary_admin(?string $meta_b64, ?int $sum_eur, ?int $sum_min, string $lang='ru'): string {
  $meta = quiz_meta_from_b64($meta_b64);
  $quiz = ($meta && isset($meta['quiz']) && is_array($meta['quiz'])) ? $meta['quiz'] : [];

  // Группируем по area
  $byArea = [];
  foreach ($quiz as $k=>$v){
    if (!is_string($k)) continue;
    if (strpos($k,'manicure_')===0) { $byArea['MANICURE'][] = [$k,$v]; continue; }
    if (strpos($k,'pedicure_')===0) { $byArea['PEDICURE'][] = [$k,$v]; continue; }
    $byArea['OTHER'][] = [$k,$v];
  }

  $labels = [
    'oldcover'=>'Что на ногтях сейчас','service'=>'Услуга','cover'=>'Покрытие',
    'length'=>'Длина','design'=>'Дизайн','spa'=>'SPA'
  ];

  $hm = function(int $mins) {
    $mins = max(0,$mins); $h=intdiv($mins,60); $m=$mins%60;
    if ($h && $m) return "{$h} ч {$m} мин";
    if ($h) return "{$h} ч";
    return "{$m} мин";
  };

  ob_start(); ?>
  <div class="ok" style="background:#f8fafc;border:1px solid #e2e8f0">
    <div style="font-weight:700;margin-bottom:6px">Квиз — итог выбора</div>
    <?php if ($byArea): ?>
      <?php foreach ($byArea as $area=>$pairs): ?>
        <div style="margin:6px 0 2px"><b><?=h($area==='OTHER'?'Другое':$area)?></b></div>
        <ul style="margin:0 0 6px 18px;padding:0">
          <?php foreach ($pairs as [$k,$v]):
            $part = strtolower(substr($k, strpos($k,'_')!==false ? strpos($k,'_')+1 : 0));
            $label = $labels[$part] ?? $k;
          ?>
            <li><?=h($label)?>: <?=h((string)$v)?></li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    <?php else: ?>
      <div style="color:#64748b">Данные квиза не переданы.</div>
    <?php endif; ?>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
      <?php if (is_int($sum_eur)): ?>
        <span class="pill" style="display:inline-block;padding:6px 10px;border-radius:999px;border:1px solid #e5e7eb;background:#fff">
          💶 Итого: <b><?= (int)$sum_eur ?></b> €
        </span>
      <?php endif; ?>
      <?php if (is_int($sum_min)): ?>
        <span class="pill" style="display:inline-block;padding:6px 10px;border-radius:999px;border:1px solid #e5e7eb;background:#fff">
          ⏱ Общая длительность: <b><?= h($hm((int)$sum_min)) ?></b>
        </span>
      <?php endif; ?>
    </div>
  </div>
  <?php
  return (string)ob_get_clean();
}

/* ===== входные действия ===== */
$db = pdo2();
$err = '';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    // Поля формы
    $service_id   = (int)($_POST['service_id'] ?? 0);
    $salon_id     = (int)($_POST['salon_id'] ?? 0);
    $staff_id     = (int)($_POST['staff_id'] ?? 0);
    $client_id    = (int)($_POST['client_id'] ?? 0);
    $client_name  = trim((string)$_POST['client_name'] ?? '');
    $client_phone = trim((string)$_POST['client_phone'] ?? '');
    $client_email = trim((string)$_POST['client_email'] ?? '');
    $price_eur    = (float)($_POST['price_eur'] ?? 0);
    $duration_min = (int)($_POST['duration_min'] ?? 60);
    $status       = (string)($_POST['status'] ?? 'confirmed');

    // Квиз-мета: только base64url (из квиза/URL)
    $meta_b64  = trim((string)$_POST['meta_b64'] ?? '');
    $meta_json = null;
    if ($meta_b64 !== '') {
      $b64 = strtr($meta_b64, '-_', '+/');
      $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
      $decoded = base64_decode($b64, true);
      if ($decoded !== false) $meta_json = $decoded;
    }

    /* === Автоподстановка service_id по коду из квиза (svc) === */
    // 1) из URL: appointments.php?svc=...
    $svcCodeFromGet = isset($_GET['svc']) ? trim((string)$_GET['svc']) : '';
    if ($service_id <= 0 && $svcCodeFromGet !== '') {
      $stmt = $db->prepare("SELECT id FROM services WHERE code = :c LIMIT 1");
      $stmt->execute([':c' => $svcCodeFromGet]);
      $hit = (int)($stmt->fetchColumn() ?: 0);
      if ($hit > 0) $service_id = $hit;
    }
    // 2) из скрытого поля формы (если JS положил туда код квиза)
    if ($service_id <= 0 && !empty($_POST['svc_code'])) {
      $svcCodeFromPost = trim((string)$_POST['svc_code']);
      $stmt = $db->prepare("SELECT id FROM services WHERE code = :c LIMIT 1");
      $stmt->execute([':c' => $svcCodeFromPost]);
      $hit = (int)($stmt->fetchColumn() ?: 0);
      if ($hit > 0) $service_id = $hit;
    }
    // 3) если колонка appointments.service_id существует и NOT NULL, а id так и не получили — стоп
    if (qcol($db,'appointments','service_id') && $service_id <= 0) {
      throw new RuntimeException('Не выбрана услуга и не удалось сопоставить код из квиза (svc) с таблицей services.code.');
    }

    // Дата/время старта из <input type="datetime-local" name="start_dt">
    $start = '';
    $startISO = trim((string)($_POST['start_dt'] ?? '')); // 'YYYY-MM-DDTHH:MM'
    if ($startISO !== '') {
      $start = str_replace('T', ' ', $startISO);
      if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $start)) {
        $start .= ':00';
      }
    }
    // если пусто — по умолчанию «через 15 минут»
    if ($start === '') {
      $dt = new DateTime('+15 minutes', new DateTimeZone(DEFAULT_TZ));
      $start = $dt->format('Y-m-d H:i:s');
    }

    $dtStart = new DateTime($start, new DateTimeZone(DEFAULT_TZ));
    $dtEnd   = (clone $dtStart)->modify('+' . max(1,$duration_min) . ' minutes');
    $end     = $dtEnd->format('Y-m-d H:i:s');

    // Если client_id не выбран — создадим/найдём по телефону/почте
    if ($client_id <= 0) {
      $client_id = ensure_client($db, $client_name, $client_phone, $client_email);
    }

    // Выясняем реальные имена колонок в appointments
    $apStart = pick_col($db,'appointments',['starts','start_dt','start_at','start','begin_at']) ?: 'starts';
    $apEnd   = pick_col($db,'appointments',['ends','end_dt','end_at','end','finish_at']) ?: 'ends';
    $priceCol= pick_col($db,'appointments',['price_eur','total_eur','price','amount']);
    $durCol  = pick_col($db,'appointments',['duration_min','minutes','duration']);

    $cols = [$apStart,$apEnd,'status'];
    $vals = [':s',':e',':st'];
    $par  = [':s'=>$start, ':e'=>$end, ':st'=>$status];

    if (qcol($db,'appointments','service_id') && $service_id>0){ $cols[]='service_id'; $vals[]=':svc'; $par[':svc']=$service_id; }
    if (qcol($db,'appointments','salon_id')   && $salon_id>0)  { $cols[]='salon_id';   $vals[]=':sal'; $par[':sal']=$salon_id; }
    if (qcol($db,'appointments','staff_id')   && $staff_id>0)  { $cols[]='staff_id';   $vals[]=':sid'; $par[':sid']=$staff_id; }
    if (qcol($db,'appointments','client_id')  && $client_id>0) { $cols[]='client_id';  $vals[]=':cid'; $par[':cid']=$client_id; }
    if ($priceCol) { $cols[]=$priceCol; $vals[]=':price'; $par[':price']=$price_eur; }
    if ($durCol)   { $cols[]=$durCol;   $vals[]=':dur';   $par[':dur']=$duration_min; }
    if (qcol($db,'appointments','created_at')) { $cols[]='created_at'; $vals[]='NOW()'; }

    // План начисления баллов (1 € = 1 балл), если колонка есть
    if (qcol($db,'appointments','points_award')) {
      $cols[]='points_award'; $vals[]=':pa'; $par[':pa'] = max(0,(int)floor($price_eur));
    }

    // Сохраняем JSON квиза в appointments.meta (если колонка есть и meta_json получен)
    if ($meta_json !== null && qcol($db,'appointments','meta')) {
      $cols[] = 'meta';
      $vals[] = ':meta';
      $par[':meta'] = $meta_json;
    }

    $sql = "INSERT INTO appointments (`".implode('`,`',$cols)."`) VALUES (".implode(',',$vals).")";
    $st = $db->prepare($sql);
    $st->execute($par);
    $newId = (int)$db->lastInsertId();

    $msg = "Запись #$newId создана.";
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

/* ===== данные для форм ===== */
$salons   = $db->query("SELECT id,name FROM salons ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$staff    = $db->query("SELECT id,name FROM staff ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$services = $db->query("SELECT id, code, COALESCE(title_ru,title_en,title_et,code) AS title FROM services ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$clients  = $db->query("SELECT id,name,phone,email FROM clients ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

/* ===== последние 50 записей ===== */
$colStart = pick_col($db,'appointments',['starts','start_dt','start_at','start','begin_at']) ?: 'starts';
$colEnd   = pick_col($db,'appointments',['ends','end_dt','end_at','end','finish_at']) ?: 'ends';
$colPrice = pick_col($db,'appointments',['price_eur','total_eur','price','amount']);
$colPoints= qcol($db,'appointments','points_award') ? 'points_award' : 'NULL';
$colAwardedAt = qcol($db,'appointments','points_awarded_at') ? 'points_awarded_at' : 'NULL';

$sql = "
  SELECT id, client_id,
         `$colStart` AS s,
         `$colEnd`   AS e,
         status,
         ".($colPrice ? "`$colPrice`" : "NULL")." AS price,
         $colPoints AS points_award,
         $colAwardedAt AS points_awarded_at
  FROM appointments
  ORDER BY id DESC
  LIMIT 50
";
$list = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/* ===== Календарь: записи за месяц ===== */
$colStartCal = $colStart; // уже выбрали выше
$colEndCal   = $colEnd;

$ymParam = isset($_GET['month']) ? preg_replace('~[^0-9\-]~','', (string)$_GET['month']) : '';
$curMonth = $ymParam && preg_match('~^\d{4}-\d{2}$~',$ymParam) ? $ymParam : (new DateTime('first day of this month', new DateTimeZone(DEFAULT_TZ)))->format('Y-m');

$dtFirst = DateTime::createFromFormat('Y-m-d H:i:s', $curMonth.'-01 00:00:00', new DateTimeZone(DEFAULT_TZ));
$dtNext  = (clone $dtFirst)->modify('first day of next month');
$monthStart = $dtFirst->format('Y-m-d H:i:s');
$monthEnd   = $dtNext->format('Y-m-d H:i:s');

$sqlCal = "
  SELECT a.id,
       a.client_id,
       a.status,
       a.$colStartCal AS s,
       a.$colEndCal   AS e,
       COALESCE(staff.name,'')  AS staff_name,
       COALESCE(clients.name,'') AS client_name
  FROM appointments a
  LEFT JOIN staff   ON staff.id   = a.staff_id
  LEFT JOIN clients ON clients.id = a.client_id
  WHERE a.$colStartCal >= :start AND a.$colStartCal < :end
  ORDER BY a.$colStartCal
";
$stCal = $db->prepare($sqlCal);
$stCal->execute([':start'=>$monthStart, ':end'=>$monthEnd]);
$rowsCal = $stCal->fetchAll(PDO::FETCH_ASSOC);

/* Группировка по дню YYYY-MM-DD */
$daysMap = []; // [ 'YYYY-MM-DD' => [rows...] ]
foreach ($rowsCal as $r) {
  $d = substr((string)$r['s'], 0, 10);
  $daysMap[$d][] = $r;
}

/* Параметры для рисования сетки */
$daysInMonth = (int)$dtFirst->format('t');
$firstDow = (int)$dtFirst->format('N'); // 1..7 (Пн..Вс)
$prevMonth = (clone $dtFirst)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $dtFirst)->modify('+1 month')->format('Y-m');
$todayStr  = (new DateTime('now', new DateTimeZone(DEFAULT_TZ)))->format('Y-m-d');

/* ===== значения префилла из GET (редирект из quiz.php?to=admin) ===== */
$defaultStartLocal = (new DateTime('+15 minutes', new DateTimeZone(DEFAULT_TZ)))->format('Y-m-d\TH:i');
$startPrefill = isset($_POST['start_dt']) && $_POST['start_dt'] !== ''
  ? (string)$_POST['start_dt']
  : $defaultStartLocal;
$pricePrefill = isset($_GET['sum_eur']) ? (float)$_GET['sum_eur'] : 19.0;
$durPrefill   = isset($_GET['sum_min']) ? (int)$_GET['sum_min']   : 60;
$metaB64FromGet = isset($_GET['meta_b64']) ? (string)$_GET['meta_b64'] : '';
$quizSummaryHtml = render_quiz_summary_admin(
  $metaB64FromGet !== '' ? $metaB64FromGet : null,
  isset($_GET['sum_eur']) ? (int)$_GET['sum_eur'] : null,
  isset($_GET['sum_min']) ? (int)$_GET['sum_min'] : null,
  'ru'
);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Админка — Записи</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; margin:24px;}
    h1{margin:0 0 16px}
    .row{display:flex; gap:24px; align-items:flex-start}
    form{border:1px solid #ddd; border-radius:12px; padding:16px; max-width:640px}
    label{display:block; margin:8px 0 4px; font-size:14px}
    input,select,textarea{width:100%; padding:8px; border:1px solid #ccc; border-radius:8px}
    .grid{display:grid; grid-template-columns:1fr 1fr; gap:12px}
    .ok{background:#eefbf1; border:1px solid #cfead7; padding:10px; border-radius:8px; margin-bottom:12px}
    .err{background:#fff3f3; border:1px solid #f3c4c4; padding:10px; border-radius:8px; margin-bottom:12px}
    .actions{display:flex; gap:8px; margin-top:8px}
    .btn{padding:8px 12px; border-radius:8px; border:1px solid #ccc; background:#fafafa; cursor:pointer; text-decoration:none; color:#111}
    .btn.primary{background:#111; color:#fff; border-color:#111}
    small.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace; color:#666}

    /* --- Календарь --- */
    .cal{ display:grid; grid-template-columns:repeat(7,1fr); gap:6px; margin-bottom:10px; }
    .cal__head{ font-weight:700; text-align:center; padding:6px 0; color:#374151; }
    .cal__cell{ border:1px solid #e5e7eb; border-radius:10px; min-height:90px; padding:6px; position:relative; background:#fff; cursor:pointer; }
    .cal__cell--empty{ background:#f9fafb; border-style:dashed; cursor:default; }
    .cal__cell--today{ box-shadow: inset 0 0 0 2px #111827; }
    .cal__date{ font-weight:700; color:#111827; }
    .cal__count{ position:absolute; top:6px; right:6px; font-size:12px; background:#111827; color:#fff; padding:2px 6px; border-radius:999px; }
    .cal__tags{ margin-top:26px; display:flex; gap:4px; flex-wrap:wrap }
    .cal__tag{ font-size:12px; border:1px solid #e5e7eb; border-radius:999px; padding:2px 6px; background:#f9fafb; }

    .daylist{ border:1px solid #e5e7eb; border-radius:12px; padding:10px; background:#fff; margin-top:10px; }
    .daylist__hdr{ display:flex; justify-content:space-between; align-items:center; margin-bottom:6px }
    .daylist__body{ display:flex; flex-direction:column; gap:6px }
    .dayrow{ padding:6px 8px; border:1px solid #f1f5f9; border-radius:8px; background:#f8fafc; }
    .dayrow .time{ font-weight:700; margin-right:8px }
    .dayrow .staff{ color:#111827; margin-right:8px }
    .dayrow .client{ color:#374151; margin-right:8px }
    .dayrow .status{ color:#6b7280 }
    .muted{ color:#6b7280 }
  </style>
</head>
<body>
  <h1>Записи (просмотр / добавить)</h1>

  <?php if($msg): ?><div class="ok"><?=h($msg)?></div><?php endif; ?>
  <?php if($err): ?><div class="err"><?=h($err)?></div><?php endif; ?>

  <div class="row">
    <form method="post">
      <h3>Добавить запись</h3>

      <label>Дата и время (локальные)</label>
      <input type="datetime-local" name="start_dt" value="<?=h($startPrefill)?>" step="60">
      <small class="mono">Время вводится в зоне <?=h(DEFAULT_TZ)?>.</small>

      <div class="grid" style="margin-top:8px">
        <div>
          <label>Длительность, минут</label>
          <input name="duration_min" type="number" value="<?=h($durPrefill)?>" min="5" step="5">
        </div>
        <div>
          <label>Статус</label>
          <select name="status">
            <option value="confirmed">confirmed</option>
            <option value="pending">pending</option>
            <option value="cancelled">cancelled</option>
          </select>
        </div>
      </div>

      <h3 style="margin-top:16px">Квиз</h3>
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
        <button type="button" class="btn" id="btnOpenQuiz">Открыть квиз</button>
        <small class="mono">Результат подставится автоматически в поля ниже.</small>
      </div>

      <!-- Полный итог квиза (server-side из GET/meta_b64) -->
      <div id="quizPreview" style="border:1px dashed #ddd;border-radius:8px;padding:10px;margin-bottom:12px;<?= $metaB64FromGet||isset($_GET['sum_eur'])||isset($_GET['sum_min']) ? '' : 'display:none;' ?>">
        <?= $quizSummaryHtml ?>
      </div>

      <!-- meta_b64 из квиза -->
      <input type="hidden" name="meta_b64" id="meta_b64" value="<?= h($metaB64FromGet) ?>">

      <div class="grid" style="margin-top:16px">
        <div>
          <label>Цена, €</label>
          <input name="price_eur" type="number" step="0.01" value="<?=h($pricePrefill)?>">
        </div>
        <div>
          <label>Услуга</label>
          <select name="service_id" id="service_id">
            <option value="0">— не выбирать —</option>
            <?php foreach($services as $s): ?>
              <option value="<?=$s['id']?>" data-code="<?=h($s['code'] ?? '')?>">
                <?=h($s['id'].' — '.$s['title'])?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="mono">Можно оставить «не выбирать» — возьмём из кода квиза (svc).</small>
        </div>
      </div>

      <!-- Код услуги (svc) из квиза -->
      <input type="hidden" name="svc_code" id="svc_code">

      <div class="grid" style="margin-top:12px">
        <div>
          <label>Салон</label>
          <select name="salon_id">
            <option value="0">— не выбирать —</option>
            <?php foreach($salons as $s): ?>
              <option value="<?=$s['id']?>"><?=h($s['id'].' — '.$s['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Сотрудник</label>
          <select name="staff_id">
            <option value="0">— авто/не выбирать —</option>
            <?php foreach($staff as $s): ?>
              <option value="<?=$s['id']?>"><?=h($s['id'].' — '.$s['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <label style="margin-top:12px">Клиент (последние 50)</label>
      <select name="client_id">
        <option value="0">— создать нового —</option>
        <?php foreach($clients as $c): ?>
          <option value="<?=$c['id']?>"><?=h('#'.$c['id'].' — '.$c['name'].' '.$c['phone'].' '.$c['email'])?></option>
        <?php endforeach; ?>
      </select>

      <div class="grid" style="margin-top:12px">
        <div>
          <label>Имя (если создаём нового)</label>
          <input name="client_name" placeholder="Имя">
        </div>
        <div>
          <label>Телефон (если создаём нового)</label>
          <input name="client_phone" placeholder="+3725xxxxxxx">
        </div>
      </div>
      <label>Email (если создаём нового)</label>
      <input name="client_email" placeholder="email@example.com">

      <div class="actions" style="margin-top:12px">
        <button class="btn primary" type="submit" name="save">Сохранить</button>
      </div>
    </form>

    <div style="flex:1">
      <h3>Календарь (месяц)</h3>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <a class="btn" href="?month=<?=h($prevMonth)?>">← Предыдущий</a>
        <div><b><?=h($dtFirst->format('F Y'))?></b></div>
        <a class="btn" href="?month=<?=h($nextMonth)?>">Следующий →</a>
        <a class="btn" href="?month=<?=h((new DateTime('first day of this month'))->format('Y-m'))?>">Текущий месяц</a>
      </div>

      <div class="cal">
        <div class="cal__head">Пн</div><div class="cal__head">Вт</div><div class="cal__head">Ср</div>
        <div class="cal__head">Чт</div><div class="cal__head">Пт</div><div class="cal__head">Сб</div><div class="cal__head">Вс</div>

        <?php
          // пустые клетки до первого дня
          for($i=1;$i<$firstDow;$i++) echo '<div class="cal__cell cal__cell--empty"></div>';

          for($d=1;$d<=$daysInMonth;$d++){
            $dateStr = $dtFirst->format('Y-m-').str_pad((string)$d,2,'0',STR_PAD_LEFT);
            $items = $daysMap[$dateStr] ?? [];
            $isToday = ($dateStr === $todayStr);
            echo '<div class="cal__cell'.($isToday?' cal__cell--today':'').'" data-date="'.h($dateStr).'">';
              echo '<div class="cal__date">'.(int)$d.'</div>';
              if ($items){
                echo '<div class="cal__count">'.count($items).'</div>';
                echo '<div class="cal__tags">';
                $shown = 0;
                foreach($items as $it){
                  $nm = trim((string)$it['staff_name']);
                  if ($nm==='') $nm = '—';
                  // инициалы
                  $parts = preg_split('~\s+~',$nm);
                  $ini = '';
                  foreach ($parts as $p){ if($p!=='') $ini .= mb_substr($p,0,1,'UTF-8'); }
                  echo '<span class="cal__tag" title="'.h($nm).'">'.h($ini?:'•').'</span>';
                  if (++$shown>=4){ if(count($items)>$shown) echo '<span class="cal__tag" title="и ещё">+' . (count($items)-$shown) . '</span>'; break; }
                }
                echo '</div>';
              }
            echo '</div>';
          }
          // добить сетку пустыми, чтобы было кратно 7
          $cellsPrinted = ($firstDow-1) + $daysInMonth;
          $rem = (7 - ($cellsPrinted % 7)) % 7;
          for($i=0;$i<$rem;$i++) echo '<div class="cal__cell cal__cell--empty"></div>';
        ?>
      </div>

      <div id="dayList" class="daylist" style="display:none">
        <div class="daylist__hdr">
          <b id="dayListTitle">День</b>
          <button type="button" class="btn" id="dayListClose">Закрыть</button>
        </div>
        <div id="dayListBody" class="daylist__body"></div>
      </div>

      <h3 style="margin-top:18px">Сегодня</h3>
      <div id="todayBody" class="daylist__body">
        <?php
  $todayItems = $daysMap[$todayStr] ?? [];
  if (!$todayItems){
    echo '<div class="muted">Сегодня записей нет.</div>';
  } else {
    foreach ($todayItems as $it){
      $t1 = date('H:i', strtotime($it['s']));
      $t2 = date('H:i', strtotime($it['e']));
      $hrefEdit = '/booking/admin/appointments_edit.php?id='.(int)$it['id'];
      $hrefClient = '/booking/admin/client.php?id='.(int)($it['client_id'] ?? 0);
      echo '<div class="dayrow" style="display:flex;gap:12px;align-items:center">';
      echo '  <a class="time" href="'.h($hrefEdit).'" style="text-decoration:none;color:#111">'.h($t1.'–'.$t2).'</a>';
      echo '  <a class="staff" href="'.h($hrefEdit).'" style="text-decoration:none;color:#111">'.h($it['staff_name']?:'—').'</a>';
      if (!empty($it['client_name'])) {
        echo '  <a class="client" href="'.h($hrefClient).'" style="text-decoration:none">'.h($it['client_name']).'</a>';
      } else {
        echo '  <span class="client muted">—</span>';
      }
      echo '  <span class="status">'.h($it['status']).'</span>';
      echo '</div>';
    }
  }
?>
      </div>

      <h3 style="margin-top:18px">Список записей</h3>
      <a class="btn" href="/booking/admin/appointments_list.php">Открыть полный список →</a>
      <p class="muted" style="margin-top:8px">Список откроется на отдельной странице с пагинацией, чтобы не нагружать эту.</p>

      <p style="margin-top:12px"><small class="mono">
        Баллы: 1 € = 1 балл, план записывается в <code>appointments.points_award</code>, начисление по крону/скрипту.
      </small></p>
    </div>
  </div>

  <!-- Модальное окно для квиза -->
  <div id="quizModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:min(900px,95vw); height:min(80vh,700px); border-radius:12px; overflow:hidden; box-shadow:0 10px 40px rgba(0,0,0,.2); display:flex; flex-direction:column;">
      <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 12px; border-bottom:1px solid #eee;">
        <b style="font-size:15px">Квиз — выбор услуги</b>
        <button type="button" id="quizClose" class="btn">Закрыть</button>
      </div>
      <!-- ВАЖНО: to=admin & embed=1 -->
      <iframe id="quizFrame" src="/quiz.php?to=admin&embed=1&lang=ru" style="flex:1; border:0;"></iframe>
    </div>
  </div>

  <script>
  // --- Открыть/закрыть модалку квиза ---
  const btnOpenQuiz = document.getElementById('btnOpenQuiz');
  const quizModal   = document.getElementById('quizModal');
  const quizClose   = document.getElementById('quizClose');

  btnOpenQuiz?.addEventListener('click', () => { quizModal.style.display = 'flex'; });
  quizClose?.addEventListener('click', () => { quizModal.style.display = 'none'; });
  quizModal?.addEventListener('click', (e)=>{ if(e.target === quizModal) quizModal.style.display='none'; });

  // Приём результата из квиза через postMessage (резерв)
  window.addEventListener('message', (ev) => {
    const data = ev.data;
    if (!data || typeof data !== 'object') return;
    const type = data.type;
    if (type !== 'quizResult' && type !== 'quiz_result') return;

    const metaField = document.getElementById('meta_b64');
    if (metaField && typeof data.meta_b64 === 'string') {
      metaField.value = data.meta_b64;
    }

    const price = (typeof data.sum_eur !== 'undefined') ? data.sum_eur : data.price_eur;
    const dur   = (typeof data.sum_min !== 'undefined') ? data.sum_min : data.duration_min;
    const svc   = data.svc || data.slug;

    const priceInput = document.querySelector('input[name="price_eur"]');
    if (priceInput && typeof price !== 'undefined') priceInput.value = String(price);

    const durInput = document.querySelector('input[name="duration_min"]');
    if (durInput && typeof dur !== 'undefined') durInput.value = String(dur);

    const svcSel = document.getElementById('service_id');
    if (svcSel) {
      if (typeof data.service_id === 'number') {
        svcSel.value = String(data.service_id);
      } else if (svc) {
        const hit = Array.from(svcSel.options||[]).find(o => (o.dataset && o.dataset.code === String(svc)));
        if (hit) svcSel.value = hit.value;
      }
    }

    // Отрисуем «Итог квиза» в превью
    renderQuizSummaryFromMeta(data.meta_b64, price, dur);
    // Положим svc в hidden — пригодится на бэкенде
    const svcCodeHidden = document.getElementById('svc_code');
    if (svcCodeHidden && svc) svcCodeHidden.value = svc;

    quizModal.style.display = 'none';
  });

  // Применение sum_eur / sum_min / svc / meta_b64 из URL (редирект из квиза)
  (function(){
    const url = new URL(window.location.href);
    const qp = {
      eur: url.searchParams.get('sum_eur'),
      min: url.searchParams.get('sum_min'),
      svc: url.searchParams.get('svc'),
      meta: url.searchParams.get('meta_b64'),
    };

    // meta_b64 -> hidden
    if (qp.meta) {
      const metaField = document.getElementById('meta_b64');
      if (metaField) metaField.value = qp.meta;
    }

    // Цена / длительность
    const priceInput = document.querySelector('input[name="price_eur"]');
    const durInput   = document.querySelector('input[name="duration_min"]');
    if (priceInput && qp.eur !== null) priceInput.value = qp.eur;
    if (durInput   && qp.min !== null) durInput.value   = qp.min;

    // Сервис по коду (svc -> <option data-code="...">)
    const svcSel = document.getElementById('service_id');
    if (svcSel && qp.svc) {
      const hit = Array.from(svcSel.options).find(o => (o.dataset && o.dataset.code === String(qp.svc)));
      if (hit) svcSel.value = hit.value;
    }

    // Положим svc в hidden для бэкенда
    const svcCodeHidden = document.getElementById('svc_code');
    if (svcCodeHidden && qp.svc) svcCodeHidden.value = qp.svc;

    // Превью (если пришли значения сразу из URL)
    if (qp.meta || qp.eur || qp.min) {
      renderQuizSummaryFromMeta(qp.meta, qp.eur, qp.min);
    }
  })();

  // Рендер полного «итога» из meta_b64 прямо в превью (client-side) с правильной UTF-8
  function renderQuizSummaryFromMeta(meta_b64, sum_eur, sum_min){
    try{
      const box = document.getElementById('quizPreview');
      if (!box) return;
      function b64urlToUtf8(s){
        if (!s) return '';
        s = s.replace(/-/g,'+').replace(/_/g,'/');
        const pad = s.length % 4; if (pad) s += '='.repeat(4-pad);
        const bin = atob(s);
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return new TextDecoder('utf-8').decode(bytes);
      }
      const json = meta_b64 ? b64urlToUtf8(meta_b64) : '';
      let html = '';
      if (json){
        const obj = JSON.parse(json);
        const quiz = (obj && obj.quiz && typeof obj.quiz==='object') ? obj.quiz : null;
        if (quiz){
          const groups = {MANICURE:[], PEDICURE:[], OTHER:[]};
          Object.keys(quiz).forEach(k=>{
            if (k.startsWith('manicure_')) groups.MANICURE.push([k,quiz[k]]);
            else if (k.startsWith('pedicure_')) groups.PEDICURE.push([k,quiz[k]]);
            else groups.OTHER.push([k,quiz[k]]);
          });
          const labelMap = {oldcover:'Что на ногтях сейчас', service:'Услуга', cover:'Покрытие', length:'Длина', design:'Дизайн', spa:'SPA'};
          const partOf = (k)=>{ const i=k.indexOf('_'); return i>-1 ? k.slice(i+1).toLowerCase() : k; };
          html += '<div class="ok" style="background:#f8fafc;border:1px solid #e2e8f0;padding:10px;border-radius:8px">';
          html += '<div style="font-weight:700;margin-bottom:6px">Квиз — итог выбора</div>';
          ['MANICURE','PEDICURE','OTHER'].forEach(area=>{
            const arr = groups[area]; if (!arr || !arr.length) return;
            html += `<div style="margin:6px 0 2px"><b>${area==='OTHER'?'Другое':area}</b></div><ul style="margin:0 0 6px 18px;padding:0">`;
            arr.forEach(([k,v])=>{
              html += `<li>${labelMap[partOf(k)]||partOf(k)}: ${String(v)}</li>`;
            });
            html += '</ul>';
          });
          const pills=[];
          if (typeof sum_eur!=='undefined' && sum_eur!==null && sum_eur!=='')
            pills.push(`💶 Итого: <b>${sum_eur}</b> €`);
          if (typeof sum_min!=='undefined' && sum_min!==null && sum_min!==''){
            const mins = parseInt(sum_min,10)||0;
            const h = Math.floor(mins/60), m = mins%60;
            const hm = h && m ? `${h} ч ${m} мин` : (h?`${h} ч`:`${m} мин`);
            pills.push(`⏱ Общая длительность: <b>${hm}</b>`);
          }
          if (pills.length){
            html += `<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">`+
                    pills.map(x=>`<span class="pill" style="display:inline-block;padding:6px 10px;border-radius:999px;border:1px solid #e5e7eb;background:#fff">${x}</span>`).join('')+
                    `</div>`;
          }
          html += '</div>';
        }
      }
      if (html){
        box.style.display='block';
        box.innerHTML = html;
      }
    }catch(e){}
  }

  // Календарь: раскрытие дня
  (function(){
    const dayMap = <?php
      $pack = [];
      foreach ($daysMap as $d=>$arr){
        foreach($arr as $it){
          $pack[$d][] = [
  's'=>$it['s'], 'e'=>$it['e'],
  'staff'=>$it['staff_name'] ?: '—',
  'client'=>$it['client_name'] ?: '',
  'status'=>$it['status'],
  'id'=>(int)$it['id'],
  'client_id'=>isset($it['client_id']) ? (int)$it['client_id'] : 0,
];
        }
      }
      echo json_encode($pack, JSON_UNESCAPED_UNICODE);
    ?>;

    const wrap = document.querySelector('.cal');
    const panel = document.getElementById('dayList');
    const title = document.getElementById('dayListTitle');
    const body  = document.getElementById('dayListBody');
    const close = document.getElementById('dayListClose');

    function openDay(dateStr){
      const items = dayMap[dateStr] || [];
      title.textContent = new Date(dateStr).toLocaleDateString('ru-RU', { day:'2-digit', month:'long', year:'numeric' });
      body.innerHTML = '';
      if (!items.length){
        body.innerHTML = '<div class="muted">Записей нет.</div>';
      } else {
        items.forEach(it=>{
          const t1 = new Date(it.s.replace(' ','T'));
          const t2 = new Date(it.e.replace(' ','T'));
          const pad = n=> String(n).padStart(2,'0');
          const tm = `${pad(t1.getHours())}:${pad(t1.getMinutes())}–${pad(t2.getHours())}:${pad(t2.getMinutes())}`;
          const wrap = document.createElement('div');
wrap.className = 'dayrow';
wrap.style.display = 'flex';
wrap.style.gap = '12px';
wrap.style.alignItems = 'center';

const aEditTime = document.createElement('a');
aEditTime.href = `/booking/admin/appointments_edit.php?id=${it.id}`;
aEditTime.className = 'time';
aEditTime.style.textDecoration = 'none';
aEditTime.style.color = '#111';
aEditTime.textContent = tm;

const aEditStaff = document.createElement('a');
aEditStaff.href = `/booking/admin/appointments_edit.php?id=${it.id}`;
aEditStaff.className = 'staff';
aEditStaff.style.textDecoration = 'none';
aEditStaff.style.color = '#111';
aEditStaff.textContent = it.staff;

const aClient = document.createElement('a');
aClient.href = `/booking/admin/client.php?id=${it.client_id || 0}`;
aClient.className = 'client';
aClient.textContent = it.client || '—';

const st = document.createElement('span');
st.className = 'status';
st.textContent = it.status;

wrap.appendChild(aEditTime);
wrap.appendChild(aEditStaff);
wrap.appendChild(aClient);
wrap.appendChild(st);
body.appendChild(wrap);
        });
      }
      panel.style.display = 'block';
      panel.scrollIntoView({behavior:'smooth', block:'nearest'});
    }

    wrap?.addEventListener('click', (e)=>{
      const cell = e.target.closest('.cal__cell');
      if (!cell || cell.classList.contains('cal__cell--empty')) return;
      const d = cell.getAttribute('data-date');
      if (d) openDay(d);
    });
    close?.addEventListener('click', ()=>{ panel.style.display='none'; });
  })();
  </script>
</body>
</html>
