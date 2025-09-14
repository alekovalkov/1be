<?php
declare(strict_types=1);

/**
 * booking/cron_award.php
 * Автоначисление лояльных баллов по завершённым приёмам.
 * Дёргается cron-ом: раз в минуту.
 * Безопасность: требуем токен ?token=... (см. CRON_SECRET ниже).
 */

require __DIR__ . '/config.php'; // pdo()

header('Content-Type: application/json; charset=UTF-8');

/* ==== ПРОСТАЯ ЗАЩИТА ПО ТОКЕНУ ==== */
$secret = getenv('CRON_SECRET') ?: 'changeme-secret';
$token  = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if ($secret === '' || $secret === 'changeme-secret') {
  // на всякий случай — допускаем запуск вручную, но предупреждаем в ответе
  $warn = 'WARNING: set CRON_SECRET env var!';
} else {
  if (!hash_equals($secret, $token)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden']);
    exit;
  }
}

$pdo = pdo();

/*
  Начисляем тем, у кого:
   - время окончания <= NOW()
   - не отменён (status IS NULL или IN ('pending','confirmed','completed'))
   - points_award > 0
   - points_awarded_at IS NULL
   - client_id IS NOT NULL
*/
$sql = "
  SELECT id, client_id, points_award
  FROM appointments
  WHERE ends <= NOW()
    AND (status IS NULL OR status IN ('pending','confirmed','completed'))
    AND COALESCE(points_award,0) > 0
    AND points_awarded_at IS NULL
    AND client_id IS NOT NULL
  ORDER BY ends ASC
  LIMIT 100
";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$done = 0; $skipped = 0; $errors = [];

foreach ($rows as $r) {
  $aid = (int)$r['id'];
  $cid = (int)$r['client_id'];
  $delta = (int)$r['points_award'];
  if ($aid<=0 || $cid<=0 || $delta<=0) { $skipped++; continue; }

  try {
    $pdo->beginTransaction();

    // 1) ещё раз убедимся, что никто не начислил параллельно
    $chk = $pdo->prepare("SELECT points_awarded_at, status FROM appointments WHERE id=:id FOR UPDATE");
    $chk->execute([':id'=>$aid]);
    $cur = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$cur) { $pdo->rollBack(); $skipped++; continue; }
    if (!empty($cur['points_awarded_at'])) { $pdo->rollBack(); $skipped++; continue; }

    // 2) начисляем клиенту
    $upd = $pdo->prepare("UPDATE clients SET points_balance = points_balance + :d WHERE id=:cid");
    $upd->execute([':d'=>$delta, ':cid'=>$cid]);

    // 3) запись в историю
    $ins = $pdo->prepare("INSERT INTO loyalty_ledger (client_id, appointment_id, delta, reason)
                          VALUES (:cid, :aid, :d, :r)");
    $ins->execute([
      ':cid'=>$cid, ':aid'=>$aid, ':d'=>$delta,
      ':r'=>'Автоначисление после окончания визита'
    ]);

    // 4) помечаем, что начислено (и при желании — статус completed, если не отменён)
    $pdo->prepare("UPDATE appointments
                   SET points_awarded_at = NOW(),
                       status = IF(status='cancelled', status, 'completed')
                   WHERE id=:id")->execute([':id'=>$aid]);

    $pdo->commit();
    $done++;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errors[] = "id=$aid: ".$e->getMessage();
  }
}

echo json_encode([
  'ok'=>true,
  'processed'=>$done,
  'skipped'=>$skipped,
  'batch'=>count($rows),
  'warn'=>$warn ?? null,
  'errors'=>$errors,
], JSON_UNESCAPED_UNICODE);