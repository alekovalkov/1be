<?php
declare(strict_types=1);
session_start();
$name = $_SESSION['client_name'] ?? null;
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Главная</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font:16px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:24px}
    a.btn{display:inline-block;padding:10px 14px;border-radius:10px;background:#111827;color:#fff;text-decoration:none;margin-right:8px}
    .muted{color:#6b7280}
  </style>
</head>
<body>
  <h1>Онлайн-запись</h1>
  <?php if ($name): ?>
    <p class="muted">Вы вошли как <?= htmlspecialchars($name, ENT_QUOTES) ?></p>
    <p>
      <a class="btn" href="/client/">Мои брони</a>
      <a class="btn" href="/client/profile.php">Профиль</a>
      <a class="btn" href="/booking/">Календарь</a>
      <a class="btn" href="/logout.php">Выйти</a>
    </p>
  <?php else: ?>
    <p>
      <a class="btn" href="/login.php">Войти</a>
      <a class="btn" href="/register.php">Регистрация</a>
      <a class="btn" href="/booking/">Календарь</a>
    </p>
  <?php endif; ?>
</body>
</html>
