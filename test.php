<?php
declare(strict_types=1);

/**
 * maniküür.ee — лендинг с мультиязычностью и онлайн-слотами.
 * Требует booking/config.php (pdo()).
 */
require __DIR__ . '/booking/config.php';

/* ------------------------- I18N ------------------------- */
$allowedLangs = ['et','en','ua','ru'];
$lang = $_GET['lang'] ?? 'et';
if (!in_array($lang, $allowedLangs, true)) $lang = 'et';

$brand   = 'maniküür.ee';
$phone   = '+37259177779';
$phoneHref = '+37259177779';
$email   = 'info@maniküür.ee';
$addresses = [
  ['label'=>'Kassi 6','maps'=>'https://maps.google.com/?q=Kassi%206%2C%20Tallinn'],
  ['label'=>'Narva maantee 15','maps'=>'https://maps.google.com/?q=Narva%20maantee%2015%2C%20Tallinn'],
  ['label'=>'Priisle tee 4/1','maps'=>'https://maps.google.com/?q=Priisle%20tee%204%2F1%2C%20Tallinn'],
];

$dict = [
  'et' => [
    'meta_title' => 'maniküür.ee — Täiuslik maniküür ja pediküür Tallinnas',
    'nav_services'=>'Teenused','nav_masters'=>'Meistrid','nav_reviews'=>'Arvustused','nav_prices'=>'Hinnad','nav_contacts'=>'Kontaktid',
    'cta_book'=>'Broneeri online','cta_call'=>'Helista','cta_quiz'=>'Vali quiziga',
    'hero_badge'=>'TOP salong Tallinnas • 4.97/5',
    'hero_title'=>'Sinu ideaalne maniküür — esimesest puudutusest.',
    'hero_desc'=>'Maniküür, pediküür, disain — täpne, steriilne ja armastusega detailide vastu. Broneering 1 minutiga.',
    'feat_exp'=>'Kogemus','feat_sterile'=>'Autoklaav','feat_designs'=>'Disainid','feat_warranty'=>'Garantii',
    'feat_exp_v'=>'8+ a','feat_designs_v'=>'500+','feat_warranty_v'=>'7 päeva',
    'block_slots_title'=>'Vabad ajad täna',
    'block_slots_pick'=>'Vali kuupäev','block_slots_duration'=>'Kestus','block_slots_min'=>'min','block_slots_more'=>'Rohkem aegu',
    'block_masters_title'=>'Meie meistrid','block_masters_more'=>'Vaata kõiki',
    'block_transport_title'=>'Kuidas meie juurde tulla',
    'transport_text'=>'Tramm/buss: peatused lähistel. Tasuta parkimine 30 min piirkonnas (täpsemalt kohapeal).',
    'block_contacts_title'=>'Kontaktid',
    'hours'=>'Iga päev 09:00–21:00',
    'footer_rights'=>'© '.date('Y').' maniküür.ee. Kõik õigused kaitstud.',
    'lang_et'=>'ET','lang_en'=>'EN','lang_ua'=>'UA','lang_ru'=>'RU',
    'whatsapp'=>'WhatsApp','telegram'=>'Telegram',
    'slots_none'=>'Vabu aegu pole','slots_loading'=>'Laen…',
  ],
  'en' => [
    'meta_title' => 'maniküür.ee — Perfect manicure & pedicure in Tallinn',
    'nav_services'=>'Services','nav_masters'=>'Masters','nav_reviews'=>'Reviews','nav_prices'=>'Prices','nav_contacts'=>'Contacts',
    'cta_book'=>'Book online','cta_call'=>'Call','cta_quiz'=>'Pick with quiz',
    'hero_badge'=>'TOP salon in Tallinn • 4.97/5',
    'hero_title'=>'Your perfect manicure — from the first touch.',
    'hero_desc'=>'Manicure, pedicure, design — accurate, sterile and with love for details. Book online in 1 minute.',
    'feat_exp'=>'Experience','feat_sterile'=>'Autoclave','feat_designs'=>'Designs','feat_warranty'=>'Warranty',
    'feat_exp_v'=>'8+ yrs','feat_designs_v'=>'500+','feat_warranty_v'=>'7 days',
    'block_slots_title'=>'Free time today',
    'block_slots_pick'=>'Pick a date','block_slots_duration'=>'Duration','block_slots_min'=>'min','block_slots_more'=>'More slots',
    'block_masters_title'=>'Our masters','block_masters_more'=>'See all',
    'block_transport_title'=>'How to get here',
    'transport_text'=>'Tram/bus: stops nearby. Free parking 30 min in the area (details on site).',
    'block_contacts_title'=>'Contacts',
    'hours'=>'Daily 09:00–21:00',
    'footer_rights'=>'© '.date('Y').' maniküür.ee. All rights reserved.',
    'lang_et'=>'ET','lang_en'=>'EN','lang_ua'=>'UA','lang_ru'=>'RU',
    'whatsapp'=>'WhatsApp','telegram'=>'Telegram',
    'slots_none'=>'No free slots','slots_loading'=>'Loading…',
  ],
  'ua' => [
    'meta_title' => 'maniküür.ee — Ідеальний манікюр і педикюр у Таллінні',
    'nav_services'=>'Послуги','nav_masters'=>'Майстри','nav_reviews'=>'Відгуки','nav_prices'=>'Ціни','nav_contacts'=>'Контакти',
    'cta_book'=>'Записатися онлайн','cta_call'=>'Подзвонити','cta_quiz'=>'Підібрати за квізом',
    'hero_badge'=>'ТОП салон Таллінна • 4.97/5',
    'hero_title'=>'Ваш ідеальний манікюр — з першого дотику.',
    'hero_desc'=>'Манікюр, педикюр, дизайн — акуратно, стерильно й з любов’ю до деталей. Онлайн-запис за 1 хвилину.',
    'feat_exp'=>'Досвід','feat_sterile'=>'Автоклав','feat_designs'=>'Дизайни','feat_warranty'=>'Гарантія',
    'feat_exp_v'=>'8+ років','feat_designs_v'=>'500+','feat_warranty_v'=>'7 днів',
    'block_slots_title'=>'Вільні години сьогодні',
    'block_slots_pick'=>'Обрати дату','block_slots_duration'=>'Тривалість','block_slots_min'=>'хв','block_slots_more'=>'Ще слоти',
    'block_masters_title'=>'Наші майстри','block_masters_more'=>'Дивитися всі',
    'block_transport_title'=>'Як до нас дістатися',
    'transport_text'=>'Трамвай/автобус: зупинки поруч. Безкоштовне паркування 30 хв у районі (деталі на місці).',
    'block_contacts_title'=>'Контакти',
    'hours'=>'Щодня 09:00–21:00',
    'footer_rights'=>'© '.date('Y').' maniküür.ee. Усі права захищені.',
    'lang_et'=>'ET','lang_en'=>'EN','lang_ua'=>'UA','lang_ru'=>'RU',
    'whatsapp'=>'WhatsApp','telegram'=>'Telegram',
    'slots_none'=>'Немає вільних слотів','slots_loading'=>'Завантаження…',
  ],
  'ru' => [
    'meta_title' => 'maniküür.ee — Идеальный маникюр и педикюр в Таллине',
    'nav_services'=>'Услуги','nav_masters'=>'Мастера','nav_reviews'=>'Отзывы','nav_prices'=>'Цены','nav_contacts'=>'Контакты',
    'cta_book'=>'Записаться онлайн','cta_call'=>'Позвонить','cta_quiz'=>'Подобрать по квизу',
    'hero_badge'=>'ТОП-салон Таллина • 4.97/5',
    'hero_title'=>'Ваш идеальный маникюр — с первого касания.',
    'hero_desc'=>'Маникюр, педикюр, дизайн — аккуратно, стерильно и с любовью к деталям. Онлайн-запись за 1 минуту.',
    'feat_exp'=>'Опыт','feat_sterile'=>'Автоклав','feat_designs'=>'Дизайны','feat_warranty'=>'Гарантия',
    'feat_exp_v'=>'8+ лет','feat_designs_v'=>'500+','feat_warranty_v'=>'7 дней',
    'block_slots_title'=>'Свободные окна сегодня',
    'block_slots_pick'=>'Выберите дату','block_slots_duration'=>'Длительность','block_slots_min'=>'мин','block_slots_more'=>'Ещё слоты',
    'block_masters_title'=>'Наши мастера','block_masters_more'=>'Смотреть все',
    'block_transport_title'=>'Как к нам добраться',
    'transport_text'=>'Трамвай/автобус: остановки рядом. Бесплатная парковка 30 мин в районе (подробности на месте).',
    'block_contacts_title'=>'Контакты',
    'hours'=>'Ежедневно 09:00–21:00',
    'footer_rights'=>'© '.date('Y').' maniküür.ee. Все права защищены.',
    'lang_et'=>'ET','lang_en'=>'EN','lang_ua'=>'UA','lang_ru'=>'RU',
    'whatsapp'=>'WhatsApp','telegram'=>'Telegram',
    'slots_none'=>'Свободных слотов нет','slots_loading'=>'Загрузка…',
  ],
];
$t = $dict[$lang];

/* ------------- мини-helpers -------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function pdo2(): PDO { return pdo(); }

/* ------------- Мини-API для слотов --------------
 * GET action=slots&date=YYYY-MM-DD&salon_id=&duration=
 * Рабочие часы ежедневно 09:00–21:00.
 */
if (($_GET['action'] ?? '') === 'slots') {
  header('Content-Type: application/json; charset=utf-8');
  $date = preg_replace('~[^0-9\-]~','', (string)($_GET['date'] ?? ''));
  if (!$date || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) $date = (new DateTime('today'))->format('Y-m-d');
  $salonId  = (int)($_GET['salon_id'] ?? 0);
  $duration = max(15, (int)($_GET['duration'] ?? 60)); // минуты

  $db = pdo2();
  // узнаем колонки начала/конца
  $colStart = 'starts'; $colEnd='ends';
  foreach (['starts','start_dt','start_at','start','begin_at'] as $c) {
    $ok = $db->query("SHOW COLUMNS FROM appointments LIKE ".$db->quote($c))->fetch();
    if ($ok) { $colStart = $c; break; }
  }
  foreach (['ends','end_dt','end_at','end','finish_at'] as $c) {
    $ok = $db->query("SHOW COLUMNS FROM appointments LIKE ".$db->quote($c))->fetch();
    if ($ok) { $colEnd = $c; break; }
  }

  $dayStart = "$date 09:00:00";
  $dayEnd   = "$date 21:00:00";

  $sql = "SELECT $colStart AS s, $colEnd AS e FROM appointments
          WHERE $colStart < :dayEnd AND $colEnd > :dayStart" . ($salonId>0 ? " AND salon_id=:sal" : "");
  $st = $db->prepare($sql);
  $params = [':dayStart'=>$dayStart, ':dayEnd'=>$dayEnd];
  if ($salonId>0) $params[':sal']=$salonId;
  $st->execute($params);
  $busy = $st->fetchAll(PDO::FETCH_ASSOC);

  // генерим сетку слотов
  $slots = [];
  $t = new DateTime($dayStart);
  $end = new DateTime($dayEnd);
  while ($t < $end) {
    $slotStart = clone $t;
    $slotEnd   = (clone $t)->modify("+{$duration} minutes");
    if ($slotEnd > $end) break;

    // проверка пересечения
    $free = true;
    foreach ($busy as $b) {
      $bs = new DateTime($b['s']); $be = new DateTime($b['e']);
      if ($slotStart < $be && $slotEnd > $bs) { $free = false; break; }
    }
    if ($free) $slots[] = ['s'=>$slotStart->format('H:i'), 'e'=>$slotEnd->format('H:i')];
    $t->modify('+15 minutes'); // шаг сетки 15 мин
  }

  echo json_encode(['date'=>$date,'slots'=>$slots], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ------ Данные мастеров для карусели (из БД) ------ */
$db = pdo2();

/** Проверка наличия колонки в таблице (без prepared) */
function has_col(PDO $db, string $table, string $col): bool {
  // экранируем имя таблицы и кавычки в нём (поддержка бэктиков)
  $safeTable = str_replace('`','``',$table);
  $sql = "SHOW COLUMNS FROM `{$safeTable}` LIKE " . $db->quote($col);
  return (bool)$db->query($sql)->fetch();
}

/** Выбираем первую существующую колонку для фото */
$photoCandidates = ['photo_url','photo','avatar','image_url','img_url','image','img','picture'];
$photoCol = '';
foreach ($photoCandidates as $c) {
  if (has_col($db, 'staff', $c)) { $photoCol = $c; break; }
}

$sql = "SELECT id, name"
     . ($photoCol ? ", `{$photoCol}` AS photo" : ", '' AS photo")
     . " FROM staff ORDER BY id LIMIT 12";

$masters = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="<?=h($lang)?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=h($t['meta_title'])?></title>
  <style>
    :root{
      --bg:#0b1020; --bg2:#121a33; --glass:rgba(255,255,255,.06); --muted:#a7b0c4;
      --fg:#e8ecf8; --brand1:#8b5cf6; --brand2:#60a5fa; --ring:rgba(99,102,241,.4);
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:radial-gradient(1200px 600px at 20% -10%, #1a2144 0%, #0b1020 60%, #080d1a 100%);color:var(--fg)}
    a{color:inherit}
    .wrap{max-width:1200px;margin:0 auto;padding:0 20px}

    /* header */
    header{position:sticky;top:0;backdrop-filter:saturate(140%) blur(8px);background:linear-gradient(180deg, rgba(8,13,26,.8), rgba(8,13,26,.3));border-bottom:1px solid rgba(255,255,255,.06);z-index:20}
    .nav{display:flex;align-items:center;justify-content:space-between;height:64px}
    .logo{display:flex;align-items:center;gap:10px;font-weight:800}
    .logo-badge{width:28px;height:28px;border-radius:8px;background:linear-gradient(135deg,var(--brand1),var(--brand2));box-shadow:0 8px 30px rgba(99,102,241,.35)}
    .menu{display:flex;gap:14px}
    .menu a{padding:8px 12px;border:1px solid rgba(255,255,255,.08);border-radius:999px;text-decoration:none;color:#e7ecfb}
    .menu a:hover{border-color:rgba(255,255,255,.35)}
    .lang a{opacity:.65;text-decoration:none;margin:0 4px}
    .lang .active{opacity:1;font-weight:700}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:999px;text-decoration:none;border:1px solid rgba(255,255,255,.12);background:linear-gradient(90deg,var(--brand1),var(--brand2));color:white;box-shadow:0 8px 28px rgba(99,102,241,.35)}
    .btn--ghost{background:transparent;color:#e7ecfb}
    .btn--ghost:hover{border-color:rgba(255,255,255,.4)}

    /* hero */
    .hero{display:grid;grid-template-columns:1.1fr .9fr;gap:28px;align-items:center;padding:56px 0 24px}
    .badge{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;background:var(--glass);border:1px solid rgba(255,255,255,.1);color:#cfe0ff}
    h1{font-size:48px;line-height:1.1;margin:14px 0 12px}
    .lead{color:var(--muted);max-width:640px}
    .hero-cta{display:flex;gap:10px;margin-top:16px}
    .kpi{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:22px}
    .kpi .card{border:1px solid rgba(255,255,255,.08);background:var(--glass);border-radius:14px;padding:14px}
    .kpi small{display:block;color:#c8d1e8}
    .kpi b{font-size:18px}

    .panel{border:1px solid rgba(255,255,255,.12);background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));border-radius:16px;padding:14px}
    .slots{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
    .slot{padding:8px 10px;border-radius:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);cursor:pointer}
    .slot:hover{border-color:var(--brand2)}
    .muted{color:var(--muted)}
    .subtitle{font-size:22px;margin:26px 0 12px}

    /* masters carousel */
    .carousel{display:grid;grid-auto-flow:column;grid-auto-columns:220px;gap:12px;overflow:auto;padding-bottom:6px}
    .master{border:1px solid rgba(255,255,255,.08);background:var(--glass);border-radius:14px;padding:12px}
    .master img{width:100%;height:180px;object-fit:cover;border-radius:10px;background:#101830}
    .master b{display:block;margin-top:8px}

    /* cards / transport / contacts */
    .cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
    .card{border:1px solid rgba(255,255,255,.08);background:var(--glass);border-radius:14px;padding:14px}

    footer{margin:36px 0 24px;color:#9aa6c5}
    .float-widgets{position:fixed;right:16px;bottom:16px;display:flex;flex-direction:column;gap:10px;z-index:30}
    .float-widgets a{padding:12px 14px;border-radius:999px;background:linear-gradient(90deg,var(--brand2),var(--brand1));color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.18);box-shadow:0 12px 30px rgba(99,102,241,.33)}
    @media (max-width:980px){
      .hero{grid-template-columns:1fr}
      .kpi{grid-template-columns:repeat(2,1fr)}
      .cards{grid-template-columns:1fr}
    }
  </style>
</head>
<body>

<header>
  <div class="wrap nav">
    <div class="logo">
      <div class="logo-badge"></div>
      <a href="/?lang=<?=h($lang)?>" style="text-decoration:none;color:#fff"><b><?=h($brand)?></b></a>
    </div>
    <nav class="menu">
      <a href="#services"><?=$t['nav_services']?></a>
      <a href="#masters"><?=$t['nav_masters']?></a>
      <a href="#reviews"><?=$t['nav_reviews']?></a>
      <a href="#prices"><?=$t['nav_prices']?></a>
      <a href="#contacts"><?=$t['nav_contacts']?></a>
    </nav>
    <div style="display:flex;align-items:center;gap:12px">
      <div class="lang">
        <a class="<?= $lang==='et'?'active':''?>" href="?lang=et"><?=$t['lang_et']?></a> |
        <a class="<?= $lang==='en'?'active':''?>" href="?lang=en"><?=$t['lang_en']?></a> |
        <a class="<?= $lang==='ua'?'active':''?>" href="?lang=ua"><?=$t['lang_ua']?></a> |
        <a class="<?= $lang==='ru'?'active':''?>" href="?lang=ru"><?=$t['lang_ru']?></a>
      </div>
      <a class="btn" href="/booking/?lang=<?=h($lang)?>"><?=$t['cta_book']?></a>
    </div>
  </div>
</header>

<main class="wrap">
  <!-- HERO -->
  <section class="hero">
    <div>
      <div class="badge">✨ <?=h($t['hero_badge'])?></div>
      <h1><?=h($t['hero_title'])?></h1>
      <p class="lead"><?=h($t['hero_desc'])?></p>
      <div class="hero-cta">
        <a class="btn" href="/booking/?lang=<?=h($lang)?>"><?=$t['cta_book']?></a>
        <a class="btn btn--ghost" href="tel:<?=h($phoneHref)?>"><?=$t['cta_call']?></a>
        <a class="btn btn--ghost" href="/quiz.php?lang=<?=h($lang)?>"><?=$t['cta_quiz']?></a>
      </div>
      <div class="kpi">
        <div class="card"><small><?=$t['feat_exp']?></small><b><?=$t['feat_exp_v']?></b></div>
        <div class="card"><small>Sterile</small><b><?=$t['feat_sterile']?></b></div>
        <div class="card"><small><?=$t['feat_designs']?></small><b><?=$t['feat_designs_v']?></b></div>
        <div class="card"><small><?=$t['feat_warranty']?></small><b><?=$t['feat_warranty_v']?></b></div>
      </div>
    </div>

    <!-- Slots Panel -->
    <div class="panel">
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
        <b><?=$t['block_slots_title']?></b>
        <div class="muted">🕘 <?=$t['hours']?></div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
        <label class="muted"><?=$t['block_slots_pick']?>:
          <input id="slotsDate" type="date" style="margin-left:6px;background:transparent;border:1px solid rgba(255,255,255,.25);border-radius:8px;color:#fff;padding:6px 8px">
        </label>
        <label class="muted"><?=$t['block_slots_duration']?>:
          <select id="slotsDur" style="margin-left:6px;background:transparent;border:1px solid rgba(255,255,255,.25);border-radius:8px;color:#fff;padding:6px 8px">
            <option value="45">45 <?=$t['block_slots_min']?></option>
            <option value="60" selected>60 <?=$t['block_slots_min']?></option>
            <option value="75">75 <?=$t['block_slots_min']?></option>
            <option value="90">90 <?=$t['block_slots_min']?></option>
          </select>
        </label>
      </div>
      <div id="slots" class="slots"><span class="muted"><?=$t['slots_loading']?></span></div>
      <div style="margin-top:10px"><a href="/booking/?lang=<?=h($lang)?>" class="btn btn--ghost"><?=$t['block_slots_more']?></a></div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px" class="muted">
        <?php foreach ($addresses as $a): ?>
          <a href="<?=h($a['maps'])?>" target="_blank" style="padding:6px 10px;border:1px solid rgba(255,255,255,.12);border-radius:999px;text-decoration:none">📍 <?=h($a['label'])?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- Masters -->
  <section id="masters">
    <div class="subtitle"><?=$t['block_masters_title']?></div>
    <div class="carousel">
      <?php foreach ($masters as $m):
        $img = $m['photo'] ?: 'https://picsum.photos/seed/'.urlencode((string)$m['id']).'/400/300';
      ?>
        <div class="master">
          <img src="<?=h($img)?>" alt="<?=h($m['name'])?>">
          <b><?=h($m['name'])?></b>
          <a class="btn btn--ghost" style="margin-top:8px" href="/booking/?staff_id=<?= (int)$m['id'] ?>&lang=<?=h($lang)?>"><?=$t['cta_book']?></a>
        </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:10px"><a class="btn btn--ghost" href="/booking/?lang=<?=h($lang)?>"><?=$t['block_masters_more']?></a></div>
  </section>

  <!-- Services / Prices (якоря) -->
  <section id="services">
    <div class="subtitle"><?=$t['nav_services']?> & <?=$t['nav_prices']?></div>
    <div class="cards">
      <div class="card">
        <b>Manicure</b>
        <div class="muted">Classic • Gel polish • Builder leveling • Design</div>
      </div>
      <div class="card">
        <b>Pedicure</b>
        <div class="muted">Classic • Gel polish • SPA</div>
      </div>
      <div class="card">
        <b>Combo</b>
        <div class="muted">Manicure + Pedicure • save 10%</div>
      </div>
    </div>
  </section>

  <!-- Transport / Parking -->
  <section style="margin-top:24px">
    <div class="subtitle"><?=$t['block_transport_title']?></div>
    <div class="card">
      <div class="muted"><?=$t['transport_text']?></div>
    </div>
  </section>

  <!-- Contacts -->
  <section id="contacts" style="margin-top:24px">
    <div class="subtitle"><?=$t['block_contacts_title']?></div>
    <div class="cards">
      <div class="card">
        <b>Email</b>
        <div><a href="mailto:<?=h($email)?>" style="text-decoration:none"><?=h($email)?></a></div>
      </div>
      <div class="card">
        <b>Phone</b>
        <div><a href="tel:<?=h($phoneHref)?>" style="text-decoration:none"><?=h($phone)?></a></div>
      </div>
      <div class="card">
        <b>Hours</b>
        <div class="muted"><?=$t['hours']?></div>
      </div>
    </div>
  </section>

  <footer>
    <div class="wrap" style="padding-top:16px"><?=$t['footer_rights']?></div>
  </footer>
</main>

<!-- Floating chat widgets -->
<div class="float-widgets">
  <a href="https://wa.me/<?=preg_replace('~\D~','',$phoneHref)?>" target="_blank">💬 <?=$t['whatsapp']?></a>
  <a href="https://t.me/<?=urlencode('manikuurEE')?>" target="_blank">✈️ <?=$t['telegram']?></a>
</div>

<script>
  // init date = today
  const d = document.getElementById('slotsDate');
  const dur = document.getElementById('slotsDur');
  const slotsBox = document.getElementById('slots');
  function fmtDate(dt){ const z=n=>String(n).padStart(2,'0'); return dt.getFullYear()+'-'+z(dt.getMonth()+1)+'-'+z(dt.getDate()); }
  d.value = fmtDate(new Date());

  async function loadSlots(){
    slotsBox.innerHTML = '<span class="muted"><?=h($t['slots_loading'])?></span>';
    const url = `?action=slots&date=${encodeURIComponent(d.value)}&duration=${encodeURIComponent(dur.value)}`;
    try{
      const r = await fetch(url, {cache:'no-store'});
      const js = await r.json();
      slotsBox.innerHTML = '';
      if (!js.slots || !js.slots.length){
        slotsBox.innerHTML = '<span class="muted"><?=h($t['slots_none'])?></span>';
        return;
      }
      js.slots.slice(0,16).forEach(s=>{
        const a = document.createElement('a');
        a.className = 'slot';
        a.href = `/booking/?start=${encodeURIComponent(js.date)}T${encodeURIComponent(s.s)}&dur=${encodeURIComponent(<?=json_encode( (int)($_GET['duration'] ?? 60) )?>)}&lang=<?=h($lang)?>`;
        a.textContent = `${s.s}–${s.e}`;
        slotsBox.appendChild(a);
      });
    }catch(e){
      slotsBox.innerHTML = '<span class="muted">…</span>';
    }
  }
  d.addEventListener('change', loadSlots);
  dur.addEventListener('change', loadSlots);
  loadSlots();
</script>
</body>
</html>