<?php
// ══════════════════════════════════════════════════════════════════════
// Photo Manager — DishNet Hybrid
// Browse, search and view all stored photos:
//   KYC customer photos & ID proofs, expense receipts, install photos
// Admin only.
// ══════════════════════════════════════════════════════════════════════
if (!($isAdmin ?? false)) {
    echo '<div style="padding:40px;text-align:center;color:#dc2626;">⛔ Admin only.</div>';
    return;
}

// ── Disk usage summary ──────────────────────────────────────────────
$dirs = [
    'kyc_uploads'              => ['label'=>'KYC Photos',         'icon'=>'🪪', 'color'=>'#7c3aed'],
    'kyc_photos'               => ['label'=>'KYC Re-uploads',     'icon'=>'📷', 'color'=>'#6d28d9'],
    'uploads/expense_receipts' => ['label'=>'Expense Receipts',   'icon'=>'🧾', 'color'=>'#0369a1'],
    'uploads/expenses'         => ['label'=>'Expense Photos',     'icon'=>'📸', 'color'=>'#0891b2'],
    'uploads/install_photos'   => ['label'=>'Install Photos',     'icon'=>'🔧', 'color'=>'#065f46'],
];

$totals = [];
foreach ($dirs as $rel => $meta) {
    $path  = $dataDir . '/' . $rel;
    $count = 0; $bytes = 0;
    if (is_dir($path)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if ($f->isFile() && !str_ends_with($f->getFilename(), '_meta.json')) {
                $count++; $bytes += $f->getSize();
            }
        }
    }
    $totals[$rel] = array_merge($meta, ['count'=>$count, 'mb'=>round($bytes/1048576,1)]);
}
$grandCount = array_sum(array_column($totals, 'count'));
$grandMb    = array_sum(array_column($totals, 'mb'));
?>
<style>
.pm-wrap   { max-width:1400px; }
.pm-stats  { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:20px; }
.pm-stat   { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 12px; text-align:center; position:relative; overflow:hidden; }
.pm-stat::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.pm-toolbar{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:16px; }
.pm-filter { display:flex; gap:6px; flex-wrap:wrap; }
.pm-chip   { padding:6px 14px; border-radius:20px; border:1px solid #e2e8f0; background:#fff; font-size:12px; font-weight:700; cursor:pointer; color:#374151; transition:.15s; }
.pm-chip.on{ background:#1e293b; color:#fff; border-color:#1e293b; }
.pm-grid   { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; }
.pm-card   { background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; cursor:pointer; transition:.15s; }
.pm-card:hover { box-shadow:0 4px 20px rgba(0,0,0,.1); transform:translateY(-2px); }
.pm-thumb  { width:100%; aspect-ratio:4/3; object-fit:cover; background:#f8fafc; display:block; }
.pm-thumb-pdf { width:100%; aspect-ratio:4/3; display:flex; align-items:center; justify-content:center; background:#fef3c7; font-size:40px; }
.pm-info   { padding:8px 10px; }
.pm-badge  { display:inline-block; padding:2px 8px; border-radius:20px; font-size:10px; font-weight:700; margin-bottom:4px; }
.pm-badge.kyc     { background:#ede9fe; color:#6d28d9; }
.pm-badge.expense { background:#e0f2fe; color:#0369a1; }
.pm-badge.install { background:#dcfce7; color:#15803d; }
.pm-name   { font-size:11px; font-weight:700; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pm-meta   { font-size:10px; color:#94a3b8; margin-top:2px; }
.pm-empty  { grid-column:1/-1; text-align:center; padding:60px 20px; color:#94a3b8; }
.pm-modal  { position:fixed; inset:0; background:rgba(0,0,0,.8); z-index:9999; display:flex; align-items:center; justify-content:center; }
.pm-modal-inner { background:#fff; border-radius:16px; max-width:900px; width:calc(100%-32px); max-height:90vh; overflow:hidden; display:flex; flex-direction:column; }
.pm-modal-hd{ padding:16px 20px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
.pm-modal-bd{ overflow:auto; flex:1; display:flex; align-items:center; justify-content:center; background:#f8fafc; padding:20px; }
.pm-modal-ft{ padding:12px 20px; border-top:1px solid #f1f5f9; display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap; }
.pm-btn    { padding:8px 16px; border-radius:10px; border:none; font-size:13px; font-weight:700; cursor:pointer; font-family:inherit; }
.pm-btn.primary { background:#1e293b; color:#fff; }
.pm-btn.ghost   { background:#f8fafc; color:#374151; border:1px solid #e2e8f0; }
.pm-btn.danger  { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
.pm-search { flex:1; min-width:200px; padding:9px 14px; border-radius:10px; border:1.5px solid #e2e8f0; font-size:13px; outline:none; font-family:inherit; }
.pm-search:focus { border-color:#7c3aed; }
.pm-pager  { display:flex; gap:6px; align-items:center; justify-content:center; margin-top:20px; flex-wrap:wrap; }
.pm-page-btn { width:34px; height:34px; border-radius:8px; border:1px solid #e2e8f0; background:#fff; font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; }
.pm-page-btn.on { background:#1e293b; color:#fff; border-color:#1e293b; }
@media(max-width:700px){
    .pm-stats { grid-template-columns:repeat(2,1fr); }
    .pm-grid  { grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); }
}
</style>

<div class="pm-wrap">

<!-- Header -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <div>
        <div style="font-size:20px;font-weight:800;color:#1e293b;">🖼️ Photo Manager</div>
        <div style="font-size:12px;color:#6b7280;margin-top:2px;"><?= $grandCount ?> files · <?= $grandMb ?> MB stored</div>
    </div>
</div>

<!-- Storage tiles -->
<div class="pm-stats">
<?php foreach ($totals as $rel => $t): ?>
    <div class="pm-stat" style="cursor:pointer;" onclick="pmSetFilter('<?=
        str_contains($rel,'kyc') ? 'kyc' : (str_contains($rel,'install') ? 'install' : 'expense') ?>');">
        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:<?= $t['color'] ?>;"></div>
        <div style="font-size:22px;margin-bottom:4px;"><?= $t['icon'] ?></div>
        <div style="font-size:22px;font-weight:900;color:#1e293b;line-height:1;"><?= $t['count'] ?></div>
        <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-top:3px;"><?= $t['label'] ?></div>
        <div style="font-size:10px;color:#94a3b8;"><?= $t['mb'] ?> MB</div>
    </div>
<?php endforeach; ?>
</div>

<!-- Toolbar -->
<div class="pm-toolbar">
    <input type="text" class="pm-search" id="pm-search" placeholder="🔍  Search by CRM ID, agent, ticket…" oninput="pmDebounce()">
    <div class="pm-filter" id="pm-filter">
        <button class="pm-chip on" data-type="all"     onclick="pmSetFilter('all')">All</button>
        <button class="pm-chip"    data-type="kyc"     onclick="pmSetFilter('kyc')">🪪 KYC</button>
        <button class="pm-chip"    data-type="expense" onclick="pmSetFilter('expense')">🧾 Expenses</button>
        <button class="pm-chip"    data-type="install" onclick="pmSetFilter('install')">🔧 Install</button>
    </div>
    <button class="pm-btn ghost" onclick="pmLoad()" style="flex-shrink:0;">↺ Refresh</button>
</div>

<!-- Grid -->
<div id="pm-grid" class="pm-grid">
    <div class="pm-empty">Loading photos…</div>
</div>

<!-- Pager -->
<div class="pm-pager" id="pm-pager" style="display:none;"></div>

<!-- Lightbox modal -->
<div class="pm-modal" id="pm-modal" style="display:none;" onclick="if(event.target===this)pmCloseModal();">
    <div class="pm-modal-inner">
        <div class="pm-modal-hd">
            <div>
                <div style="font-size:14px;font-weight:800;color:#1e293b;" id="pm-modal-title">Photo</div>
                <div style="font-size:11px;color:#94a3b8;" id="pm-modal-meta"></div>
            </div>
            <button class="pm-btn ghost" onclick="pmCloseModal()">✕ Close</button>
        </div>
        <div class="pm-modal-bd" id="pm-modal-bd"></div>
        <div class="pm-modal-ft">
            <button class="pm-btn ghost" id="pm-modal-prev" onclick="pmNav(-1)">← Prev</button>
            <a class="pm-btn primary" id="pm-modal-dl" href="#" target="_blank">⬇ Open Full Size</a>
            <button class="pm-btn ghost" id="pm-modal-next" onclick="pmNav(1)">Next →</button>
        </div>
    </div>
</div>

</div>

<script>
(function(){
const API = '?page=photo_manager';
let state = { type:'all', q:'', page:1, photos:[], total:0, pages:1 };
let debTimer = null;
let modalIdx = 0;

window.pmSetFilter = function(type) {
    state.type = type; state.page = 1;
    document.querySelectorAll('.pm-chip').forEach(b => {
        b.classList.toggle('on', b.dataset.type === type);
    });
    pmLoad();
};

window.pmDebounce = function() {
    clearTimeout(debTimer);
    debTimer = setTimeout(() => { state.q = document.getElementById('pm-search').value; state.page=1; pmLoad(); }, 350);
};

window.pmLoad = async function(pg) {
    if (pg) state.page = pg;
    const grid = document.getElementById('pm-grid');
    grid.innerHTML = '<div class="pm-empty" style="padding:40px;"><div style="font-size:32px;margin-bottom:10px;">⏳</div>Loading…</div>';
    const url = API + '&type=' + state.type + '&q=' + encodeURIComponent(state.q) + '&pg=' + state.page;
    try {
        const r = await fetch(url);
        const d = await r.json();
        state.photos = d.photos || [];
        state.total  = d.total  || 0;
        state.pages  = d.pages  || 1;
        renderGrid();
        renderPager();
    } catch(e) {
        grid.innerHTML = '<div class="pm-empty">⚠️ Failed to load photos. ' + e.message + '</div>';
    }
};

function renderGrid() {
    const grid = document.getElementById('pm-grid');
    if (!state.photos.length) {
        grid.innerHTML = '<div class="pm-empty"><div style="font-size:48px;margin-bottom:12px;">📭</div><div style="font-size:15px;font-weight:700;color:#374151;">No photos found</div><div style="font-size:13px;margin-top:4px;">Try changing the filter or search term</div></div>';
        return;
    }
    grid.innerHTML = state.photos.map((p, i) => {
        const isPdf = p.path.endsWith('.pdf');
        const thumb = isPdf
            ? '<div class="pm-thumb-pdf">📄</div>'
            : '<img class="pm-thumb" src="' + p.url + '" loading="lazy" onerror="this.style.background=\'#f1f5f9\';this.style.minHeight=\'135px\';this.alt=\'No preview\';">';
        return '<div class="pm-card" onclick="pmOpenModal(' + i + ')">' +
            thumb +
            '<div class="pm-info">' +
                '<span class="pm-badge ' + p.category + '">' +
                    (p.category==='kyc' ? '🪪 KYC' : p.category==='expense' ? '🧾 Expense' : '🔧 Install') +
                '</span>' +
                '<div class="pm-name" title="' + p.label + '">' + p.label + '</div>' +
                '<div class="pm-meta">' + p.date + ' · ' + p.size_kb + ' KB</div>' +
            '</div></div>';
    }).join('');
}

function renderPager() {
    const pager = document.getElementById('pm-pager');
    if (state.pages <= 1) { pager.style.display='none'; return; }
    pager.style.display = 'flex';
    let html = '<span style="font-size:12px;color:#6b7280;">Page ' + state.page + ' of ' + state.pages + ' · ' + state.total + ' photos</span>';
    const addBtn = (label, pg, disabled) =>
        '<button class="pm-page-btn' + (pg===state.page?' on':'') + '" ' +
        (disabled?'disabled style="opacity:.4;"':'') +
        ' onclick="pmLoad(' + pg + ')">' + label + '</button>';
    html += addBtn('‹', state.page-1, state.page===1);
    const start = Math.max(1, state.page-2);
    const end   = Math.min(state.pages, state.page+2);
    for (let p=start; p<=end; p++) html += addBtn(p, p, false);
    html += addBtn('›', state.page+1, state.page===state.pages);
    pager.innerHTML = html;
}

window.pmOpenModal = function(idx) {
    modalIdx = idx;
    renderModal();
    document.getElementById('pm-modal').style.display = 'flex';
    document.addEventListener('keydown', pmKeyHandler);
};

window.pmCloseModal = function() {
    document.getElementById('pm-modal').style.display = 'none';
    document.getElementById('pm-modal-bd').innerHTML = '';
    document.removeEventListener('keydown', pmKeyHandler);
};

function pmKeyHandler(e) {
    if (e.key === 'ArrowLeft')  pmNav(-1);
    if (e.key === 'ArrowRight') pmNav(1);
    if (e.key === 'Escape')     pmCloseModal();
}

window.pmNav = function(dir) {
    const next = modalIdx + dir;
    if (next < 0 || next >= state.photos.length) return;
    modalIdx = next;
    renderModal();
};

function renderModal() {
    const p = state.photos[modalIdx];
    if (!p) return;
    document.getElementById('pm-modal-title').textContent = p.label;
    document.getElementById('pm-modal-meta').textContent  = p.date + ' · ' + p.size_kb + ' KB · ' + p.path;
    document.getElementById('pm-modal-dl').href = p.url;

    const bd = document.getElementById('pm-modal-bd');
    if (p.path.endsWith('.pdf')) {
        bd.innerHTML = '<div style="text-align:center;padding:40px;">' +
            '<div style="font-size:64px;margin-bottom:16px;">📄</div>' +
            '<div style="font-size:14px;font-weight:700;color:#374151;margin-bottom:12px;">' + p.label + '</div>' +
            '<a href="' + p.url + '" target="_blank" class="pm-btn primary" style="display:inline-block;text-decoration:none;">⬇ Open PDF</a>' +
        '</div>';
    } else {
        bd.innerHTML = '<img src="' + p.url + '" style="max-width:100%;max-height:70vh;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,.2);">';
        bd.querySelector('img').onerror = function(){ this.parentNode.innerHTML = '<div style="padding:40px;text-align:center;color:#dc2626;font-weight:700;">Image failed to load</div>'; };
    }

    document.getElementById('pm-modal-prev').disabled = modalIdx === 0;
    document.getElementById('pm-modal-next').disabled = modalIdx === state.photos.length - 1;
}

// Auto-load on open
pmLoad();
})();
</script>
