<?php
declare(strict_types=1);

/* /client/history.php — прошедшие брони с пагинацией */
require __DIR__ . '/../booking/config.php';
session_start();

$cid = $_SESSION['client_id'] ?? 0;
if (!$cid) {
  header('Location: /login.php?next=' . urlencode('/client/history.php'));
  exit;
}

$pdo = pdo();

/* настройки пагинации */
$perPage = max(1, (int)($_GET['per'] ?? 20));
$page    = max(1, (int)($_GET['p']   ?? 1));
$offset  = ($page - 1) * $perPage;

/* фильтруем отменённые; считаем «прошедшими», если начало < сейчас */
$nowSql = 'NOW()';
$statusOk = "status IS NULL OR status NOT IN ('cancelled','canceled')";

/* всего записей */
$stmtCnt = $pdo->prepare("
  SELECT COUNT(*) 
  FROM appointments 
  WHERE client_id = :cid 
    AND starts < $nowSql 
    AND ($statusOk)
");
$stmtCnt->execute([':cid' => $cid]);
$total = (int)$stmtCnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

/* сами записи */
$stmt = $pdo->prepare("
  SELECT id, service_id, service_title, salon_id, staff_id, starts, ends, price_eur, status
  FROM appointments
  WHERE client_id = :cid 
    AND starts < $nowSql 
    AND ($statusOk)
  ORDER BY starts DESC
  LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':cid',    $cid,    PDO::PARAM_INT);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Прошедшие брони</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font:16px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:24px}
  h1{margin:0 0 16px}
  .muted{color:#6b7280}
  table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden}
  th,td{padding:10px 12px;border-bottom:1px solid #f1f5f9;text-align:left}
  th{background:#f8fafc;font-weight:600}
  tr:last-child td{border-bottom:0}
  .pager{display:flex;gap:8px;align-items:center;margin-top:14px;flex-wrap:wrap}
  .pager a,.pager span{padding:8px 12px;border:1px solid #e5e7eb;border-radius:10px;text-decoration:none;color:#111827}
  .pager .cur{background:#111827;color:#fff;border-color:#111827}
  .btn{display:inline-block;background:#111827;color:#fff;border-radius:10px;padding:8px 12px;text-decoration:none}
  .topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
  .right{display:flex;gap:8px;align-items:center}
  select{padding:6px 8px;border:1px solid #e5e7eb;border-radius:8px}
</style>
</head>
<body>
  <div class="topbar">
    <h1>Прошедшие брони</h1>
    <div class="right">
      <a class="btn" href="/client/">Мои брони</a>
      <a class="btn" href="/booking/">Новая запись</a>
    </div>
  </div>

  <div class="muted">Всего: <?= htmlspecialchars((string)$total) ?> • Страница <?= $page ?> из <?= $pages ?></div>

  <?php if (!$rows): ?>
    <p class="muted">Прошедших броней не найдено.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Услуга</th>
          <th>Дата</th>
          <th>Время</th>
          <th>Длительность</th>
          <th>Цена</th>
          <th>Статус</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $starts = new DateTime((string)$r['starts']);
        $ends   = new DateTime((string)$r['ends']);
        $durMin = max(0, (int)round(($ends->getTimestamp() - $starts->getTimestamp()) / 60));
      ?>
        <tr>
          <td>#<?= (int)$r['id'] ?></td>
          <td><?= htmlspecialchars($r['service_title'] ?? ('Услуга #'.(int)$r['service_id'])) ?></td>
          <td><?= $starts->format('Y-m-d') ?></td>
          <td><?= $starts->format('H:i') ?></td>
          <td><?= $durMin ?> мин</td>
          <td><?= isset($r['price_eur']) ? (float)$r['price_eur'] . ' €' : '—' ?></td>
          <td><?= htmlspecialchars($r['status'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="pager">
      <?php
      // построение ссылок пагинации
      $qs = $_GET;
      $qs['per'] = $perPage;

      $makeUrl = function(int $p) use ($qs){
        $qs['p'] = $p;
        return '/client/history.php?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
      };

      if ($page > 1)   echo '<a href="'.$makeUrl($page-1).'">« Назад</a>';
      // компактная нумерация: первая, текущая +/-2, последняя
      $win = [];
      $win[] = 1;
      for ($i=$page-2; $i<=$page+2; $i++) if ($i>=1 && $i<=$pages) $win[]=$i;
      $win[] = $pages;
      $win = array_values(array_unique($win));
      $prev = 0;
      foreach ($win as $p) {
        if ($prev && $p > $prev+1) echo '<span>…</span>';
        if ($p === $page) echo '<span class="cur">'.$p.'</span>';
        else echo '<a href="'.$makeUrl($p).'">'.$p.'</a>';
        $prev = $p;
      }
      if ($page < $pages) echo '<a href="'.$makeUrl($page+1).'">Вперёд »</a>';
      ?>
      <form method="get" style="margin-left:auto">
        <?php
          // сохраняем другие параметры, если есть
          foreach ($_GET as $k=>$v) {
            if (in_array($k, ['p','per'], true)) continue;
            echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars((string)$v).'">';
          }
        ?>
        <label>На странице:
          <select name="per" onchange="this.form.submit()">
            <?php foreach ([10,20,30,50] as $opt): ?>
              <option value="<?= $opt ?>" <?= $opt===$perPage?'selected':''; ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <input type="hidden" name="p" value="1">
      </form>
    </div>
  <?php endif; ?>
</body>
</html>
