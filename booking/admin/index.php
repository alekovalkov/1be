<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Админка — Peach</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <h1 class="h1">Админка</h1>
  <div class="nav">
    <a class="btn sec" href="salons.php">Салоны</a>
    <a class="btn sec" href="staff.php">Сотрудники</a>
<a class="btn sec" href="appointments.php">Записи (просмотр / добавить)</a>
  </div>
<li><a href="working_hours.php">График сотрудников</a></li>
<li><a href="time_off.php">Отсутствия (отпуска/блокировки)</a></li>
  <div class="card">
    <p>Здесь вы можете управлять справочниками, которые использует календарь.</p>
    <ul>
      <li><strong>Салоны</strong> — список филиалов (Mustamäe, Kesklinn, Lasnamäe).</li>
      <li><strong>Сотрудники</strong> — мастера, их активность, часовой пояс и привязка к салонам.</li>
    </ul>
    <p class="muted">Дальше добавим рабочие часы, отпуска и т.д. — отдельными шагами.</p>
  </div>
</div>
</body>
</html>
