<?php
// booking/book.php
declare(strict_types=1);
require __DIR__.'/config.php';

function fail(string $msg){
  http_response_code(400);
  echo '<!doctype html><meta charset="utf-8"><div class="success" style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:10px;margin:20px;border-radius:8px">'
      .h($msg).'</div>';
  exit;
}

$svcCode = trim($_POST['svc'] ?? '');
$date    = trim($_POST['date'] ?? '');
$time    = trim($_POST['time'] ?? '');
$staffId = (int)($_POST['staff_id'] ?? 0);
$name    = trim($_POST['name'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$email   = trim($_POST['email'] ?? '');

if ($svcCode==='' || $date==='' || $time==='' || !$staffId) fail('Не хватает данных слота.');
$svc = getServiceByCode($svcCode); if (!$svc) fail('Услуга не найдена.');
if ($name==='' || $phone==='') fail('Укажите имя и телефон.');

$starts = DateTime::createFromFormat('Y-m-d H:i', $date.' '.$time);
if (!$starts) fail('Неверная дата/время.');
$ends = (clone $starts)->add(new DateInterval('PT'.((int)$svc['duration_min']).'M'));

// Проверяем, что слот ещё свободен
$st = pdo()->prepare("SELECT 1 FROM appointments
  WHERE staff_id=? AND status IN ('pending','confirmed','noshow')
    AND NOT (ends <= :s OR starts >= :e) LIMIT 1");
$st->execute([$staffId, ':s'=>$starts->format('Y-m-d H:i:s'), ':e'=>$ends->format('Y-m-d H:i:s')]);
if ($st->fetch()) fail('Увы, слот только что заняли. Выберите другое время.');

// Клиент (по телефону/почте)
$clientId = null;
if ($phone !== '') {
  $st = pdo()->prepare("SELECT id FROM clients WHERE phone = ? LIMIT 1");
  $st->execute([$phone]);
  $row = $st->fetch();
  if ($row) $clientId = (int)$row['id'];
}
if (!$clientId) {
  $st = pdo()->prepare("INSERT INTO clients(name,phone,email) VALUES(?,?,?)");
  $st->execute([$name,$phone,($email?:null)]);
  $clientId = (int)pdo()->lastInsertId();
}

// Салон — берём первый, к которому привязан мастер (или 1)
$st = pdo()->prepare("SELECT salon_id FROM staff_salons WHERE staff_id=? LIMIT 1");
$st->execute([$staffId]);
$row = $st->fetch();
$salonId = $row ? (int)$row['salon_id'] : 1;

// Записываем
$price = (float)$svc['price_eur'];
$st = pdo()->prepare("INSERT INTO appointments(client_id, staff_id, salon_id, service_id, starts, ends, price_eur, status, meta)
                      VALUES(?,?,?,?,?,?,?,'pending', JSON_OBJECT('source','quiz'))");
$st->execute([
  $clientId, $staffId, $salonId, (int)$svc['id'],
  $starts->format('Y-m-d H:i:s'), $ends->format('Y-m-d H:i:s'), $price
]);

?>
<!doctype html>
<meta charset="utf-8">
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#fafafa;margin:0}
.wrap{max-width:720px;margin:24px auto;padding:0 16px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px}
h1{font-size:22px;margin:0 0 12px}
.success{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px 12px;border-radius:8px}
.btn{display:inline-block;padding:10px 14px;border-radius:10px;border:0;background:#111827;color:#fff;cursor:pointer;text-decoration:none}
</style>
<div class="wrap">
  <div class="card">
    <h1>Заявка создана</h1>
    <div class="success">Мы записали вас на <?=h($starts->format('d.m.Y H:i'))?> (услуга: <?=h($svc['title_ru'])?>).
      Статус: <strong>ожидает подтверждения</strong>.
    </div>
    <p style="margin-top:12px">
      <a class="btn" href="/quiz.php">Вернуться в квиз</a>
    </p>
  </div>
</div>
