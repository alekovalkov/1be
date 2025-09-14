<?php
declare(strict_types=1);
require __DIR__.'/../config.php';

$db = pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Список сотрудников
$staff = $db->query("SELECT id, name FROM staff WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
if (!$staff) { die('Нет активных сотрудников. Сначала добавьте их в админке.'); }

$staffId = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : (int)$staff[0]['id'];
if ($staffId<=0) $staffId = (int)$staff[0]['id'];

// Сохранение расписания
$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_hours'])) {
  try {
    $db->beginTransaction();
    // Очищаем старые строки для этого сотрудника
    $db->prepare("DELETE FROM working_hours WHERE staff_id=?")->execute([$staffId]);

    $work = $_POST['work'] ?? [];
    for ($d=1;$d<=7;$d++){
      $row = $work[$d] ?? [];
      $enabled = !empty($row['enabled']);
      $start   = trim((string)($row['start'] ?? ''));
      $end     = trim((string)($row['end'] ?? ''));

      if ($enabled) {
        // простая валидация HH:MM
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
          throw new RuntimeException("Неверный формат времени для дня $d");
        }
        if (strtotime($start) >= strtotime($end)) {
          throw new RuntimeException("Время конца должно быть больше начала для дня $d");
        }
        $stmt = $db->prepare("INSERT INTO working_hours (staff_id, weekday, start, end) VALUES (?,?,?,?)");
        $stmt->execute([$staffId, $d, $start.':00', $end.':00']);
      }
    }
    $db->commit();
    header('Location: working_hours.php?staff_id='.$staffId.'&saved=1'); exit;
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $err = $e->getMessage();
  }
}

// Читаем текущее расписание
$hours = $db->prepare("SELECT weekday, TIME_FORMAT(start,'%H:%i') as start, TIME_FORMAT(end,'%H:%i') as end
                       FROM working_hours WHERE staff_id=?");
$hours->execute([$staffId]);
$map = [];
foreach ($hours as $r){ $map[(int)$r['weekday']] = ['start'=>$r['start'],'end'=>$r['end']]; }

$wd = [1=>'Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>График сотрудников</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font:16px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, Arial; margin:20px; background:#fafafa}
    .wrap{max-width:920px;margin:0 auto}
    h1{margin:0 0 12px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px; padding:16px}
    .row{display:flex;gap:8px;align-items:center}
    table{width:100%;border-collapse:separate;border-spacing:0 8px}
    th,td{padding:8px 10px}
    th{color:#6b7280;text-align:left}
    tr{background:#fff;border:1px solid #e5e7eb}
    tr td:first-child{border-left:1px solid #e5e7eb;border-top-left-radius:10px;border-bottom-left-radius:10px}
    tr td:last-child{border-right:1px solid #e5e7eb;border-top-right-radius:10px;border-bottom-right-radius:10px}
    input[type=time]{padding:6px 8px;border:1px solid #e5e7eb;border-radius:8px}
    .topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
    .btn{border:0;background:#ff2a7a;color:#fff;border-radius:999px;padding:10px 14px;font-weight:700;cursor:pointer}
    .muted{color:#6b7280}
    .ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:8px 10px;border-radius:8px;margin-bottom:10px}
    .err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:8px 10px;border-radius:8px;margin-bottom:10px}
    select{padding:8px;border:1px solid #e5e7eb;border-radius:8px;background:#fff}
  </style>
</head>
<body>
<div class="wrap">
  <h1>График сотрудников</h1>

  <div class="card" style="margin-bottom:14px">
    <form method="get" class="row">
      <label>Сотрудник:</label>
      <select name="staff_id" onchange="this.form.submit()">
        <?php foreach ($staff as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id']===$staffId?'selected':'' ?>>
            <?= htmlspecialchars($s['name'],ENT_QUOTES) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (isset($_GET['saved'])): ?>
    <div class="ok">Сохранено.</div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="err"><?= htmlspecialchars($err,ENT_QUOTES) ?></div>
  <?php endif; ?>

  <form method="post" class="card">
    <input type="hidden" name="save_hours" value="1">
    <table>
      <thead>
        <tr><th>День</th><th>Работает</th><th>С</th><th>До</th></tr>
      </thead>
      <tbody>
      <?php for ($d=1;$d<=7;$d++):
          $row = $map[$d] ?? null;
          $en  = $row!==null;
          $st  = $row['start'] ?? '10:00';
          $enT = $row['end']   ?? '18:00';
      ?>
        <tr>
          <td><strong><?= $wd[$d] ?></strong></td>
          <td><input type="checkbox" name="work[<?= $d ?>][enabled]" <?= $en?'checked':'' ?>></td>
          <td><input type="time" name="work[<?= $d ?>][start]" value="<?= htmlspecialchars($st,ENT_QUOTES) ?>" step="900"></td>
          <td><input type="time" name="work[<?= $d ?>][end]"   value="<?= htmlspecialchars($enT,ENT_QUOTES) ?>" step="900"></td>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
    <div style="display:flex;justify-content:flex-end;margin-top:10px">
      <button class="btn">Сохранить</button>
    </div>
    <p class="muted">Совет: если нужен «разрыв» (например, 10:00–14:00 и 15:00–19:00), пока делайте один диапазон. Поддержку нескольких интервалов на день добавим позже.</p>
  </form>
</div>
</body>
</html>
