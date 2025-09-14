<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Салоны — Админка</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <h1 class="h1">Салоны</h1>
  <div class="nav">
    <a class="btn sec" href="index.php">← Назад</a>
  </div>

  <div class="grid">
    <div class="card">
      <h3>Список</h3>
      <table class="table" id="tbl">
        <thead><tr><th>ID</th><th>Название</th><th></th></tr></thead>
        <tbody></tbody>
      </table>
    </div>

    <div class="card">
      <h3 id="formTitle">Новый салон</h3>
      <div class="row"><label>Название</label><input id="name" class="input" autocomplete="off"></div>
      <div style="margin-top:12px;display:flex;gap:8px">
        <button class="btn" id="saveBtn">Сохранить</button>
        <button class="btn sec" id="resetBtn" type="button">Очистить</button>
      </div>
      <div style="margin-top:10px"><span class="badge" id="msg" style="display:none"></span></div>
      <input type="hidden" id="id">
    </div>
  </div>
</div>

<script>
const api = (a,d)=>fetch('api.php?action='+a,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d||{})}).then(r=>r.json());
const $ = s=>document.querySelector(s);
let editingId = 0;

async function load(){
  const res = await fetch('api.php?action=salons_list').then(r=>r.json());
  const tb = $('#tbl tbody'); tb.innerHTML='';
  (res.items||[]).forEach(it=>{
    const tr=document.createElement('tr');
    tr.innerHTML = `<td>${it.id}</td><td>${it.name}</td>
      <td style="text-align:right">
        <button class="btn sec" data-ed="${it.id}">Изменить</button>
        <button class="btn" style="background:#dc2626" data-del="${it.id}">Удалить</button>
      </td>`;
    tb.appendChild(tr);
  });
}
load();

document.addEventListener('click', async (e)=>{
  const ed = e.target.getAttribute('data-ed');
  const del= e.target.getAttribute('data-del');
  if (ed){
    editingId = +ed;
    const row = e.target.closest('tr').children;
    $('#id').value = editingId;
    $('#name').value = row[1].textContent;
    $('#formTitle').textContent = 'Изменить салон #'+editingId;
  }
  if (del){
    if (!confirm('Удалить салон?')) return;
    const res = await api('salon_delete',{id:+del});
    if (res.ok) { load(); show('Удалено'); resetForm(); } else alert(res.error||'Ошибка');
  }
});

function resetForm(){
  editingId = 0; $('#id').value=''; $('#name').value='';
  $('#formTitle').textContent='Новый салон';
}
$('#resetBtn').onclick = resetForm;

function show(t){ const m=$('#msg'); m.style.display='inline-block'; m.textContent=t; setTimeout(()=>m.style.display='none',1500); }

$('#saveBtn').onclick = async ()=>{
  const name = $('#name').value.trim();
  if (!name) { alert('Введите название'); return; }
  let res;
  if (editingId){
    res = await api('salon_update',{id:editingId,name});
  } else {
    res = await api('salon_create',{name});
  }
  if (res.ok){ load(); show('Сохранено'); if(!editingId) resetForm(); }
  else alert(res.error||'Ошибка');
};
</script>
</body>
</html>
