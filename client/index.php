<?php
declare(strict_types=1);

require __DIR__ . '/../booking/config.php';
require __DIR__ . '/_auth.php';
require __DIR__ . '/_layout.php';

$pdo = pdo();
$clientId = (int)$_SESSION['client_id'];

/** аккуратно определим имена колонок appointments (у тебя они уже есть) */
function pick_col(PDO $db, string $table, array $cands): ?string {
  $q = $db->query("SHOW COLUMNS FROM `$table`");
  $have = array_column($q->fetchAll(PDO::FETCH_ASSOC), 'Field');
  foreach ($cands as $c) if (in_array($c, $have, true)) return $c;
  return null;
}

$apS = pick_col($pdo,'appointments',['starts','start_dt','start_at','start','begin_at']) ?: 'starts';
$apE = pick_col($pdo,'appointments',['ends','end_dt','end_at','end','finish_at']) ?: 'ends';
$svcT= pick_col($pdo,'appointments',['service_title','title','service_name']) ?: 'service_title';
$price = pick_col($pdo,'appointments',['price_eur','total_eur','price','amount']);
$dur = pick_col($pdo,'appointments',['duration_min','minutes','duration']);
$status = pick_col($pdo,'appointments',['status']);

$cols = ["id","$apS AS starts","$apE AS ends"];
if ($svcT)  $cols[] = "$svcT AS service_title";
if ($price) $cols[] = "$price AS price_eur";
if ($dur)   $cols[] = "$dur AS duration_min";
if ($status)$cols[] = "$status AS status";

// 👉 добавим фильтр для предстоящих: не показывать отменённые
$notCancelledSQL = $status
  ? " AND ($status IS NULL OR $status NOT IN ('cancelled','canceled'))"
  : "";

$sqlBase = "SELECT ".implode(',',$cols)." FROM `appointments` WHERE client_id = :cid";

// было: AND $apS >= NOW() ORDER BY ...
$st1 = $pdo->prepare($sqlBase." AND $apS >= NOW()".$notCancelledSQL." ORDER BY $apS ASC");
$st1->execute([':cid'=>$clientId]);
$upcoming = $st1->fetchAll();

$st2 = $pdo->prepare($sqlBase." AND $apS < NOW() ORDER BY $apS DESC LIMIT 50");
$st2->execute([':cid'=>$clientId]);
$past = $st2->fetchAll();

page_header('Мои брони');

// ====== блок сообщений ======
$errMsg = '';
$okMsg = '';

if (!empty($_GET['err'])) {
    if ($_GET['err'] === 'too-late') {
        $errMsg = '❌ Отменить бронь можно не позже, чем за 2 часа до начала.';
    }
}
if (!empty($_GET['ok'])) {
    if ($_GET['ok'] === 'cancelled') {
        $okMsg = '✅ Бронь успешно отменена.';
    }
}

if ($errMsg): ?>
  <div class="msg err"><?= htmlspecialchars($errMsg, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?></div>
<?php endif; ?>

<?php if ($okMsg): ?>
  <div class="msg ok"><?= htmlspecialchars($okMsg, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?></div>
<?php endif; ?>

<div class="row">
  <div class="card">
    <h2>Ближайшие брони</h2>
    <?php if (!$upcoming): ?>
      <p class="muted">Пока нет ближайших записей.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Дата</th>
            <th>Время</th>
            <th>Услуга</th>
            <th class="right">Цена</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($upcoming as $row): 
          $dt = new DateTime((string)$row['starts']);
          $date = $dt->format('Y-m-d');
          $time = $dt->format('H:i');
          $title = $row['service_title'] ?? 'Услуга';
          $priceTxt = isset($row['price_eur']) ? (number_format((float)$row['price_eur'], 2, '.', ' ').' €') : '—';
          $canCancel = true; // можно добавить правило (например, не позже чем за X часов)
        ?>
          <tr>
            <td><?= htmlspecialchars($date) ?></td>
            <td><?= htmlspecialchars($time) ?></td>
            <td><?= htmlspecialchars((string)$title) ?></td>
            <td class="right"><?= htmlspecialchars($priceTxt) ?></td>
            <td class="right">
              <?php if ($canCancel): ?>
                <form method="post" action="/client/cancel.php" onsubmit="return confirm('Отменить запись?');" style="display:inline">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <button class="btn danger" type="submit">Отменить</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Прошлые брони</h2>
    <?php if (!$past): ?>
      <p class="muted">Пока пусто.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Дата</th>
            <th>Время</th>
            <th>Услуга</th>
            <th class="right">Цена</th>
            <th>Статус</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($past as $row): 
          $dt = new DateTime((string)$row['starts']);
          $date = $dt->format('Y-m-d');
          $time = $dt->format('H:i');
          $title = $row['service_title'] ?? 'Услуга';
          $priceTxt = isset($row['price_eur']) ? (number_format((float)$row['price_eur'], 2, '.', ' ').' €') : '—';
          $st = (string)($row['status'] ?? '');
        ?>
          <tr>
            <td><?= htmlspecialchars($date) ?></td>
            <td><?= htmlspecialchars($time) ?></td>
            <td><?= htmlspecialchars((string)$title) ?></td>
            <td class="right"><?= htmlspecialchars($priceTxt) ?></td>
            <td><?= $st ? htmlspecialchars($st) : '<span class="muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php page_footer();
