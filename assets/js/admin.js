(function(){
  'use strict';
  var cfg=window.WCRAdmin;
  if(!cfg) return;
  var dialog=document.getElementById('wcr-history');

  function post(action,fields){
    var data=new FormData();
    data.append('action',action); data.append('nonce',cfg.nonce);
    Object.keys(fields||{}).forEach(function(k){ data.append(k,fields[k]); });
    return fetch(cfg.ajaxUrl,{method:'POST',body:data,credentials:'same-origin'}).then(function(r){ return r.json().catch(function(){ return null; }); });
  }
  function errorOf(res,fallback){ return (res && res.data && res.data.message) || fallback; }
  // Replace a customer's WhatsApp and status cells with the fresh HTML from the server.
  function applyCells(id,data){
    [['waCell','data-wa-cell'],['voucherCell','data-voucher-cell'],['statusCell','data-status-cell']].forEach(function(p){
      if(!data || !data[p[0]]) return;
      var el=document.querySelector('['+p[1]+'="'+id+'"]');
      if(el) el.outerHTML=data[p[0]];
    });
  }
  function setBusy(id,busy){
    var cell=document.querySelector('[data-wa-cell="'+id+'"]');
    if(cell) [].forEach.call(cell.querySelectorAll('button'),function(b){ b.disabled=busy; });
  }
  // Popup blocked (e.g. after the confirmation dialog): offer a normal link instead.
  function showFallback(id,url){
    var cell=document.querySelector('[data-wa-cell="'+id+'"]');
    if(!cell) { window.location.href=url; return; }
    var p=document.createElement('p'); p.className='wcr-fallback';
    var a=document.createElement('a'); a.className='button button-small button-primary'; a.href=url; a.target='_blank'; a.rel='noopener noreferrer'; a.textContent='Open WhatsApp';
    p.appendChild(document.createTextNode('Your browser blocked the new tab. ')); p.appendChild(a);
    cell.appendChild(p);
  }

  // WhatsApp / WhatsApp + Voucher: open the new tab synchronously (inside the click, so popup blockers allow it),
  // then ask the server for the wa.me link. The server records the contact (and starts the voucher's validity).
  function sendWhatsApp(btn){
    if(btn.disabled) return;
    var id=btn.getAttribute('data-id'), win=window.open('','_blank');
    if(win){ try{ win.document.title='Opening WhatsApp…'; win.document.body.innerHTML='<p style="font:16px/1.5 -apple-system,Segoe UI,sans-serif;padding:24px;color:#50575e">Opening WhatsApp…</p>'; }catch(e){} }
    setBusy(id,true);
    post('wcr_contact',{id:id,type:btn.getAttribute('data-type')})
      .then(function(res){
        if(!res || !res.success){
          if(win) win.close();
          setBusy(id,false);
          window.alert(errorOf(res,'Could not create the WhatsApp message. Reload the page and try again.'));
          return;
        }
        applyCells(id,res.data);
        if(win){ win.opener=null; win.location.replace(res.data.url); } else { showFallback(id,res.data.url); }
      })
      .catch(function(){ if(win) win.close(); setBusy(id,false); window.alert('Could not reach the server. Check your connection and try again.'); });
  }

  // Email / Email + Voucher: sent by the server right away, so the admin confirms first (an email cannot be unsent).
  function sendEmail(btn){
    if(btn.disabled) return;
    var to=btn.getAttribute('data-to'), code=btn.getAttribute('data-code'), id=btn.getAttribute('data-id');
    if(!window.confirm(code ? 'Send voucher '+code+' by email to '+to+'?' : 'Send the reminder email to '+to+'?')) return;
    setBusy(id,true);
    post('wcr_email',{id:id,type:btn.getAttribute('data-type')}).then(function(res){
      if(res && res.data) applyCells(id,res.data);
      if(!res || !res.success){ setBusy(id,false); window.alert(errorOf(res,'The email could not be sent.')); }
    }).catch(function(){ setBusy(id,false); window.alert('Could not reach the server. Check your connection and try again.'); });
  }

  function undo(btn){
    if(!window.confirm('Mark the last WhatsApp contact as not sent? It will no longer count as contacted.')) return;
    var id=btn.getAttribute('data-id');
    btn.disabled=true;
    post('wcr_undo',{id:id}).then(function(res){
      if(!res || !res.success){ btn.disabled=false; window.alert(errorOf(res,'Could not undo the contact.')); return; }
      applyCells(id,res.data);
    }).catch(function(){ btn.disabled=false; window.alert('Could not reach the server. Check your connection and try again.'); });
  }

  // History dialog.
  var dbody=dialog ? dialog.querySelector('.wcr-dialog__body') : null, dtitle=dialog ? dialog.querySelector('#wcr-history-title') : null, lastFocus=null;
  function openHistory(btn){
    if(!dialog) return;
    lastFocus=btn;
    dtitle.textContent='Customer history';
    dbody.innerHTML='<div class="wcr-dialog__state"><span class="spinner is-active"></span><p>Loading…</p></div>';
    if(typeof dialog.showModal==='function'){ if(!dialog.open) dialog.showModal(); } else { dialog.setAttribute('open',''); }
    post('wcr_history',{id:btn.getAttribute('data-id')}).then(function(res){
      if(!res || !res.success){ dbody.innerHTML=''; var p=document.createElement('p'); p.className='wcr-dialog__error'; p.textContent=errorOf(res,'Could not load the history.'); dbody.appendChild(p); return; }
      dtitle.textContent=res.data.title;
      dbody.innerHTML=res.data.html;
    }).catch(function(){ dbody.innerHTML='<p class="wcr-dialog__error">Could not reach the server.</p>'; });
  }
  function closeHistory(){
    if(!dialog) return;
    if(typeof dialog.close==='function') dialog.close(); else dialog.removeAttribute('open');
  }
  if(dialog){
    dialog.addEventListener('close',function(){ if(lastFocus) lastFocus.focus(); });
    dialog.addEventListener('click',function(e){ if(e.target===dialog) closeHistory(); }); // backdrop
    dialog.querySelector('.wcr-dialog__close').addEventListener('click',closeHistory);
  }
  function toggleDnc(btn){
    var on=btn.getAttribute('data-on')==='1', id=btn.getAttribute('data-id');
    if(on && !window.confirm('Mark this customer as Do not contact? WhatsApp buttons will be hidden for them.')) return;
    btn.disabled=true;
    post('wcr_dnc',{id:id,on:on?1:0}).then(function(res){
      if(!res || !res.success){ btn.disabled=false; window.alert(errorOf(res,'Could not save.')); return; }
      dbody.innerHTML=res.data.html;
      applyCells(id,res.data);
    }).catch(function(){ btn.disabled=false; window.alert('Could not reach the server. Check your connection and try again.'); });
  }

  // Add Voucher dialog: pre-filled with the voucher settings each time; values can be changed for this customer.
  var vdlg=document.getElementById('wcr-voucher-dialog'), vform=vdlg ? vdlg.querySelector('form') : null, vId=0, vLast=null;
  function vField(name){ return vform.elements[name]; }
  function syncVoucherForm(){
    var fixed=vform.querySelector('input[name="type"][value="fixed"]').checked;
    var unit=vform.querySelector('.wcr-unit'); unit.textContent=fixed ? unit.getAttribute('data-fixed') : unit.getAttribute('data-percent');
    vform.querySelector('.wcr-field--max').hidden=fixed; // a cap only applies to percentages
    var days=parseInt(vField('valid_days').value,10)||0, limit=parseInt(vField('usage_limit').value,10)||0;
    vform.querySelector('.wcr-voucher-warn').hidden=!(days===0 && limit===0);
  }
  function openVoucher(btn){
    if(!vdlg) return;
    var d=cfg.voucher||{};
    vId=btn.getAttribute('data-id'); vLast=btn;
    vform.reset();
    vform.querySelector('input[name="type"][value="'+(d.type==='fixed'?'fixed':'percent')+'"]').checked=true;
    vField('amount').value=d.amount; vField('min_spend').value=d.min_spend; vField('max_discount').value=d.max_discount;
    vField('valid_days').value=d.valid_days; vField('usage_limit').value=d.usage_limit;
    vField('individual').checked=!!+d.individual; vField('restrict_email').checked=!!+d.restrict_email;
    vform.querySelector('.wcr-voucher-for').textContent='For '+btn.getAttribute('data-name')+'. The code is created now; send it with WhatsApp + Voucher.';
    var err=vform.querySelector('.wcr-dialog__error'); err.hidden=true; err.textContent='';
    syncVoucherForm();
    if(typeof vdlg.showModal==='function'){ if(!vdlg.open) vdlg.showModal(); } else { vdlg.setAttribute('open',''); }
    vField('amount').focus();
  }
  function closeVoucher(){ if(typeof vdlg.close==='function') vdlg.close(); else vdlg.removeAttribute('open'); }
  if(vdlg){
    vform.addEventListener('input',syncVoucherForm);
    vform.addEventListener('change',syncVoucherForm);
    vdlg.addEventListener('close',function(){ if(vLast && document.body.contains(vLast)) vLast.focus(); });
    vdlg.addEventListener('click',function(e){ if(e.target===vdlg) closeVoucher(); });
    [].forEach.call(vdlg.querySelectorAll('.wcr-dialog__close,.wcr-dialog__cancel'),function(b){ b.addEventListener('click',closeVoucher); });
    vform.addEventListener('submit',function(e){
      e.preventDefault();
      var submit=vform.querySelector('button[type="submit"]'), err=vform.querySelector('.wcr-dialog__error');
      var fields={id:vId,type:vform.querySelector('input[name="type"]:checked').value};
      ['amount','min_spend','max_discount','valid_days','usage_limit'].forEach(function(n){ fields[n]=vField(n).value; });
      fields.individual=vField('individual').checked?1:0; fields.restrict_email=vField('restrict_email').checked?1:0;
      submit.disabled=true; err.hidden=true;
      post('wcr_voucher_create',fields).then(function(res){
        submit.disabled=false;
        if(!res || !res.success){ err.textContent=errorOf(res,'Could not create the voucher.'); err.hidden=false; return; }
        applyCells(vId,res.data);
        vLast=document.querySelector('[data-id="'+vId+'"].wcr-wa--voucher') || vLast; // focus moves to the new button
        closeVoucher();
      }).catch(function(){ submit.disabled=false; err.textContent='Could not reach the server. Check your connection and try again.'; err.hidden=false; });
    });
  }
  function revokeVoucher(btn){
    if(!window.confirm('Revoke voucher '+btn.getAttribute('data-code')+'? The customer will no longer be able to use it.')) return;
    var id=btn.getAttribute('data-id');
    btn.disabled=true;
    post('wcr_voucher_revoke',{id:id}).then(function(res){
      if(!res || !res.success){ btn.disabled=false; window.alert(errorOf(res,'Could not revoke the voucher.')); return; }
      applyCells(id,res.data);
    }).catch(function(){ btn.disabled=false; window.alert('Could not reach the server. Check your connection and try again.'); });
  }

  document.addEventListener('click',function(e){
    if(!e.target.closest) return;
    var b;
    if((b=e.target.closest('.wcr-wa'))){ e.preventDefault(); sendWhatsApp(b); }
    else if((b=e.target.closest('.wcr-email'))){ e.preventDefault(); sendEmail(b); }
    else if((b=e.target.closest('.wcr-voucher-add'))){ e.preventDefault(); openVoucher(b); }
    else if((b=e.target.closest('.wcr-voucher-revoke'))){ e.preventDefault(); revokeVoucher(b); }
    else if((b=e.target.closest('.wcr-undo'))){ e.preventDefault(); undo(b); }
    else if((b=e.target.closest('.wcr-history'))){ e.preventDefault(); openHistory(b); }
    else if((b=e.target.closest('.wcr-dnc'))){ e.preventDefault(); toggleDnc(b); }
  });
})();
