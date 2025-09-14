<?php
declare(strict_types=1);
require __DIR__ . '/common.php';

$action = $_GET['action'] ?? '';

try {
  $pdo = db();

  // ===== SALONS =====
  if ($action === 'salons_list') {
    $rows = $pdo->query("SELECT id, name FROM salons ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; }
    json_out(['ok'=>true,'items'=>$rows]);
  }

  if ($action === 'salon_create') {
    $in = read_json();
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') json_out(['ok'=>false,'error'=>'name required'], 400);
    $st = $pdo->prepare("INSERT INTO salons(name) VALUES(?)");
    $st->execute([$name]);
    json_out(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
  }

  if ($action === 'salon_update') {
    $in = read_json();
    $id   = (int)($in['id'] ?? 0);
    $name = trim((string)($in['name'] ?? ''));
    if ($id<=0 || $name==='') json_out(['ok'=>false,'error'=>'id/name required'], 400);
    $st = $pdo->prepare("UPDATE salons SET name=? WHERE id=?");
    $st->execute([$name,$id]);
    json_out(['ok'=>true]);
  }

  if ($action === 'salon_delete') {
    $in = read_json();
    $id = (int)($in['id'] ?? 0);
    if ($id<=0) json_out(['ok'=>false,'error'=>'id required'], 400);
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM staff_salons WHERE salon_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM salons WHERE id=?")->execute([$id]);
    $pdo->commit();
    json_out(['ok'=>true]);
  }

  // ===== STAFF =====
  if ($action === 'staff_list') {
    // отдадим сотрудников + их салоны одной структурой
    $staff = $pdo->query("
      SELECT id,
             name,
             is_active,
             COALESCE(tz,'Europe/Tallinn') AS tz,
             email,
             phone
      FROM staff
      ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($staff as &$s) {
      $s['id']        = (int)$s['id'];
      $s['is_active'] = (int)$s['is_active'];
      // Нормализуем пустые поля, чтобы на фронте было удобно
      $s['email'] = isset($s['email']) ? (string)$s['email'] : '';
      $s['phone'] = isset($s['phone']) ? (string)$s['phone'] : '';
    }

    $map = $pdo->query("SELECT staff_id, salon_id FROM staff_salons ORDER BY staff_id, salon_id")->fetchAll(PDO::FETCH_ASSOC);
    $by = [];
    foreach ($map as $m) { $by[(int)$m['staff_id']][] = (int)$m['salon_id']; }
    foreach ($staff as &$s) { $s['salons'] = $by[$s['id']] ?? []; }

    $salons = $pdo->query("SELECT id,name FROM salons ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($salons as &$r) { $r['id']=(int)$r['id']; }

    json_out(['ok'=>true,'staff'=>$staff,'salons'=>$salons]);
  }

  if ($action === 'staff_create') {
    $in = read_json();
    $name      = trim((string)($in['name'] ?? ''));
    $tz        = trim((string)($in['tz'] ?? 'Europe/Tallinn'));
    $is_active = (int)($in['is_active'] ?? 1);
    $email     = trim((string)($in['email'] ?? '')) ?: null;
    $phone     = trim((string)($in['phone'] ?? '')) ?: null;
    $salon_ids = array_values(array_filter(array_map('intval', (array)($in['salons'] ?? []))));

    if ($name==='') json_out(['ok'=>false,'error'=>'name required'], 400);

    $pdo->beginTransaction();

    // добавили email/phone
    $st = $pdo->prepare("
      INSERT INTO staff(name, tz, is_active, email, phone)
      VALUES(?,?,?,?,?)
    ");
    $st->execute([
      $name,
      $tz ?: 'Europe/Tallinn',
      $is_active ? 1 : 0,
      $email,
      $phone
    ]);
    $id = (int)$pdo->lastInsertId();

    if ($salon_ids) {
      $ins = $pdo->prepare("INSERT INTO staff_salons(staff_id, salon_id) VALUES(?,?)");
      foreach ($salon_ids as $sid) { $ins->execute([$id,$sid]); }
    }

    $pdo->commit();
    json_out(['ok'=>true,'id'=>$id]);
  }

  if ($action === 'staff_update') {
    $in = read_json();
    $id        = (int)($in['id'] ?? 0);
    $name      = trim((string)($in['name'] ?? ''));
    $tz        = trim((string)($in['tz'] ?? 'Europe/Tallinn'));
    $is_active = (int)($in['is_active'] ?? 1);
    $email     = trim((string)($in['email'] ?? '')) ?: null;
    $phone     = trim((string)($in['phone'] ?? '')) ?: null;
    $salon_ids = array_values(array_filter(array_map('intval', (array)($in['salons'] ?? []))));

    if ($id<=0 || $name==='') json_out(['ok'=>false,'error'=>'id/name required'], 400);

    $pdo->beginTransaction();

    // добавили email/phone
    $pdo->prepare("
      UPDATE staff
         SET name=?,
             tz=?,
             is_active=?,
             email=?,
             phone=?
       WHERE id=?
    ")->execute([
      $name,
      $tz ?: 'Europe/Tallinn',
      $is_active ? 1 : 0,
      $email,
      $phone,
      $id
    ]);

    // связи с салонами
    $pdo->prepare("DELETE FROM staff_salons WHERE staff_id=?")->execute([$id]);
    if ($salon_ids) {
      $ins = $pdo->prepare("INSERT INTO staff_salons(staff_id, salon_id) VALUES(?,?)");
      foreach ($salon_ids as $sid) { $ins->execute([$id,$sid]); }
    }

    $pdo->commit();
    json_out(['ok'=>true]);
  }

  if ($action === 'staff_delete') {
    $in = read_json();
    $id = (int)($in['id'] ?? 0);
    if ($id<=0) json_out(['ok'=>false,'error'=>'id required'], 400);
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM staff_salons WHERE staff_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM staff WHERE id=?")->execute([$id]);
    $pdo->commit();
    json_out(['ok'=>true]);
  }

  json_out(['ok'=>false,'error'=>'unknown action'], 400);

} catch (Throwable $e) {
  json_out(['ok'=>false,'error'=>$e->getMessage()], 500);
}
