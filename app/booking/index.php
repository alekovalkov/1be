<?php
require_once __DIR__.'/config.php';
$svcCode = $_GET['svc'] ?? '';
$service = $svcCode ? getServiceByCode($svcCode) : null;
if (!$service){ http_response_code(404); echo "Service not found"; exit; }
$salons = getSalons(); $staff = getStaff();
$today = (new DateTime('today'))->format('Y-m-d');
?>
<!doctype html><html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars($service['title_ru'])?> — выбрать время</title>
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#fcf8f8;margin:0;padding:24px;color:#111}
.wrap{max-width:960px;margin:0 auto} h1{font-size:28px;margin:0 0 16px}
.grid{display:grid;grid-template-columns:360px 1fr;gap:16px}
.card{background:#fff;border:1px solid #eee;border-radius:12px;padding:16px}
.row{margin:0 0 10px} label{display:block;margin:0 0 6px;color:#6b7280}
input,select{padding:10px;border:1px solid #d1d5db;border-radius:8px;width:100%}
.slot{display:inline-block;margin:6px 8px 0 0}
.slot button{border:1px solid #d1d5db;border-radius:999px;padding:10px 16px;background:#fff;cursor:pointer}
.slot button:hover{background:#111;color:#fff;border-color:#111}
.hint{font-size:12px;color:#6b7280;margin-top:10px}
.pill{display:inline-block;background:#f3f4f6;border-radius:999px;padding:6px 10px;margin-right:6px}
.row-inline{display:flex;gap:10px;align-items:center}
</style></head><body><div class="wrap">
<h1><?=htmlspecialchars($service['title_ru'])?> — выбрать время</h1>
<div class="grid">
<div class="card">
  <div class="row"><label>Дата</label><input type="date" id="date" value="<?=$today?>"></div>
  <div class="row"><label>Салон</label><select id="salon"><option value="">Все</option>
    <?php foreach($salons as $s): ?><option value="<?=$s['id']?>"><?=$s['name']?></option><?php endforeach; ?>
  </select></div>
  <div class="row"><label>Сотрудник</label><select id="staff"><option value="">Все</option>
    <?php foreach($staff as $m): ?><option value="<?=$m['id']?>"><?=$m['name']?></option><?php endforeach; ?>
  </select></div>
  <div class="hint">Свободные слоты учитывают занятые записи и отпуска.</div>
</div>
<div class="card">
  <div class="row-inline"><span class="pill">Длительность: <?= (int)$service['duration_min'] ?> мин</span>
    <span class="pill">Цена: <?= (float)$service['price_eur'] ?> €</span></div>
  <div id="slots" style="margin-top:10px">Загрузка…</div>
</div>
</div></div>
<script>
const svc   = <?= json_encode($service['code']) ?>;
const slotsEl = document.getElementById('slots');
const dateEl  = document.getElementById('date');
const salonEl = document.getElementById('salon');
const staffEl = document.getElementById('staff');

async function loadSlots(){
  slotsEl.textContent='Загрузка...';
  const params = new URLSearchParams({svc, date:dateEl.value, salon:salonEl.value, staff:staffEl.value});
  try{
    const r = await fetch('api/slots.php?'+params.toString(),{cache:'no-store'});
    if(!r.ok) throw new Error('HTTP '+r.status);
    const j = await r.json();
    if (Array.isArray(j.staff)){
      const keep = staffEl.value; staffEl.innerHTML='<option value="">Все</option>';
      j.staff.forEach(m=>{ const o=document.createElement('option'); o.value=m.id; o.textContent=m.name; staffEl.appendChild(o); });
      if ([...staffEl.options].some(o=>o.value===keep)) staffEl.value=keep;
    }
    const times = Array.isArray(j.times)? j.times:[];
    if(!times.length){ slotsEl.textContent='Свободных слотов нет.'; return; }
    const frag=document.createDocumentFragment();
    times.forEach(t=>{ const w=document.createElement('div'); w.className='slot';
      const b=document.createElement('button'); b.textContent=t;
      b.addEventListener('click',()=>alert('Оформим запись на '+t+' ('+svc+')'));
      w.appendChild(b); frag.appendChild(w);
    });
    slotsEl.innerHTML=''; slotsEl.appendChild(frag);
  }catch(e){ console.error(e); slotsEl.textContent='Ошибка загрузки слотов'; }
}
dateEl.addEventListener('change', loadSlots);
salonEl.addEventListener('change', ()=>{ staffEl.value=''; loadSlots(); });
staffEl.addEventListener('change', loadSlots);
loadSlots();
</script>
</body></html>
