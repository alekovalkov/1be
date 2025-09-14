<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$u  = require_staff_auth();
$db = db();

/* tz мастера */
$tz='Europe/Tallinn';
$stz=$db->prepare("SELECT COALESCE(tz,'Europe/Tallinn') FROM staff WHERE id=:sid");
$stz->execute([':sid'=>$u['staff_id']]);
if($t=$stz->fetchColumn()) $tz=(string)$t;
$tzObj=new DateTimeZone($tz);

/* дата */
$day = (isset($_GET['d']) && preg_match('~^\d{4}-\d{2}-\d{2}$~',(string)$_GET['d'])) ? (string)$_GET['d'] : (new DateTimeImmutable('today',$tzObj))->format('Y-m-d');

/* helper */
if(!function_exists('pick_col')){
  function pick_col(PDO $pdo, string $table, array $cands): ?string {
    foreach($cands as $c){ $q=$pdo->query("SHOW COLUMNS FROM `$table` LIKE ".$pdo->quote($c)); if($q&&$q->fetch()) return $c; }
    return null;
  }
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

/* колонки */
$startCol = pick_col($db,'appointments',['starts','start_dt','start_at','start','begin_at']) ?: 'starts';
$endCol   = pick_col($db,'appointments',['ends','end_dt','end_at','end','finish_at'])      ?: 'ends';
$colStatus= pick_col($db,'appointments',['status','state']) ?: 'status';
$colCName   = pick_col($db,'appointments',['client_name','customer_name','name','client']);
$colCPhone  = pick_col($db,'appointments',['client_phone','phone','customer_phone','tel']);
$colCEmail  = pick_col($db,'appointments',['client_email','email','customer_email','mail']);
$colComment = pick_col($db,'appointments',['comment','notes','note','remarks']);
$colSvc     = pick_col($db,'appointments',['service_title','service','service_name','svc_title']);
$colPrice   = pick_col($db,'appointments',['price_eur','price','amount_eur','amount']);
$colMeta    = pick_col($db,'appointments',['meta']); // читаем meta

/* join clients при необходимости */
$needJoin = (!$colCName && !$colCPhone && !$colCEmail);
$clientIdCol = null;
if($needJoin){
  $clientIdCol = pick_col($db,'appointments',['client_id','customer_id','clientID','customerID','client','customer']);
  $needJoin = (bool)$clientIdCol;
}
$clientsNameCol=$clientsPhoneCol=$clientsEmailCol=null;
if($needJoin){
  $clientsNameCol  = pick_col($db,'clients',['name','full_name','client_name']);
  $clientsPhoneCol = pick_col($db,'clients',['phone','tel','client_phone']);
  $clientsEmailCol = pick_col($db,'clients',['email','mail','client_email']);
}

/* SELECT */
$select="SELECT a.id, a.$startCol AS starts, a.$endCol AS ends, a.$colStatus AS status";
$select .= $needJoin
  ? ( ($clientsNameCol  ? ", c.$clientsNameCol  AS client_name"  : ", NULL AS client_name")
    .($clientsPhoneCol ? ", c.$clientsPhoneCol AS client_phone" : ", NULL AS client_phone")
    .($clientsEmailCol ? ", c.$clientsEmailCol AS client_email" : ", NULL AS client_email") )
  : ( ($colCName  ? ", a.$colCName  AS client_name"  : ", NULL AS client_name")
    .($colCPhone ? ", a.$colCPhone AS client_phone" : ", NULL AS client_phone")
    .($colCEmail ? ", a.$colCEmail AS client_email" : ", NULL AS client_email") );

$select .= $colComment ? ", a.$colComment AS comment"       : ", NULL AS comment";
$select .= $colSvc     ? ", a.$colSvc     AS service_title" : ", NULL AS service_title";
$select .= $colPrice   ? ", a.$colPrice   AS price_eur"     : ", NULL AS price_eur";
$select .= $colMeta    ? ", a.$colMeta    AS meta"          : ", NULL AS meta";
$select .= " FROM appointments a";
if($needJoin) $select .= " LEFT JOIN clients c ON c.id = a.$clientIdCol";
$select .= " WHERE a.staff_id=:sid AND DATE(a.$startCol)=:d ORDER BY a.$startCol";

$st=$db->prepare($select);
$st->execute([':sid'=>$u['staff_id'], ':d'=>$day]);
$rows=$st->fetchAll(PDO::FETCH_ASSOC);

/* now/prev/next */
$now = new DateTimeImmutable('now',$tzObj);
$dayObj=new DateTimeImmutable($day,$tzObj);
$prev=$dayObj->modify('-1 day')->format('Y-m-d');
$next=$dayObj->modify('+1 day')->format('Y-m-d');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>ЛК мастера — расписание</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f8fafc;margin:0;color:#0f172a}
  .top{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;background:#fff;border-bottom:1px solid #e5e7eb;gap:12px}
  .date-nav{display:flex;align-items:center;gap:8px}
  .btn,a.btn{display:inline-block;background:#111827;color:#fff;text-decoration:none;border:none;border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer}
  .btn.sec{background:#e5e7eb;color:#111827}
  .btn.danger{background:#b91c1c}
  .btn[disabled]{opacity:.5;cursor:not-allowed}
  input[type=date]{padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px}
  .wrap{max-width:1000px;margin:0 auto;padding:16px}

  .table{background:#fff;border:1px solid #e5e7eb;border-radius:12px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:14px;vertical-align:top}
  th{background:#f1f5f9}
  .status{padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid #cbd5e1;display:inline-block}
  .muted{color:#64748b}
  .countdown{font-size:12px;color:#64748b;margin-top:4px}

  .svc{font-weight:700}
  .svc-btn{background:none;border:0;padding:0;margin:0;color:#0ea5e9;cursor:pointer;font:inherit}
  .svc-btn:hover{text-decoration:underline}
  .quiz{color:#475569;font-size:13px;margin-top:4px}

  .mobile-actions{display:none}
  @media(max-width:640px){
    th.col-actions,td.col-actions{display:none}
    .mobile-actions{display:block;margin-top:8px}
    .mobile-actions .btn{padding:6px 10px;border-radius:8px}
    th,td{white-space:normal}
  }

  /* Лайтбокс */
  .modal{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;align-items:center;justify-content:center;padding:16px}
  .modal.open{display:flex}
  .modal__dlg{background:#fff;border-radius:12px;max-width:520px;width:100%;box-shadow:0 10px 30px rgba(0,0,0,.2)}
  .modal__body{padding:16px}
  .modal__title{margin:0;padding:14px 16px;border-bottom:1px solid #e5e7eb}
  .modal__foot{padding:12px 16px;border-top:1px solid #e5e7eb;text-align:right}
  .status.cancelled {
    background:#fee2e2;
    border-color:#fecaca;
    color:#991b1b;
  }
</style>
</head>
<body>
  <div class="top">
    <div class="date-nav">
      <a class="btn sec" href="?d=<?=$prev?>">‹</a>
      <form id="dForm" method="get" style="display:inline">
        <input type="date" name="d" value="<?=h($day)?>" id="dInput">
      </form>
      <a class="btn sec" href="?d=<?=$next?>">›</a>
    </div>
    <div style="margin-left:auto"><a class="btn danger" href="/staff/logout.php">Выйти</a></div>
  </div>

  <div class="wrap">
    <h1 style="margin:0 0 12px">Мои записи на <?=h($day)?> (TZ: <?=h($tz)?>)</h1>
    <div class="table">
      <table>
        <thead>
          <tr>
            <th>Время</th>
            <th>Клиент</th>
            <th>Услуга</th>
            <th>Цена</th>
            <th>Статус</th>
            <th class="col-actions">Действия</th>
          </tr>
        </thead>
        <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="6" class="muted">Записей нет.</td></tr>
        <?php else: foreach($rows as $r):
          $startsDt=new DateTimeImmutable($r['starts'],$tzObj);
          $endsDt  =new DateTimeImmutable($r['ends'],$tzObj);
          $starts=$startsDt->format('H:i'); $ends=$endsDt->format('H:i');
          $status = (string)($r['status'] ?? 'pending');
$isCancelled = in_array(strtolower($status), ['cancelled','canceled'], true);

$allowAt = $startsDt->modify('+15 minutes');
// кнопка доступна только если НЕ отменено и наступил allowAt
$canCancel = !$isCancelled && ($now >= $allowAt);

          // === подготовим данные квиза (поддерживаем и {"quiz":{...}}, и просто {...}) ===
          $quizPairs=[];
          $rawMeta = (string)($r['meta'] ?? '');
          if ($rawMeta !== '' && $rawMeta !== 'null') {
            $m = json_decode($rawMeta, true);
            if (is_array($m)) {
              $data = (isset($m['quiz']) && is_array($m['quiz'])) ? $m['quiz'] : $m;
              if (is_array($data)) {
                foreach($data as $k=>$v){
                  $label=str_replace(['_','-'],' ',(string)$k);
                  if(is_array($v)) $v=implode(', ',array_map('strval',$v));
                  elseif(is_object($v)) $v=json_encode($v, JSON_UNESCAPED_UNICODE);
                  $quizPairs[] = [ 'k'=>mb_convert_case($label, MB_CASE_TITLE, 'UTF-8'), 'v'=>(string)$v ];
                }
              }
            }
          }
          $quizJson = htmlspecialchars(json_encode($quizPairs, JSON_UNESCAPED_UNICODE), ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        ?>
          <tr data-id="<?= (int)$r['id'] ?>"
    <?= $isCancelled ? '' : 'data-allowts="'.$allowAt->getTimestamp().'"' ?>
    data-nowts="<?=$now->getTimestamp()?>">

            <td><b><?=h($starts)?>–<?=h($ends)?></b></td>

            <td>
              <div><?=h($r['client_name'] ?? '')?></div>
              <div class="muted"><?=h($r['client_phone'] ?? '')?> <?=h($r['client_email'] ?? '')?></div>
              <?php if(!empty($r['comment'])): ?><div class="muted">💬 <?=h((string)$r['comment'])?></div><?php endif; ?>
              <div class="mobile-actions">
  <?php if(!$isCancelled): ?>
    <button class="btn danger act-cancel" <?=$canCancel?'':'disabled'?>>Отменить</button>
    <div class="countdown"><?= $canCancel ? '' : 'Можно отменить позже' ?></div>
  <?php endif; ?>
</div>
            </td>

            <td>
              <div class="svc">
                <!-- всегда кликабельно: если данных нет — покажем «Нет деталей» -->
                <button class="svc-btn" data-quiz='<?=$quizJson?>'><?=h($r['service_title'] ?? '—')?></button>
              </div>
            </td>

            <td><?= ($r['price_eur']!==null && $r['price_eur']!=='') ? (float)$r['price_eur'].' €' : '—' ?></td>
            <td><span class="status <?= $isCancelled ? 'cancelled' : '' ?>"><?=h($status)?></span></td>

            <td class="col-actions">
  <?php if(!$isCancelled): ?>
    <button class="btn danger act-cancel" <?=$canCancel?'':'disabled'?>>Отменить</button>
    <div class="countdown"><?= $canCancel ? '' : 'Можно отменить позже' ?></div>
  <?php else: ?>
    <div class="muted">Отменено</div>
  <?php endif; ?>
</td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<!-- Лайтбокс -->
<div class="modal" id="qmodal" aria-hidden="true">
  <div class="modal__dlg">
    <h3 class="modal__title">Детали квиза</h3>
    <div class="modal__body" id="qbody"></div>
    <div class="modal__foot"><button class="btn sec" id="qclose">OK</button></div>
  </div>
</div>

<script>
/* смена даты */
document.getElementById('dInput')?.addEventListener('change', function(){ this.form.submit(); });

/* формат чч:мм:сс */
function fmtHMS(sec){
  sec = Math.max(0, sec|0);
  const h = Math.floor(sec/3600);
  const m = Math.floor((sec%3600)/60);
  const s = sec%60;
  return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

/* тик: разблокирование и таймер */
setInterval(()=>{
  document.querySelectorAll('tr[data-allowts]').forEach(tr=>{
    const allow=+tr.dataset.allowts, now=Math.floor(Date.now()/1000);
    const btns=tr.querySelectorAll('.act-cancel'), cds=tr.querySelectorAll('.countdown');
    const left=allow-now;
    if(left<=0){ btns.forEach(b=>b.disabled=false); cds.forEach(c=>c.textContent=''); }
    else{ cds.forEach(c=>c.textContent='Можно отменить через '+fmtHMS(left)); }
  });
}, 1000);

/* отмена */
document.querySelectorAll('.act-cancel').forEach(btn=>{
  btn.addEventListener('click', function(){
    if(this.disabled) return;
    const tr=this.closest('tr'); const id=tr.getAttribute('data-id');
    if(!confirm('Отменить запись?')) return;
    fetch('/staff/api.php?action=set_status', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ id:id, status:'cancel' })
    }).then(r=>r.json()).then(j=>{
      if(!j.ok){ alert(j.error||'Ошибка'); return; }
      tr.querySelector('.status').textContent=j.status;
const st = (j.status||'').toLowerCase();
if (st === 'cancelled' || st === 'canceled') {
  tr.querySelectorAll('.act-cancel').forEach(b => { b.disabled = true; b.style.display = 'none'; });
  tr.querySelectorAll('.countdown').forEach(c => c.textContent = '');
  tr.removeAttribute('data-allowts');
  tr.querySelector('.status')?.classList.add('cancelled');
}
    }).catch(()=>alert('Ошибка сети'));
  });
});

/* лайтбокс по клику на услугу */
(function(){
  const modal=document.getElementById('qmodal');
  const body =document.getElementById('qbody');
  const close=document.getElementById('qclose');
  function open(items){
    if(!Array.isArray(items)||!items.length){ body.innerHTML='<div class="muted">Нет деталей</div>'; }
    else{
      let html='<table style="width:100%;border-collapse:collapse">';
      items.forEach(it=>{
        html+=`<tr><td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;color:#64748b">${it.k}</td><td style="padding:6px 8px;border-bottom:1px solid #e5e7eb"><b>${it.v}</b></td></tr>`;
      });
      html+='</table>';
      body.innerHTML=html;
    }
    modal.classList.add('open');
  }
  function closeM(){ modal.classList.remove('open'); body.innerHTML=''; }
  document.addEventListener('click', e=>{
    const b=e.target.closest('.svc-btn'); if(b){ e.preventDefault(); const j=b.dataset.quiz?JSON.parse(b.dataset.quiz):[]; open(j); }
    if(e.target===modal) closeM();
  });
  close.addEventListener('click', closeM);
})();
</script>
</body>
</html>
