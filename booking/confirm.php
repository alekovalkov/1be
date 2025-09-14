<?php
declare(strict_types=1);

/* confirm.php — шаг подтверждения и создание записи (svc или slug) */

$lang = $_GET['lang'] ?? 'ru';
if (!in_array($lang, ['ru','en','et'], true)) $lang = 'ru';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

/* ----- входные параметры из URL ----- */
$svc    = isset($_GET['svc'])  ? (string)$_GET['svc']  : '';
$slug   = isset($_GET['slug']) ? (string)$_GET['slug'] : '';
$date   = isset($_GET['date']) ? (string)$_GET['date'] : '';
$time   = isset($_GET['time']) ? (string)$_GET['time'] : '';
$salon  = (isset($_GET['salon_id']) && $_GET['salon_id']!=='') ? (string)$_GET['salon_id'] : '';
$staff  = (isset($_GET['staff_id']) && $_GET['staff_id']!=='') ? (string)$_GET['staff_id'] : '';

$sum_eur = isset($_GET['sum_eur']) ? (int)$_GET['sum_eur'] : 0;
$sum_min = isset($_GET['sum_min']) ? (int)$_GET['sum_min'] : 0;

/* meta/quiz из URL */
$meta_b64 = isset($_GET['meta_b64']) ? (string)$_GET['meta_b64'] : '';
$quizParams = [];
foreach ($_GET as $k=>$v) {
  if (strpos((string)$k,'quiz_')===0) $quizParams[(string)$k] = is_array($v)?array_values($v):(string)$v;
}

/* базовая валидация */
$hasService = ($svc!=='' || $slug!=='');
$okBase = $hasService && preg_match('~^\d{4}-\d{2}-\d{2}$~',$date) && preg_match('~^\d{2}:\d{2}$~',$time);

/* 👉 если нет валидных даты/времени — уводим в календарь, сохраняя «от …» из квиза */
if (!$okBase) {
  $q = [
    'from'     => 'quiz',
    'lang'     => $lang,
    'svc'      => $svc,
    'services' => $svc, // совместимость
  ];
  if ($slug   !== '') $q['slug']     = $slug;
  if ($salon  !== '') $q['salon_id'] = $salon;
  if ($staff  !== '') $q['staff_id'] = $staff;

  if ($sum_min > 0) $q['sum_min'] = (string)$sum_min;
  if ($sum_eur > 0) {
    $q['sum_eur']   = (string)$sum_eur;   // «от …»
    $q['price_eur'] = (string)$sum_eur;   // совместимость
  }

  if ($meta_b64 !== '') $q['meta_b64'] = $meta_b64;
  foreach ($quizParams as $k=>$v) { $q[$k] = $v; }

  header('Location: /booking/?'.http_build_query($q, '', '&', PHP_QUERY_RFC3986));
  exit;
}
?>
<!doctype html>
<html lang="<?=h($lang)?>">
<head>
<meta charset="utf-8">
<title><?= $lang==='et'?'Kinnitamine':($lang==='en'?'Confirmation':'Подтверждение') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/booking/style.css">
<style>
  .wrap{max-width:960px;margin:24px auto;padding:0 14px;font:16px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Arial}
  .h1{font-size:36px;margin:0 0 14px}
  .row{display:grid;gap:16px;grid-template-columns:1fr 1fr}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px}
  .pad{padding:14px}
  .pair{display:flex;justify-content:space-between;gap:16px;border-bottom:1px solid #f1f5f9;padding:8px 0}
  .pair:last-child{border-bottom:0}
  .muted{color:#64748b}
  .ok{color:#16a34a;font-weight:700}
  .err{color:#b91c1c;font-weight:700}
  .btn{display:inline-block;background:#111827;color:#fff;border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}
  .hidden{display:none}
  .warn{background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:10px 12px;color:#9a3412;margin-bottom:12px}
  .form{display:grid;gap:10px}
  .form .ctrl input,.form .ctrl textarea{width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:10px}
  @media(max-width:900px){.row{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <h1 class="h1"><?= $lang==='et'?'Broneering — kinnitus':($lang==='en'?'Booking — confirmation':'Запись — подтверждение') ?></h1>

  <div class="row">
    <!-- Сводка -->
    <div class="card pad">
      <div id="warnBox" class="warn" <?= $salon!=='' ? 'style="display:none"' : '' ?>>
        <?= $lang==='et'
          ? 'Te ei valinud salongi. Palun pöörake erilist tähelepanu salongi nimele ja aadressile.'
          : ($lang==='en'
            ? 'You did not choose a salon. Please double-check the salon name and address.'
            : 'Вы не выбрали салон. Пожалуйста, внимательно проверьте название салона и адрес.') ?>
      </div>
      <div class="pair"><span><?= $lang==='et'?'Teenus':($lang==='en'?'Service':'Услуга') ?></span><b id="svcTitle">…</b></div>
      <div class="pair"><span><?= $lang==='et'?'Kuupäev':($lang==='en'?'Date':'Дата') ?></span><b><?=h($date)?></b></div>
      <div class="pair"><span><?= $lang==='et'?'Aeg':($lang==='en'?'Time':'Время') ?></span><b><?=h($time)?></b></div>
      <div class="pair"><span><?= $lang==='et'?'Salong':($lang==='en'?'Salon':'Салон') ?></span><b id="salonName"><?= $salon!==''?'…':'—' ?></b></div>
      <div class="pair"><span><?= $lang==='et'?'Töötaja':($lang==='en'?'Staff':'Сотрудник') ?></span><b id="staffName"><?= $staff!==''?'…':($lang==='et'?'Suvaline':($lang==='en'?'Any':'Любой')) ?></b></div>
      <div class="pair"><span><?= $lang==='et'?'Kestus':($lang==='en'?'Duration':'Длительность') ?></span><b id="svcDur">…</b></div>
      <div class="pair"><span><?= $lang==='et'?'Hind':($lang==='en'?'Price':'Цена') ?></span><b id="svcPrice">…</b></div>
    </div>

    <!-- Форма -->
    <div class="card pad">
      <form id="frm" class="form" autocomplete="off">
        <!-- скрытые -->
        <input type="hidden" id="hid_svc"   name="svc"      value="<?=h($svc)?>">
        <input type="hidden" id="hid_slug"  name="slug"     value="<?=h($slug)?>">
        <input type="hidden" id="hid_date"  name="date"     value="<?=h($date)?>">
        <input type="hidden" id="hid_time"  name="time"     value="<?=h($time)?>">
        <input type="hidden" id="hid_salon" name="salon_id" value="<?=h($salon)?>">
        <input type="hidden" id="hid_staff" name="staff_id" value="<?=h($staff)?>">

        <?php if ($sum_min>0): ?><input type="hidden" name="sum_min" value="<?=h((string)$sum_min)?>"><?php endif; ?>
        <?php if ($sum_eur>0): ?>
          <input type="hidden" name="sum_eur"   value="<?=h((string)$sum_eur)?>">
          <input type="hidden" name="price_eur" value="<?=h((string)$sum_eur)?>">
        <?php endif; ?>

        <?php if ($meta_b64 !== ''): ?>
          <input type="hidden" name="meta_b64" value="<?= h($meta_b64) ?>">
        <?php endif; ?>

        <div class="ctrl"><label><?= $lang==='et'?'Nimi':($lang==='en'?'Name':'Имя') ?></label>
          <input type="text" name="client_name" required placeholder="<?= $lang==='et'?'Teie nimi':($lang==='en'?'Your name':'Ваше имя') ?>">
        </div>
        <div class="ctrl"><label><?= $lang==='et'?'Telefon':($lang==='en'?'Phone':'Телефон') ?></label>
          <input id="clientPhone" type="tel" name="client_phone" placeholder="+372 5xxxxxxx" autocomplete="tel" inputmode="tel" pattern="^\+?[0-9\s\-()]{7,20}$" required>
        </div>
        <div class="ctrl"><label>Email</label>
          <input type="email" name="client_email" placeholder="name@example.com">
        </div>
        <div class="ctrl"><label><?= $lang==='et'?'Märkus':($lang==='en'?'Comment':'Комментарий') ?></label>
          <textarea name="comment" rows="3" placeholder="<?= $lang==='et'?'Soovid/kommentaar':($lang==='en'?'Your wishes / comment':'Пожелания/комментарий') ?>"></textarea>
        </div>

        <div id="msg" class="muted"></div>
        <div><button class="btn" id="btnSubmit"><?= $lang==='et'?'Kinnita broneering':($lang==='en'?'Confirm booking':'Подтвердить запись') ?></button></div>
      </form>

      <div id="done" class="hidden">
        <div class="ok" style="margin-bottom:8px">
          <?= $lang==='et'?'Broneering loodud!':($lang==='en'?'Booking created!':'Запись создана!') ?>
        </div>
        <div id="doneTxt" class="muted" style="margin-bottom:12px"></div>
        <div><a class="btn" href="/booking/"><?= $lang==='et'?'Uus broneering':($lang==='en'?'New booking':'Новая запись') ?></a></div>
      </div>
    </div>
  </div>
</div>

<script>
/* ====== PHP → JS ====== */
var LANG  = <?= json_encode($lang) ?>;
var SVC   = <?= json_encode($svc) ?>;
var SLUG  = <?= json_encode($slug) ?>;
var DATE  = <?= json_encode($date) ?>;
var TIME  = <?= json_encode($time) ?>;
var SALON = <?= json_encode($salon) ?>;
var STAFF = <?= json_encode($staff) ?>;
var SUM_EUR = <?= json_encode($sum_eur) ?>;
var SUM_MIN = <?= json_encode($sum_min) ?>;

/* мета/квиз из URL */
var META_B64    = <?= json_encode($meta_b64) ?>;
var QUIZ_PARAMS = <?= json_encode($quizParams, JSON_UNESCAPED_UNICODE) ?>;

var FROM_ANY = (SALON === '');

function t(ru,en,et){ return (LANG==='en'?en:(LANG==='et'?et:ru)); }
function fmtHM(min){ var m=Math.max(0,Number(min)||0),h=Math.floor(m/60),r=m%60, H=(LANG==='et'?'t':(LANG==='en'?'h':'ч')), M=(LANG==='et'?'min':(LANG==='en'?'min':'мин')); return h?(r?(h+' '+H+' '+r+' '+M):(h+' '+H)):(r+' '+M); }

function findById(list, id){ id=String(id||''); for(var i=0;i<(list||[]).length;i++){ if(String(list[i].id)===id) return list[i]; } return null; }

/* ====== загрузка меты услуги + подписи ====== */
(async function loadMeta(){
  if (!(SVC || SLUG)) return;
  try{
    var qs = new URLSearchParams({ action:'meta', lang:LANG });
    if (SVC)  qs.set('svc', SVC);
    if (SLUG) qs.set('slug', SLUG);
    var url = '/booking/api.php?' + qs.toString();
    var r = await fetch(url); var j = await r.json();
    if(!j || !j.ok) throw new Error((j&&j.error)||'meta error');

    window._meta_salons = j.salons || [];
    window._meta_staff  = j.staff  || [];
    window._meta_map    = j.staff_salons || [];

    var title   = (j.service && j.service.title) || (SVC||SLUG);
    var baseMin = (j.service && parseInt(j.service.duration_min||0,10)) || 0;
    var baseEur = (j.service && parseInt(j.service.price_eur||0,10))    || 0;

    document.getElementById('svcTitle').textContent = title;
    document.getElementById('svcDur').textContent   = fmtHM( (SUM_MIN>0?SUM_MIN:baseMin) );
    document.getElementById('svcPrice').textContent = String( (SUM_EUR>0?SUM_EUR:baseEur) ) + ' €';

    if (SALON!==''){ var s=findById(j.salons,SALON); if(s) document.getElementById('salonName').textContent=s.name; }
    if (STAFF!==''){ var st=findById(j.staff,STAFF); if(st) document.getElementById('staffName').textContent=st.name; }

    await autoAssignIfAny(); // автоназначение если "Любой"
  }catch(e){ console.error(e); }
})();

/* ====== автоназначение при «Любой» ====== */
async function autoAssignIfAny(){
  if (!DATE || !TIME || !(SVC||SLUG)) return;

  var q = new URLSearchParams({action:'slots', date:DATE, lang:LANG, include_staff:'1'});
  if (SVC)  q.set('svc', SVC);
  if (SLUG) q.set('slug', SLUG);
  if (SUM_MIN) q.set('sum_min', String(SUM_MIN));
  if (SALON) q.set('salon_id', SALON);

  var byTime = {};
  try{
    var r = await fetch('/booking/api.php?'+q.toString());
    var j = await r.json();
    if (j && j.ok && j.by_time) byTime = j.by_time;
  }catch(_){}

  var pickedStaffId = STAFF || '';
  if (!pickedStaffId){
    var arr = byTime[TIME] || [];
    if (arr.length){
      var ids = arr.map(function(x){return Number(x.id)}).sort(function(a,b){return a-b});
      pickedStaffId = String(ids[0]);
    }
  }

  var pickedSalonId = SALON || '';
  if (!pickedSalonId && pickedStaffId){
    var map = window._meta_map||[];
    for (var i=0;i<map.length;i++){
      if (String(map[i].staff_id)===String(pickedStaffId)){ pickedSalonId = String(map[i].salon_id); break; }
    }
  }

  if (pickedSalonId){
    var salonObj = findById(window._meta_salons, pickedSalonId);
    document.getElementById('salonName').textContent = salonObj? (salonObj.name||'—') : '—';
    document.getElementById('hid_salon').value = pickedSalonId;
  }
  if (pickedStaffId){
    var staffObj = findById(window._meta_staff, pickedStaffId);
    document.getElementById('staffName').textContent = staffObj? (staffObj.name||t('Любой свободный','Any available','Mõni vaba')) : t('Любой свободный','Any available','Mõni vaba');
    document.getElementById('hid_staff').value = pickedStaffId;
  }

  if (FROM_ANY){
    var sName = pickedSalonId ? (findById(window._meta_salons,pickedSalonId)?.name || '—') : '—';
    var stName= pickedStaffId ? (findById(window._meta_staff,pickedStaffId)?.name || t('любой свободный','any available','mõni vaba')) : t('любой','any','suvaline');
    var box = document.getElementById('warnBox'); if(box){ box.style.display='block'; box.textContent =
      (LANG==='et')
        ? ('Valisite „Kõik“. Süsteem määras: salong — '+sName+', töötaja — '+stName+'. Palun kontrollige kinnitamisel.')
        : (LANG==='en')
          ? ('You chose “All”. The system assigned: salon — '+sName+', staff — '+stName+'. Please double-check on confirmation.')
          : ('Вы выбрали «Все». Система назначила: салон — '+sName+', сотрудник — '+stName+'. Пожалуйста, проверьте на подтверждении.');
    }
  } else {
    var box = document.getElementById('warnBox'); if(box) box.style.display='none';
  }
}

/* ====== отправка формы ====== */
var frm = document.getElementById('frm');
var btn = document.getElementById('btnSubmit');
var msg = document.getElementById('msg');
var doneBox = document.getElementById('done');
var doneTxt = document.getElementById('doneTxt');

if (frm) frm.addEventListener('submit', async function(e){
  e.preventDefault(); msg.textContent=''; msg.className='muted'; btn.disabled=true;
  try{
    var fd = new FormData(frm);
    var payload = {
      action:'book', lang:LANG,
      svc:  document.getElementById('hid_svc').value,
      slug: document.getElementById('hid_slug').value,
      date: document.getElementById('hid_date').value,
      time: document.getElementById('hid_time').value,
      salon_id: document.getElementById('hid_salon').value || '',
      staff_id: document.getElementById('hid_staff').value || '',
      client_name:  fd.get('client_name') || '',
      client_phone: fd.get('client_phone') || '',
      client_email: fd.get('client_email') || '',
      comment:      fd.get('comment') || ''
    };
    if (SUM_MIN>0) { payload.sum_min = String(parseInt(SUM_MIN,10)); }
    if (SUM_EUR>0) { payload.sum_eur = String(parseInt(SUM_EUR,10)); payload.price_eur = payload.sum_eur; }

    // прокинуть квиз
    if (META_B64) {
      payload.meta_b64 = META_B64;
    } else {
      var keys = Object.keys(QUIZ_PARAMS||{});
      if (keys.length){
        var q = {}; keys.forEach(function(k){ q[k.replace(/^quiz_/,'')] = QUIZ_PARAMS[k]; });
        payload.meta = JSON.stringify({quiz:q});
      }
    }
    if (!payload.meta_b64) {
      var mb = fd.get('meta_b64');
      if (mb) payload.meta_b64 = mb;
    }

    var res = await fetch('/booking/api.php?action=book', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
      body:new URLSearchParams(payload)
    });
    var js = await res.json();
    if(!js.ok) throw new Error(js.error||'Error');

    frm.classList.add('hidden'); doneBox.classList.remove('hidden');
    doneTxt.textContent = t(
      'Дата: '+payload.date+', время: '+payload.time+'. Номер брони: '+js.id+'.',
      'Date: '+payload.date+', time: '+payload.time+'. Booking ID: '+js.id+'.',
      'Kuupäev: '+payload.date+', aeg: '+payload.time+'. Broneeringu nr: '+js.id+'.'
    );
  }catch(err){
    msg.className='err'; msg.textContent=String(err.message||err);
    btn.disabled=false;
  }
});
</script>
<script>
// — Маска телефона (одна, без дублей) —
(function(){
  const inp = document.querySelector('input[name="client_phone"]');
  if(!inp) return;
  inp.addEventListener('focus', ()=>{ if(!inp.value.trim()) inp.value='+372 '; });
  inp.addEventListener('input', ()=>{
    let v = inp.value.replace(/[^\d+]+/g,'');
    v = (v[0]==='+' ? '+' : '') + v.replace(/\+/g,'');
    inp.value = v;
  });
  const form = document.getElementById('frm');
  form?.addEventListener('submit', (e)=>{
    const v = inp.value.trim();
    if(!/^\+\d{7,15}$/.test(v)){
      e.preventDefault();
      alert('Телефон должен быть в формате +3725xxxxxxx');
      inp.focus();
    }
  });
})();
</script>
</body>
</html>