<?php
declare(strict_types=1);

require __DIR__ . '/../booking/config.php';
require __DIR__ . '/_auth.php';
require __DIR__ . '/_layout.php';

$pdo = pdo();
$clientId = (int)$_SESSION['client_id'];

$st = $pdo->prepare("SELECT id, email, name, phone FROM clients WHERE id = ?");
$st->execute([$clientId]);
$user = $st->fetch();
if (!$user) { header('Location: /logout.php'); exit; }

$ok = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name  = trim($_POST['name']  ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $email = trim($_POST['email'] ?? '');

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Неверный email';

  // смена пароля (опционально)
  $newPass = $_POST['new_password'] ?? '';
  $newPass2= $_POST['new_password2'] ?? '';
  if ($newPass !== '' || $newPass2 !== '') {
    if ($newPass !== $newPass2) $errors[] = 'Пароли не совпадают';
    if (strlen($newPass) < 6)   $errors[] = 'Пароль должен быть не короче 6 символов';
  }

  if (!$errors) {
    try {
      // проверка уникальности email
      $chk = $pdo->prepare("SELECT id FROM clients WHERE email = ? AND id <> ?");
      $chk->execute([$email, $clientId]);
      if ($chk->fetch()) {
        $errors[] = 'Такой email уже используется';
      } else {
        $pdo->beginTransaction();
        $u = $pdo->prepare("UPDATE clients SET name=:n, phone=:p, email=:e WHERE id=:id");
        $u->execute([':n'=>$name, ':p'=>$phone, ':e'=>$email, ':id'=>$clientId]);

        if ($newPass !== '' && $newPass === $newPass2) {
          $hash = password_hash($newPass, PASSWORD_DEFAULT);
          $u2 = $pdo->prepare("UPDATE clients SET password_hash=:h WHERE id=:id");
          $u2->execute([':h'=>$hash, ':id'=>$clientId]);
        }

        $pdo->commit();
        $_SESSION['client_name'] = $name ?: $email;
        $ok = 'Сохранено';
        // обновим $user для формы
        $st = $pdo->prepare("SELECT id, email, name, phone FROM clients WHERE id = ?");
        $st->execute([$clientId]);
        $user = $st->fetch();
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Ошибка БД: ' . $e->getMessage();
    }
  }
}

page_header('Профиль');
?>
<div class="row">
  <div class="card">
    <h2>Профиль</h2>

    <?php if ($ok): ?><p class="ok"><?= htmlspecialchars($ok) ?></p><?php endif; ?>
    <?php if ($errors): ?>
      <ul class="danger" style="padding:10px;border-radius:10px;list-style:disc inside;background:#fee2e2;color:#7f1d1d">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="post" style="display:grid;gap:10px;max-width:520px">
      <label>Имя
        <input type="text" name="name" value="<?= htmlspecialchars((string)($user['name'] ?? ''), ENT_QUOTES) ?>">
      </label>
      <label>Телефон
        <input type="text" name="phone" value="<?= htmlspecialchars((string)($user['phone'] ?? ''), ENT_QUOTES) ?>">
      </label>
      <label>Email
        <input type="email" name="email" required value="<?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES) ?>">
      </label>

      <fieldset style="border:1px dashed #e5e7eb;border-radius:10px;padding:10px">
        <legend class="muted">Смена пароля (необязательно)</legend>
        <label>Новый пароль
          <input type="password" name="new_password" placeholder="••••••">
        </label>
        <label>Повторите новый пароль
          <input type="password" name="new_password2" placeholder="••••••">
        </label>
      </fieldset>

      <button class="btn" type="submit">Сохранить</button>
    </form>
  </div>
</div>
<?php page_footer();
