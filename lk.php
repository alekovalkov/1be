<?php
declare(strict_types=1);

require __DIR__ . '/booking/config.php';

session_start();

// Если пользователь не авторизован — уводим на login.php
if (empty($_SESSION['client_id'])) {
    header('Location: /login.php');
    exit;
}

$clientId = (int)$_SESSION['client_id'];

// Загружаем клиента
$user = db_one("SELECT id, name, email, phone, created_at 
                FROM clients WHERE id = ?", [$clientId]);

// Загружаем его брони
$bookings = db_all("
    SELECT a.id, a.starts, a.ends, a.comment, a.status, a.meta
    FROM appointments a
    WHERE a.client_id = ?
    ORDER BY a.starts DESC
    LIMIT 20
", [$clientId]);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Личный кабинет</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: system-ui, sans-serif; margin: 2rem; }
    .wrap { max-width: 800px; margin: auto; }
    .card { border: 1px solid #ddd; border-radius: 10px; padding: 16px; margin-bottom: 20px; background: #fff; }
    h1 { font-size: 24px; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 8px 10px; border-bottom: 1px solid #eee; text-align: left; }
    th { background: #f9fafb; }
    .btn { display: inline-block; padding: 8px 14px; background: #111827; color: #fff; border-radius: 8px; text-decoration: none; }
    .logout { float: right; }
  </style>
</head>
<body>
<div class="wrap">
  <h1>
    Личный кабинет
    <a class="btn logout" href="/logout.php">Выйти</a>
  </h1>

  <div class="card">
    <h2>Ваш профиль</h2>
    <p><b>Имя:</b> <?= htmlspecialchars($user['name'] ?: '—') ?></p>
    <p><b>Email:</b> <?= htmlspecialchars($user['email']) ?></p>
    <p><b>Телефон:</b> <?= htmlspecialchars($user['phone'] ?: '—') ?></p>
    <p><b>Зарегистрирован:</b> <?= htmlspecialchars($user['created_at']) ?></p>
  </div>

  <div class="card">
    <h2>Ваши записи</h2>
    <?php if ($bookings): ?>
      <table>
        <tr>
          <th>ID</th>
          <th>Начало</th>
          <th>Окончание</th>
          <th>Комментарий</th>
          <th>Статус</th>
        </tr>
        <?php foreach ($bookings as $b): ?>
          <tr>
            <td>#<?= (int)$b['id'] ?></td>
            <td><?= htmlspecialchars($b['starts']) ?></td>
            <td><?= htmlspecialchars($b['ends']) ?></td>
            <td><?= htmlspecialchars($b['comment'] ?: '—') ?></td>
            <td><?= htmlspecialchars($b['status'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <p>У вас пока нет записей.</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
