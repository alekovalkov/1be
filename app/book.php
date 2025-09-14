<?php
// app/book.php
$service = $_GET['svc'] ?? 'classic';
$area    = $_GET['area'] ?? 'manicure';
?><!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Бронирование</title>
<style>
body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f7f8fb;margin:0;padding:20px;color:#111}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;max-width:760px;margin:0 auto;padding:16px}
.row{margin:10px 0}
.btn{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #d1d5db;background:#111827;color:#fff;cursor:pointer}
.btn.ghost{background:#f3f4f6;color:#111827}
.slot{display:inline-block;margin:6px 6px 0 0;padding:8px 12px;border:1px solid #d1d5db;border-radius:10px;cursor:pointer}
.slot.disabled{opacity:.4;pointer-events:none}
.badge{display:inline-block;background:#eef2ff;color:#3730a3;border:1px solid #e0e7ff;border-radius:999px;padding:3px 8px;font-size:12px}
</style>
</head><body>
<div class="card">
  <h2>Выбор времени</h2>
  <div class="row">Услуга: <span class="badge" id="svcBadge"><?=htmlspecialchars($service)?></span></div>
  <div class="row">
    Дата: <input type="date" id="date" value="<?=date('Y-m-d',strtotime('+1 day'))?>">
    <button class="btn ghost" id="load">Показать слоты</button>
  </div>
  <div class="row" id="slots"></div>

  <hr>
  <h3>Ваши данные</h3>
  <div class="row"><input id="name" placeholder="Имя" style="width:260px;padding:8px"></div>
  <div class="row"><input id="phone" placeholder="Телефон" style="width:260px;padding:8px"></div>
  <div class="row"><input id="email" placeholder="Email (необязательно)" style="width:260px;padding:8px"></div>
  <div class="row"><button class="btn" id="book" disabled>Забронировать</button></div>
  <div class="row" id="msg"></div>
</div>

<script>
const svc   = new URLSearchParams(location.search).get('svc') || 'classic';
const dateI = document.getElementById('date');
const slots = document.getElementById('slots');
const btnLoad=document.getElementById('load');
const btnBook=document.getElementById('book');
let selectedTime=null, lastPayload=null;

async function loadSlots(){
  slots.textContent='Загрузка...';
  selectedTime=null; btnBook.disabled=true;
  const r = await fetch(`api/slots.php?date=${dateI.value}&service=${encodeURIComponent(svc)}&staff_id=1`);
  const data = await r.json();
  lastPayload = data;
  slots.innerHTML='';
  if (!data.slots || data.slots.length===0){ slots.textContent='Нет свободных слотов на выбранную дату.'; return; }
  data.slots.forEach(t=>{
    const b=document.createElement('button');
    b.className='slot'; b.textContent=t;
    b.onclick=()=>{ document.querySelectorAll('.slot').forEach(x=>x.classList.remove('selected'));
                    b.classList.add('selected'); selectedTime=t; btnBook.disabled=false; };
    slots.appendChild(b);
  });
}

btnLoad.onclick=loadSlots;
loadSlots();

btnBook.onclick=async ()=>{
  if(!selectedTime) return;
  const start = `${dateI.value} ${selectedTime}`;
  const body = new URLSearchParams({
    name: document.getElementById('name').value.trim(),
    phone:document.getElementById('phone').value.trim(),
    email:document.getElementById('email').value.trim(),
    staff_id: '1',
    salon_id: '1',
    service: svc,
    start
  });
  const r = await fetch('api/book.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
  const data = await r.json();
  const msg = document.getElementById('msg');
  if (data.ok){ msg.textContent = 'Готово! Бронь № ' + data.appointment_id; btnBook.disabled=true; }
  else { msg.textContent = 'Ошибка: ' + (data.error||'unknown'); }
};
</script>
</body></html>
