<?php
declare(strict_types=1);
/**
 * ЛК мастера → Мои цены и время (квиз).
 * - Светлая/тёмная тема (toggle, localStorage)
 * - Слайдеры с подсказками и «баблами» значений
 * - Ограничения берём из quiz_config.json на уровне опций:
 *      price_min_master, price_max_master,
 *      duration_min_master, duration_max_master
 *   (если нет — fallback на базовые значения и дефолтные коридоры)
 * - Баллы: 1 € = 1 балл (авто)
 */

require __DIR__ . '/_bootstrap.php';
$u  = require_staff_auth();
$db = db();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

/* =================== АВТО-МИГРАЦИЯ =================== */
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

/* =================== ЧТЕНИЕ quiz_config.json =================== */
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

/* areas + steps (на случай пустого steps в конфиге) */
$areas = [];
if (!isset($config['areas']) || !is_array($config['areas']) || !$config['areas']) {
  if (isset($config['manicure'])) $areas['manicure'] = ['title'=>'MANICURE'];
  if (isset($config['pedicure'])) $areas['pedicure'] = ['title'=>'PEDICURE'];
} else { $areas = $config['areas']; }

$ALLOWED_STEP_KEYS = ['oldCover','service','cover','length','design','spa'];
function ensure_default_steps(array &$cfg, string $areaKey, array $allowed): void {
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
    if (empty($s['key']) || !in_array($s['key'],$allowed,true)) $s['key']='service';
  } unset($s);
  usort($cfg[$areaKey]['steps'], fn($a,$b)=>($a['order']<=>$b['order']) ?: strcmp($a['key'],$b['key']));
}
foreach (array_keys($areas) as $ak) ensure_default_steps($config, $ak, $ALLOWED_STEP_KEYS);

/* =================== ОВЕРРАЙДЫ ТЕКУЩЕГО МАСТЕРА =================== */
$staffId = (int)$u['staff_id'];
$stSel = $db->prepare("SELECT area_key, step_key, option_id, price_eur, duration_min, points
                       FROM quiz_option_overrides WHERE staff_id = :sid");
$stSel->execute([':sid'=>$staffId]);
$ov = []; // [$ak][$sk][$opt] = ['p'=>..,'d'=>..,'pts'=>..]
while ($r = $stSel->fetch(PDO::FETCH_ASSOC)) {
  $ak=$r['area_key']; $sk=$r['step_key']; $oi=$r['option_id'];
  $ov[$ak][$sk][$oi] = [
    'p'   => isset($r['price_eur'])    ? (int)$r['price_eur']    : null,
    'd'   => isset($r['duration_min']) ? (int)$r['duration_min'] : null,
    'pts' => array_key_exists('points',$r) && $r['points']!==null ? (int)$r['points'] : null,
  ];
}

/* =================== СОХРАНЕНИЕ =================== */
$ok=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $data = $_POST['data'] ?? [];
    $ins = $db->prepare(
      "INSERT INTO quiz_option_overrides
        (area_key, step_key, option_id, staff_id, price_eur, duration_min, points)
       VALUES (:ak,:sk,:oi,:sid,:p,:d,:pts)
       ON DUPLICATE KEY UPDATE
         price_eur=VALUES(price_eur),
         duration_min=VALUES(duration_min),
         points=VALUES(points)"
    );
    foreach ($data as $ak=>$stepsArr) {
      foreach ($stepsArr as $sk=>$optsArr) {
        foreach ($optsArr as $oi=>$vals) {
          $p = (isset($vals['price']) && $vals['price']!=='') ? max(0,(int)$vals['price']) : null;
          $d = (isset($vals['dur'])   && $vals['dur']  !=='') ? max(0,(int)$vals['dur'])   : null;
          $pts = ($p===null ? null : $p); // 1€ = 1 балл
          $ins->execute([':ak'=>$ak,':sk'=>$sk,':oi'=>$oi,':sid'=>$staffId,':p'=>$p,':d'=>$d,':pts'=>$pts]);
        }
      }
    }
    // перечитать
    $stSel->execute([':sid'=>$staffId]);
    $ov = [];
    while ($r = $stSel->fetch(PDO::FETCH_ASSOC)) {
      $ak=$r['area_key']; $sk=$r['step_key']; $oi=$r['option_id'];
      $ov[$ak][$sk][$oi] = [
        'p'=> isset($r['price_eur']) ? (int)$r['price_eur'] : null,
        'd'=> isset($r['duration_min']) ? (int)$r['duration_min'] : null,
        'pts'=> array_key_exists('points',$r) && $r['points']!==null ? (int)$r['points'] : null,
      ];
    }
    $ok = 'Сохранено.';
  } catch(Throwable $e) {
    $err = $e->getMessage();
  }
}

/* =================== HELPERS (лимиты/дефолты) =================== */
function base_title($opt, string $fallback){
  if (!is_array($opt)) return $fallback;
  $t = $opt['text'] ?? $fallback;
  if (is_array($t)) return ($t['ru'] ?? ($t['et'] ?? ($t['en'] ?? $fallback)));
  return (string)$t;
}
function base_price($opt): int {
  if (!is_array($opt)) return 0;
  if (isset($opt['price']) && $opt['price']!=='') return (int)$opt['price'];
  if (isset($opt['base_price'])) return (int)$opt['base_price'];
  if (isset($opt['price_add']))  return (int)$opt['price_add'];
  return 0;
}
function base_dur($opt): int {
  if (!is_array($opt)) return 0;
  if (isset($opt['duration_min']) && $opt['duration_min']!=='') return (int)$opt['duration_min'];
  if (isset($opt['duration_add']) && $opt['duration_add']!=='') return (int)$opt['duration_add'];
  return 0;
}
function limit_price_min($opt): int {
  if (isset($opt['price_min_master']) && $opt['price_min_master']!=='') return max(0,(int)$opt['price_min_master']);
  $bp = base_price($opt);
  return max(0, min($bp, 200)); // «разумный» минимум
}
function limit_price_max($opt): int {
  if (isset($opt['price_max_master']) && $opt['price_max_master']!=='') return max(limit_price_min($opt),(int)$opt['price_max_master']);
  $bp = base_price($opt);
  return max($bp, 200); // верх — хотя бы 200 или базовая
}
function limit_dur_min($opt): int {
  if (isset($opt['duration_min_master']) && $opt['duration_min_master']!=='') return max(0,(int)$opt['duration_min_master']);
  $bd = base_dur($opt);
  return max(0, min($bd, 240));
}
function limit_dur_max($opt): int {
  if (isset($opt['duration_max_master']) && $opt['duration_max_master']!=='') return max(limit_dur_min($opt),(int)$opt['duration_max_master']);
  $bd = base_dur($opt);
  return max($bd, 240);
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
    --bg:#f7f8fb; --card:#ffffff; --line:#e5e7eb; --text:#0f172a; --muted:#64748b; --accent:#5b5bd6; --chip:#eef2ff;
    --btn:#111827; --btn-text:#ffffff; --range:#d1d5db; --range-fill:#5b5bd6;
  }
  [data-theme="dark"]{
    --bg:#0b1020; --card:#0f172a; --line:#1f2937; --text:#e5e7eb; --muted:#94a3b8; --accent:#7c7cff; --chip:#111827;
    --btn:#e5e7eb; --btn-text:#0b1020; --range:#334155; --range-fill:#7c7cff;
  }

  *{box-sizing:border-box}
  html,body{height:100%}
  body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text)}
  a{color:var(--accent)}
  .top{position:sticky;top:0;z-index:10;display:flex;gap:12px;align-items:center;justify-content:space-between;padding:12px 16px;background:var(--card);border-bottom:1px solid var(--line)}
  .top .group{display:flex;gap:8px;align-items:center}
  .btn{display:inline-flex;gap:8px;align-items:center;background:var(--btn);color:var(--btn-text);border:1px solid var(--btn);padding:8px 12px;border-radius:10px;text-decoration:none;cursor:pointer}
  .btn.ghost{background:transparent;color:var(--text);border-color:var(--line)}
  .wrap{max-width:1100px;margin:0 auto;padding:16px}
  .msg{border-radius:10px;padding:10px;margin:10px 0}
  .ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
  .err{background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d}
  .panel{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;margin-bottom:16px}
  h1{margin:0;font-size:20px}
  h2{margin:0 0 10px;font-size:18px}
  h3{margin:0 0 10px;font-size:15px;color:var(--muted)}
  .area{margin-bottom:8px}
  .chip{display:inline-block;padding:4px 10px;border:1px solid var(--line);border-radius:999px;background:var(--chip);font-size:12px;margin-left:8px}
  .grid{display:grid;grid-template-columns:1fr;gap:14px}
  .step{border:1px dashed var(--line);border-radius:14px;padding:12px}
  .opt{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:center}
  .opt + .opt{margin-top:14px}
  .muted{color:var(--muted)}
  .row{display:grid;grid-template-columns:1fr 180px;gap:12px;align-items:center}
  .bubble{font-variant-numeric:tabular-nums;min-width:80px;text-align:center;background:var(--chip);border:1px solid var(--line);padding:4px 8px;border-radius:999px}
  .sub{font-size:12px;color:var(--muted);margin-top:4px}
  .hint{font-size:12px;margin-top:6px}
  .hint.good{color:#16a34a}
  .hint.mid{color:#f59e0b}
  .hint.high{color:#dc2626}
  .tag{display:inline-block;padding:2px 8px;border:1px solid var(--line);border-radius:999px;background:transparent;font-size:12px;margin-left:6px;color:var(--muted)}
  .savebar{position:sticky;bottom:0;background:var(--card);border-top:1px solid var(--line);padding:12px;display:flex;justify-content:space-between;align-items:center;border-radius:16px 16px 0 0}
  .legend{font-size:12px;color:var(--muted)}
  /* range beautify */
  input[type=range]{appearance:none;height:8px;border-radius:999px;background:var(--range);outline:none}
  input[type=range]::-webkit-slider-thumb{appearance:none;width:22px;height:22px;border-radius:50%;background:var(--btn);border:2px solid var(--card);cursor:pointer;box-shadow:0 0 0 2px var(--btn) inset}
  input[type=range]::-moz-range-thumb{width:22px;height:22px;border-radius:50%;background:var(--btn);border:2px solid var(--card);cursor:pointer}
  .range-wrap{position:relative}
  .range-fill{position:absolute;left:0;top:0;height:8px;border-radius:999px;background:var(--range-fill);pointer-events:none}
  .range-bubble{position:absolute;transform:translate(-50%,-140%);background:var(--btn);color:var(--btn-text);padding:4px 8px;border-radius:8px;font-size:12px;white-space:nowrap}
  .range-bubble::after{content:"";position:absolute;left:50%;bottom:-5px;transform:translateX(-50%);border:6px solid transparent;border-top-color:var(--btn)}
  @media (max-width:800px){ .row{grid-template-columns:1fr} .opt{grid-template-columns:1fr} }
</style>
</head>
<body>
  <div class="top">
    <div class="group">
      <h1>Мои цены и время — квиз</h1>
      <span class="chip"><?=h($u['name'] ?? 'Мастер')?> (ID <?= (int)$staffId ?>)</span>
    </div>
    <div class="group">
      <button class="btn ghost" id="themeToggle" title="Светлая/тёмная тема">🌓 Тема</button>
      <a class="btn ghost" href="/booking/staff/index.php">← К расписанию</a>
      <a class="btn" href="/staff/logout.php">Выйти</a>
    </div>
  </div>

  <div class="wrap">
    <?php if($ok): ?><div class="msg ok"><?=h($ok)?></div><?php endif; ?>
    <?php if($err): ?><div class="msg err"><?=h($err)?></div><?php endif; ?>

    <div class="panel">
      <div class="legend">
        Двигая ползунки, вы задаёте свои значения в рамках ограничений владельца. Пустой ползунок = «брать из общего конфига».
        Баллы считаются автоматически: <b>1 € = 1 балл</b>.
      </div>
    </div>

    <form method="post" id="pricingForm" autocomplete="off">
      <?php foreach ($areas as $ak=>$aCfg): ?>
        <div class="panel area">
          <h2><?= h($aCfg['title'] ?? strtoupper($ak)) ?> <span class="tag"><?= h($ak) ?></span></h2>
          <div class="grid">
          <?php
            $steps = $config[$ak]['steps'] ?? [];
            foreach ($steps as $step) {
              if (empty($step['enabled'])) continue;
              $sk = $step['key'] ?? '';
              if (!$sk) continue;
              $nodeKey = ($sk === 'oldCover') ? 'oldCover' : ($sk === 'cover' ? 'cover' : $sk);
              $node = $config[$ak][$nodeKey] ?? ['options'=>[]];

              echo '<div class="step">';
              echo '<h3>'.h($sk).'</h3>';

              if (empty($node['options'])) {
                echo '<div class="muted">Нет опций.</div>';
              } else {
                foreach ($node['options'] as $optId=>$opt) {
                  $title = base_title($opt, $optId);

                  // лимиты
                  $pMin = limit_price_min($opt);
                  $pMax = max($pMin, limit_price_max($opt));
                  $dMin = limit_dur_min($opt);
                  $dMax = max($dMin, limit_dur_max($opt));

                  // значения по умолчанию для ползунка
                  $cur = $ov[$ak][$sk][$optId] ?? ['p'=>null,'d'=>null];
                  $curP = $cur['p']; $curD = $cur['d'];
                  if ($curP === null) { // выберем «базовую» в коридоре
                    $baseP = base_price($opt);
                    $curP = ($baseP>0) ? min(max($baseP, $pMin), $pMax) : $pMin;
                  }
                  if ($curD === null) {
                    $baseD = base_dur($opt);
                    $curD = ($baseD>0) ? min(max($baseD, $dMin), $dMax) : $dMin;
                  }

                  $nameP = "data[$ak][$sk][$optId][price]";
                  $nameD = "data[$ak][$sk][$optId][dur]";

                  echo '<div class="opt">';

                  echo '<div>';
                    echo '<div class="row">';
                      echo '<div>';
                        echo '<div class="title">'.h($title).'<span class="tag">'.h($optId).'</span></div>';
                        echo '<div class="sub">Цена (€): <span class="bubble" id="b_'.$ak.'_'.$sk.'_'.$optId.'_p">'.(int)$curP.' €</span></div>';
                      echo '</div>';
                      echo '<div class="muted" style="text-align:right">Диапазон: '.$pMin.'–'.$pMax.' €</div>';
                    echo '</div>';

                    echo '<div class="range-wrap" data-min="'.$pMin.'" data-max="'.$pMax.'">';
                      echo '<div class="range-fill" id="f_'.$ak.'_'.$sk.'_'.$optId.'_p"></div>';
                      echo '<input type="range" class="rng price" name="'.h($nameP).'" id="r_'.$ak.'_'.$sk.'_'.$optId.'_p" value="'.(int)$curP.'" min="'.$pMin.'" max="'.$pMax.'" step="1" data-bubble="b_'.$ak.'_'.$sk.'_'.$optId.'_p" data-fill="f_'.$ak.'_'.$sk.'_'.$optId.'_p" data-hint="h_'.$ak.'_'.$sk.'_'.$optId.'_p">';
                      echo '<div class="range-bubble" style="display:none"></div>';
                    echo '</div>';
                    echo '<div class="hint" id="h_'.$ak.'_'.$sk.'_'.$optId.'_p"></div>';
                  echo '</div>';

                  echo '<div>';
                    echo '<div class="row">';
                      echo '<div>';
                        echo '<div class="sub">Время (мин): <span class="bubble" id="b_'.$ak.'_'.$sk.'_'.$optId.'_d">'.(int)$curD.' мин</span></div>';
                      echo '</div>';
                      echo '<div class="muted" style="text-align:right">Диапазон: '.$dMin.'–'.$dMax.' мин</div>';
                    echo '</div>';

                    echo '<div class="range-wrap" data-min="'.$dMin.'" data-max="'.$dMax.'">';
                      echo '<div class="range-fill" id="f_'.$ak.'_'.$sk.'_'.$optId.'_d"></div>';
                      echo '<input type="range" class="rng dur" name="'.h($nameD).'" id="r_'.$ak.'_'.$sk.'_'.$optId.'_d" value="'.(int)$curD.'" min="'.$dMin.'" max="'.$dMax.'" step="5" data-bubble="b_'.$ak.'_'.$sk.'_'.$optId.'_d" data-fill="f_'.$ak.'_'.$sk.'_'.$optId.'_d">';
                      echo '<div class="range-bubble" style="display:none"></div>';
                    echo '</div>';
                  echo '</div>';

                  echo '</div>'; // .opt
                }
              }
              echo '</div>'; // .step
            }
          ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="savebar panel">
        <div class="legend">Подсказка по ценам: до 35% — <b>очень хорошая</b>, 35–70% — <b>средняя</b>, от 70% — <b>уровень профи</b>.</div>
        <button class="btn" type="submit">💾 Сохранить</button>
      </div>
    </form>
  </div>

<script>
/* ===== ТЕМА ===== */
(function(){
  const root = document.documentElement;
  const key = 'qp_theme';
  function apply(t){ root.setAttribute('data-theme', t); }
  const saved = localStorage.getItem(key) || 'light';
  apply(saved);
  document.getElementById('themeToggle').addEventListener('click', ()=>{
    const t = (root.getAttribute('data-theme')==='dark')?'light':'dark';
    apply(t); localStorage.setItem(key, t);
  });
})();

/* ===== RANGE UI ===== */
function pct(val, min, max){
  const p = (val - min) / Math.max(1,(max - min));
  return Math.min(1, Math.max(0, p));
}
function priceHint(el, percent){
  const h = document.getElementById(el.dataset.hint);
  if (!h) return;
  h.classList.remove('good','mid','high');
  if (percent <= 0.35){
    h.textContent = 'Очень хорошая цена (рекомендуется новичкам или для набора базы). Вероятность выбора вас — высокая.';
    h.classList.add('good');
  } else if (percent <= 0.70){
    h.textContent = 'Средняя цена по Таллину. Если хотите работать почти без окон, не завышайте выше этого диапазона.';
    h.classList.add('mid');
  } else {
    h.textContent = 'Ценовая категория профессионалов: ставьте так только при записи на ~5 дней вперёд и наличии своей базы.';
    h.classList.add('high');
  }
}
function updRange(r){
  const wrap = r.closest('.range-wrap');
  const min = Number(r.min), max = Number(r.max), val = Number(r.value);
  const p = pct(val,min,max);
  const bubbleId = r.dataset.bubble;
  const fillId   = r.dataset.fill;
  const bubble   = document.getElementById(bubbleId);
  const fill     = document.getElementById(fillId);

  // bubble text already set outside
  // fill width
  if (fill){ fill.style.width = (p*100) + '%'; }

  // move bubble element over thumb
  const w = r.getBoundingClientRect().width || r.offsetWidth || 300;
  if (bubble){
    bubble.closest('.row'); // nothing; bubble is separate
  }
  // for pretty thumb-bubble on top of track:
  const ghost = wrap.querySelector('.range-bubble');
  if (ghost){
    ghost.style.display = 'block';
    ghost.textContent = r.classList.contains('dur') ? (val + ' мин') : (val + ' €');
    ghost.style.left = (p * w) + 'px';
  }

  if (r.classList.contains('price')){
    priceHint(r, p);
  }
}
function bindRange(r){
  const bubble = document.getElementById(r.dataset.bubble);
  const format = r.classList.contains('dur') ? (v => v+' мин') : (v => v+' €');
  function sync(){
    bubble && (bubble.textContent = format(r.value));
    updRange(r);
  }
  r.addEventListener('input', sync);
  r.addEventListener('change', sync);
  sync();
}
document.querySelectorAll('input.rng').forEach(bindRange);
</script>
</body>
</html>