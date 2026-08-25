<?php
// Tab: android_app
// Extracted from public.php on 2026-03-15
    // Resolve APK using stored_filename from meta, fall back to legacy name
    $apkMeta    = $store->load('android_app_meta.json') ?? [];
    $apkHistory = $store->load('android_app_history.json') ?: [];
    if (!is_array($apkHistory)) $apkHistory = [];
    $_sname     = $apkMeta['stored_filename'] ?? '';
    $apkPath    = ($_sname && file_exists($dataDir.'/'.$_sname))
        ? $dataDir.'/'.$_sname
        : (file_exists($dataDir.'/dishnet-app.apk') ? $dataDir.'/dishnet-app.apk' : '');
    $apkExists  = file_exists($apkPath);
    $apkSize    = $apkExists ? round(filesize($apkPath)/1024/1024,1).'MB' : null;
    $scheme     = ((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')
               || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])&&$_SERVER['HTTP_X_FORWARDED_PROTO']==='https')
               || (($_SERVER['SERVER_PORT']??80)==443)) ? 'https' : 'http';
    $appUrl     = $scheme.'://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'];
    $apkUrl     = $appUrl.'?page=download_app';
    $installUrl = $appUrl.'?page=install';
    $qrUrl      = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data='.urlencode($installUrl);
?>
<div class="kyc-card">
  <div class="kyc-card-header"><i class="bi bi-android2"></i> Android App Manager</div>
  <div class="kyc-card-body">

    <!-- ── Current APK Status ─────────────────────────────── -->
    <?php if ($apkExists): ?>
    <div style="background:linear-gradient(135deg,#052e16,#14532d);border-radius:16px;padding:20px 22px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:16px;align-items:center;">
      <div style="flex:1;min-width:200px;">
        <div style="font-size:13px;font-weight:800;color:#4ade80;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">✅ APK Active</div>
        <div style="font-size:22px;font-weight:900;color:#fff;margin-bottom:4px;">
          <?= h($apkMeta['filename'] ?? 'dishnet-app.apk') ?>
        </div>
        <div style="font-size:12px;color:#86efac;display:flex;flex-wrap:wrap;gap:12px;margin-top:6px;">
          <?php if (!empty($apkMeta['version'])): ?>
          <span>🏷 Version: <strong><?= h($apkMeta['version']) ?></strong><?php if (!empty($apkMeta['version_code'])): ?> <span style="font-weight:400;opacity:.7;">(code <?= h($apkMeta['version_code']) ?>)</span><?php endif; ?></span>
          <?php endif; ?>
          <?php if (!empty($apkMeta['build_variant'])): ?>
          <span>🔧 Build: <strong><?= h($apkMeta['build_variant']) ?></strong></span>
          <?php endif; ?>
          <span>📦 Size: <strong><?= $apkSize ?></strong></span>
          <?php if (!empty($apkMeta['uploaded_at'])): ?>
          <span>📅 Uploaded: <strong><?= h($apkMeta['uploaded_at']) ?></strong></span>
          <?php endif; ?>
          <?php if (!empty($apkMeta['uploaded_by'])): ?>
          <span>👤 By: <strong><?= h($apkMeta['uploaded_by']) ?></strong></span>
          <?php endif; ?>
          <?php if (!empty($apkMeta['set_current_at'])): ?>
          <span style="background:rgba(124,58,237,.25);color:#c4b5fd;border-radius:6px;padding:2px 8px;font-size:10px;font-weight:700;">
            ⬆️ Promoted <?= h(substr($apkMeta['set_current_at'],0,10)) ?> by <?= h($apkMeta['set_current_by'] ?? '?') ?>
          </span>
          <?php endif; ?>
        </div>
        <?php if (!empty($apkMeta['changelog'])): ?>
        <div style="margin-top:10px;background:rgba(0,0,0,.25);border-radius:8px;padding:8px 12px;font-size:12px;color:#d1fae5;line-height:1.6;">
          <strong>Changelog:</strong> <?= nl2br(h($apkMeta['changelog'])) ?>
        </div>
        <?php endif; ?>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;">
        <a href="<?= h($apkUrl) ?>" style="background:#22c55e;color:#fff;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:800;text-decoration:none;white-space:nowrap;">
          ⬇️ Download APK
        </a>
        <a href="<?= h($installUrl) ?>" target="_blank" style="background:#0ea5e9;color:#fff;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:800;text-decoration:none;white-space:nowrap;">
          📲 Install Page
        </a>
        <form method="POST" style="margin:0;" onsubmit="return confirm('Delete the current APK? It will no longer be downloadable.')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete_apk">
          <button type="submit" style="background:#dc2626;color:#fff;border:none;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;">
            🗑 Delete APK
          </button>
        </form>
      </div>
    </div>
    <?php else: ?>
    <div style="background:#1c1917;border:2px dashed #78716c;border-radius:16px;padding:28px;text-align:center;margin-bottom:20px;">
      <div style="font-size:40px;margin-bottom:8px;">📱</div>
      <div style="font-size:16px;font-weight:800;color:#e7e5e4;margin-bottom:4px;">No APK uploaded yet</div>
      <div style="font-size:13px;color:#78716c;">Upload an APK below to enable the Android download &amp; install page for your agents.</div>
    </div>
    <?php endif; ?>

    <!-- ── Minimum App Version Control ──────────────────── -->
    <div style="background:#1e293b;border-radius:16px;padding:18px 22px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:14px;align-items:center;">
      <div style="flex:1;min-width:200px;">
        <div style="font-size:14px;font-weight:800;color:#e2e8f0;margin-bottom:4px;">📱 Minimum App Version</div>
        <div style="font-size:11px;color:#64748b;line-height:1.5;">
          Agents with an older Android app will see an <strong style="color:#fca5a5;">update required</strong> banner.
          Set this to the version that has features your team needs (e.g. barcode scanner = 2.4).
        </div>
      </div>
      <form method="POST" style="margin:0;display:flex;gap:8px;align-items:center;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="set_min_app_version">
        <input type="text" name="min_version" value="<?= h($apkMeta['min_version'] ?? '2.4') ?>"
          style="width:80px;background:#0f172a;border:1px solid #334155;border-radius:8px;padding:9px 12px;color:#f1f5f9;font-size:14px;font-weight:700;text-align:center;"
          placeholder="2.4">
        <button type="submit"
          style="background:#7c3aed;color:#fff;border:none;border-radius:8px;padding:9px 16px;font-size:13px;font-weight:800;cursor:pointer;white-space:nowrap;">
          Save
        </button>
      </form>
    </div>

    <!-- ── Version History ───────────────────────────────── -->
    <?php if (!empty($apkHistory)): ?>
    <div style="background:#1e293b;border-radius:16px;padding:20px;margin-bottom:20px;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div style="font-size:14px;font-weight:800;color:#e2e8f0;">📦 Version History</div>
        <div style="font-size:11px;color:#475569;">Promote any version to make it the live download link</div>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;">
        <?php foreach ($apkHistory as $_hv): ?>
        <?php
          $_hvFile   = $dataDir.'/' . ($_hv['stored_filename'] ?? '');
          $_hvExists = file_exists($_hvFile);
          $_hvSize   = $_hvExists ? round(filesize($_hvFile)/1024/1024,1).'MB' : null;
          $_hvIsCurrent = ($apkMeta['stored_filename'] ?? '') === ($_hv['stored_filename'] ?? '');
        ?>
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;background:#0f172a;border-radius:10px;padding:10px 14px;<?= $_hvIsCurrent ? 'border:1px solid #22c55e;' : '' ?>">
          <div style="flex:1;min-width:180px;">
            <div style="font-size:13px;font-weight:800;color:#e2e8f0;display:flex;align-items:center;flex-wrap:wrap;gap:6px;">
              v<?= h($_hv['version'] ?? '?') ?>
              <?php if (!empty($_hv['version_code'])): ?>
                <span style="font-size:10px;color:#64748b;font-weight:400;">(code <?= h($_hv['version_code']) ?>)</span>
              <?php endif; ?>
              <?php if (!empty($_hv['build_variant'])): ?>
                <span style="background:#1e3a5f;color:#7dd3fc;border-radius:5px;padding:1px 6px;font-size:10px;font-weight:700;"><?= h($_hv['build_variant']) ?></span>
              <?php endif; ?>
            </div>
            <div style="font-size:11px;color:#475569;margin-top:3px;">
              📅 <?= h(substr($_hv['uploaded_at']??'',0,10)) ?>
              <?php if (!empty($_hv['uploaded_by'])): ?> · 👤 <?= h($_hv['uploaded_by']) ?><?php endif; ?>
              <?php if ($_hvSize): ?> · 📦 <?= $_hvSize ?><?php endif; ?>
            </div>
            <div style="font-size:10px;color:#1e3a5f;margin-top:2px;font-family:monospace;"><?= h($_hv['stored_filename'] ?? '') ?></div>
          </div>
          <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
            <?php if ($_hvExists): ?>
              <a href="?page=download_apk_version&file=<?= urlencode($_hv['stored_filename'] ?? '') ?>"
                 style="background:#1e3a5f;color:#7dd3fc;border-radius:8px;padding:6px 12px;font-size:11px;font-weight:700;text-decoration:none;white-space:nowrap;">
                ⬇️ Download
              </a>
              <?php if (!$_hvIsCurrent): ?>
              <form method="POST" style="margin:0;" onsubmit="return confirm('Set v<?= h($_hv['version'] ?? '?') ?> as the live download? All agents will get this version.')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_current_apk">
                <input type="hidden" name="target_filename" value="<?= h($_hv['stored_filename'] ?? '') ?>">
                <button type="submit"
                  style="background:#7c3aed;color:#fff;border:none;border-radius:8px;padding:6px 12px;font-size:11px;font-weight:800;cursor:pointer;white-space:nowrap;">
                  ⬆️ Set as Current
                </button>
              </form>
              <?php else: ?>
              <span style="background:#14532d;color:#4ade80;border-radius:8px;padding:6px 12px;font-size:11px;font-weight:800;white-space:nowrap;">
                ✅ Current
              </span>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:#4b5563;font-size:11px;font-style:italic;">file missing</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Upload New APK ─────────────────────────────────── -->
    <div style="background:#1e293b;border-radius:16px;padding:22px;margin-bottom:20px;">
      <div style="font-size:14px;font-weight:800;color:#e2e8f0;margin-bottom:16px;">
        <?= $apkExists ? '🔄 Replace APK (Upload New Version)' : '⬆️ Upload APK' ?>
      </div>
      <form method="POST" enctype="multipart/form-data" id="apkUploadForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="upload_apk">

        <!-- Metadata JSON auto-fill banner (shown when JSON is loaded) -->
        <div id="apkMetaBanner" style="display:none;background:#0f2a1a;border:1px solid #22c55e;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#86efac;display:none;align-items:center;gap:10px;">
          <span style="font-size:18px;">✅</span>
          <span id="apkMetaBannerText">Metadata loaded</span>
          <button type="button" onclick="apkClearMeta()" style="margin-left:auto;background:none;border:1px solid #4ade80;border-radius:6px;color:#4ade80;padding:2px 8px;font-size:11px;cursor:pointer;">✕ Clear</button>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="font-size:12px;font-weight:700;color:#94a3b8;display:block;margin-bottom:5px;">APK File (.apk) <span style="color:#ef4444;">*</span></label>
            <input type="file" name="apk_file" accept=".apk,application/vnd.android.package-archive" required id="apkFileInput"
              style="width:100%;background:#0f172a;border:1px solid #334155;border-radius:8px;padding:8px 12px;color:#f1f5f9;font-size:13px;">
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:#94a3b8;display:block;margin-bottom:5px;">
              output-metadata.json <span style="color:#64748b;">(optional — auto-fills version)</span>
            </label>
            <input type="file" name="apk_metadata" accept=".json,application/json" id="apkMetaInput"
              style="width:100%;background:#0f172a;border:1px solid #334155;border-radius:8px;padding:8px 12px;color:#f1f5f9;font-size:13px;">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="font-size:12px;font-weight:700;color:#94a3b8;display:block;margin-bottom:5px;">Version Name <span style="color:#ef4444;">*</span></label>
            <input type="text" name="apk_version" id="apkVersionInput" placeholder="e.g. 2.1.0 (auto-filled from JSON)"
              style="width:100%;background:#0f172a;border:1px solid #334155;border-radius:8px;padding:9px 12px;color:#f1f5f9;font-size:13px;">
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:#94a3b8;display:block;margin-bottom:5px;">Build Variant</label>
            <input type="text" id="apkVariantDisplay" readonly placeholder="e.g. debug / release"
              style="width:100%;background:#0a1628;border:1px solid #1e3a5f;border-radius:8px;padding:9px 12px;color:#64748b;font-size:13px;cursor:default;">
          </div>
        </div>

        <div style="margin-bottom:16px;">
          <label style="font-size:12px;font-weight:700;color:#94a3b8;display:block;margin-bottom:5px;">Changelog / Release Notes <span style="color:#64748b;">(optional)</span></label>
          <textarea name="apk_changelog" rows="3" placeholder="What's new in this version..."
            style="width:100%;background:#0f172a;border:1px solid #334155;border-radius:8px;padding:9px 12px;color:#f1f5f9;font-size:13px;resize:vertical;"></textarea>
        </div>

        <!-- Upload progress bar (shown during upload) -->
        <div id="apkProgressWrap" style="display:none;margin-bottom:14px;">
          <div style="font-size:12px;color:#94a3b8;margin-bottom:5px;" id="apkProgressLabel">Uploading…</div>
          <div style="background:#0f172a;border-radius:8px;height:10px;overflow:hidden;">
            <div id="apkProgressBar" style="height:100%;background:linear-gradient(90deg,#D41C1C,#f97316);width:0%;transition:width .3s;border-radius:8px;"></div>
          </div>
        </div>

        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <button type="submit" id="apkUploadBtn"
            style="background:#D41C1C;color:#fff;border:none;border-radius:10px;padding:11px 26px;font-size:14px;font-weight:800;cursor:pointer;">
            ⬆️ Upload APK
          </button>
          <span id="apkFileInfo" style="font-size:12px;color:#64748b;"></span>
        </div>
      </form>
    </div>

    <!-- ── Share / QR ──────────────────────────────────────── -->
    <?php if ($apkExists): ?>
    <div style="background:#1e293b;border-radius:16px;padding:22px;display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start;">
      <div style="flex:1;min-width:200px;">
        <div style="font-size:14px;font-weight:800;color:#e2e8f0;margin-bottom:12px;">📤 Share with Agents</div>
        <p style="font-size:13px;color:#94a3b8;margin-bottom:12px;">Send this link or QR code to your agents so they can install the Android app on their phones.</p>
        <div style="background:#0f172a;border-radius:8px;padding:10px 14px;font-family:monospace;font-size:11px;color:#7dd3fc;word-break:break-all;margin-bottom:12px;">
          <?= h($installUrl) ?>
        </div>
        <?php
          $waText = urlencode("📡 *DishNet Africa App*\n\nDownload and install the DishNet agent app:\n\n👇 *Tap the link to open the install page:*\n".$installUrl."\n\nTap Download → Install. That's it! ✅");
        ?>
        <a href="https://wa.me/?text=<?= $waText ?>" target="_blank"
          style="display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,#128C7E,#25D366);color:#fff;border-radius:10px;padding:10px 18px;font-size:13px;font-weight:800;text-decoration:none;">
          💬 Share via WhatsApp
        </a>
      </div>
      <div style="text-align:center;">
        <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">📷 Scan QR to Install</div>
        <img src="<?= h($qrUrl) ?>" width="160" height="160" alt="QR Code"
          style="border-radius:12px;background:#fff;padding:8px;">
        <div style="font-size:10px;color:#475569;margin-top:6px;">Points to the install page</div>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
// Show file size when APK is picked
document.getElementById('apkFileInput')?.addEventListener('change', function() {
    var info = document.getElementById('apkFileInfo');
    if (this.files[0]) {
        var mb = (this.files[0].size / 1024 / 1024).toFixed(1);
        info.textContent = this.files[0].name + ' — ' + mb + ' MB';
    }
});

// Auto-read output-metadata.json and fill in version fields
document.getElementById('apkMetaInput')?.addEventListener('change', function() {
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        try {
            var meta = JSON.parse(e.target.result);
            var el   = (meta.elements || [])[0] || {};
            var ver  = el.versionName || '';
            var code = el.versionCode || '';
            var variant = meta.variantName || '';

            if (ver) {
                document.getElementById('apkVersionInput').value = ver;
                document.getElementById('apkVersionInput').style.borderColor = '#22c55e';
            }
            if (variant) {
                document.getElementById('apkVariantDisplay').value = variant;
            }

            var banner = document.getElementById('apkMetaBanner');
            var bannerText = document.getElementById('apkMetaBannerText');
            var parts = [];
            if (ver)     parts.push('Version: ' + ver);
            if (code)    parts.push('Code: ' + code);
            if (variant) parts.push('Variant: ' + variant);
            bannerText.textContent = '✅ Metadata loaded — ' + parts.join(' · ');
            banner.style.display = 'flex';
        } catch(err) {
            alert('Could not read metadata JSON: ' + err.message);
        }
    };
    reader.readAsText(file);
});

function apkClearMeta() {
    document.getElementById('apkMetaInput').value = '';
    document.getElementById('apkVersionInput').value = '';
    document.getElementById('apkVersionInput').style.borderColor = '';
    document.getElementById('apkVariantDisplay').value = '';
    document.getElementById('apkMetaBanner').style.display = 'none';
}

// Show upload progress via XHR so large APKs don't look frozen
document.getElementById('apkUploadForm')?.addEventListener('submit', function(e) {
    var fileInput = document.getElementById('apkFileInput');
    if (!fileInput.files[0]) return; // let native validation handle it

    e.preventDefault();
    var formData = new FormData(this);
    var xhr = new XMLHttpRequest();
    var btn = document.getElementById('apkUploadBtn');
    var wrap = document.getElementById('apkProgressWrap');
    var bar  = document.getElementById('apkProgressBar');
    var lbl  = document.getElementById('apkProgressLabel');

    btn.disabled = true;
    btn.textContent = '⏳ Uploading…';
    wrap.style.display = 'block';

    xhr.upload.addEventListener('progress', function(ev) {
        if (ev.lengthComputable) {
            var pct = Math.round(ev.loaded / ev.total * 100);
            bar.style.width = pct + '%';
            lbl.textContent = 'Uploading… ' + pct + '% (' + (ev.loaded/1024/1024).toFixed(1) + ' / ' + (ev.total/1024/1024).toFixed(1) + ' MB)';
        }
    });

    xhr.addEventListener('load', function() {
        lbl.textContent = 'Processing…';
        bar.style.width = '100%';
        // Server redirects on success — follow the redirect
        window.location.href = '?page=dashboard&tab=android_app';
    });

    xhr.addEventListener('error', function() {
        btn.disabled = false;
        btn.textContent = '⬆️ Upload APK';
        wrap.style.display = 'none';
        alert('Upload failed. Please try again.');
    });

    xhr.open('POST', window.location.href);
    xhr.send(formData);
});
</script>

