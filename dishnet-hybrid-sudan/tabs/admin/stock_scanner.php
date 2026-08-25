<?php
/**
 * Stock Scanner — Barcode/QR scan for stock entry
 * DishNet Hybrid v4.10.1
 *
 * Uses html5-qrcode library (camera-based barcode/QR scanning).
 * Supports: Code128, Code39, EAN, QR, DataMatrix — covers KIT labels, ONU stickers, SIM cards.
 *
 * Flow: Select category → Scan → Review → Submit batch
 */
?>
<style>
.scn-wrap{max-width:700px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}
.scn-header{text-align:center;margin-bottom:20px;}
.scn-header h2{margin:0;font-size:22px;font-weight:800;color:var(--text-1,#1E293B);}
.scn-header p{color:#64748B;font-size:13px;margin-top:4px;}

/* Step indicators */
.scn-steps{display:flex;justify-content:center;gap:6px;margin-bottom:20px;}
.scn-step{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;background:#E2E8F0;color:#94A3B8;transition:all .2s;}
.scn-step.active{background:var(--primary,#2563EB);color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.3);}
.scn-step.done{background:#059669;color:#fff;}

/* Panels */
.scn-panel{background:#fff;border:1px solid #E2E8F0;border-radius:16px;padding:24px;margin-bottom:16px;}
.scn-panel h3{margin:0 0 16px;font-size:16px;font-weight:700;color:#1E293B;}
.scn-panel.hidden{display:none;}

/* Category selector */
.scn-cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;}
.scn-cat-card{border:2px solid #E2E8F0;border-radius:12px;padding:14px;text-align:center;cursor:pointer;transition:all .15s;}
.scn-cat-card:hover{border-color:#93C5FD;background:#F0F7FF;}
.scn-cat-card.selected{border-color:var(--primary,#2563EB);background:#EFF6FF;box-shadow:0 0 0 3px rgba(37,99,235,.15);}
.scn-cat-card .icon{font-size:28px;margin-bottom:6px;}
.scn-cat-card .name{font-size:12px;font-weight:700;color:#1E293B;line-height:1.3;}
.scn-cat-card .sub{font-size:10px;color:#94A3B8;margin-top:2px;}

/* Scanner area */
#scnReader{width:100%;max-width:500px;margin:0 auto;border-radius:12px;overflow:hidden;}
#scnReader video{border-radius:12px;}
.scn-scan-status{text-align:center;margin-top:12px;font-size:14px;font-weight:600;color:#64748B;}
.scn-scan-status.success{color:#059669;}
.scn-scan-status.error{color:#DC2626;}
.scn-last-scan{background:#ECFDF5;border:1px solid #A7F3D0;border-radius:10px;padding:10px 16px;margin-top:12px;text-align:center;font-size:15px;font-weight:700;color:#059669;display:none;}

/* Scanned items list */
.scn-items{margin-top:16px;}
.scn-item{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;margin-bottom:6px;}
.scn-item .serial{font-size:14px;font-weight:700;color:#1E293B;font-family:'Courier New',monospace;}
.scn-item .idx{width:24px;height:24px;border-radius:50%;background:var(--primary,#2563EB);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;margin-right:10px;flex-shrink:0;}
.scn-item .remove{background:none;border:none;color:#DC2626;cursor:pointer;font-size:18px;padding:4px 8px;}
.scn-item .remove:hover{background:#FEF2F2;border-radius:6px;}

/* Count badge */
.scn-count{display:inline-flex;align-items:center;justify-content:center;background:var(--primary,#2563EB);color:#fff;border-radius:20px;padding:2px 12px;font-size:13px;font-weight:800;margin-left:8px;}

/* Buttons */
.scn-btn{padding:12px 24px;border-radius:12px;font-size:15px;font-weight:700;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:all .15s;}
.scn-btn.primary{background:var(--primary,#2563EB);color:#fff;}
.scn-btn.primary:hover{background:#1D4ED8;}
.scn-btn.success{background:#059669;color:#fff;}
.scn-btn.success:hover{background:#047857;}
.scn-btn.secondary{background:#F1F5F9;color:#475569;border:1px solid #D1D5DB;}
.scn-btn.secondary:hover{background:#E2E8F0;}
.scn-btn.danger{background:#FEF2F2;color:#DC2626;border:1px solid #FECACA;}
.scn-btn:disabled{opacity:.5;cursor:not-allowed;}
.scn-btn-row{display:flex;gap:10px;justify-content:center;margin-top:20px;flex-wrap:wrap;}

/* Manual entry */
.scn-manual{display:flex;gap:8px;margin-top:12px;}
.scn-manual input{flex:1;padding:10px 14px;border:1px solid #D1D5DB;border-radius:10px;font-size:14px;font-family:'Courier New',monospace;}
.scn-manual input:focus{border-color:var(--primary,#2563EB);outline:none;box-shadow:0 0 0 3px rgba(37,99,235,.1);}

/* Settings row */
.scn-settings{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;align-items:center;justify-content:center;}
.scn-settings label{font-size:12px;font-weight:600;color:#64748B;display:flex;align-items:center;gap:4px;}
.scn-settings select,.scn-settings input[type=number]{padding:6px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;}

/* Result */
.scn-result{text-align:center;padding:40px 20px;}
.scn-result .big-icon{font-size:64px;margin-bottom:16px;}
.scn-result h3{font-size:20px;font-weight:800;color:#059669;margin:0 0 8px;}
.scn-result p{color:#64748B;font-size:14px;}

@media(max-width:480px){
    .scn-cat-grid{grid-template-columns:repeat(2,1fr);}
    .scn-btn{padding:10px 18px;font-size:13px;}
}
</style>

<div class="scn-wrap">
    <div class="scn-header">
        <h2>📷 Stock Scanner</h2>
        <p>Scan barcodes or QR codes to add stock — like a supermarket!</p>
    </div>

    <div class="scn-steps">
        <div class="scn-step active" id="step1">1</div>
        <div class="scn-step" id="step2">2</div>
        <div class="scn-step" id="step3">3</div>
    </div>

    <!-- ═══ STEP 1: Select Category ═══ -->
    <div class="scn-panel" id="panelStep1">
        <h3>Step 1: Select Category</h3>
        <div class="scn-cat-grid" id="scnCatGrid">
            <div style="text-align:center;color:#94A3B8;grid-column:1/-1;padding:20px;">Loading categories...</div>
        </div>
        <div class="scn-btn-row">
            <button class="scn-btn primary" id="btnToStep2" disabled onclick="scnGoStep2()">Next → Start Scanning</button>
        </div>
    </div>

    <!-- ═══ STEP 2: Scan Items ═══ -->
    <div class="scn-panel hidden" id="panelStep2">
        <h3>Step 2: Scan Items <span class="scn-count" id="scnItemCount">0</span></h3>

        <div class="scn-settings">
            <label>Location: <select id="scnLocation"><option value="DishNet UNMISS">DishNet UNMISS</option><option value="DishNet Kololo Office">DishNet Kololo Office</option></select></label>
            <label>Condition: <select id="scnCondition"><option value="new">New</option><option value="good">Good</option><option value="fair">Fair</option></select></label>
            <label>Cost $: <input type="number" id="scnCost" step="0.01" style="width:80px;"></label>
        </div>

        <div id="scnReader"></div>
        <div class="scn-scan-status" id="scnStatus">Tap "Start Camera" to begin scanning</div>
        <div class="scn-last-scan" id="scnLastScan"></div>

        <div class="scn-btn-row">
            <button class="scn-btn primary" id="btnStartCam" onclick="scnStartCamera()">📷 Start Camera</button>
            <button class="scn-btn danger" id="btnStopCam" onclick="scnStopCamera()" style="display:none;">⏹ Stop Camera</button>
        </div>

        <div class="scn-manual">
            <input type="text" id="scnManualInput" placeholder="Or type serial number manually..." onkeydown="if(event.key==='Enter')scnAddManual()">
            <button class="scn-btn secondary" onclick="scnAddManual()">+ Add</button>
        </div>

        <div class="scn-items" id="scnItems"></div>

        <div class="scn-btn-row">
            <button class="scn-btn secondary" onclick="scnGoStep1()">← Back</button>
            <button class="scn-btn success" id="btnToStep3" disabled onclick="scnGoStep3()">Review & Submit →</button>
        </div>
    </div>

    <!-- ═══ STEP 3: Review & Submit ═══ -->
    <div class="scn-panel hidden" id="panelStep3">
        <h3>Step 3: Review & Submit</h3>
        <div id="scnReview"></div>
        <div class="scn-btn-row">
            <button class="scn-btn secondary" onclick="scnGoStep2()">← Back to Scan</button>
            <button class="scn-btn success" id="btnSubmit" onclick="scnSubmitAll()">✅ Add All to Stock</button>
        </div>
        <div id="scnSubmitResult" style="margin-top:16px;"></div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js" async></script>
<script>
(function(){
var API = '?page=stock_api&action=';
var _selectedCat = null;
var _scannedItems = [];
var _scanner = null;
var _scanning = false;
var _lastScan = '';
var _lastScanTime = 0;

function esc(s){ return s?String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'):''; }
function $(id){ return document.getElementById(id); }

function api(action, opts, cb){
    var url = API + action;
    var method = (opts||{}).method || 'GET';
    if(method==='GET'&&opts&&opts.params){var qs=[];for(var k in opts.params)if(opts.params[k]!=null&&opts.params[k]!=='')qs.push(k+'='+encodeURIComponent(opts.params[k]));if(qs.length)url+='&'+qs.join('&');}
    var xhr=new XMLHttpRequest();
    xhr.open(method,url,true);
    if(method==='POST')xhr.setRequestHeader('Content-Type','application/json');
    xhr.onload=function(){try{cb(null,JSON.parse(xhr.responseText));}catch(e){cb(e.message);}};
    xhr.onerror=function(){cb('Network error');};
    xhr.send(opts&&opts.body?JSON.stringify(opts.body):null);
}

// ═══ STEP 1: Load Categories ═══
function loadCategories(){
    api('stock_categories', {params:{active_only:1}}, function(err, r){
        if(err||!r||!r.data){$('scnCatGrid').innerHTML='<div style="color:#DC2626;padding:10px;">Error loading categories. <button onclick="loadCategories()" style="background:#D41C1C;color:#fff;border:none;border-radius:6px;padding:6px 14px;font-weight:700;cursor:pointer;margin-left:8px;">🔄 Retry</button></div>';return;}
        var cats = (r.data||[]).filter(function(c){return c.track_mode==='serial';});
        if(!cats.length){$('scnCatGrid').innerHTML='<div style="color:#94A3B8;grid-column:1/-1;padding:20px;text-align:center;">No serial-tracked categories found. Add categories in Stock Management first.</div>';return;}

        var icons = {starlink:'📡',fiber:'🌐',lte:'📶',general:'📦'};
        var h = '';
        cats.forEach(function(c){
            h += '<div class="scn-cat-card" data-id="'+c.id+'" data-title="'+esc(c.title)+'" data-cost="'+(c.buy_price||0)+'" onclick="scnSelectCat(this,'+c.id+')">';
            if(c.image_url) {
                h += '<div class="icon" style="font-size:inherit;"><img src="'+esc(c.image_url)+'" style="width:56px;height:56px;object-fit:cover;border-radius:10px;" onerror="this.outerHTML=\''+(icons[c.service_type]||'📦')+'\';"></div>';
            } else {
                h += '<div class="icon">'+(icons[c.service_type]||'📦')+'</div>';
            }
            h += '<div class="name">'+esc(c.title)+'</div>';
            h += '<div class="sub">'+esc(c.sku||'')+' · '+(c.serial_in_stock||0)+' in stock</div>';
            h += '</div>';
        });
        $('scnCatGrid').innerHTML = h;
    });
}

window.scnSelectCat = function(el, catId){
    document.querySelectorAll('.scn-cat-card').forEach(function(c){c.classList.remove('selected');});
    el.classList.add('selected');
    _selectedCat = {id:catId, title:el.dataset.title, cost:parseFloat(el.dataset.cost)||0};
    $('btnToStep2').disabled = false;
    $('scnCost').value = _selectedCat.cost || '';
};

// ═══ NAVIGATION ═══
function showStep(n){
    ['panelStep1','panelStep2','panelStep3'].forEach(function(p,i){
        $(p).classList.toggle('hidden', i !== n-1);
    });
    [1,2,3].forEach(function(s){
        var el = $('step'+s);
        el.classList.remove('active','done');
        if(s < n) el.classList.add('done');
        if(s === n) el.classList.add('active');
    });
}

window.scnGoStep1 = function(){
    scnStopCamera();
    showStep(1);
};
window.scnGoStep2 = function(){
    if(!_selectedCat){alert('Select a category first');return;}
    showStep(2);
    updateItemList();
};
window.scnGoStep3 = function(){
    if(!_scannedItems.length){alert('Scan at least one item');return;}
    scnStopCamera();
    showStep(3);
    buildReview();
};

// ═══ STEP 2: Camera Scanner ═══
window.scnStartCamera = function(){
    if(_scanning) return;

    // Native barcode scanner: instant, one scan at a time, loops for multi-item
    if(window.dishnetScan){
        var nativeLaunched = window.dishnetScan('stock_item', function(value, format, id){
            if(!value || format === 'CANCELLED'){
                $('scnStatus').textContent = 'Scan cancelled — tap Start Camera to try again';
                $('btnStartCam').style.display = '';
                $('btnStopCam').style.display = 'none';
                return;
            }
            onScan(value);
            // Auto-reopen for next item (stock mode = continuous scanning)
            setTimeout(function(){ window.scnStartCamera(); }, 600);
        });
        if(nativeLaunched){
            $('btnStartCam').style.display = 'none';
            $('btnStopCam').style.display = '';
            $('scnStatus').textContent = 'Native scanner active...';
            $('scnStatus').className = 'scn-scan-status';
            _scanning = true;
            return;
        }
    }

    // Fallback: html5-qrcode PWA camera scanner
    $('btnStartCam').style.display = 'none';
    $('btnStopCam').style.display = '';
    $('scnStatus').textContent = 'Starting camera...';
    $('scnStatus').className = 'scn-scan-status';

    if(typeof Html5Qrcode === 'undefined'){
        $('scnStatus').textContent = 'Scanner library failed to load. Check internet connection — use manual entry below.';
        $('scnStatus').className = 'scn-scan-status error';
        $('btnStartCam').style.display = '';
        $('btnStopCam').style.display = 'none';
        return;
    }
    _scanner = new Html5Qrcode("scnReader",{formatsToSupport:[3,4,5]});
    _scanner.start(
        {facingMode:"environment"},
        {fps:15, aspectRatio:1.6},
        function(decodedText){
            onScan(decodedText);
        },
        function(errorMessage){
            // Ignore scan failures (expected while searching)
        }
    ).then(function(){
        _scanning = true;
        $('scnStatus').textContent = 'Camera active — point at barcode or QR code';
    }).catch(function(err){
        var msg = String(err);
        if(msg.indexOf('NotAllowed')!==-1 || msg.indexOf('Permission')!==-1){
            window.open(location.href.split('?')[0]+'?page=scanner', '_blank');
            $('scnStatus').innerHTML = 'Scanner opened in new tab <a href="javascript:void(0)" onclick="window.open(location.href.split(\'?\')[0]+\'?page=scanner\',\'_blank\')" style="color:#3b82f6;">→ Open again</a>';
        } else {
            $('scnStatus').textContent = 'Camera error: ' + err;
        }
        $('scnStatus').className = 'scn-scan-status error';
        $('btnStartCam').style.display = '';
        $('btnStopCam').style.display = 'none';
    });
};

window.scnStopCamera = function(){
    if(_scanner && _scanning){
        _scanner.stop().then(function(){
            _scanning = false;
            _scanner.clear();
        }).catch(function(){});
    }
    _scanning = false;
    $('btnStartCam').style.display = '';
    $('btnStopCam').style.display = 'none';
    $('scnStatus').textContent = 'Camera stopped';
};

function onScan(text){
    text = text.trim();
    if(!text) return;
    // Reject URLs (QR codes on Starlink labels link to certification sites)
    if(text.match(/^https?:\/\//i) || text.match(/\.(com|org|net|go|id)\//i)) return;
    // Validate known serial patterns
    var kitMatch = text.match(/KIT[A-Z0-9]{8,16}/i);
    if(kitMatch) { text = kitMatch[0].toUpperCase(); }
    else {
        var dishMatch = text.match(/(2ABC|HPCP|HPBA)[A-Z0-9]{12,14}/i);
        if(dishMatch) { text = dishMatch[0].toUpperCase(); }
        else {
            // Stricter fallback: strip non-alphanumeric, must be 10-25 chars
            var clean = text.replace(/[^A-Z0-9]/gi, '');
            if(clean.length >= 10 && clean.length <= 25) { text = clean.toUpperCase(); }
            else return; // reject unrecognized patterns
        }
    }
    // Debounce: same code within 2 seconds = skip
    var now = Date.now();
    if(text === _lastScan && now - _lastScanTime < 2000) return;
    _lastScan = text;
    _lastScanTime = now;

    // Check for duplicates in current batch
    if(_scannedItems.some(function(it){return it.serial === text;})){
        $('scnStatus').textContent = '⚠️ Already scanned: ' + text;
        $('scnStatus').className = 'scn-scan-status error';
        playBeep(false);
        return;
    }

    // Add to list
    _scannedItems.push({
        serial: text,
        cost: parseFloat($('scnCost').value) || _selectedCat.cost || 0,
        condition: $('scnCondition').value,
        location: $('scnLocation').value,
    });

    $('scnStatus').textContent = '✅ Scanned: ' + text;
    $('scnStatus').className = 'scn-scan-status success';
    $('scnLastScan').textContent = '✅ ' + text;
    $('scnLastScan').style.display = 'block';
    setTimeout(function(){$('scnLastScan').style.display='none';}, 3000);

    playBeep(true);
    updateItemList();
}

window.scnAddManual = function(){
    var input = $('scnManualInput');
    var text = input.value.trim();
    if(!text) return;
    if(_scannedItems.some(function(it){return it.serial === text;})){
        alert('Already in list: ' + text);
        return;
    }
    _scannedItems.push({
        serial: text,
        cost: parseFloat($('scnCost').value) || _selectedCat.cost || 0,
        condition: $('scnCondition').value,
        location: $('scnLocation').value,
    });
    input.value = '';
    input.focus();
    updateItemList();
};

window.scnRemoveItem = function(idx){
    _scannedItems.splice(idx, 1);
    updateItemList();
};

function updateItemList(){
    $('scnItemCount').textContent = _scannedItems.length;
    $('btnToStep3').disabled = _scannedItems.length === 0;
    if(!_scannedItems.length){
        $('scnItems').innerHTML = '';
        return;
    }
    var h = '';
    _scannedItems.forEach(function(it, i){
        h += '<div class="scn-item">';
        h += '<span class="idx">'+(i+1)+'</span>';
        h += '<span class="serial">'+esc(it.serial)+'</span>';
        h += '<span style="color:#94A3B8;font-size:12px;margin-left:auto;margin-right:8px;">$'+(it.cost||0).toFixed(2)+'</span>';
        h += '<button class="remove" onclick="scnRemoveItem('+i+')" title="Remove">✕</button>';
        h += '</div>';
    });
    $('scnItems').innerHTML = h;
}

// ═══ STEP 3: Review & Submit ═══
function buildReview(){
    var totalCost = 0;
    _scannedItems.forEach(function(it){totalCost += it.cost||0;});

    var h = '<div style="background:#F8FAFC;border-radius:12px;padding:16px;margin-bottom:16px;">';
    h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:14px;">';
    h += '<div><strong>Category:</strong> '+esc(_selectedCat.title)+'</div>';
    h += '<div><strong>Items:</strong> '+_scannedItems.length+'</div>';
    h += '<div><strong>Total Cost:</strong> $'+totalCost.toFixed(2)+'</div>';
    h += '<div><strong>Location:</strong> '+esc(_scannedItems[0]?.location||'Warehouse')+'</div>';
    h += '</div></div>';

    h += '<div style="max-height:300px;overflow-y:auto;">';
    _scannedItems.forEach(function(it, i){
        h += '<div class="scn-item">';
        h += '<span class="idx">'+(i+1)+'</span>';
        h += '<span class="serial">'+esc(it.serial)+'</span>';
        h += '<span style="color:#94A3B8;font-size:12px;margin-left:auto;">$'+(it.cost||0).toFixed(2)+' · '+esc(it.condition)+'</span>';
        h += '</div>';
    });
    h += '</div>';

    $('scnReview').innerHTML = h;
}

window.scnSubmitAll = function(){
    var btn = $('btnSubmit');
    btn.disabled = true;
    btn.textContent = '⏳ Submitting...';
    $('scnSubmitResult').innerHTML = '';

    var items = _scannedItems.map(function(it){
        return {
            category_id: _selectedCat.id,
            serial_number: it.serial,
            purchase_cost: it.cost || 0,
            condition_grade: it.condition || 'new',
            location_name: it.location || 'DishNet UNMISS',
        };
    });

    // Submit items one by one (serial items need individual creation)
    var done = 0;
    var errors = [];
    var total = items.length;

    function submitNext(){
        if(done >= total){
            btn.disabled = false;
            btn.textContent = '✅ Add All to Stock';
            if(errors.length === 0){
                $('scnSubmitResult').innerHTML = '<div class="scn-result"><div class="big-icon">🎉</div><h3>All '+total+' items added!</h3><p>Category: '+esc(_selectedCat.title)+'</p></div>';
                _scannedItems = [];
            } else {
                var eh = '<div style="color:#DC2626;font-size:14px;"><strong>'+errors.length+' error(s):</strong><br>';
                errors.forEach(function(e){eh += '• '+esc(e)+'<br>';});
                eh += '</div>';
                if(done > errors.length) eh += '<div style="color:#059669;margin-top:8px;">✅ '+(done-errors.length)+' items added successfully</div>';
                $('scnSubmitResult').innerHTML = eh;
            }
            return;
        }
        var it = items[done];
        btn.textContent = '⏳ ' + (done+1) + '/' + total + '...';
        api('stock_unit_save', {method:'POST', body:it}, function(err, r){
            if(err || !r || r.status === 'error'){
                errors.push(it.serial_number + ': ' + (r && r.message || err || 'Unknown error'));
            }
            done++;
            submitNext();
        });
    }
    submitNext();
};

// ═══ BEEP SOUND (optional audio feedback) ═══
function playBeep(success){
    try {
        var ctx = new (window.AudioContext || window.webkitAudioContext)();
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.value = success ? 800 : 300;
        gain.gain.value = 0.15;
        osc.start();
        osc.stop(ctx.currentTime + (success ? 0.12 : 0.25));
    } catch(e){}
}

// ═══ INIT ═══
// v4.11.3: Don't auto-load — parent page calls scnInit() when scanner tab is shown
window.scnInit = function(){ loadCategories(); };
})();
</script>
