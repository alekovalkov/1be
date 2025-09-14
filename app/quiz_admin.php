<?php
// ==== ADMIN SESSION: 2 часа ====
$ADMIN_SESSION_TTL = 7200;

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

// Скользящее продление
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

/* ============ SETTINGS ============ */
const ADMIN_PASSWORD = 'change_me_please'; // поменяйте
$CONFIG_FILE = __DIR__.'/quiz_config.json';
$IMAGES_DIR  = __DIR__.'/images';
$BACKUP_DIR  = __DIR__.'/backups';
/* ================================== */

if (!is_dir($IMAGES_DIR)) @mkdir($IMAGES_DIR,0775,true);
if (!is_dir($BACKUP_DIR)) @mkdir($BACKUP_DIR,0775,true);

/* ---------- auth ---------- */
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
  body{font-family:Arial,Helvetica,sans-serif;background:#f6f7fb;margin:0;padding:40px;display:flex;align-items:center;justify-content:center}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;max-width:380px;width:100%;padding:24px;box-shadow:0 10px 30px rgba(0,0,0,.06)}
  h1{margin:0 0 16px;font-size:20px}.field{margin-bottom:12px}
  input[type=password]{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px}
  .btn{display:inline-block;border:0;border-radius:8px;padding:10px 14px;background:#111827;color:#fff;cursor:pointer}
  .err{color:#b91c1c;margin-bottom:10px}
  </style></head><body>
  <form class="card" method="post">
    <h1>Админка квиза</h1>
    <?php if($err): ?><div class="err"><?=htmlspecialchars($err)?></div><?php endif; ?>
    <div class="field"><input type="password" name="password" placeholder="Пароль"></div>
    <button class="btn" type="submit" name="adm_login" value="1">Войти</button>
  </form></body></html><?php
  exit;
}

/* ---------- utils ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function load_config($file){
  if (!file_exists($file)) return [];
  $j=file_get_contents($file); $cfg=json_decode($j,true);
  return is_array($cfg)?$cfg:[];
}
function slugify($s){
  $s=mb_strtolower($s,'UTF-8');
  $s=preg_replace('~[^\pL\d]+~u','_',$s);
  $s=trim($s,'_'); $s=preg_replace('~_+~','_',$s);
  return $s!==''?$s:'opt_'.substr(md5(mt_rand()),0,6);
}
function normalize_key($s){
  $s = preg_replace('~[^a-z0-9_]+~i','_', (string)$s);
  $s = strtolower(trim($s,'_'));
  return $s;
}
function backup_config($src,$backupDir){
  if (!file_exists($src)) return;
  $name='quiz_config_'.date('Ymd_His').'.json';
  @copy($src, rtrim($backupDir,'/').'/'.$name);
}
function norm_i18n($v, $LANGS){
  if (is_array($v)) {
    $res=[]; foreach ($LANGS as $L){ $res[$L] = (string)($v[$L] ?? ''); }
    return $res;
  }
  // если пришла строка — кладём в первый язык
  $res=[]; foreach ($LANGS as $i=>$L){ $res[$L] = ($i===0)? (string)$v : ''; }
  return $res;
}

/* ---------- load ---------- */
$config = load_config($CONFIG_FILE);
if (!isset($config['languages']) || !is_array($config['languages']) || !$config['languages']) {
  $config['languages'] = ['ru','et','en']; // по умолчанию
}
$LANGS = $config['languages'];
$ALLOWED_STEP_KEYS = ['oldCover','service','cover','length','design','spa'];

/* Корень с разделами услуг */
if (!isset($config['areas']) || !is_array($config['areas'])) {
  $config['areas'] = [];
  if (isset($config['manicure'])) $config['areas']['manicure'] = ['title'=>'MANICURE'];
  if (isset($config['pedicure'])) $config['areas']['pedicure'] = ['title'=>'PEDICURE'];
}

/* Узел area (главная) */
if (!isset($config['area']) || !is_array($config['area'])) {
  $config['area'] = ['question'=>'', 'question_image'=>'', 'options'=>[]];
} else {
  $config['area'] += ['question'=>'', 'question_image'=>'', 'options'=>[]];
}

function ensure_node(&$cfg,$area,$key){
  if (!isset($cfg[$area][$key])) $cfg[$area][$key]=['question'=>'','question_image'=>'','options'=>[]];
  else $cfg[$area][$key]+= ['question'=>'','question_image'=>'','options'=>[]];
}
function ensure_default_steps(&$cfg,$areaKey,$allowed){
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
  global $ALLOWED_STEP_KEYS;
  foreach ($ALLOWED_STEP_KEYS as $k) ensure_node($cfg,$areaKey,$k);
}

/* Инициализируем ноды для всех существующих разделов */
foreach (array_keys($config['areas']) as $areaKey) ensure_default_steps($config,$areaKey,$ALLOWED_STEP_KEYS);

/* ---------- save handler ---------- */
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {

  backup_config($CONFIG_FILE, $BACKUP_DIR);
  $new = $config;

  // (опционально) обновление списка языков из формы
  if (isset($_POST['languages']) && is_array($_POST['languages'])) {
    $langs = array_values(array_filter(array_map(function($x){ return strtolower(trim($x)); }, $_POST['languages'])));
    $langs = array_unique(array_filter($langs, function($x){ return preg_match('~^[a-z]{2}$~',$x); }));
    if ($langs) { $new['languages'] = $langs; $LANGS=$langs; }
  }

  /* === 0. Менеджер разделов (areas) === */
  // Обновление заголовков (i18n)
  if (!empty($_POST['areas']) && is_array($_POST['areas'])) {
    foreach ($_POST['areas'] as $key => $row) {
      if (!isset($new['areas'][$key])) continue;
      if (isset($row['title'])) {
        $new['areas'][$key]['title'] = norm_i18n($row['title'], $LANGS);
      }
    }
  }
  // Удаление разделов
  $deletedAreas = [];
  if (!empty($_POST['areas_delete']) && is_array($_POST['areas_delete'])) {
    foreach ($_POST['areas_delete'] as $delKey) {
      $delKey = (string)$delKey;
      if (isset($new['areas'][$delKey])) {
        $deletedAreas[$delKey] = true;
        unset($new['areas'][$delKey], $new[$delKey]);
      }
    }
  }
  // Добавление нового раздела
  if (!empty($_POST['new_area_key'])) {
    $akey = normalize_key($_POST['new_area_key']);
    if ($akey !== '' && !isset($new['areas'][$akey])) {
      $titleArr = norm_i18n(($_POST['new_area_title'] ?? ''), $LANGS);
      $new['areas'][$akey] = ['title'=>$titleArr];
      ensure_default_steps($new,$akey,$ALLOWED_STEP_KEYS);
    }
  }
  $validAreas = array_keys($new['areas']);

  /* === 1. Главная (вопрос/картинка/опции + привязки к areas) === */
  if (isset($_POST["q_area"])) $new['area']['question'] = norm_i18n($_POST["q_area"], $LANGS);
  if (!empty($_POST["del_qimg_area"])) {
    $old = $new['area']['question_image'] ?? '';
    if ($old) { $abs = __DIR__ . '/' . $old; if (is_file($abs)) @unlink($abs); }
    $new['area']['question_image'] = '';
  }
  if (!isset($new['area']['options']) || !is_array($new['area']['options'])) $new['area']['options'] = [];

  $deleteAreaButtons = [];
  if (!empty($_POST['delete_area'])) {
    foreach ((array)$_POST['delete_area'] as $optId) $deleteAreaButtons[$optId] = true;
  }

  if (!empty($_POST['opt_area']) && is_array($_POST['opt_area'])) {
    foreach ($_POST['opt_area'] as $id=>$row) {
      if (!isset($new['area']['options'][$id])) continue;
      if (!empty($deleteAreaButtons[$id])) { unset($new['area']['options'][$id]); continue; }

      $o=&$new['area']['options'][$id];
      if (isset($row['text'])) $o['text']=norm_i18n($row['text'], $LANGS);
      if (isset($row['desc'])) $o['desc']=norm_i18n($row['desc'], $LANGS);
      $o['hide_image']=!empty($row['hide_image'])?1:0;
      if (isset($row['sort'])) $o['_sort']=(int)$row['sort'];

      // привязка к areas: нормализация + очистка невалидных
      $areasStr = (string)($row['areas'] ?? '');
      $arr = array_values(array_filter(array_map(function($x){ return normalize_key($x); }, explode(',', $areasStr))));
      $arr = array_values(array_filter($arr, function($k) use ($validAreas){ return in_array($k,$validAreas,true); }));
      $o['areas'] = $arr;

      if (empty($o['areas'])) { unset($new['area']['options'][$id]); continue; }

      if (!empty($row['del_img'])) {
        $old=$o['image']??''; if($old){$abs=__DIR__.'/'.$old; if(is_file($abs)) @unlink($abs);}
        $o['image']='';
      }
      unset($o);
    }
  }

  foreach ($new['area']['options'] as $id=>&$opt){
    if (!empty($opt['areas']) && is_array($opt['areas'])) {
      $opt['areas'] = array_values(array_filter(array_map('normalize_key',$opt['areas']), function($k) use ($validAreas){ return in_array($k,$validAreas,true); }));
    }
    if (empty($opt['areas'])) { unset($new['area']['options'][$id]); continue; }

    $f="img_area_{$id}";
    if (isset($_FILES[$f]) && $_FILES[$f]['error']===UPLOAD_ERR_OK){
      $ext=strtolower(pathinfo($_FILES[$f]['name'],PATHINFO_EXTENSION));
      if (in_array($ext,['png','jpg','jpeg','gif','webp'])){
        if(!empty($opt['image'])){$abs=__DIR__.'/'.$opt['image']; if(is_file($abs)) @unlink($abs);}
        $to=$IMAGES_DIR."/area_{$id}.".($ext==='jpeg'?'jpg':$ext);
        move_uploaded_file($_FILES[$f]['tmp_name'],$to);
        $opt['image']='images/'.basename($to);
      }
    }
  } unset($opt);

  if (isset($_FILES['img_q_area']) && $_FILES['img_q_area']['error']===UPLOAD_ERR_OK){
    $ext=strtolower(pathinfo($_FILES['img_q_area']['name'],PATHINFO_EXTENSION));
    if (in_array($ext,['png','jpg','jpeg','gif','webp'])){
      foreach (glob($IMAGES_DIR."/area_question.*") as $old) @unlink($old);
      $to=$IMAGES_DIR."/area_question.".($ext==='jpeg'?'jpg':$ext);
      move_uploaded_file($_FILES['img_q_area']['tmp_name'],$to);
      $new['area']['question_image']='images/'.basename($to);
    }
  }

  if (!empty($new['area']['options'])){
    $ops=&$new['area']['options'];
    uasort($ops,function($a,$b){ $sa=$a['_sort']??0; $sb=$b['_sort']??0; return $sa<=>$sb; });
    foreach ($ops as &$o) unset($o['_sort']);
    unset($ops,$o);
  }

  /* === 2. Порядок/условия шагов — по всем разделам === */
  foreach (array_keys($new['areas']) as $areaKey){
    $formSteps = $_POST["steps_{$areaKey}"] ?? [];
    $steps = [];
    if (is_array($formSteps)){
      foreach ($formSteps as $row){
        $key = trim((string)($row['key'] ?? ''));
        if (!$key || !in_array($key,$ALLOWED_STEP_KEYS,true)) continue;
        $enabled = !empty($row['enabled']) ? 1 : 0;
        $order   = (int)($row['order'] ?? 0);
        $show_if = array_values(array_filter(array_map('trim', explode(',', (string)($row['show_if_service_in'] ?? '')))));
        $hide_if = array_values(array_filter(array_map('trim', explode(',', (string)($row['hide_if_service_in'] ?? '')))));
        $steps[]=[
          'key'=>$key,
          'enabled'=>$enabled,
          'order'=>$order,
          'show_if_service_in'=>$show_if ?: [],
          'hide_if_service_in'=>$hide_if ?: [],
        ];
      }
    }
    if (!$steps){ $steps=[['key'=>'service','enabled'=>1,'order'=>1]]; }
    usort($steps, fn($a,$b)=>($a['order']<=>$b['order']) ?: strcmp($a['key'],$b['key']));
    $new[$areaKey]['steps']=$steps;
  }

  /* === 3. Ноды шагов (вопрос/опции) — по всем разделам === */
  foreach (array_keys($new['areas']) as $areaKey){
    foreach ($ALLOWED_STEP_KEYS as $stepKey){
      if (!isset($new[$areaKey][$stepKey]) || !is_array($new[$areaKey][$stepKey])) $new[$areaKey][$stepKey]=['question'=>'','question_image'=>'','options'=>[]];

      // вопрос (i18n)
      if (isset($_POST["q_{$areaKey}_{$stepKey}"])) {
        $new[$areaKey][$stepKey]['question'] = norm_i18n($_POST["q_{$areaKey}_{$stepKey}"], $LANGS);
      }
      if (!empty($_POST["del_qimg_{$areaKey}_{$stepKey}"])) {
        $old = $new[$areaKey][$stepKey]['question_image'] ?? '';
        if ($old) { $abs = __DIR__ . '/' . $old; if (is_file($abs)) @unlink($abs); }
        $new[$areaKey][$stepKey]['question_image'] = '';
      }

      // удаление опций
      if (!empty($_POST['delete'][$areaKey][$stepKey])) {
        foreach ((array)$_POST['delete'][$areaKey][$stepKey] as $optId) unset($new[$areaKey][$stepKey]['options'][$optId]);
      }

      // обновление опций
      $postKey = "opt_{$areaKey}_{$stepKey}";
      if (!empty($_POST[$postKey]) && is_array($_POST[$postKey])) {
        foreach ($_POST[$postKey] as $id => $row) {
          if (!isset($new[$areaKey][$stepKey]['options'][$id])) continue;
          $o = &$new[$areaKey][$stepKey]['options'][$id];

          if (isset($row['text']))        $o['text'] = norm_i18n($row['text'], $LANGS);
          if (isset($row['desc']))        $o['desc'] = norm_i18n($row['desc'], $LANGS);
          $o['hide_image'] = !empty($row['hide_image']) ? 1 : 0;

          // цена/баллы — единые (не по языкам)
          if (array_key_exists('price',$row))  $o['price']  = ($row['price']===''?0:(float)$row['price']);
          if (array_key_exists('points',$row)) $o['points'] = ($row['points']===''?'':(int)$row['points']);
          $o['hide_price'] = !empty($row['hide_price']) ? 1 : 0;

          if ($stepKey==='service' && isset($row['booking_url'])) $o['booking_url']=(string)$row['booking_url'];
          if (isset($row['sort'])) $o['_sort'] = (int)$row['sort'];

          if (!empty($row['del_img'])) {
            $old = $o['image'] ?? '';
            if ($old) { $abs = __DIR__ . '/' . $old; if (is_file($abs)) @unlink($abs); }
            $o['image'] = '';
          }
          unset($o);
        }
      }

      // новая опция
      $addKey = "add_{$areaKey}_{$stepKey}_name";
      if (!empty($_POST[$addKey])) {
        $name = trim((string)$_POST[$addKey]);
        if ($name !== '') {
          $id = slugify($name);
          if (!isset($new[$areaKey][$stepKey]['options'][$id])) {
            $base = [
              'text'=>norm_i18n(['ru'=>$name], $LANGS), 'image'=>'', 'hide_image'=>0, 'desc'=>norm_i18n('', $LANGS),
              'price'=>0, 'points'=>'', 'hide_price'=>0
            ];
            if ($stepKey==='service') $base += ['booking_url'=>''];
            $new[$areaKey][$stepKey]['options'][$id] = $base;
          }
        }
      }

      // загрузка изображений
      $qKey = "img_q_{$areaKey}_{$stepKey}";
      if (isset($_FILES[$qKey]) && $_FILES[$qKey]['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES[$qKey]['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg','gif','webp'])) {
          foreach (glob($IMAGES_DIR . "/{$areaKey}_{$stepKey}_question.*") as $old) @unlink($old);
          $to = $IMAGES_DIR . "/{$areaKey}_{$stepKey}_question." . ($ext==='jpeg'?'jpg':$ext);
          move_uploaded_file($_FILES[$qKey]['tmp_name'], $to);
          $new[$areaKey][$stepKey]['question_image'] = 'images/'.basename($to);
        }
      }
      foreach ($new[$areaKey][$stepKey]['options'] as $id => &$opt) {
        $fKey = "img_{$areaKey}_{$stepKey}_{$id}";
        if (isset($_FILES[$fKey]) && $_FILES[$fKey]['error'] === UPLOAD_ERR_OK) {
          $ext = strtolower(pathinfo($_FILES[$fKey]['name'], PATHINFO_EXTENSION));
          if (in_array($ext, ['png','jpg','jpeg','gif','webp'])) {
            if (!empty($opt['image'])) { $abs = __DIR__ . '/' . $opt['image']; if (is_file($abs)) @unlink($abs); }
            $to = $IMAGES_DIR . "/{$areaKey}_{$stepKey}_{$id}." . ($ext==='jpeg'?'jpg':$ext);
            move_uploaded_file($_FILES[$fKey]['tmp_name'], $to);
            $opt['image'] = 'images/'.basename($to);
          }
        }
      } unset($opt);

      // сортировка опций
      if (!empty($new[$areaKey][$stepKey]['options'])) {
        $ops = &$new[$areaKey][$stepKey]['options'];
        uasort($ops, function($a,$b){
          $sa = $a['_sort'] ?? 0; $sb = $b['_sort'] ?? 0;
          return $sa <=> $sb;
        });
        foreach ($ops as &$o) unset($o['_sort']);
        unset($ops,$o);
      }
    }
  }

  // ===== save =====
  file_put_contents($CONFIG_FILE, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  $config = $new;
  $flash  = 'Изменения сохранены. Мёртвые кнопки на главной удалены автоматически.';
}

/* ---------- view helpers ---------- */
function numInput($name,$val,$step='0.1',$min='0'){ return '<input type="number" step="'.h($step).'" min="'.h($min).'" name="'.$name.'" value="'.h($val).'" style="width:110px">'; }
function textInput($name,$val,$w=420){ return '<input type="text" name="'.$name.'" value="'.h($val).'" style="width:'.$w.'px">'; }
function textarea($name,$val,$w=420,$h=70){ return '<textarea name="'.$name.'" style="width:'.$w.'px;height:'.$h.'px">'.h($val).'</textarea>'; }
function langInputs($baseName, $valArr, $LANGS, $w=420){
  $html = '';
  foreach ($LANGS as $L){
    $val = is_array($valArr) ? ($valArr[$L] ?? '') : (string)$valArr;
    $html .= '<div style="margin:4px 0"><label class="small" style="width:60px">['.h(strtoupper($L)).']</label> '.textInput($baseName.'['.$L.']', $val, $w).'</div>';
  }
  return $html;
}

$areas = $config['areas'];
?>
<!doctype html><html lang="ru"><head>
<meta charset="utf-8"><title>Админка квиза</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#f7f8fb;margin:0;padding:0;color:#111827}
.container{max-width:1200px;margin:0 auto;padding:28px}
h1{margin:0 0 16px;font-size:22px}
.flash{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px 12px;border-radius:8px;margin-bottom:14px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:18px;padding:16px 16px 10px;box-shadow:0 8px 24px rgba(0,0,0,.04)}
.card h2{font-size:18px;margin:0 0 10px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.row{margin-bottom:8px}
label.small{display:inline-block;width:180px;color:#6b7280}
hr.sep{border:0;border-top:1px dashed #e5e7eb;margin:10px 0}
.opt{border:1px dashed #e5e7eb;border-radius:10px;padding:10px;margin:8px 0;background:#fafafa}
.opt .hdr{display:flex;align-items:center;gap:10px;margin-bottom:6px}
.opt .hdr code{background:#eef2ff;border:1px solid #e0e7ff;border-radius:6px;padding:2px 6px}
.opt .hdr .sort{width:70px}
.imgprev{max-width:60px;max-height:60px;vertical-align:middle;margin-left:6px}
.btn{display:inline-block;border:0;border-radius:8px;padding:8px 12px;background:#111827;color:#fff;cursor:pointer}
.btn.link{background:#f3f4f6;color:#111827}
.btn.red{background:#b91c1c}
.addrow{display:flex;gap:8px;margin-top:8px}
.small-note{font-size:12px;color:#6b7280}
.inline{display:inline-block}
.small{font-size:12px;color:#6b7280}
.steps-table{width:100%;border-collapse:collapse;margin-top:8px}
.steps-table th,.steps-table td{border:1px solid #e5e7eb;padding:6px 8px;text-align:left}
.area-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px}
.area-item{border:1px dashed #e5e7eb;border-radius:10px;padding:10px;background:#fafafa}
.langs{margin-bottom:10px}
.langs input{width:40px}
</style></head><body>
<div class="container">
  <h1>Админка квиза</h1>
  <p class="small-note">Перед сохранением создаётся бэкап <code>quiz_config.json</code>. При удалении разделов «мертвые» кнопки на главной удаляются автоматически.</p>
  <?php if($flash): ?><div class="flash"><?=h($flash)?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data">

    <!-- Языки -->
    <div class="card">
      <h2>Языки интерфейса (двухбуквенные коды)</h2>
      <div class="langs">
        <?php for ($i=0;$i<max(3,count($LANGS));$i++):
          $val = $LANGS[$i] ?? '';
        ?>
          <input type="text" name="languages[]" value="<?=h($val)?>" maxlength="2" placeholder="ru">
        <?php endfor; ?>
        <span class="small-note">Напр.: <code>ru</code>, <code>et</code>, <code>en</code>. Первый — по умолчанию.</span>
      </div>
    </div>

    <!-- Менеджер разделов (услуг) -->
    <div class="card">
      <h2>Разделы (услуги)</h2>
      <div class="area-list">
        <?php foreach ($areas as $key=>$meta): ?>
          <div class="area-item">
            <div class="row"><label class="small">Ключ (ID):</label><code><?=h($key)?></code></div>
            <div class="row"><label class="small">Заголовок (i18n):</label></div>
            <?=langInputs("areas[{$key}][title]", $meta['title'] ?? strtoupper($key), $LANGS, 360)?>
            <div class="row"><label class="small">Удалить:</label><input type="checkbox" name="areas_delete[]" value="<?=h($key)?>"> да, удалить этот раздел</div>
            <div class="small-note">Шаги и контент редактируются ниже в секции «контент шагов» для <?=h($key)?>.</div>
          </div>
        <?php endforeach; ?>
      </div>
      <hr class="sep">
      <div class="row"><strong>Добавить новый раздел</strong></div>
      <div class="row">
        <label class="small">Новый ключ (латиница/цифры/подчёркивания):</label>
        <input type="text" name="new_area_key" placeholder="например: brows" style="width:260px">
      </div>
      <div class="row">
        <label class="small">Заголовок (i18n):</label>
        <?=langInputs("new_area_title", '', $LANGS, 260)?>
      </div>
    </div>

    <!-- Главная: «Что вам нужно?» + привязки к areas -->
    <div class="card" id="sec-area">
      <h2>Главная — «Что вам нужно?»</h2>
      <div class="row">
        <label class="small">Текст вопроса (i18n):</label>
        <?=langInputs("q_area", $config['area']['question'] ?? '', $LANGS)?>
      </div>

      <div class="row">
        <label class="small">Картинка вопроса:</label>
        <?php if (!empty($config['area']['question_image'])): ?>
          <img class="imgprev" src="<?=h($config['area']['question_image'])?>" alt="">
        <?php endif; ?>
        <input type="file" name="img_q_area">
        <?php if (!empty($config['area']['question_image'])): ?>
          <label style="margin-left:10px">
            <input type="checkbox" name="del_qimg_area" value="1"> удалить картинку вопроса
          </label>
        <?php endif; ?>
      </div>

      <hr class="sep">

      <?php if (!empty($config['area']['options'])): ?>
        <?php foreach ($config['area']['options'] as $id=>$o): ?>
          <div class="opt">
            <div class="hdr">
              <input class="sort" type="number" name="opt_area[<?=$id?>][sort]" value="<?=h($o['_sort'] ?? 0)?>" title="Порядок">
              <code><?=h($id)?></code>
              <label><input type="checkbox" name="delete_area[]" value="<?=h($id)?>"> удалить кнопку</label>
            </div>

            <div class="row">
              <label class="small">Название (i18n):</label>
              <?=langInputs("opt_area[{$id}][text]", $o['text'] ?? '', $LANGS)?>
            </div>

            <div class="row">
              <label class="small">Описание (i18n):</label>
              <?php foreach ($LANGS as $L): ?>
                <div style="margin:4px 0">
                  <label class="small" style="width:60px">[<?=strtoupper(h($L))?>]</label>
                  <?=textarea("opt_area[{$id}][desc][{$L}]", (is_array($o['desc'] ?? '')? ($o['desc'][$L] ?? '') : ($L===$LANGS[0] ? ($o['desc'] ?? ''):'')), 500, 60)?>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="row">
              <label class="small">Привязанные разделы:</label>
              <?php
                $areasStr = '';
                if (!empty($o['areas']) && is_array($o['areas'])) $areasStr = implode(',', $o['areas']);
              ?>
              <?=textInput("opt_area[{$id}][areas]", $areasStr, 420)?>
              <span class="small-note">ключи через запятую, напр.: <code>manicure</code> или <code>manicure,pedicure</code></span>
            </div>

            <div class="row">
              <label class="small">Картинка:</label>
              <?php if (!empty($o['image'])): ?>
                <img class="imgprev" src="<?=h($o['image'])?>" alt="">
              <?php endif; ?>
              <input type="file" name="img_area_<?=$id?>">
              <label style="margin-left:10px">
                <input type="checkbox" name="opt_area[<?=$id?>][hide_image]" value="1" <?= !empty($o['hide_image'])?'checked':''; ?>> не показывать изображение
              </label>
              <?php if (!empty($o['image'])): ?>
                <label style="margin-left:10px">
                  <input type="checkbox" name="opt_area[<?=$id?>][del_img]" value="1"> удалить картинку
                </label>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="addrow">
        <input type="text" name="add_area_name" placeholder="(Опции главной добавляются через код/JSON — оставим как есть)">
      </div>
    </div>

    <!-- Управление шагами и контент для каждого раздела -->
    <?php foreach ($areas as $areaKey=>$meta): ?>
      <div class="card" id="sec-steps-<?=$areaKey?>">
        <h2><?=h(is_array($meta['title']??null)? ($meta['title'][$LANGS[0]] ?? strtoupper($areaKey)) : ($meta['title'] ?? strtoupper($areaKey)))?> — порядок и условия шагов</h2>
        <p class="small">Типы: <code>oldCover</code>, <code>service</code>, <code>cover</code>, <code>length</code>, <code>design</code>, <code>spa</code></p>
        <table class="steps-table">
          <thead>
            <tr>
              <th>#</th><th>Тип шага</th><th>Вкл</th><th>Порядок</th><th>show_if_service_in</th><th>hide_if_service_in</th>
            </tr>
          </thead>
          <tbody>
            <?php $idx=0; foreach (($config[$areaKey]['steps'] ?? []) as $s): $idx++; ?>
              <tr>
                <td><?=$idx?></td>
                <td>
                  <select name="steps_<?=$areaKey?>[<?=$idx?>][key]">
                    <?php foreach ($ALLOWED_STEP_KEYS as $k): ?>
                      <option value="<?=$k?>" <?=$k===($s['key']??'')?'selected':''?>><?=$k?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><input type="checkbox" name="steps_<?=$areaKey?>[<?=$idx?>][enabled]" value="1" <?=!empty($s['enabled'])?'checked':''?>></td>
                <td><input type="number" name="steps_<?=$areaKey?>[<?=$idx?>][order]" value="<?=h($s['order'] ?? $idx)?>" style="width:80px"></td>
                <td><input type="text" name="steps_<?=$areaKey?>[<?=$idx?>][show_if_service_in]" value="<?=h(isset($s['show_if_service_in'])? (is_array($s['show_if_service_in'])? implode(',',$s['show_if_service_in']):$s['show_if_service_in']) :'')?>" style="width:240px"></td>
                <td><input type="text" name="steps_<?=$areaKey?>[<?=$idx?>][hide_if_service_in]" value="<?=h(isset($s['hide_if_service_in'])? (is_array($s['hide_if_service_in'])? implode(',',$s['hide_if_service_in']):$s['hide_if_service_in']) :'')?>" style="width:240px"></td>
              </tr>
            <?php endforeach; ?>
            <tr>
              <td>+</td>
              <td>
                <select name="steps_<?=$areaKey?>[new][key]">
                  <option value="">— добавить шаг —</option>
                  <?php foreach ($ALLOWED_STEP_KEYS as $k): ?><option value="<?=$k?>"><?=$k?></option><?php endforeach; ?>
                </select>
              </td>
              <td><input type="checkbox" name="steps_<?=$areaKey?>[new][enabled]" value="1" checked></td>
              <td><input type="number" name="steps_<?=$areaKey?>[new][order]" value="<?=100+$idx?>" style="width:80px"></td>
              <td><input type="text" name="steps_<?=$areaKey?>[new][show_if_service_in]" placeholder="например: manicure_cover" style="width:240px"></td>
              <td><input type="text" name="steps_<?=$areaKey?>[new][hide_if_service_in]" placeholder="например: classic" style="width:240px"></td>
            </tr>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>

    <?php foreach ($areas as $areaKey=>$meta): ?>
      <h2 style="margin:18px 0 8px"><?=h(is_array($meta['title']??null)? ($meta['title'][$LANGS[0]] ?? strtoupper($areaKey)) : ($meta['title'] ?? strtoupper($areaKey)))?> — контент шагов</h2>
      <?php foreach ($ALLOWED_STEP_KEYS as $stepKey):
        $node = $config[$areaKey][$stepKey] ?? ['question'=>'','question_image'=>'','options'=>[]];
      ?>
        <div class="card" id="sec-<?=$areaKey?>-<?=$stepKey?>">
          <h3 style="margin:0 0 10px"><?=strtoupper($stepKey)?></h3>
          <div class="row">
            <label class="small">Текст вопроса (i18n):</label>
            <?=langInputs("q_{$areaKey}_{$stepKey}", $node['question'] ?? '', $LANGS)?>
          </div>
          <div class="row">
            <label class="small">Картинка вопроса:</label>
            <?php if (!empty($node['question_image'])): ?><img class="imgprev" src="<?=h($node['question_image'])?>" alt=""><?php endif; ?>
            <input type="file" name="img_q_<?=$areaKey?>_<?=$stepKey?>">
            <?php if (!empty($node['question_image'])): ?>
              <label style="margin-left:10px"><input type="checkbox" name="del_qimg_<?=$areaKey?>_<?=$stepKey?>" value="1"> удалить картинку вопроса</label>
            <?php endif; ?>
          </div>
          <hr class="sep">
          <?php if (!empty($node['options'])): ?>
            <?php foreach ($node['options'] as $id=>$o): ?>
              <div class="opt">
                <div class="hdr">
                  <input class="sort" type="number" name="opt_<?=$areaKey?>_<?=$stepKey?>[<?=$id?>][sort]" value="<?=h($o['_sort'] ?? 0)?>" title="Порядок">
                  <code><?=h($id)?></code>
                  <label><input type="checkbox" name="delete[<?=$areaKey?>][<?=$stepKey?>][]" value="<?=h($id)?>"> удалить</label>
                </div>
                <div class="row"><label class="small">Название (i18n):</label><?=langInputs("opt_{$areaKey}_{$stepKey}[{$id}][text]", $o['text'] ?? '', $LANGS)?></div>
                <div class="row"><label class="small">Цена (€):</label><?=numInput("opt_{$areaKey}_{$stepKey}[{$id}][price]", $o['price'] ?? ($o['base_price'] ?? $o['price_add'] ?? ($o['removal_cost'] ?? 0)))?></div>
                <div class="row"><label class="small">Баллы:</label><?=numInput("opt_{$areaKey}_{$stepKey}[{$id}][points]", ($o['points'] ?? ''))?> <span class="small">если пусто — = цене</span></div>
                <?php if ($stepKey==='service'): ?>
                  <div class="row"><label class="small">Ссылка на запись:</label><?=textInput("opt_{$areaKey}_{$stepKey}[{$id}][booking_url]", $o['booking_url'] ?? '', 520)?></div>
                <?php endif; ?>
                <div class="row">
                  <label class="small">Описание (i18n):</label>
                  <?php foreach ($LANGS as $L): ?>
                    <div style="margin:4px 0">
                      <label class="small" style="width:60px">[<?=strtoupper(h($L))?>]</label>
                      <?=textarea("opt_{$areaKey}_{$stepKey}[{$id}][desc][{$L}]", (is_array($o['desc'] ?? '')? ($o['desc'][$L] ?? '') : ($L===$LANGS[0] ? ($o['desc'] ?? ''):'')), 520, 60)?>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="row">
                  <label class="small">Отображение:</label>
                  <label class="inline" style="margin-right:14px"><input type="checkbox" name="opt_<?=$areaKey?>_<?=$stepKey?>[<?=$id?>][hide_price]" value="1" <?= !empty($o['hide_price'])?'checked':''; ?>> скрыть цену в квизе</label>
                  <label class="inline"><input type="checkbox" name="opt_<?=$areaKey?>_<?=$stepKey?>[<?=$id?>][hide_image]" value="1" <?= !empty($o['hide_image'])?'checked':''; ?>> скрыть изображение</label>
                </div>
                <div class="row">
                  <label class="small">Картинка:</label>
                  <?php if (!empty($o['image'])): ?><img class="imgprev" src="<?=h($o['image'])?>" alt=""><?php endif; ?>
                  <input type="file" name="img_<?=$areaKey?>_<?=$stepKey?>_<?=$id?>">
                  <?php if (!empty($o['image'])): ?>
                    <label style="margin-left:10px"><input type="checkbox" name="opt_<?=$areaKey?>_<?=$stepKey?>[<?=$id?>][del_img]" value="1"> удалить картинку</label>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
          <div class="addrow">
            <input type="text" name="add_<?=$areaKey?>_<?=$stepKey?>_name" placeholder="Добавить новую опцию (введите название)">
            <button class="btn link" type="submit" name="save_all" value="1">+ Добавить</button>
            <span class="small-note">ID создастся автоматически (на основе названия).</span>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <div style="display:flex;gap:10px">
      <button class="btn" type="submit" name="save_all" value="1">Сохранить все изменения</button>
      <a class="btn link" href="quiz.php" target="_blank">Открыть квиз</a>
      <a class="btn red" href="?logout=1" onclick="return confirm('Выйти из админки?');">Выйти</a>
    </div>
  </form>
</div>
</body></html>
<?php
if (isset($_GET['logout'])) {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
  header('Location: ' . $_SERVER['PHP_SELF']);
  exit;
}
