<style>
:root{
  /* ── DishNet Africa Brand ── */
  --primary:#D41C1C;--primary-dk:#A81515;--primary-lt:#FFF0F0;
  --swoosh:linear-gradient(110deg,#D41C1C 0%,#E8521A 60%,#FF7A35 100%);
  --nav-bg:#141414;--nav-hover:#2A2A2A;--nav-active:#D41C1C;
  --nav-txt:#888888;--nav-txt-active:#FFFFFF;--nav-section:#444444;
  --surface:#F8FAFC;--card:#FFFFFF;--border:#E2E8F0;
  --text:#0F172A;--text-2:#64748B;--text-3:#94A3B8;
  --green:#16A34A;--red:#DC2626;--orange:#D97706;--purple:#7C3AED;
  --shadow-sm:0 1px 3px rgba(0,0,0,.06);
  --shadow-md:0 4px 16px rgba(0,0,0,.08);
  --radius:12px;
}
*{box-sizing:border-box;}
body{font-family:'Inter',-apple-system,'Segoe UI',sans-serif;font-size:14px;background:var(--surface);color:var(--text);margin:0;}
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

/* ══ TOP HEADER ══ */
.kyc-header{
  background:var(--nav-bg);border-bottom:1px solid #2A2A2A;
  padding:0 12px;display:flex;align-items:center;justify-content:space-between;
  box-shadow:0 1px 0 rgba(255,255,255,.04);position:sticky;top:0;z-index:150;height:52px;
  min-width:0;overflow:hidden;
}
.kyc-header h1{font-size:15px;font-weight:700;margin:0;color:#F8FAFC;letter-spacing:-.2px;}
.kyc-header .wallet-badge{background:rgba(212,28,28,.15);color:#FF8080;border-radius:8px;padding:4px 10px;font-size:11px;font-weight:600;display:flex;align-items:center;gap:4px;border:1px solid rgba(212,28,28,.25);white-space:nowrap;}
.kyc-header .user-badge{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:4px 8px;font-size:11px;font-weight:600;display:flex;align-items:center;gap:5px;color:#CBD5E1;min-width:0;}
.kyc-header .uname{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:80px;}
@media(max-width:420px){
  .kyc-header .uname{display:none;}
  .kyc-header .wallet-badge span.wlabel{display:none;}
  .kyc-header{gap:6px;}
  .kyc-header>div:first-child{gap:6px;}
  .kyc-header>div:last-child{gap:5px;}
}

/* ══ SIDEBAR ══ */
.kyc-tabs{
  background:var(--nav-bg);width:240px;display:flex;flex-direction:column;
  padding:8px 0 60px;overflow-y:auto;overflow-x:hidden;
  position:sticky;top:56px;height:calc(100vh - 56px);flex-shrink:0;
  -webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:#2A2A2A transparent;
}
.kyc-tabs::-webkit-scrollbar{width:4px;}
.kyc-tabs::-webkit-scrollbar-track{background:transparent;}
.kyc-tabs::-webkit-scrollbar-thumb{background:#2A2A2A;border-radius:4px;}

/* Nav section headers */
.nav-section{
  font-size:10px;font-weight:700;color:var(--nav-section);
  text-transform:uppercase;letter-spacing:1.2px;
  padding:16px 16px 6px;display:flex;align-items:center;gap:6px;
}
.nav-section::after{content:'';flex:1;height:1px;background:#2A2A2A;margin-left:4px;}

/* Sub-section dividers inside a section (e.g. Accounts groups) */
.nav-sub{
  font-size:9px;font-weight:700;color:#64748b;
  text-transform:uppercase;letter-spacing:1px;
  padding:10px 16px 3px;
  border-left:3px solid #64748b;margin-left:12px;
}

/* Nav items */
.kyc-tab{
  padding:0 10px;margin:1px 8px;border-radius:8px;
  font-size:13px;font-weight:500;color:var(--nav-txt);
  text-decoration:none;border:none;display:flex;align-items:center;gap:10px;
  transition:all .15s;white-space:nowrap;height:38px;cursor:pointer;
  position:relative;
}
.kyc-tab:hover{color:#CBD5E1;background:var(--nav-hover);text-decoration:none;}
.kyc-tab.active{
  color:var(--nav-txt-active);background:var(--nav-active);font-weight:600;
  box-shadow:0 1px 8px rgba(212,28,28,.35);
}
.kyc-tab.active::before{display:none;}
.nav-icon{width:18px;height:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px;opacity:.85;}
.kyc-tab.active .nav-icon{opacity:1;}
.nav-badge{background:#DC2626;color:#fff;border-radius:20px;padding:1px 7px;font-size:10px;font-weight:700;margin-left:auto;}
.nav-badge.orange{background:#D97706;}
.nav-badge.blue{background:#D41C1C;}

/* Divider — replaced by section headers */
.kyc-tab-divider{display:none;}

/* ══ MAIN CONTENT ══ */
.kyc-layout{display:flex;}
.kyc-main{flex:1;min-height:calc(100vh - 56px);overflow:auto;padding:20px;}
@media(max-width:900px){.kyc-main{padding:14px;}}
/* ═══ MOBILE RESPONSIVE — Clean Professional Design ═══ */
.mobile-wallet-hero{display:none;}
.mobile-nav{display:none;}

@media(max-width:768px){

/* ─── LAYOUT RESET ─── */
.kyc-tabs{display:none!important;}
.kyc-main{padding-bottom:max(80px,calc(72px + env(safe-area-inset-bottom)))!important;}
html{scroll-behavior:smooth;-webkit-overflow-scrolling:touch;}
body{-webkit-text-size-adjust:100%;background:#f0f2f5;}

/* ─── HEADER: Ultra-compact ─── */
.kyc-header{padding:6px 12px;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.kyc-header h1{font-size:15px;letter-spacing:-.3px;}
.kyc-header .sub{display:none;}
.kyc-header .wallet-badge{font-size:11px;padding:4px 10px;gap:4px;}
.kyc-header .user-badge .uname{display:none;}
.kyc-header .user-badge{padding:4px 8px;gap:3px;font-size:11px;}

/* ─── CONTENT AREA ─── */
#kyc-content{padding:12px!important;}

/* ─── MOBILE WALLET HERO ─── */
.mobile-wallet-hero{display:block;margin-bottom:14px;}
.mwh-card{background:linear-gradient(135deg,#0f172a,#1e3a5f);border-radius:20px;padding:20px 18px;color:#fff;position:relative;overflow:hidden;}
.mwh-card::before{content:'';position:absolute;top:-50px;right:-50px;width:150px;height:150px;border-radius:50%;background:rgba(33,150,243,.12);}
.mwh-label{font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.4);font-weight:800;}
.mwh-amount{font-size:34px;font-weight:800;margin:4px 0 10px;letter-spacing:-1px;}
.mwh-stats{display:flex;gap:16px;font-size:11px;color:rgba(255,255,255,.5);}
.mwh-stats i{margin-right:3px;color:#60a5fa;}
.mwh-quick{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px;}
.mwh-btn{display:flex;align-items:center;gap:8px;padding:12px 14px;background:rgba(255,255,255,.95);border-radius:12px;text-decoration:none;color:#1E293B;font-size:12px;font-weight:700;box-shadow:0 2px 8px rgba(0,0,0,.06);transition:.15s;-webkit-tap-highlight-color:transparent;}
.mwh-btn:hover{text-decoration:none;color:#1E293B;}
.mwh-btn i{font-size:18px;color:#D41C1C;}

/* ─── CARDS ─── */
.kyc-card{border-radius:16px;box-shadow:0 1px 6px rgba(0,0,0,.05);border:none;margin-bottom:12px;background:#fff;}
.kyc-card-header{padding:12px 16px;font-size:13px;font-weight:700;border-radius:16px 16px 0 0;}
.kyc-card-body{padding:14px 16px;}

/* ─── STAT GRID ─── */
.stat-grid{grid-template-columns:1fr 1fr!important;gap:8px!important;margin-bottom:12px!important;}
.stat-card{padding:14px!important;border-radius:14px!important;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.stat-label{font-size:10px!important;text-transform:uppercase;letter-spacing:.5px;font-weight:700;color:#94a3b8;}
.stat-value{font-size:20px!important;font-weight:800;letter-spacing:-.3px;margin-top:2px;}

/* ─── FORM INPUTS ─── */
.form-control,.form-select,select.form-control,.wiz-field input,.wiz-field select,.wiz-field textarea{
    height:48px;font-size:16px!important;border-radius:12px;padding:12px 14px;border:1.5px solid #e2e8f0;background:#f8fafc;width:100%;box-sizing:border-box;-webkit-appearance:none;
}
.wiz-field textarea{height:auto;min-height:70px;}
.form-control:focus,.wiz-field input:focus,.wiz-field select:focus{border-color:#D41C1C;background:#fff;box-shadow:0 0 0 3px rgba(212,28,28,.08);}
.form-label,.wiz-field label{font-size:12px;margin-bottom:6px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.3px;display:block;}

/* ─── RADIO CARDS ─── */
.wiz-radio-group{gap:8px;}
.wiz-radio-card{padding:12px 14px;border-radius:14px;border-width:1.5px;}
.wiz-radio-card.selected{border-color:#D41C1C;background:#fff5f5;box-shadow:0 2px 8px rgba(212,28,28,.12);}

/* ─── WIZARD ─── */
.wiz-container{max-width:100%!important;margin:0;}
.wiz-progress{margin-bottom:18px;padding:0 4px;gap:0;}
.wiz-step-dot{width:28px;height:28px;font-size:11px;}
.wiz-step-label{font-size:8px;}
.wiz-step-line{height:2px;}
.wiz-nav{gap:10px;margin-top:20px;}
.wiz-btn{padding:14px;font-size:15px;border-radius:14px;-webkit-tap-highlight-color:transparent;}
.wiz-row{grid-template-columns:1fr!important;gap:0;}
.wiz-section-title{font-size:14px;margin-bottom:12px;}

/* ─── SIM CARD ─── */
.sim-card-visual{border-radius:16px;padding:16px;}

/* ─── GRID STACKING ─── */
.cp-form-grid,.cp-form-grid2,.resp-grid-5,.resp-grid-5b,.resp-grid-6,.resp-grid-2{grid-template-columns:1fr!important;}
.row>[class*="col"]{flex:0 0 100%;max-width:100%;padding:0;margin-bottom:8px;}
.row.mt-3>[class*="col"]{flex:0 0 100%;max-width:100%;margin-bottom:10px;}
.row{margin:0;}

/* ─── TABLES → CARD LIST ─── */
.kyc-table{display:block;border:none;}
.kyc-table thead{display:none;}
.kyc-table tbody{display:block;}
.kyc-table tbody tr{display:block;background:#fff;border-radius:12px;padding:12px 14px;margin-bottom:8px;box-shadow:0 1px 3px rgba(0,0,0,.04);border:1px solid #f1f5f9;}
.kyc-table tbody td{display:flex;justify-content:space-between;align-items:center;padding:3px 0;border:none;font-size:13px;}
.kyc-table tbody td::before{content:attr(data-label);font-weight:700;color:#94a3b8;font-size:10px;text-transform:uppercase;letter-spacing:.3px;flex-shrink:0;min-width:80px;}
.kyc-table tbody td[style*="text-align:center"]{justify-content:space-between!important;text-align:right!important;}

/* ─── BUTTONS ─── */
.btn-kyc-submit{width:100%;padding:14px;font-size:15px;border-radius:14px;-webkit-tap-highlight-color:transparent;}
button,a,.btn,.mwh-btn,select,input[type="submit"]{-webkit-tap-highlight-color:transparent;touch-action:manipulation;}

/* ─── BOTTOM NAV ─── */
/* ── C-01: iOS auto-zoom prevention — any input < 16px triggers viewport zoom ── */
input,select,textarea{font-size:max(16px,1rem)!important;}
/* Restore visual size for tiny UI elements that must look small */
.cb3-fi,input[type=month],input[type=date]{font-size:16px!important;}

/* ── Mobile nav — M-02, U-03, U-05 ── */
.mobile-nav{display:flex!important;position:fixed;bottom:0;left:0;right:0;background:#fff;z-index:200;padding:4px 0 max(4px,env(safe-area-inset-bottom));box-shadow:0 -2px 20px rgba(0,0,0,.08);border-top:1px solid #f1f5f9;}
.mobile-nav a{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 4px 2px;text-decoration:none;font-size:10px;font-weight:700;color:#94A3B8;transition:.15s;position:relative;letter-spacing:.2px;border-radius:10px;margin:2px;}
.mobile-nav a.active{color:#D41C1C;background:rgba(212,28,28,.07);}
.mobile-nav a.active::after{content:'';position:absolute;top:0;left:20%;right:20%;height:2.5px;background:#D41C1C;border-radius:0 0 3px 3px;}
.mobile-nav a i{font-size:20px;}
/* Edge tap area fix — safe-area on sides */
.mobile-nav a:first-child{padding-left:max(8px,env(safe-area-inset-left));}
.mobile-nav a:last-child{padding-right:max(8px,env(safe-area-inset-right));}

/* ─── ALERTS ─── */
.kyc-alert{font-size:13px;padding:12px 14px;border-radius:12px;margin-bottom:12px;}

/* ─── LOGIN MOBILE ─── */
.login-wrap{padding:0;}
.login-card{width:100%!important;max-width:100%!important;margin:0!important;padding:36px 24px 32px!important;border-radius:0!important;min-height:100vh;}

/* ─── MODAL ─── */
.modal-panel{width:95vw!important;max-width:95vw!important;margin:8px!important;max-height:88vh;overflow-y:auto;border-radius:16px!important;}

/* ─── PASSBOOK/REVIEW ─── */
.review-table td{padding:6px 10px;font-size:12px;}

/* ─── FAQ ─── */
.faq-q{padding:14px 16px;font-size:14px;}
.faq-a{padding:0 16px 14px;font-size:13px;}

/* ─── PROOF THUMBNAILS ─── */
.proof-thumb{width:48px;height:48px;}

/* ─── BACKUP GRID ─── */
.backup-grid{grid-template-columns:1fr!important;}
}

/* ─── EXTRA SMALL: < 375px (iPhone SE) ─── */
@media(max-width:375px){
.stat-grid{grid-template-columns:1fr!important;}
.lead-stats{grid-template-columns:1fr 1fr!important;}
.mwh-quick{grid-template-columns:1fr!important;}
.mwh-amount{font-size:28px;}
.kyc-header h1{font-size:13px;}
.mobile-nav a{font-size:8px;}
.mobile-nav a i{font-size:18px;}
#kyc-content{padding:8px!important;}
}


/* ══════════════════════════════════════════════════════════════════════
   DESKTOP ENHANCEMENTS
   ══════════════════════════════════════════════════════════════════════ */
@media(min-width:769px){
/* Content area max width */
#kyc-content{max-width:1200px;margin:0 auto;padding:20px 28px;}

/* Cards subtle hover */
.kyc-card{transition:box-shadow .2s;}
.kyc-card:hover{box-shadow:0 4px 20px rgba(0,0,0,.08);}

/* Tables striped */
.kyc-table tbody tr:nth-child(even){background:#f9fafb;}
.kyc-table tbody tr:hover{background:#f1f5f9;}
.kyc-table th{position:sticky;top:0;background:#fff;z-index:1;}

/* Stat cards hover */
.stat-card{transition:transform .15s,box-shadow .15s;}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.08);}

/* Lead cards hover */
.lead-card{transition:box-shadow .2s;}
.lead-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.1);}

/* Better form layout on desktop */
.lead-form-grid{grid-template-columns:1fr 1fr 1fr;}
.cp-form-grid{grid-template-columns:2fr 1fr 1fr;}
.cp-form-grid2{grid-template-columns:1fr 2fr 1fr;}

/* Wider admin tables */
.kyc-table{width:100%;border-collapse:collapse;}
.kyc-table td,.kyc-table th{padding:10px 14px;}

/* Pipeline cards in row on desktop */
.lead-card-actions>*:hover{background:#fff5f5;color:#D41C1C;}

/* Scrollbar styling */
.kyc-tabs::-webkit-scrollbar{width:4px;}
.kyc-tabs::-webkit-scrollbar-track{background:transparent;}
.kyc-tabs::-webkit-scrollbar-thumb{background:#d1d5db;border-radius:4px;}
.kyc-tabs::-webkit-scrollbar-thumb:hover{background:#9ca3af;}

/* Header polish */
.kyc-header{backdrop-filter:none;background:#141414;border-bottom:1px solid #2A2A2A;}
}

@media(min-width:769px){.mobile-nav{display:none!important;}.mobile-wallet-hero{display:none!important;}}
#kyc-content{padding:22px;max-width:1400px;margin:0 auto;}
.kyc-card{background:#fff;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:16px;overflow:hidden;border:1px solid var(--border);}
.kyc-card-header{background:#fff;color:var(--text);padding:14px 18px;font-size:13px;font-weight:700;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;}
.kyc-card-header i{color:var(--primary);font-size:15px;}
.kyc-card-body{padding:18px;}
.kyc-alert{padding:11px 18px;border-left:4px solid;border-radius:6px;margin-bottom:18px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:10px;}
.kyc-alert.success{background:#d4edda;border-left-color:#28a745;color:#155724;}
.kyc-alert.danger{background:#f8d7da;border-left-color:#dc3545;color:#721c24;}
.kyc-alert.warning{background:#fff3cd;border-left-color:#f39c12;color:#856404;}
.kyc-alert.info{background:#d1ecf1;border-left-color:#17a2b8;color:#0c5460;}
.form-label{font-size:12px;font-weight:700;color:#455a64;margin-bottom:3px;display:block;}
.form-hint{font-size:11px;color:var(--primary);margin-top:2px;}
.form-control,.form-select,select.form-control{font-size:13px;border:1.5px solid #dee2e6;border-radius:6px;height:38px;padding:5px 12px;width:100%;transition:.2s;}
.form-control:focus,select.form-control:focus{border-color:#D41C1C;box-shadow:0 0 0 3px rgba(212,28,28,.08);outline:none;}
.radio-opt{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:500;cursor:pointer;min-width:170px;margin-bottom:6px;}
.radio-opt input{accent-color:var(--primary);width:15px;height:15px;}
.section-title{font-size:12px;font-weight:800;color:#343a40;border-bottom:2px solid var(--gray-100);padding-bottom:6px;margin-bottom:12px;}
.file-row{display:flex;align-items:center;gap:10px;}
.file-row input[type=file]{font-size:12px;border:1.5px solid #dee2e6;border-radius:6px;padding:5px 8px;background:#fff;flex:1;}
.file-preview{width:48px;height:48px;border-radius:6px;object-fit:cover;border:2px solid #dee2e6;display:none;}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px;}
.stat-card{background:#fff;border-radius:10px;padding:16px 18px;box-shadow:var(--shadow);border-left:4px solid;}
.stat-card.teal{border-left-color:var(--primary);}
.stat-card.blue{border-left-color:var(--accent);}
.stat-card.orange{border-left-color:#f39c12;}
.stat-card.red{border-left-color:#dc3545;}
.stat-card.purple{border-left-color:#6f42c1;}
.stat-card.green{border-left-color:#28a745;}
.stat-label{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#888;font-weight:700;}
.stat-value{font-size:28px;font-weight:800;color:#D41C1C;line-height:1.1;}
.stat-sub{font-size:11px;color:#aaa;margin-top:2px;}
.kyc-table{font-size:12px;width:100%;}
.kyc-table thead th{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#888;font-weight:700;background:var(--gray-50);padding:8px 12px;border-bottom:2px solid #e9ecef;white-space:nowrap;}
.kyc-table tbody td{padding:8px 12px;border-bottom:1px solid var(--gray-100);vertical-align:middle;}
.kyc-table tbody tr:hover{background:#f0fdfc;}
.badge-new{background:#cce5ff;color:#004085;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-updated{background:#d4edda;color:#155724;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-failed{background:#f8d7da;color:#721c24;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-debit{background:#f8d7da;color:#721c24;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-credit{background:#d4edda;color:#155724;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-pending{background:#fff3cd;color:#856404;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:4px;}
.badge-approved{background:#d4edda;color:#155724;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:4px;}
.badge-rejected{background:#f8d7da;color:#721c24;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:4px;}
.token-box{background:#1e1e2e;color:#a8ff78;font-family:monospace;font-size:12px;padding:12px 16px;border-radius:8px;word-break:break-all;letter-spacing:.5px;}
.btn-kyc-submit{background:#D41C1C;border:none;border-radius:8px;color:#fff;padding:11px 40px;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(212,28,28,.35);transition:.2s;}
.btn-kyc-submit:hover{opacity:.92;transform:translateY(-1px);}
.btn-kyc-submit:disabled{opacity:.6;transform:none;}
/* ── LOGIN PAGE — DishNet Brand ── */
.login-wrap{
  min-height:100vh;display:flex;align-items:stretch;justify-content:center;
  background:#141414;position:relative;overflow:hidden;
}
.login-wrap::before{
  content:'';position:absolute;
  bottom:-120px;left:-120px;
  width:480px;height:480px;
  background:radial-gradient(circle,rgba(212,28,28,.28) 0%,transparent 65%);
  pointer-events:none;
}
.login-wrap::after{
  content:'';position:absolute;
  top:-80px;right:-60px;
  width:320px;height:320px;
  background:radial-gradient(circle,rgba(212,28,28,.12) 0%,transparent 65%);
  pointer-events:none;
}
.login-card{
  width:100%;max-width:420px;
  background:#1C1C1C;
  border-radius:0;
  padding:52px 44px 40px;
  display:flex;flex-direction:column;justify-content:center;
  position:relative;z-index:1;
  border-left:1px solid #2A2A2A;border-right:1px solid #2A2A2A;
}
.login-logo{text-align:center;margin-bottom:28px;}
.login-logo h2{font-size:22px;font-weight:800;color:#212529;margin-top:10px;}
.login-logo p{font-size:13px;color:#6c757d;margin:0;}
.wallet-badge{background:rgba(212,28,28,.12);color:#D41C1C;border:1px solid rgba(212,28,28,.2);border-radius:20px;padding:5px 14px;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:6px;}
#kycSpinner{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;}
.spin-box{background:#fff;border-radius:14px;padding:30px 50px;text-align:center;}
.spin-box .spinner-border{width:3rem;height:3rem;border-width:.3rem;color:var(--primary);}
.spin-msg{margin-top:10px;font-weight:700;color:var(--primary);}
.acc-row{display:flex;gap:8px;align-items:center;margin-bottom:8px;}
.acc-row select{flex:1;}
.acc-row input[type=number]{width:75px;}
.admin-ribbon{background:#dc3545;color:#fff;font-size:10px;font-weight:700;padding:2px 10px;border-radius:20px;margin-left:8px;text-transform:uppercase;}
.recharge-balance-box{background:linear-gradient(135deg,#e3fcef,#d4edda);border:1px solid #b7dfc7;border-radius:10px;padding:20px 24px;margin-bottom:20px;}
.recharge-balance-box .rbb-label{font-size:11px;text-transform:uppercase;font-weight:700;color:#2d6a4f;letter-spacing:.5px;}
.recharge-balance-box .rbb-value{font-size:36px;font-weight:800;color:#1a7a3e;line-height:1.1;}
.proof-thumb{width:56px;height:56px;object-fit:cover;border-radius:6px;border:2px solid #dee2e6;cursor:pointer;transition:.2s;}
.proof-thumb:hover{transform:scale(1.08);border-color:var(--primary);}
.crm-username-field{border:2px solid #f39c12 !important;background:#fffde7 !important;}
.crm-username-field:focus{border-color:var(--primary) !important;background:#fff !important;}
.log-badge{font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;}
.log-badge.recharge_request{background:#e3f2fd;color:#1565c0;}
.log-badge.recharge_approved{background:#d4edda;color:#155724;}
.log-badge.recharge_rejected{background:#f8d7da;color:#721c24;}
.log-badge.crm_username_changed{background:#fff3cd;color:#856404;}
/* Modal overlay */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9998;align-items:center;justify-content:center;}
.modal-panel{background:#fff;border-radius:14px;padding:30px;width:460px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.3);}

/* UISP-style enhancements */
@media(max-width:768px){
.kyc-header{padding:8px 12px;}
.kyc-header h1{font-size:13px;}
.kyc-header .sub{display:none;}
.wallet-badge{font-size:11px;padding:4px 10px;}
.user-badge span:not(.wallet-badge){font-size:11px;}
/* Better table cards for mobile */
.kyc-table-mobile-wrap{border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);}
/* Touch-friendly buttons */
button,a.wiz-btn,.btn-kyc-submit,.mwh-btn{-webkit-tap-highlight-color:transparent;}
/* Smooth scrolling */
html{scroll-behavior:smooth;}
/* Bottom safe area for iOS */

}


/* ── Toast notifications (Starlink Finance pattern) ── */
#toastContainer{position:fixed;bottom:24px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:8px;pointer-events:none;}
.dn-toast{padding:12px 20px;border-radius:12px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px;
  animation:dnToastIn .3s ease;max-width:380px;pointer-events:auto;box-shadow:0 4px 20px rgba(0,0,0,.15);}
.dn-toast.success{background:#f0fdf4;border-left:4px solid #10B981;color:#059669;}
.dn-toast.error{background:#fef2f2;border-left:4px solid #EF4444;color:#DC2626;}
.dn-toast.warning{background:#fffbeb;border-left:4px solid #F59E0B;color:#D97706;}
.dn-toast.info{background:#eff6ff;border-left:4px solid #3B82F6;color:#1D4ED8;}
@keyframes dnToastIn{from{transform:translateX(110%);opacity:0;}to{transform:translateX(0);opacity:1;}}
@keyframes dnToastOut{from{transform:translateX(0);opacity:1;}to{transform:translateX(110%);opacity:0;}}
@keyframes pulse{0%,100%{opacity:1;}50%{opacity:.55;}}
/* ── KPI cards (Starlink Finance pattern) ── */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px;}
.kpi-card{background:#fff;padding:16px;border-radius:12px;border:1px solid #eee;border-left:4px solid;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.kpi-card.green{border-left-color:#27ae60;}.kpi-card.blue{border-left-color:#3498db;}
.kpi-card.orange{border-left-color:#f39c12;}.kpi-card.red{border-left-color:#e74c3c;}
.kpi-card.purple{border-left-color:#9b59b6;}.kpi-card.teal{border-left-color:#1abc9c;}
.kpi-label{color:#7f8c8d;font-size:10px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;font-weight:600;}
.kpi-value{font-size:24px;font-weight:700;color:#2c3e50;line-height:1;}
.kpi-sub{font-size:11px;color:#95a5a6;margin-top:4px;}
/* ── Image Lightbox (PWA-safe — no navigation) ── */
.dn-lb{position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.92);display:none;flex-direction:column;align-items:center;justify-content:center;}
.dn-lb.open{display:flex;}
.dn-lb img{max-width:95vw;max-height:85vh;object-fit:contain;border-radius:4px;}
.dn-lb-close{position:absolute;top:12px;right:16px;width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.15);border:none;color:#fff;font-size:24px;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:2;}
.dn-lb-close:active{background:rgba(255,255,255,.3);}
</style>
<!-- Image Lightbox -->
<div class="dn-lb" id="dnLightbox" onclick="if(event.target===this)dnLbClose()">
    <button class="dn-lb-close" onclick="dnLbClose()">&times;</button>
    <img id="dnLbImg" src="" alt="Photo">
</div>
<script>
function dnLbOpen(src){var lb=document.getElementById('dnLightbox'),img=document.getElementById('dnLbImg');img.src=src;lb.classList.add('open');document.body.style.overflow='hidden';}
function dnLbClose(){document.getElementById('dnLightbox').classList.remove('open');document.body.style.overflow='';document.getElementById('dnLbImg').src='';}
document.addEventListener('keydown',function(e){if(e.key==='Escape')dnLbClose();});
</script>
