(function(){
  'use strict';
  // Message templates: placeholder buttons insert at the cursor of their own textarea, and each template has a live
  // preview with sample data. Also: reminder-period presets, and the max-discount row only for percentage vouchers.
  var cfg=window.WCRSettings || {}, sample=cfg.sample || {};
  var areas=[].slice.call(document.querySelectorAll('textarea.wcr-template'));
  function render(area){
    var preview=document.getElementById(area.getAttribute('data-preview'));
    if(!preview) return;
    var text=area.value;
    Object.keys(sample).forEach(function(ph){ text=text.split(ph).join(sample[ph]); });
    preview.textContent=text.trim(); // textContent + CSS pre-wrap: no HTML is ever interpreted
  }
  areas.forEach(function(area){ area.addEventListener('input',function(){ render(area); }); render(area); });

  document.addEventListener('click',function(e){
    if(!e.target.closest) return;
    var chip=e.target.closest('.wcr-chip');
    if(chip){
      e.preventDefault();
      var area=document.getElementById(chip.getAttribute('data-target'));
      if(!area) return;
      var ph=chip.getAttribute('data-insert'), start=area.selectionStart, end=area.selectionEnd;
      area.focus();
      if(typeof area.setRangeText==='function'){ area.setRangeText(ph,start,end,'end'); }
      else { area.value=area.value.slice(0,start)+ph+area.value.slice(end); }
      render(area);
      return;
    }
    var preset=e.target.closest('.wcr-preset');
    if(preset){
      e.preventDefault();
      var input=document.getElementById(preset.getAttribute('data-target'));
      if(input){ input.value=preset.getAttribute('data-value'); input.focus(); }
    }
  });

  // Settings → Email: live preview (rendered by the server from the unsaved form), test sending, logo picker.
  var frame=document.getElementById('wcr-email-frame');
  if(frame && cfg.ajaxUrl){
    var form=frame.closest('form'), subject=document.getElementById('wcr-email-subject'), previewType='voucher', timer=null, seq=0;
    function emailFields(){
      var data=new FormData();
      [].forEach.call(form.querySelectorAll('.wcr-email-field'),function(el){ data.append(el.name,el.value); });
      data.append('preview_type',previewType); data.append('nonce',cfg.nonce);
      data.append('customer_id',document.getElementById('wcr-test-customer-id').value||'0');
      return data;
    }
    function post(action,data){
      data.append('action',action);
      return fetch(cfg.ajaxUrl,{method:'POST',body:data,credentials:'same-origin'}).then(function(r){ return r.json(); });
    }
    function refresh(){
      var data=emailFields(), mine=++seq; data.append('action','wcr_email_preview');
      fetch(cfg.ajaxUrl,{method:'POST',body:data,credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(res){
        if(mine!==seq || !res || !res.success) return;
        frame.srcdoc=res.data.html; subject.textContent=res.data.subject;
      }).catch(function(){});
    }
    function later(){ clearTimeout(timer); timer=setTimeout(refresh,500); }
    form.addEventListener('input',function(e){ if(e.target.classList.contains('wcr-email-field')) later(); });
    form.addEventListener('change',function(e){ if(e.target.classList.contains('wcr-email-field')) later(); });
    [].forEach.call(document.querySelectorAll('[data-preview-type]'),function(b){
      b.addEventListener('click',function(){
        previewType=b.getAttribute('data-preview-type');
        [].forEach.call(document.querySelectorAll('[data-preview-type]'),function(x){ x.classList.toggle('is-active',x===b); });
        refresh();
      });
    });
    // Send a test email: the format as it is now, to the test addresses only.
    var testBtn=document.querySelector('.wcr-email-test-send'), result=document.getElementById('wcr-test-result');
    function testType(){ var r=document.querySelector('input[name="wcr_test_type"]:checked'); return r ? r.value : 'voucher'; }
    testBtn.addEventListener('click',function(){
      var data=emailFields(); data.append('to',document.getElementById('wcr-test-to').value); data.append('test_type',testType());
      testBtn.disabled=true; result.className='wcr-test-result'; result.textContent='Sending…';
      post('wcr_email_test',data).then(function(res){
        result.textContent=(res && res.data && res.data.message) || 'The test email could not be sent.';
        result.className='wcr-test-result '+(res && res.success ? 'is-ok' : 'is-error');
      }).catch(function(){ result.className='wcr-test-result is-error'; result.textContent='Could not reach the server.'; }).then(function(){ testBtn.disabled=false; });
    });
    // Enter in the test inputs must not submit (save) the settings form.
    [].forEach.call(document.querySelectorAll('.wcr-test-input'),function(el){
      el.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); if(el.id==='wcr-test-to') testBtn.click(); } });
    });
    // Customer picker: real name, last-order product and order details in the preview and the test email.
    var cq=document.getElementById('wcr-test-customer-q'), cres=document.getElementById('wcr-test-customer-results');
    var cchosen=document.getElementById('wcr-test-customer-chosen'), cid=document.getElementById('wcr-test-customer-id'), ctimer=null, cseq=0;
    function choose(id,label){
      cid.value=id; cres.hidden=true; cres.innerHTML='';
      cchosen.hidden=!id; cchosen.querySelector('strong').textContent=label||'';
      cq.value=''; refresh();
    }
    cq.addEventListener('input',function(){
      clearTimeout(ctimer);
      var q=cq.value.trim();
      if(q.length<2){ cres.hidden=true; cres.innerHTML=''; return; }
      ctimer=setTimeout(function(){
        var data=new FormData(), mine=++cseq; data.append('nonce',cfg.nonce); data.append('q',q);
        post('wcr_customer_search',data).then(function(res){
          if(mine!==cseq) return;
          cres.innerHTML='';
          var list=(res && res.success && res.data) || [];
          if(!list.length){ var none=document.createElement('li'); none.className='wcr-test-none'; none.textContent='No customer found.'; cres.appendChild(none); }
          list.forEach(function(c){
            var li=document.createElement('li'), b=document.createElement('button');
            b.type='button'; b.textContent=c.label; b.setAttribute('role','option');
            b.addEventListener('click',function(){ choose(c.id,c.label); });
            li.appendChild(b); cres.appendChild(li);
          });
          cres.hidden=false;
        }).catch(function(){});
      },300);
    });
    cchosen.querySelector('.wcr-test-customer-clear').addEventListener('click',function(){ choose(0,''); });
    var logoId=document.getElementById('email_logo_id'), logoImg=document.getElementById('wcr-logo-preview'), media=null;
    document.querySelector('.wcr-logo-pick').addEventListener('click',function(){
      if(!window.wp || !wp.media) return;
      if(!media){
        media=wp.media({title:'Choose the email logo',button:{text:'Use this logo'},library:{type:'image'},multiple:false});
        media.on('select',function(){
          var a=media.state().get('selection').first().toJSON();
          logoId.value=a.id; logoImg.src=(a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url); logoImg.hidden=false; refresh();
        });
      }
      media.open();
    });
    document.querySelector('.wcr-logo-clear').addEventListener('click',function(){ logoId.value='0'; logoImg.hidden=true; refresh(); });
    refresh();
  }

  var maxRow=document.querySelector('.wcr-max-row');
  var types=[].slice.call(document.querySelectorAll('input[name="coupon_type"]'));
  function syncMax(){
    var fixed=types.some(function(r){ return r.checked && r.value==='fixed'; });
    if(maxRow) maxRow.hidden=fixed;
  }
  types.forEach(function(r){ r.addEventListener('change',syncMax); });
  syncMax();
})();
