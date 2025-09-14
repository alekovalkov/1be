<?php
declare(strict_types=1);
/**
 * ЛК мастера → Настройки цен и времени для квиза.
 * Мастер редактирует ТОЛЬКО свои значения (staff_id текущего юзера).
 * Баллы считаются автоматически: 1 € = 1 балл.
 */

require __DIR__ . '/_bootstrap.php';
$u  = require_staff_auth();
$db = db();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

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

/* =================== ПОИСК quiz_config.json =================== */
function find_quiz_config_path(): string {
  $cands = [
    // если лежит рядом с quiz.php в корне сайта:
    $_SERVER['DOCUMENT_ROOT'] . '/quiz_config.json',
    // если страница реально booking/staff/…
    realpath(__DIR__ . '/../../quiz_config.json') ?: (__DIR__ . '/../../quiz_config.json'),
    // если страница реально /staff/…
    realpath(__DIR__ . '/../quiz_config.json') ?: (__DIR__ . '/../quiz_config.json'),
    // ещё один шаг вверх на всякий
    realpath(__DIR__ . '/../../../quiz_config.json') ?: (__DIR__ . '/../../../quiz_config.json'),
  ];
  foreach ($cands as $p) {
    if ($p && @is_file($p)) return $p;
  }
  return '';
}
$CONFIG = find_quiz_config_path();
if ($CONFIG === '') {
  http_response_code(500);
  echo "quiz_config.json не найден. Попробовал:\n<pre>"
     . h($_SERVER['DOCUMENT_ROOT'] . '/quiz_config.json') . "\n"
     . h(__DIR__ . '/../../quiz_config.json') . "\n"
     . h(__DIR__ . '/../quiz_config.json') . "\n"
     . h(__DIR__ . '/../../../quiz_config.json') . "\n</pre>";
  exit;
}
$config = json_decode((string)file_get_contents($CONFIG), true);
if (!is_array($config)) {
  http_response_code(500);
  echo "Ошибка чтения quiz_config.json: " . json_last_error_msg();
  exit;
}

/* =================== СПИСОК AREAS + ШАГИ =================== */
$areas = [];
if (!isset($config['areas']) || !is_array($config['areas']) || !$config['areas']) {
  if (isset($config['manicure'])) $areas['manicure'] = ['title'=>'MANICURE'];
  if (isset($config['pedicure'])) $areas['pedicure'] = ['title'=>'PEDICURE'];
} else {
  $areas = $config['areas'];
}

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

/* =================== ЗАГРУЗКА ТЕКУЩИХ ОВЕРРАЙДОВ =================== */
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

$ok=''; $err='';

/* =================== СОХРАНЕНИЕ =================== */
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
          $p = (isset($vals['price']) && $vals['price']!=='') ? (int)$vals['price'] : null;
          $d = (isset($vals['dur'])   && $vals['dur']  !=='') ? (int)$vals['dur']   : null;
          $pts = $p; // 1€ = 1 балл
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
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Мои цены и время — квиз</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root{
    --bg:#f8fafc; --card:#ffffff; --line:#e5e7eb; --muted:#64748b; --text:#0f172a; --pri:#111827;
  }
  *{box-sizing:border-box}
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:var(--bg);color:var(--text)}
  .top{display:flex;gap:10px;align-items:center;justify-content:space-between;padding:14px 16px;background:#fff;border-bottom:1px solid var(--line)}
  .wrap{max-width:1100px;margin:0 auto;padding:16px}
  .btn{display:inline-block;background:var(--pri);color:#fff;border:1px solid var(--pri);padding:8px 12px;border-radius:10px;text-decoration:none;cursor:pointer}
  .btn.sec{background:#fff;color:var(--pri)}
  .msg{border-radius:10px;padding:10px;margin:10px 0}
  .ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
  .err{background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d}
  .panel{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;margin-bottom:16px}
  h1{margin:0}
  h2{margin:0 0 6px}
  .grid{display:grid;grid-template-columns:1fr;gap:12px}
  .step{border:1px dashed var(--line);border-radius:12px;padding:10px;background:#fcfcff}
  .step h3{margin:4px 0 10px;font-size:16px}
  .opt{display:grid;grid-template-columns:1fr 120px 120px;gap:8px;align-items:center}
  .opt + .opt{margin-top:6px}
  .opt input{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px}
  .title{font-weight:600}
  .tag{display:inline-block;padding:2px 8px;border:1px solid var(--line);border-radius:999px;background:#f1f5f9;font-size:12px;margin-left:6px}
  .muted{color:var(--muted)}
  @media(max-width:720px){ .opt{grid-template-columns:1fr 1fr; } }
</style>
</head>
<body>
  <div class="top">
    <div><strong>ЛК мастера</strong> — мои цены и время</div>
    <div style="display:flex;gap:8px">
      <a class="btn sec" href="/booking/staff/index.php">← К расписанию</a>
      <a class="btn sec" href="/staff/logout.php">Выйти</a>
    </div>
  </div>

  <div class="wrap">
    <?php if($ok): ?><div class="msg ok"><?=h($ok)?></div><?php endif; ?>
    <?php if($err): ?><div class="msg err"><?=h($err)?></div><?php endif; ?>

    <div class="panel">
      <div class="muted">Ваши изменения применяются сразу. Баллы считаются автоматически: <b>1 € = 1 балл</b>.<br>Пустые поля = «брать из общего конфига».</div>
    </div>

    <form method="post">
      <?php foreach ($areas as $ak=>$aCfg): ?>
        <div class="panel">
          <h2><?= h($aCfg['title'] ?? strtoupper($ak)) ?> <span class="tag"><?= h($ak) ?></span></h2>
          <div class="grid">
            <?php
              $steps = $config[$ak]['steps'] ?? [];
              foreach ($steps as $step) {
                if (empty($step['enabled'])) continue;
                $sk = $step['key'] ?? '';
                if (!$sk) continue;
                $nodeKey = $sk === 'oldCover' ? 'oldCover' : ($sk === 'cover' ? 'cover' : $sk);
                $node = $config[$ak][$nodeKey] ?? ['options'=>[]];

                echo '<div class="step">';
                echo '<h3>'.h($sk).'</h3>';
                if (empty($node['options'])) {
                  echo '<div class="muted">Нет опций.</div>';
                } else {
                  foreach ($node['options'] as $optId=>$opt) {
                    $title = $opt['text'] ?? $optId;
                    if (is_array($title)) {
                      $title = $title['ru'] ?? ($title['et'] ?? ($title['en'] ?? $optId));
                    }
                    $row = $ov[$ak][$sk][$optId] ?? ['p'=>null,'d'=>null];
                    echo '<div class="opt">';
                    echo   '<div class="title">'.h((string)$title).'<span class="tag">'.h($optId).'</span></div>';
                    echo   '<input type="number" name="data['.h($ak).']['.h($sk).']['.h($optId).'][price]" placeholder="Цена €" value="'.h($row['p']??'').'" min="0" step="1">';
                    echo   '<input type="number" name="data['.h($ak).']['.h($sk).']['.h($optId).'][dur]"   placeholder="Время, мин" value="'.h($row['d']??'').'" min="0" step="5">';
                    echo '</div>';
                  }
                }
                echo '</div>';
              }
            ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div>
        <button class="btn" type="submit">Сохранить изменения</button>
        <span class="muted" style="margin-left:10px">Оставьте поле пустым, чтобы использовать «общие» значения.</span>
      </div>
    </form>
  </div>
</body>
</html>