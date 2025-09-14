<?php
// index.php — главная maniküür.ee
// Никакой внешней зависимости; "живые" слоты пытаемся взять с /booking/api/slots-today
// При желании поменяй ссылки BOOK_URL/QUIZ_URL/BOOK_PHONE/WHATSAPP/TELEGRAM/EMAIL ниже.

const BOOK_URL   = '/quiz.php?lang=et';                // онлайн-запись
const QUIZ_URL   = '/quiz.php?lang=et';                // подбор по квизу
const BOOK_PHONE = '+37259177779';
const WHATSAPP   = 'https://wa.me/37259177779';
const TELEGRAM   = 'https://t.me/share/url?url=https%3A%2F%2Fmaniküü r.ee&text=Tere!';
const EMAIL      = 'mailto:info@maniküü r.ee';

$addresses = [
  // title ET / EN / RU для подписи можно на клиенте переключать
  ['title'=>'Kassi 6',           'maps'=>'https://maps.apple.com/?q=Kassi+6,+Tallinn'],
  ['title'=>'Narva maantee 15',  'maps'=>'https://maps.apple.com/?q=Narva+maantee+15,+Tallinn'],
  ['title'=>'Priisle tee 4/1',   'maps'=>'https://maps.apple.com/?q=Priisle+tee+4%2F1,+Tallinn'],
];
?>
<!doctype html>
<html lang="et">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>maniküür.ee — parim maniküür Tallinnas</title>
  <meta name="description" content="Maniküür ja pediküür Tallinnas. Steriilsus (autoklaav), 500+ disaini, garantii 7 päeva. Online-broneering 1 minutiga.">
  <style>
    :root{
      --bg:#0b1020;               /* фон страницы */
      --bg2:#121833;              /* второй фон/карточки */
      --text:#EAF0FF;             /* основной текст */
      --muted:#9fb2ff;            /* вторичный */
      --accent:#7C3AED;           /* фиолетовая */
      --accent2:#6EE7FF;          /* бирюза */
      --ring:rgba(255,255,255,.12);
      --ok:#22C55E;
      --danger:#EF4444;
      --chip:#1b2038;
      --shadow:0 12px 40px rgba(0,0,0,.35);
      --radius:16px;
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;background:radial-gradient(80% 100% at 20% 0%,#1a1f3e 0%,#0b1020 60%) fixed; color:var(--text); font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
    a{color:inherit}
    .wrap{max-width:1200px;margin:0 auto;padding:20px}
    .topbar{display:flex;gap:14px;align-items:center;justify-content:space-between;padding:8px 0}
    .brand{display:flex;gap:10px;align-items:center;font-weight:800}
    .brand .dot{width:28px;height:28px;border-radius:10px;background:linear-gradient(135deg,var(--accent),#a78bfa)}
    .lang{display:flex;gap:6px}
    .chip{border:1px solid var(--ring);background:#0e1430b3;padding:8px 12px;border-radius:999px}
    .chip.ghost{background:transparent}
    .cta{background:linear-gradient(90deg,#8b5cf6,#06b6d4); color:#fff;border:0;border-radius:999px;padding:10px 16px;font-weight:700;box-shadow:var(--shadow);text-decoration:none}
    .cta:hover{filter:brightness(1.08)}
    .btn{background:#0e1430;border:1px solid var(--ring);border-radius:999px;padding:10px 14px;text-decoration:none}
    .btn:hover{border-color:#3b82f6}
    .grid{display:grid;gap:22px}
    .hero{display:grid;grid-template-columns:1.1fr .9fr;gap:28px;align-items:center;margin-top:18px}
    .hero h1{font-size:52px;line-height:1.05;margin:10px 0 16px;font-weight:900;letter-spacing:.2px}
    .hero p{color:#cbd5ff;max-width:52ch}
    .card{background:linear-gradient(180deg,#12183a 0%, #0e1430 100%);border:1px solid var(--ring);border-radius:var(--radius);box-shadow:var(--shadow)}
    .card.pad{padding:16px}
    .hero-badges{display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:12px;margin-top:18px}
    .hb{border:1px solid var(--ring);background:var(--chip);border-radius:14px;padding:12px 14px}
    .hb b{display:block;font-size:14px;margin-bottom:2px;color:#cbd5ff}
    .hb span{opacity:.9}

    .slotbar{display:flex;gap:10px;align-items:center;color:#cbd5ff;font-size:14px;margin-bottom:10px}
    .slots{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
    .slot{border:1px solid var(--ring);background:#0d1330;border-radius:12px;padding:10px;display:flex;align-items:center;gap:10px}
    .slot .badge{width:10px;height:10px;border-radius:999px;background:var(--ok);box-shadow:0 0 0 4px rgba(34,197,94,.15)}
    .slot.busy .badge{background:var(--danger);box-shadow:0 0 0 4px rgba(239,68,68,.10)}
    .slot small{color:#b7c4ff}
    .addr{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .addr a{border:1px solid var(--ring);background:#0d1330;border-radius:999px;padding:8px 12px;color:#cbd5ff;text-decoration:none}
    .addr a:hover{border-color:#7dd3fc}

    /* карусель мастеров */
    .masters{margin-top:50px}
    .mh{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
    .carousel{position:relative;overflow:hidden}
    .track{display:flex;gap:14px;transition:transform .4s ease}
    .master{min-width:220px; background:linear-gradient(180deg,#151a40,#0e1430); border:1px solid var(--ring); border-radius:16px; padding:12px}
    .master .ph{height:160px;border-radius:12px;background:linear-gradient(135deg,#334155,#0f172a)}
    .master h4{margin:10px 0 6px}
    .navbtn{border:1px solid var(--ring);background:#0d1330;border-radius:10px;padding:8px 10px;cursor:pointer}
    .navbtn:disabled{opacity:.5;cursor:not-allowed}

    /* как добраться */
    .travel{margin-top:44px}
    .list{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
    .b{padding:14px;border:1px solid var(--ring);background:#0d1330;border-radius:16px}
    .b h5{margin:0 0 6px}
    .b p{margin:0;color:#cbd5ff}

    /* баннер акции */
    .promo{margin:44px 0;padding:18px;border-radius:18px;border:1px solid var(--ring);background:linear-gradient(90deg,#0ea5e9 0,#8b5cf6 100%);color:#fff;display:flex;align-items:center;justify-content:space-between;gap:14px}
    .promo small{opacity:.95}

    /* футер */
    footer{margin:40px 0 10px;color:#a3b0ff;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
    footer a{color:#a3b0ff;text-decoration:none}
    footer a:hover{color:#fff}

    /* плавающий мессенджер */
    .fab{position:fixed;right:18px;bottom:18px;display:flex;flex-direction:column;gap:10px;z-index:99}
    .fab a{display:flex;align-items:center;gap:8px;border-radius:999px;padding:12px 14px;background:#0d1330;border:1px solid var(--ring);text-decoration:none;color:#eaf0ff;box-shadow:var(--shadow)}
    .fab a.wh{border-color:#22c55e4d}
    .fab a.tg{border-color:#60a5fa4d}
    .fab svg{width:18px;height:18px}

    /* мобильная адаптация */
    @media (max-width:980px){
      .hero{grid-template-columns:1fr}
      .slots{grid-template-columns:1fr 1fr}
      .list{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
  <div class="wrap">

    <!-- TOPBAR -->
    <div class="topbar">
      <div class="brand">
        <div class="dot"></div>
        <div style="font-weight:900">maniküür.ee</div>
      </div>
      <nav style="display:flex;gap:8px;align-items:center">
        <a class="chip ghost" href="#services">Teenused</a>
        <a class="chip ghost" href="#masters">Meistrid</a>
        <a class="chip ghost" href="#prices">Hinnad</a>
        <a class="chip ghost" href="#reviews">Arvustused</a>
        <div class="lang">
          <span class="chip">ET</span>
          <a class="chip ghost" href="?lang=en">EN</a>
          <a class="chip ghost" href="?lang=ua">UA</a>
          <a class="chip ghost" href="?lang=ru">RU</a>
        </div>
        <a class="cta" href="<?=BOOK_URL?>">Broneeri online</a>
      </nav>
    </div>

    <!-- HERO -->
    <section class="hero">
      <div>
        <div class="chip" style="display:inline-block">⭐ TOP Tallinn • 4.97/5</div>
        <h1>Ideaalne maniküür — esimesest puudutusest.</h1>
        <p>Maniküür, pediküür, modelleerimine, disain. Steriilsus — <b>autoklaav</b>. 500+ disaini ja <b>7 päeva</b> garantii. Online-broneering 1 minutiga.</p>
        <div style="display:flex;gap:10px;margin:14px 0 6px">
          <a class="cta" href="<?=BOOK_URL?>">Broneeri online</a>
          <a class="btn" href="tel:<?=preg_replace('~\s+~','',BOOK_PHONE)?>">Helista</a>
          <a class="btn" href="<?=QUIZ_URL?>">Vali kvizi abil</a>
        </div>
        <div class="hero-badges">
          <div class="hb"><b>8+ aastat</b><span>kogemus</span></div>
          <div class="hb"><b>Steriilsus</b><span>Autoklaav</span></div>
          <div class="hb"><b>Disainid</b><span>500+</span></div>
          <div class="hb"><b>Garantii</b><span>7 päeva</span></div>
        </div>
      </div>

      <div class="card pad">
        <div class="slotbar">⏰ Täna vabad ajad (uuenevad reaalajas)</div>
        <div id="slots" class="slots">
          <!-- динамически -->
        </div>
        <div style="margin-top:10px;color:#b7c4ff;font-size:14px">🕘 Avatud iga päev 09:00–21:00</div>
        <div class="addr">
          <?php foreach($addresses as $a): ?>
            <a href="<?=htmlspecialchars($a['maps'])?>" target="_blank">📍 <?=htmlspecialchars($a['title'])?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- PROMO -->
    <div class="promo">
      <div>
        <div style="font-weight:800;font-size:20px">🎁 Sügispakkumine: -10% uutele klientidele</div>
        <small>Kehtib ainult esimesel visiidil. Kasuta koodi <b>HELLO10</b> online-broneeringus.</small>
      </div>
      <a class="cta" href="<?=BOOK_URL?>">Kasuta soodustust</a>
    </div>

    <!-- MASTERS -->
    <section class="masters" id="masters">
      <div class="mh">
        <h2 style="margin:0">Meie meistrid</h2>
        <div style="display:flex;gap:8px">
          <button class="navbtn" id="prevBtn">◀</button>
          <button class="navbtn" id="nextBtn">▶</button>
        </div>
      </div>
      <div class="carousel card pad">
        <div id="track" class="track">
          <!-- карточки примеры; подставишь реальные фото -->
          <?php
          $demo = [
            ['name'=>'Tatyana','spec'=>'Maniküür • Disain','rating'=>'4.97','exp'=>'8 a'],
            ['name'=>'Aleksandr','spec'=>'Pediküür • Aparaat','rating'=>'4.93','exp'=>'6 a'],
            ['name'=>'Marina','spec'=>'Geel • Prantsuse','rating'=>'4.95','exp'=>'7 a'],
            ['name'=>'Elena','spec'=>'Laste maniküür','rating'=>'4.90','exp'=>'5 a'],
            ['name'=>'Olga','spec'=>'Kombineeritud','rating'=>'4.96','exp'=>'9 a'],
          ];
          foreach($demo as $m): ?>
            <div class="master">
              <div class="ph"></div>
              <h4><?=htmlspecialchars($m['name'])?> · ⭐ <?=htmlspecialchars($m['rating'])?></h4>
              <div style="color:#b7c4ff"><?=htmlspecialchars($m['spec'])?></div>
              <div style="margin-top:6px" class="addr">
                <a href="<?=BOOK_URL?>">Broneeri</a>
                <a href="<?=QUIZ_URL?>">Kviz</a>
              </div>
              <div style="margin-top:6px;color:#9fb2ff;font-size:14px">Kogemus: <?=$m['exp']?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- HOW TO GET / PARKING -->
    <section class="travel" id="travel">
      <h2 style="margin:0 0 10px">Kuidas kohale jõuda ja parkida</h2>
      <div class="list">
        <div class="b">
          <h5>🚗 Parkimine</h5>
          <p>Kassi 6 — tänavaparkla (tasuline tööpäeviti 08–19). Narva mnt 15 — EuroPark maa-alune. Priisle tee 4/1 — tasuta parkla 2h kettaga.</p>
        </div>
        <div class="b">
          <h5>🚌 Transport</h5>
          <p>Tramm 1/3 <b>Kiviranna</b> jaam (Narva mnt). Bussid 7, 9, 31 — peatused jalutuskäigu kaugusel.</p>
        </div>
        <div class="b">
          <h5>♿ Mugavus</h5>
          <p>Lift, kohv/tee, Wi-Fi. Steriilsus: instrumentide täistsükkel autoklaavis.</p>
        </div>
      </div>
    </section>

    <!-- FOOTER -->
    <footer>
      <div>© <?=date('Y')?> maniküür.ee • Avatud iga päev 09:00–21:00</div>
      <div style="display:flex;gap:12px;align-items:center">
        <a href="tel:<?=preg_replace('~\s+~','',BOOK_PHONE)?>">📞 <?=BOOK_PHONE?></a>
        <a href="<?=EMAIL?>">✉️ info@maniküür.ee</a>
        <a href="<?=WHATSAPP?>">WhatsApp</a>
        <a href="<?=TELEGRAM?>">Telegram</a>
      </div>
    </footer>
  </div>

  <!-- Floating messengers -->
  <div class="fab">
    <a class="wh" href="<?=WHATSAPP?>" target="_blank" rel="noopener">
      <!-- WhatsApp icon -->
      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.52 3.48A11.94 11.94 0 0 0 12 0C5.37 0 0 5.37 0 12c0 2.11.55 4.1 1.6 5.88L0 24l6.29-1.64A11.93 11.93 0 0 0 12 24c6.63 0 12-5.37 12-12 0-3.2-1.25-6.21-3.48-8.52ZM12 21.8c-1.87 0-3.7-.5-5.3-1.46l-.38-.22-3.73.97.99-3.64-.24-.38A9.79 9.79 0 0 1 2.2 12C2.2 6.58 6.58 2.2 12 2.2S21.8 6.58 21.8 12 17.42 21.8 12 21.8Zm5.25-6.93c-.29-.14-1.7-.84-1.96-.93-.26-.1-.45-.14-.65.14-.19.27-.74.93-.9 1.12-.17.19-.33.22-.62.08-.29-.14-1.23-.45-2.35-1.43a8.8 8.8 0 0 1-1.63-2.01c-.17-.29 0-.45.13-.59.13-.13.29-.33.43-.5.14-.17.19-.29.29-.48.1-.19.05-.36-.02-.5-.07-.14-.65-1.56-.9-2.14-.24-.58-.48-.5-.65-.5h-.56c-.19 0-.5.07-.76.36-.26.29-1 1-1 2.43 0 1.43 1.03 2.8 1.18 2.99.14.19 2.02 3.09 4.89 4.33.68.29 1.2.46 1.6.58.67.21 1.28.18 1.77.11.54-.08 1.7-.69 1.94-1.35.24-.66.24-1.23.17-1.35-.07-.12-.26-.2-.55-.34Z"/></svg>
      WhatsApp
    </a>
    <a class="tg" href="<?=TELEGRAM?>" target="_blank" rel="noopener">
      <!-- Telegram icon -->
      <svg viewBox="0 0 24 24" fill="currentColor"><path d="M9.03 15.6 8.86 20c.38 0 .54-.16.74-.36l1.78-1.72 3.7 2.7c.68.38 1.16.18 1.34-.62l2.43-11.43c.24-.98-.36-1.36-1.02-1.12L3.3 10.1c-.95.38-.94.92-.16 1.16l4.46 1.38 10.38-6.56-8.94 8.5Z"/></svg>
      Telegram
    </a>
  </div>

  <script>
  // Карусель мастеров
  (function(){
    const track = document.getElementById('track');
    if (!track) return;
    let index = 0;
    const cardW = 234; // ширина карточки + gap (чуть с запасом)
    const prev = document.getElementById('prevBtn');
    const next = document.getElementById('nextBtn');
    function update(){
      track.style.transform = `translateX(${-index*cardW}px)`;
      prev.disabled = index<=0;
      next.disabled = (index >= track.children.length-3);
    }
    prev?.addEventListener('click', ()=>{ index=Math.max(0,index-1); update(); });
    next?.addEventListener('click', ()=>{ index=Math.min(track.children.length, index+1); update(); });
    update();
  })();

  // Живые слоты: реальные окна под выбранную услугу + фильтр "прошедших" слотов
(function(){
  const box = document.getElementById('slots');

  function pill(time, free, staff, salon){
    return `<div class="slot ${free ? '' : 'busy'}">
      <div class="badge"></div>
      <div>
        <div style="font-weight:700">${time}${staff ? ` · ${staff}` : ''}</div>
        <small>${free ? 'Vaba' : 'Hõivatud'}${salon ? ` • ${salon}` : ''}</small>
      </div>
    </div>`;
  }

  // утилита: спарсить дату/время из ответа API и понять, будущее ли это окно
  function toDate(x){
    // Поддерживаем 3 варианта:
    // 1) x.datetime (ISO-8601)
    // 2) x.date + x.time
    // 3) только x.time → считаем, что это сегодня
    if (x.datetime) {
      const d = new Date(x.datetime);
      if (!isNaN(d)) return d;
    }
    if (x.date && x.time) {
      const d = new Date(`${x.date}T${x.time}`);
      if (!isNaN(d)) return d;
    }
    if (x.time) {
      const today = new Date();
      const [hh, mm] = String(x.time).split(':').map(Number);
      const d = new Date(today.getFullYear(), today.getMonth(), today.getDate(), hh||0, mm||0, 0, 0);
      return d;
    }
    return null;
  }

  // читаем параметры из URL (если клиент пришёл из квиза — они там уже есть)
  const qs = new URLSearchParams(location.search);
  const services = qs.get('services') || qs.get('svc') || '';
  const sumMin   = qs.get('sum_min') || '';
  const lang     = qs.get('lang') || 'et';

  const url = new URL('/booking/api/slots_today.php', location.origin);
  if (services) url.searchParams.set('services', services);
  if (sumMin)   url.searchParams.set('sum_min', sumMin);
  url.searchParams.set('lang', lang);

  fetch(url.toString(), { cache: 'no-store' })
    .then(r => r.json())
    .then(data => {
      const now = new Date();

      let items = Array.isArray(data?.items) ? data.items : [];

      // фильтруем прошлые окна (<= now)
      items = items
        .map(x => ({...x, _dt: toDate(x)}))
        .filter(x => x._dt && x._dt.getTime() > now.getTime());

      if (items.length) {
        // сортируем по времени, берём первые 6
        items.sort((a,b) => a._dt - b._dt);
        box.innerHTML = items.slice(0,6)
          .map(x => pill(
            x.time || (x._dt.toTimeString().slice(0,5)),
            x.free !== false,         // если поле free отсутствует — считаем, что свободно
            x.staff || '',
            x.salon || ''
          ))
          .join('');
      } else {
        box.innerHTML = `<div class="slot busy">
          <div class="badge"></div>
          <div><div style="font-weight:700">—</div><small>Hetkel pole vabu aegu</small></div>
        </div>`;
      }
    })
    .catch(() => {
      // короткая заглушка
      const fallback = ['15:30','17:00','18:45'];
      box.innerHTML = fallback.map(t => pill(t, true, '', '')).join('');
    });
})();
  </script>
</body>
</html>