<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DishNet Hybrid — Support Leader Guide</title>
<style>
  :root {
    --red: #D41C1C;
    --dark: #141414;
    --text: #1e293b;
    --muted: #64748b;
    --border: #e2e8f0;
    --bg: #f8fafc;
    --green: #059669;
    --purple: #7c3aed;
    --orange: #d97706;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Barlow Condensed', 'Segoe UI', sans-serif; background: #fff; color: var(--text); font-size: 15px; line-height: 1.6; }

  /* Cover */
  .cover { background: linear-gradient(145deg, #141414, #2a0a0a); color: #fff; padding: 60px 40px 50px; position: relative; overflow: hidden; }
  .cover::before { content: ''; position: absolute; top: -60px; right: -60px; width: 300px; height: 300px; border-radius: 50%; background: rgba(212,28,28,.15); }
  .cover::after  { content: ''; position: absolute; bottom: -40px; left: 40px; width: 180px; height: 180px; border-radius: 50%; background: rgba(212,28,28,.08); }
  .cover-logo { font-size: 28px; font-weight: 900; letter-spacing: -1px; color: #fff; margin-bottom: 6px; }
  .cover-logo span { color: var(--red); }
  .cover-tag { font-size: 11px; color: rgba(255,255,255,.45); text-transform: uppercase; letter-spacing: 2px; margin-bottom: 48px; }
  .cover-title { font-size: 42px; font-weight: 900; line-height: 1.1; letter-spacing: -1px; margin-bottom: 12px; }
  .cover-sub { font-size: 16px; color: rgba(255,255,255,.6); margin-bottom: 32px; }
  .cover-badge { display: inline-flex; align-items: center; gap: 8px; background: var(--red); color: #fff; font-size: 12px; font-weight: 800; padding: 6px 16px; border-radius: 20px; text-transform: uppercase; letter-spacing: .5px; }
  .cover-meta { margin-top: 40px; font-size: 12px; color: rgba(255,255,255,.35); }

  /* Layout */
  .content { max-width: 860px; margin: 0 auto; padding: 0 32px 60px; }

  /* TOC */
  .toc { background: var(--bg); border-left: 4px solid var(--red); border-radius: 0 12px 12px 0; padding: 24px 28px; margin: 32px 0; }
  .toc-title { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); margin-bottom: 14px; }
  .toc ol { padding-left: 18px; }
  .toc li { margin-bottom: 6px; }
  .toc a { color: var(--red); text-decoration: none; font-weight: 600; font-size: 14px; }
  .toc a:hover { text-decoration: underline; }

  /* Sections */
  h2 { font-size: 26px; font-weight: 900; color: var(--dark); margin: 48px 0 6px; padding-top: 8px; border-bottom: 3px solid var(--red); padding-bottom: 8px; display: flex; align-items: center; gap: 10px; }
  h3 { font-size: 17px; font-weight: 800; color: var(--text); margin: 28px 0 10px; display: flex; align-items: center; gap: 8px; }
  h3::before { content: '▸'; color: var(--red); font-size: 12px; }
  p { margin-bottom: 12px; color: #334155; }

  /* Screen mockup */
  .screen { background: var(--dark); border-radius: 14px; padding: 0; margin: 20px 0; overflow: hidden; box-shadow: 0 8px 32px rgba(0,0,0,.18); }
  .screen-bar { background: #1e1e1e; padding: 8px 16px; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid #333; }
  .screen-dot { width: 10px; height: 10px; border-radius: 50%; }
  .screen-url { font-size: 11px; color: #888; margin-left: 8px; font-family: monospace; }
  .screen-body { padding: 18px 20px; }

  /* Nav preview */
  .nav-preview { background: #1a1a1a; border-radius: 10px; padding: 12px 0; margin-bottom: 14px; }
  .nav-item { display: flex; align-items: center; gap: 10px; padding: 8px 16px; font-size: 13px; color: rgba(255,255,255,.7); font-weight: 600; cursor: default; }
  .nav-item.active { background: var(--red); color: #fff; border-radius: 8px; margin: 0 8px; }
  .nav-item.group-title { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: rgba(255,255,255,.3); padding: 12px 16px 4px; font-weight: 700; }

  /* Step flow */
  .flow { display: flex; align-items: flex-start; gap: 0; margin: 20px 0; flex-wrap: wrap; }
  .flow-step { display: flex; flex-direction: column; align-items: center; flex: 1; min-width: 100px; }
  .flow-step-box { background: var(--bg); border: 2px solid var(--border); border-radius: 12px; padding: 12px 10px; text-align: center; width: 100%; font-size: 12px; font-weight: 700; color: var(--text); }
  .flow-step-box.red { background: #fff5f5; border-color: var(--red); color: var(--red); }
  .flow-step-box.green { background: #f0fdf4; border-color: var(--green); color: var(--green); }
  .flow-step-box.purple { background: #f5f3ff; border-color: var(--purple); color: var(--purple); }
  .flow-step-icon { font-size: 20px; margin-bottom: 4px; }
  .flow-arrow { align-self: center; color: var(--muted); font-size: 18px; padding: 0 4px; flex-shrink: 0; }

  /* Status badges */
  .badge-row { display: flex; flex-wrap: wrap; gap: 8px; margin: 14px 0; }
  .badge { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 800; }
  .badge.pending  { background: #fef9c3; color: #854d0e; }
  .badge.assigned { background: #dbeafe; color: #1e40af; }
  .badge.ready    { background: #f5f3ff; color: #5b21b6; }
  .badge.approved { background: #dcfce7; color: #166534; }
  .badge.rejected { background: #fee2e2; color: #991b1b; }

  /* Action button previews */
  .btn-row { display: flex; flex-wrap: wrap; gap: 8px; margin: 14px 0; }
  .btn-preview { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 10px; font-size: 12px; font-weight: 800; cursor: default; }
  .btn-preview.red    { background: var(--red); color: #fff; }
  .btn-preview.green  { background: var(--green); color: #fff; }
  .btn-preview.purple { background: var(--purple); color: #fff; }
  .btn-preview.ghost  { background: #f1f5f9; color: #374151; border: 1.5px solid #e2e8f0; }
  .btn-preview.danger { background: #ef4444; color: #fff; }

  /* Info / warning / tip boxes */
  .box { border-radius: 12px; padding: 16px 20px; margin: 16px 0; display: flex; gap: 12px; }
  .box-icon { font-size: 20px; flex-shrink: 0; margin-top: 1px; }
  .box-body { font-size: 14px; }
  .box-body strong { display: block; font-size: 13px; font-weight: 800; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .3px; }
  .box.info    { background: #eff6ff; border-left: 4px solid #3b82f6; }
  .box.tip     { background: #f0fdf4; border-left: 4px solid var(--green); }
  .box.warning { background: #fffbeb; border-left: 4px solid var(--orange); }
  .box.danger  { background: #fef2f2; border-left: 4px solid #ef4444; }

  /* Table */
  table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px; }
  th { background: var(--dark); color: #fff; padding: 10px 14px; text-align: left; font-weight: 800; font-size: 12px; text-transform: uppercase; letter-spacing: .3px; }
  th:first-child { border-radius: 8px 0 0 0; }
  th:last-child  { border-radius: 0 8px 0 0; }
  td { padding: 10px 14px; border-bottom: 1px solid var(--border); vertical-align: top; }
  tr:last-child td { border-bottom: none; }
  tr:nth-child(even) td { background: var(--bg); }

  /* Step list */
  .steps { counter-reset: step; list-style: none; padding: 0; margin: 16px 0; }
  .steps li { counter-increment: step; display: flex; gap: 14px; margin-bottom: 14px; align-items: flex-start; }
  .steps li::before { content: counter(step); background: var(--red); color: #fff; width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 900; flex-shrink: 0; margin-top: 1px; }
  .steps li div { font-size: 14px; color: #334155; padding-top: 3px; }
  .steps li strong { display: block; font-size: 14px; font-weight: 800; color: var(--text); margin-bottom: 2px; }

  /* Chip */
  .chip { display: inline-flex; align-items: center; gap: 4px; background: var(--red); color: #fff; font-size: 11px; font-weight: 800; padding: 2px 10px; border-radius: 20px; }
  .chip.purple { background: var(--purple); }
  .chip.green  { background: var(--green); }

  /* Quick ref card */
  .qr-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 16px 0; }
  .qr-card { background: var(--bg); border: 1.5px solid var(--border); border-radius: 12px; padding: 16px; }
  .qr-card-title { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
  .qr-card ul { list-style: none; padding: 0; }
  .qr-card ul li { font-size: 13px; color: #334155; padding: 4px 0; border-bottom: 1px solid #f1f5f9; display: flex; gap: 8px; }
  .qr-card ul li:last-child { border: none; }
  .qr-card ul li::before { content: '→'; color: var(--red); font-weight: 800; flex-shrink: 0; }

  /* Footer */
  .footer { background: var(--dark); color: rgba(255,255,255,.4); text-align: center; padding: 24px; font-size: 12px; margin-top: 60px; }
  .footer strong { color: rgba(255,255,255,.7); }

  @media print {
    .cover { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    h2 { page-break-before: always; }
    h2:first-of-type { page-break-before: avoid; }
  }
</style>
</head>
<body>

<!-- ═══ COVER ═══════════════════════════════════════════════════════════ -->
<div class="cover">
  <div class="cover-logo">Dish<span>Net</span> Africa</div>
  <div class="cover-tag">DishNet Hybrid Telecom Plugin · v4.1</div>
  <div class="cover-title">Support Leader<br>Operations Guide</div>
  <div class="cover-sub">Everything you need to run Fiber installs, manage field engineers,<br>monitor LTE, and keep customers happy — every day.</div>
  <div class="cover-badge">🛡 Support Leader Role</div>
  <div class="cover-meta">DishNet Africa Ltd · Juba, South Sudan · Confidential</div>
</div>

<div class="content">

<!-- TOC -->
<div class="toc">
  <div class="toc-title">Contents</div>
  <ol>
    <li><a href="#login">Logging In &amp; Your Dashboard</a></li>
    <li><a href="#noc">Splynx NOC Dashboard — Your Main Screen</a></li>
    <li><a href="#tickets">Understanding Ticket Status</a></li>
    <li><a href="#assign">Assigning Engineers to Tickets</a></li>
    <li><a href="#commission">Approving &amp; Commissioning Completions</a></li>
    <li><a href="#reject">Rejecting a Job</a></li>
    <li><a href="#my-jobs">My Install Jobs — Your Own Jobs</a></li>
    <li><a href="#live-map">Live Staff Map</a></li>
    <li><a href="#route">Route Manager</a></li>
    <li><a href="#lte">LTE Network Management</a></li>
    <li><a href="#lookup">Customer Lookup &amp; Service Status</a></li>
    <li><a href="#daily">Daily Routine Checklist</a></li>
    <li><a href="#tips">Tips &amp; Common Mistakes</a></li>
  </ol>
</div>

<!-- ═══ 1. LOGIN ══════════════════════════════════════════════════════ -->
<h2 id="login">1 · Logging In &amp; Your Dashboard</h2>

<p>Open your browser and go to:</p>
<div class="screen">
  <div class="screen-bar">
    <div class="screen-dot" style="background:#ff5f56;"></div>
    <div class="screen-dot" style="background:#ffbd2e;"></div>
    <div class="screen-dot" style="background:#27c93f;"></div>
    <div class="screen-url">crm.dishnetafrica.com/crm/_plugins/dishnet-hybrid-telecom/public.php</div>
  </div>
  <div class="screen-body" style="color:#ccc;font-size:13px;">
    Enter your <strong style="color:#fff;">username</strong> and <strong style="color:#fff;">password</strong> → Click <strong style="color:#D41C1C;">Login</strong>
  </div>
</div>

<p>After login you land directly on the <strong>Splynx NOC Dashboard</strong> — this is your home screen as Support Leader. Your sidebar shows these sections:</p>

<div class="nav-preview">
  <div class="nav-item group-title">Support</div>
  <div class="nav-item">📡 Support Dashboard</div>
  <div class="nav-item active">🌐 Splynx NOC Dashboard</div>
  <div class="nav-item">🔧 My Install Jobs</div>
  <div class="nav-item">📍 Live Staff Map</div>
  <div class="nav-item">🛣 Route Manager</div>
  <div class="nav-item">🔍 Customer Lookup</div>
  <div class="nav-item">📶 Service Status</div>
  <div class="nav-item">🎫 Support Tickets</div>
  <div class="nav-item group-title">LTE Network</div>
  <div class="nav-item">📡 LTE Dashboard</div>
  <div class="nav-item">👥 LTE Subscribers</div>
  <div class="nav-item">🔁 LTE Renewal Queue</div>
  <div class="nav-item">📱 SIM Inventory</div>
  <div class="nav-item">🔌 CPE / Hardware</div>
</div>

<!-- ═══ 2. NOC ════════════════════════════════════════════════════════ -->
<h2 id="noc">2 · Splynx NOC Dashboard — Your Main Screen</h2>

<p>This is your command centre for all <strong>Fiber FTTH installations</strong>. Every new fiber customer application creates a ticket here automatically.</p>

<h3>Area Dispatch Grid</h3>
<p>At the top you see a grid of all 50 Juba areas — each cell shows how many open tickets are in that area:</p>

<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;margin:12px 0;">
<table>
  <tr><th>Cell colour</th><th>Meaning</th></tr>
  <tr><td>🔴 Red border</td><td>Has urgent tickets (older than 48 hours — needs immediate attention)</td></tr>
  <tr><td>🔵 Blue border when clicked</td><td>Area selected — batch assign bar appears below</td></tr>
  <tr><td>Grey / faded</td><td>No open tickets in that area</td></tr>
</table>
</div><!-- /overflow-x:auto -->

<div class="box info">
  <div class="box-icon">💡</div>
  <div class="box-body">
    <strong>Quick Batch Assign</strong>
    Click an area cell → a bar appears showing "<em>X unassigned tickets in [Area]</em>" → select an engineer from the dropdown → click <strong>🚀 Assign All</strong>. All unassigned tickets in that area go to that engineer in one tap.
  </div>
</div>

<h3>Ticket List (below the grid)</h3>
<p>Scrolling down shows all individual tickets. You can filter by:</p>
<ul style="margin:10px 0 10px 20px;color:#334155;font-size:14px;line-height:2;">
  <li><strong>All</strong> — every open ticket</li>
  <li><strong>Unassigned</strong> — tickets with no engineer yet (priority!)</li>
  <li><strong>My Jobs</strong> — tickets assigned to you personally</li>
  <li><strong>By Area</strong> — filter to a specific neighbourhood</li>
</ul>

<h3>Sync Button</h3>
<p>The <strong>🔄 Sync</strong> button at the top refreshes tickets from Splynx. Press this if you think a new ticket is missing, or after you know a new KYC was submitted. If sync shows 0 tickets, use the <strong>🔧 Diagnose</strong> button to check the connection.</p>

<!-- ═══ 3. TICKET STATUS ══════════════════════════════════════════════ -->
<h2 id="tickets">3 · Understanding Ticket Status</h2>

<p>Every ticket moves through these stages:</p>

<div class="flow">
  <div class="flow-step">
    <div class="flow-step-box red"><div class="flow-step-icon">⏳</div>Pending<br><span style="font-size:10px;font-weight:400;">New, no engineer</span></div>
  </div>
  <div class="flow-arrow">→</div>
  <div class="flow-step">
    <div class="flow-step-box" style="background:#dbeafe;border-color:#3b82f6;color:#1e40af;"><div class="flow-step-icon">🔧</div>Assigned<br><span style="font-size:10px;font-weight:400;">Engineer allocated</span></div>
  </div>
  <div class="flow-arrow">→</div>
  <div class="flow-step">
    <div class="flow-step-box purple"><div class="flow-step-icon">🔬</div>Ready<br><span style="font-size:10px;font-weight:400;">Engineer done, awaiting review</span></div>
  </div>
  <div class="flow-arrow">→</div>
  <div class="flow-step">
    <div class="flow-step-box green"><div class="flow-step-icon">✅</div>Approved<br><span style="font-size:10px;font-weight:400;">You confirmed &amp; commissioned</span></div>
  </div>
</div>

<div class="badge-row">
  <span class="badge pending">⏳ Pending</span>
  <span class="badge assigned">🔧 Assigned</span>
  <span class="badge ready">🔬 Ready for Review</span>
  <span class="badge approved">✅ Approved / Done</span>
  <span class="badge rejected">❌ Rejected</span>
</div>

<div class="box warning">
  <div class="box-icon">⚠️</div>
  <div class="box-body">
    <strong>Urgency Chips</strong>
    A ticket shows an <span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;">⏰ 2d old</span> chip if it has been waiting over 48 hours. These are your top priority — assign immediately.
  </div>
</div>

<!-- ═══ 4. ASSIGN ════════════════════════════════════════════════════ -->
<h2 id="assign">4 · Assigning Engineers to Tickets</h2>

<h3>Assign a Single Ticket</h3>
<ol class="steps">
  <li><div><strong>Find the ticket</strong> — scroll the list or use the area filter to find the ticket you need.</div></li>
  <li><div><strong>Click 👤 Assign</strong> — the assign panel opens. You'll see the customer name, area chip (<span class="chip">📍 Buluk</span>), and address.</div></li>
  <li><div><strong>Select an engineer</strong> from the dropdown. Engineers available in that area are listed first.</div></li>
  <li><div><strong>Add an Ops Note</strong> (optional but recommended) — e.g. "Configure ONT, take before/after photos, collect first payment."</div></li>
  <li><div><strong>Set Urgency</strong> — Normal, High, or Critical. High/Critical sends a priority WhatsApp to the engineer.</div></li>
  <li><div><strong>Click Assign</strong> → ticket status changes to 🔧 Assigned. Engineer receives a WhatsApp notification instantly.</div></li>
</ol>

<div class="btn-row">
  <span class="btn-preview red">👤 Assign</span>
  <span class="btn-preview red">🔄 Reassign</span>
</div>

<div class="box tip">
  <div class="box-icon">✅</div>
  <div class="box-body">
    <strong>Reassign</strong>
    If the engineer can't make it, the button changes to <strong>🔄 Reassign</strong>. Click it, pick a different engineer. The original engineer will also receive a WhatsApp that the job was reassigned.
  </div>
</div>

<h3>Batch Assign (Whole Area)</h3>
<ol class="steps">
  <li><div><strong>Click the area cell</strong> in the dispatch grid (e.g. Buluk).</div></li>
  <li><div>A bar appears: <em>"📍 Buluk — 4 unassigned tickets"</em></div></li>
  <li><div>Select an engineer from the dropdown in the bar.</div></li>
  <li><div>Click <strong>🚀 Assign All</strong> → all 4 tickets assigned at once. One WhatsApp summary sent to the engineer.</div></li>
</ol>

<!-- ═══ 5. COMMISSION ════════════════════════════════════════════════ -->
<h2 id="commission">5 · Approving &amp; Commissioning Completions</h2>

<p>When an engineer finishes an installation they mark the job <strong>Ready</strong> from their device. You will then see the ticket with a <span class="badge ready">🔬 Ready</span> badge and two action buttons:</p>

<div class="btn-row">
  <span class="btn-preview green">✅ Commission</span>
  <span class="btn-preview danger">✕ Reject</span>
</div>

<h3>How to Commission (Approve)</h3>
<ol class="steps">
  <li><div><strong>Open the ticket</strong> — click <strong>View</strong> to see the full detail. Check: ONU serial recorded, photos uploaded, customer confirmed.</div></li>
  <li><div><strong>Verify ONU serial is entered</strong> — if missing, you cannot commission. The field must be filled.</div></li>
  <li><div><strong>Click ✅ Commission</strong> → a confirmation panel opens showing the install summary.</div></li>
  <li><div><strong>Confirm</strong> → ticket moves to ✅ Approved. Engineer's commission is recorded. Customer's service activates in Splynx.</div></li>
</ol>

<div class="box info">
  <div class="box-icon">💡</div>
  <div class="box-body">
    <strong>What happens when you commission</strong>
    The Splynx ticket is marked Solved → CRM service activates → engineer's commission is logged → WhatsApp confirmation sent to the sales agent who registered the customer.
  </div>
</div>

<!-- ═══ 6. REJECT ════════════════════════════════════════════════════ -->
<h2 id="reject">6 · Rejecting a Job</h2>

<p>Reject only when the work is incomplete or incorrect — <strong>not as a way to delay</strong>.</p>

<ol class="steps">
  <li><div><strong>Click ✕ Reject</strong> on a Ready ticket.</div></li>
  <li><div><strong>Type a clear rejection reason</strong> — e.g. "ONU serial missing", "No photos uploaded", "Customer not home at time of visit."</div></li>
  <li><div><strong>Click Confirm Reject</strong> → ticket goes back to Assigned status. The engineer receives a WhatsApp with your rejection reason.</div></li>
</ol>

<div class="box danger">
  <div class="box-icon">❌</div>
  <div class="box-body">
    <strong>Always give a specific reason</strong>
    Vague rejections like "Not done" waste time. Tell the engineer exactly what to fix so they can return and complete it quickly.
  </div>
</div>

<!-- ═══ 7. MY JOBS ════════════════════════════════════════════════════ -->
<h2 id="my-jobs">7 · My Install Jobs — Your Own Jobs</h2>

<p>Go to <strong>🔧 My Install Jobs</strong> in the sidebar. This shows only the tickets assigned to <em>you personally</em>.</p>

<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;margin:12px 0;">
<table>
  <tr><th>Action</th><th>When to use</th></tr>
  <tr><td>📋 Submit Data</td><td>You have completed the install — enter ONU serial, OLT port, and any notes.</td></tr>
  <tr><td>🔬 Mark Ready</td><td>After submitting data — mark the job ready for your leader (or admin) to commission.</td></tr>
  <tr><td>📞 Call</td><td>Tap to call the customer directly from the ticket.</td></tr>
  <tr><td>View</td><td>See full ticket detail — customer info, address, area, service plan.</td></tr>
</table>
</div><!-- /overflow-x:auto -->

<div class="box tip">
  <div class="box-icon">📋</div>
  <div class="box-body">
    <strong>Submit Data before Mark Ready</strong>
    Always fill in the ONU serial number first. If it's missing, the Commission button will be blocked for whoever reviews your job.
  </div>
</div>

<!-- ═══ 8. LIVE MAP ══════════════════════════════════════════════════ -->
<h2 id="live-map">8 · Live Staff Map</h2>

<p>Go to <strong>📍 Live Staff Map</strong> in the sidebar. You see a real-time map of all field engineers with GPS active.</p>

<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;margin:12px 0;">
<table>
  <tr><th>What you see</th><th>What it means</th></tr>
  <tr><td>Coloured pin with name</td><td>Engineer's current GPS location (updates every few minutes)</td></tr>
  <tr><td>📍 Trail button</td><td>Show the route that engineer has travelled today</td></tr>
  <tr><td>📍 View location link</td><td>Opens Google Maps at that engineer's exact location</td></tr>
  <tr><td>Grey / Offline label</td><td>Engineer's app is closed or GPS is off</td></tr>
</table>
</div><!-- /overflow-x:auto -->

<div class="box info">
  <div class="box-icon">💡</div>
  <div class="box-body">
    <strong>Use the map before assigning</strong>
    Before assigning a ticket, check the map to see which engineer is closest to that area. Saves fuel and gets the job done faster.
  </div>
</div>

<!-- ═══ 9. ROUTE MANAGER ══════════════════════════════════════════════ -->
<h2 id="route">9 · Route Manager</h2>

<p>Go to <strong>🛣 Route Manager</strong>. Use this to plan and assign a sequence of jobs to one engineer in a single action.</p>

<ol class="steps">
  <li><div><strong>Select an engineer</strong> from the dropdown.</div></li>
  <li><div><strong>Add stops</strong> — search for tickets or addresses and add them to the route in order.</div></li>
  <li><div><strong>Reorder stops</strong> — drag to arrange the most efficient path.</div></li>
  <li><div><strong>Click 🚀 Assign Route &amp; Notify Agent via WhatsApp</strong> → engineer receives a WhatsApp message listing all their stops in order with addresses and customer names.</div></li>
</ol>

<div class="box tip">
  <div class="box-icon">🗺️</div>
  <div class="box-body">
    <strong>Best practice</strong>
    Group stops by area (e.g. all Gudele tickets first, then Hai Malakal) to minimise driving time. The route map shows the path between stops.
  </div>
</div>

<!-- ═══ 10. LTE ══════════════════════════════════════════════════════ -->
<h2 id="lte">10 · LTE Network Management</h2>

<h3>LTE Dashboard</h3>
<p>Shows overall health of the private LTE network — active subscribers, bandwidth usage, and any alarms.</p>

<h3>LTE Subscribers</h3>
<p>Full list of all LTE customers. You can search by name, phone, or IMSI. Click a subscriber to see their service status and data usage.</p>

<h3>LTE Renewal Queue</h3>
<p>Subscribers whose plans are expiring soon appear here. You can:</p>
<div class="btn-row">
  <span class="btn-preview green">✅ Renew</span>
  <span class="btn-preview ghost">View</span>
</div>
<p>Renewing from here manually extends the plan if automatic renewal has not processed yet.</p>

<h3>SIM Inventory</h3>
<p>Shows all SIM cards — available stock, assigned SIMs, and which subscriber each one belongs to. Use this to track physical SIM allocation.</p>

<h3>CPE / Hardware</h3>
<p>List of all Customer Premises Equipment (routers, CPE units). Track serial numbers, assignment status, and warranty.</p>

<div class="box warning">
  <div class="box-icon">⚠️</div>
  <div class="box-body">
    <strong>Auto-suspend is running</strong>
    LTE subscribers who are overdue automatically get suspended by the system. You can manually reactivate from the Subscribers list if the payment has been confirmed but suspension hasn't lifted yet.
  </div>
</div>

<!-- ═══ 11. LOOKUP ═══════════════════════════════════════════════════ -->
<h2 id="lookup">11 · Customer Lookup &amp; Service Status</h2>

<h3>🔍 Customer Lookup</h3>
<p>Search any customer by name, phone number, or CRM ID. Shows their service type, balance, account status, and history. Use this when a customer calls with a complaint.</p>

<h3>📶 Service Status Check</h3>
<p>Enter a customer's name or ID to instantly see:</p>
<ul style="margin:10px 0 10px 20px;color:#334155;font-size:14px;line-height:2;">
  <li>Is their service <strong>Active</strong>, <strong>Suspended</strong>, or <strong>Terminated</strong>?</li>
  <li>Last payment date and amount</li>
  <li>Outstanding balance</li>
  <li>Service plan name</li>
</ul>
<p>This is the fastest way to answer "why is my internet not working?" — check service status before dispatching an engineer.</p>

<!-- ═══ 12. DAILY ROUTINE ══════════════════════════════════════════ -->
<h2 id="daily">12 · Daily Routine Checklist</h2>

<div class="qr-grid">
  <div class="qr-card">
    <div class="qr-card-title">🌅 Morning (Start of Day)</div>
    <ul>
      <li>Open NOC Dashboard — check for urgent (red) tickets</li>
      <li>Check unassigned tickets — assign all before 9am</li>
      <li>Review Live Map — confirm engineers are on the road</li>
      <li>Check LTE Renewal Queue — process any overdue renewals</li>
    </ul>
  </div>
  <div class="qr-card">
    <div class="qr-card-title">🌞 Midday (Active Hours)</div>
    <ul>
      <li>Check for new Ready tickets — commission or reject promptly</li>
      <li>Monitor Live Map for engineer locations vs job locations</li>
      <li>Answer any customer calls — use Customer Lookup</li>
      <li>Sync NOC if new KYCs were registered this morning</li>
    </ul>
  </div>
  <div class="qr-card">
    <div class="qr-card-title">🌆 Afternoon</div>
    <ul>
      <li>Follow up on any assigned tickets not yet moved to Ready</li>
      <li>Use Route Manager to plan tomorrow's installs</li>
      <li>Reject any incomplete submissions with clear reasons</li>
      <li>Check LTE Subscribers for new suspensions</li>
    </ul>
  </div>
  <div class="qr-card">
    <div class="qr-card-title">🌙 End of Day</div>
    <ul>
      <li>All Ready tickets should be commissioned or rejected</li>
      <li>No tickets should remain Unassigned overnight</li>
      <li>Check Support Tickets for any unresolved customer issues</li>
      <li>Note any recurring problems for the admin report</li>
    </ul>
  </div>
</div>

<!-- ═══ 13. TIPS ══════════════════════════════════════════════════ -->
<h2 id="tips">13 · Tips &amp; Common Mistakes</h2>

<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;margin:12px 0;">
<table>
  <tr><th>❌ Common mistake</th><th>✅ What to do instead</th></tr>
  <tr><td>Commissioning without checking ONU serial</td><td>Always open the ticket detail first — ONU serial must be filled</td></tr>
  <tr><td>Assigning all jobs to one engineer</td><td>Use Live Map to spread work fairly based on location</td></tr>
  <tr><td>Rejecting with no reason</td><td>Always write exactly what is missing — engineer needs to know what to fix</td></tr>
  <tr><td>Ignoring urgent (red) tickets</td><td>Red tickets mean 48h+ waiting — these are your top priority</td></tr>
  <tr><td>Not syncing before checking NOC</td><td>Hit Sync button after new KYCs are submitted so tickets appear</td></tr>
  <tr><td>Calling customer before checking service status</td><td>Check 📶 Service Status first — 80% of calls are just suspensions</td></tr>
  <tr><td>Using batch assign for different-area jobs</td><td>Batch assign only works well when all tickets are in the same area</td></tr>
</table>
</div><!-- /overflow-x:auto -->

<div class="box tip">
  <div class="box-icon">📞</div>
  <div class="box-body">
    <strong>Customer call script</strong>
    When a customer calls: 1) Get their name → 2) Open Customer Lookup → 3) Check Service Status → 4) If suspended, confirm payment has been made → 5) If payment confirmed but still suspended, manually check LTE Renewal or escalate to admin.
  </div>
</div>

<div class="box info">
  <div class="box-icon">🆘</div>
  <div class="box-body">
    <strong>If something is broken</strong>
    If the NOC dashboard shows errors or sync fails: use the <strong>🔧 Diagnose</strong> button. It runs a 3-step check and shows exactly what is wrong (connection, API, or local data). Screenshot the result and send to admin.
  </div>
</div>

</div><!-- /content -->

<div class="footer">
  <strong>DishNet Africa Ltd</strong> · DishNet Hybrid Telecom Plugin v4.1 · Support Leader Guide · Confidential<br>
  For system issues contact admin · For plugin updates contact the development team
</div>

</body>
</html>
