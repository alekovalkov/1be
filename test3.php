<?php
// index.php — главная maniküür.ee (ET/EN/RU/UA) с выбором темы при первом визите

/* ====== CONFIG ====== */
const BOOK_URL   = '/quiz.php?lang=et';
const QUIZ_URL   = '/quiz.php?lang=et';
const BOOK_PHONE = '+37259177779';
const WHATSAPP   = 'https://wa.me/37259177779';
const TELEGRAM   = 'https://t.me/manikuuree';
const EMAIL      = 'mailto:info@manikuur.ee';

$addresses = [
  ['title'=>['et'=>'Kassi 6','en'=>'Kassi 6','ru'=>'Kassi 6','ua'=>'Kassi 6'], 'maps'=>'https://maps.apple.com/?q=Kassi+6,+Tallinn'],
  ['title'=>['et'=>'Narva maantee 15','en'=>'Narva mnt 15','ru'=>'Narva mnt 15','ua'=>'Narva mnt 15'], 'maps'=>'https://maps.apple.com/?q=Narva+maantee+15,+Tallinn'],
  ['title'=>['et'=>'Priisle tee 4/1','en'=>'Priisle tee 4/1','ru'=>'Priisle tee 4/1','ua'=>'Priisle tee 4/1'], 'maps'=>'https://maps.apple.com/?q=Priisle+tee+4%2F1,+Tallinn'],
];

/* ====== I18N ====== */
$I18N = [
  'et'=>[
    'title' => 'maniküür.ee — parim maniküür Tallinnas',
    'desc'  => 'Maniküür ja pediküür Tallinnas. Steriilsus: kõrgetemperatuuriline kuivkuum sterilisaator. 14 000+ tehtud tööd. Online-broneering 1 minutiga.',
    'nav_services'=>'Teenused','nav_masters'=>'Meistrid','nav_prices'=>'Hinnad','nav_reviews'=>'Arvustused',
    'book_online'=>'Broneeri online','call'=>'Helista','via_quiz'=>'Vali kvizi abil',
    'top_badge'=>'⭐ TOP Tallinn • 4.97/5',
    'hero_h1'=>'Ideaalne maniküür — esimesest puudutusest.',
    'hero_p'=>'Maniküür, pediküür, modelleerimine, disain. Steriilsus — <b>High Temperature Dry Heat Sterilizer</b>. <b>14 000+</b> tehtud tööd ja <b>7 päeva</b> garantii. Online-broneering 1 minutiga.',
    'hb_exp'=>'8+ aastat kogemus','hb_ster'=>'Steriilsus • Kuivkuum','hb_works'=>'Töid kokku • 14 000+','hb_warranty'=>'Garantii • 7 päeva',
    'slots_today'=>'⏰ Täna vabad ajad (uuenevad reaalajas)','open_hours'=>'🕘 Avatud iga päev 09:00–21:00',
    'promo_title'=>'🎁 Sügispakkumine: -10% uutele klientidele','promo_note'=>'Kehtib ainult esimesel visiidil. Kasuta koodi <b>HELLO10</b> online-broneeringus.','promo_cta'=>'Kasuta soodustust',
    'masters_h2'=>'Meie meistrid','book'=>'Broneeri','quiz'=>'Kviz',
    'travel_h2'=>'Kuidas kohale jõuda ja parkida','parking_h5'=>'🚗 Parkimine','transport_h5'=>'🚌 Transport','comfort_h5'=>'♿ Mugavus',
    'parking_p'=>'Kassi 6 — tänavaparkla (tasuline tööpäeviti 08–19). Narva mnt 15 — EuroPark maa-alune. Priisle tee 4/1 — tasuta parkla 2h kettaga.',
    'transport_p'=>'Tramm 1/3 <b>Kiviranna</b> jaam (Narva mnt). Bussid 7, 9, 31 — peatused jalutuskäigu kaugusel.',
    'comfort_p'=>'Lift, kohv/tee, Wi-Fi. Steriilsus: täistsükkel kuivkuum-sterilisaatoris.',
    'footer_copy'=>'© %s maniküür.ee • Avatud iga päev 09:00–21:00',
    'choose_theme_title'=>'Vali saidi teema','choose_theme_sub'=>'Saad alati muuta üleval paremal ⚙︎',
    'theme_light'=>'Hele teema','theme_dark'=>'Tume teema'
  ],
  'en'=>[
    'title'=>'maniküür.ee — best manicure in Tallinn',
    'desc'=>'Manicure & pedicure in Tallinn. Sterility: High Temperature Dry Heat Sterilizer. 14,000+ works done. Book online in 1 minute.',
    'nav_services'=>'Services','nav_masters'=>'Masters','nav_prices'=>'Prices','nav_reviews'=>'Reviews',
    'book_online'=>'Book online','call'=>'Call','via_quiz'=>'Choose with quiz',
    'top_badge'=>'⭐ TOP Tallinn • 4.97/5',
    'hero_h1'=>'Perfect manicure from the first touch.',
    'hero_p'=>'Manicure, pedicure, modelling, design. Sterility — <b>High Temperature Dry Heat Sterilizer</b>. <b>14,000+</b> works done and <b>7-day</b> warranty. Book online in 1 minute.',
    'hb_exp'=>'8+ years • experience','hb_ster'=>'Sterility • Dry heat','hb_works'=>'Works total • 14,000+','hb_warranty'=>'Warranty • 7 days',
    'slots_today'=>'⏰ Free slots today (live)','open_hours'=>'🕘 Open daily 09:00–21:00',
    'promo_title'=>'🎁 Autumn deal: -10% for new clients','promo_note'=>'Valid for the first visit only. Use code <b>HELLO10</b> when booking online.','promo_cta'=>'Get discount',
    'masters_h2'=>'Our masters','book'=>'Book','quiz'=>'Quiz',
    'travel_h2'=>'How to get & parking','parking_h5'=>'🚗 Parking','transport_h5'=>'🚌 Transport','comfort_h5'=>'♿ Comfort',
    'parking_p'=>'Kassi 6 — street parking (paid weekdays 08–19). Narva mnt 15 — EuroPark underground. Priisle tee 4/1 — free 2h with disc.',
    'transport_p'=>'Tram 1/3 <b>Kiviranna</b> stop (Narva mnt). Buses 7, 9, 31 — short walk.',
    'comfort_p'=>'Lift, coffee/tea, Wi-Fi. Sterility: full dry-heat cycle.',
    'footer_copy'=>'© %s maniküür.ee • Open daily 09:00–21:00',
    'choose_theme_title'=>'Choose a theme','choose_theme_sub'=>'You can change it anytime in the top-right ⚙︎',
    'theme_light'=>'Light theme','theme_dark'=>'Dark theme'
  ],
  'ru'=>[
    'title'=>'maniküür.ee — лучший маникюр в Таллине',
    'desc'=>'Маникюр и педикюр в Таллине. Стерильность: высокотемпературный сухожаровый стерилизатор. 14 000+ выполненных работ. Онлайн-запись за 1 минуту.',
    'nav_services'=>'Услуги','nav_masters'=>'Мастера','nav_prices'=>'Цены','nav_reviews'=>'Отзывы',
    'book_online'=>'Записаться онлайн','call'=>'Позвонить','via_quiz'=>'Подобрать по квизу',
    'top_badge'=>'⭐ ТОП Таллин • 4.97/5',
    'hero_h1'=>'Идеальный маникюр — с первого касания.',
    'hero_p'=>'Маникюр, педикюр, моделирование, дизайн. Стерильность — <b>High Temperature Dry Heat Sterilizer</b>. <b>14 000+</b> выполненных работ и <b>7 дней</b> гарантии. Запись онлайн за 1 минуту.',
    'hb_exp'=>'8+ лет • опыт','hb_ster'=>'Стерильность • сухожар','hb_works'=>'Работ всего • 14 000+','hb_warranty'=>'Гарантия • 7 дней',
    'slots_today'=>'⏰ Свободные окошки сегодня (в реальном времени)','open_hours'=>'🕘 Ежедневно 09:00–21:00',
    'promo_title'=>'🎁 Осеннее предложение: −10% новым клиентам','promo_note'=>'Действует только при первом визите. Используйте код <b>HELLO10</b> при онлайн-записи.','promo_cta'=>'Получить скидку',
    'masters_h2'=>'Наши мастера','book'=>'Записаться','quiz'=>'Квиз',
    'travel_h2'=>'Как добраться и парковка','parking_h5'=>'🚗 Парковка','transport_h5'=>'🚌 Транспорт','comfort_h5'=>'♿ Комфорт',
    'parking_p'=>'Kassi 6 — уличная парковка (платная в будни 08–19). Narva mnt 15 — подземный EuroPark. Priisle tee 4/1 — бесплатно 2 часа с диском.',
    'transport_p'=>'Трамвай 1/3, остановка <b>Kiviranna</b> (Narva mnt). Автобусы 7, 9, 31 — пешком недалеко.',
    'comfort_p'=>'Лифт, кофе/чай, Wi-Fi. Стерильность: полный цикл в сухожаре.',
    'footer_copy'=>'© %s maniküür.ee • Ежедневно 09:00–21:00',
    'choose_theme_title'=>'Выберите тему сайта','choose_theme_sub'=>'Можно сменить в любой момент в правом верхнем углу ⚙︎',
    'theme_light'=>'Светлая тема','theme_dark'=>'Тёмная тема'
  ],
  'ua'=>[
    'title'=>'maniküür.ee — найкращий манікюр у Таллінні',
    'desc'=>'Манікюр і педикюр у Таллінні. Стерильність: високотемпературний сухожаровий стерилізатор. 14 000+ виконаних робіт. Онлайн-запис за 1 хвилину.',
    'nav_services'=>'Послуги','nav_masters'=>'Майстри','nav_prices'=>'Ціни','nav_reviews'=>'Відгуки',
    'book_online'=>'Запис онлайн','call'=>'Подзвонити','via_quiz'=>'Підібрати через квіз',
    'top_badge'=>'⭐ ТОП Таллінн • 4.97/5',
    'hero_h1'=>'Ідеальний манікюр з першого дотику.',
    'hero_p'=>'Манікюр, педикюр, моделювання, дизайн. Стерильність — <b>High Temperature Dry Heat Sterilizer</b>. <b>14 000+</b> робіт і <b>7 днів</b> гарантії. Запис онлайн за 1 хвилину.',
    'hb_exp'=>'8+ років • досвід','hb_ster'=>'Стерильність • сухожар','hb_works'=>'Виконано • 14 000+','hb_warranty'=>'Гарантія • 7 днів',
    'slots_today'=>'⏰ Вільні слоти сьогодні (онлайн)','open_hours'=>'🕘 Щоденно 09:00–21:00',
    'promo_title'=>'🎁 Осіння пропозиція: −10% новим клієнтам','promo_note'=>'Лише на перший візит. Використайте код <b>HELLO10</b> під час онлайн-запису.','promo_cta'=>'Скористатися знижкою',
    'masters_h2'=>'Наші майстри','book'=>'Запис','quiz'=>'Квіз',
    'travel_h2'=>'Як дістатися та паркування','parking_h5'=>'🚗 Паркування','transport_h5'=>'🚌 Транспорт','comfort_h5'=>'♿ Комфорт',
    'parking_p'=>'Kassi 6 — парковка на вулиці (платна у будні 08–19). Narva mnt 15 — підземний EuroPark. Priisle tee 4/1 — безкоштовно 2 год з диском.',
    'transport_p'=>'Трамвай 1/3, зупинка <b>Kiviranna</b> (Narva mnt). Автобуси 7, 9, 31 — недалеко пішки.',
    'comfort_p'=>'Ліфт, кава/чай, Wi-Fi. Стерильність: повний цикл у сухожарі.',
    'footer_copy'=>'© %s maniküür.ee • Щоденно 09:00–21:00',
    'choose_theme_title'=>'Оберіть тему сайту','choose_theme_sub'=>'Можна змінити вгорі праворуч ⚙︎',
    'theme_light'=>'Світла тема','theme_dark'=>'Темна тема'
  ],
];

function pickLang(array $I18N): string {
  $lang = strtolower((string)($_GET['lang'] ?? ''));
  if (!isset($I18N[$lang])) $lang = 'et';
  return $lang;
}
$lang = pickLang($I18N);
function t($key){ global $I18N,$lang; return $I18N[$lang][$key] ?? $key; }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
?>
<!doctype html>
<html lang="<?=e($lang)?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=e(t('title'))?></title>
  <meta name="description" content="<?=e(t('desc'))?>">
  <style>
    :root{
      --bg:#f8fafc; --bg2:#ffffff; --text:#0f172a; --muted:#64748b; --ring:#e5e7eb;
      --accent:#7C3AED; --accent2:#06B6D4; --ok:#22C55E; --danger:#EF4444;
      --chip:#eef2ff; --shadow:0 10px 30px rgba(2,6,23,.08); --radius:16px;
    }
    [data-theme="dark"]{
      --bg:#0b1020; --bg2:#121833; --text:#EAF0FF; --muted:#9fb2ff; --ring:rgba(255,255,255,.12);
      --chip:#0e1430b3; --shadow:0 12px 40px rgba(0,0,0,.35);
    }
    *{box-sizing:border-box}
    html,body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
    a{color:inherit}
    .wrap{max-width:1200px;margin:0 auto;padding:20px}
    .topbar{display:flex;gap:14px;align-items:center;justify-content:space-between;padding:8px 0}
    .brand{display:flex;gap:10px;align-items:center;font-weight:800}
    .brand .dot{width:28px;height:28px;border-radius:10px;background:linear-gradient(135deg,var(--accent),#a78bfa)}
    .lang{display:flex;gap:6px}
    .chip{border:1px solid var(--ring);background:var(--chip);padding:8px 12px;border-radius:999px}
    .chip.ghost{background:transparent}
    .cta{background:linear-gradient(90deg,var(--accent),var(--accent2)); color:#fff;border:0;border-radius:999px;padding:10px 16px;font-weight:700;box-shadow:var(--shadow);text-decoration:none}
    .cta:hover{filter:brightness(1.06)}
    .btn{background:var(--bg2);border:1px solid var(--ring);border-radius:999px;padding:10px 14px;text-decoration:none}
    .btn:hover{border-color:#60a5fa}
    .grid{display:grid;gap:22px}
    .hero{display:grid;grid-template-columns:1.1fr .9fr;gap:28px;align-items:center;margin-top:18px}
    .hero h1{font-size:48px;line-height:1.05;margin:10px 0 16px;font-weight:900;letter-spacing:.2px}
    .hero p{color:var(--muted);max-width:60ch}
    .card{background:var(--bg2);border:1px solid var(--ring);border-radius:var(--radius);box-shadow:var(--shadow)}
    .card.pad{padding:16px}
    .hero-badges{display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:12px;margin-top:18px}
    .hb{border:1px solid var(--ring);background:var(--chip);border-radius:14px;padding:12px 14px}
    .hb b{display:block;font-size:14px;margin-bottom:2px;color:var(--muted)}
    .hb span{opacity:.9}

    .slotbar{display:flex;gap:10px;align-items:center;color:var(--muted);font-size:14px;margin-bottom:10px}
    .slots{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
    .slot{border:1px solid var(--ring);background:var(--bg2);border-radius:12px;padding:10px;display:flex;align-items:center;gap:10px}
    .slot .badge{width:10px;height:10px;border-radius:999px;background:var(--ok);box-shadow:0 0 0 4px rgba(34,197,94,.15)}
    .slot.busy .badge{background:var(--danger);box-shadow:0 0 0 4px rgba(239,68,68,.10)}
    .slot small{color:var(--muted)}
    .addr{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .addr a{border:1px solid var(--ring);background:var(--bg2);border-radius:999px;padding:8px 12px;color:var(--muted);text-decoration:none}
    .addr a:hover{border-color:#7dd3fc}

    .masters{margin-top:50px}
    .mh{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
    .carousel{position:relative;overflow:hidden}
    .track{display:flex;gap:14px;transition:transform .4s ease}
    .master{min-width:220px; background:var(--bg2); border:1px solid var(--ring); border-radius:16px; padding:12px}
    .master .ph{height:160px;border-radius:12px;background:linear-gradient(135deg,#94a3b8,#e2e8f0)}
    [data-theme="dark"] .master .ph{background:linear-gradient(135deg,#334155,#0f172a)}
    .master h4{margin:10px 0 6px}
    .navbtn{border:1px solid var(--ring);background:var(--bg2);border-radius:10px;padding:8px 10px;cursor:pointer}
    .navbtn:disabled{opacity:.5;cursor:not-allowed}

    .travel{margin-top:44px}
    .list{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
    .b{padding:14px;border:1px solid var(--ring);background:var(--bg2);border-radius:16px}
    .b h5{margin:0 0 6px}
    .b p{margin:0;color:var(--muted)}

    .promo{margin:44px 0;padding:18px;border-radius:18px;border:1px solid var(--ring);background:linear-gradient(90deg,#0ea5e9 0,#8b5cf6 100%);color:#fff;display:flex;align-items:center;justify-content:space-between;gap:14px}
    .promo small{opacity:.95}

    footer{margin:40px 0 10px;color:var(--muted);display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
    footer a{color:var(--muted);text-decoration:none}
    footer a:hover{color:inherit}

    .fab{position:fixed;right:18px;bottom:18px;display:flex;flex-direction:column;gap:10px;z-index:99}
    .fab a{display:flex;align-items:center;gap:8px;border-radius:999px;padding:12px 14px;background:var(--bg2);border:1px solid var(--ring);text-decoration:none;color:inherit;box-shadow:var(--shadow)}

    .theme-toggle{margin-left:8px}
    .theme-toggle input{width:40px;height:22px;appearance:none;background:var(--ring);border-radius:999px;position:relative;outline:none;cursor:pointer}
    .theme-toggle input:before{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:.2s}
    [data-theme="dark"] .theme-toggle input{background:#1f2937}
    .theme-toggle input:checked:before{transform:translateX(18px)}

    /* first-visit theme chooser */
    .veil{position:fixed;inset:0;backdrop-filter:blur(10px);background:rgba(0,0,0,.25);display:none;align-items:center;justify-content:center;z-index:120}
    .veil.open{display:flex}
    .veil .chooser{background:var(--bg2);border:1px solid var(--ring);border-radius:18px;box-shadow:var(--shadow);padding:22px;max-width:520px}
    .veil h3{margin:0 0 6px}
    .veil p{margin:0 0 12px;color:var(--muted)}
    .veil .row{display:flex;gap:10px;flex-wrap:wrap}
    .preview{flex:1;min-width:200px;border:1px solid var(--ring);border-radius:12px;padding:10px}
    .preview.light{background:#fff;color:#0f172a}
    .preview.dark{background:#0b1020;color:#EAF0FF}
    .preview small{color:#64748b}
    .preview.dark small{color:#9fb2ff}

    @media (max-width:980px){
      .hero{grid-template-columns:1fr}
      .slots{grid-template-columns:1fr 1fr}
      .list{grid-template-columns:1fr}
    }
  </style>
</head>
<body>
  <div id="page" data-theme="light">
    <div class="wrap">
      <!-- TOPBAR -->
      <div class="topbar">
        <div class="brand">
          <div class="dot"></div>
          <div style="font-weight:900">maniküür.ee</div>
        </div>
        <nav style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <a class="chip ghost" href="#services"><?=e(t('nav_services'))?></a>
          <a class="chip ghost" href="#masters"><?=e(t('nav_masters'))?></a>
          <a class="chip ghost" href="#prices"><?=e(t('nav_prices'))?></a>
          <a class="chip ghost" href="#reviews"><?=e(t('nav_reviews'))?></a>

          <div class="lang">
            <?php foreach(['et','en','ua','ru'] as $L): ?>
              <?php if ($L===$lang): ?>
                <span class="chip"><?=strtoupper(e($L))?></span>
              <?php else: ?>
                <a class="chip ghost" href="?lang=<?=$L?>"><?=strtoupper(e($L))?></a>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>

          <a class="cta" href="<?=BOOK_URL?>"><?=e(t('book_online'))?></a>

          <!-- Theme toggle -->
          <label class="theme-toggle" title="Theme">
            <input id="themeSwitch" type="checkbox">
          </label>
        </nav>
      </div>

      <!-- HERO -->
      <section class="hero">
        <div>
          <div class="chip" style="display:inline-block"><?=e(t('top_badge'))?></div>
          <h1><?=e(t('hero_h1'))?></h1>
          <p><?=t('hero_p')?></p>
          <div style="display:flex;gap:10px;margin:14px 0 6px;flex-wrap:wrap">
            <a class="cta" href="<?=BOOK_URL?>"><?=e(t('book_online'))?></a>
            <a class="btn" href="tel:<?=preg_replace('~\s+~','',BOOK_PHONE)?>"><?=e(t('call'))?></a>
            <a class="btn" href="<?=QUIZ_URL?>"><?=e(t('via_quiz'))?></a>
          </div>
          <div class="hero-badges">
            <div class="hb"><b><?=e(t('hb_exp'))?></b><span>&nbsp;</span></div>
            <div class="hb"><b><?=e(t('hb_ster'))?></b><span>&nbsp;</span></div>
            <div class="hb"><b><?=e(t('hb_works'))?></b><span>&nbsp;</span></div>
            <div class="hb"><b><?=e(t('hb_warranty'))?></b><span>&nbsp;</span></div>
          </div>
        </div>

        <div class="card pad">
          <div class="slotbar"><?=e(t('slots_today'))?></div>
          <div id="slots" class="slots"></div>
          <div style="margin-top:10px;color:var(--muted);font-size:14px"><?=e(t('open_hours'))?></div>
          <div class="addr">
            <?php foreach($addresses as $a): ?>
              <a href="<?=e($a['maps'])?>" target="_blank">📍 <?=e($a['title'][$lang] ?? $a['title']['et'])?></a>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <!-- PROMO -->
      <div class="promo">
        <div>
          <div style="font-weight:800;font-size:20px"><?=t('promo_title')?></div>
          <small><?=t('promo_note')?></small>
        </div>
        <a class="cta" href="<?=BOOK_URL?>"><?=e(t('promo_cta'))?></a>
      </div>

      <!-- MASTERS -->
      <section class="masters" id="masters">
        <div class="mh">
          <h2 style="margin:0"><?=e(t('masters_h2'))?></h2>
          <div style="display:flex;gap:8px">
            <button class="navbtn" id="prevBtn">◀</button>
            <button class="navbtn" id="nextBtn">▶</button>
          </div>
        </div>
        <div class="carousel card pad">
          <div id="track" class="track">
            <?php
            $demo = [
              ['name'=>'Tatyana','spec'=>['et'=>'Maniküür • Disain','en'=>'Manicure • Design','ru'=>'Маникюр • Дизайн','ua'=>'Манікюр • Дизайн'],'rating'=>'4.97','exp'=>['et'=>'8 a','en'=>'8 y','ru'=>'8 л','ua'=>'8 р']],
              ['name'=>'Aleksandr','spec'=>['et'=>'Pediküür • Aparaat','en'=>'Pedicure • Apparatus','ru'=>'Педикюр • Аппаратный','ua'=>'Педикюр • Апаратний'],'rating'=>'4.93','exp'=>['et'=>'6 a','en'=>'6 y','ru'=>'6 л','ua'=>'6 р']],
              ['name'=>'Marina','spec'=>['et'=>'Geel • Prantsuse','en'=>'Gel • French','ru'=>'Гель • Французский','ua'=>'Гель • Французький'],'rating'=>'4.95','exp'=>['et'=>'7 a','en'=>'7 y','ru'=>'7 л','ua'=>'7 р']],
              ['name'=>'Elena','spec'=>['et'=>'Laste maniküür','en'=>'Kids manicure','ru'=>'Детский маникюр','ua'=>'Дитячий манікюр'],'rating'=>'4.90','exp'=>['et'=>'5 a','en'=>'5 y','ru'=>'5 л','ua'=>'5 р']],
              ['name'=>'Olga','spec'=>['et'=>'Kombineeritud','en'=>'Combined','ru'=>'Комбинированный','ua'=>'Комбінований'],'rating'=>'4.96','exp'=>['et'=>'9 a','en'=>'9 y','ru'=>'9 л','ua'=>'9 р']],
            ];
            foreach($demo as $m): ?>
              <div class="master">
                <div class="ph"></div>
                <h4><?=e($m['name'])?> · ⭐ <?=e($m['rating'])?></h4>
                <div style="color:var(--muted)"><?=e($m['spec'][$lang])?></div>
                <div style="margin-top:6px" class="addr">
                  <a href="<?=BOOK_URL?>"><?=e(t('book'))?></a>
                  <a href="<?=QUIZ_URL?>"><?=e(t('quiz'))?></a>
                </div>
                <div style="margin-top:6px;color:var(--muted);font-size:14px"><?=e($m['exp'][$lang])?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <!-- HOW TO GET / PARKING -->
      <section class="travel" id="travel">
        <h2 style="margin:0 0 10px"><?=e(t('travel_h2'))?></h2>
        <div class="list">
          <div class="b">
            <h5><?=e(t('parking_h5'))?></h5>
            <p><?=t('parking_p')?></p>
          </div>
          <div class="b">
            <h5><?=e(t('transport_h5'))?></h5>
            <p><?=t('transport_p')?></p>
          </div>
          <div class="b">
            <h5><?=e(t('comfort_h5'))?></h5>
            <p><?=t('comfort_p')?></p>
          </div>
        </div>
      </section>

      <!-- FOOTER -->
      <footer>
        <div><?=sprintf(t('footer_copy'), date('Y'))?></div>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
          <a href="tel:<?=preg_replace('~\s+~','',BOOK_PHONE)?>">📞 <?=e(BOOK_PHONE)?></a>
          <a href="<?=e(EMAIL)?>">✉️ info@manikuur.ee</a>
          <a href="<?=e(WHATSAPP)?>">WhatsApp</a>
          <a href="<?=e(TELEGRAM)?>">Telegram</a>
        </div>
      </footer>
    </div>
  </div>

  <!-- Floating messengers -->
  <div class="fab">
    <a class="wh" href="<?=e(WHATSAPP)?>" target="_blank" rel="noopener">WhatsApp</a>
    <a class="tg" href="<?=e(TELEGRAM)?>" target="_blank" rel="noopener">Telegram</a>
  </div>

  <!-- First-visit theme chooser -->
  <div class="veil" id="veil">
    <div class="chooser">
      <h3><?=e(t('choose_theme_title'))?></h3>
      <p><?=e(t('choose_theme_sub'))?></p>
      <div class="row">
        <button id="pickLight" class="btn"><?=e(t('theme_light'))?></button>
        <button id="pickDark"  class="cta"><?=e(t('theme_dark'))?></button>
      </div>
      <div class="row" style="margin-top:12px">
        <div class="preview light"><b><?=e(t('theme_light'))?></b><br><small>maniküür.ee</small></div>
        <div class="preview dark"><b><?=e(t('theme_dark'))?></b><br><small>maniküür.ee</small></div>
      </div>
    </div>
  </div>

  <script>
  // Theme engine
  (function(){
    const page  = document.getElementById('page');
    const sw    = document.getElementById('themeSwitch');
    const veil  = document.getElementById('veil');

    function apply(theme){
      page.setAttribute('data-theme', theme);
      sw.checked = (theme === 'dark');
      try { localStorage.setItem('site_theme', theme); } catch(_) {}
    }
    function current(){
      try { return localStorage.getItem('site_theme') || ''; } catch(_){ return ''; }
    }

    // first visit chooser
    let th = current();
    if (!th) {
      veil.classList.add('open');
    } else {
      apply(th);
    }

    document.getElementById('pickLight').addEventListener('click', ()=>{
      apply('light'); veil.classList.remove('open');
      new Image().src='/track/theme.php?c=light&t=' + Date.now();
    });
    document.getElementById('pickDark').addEventListener('click', ()=>{
      apply('dark'); veil.classList.remove('open');
      new Image().src='/track/theme.php?c=dark&t=' + Date.now();
    });

    sw.addEventListener('change', ()=>apply(sw.checked?'dark':'light'));
  })();

  // Masters carousel
  (function(){
    const track = document.getElementById('track');
    if (!track) return;
    let index = 0;
    const cardW = 234;
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

  // Live slots (progressive enhancement)
  (async function(){
    const box = document.getElementById('slots');
    function pill(t, free, note){
      return `<div class="slot ${free?'':'busy'}">
                <div class="badge"></div>
                <div><div style="font-weight:700">${t}</div><small>${note|| (free?'Free':'Busy')}</small></div>
              </div>`;
    }
    try{
      const urls = ['/booking/api/slots_today','/booking/api/slots-today','/booking/api/slots_today.php'];
      let data = null;
      for (const u of urls){
        try{
          const r = await fetch(u, {cache:'no-store'});
          if (r.ok){ data = await r.json(); break; }
        }catch(_){}
      }
      if (Array.isArray(data) && data.length){
        box.innerHTML = data.slice(0,6).map(x => pill(x.time, !!x.free, x.salon||'')).join('');
      } else {
        throw 0;
      }
    }catch(e){
      const sample = ['11:00','13:00','16:30'].map(t=>({time:t,free:true}));
      box.innerHTML = sample.map(x=>pill(x.time,true,'')).join('');
    }
  })();
  </script>
</body>
</html>