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

  var maxRow=document.querySelector('.wcr-max-row');
  var types=[].slice.call(document.querySelectorAll('input[name="coupon_type"]'));
  function syncMax(){
    var fixed=types.some(function(r){ return r.checked && r.value==='fixed'; });
    if(maxRow) maxRow.hidden=fixed;
  }
  types.forEach(function(r){ r.addEventListener('change',syncMax); });
  syncMax();
})();
