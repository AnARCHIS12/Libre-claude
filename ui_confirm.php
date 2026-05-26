<?php
/**
 * Libre Claude - Confirmation UI partagée
 */

function render_confirm_ui($t) {
    $title = htmlspecialchars($t('confirm_action'), ENT_QUOTES, 'UTF-8');
    $cancel = htmlspecialchars($t('cancel'), ENT_QUOTES, 'UTF-8');
    $confirm = htmlspecialchars($t('confirm'), ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<style>
.lc-confirm-backdrop{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(0,0,0,.66);backdrop-filter:blur(10px)}
.lc-confirm-backdrop.open{display:flex}
.lc-confirm-dialog{width:min(420px,100%);background:#111119;border:1px solid #2a2a3c;border-radius:14px;box-shadow:0 28px 90px rgba(0,0,0,.65);padding:20px;color:#e8e6f0}
.lc-confirm-icon{width:40px;height:40px;border-radius:10px;background:rgba(230,18,42,.14);color:#ff3b4f;display:flex;align-items:center;justify-content:center;margin-bottom:14px;font-size:18px}
.lc-confirm-title{font-size:17px;font-weight:750;margin-bottom:8px}
.lc-confirm-message{font-size:14px;line-height:1.55;color:#9a97ad;margin-bottom:18px}
.lc-confirm-actions{display:flex;justify-content:flex-end;gap:10px}
.lc-confirm-btn{min-height:38px;border-radius:8px;padding:0 14px;border:1px solid #2a2a3c;background:rgba(255,255,255,.05);color:#e8e6f0;font:600 13.5px system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;cursor:pointer}
.lc-confirm-btn:hover{border-color:rgba(255,59,79,.45)}
.lc-confirm-btn.danger{border-color:rgba(248,113,113,.36);background:linear-gradient(135deg,#e6122a,#ff3b4f);color:#fff}
</style>
<div class="lc-confirm-backdrop" id="lc-confirm-backdrop" aria-hidden="true">
  <div class="lc-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="lc-confirm-title">
    <div class="lc-confirm-icon">!</div>
    <div class="lc-confirm-title" id="lc-confirm-title">$title</div>
    <div class="lc-confirm-message" id="lc-confirm-message"></div>
    <div class="lc-confirm-actions">
      <button class="lc-confirm-btn" type="button" id="lc-confirm-cancel">$cancel</button>
      <button class="lc-confirm-btn danger" type="button" id="lc-confirm-ok">$confirm</button>
    </div>
  </div>
</div>
<script>
(function(){
  const backdrop = document.getElementById('lc-confirm-backdrop');
  const message = document.getElementById('lc-confirm-message');
  const ok = document.getElementById('lc-confirm-ok');
  const cancel = document.getElementById('lc-confirm-cancel');
  if (!backdrop || !message || !ok || !cancel) return;

  let resolver = null;
  function close(value) {
    backdrop.classList.remove('open');
    backdrop.setAttribute('aria-hidden', 'true');
    if (resolver) resolver(value);
    resolver = null;
  }

  window.lcConfirm = function(text) {
    message.textContent = text || '';
    backdrop.classList.add('open');
    backdrop.setAttribute('aria-hidden', 'false');
    setTimeout(() => ok.focus(), 20);
    return new Promise(resolve => { resolver = resolve; });
  };

  ok.addEventListener('click', () => close(true));
  cancel.addEventListener('click', () => close(false));
  backdrop.addEventListener('click', event => {
    if (event.target === backdrop) close(false);
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && backdrop.classList.contains('open')) close(false);
  });
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!form || !form.matches('form[data-confirm]') || form.dataset.confirmed === '1') return;
    event.preventDefault();
    if (await window.lcConfirm(form.dataset.confirm || 'Confirmer ?')) {
      form.dataset.confirmed = '1';
      form.submit();
    }
  });
})();
</script>
HTML;
}
