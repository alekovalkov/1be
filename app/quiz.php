<?php
/* ===== DEBUG ===== */
define('QUIZ_DEBUG', true);
if (QUIZ_DEBUG) {
  error_reporting(E_ALL);
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  ini_set('log_errors', '1');
  ini_set('error_log', __DIR__.'/php-error.log');
  register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
      header('Content-Type: text/html; charset=UTF-8');
      echo "<pre style='white-space:pre-wrap;background:#fff3cd;border:1px solid #ffecb5;padding:10px;border-radius:8px'>";
      echo "FATAL: {$e['message']}\nFile: {$e['file']}:{$e['line']}";
      echo "</pre>";
    }
  });
}

/* ===== iFrame-friendly session (Wix) ===== */
if (PHP_VERSION_ID >= 70300) {
  session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>true,'httponly'=>true,'samesite'=>'None']);
} else {
  ini_set('session.cookie_samesite','None');
  ini_set('session.cookie_secure','1');
  ini_set('session.cookie_httponly','1');
}
session_start();

/* ===== anti-cache ===== */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/* ===== reset ===== */
if (isset($_GET['reset'])) { session_destroy(); header('Location: quiz.php'); exit; }

/* ===== load config ===== */
$CONFIG = __DIR__.'/quiz_config.json';
if (!is_file($CONFIG)) { http_response_code(500); die('quiz_config.json не найден'); }
$config = json_decode(file_get_contents($CONFIG), true);
if (!is_array($config)) { http_response_code(500); die('Ошибка чтения quiz_config.json: '.json_last_error_msg()); }

/* ===== languages ===== */
$LANGS = $config['languages'] ?? ['ru','et','en'];
$lang  = $_GET['lang'] ?? ($_SESSION['lang'] ?? 'ru');
if (!in_array($lang,$LANGS,true)) $lang = $LANGS[0];
$_SESSION['lang'] = $lang;

/* ===== i18n helpers ===== */
function tr($v, $lang='ru', $fallback='ru'){
  if (is_array($v)) {
    if (isset($v[$lang]) && $v[$lang] !== '') return (string)$v[$lang];
    if (isset($v[$fallback]) && $v[$fallback] !== '') return (string)$v[$fallback];
    foreach ($v as $s){ if ((string)$s!=='') return (string)$s; }
    return '';
  }
  return (string)$v;
}
$MSG = [
  'ru'=>[
    'choose_area' => 'Выбор направления',
    'your_choice' => 'Ваш выбор',
    'open_choice' => 'Мой выбор',
    'total_now'   => 'Итого сейчас',
    'points'      => 'Баллы',
    'what_need'   => 'Что вам нужно?',
    'back'        => '← Назад',
    'restart'     => 'Начать заново',
    'summary'     => 'Итог',
    'final_total' => 'Итоговая стоимость',
    'book'        => 'Записаться',
    'pick_time'   => 'Выбрать время',
    'again'       => 'Пройти квиз заново',
    'incl_total'  => 'Итого',
    'plus'        => '+',
    'oldcover'    => 'Что на ногтях сейчас',
    'service'     => 'Услуга',
    'cover'       => 'Покрытие',
    'length'      => 'Длина',
    'design'      => 'Дизайн',
    'spa'         => 'SPA',
  ],
  'et'=>[
    'choose_area' => 'Vali suund',
    'your_choice' => 'Sinu valik',
    'open_choice' => 'Minu valik',
    'total_now'   => 'Kokku praegu',
    'points'      => 'Punktid',
    'what_need'   => 'Mida vajate?',
    'back'        => '← Tagasi',
    'restart'     => 'Alusta uuesti',
    'summary'     => 'Kokkuvõte',
    'final_total' => 'Lõpphind',
    'book'        => 'Broneeri',
    'pick_time'   => 'Vali aeg',
    'again'       => 'Täida uuesti',
    'incl_total'  => 'Kokku',
    'plus'        => '+',
    'oldcover'    => 'Mis on küüntel',
    'service'     => 'Teenus',
    'cover'       => 'Kate',
    'length'      => 'Pikkus',
    'design'      => 'Disain',
    'spa'         => 'SPA',
  ],
  'en'=>[
    'choose_area' => 'Choose category',
    'your_choice' => 'Your selection',
    'open_choice' => 'My selection',
    'total_now'   => 'Total now',
    'points'      => 'Points',
    'what_need'   => 'What do you need?',
    'back'        => '← Back',
    'restart'     => 'Start over',
    'summary'     => 'Summary',
    'final_total' => 'Total price',
    'book'        => 'Book now',
    'pick_time'   => 'Pick a time',
    'again'       => 'Take the quiz again',
    'incl_total'  => 'Total',
    'plus'        => '+',
    'oldcover'    => 'What’s on nails now',
    'service'     => 'Service',
    'cover'       => 'Cover',
    'length'      => 'Length',
    'design'      => 'Design',
    'spa'         => 'SPA',
  ],
];
function t($key, $lang, $MSG){ return $MSG[$lang][$key] ?? $MSG['ru'][$key] ?? $key; }

/* ===== helpers ===== */
$ALLOWED_STEP_KEYS = ['oldCover','service','cover','length','design','spa'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function S($k,$d=null){ return $_SESSION[$k] ?? $d; }
function setS($k,$v){ $_SESSION[$k]=$v; }
function delS(...$keys){ foreach($keys as $k) unset($_SESSION[$k]); }

function normalize_key($s){
  $s = preg_replace('~[^a-z0-9_]+~i','_', (string)$s);
  $s = strtolower(trim($s,'_'));
  return $s;
}

/* ===== areas registry (новая структура + бэкап для старой) ===== */
if (!isset($config['areas']) || !is_array($config['areas']) || !$config['areas']) {
  $config['areas'] = [];
  if (isset($config['manicure'])) $config['areas']['manicure'] = ['title'=>'MANICURE'];
  if (isset($config['pedicure'])) $config['areas']['pedicure'] = ['title'=>'PEDICURE'];
}

function cfg_has_area(array $cfg, string $areaKey): bool {
  return isset($cfg['areas'][$areaKey]) && is_array($cfg['areas'][$areaKey]);
}
function area_list(array $cfg): array { return isset($cfg['areas']) && is_array($cfg['areas']) ? $cfg['areas'] : []; }
function area_title(array $cfg, string $areaKey, $lang='ru'): string {
  $areas = area_list($cfg);
  $raw = $areas[$areaKey]['title'] ?? ucfirst($areaKey);
  return tr($raw,$lang,'ru');
}

/* ===== current flow mode: single | combo ===== */
function areaMode(): string { return S('area_mode',''); }
function currentAreaKey(array $cfg): ?string {
  if (areaMode()==='combo'){
    $combo = S('combo',[]);
    $i = (int)S('combo_i',0);
    return $combo[$i] ?? null;
  }
  $a = S('area',null);
  return ($a && cfg_has_area($cfg,$a)) ? $a : null;
}
function prefix(array $cfg): string {
  $ak = currentAreaKey($cfg) ?: 'area';
  return 'a_'.$ak.'_';
}

/* ===== UI helpers ===== */
function has_img($opt){ return !empty($opt['image']) && empty($opt['hide_image']); }
function has_desc($opt){
  $d = $opt['desc'] ?? '';
  if (is_array($d)) { foreach ($d as $s){ if (trim((string)$s)!=='') return true; } return false; }
  return isset($opt['desc']) && trim((string)$opt['desc'])!==''; }
function desc_html($opt, $lang='ru'){
  $d = tr(($opt['desc'] ?? ''), $lang, 'ru'); if ($d==='') return '';
  return h(str_replace(["\r\n","\r"],"\n",$d));
}

/* ===== price/points helpers ===== */
function opt_price(array $node, string $id): int {
  if (!isset($node['options'][$id])) return 0;
  $o = $node['options'][$id];
  if (isset($o['removal_cost'])) return (int)$o['removal_cost'];
  if (isset($o['price']) && $o['price']!=='') return (int)$o['price'];
  if (isset($o['base_price'])) return (int)$o['base_price'];
  if (isset($o['price_add']))  return (int)$o['price_add'];
  return 0;
}
function opt_points(array $node, string $id): int {
  if (!isset($node['options'][$id])) return 0;
  $o = $node['options'][$id];
  if (isset($o['points']) && $o['points']!=='') return (int)$o['points'];
  return opt_price($node,$id);
}
function opt_hide_price(array $node, string $id): bool { return !empty($node['options'][$id]['hide_price']); }

/* ===== steps model ===== */
function ensure_default_steps(array &$cfg, string $areaKey, array $allowed){
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
    if (isset($s['show_if_service_in']) && is_string($s['show_if_service_in'])) {
      $s['show_if_service_in'] = array_values(array_filter(array_map('trim', explode(',',$s['show_if_service_in']))));
    }
    if (isset($s['hide_if_service_in']) && is_string($s['hide_if_service_in'])) {
      $s['hide_if_service_in'] = array_values(array_filter(array_map('trim', explode(',',$s['hide_if_service_in']))));
    }
    if (empty($s['key']) || !in_array($s['key'],$allowed,true)) $s['key']='service';
  } unset($s);
  usort($cfg[$areaKey]['steps'], fn($a,$b)=>($a['order']<=>$b['order']) ?: strcmp($a['key'],$b['key']));
}
foreach (array_keys(area_list($config)) as $akKey) ensure_default_steps($config,$akKey,$ALLOWED_STEP_KEYS);

function steps_filtered(array $cfg, string $areaKey): array {
  $pf  = 'a_'.$areaKey.'_';
  $svc = S($pf.'service', null);
  $all = $cfg[$areaKey]['steps'] ?? [];
  $res = [];
  foreach ($all as $step){
    if (empty($step['enabled'])) continue;
    $key = $step['key'] ?? '';
    if (!$key) continue;

    if ($key === 'oldCover'){ $res[]=$step; continue; }
    if ($key === 'service'){ $res[]=$step; continue; }
    if ($svc===null) continue;

    $showOk = true;
    if (!empty($step['show_if_service_in'])) {
      $showOk = in_array($svc, (array)$step['show_if_service_in'], true);
    }
    if (!empty($step['hide_if_service_in']) && in_array($svc, (array)$step['hide_if_service_in'], true)) {
      $showOk = false;
    }
    if ($showOk) $res[]=$step;
  }
  $i=1; foreach ($res as &$s){ $s['_runtime_index']=$i++; } unset($s);
  return $res;
}
function step_index_by_key(array $steps, string $key): ?int {
  foreach ($steps as $s){ if (($s['key']??'')===$key) return (int)$s['_runtime_index']; }
  return null;
}

/* ===== авто-определение областей для кнопки на главной ===== */
function areas_for_main_option(string $optId, array $optData, array $cfg): array {
  $areas = [];
  if (!empty($optData['areas']) && is_array($optData['areas'])) {
    foreach ($optData['areas'] as $k){
      $k2 = normalize_key($k);
      if ($k2 && cfg_has_area($cfg,$k2)) $areas[] = $k2;
    }
  }
  if (!$areas) {
    $k2 = normalize_key($optId);
    if (cfg_has_area($cfg,$k2)) $areas = [$k2];
  }
  if (!$areas) {
    $valid = array_keys(area_list($cfg));
    $hasMP = in_array('manicure',$valid,true) && in_array('pedicure',$valid,true);
    if ($hasMP) {
      $txtRaw = $optData['text'] ?? '';
      $txt = is_array($txtRaw) ? mb_strtolower(implode(' ',$txtRaw),'UTF-8') : mb_strtolower((string)$txtRaw,'UTF-8');
      $k2 = normalize_key($optId);
      $looksBoth =
        in_array($k2, ['both','manicure_pedicure','manicurepluspedicure','manicure_plus_pedicure'], true) ||
        (strpos($txt,'маникюр')!==false && strpos($txt,'педикюр')!==false) ||
        (strpos($txt,'manicure')!==false && strpos($txt,'pedicure')!==false) ||
        strpos($txt,'+')!==false;
      if ($looksBoth) $areas = ['manicure','pedicure'];
    }
  }
  return array_values(array_unique($areas));
}

/* ===== totals helpers ===== */
function selected_id_for_key(string $pf, string $key): ?string {
  switch ($key){
    case 'oldCover':return S($pf.'old_cover');
    case 'service': return S($pf.'service');
    case 'cover':   return S($pf.'cover_type');
    case 'length':  return S($pf.'length');
    case 'design':  return S($pf.'design');
    case 'spa':     return S($pf.'spa');
  }
  return null;
}
function subtotal_before_idx(array $cfg, string $ak, string $pf, array $steps, int $currentIdx): int {
  $sum = 0;
  foreach ($steps as $s){
    $idx = (int)($s['_runtime_index'] ?? 0);
    if ($idx >= $currentIdx) continue;
    $key = $s['key'] ?? '';
    if (!$key) continue;
    $sel = selected_id_for_key($pf,$key);
    if (!$sel) continue;
    $node = $cfg[$ak][$key] ?? null;
    if (!$node) continue;
    $sum += opt_price($node, $sel);
  }
  return (int)$sum;
}
function subtotal_now_for_area(array $cfg, string $ak, string $pf): int {
  $sum = 0;
  foreach (['oldCover','service','cover','length','design','spa'] as $key){
    $sel = selected_id_for_key($pf,$key);
    if (!$sel) continue;
    $node = $cfg[$ak][$key] ?? null;
    if (!$node) continue;
    $sum += opt_price($node,$sel);
  }
  return (int)$sum;
}
function subtotal_for_area_points($cfg,$ak,$pf){
  $sum = 0;
  foreach (['oldCover','service','cover','length','design','spa'] as $key){
    $sel = selected_id_for_key($pf,$key);
    if (!$sel) continue;
    $node = $cfg[$ak][$key] ?? null;
    if (!$node) continue;
    $sum += opt_points($node,$sel);
  }
  return (int)$sum;
}

/* ===== POST ===== */
if ($_SERVER['REQUEST_METHOD']==='POST'){
  if (isset($_POST['area'])) {
    $optId = $_POST['area'];
    $opt   = $config['area']['options'][$optId] ?? null;

    $areasFromOpt = $opt ? areas_for_main_option($optId, $opt, $config) : [];

    foreach (array_keys(area_list($config)) as $k) {
      $pf = 'a_'.$k.'_';
      delS($pf.'old_cover', $pf.'service', $pf.'cover_type', $pf.'length', $pf.'design', $pf.'spa');
    }

    if (count($areasFromOpt) > 1) {
      setS('area_mode','combo'); setS('combo', $areasFromOpt); setS('combo_i', 0);
      header('Location: quiz.php?step=1&lang='.$lang); exit;
    } elseif (count($areasFromOpt) === 1) {
      setS('area_mode','single'); setS('area', $areasFromOpt[0]);
      header('Location: quiz.php?step=1&lang='.$lang); exit;
    } else {
      header('Location: quiz.php?lang='.$lang); exit;
    }
  }

  $ak = currentAreaKey($config);
  if ($ak){
    $pf = prefix($config);
    if (isset($_POST['old_cover']))  { setS($pf.'old_cover',  $_POST['old_cover']);  header('Location: quiz.php?step=step_next&lang='.$lang); exit; }
    if (isset($_POST['service']))    { setS($pf.'service',    $_POST['service']);    delS($pf.'cover_type',$pf.'length',$pf.'design'); header('Location: quiz.php?step=step_next&lang='.$lang); exit; }
    if (isset($_POST['cover_type'])) { setS($pf.'cover_type', $_POST['cover_type']); header('Location: quiz.php?step=step_next&lang='.$lang); exit; }
    if (isset($_POST['length']))     { setS($pf.'length',     $_POST['length']);     header('Location: quiz.php?step=step_next&lang='.$lang); exit; }
    if (isset($_POST['design']))     { setS($pf.'design',     $_POST['design']);     header('Location: quiz.php?step=step_next&lang='.$lang); exit; }
    if (isset($_POST['spa']))        {
      setS($pf.'spa', $_POST['spa']);
      if (areaMode()==='combo'){
        $combo = S('combo',[]); $i=(int)S('combo_i',0);
        if ($i+1 < count($combo)) { setS('combo_i', $i+1); header('Location: quiz.php?step=1&lang='.$lang); exit; }
      }
      header('Location: quiz.php?step=999&lang='.$lang); exit;
    }
  }
}

/* ===== Wix-friendly GET fallback ===== */
if (isset($_GET['choose'], $_GET['val'])) {
  $choose = $_GET['choose'];
  $val    = $_GET['val'];

  if ($choose === 'area') {
    $optId = $val;
    $opt   = $config['area']['options'][$optId] ?? null;
    $areasFromOpt = $opt ? areas_for_main_option($optId, $opt, $config) : [];

    foreach (array_keys(area_list($config)) as $k) {
      $pf = 'a_'.$k.'_';
      delS($pf.'old_cover', $pf.'service', $pf.'cover_type', $pf.'length', $pf.'design', $pf.'spa');
    }

    if (count($areasFromOpt) > 1) {
      setS('area_mode','combo'); setS('combo', $areasFromOpt); setS('combo_i', 0);
      header('Location: quiz.php?step=1&lang='.$lang); exit;
    } elseif (count($areasFromOpt) === 1) {
      setS('area_mode','single'); setS('area', $areasFromOpt[0]);
      header('Location: quiz.php?step=1&lang='.$lang); exit;
    } else {
      header('Location: quiz.php?lang='.$lang); exit;
    }

  } else {
    $ak = currentAreaKey($config);
    if ($ak){
      $pf = prefix($config);
      switch ($choose) {
        case 'old_cover':
          if (isset($config[$ak]['oldCover']['options'][$val])) { $_SESSION[$pf.'old_cover'] = $val; header('Location: quiz.php?step=step_next&lang='.$lang); exit; }
          break;
        case 'service':
          if (isset($config[$ak]['service']['options'][$val])) {
            $_SESSION[$pf.'service'] = $val;
            unset($_SESSION[$pf.'cover_type'], $_SESSION[$pf.'length'], $_SESSION[$pf.'design']);
            header('Location: quiz.php?step=step_next&lang='.$lang); exit;
          }
          break;
        case 'cover_type':
          if (isset($config[$ak]['cover']['options'][$val])) { $_SESSION[$pf.'cover_type'] = $val; header('Location: quiz.php?step=step_next&lang='.$lang); exit; }
          break;
        case 'length':
          if (isset($config[$ak]['length']['options'][$val])) { $_SESSION[$pf.'length'] = $val; header('Location: quiz.php?step=step_next&lang='.$lang); exit; }
          break;
        case 'design':
          if (isset($config[$ak]['design']['options'][$val])) { $_SESSION[$pf.'design'] = $val; header('Location: quiz.php?step=step_next&lang='.$lang); exit; }
          break;
        case 'spa':
          if (isset($config[$ak]['spa']['options'][$val])) {
            $_SESSION[$pf.'spa'] = $val;
            if (areaMode()==='combo'){
              $combo = S('combo',[]); $i=(int)S('combo_i',0);
              if ($i+1 < count($combo)) { $_SESSION['combo_i'] = $i+1; header('Location: quiz.php?step=1&lang='.$lang); exit; }
            }
            header('Location: quiz.php?step=999&lang='.$lang); exit;
          }
          break;
      }
    }
  }
}

/* ===== compute steps & navigation ===== */
$areas  = area_list($config);
$ak     = currentAreaKey($config);
$pf     = $ak ? prefix($config) : '';
$areaOk = $ak && cfg_has_area($config,$ak);

$requested = $_GET['step'] ?? 0;
if ($requested === 'step_next') {
  if ($areaOk){
    $steps = steps_filtered($config,$ak);
    $curIdx = 1;
    foreach ($steps as $s){
      $idx = (int)$s['_runtime_index'];
      $key = $s['key'];
      $sel = selected_id_for_key($pf,$key);
      if ($sel) $curIdx = $idx;
    }
    $requested = $curIdx+1;
  } else {
    $requested = 0;
  }
}
$requested = (int)$requested;

if (!$areaOk) {
  $pageType = 'area';
} else {
  if ($requested<=0) $requested=1;
  $steps = steps_filtered($config,$ak);
  $maxIdx = count($steps);
  $pageType = ($requested>=$maxIdx+1) ? 'summary' : 'step';
}

function prev_step_index(array $steps, int $currentIdx): int { return max(1, $currentIdx-1); }

/* Summary helper */
function build_current_summary(array $cfg, string $ak, string $pf, $lang, $MSG): array {
  $L = [];
  if (!empty($_SESSION[$pf.'old_cover'])) { $id=$_SESSION[$pf.'old_cover']; $txt=tr($cfg[$ak]['oldCover']['options'][$id]['text'] ?? '',$lang,'ru'); if ($txt!=='') $L[] = t('oldcover',$lang,$MSG).": ".$txt; }
  if (!empty($_SESSION[$pf.'service']))    { $id=$_SESSION[$pf.'service'];    $txt=tr($cfg[$ak]['service']['options'][$id]['text'] ?? '',$lang,'ru'); if ($txt!=='') $L[] = t('service',$lang,$MSG).": ".$txt; }
  if (!empty($_SESSION[$pf.'cover_type'])) { $id=$_SESSION[$pf.'cover_type']; $txt=tr($cfg[$ak]['cover']['options'][$id]['text'] ?? '',$lang,'ru'); if ($txt!=='') $L[] = t('cover',$lang,$MSG).": ".$txt; }
  if (!empty($_SESSION[$pf.'length']))     { $id=$_SESSION[$pf.'length'];     $txt=tr($cfg[$ak]['length']['options'][$id]['text'] ?? '',$lang,'ru'); if ($txt!=='') $L[] = t('length',$lang,$MSG).": ".$txt; }
  if (!empty($_SESSION[$pf.'design']))     { $id=$_SESSION[$pf.'design'];     $txt=tr($cfg[$ak]['design']['options'][$id]['text'] ?? '',$lang,'ru'); if ($txt!=='') $L[] = t('design',$lang,$MSG).": ".$txt; }
  if (isset($_SESSION[$pf.'spa']))         { $id=$_SESSION[$pf.'spa'];        $txt=tr(($cfg[$ak]['spa']['options'][$id]['text'] ?? ($id==='yes_spa'?'Yes':'No')),$lang,'ru'); $L[] = t('spa',$lang,$MSG).": ".$txt; }
  return array_values(array_filter($L));
}

/* ===== RENDER ===== */
?>
<!doctype html>
<html lang="<?=h($lang)?>">
<head>
  <meta charset="UTF-8">
  <title>Онлайн Квиз</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="quiz.css">
  <style>
    .lang-switch {font-size:14px;color:#6b7280}
    .lang-switch a{color:#111827;text-decoration:none;padding:2px 6px;border-radius:6px}
    .lang-switch a.active{background:#111827;color:#fff}
    .btn-secondary{display:inline-block;border:0;border-radius:10px;padding:10px 14px;background:#374151;color:#fff;text-decoration:none}
  </style>
</head>
<body>
<div id="wrap">
  <div class="header">
    <div class="progress">
      <?php if ($pageType==='area'): ?>
        <?= h(t('choose_area',$lang,$MSG)) ?>
      <?php else: ?>
        <?= h(area_title($config, $ak, $lang)) ?> — <?= h('Шаг '.$requested.' из '.count($steps)) ?>
      <?php endif; ?>
    </div>
    <div class="lang-switch">
      <?php foreach ($LANGS as $L): ?>
        <a href="?lang=<?=h($L)?>" class="<?= $L===$lang?'active':'' ?>"><?=strtoupper(h($L))?></a>
      <?php endforeach; ?>
    </div>
    <?php if ($pageType==='step' && $requested>=1): ?>
      <div class="actions">
        <button type="button" class="btn" onclick="location.href='quiz.php?step=<?= (int)prev_step_index($steps,$requested) ?>&lang=<?=h($lang)?>'"><?= h(t('back',$lang,$MSG)) ?></button>
        <a class="btn" href="quiz.php?reset=1&lang=<?=h($lang)?>"><?= h(t('restart',$lang,$MSG)) ?></a>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($pageType==='step'):
      $summaryLines = build_current_summary($config,$ak,$pf,$lang,$MSG);
      if (!empty($summaryLines)): ?>
    <div class="summary-inline desktop-only">
      <div class="summary-title"><?= h(t('your_choice',$lang,$MSG)) ?>:</div>
      <ul class="summary-list">
        <?php foreach ($summaryLines as $line): ?><li><?= h($line) ?></li><?php endforeach; ?>
      </ul>
    </div>
    <button type="button" class="summary-fab mobile-only" id="openSummary"><?= h(t('open_choice',$lang,$MSG)) ?></button>
    <div id="summaryModal" class="modal" aria-hidden="true">
      <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="summaryTitle">
        <h3 id="summaryTitle" class="modal__title"><?= h(t('your_choice',$lang,$MSG)) ?></h3>
        <div class="modal__body">
          <ul class="summary-list">
            <?php foreach ($summaryLines as $line): ?><li><?= h($line) ?></li><?php endforeach; ?>
          </ul>
        </div>
        <div class="modal__actions"><button class="modal__close" id="closeSummary">OK</button></div>
      </div>
    </div>
  <?php endif; endif; ?>

  <div class="card">
    <?php if ($pageType==='area'): ?>
      <h1><?= h(tr($config['area']['question'] ?? t('what_need',$lang,$MSG), $lang, 'ru')) ?></h1>
      <form method="POST" action="quiz.php?lang=<?=h($lang)?>">
        <div class="grid">
          <?php foreach (($config['area']['options'] ?? []) as $id=>$o):
                $targets = areas_for_main_option($id,$o,$config);
                if (!$targets) continue;
          ?>
            <label class="option">
              <input type="radio" name="area" value="<?= h($id) ?>">
              <?php if (has_img($o)): ?>
                <div class="option__media">
                  <img loading="lazy" src="<?= h($o['image']) ?>" alt="" class="option-img"
                       onerror="this.closest('.option').classList.add('noimg'); this.remove();">
                </div>
              <?php endif; ?>
              <div class="chip">
                <div class="title"><strong><?= h(tr($o['text'] ?? '', $lang, 'ru')) ?></strong></div>
                <?php if (has_desc($o)): ?><a href="#" class="info-link" data-desc="<?= desc_html($o,$lang) ?>">Подробнее</a><?php endif; ?>
              </div>
            </label>
          <?php endforeach; ?>
        </div>
      </form>

    <?php elseif ($pageType==='summary'): ?>
      <?php
        // собрать итог и ссылки
        $areasDone = [];
        if (areaMode()==='combo') {
          $areasDone = array_values(array_filter((array)S('combo',[]), fn($k)=>cfg_has_area($config,$k)));
        } else {
          $areasDone = $ak ? [$ak] : [];
        }

        $totalEur = 0; $totalPts = 0; $items = [];
        $svcForBooking = null;

        foreach ($areasDone as $key) {
          $pfA = 'a_'.$key.'_';
          $title = area_title($config,$key,$lang);

          $svcId = S($pfA.'service');
          if ($svcId) {
            if ($svcForBooking===null) $svcForBooking = $svcId; // возьмём первую услугу
            $sumEur = subtotal_now_for_area($config,$key,$pfA);
            $sumPts = subtotal_for_area_points($config,$key,$pfA);
            $totalEur += $sumEur; $totalPts += $sumPts;

            if (S($pfA.'old_cover')) { $id=S($pfA.'old_cover'); $items[] = ['title'=>"$title — ".t('oldcover',$lang,$MSG), 'val'=>tr($config[$key]['oldCover']['options'][$id]['text'] ?? '',$lang,'ru')]; }
            $items[] = ['title'=>"$title — ".t('service',$lang,$MSG), 'val'=>tr($config[$key]['service']['options'][$svcId]['text'] ?? '',$lang,'ru')];
            if (S($pfA.'cover_type')) { $id=S($pfA.'cover_type'); $items[] = ['title'=>"$title — ".t('cover',$lang,$MSG), 'val'=>tr($config[$key]['cover']['options'][$id]['text'] ?? '',$lang,'ru')]; }
            if (S($pfA.'length'))     { $id=S($pfA.'length');     $items[] = ['title'=>"$title — ".t('length',$lang,$MSG), 'val'=>tr($config[$key]['length']['options'][$id]['text'] ?? '',$lang,'ru')]; }
            if (S($pfA.'design'))     { $id=S($pfA.'design');     $items[] = ['title'=>"$title — ".t('design',$lang,$MSG), 'val'=>tr($config[$key]['design']['options'][$id]['text'] ?? '',$lang,'ru')]; }
            if (S($pfA.'spa')!==null) { $id=S($pfA.'spa');        $items[] = ['title'=>"$title — ".t('spa',$lang,$MSG),    'val'=>tr($config[$key]['spa']['options'][$id]['text'] ?? ($id==='yes_spa'?'Yes':'No'),$lang,'ru')]; }
          }
        }

        // Базовые ссылки
        $bookingBase = $config['booking']['default'] ?? '#';

        // Если в услуге задан индивидуальный booking_url — используем его
        if ($svcForBooking && isset($config[$ak]['service']['options'][$svcForBooking]['booking_url']) && $config[$ak]['service']['options'][$svcForBooking]['booking_url']!=='') {
          $bookUrl = $config[$ak]['service']['options'][$svcForBooking]['booking_url'];
        } else {
          // добавим svc=<код>
          $sep = (strpos($bookingBase,'?')===false ? '?' : '&');
          $bookUrl = $bookingBase . ($bookingBase!=='#' ? $sep.'svc='.rawurlencode((string)$svcForBooking) : '');
        }
      ?>
      <h1><?= h(t('summary',$lang,$MSG)) ?></h1>
      <div class="summary-box">
        <ul>
          <?php foreach ($items as $it): if(!$it['val']) continue; ?>
            <li><?= h($it['title']) ?>: <?= h($it['val']) ?></li>
          <?php endforeach; ?>
        </ul>
        <div class="total"><?= h(t('final_total',$lang,$MSG)) ?>: <?= (int)$totalEur ?> €</div>
        <div class="badge">🌸 <?= h(t('points',$lang,$MSG)) ?>: <strong>+<?= (int)$totalPts ?></strong></div>
      </div>

      <div class="book" style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="btn" href="<?= h($bookUrl) ?>" onclick="window.top.location.href=this.href; return false;"><?= h(t('book',$lang,$MSG)) ?></a>
        <a class="btn-secondary" href="<?= h($bookUrl) ?>" onclick="window.top.location.href=this.href; return false;"><?= h(t('pick_time',$lang,$MSG)) ?></a>
      </div>

      <div class="footer-note" style="margin-top:10px"><a href="quiz.php?reset=1&lang=<?=h($lang)?>"><?= h(t('again',$lang,$MSG)) ?></a></div>

    <?php else: /* pageType === 'step' */ 
      $steps = steps_filtered($config,$ak);
      $currentKey = null;
      foreach ($steps as $s){ if ((int)$s['_runtime_index']===$requested){ $currentKey=$s['key']; break; } }
      if (!$currentKey) { $currentKey='service'; }
      $node = $config[$ak][$currentKey] ?? ['question'=>'','options'=>[]];

      // ПРОМЕЖУТОЧНЫЕ СУММЫ
      $subtotalPrev = subtotal_before_idx($config,$ak,$pf,$steps,$requested);
      if ($requested>=1): ?>
        <div class="badge">💶 <?= h(t('total_now',$lang,$MSG)) ?>: <strong><?= (int)$subtotalPrev ?></strong> € &nbsp; • &nbsp; 🌸 <?= h(t('points',$lang,$MSG)) ?>: +<?= (int)$subtotalPrev ?></div>
      <?php endif; ?>
      <h1><?= h(tr($node['question'] ?? '', $lang, 'ru')) ?></h1>
      <form method="POST" action="quiz.php?lang=<?=h($lang)?>">
        <div class="grid">
          <?php
          $nameAttr = ($currentKey==='cover') ? 'cover_type' : ($currentKey==='oldCover' ? 'old_cover' : $currentKey);
          foreach (($node['options'] ?? []) as $id=>$o):
            $delta   = opt_price($node, $id);
            $total   = $subtotalPrev + (int)$delta;
            $hide    = opt_hide_price($node,$id);
            $textRaw = $o['text'] ?? '';
          ?>
            <label class="option">
              <input type="radio" name="<?= h($nameAttr) ?>" value="<?= h($id) ?>">
              <?php if (has_img($o)): ?>
                <div class="option__media">
                  <img loading="lazy" src="<?= h($o['image']) ?>" alt="" class="option-img"
                       onerror="this.closest('.option').classList.add('noimg'); this.remove();">
                </div>
              <?php endif; ?>
              <div class="chip">
                <div class="title"><strong><?= h(tr($textRaw, $lang, 'ru')) ?></strong></div>
                <?php if (!$hide): ?>
                  <div class="price"><?= h(t('incl_total',$lang,$MSG)) ?>: <?= (int)$total ?> €</div>
                  <div class="price price--muted"><?= h(t('plus',$lang,$MSG)) ?><?= (int)$delta ?> €</div>
                <?php endif; ?>
                <?php if (has_desc($o)): ?><a href="#" class="info-link" data-desc="<?= desc_html($o,$lang) ?>">Что входит в услугу?</a><?php endif; ?>
              </div>
            </label>
          <?php endforeach; ?>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<!-- Модалки -->
<div id="infoModal" class="modal" aria-hidden="true">
  <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="infoTitle">
    <h3 id="infoTitle" class="modal__title">Что входит в услугу:</h3>
    <div id="infoBody" class="modal__body"></div>
    <div class="modal__actions"><button id="infoClose" class="modal__close">OK</button></div>
  </div>
</div>

<script>
(function(){
  const modal=document.getElementById('infoModal');
  const body =document.getElementById('infoBody');
  const close=document.getElementById('infoClose');
  function openModal(html){ body.innerHTML=html; modal.classList.add('open'); }
  function closeModal(){ modal.classList.remove('open'); body.innerHTML=''; }
  document.addEventListener('click', e=>{
    const a=e.target.closest('.info-link');
    if(a){ e.preventDefault(); openModal((a.dataset.desc||'').replace(/\n/g,'<br>')); }
    if(e.target===modal) closeModal();
  });
  close.addEventListener('click', closeModal);
})();

document.addEventListener('change', function(e){
  if (e.target.matches('input[type=radio]')){
    const input=e.target, name=input.name, val=input.value;
    const grid=input.closest('.grid'); if (grid) grid.querySelectorAll('.option').forEach(o=>o.classList.remove('selected'));
    const card=input.closest('.option'); if (card) card.classList.add('selected');
    const url='quiz.php?choose=' + encodeURIComponent(name) + '&val=' + encodeURIComponent(val) + '&lang=<?=h($lang)?>';
    setTimeout(()=>{ location.href=url; }, 60);
  }
});

(function(){
  const openBtn = document.getElementById('openSummary');
  const modal   = document.getElementById('summaryModal');
  if (!openBtn || !modal) return;
  const closeBtn= document.getElementById('closeSummary');
  function open(){ modal.classList.add('open'); }
  function close(){ modal.classList.remove('open'); }
  openBtn.addEventListener('click', open);
  if (closeBtn) closeBtn.addEventListener('click', close);
  modal.addEventListener('click', function(e){ if (e.target === modal) close(); });
})();
</script>

<?php
echo "<!-- CONFIG: ".h(realpath($CONFIG))." | mtime: ".date('c', @filemtime($CONFIG))." -->";
?>
</body>
</html>
