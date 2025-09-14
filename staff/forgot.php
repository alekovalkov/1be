<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// базовый URL (http/https + домен)
function base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme . '://' . $host;
}

// простая отправка письма (через mail())
function send_reset_email(string $toEmail, string $toName, string $link): bool {
  $subject = 'Восстановление пароля';
  $body = "Здравствуйте, {$toName}!\n\n"
        . "Вы запросили ссылку для сброса пароля в личном кабинете мастера.\n"
        . "Перейдите по ссылке (действительна 30 минут):\n\n{$link}\n\n"
        . "Если вы не запрашивали сброс пароля, просто игнорируйте это письмо.";
  $headers = [];
  $headers[] = "MIME-Version: 1.0";
  $headers[] = "Content-Type: text/plain; charset=UTF-8";
  $headers[] = "From: Booking <reset@xn--manikr-7yaa.ee>";
  return @mail($toEmail, '=?UTF-8?B?'.base64_encode($subject).'?=', $body, implode("\r\n",$headers));
}

$sent = false;
$devLink = ''; // покажем ссылку на экране, если отправка не удалась (удобно в деве)
$err  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string)($_POST['email'] ?? ''));

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'Введите корректный e-mail';
  } else {
    try{
      $db = pdo();

      // ищем активного пользователя по e-mail из таблицы staff (email храним в staff)
      $q = $db->prepare("
        SELECT su.id AS staff_user_id, COALESCE(s.name, su.username) AS name
        FROM staff_users su
        JOIN staff s ON s.id = su.staff_id
        WHERE s.email = :e AND su.is_active = 1
        LIMIT 1
      ");
      $q->execute([':e'=>$email]);
      $row = $q->fetch(PDO::FETCH_ASSOC);

      // всегда отвечаем "ссылка отправлена", даже если e-mail не найден — чтобы не палить базу
      $sent = true;

      if ($row) {
        $token = bin2hex(random_bytes(16));
        $expiresAt = (new DateTime('+30 minutes'))->format('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $ins = $db->prepare("
          INSERT INTO staff_reset_tokens (staff_user_id, token, expires_at, ip)
          VALUES (:uid, :t, :exp, :ip)
        ");
        $ins->execute([':uid'=>$row['staff_user_id'], ':t'=>$token, ':exp'=>$expiresAt, ':ip'=>$ip]);

        $link = base_url() . '/staff/reset.php?token=' . urlencode($token);

        // пробуем отправить e-mail
        if (!send_reset_email($email, (string)$row['name'], $link)) {
          // на деве/локале письмо может не отправиться — покажем ссылку прямо на странице
          $devLink = $link;
        }
      }
    }catch(Throwable $e){
      // техническая ошибка — покажем мягкую фразу, чтобы не раскрывать детали
      $sent = true;
    }
  }
}
?>
<!doctype html>
<html lang="ru"><head>
  <meta charset="utf-8">
  <title>Восстановление пароля — ЛК мастера</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu; background:#f6f7fb; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0}
    .card{background:#fff; padding:28px; border-radius:14px; box-shadow:0 6px 24px rgba(16,24,40,.08); width:100%; max-width:420px}
    h1{margin:0 0 16px; font-size:20px}
    label{display:block; font-size:13px; color:#475569; margin:12px 0 6px}
    input{width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:10px; font-size:14px}
    .btn{width:100%; margin-top:16px; background:#111827; color:#fff; border:0; padding:10px 14px; border-radius:10px; font-weight:600; cursor:pointer}
    .muted{color:#6b7280; font-size:13px; margin-top:10px}
    .err{color:#b91c1c; font-size:13px; margin-top:8px}
    .info{background:#ecfeff;border:1px solid #67e8f9;color:#155e75;border-radius:10px;padding:10px 12px;margin-top:12px}
    .dev{background:#fff7ed;border:1px dashed #f59e0b;color:#92400e;padding:8px;border-radius:8px;margin-top:10px;word-break:break-all}
  </style>
</head><body>
  <div class="card">
    <h1>Восстановление пароля</h1>

    <?php if ($sent): ?>
      <div class="info">Если такой e-mail есть в системе, мы отправили письмо со ссылкой для сброса пароля.</div>
      <?php if ($devLink): ?>
        <div class="dev">Отправка e-mail не настроена. Ссылка для теста:<br><a href="<?=h($devLink)?>"><?=h($devLink)?></a></div>
      <?php endif; ?>
      <div class="muted"><a href="/staff/login.php">← Назад ко входу</a></div>
    <?php else: ?>
      <?php if ($err): ?><div class="err"><?=h($err)?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <label>Ваш e-mail</label>
        <input name="email" type="email" placeholder="name@example.com" required>
        <button class="btn" type="submit">Отправить ссылку</button>
      </form>
      <div class="muted"><a href="/staff/login.php">← Назад ко входу</a></div>
    <?php endif; ?>
  </div>
</body></html>
