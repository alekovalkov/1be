<?php
declare(strict_types=1);
require __DIR__.'/../config.php';

$db = pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Список сотрудников
$staff = $db->query("SELECT id,name FROM staff WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
if (!$staff) { die('Нет активных сотрудников.'); }

$staffId = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0; // 0 = все

$err = '';
$ok  = '';

// Добавление
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add'])) {
  try{
    $sid = (int)($_POST['sid'] ?? 0);
    $df  = trim((string)($_POST['date_from'] ?? ''));
    $tf  = trim((string)($_POST['time_from'] ?? ''));
    $dt  = trim((string)($_POST['date_to'] ?? ''));
    $tt  = trim((string)($_POST['time_to'] ?? ''));
    $note= trim((string)($_POST['note'] ?? ''));

    if ($sid<=0) throw new RuntimeException('Выберите сотрудника.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$df) || !preg_match('/^\d{2}:\d{2}$/',$tf)) throw new RuntimeException('Неверная дата/время начала.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dt) || !preg_match('/^\d{2}:\d{2}$/',$tt)) throw new RuntimeException('Неверная дата/время конца.');

    $starts = $df.' '.$tf.':00';
    $ends   = $dt.' '.$tt.':00';
    if (strtotime($starts) >= strtotime($ends)) throw new RuntimeException('Конец должен быть позже начала.');

    $stmt=$db->prepare("INSERT INTO time_off (staff_id, starts, ends, note) VALUES (?,?,?,?)");
    $stmt->execute([$sid,$starts,$ends,$note!==''?$note:null]);
    $ok='Добавлено.';
    if (!$staffId) $staffId = $sid;
  } catch(Throwable $e){ $err=$e->getMessage(); }
}

// Удаление
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['del'], $_POST['id'])) {
  $id=(int)$_POST['id'];
  $db->prepare("DELETE FROM time_off WHERE id=?")->execute([$id]);
  $ok='Удалено.';
}

// Список отсутствий
$params = [];
$sql = "SELECT t.id, t.staff_id, s.name as staff_name,
               DATE_FORMAT(t.starts, '%Y-%m-%d %H:%i') as starts,
               DATE_FORMAT(t.ends,   '%Y-%m-%d %H:%i') as ends,
               t.note
        FROM time_off t
        JOIN staff s ON s.id=t.staff_id";
if ($staffId>0) { $sql.=" WHERE t.staff_id=?"; $params[]=$staffId; }
$sql.=" ORDER BY t.starts DESC LIMIT 200";
$items = $db->prepare($sql);
$items->execute($params);
$rows=$items->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Отсутствия сотрудников</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font:16px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, Arial; margin:20px; background:#fafafa}
    .wrap{max-width:980px;margin:0 auto}
    h1{margin:0 0 12px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px; padding:16px}
    .row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    table{width:100%;border-collapse:collapse;margin-top:12px}
    th,td{padding:10px;border-bottom:1px solid #f1f5f9;text-align:left}
    .btn{border:0;background:#ff2a7a;color:#fff;border-radius:999px;padding:10px 14px;font-weight:700;cursor:pointer}
    .muted{color:#6b7280}
    input,select,textarea{padding:8px;border:1px solid #e5e7eb;border-radius:8px}
    .ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:8px 10px;border-radius:8px;margin:10px 0}
    .err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:8px 10px;border-radius:8px;margin:10px 0}
    form.inline{display:inline}
  </style>
</head>
<body>
<div class="wrap">
  <h1>Отсутствия (отпуска/блокировки)</h1>

  <div class="card">
    <form method="get" class="row">
      <label>Фильтр по сотруднику:</label>
      <select name="staff_id" onchange="this.form.submit()">
        <option value="0" <?= $staffId===0?'selected':'' ?>>Все</option>
        <?php foreach ($staff as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id']===$staffId?'selected':'' ?>>
            <?= htmlspecialchars($s['name'],ENT_QUOTES) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if ($ok): ?><div class="ok"><?= htmlspecialchars($ok,ENT_QUOTES) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="err"><?= htmlspecialchars($err,ENT_QUOTES) ?></div><?php endif; ?>

  <div class="card" style="margin-top:12px">
    <h3 style="margin-top:0">Добавить период</h3>
    <form method="post" class="row">
      <input type="hidden" name="add" value="1">
      <label>Сотрудник:</label>
      <select name="sid" required>
        <?php foreach ($staff as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id']===$staffId?'selected':'' ?>>
            <?= htmlspecialchars($s['name'],ENT_QUOTES) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <label>С:</label>
      <input type="date" name="date_from" required>
      <input type="time" name="time_from" step="900" required>
      <label>До:</label>
      <input type="date" name="date_to" required>
      <input type="time" name="time_to" step="900" required>
      <input type="text" name="note" placeholder="прим.: отпуск" style="min-width:180px">
      <button class="btn">Добавить</button>
    </form>
  </div>

  <div class="card" style="margin-top:12px">
    <h3 style="margin:0 0 8px">Ближайшие отсутствия</h3>
    <table>
      <thead>
        <tr><th>Сотрудник</th><th>С</th><th>До</th><th>Заметка</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="muted">Пока пусто.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['staff_name'],ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($r['starts'],ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars($r['ends'],ENT_QUOTES) ?></td>
          <td><?= htmlspecialchars((string)$r['note'],ENT_QUOTES) ?></td>
          <td>
            <form method="post" class="inline" onsubmit="return confirm('Удалить запись об отсутствии?')">
              <input type="hidden" name="del" value="1">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn" style="background:#ef4444">Удалить</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
