<?php
declare(strict_types=1);

/* ---------- svc (код услуги) из URL ---------- */
function pick_svc_from_get(): string {
  $list = [];

  if (isset($_GET['svc'])) {
    $v = $_GET['svc'];
    if (is_array($v)) {
      foreach ($v as $x) { $list = array_merge($list, explode(',', (string)$x)); }
    } else {
      $list = array_merge($list, explode(',', (string)$v));
    }
  }
  if (!empty($_GET['services'])) {
    $list = array_merge($list, explode(',', (string)$_GET['services']));
  }

  $out = [];
  foreach ($list as $s) {
    $s = strtolower(trim((string)$s));
    if ($s !== '' && !in_array($s, $out, true)) $out[] = $s;
  }
  return $out[0] ?? '';
}

$svc = pick_svc_from_get();
if ($svc === '') { http_response_code(400); echo 'svc required'; exit; }

/* ---------- язык интерфейса ---------- */
$allowLang = ['ru','et','en'];
$lang = isset($_GET['lang']) ? (string)$_GET['lang'] : 'ru';
if (!in_array($lang, $allowLang, true)) $lang = 'ru';

$localeMap = ['ru'=>'ru-RU', 'et'=>'et-EE', 'en'=>'en-GB'];
$locale = $localeMap[$lang] ?? 'ru-RU';
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($lang, ENT_QUOTES); ?>">
<head>
  <meta charset="utf-8">
  <title>Выбрать время</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/booking/style.css">
  <style>
  /* календарь */
  .kal{width:100%;border:1px solid var(--line);border-radius:16px;background:#fff;padding:0;box-shadow:0 6px 24px rgba(0,0,0,.06)}
  .kal .mon{display:flex;align-items:center;justify-content:space-between;padding:12px 16px 4px}
  .kal .mon strong{font-size:18px;font-weight:600;text-transform:lowercase}
  .kal .nav{display:flex;gap:8px}
  .kal .nav button{width:36px;height:36px;border:0;border-radius:999px;cursor:pointer;background:#f3f4f6;color:#111827;font-size:18px;line-height:36px;display:inline-flex;align-items:center;justify-content:center}
  .kal .nav button[disabled]{opacity:.35;pointer-events:none}
  .kal .grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px;padding:8px 16px 16px}
  .kal .dow{text-transform:uppercase;font-size:13px;color:#ec4899;text-align:center;padding:6px 0}
  .kal .d{position:relative;width:100%;aspect-ratio:1/1;border-radius:999px;display:flex;align-items:center;justify-content:center;color:#9aa0a6;background:transparent;user-select:none;cursor:pointer}
  .kal .d:hover{background:#f3f4f6;color:#111827}
  .kal .d.muted{opacity:.45}
  .kal .d.blocked{opacity:.30;pointer-events:none}
  .kal .d.today{outline:1px dashed #e5e7eb;outline-offset:-3px}
  .kal .d.sel{background:#86efac;color:#065f46;font-weight:700}

  /* слоты */
  .slotgrid{display:flex;flex-wrap:wrap;gap:10px}
  .slot{border:0;border-radius:999px;padding:10px 14px;background:#fce7f3;color:#9d174d;cursor:pointer}
  .slot.sel{background:#ec4899;color:#fff}

  /* жёлтая плашка при «Салон: Все» */
  .warn{display:none;margin-top:12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:10px 12px;color:#9a3412;font-size:14px}
  </style>
</head>
<body>
<div class="wrap">
  <h1 class="h1" id="pageTitle">Выбрать время</h1>

  <div class="row">
    <!-- Левая колонка -->
    <div class="card pad">
      <div class="controls">
        <div class="ctrl">
          <label>Дата</label>
          <input id="dateInput" type="date" inputmode="none" autocomplete="off">
        </div>
        <div class="ctrl">
          <label>Салон</label>
          <select id="salonSel"><option value=""><?php echo ($lang==='et'?'Kõik':($lang==='en'?'All':'Все')); ?></option></select>
        </div>
        <div class="ctrl" id="staffCtrl">
          <label><?php echo ($lang==='et'?'Töötaja':($lang==='en'?'Staff':'Сотрудник')); ?></label>
          <select id="staffSel" disabled>
            <option value=""><?php echo ($lang==='et'?'Vali salong':($lang==='en'?'Choose a salon':'Сначала выберите салон')); ?></option>
          </select>
        </div>
        <div class="help">
          <?php
          echo ($lang==='et'
             ? 'Töötajate nimekiri filtreeritakse salongi järgi. Vabad ajad arvestavad broneeringuid ja puhkusi.'
             : ($lang==='en'
                ? 'Staff list is filtered by selected salon. Free slots respect existing bookings and vacations.'
                : 'Список сотрудников фильтруется выбранным салоном. Свободные слоты учитывают занятые записи и отпуска.'));
          ?>
        </div>
      </div>

      <div class="kal" style="margin-top:12px">
        <div class="mon">
          <div class="nav"><button id="prevMon" aria-label="<?php echo ($lang==='et'?'Eelmine kuu':($lang==='en'?'Previous month':'Предыдущий месяц')); ?>">‹</button></div>
          <strong id="monTitle"></strong>
          <div class="nav"><button id="nextMon" aria-label="<?php echo ($lang==='et'?'Järgmine kuu':($lang==='en'?'Next month':'Следующий месяц')); ?>">›</button></div>
        </div>
        <div class="grid" id="dow"></div>
        <div class="grid" id="days"></div>
      </div>
    </div>

    <!-- Правая колонка -->
    <div class="card">
      <div class="slots">
        <div class="title" id="svcInfo"><?php echo ($lang==='et'?'Laadimine…':($lang==='en'?'Loading…':'Загрузка…')); ?></div>
        <div id="slotWrap">
          <div class="slotgrid" id="slots"></div>

          <!-- Предупреждение, если выбран «Салон: Все» -->
          <div id="anySalonWarn" class="warn">
            <?php
            echo ($lang==='et'
              ? 'Valisite „Kõik“ salongid. Kontrollige palun kinnitusetapil salongi nime ja aadressi.'
              : ($lang==='en'
                ? 'You chose “All” salons. Please double-check the salon name and address on the confirmation step.'
                : 'Вы выбрали «Все» салоны. Пожалуйста, проверьте адрес салона на шаге подтверждения.'));
            ?>
          </div>
        </div>
      </div>
      <div class="sidebar">
        <div class="badge" id="chooseBadge"><?php echo ($lang==='et'?'Vali aeg':( $lang==='en'?'Choose a time':'Выберите время')); ?></div>
        <div class="details" id="details"></div>
        <div class="footer">
          <button class="btn" id="nextBtn" disabled><?php echo ($lang==='et'?'Edasi':($lang==='en'?'Next':'Дальше')); ?></button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
/* ---------- конфиг с PHP ---------- */
const SVC    = <?php echo json_encode($svc, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?>;
const LANG   = <?php echo json_encode($lang); ?>;
const LOCALE = <?php echo json_encode($locale); ?>;

/* ---------- параметры из квиза (URL) ---------- */
const QP      = new URLSearchParams(location.search);
const SUM_MIN = QP.get('sum_min')  || QP.get('duration_min') || QP.get('dur_min') || '';
const SUM_EUR = QP.get('sum_eur')  || QP.get('price')        || '';

/* ---------- утилиты ---------- */
const api = (p) => fetch('/booking/api.php?' + new URLSearchParams(p)).then(r=>r.json());
const qS  = (sel) => document.querySelector(sel);

const slotsEl   = qS('#slots');
const badgeEl   = qS('#chooseBadge');
const detailsEl = qS('#details');
const nextBtn   = qS('#nextBtn');
const salonSel  = qS('#salonSel');
const staffSel  = qS('#staffSel');
const dateInput = qS('#dateInput');
const pageTitle = qS('#pageTitle');
const svcInfo   = qS('#svcInfo');
const anySalonWarn = qS('#anySalonWarn');

let meta = null;
let selected = {date:null, time:null};
let viewMonthISO = null, minDateISO = null, minMonthISO = null;

/* helpers */
const z = (n) => String(n).padStart(2,'0');
const fmtDateISO = (d) => `${d.getFullYear()}-${z(d.getMonth()+1)}-${z(d.getDate())}`;
function monthStartISO(dateStr){ const d=new Date(dateStr+'T00:00:00'); d.setDate(1); return fmtDateISO(d); }
function shiftMonthISO(ymd, delta){ const d=new Date(ymd+'T00:00:00'); d.setDate(1); d.setMonth(d.getMonth()+delta); return fmtDateISO(d); }
function todayISO(){ const now=new Date(); const d=new Date(now.getFullYear(),now.getMonth(),now.getDate()); return fmtDateISO(d); }
function safeISOorToday(s){ return (/^\d{4}-\d{2}-\d{2}$/.test(s||'')) ? s : todayISO(); }

/* длительность -> текст */
function fmtDuration(mins, lang='ru') {
  mins = parseInt(mins||0,10);
  const h = Math.floor(mins/60), m = mins%60;
  if (lang==='en') { if (h && m) return `${h} h ${m} min`; if (h) return `${h} h`; return `${m} min`; }
  if (lang==='et') { if (h && m) return `${h} t ${m} min`; if (h) return `${h} t`; return `${m} min`; }
  if (h && m) return `${h} ч ${m} мин`; if (h) return `${h} ч`; return `${m} мин`;
}

/* правый сайдбар: сводка */
function updateDetails(){
  const sName = salonSel.value
    ? (salonSel.options[salonSel.selectedIndex]?.text || '')
    : (LANG==='et'?'Kõik':(LANG==='en'?'All':'Все'));

  const stName = staffSel.value
    ? (staffSel.options[staffSel.selectedIndex]?.text || '')
    : (LANG==='et'?'Suvaline':(LANG==='en'?'Any':'Любой'));

  const dtTxt  = (LANG==='et'?'Kuupäev':(LANG==='en'?'Date':'Дата'));
  const tmTxt  = (LANG==='et'?'Aeg':(LANG==='en'?'Time':'Время'));
  const slnTxt = (LANG==='et'?'Salon':(LANG==='en'?'Salon':'Салон'));
  const stfTxt = (LANG==='et'?'Töötaja':(LANG==='en'?'Staff':'Сотрудник'));
  detailsEl.textContent = `${dtTxt}: ${selected.date||'—'} • ${tmTxt}: ${selected.time||'—'} • ${slnTxt}: ${sName} • ${stfTxt}: ${stName}`;
}

/* заполняем селект сотрудников по салону */
function fillStaffBySalon(){
  if (!meta) return;

  const sid = String(salonSel.value || '');
  staffSel.innerHTML = '';

  if (!sid) {
    staffSel.disabled = true;
    const o = document.createElement('option');
    o.value = '';
    o.textContent = (LANG==='et'?'Vali salong':(LANG==='en'?'Choose a salon':'Сначала выберите салон'));
    staffSel.appendChild(o);
    return;
  }

  // разрешённые сотрудники (по связке staff_salons)
  const allowedIds = new Set(
    (meta.staff_salons || []).filter(x => String(x.salon_id)===sid).map(x => String(x.staff_id))
  );

  // если связок нет — берём просто всех активных
  const list = (meta.staff || []).filter(s => (allowedIds.size ? allowedIds.has(String(s.id)) : true));

  // "Любой"
  {
    const o=document.createElement('option');
    o.value=''; o.textContent=(LANG==='et'?'Suvaline':(LANG==='en'?'Any':'Любой'));
    staffSel.appendChild(o);
  }

  list.forEach(s=>{
    const o=document.createElement('option');
    o.value=String(s.id);
    o.textContent=s.name;
    staffSel.appendChild(o);
  });

  staffSel.disabled = false;
}

/* метаданные */
async function loadMeta(){
  const res = await api({action:'meta', svc:SVC, lang:LANG});
  if (!res || !res.ok) {
    svcInfo.textContent = (res && res.error) ? res.error : (LANG==='et'?'Viga':(LANG==='en'?'Error':'Ошибка'));
    return;
  }
  meta = res;

  const title = (res.service && res.service.title) || (LANG==='et'?'Teenus':(LANG==='en'?'Service':'Услуга'));
  const dur   = Number(SUM_MIN || (res.service && res.service.duration_min) || 0);
  const price = Number(SUM_EUR || (res.service && res.service.price_eur) || 0);
  const durTxt   = (LANG==='et'?'Kestus':(LANG==='en'?'Duration':'Длительность'));
  const priceTxt = (LANG==='et'?'Hind':(LANG==='en'?'Price':'Цена'));
  pageTitle.textContent = `${title} — ${LANG==='et'?'vali aeg':(LANG==='en'?'choose a time':'выбрать время')}`;
  svcInfo.textContent   = `${title} • ${durTxt}: ${fmtDuration(dur, LANG)} • ${priceTxt}: ${price} €`;

  // салон-опции
  (res.salons || []).forEach(s=>{
    const o=document.createElement('option'); o.value=s.id; o.textContent=s.name; salonSel.appendChild(o);
  });

  selected.date = safeISOorToday(res.min_date || '');
  dateInput.value = selected.date;
  minDateISO  = safeISOorToday(res.min_date || '');
  minMonthISO = monthStartISO(minDateISO);
  viewMonthISO= monthStartISO(selected.date);

  // заполним сотрудников (пока салон не выбран — селект заблокирован)
  fillStaffBySalon();

  buildCalendar(viewMonthISO);
  loadSlots();
}

/* календарь */
function setMonthTitle(d){ const opts={month:'long',year:'numeric'}; qS('#monTitle').textContent=d.toLocaleDateString(LOCALE,opts); }

function buildCalendar(monthISO){
  const root=new Date(monthISO+'T00:00:00'); const y=root.getFullYear(), m=root.getMonth();
  const first=new Date(y,m,1), last=new Date(y,m+1,0);
  const start=new Date(first); start.setDate(first.getDate()-((first.getDay()+6)%7));
  const end=new Date(last); end.setDate(last.getDate()+(7-((last.getDay()+6)%7)-1));

  setMonthTitle(root);

  const dow=(LANG==='et'?['E','T','K','N','R','L','P']:(LANG==='en'?['Mon','Tue','Wed','Thu','Fri','Sat','Sun']:['пн','вт','ср','чт','пт','сб','вс']));
  const dowEl=qS('#dow'); dowEl.innerHTML='';
  dow.forEach(s=>{ const el=document.createElement('div'); el.textContent=s; el.className='dow'; dowEl.appendChild(el); });

  const cont=qS('#days'); cont.innerHTML='';
  for(let d=new Date(start); d<=end; d.setDate(d.getDate()+1)){
    const day=new Date(d); const iso=fmtDateISO(day); const el=document.createElement('div');
    el.className='d'+(day.getMonth()!==m?' muted':'');
    if(iso<minDateISO) el.classList.add('blocked');

    const today=todayISO();
    if(iso===today) el.classList.add('today');
    if(iso===selected.date) el.classList.add('sel');

    el.textContent=day.getDate();
    el.dataset.iso=iso;

    if(!el.classList.contains('blocked')){
      el.onclick=()=>{ selected.date=iso; dateInput.value=iso; markSelectedDate(); loadSlots(); updateDetails(); };
    }
    cont.appendChild(el);
  }

  const prevBtn=document.querySelector('#prevMon');
  const nextBtn=document.querySelector('#nextMon');
  const thisMonthISO=monthStartISO(monthISO);
  prevBtn.disabled=!(thisMonthISO>minMonthISO);
  nextBtn.disabled=false;

  markSelectedDate();
}

function markSelectedDate(){
  document.querySelectorAll('.kal .d').forEach(el=>{
    el.classList.toggle('sel', el.dataset.iso===selected.date);
  });
}

/* загрузка слотов */
let warnShownOnce=false;

async function loadSlots(){
  slotsEl.innerHTML='';
  badgeEl.textContent=(LANG==='et'?'Laadimine…':(LANG==='en'?'Loading…':'Загрузка…'));
  nextBtn.disabled=true; selected.time=null;
  anySalonWarn.style.display='none';

  const res = await api({
    action:'slots',
    date:selected.date,
    svc:SVC,
    lang:LANG,
    salon_id: salonSel.value,
    staff_id: staffSel.value, // если выбрали конкретного мастера — фильтруем
    sum_min:  SUM_MIN
  });

  if (!res || !res.ok) {
    badgeEl.textContent=(res && res.error) ? res.error : (LANG==='et'?'Viga':(LANG==='en'?'Error':'Ошибка'));
    return;
  }

  const list = Array.isArray(res.slots) ? res.slots : [];
  if (!list.length){
    badgeEl.textContent=(LANG==='et'?'Vabasid aegu pole':(LANG==='en'?'No free slots':'Нет свободных слотов'));
    updateDetails();
    return;
  }

  badgeEl.textContent=(LANG==='et'?'Vali aeg':(LANG==='en'?'Choose a time':'Выберите время'));
  list.forEach(t=>{
    const b=document.createElement('button');
    b.type='button'; b.className='slot'; b.textContent=t;
    b.onclick=()=>{
      document.querySelectorAll('.slot').forEach(x=>x.classList.remove('sel'));
      b.classList.add('sel');
      selected.time=t;
      nextBtn.disabled=false;
      updateDetails();

      // Показать предупреждение один раз, если «Салон: Все»
      if (!salonSel.value && !warnShownOnce) {
        anySalonWarn.style.display='block';
        warnShownOnce=true;
      }
    };
    slotsEl.appendChild(b);
  });

  updateDetails();
}

/* события */
document.addEventListener('DOMContentLoaded', ()=>{
  document.querySelector('#prevMon').onclick = ()=>{
    const cand=shiftMonthISO(viewMonthISO,-1);
    if(cand<minMonthISO) return;
    viewMonthISO=cand;
    buildCalendar(viewMonthISO);
  };
  document.querySelector('#nextMon').onclick = ()=>{
    viewMonthISO=shiftMonthISO(viewMonthISO,+1);
    buildCalendar(viewMonthISO);
  };

  dateInput.onchange = ()=>{
    const v=dateInput.value;
    selected.date=/^\d{4}-\d{2}-\d{2}$/.test(v)?v:todayISO();
    if(selected.date<minDateISO) selected.date=minDateISO;
    dateInput.value=selected.date;
    viewMonthISO=monthStartISO(selected.date);
    buildCalendar(viewMonthISO);
    warnShownOnce=false;
    anySalonWarn.style.display='none';
    loadSlots();
  };

  salonSel.onchange = ()=>{
    fillStaffBySalon();
    warnShownOnce=false;
    anySalonWarn.style.display='none';
    loadSlots();
    updateDetails();
  };

  staffSel.onchange = ()=>{
    loadSlots();
    updateDetails();
  };

  nextBtn.onclick = () => {
  if (!selected.time) return;

  // то, что и раньше отправляли
  const params = new URLSearchParams({
    svc:     SVC,
    lang:    LANG,
    date:    selected.date,
    time:    selected.time,
    salon_id: salonSel.value,
    staff_id: staffSel.value
  });

  if (SUM_MIN) params.set('sum_min', SUM_MIN);
  if (SUM_EUR) {
    params.set('sum_eur',  SUM_EUR);
    params.set('price_eur', SUM_EUR);    // пригодится, confirm это понимает
  }

  // НОВОЕ: протащить всё, что пришло в календарь из квиза
  // (мы уже создали выше QP = new URLSearchParams(location.search))
  // 1) meta_b64 / meta
  const metaB64 = QP.get('meta_b64');
  if (metaB64) params.set('meta_b64', metaB64);

  const metaRaw = QP.get('meta');
  if (metaRaw) params.set('meta', metaRaw);

  // 2) все quiz_* как есть
  for (const [k, v] of QP.entries()) {
    if (k.startsWith('quiz_') && v != null && v !== '') {
      params.set(k, v);
    }
  }

  // 3) если в адресе календаря был slug — тоже прокинем
  const slug = QP.get('slug');
  if (slug) params.set('slug', slug);

  location.href = '/booking/confirm.php?' + params.toString();
};


  loadMeta();
});
</script>
</body>
</html>
