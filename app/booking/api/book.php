<?php
// app/api/book.php
header('Content-Type: application/json; charset=utf-8');
require __DIR__.'/../_db.php';

$input = $_POST ?: json_decode(file_get_contents('php://input'), true) ?: [];

$name  = trim($input['name']  ?? '');
$phone = trim($input['phone'] ?? '');
$email = trim($input['email'] ?? '');
$staffId = (int)($input['staff_id'] ?? 1);
$salonId = (int)($input['salon_id'] ?? 1);
$serviceCode = $input['service'] ?? '';
$startIso = $input['start'] ?? ''; // YYYY-MM-DD HH:MM

if (!$name || !$serviceCode || !$startIso) {
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing_fields']); exit;
}

$svcQ = $pdo->prepare("SELECT id,duration_min,price_eur FROM services WHERE code=?");
$svcQ->execute([$serviceCode]);
$svc = $svcQ->fetch();
if (!$svc){ http_response_code(404); echo json_encode(['ok'=>false,'error'=>'service_not_found']); exit; }

$start = new DateTimeImmutable($startIso);
$end   = $start->modify("+{$svc['duration_min']} minutes");

// client: find or create
$cid = null;
if ($email) {
  $q=$pdo->prepare("SELECT id FROM clients WHERE email=? LIMIT 1"); $q->execute([$email]); $cid=$q->fetchColumn();
}
if (!$cid && $phone) {
  $q=$pdo->prepare("SELECT id FROM clients WHERE phone=? LIMIT 1"); $q->execute([$phone]); $cid=$q->fetchColumn();
}
if (!$cid){
  $q=$pdo->prepare("INSERT INTO clients (name,phone,email) VALUES (?,?,?)");
  $q->execute([$name,$phone,$email]);
  $cid = (int)$pdo->lastInsertId();
}

// overlap check
$q=$pdo->prepare("SELECT COUNT(*) FROM appointments WHERE staff_id=? AND NOT(ends<=? OR starts>=?)");
$q->execute([$staffId, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
if ($q->fetchColumn() > 0){ http_response_code(409); echo json_encode(['ok'=>false,'error'=>'slot_taken']); exit; }

// insert
$ins=$pdo->prepare("INSERT INTO appointments (client_id,staff_id,salon_id,service_id,starts,ends,price_eur,status) VALUES (?,?,?,?,?,?,?,'pending')");
$ins->execute([$cid,$staffId,$salonId,$svc['id'],$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$svc['price_eur']]);

echo json_encode(['ok'=>true,'appointment_id'=>(int)$pdo->lastInsertId()]);
