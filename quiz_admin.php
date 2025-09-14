<?php
/* ===========================================================
 * quiz_admin.php — админка квиза (обновлённая)
 * - Цены/длительности/баллы + диапазоны для мастеров
 * - Управление бейджами «от…» (€, ⏱, 🌸) + текст-пояснения к ним (i18n)
 * - Поиск / фильтр по категориям / развёртывание секций
 * - Тёмная тема, аккуратная вёрстка
 * =========================================================== */

$ADMIN_SESSION_TTL = 7200; // 2 часа

ini_set('session.gc_maxlifetime', $ADMIN_SESSION_TTL);
ini_set('session.cookie_lifetime', $ADMIN_SESSION_TTL);

session_set_cookie_params([
  'lifetime' => $ADMIN_SESSION_TTL,
  'path'     => '/',
  'domain'   => '',
  'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'httponly' => true,
  'samesite' => 'Lax',
]);

session_start();
// скользящее продление
if (session_status() === PHP_SESSION_ACTIVE) {
  setcookie(session_name(), session_id(), [
    'expires'  => time() + $ADMIN_SESSION_TTL,
    'path'     => '/',
    'domain'   => '',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}

/* ================== SETTINGS ================== */
const ADMIN_PASSWORD = 'change_me_please'; // замени
$CONFIG_FILE = __DIR__.'/quiz_config.json';
$IMAGES_DIR  = __DIR__.'/images';
$BACKUP_DIR  = __DIR__.'/backups';
/* ============================================== */

if (!is_dir($IMAGES_DIR)) @mkdir($IMAGES_DIR,0775,true);
if (!is_dir($BACKUP_DIR)) @mkdir($BACKUP_DIR,0775,true);

/* ============== AUTH ============== */
if (!isset($_SESSION['admin_ok'])) {
  $err='';
  if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['adm_login'])){
    if (hash_equals(ADMIN_PASSWORD, $_POST['password'] ?? '')) {
      $_SESSION['admin_ok']=true; header('Location: '.$_SERVER['PHP_SELF']); exit;
    } else $err='Неверный пароль';
  } ?>
  <!doctype html><html lang="ru"><head>
  <meta charset="utf-8"><title>Вход в админку квиза</title>
  <style>
    body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0b1020;color:#eaf0ff;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
    .card{background:#101737;border:1px solid rgba(255,255,255,.08);border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.35);padding:24px;width:360px}
    h1{margin:0 0 14px;font-size:18px}
    input[type=password]{width:100%;padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#0e1430;color:#fff}
    .btn{margin-top:10px;background:linear-gradient(90deg,#8b5cf6,#06b6d4);border:0;color:#fff;border-radius:999px;padding:10px 14px;cursor:pointer;width:100%}
    .err{color:#fecaca;margin:6px 0 0;font-size:13px}
  </style></head><body>
  <form class="card" method="post">
    <h1>Квиз — вход</h1>
    <?php if($err): ?><div class="err"><?=htmlspecialchars($err)?></div><?php endif; ?>
    <input type="password" name="password" placeholder="Пароль">
    <button class="btn" type="submit" name="adm_login" value="1">Войти</button>
  </form></body></html><?php
  exit;
}

/* ============== UTILS ============== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function load_config($file){ if(!is_file($file)) return []; $j=file_get_contents($file); $cfg=json_decode($j,true); return is_array($cfg)?$cfg:[]; }
function backup_config($src,$dir){ if (!is_file($src)) return; @mkdir($dir,0775,true); @copy($src, rtrim($dir,'/').'/quiz_config_'.date('Ymd_His').'.json'); }
function normalize_key($s){ $s=preg_replace('~[^a-z0-9_]+~i','_', (string)$s); $s=strtolower(trim($s,'_')); return $s ?: 'key_'.substr(md5(mt_rand()),0,6); }
function slugify($s){ $s=mb_strtolower($s,'UTF-8'); $s=preg_replace('~[^\pL\d]+~u','_', $s); $s=trim($s,'_'); $s=preg_replace('~_+~','_',$s); return $s?:'opt_'.substr(md5(mt_rand()),0,6); }
function norm_i18n($v,$LANGS){
  if(is_array($v)){ $res=[]; foreach($LANGS as $L){ $res[$L]=(string)($v[$L]??''); } return $res; }
  $res=[]; foreach($LANGS as $i=>$L){ $res[$L]=($i===0)?(string)$v:''; } return $res;
}
function i18n_pick($arr,$LANGS){
  if(is_array($arr)){ foreach([$LANGS[0]??'ru','ru','et','en'] as $k){ if(isset($arr[$k]) && trim((string)$arr[$k])!=='') return (string)$arr[$k]; }
    foreach($arr as $v){ if(trim((string)$v)!=='') return (string)$v; } }
  return (string)$arr;
}

/* ============== LOAD ============== */
$config = load_config($CONFIG_FILE);
if (!isset($config['languages']) || !is_array($config['languages']) || !$config['languages']) $config['languages']=['et','en','ru'];
$LANGS=$config['languages'];

if (!isset($config['areas']) || !is_array($config['areas'])) $config['areas']=[];
$ALLOWED_STEP_KEYS=['oldCover','service','cover','length','design','spa'];

function ensure_node(&$cfg,$area,$key){
  if(!isset($cfg[$area][$key])||!is_array($cfg[$area][$key])){
    $cfg[$area][$key]=['question'=>'','question_image'=>'','options'=>[]];
  } else {
    $cfg[$area][$key]+= ['question'=>'','question_image'=>'','options'=>[]];
  }
}
function ensure_default_steps(&$cfg,$areaKey){
  if (!isset($cfg[$areaKey]['steps']) || !is_array($cfg[$areaKey]['steps']) || !$cfg[$areaKey]['steps']) {
    $cfg[$areaKey]['steps']=[
      ['key'=>'oldCover','enabled'=>1,'order'=>1],
      ['key'=>'service','enabled'=>1,'order'=>2],
      ['key'=>'cover','enabled'=>1,'order'=>3,'show_if_service_in'=>['manicure_cover','pedicure_cover']],
      ['key'=>'length','enabled'=>1,'order'=>4,'show_if_service_in'=>['extensions_new','extensions_correction']],
      ['key'=>'design','enabled'=>1,'order'=>5,'hide_if_service_in'=>['classic']],
      ['key'=>'spa','enabled'=>1,'order'=>6],
    ];
  }
  foreach($cfg[$areaKey]['steps'] as &$s){
    $s['enabled']=isset($s['enabled'])?(int)$s['enabled']:1;
    $s['order']=isset($s['order'])?(int)$s['order']:0;
    $s['show_if_service_in']=$s['show_if_service_in']??[];
    $s['hide_if_service_in']=$s['hide_if_service_in']??[];
  } unset($s);
  global $ALLOWED_STEP_KEYS;
  foreach($ALLOWED_STEP_KEYS as $k) ensure_node($cfg,$areaKey,$k);
}
foreach(array_keys($config['areas']) as $ak) ensure_default_steps($config,$ak);

/* ============== SAVE ============== */
$flash='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_all'])) {
  backup_config($CONFIG_FILE,$BACKUP_DIR);
  $new=$config;

  // Языки
  if(isset($_POST['languages']) && is_array($_POST['languages'])){
    $langs=array_values(array_filter(array_map(fn($x)=>strtolower(trim($x)),$_POST['languages'])));
    $langs=array_values(array_unique(array_filter($langs,fn($x)=>preg_match('~^[a-z]{2}$~',$x))));
    if($langs) { $new['languages']=$langs; $LANGS=$langs; }
  }

  // Разделы: редактирование заголовков
  if (!empty($_POST['areas']) && is_array($_POST['areas'])) {
    foreach ($_POST['areas'] as $key=>$row){
      if(!isset($new['areas'][$key])) continue;
      if(isset($row['title'])) $new['areas'][$key]['title']=norm_i18n($row['title'],$LANGS);
    }
  }
  // Удаление разделов
  if(!empty($_POST['areas_delete']) && is_array($_POST['areas_delete'])){
    foreach($_POST['areas_delete'] as $delKey){
      $delKey=(string)$delKey;
      if(isset($new['areas'][$delKey])){ unset($new['areas'][$delKey], $new[$delKey]); }
    }
  }
  // Добавление раздела
  if(!empty($_POST['new_area_key'])){
    $akey=normalize_key($_POST['new_area_key']);
    if($akey!=='' && !isset($new['areas'][$akey])){
      $new['areas'][$akey]=['title'=>norm_i18n($_POST['new_area_title']??'',$LANGS)];
      ensure_default_steps($new,$akey);
    }
  }

  // Порядок/условия шагов
  foreach(array_keys($new['areas']) as $areaKey){
    $form=$_POST["steps_{$areaKey}"] ?? [];
    $steps=[];
    if(is_array($form)){
      foreach($form as $row){
        $k=trim((string)($row['key']??'')); if(!$k || !in_array($k,$ALLOWED_STEP_KEYS,true)) continue;
        $steps[]=[
          'key'=>$k,
          'enabled'=>!empty($row['enabled'])?1:0,
          'order'=>(int)($row['order'] ?? 0),
          'show_if_service_in'=>array_values(array_filter(array_map('trim', explode(',',(string)($row['show_if_service_in']??''))))),
          'hide_if_service_in'=>array_values(array_filter(array_map('trim', explode(',',(string)($row['hide_if_service_in']??''))))),
        ];
      }
    }
    if(!$steps) $steps=[['key'=>'service','enabled'=>1,'order'=>1]];
    usort($steps, fn($a,$b)=>($a['order']<=>$b['order']) ?: strcmp($a['key'],$b['key']));
    $new[$areaKey]['steps']=$steps;
  }

  // Контент шагов
  foreach(array_keys($new['areas']) as $areaKey){
    foreach($ALLOWED_STEP_KEYS as $stepKey){
      if(!isset($new[$areaKey][$stepKey]) || !is_array($new[$areaKey][$stepKey])) $new[$areaKey][$stepKey]=['question'=>'','question_image'=>'','options'=>[]];

      // Вопрос
      if(isset($_POST["q_{$areaKey}_{$stepKey}"])) $new[$areaKey][$stepKey]['question']=norm_i18n($_POST["q_{$areaKey}_{$stepKey}"],$LANGS);
      if(!empty($_POST["del_qimg_{$areaKey}_{$stepKey}"])){
        $old=$new[$areaKey][$stepKey]['question_image'] ?? '';
        if($old){ $abs=__DIR__.'/'.$old; if(is_file($abs)) @unlink($abs); }
        $new[$areaKey][$stepKey]['question_image']='';
      }

      // Удаление опций
      if(!empty($_POST['delete'][$areaKey][$stepKey])){
        foreach((array)$_POST['delete'][$areaKey][$stepKey] as $oid) unset($new[$areaKey][$stepKey]['options'][$oid]);
      }

      // Обновление опций
      $postKey="opt_{$areaKey}_{$stepKey}";
      if(!empty($_POST[$postKey]) && is_array($_POST[$postKey])){
        foreach($_POST[$postKey] as $id=>$row){
          if(!isset($new[$areaKey][$stepKey]['options'][$id])) continue;
          $o=&$new[$areaKey][$stepKey]['options'][$id];

          // Название/описание
          if(isset($row['text'])) $o['text']=norm_i18n($row['text'],$LANGS);
          if(isset($row['desc'])) $o['desc']=norm_i18n($row['desc'],$LANGS);

          // Основные поля
          if(array_key_exists('duration_min',$row)) $o['duration_min']=($row['duration_min'] === ''? 0 : (int)$row['duration_min']);
          if(array_key_exists('price',$row))        $o['price']=($row['price'] === ''? 0 : (float)$row['price']);
          if(array_key_exists('points',$row))       $o['points']=($row['points'] === ''? '' : (int)$row['points']); // пусто → «= цене» в квизе

          // Диапазоны для мастеров
          $o['limits']=[
            'price_min' => (isset($row['limits']['price_min']) && $row['limits']['price_min']!=='') ? (float)$row['limits']['price_min'] : null,
            'price_max' => (isset($row['limits']['price_max']) && $row['limits']['price_max']!=='') ? (float)$row['limits']['price_max'] : null,
            'dur_min'   => (isset($row['limits']['dur_min'])   && $row['limits']['dur_min']  !=='') ? (int)$row['limits']['dur_min']   : null,
            'dur_max'   => (isset($row['limits']['dur_max'])   && $row['limits']['dur_max']  !=='') ? (int)$row['limits']['dur_max']   : null,
          ];

          // Управление бейджами
          $o['show_badge_price']  = !empty($row['show_badge_price']) ? 1 : 0;
          $o['show_badge_time']   = !empty($row['show_badge_time'])  ? 1 : 0;
          $o['show_badge_points'] = !empty($row['show_badge_points'])? 1 : 0;

          // Тексты-пояснения к бейджам (i18n)
          if(isset($row['badge_price_note']))  $o['badge_price_note']  = norm_i18n($row['badge_price_note'],$LANGS);
          if(isset($row['badge_time_note']))   $o['badge_time_note']   = norm_i18n($row['badge_time_note'],$LANGS);
          if(isset($row['badge_points_note'])) $o['badge_points_note'] = norm_i18n($row['badge_points_note'],$LANGS);

          // Изображение/показы
          $o['hide_image']=!empty($row['hide_image'])?1:0;

          if(isset($row['sort'])) $o['_sort']=(int)$row['sort'];

          if(!empty($row['del_img'])){
            $old=$o['image']??''; if($old){$abs=__DIR__.'/'.$old; if(is_file($abs)) @unlink($abs);}
            $o['image']='';
          }
          unset($o);
        }
      }

      // Новая опция
      $addKey="add_{$areaKey}_{$stepKey}_name";
      if(!empty($_POST[$addKey])){
        $name=trim((string)$_POST[$addKey]);
        if($name!==''){
          $id=slugify($name);
          if(!isset($new[$areaKey][$stepKey]['options'][$id])){
            $new[$areaKey][$stepKey]['options'][$id]=[
              'text'=>norm_i18n(['ru'=>$name],$LANGS),
              'desc'=>norm_i18n('',$LANGS),
              'image'=>'', 'hide_image'=>0,
              'duration_min'=>0, 'price'=>0, 'points'=>'',
              'limits'=>['price_min'=>null,'price_max'=>null,'dur_min'=>null,'dur_max'=>null],
              'show_badge_price'=>1,'show_badge_time'=>1,'show_badge_points'=>1,
              'badge_price_note'=>norm_i18n('',$LANGS),
              'badge_time_note'=>norm_i18n('',$LANGS),
              'badge_points_note'=>norm_i18n('',$LANGS),
            ];
          }
        }
      }

      // Картинки
      $qKey="img_q_{$areaKey}_{$stepKey}";
      if(isset($_FILES[$qKey]) && $_FILES[$qKey]['error']===UPLOAD_ERR_OK){
        $ext=strtolower(pathinfo($_FILES[$qKey]['name'],PATHINFO_EXTENSION));
        if(in_array($ext,['png','jpg','jpeg','gif','webp'])){
          foreach(glob($IMAGES_DIR."/{$areaKey}_{$stepKey}_question.*") as $old) @unlink($old);
          $to=$IMAGES_DIR."/{$areaKey}_{$stepKey}_question.".($ext==='jpeg'?'jpg':$ext);
          move_uploaded_file($_FILES[$qKey]['tmp_name'],$to);
          $new[$areaKey][$stepKey]['question_image']='images/'.basename($to);
        }
      }
      foreach(($new[$areaKey][$stepKey]['options']??[]) as $id=>&$opt){
        $fKey="img_{$areaKey}_{$stepKey}_{$id}";
        if(isset($_FILES[$fKey]) && $_FILES[$fKey]['error']===UPLOAD_ERR_OK){
          $ext=strtolower(pathinfo($_FILES[$fKey]['name'],PATHINFO_EXTENSION));
          if(in_array($ext,['png','jpg','jpeg','gif','webp'])){
            if(!empty($opt['image'])){ $abs=__DIR__.'/'.$opt['image']; if(is_file($abs)) @unlink($abs); }
            $to=$IMAGES_DIR."/{$areaKey}_{$stepKey}_{$id}.".($ext==='jpeg'?'jpg':$ext);
            move_uploaded_file($_FILES[$fKey]['tmp_name'],$to);
            $opt['image']='images/'.basename($to);
          }
        }
      } unset($opt);

      // Сортировка опций
      if(!empty($new[$areaKey][$stepKey]['options'])){
        $ops=&$new[$areaKey][$stepKey]['options'];
        uasort($ops, fn($a,$b)=>(($a['_sort']??0) <=> ($b['_sort']??0)));
        foreach($ops as &$o) unset($o['_sort']); unset($ops,$o);
      }
    }
  }

  file_put_contents($CONFIG_FILE, json_encode($new, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  $config=$new;
  $flash='Изменения сохранены.';
}

/* ============== VIEW HELPERS ============== */
function intInput($name,$val,$min='0',$w=120){ return '<input type="number" step="1" min="'.h($min).'" name="'.$name.'" value="'.h((string)(is_numeric($val)?(int)$val:0)).'" style="width:'.$w.'px">'; }
function numInput($name,$val,$step='0.1',$min='0',$w=120){ $v=(is_numeric($val)?(string)+$val:(string)$val); return '<input type="number" step="'.h($step).'" min="'.h($min).'" name="'.$name.'" value="'.h($v).'" style="width:'.$w.'px">'; }
function textInput($name,$val,$w=420){
  return '<input type="text" name="'.$name.'" value="'.h($val).'" '
    .'style="max-width:'.$w.'px;width:100%;flex:1;min-width:0">';
}
function textarea($name,$val,$w=520,$h=70){ return '<textarea name="'.$name.'" style="width:'.$w.'px;height:'.$h.'px">'.h($val).'</textarea>'; }
function i18nInputs($base,$valArr,$LANGS,$w=420){
  $html=''; foreach($LANGS as $L){ $val=is_array($valArr)?($valArr[$L]??''):(string)$valArr; $html.='<div class="i18n-line"><span class="tag">'.strtoupper(h($L)).'</span> '.textInput($base.'['.$L.']',$val,$w).'</div>'; }
  return $html;
}

$areas = $config['areas'];
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Админка квиза</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root{
    --bg:#0b1020; --bg2:#111735; --card:#0f1533; --ring:rgba(255,255,255,.12);
    --text:#eaf0ff; --muted:#9fb2ff; --chip:#1b2038; --accent:#7C3AED; --accent2:#06B6D4;
    --ok:#22C55E; --danger:#EF4444; --radius:14px; --shadow:0 12px 40px rgba(0,0,0,.35);
  }
  *{box-sizing:border-box}
  body{margin:0;background:radial-gradient(80% 100% at 20% 0%,#1a1f3e 0%,#0b1020 60%) fixed;color:var(--text);
       font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  .wrap{max-width:1200px;margin:0 auto;padding:18px}
  h1{margin:8px 0 14px;font-size:22px}
  .panel{background:linear-gradient(180deg,#12183a 0%, #0e1430 100%);border:1px solid var(--ring);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px;margin:12px 0}
  .row{margin:8px 0}
  .muted{color:#b7c4ff}
  .chip{display:inline-block;border:1px solid var(--ring);border-radius:999px;padding:6px 10px;background:#0e1430b3}
  .btn{display:inline-block;border-radius:999px;border:1px solid var(--ring);background:#0e1430;color:#fff;padding:10px 14px;text-decoration:none;cursor:pointer}
  .btn.ghost{background:transparent}
  .btn.cta{background:linear-gradient(90deg,#8b5cf6,#06b6d4);border:0}
  .grid{display:grid;gap:14px}
  .two{grid-template-columns:1fr 1fr}
  .card{background:#0e1430;border:1px solid var(--ring);border-radius:16px;padding:14px}
  .flash{background:#064e3b;border:1px solid #10b981;color:#eafff3;padding:10px 12px;border-radius:10px;margin:10px 0}
  .area-head{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .area-badge{border:1px solid var(--ring);background:#0e1430b3;padding:8px 12px;border-radius:999px}
  .step-card{border:1px dashed var(--ring);border-radius:12px;padding:10px;margin:10px 0;background:#0d1330}
  .opt{border:1px dashed var(--ring);border-radius:12px;padding:10px;margin:8px 0;background:#0d1330}
  .opt .hdr{display:flex;gap:10px;align-items:center;margin-bottom:8px}
  .opt code{border:1px solid var(--ring);background:#0e1430b3;border-radius:8px;padding:2px 6px}
  .i18n-line{display:flex;gap:8px;align-items:center;margin:4px 0;flex-wrap:wrap}
  .tag{display:inline-block;border:1px solid var(--ring);border-radius:8px;padding:2px 6px;background:#0e1430b3;font-size:12px}
  input[type=text],input[type=number],textarea,select{background:#0b112d;border:1px solid #243354;color:#eaf0ff;border-radius:10px;padding:8px}
  input[type=file]{color:#cdd7ff}
  label.small{color:#9fb2ff;display:inline-block;min-width:160px}
  .toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .toolbar .search{flex:1;min-width:260px}
  .inline{display:inline-flex;gap:10px;align-items:center}
  .tiny{font-size:12px;color:#9fb2ff}
  .danger{color:#fecaca}
  .sp{height:10px}
  .collapse-toggle{margin-left:auto}
  details > summary{cursor:pointer;list-style:none}
  details > summary::-webkit-details-marker{display:none}
  .row.badges{display:grid;grid-template-columns:repeat(3, minmax(240px, 1fr));gap:10px}
  .row.badges label{display:flex;align-items:center;gap:6px;cursor:pointer}
  .row.badges .badge-inputs{margin-top:6px;display:flex;flex-direction:column;gap:4px}
  .row.badges .badge-inputs .i18n-line{margin:0}
  @media(max-width:980px){ .row.badges{grid-template-columns:1fr} .two{grid-template-columns:1fr} }
</style>
</head>
<body>
<div class="wrap">
  <h1>Квиз — админ</h1>

  <?php if($flash): ?><div class="flash"><?=h($flash)?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" id="formAll">

    <!-- Панель инструментов -->
    <div class="panel toolbar">
      <input id="search" class="search" type="text" placeholder="Поиск по названию или ID…">
      <select id="areaFilter">
        <option value="">Все категории</option>
        <?php foreach ($areas as $key=>$meta): ?>
          <option value="<?=h($key)?>"><?=h(is_array($meta['title']??null)?($meta['title'][$LANGS[0]]??strtoupper($key)):($meta['title']??strtoupper($key)))?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="btn" id="expandAll">Развернуть всё</button>
      <button type="button" class="btn" id="collapseAll">Свернуть всё</button>
      <a class="btn cta" href="quiz.php" target="_blank">Предпросмотр квиза</a>
    </div>

    <!-- Языки -->
    <div class="panel">
      <div class="row"><strong>Языки интерфейса</strong> <span class="tiny">первый — язык по умолчанию</span></div>
      <div class="inline" style="flex-wrap:wrap;gap:8px">
        <?php for($i=0;$i<max(3,count($LANGS));$i++): $val=$LANGS[$i]??''; ?>
          <input type="text" name="languages[]" value="<?=h($val)?>" maxlength="2" placeholder="ru" style="width:60px;text-align:center">
        <?php endfor; ?>
      </div>
    </div>

    <!-- Разделы -->
    <div class="panel">
      <div class="row"><strong>Разделы (категории)</strong></div>
      <div class="grid">
        <?php foreach ($areas as $key=>$meta): ?>
          <div class="card">
            <div class="area-head">
              <div class="area-badge"><?=h(is_array($meta['title']??null)?($meta['title'][$LANGS[0]]??strtoupper($key)):($meta['title']??strtoupper($key)))?></div>
              <span class="chip"><?=h($key)?></span>
              <label class="tiny"><input type="checkbox" name="areas_delete[]" value="<?=h($key)?>"> удалить</label>
              <button class="btn ghost collapse-toggle" type="button" data-target="#steps-<?=h($key)?>">Развернуть</button>
            </div>
            <div class="sp"></div>
            <label class="small">Заголовок (i18n):</label>
            <div><?=i18nInputs("areas[{$key}][title]", $meta['title'] ?? strtoupper($key), $LANGS, 360)?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="card">
        <div class="row"><strong>Добавить раздел</strong></div>
        <div class="row"><label class="small">Ключ:</label> <input type="text" name="new_area_key" placeholder="например: brows" style="width:260px"></div>
        <div class="row"><label class="small">Заголовок (i18n):</label> <?=i18nInputs("new_area_title",'', $LANGS, 360)?></div>
      </div>
    </div>

    <!-- Порядок шагов + Контент -->
    <?php foreach ($areas as $areaKey=>$meta): ?>
      <details class="panel area-section" id="steps-<?=$areaKey?>">
        <summary class="inline"><strong><?=h(is_array($meta['title']??null)?($meta['title'][$LANGS[0]]??strtoupper($areaKey)):($meta['title']??strtoupper($areaKey)))?></strong> <span class="chip"><?=h($areaKey)?></span></summary>
        <div class="sp"></div>

        <!-- Порядок шагов -->
        <div class="card">
          <div class="row tiny">Типы: oldCover, service, cover, length, design, spa</div>
          <table style="width:100%;border-collapse:collapse">
            <thead>
              <tr>
                <th style="text-align:left;border-bottom:1px solid var(--ring);padding:6px 8px">Тип</th>
                <th style="text-align:left;border-bottom:1px solid var(--ring);padding:6px 8px">Вкл</th>
                <th style="text-align:left;border-bottom:1px solid var(--ring);padding:6px 8px">Порядок</th>
                <th style="text-align:left;border-bottom:1px solid var(--ring);padding:6px 8px">show_if_service_in</th>
                <th style="text-align:left;border-bottom:1px solid var(--ring);padding:6px 8px">hide_if_service_in</th>
              </tr>
            </thead>
            <tbody>
              <?php $idx=0; foreach(($config[$areaKey]['steps'] ?? []) as $s): $idx++; ?>
                <tr>
                  <td style="padding:6px 8px">
                    <select name="steps_<?=$areaKey?>[<?=$idx?>][key]">
                      <?php foreach ($ALLOWED_STEP_KEYS as $k): ?>
                        <option value="<?=$k?>" <?=$k===($s['key']??'')?'selected':''?>><?=$k?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td style="padding:6px 8px"><input type="checkbox" name="steps_<?=$areaKey?>[<?=$idx?>][enabled]" value="1" <?=!empty($s['enabled'])?'checked':''?>></td>
                  <td style="padding:6px 8px"><input type="number" name="steps_<?=$areaKey?>[<?=$idx?>][order]" value="<?=h($s['order'] ?? $idx)?>" style="width:90px"></td>
                  <td style="padding:6px 8px"><input type="text" name="steps_<?=$areaKey?>[<?=$idx?>][show_if_service_in]" value="<?=h(isset($s['show_if_service_in'])?(is_array($s['show_if_service_in'])?implode(',',$s['show_if_service_in']):$s['show_if_service_in']):'')?>" style="width:260px"></td>
                  <td style="padding:6px 8px"><input type="text" name="steps_<?=$areaKey?>[<?=$idx?>][hide_if_service_in]" value="<?=h(isset($s['hide_if_service_in'])?(is_array($s['hide_if_service_in'])?implode(',',$s['hide_if_service_in']):$s['hide_if_service_in']):'')?>" style="width:260px"></td>
                </tr>
              <?php endforeach; ?>
              <tr>
                <td style="padding:6px 8px">
                  <select name="steps_<?=$areaKey?>[new][key]">
                    <option value="">— добавить шаг —</option>
                    <?php foreach ($ALLOWED_STEP_KEYS as $k): ?><option value="<?=$k?>"><?=$k?></option><?php endforeach; ?>
                  </select>
                </td>
                <td style="padding:6px 8px"><input type="checkbox" name="steps_<?=$areaKey?>[new][enabled]" value="1" checked></td>
                <td style="padding:6px 8px"><input type="number" name="steps_<?=$areaKey?>[new][order]" value="<?=100+$idx?>" style="width:90px"></td>
                <td style="padding:6px 8px"><input type="text" name="steps_<?=$areaKey?>[new][show_if_service_in]" placeholder="например: manicure_cover" style="width:260px"></td>
                <td style="padding:6px 8px"><input type="text" name="steps_<?=$areaKey?>[new][hide_if_service_in]" placeholder="например: classic" style="width:260px"></td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Контент шагов -->
        <?php foreach ($ALLOWED_STEP_KEYS as $stepKey):
          $node = $config[$areaKey][$stepKey] ?? ['question'=>'','question_image'=>'','options'=>[]];
        ?>
          <div class="step-card">
            <h3 style="margin:0 0 6px"><?=strtoupper($stepKey)?></h3>
            <div class="row">
              <label class="small">Текст вопроса (i18n):</label>
              <div><?=i18nInputs("q_{$areaKey}_{$stepKey}", $node['question'] ?? '', $LANGS, 520)?></div>
            </div>
            <div class="row">
              <label class="small">Картинка вопроса:</label>
              <?php if(!empty($node['question_image'])): ?>
                <img src="<?=h($node['question_image'])?>" alt="" style="max-height:60px;border-radius:8px;margin-right:8px;border:1px solid var(--ring)">
              <?php endif; ?>
              <input type="file" name="img_q_<?=$areaKey?>_<?=$stepKey?>">
              <?php if(!empty($node['question_image'])): ?>
                <label style="margin-left:10px" class="tiny"><input type="checkbox" name="del_qimg_<?=$areaKey?>_<?=$stepKey?>" value="1"> удалить</label>
              <?php endif; ?>
            </div>

            <?php if (!empty($node['options'])): ?>
              <?php foreach ($node['options'] as $id=>$o): ?>
                <div class="opt" data-area="<?=$areaKey?>" data-step="<?=$stepKey?>" data-id="<?=$id?>">
                  <div class="hdr">
                    <input type="number" class="sort" name="opt_<?=$areaKey?>_<?=$stepKey?>[<?=$id?>][sort]" value="<?=h($o['_sort'] ?? 0)?>" title="Порядок" style="width:80px">
                    <code><?=h($id)?></code>
                    <label class="tiny"><input type="checkbox" name="delete[<?=$areaKey?>][<?=$stepKey?>][]" value="<?=h($id)?>"> удалить</label>
                  </div>

                  <div class="row"><label class="small">Название (i18n):</label><?=i18nInputs("opt_{$areaKey}_{$stepKey}[{$id}][text]", $o['text'] ?? '', $LANGS, 520)?></div>

                  <div class="grid two">
                    <div class="card">
                      <div class="row"><label class="small">Длительность (мин):</label><?=intInput("opt_{$areaKey}_{$stepKey}[{$id}][duration_min]", ($o['duration_min'] ?? 0), 0)?></div>
                      <div class="row"><label class="small">Цена (€):</label><?=numInput("opt_{$areaKey}_{$stepKey}[{$id}][price]", $o['price'] ?? 0, '0.01','0')?></div>
                      <div class="row"><label class="small">Баллы:</label><?=intInput("opt_{$areaKey}_{$stepKey}[{$id}][points]", ($o['points'] ?? ''), 0)?> <span class="tiny">пусто → = цене (1:1)</span></div>
                    </div>
                    <div class="card">
                      <div class="row"><strong>Диапазоны для мастеров</strong> <span class="tiny">(quiz_pricing.php)</span></div>
                      <div class="row">
                        <label class="small">Цена (€), от/до:</label>
                        <?=numInput("opt_{$areaKey}_{$stepKey}[{$id}][limits][price_min]", $o['limits']['price_min'] ?? '', '0.01','0',110)?>
                        <?=numInput("opt_{$areaKey}_{$stepKey}[{$id}][limits][price_max]", $o['limits']['price_max'] ?? '', '0.01','0',110)?>
                        <span class="tiny">пусто = без ограничений</span>
                      </div>
                      <div class="row">
                        <label class="small">Время (мин), от/до:</label>
                        <?=intInput("opt_{$areaKey}_{$stepKey}[{$id}][limits][dur_min]", $o['limits']['dur_min'] ?? '', 0,110)?>
                        <?=intInput("opt_{$areaKey}_{$stepKey}[{$id}][limits][dur_max]", $o['limits']['dur_max'] ?? '', 0,110)?>
                        <span class="tiny">пусто = без ограничений</span>
                      </div>
                    </div>
                  </div>

                  <div class="card">
                    <div class="row"><strong>Бейджи «от…»</strong></div>
                    <div class="row badges">
                      <div>
                        <label class="tiny"><input type="checkbox" name="opt_<?=$areaKey?>_<?=$stepKey?>[<?=$id?>][show_badge_price]" value="1" <?=!empty($o['show_badge_price'])?'checked':''?>> показывать «€»</label>
                        <div class="badge-inputs">
                          <span class="tiny" style="min-width:160px">Текст к цене:</span>
                          <?=i18nInputs("opt_{$areaKey}_{$stepKey}[{$id}][badge_price_note]", $o['badge_price_note'] ?? '', $LANGS, 360)?>
                        </div>
                      </div>
                      <div>
                        <label class="tiny"><input type="checkbox" name="opt_<?=$areaKey?>_<?=$stepKey?>[<?=$id?>][show_badge_time]" value="1" <?=!empty($o['show_badge_time'])?'checked':''?>> показывать «⏱»</label>
                        <div class="badge-inputs">
                          <span class="tiny" style="min-width:160px">Текст ко времени:</span>
                          <?=i18nInputs("opt_{$areaKey}_{$stepKey}[{$id}][badge_time_note]", $o['badge_time_note'] ?? '', $LANGS, 360)?>
                        </div>
                      </div>
                      <div>
                        <label class="tiny"><input type="checkbox" name="opt_<?=$areaKey?>_<?=$stepKey?>[<?=$id?>][show_badge_points]" value="1" <?=!empty($o['show_badge_points'])?'checked':''?>> показывать «🌸»</label>
                        <div class="badge-inputs">
                          <span class="tiny" style="min-width:160px">Текст к баллам:</span>
                          <?=i18nInputs("opt_{$areaKey}_{$stepKey}[{$id}][badge_points_note]", $o['badge_points_note'] ?? '', $LANGS, 360)?>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="row"><label class="small">Описание (i18n):</label>
                    <?php foreach ($LANGS as $L): ?>
                      <div class="i18n-line" style="align-items:flex-start">
                        <span class="tag"><?=strtoupper(h($L))?></span>
                        <?=textarea("opt_{$areaKey}_{$stepKey}[{$id}][desc][{$L}]", (is_array($o['desc']??'')?($o['desc'][$L]??''):($L===$LANGS[0]?($o['desc']??''):'')), 520, 60)?>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <div class="row">
                    <label class="small">Картинка:</label>
                    <?php if(!empty($o['image'])): ?>
                      <img src="<?=h($o['image'])?>" alt="" style="max-height:60px;border-radius:8px;margin-right:8px;border:1px solid var(--ring)">
                    <?php endif; ?>
                    <input type="file" name="img_<?=$areaKey?>_<?=$stepKey?>_<?=$id?>">
                    <?php if(!empty($o['image'])): ?>
                      <label class="tiny" style="margin-left:10px"><input type="checkbox" name="opt_<?=$areaKey?>_<?=$stepKey?>[<?=$id?>][del_img]" value="1"> удалить</label>
                    <?php endif; ?>
                    <label class="tiny" style="margin-left:14px"><input type="checkbox" name="opt_<?=$areaKey?>_<?=$stepKey?>[<?=$id?>][hide_image]" value="1" <?=!empty($o['hide_image'])?'checked':''?>> скрыть изображение</label>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <div class="row inline" style="gap:8px;flex-wrap:wrap">
              <input type="text" name="add_<?=$areaKey?>_<?=$stepKey?>_name" placeholder="Добавить новую опцию (введите название)" style="width:360px">
              <button class="btn ghost" type="submit" name="save_all" value="1">+ Добавить</button>
            </div>
          </div>
        <?php endforeach; ?>
      </details>
    <?php endforeach; ?>

    <div class="panel inline" style="gap:10px;flex-wrap:wrap">
      <button class="btn cta" type="submit" name="save_all" value="1">Сохранить все изменения</button>
      <a class="btn" href="quiz.php" target="_blank">Предпросмотр квиза</a>
      <a class="btn danger" href="?logout=1" onclick="return confirm('Выйти из админки?');">Выйти</a>
    </div>
  </form>
</div>

<script>
  // Поиск + фильтр категорий + раскрытие/сворачивание
  (function(){
    const q = document.getElementById('search');
    const filter = document.getElementById('areaFilter');
    const sections = Array.from(document.querySelectorAll('.area-section'));
    const expandAllBtn = document.getElementById('expandAll');
    const collapseAllBtn = document.getElementById('collapseAll');
    const toggles = Array.from(document.querySelectorAll('.collapse-toggle'));

    function areaOf(el){
      const id = el.id || '';
      const m = id.match(/^steps\-(.+)$/);
      return m ? m[1] : '';
    }

    function matchText(sec, text){
      if (!text) return true;
      text = text.toLowerCase();
      // ищем по заголовкам, id опций и названию
      const idMatch = sec.querySelectorAll('.opt .hdr code');
      for (const c of idMatch) if (c.textContent.toLowerCase().includes(text)) return true;
      const titles = sec.querySelectorAll('.opt .i18n-line input[type="text"]');
      for (const inp of titles) if (inp.value && inp.value.toLowerCase().includes(text)) return true;
      return false;
    }

    function apply(){
      const t = (q.value||'').trim().toLowerCase();
      const area = filter.value;
      for (const sec of sections){
        const a = areaOf(sec);
        const okArea = !area || a === area;
        const okText = matchText(sec, t);
        sec.style.display = (okArea && okText) ? '' : 'none';
      }
    }
    q.addEventListener('input', apply);
    q.addEventListener('keyup', (e)=>{ if(e.key==='Enter'){ e.preventDefault(); apply(); }});
    filter.addEventListener('change', apply);
    apply();

    expandAllBtn.addEventListener('click', ()=>sections.forEach(s=>s.open=true));
    collapseAllBtn.addEventListener('click', ()=>sections.forEach(s=>s.open=false));
    toggles.forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const trg = document.querySelector(btn.dataset.target);
        if (!trg) return;
        trg.open = !trg.open;
      });
    });
  })();
</script>
</body>
</html>
<?php
// logout
if (isset($_GET['logout'])) {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
  header('Location: ' . strtok($_SERVER['REQUEST_URI'],'?'));
  exit;
}