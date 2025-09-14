<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Сотрудники — Админка</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <h1 class="h1">Сотрудники</h1>
  <div class="nav">
    <a class="btn sec" href="index.php">← Назад</a>
  </div>

  <div class="grid">
    <div class="card">
      <h3>Список</h3>
      <table class="table" id="tbl">
        <thead>
          <tr>
            <th>ID</th>
            <th>Имя</th>
            <th>Активен</th>
            <th>TZ</th>
            <th>Email</th>
            <th>Телефон</th>
            <th>Салоны</th>
            <th></th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <div class="card">
      <h3 id="formTitle">Новый сотрудник</h3>

      <div class="row"><label>Имя</label><input id="name" class="input"></div>

      <div class="row"><label>Активен</label>
        <select id="is_active" class="input">
          <option value="1">Да</option>
          <option value="0">Нет</option>
        </select>
      </div>

      <div class="row"><label>Часовой пояс</label>
        <select id="tz" class="input">
          <option>Europe/Tallinn</option>
          <option>Europe/Riga</option>
          <option>Europe/Vilnius</option>
          <option>Europe/Helsinki</option>
          <option>Europe/Kiev</option>
        </select>
      </div>

      <div class="row"><label>Email</label>
        <input id="email" class="input" type="email" placeholder="name@example.com">
        <div class="help">Этот email можно использовать для восстановления пароля.</div>
      </div>

      <div class="row"><label>Телефон</label>
        <input id="phone" class="input" placeholder="+372 ...">
      </div>

      <div class="row"><label>Салоны</label>
        <select id="salons" class="input" multiple size="5"></select>
      </div>

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
const api = (a,d)=>fetch('api.php?action='+a,{
  method:'POST',
  headers:{'Content-Type':'application/json'},
  body:JSON.stringify(d||{})
}).then(r=>r.json());

const $ = s=>document.querySelector(s);
let data = {salons:[], staff:[]};
let editingId = 0;

function setSalonsSelect(selected=[]) {
  const sel = $('#salons');
  sel.innerHTML='';
  data.salons.forEach(s=>{
    const o = document.createElement('option');
    o.value = s.id; o.textContent = `${s.id}. ${s.name}`;
    if (selected.includes(s.id)) o.selected = true;
    sel.appendChild(o);
  });
}

async function load(){
  const res = await fetch('api.php?action=staff_list').then(r=>r.json());
  data = res;
  // таблица
  const tb = $('#tbl tbody'); tb.innerHTML='';
  (data.staff||[]).forEach(it=>{
    const salonsText = (it.salons||[]).map(id=>{
      const s = data.salons.find(x=>x.id===id);
      return s ? s.name : id;
    }).join(', ');
    const tr=document.createElement('tr');
    tr.innerHTML = `<td>${it.id}</td>
      <td>${escapeHtml(it.name||'')}</td>
      <td>${it.is_active? 'Да':'Нет'}</td>
      <td>${escapeHtml(it.tz||'')}</td>
      <td>${escapeHtml(it.email||'')}</td>
      <td>${escapeHtml(it.phone||'')}</td>
      <td>${escapeHtml(salonsText)}</td>
      <td style="text-align:right">
        <button class="btn sec" data-ed="${it.id}">Изменить</button>
        <button class="btn" style="background:#dc2626" data-del="${it.id}">Удалить</button>
      </td>`;
    tb.appendChild(tr);
  });
  // селект салонов
  setSalonsSelect();
}
load();

document.addEventListener('click', async (e)=>{
  const ed = e.target.getAttribute('data-ed');
  const del= e.target.getAttribute('data-del');
  if (del){
    if (!confirm('Удалить сотрудника?')) return;
    const res = await api('staff_delete',{id:+del});
    if (res.ok) { load(); show('Удалено'); resetForm(); } else alert(res.error||'Ошибка');
    return;
  }
  if (ed){
    editingId = +ed;
    const item = (data.staff||[]).find(x=>x.id===editingId);
    if (!item) return;
    $('#id').value = editingId;
    $('#name').value = item.name||'';
    $('#is_active').value = String(item.is_active?1:0);
    $('#tz').value = item.tz || 'Europe/Tallinn';
    $('#email').value = item.email || '';
    $('#phone').value = item.phone || '';
    setSalonsSelect(item.salons||[]);
    $('#formTitle').textContent = 'Изменить сотрудника #'+editingId;
  }
});

function getSelectedSalons(){
  return Array.from($('#salons').selectedOptions).map(o=>+o.value);
}

function resetForm(){
  editingId = 0; $('#id').value='';
  $('#name').value=''; $('#is_active').value='1';
  $('#tz').value='Europe/Tallinn';
  $('#email').value=''; $('#phone').value='';
  setSalonsSelect([]);
  $('#formTitle').textContent='Новый сотрудник';
}
$('#resetBtn').onclick = resetForm;

function show(t){ const m=$('#msg'); m.style.display='inline-block'; m.textContent=t; setTimeout(()=>m.style.display='none',1500); }

function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

$('#saveBtn').onclick = async ()=>{
  const name = $('#name').value.trim();
  const is_active = +$('#is_active').value;
  const tz = $('#tz').value.trim() || 'Europe/Tallinn';
  const email = $('#email').value.trim();
  const phone = $('#phone').value.trim();
  const salons = getSelectedSalons();

  if (!name) { alert('Введите имя'); return; }
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { alert('Некорректный email'); return; }

  let res;
  const payload = {name,is_active,tz,email,phone,salons};
  if (editingId){
    payload.id = editingId;
    res = await api('staff_update', payload);
  } else {
    res = await api('staff_create', payload);
  }
  if (res.ok){ load(); show('Сохранено'); if(!editingId) resetForm(); }
  else alert(res.error||'Ошибка');
};
</script>
</body>
</html>
