<?php
declare(strict_types=1);

/*
  cron/award_points.php
  Начисляет лояльные баллы за завершённые визиты.

  GET-параметры:
    ?secret=MY_SUPER_SECRET_123  — защита
    &dry=1                        — «сухой» режим, только вывести что бы начислили
    &debug=1                      — подробный вывод (почему не подошли записи)
*/

require __DIR__ . '/../booking/config.php';

$SECRET = getenv('AWARD_SECRET') ?: 'MY_SUPER_SECRET_123';

$reqSecret = $_GET['secret'] ?? '';
$dry = isset($_GET['dry']);
$debug = isset($_GET['debug']);

if ($reqSecret !== $SECRET) {
  http_response_code(403);
  echo "Forbidden: bad secret\n";
  exit;
}

function pick_col(PDO $db, string $table, array $cands): ?string {
  foreach ($cands as $c) {
    $q = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($c));
    if ($q && $q->fetch()) return $c;
  }
  return null;
}

try {
  $db = pdo();
$db->exec("SET time_zone = 'Europe/Tallinn'");

  // Проверим, что нужные таблица и колонки есть
  $haveAppts = (bool)$db->query("SHOW TABLES LIKE 'appointments'")->fetchColumn();
  if (!$haveAppts) { echo "No table appointments\n"; exit; }

  $colEnds  = pick_col($db,'appointments',['ends','end_dt','end_at','end','finish_at']) ?: 'ends';
  $colStat  = pick_col($db,'appointments',['status','state']) ?: 'status';
  $colAward = pick_col($db,'appointments',['points_award','points']) ?: 'points_award';
  $colAwardedAt = pick_col($db,'appointments',['points_awarded_at','awarded_at']) ?: 'points_awarded_at';

  // Минимальный набор: ends, points_award, points_awarded_at должны быть
  foreach ([$colEnds,$colAward,$colAwardedAt] as $needCol) {
    $chk = $db->query("SHOW COLUMNS FROM `appointments` LIKE ".$db->quote($needCol))->fetch();
    if (!$chk) {
      echo "Missing column in appointments: $needCol\n";
      exit;
    }
  }

  // Подготовим варианты статусов: по умолчанию начисляем всем, кто НЕ отменён
  $whereStatus = "($colStat IS NULL OR $colStat NOT IN ('cancelled','canceled'))";

  // Основной запрос-кандидат
  $sql = "SELECT id, $colEnds AS ends, $colStat AS status, $colAward AS points_award, $colAwardedAt AS points_awarded_at
          FROM appointments
          WHERE $colAward > 0
            AND $colAwardedAt IS NULL
            AND $colEnds <= NOW()
            AND $whereStatus
          ORDER BY $colEnds ASC
          LIMIT 100";

  $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) {
    echo "Nothing to award\n";
    if ($debug) {
      // Диагностика: покажем, сколько записей «почти подходят»
      $cntTotal = (int)$db->query("SELECT COUNT(*) FROM appointments")->fetchColumn();

      $cntAward = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE $colAward > 0")->fetchColumn();
      $cntAwardNotNull = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE $colAward > 0 AND $colAwardedAt IS NOT NULL")->fetchColumn();
      $cntEnded = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE $colEnds <= NOW()")->fetchColumn();
      $cntStatusOk = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE $whereStatus")->fetchColumn();

      echo "Debug:\n";
      echo "  total appts: $cntTotal\n";
      echo "  points_award > 0: $cntAward (из них уже начислено: $cntAwardNotNull)\n";
      echo "  ended (ends<=NOW): $cntEnded\n";
      echo "  status OK (not cancelled): $cntStatusOk\n";

      // Покажем несколько последних записей с интересующими полями
      $diag = $db->query("SELECT id, $colStat AS status, $colEnds AS ends, $colAward AS points_award, $colAwardedAt AS points_awarded_at
                          FROM appointments
                          ORDER BY id DESC
                          LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
      echo "\nLast 10 appts snapshot:\n";
      foreach ($diag as $r) {
        echo sprintf("#%d | status=%s | ends=%s | award=%s | awarded_at=%s\n",
          (int)$r['id'],
          (string)($r['status'] ?? ''),
          (string)($r['ends'] ?? ''),
          (string)($r['points_award'] ?? ''),
          (string)($r['points_awarded_at'] ?? '')
        );
      }
    }
    exit;
  }

  echo "Found ".count($rows)." appointments to award\n";

  if ($dry) {
    foreach ($rows as $r) {
      printf("DRY: appt #%d -> +%d points\n", (int)$r['id'], (int)$r['points_award']);
    }
    echo "Done (dry)\n";
    exit;
  }

  // Начисление: просто ставим отметку о начислении (и тут же можно суммировать на карточку клиента, если есть)
  $upd = $db->prepare("UPDATE appointments SET $colAwardedAt = NOW() WHERE id = :id AND $colAwardedAt IS NULL");
  $n = 0;
  foreach ($rows as $r) {
    $upd->execute([':id' => (int)$r['id']]);
    $n++;
    printf("Awarded: appt #%d -> +%d points\n", (int)$r['id'], (int)$r['points_award']);
  }
  echo "OK, updated $n rows\n";
  echo "Done.\n";

} catch (Throwable $e) {
  http_response_code(500);
  echo "Fatal: ".$e->getMessage()."\n";
}
