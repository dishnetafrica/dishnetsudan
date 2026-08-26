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
  var PRIVACY  = script.getAttribute('data-privacy') || 'privacy.html';
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
    thinking: 'يكتب…',
    teaser: 'هل لديك سؤال عن ستارلينك؟ اسألني.',
    leadIntro: 'حتى يتمكن أحد الزملاء من متابعة طلبك، اترك رقمك أو بريدك الإلكتروني.',
    fName: 'الاسم (اختياري)', fPhone: 'رقم الهاتف', fEmail: 'البريد الإلكتروني',
    fSave: 'إرسال', fSkip: 'تخطي', fNeed: 'الرجاء إدخال رقم هاتف أو بريد إلكتروني.',
    fThanks: 'شكراً. سنتواصل معك.',
    consent: 'بإرسال بياناتك، أنت توافق على استخدام ديش نت لها للرد على استفسارك. '
           + 'قد تتم معالجة المحادثات بواسطة مزوّد خدمة الذكاء الاصطناعي لدينا لتوليد الردود.',
    privacy: 'سياسة الخصوصية'
  } : {
    title: 'DishNet Assistant', open: 'Chat with us', close: 'Close',
    placeholder: 'Ask about Starlink in Sudan…', send: 'Send',
    greeting: 'Hello. Ask me about Starlink kits, prices, monthly plans or installation.',
    wa: 'Continue on WhatsApp',
    offline: 'The assistant is unavailable right now. Message us on WhatsApp and we will help you there.',
    thinking: 'Typing…',
    teaser: 'Question about Starlink? Ask me.',
    leadIntro: 'So a colleague can follow up, leave a phone number or an email.',
    fName: 'Name (optional)', fPhone: 'Phone number', fEmail: 'Email',
    fSave: 'Send', fSkip: 'Skip', fNeed: 'Please give a phone number or an email.',
    fThanks: 'Thank you. We will be in touch.',
    consent: 'By submitting your details you agree that DishNet may use them to respond to '
           + 'your enquiry. Chat conversations may be processed by our AI service provider '
           + 'to generate replies.',
    privacy: 'Privacy Policy'
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
    '.dnchat-teaser{position:fixed;bottom:88px;' + (RTL ? 'left' : 'right') + ':24px;z-index:95;',
    ' max-width:min(280px,calc(100vw - 48px));background:#fff;border:1px solid #E5E4E0;',
    ' border-radius:14px;padding:13px 34px 13px 15px;box-shadow:0 10px 30px rgba(0,0,0,.14);',
    ' font:400 14px/1.45 system-ui,sans-serif;color:#1A1A1A;cursor:pointer}',
    '.dnchat-teaser[hidden]{display:none}',
    '.dnchat-teaser button{position:absolute;top:6px;' + (RTL ? 'left' : 'right') + ':8px;border:none;',
    ' background:transparent;font-size:17px;line-height:1;color:#8A857E;cursor:pointer;padding:2px 5px}',
    '.dnchat-lead{align-self:stretch;background:#fff;border:1px solid #E5E4E0;border-radius:14px;padding:13px}',
    '.dnchat-lead p{margin:0 0 9px;font-size:13.5px;color:#5A5A58}',
    '.dnchat-lead input{width:100%;margin-bottom:7px;padding:9px 11px;border:1px solid #DDDBD6;',
    ' border-radius:8px;font:inherit;font-size:14px}',
    '.dnchat-lead input:focus{outline:2px solid #C8102E;outline-offset:1px;border-color:transparent}',
    '.dnchat-lead .dnchat-err{color:#C8102E;font-size:12.5px;margin:0 0 7px;display:block}',
    '.dnchat-consent{font-size:11.5px;line-height:1.5;color:#6B6862;margin:2px 0 10px}',
    '.dnchat-consent a{color:#C8102E;font-weight:600}',
    '.dnchat-lead-row{display:flex;gap:8px;align-items:center}',
    '.dnchat-lead-row button{flex:1;padding:9px;border:none;border-radius:100px;background:#C8102E;',
    ' color:#fff;font:600 14px/1 system-ui,sans-serif;cursor:pointer}',
    '.dnchat-lead-row .dnchat-skip{flex:0 0 auto;background:transparent;color:#6B6862;',
    ' text-decoration:underline;font-weight:400;padding:9px 10px}',
    '@media (max-width:520px){.dnchat-teaser{bottom:84px}.dnchat-launch{' + (RTL ? 'left' : 'right') + ':86px;padding:0 14px}',
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

  // ── State ───────────────────────────────────────────────────────────────
  var opened    = false;
  var leadMode  = 'after';     // 'before' | 'after' | 'off'
  var leadDone  = false;       // captured, or the visitor chose to skip
  var pendingLead = null;      // rides along with the next message
  var answered  = false;       // the assistant has replied at least once

  var DISMISS_KEY = 'dishnet_chat_teaser_dismissed';

  function stash(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
  function stashed(k)  { try { return sessionStorage.getItem(k); } catch (e) { return null; } }

  function open() {
    hideTeaser();
    panel.hidden = false;
    launch.hidden = true;
    if (!opened) {
      opened = true;
      say('them', T.greeting);
      if (leadMode === 'before' && !leadDone) showLeadForm();
    }
    if (!form.hidden) input.focus();
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

  // ── The nudge ───────────────────────────────────────────────────────────
  // Once per session, after a delay, and never again once dismissed. A box
  // that reopens itself on every page is the reason people hate these.
  var teaser = null;
  function showTeaser(text, delay) {
    if (!text || stashed(DISMISS_KEY) === '1' || stashed(SESSION_KEY)) return;
    setTimeout(function () {
      if (!panel.hidden || stashed(DISMISS_KEY) === '1') return;
      teaser = el('div', 'dnchat-teaser');
      teaser.setAttribute('role', 'button');
      teaser.tabIndex = 0;
      teaser.appendChild(el('span', null, text));
      var x = el('button', null, '×');
      x.type = 'button';
      x.setAttribute('aria-label', T.close);
      x.addEventListener('click', function (e) { e.stopPropagation(); dismissTeaser(); });
      teaser.appendChild(x);
      teaser.addEventListener('click', open);
      teaser.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
      });
      document.body.appendChild(teaser);
    }, delay * 1000);
  }
  function hideTeaser() { if (teaser) { teaser.hidden = true; } }
  function dismissTeaser() { stash(DISMISS_KEY, '1'); hideTeaser(); }

  // ── Contact details ─────────────────────────────────────────────────────
  // Never a gate on getting an answer unless the operator asks for it, and
  // always skippable: the visitor who will not give a number before asking a
  // question is the one this channel exists to keep.
  function showLeadForm() {
    if (leadDone || document.querySelector('.dnchat-lead')) return;
    var box = el('div', 'dnchat-lead');
    box.appendChild(el('p', null, T.leadIntro));
    var err = el('span', 'dnchat-err');
    err.hidden = true;

    var name  = el('input'); name.type  = 'text';  name.placeholder  = T.fName;
    var phone = el('input'); phone.type = 'tel';   phone.placeholder = T.fPhone;
    var email = el('input'); email.type = 'email'; email.placeholder = T.fEmail;
    [name, phone, email].forEach(function (i) {
      i.autocomplete = i === phone ? 'tel' : (i === email ? 'email' : 'name');
      i.setAttribute('aria-label', i.placeholder);
      box.appendChild(i);
    });
    box.appendChild(err);

    // Consent is stated where the decision is made, not only in a policy page
    // nobody opened. Collecting a phone number silently is the thing to avoid.
    var consent = el('p', 'dnchat-consent');
    consent.appendChild(document.createTextNode(T.consent + ' '));
    var plink = el('a', null, T.privacy);
    plink.href = PRIVACY;
    plink.target = '_blank';
    plink.rel = 'noopener';
    consent.appendChild(plink);
    consent.appendChild(document.createTextNode('.'));
    box.appendChild(consent);

    var row  = el('div', 'dnchat-lead-row');
    var save = el('button', null, T.fSave); save.type = 'button';
    var skip = el('button', 'dnchat-skip', T.fSkip); skip.type = 'button';
    row.appendChild(save);
    row.appendChild(skip);
    box.appendChild(row);

    save.addEventListener('click', function () {
      var lead = { name: name.value.trim(), phone: phone.value.trim(), email: email.value.trim() };
      if (!lead.phone && !lead.email) {
        err.textContent = T.fNeed;
        err.hidden = false;
        phone.focus();
        return;
      }
      leadDone = true;
      box.parentNode.removeChild(box);
      say('them', T.fThanks);
      // Send it now rather than waiting for another message that may never come.
      post('', lead);
      if (form.hidden) { form.hidden = false; input.focus(); }
    });
    skip.addEventListener('click', function () {
      leadDone = true;
      box.parentNode.removeChild(box);
      if (form.hidden) { form.hidden = false; input.focus(); }
    });

    log.appendChild(box);
    log.scrollTop = log.scrollHeight;
    // 'before' means the form stands in place of the input until it is dealt with.
    if (leadMode === 'before') form.hidden = true;
    phone.focus();
  }

  // ── Talking to the server ───────────────────────────────────────────────
  function post(text, lead) {
    var body = { message: text, session: session() };
    if (lead) body.lead = lead;
    else if (pendingLead) { body.lead = pendingLead; pendingLead = null; }

    var wait = null;
    if (text) {
      sending = true;
      send.disabled = true;
      wait = el('div', 'dnchat-wait', T.thinking);
      log.appendChild(wait);
      log.scrollTop = log.scrollHeight;
    }
    var done = function () {
      sending = false;
      send.disabled = false;
      if (wait && wait.parentNode) wait.parentNode.removeChild(wait);
    };

    return fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) {
      // Read as text first. A 200 whose body will not parse is a real failure
      // mode -- a stray PHP warning in front of the JSON does exactly that --
      // and silently swallowing it leaves nothing to debug from.
      return r.text().then(function (raw) {
        try {
          return JSON.parse(raw);
        } catch (e) {
          console.error('[DishNet chat] ' + r.status +
                        ' response was not JSON:', raw.slice(0, 400));
          return null;
        }
      });
    }).then(function (data) {
      done();
      if (!data) { if (text) { say('them', T.offline); offerWhatsApp(); } return; }
      remember(data.session);
      if (data.lead_mode) leadMode = data.lead_mode;
      if (data.have_lead) leadDone = true;
      if (!text) return;                       // a lead-only post says nothing
      say('them', data.reply || T.offline);
      if (!data.ok || data.escalate) offerWhatsApp(data.handoff);
      // Ask once, after the visitor has had something useful back.
      if (data.ok && !answered) {
        answered = true;
        if (leadMode === 'after' && !leadDone) setTimeout(showLeadForm, 400);
      }
    }).catch(function () {
      done();
      if (text) { say('them', T.offline); offerWhatsApp(); }
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text || sending) return;
    input.value = '';
    say('you', text);
    post(text, null);
  });

  // ── Boot ────────────────────────────────────────────────────────────────
  // Ask the server what it wants before showing anything. A launcher that
  // opens onto a switched-off assistant is worse than no launcher.
  launch.hidden = true;
  fetch(ENDPOINT + '&probe=1', { method: 'GET' })
    .then(function (r) { return r.json(); })
    .then(function (cfg) {
      if (!cfg || !cfg.ok || !cfg.enabled) return;      // stay invisible
      if (cfg.lead_mode) leadMode = cfg.lead_mode;
      if (cfg.handoff) WHATSAPP = cfg.handoff.replace(/\D/g, '') || WHATSAPP;
      launch.hidden = false;
      showTeaser(cfg.teaser || T.teaser,
                 typeof cfg.teaser_delay === 'number' ? cfg.teaser_delay : 6);
    })
    .catch(function () {
      // Probe failed. Still offer the launcher: the visitor gets an honest
      // "unavailable, here is WhatsApp" rather than silence.
      launch.hidden = false;
    });
})();
