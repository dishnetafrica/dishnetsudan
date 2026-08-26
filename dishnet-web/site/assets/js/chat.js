/*
 * chat.js — the DishNet assistant on the website.
 *
 * A thin client. It holds no key, no prices and no product knowledge: it posts
 * what the visitor typed to the plugin and renders what comes back. Everything
 * that decides what may be said lives on the server, so the website and
 * WhatsApp cannot answer the same question differently.
 *
 * Configuration rides on the script tag rather than being baked in, so the
 * endpoint can move without touching ninety pages:
 *
 *   <script src="assets/js/chat.js" data-endpoint="https://.../public.php?page=web_chat"
 *           data-whatsapp="249900083481" defer></script>
 *
 * If anything at all goes wrong -- endpoint down, chat switched off, budget
 * spent, network gone -- the panel says so plainly and offers WhatsApp. A
 * chat box that fails silently is worse than no chat box.
 */
(function () {
  'use strict';

  var script   = document.currentScript ||
                 document.querySelector('script[src*="chat.js"]');
  if (!script) return;
  var ENDPOINT = script.getAttribute('data-endpoint') || '';
  var WHATSAPP = (script.getAttribute('data-whatsapp') || '').replace(/\D/g, '');
  if (!ENDPOINT) return;

  var RTL     = (document.documentElement.getAttribute('dir') || '').toLowerCase() === 'rtl';
  var SESSION_KEY = 'dishnet_chat_session';
  var sending = false;

  var T = RTL ? {
    title: 'مساعد ديش نت', open: 'تحدث معنا', close: 'إغلاق',
    placeholder: 'اسأل عن ستارلينك في السودان…', send: 'إرسال',
    greeting: 'مرحباً! اسألني عن أطقم ستارلينك والأسعار والباقات الشهرية والتركيب.',
    wa: 'المتابعة عبر واتساب',
    offline: 'المساعد غير متاح الآن. راسلنا على واتساب وسنساعدك هناك.',
    thinking: 'يكتب…'
  } : {
    title: 'DishNet Assistant', open: 'Chat with us', close: 'Close',
    placeholder: 'Ask about Starlink in Sudan…', send: 'Send',
    greeting: 'Hello. Ask me about Starlink kits, prices, monthly plans or installation.',
    wa: 'Continue on WhatsApp',
    offline: 'The assistant is unavailable right now. Message us on WhatsApp and we will help you there.',
    thinking: 'Typing…'
  };

  var css = [
    '.dnchat-launch{position:fixed;bottom:24px;' + (RTL ? 'left' : 'right') + ':92px;z-index:95;',
    ' display:inline-flex;align-items:center;gap:8px;height:52px;padding:0 18px;border:none;',
    ' border-radius:100px;background:#C8102E;color:#fff;font:600 14px/1 system-ui,sans-serif;',
    ' cursor:pointer;box-shadow:0 4px 16px rgba(200,16,46,.35);transition:transform .2s}',
    '.dnchat-launch:hover{transform:translateY(-2px)}',
    '.dnchat-launch[hidden]{display:none}',
    '.dnchat-panel{position:fixed;bottom:24px;' + (RTL ? 'left' : 'right') + ':24px;z-index:96;',
    ' width:min(380px,calc(100vw - 32px));height:min(560px,calc(100vh - 48px));',
    ' background:#fff;border:1px solid #E5E4E0;border-radius:16px;display:flex;flex-direction:column;',
    ' overflow:hidden;box-shadow:0 18px 50px rgba(0,0,0,.18);font:400 15px/1.55 system-ui,sans-serif;color:#1A1A1A}',
    '.dnchat-panel[hidden]{display:none}',
    '.dnchat-head{display:flex;align-items:center;justify-content:space-between;gap:10px;',
    ' padding:14px 16px;background:#C8102E;color:#fff;flex-shrink:0}',
    '.dnchat-head strong{font-size:15px;font-weight:700}',
    '.dnchat-head button{background:transparent;border:none;color:#fff;font-size:22px;line-height:1;',
    ' cursor:pointer;padding:2px 6px;border-radius:6px}',
    '.dnchat-head button:hover{background:rgba(255,255,255,.18)}',
    '.dnchat-log{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;background:#FAFAF8}',
    '.dnchat-msg{max-width:85%;padding:10px 13px;border-radius:14px;white-space:pre-wrap;overflow-wrap:anywhere}',
    '.dnchat-them{background:#fff;border:1px solid #E5E4E0;align-self:flex-start;border-bottom-' + (RTL ? 'right' : 'left') + '-radius:4px}',
    '.dnchat-you{background:#C8102E;color:#fff;align-self:flex-end;border-bottom-' + (RTL ? 'left' : 'right') + '-radius:4px}',
    '.dnchat-wait{align-self:flex-start;color:#6B6862;font-size:13px;padding:4px 2px}',
    '.dnchat-wa{align-self:flex-start;display:inline-block;margin-top:2px;padding:9px 15px;border-radius:100px;',
    ' background:#25D366;color:#fff;font-weight:600;font-size:13.5px;text-decoration:none}',
    '.dnchat-form{display:flex;gap:8px;padding:12px;border-top:1px solid #E5E4E0;background:#fff;flex-shrink:0}',
    '.dnchat-form input{flex:1;min-width:0;padding:11px 13px;border:1px solid #DDDBD6;border-radius:100px;',
    ' font:inherit;font-size:14px;color:inherit;background:#fff}',
    '.dnchat-form input:focus{outline:2px solid #C8102E;outline-offset:1px;border-color:transparent}',
    '.dnchat-form button{flex-shrink:0;padding:0 18px;border:none;border-radius:100px;background:#C8102E;',
    ' color:#fff;font:600 14px/1 system-ui,sans-serif;cursor:pointer}',
    '.dnchat-form button:disabled{opacity:.55;cursor:default}',
    '.dnchat-note{padding:8px 14px;font-size:11.5px;color:#8A857E;background:#fff;text-align:center;flex-shrink:0}',
    '@media (max-width:520px){.dnchat-launch{' + (RTL ? 'left' : 'right') + ':86px;padding:0 14px}',
    ' .dnchat-panel{bottom:0;' + (RTL ? 'left' : 'right') + ':0;width:100vw;height:100dvh;border-radius:0;border:none}}'
  ].join('');

  var style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;      // textContent, never innerHTML:
    return n;                                     // model output is not markup
  }

  var launch = el('button', 'dnchat-launch');
  launch.type = 'button';
  launch.setAttribute('aria-label', T.open);
  launch.appendChild(el('span', null, '💬'));
  launch.appendChild(el('span', null, T.open));

  var panel = el('div', 'dnchat-panel');
  panel.hidden = true;
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', T.title);

  var head = el('div', 'dnchat-head');
  head.appendChild(el('strong', null, T.title));
  var closeBtn = el('button', null, '×');
  closeBtn.type = 'button';
  closeBtn.setAttribute('aria-label', T.close);
  head.appendChild(closeBtn);

  var log = el('div', 'dnchat-log');
  log.setAttribute('role', 'log');
  log.setAttribute('aria-live', 'polite');

  var form = el('form', 'dnchat-form');
  var input = el('input');
  input.type = 'text';
  input.maxLength = 1000;
  input.placeholder = T.placeholder;
  input.setAttribute('aria-label', T.placeholder);
  input.autocomplete = 'off';
  var send = el('button', null, T.send);
  send.type = 'submit';
  form.appendChild(input);
  form.appendChild(send);

  var note = el('div', 'dnchat-note',
    RTL ? 'مساعد آلي — للطلبات يتواصل معك شخص عبر واتساب.'
        : 'Automated assistant. For orders a person picks it up on WhatsApp.');

  panel.appendChild(head);
  panel.appendChild(log);
  panel.appendChild(form);
  panel.appendChild(note);
  document.body.appendChild(launch);
  document.body.appendChild(panel);

  function say(who, text) {
    var m = el('div', 'dnchat-msg ' + (who === 'you' ? 'dnchat-you' : 'dnchat-them'), text);
    log.appendChild(m);
    log.scrollTop = log.scrollHeight;
    return m;
  }

  function offerWhatsApp(url) {
    var href = url || (WHATSAPP ? 'https://wa.me/' + WHATSAPP : '');
    if (!href) return;
    var a = el('a', 'dnchat-wa', T.wa);
    a.href = href;
    a.target = '_blank';
    a.rel = 'noopener';
    log.appendChild(a);
    log.scrollTop = log.scrollHeight;
  }

  function session() {
    try { return sessionStorage.getItem(SESSION_KEY) || ''; } catch (e) { return ''; }
  }
  function remember(id) {
    try { if (id) sessionStorage.setItem(SESSION_KEY, id); } catch (e) { /* private mode */ }
  }

  var opened = false;
  function open() {
    panel.hidden = false;
    launch.hidden = true;
    if (!opened) { opened = true; say('them', T.greeting); }
    input.focus();
  }
  function close() {
    panel.hidden = true;
    launch.hidden = false;
    launch.focus();
  }
  launch.addEventListener('click', open);
  closeBtn.addEventListener('click', close);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !panel.hidden) close();
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text || sending) return;
    input.value = '';
    say('you', text);

    sending = true;
    send.disabled = true;
    var wait = el('div', 'dnchat-wait', T.thinking);
    log.appendChild(wait);
    log.scrollTop = log.scrollHeight;

    var done = function () {
      sending = false;
      send.disabled = false;
      if (wait.parentNode) wait.parentNode.removeChild(wait);
    };

    fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: text, session: session() })
    }).then(function (r) {
      return r.json().catch(function () { return null; });
    }).then(function (data) {
      done();
      if (!data) { say('them', T.offline); offerWhatsApp(); return; }
      remember(data.session);
      say('them', data.reply || T.offline);
      // Anything that is not a normal answer ends with a way to reach a person.
      if (!data.ok || data.escalate) offerWhatsApp(data.handoff);
    }).catch(function () {
      done();
      say('them', T.offline);
      offerWhatsApp();
    });
  });
})();
