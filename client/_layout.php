<?php
declare(strict_types=1);
/** простой layout-хелпер */
function page_header(string $title = 'Личный кабинет'): void { ?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title, ENT_QUOTES) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{--bg:#f3f4f6;--card:#fff;--line:#e5e7eb;--text:#111827;--muted:#6b7280;}
    body{margin:0;background:var(--bg);color:var(--text);font:16px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Arial}
    .wrap{max-width:960px;margin:24px auto;padding:0 14px}
    .row{display:grid;grid-template-columns:1fr;gap:16px}
    .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px}
    a{color:#111827}
    .nav{display:flex;gap:12px;align-items:center;margin-bottom:12px}
    .nav a{display:inline-block;padding:8px 12px;border-radius:999px;background:#eef2ff;text-decoration:none}
    .muted{color:var(--muted)}
    .btn{display:inline-block;background:#111827;color:#fff;border:0;border-radius:10px;padding:8px 12px;cursor:pointer;text-decoration:none}
    table{width:100%;border-collapse:collapse}
    th,td{padding:8px;border-bottom:1px solid var(--line);text-align:left}
    .right{text-align:right}
    .danger{background:#fee2e2;color:#7f1d1d}
    .ok{color:#16a34a}
  </style>
</head>
<body>
<div class="wrap">
  <div class="nav">
    <strong>ЛК клиента</strong>
    <a href="/client/">Мои брони</a>
    <a href="/client/profile.php">Профиль</a>
    <span class="muted" style="margin-left:auto">
      <?= isset($_SESSION['client_name']) ? 'Здравствуйте, '.htmlspecialchars((string)$_SESSION['client_name']) : '' ?>
    </span>
    <a href="/logout.php">Выйти</a>
  </div>
<?php }

function page_footer(): void { ?>
</div>
</body>
</html>
<?php }
