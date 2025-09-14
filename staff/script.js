(function(){
  function qs(sel, root){ return (root||document).querySelector(sel); }
  function qsa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }

  var $modal = qs('#quizModal');
  var $back = qs('#modalBackdrop');
  var $list = qs('#quizList');

  function openModal(){ $modal.classList.remove('hidden'); $back.classList.remove('hidden'); }
  function closeModal(){ $modal.classList.add('hidden'); $back.classList.add('hidden'); $list.innerHTML = ''; }

  function kvRow(k, v){
    var d=document.createElement('div');
    var dk=document.createElement('div'); dk.className='k'; dk.textContent=k;
    var dv=document.createElement('div'); dv.className='v'; dv.textContent=v;
    d.appendChild(dk); d.appendChild(dv); return d;
  }

  function renderQuiz(metaJson){
    // ожидаем {"quiz": {...}} либо любой объект
    var obj = {};
    try {
      obj = JSON.parse(metaJson||'{}') || {};
    } catch(_) { obj = {}; }

    // поддержка форматов:
    // 1) { quiz: {shape:"oval", color:"red"} }
    // 2) { shape:"oval", color:"red" }
    var data = obj.quiz && typeof obj.quiz === 'object' ? obj.quiz : obj;

    if (!data || Object.keys(data).length === 0){
      $list.appendChild(kvRow('Пусто', '—'));
      return;
    }
    Object.keys(data).forEach(function(k){
      var val = data[k];
      if (Array.isArray(val)) val = val.join(', ');
      else if (val && typeof val === 'object') val = JSON.stringify(val);
      $list.appendChild(kvRow(k[0].toUpperCase()+k.slice(1), String(val)));
    });
  }

  qsa('a.js-quiz').forEach(function(a){
    a.addEventListener('click', function(e){
      e.preventDefault();
      var raw = a.getAttribute('data-meta') || '{}';
      renderQuiz(raw);
      openModal();
    });
  });

  qs('#quizClose').addEventListener('click', closeModal);
  qs('#quizOk').addEventListener('click', closeModal);
  $back.addEventListener('click', closeModal);
})();
