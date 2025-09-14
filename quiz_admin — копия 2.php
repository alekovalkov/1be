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
  :root{--bg:#0b1020;--card:#121833;--text:#EAF0FF;--muted:#9fb2ff;--ring:rgba(255,255,255,.12);--accent:#7C3AED;--accent2:#6EE7FF}
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:grid;place-items:center;background:radial-gradient(80% 100% at 20% 0%,#1a1f3e 0%,#0b1020 60%) fixed;color:var(--text);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  .card{background:linear-gradient(180deg,#12183a,#0e1430);border:1px solid var(--ring);border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.35);padding:22px;min-width:320px}
  h1{margin:0 0 12px;font-size:20px}
  input{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--ring);background:#0e1430;color:#fff}
  .btn{margin-top:12px;width:100%;padding:10px 14px;border-radius:12px;border:0;background:linear-gradient(90deg,#8b5cf6,#06b6d4);color:#fff;font-weight:700;cursor:pointer}
  .err{color:#ffd1d1;margin-bottom:8px}
  </style></head><body>
  <form class="card" method="post">
    <h1>Админка квиза</h1>
    <?php if($err): ?><div class="err"><?=htmlspecialchars($err)?></div><?php endif; ?>
    <input type="password" name="password" placeholder="Пароль">
    <button class="btn" type="submit" name="adm_login" value="1">Войти</button>
  </form></body></html><?php
  exit;
}

/* ---------- utils ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function load_config($file){
  if (!file_exists($file)) return [];
  $j=file_get_contents($file); $cfg=json_decode($j,true);
  return is_array($cfg)?$cfg:[]; }
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
  $res=[]; foreach ($LANGS as $i=>$L){ $res[$L] = ($i===0)? (string)$v : ''; }
  return $res;
}
function i18n_pick($arr,$LANGS){
  if (is_array($arr)) {
    foreach ([$LANGS[0]??'ru','ru','en','et'] as $k){
      if (isset($arr[$k]) && trim((string)$arr[$k])!=='') return (string)$arr[$k];
    }
    foreach ($arr as $v){ if (trim((string)$v)!=='') return (string)$v; }
  }
  return (string)$arr;
}

/* === slug для SEO (латиница, учитываем ee-символы) === */
function slugify_et($s){
  $map = [
    'ä'=>'a','Ä'=>'a','õ'=>'o','Õ'=>'o','ö'=>'o','Ö'=>'o','ü'=>'u','Ü'=>'u',
    'š'=>'s','Š'=>'s','ž'=>'z','Ž'=>'z','ß'=>'ss','ñ'=>'n','Ñ'=>'n','å'=>'a','Å'=>'a',
    'ć'=>'c','Ć'=>'c','č'=>'c','Č'=>'c','é'=>'e','É'=>'e','è'=>'e','È'=>'e','ê'=>'e','Ê'=>'e'
  ];
  $s = strtr((string)$s,$map);
  $s = mb_strtolower($s,'UTF-8');
  $s = preg_replace('~[^a-z0-9]+~u','-',$s);
  $s = trim($s,'-');
  $s = preg_replace('~-+~','-',$s);
  return $s ?: 'service';
}

/* ===== Optional: PDO к БД из booking/config.php для синка ===== */
function db_pdo_or_null(){
  $cfg = __DIR__.'/booking/config.php';
  if (is_file($cfg)) {
    require_once $cfg;
    if (function_exists('pdo')) {
      try { return pdo(); } catch (Throwable $e) { return null; }
    }
  }
  return null;
}
/**
 * Синхроним только код/название/slug. Цены и время теперь НЕ трогаем.
 */
function db_sync_services(array $config, array $LANGS){
  $pdo = db_pdo_or_null();
  if (!$pdo) return;

  // Добавим колонку slug, если есть такая схема (мягко)
  try { $pdo->query("ALTER TABLE services ADD COLUMN IF NOT EXISTS slug_et VARCHAR(190) NULL"); } catch(Throwable $e){}

  foreach (($config['areas'] ?? []) as $areaKey => $_meta){
    $node = $config[$areaKey]['service']['options'] ?? [];
    foreach ($node as $code => $o){
      $title_ru = i18n_pick($o['text'] ?? ($config['areas'][$areaKey]['title'] ?? $code), $LANGS);
      $slug = (string)($o['slug'] ?? '');
      try{
        // Вставим если нет, иначе обновим только название/slug
        $stmt = $pdo->prepare("
          INSERT INTO services (code, title_ru, duration_min, price_eur, slug_et)
          VALUES (:code,:title,0,0,:slug)
          ON DUPLICATE KEY UPDATE
            title_ru=VALUES(title_ru),
            slug_et=VALUES(slug_et)
        ");
        $stmt->execute([
          ':code'=>$code, ':title'=>$title_ru, ':slug'=>$slug,
        ]);
      }catch(Throwable $e){ /* мягко молчим */ }
    }
  }
}

/* ---------- load ---------- */
$config = load_config($CONFIG_FILE);
if (!isset($config['languages']) || !is_array($config['languages']) || !$config['languages']) {
  $config['languages'] = ['ru','et','en'];
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

  // (опц.) обновление списка языков
  if (isset($_POST['languages']) && is_array($_POST['languages'])) {
    $langs = array_values(array_filter(array_map(function($x){ return strtolower(trim($x)); }, $_POST['languages'])));
    $langs = array_unique(array_filter($langs, function($x){ return preg_match('~^[a-z]{2}$~',$x); }));
    if ($langs) { $new['languages'] = $langs; $LANGS=$langs; }
  }

  /* === 0. Менеджер разделов (areas) === */
  if (!empty($_POST['areas']) && is_array($_POST['areas'])) {
    foreach ($_POST['areas'] as $key => $row) {
      if (!isset($new['areas'][$key])) continue;
      if (isset($row['title'])) $new['areas'][$key]['title'] = norm_i18n($row['title'], $LANGS);
    }
  }
  // Удаление разделов
  if (!empty($_POST['areas_delete']) && is_array($_POST['areas_delete'])) {
    foreach ($_POST['areas_delete'] as $delKey) {
      $delKey = (string)$delKey;
      if (isset($new['areas'][$delKey])) {
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

  /* === 1. Главная === */
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

  /* === 2. Порядок/условия шагов === */
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
          'key'=>$key,'enabled'=>$enabled,'order'=>$order,
          'show_if_service_in'=>$show_if ?: [], 'hide_if_service_in'=>$hide_if ?: [],
        ];
      }
    }
    if (!$steps){ $steps=[['key'=>'service','enabled'=>1,'order'=>1]]; }
    usort($steps, fn($a,$b)=>($a['order']<=>$b['order']) ?: strcmp($a['key'],$b['key']));
    $new[$areaKey]['steps']=$steps;
  }

  /* === 3. Контент шагов (без цены/времени/баллов) === */
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

          // УДАЛЯЕМ финансовые и временные ключи, если вдруг остались
          unset($o['price'],$o['points'],$o['duration_min'],$o['hide_price']);

          // для шага service — SEO slug
          if ($stepKey==='service') {
            $slugInput = isset($row['slug']) ? trim((string)$row['slug']) : '';
            if ($slugInput!=='') $o['slug'] = slugify_et($slugInput);
          } else {
            unset($o['slug']); // slug нужен только для service
          }

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
              'text'=>norm_i18n(['ru'=>$name], $LANGS),
              'image'=>'', 'hide_image'=>0,
              'desc'=>norm_i18n('', $LANGS),
            ];
            if ($stepKey==='service') $base += ['slug'=>''];
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

      // автогенерация и уникализация slug для service
      if ($stepKey==='service' && !empty($new[$areaKey][$stepKey]['options'])) {
        $seen = [];
        foreach ($new[$areaKey][$stepKey]['options'] as $id=>&$o) {
          $slug = trim((string)($o['slug'] ?? ''));
          if ($slug==='') {
            $titleEt = '';
            if (is_array($o['text'] ?? null)) { $titleEt = (string)($o['text']['et'] ?? ''); }
            if ($titleEt==='') $titleEt = i18n_pick($o['text'] ?? '', $LANGS);
            $slug = slugify_et($titleEt);
          } else {
            $slug = slugify_et($slug);
          }
          $base = $slug; $i=2;
          while(isset($seen[$slug])){ $slug = $base.'-'.$i++; }
          $seen[$slug]=1; $o['slug']=$slug;
        }
        unset($o);
      } else {
        // на прочих шагах случайный «старый» slug чистим
        foreach ($new[$areaKey][$stepKey]['options'] as &$o) unset($o['slug']);
        unset($o);
      }
    }
  }

  // ===== save JSON =====
  file_put_contents($CONFIG_FILE, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  $config = $new;

  // ===== optional DB sync (только title/slug) =====
  db_sync_services($config,$LANGS);

  $flash  = 'Изменения сохранены. Цена/время/баллы теперь настраиваются в ЛК мастера (quiz_pricing.php). Из конфига убраны устаревшие поля.';
}

/* ---------- view helpers ---------- */
function textInput($name,$val,$w=420){ return '<input type="text" name="'.$name.'" value="'.h($val).'" style="width:'.$w.'px">'; }
function textarea($name,$val,$w=420,$h=70){ return '<textarea name="'.$name.'" style="width:'.$w.'px;height:'.$h.'px">'.h($val).'</textarea>'; }
function langInputs($baseName, $valArr, $LANGS, $w=420){
  $html = '';
  foreach ($LANGS as $L){
    $val = is_array($valArr) ? ($valArr[$L] ?? '') : (string)$valArr;
    $html .= '<div class="langline"><span class="chip">'.h(strtoupper($L)).'</span> '.textInput($baseName.'['.$L.']', $val, $w).'</div>';
  }
  return $html;
}

$areas = $config['areas'];
?>
<!doctype html><html lang="ru"><head>
<meta charset="utf-8"><title>Админка квиза</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
:root{
  --bg:#0b1020;--bg2:#121833;--card:#0e1430;--text:#EAF0FF;--muted:#9fb2ff;--ring:rgba(255,255,255,.12);
  --accent:#7C3AED;--accent2:#6EE7FF;--ok:#22C55E;--danger:#EF4444;--shadow:0 12px 40px rgba(0,0,0,.35);--radius:16px
}
*{box-sizing:border-box}
html,body{margin:0;background:radial-gradient(80% 100% at 20% 0%,#1a1f3e 0%,#0b1020 60%) fixed;color:var(--text);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
a{color:inherit}
.container{max-width:1200px;margin:0 auto;padding:22px}
.topbar{display:flex;gap:10px;align-items:center;justify-content:space-between}
.brand{display:flex;gap:10px;align-items:center;font-weight:800}
.brand .dot{width:20px;height:20px;border-radius:8px;background:linear-gradient(135deg,var(--accent),#a78bfa)}
.controls{display:flex;gap:8px;flex-wrap:wrap}
.btn{background:#0e1430;border:1px solid var(--ring);border-radius:999px;padding:8px 12px;text-decoration:none;cursor:pointer}
.btn.primary{background:linear-gradient(90deg,#8b5cf6,#06b6d4);border:0;color:#fff;font-weight:700}
.btn.danger{border-color:#ef444466}
.flash{background:#0f5132;border:1px solid #19875466;color:#d1ffd1;padding:10px 12px;border-radius:12px;margin:14px 0}
.card{background:linear-gradient(180deg,#12183a 0%, #0e1430 100%);border:1px solid var(--ring);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px;margin:16px 0}
h1{margin:10px 0 6px;font-size:22px}
h2{margin:0 0 10px;font-size:18px}
.small{color:#9fb2ff}
.chip{display:inline-block;border:1px solid var(--ring);background:#0e1430b3;border-radius:999px;padding:4px 10px;font-size:12px}
.langline{margin:6px 0;display:flex;align-items:center;gap:8px}
input[type=text],textarea,select{background:#0d1330;border:1px solid var(--ring);border-radius:10px;color:#fff;padding:8px 10px}
input[type=file]{color:#cbd5ff}
.imgprev{max-width:60px;max-height:60px;vertical-align:middle;margin-left:6px;border-radius:10px;border:1px solid var(--ring)}
.grid{display:grid;gap:12px}
.area-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px}
.area-item{border:1px dashed var(--ring);border-radius:12px;padding:12px;background:#0d1330}
.details{border:1px solid var(--ring);border-radius:14px;background:#0d1330}
.details > summary{list-style:none;cursor:pointer;padding:12px 14px;border-radius:14px;display:flex;align-items:center;justify-content:space-between;font-weight:700}
.details[open] > summary{background:#0e1438}
.summary-title{display:flex;align-items:center;gap:10px}
.badge{display:inline-block;border:1px solid var(--ring);border-radius:999px;padding:2px 8px;font-size:12px;background:#0e1430b3}
.opt{border:1px dashed var(--ring);border-radius:12px;padding:10px;margin:8px 0;background:#0d1330}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:6px 0}
label.small{min-width:160px;color:#9fb2ff}
hr.sep{border:0;border-top:1px dashed var(--ring);margin:10px 0}
.tools{display:flex;gap:8px;flex-wrap:wrap}
.search{display:flex;gap:8px;align-items:center}
.search input{width:240px}
.sticky-actions{position:sticky;bottom:16px;display:flex;gap:10px;justify-content:flex-start;padding-top:10px}
kbd{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;font-size:12px;background:#111827;border:1px solid var(--ring);padding:2px 6px;border-radius:6px}
</style>
</head><body>
<div class="container">
  <div class="topbar">
    <div class="brand">
      <div class="dot"></div>
      <div>Quiz Admin</div>
    </div>
    <div class="controls">
      <a class="btn" href="quiz.php" target="_blank">Открыть квиз</a>
      <a class="btn danger" href="?logout=1" onclick="return confirm('Выйти из админки?');">Выйти</a>
    </div>
  </div>

  <h1>Админка квиза</h1>
  <div class="small">Цены, время и баллы редактируются в <b>ЛК мастера → quiz_pricing.php</b>. Здесь — структура, тексты, изображения и SEO-slug услуг.</div>
  <?php if($flash): ?><div class="flash"><?=h($flash)?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" id="quizForm">
    <!-- Панель инструментов -->
    <div class="card">
      <div class="tools">
        <button type="button" class="btn" id="expandAll">Развернуть всё</button>
        <button type="button" class="btn" id="collapseAll">Свернуть всё</button>
        <div class="search">
          <span class="small">Поиск по опциям:</span>
          <input type="text" id="searchInput" placeholder="Начните печатать... (⌘/Ctrl+K)">
          <span class="small">будут подсвечены совпадения в названиях</span>
        </div>
      </div>
    </div>

    <!-- Языки -->
    <div class="card">
      <h2>Языки интерфейса</h2>
      <div class="row">
        <?php for ($i=0;$i<max(3,count($LANGS));$i++):
          $val = $LANGS[$i] ?? '';
        ?>
          <span class="chip"><?= $i+1 ?></span>
          <input type="text" name="languages[]" value="<?=h($val)?>" maxlength="2" placeholder="ru">
        <?php endfor; ?>
        <span class="small">первый — язык по умолчанию</span>
      </div>
    </div>

    <!-- Разделы -->
    <div class="card">
      <h2>Разделы (категории услуг)</h2>
      <div class="area-list">
        <?php foreach ($areas as $key=>$meta): ?>
          <div class="area-item">
            <div class="row"><label class="small">Ключ (ID):</label><code><?=h($key)?></code></div>
            <div class="row" style="flex-direction:column;align-items:flex-start">
              <label class="small">Заголовок (i18n):</label>
              <?=langInputs("areas[{$key}][title]", $meta['title'] ?? strtoupper($key), $LANGS, 360)?>
            </div>
            <div class="row">
              <label class="small">Удалить:</label>
              <input type="checkbox" name="areas_delete[]" value="<?=h($key)?>"> удалить раздел
            </div>
            <div class="small">Шаги и контент внутри — ниже, в аккордеонах.</div>
          </div>
        <?php endforeach; ?>
      </div>
      <hr class="sep">
      <div class="row"><b>Добавить новый раздел</b></div>
      <div class="row">
        <label class="small">Новый ключ:</label>
        <input type="text" name="new_area_key" placeholder="например: brows" style="width:260px">
      </div>
      <div class="row" style="flex-direction:column;align-items:flex-start">
        <label class="small">Заголовок (i18n):</label>
        <?=langInputs("new_area_title", '', $LANGS, 260)?>
      </div>
    </div>

    <!-- Главная: «Что вам нужно?» -->
    <div class="details card" open>
      <summary>
        <div class="summary-title"><span>Главная — «Что вам нужно?»</span></div>
        <span class="badge">Root</span>
      </summary>
      <div class="grid" style="padding:10px 6px">
        <div class="row" style="flex-direction:column;align-items:flex-start">
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
            <label><input type="checkbox" name="del_qimg_area" value="1"> удалить</label>
          <?php endif; ?>
        </div>

        <?php if (!empty($config['area']['options'])): ?>
          <hr class="sep">
          <div class="small">Кнопки выбора категорий</div>
          <?php foreach ($config['area']['options'] as $id=>$o): ?>
            <div class="opt" data-opt>
              <div class="row" style="justify-content:space-between">
                <div style="display:flex;gap:10px;align-items:center">
                  <span class="badge">ID: <?=h($id)?></span>
                  <label><input type="checkbox" name="delete_area[]" value="<?=h($id)?>"> удалить</label>
                </div>
                <div class="row"><label class="small">Сортировка:</label><input type="number" name="opt_area[<?=$id?>][sort]" value="<?=h($o['_sort'] ?? 0)?>" style="width:90px"></div>
              </div>
              <div class="row" style="flex-direction:column;align-items:flex-start">
                <label class="small">Название (i18n):</label>
                <?=langInputs("opt_area[{$id}][text]", $o['text'] ?? '', $LANGS)?>
              </div>
              <div class="row" style="flex-direction:column;align-items:flex-start">
                <label class="small">Описание (i18n):</label>
                <?php foreach ($LANGS as $L): ?>
                  <div class="langline">
                    <span class="chip"><?=strtoupper(h($L))?></span>
                    <?=textarea("opt_area[{$id}][desc][{$L}]", (is_array($o['desc'] ?? '')? ($o['desc'][$L] ?? '') : ($L===$LANGS[0] ? ($o['desc'] ?? ''):'')), 520, 60)?>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="row">
                <label class="small">Привязанные разделы (через запятую):</label>
                <?php
                  $areasStr = '';
                  if (!empty($o['areas']) && is_array($o['areas'])) $areasStr = implode(',', $o['areas']);
                ?>
                <input type="text" name="opt_area[<?=$id?>][areas]" value="<?=h($areasStr)?>" placeholder="manicure,pedicure" style="width:360px">
              </div>
              <div class="row">
                <label class="small">Картинка:</label>
                <?php if (!empty($o['image'])): ?><img class="imgprev" src="<?=h($o['image'])?>" alt=""><?php endif; ?>
                <input type="file" name="img_area_<?=$id?>">
                <label><input type="checkbox" name="opt_area[<?=$id?>][hide_image]" value="1" <?= !empty($o['hide_image'])?'checked':''; ?>> скрыть</label>
                <?php if (!empty($o['image'])): ?>
                  <label><input type="checkbox" name="opt_area[<?=$id?>][del_img]" value="1"> удалить</label>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <div class="row">
          <input type="text" name="add_area_name" placeholder="(Добавление кнопок главной — через JSON/код; поле оставим пустым)">
        </div>
      </div>
    </div>

    <!-- Управление шагами и контент по разделам -->
    <?php foreach ($areas as $areaKey=>$meta): ?>
      <div class="details card">
        <summary>
          <div class="summary-title">
            <span><?=h(is_array($meta['title']??null)? ($meta['title'][$LANGS[0]] ?? strtoupper($areaKey)) : ($meta['title'] ?? strtoupper($areaKey)))?></span>
            <span class="badge"><?=h($areaKey)?></span>
          </div>
          <span class="small">кликните, чтобы развернуть</span>
        </summary>

        <!-- Порядок/условия шагов -->
        <div class="card" style="margin:12px 0 8px">
          <h2 style="margin:0 0 8px">Порядок и условия шагов</h2>
          <div class="small">Типы: <code>oldCover</code>, <code>service</code>, <code>cover</code>, <code>length</code>, <code>design</code>, <code>spa</code></div>
          <div class="grid" style="margin-top:10px">
            <?php $idx=0; foreach (($config[$areaKey]['steps'] ?? []) as $s): $idx++; ?>
              <div class="opt">
                <div class="row">
                  <label class="small">Тип шага</label>
                  <select name="steps_<?=$areaKey?>[<?=$idx?>][key]">
                    <?php foreach ($ALLOWED_STEP_KEYS as $k): ?>
                      <option value="<?=$k?>" <?=$k===($s['key']??'')?'selected':''?>><?=$k?></option>
                    <?php endforeach; ?>
                  </select>
                  <label class="small">Вкл</label>
                  <input type="checkbox" name="steps_<?=$areaKey?>[<?=$idx?>][enabled]" value="1" <?=!empty($s['enabled'])?'checked':''?>>
                  <label class="small">Порядок</label>
                  <input type="number" name="steps_<?=$areaKey?>[<?=$idx?>][order]" value="<?=h($s['order'] ?? $idx)?>" style="width:100px">
                </div>
                <div class="row">
                  <label class="small">show_if_service_in</label>
                  <input type="text" name="steps_<?=$areaKey?>[<?=$idx?>][show_if_service_in]" value="<?=h(isset($s['show_if_service_in'])? (is_array($s['show_if_service_in'])? implode(',',$s['show_if_service_in']):$s['show_if_service_in']) :'')?>" style="width:420px" placeholder="например: manicure_cover">
                </div>
                <div class="row">
                  <label class="small">hide_if_service_in</label>
                  <input type="text" name="steps_<?=$areaKey?>[<?=$idx?>][hide_if_service_in]" value="<?=h(isset($s['hide_if_service_in'])? (is_array($s['hide_if_service_in'])? implode(',',$s['hide_if_service_in']):$s['hide_if_service_in']) :'')?>" style="width:420px" placeholder="например: classic">
                </div>
              </div>
            <?php endforeach; ?>

            <div class="opt">
              <div class="row"><b>Добавить шаг</b></div>
              <div class="row">
                <label class="small">Тип шага</label>
                <select name="steps_<?=$areaKey?>[new][key]">
                  <option value="">— выбрать —</option>
                  <?php foreach ($ALLOWED_STEP_KEYS as $k): ?><option value="<?=$k?>"><?=$k?></option><?php endforeach; ?>
                </select>
                <label class="small">Вкл</label>
                <input type="checkbox" name="steps_<?=$areaKey?>[new][enabled]" value="1" checked>
                <label class="small">Порядок</label>
                <input type="number" name="steps_<?=$areaKey?>[new][order]" value="<?=100+($idx??0)?>" style="width:100px">
              </div>
              <div class="row">
                <label class="small">show_if_service_in</label>
                <input type="text" name="steps_<?=$areaKey?>[new][show_if_service_in]" placeholder="например: manicure_cover" style="width:420px">
              </div>
              <div class="row">
                <label class="small">hide_if_service_in</label>
                <input type="text" name="steps_<?=$areaKey?>[new][hide_if_service_in]" placeholder="например: classic" style="width:420px">
              </div>
            </div>
          </div>
        </div>

        <!-- Контент шагов (аккордеоны) -->
        <?php foreach ($ALLOWED_STEP_KEYS as $stepKey):
          $node = $config[$areaKey][$stepKey] ?? ['question'=>'','question_image'=>'','options'=>[]];
        ?>
          <div class="details" style="margin:10px 0">
            <summary>
              <div class="summary-title">
                <span><?=strtoupper($stepKey)?></span>
                <span class="badge"><?= count($node['options'] ?? []) ?> опц.</span>
              </div>
            </summary>

            <div style="padding:12px 12px 2px">
              <div class="row" style="flex-direction:column;align-items:flex-start">
                <label class="small">Текст вопроса (i18n):</label>
                <?=langInputs("q_{$areaKey}_{$stepKey}", $node['question'] ?? '', $LANGS)?>
              </div>
              <div class="row">
                <label class="small">Картинка вопроса:</label>
                <?php if (!empty($node['question_image'])): ?><img class="imgprev" src="<?=h($node['question_image'])?>" alt=""><?php endif; ?>
                <input type="file" name="img_q_<?=$areaKey?>_<?=$stepKey?>">
                <?php if (!empty($node['question_image'])): ?>
                  <label><input type="checkbox" name="del_qimg_<?=$areaKey?>_<?=$stepKey?>" value="1"> удалить</label>
                <?php endif; ?>
              </div>

              <hr class="sep">

              <?php if (!empty($node['options'])): ?>
                <?php foreach ($node['options'] as $id=>$o): ?>
                  <div class="opt" data-opt>
                    <div class="row" style="justify-content:space-between">
                      <div style="display:flex;gap:10px;align-items:center">
                        <span class="badge">ID: <?=h($id)?></span>
                        <label><input type="checkbox" name="delete[<?=$areaKey?>][<?=$stepKey?>][]" value="<?=h($id)?>"> удалить</label>
                      </div>
                      <div class="row">
                        <label class="small">Сортировка:</label>
                        <input type="number" name="opt_<?=$areaKey?>_<?=$stepKey?>[<?=$id?>][sort]" value="<?=h($o['_sort'] ?? 0)?>" style="width:90px">
                      </div>
                    </div>

                    <div class="row" style="flex-direction:column;align-items:flex-start">
                      <label class="small">Название (i18n):</label>
                      <?=langInputs("opt_{$areaKey}_{$stepKey}[{$id}][text]", $o['text'] ?? '', $LANGS)?>
                    </div>

                    <?php if ($stepKey==='service'): ?>
                      <div class="row">
                        <label class="small">SEO-slug (et):</label>
                        <input type="text" name="opt_<?=$areaKey?>_<?=$stepKey?>[<?=$id?>][slug]" value="<?=h($o['slug'] ?? '')?>" style="width:260px" placeholder="автогенерация по названию">
                      </div>
                    <?php endif; ?>

                    <div class="row" style="flex-direction:column;align-items:flex-start">
                      <label class="small">Описание (i18n):</label>
                      <?php foreach ($LANGS as $L): ?>
                        <div class="langline">
                          <span class="chip"><?=strtoupper(h($L))?></span>
                          <?=textarea("opt_{$areaKey}_{$stepKey}[{$id}][desc][{$L}]", (is_array($o['desc'] ?? '')? ($o['desc'][$L] ?? '') : ($L===$LANGS[0] ? ($o['desc'] ?? ''):'')), 520, 60)?>
                        </div>
                      <?php endforeach; ?>
                    </div>

                    <div class="row">
                      <label class="small">Картинка:</label>
                      <?php if (!empty($o['image'])): ?><img class="imgprev" src="<?=h($o['image'])?>" alt=""><?php endif; ?>
                      <input type="file" name="img_<?=$areaKey?>_<?=$stepKey?>_<?=$id?>">
                      <label><input type="checkbox" name="opt_<?=$areaKey?>_<?=$stepKey?>[<?=$id?>][hide_image]" value="1" <?= !empty($o['hide_image'])?'checked':''; ?>> скрыть</label>
                      <?php if (!empty($o['image'])): ?>
                        <label><input type="checkbox" name="opt_<?=$areaKey?>_<?=$stepKey?>[<?=$id?>][del_img]" value="1"> удалить</label>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>

              <div class="row">
                <input type="text" name="add_<?=$areaKey?>_<?=$stepKey?>_name" placeholder="Добавить новую опцию (введите название)" style="width:340px">
                <button class="btn" type="submit" name="save_all" value="1">+ Добавить</button>
                <span class="small">ID создастся автоматически; для услуг можно задать свой slug.</span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <div class="sticky-actions">
      <button class="btn primary" type="submit" name="save_all" value="1">Сохранить все изменения</button>
      <a class="btn" href="quiz.php" target="_blank">Предпросмотр квиза</a>
    </div>
  </form>
</div>

<script>
/* Развернуть/свернуть всё */
const expandAllBtn = document.getElementById('expandAll');
const collapseAllBtn = document.getElementById('collapseAll');
expandAllBtn?.addEventListener('click', () => {
  document.querySelectorAll('.details').forEach(d => d.setAttribute('open',''));
});
collapseAllBtn?.addEventListener('click', () => {
  document.querySelectorAll('.details').forEach(d => d.removeAttribute('open'));
});

/* Поиск по опциям (подсветка по value текстовых полей названий) */
const searchInput = document.getElementById('searchInput');
function doSearch(q){
  const items = document.querySelectorAll('[data-opt]');
  items.forEach(card => card.style.outline = '');
  if (!q) return;
  const val = q.toLowerCase();
  items.forEach(card => {
    const inputs = card.querySelectorAll('input[type="text"]');
    for (const inp of inputs){
      if ((inp.value||'').toLowerCase().includes(val)){
        card.style.outline = '2px solid #6EE7FF';
        // раскрываем родителей-аккордеоны
        let p = card.parentElement;
        while (p){
          if (p.classList && p.classList.contains('details')) p.setAttribute('open','');
          p = p.parentElement;
        }
        break;
      }
    }
  });
}
searchInput?.addEventListener('input', e => doSearch(e.target.value));
window.addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase()==='k'){
    e.preventDefault(); searchInput?.focus();
  }
});
</script>
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