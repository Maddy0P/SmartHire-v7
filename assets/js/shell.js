/* SmartHire v8 shell behaviors. Additive: does not modify legacy main.js contracts. */
(function(){
  'use strict';
  var body=document.body;
  if(!body.classList.contains('sh-v8'))return;

  // Mobile drawer / desktop is CSS-collapsed; toggle only opens drawer state
  var sidebar=document.getElementById('sidebar');
  var toggle=document.getElementById('shSidebarToggle');
  var scrim=document.getElementById('shScrim');
  function setDrawer(open){
    if(!sidebar)return;
    sidebar.classList.toggle('open',open);
    body.classList.toggle('sh-drawer-open',open);
    if(toggle)toggle.setAttribute('aria-expanded',String(open));
    if(open){var f=sidebar.querySelector('a,button');if(f)f.focus();}
    else if(toggle){toggle.focus();}
  }
  if(toggle)toggle.addEventListener('click',function(){setDrawer(!sidebar.classList.contains('open'));});
  if(scrim)scrim.addEventListener('click',function(){setDrawer(false);});

  // Generic dropdown menus [data-sh-menu] -> targets id
  function closeMenus(){document.querySelectorAll('.sh-menu.open').forEach(function(m){m.classList.remove('open');var b=document.querySelector('[aria-controls="'+m.id+'"]');if(b)b.setAttribute('aria-expanded','false');});}
  document.querySelectorAll('[data-sh-menu]').forEach(function(btn){
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      var m=document.getElementById(btn.getAttribute('data-sh-menu'));
      var willOpen=m&&!m.classList.contains('open');
      closeMenus();
      if(m&&willOpen){m.classList.add('open');btn.setAttribute('aria-expanded','true');}
    });
  });
  document.addEventListener('click',function(e){if(!e.target.closest('.sh-menu'))closeMenus();});

  // Slide-over open/close via [data-sh-slideover]
  var lastTrigger=null;
  window.shOpenSlideover=function(id,trigger){var p=document.getElementById(id);if(!p)return;lastTrigger=trigger||document.activeElement;p.classList.add('open');p.removeAttribute('aria-hidden');var f=p.querySelector('button,a,[tabindex]');if(f)f.focus();};
  window.shCloseSlideover=function(id){var p=document.getElementById(id);if(!p)return;p.classList.remove('open');p.setAttribute('aria-hidden','true');if(lastTrigger&&lastTrigger.focus)lastTrigger.focus();};

  // Escape: drawer, menus, slide-overs (modals handled by legacy main.js)
  document.addEventListener('keydown',function(e){
    if(e.key!=='Escape')return;
    closeMenus();
    if(sidebar&&sidebar.classList.contains('open'))setDrawer(false);
    document.querySelectorAll('.sh-slideover.open').forEach(function(p){window.shCloseSlideover(p.id);});
  });

  // "/" focuses topbar search (unless typing in a field)
  document.addEventListener('keydown',function(e){
    if(e.key==='/'&&!/input|textarea|select/i.test(document.activeElement.tagName)){
      var s=document.getElementById('globalSearch');
      if(s){e.preventDefault();s.focus();}
    }
  });

  // Bulk selection (candidates table)
  var master=document.getElementById('shCheckAll');
  var bar=document.getElementById('shBulkBar');
  var countEl=document.getElementById('shBulkCount');
  function rows(){return Array.prototype.slice.call(document.querySelectorAll('.sh-row-check'));}
  function refreshBulk(){
    var sel=rows().filter(function(c){return c.checked;});
    if(bar){bar.classList.toggle('open',sel.length>0);}
    if(countEl)countEl.textContent=sel.length;
    if(master){master.indeterminate=sel.length>0&&sel.length<rows().length;master.checked=sel.length>0&&sel.length===rows().length;}
    return sel;
  }
  if(master)master.addEventListener('change',function(){rows().forEach(function(c){c.checked=master.checked;});refreshBulk();});
  document.addEventListener('change',function(e){if(e.target.classList&&e.target.classList.contains('sh-row-check'))refreshBulk();});

  // Bulk export CSV — generic, header-driven from row data-* attributes
  window.shBulkExport=function(headers,filename){
    var sel=refreshBulk();if(!sel.length)return;
    headers=headers||['Name','Email','Phone','Position','Status','AI Score'];
    filename=filename||'export.csv';
    var lines=[headers.join(',')];
    sel.forEach(function(c){
      var tr=c.closest('tr');
      lines.push(headers.map(function(h){var v=tr.getAttribute('data-'+h.toLowerCase().replace(/ /g,''))||'';return '"'+v.replace(/"/g,'""')+'"';}).join(','));
    });
    var blob=new Blob([lines.join('\n')],{type:'text/csv'});
    var a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=filename;a.click();URL.revokeObjectURL(a.href);
  };

  // Generic bulk POST — sequential requests to an EXISTING endpoint (contracts unchanged)
  window.shBulkPost=function(url,makeFields,confirmMsg){
    var sel=refreshBulk();if(!sel.length)return;
    if(confirmMsg&&!confirm(confirmMsg.replace('{n}',sel.length)))return;
    var token=(document.querySelector('meta[name="csrf-token"]')||{}).content||'';
    var chain=Promise.resolve();
    sel.forEach(function(c){
      var fields=makeFields(c.value);
      chain=chain.then(function(){
        var fd=new FormData();
        fd.append('_csrf',token);
        Object.keys(fields).forEach(function(k){fd.append(k,fields[k]);});
        return fetch(url,{method:'POST',body:fd,credentials:'same-origin'});
      });
    });
    chain.then(function(){location.reload();});
  };

  // Candidates bulk delete — thin wrapper over shBulkPost (back-compat API)
  window.shBulkDelete=function(){
    window.shBulkPost('candidates.php',
      function(id){return {form_action:'delete',candidate_id:id};},
      'Delete {n} selected candidate(s)? This cannot be undone.');
  };

  // Skeleton removal marker for future async surfaces
  document.querySelectorAll('[data-sh-skeleton]').forEach(function(el){el.classList.remove('sh-skeleton');});
})();

/* v8 pipeline board — posts to the EXISTING applications.php?ajax endpoint.
   Accessible path: per-card "Move to…" select. Enhancement: drag & drop. */
(function(){
  'use strict';
  var board=document.querySelector('.sh-v8 .sh-pipeline');
  if(!board)return;
  var status=document.getElementById('boardStatus');
  function say(msg){if(status)status.textContent=msg;}
  function csrf(){var m=document.querySelector('meta[name="csrf-token"]');return m?m.content:'';}
  function moveApp(appId,toStage,card){
    var fd=new FormData();
    fd.append('app_id',appId);fd.append('to_stage',toStage);fd.append('_csrf',csrf());
    say('Moving…');
    return fetch('applications.php?ajax=1',{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(res){
        if(res.ok){
          var col=board.querySelector('.pipe-col[data-stage="'+toStage+'"] .pipe-body');
          if(col&&card){col.appendChild(card);card.setAttribute('data-stage',toStage);
            var sel=card.querySelector('.pipe-move');if(sel)sel.value='';}
          recount();say(res.message||'Moved.');
        }else{say(res.message||'Move not allowed.');}
        return res.ok;
      })
      .catch(function(){say('Network error — not moved.');return false;});
  }
  function recount(){
    board.querySelectorAll('.pipe-col').forEach(function(col){
      var n=col.querySelectorAll('.pipe-card').length;
      var cnt=col.querySelector('.cnt');if(cnt)cnt.textContent=n;
      var empty=col.querySelector('.pipe-empty');if(empty)empty.style.display=n?'none':'';
    });
  }
  // Accessible select path
  board.addEventListener('change',function(e){
    var sel=e.target.closest('.pipe-move');if(!sel||!sel.value)return;
    moveApp(sel.getAttribute('data-app-id'),sel.value,sel.closest('.pipe-card'));
  });
  // Drag & drop enhancement
  var dragCard=null;
  board.addEventListener('dragstart',function(e){
    dragCard=e.target.closest('.pipe-card');
    if(dragCard){dragCard.classList.add('dragging');e.dataTransfer.effectAllowed='move';}
  });
  board.addEventListener('dragend',function(){
    if(dragCard)dragCard.classList.remove('dragging');
    board.querySelectorAll('.pipe-col.dragover').forEach(function(c){c.classList.remove('dragover');});
    dragCard=null;
  });
  board.addEventListener('dragover',function(e){
    var col=e.target.closest('.pipe-col');
    if(col&&dragCard){e.preventDefault();e.dataTransfer.dropEffect='move';
      board.querySelectorAll('.pipe-col.dragover').forEach(function(c){if(c!==col)c.classList.remove('dragover');});
      col.classList.add('dragover');}
  });
  board.addEventListener('drop',function(e){
    var col=e.target.closest('.pipe-col');
    if(!col||!dragCard)return;
    e.preventDefault();col.classList.remove('dragover');
    var to=col.getAttribute('data-stage');
    if(to&&to!==dragCard.getAttribute('data-stage'))moveApp(dragCard.getAttribute('data-app-id'),to,dragCard);
  });
})();

/* v8 accessible tabs — generic, reusable. Markup: [role=tablist]>.sh-tab[aria-controls] + .sh-tabpanel.
   Arrow-key navigation per WAI-ARIA APG; hash-sync (#tab-<id>) for deep links. */
(function(){
  'use strict';
  document.querySelectorAll('.sh-v8 [role="tablist"]').forEach(function(list){
    var tabs=Array.prototype.slice.call(list.querySelectorAll('[role="tab"]'));
    function select(tab,focus){
      tabs.forEach(function(t){
        var on=t===tab;
        t.setAttribute('aria-selected',String(on));
        t.tabIndex=on?0:-1;
        var p=document.getElementById(t.getAttribute('aria-controls'));
        if(p)p.hidden=!on;
      });
      if(focus)tab.focus();
      if(tab.id&&history.replaceState)history.replaceState(null,'','#'+tab.id);
    }
    list.addEventListener('click',function(e){
      var t=e.target.closest('[role="tab"]');if(t)select(t,false);
    });
    list.addEventListener('keydown',function(e){
      var i=tabs.indexOf(document.activeElement);if(i<0)return;
      var n=null;
      if(e.key==='ArrowRight')n=tabs[(i+1)%tabs.length];
      else if(e.key==='ArrowLeft')n=tabs[(i-1+tabs.length)%tabs.length];
      else if(e.key==='Home')n=tabs[0];
      else if(e.key==='End')n=tabs[tabs.length-1];
      if(n){e.preventDefault();select(n,true);}
    });
    if(location.hash){
      var t=list.querySelector(location.hash.replace(/[^-#a-zA-Z0-9_]/g,''));
      if(t&&t.getAttribute('role')==='tab')select(t,false);
    }
  });
})();
