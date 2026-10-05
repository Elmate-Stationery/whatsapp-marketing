(function(){
  'use strict';
  // Storefront: the offer popup after a WhatsApp voucher link, and voucher messages for the Cart / Checkout blocks
  // (their Store API responses carry them in extensions.wcr.notices). This file is identical for every visitor, so a
  // cached page is safe: the popup is fetched only when the link's flag cookie is present.
  var cfg=window.WCROffer;
  if(!cfg) return;

  function node(tag,cls,text){ var n=document.createElement(tag); if(cls) n.className=cls; if(text!=null) n.textContent=text; return n; }
  function hasFlag(){ return document.cookie.split(';').some(function(c){ return c.trim().indexOf(cfg.cookie+'=')===0; }); }
  function clearFlag(){ document.cookie=cfg.cookie+'=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path='+cfg.cookiePath+'; SameSite=Lax'; }

  function copyCode(code,btn){
    function done(){ btn.textContent='Copied'; setTimeout(function(){ btn.textContent='Copy code'; },2000); }
    if(navigator.clipboard && window.isSecureContext){ navigator.clipboard.writeText(code).then(done,fallback); } else { fallback(); }
    function fallback(){
      var t=node('textarea'); t.value=code; t.setAttribute('readonly',''); t.style.position='fixed'; t.style.opacity='0';
      document.body.appendChild(t); t.select();
      try{ document.execCommand('copy'); done(); }catch(e){}
      t.remove();
    }
  }

  function showOffer(o){
    var last=document.activeElement;
    var overlay=node('div','wcr-offer');
    var box=node('div','wcr-offer__box'); box.setAttribute('role','dialog'); box.setAttribute('aria-modal','true'); box.setAttribute('aria-labelledby','wcr-offer-title'); box.tabIndex=-1;
    var title, button='OK';
    if(o.state!=='offer'){
      title=node('h2','wcr-offer__title','This offer is no longer available');
      box.appendChild(title);
      box.appendChild(node('p','wcr-offer__note','The voucher in your link has expired, has been used, or was withdrawn. You can still shop as usual.'));
    } else {
      title=node('h2','wcr-offer__title',o.name ? 'A gift for you, '+o.name+'!' : 'A gift for you!');
      box.appendChild(title);
      box.appendChild(node('p','wcr-offer__intro','Here is your personal voucher for your next order.'));
      var codeRow=node('div','wcr-offer__code');
      codeRow.appendChild(node('span','wcr-offer__code-text',o.code));
      var copy=node('button','wcr-offer__copy','Copy code'); copy.type='button';
      copy.addEventListener('click',function(){ copyCode(o.code,copy); });
      codeRow.appendChild(copy); box.appendChild(codeRow);
      var rows=node('dl','wcr-offer__rows');
      function row(label,value,cls){ var r=node('div','wcr-offer__row'+(cls?' '+cls:'')); r.appendChild(node('dt',null,label)); r.appendChild(node('dd',null,value)); rows.appendChild(r); }
      if(o.applied){
        row('Cart',o.cart);
        row('Discount','−'+o.discountAmount,'is-discount');
        row('New total',o.total,'is-total');
      } else {
        row('Discount',o.discount,'is-discount');
        if(o.minSpend) row('Minimum spend',o.minSpend);
        if(o.cart) row('Your cart',o.cart);
      }
      if(o.validUntil) row('Valid until',o.validUntil);
      box.appendChild(rows);
      var note;
      if(o.applied) note='The voucher is applied to your cart. Shipping, if any, is added at checkout.';
      else if(o.cart && o.needMore) note='Add '+o.needMore+' more to your cart and the voucher will be applied automatically.';
      else if(o.cart) note='The voucher will be applied automatically at checkout.';
      else { note='It will be applied automatically when you add items to your cart.'; button='Start shopping'; }
      box.appendChild(node('p','wcr-offer__note',note));
    }
    title.id='wcr-offer-title';
    // One button that only closes the popup: the customer stays on this page, the voucher stays in their session.
    var actions=node('div','wcr-offer__actions');
    var ok=node('button','wcr-offer__cta',button); ok.type='button';
    actions.appendChild(ok); box.appendChild(actions);
    overlay.appendChild(box); document.body.appendChild(overlay);
    document.documentElement.classList.add('wcr-offer-open');
    var focusables=[].slice.call(box.querySelectorAll('button'));
    function close(){
      overlay.remove(); document.documentElement.classList.remove('wcr-offer-open');
      document.removeEventListener('keydown',keys,true);
      if(last && last.focus) last.focus();
    }
    function keys(e){
      if(e.key==='Escape'){ e.preventDefault(); close(); return; }
      if(e.key==='Tab'){ // keep focus inside the popup
        var i=focusables.indexOf(document.activeElement);
        e.preventDefault();
        focusables[(i+(e.shiftKey?-1:1)+focusables.length)%focusables.length].focus();
      }
    }
    ok.addEventListener('click',close);
    overlay.addEventListener('click',function(e){ if(e.target===overlay) close(); });
    document.addEventListener('keydown',keys,true);
    ok.focus();
  }

  function fetchOffer(){
    clearFlag(); // shown once, even if the request fails
    var data=new FormData(); data.append('wcr','1');
    fetch(cfg.endpoint,{method:'POST',body:data,credentials:'same-origin',cache:'no-store'})
      .then(function(r){ return r.json(); })
      .then(function(res){ if(res && res.success && res.data) showOffer(res.data); })
      .catch(function(){});
  }

  var toastWrap=null;
  function toast(message){
    if(!toastWrap){ toastWrap=node('div','wcr-toasts'); toastWrap.setAttribute('role','status'); toastWrap.setAttribute('aria-live','polite'); document.body.appendChild(toastWrap); }
    var t=node('div','wcr-toast'); t.appendChild(node('span',null,message));
    var b=node('button','wcr-toast__close'); b.type='button'; b.setAttribute('aria-label','Dismiss'); b.textContent='×';
    b.addEventListener('click',function(){ t.remove(); });
    t.appendChild(b); toastWrap.appendChild(t);
    setTimeout(function(){ t.remove(); },12000);
  }
  function watchBlockNotices(){
    var data=window.wp && window.wp.data;
    if(!data || typeof data.subscribe!=='function') return;
    var lastCart=null;
    data.subscribe(function(){
      var cart;
      try { cart=data.select('wc/store/cart').getCartData(); } catch(e){ return; }
      if(!cart || cart===lastCart) return;
      lastCart=cart;
      var list=cart.extensions && cart.extensions.wcr && cart.extensions.wcr.notices;
      if(list && list.length) list.forEach(toast);
    });
  }

  function start(){ if(hasFlag()) fetchOffer(); watchBlockNotices(); }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',start); else start();
})();
