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
      echo "<pre style='white-space:pre-wrap;background:#fff3cd;border:1px solid #ffecb5;padding:10px;border-radius:8px'>".
           "FATAL: {$e['message']}\nFile: {$e['file']}:{$e['line']}</pre>";
    }
  });
}

// Подключение к БД по env из docker-compose
function pdo(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $host = getenv('DB_HOST') ?: 'db';
  $port = getenv('DB_PORT') ?: '3306';
  $name = getenv('DB_NAME') ?: 'booking';
  $user = getenv('DB_USER') ?: 'app';
  $pass = getenv('DB_PASS') ?: 'app';
  $dsn  = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

/* ===== helper: чтение overrides ===== */
function qoo_min(string $area, string $step, string $opt, string $col): ?int {
  try{
    $db = pdo();
    $st = $db->prepare("
      SELECT MIN($col) AS v
      FROM quiz_option_overrides
      WHERE area_key=:ak AND step_key=:sk AND option_id=:oi
        AND staff_id>0 AND $col IS NOT NULL
      LIMIT 1
    ");
    $st->execute([':ak'=>$area, ':sk'=>$step, ':oi'=>$opt]);
    $v = $st->fetchColumn();
    if ($v === false || $v === null || $v === '') return null;
    return (int)$v;
  }catch(Throwable $e){
    return null;
  }
}

/* ===== reading defaults из quiz_config.json узла ===== */
function cfg_default_price(array $node, string $id): int {
  if (!isset($node['options'][$id])) return 0;
  $o = $node['options'][$id];
  if (isset($o['price']) && $o['price']!=='') return (int)$o['price'];
  if (isset($o['base_price'])) return (int)$o['base_price'];
  if (isset($o['price_add']))  return (int)$o['price_add'];
  return 0;
}
function cfg_default_duration(array $node, string $id): int {
  if (!isset($node['options'][$id])) return 0;
  $o = $node['options'][$id];
  if (isset($o['duration_min']) && $o['duration_min']!=='') return (int)$o['duration_min'];
  if (isset($o['duration_add']) && $o['duration_add']!=='') return (int)$o['duration_add'];
  return 0;
}

/* ===== «от …» для одной опции ===== */
function opt_min_price(string $areaKey, string $stepKey, array $node, string $optId): int {
  $v = qoo_min($areaKey, $stepKey, $optId, 'price_eur');
  return ($v!==null) ? $v : cfg_default_price($node,$optId);
}
function opt_min_duration(string $areaKey, string $stepKey, array $node, string $optId): int {
  $v = qoo_min($areaKey, $stepKey, $optId, 'duration_min');
  return ($v!==null) ? $v : cfg_default_duration($node,$optId);
}
function opt_min_points(string $areaKey, string $stepKey, array $node, string $optId): int {
  // 1€ = 1 балл
  return opt_min_price($areaKey, $stepKey, $node, $optId);
}

/* ===== Сессия ===== */
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off');
if (PHP_VERSION_ID >= 70300) {
  session_set_cookie_params([
    'lifetime'=>0,'path'=>'/','domain'=>'',
    'secure'=>$secure,'httponly'=>true,'samesite'=>'Lax'
  ]);
} else {
  ini_set('session.cookie_samesite','Lax');
  ini_set('session.cookie_secure', $secure ? '1':'0');
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
$LANGS = (isset($config['languages']) && is_array($config['languages']) && $config['languages']) ? $config['languages'] : ['ru','et','en'];
$lang  = $_GET['lang'] ?? ($_SESSION['lang'] ?? ($LANGS[0] ?? 'ru'));
if (!in_array($lang,$LANGS,true)) $lang = $LANGS[0] ?? 'ru';
$_SESSION['lang'] = $lang;

/* staff_id в сессии (оставляем, но «от» логика его не использует) */
$QUIZ_STAFF_ID = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;
if ($QUIZ_STAFF_ID > 0) {
  $_SESSION['quiz_staff_id'] = $QUIZ_STAFF_ID;
} elseif (!empty($_SESSION['quiz_staff_id'])) {
  $QUIZ_STAFF_ID = (int)$_SESSION['quiz_staff_id'];
}
$DB = pdo();

/* ===== i18n ===== */
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
    'minutes'     => 'Время',
    'what_need'   => 'Что вам нужно?',
    'back'        => '← Назад',
    'restart'     => 'Начать заново',
    'summary'     => 'Итог',
    'final_total' => 'Итоговая стоимость',
    'final_time'  => 'Общая длительность',
    'book'        => 'Записаться',
    'again'       => 'Пройти квиз заново',
    'incl_total'  => 'Итого',
    'plus'        => '+',
    'from'        => 'от',
    'oldcover'    => 'Что на ногтях сейчас',
    'service'     => 'Услуга',
    'cover'       => 'Покрытие',
    'length'      => 'Длина',
    'design'      => 'Дизайн',
    'spa'         => 'SPA',
    'no_booking'  => 'Ссылка на запись не настроена',
    'progress'    => 'Шаг %d из %d',
    'book_hint'   => 'Нажмите «Записаться», чтобы выбрать время'
  ],
  'et'=>[
    'choose_area' => 'Vali suund',
    'your_choice' => 'Sinu valik',
    'open_choice' => 'Minu valik',
    'total_now'   => 'Kokku praegu',
    'points'      => 'Punktid',
    'minutes'     => 'Aeg',
    'what_need'   => 'Mida vajate?',
    'back'        => '← Tagasi',
    'restart'     => 'Alusta uuesti',
    'summary'     => 'Kokkuvõte',
    'final_total' => 'Lõpphind',
    'final_time'  => 'Kogukestus',
    'book'        => 'Broneeri',
    'again'       => 'Täida uuesti',
    'incl_total'  => 'Kokku',
    'plus'        => '+',
    'from'        => 'alates',
    'oldcover'    => 'Mis on küüntel',
    'service'     => 'Teenus',
    'cover'       => 'Kate',
    'length'      => 'Pikkus',
    'design'      => 'Disain',
    'spa'         => 'SPA',
    'no_booking'  => 'Broneeringu link seadistamata',
    'progress'    => 'Samm %d / %d',
    'book_hint'   => 'Vaba aja valimiseks vajuta «Broneeri»'
  ],
  'en'=>[
    'choose_area' => 'Choose category',
    'your_choice' => 'Your selection',
    'open_choice' => 'My selection',
    'total_now'   => 'Total now',
    'points'      => 'Points',
    'minutes'     => 'Time',
    'what_need'   => 'What do you need?',
    'back'        => '← Back',
    'restart'     => 'Start over',
    'summary'     => 'Summary',
    'final_total' => 'Total price',
    'final_time'  => 'Total duration',
    'book'        => 'Book now',
    'again'       => 'Take the quiz again',
    'incl_total'  => 'Total',
    'plus'        => '+',
    'from'        => 'from',
    'oldcover'    => 'What’s on nails now',
    'service'     => 'Service',
    'cover'       => 'Cover',
    'length'      => 'Length',
    'design'      => 'Design',
    'spa'         => 'SPA',
    'no_booking'  => 'Booking link is not configured',
    'progress'    => 'Step %d of %d',
    'book_hint'   => 'Click “Book now” to pick a time'
  ],
];
function t($key, $lang, $MSG){ return $MSG[$lang][$key] ?? $MSG['ru'][$key] ?? $key; }

/* ===== helpers ===== */
$ALLOWED_STEP_KEYS = ['oldCover','service','cover','length','design','spa'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function S($k,$d=null){ return $_SESSION[$k] ?? $d; }
function setS($k,$v){ $_SESSION[$k]=$v; }
function delS(...$keys){ foreach($keys as $k) unset($_SESSION[$k]); }
function normalize_key($s){ $s=preg_replace('~[^a-z0-9_]+~i','_', (string)$s); return strtolower(trim($s,'_')); }

/* ===== URL helper ===== */
function quiz_url(array $extra = []): string {
  $keep = [];
  foreach (['lang','to','embed','staff_id'] as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') $keep[$k] = (string)$_GET[$k];
  }
  $q = http_build_query(array_merge($keep, $extra), '', '&', PHP_QUERY_RFC3986);
  return 'quiz.php' . ($q ? ('?' . $q) : '');
}

/* Форматирование минут */
function fmt_duration_hm(int $mins, string $lang='ru'): string {
  $mins = max(0, (int)$mins);
  $h = intdiv($mins, 60);
  $m = $mins % 60;
  if ($lang==='en') { if ($h && $m) return "{$h} h {$m} min"; if ($h) return "{$h} h"; return "{$m} min"; }
  if ($lang==='et') { if ($h && $m) return "{$h} t {$m} min"; if ($h) return "{$h} t"; return "{$m} min"; }
  if ($h && $m) return "{$h} ч {$m} мин"; if ($h) return "{$h} ч"; return "{$m} мин";
}

/* slugify */
function slugify_et($s){
  $map = ['ä'=>'a','Ä'=>'a','õ'=>'o','Õ'=>'o','ö'=>'o','Ö'=>'o','ü'=>'u','Ü'=>'u','š'=>'s','Š'=>'s','ž'=>'z','Ž'=>'z','ß'=>'ss','ñ'=>'n','Ñ'=>'n','å'=>'a','Å'=>'a','ć'=>'c','Ć'=>'c','č'=>'c','Č'=>'c','é'=>'e','É'=>'e','è'=>'e','È'=>'e','ê'=>'e','Ê'=>'e'];
  $s = strtr((string)$s,$map);
  $s = mb_strtolower($s,'UTF-8');
  $s = preg_replace('~[^a-z0-9]+~u','-',$s);
  $s = trim($s,'-');
  $s = preg_replace('~-+~','-',$s);
  return $s ?: 'service';
}

/* ===== areas ===== */
if (!isset($config['areas']) || !is_array($config['areas']) || !$config['areas']) {
  $config['areas'] = [];
  if (isset($config['manicure'])) $config['areas']['manicure'] = ['title'=>'MANICURE'];
  if (isset($config['pedicure'])) $config['areas']['pedicure'] = ['title'=>'PEDICURE'];
}
function cfg_has_area(array $cfg, string $areaKey): bool { return isset($cfg['areas'][$areaKey]) && is_array($cfg['areas'][$areaKey]); }
function area_list(array $cfg): array { return isset($cfg['areas']) && is_array($cfg['areas']) ? $cfg['areas'] : []; }
function area_title(array $cfg, string $areaKey, $lang='ru'): string {
  $areas = area_list($cfg);
  $raw = $areas[$areaKey]['title'] ?? ucfirst($areaKey);
  return tr($raw,$lang,'ru');
}

/* ===== flow mode ===== */
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

/* (оставляем для совместимости) */
function opt_hide_price(array $node, string $id): bool {
  return !empty($node['options'][$id]['hide_price']);
}

/* ====== НОВОЕ: флаги показа бейджей + подписи ====== */
function opt_show_badge(array $node, string $id, string $flagKey, bool $default=true): bool {
  $o = $node['options'][$id] ?? [];
  if ($flagKey === 'show_badge_price' && !empty($o['hide_price'])) return false; // back-compat
  if (!array_key_exists($flagKey, $o)) return $default;
  return !empty($o[$flagKey]);
}
function opt_badge_note(array $node, string $id, string $noteKey, string $lang): string {
  $o = $node['options'][$id] ?? [];
  $raw = $o[$noteKey] ?? '';
  $txt = tr($raw, $lang, 'ru');
  return trim((string)$txt);
}

/* ===== steps model ===== */
$ALLOWED_STEP_KEYS = ['oldCover','service','cover','length','design','spa'];
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

/* ===== area auto-detect for main option ===== */
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
      $txt = is_array($txtRaw) ? mb_strtolower(implode(' ', $txtRaw), 'UTF-8') : mb_strtolower((string)$txtRaw, 'UTF-8');
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

/* ===== selected helpers ===== */
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

/* ===== «от …» СУММЫ (мин. по всем мастерам) ===== */
function subtotal_min_before_idx(array $cfg, string $ak, string $pf, array $steps, int $currentIdx): array {
  $sumE=0; $sumP=0; $sumM=0;
  foreach ($steps as $s){
    $idx = (int)($s['_runtime_index'] ?? 0);
    if ($idx >= $currentIdx) continue;
    $sel = selected_id_for_key($pf, $s['key'] ?? '');
    if (!$sel) continue;
    $nodeKey = $s['key'];
    $node    = $cfg[$ak][$nodeKey] ?? null;
    if (!$node) continue;

    $p = opt_min_price($ak, $nodeKey, $node, $sel);
    $m = opt_min_duration($ak, $nodeKey, $node, $sel);
    $pts = $p;

    $sumE += $p; $sumP += $pts; $sumM += $m;
  }
  return [(int)$sumE,(int)$sumP,(int)$sumM];
}

function subtotal_min_now_for_area(array $cfg, string $ak, string $pf): array {
  $sumE=0; $sumP=0; $sumM=0;
  foreach (['oldCover','service','cover','length','design','spa'] as $key){
    $sel = selected_id_for_key($pf,$key);
    if (!$sel) continue;
    $node = $cfg[$ak][$key] ?? null;
    if (!$node) continue;

    $p = opt_min_price($ak,$key,$node,$sel);
    $m = opt_min_duration($ak,$key,$node,$sel);
    $pts = $p;
    $sumE += $p; $sumP += $pts; $sumM += $m;
  }
  return [(int)$sumE,(int)$sumP,(int)$sumM];
}

function combo_subtotals_min_before_current_step(array $cfg, array $combo, int $comboIndex, string $currentAk, int $currentIdx): array {
  $sumE=0; $sumP=0; $sumM=0;
  foreach ($combo as $i=>$key){
    if (!cfg_has_area($cfg,$key)) continue;
    $pfA = 'a_'.$key.'_';
    if ($i < $comboIndex) {
      [$e,$p,$m] = subtotal_min_now_for_area($cfg,$key,$pfA);
      $sumE+=$e; $sumP+=$p; $sumM+=$m;
    } elseif ($i == $comboIndex) {
      $stepsA = steps_filtered($cfg,$key);
      [$e,$p,$m] = subtotal_min_before_idx($cfg,$key,$pfA,$stepsA,$currentIdx);
      $sumE+=$e; $sumP+=$p; $sumM+=$m;
    }
  }
  return [(int)$sumE,(int)$sumP,(int)$sumM];
}

/* ===== BOOKING URL HELPERS ===== */
function service_slug(array $cfg, string $areaKey, string $svcId): string {
  $o = $cfg[$areaKey]['service']['options'][$svcId] ?? null;
  if (!$o) return 'service';
  $slug = trim((string)($o['slug'] ?? ''));
  if ($slug!=='') return slugify_et($slug);
  $titleEt = '';
  if (is_array($o['text'] ?? null)) $titleEt = (string)($o['text']['et'] ?? '');
  if ($titleEt==='') $titleEt = tr($o['text'] ?? '', 'et', 'ru');
  return slugify_et($titleEt ?: $svcId);
}

function build_quiz_meta_map(array $cfg, array $areas, string $lang): array {
  $map = [];
  foreach ($areas as $key) {
    if (!cfg_has_area($cfg,$key)) continue;
    $pf = 'a_'.$key.'_';

    if (S($pf.'old_cover')) { $id=S($pf.'old_cover'); $map[$key.'_oldcover'] = tr($cfg[$key]['oldCover']['options'][$id]['text'] ?? '',$lang,'ru'); }
    if (S($pf.'service'))    { $id=S($pf.'service');    $map[$key.'_service']   = tr($cfg[$key]['service']['options'][$id]['text'] ?? '',$lang,'ru'); }
    if (S($pf.'cover_type')) { $id=S($pf.'cover_type'); $map[$key.'_cover']     = tr($cfg[$key]['cover']['options'][$id]['text'] ?? '',$lang,'ru'); }
    if (S($pf.'length'))     { $id=S($pf.'length');     $map[$key.'_length']    = tr($cfg[$key]['length']['options'][$id]['text'] ?? '',$lang,'ru'); }
    if (S($pf.'design'))     { $id=S($pf.'design');     $map[$key.'_design']    = tr($cfg[$key]['design']['options'][$id]['text'] ?? '',$lang,'ru'); }
    if (S($pf.'spa')!==null) { $id=S($pf.'spa');        $map[$key.'_spa']       = tr(($cfg[$key]['spa']['options'][$id]['text'] ?? ($id==='yes_spa'?'Yes':'No')),$lang,'ru'); }
  }
  return array_filter($map, fn($v)=> (string)$v !== '');
}

function build_internal_booking_url(array $cfg, array $areasList, string $lang, int $sumEur, int $sumMin): string {
  $baseRaw = trim((string)($cfg['booking']['base'] ?? 'booking'), " \t");
  if ($baseRaw === '') $baseRaw = 'booking';
  $isFile = (bool)preg_match('~\.(php|html?)$~i', parse_url($baseRaw, PHP_URL_PATH) ?? '');

  $codes = []; $slugs = [];
  foreach ($areasList as $key){
    if (!isset($cfg['areas'][$key])) continue;
    $svc = $_SESSION['a_'.$key.'_service'] ?? null;
    if ($svc) {
      $codes[] = $svc;
      $o = $cfg[$key]['service']['options'][$svc] ?? null;
      $titleEt = '';
      if (is_array($o['text'] ?? null)) $titleEt = (string)($o['text']['et'] ?? '');
      if ($titleEt==='') $titleEt = tr($o['text'] ?? '', 'et', 'ru');
      $slug = slugify_et(($o['slug'] ?? '') ?: ($titleEt ?: $svc));
      $slugs[] = $slug;
    }
  }
  if (!$codes) return '#';

  $path = '';
  if (!$isFile) {
    if (count($codes) === 1) {
      $path = $slugs[0];
    } else {
      $comboKey = implode('+', $areasList);
      $mapped   = (string)($cfg['booking']['combo'][$comboKey] ?? '');
      $path     = $mapped !== '' ? $mapped : 'combo';
    }
  }

  $quizMap  = build_quiz_meta_map($cfg, $areasList, $lang);
  $metaJson = json_encode(['quiz'=>$quizMap], JSON_UNESCAPED_UNICODE);
  $metaB64  = rtrim(strtr(base64_encode($metaJson), '+/', '-_'), '=');

  $params = [
    'from'     => 'quiz',
    'lang'     => $lang,
    'svc'      => implode(',', $codes),
    'services' => implode(',', $codes),
    'sum_eur'  => (string)(int)$sumEur,
    'sum_min'  => (string)(int)$sumMin,
    'meta_b64' => $metaB64,
  ];
  if (count($codes) === 1 && isset($slugs[0]) && $slugs[0] !== '') {
    $params['slug'] = $slugs[0];
  }

  $q = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
  foreach ($codes as $c) { $q .= '&svc=' . rawurlencode($c); }

  $baseHasQuery = (strpos($baseRaw, '?') !== false);
  $basePath = rtrim($baseRaw, '/');
  if (!$isFile) {
    $basePath .= '/';
    if ($path) $basePath .= trim($path, '/').'/';
  }
  $sep = $baseHasQuery ? '&' : '?';
  return $basePath . $sep . $q;
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
      header('Location: ' . quiz_url(['step'=>1])); exit;
    } elseif (count($areasFromOpt) === 1) {
      setS('area_mode','single'); setS('area', $areasFromOpt[0]);
      header('Location: ' . quiz_url(['step'=>1])); exit;
    } else {
      header('Location: ' . quiz_url()); exit;
    }
  }

  $ak = currentAreaKey($config);
  if ($ak){
    $pf = prefix($config);
    if (isset($_POST['old_cover']))  { setS($pf.'old_cover',  $_POST['old_cover']);  header('Location: ' . quiz_url(['step'=>'step_next'])); exit; }
    if (isset($_POST['service']))    { setS($pf.'service',    $_POST['service']);    delS($pf.'cover_type',$pf.'length',$pf.'design'); header('Location: ' . quiz_url(['step'=>'step_next'])); exit; }
    if (isset($_POST['cover_type'])) { setS($pf.'cover_type', $_POST['cover_type']); header('Location: ' . quiz_url(['step'=>'step_next'])); exit; }
    if (isset($_POST['length']))     { setS($pf.'length',     $_POST['length']);     header('Location: ' . quiz_url(['step'=>'step_next'])); exit; }
    if (isset($_POST['design']))     { setS($pf.'design',     $_POST['design']);     header('Location: ' . quiz_url(['step'=>'step_next'])); exit; }
    if (isset($_POST['spa']))        {
      setS($pf.'spa', $_POST['spa']);
      if (areaMode()==='combo'){
        $combo = S('combo',[]); $i=(int)S('combo_i',0);
        if ($i+1 < count($combo)) { setS('combo_i', $i+1); header('Location: ' . quiz_url(['step'=>1])); exit; }
      }
      header('Location: ' . quiz_url(['step'=>999])); exit;
    }
  }
}

/* ===== GET fallback ===== */
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
      header('Location: ' . quiz_url(['step'=>1])); exit;
    } elseif (count($areasFromOpt) === 1) {
      setS('area_mode','single'); setS('area', $areasFromOpt[0]);
      header('Location: ' . quiz_url(['step'=>1])); exit;
    } else {
      header('Location: ' . quiz_url()); exit;
    }

  } else {
    $ak = currentAreaKey($config);
    if ($ak){
      $pf = prefix($config);
      switch ($choose) {
        case 'old_cover':
          if (isset($config[$ak]['oldCover']['options'][$val])) { $_SESSION[$pf.'old_cover'] = $val; header('Location: ' . quiz_url(['step'=>'step_next'])); exit; }
          break;
        case 'service':
          if (isset($config[$ak]['service']['options'][$val])) {
            $_SESSION[$pf.'service'] = $val;
            unset($_SESSION[$pf.'cover_type'], $_SESSION[$pf.'length'], $_SESSION[$pf.'design']);
            header('Location: ' . quiz_url(['step'=>'step_next'])); exit;
          }
          break;
        case 'cover_type':
          if (isset($config[$ak]['cover']['options'][$val])) { $_SESSION[$pf.'cover_type'] = $val; header('Location: ' . quiz_url(['step'=>'step_next'])); exit; }
          break;
        case 'length':
          if (isset($config[$ak]['length']['options'][$val])) { $_SESSION[$pf.'length'] = $val; header('Location: ' . quiz_url(['step'=>'step_next'])); exit; }
          break;
        case 'design':
          if (isset($config[$ak]['design']['options'][$val])) { $_SESSION[$pf.'design'] = $val; header('Location: ' . quiz_url(['step'=>'step_next'])); exit; }
          break;
        case 'spa':
          if (isset($config[$ak]['spa']['options'][$val])) {
            $_SESSION[$pf.'spa'] = $val;
            if (areaMode()==='combo'){
              $combo = S('combo',[]); $i=(int)S('combo_i',0);
              if ($i+1 < count($combo)) { $_SESSION['combo_i'] = $i+1; header('Location: ' . quiz_url(['step'=>1])); exit; }
            }
            header('Location: ' . quiz_url(['step'=>999])); exit;
          }
          break;
      }
    }
  }
}

/* ===== Назад ===== */
function quiz_clear_state(array $cfg): void {
  foreach (array_keys(area_list($cfg)) as $k) {
    $pf = 'a_'.$k.'_';
    delS($pf.'old_cover', $pf.'service', $pf.'cover_type', $pf.'length', $pf.'design', $pf.'spa');
  }
  delS('area_mode', 'area', 'combo', 'combo_i');
}

if (isset($_GET['go']) && $_GET['go'] === 'prev') {
  $from = isset($_GET['from']) ? (int)$_GET['from'] : 1;
  if ($from > 1) { header('Location: ' . quiz_url(['step'=>$from-1])); exit; }

  if (areaMode() === 'combo') {
    $combo = S('combo', []);
    $i = (int) S('combo_i', 0);
    if ($i > 0) {
      setS('combo_i', $i - 1);
      $akPrev = currentAreaKey($config);
      $stepsPrev = $akPrev ? steps_filtered($config, $akPrev) : [];
      $last = max(1, count($stepsPrev));
      header('Location: ' . quiz_url(['step'=>$last])); exit;
    }
    quiz_clear_state($config); header('Location: ' . quiz_url()); exit;
  }

  quiz_clear_state($config);
  header('Location: ' . quiz_url()); exit;
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
      $sel = selected_id_for_key($pf,$s['key']);
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
function progress_text($lang,$MSG,$cur,$total){ return sprintf(t('progress',$lang,$MSG), (int)$cur, (int)$total); }

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
    .pill{display:inline-block;background:#eef2ff;border:1px solid #e0e7ff;border-radius:999px;padding:6px 10px;font-weight:700;color:#3730a3}
    .muted{color:#6b7280}
    .badge-note{display:block;font-size:12px;color:#6b7280;margin-top:2px}
  </style>
</head>
<body>
<div id="wrap">
  <div class="header">
    <div class="progress">
      <?php if ($pageType==='area'): ?>
        <?= h(t('choose_area',$lang,$MSG)) ?>
      <?php else: ?>
        <?= h(area_title($config, $ak, $lang)) ?> — <?= h(progress_text($lang,$MSG,$requested,count($steps))) ?>
      <?php endif; ?>
    </div>
    <div class="lang-switch">
      <?php foreach ($LANGS as $L): ?>
        <a href="<?=h(quiz_url(['lang'=>$L]))?>" class="<?= $L===$lang?'active':'' ?>"><?=strtoupper(h($L))?></a>
      <?php endforeach; ?>
    </div>
    <?php if ($pageType==='step' && $requested>=1): ?>
      <div class="actions">
        <a class="btn" href="<?=h(quiz_url(['go'=>'prev','from'=>(int)$requested]))?>"><?= h(t('back',$lang,$MSG)) ?></a>
        <a class="btn" href="<?=h(quiz_url(['reset'=>1]))?>"><?= h(t('restart',$lang,$MSG)) ?></a>
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
      <form method="POST" action="<?=h(quiz_url())?>">
        <div class="grid">
          <?php foreach (($config['area']['options'] ?? []) as $id=>$o):
                $targets = areas_for_main_option($id,$o,$config);
                if (!$targets) continue;
          ?>
            <label class="option">
              <input type="radio" name="area" value="<?= h($id) ?>" onchange="this.form.submit()">
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
        <noscript><button class="btn" type="submit">OK</button></noscript>
      </form>

    <?php elseif ($pageType==='summary'): ?>
      <?php
        $areasDone = [];
        if (areaMode()==='combo') {
          $areasDone = array_values(array_filter((array)S('combo',[]), fn($k)=>cfg_has_area($config,$k)));
        } else {
          $areasDone = $ak ? [$ak] : [];
        }

        $totalEur = 0; $totalPts = 0; $totalMin = 0; $items = [];
        foreach ($areasDone as $key) {
          $pfA = 'a_'.$key.'_';
          $title = area_title($config,$key,$lang);

          $svcId = S($pfA.'service');
          if ($svcId) {
            [$sumEur,$sumPts,$sumMin] = subtotal_min_now_for_area($config,$key,$pfA);
            $totalEur += $sumEur; $totalPts += $sumPts; $totalMin += $sumMin;

            if (S($pfA.'old_cover')) { $id=S($pfA.'old_cover'); $items[] = ['title'=>"$title — ".t('oldcover',$lang,$MSG), 'val'=>tr($config[$key]['oldCover']['options'][$id]['text'] ?? '',$lang,'ru')]; }
            $items[] = ['title'=>"$title — ".t('service',$lang,$MSG), 'val'=>tr($config[$key]['service']['options'][$svcId]['text'] ?? '',$lang,'ru')];
            if (S($pfA.'cover_type')) { $id=S($pfA.'cover_type'); $items[] = ['title'=>"$title — ".t('cover',$lang,$MSG), 'val'=>tr($config[$key]['cover']['options'][$id]['text'] ?? '',$lang,'ru')]; }
            if (S($pfA.'length'))     { $id=S($pfA.'length');     $items[] = ['title'=>"$title — ".t('length',$lang,$MSG), 'val'=>tr($config[$key]['length']['options'][$id]['text'] ?? '',$lang,'ru')]; }
            if (S($pfA.'design'))     { $id=S($pfA.'design');     $items[] = ['title'=>"$title — ".t('design',$lang,$MSG), 'val'=>tr($config[$key]['design']['options'][$id]['text'] ?? '',$lang,'ru')]; }
            if (S($pfA.'spa')!==null) { $id=S($pfA.'spa');        $items[] = ['title'=>"$title — ".t('spa',$lang,$MSG),    'val'=>tr($config[$key]['spa']['options'][$id]['text'] ?? ($id==='yes_spa'?'Yes':'No'),$lang,'ru')]; }
          }
        }

        $bookingUrl = build_internal_booking_url($config, $areasDone, $lang, (int)$totalEur, (int)$totalMin);

        $quizMap  = build_quiz_meta_map($config, $areasDone, $lang);
        $metaJson = json_encode(['quiz'=>$quizMap], JSON_UNESCAPED_UNICODE);
        $metaB64  = rtrim(strtr(base64_encode($metaJson), '+/', '-_'), '=');

        $svcCode = '';
        $serviceId = null;
        if (count($areasDone) === 1) {
          $akOne   = $areasDone[0];
          $svcCode = (string)($_SESSION['a_'.$akOne.'_service'] ?? '');
        }

        $adminUrl = '/booking/admin/appointments.php'
                  . '?meta_b64=' . rawurlencode($metaB64)
                  . '&sum_eur=' . (int)$totalEur
                  . '&sum_min=' . (int)$totalMin;
        if ($svcCode !== '')     $adminUrl .= '&svc=' . rawurlencode($svcCode);
        if ($serviceId !== null) $adminUrl .= '&service_id=' . (int)$serviceId;

        if (isset($_GET['to']) && $_GET['to'] === 'admin') {
          echo '<script>window.top.location.href = ' . json_encode($adminUrl) . ';</script>';
          echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($adminUrl, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') . '"></noscript>';
          exit;
        }
      ?>
      <h1><?= h(t('summary',$lang,$MSG)) ?></h1>
      <div class="summary-box">
        <ul>
          <?php foreach ($items as $it): if(!$it['val']) continue; ?>
            <li><?= h($it['title']) ?>: <?= h($it['val']) ?></li>
          <?php endforeach; ?>
        </ul>
        <div class="total"><?= h(t('final_total',$lang,$MSG)) ?>: <?= h(t('from',$lang,$MSG)) ?> <?= (int)$totalEur ?> €</div>
        <div class="pill">🌸 <?= h(t('points',$lang,$MSG)) ?>: <strong><?= h(t('from',$lang,$MSG)) ?> +<?= (int)$totalPts ?></strong></div>
        <div class="pill" style="margin-left:8px">⏱ <?= h(t('final_time',$lang,$MSG)) ?>: <strong><?= h(t('from',$lang,$MSG)) ?> <?= h(fmt_duration_hm((int)$totalMin,$lang)) ?></strong></div>
      </div>

      <?php if ($bookingUrl && $bookingUrl!=='#'): ?>
        <p class="muted" style="margin:12px 0 6px"><?= h(t('book_hint',$lang,$MSG)) ?></p>
        <div class="book">
          <a class="link" href="<?= h($bookingUrl) ?>" rel="noopener"><?= h(t('book',$lang,$MSG)) ?></a>
        </div>
      <?php else: ?>
        <div class="muted" style="margin-top:10px"><?= h(t('no_booking',$lang,$MSG)) ?></div>
      <?php endif; ?>

      <div class="footer-note"><a href="<?=h(quiz_url(['reset'=>1]))?>"><?= h(t('again',$lang,$MSG)) ?></a></div>

    <?php else: /* pageType === 'step' */ 
      $steps = steps_filtered($config,$ak);
      $currentKey = null;
      foreach ($steps as $s){ if ((int)$s['_runtime_index']===$requested){ $currentKey=$s['key']; break; } }
      if (!$currentKey) { $currentKey='service'; }
      $node = $config[$ak][$currentKey] ?? ['question'=>'','options'=>[]];

      // ======== бейдж «Итого сейчас: ОТ …» ========
      if (areaMode()==='combo') {
        $combo = S('combo',[]); $ci=(int)S('combo_i',0);
        [$subtotalPrev, $subtotalPrevPts, $subtotalPrevMin] = combo_subtotals_min_before_current_step($config, $combo, $ci, $ak, $requested);
      } else {
        [$subtotalPrev, $subtotalPrevPts, $subtotalPrevMin] = subtotal_min_before_idx($config,$ak,$pf,$steps,$requested);
      }
      ?>
        <div class="badge" <?= $requested<1?'style="display:none"':'';?>>
          💶 <?= h(t('total_now',$lang,$MSG)) ?>: <strong><?= h(t('from',$lang,$MSG)) ?> <?= (int)$subtotalPrev ?></strong> €
          &nbsp; • &nbsp; 🌸 <?= h(t('points',$lang,$MSG)) ?>: <?= h(t('from',$lang,$MSG)) ?> +<?= (int)$subtotalPrevPts ?>
          &nbsp; • &nbsp; ⏱ <?= h(t('minutes',$lang,$MSG)) ?>: <?= h(t('from',$lang,$MSG)) ?> <?= h(fmt_duration_hm((int)$subtotalPrevMin,$lang)) ?>
        </div>

      <h1><?= h(tr($node['question'] ?? '', $lang, 'ru')) ?></h1>
      <form method="POST" action="<?=h(quiz_url())?>">
        <div class="grid">
          <?php
            $nameAttr = ($currentKey==='cover') ? 'cover_type' : ($currentKey==='oldCover' ? 'old_cover' : $currentKey);
            foreach (($node['options'] ?? []) as $id=>$o):
              $minEur = opt_min_price($ak, $currentKey, $node, $id);
              $minMin = opt_min_duration($ak, $currentKey, $node, $id);
              $minPts = $minEur; // 1€ = 1 балл

              $showPrice  = opt_show_badge($node,$id,'show_badge_price', true);
              $showTime   = opt_show_badge($node,$id,'show_badge_time',  true);
              $showPoints = opt_show_badge($node,$id,'show_badge_points',true);

              $priceNote  = opt_badge_note($node,$id,'badge_price_note',  $lang);
              $timeNote   = opt_badge_note($node,$id,'badge_time_note',   $lang);
              $pointsNote = opt_badge_note($node,$id,'badge_points_note', $lang);

              $textRaw   = $o['text'] ?? '';
          ?>
            <label class="option">
              <input type="radio" name="<?= h($nameAttr) ?>" value="<?= h($id) ?>" onchange="this.form.submit()">
              <?php if (has_img($o)): ?>
                <div class="option__media">
                  <img loading="lazy" src="<?= h($o['image']) ?>" alt="" class="option-img"
                       onerror="this.closest('.option').classList.add('noimg'); this.remove();">
                </div>
              <?php endif; ?>
              <div class="chip">
                <div class="title"><strong><?= h(tr($textRaw, $lang, 'ru')) ?></strong></div>

                <?php if ($showPrice): ?>
                  <div class="price price--muted">
                    <?= h(t('from',$lang,$MSG)) ?> <?= (int)$minEur ?> € 
                    <?php if ($priceNote!==''): ?><span class="badge-note"><?= h($priceNote) ?></span><?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php if ($showTime): ?>
                  <div class="price price--muted">
                    ⏱ <?= h(t('from',$lang,$MSG)) ?> <?= h(fmt_duration_hm((int)$minMin,$lang)) ?>
                    <?php if ($timeNote!==''): ?><span class="badge-note"><?= h($timeNote) ?></span><?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php if ($showPoints): ?>
                  <div class="price price--muted">
                    🌸 <?= h(t('from',$lang,$MSG)) ?> +<?= (int)$minPts ?> <?=h(t('points',$lang,$MSG))?>
                    <?php if ($pointsNote!==''): ?><span class="badge-note"><?= h($pointsNote) ?></span><?php endif; ?>
                  </div>
                <?php endif; ?>

                <?php if (has_desc($o)): ?><a href="#" class="info-link" data-desc="<?= desc_html($o,$lang) ?>">Что входит в услугу?</a><?php endif; ?>
              </div>
            </label>
          <?php endforeach; ?>
        </div>
        <noscript><button class="btn" type="submit">OK</button></noscript>
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
/* Простая модалка «что входит» */
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

/* Подсветка выбранной карточки */
document.addEventListener('change', function(e){
  if (e.target.matches('input[type=radio]')){
    const card = e.target.closest('.option');
    const grid = e.target.closest('.grid');
    if (grid) grid.querySelectorAll('.option').forEach(el=>el.classList.remove('selected'));
    if (card) card.classList.add('selected');
  }
});

/* Мобильная модалка «Мой выбор» */
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