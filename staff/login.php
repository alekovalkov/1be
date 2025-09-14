<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim((string)($_POST['username'] ?? ''));
  $password = (string)($_POST['password'] ?? '');

  try {
    if ($username === '' || $password === '') {
      throw new RuntimeException('Введите логин и пароль');
    }

    $db = db(); // единая обёртка над PDO

    // простая таблица staff_users: id, username, pass_hash, staff_id, role, is_active
    $st = $db->prepare("
      SELECT id, username, pass_hash, staff_id, role, is_active
      FROM staff_users
      WHERE username = :u
      LIMIT 1
    ");
    $st->execute([':u' => $username]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (
      $row &&
      (int)$row['is_active'] === 1 &&
      password_verify($password, (string)$row['pass_hash'])
    ) {
      // Узнаём имя мастера (красиво для интерфейса, не обязательно)
      $name = $row['username'];
      $st2 = $db->prepare("SELECT name FROM staff WHERE id = :sid");
      $st2->execute([':sid' => (int)$row['staff_id']]);
      $n2 = $st2->fetchColumn();
      if ($n2) $name = (string)$n2;

      // ВАЖНО: используем общий механизм авторизации
      // чтобы require_staff_auth() нас увидел
      $role = (string)($row['role'] ?? 'master');
      login_staff((int)$row['staff_id'], $row['username'], $role);

      // при желании сохраним «display name» отдельно
      $_SESSION['staff_display_name'] = $name;

      $next = isset($_GET['next']) ? (string)$_GET['next'] : '/staff/';
      header('Location: ' . $next);
      exit;
    } else {
      $err = 'Неверный логин или пароль';
    }
  } catch (Throwable $e) {
    $err = 'Ошибка: ' . $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="ru"><head>
  <meta charset="utf-8">
  <title>Вход — ЛК мастера</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu; background:#f6f7fb; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0}
    .card{background:#fff; padding:28px; border-radius:14px; box-shadow:0 6px 24px rgba(16,24,40,.08); width:100%; max-width:360px}
    h1{margin:0 0 16px; font-size:20px}
    label{display:block; font-size:13px; color:#475569; margin:12px 0 6px}
    input{width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:10px; font-size:14px}
    .btn{width:100%; margin-top:16px; background:#111827; color:#fff; border:0; padding:10px 14px; border-radius:10px; font-weight:600; cursor:pointer}
    .err{color:#b91c1c; font-size:13px; margin-top:8px}
    .links{margin-top:10px; font-size:13px}
    .links a{color:#2563eb; text-decoration:none}
  </style>
</head><body>
  <div class="card">
    <h1>ЛК мастера — вход</h1>
    <?php if ($err): ?><div class="err"><?=h($err)?></div><?php endif; ?>
    <form method="post">
      <label>Логин</label>
      <input name="username" autocomplete="username" required>
      <label>Пароль</label>
      <input name="password" type="password" autocomplete="current-password" required>
      <button class="btn" type="submit">Войти</button>
    </form>
    <div class="links">
      <a href="/staff/forgot.php">Забыли пароль?</a>
    </div>
  </div>
</body></html>
