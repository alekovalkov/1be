<?php
declare(strict_types=1);
/**
 * staff/quiz_pricing.php
 * ЛК мастера — цены/время для квиза (ползунки), с цветными зонами 35/70%,
 * динамическими рекомендациями и опцией «не выполняю». Пустое значение = общий конфиг.
 */

require __DIR__ . '/_bootstrap.php';
$u  = require_staff_auth();
$db = db();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

/* =================== АВТО-МИГРАЦИЯ ТАБЛИЦЫ =================== */
$db->exec("
CREATE TABLE IF NOT EXISTS quiz_option_overrides (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  area_key   VARCHAR(50)  NOT NULL,
  step_key   VARCHAR(50)  NOT NULL,
  option_id  VARCHAR(100) NOT NULL,
  staff_id   INT NOT NULL DEFAULT 0,
  price_eur    INT NULL,
  duration_min INT NULL,
  points       INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qoo (area_key, step_key, option_id, staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
try { $db->exec("ALTER TABLE quiz_option_overrides ADD COLUMN disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER points"); } catch(Throwable $e){}

/* =================== ПОИСК quiz_config.json =================== */
function find_quiz_config_path(): string {
  $cands = [
    $_SERVER['DOCUMENT_ROOT'] . '/quiz_config.json',
    realpath(__DIR__ . '/../../quiz_config.json') ?: (__DIR__ . '/../../quiz_config.json'),
    realpath(__DIR__ . '/../quiz_config.json') ?: (__DIR__ . '/../quiz_config.json'),
    realpath(__DIR__ . '/../../../quiz_config.json') ?: (__DIR__ . '/../../../quiz_config.json'),
  ];
  foreach ($cands as $p) if ($p && @is_file($p)) return $p;
  return '';
}
$CONFIG = find_quiz_config_path();
if ($CONFIG === '') { http_response_code(500); echo 'quiz_config.json не найден'; exit; }
$config = json_decode((string)file_get_contents($CONFIG), true);
if (!is_array($config)) { http_response_code(500); echo 'Ошибка чтения quiz_config.json: '.json_last_error_msg(); exit; }

/* =================== AREAS + STEPS =================== */
$areas = [];
if (!isset($config['areas']) || !is_array($config['areas']) || !$config['areas']) {
  if (isset($config['manicure'])) $areas['manicure'] = ['title'=>'MANICURE'];
  if (isset($config['pedicure'])) $areas['pedicure'] = ['title'=>'PEDICURE'];
} else { $areas = $config['areas']; }

function ensure_default_steps(array &$cfg, string $areaKey): void {
  if (!isset($cfg[$areaKey]['steps']) || !is_array($cfg[$areaKey]['steps']) || !$cfg[$areaKey]['steps']) {
    $cfg[$areaKey]['steps'] = [
      ['key'=>'oldCover','enabled'=>1,'order'=>1],
      ['key'=>'service','enabled'=>1,'order'=>2],
      ['key'=>'cover','enabled'=>1,'order'=>3,'show_if_service_in'=>['manicure_cover','pedicure_cover']],
      ['key'=>'length','enabled'=>1,'order'=>4,'show_if_service_in'=>['extensions_new','extensions_correction']],
      ['key'=>'design','enabled'=>1,'order'=>5,'hide_if_service_in'=>['classic']],
      ['key'=>'spa','enabled'=>1,'order'=>6],
    ];
  }
  foreach ($cfg[$areaKey]['steps'] as &$s){
    $s['enabled'] = isset($s['enabled']) ? (int)$s['enabled'] : 1;
    $s['order']   = isset($s['order'])   ? (int)$s['order']   : 0;
  } unset($s);
  usort($cfg[$areaKey]['steps'], fn($a,$b)=>($a['order']<=>$b['order']) ?: strcmp($a['key'],$b['key']));
}
foreach (array_keys($areas) as $ak) ensure_default_steps($config,$ak);

/* =================== ОВЕРРАЙДЫ МАСТЕРА =================== */
$staffId = (int)$u['staff_id'];
$stSel = $db->prepare("SELECT area_key, step_key, option_id, price_eur, duration_min, disabled
                       FROM quiz_option_overrides WHERE staff_id = :sid");
$stSel->execute([':sid'=>$staffId]);
$ov = [];
while ($r = $stSel->fetch(PDO::FETCH_ASSOC)) {
  $ak=$r['area_key']; $sk=$r['step_key']; $oi=$r['option_id'];
  $ov[$ak][$sk][$oi] = [
    'p'   => isset($r['price_eur'])    ? (int)$r['price_eur']    : null,
    'd'   => isset($r['duration_min']) ? (int)$r['duration_min'] : null,
    'off' => isset($r['disabled'])     ? (int)$r['disabled']     : 0,
  ];
}

/* =================== СОХРАНЕНИЕ =================== */
$ok=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $data = $_POST['data'] ?? [];
    $ins = $db->prepare(
      "INSERT INTO quiz_option_overrides
        (area_key, step_key, option_id, staff_id, price_eur, duration_min, points, disabled)
       VALUES (:ak,:sk,:oi,:sid,:p,:d,:pts,:off)
       ON DUPLICATE KEY UPDATE
         price_eur=VALUES(price_eur),
         duration_min=VALUES(duration_min),
         points=VALUES(points),
         disabled=VALUES(disabled)"
    );

    foreach ($data as $ak=>$stepsArr) {
      foreach ($stepsArr as $sk=>$optsArr) {
        foreach ($optsArr as $oi=>$vals) {
          $p   = (isset($vals['price']) && $vals['price']!=='') ? (int)$vals['price'] : null;
          $d   = (isset($vals['dur'])   && $vals['dur']  !=='') ? (int)$vals['dur']   : null;
          $off = !empty($vals['off']) ? 1 : 0;
          $pts = $p; // 1 € = 1 балл
          $ins->execute([':ak'=>$ak,':sk'=>$sk,':oi'=>$oi,':sid'=>$staffId,':p'=>$p,':d'=>$d,':pts'=>$pts,':off'=>$off]);
        }
      }
    }

    $stSel->execute([':sid'=>$staffId]);
    $ov = [];
    while ($r = $stSel->fetch(PDO::FETCH_ASSOC)) {
      $ak=$r['area_key']; $sk=$r['step_key']; $oi=$r['option_id'];
      $ov[$ak][$sk][$oi] = [
        'p'=> isset($r['price_eur']) ? (int)$r['price_eur'] : null,
        'd'=> isset($r['duration_min']) ? (int)$r['duration_min'] : null,
        'off'=> isset($r['disabled']) ? (int)$r['disabled'] : 0,
      ];
    }
    $ok = 'Сохранено.';
  } catch(Throwable $e) { $err = $e->getMessage(); }
}

/* =================== HELPERS =================== */
function i18n_title($meta){
  if (is_array($meta['title'] ?? null)) {
    foreach (['ru','et','en'] as $k) if (!empty($meta['title'][$k])) return (string)$meta['title'][$k];
  }
  return (string)($meta['title'] ?? '');
}
function limits_for(array $opt): array {
  $l = $opt['limits'] ?? [];
  $pmin = isset($l['price_min']) && $l['price_min']!=='' ? (float)$l['price_min'] : null;
  $pmax = isset($l['price_max']) && $l['price_max']!=='' ? (float)$l['price_max'] : null;
  $dmin = isset($l['dur_min'])   && $l['dur_min']  !=='' ? (int)$l['dur_min']   : null;
  $dmax = isset($l['dur_max'])   && $l['dur_max']  !=='' ? (int)$l['dur_max']   : null;
  if ($pmin===null) $pmin = 0;
  if ($pmax===null) $pmax = 200;
  if ($dmin===null) $dmin = 0;
  if ($dmax===null) $dmax = 240;
  if ($pmax < $pmin) $pmax = $pmin;
  if ($dmax < $dmin) $dmax = $dmin;
  return [(int)$pmin,(int)$pmax,(int)$dmin,(int)$dmax];
}
?>
<!doctype html>
<html lang="ru" data-theme="light">
<head>
<meta charset="utf-8">
<title>Мои цены и время — квиз</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root{
    --bg:#f6f8fb; --card:#ffffff; --line:#e5e7eb; --muted:#6b7280; --text:#0f172a; --pri:#111827;
    --ok:#16a34a; --mid:#f59e0b; --pro:#ef4444;
    --pill-bg:#111827; --pill-text:#fff;
  }
  [data-theme="dark"]{
    --bg:#0b1020; --card:#10172b; --line:#1f2a44; --muted:#9fb2ff; --text:#eaf0ff; --pri:#eaf0ff;
    --ok:#22c55e; --mid:#f59e0b; --pro:#ef4444;
    --pill-bg:#0b1120; --pill-text:#eaf0ff;
  }
  *{box-sizing:border-box}
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:var(--bg);color:var(--text)}
  .top{display:flex;gap:10px;align-items:center;justify-content:space-between;padding:14px 16px;background:var(--card);border-bottom:1px solid var(--line)}
  .wrap{max-width:1100px;margin:0 auto;padding:16px}
  .btn{display:inline-block;background:var(--pri);color:var(--pill-text);border:1px solid var(--pri);padding:8px 12px;border-radius:10px;text-decoration:none;cursor:pointer}
  .btn.sec{background:transparent;color:var(--pri)}
  .msg{border-radius:10px;padding:10px;margin:10px 0}
  .ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
  .err{background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d}
  .panel{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;margin-bottom:16px}
  h1{margin:0}
  h2{margin:0 0 6px}
  .muted{color:var(--muted)}
  .area{border:1px dashed var(--line);border-radius:12px;padding:12px;margin:12px 0;background:transparent}
  .opt{display:grid;grid-template-columns:1fr 1fr 160px;gap:12px;align-items:start;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px;margin:12px 0}
  .opt.disabled{opacity:.55}
  .title{font-weight:600}
  .tag{display:inline-block;padding:2px 8px;border:1px solid var(--line);border-radius:999px;background:#f1f5f9;font-size:12px;margin-left:6px;color:#111}
  [data-theme="dark"] .tag{background:#0e1430b3;color:#eaf0ff;border-color:#223056}
  .slider{padding:8px 0}
  .range{width:100%}
  .row{display:flex;gap:14px;align-items:center;justify-content:space-between}
  .val{min-width:58px;text-align:center;border-radius:999px;background:var(--pill-bg);color:var(--pill-text);padding:4px 8px;font-weight:700}
  .hint{font-size:12px;color:var(--muted);margin-top:4px}
  .reco{font-size:13px;margin-top:6px}
  .reco.ok{color:var(--ok)} .reco.mid{color:var(--mid)} .reco.pro{color:var(--pro)}
  .clean{font-size:13px;color:#2563eb;text-decoration:none;margin-left:8px}
  .right{justify-content:flex-end}
  @media(max-width:980px){ .opt{grid-template-columns:1fr} .right{justify-content:flex-start} }
  .bar{height:6px;border-radius:999px;background:
      linear-gradient(90deg,var(--ok) 0 35%,var(--mid) 35% 70%,var(--pro) 70% 100%);opacity:.5;margin-top:6px}
  /* switch */
  .switch{display:inline-flex;align-items:center;gap:8px}
  .sw{position:relative;width:46px;height:26px;background:#e5e7eb;border-radius:999px;cursor:pointer;border:1px solid var(--line)}
  .sw::after{content:'';position:absolute;top:2px;left:2px;width:22px;height:22px;border-radius:50%;background:#fff;transition:transform .18s ease}
  [data-theme="dark"] .sw{background:#223056}
  [data-theme="dark"] .sw::after{transform:translateX(20px);background:#0b1120}
</style>
</head>
<body>
  <div class="top">
    <div style="display:flex;align-items:center;gap:10px">
      <strong>Мои цены и время — квиз</strong>
      <span class="tag">Мастер (ID <?= (int)$staffId ?>)</span>
    </div>
    <div style="display:flex;gap:10px;align-items:center">
      <div class="switch"><span class="muted">Тема</span><div id="themeSw" class="sw" role="button" aria-label="Переключить тему"></div></div>
      <a class="btn sec" href="/booking/staff/index.php">← К расписанию</a>
      <a class="btn sec" href="/staff/logout.php">Выйти</a>
    </div>
  </div>

  <div class="wrap">
    <div class="panel muted">
      Двигая ползунки, вы задаёте свои значения в рамках ограничений владельца. Пустой ползунок = «брать из общего конфига».
      Баллы считаются автоматически: <b>1 € = 1 балл</b>.
    </div>

    <?php if($ok): ?><div class="msg ok"><?=h($ok)?></div><?php endif; ?>
    <?php if($err): ?><div class="msg err"><?=h($err)?></div><?php endif; ?>

    <form method="post" id="form">
      <?php foreach ($areas as $ak=>$aMeta): ?>
        <div class="panel area">
          <h2><?= h(i18n_title($aMeta) ?: strtoupper($ak)) ?> <span class="tag"><?= h($ak) ?></span></h2>

          <?php foreach (($config[$ak]['steps'] ?? []) as $step): if(empty($step['enabled'])) continue;
            $sk = $step['key'] ?? ''; if(!$sk) continue;
            $nodeKey = $sk === 'oldCover' ? 'oldCover' : ($sk === 'cover' ? 'cover' : $sk);
            $node = $config[$ak][$nodeKey] ?? ['options'=>[]];
            if (empty($node['options'])) continue;
          ?>
            <div class="muted" style="margin:8px 0 2px"><?= h($sk) ?></div>

            <?php foreach ($node['options'] as $oi=>$opt):
              $title = $opt['text'] ?? $oi;
              if (is_array($title)) { $title = $title['ru'] ?? ($title['et'] ?? ($title['en'] ?? $oi)); }
              [$pmin,$pmax,$dmin,$dmax] = limits_for($opt);
              $row = $ov[$ak][$sk][$oi] ?? ['p'=>null,'d'=>null,'off'=>0];
              $pVal = $row['p']; $dVal = $row['d']; $off = !empty($row['off']);
            ?>
              <div class="opt <?= $off?'disabled':'' ?>" data-ak="<?=h($ak)?>" data-sk="<?=h($sk)?>" data-oi="<?=h($oi)?>" data-pmin="<?=$pmin?>" data-pmax="<?=$pmax?>">
                <div class="title">
                  <?= h((string)$title) ?> <span class="tag"><?= h($oi) ?></span>
                </div>

                <div class="slider">
                  <div class="row">
                    <div>Цена (€)</div>
                    <div class="val" data-val="price"><?= $pVal===null ? '—' : (int)$pVal ?>€</div>
                  </div>
                  <input class="range price" type="range" min="<?= $pmin ?>" max="<?= $pmax ?>" step="1"
                         value="<?= $pVal===null ? $pmin : (int)$pVal ?>"
                         data-clean="<?= $pVal===null ? '1':'0' ?>">
                  <div class="bar"></div>
                  <div class="reco" data-reco></div>
                  <div class="hint">Диапазон: <?= $pmin ?>–<?= $pmax ?> € <a href="#" class="clean" data-clean-price>🧹 очистить</a></div>
                </div>

                <div class="slider">
                  <div class="row">
                    <div>Время (мин)</div>
                    <div class="val" data-val="dur"><?= $dVal===null ? '—' : (int)$dVal ?> мин</div>
                  </div>
                  <input class="range dur" type="range" min="<?= $dmin ?>" max="<?= $dmax ?>" step="5"
                         value="<?= $dVal===null ? $dmin : (int)$dVal ?>"
                         data-clean="<?= $dVal===null ? '1':'0' ?>">
                  <div class="bar"></div>
                  <div class="hint">Диапазон: <?= $dmin ?>–<?= $dmax ?> мин <a href="#" class="clean" data-clean-dur>🧹 очистить</a></div>
                </div>

                <div class="row right">
                  <label class="muted"><input type="checkbox" class="off" name="data[<?=h($ak)?>][<?=h($sk)?>][<?=h($oi)?>][off]" value="1" <?= $off?'checked':'' ?>> Не выполняю</label>
                </div>

                <!-- реальные поля -->
                <input type="hidden" class="price-hidden" name="data[<?=h($ak)?>][<?=h($sk)?>][<?=h($oi)?>][price]" value="<?= $pVal===null ? '' : (int)$pVal ?>">
                <input type="hidden" class="dur-hidden"   name="data[<?=h($ak)?>][<?=h($sk)?>][<?=h($oi)?>][dur]"   value="<?= $dVal===null ? '' : (int)$dVal ?>">
              </div>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>

      <div class="panel" style="text-align:right">
        <button class="btn" type="submit">Сохранить</button>
      </div>
    </form>
  </div>

<script>
  // Тема
  (function(){
    const root=document.documentElement;
    const key='qp_theme';
    function apply(v){ root.setAttribute('data-theme', v==='dark'?'dark':'light'); }
    apply(localStorage.getItem(key)||'light');
    document.getElementById('themeSw').addEventListener('click', ()=>{
      const cur=root.getAttribute('data-theme')==='dark'?'dark':'light';
      const next=cur==='dark'?'light':'dark';
      localStorage.setItem(key,next); apply(next);
    });
  })();

  // Рекомендации по цене (текст + цвет) и управление «пусто/очистить»
  function recompute(opt){
    const pInp = opt.querySelector('.range.price');
    const pHidden = opt.querySelector('.price-hidden');
    const valBadge = opt.querySelector('.val[data-val="price"]');
    const reco = opt.querySelector('[data-reco]');
    const pmin = parseInt(opt.dataset.pmin,10);
    const pmax = parseInt(opt.dataset.pmax,10);
    const v = parseInt(pInp.value,10);
    const pct = (pmax>pmin)? ((v-pmin)*100/(pmax-pmin)) : 0;

    // текст
    let cls='ok', txt='Очень хорошая цена — рекомендуем новичкам и для набора базы. Вероятность выбора высокая.';
    if (pct >= 35 && pct < 70){ cls='mid'; txt='Средняя цена по Таллину. Хотите работать почти без «окон» — не завышайте выше этой зоны.'; }
    if (pct >= 70){ cls='pro'; txt='Профессиональный уровень. Ставьте так, если у вас записи на 5+ дней вперёд и стабильная база.'; }

    reco.className='reco '+cls;
    reco.textContent=txt;

    // бейдж и hidden
    if (pInp.dataset.clean==='1'){ valBadge.textContent='—'; pHidden.value=''; }
    else { valBadge.textContent=v+'€'; pHidden.value=v; }
  }
  function recomputeDur(opt){
    const dInp = opt.querySelector('.range.dur');
    const dHidden = opt.querySelector('.dur-hidden');
    const valBadge = opt.querySelector('.val[data-val="dur"]');
    const v = parseInt(dInp.value,10);
    if (dInp.dataset.clean==='1'){ valBadge.textContent='—'; dHidden.value=''; }
    else { valBadge.textContent=v+' мин'; dHidden.value=v; }
  }
  function setDisabledUI(opt, off){
    opt.classList.toggle('disabled', off);
    opt.querySelectorAll('input.range, a.clean').forEach(el=>{
      el.toggleAttribute('disabled', off);
      if (off) el.setAttribute('tabindex','-1'); else el.removeAttribute('tabindex');
    });
  }

  document.querySelectorAll('.opt').forEach(opt=>{
    const price = opt.querySelector('.range.price');
    const dur   = opt.querySelector('.range.dur');
    // начальный пересчёт
    recompute(opt); recomputeDur(opt);

    price.addEventListener('input', ()=>{ price.dataset.clean='0'; recompute(opt); });
    dur.addEventListener('input', ()=>{ dur.dataset.clean='0'; recomputeDur(opt); });

    opt.querySelector('[data-clean-price]').addEventListener('click', (e)=>{ e.preventDefault(); price.dataset.clean='1'; recompute(opt); });
    opt.querySelector('[data-clean-dur]').addEventListener('click',   (e)=>{ e.preventDefault(); dur.dataset.clean='1';   recomputeDur(opt); });

    const off = opt.querySelector('.off');
    setDisabledUI(opt, off.checked);
    off.addEventListener('change', ()=>{ setDisabledUI(opt, off.checked); });
  });
</script>
</body>
</html>