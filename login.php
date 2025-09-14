<?php
declare(strict_types=1);

require __DIR__ . '/booking/config.php';
session_start();

$errors = [];
$email = trim($_POST['email'] ?? ''); // чтобы не было Notice при первом заходе
$password = $_POST['password'] ?? '';
$next = isset($_GET['next']) ? (string)$_GET['next'] : '/'; // куда вернуть после входа

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Валидация
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Введите корректный email';
    }
    if ($password === '') {
        $errors[] = 'Введите пароль';
    }

    if (!$errors) {
        try {
            // Получаем пользователя
            $st = pdo()->prepare("SELECT id, email, name, password_hash FROM clients WHERE email = ? LIMIT 1");
            $st->execute([$email]);
            $user = $st->fetch(); // FETCH_ASSOC уже задан в config.php

            // Фиктивный bcrypt-хэш для выравнивания времени (если такого email нет)
            // Можно любой валидный bcrypt-хэш, например от строки "placeholder"
            $dummyHash = '$2y$10$wH2m9h7B9b4m7x0s9N0J9eZ0a2lE2Yt7xg0eFZkqvQf8o9iB1dQbS'; 

            $hashToVerify = is_array($user) ? ($user['password_hash'] ?? '') : $dummyHash;
            $ok = password_verify($password, $hashToVerify);

            if ($ok && $user) {
                // Успех входа: обновляем id сессии
                session_regenerate_id(true);
                $_SESSION['client_id']   = (int)$user['id'];
                $_SESSION['client_name'] = $user['name'] ?? $user['email'];

                // Безопасный редирект: разрешим только относительные пути
                if (!preg_match('~^/[^/].*~', $next)) {
                    $next = '/';
                }
                header('Location: ' . $next, true, 302);
                exit;
            }

            $errors[] = 'Неверный email или пароль';
        } catch (Throwable $e) {
            $errors[] = 'Ошибка БД: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Вход</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: sans-serif; margin: 2rem; }
    form { max-width: 400px; margin: auto; display: grid; gap: 12px; }
    input { padding: 8px; border: 1px solid #ccc; border-radius: 6px; }
    button { padding: 10px; background: #111827; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
    .msg { margin: 1rem auto; max-width: 400px; }
    .err { color: #b91c1c; }
  </style>
</head>
<body>
  <h1>Вход</h1>

  <?php if ($errors): ?>
    <div class="msg err">
      <ul>
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES) ?>">
    <input type="email" name="email" placeholder="Email" required value="<?= htmlspecialchars($email, ENT_QUOTES) ?>">
    <input type="password" name="password" placeholder="Пароль" required>
    <button type="submit">Войти</button>
  </form>

  <p>Нет аккаунта? <a href="/register.php<?= $next !== '/' ? '?next='.urlencode($next) : '' ?>">Зарегистрироваться</a></p>
</body>
</html>
