<?php
declare(strict_types=1);

// Полный список записей с пагинацией
require __DIR__ . '/../config.php';

const DEFAULT_TZ = 'Europe/Tallinn';
date_default_timezone_set(DEFAULT_TZ);

function pdo2(): PDO { return pdo(); }
function qcol(PDO $db, string $table, string $col): bool {
  return (bool)$db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($col))->fetch();
}
function pick_col(PDO $db, string $table, array $cands): ?string {
  foreach ($cands as $c) if (qcol($db,$table,$c)) return $c; return null;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

$db = pdo2();

$colStart = pick_col($db,'appointments',['starts','start_dt','start_at','start','begin_at']) ?: 'starts';
$colEnd   = pick_col($db,'appointments',['ends','end_dt','end_at','end','finish_at']) ?: 'ends';
$colPrice = pick_col($db,'appointments',['price_eur','total_eur','price','amount']);
$colPoints= qcol($db,'appointments','points_award') ? 'points_award' : 'NULL';
$colAwardedAt = qcol($db,'appointments','points_awarded_at') ? 'points_awarded_at' : 'NULL';

// Фильтры
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = min(200, max(10, (int)($_GET['per'] ?? 50)));
$status   = trim((string)($_GET['status'] ?? ''));                   // confirmed|pending|cancelled или пусто
$from     = trim((string)($_GET['from'] ?? ''));                     // YYYY-MM-DD
$to       = trim((string)($_GET['to'] ?? ''));                       // YYYY-MM-DD
$clientId = (int)($_GET['client_id'] ?? 0);
$staffId  = (int)($_GET['staff_id'] ?? 0);

// WHERE
$where = [];
$params = [];
if ($status !== '') { $where[] = 'a.status = :st'; $params[':st'] = $status; }
if ($from   !== '' && preg_match('~^\d{4}-\d{2}-\d{2}$~',$from)) {
  $where[] = "a.$colStart >= :from"; $params[':from'] = $from.' 00:00:00';
}
if ($to     !== '' && preg_match('~^\d{4}-\d{2}-\d{2}$~',$to)) {
  $where[] = "a.$colStart <= :to";   $params[':to']   = $to.' 23:59:59';
}
if ($clientId > 0) { $where[] = 'a.client_id = :cid'; $params[':cid'] = $clientId; }
if ($staffId  > 0) { $where[] = 'a.staff_id  = :sid'; $params[':sid'] = $staffId; }

$whereSQL = $where ? ('WHERE '.implode(' AND ',$where)) : '';

// Считаем всего
$cntSQL = "SELECT COUNT(*) FROM appointments a $whereSQL";
$stCnt = $db->prepare($cntSQL);
$stCnt->execute($params);
$total = (int)$stCnt->fetchColumn();

$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);
$off   = ($page - 1) * $perPage;

// Данные
$sql = "
  SELECT a.id, a.client_id, a.staff_id,
         a.$colStart AS s,
         a.$colEnd   AS e,
         a.status,
         ".($colPrice ? "a.`$colPrice`" : "NULL")." AS price,
         $colPoints AS points_award,
         $colAwardedAt AS points_awarded_at,
         COALESCE(staff.name,'')  AS staff_name,
         COALESCE(clients.name,'') AS client_name
  FROM appointments a
  LEFT JOIN staff   ON staff.id   = a.staff_id
  LEFT JOIN clients ON clients.id = a.client_id
  $whereSQL
  ORDER BY a.id DESC
  LIMIT :lim OFFSET :off
";
$st = $db->prepare($sql);
foreach ($params as $k=>$v) $st->bindValue($k,$v);
$st->bindValue(':lim', $perPage, PDO::PARAM_INT);
$st->bindValue(':off', $off, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Хелпер для ссылок пагинации с сохранением фильтров
function linkWith(array $over=[]): string {
  $q = $_GET;
  foreach ($over as $k=>$v) $q[$k] = $v;
  return '?'.http_build_query($q);
}

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Записи — список</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; margin:24px;}
    h1{margin:0 0 16px}
    .row{display:flex; gap:16px; align-items:flex-start}
    form.filters{border:1px solid #e5e7eb; padding:12px; border-radius:10px; background:#fafafa}
    label{display:block; font-size:13px; color:#374151; margin:6px 0 4px}
    input,select{padding:8px; border:1px solid #cbd5e1; border-radius:8px}
    table{width:100%; border-collapse:collapse; margin-top:12px}
    th,td{border:1px solid #f1f5f9; padding:8px; font-size:14px; vertical-align:top}
    th{background:#f8fafc}
    .pager{display:flex; gap:8px; align-items:center; margin:12px 0}
    .btn{padding:6px 10px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; text-decoration:none; color:#111}
    .muted{color:#6b7280}
  </style>
</head>
<body>
  <h1>Записи — список</h1>
  <div class="row">
    <a class="btn" href="/booking/admin/appointments.php">← Назад к созданию</a>
  </div>

  <form method="get" class="filters" style="margin-top:12px">
    <div style="display:flex; gap:10px; flex-wrap:wrap">
      <div>
        <label>Статус</label>
        <select name="status">
          <option value="">— любой —</option>
          <?php foreach (['confirmed','pending','cancelled'] as $stOpt): ?>
            <option value="<?=$stOpt?>" <?= $status===$stOpt?'selected':'' ?>><?=$stOpt?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>С даты</label>
        <input type="date" name="from" value="<?=h($from)?>">
      </div>
      <div>
        <label>По дату</label>
        <input type="date" name="to" value="<?=h($to)?>">
      </div>
      <div>
        <label>ID клиента</label>
        <input type="number" name="client_id" value="<?= $clientId>0? (int)$clientId : '' ?>">
      </div>
      <div>
        <label>ID сотрудника</label>
        <input type="number" name="staff_id" value="<?= $staffId>0? (int)$staffId : '' ?>">
      </div>
      <div>
        <label>На странице</label>
        <select name="per">
          <?php foreach ([25,50,100,150,200] as $pp): ?>
            <option value="<?=$pp?>" <?= $perPage===$pp?'selected':'' ?>><?=$pp?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="align-self:end">
        <button class="btn" type="submit">Фильтровать</button>
        <a class="btn" href="?">Сброс</a>
      </div>
    </div>
    <div class="muted" style="margin-top:6px">Всего: <?= (int)$total ?> записей</div>
  </form>

  <div class="pager">
    <a class="btn" href="<?=h(linkWith(['page'=>max(1,$page-1)]))?>">← Назад</a>
    <div>Стр. <?= (int)$page ?> / <?= (int)$pages ?></div>
    <a class="btn" href="<?=h(linkWith(['page'=>min($pages,$page+1)]))?>">Вперёд →</a>
  </div>

  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Клиент</th>
        <th>Сотрудник</th>
        <th>Начало</th>
        <th>Конец</th>
        <th>Статус</th>
        <th>€</th>
        <th>План баллов</th>
        <th>Начислено</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="muted" style="text-align:center">Ничего не найдено</td></tr>
      <?php else: foreach($rows as $r): ?>
        <tr>
          <td><?=h($r['id'])?></td>
          <td><?=h(($r['client_name']!==''?$r['client_name'].' ':'').'#'.$r['client_id'])?></td>
          <td><?=h(($r['staff_name']!==''?$r['staff_name'].' ':'').'#'.$r['staff_id'])?></td>
          <td><?=h($r['s'])?></td>
          <td><?=h($r['e'])?></td>
          <td><?=h($r['status'])?></td>
          <td><?=h($r['price'])?></td>
          <td><?=h($r['points_award'])?></td>
          <td><?=h($r['points_awarded_at'])?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <div class="pager">
    <a class="btn" href="<?=h(linkWith(['page'=>max(1,$page-1)]))?>">← Назад</a>
    <div>Стр. <?= (int)$page ?> / <?= (int)$pages ?></div>
    <a class="btn" href="<?=h(linkWith(['page'=>min($pages,$page+1)]))?>">Вперёд →</a>
  </div>
</body>
</html>
