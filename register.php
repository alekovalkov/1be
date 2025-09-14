<?php
declare(strict_types=1);

require __DIR__ . '/booking/config.php';

session_start();

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $password = $_POST['password'] ?? '';

    // Валидация
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Неверный email';
    }
    if ($password === '' || strlen($password) < 6) {
        $errors[] = 'Пароль должен быть не меньше 6 символов';
    }

    if (!$errors) {
        try {
            $st = pdo()->prepare("SELECT id FROM clients WHERE email = ?");
            $st->execute([$email]);
            if ($st->fetch()) {
                $errors[] = 'Такой email уже зарегистрирован';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $st = pdo()->prepare(
                    "INSERT INTO clients (email, phone, password_hash, name) VALUES (?, ?, ?, ?)"
                );
                $st->execute([$email, $phone, $hash, $name]);
                $success = true;
            }
        } catch (Throwable $e) {
            $errors[] = "Ошибка БД: " . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Регистрация</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: sans-serif; margin: 2rem; }
    form { max-width: 400px; margin: auto; display: grid; gap: 12px; }
    input { padding: 8px; border: 1px solid #ccc; border-radius: 6px; }
    button { padding: 10px; background: #111827; color: #fff; border: 0; border-radius: 6px; cursor: pointer; }
    .msg { margin: 1rem auto; max-width: 400px; }
    .err { color: #b91c1c; }
    .ok { color: #16a34a; }
  </style>
</head>
<body>
  <h1>Регистрация</h1>

  <?php if ($success): ?>
    <div class="msg ok">Вы успешно зарегистрированы! <a href="login.php">Войти</a></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="msg err">
      <ul>
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post">
    <input type="text" name="name" placeholder="Имя" value="<?= htmlspecialchars($name ?? '', ENT_QUOTES) ?>">
    <input type="email" name="email" placeholder="Email" required value="<?= htmlspecialchars($email ?? '', ENT_QUOTES) ?>">
    <input type="text" name="phone" placeholder="Телефон" value="<?= htmlspecialchars($phone ?? '', ENT_QUOTES) ?>">
    <input type="password" name="password" placeholder="Пароль" required>
    <button type="submit">Зарегистрироваться</button>
  </form>
</body>
</html>
