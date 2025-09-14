<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$ok    = false;
$err   = '';
$showForm = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pass1 = (string)($_POST['password'] ?? '');
  $pass2 = (string)($_POST['password2'] ?? '');

  if (strlen($pass1) < 6) {
    $err = 'Пароль слишком короткий (минимум 6 символов).';
  } elseif ($pass1 !== $pass2) {
    $err = 'Пароли не совпадают.';
  } elseif ($token === '') {
    $err = 'Неверная ссылка.';
  } else {
    try{
      $db = pdo();

      // валидируем токен: существует, не использован, не истёк
      $q = $db->prepare("
        SELECT t.id, t.staff_user_id, t.expires_at, t.used_at, su.id AS su_id
        FROM staff_reset_tokens t
        JOIN staff_users su ON su.id = t.staff_user_id AND su.is_active = 1
        WHERE t.token = :t
        LIMIT 1
      ");
      $q->execute([':t'=>$token]);
      $row = $q->fetch(PDO::FETCH_ASSOC);

      if (!$row) {
        $err = 'Ссылка недействительна.';
      } elseif (!empty($row['used_at'])) {
        $err = 'Эта ссылка уже была использована.';
      } elseif (new DateTime($row['expires_at']) < new DateTime()) {
        $err = 'Срок действия ссылки истёк.';
      } else {
        // меняем пароль
        $hash = password_hash($pass1, PASSWORD_BCRYPT);
        $db->beginTransaction();

        $up1 = $db->prepare("UPDATE staff_users SET pass_hash=:h WHERE id=:id");
        $up1->execute([':h'=>$hash, ':id'=>(int)$row['su_id']]);

        $up2 = $db->prepare("UPDATE staff_reset_tokens SET used_at=NOW() WHERE id=:id");
        $up2->execute([':id'=>(int)$row['id']]);

        $db->commit();
        $ok = true;
        $showForm = false;
      }
    }catch(Throwable $e){
      if ($db && $db->inTransaction()) $db->rollBack();
      $err = 'Ошибка: не удалось обновить пароль.';
    }
  }
}
?>
<!doctype html>
<html lang="ru"><head>
  <meta charset="utf-8">
  <title>Новый пароль — ЛК мастера</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu; background:#f6f7fb; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0}
    .card{background:#fff; padding:28px; border-radius:14px; box-shadow:0 6px 24px rgba(16,24,40,.08); width:100%; max-width:420px}
    h1{margin:0 0 16px; font-size:20px}
    label{display:block; font-size:13px; color:#475569; margin:12px 0 6px}
    input{width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:10px; font-size:14px}
    .btn{width:100%; margin-top:16px; background:#111827; color:#fff; border:0; padding:10px 14px; border-radius:10px; font-weight:600; cursor:pointer}
    .ok{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;border-radius:10px;padding:10px 12px;margin-top:12px}
    .err{color:#b91c1c; font-size:13px; margin-top:8px}
    .muted{color:#6b7280; font-size:13px; margin-top:10px}
  </style>
</head><body>
  <div class="card">
    <h1>Новый пароль</h1>

    <?php if ($ok): ?>
      <div class="ok">Пароль успешно изменён. Теперь можно войти.</div>
      <div class="muted"><a href="/staff/login.php">→ Перейти ко входу</a></div>
    <?php else: ?>
      <?php if ($err): ?><div class="err"><?=h($err)?></div><?php endif; ?>

      <?php if ($showForm): ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="token" value="<?=h($token)?>">
          <label>Новый пароль</label>
          <input name="password" type="password" required>
          <label>Повторите пароль</label>
          <input name="password2" type="password" required>
          <button class="btn" type="submit">Сохранить</button>
        </form>
      <?php else: ?>
        <div class="muted"><a href="/staff/login.php">→ Перейти ко входу</a></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</body></html>
