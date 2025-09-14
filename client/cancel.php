<?php
declare(strict_types=1);

require __DIR__ . '/../booking/config.php';
require __DIR__ . '/_auth.php';

$pdo = pdo();
$clientId = (int)$_SESSION['client_id'];

// ===== Настройки e-mail уведомлений =====
// ЗАМЕНИ на свои адреса домена:
$MAIL_FROM   = 'info@manikuur.ee';  // адрес отправителя
$ADMIN_EMAIL = 'alekovalkov@gmail.com';     // куда слать уведомление админу

// Простая отправка HTML-писем через mail()
function send_email_simple(string $to, string $subject, string $html, string $from): void {
  $headers  = "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
  $headers .= "From: ".$from."\r\n";
  @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $html, $headers);
}

// ===== входной параметр =====
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
  header('Location: /client/', true, 302);
  exit;
}

// ===== helper: определить имя подходящей колонки =====
function pick_col(PDO $db, string $table, array $cands): ?string {
  $q = $db->query("SHOW COLUMNS FROM `$table`");
  $have = array_column($q->fetchAll(PDO::FETCH_ASSOC), 'Field');
  foreach ($cands as $c) if (in_array($c, $have, true)) return $c;
  return null;
}

$apS    = pick_col($pdo,'appointments',['starts','start_dt','start_at','start','begin_at']) ?: 'starts';
$status = pick_col($pdo,'appointments',['status']);

try {
  // найдём запись клиента
  $st = $pdo->prepare("SELECT id, $apS AS starts FROM `appointments` WHERE id = :id AND client_id = :cid LIMIT 1");
  $st->execute([':id'=>$id, ':cid'=>$clientId]);
  $row = $st->fetch();
  if (!$row) {
    header('Location: /client/', true, 302);
    exit;
  }

  // правило: можно отменить не позже чем за 2 часа
  $starts = new DateTime((string)$row['starts']);
  if ($starts < new DateTime('+2 hours')) {
    header('Location: /client/?err=too-late', true, 302);
    exit;
  }

  // выполнить отмену
  if ($status) {
    // если есть колонка status — помечаем отменой
    $u = $pdo->prepare("UPDATE `appointments` SET `$status` = 'cancelled' WHERE id = :id AND client_id = :cid");
    $u->execute([':id'=>$id, ':cid'=>$clientId]);
  } else {
    // иначе — удаляем (если так задумано)
    $u = $pdo->prepare("DELETE FROM `appointments` WHERE id = :id AND client_id = :cid");
    $u->execute([':id'=>$id, ':cid'=>$clientId]);
  }

  // ===== УВЕДОМЛЕНИЯ ПО E-MAIL (после успешной отмены) =====
  try {
    // подтянем максимум данных для письма
    $svcCol        = pick_col($pdo,'appointments',['service_title','title','service_name']);
    $priceCol      = pick_col($pdo,'appointments',['price_eur','total_eur','price','amount']);
    $clientEmailAp = pick_col($pdo,'appointments',['client_email','email']);

    $selCols = "id, $apS AS starts";
    if ($svcCol)        $selCols .= ", `$svcCol` AS service_title";
    if ($priceCol)      $selCols .= ", `$priceCol` AS price_eur";
    if ($clientEmailAp) $selCols .= ", `$clientEmailAp` AS client_email";

    $q = $pdo->prepare("SELECT $selCols FROM `appointments` WHERE id = :id LIMIT 1");
    $q->execute([':id'=>$id]);
    $ap = $q->fetch() ?: [];

    // e-mail клиента: сначала из appointments, иначе — из clients
    $clientEmail = (string)($ap['client_email'] ?? '');
    if ($clientEmail === '') {
      $q2 = $pdo->prepare("SELECT email FROM `clients` WHERE id = :cid LIMIT 1");
      $q2->execute([':cid'=>$clientId]);
      $clientEmail = (string)($q2->fetchColumn() ?: '');
    }

    // читаемые данные
    $dt       = new DateTime((string)($ap['starts'] ?? 'now'));
    $date     = $dt->format('Y-m-d');
    $time     = $dt->format('H:i');
    $title    = (string)($ap['service_title'] ?? 'Услуга');
    $priceTxt = isset($ap['price_eur']) ? (number_format((float)$ap['price_eur'], 2, '.', ' ').' €') : '—';

    // клиенту
    if ($clientEmail !== '') {
      $subjC = "Отмена брони: $title, $date $time";
      $htmlC = "
        <div style='font:14px/1.5 system-ui, -apple-system, Segoe UI, Roboto, Arial'>
          <p>Ваша бронь отменена.</p>
          <p><b>Услуга:</b> ".htmlspecialchars($title)."<br>
             <b>Дата:</b> $date<br>
             <b>Время:</b> $time<br>
             <b>Цена:</b> $priceTxt</p>
          <p>Если вы не совершали эту отмену — ответьте на это письмо.</p>
        </div>";
      send_email_simple($clientEmail, $subjC, $htmlC, $MAIL_FROM);
    }

    // админу
    if ($ADMIN_EMAIL !== '') {
      $subjA = "Отменена бронь #$id — $title, $date $time";
      $htmlA = "
        <div style='font:14px/1.5 system-ui, -apple-system, Segoe UI, Roboto, Arial'>
          <p>Клиент отменил бронь.</p>
          <p><b>ID:</b> $id<br>
             <b>Услуга:</b> ".htmlspecialchars($title)."<br>
             <b>Дата:</b> $date<br>
             <b>Время:</b> $time<br>
             <b>Цена:</b> $priceTxt<br>
             <b>Клиент ID:</b> $clientId<br>
             <b>Email клиента:</b> ".htmlspecialchars($clientEmail ?: '—')."</p>
        </div>";
      send_email_simple($ADMIN_EMAIL, $subjA, $htmlA, $MAIL_FROM);
    }
  } catch (Throwable $eMail) {
    // по желанию: error_log('Mail fail: '.$eMail->getMessage());
  }

  // и обратно в ЛК
  header('Location: /client/?ok=cancelled', true, 302);
  exit;

} catch (Throwable $e) {
  // по желанию: error_log($e->getMessage());
  header('Location: /client/', true, 302);
  exit;
}
