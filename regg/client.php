<?php
declare(strict_types=1);

require __DIR__ . '/../config.php';

const DEFAULT_TZ = 'Europe/Tallinn';
date_default_timezone_set(DEFAULT_TZ);

/* ==== helpers ==== */
function db(): PDO { return pdo(); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function qcol(PDO $db, string $table, string $col): bool {
  return (bool)$db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($col))->fetch();
}
function tableExists(PDO $db, string $name): bool {
  return (bool)$db->query("SHOW TABLES LIKE " . $db->quote($name))->fetch();
}
function pick_col(PDO $db, string $table, array $cands): ?string {
  foreach ($cands as $c) if (qcol($db,$table,$c)) return $c; return null;
}

/* ==== loyalty (баллы) ==== */
function ensure_ledger(PDO $db): void {
  if (tableExists($db, 'loyalty_ledger')) return;
  // Минимальная таблица движений (без ip/note — для совместимости)
  $db->exec("
    CREATE TABLE IF NOT EXISTS loyalty_ledger (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      client_id INT NOT NULL,
      delta INT NOT NULL,
      reason VARCHAR(100) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");
}

/** Добавляет движение баллов. Пишем только в реально существующие поля. */
function add_loyalty_delta(PDO $db, int $clientId, int $delta, string $reason=''): void {
  if ($delta === 0) return;

  if (!tableExists($db,'loyalty_ledger')) {
    ensure_ledger($db);
  }

  $cols = ['client_id','delta'];
  $vals = [':cid',':d'];
  $par  = [':cid'=>$clientId, ':d'=>$delta];

  if (qcol($db,'loyalty_ledger','reason')) {
    $cols[] = 'reason'; $vals[]=':r'; $par[':r'] = ($reason !== '') ? $reason : null;
  }

  $sql = "INSERT INTO loyalty_ledger (`".implode('`,`',$cols)."`) VALUES (".implode(',',$vals).")";
  $st  = $db->prepare($sql);
  $st->execute($par);

  // Если есть clients.points_balance — поддержим и её (дополнительно)
  if (qcol($db,'clients','points_balance')) {
    $st = $db->prepare("UPDATE clients SET points_balance = points_balance + :d WHERE id = :id");
    $st->execute([':d'=>$delta, ':id'=>$clientId]);
  }
}

/* ==== quiz summary (красивый итог) ==== */
function render_quiz_summary_from_json(?string $meta_json, $sum_eur=null, $sum_min=null): string {
  $quiz = [];
  if ($meta_json) {
    $obj = json_decode($meta_json, true);
    if (is_array($obj) && isset($obj['quiz']) && is_array($obj['quiz'])) {
      $quiz = $obj['quiz'];
    }
  }

  $labels = [
    'oldcover'=>'Что на ногтях сейчас','service'=>'Услуга','cover'=>'Покрытие',
    'length'=>'Длина','design'=>'Дизайн','spa'=>'SPA'
  ];
  $hm = function(int $mins) {
    $h=intdiv($mins,60); $m=$mins%60;
    if ($h && $m) return "{$h} ч {$m} мин";
    if ($h) return "{$h} ч";
    return "{$m} мин";
  };

  ob_start(); ?>
  <div class="quiz-card">
    <div class="quiz-title">Квиз — итог</div>
    <?php if ($quiz): ?>
      <ul class="quiz-list">
        <?php foreach ($quiz as $k=>$v):
          $part = strtolower(substr($k, strpos($k,'_')!==false ? strpos($k,'_')+1 : 0));
          $label = $labels[$part] ?? $k;
        ?>
          <li><span class="q-label"><?=h($label)?>:</span> <span class="q-val"><?=h((string)$v)?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="muted">Данные квиза отсутствуют.</div>
    <?php endif; ?>

    <div class="chips">
      <?php if ($sum_eur !== null): ?>
        <span class="chip">💶 Цена: <b><?=h($sum_eur)?></b> €</span>
      <?php endif; ?>
      <?php if ($sum_min !== null): ?>
        <span class="chip">⏱ <?=h($hm((int)$sum_min))?></span>
      <?php endif; ?>
    </div>
  </div>
  <?php
  return (string)ob_get_clean();
}

/* ==== main ==== */
$db = db();
$err = ''; $msg = '';
$clientId = (int)($_GET['id'] ?? 0);
if ($clientId<=0) { die('Missing id'); }

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['loyalty_form'])) {
  try {
    $amount = max(0,(int)($_POST['amount']??0));
    $action = $_POST['action'] ?? 'add';
    $reason = trim((string)($_POST['reason']??'manual'));
    if ($amount<=0) throw new RuntimeException('Укажите сумму больше нуля.');
    $delta = $action==='deduct' ? -$amount : $amount;
    add_loyalty_delta($db,$clientId,$delta,$reason);
    header('Location: ?id='.$clientId.'&msg='.urlencode(($delta>0?'+':'-').abs($delta).' pts'));
    exit;
  } catch(Throwable $e){ $err=$e->getMessage(); }
}

/* client */
$st=$db->prepare("SELECT * FROM clients WHERE id=:id"); $st->execute([':id'=>$clientId]); $cli=$st->fetch(PDO::FETCH_ASSOC);
if(!$cli) die('Client not found');

/* balance = начисленные (appointments.points_award with awarded) + ручные движения (ledger) */
$sumAward=0;
if(qcol($db,'appointments','points_award')&&qcol($db,'appointments','points_awarded_at')){
  $st=$db->prepare("SELECT COALESCE(SUM(points_award),0) FROM appointments WHERE client_id=:id AND points_awarded_at IS NOT NULL");
  $st->execute([':id'=>$clientId]); $sumAward=(int)$st->fetchColumn();
}
$sumLedger=0;
if(tableExists($db,'loyalty_ledger')){
  $st=$db->prepare("SELECT COALESCE(SUM(delta),0) FROM loyalty_ledger WHERE client_id=:id"); $st->execute([':id'=>$clientId]);
  $sumLedger=(int)$st->fetchColumn();
}
$currentBalance=$sumAward+$sumLedger;

/* ledger history */
$ledgerRows=[];
if(tableExists($db,'loyalty_ledger')){
  $st=$db->prepare("SELECT id, delta, reason, created_at FROM loyalty_ledger WHERE client_id=:id ORDER BY id DESC LIMIT 100");
  $st->execute([':id'=>$clientId]); $ledgerRows=$st->fetchAll(PDO::FETCH_ASSOC);
}

/* appointments of client */
$colStart=pick_col($db,'appointments',['starts','start_dt','start_at','start','begin_at'])?:'starts';
$colEnd  =pick_col($db,'appointments',['ends','end_dt','end_at','end','finish_at'])?:'ends';
$colPrice=pick_col($db,'appointments',['price_eur','total_eur','price','amount']);
$colDur  =pick_col($db,'appointments',['duration_min','minutes','duration']);
$colMeta =qcol($db,'appointments','meta')?'meta':null;

$sql="SELECT a.id,a.$colStart AS s,a.$colEnd AS e,a.status".
     ($colPrice?",a.`$colPrice` AS price":",NULL AS price").
     ($colDur?",a.`$colDur` AS dur_min":",NULL AS dur_min").
     ($colMeta?",a.`$colMeta` AS meta":",NULL AS meta").
     ",COALESCE(staff.name,'') AS staff_name
     FROM appointments a LEFT JOIN staff ON staff.id=a.staff_id
     WHERE a.client_id=:cid ORDER BY a.$colStart DESC";
$st=$db->prepare($sql);$st->execute([':cid'=>$clientId]);$apps=$st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Профиль клиента — <?=h($cli['name'] ?: ('#'.$cli['id']))?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --bg:#f6f7fb;
    --card:#ffffff;
    --muted:#64748b;
    --text:#0f172a;
    --accent:#4f46e5;
    --border:#e5e7eb;
    --chip:#f3f4f6;
    --good:#16a34a;
    --bad:#dc2626;
  }
  *{box-sizing:border-box}
  body{margin:24px;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text)}
  a{color:#1d4ed8;text-decoration:none}
  a:hover{text-decoration:underline}
  .wrap{max-width:1100px;margin:0 auto}
  .row{display:grid;grid-template-columns:360px 1fr;gap:18px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:14px;box-shadow:0 6px 20px rgba(0,0,0,.05)}
  .hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
  .title{font-size:20px;font-weight:700}
  .muted{color:var(--muted)}
  .btn{padding:8px 12px;border-radius:10px;border:1px solid var(--border);background:#f8fafc;color:var(--text);cursor:pointer;display:inline-flex;gap:8px;align-items:center}
  .btn:hover{background:#eef2ff;border-color:#c7d2fe}
  .btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .kv{display:grid;grid-template-columns:130px 1fr;gap:6px;margin:8px 0}
  .pill{display:inline-block;padding:6px 10px;border-radius:999px;border:1px solid var(--border);background:#f8fafc}
  .good{color:#16a34a}.bad{color:#dc2626}
  table{border-collapse:collapse;width:100%;font-size:14px;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
  th,td{border-bottom:1px solid var(--border);padding:10px 8px;vertical-align:top}
  th{text-align:left;color:#1f2937;font-weight:700;background:#f8fafc}
  tr:hover td{background:#fafafa}
  .quiz-card{margin-top:10px;border:1px dashed var(--border);border-radius:12px;padding:12px;background:#fafafa}
  .quiz-title{font-weight:700;margin-bottom:6px}
  .quiz-list{margin:0 0 6px 18px;padding:0}
  .quiz-list li{margin:2px 0}
  .q-label{color:#374151}
  .q-val{color:#111827}
  .chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
  .chip{display:inline-block;padding:6px 10px;border-radius:999px;border:1px solid var(--border);background:var(--chip)}
  .ledger-delta-pos{color:#16a34a;font-weight:700}
  .ledger-delta-neg{color:#dc2626;font-weight:700}
  .quick{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
  .quick .btn{padding:6px 10px}
  .topbar{display:flex;gap:10px;align-items:center;margin-bottom:16px}
  .actions-inline{display:flex;gap:8px;flex-wrap:wrap}
  input[type="number"], select{
    width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:#fff;color:var(--text)
  }
  .contact-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
  .contact-actions a.btn:disabled,
  .btn[aria-disabled="true"]{opacity:.45;pointer-events:none}
</style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <a class="btn" href="/booking/admin/appointments.php">← Назад к записям</a>
    <div class="muted">Профиль клиента</div>
  </div>

  <?php if($msg): ?><div class="card" style="border-color:#c7f9cc;background:#ecfdf5">✅ <?=h($msg)?></div><?php endif; ?>
  <?php if($err): ?><div class="card" style="border-color:#fecaca;background:#fef2f2">❌ <?=h($err)?></div><?php endif; ?>

  <div class="row">
    <!-- левая колонка -->
    <div class="card">
      <div class="hdr">
        <div class="title"><?=h($cli['name'] ?: 'Без имени')?></div>
        <div class="muted">#<?=h($cli['id'])?></div>
      </div>

      <div class="kv">
        <div class="muted">Телефон</div>
        <div>
          <?php if (!empty($cli['phone'])): ?>
            <a class="btn" href="tel:<?=h(preg_replace('~\s+~','',$cli['phone']))?>" title="Позвонить">
              📞 Позвонить
            </a>
            <div class="muted" style="margin-top:6px"><?=h($cli['phone'])?></div>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="kv">
        <div class="muted">Email</div>
        <div>
          <?php if (!empty($cli['email'])): ?>
            <a class="btn" href="mailto:<?=h($cli['email'])?>" title="Написать e-mail">
              ✉️ Написать e-mail
            </a>
            <div class="muted" style="margin-top:6px"><?=h($cli['email'])?></div>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="kv">
        <div class="muted">Баланс</div>
        <div><span class="pill"><?=h($currentBalance)?> pts</span></div>
      </div>
    </div>

    <!-- правая колонка -->
    <div class="card">
      <div class="hdr">
        <div class="title">Баллы — начислить/списать</div>
      </div>
      <form method="post" id="loyaltyForm" class="grid2">
        <input type="hidden" name="loyalty_form" value="1">
        <div>
          <label class="muted">Сумма</label>
          <input type="number" name="amount" min="1" required>
        </div>
        <div>
          <label class="muted">Причина</label>
          <select name="reason">
            <option value="discount">Обмен на скидку</option>
            <option value="promo">Промо/бонус</option>
            <option value="gift">Подарок</option>
            <option value="refund">Компенсация</option>
            <option value="manual">Ручная операция</option>
            <option value="correction">Коррекция</option>
          </select>
        </div>
        <div class="actions-inline" style="align-items:flex-end">
          <button class="btn primary" type="submit" name="action" value="add">+ Начислить</button>
          <button class="btn" type="submit" name="action" value="deduct">– Списать</button>
        </div>
        <div>
          <div class="muted">Быстро</div>
          <div class="quick">
            <button class="btn" data-q="+10" type="button">+10</button>
            <button class="btn" data-q="+50" type="button">+50</button>
            <button class="btn" data-q="+100" type="button">+100</button>
            <button class="btn" data-q="-10" type="button">-10</button>
            <button class="btn" data-q="-50" type="button">-50</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- История баллов -->
  <div class="card" style="margin-top:16px">
    <div class="hdr"><div class="title">История баллов</div><div class="muted">последние 100</div></div>
    <?php if($ledgerRows): ?>
      <table>
        <tr><th>ID</th><th>Дата</th><th>Δ</th><th>Причина</th></tr>
        <?php foreach($ledgerRows as $r): ?>
          <tr>
            <td><?=h($r['id'])?></td>
            <td class="muted"><?=h($r['created_at'])?></td>
            <td>
              <?php
                $d=(int)$r['delta'];
                $cls = $d>=0 ? 'ledger-delta-pos' : 'ledger-delta-neg';
                echo '<span class="'.$cls.'">'.($d>0?'+':'').$d.'</span>';
              ?>
            </td>
            <td><?=h($r['reason'] ?? '')?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <div class="muted">Пока нет движений.</div>
    <?php endif; ?>
  </div>

  <!-- Записи клиента (квиз-итог + цена/время, без названия услуги) -->
  <div class="card" style="margin-top:16px">
    <div class="hdr"><div class="title">Записи клиента</div></div>
    <?php if(!$apps): ?>
      <div class="muted">Записей не найдено.</div>
    <?php else: ?>
      <?php foreach($apps as $a): ?>
        <div style="padding:12px;border:1px solid var(--border);border-radius:12px;background:#fff; margin-bottom:12px">
          <div style="display:flex;gap:10px;justify-content:space-between;align-items:center;flex-wrap:wrap">
            <div><b><?=h($a['s'])?></b> → <b><?=h($a['e'])?></b></div>
            <div class="muted"><?=h($a['status'])?> · <?=h($a['staff_name']?:'—')?></div>
            <a class="btn" href="/booking/admin/appointments_edit.php?id=<?= (int)$a['id'] ?>">Изменить запись</a>
          </div>
          <?= render_quiz_summary_from_json($a['meta'], $a['price'], $a['dur_min']) ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
// Быстрые кнопки для суммы
(function(){
  const form = document.getElementById('loyaltyForm');
  const amt  = form?.querySelector('input[name="amount"]');
  form?.querySelectorAll('.quick .btn').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      if (!amt) return;
      const v = btn.getAttribute('data-q')||'';
      const sign = v[0];
      const num = parseInt(v.slice(1),10)||0;
      amt.value = String(num);
      const action = (sign==='-') ? 'deduct' : 'add';
      form.querySelector(`button[name="action"][value="${action}"]`)?.focus();
    });
  });
})();
</script>
</body>
</html>