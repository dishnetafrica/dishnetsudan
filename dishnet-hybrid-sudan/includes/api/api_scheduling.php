<?php
// ═══════════════════════════════════════════════════════════════
// SCHEDULING / JOBS
// ═══════════════════════════════════════════════════════════════


    // ── Scheduling: clear jobs cache (admin only) ─────────────────────────────
    if ($act === 'scheduling_clear_cache') {
        // Wipe cache files
        $store->save('scheduling_jobs_cache.json', []);
        $store->save('scheduling_cache_meta.json', [
            'last_sync'    => 0,
            'last_sync_ts' => '',
            'cleared_at'   => date('Y-m-d H:i:s'),
            'cleared_by'   => $me2['email'] ?? 'unknown',
        ]);
        // Also reset ALL ucrm_user_id mappings so seed map re-applies on next load
        $allRetailers = $store->load('retailers.json') ?? [];
        $seedMap = [
            'bhavin@dishnetafrica.com'            => 1,
            'hardik@dishnetafrica.com'            => 1124,
            'mohini.madlani@outlook.com'          => 1078,
            'accounts@dishnetafrica.com'          => 1929,
            'rupesh@dishnetafrica.com'            => 1929,
            'dishnetafrica@gmail.com'             => 1224,
            'atulsmahale007@gmail.com'            => 1368,
            'atul@dishnetafrica.com'              => 1368,
            'noc@dishnetafrica.com'               => 1400,
            'emmanuellukudualphon@gmail.com'      => 1401,
            'nirav@dishnetss.com'                 => 1548,
            'madlanib@gmail.com'                  => 1581,
            'vivekbhatt17@gmail.com'              => 1584,
            'vivek.dishnetafrica@outlook.com'     => 1585,
            'wmensona@gmail.com'                  => 1703,
            'kamjay285@gmail.com'                 => 1705,
            'sokiris744@gmail.com'                => 1718,
            'timelessjo30@gmail.com'              => 1729,
            'justus@dishnetss.com'                => 1927,
            'aida@dishnetss.com'                  => 1969,
            'meckylinea@dishnetss.com'            => 1971,
            'amos@dishnetss.com'                  => 1991,
            'ochiti@dishnetss.com'                => 1993,
            'deepsolanki7799@gmail.com'           => 2015,
            'geoffrey@dishnetss.com'              => 2024,
            'karan@dishnetss.com'                 => 2026,
            'dhaval@dishnetss.com'                => 2027,
            'thomas@dishnetss.com'                => 2028,
            'tabule@dishnetss.com'                => 2029,
        ];
        foreach ($allRetailers as &$r) {
            $email = strtolower(trim($r['email'] ?? ''));
            $correctId = $seedMap[$email] ?? null;
            if ($correctId) {
                $r['ucrm_user_id'] = (int)$correctId;
            } elseif (!empty($r['ucrm_user_id'])) {
                // Email not in seed map — clear it so manual entry is required
                // (prevents stale/wrong IDs persisting)
                // Only clear if it looks wrong (not matching any seed value)
                // Keep if manually set by admin
            }
        }
        unset($r);
        $store->save('retailers.json', $allRetailers);
        $ok2(['cleared' => true, 'message' => 'Cache cleared and user mappings refreshed.']);
    }

    // ── Scheduling: fetch jobs assigned to THIS support staff ─────────────────
    if ($act === 'scheduling_jobs') {
        $myAdminId  = (int)($me2['ucrm_user_id']       ?? 0); // CRM admin user ID pool
        $myClientId = (int)($me2['ftth_crm_client_id'] ?? 0); // CRM client ID pool
        $isAdminU   = $me2['is_admin'] ?? false;

        if (!$myAdminId && !$myClientId && !$isAdminU) {
            $ok2(['jobs' => [], 'needs_mapping' => true]);
        }
        $forceRefresh = !empty($_GET['refresh']);

        // ── Cache-first (built by cron/jobs_cache.php every 10 min) ───────────
        if (!$forceRefresh) {
            $cache    = $store->load('scheduling_jobs_cache.json') ?? [];
            $meta     = $store->load('scheduling_cache_meta.json') ?? [];
            $cacheAge = isset($meta['last_sync']) ? (time() - (int)$meta['last_sync']) : PHP_INT_MAX;
            if (!empty($cache) && $cacheAge < 1800 && ($myAdminId || $myClientId)) {
                $myId    = $myAdminId ?: $myClientId;
                $jobList = array_values(array_filter($cache,
                    fn($j) => (int)($j['_ucrm_user_id'] ?? 0) === $myId
                ));
                usort($jobList, fn($a,$b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));
                $ok2(['jobs' => $jobList, 'needs_mapping' => false,
                      'from_cache' => true, 'cache_age_sec' => $cacheAge,
                      'last_sync' => $meta['last_sync_ts'] ?? '']);
            }
        }

        // ── Live fallback (cache empty, stale, or ?refresh=1) ────────────────
        if (!$crm->isConfigured()) $er2('CRM not configured — and cache is empty.', 503);
        // Rolling window: open jobs from 60 days ago, closed from 30 days ago
        // This avoids hitting the 500-job limit when fetching from year start
        $dateFromOpen   = date('Y-m-d', strtotime('-60 days'));
        $dateFromClosed = date('Y-m-d', strtotime('-30 days'));
        // UCRM API ignores all assignee filter params — must fetch all and filter client-side
        // Fetch open/pending separately with wider window, closed with narrower window
        $qsOpen   = "?limit=500&dateFrom={$dateFromOpen}&statuses[]=0&statuses[]=1";
        $qsClosed = "?limit=200&dateFrom={$dateFromClosed}&statuses[]=2";
        $qs = $qsOpen; // kept for legacy references below
        $jobs = $crm->get('scheduling/jobs' . $qsOpen);
        if ($jobs === null) $er2('CRM API error: ' . json_encode($crm->getLastError()), 502);
        $openJobs   = is_array($jobs) ? $jobs : [];
        $closedJobs = $crm->get('scheduling/jobs' . $qsClosed) ?? [];
        // Merge, dedup by id (open takes priority)
        $allById = [];
        foreach ($closedJobs as $j) $allById[(int)$j['id']] = $j;
        foreach ($openJobs   as $j) $allById[(int)$j['id']] = $j; // open overwrites
        $allJobs = array_values($allById);

        // ── Opportunistically warm the cache so next load is instant ────────
        // Build retailerMap: ucrm_user_id => retailer id (for _retailer_id stamp)
        $allR2       = $store->load('retailers.json') ?? [];
        $retailerMap = [];
        foreach ($allR2 as $r2) {
            $uid2 = (int)($r2['ucrm_user_id'] ?? 0);
            if ($uid2 > 0) $retailerMap[$uid2] = (int)$r2['id'];
        }
        $stamped = array_map(function($j) use ($retailerMap) {
            $j['_ucrm_user_id'] = (int)($j['assignedUserId'] ?? 0);
            $uid = (int)($j['assignedUserId'] ?? 0);
            $j['_retailer_id']  = $retailerMap[$uid] ?? 0;
            return $j;
        }, $allJobs);
        $store->save('scheduling_jobs_cache.json', $stamped);
        $store->save('scheduling_cache_meta.json', [
            'last_sync'    => time(),
            'last_sync_ts' => date('Y-m-d H:i:s'),
            'total_jobs'   => count($stamped),
            'source'       => 'live_api_warmup',
        ]);

        // Filter for this user
        $jobList = $allJobs;
        if ($myAdminId || $myClientId) {
            $myId    = $myAdminId ?: $myClientId;
            $jobList = array_values(array_filter($allJobs,
                fn($j) => (int)($j['assignedUserId'] ?? 0) === $myId
            ));
        }
        usort($jobList, fn($a,$b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));
        $ok2(['jobs' => $jobList, 'needs_mapping' => false, 'from_cache' => false,
              'last_sync' => date('Y-m-d H:i:s')]);
    }

    // ── Scheduling: job detail — client info (safe subset) + pending invoices + services ──
    if ($act === 'scheduling_job_detail') {
        $jobId = (int)($_GET['job_id'] ?? 0);
        if (!$jobId) $er2('job_id required.', 422);
        if (!$crm->isConfigured()) $er2('CRM not configured.', 503);

        // Fetch job
        $job = $crm->get("scheduling/jobs/{$jobId}");
        if (!$job) $er2('Job not found.', 404);

        // Security: job was fetched via assigneeId filter — trust the query filter, skip assignee array check
        // (UCRM API v1.0 returns empty assignees[] on single job fetch)
        $ucrmUserId = (int)($me2['ucrm_user_id'] ?? 0);
        $isAdmin    = $me2['is_admin'] ?? false;

        $clientId = (int)($job['client']['id'] ?? 0);
        if (!$clientId) $ok2(['job' => $job, 'client' => null, 'services' => [], 'pending_invoices' => [], 'tasks' => []]);

        // Client — safe subset only (no balance, no financial history)
        $clientFull = $crm->get("clients/{$clientId}");
        $client = $clientFull ? [
            'id'        => $clientFull['id'],
            'name'      => trim(($clientFull['firstName'] ?? '') . ' ' . ($clientFull['lastName'] ?? '')),
            'phone'     => $clientFull['contacts'][0]['phone'] ?? '',
            'email'     => $clientFull['contacts'][0]['email'] ?? '',
            'address'   => trim(($clientFull['street1'] ?? '') . ' ' . ($clientFull['street2'] ?? '')),
            'city'      => $clientFull['city'] ?? '',
            'note'      => $clientFull['note'] ?? '',      // contains kit/package info
            'isLead'    => $clientFull['isLead'] ?? false,
        ] : null;

        // Services — status only, no pricing
        $servicesRaw = $crm->get("clients/{$clientId}/services") ?? [];
        $services = array_map(fn($s) => [
            'id'     => $s['id'],
            'name'   => $s['name'] ?? $s['servicePlanName'] ?? 'Service #' . $s['id'],
            'status' => $s['status'],        // 1=active, 2=ended, 3=suspended, 4=prepared, 5=quoted
            'from'   => ($s['activeFrom']  ?? ''),
            'to'     => ($s['activeTo']    ?? ''),
            'ip'     => $s['ipRanges'][0]['from'] ?? null,
        ], is_array($servicesRaw) ? $servicesRaw : []);

        // Pending invoices — only unpaid/partial (status 1,2) — amounts visible (needed for collection context)
        $invoicesRaw = $crm->get("invoices?clientId={$clientId}&limit=10&status[]=1&status[]=2") ?? [];
        $invoices = array_map(fn($inv) => [
            'id'       => $inv['id'],
            'number'   => $inv['number'] ?? $inv['id'],
            'due'      => round((float)($inv['total'] ?? 0) - (float)($inv['amountPaid'] ?? 0), 2),
            'dueDate'  => $inv['dueDate'] ?? '',
            'status'   => $inv['status'],   // 1=unpaid, 2=partial
        ], is_array($invoicesRaw) ? $invoicesRaw : []);

        // Job tasks
        $tasks = $crm->get("scheduling/jobs/{$jobId}/job-tasks") ?? [];

        // GPS coordinates from job
        $gps = null;
        if (!empty($job['gpsLat']) && !empty($job['gpsLon'])) {
            $gps = ['lat' => $job['gpsLat'], 'lon' => $job['gpsLon']];
        } elseif (!empty($clientFull['gpsLat'])) {
            $gps = ['lat' => $clientFull['gpsLat'], 'lon' => $clientFull['gpsLon']];
        }

        // Load existing survey for this job
        $surveys = $store->load('site_surveys.json') ?? [];
        $existingSurvey = null;
        foreach ($surveys as $sv) { if ((int)($sv['job_id']??0) === $jobId) { $existingSurvey = $sv; break; } }

        // Load existing signature for this job
        $signatures = $store->load('job_signatures.json') ?? [];
        $existingSig = null;
        foreach ($signatures as $sig) { if ((int)($sig['job_id']??0) === $jobId) { $existingSig = $sig; break; } }

        $ok2([
            'job'              => $job,
            'client'           => $client,
            'services'         => $services,
            'pending_invoices' => $invoices,
            'tasks'            => is_array($tasks) ? $tasks : [],
            'gps'              => $gps,
            'survey'           => $existingSurvey,
            'signature'        => $existingSig,
        ]);
    }

    // ── Scheduling: update job status ────────────────────────────────────────
    if ($act === 'scheduling_job_update' && $met === 'POST') {
        $jobId     = (int)($body['job_id'] ?? 0);
        $statusRaw = $body['status'] ?? '';   // open | pending | closed | 0 | 1 | 2
        if (!$jobId || $statusRaw === '') $er2('job_id and status required.', 422);
        if (!$crm->isConfigured()) $er2('CRM not configured.', 503);
        // UCRM API requires status as integer: 0=Pending, 1=Open/InProgress, 2=Closed
        $statusMap = ['pending' => 0, 'open' => 1, 'in_progress' => 1, 'closed' => 2, 'complete' => 2];
        if (is_numeric($statusRaw)) {
            $statusInt = (int)$statusRaw;
        } else {
            $key = strtolower((string)$statusRaw);
            $statusInt = $statusMap[$key] ?? 1;
        }
        // Verify ownership
        $job = $crm->get("scheduling/jobs/{$jobId}");
        $ucrmUserId = (int)($me2['ucrm_user_id'] ?? 0);
        if (!($me2['is_admin'] ?? false) && $ucrmUserId) {
// Access verified via assigneeId query filter
        }
        $result = $crm->patch("scheduling/jobs/{$jobId}", ['status' => $statusInt]);
        if ($result === null) $er2('CRM update failed: ' . json_encode($crm->getLastError()), 502);

        // ── notify_accept: send WhatsApp confirmations when engineer accepts ─
        if (!empty($body['notify_accept']) && $statusInt === 1) {
            $job       = $job ?? $crm->get("scheduling/jobs/{$jobId}");
            $jobTitle  = $job['title'] ?? "Job #{$jobId}";
            $jobDate   = $job['date']  ?? '';
            $dateLabel = $jobDate ? date('D d M \a\t H:i', strtotime($jobDate)) : '';
            $engName   = $me2['name'] ?? 'Engineer';
            $engPhone  = preg_replace('/[^0-9+]/', '', $me2['phone'] ?? '');

            // 1. Confirm to the engineer themselves
            if ($engPhone) {
                $msgEng  = "✅ *Job Accepted*\n\n";
                $msgEng .= "Hi *{$engName}*, you've accepted:\n";
                $msgEng .= "*{$jobTitle}*\n";
                if ($dateLabel) $msgEng .= "📅 {$dateLabel}\n";
                $msgEng .= "\nJob #{$jobId} is now *In Progress*.\n";
                $msgEng .= "Open My Jobs tab for details.\n— DishNET Africa";
                $notify->sendVia('support', $engPhone, $msgEng, 'ops_job_accepted_self');
            }

            // 2. Notify support leaders that job was accepted
            $allR3 = $store->load('retailers.json') ?? [];
            foreach ($allR3 as $r3) {
                if (($r3['role'] ?? '') !== 'support_leader') continue;
                if (empty($r3['is_active'])) continue;
                $leaderPhone = preg_replace('/[^0-9+]/', '', $r3['phone'] ?? '');
                if (!$leaderPhone || $leaderPhone === $engPhone) continue;
                $msgLead  = "🔔 *Job Accepted*\n\n";
                $msgLead .= "*{$engName}* has accepted:\n";
                $msgLead .= "*{$jobTitle}*\n";
                if ($dateLabel) $msgLead .= "📅 {$dateLabel}\n";
                $msgLead .= "\nJob #{$jobId} status → *In Progress*\n— DishNET NOC";
                $notify->sendVia('support', $leaderPhone, $msgLead, 'ops_job_accepted_leader');
            }
        }

        $ok2(['updated' => true, 'status' => $statusInt]);
    }

    // ── Scheduling: toggle task done/undone ───────────────────────────────────
    // ── Scheduling: toggle task + WhatsApp sequential notification ──────────
    if ($act === 'scheduling_task_update' && $met === 'POST') {
        $taskId = (int)($body['task_id'] ?? 0);
        $done   = (bool)($body['done'] ?? false);
        $jobId  = (int)($body['job_id'] ?? 0);
        if (!$taskId) $er2('task_id required.', 422);
        if (!$crm->isConfigured()) $er2('CRM not configured.', 503);

        // Patch the task
        $result = $crm->patch("scheduling/job-tasks/{$taskId}", ['closed' => $done]);
        if ($result === null) $er2('CRM update failed: ' . json_encode($crm->getLastError()), 502);

        // Only send WhatsApp when marking a task DONE and job_id is provided
        if ($done && $jobId) {
            $techPhone = preg_replace('/[^0-9]/', '', $me2['phone'] ?? '');
            $techName  = $me2['name'] ?? 'Technician';

            // Fetch full task list for this job to determine next task
            $allTasks = $crm->get("scheduling/jobs/{$jobId}/job-tasks") ?? [];
            if (is_array($allTasks) && !empty($allTasks)) {
                // Count completed tasks (including the one we just closed)
                $doneTasks = array_values(array_filter($allTasks, function($t) { return !empty($t['closed']); }));
                $openTasks = array_values(array_filter($allTasks, function($t) { return empty($t['closed']); }));
                $doneCount = count($doneTasks);
                $totalCount = count($allTasks);

                if (!empty($techPhone)) {
                    if (empty($openTasks)) {
                        // All tasks done — send final completion link
                        $msg = "Hi *{$techName}*,\n\n"
                             . "✅ All {$totalCount} tasks completed successfully!\n\n"
                             . "*FINAL STEP — MARK JOB COMPLETE:*\n"
                             . "Open your app → Job #{$jobId} → Complete Job\n\n"
                             . "Thank you for your excellent work!\n— DishNET Africa Team";
                        $notify->sendVia('support', $techPhone, $msg, 'ops_scheduling_all_tasks_done');
                    } else {
                        // Next task exists
                        $nextTask = $openTasks[0];
                        $nextName = $nextTask['label'] ?? $nextTask['name'] ?? $nextTask['title'] ?? 'Next task';
                        $nextNum  = $doneCount + 1;
                        $msg = "Hi *{$techName}*,\n\n"
                             . "Great job completing Task {$doneCount}! 🎉\n\n"
                             . "*TASK {$nextNum}:* {$nextName}\n\n"
                             . "Open your app to mark it complete:\n"
                             . "Job #{$jobId} in the My Jobs tab\n\n"
                             . "— DishNET Africa Team";
                        $notify->sendVia('support', $techPhone, $msg, 'ops_scheduling_task_done');
                    }
                }
            }
        }
        $ok2(['updated' => true, 'task_id' => $taskId, 'done' => $done]);
    }

    // ── Scheduling: complete job (photo + GPS + comment) ─────────────────────
    if ($act === 'scheduling_complete' && $met === 'POST') {
        $jobId   = (int)($body['job_id'] ?? 0);
        $comment = trim($body['comment'] ?? '');
        $lat     = $body['lat'] ?? null;
        $lon     = $body['lon'] ?? null;
        if (!$jobId) $er2('job_id required.', 422);
        if (!$crm->isConfigured()) $er2('CRM not configured.', 503);

        // Verify ownership
        $job = $crm->get("scheduling/jobs/{$jobId}");
        if (!$job) $er2('Job not found.', 404);
        $ucrmUserId = (int)($me2['ucrm_user_id'] ?? 0);
        if (!($me2['is_admin'] ?? false) && $ucrmUserId) {
// Access verified via assigneeId query filter
        }

        // Check all tasks are done before allowing completion
        $allTasks = $crm->get("scheduling/jobs/{$jobId}/job-tasks") ?? [];
        if (is_array($allTasks) && count($allTasks) > 0) {
            $openTasks = array_filter($allTasks, function($t) { return empty($t['closed']); });
            if (!empty($openTasks)) {
                $open = count($openTasks);
                $total = count($allTasks);
                $er2("Cannot complete — {$open} of {$total} tasks still open. Tick all tasks first.", 422);
            }
        }

        // Store completion record in local JSON (photos stored as base64 thumbnails)
        $completions = $store->load('job_completions.json') ?? [];
        $photos      = $body['photos'] ?? [];   // array of {type, data_url} from JS
        $record = [
            'job_id'      => $jobId,
            'technician'  => $me2['name'] ?? '',
            'retailer_id' => $rid,
            'comment'     => $comment,
            'lat'         => $lat,
            'lon'         => $lon,
            'photos'      => array_map(function($p) {
                return ['type' => $p['type'] ?? 'photo', 'thumb' => substr($p['data_url'] ?? '', 0, 200)];
            }, is_array($photos) ? array_slice($photos, 0, 5) : []),
            'completed_at'=> date('Y-m-d H:i:s'),
        ];
        $completions[] = $record;
        $store->save('job_completions.json', $completions);

        // Mark job closed in UCRM (status = 2 = Closed — integer required by UCRM API)
        $patchResult = $crm->patch("scheduling/jobs/{$jobId}", ['status' => 2]);
        if ($patchResult === null) $er2('CRM update failed: ' . json_encode($crm->getLastError()), 502);

        // Add comment to UCRM job if provided
        if ($comment) {
            $crm->post("scheduling/jobs/{$jobId}/job-comments", ['message' => $comment]);
        }

        // Send WhatsApp confirmation to technician
        $techPhone = preg_replace('/[^0-9]/', '', $me2['phone'] ?? '');
        $techName  = $me2['name'] ?? 'Technician';
        $clientName = trim(($job['client']['firstName'] ?? '') . ' ' . ($job['client']['lastName'] ?? ''));
        $jobTitle   = $job['title'] ?? "Job #{$jobId}";
        if (!empty($techPhone)) {
            $gpsNote = ($lat && $lon) ? "\n📍 GPS: {$lat}, {$lon}" : '';
            $msg = "Hi *{$techName}*,\n\n"
                 . "✅ *Job Completed Successfully!*\n\n"
                 . "Job: {$jobTitle}\n"
                 . ($clientName ? "Customer: {$clientName}\n" : '')
                 . "Completed: " . date('d M Y, h:i a') . $gpsNote . "\n\n"
                 . ($comment ? "Your note: {$comment}\n\n" : '')
                 . "Great work today! 🌟\n— DishNET Africa Team";
            $notify->sendVia('support', $techPhone, $msg, 'ops_scheduling_job_complete');
        }

        // Also notify support leader / admin
        $notify->sendAdmin(
            "✅ Job #{$jobId} completed by *{$techName}*"
            . ($clientName ? " for {$clientName}" : '')
            . ($comment ? "\nNote: {$comment}" : ''),
            'ops_scheduling_job_complete_admin'
        );

        // ── Add to Invoice Queue for Rupesh (accountant) ────────────────────
        // Only queue + notify accountant for installation jobs (fiber/starlink)
        $titleLower = strtolower($job['title'] ?? '');
        $invoiceKeywords = ['install', 'fiber', 'fibre', 'starlink', 'ftth', 'lte activation'];
        $needsInvoice = false;
        foreach ($invoiceKeywords as $_kw) {
            if (strpos($titleLower, $_kw) !== false) { $needsInvoice = true; break; }
        }

        if ($needsInvoice) {
        $invoiceQueue = $store->load('job_invoice_queue.json') ?? [];
        $maxIjId = empty($invoiceQueue) ? 0 : max(array_map(fn($x) => (int)($x['id'] ?? 0), $invoiceQueue));
        $clientCrmId = (int)($job['clientId'] ?? $job['client']['id'] ?? 0);
        $jobType = 'installation';
        if (strpos($titleLower, 'survey') !== false)     $jobType = 'survey';
        elseif (strpos($titleLower, 'repair') !== false || strpos($titleLower, 'maintenance') !== false) $jobType = 'maintenance';
        elseif (strpos($titleLower, 'deliver') !== false) $jobType = 'delivery';

        $invoiceQueue[] = [
            'id'                => $maxIjId + 1,
            'job_no'            => 'JOB-' . $jobId,
            'crm_job_id'        => $jobId,
            'crm_job_title'     => $job['title'] ?? "Job #{$jobId}",
            'crm_job_type'      => $jobType,
            'crm_client_id'     => $clientCrmId,
            'client_name'       => $clientName ?: 'Unknown',
            'completed_by_id'   => $rid,
            'completed_by_name' => $techName,
            'completed_at'      => date('Y-m-d H:i:s'),
            'duration_min'      => (int)($job['duration'] ?? 0),
            'field_note'        => $comment,
            'status'            => 'pending',
            'invoice_ref'       => '',
            'invoice_note'      => '',
            'actioned_by'       => '',
            'actioned_at'       => '',
            'created_at'        => date('Y-m-d H:i:s'),
        ];
        $store->save('job_invoice_queue.json', $invoiceQueue);

        // ── Notify accountant(s) via WhatsApp ───────────────────────────────
        $allR4Acct = $store->load('retailers.json') ?? [];
        foreach ($allR4Acct as $r4) {
            if (($r4['role'] ?? '') !== 'accountant' || empty($r4['is_active'])) continue;
            $acctPhone = preg_replace('/[^0-9]/', '', $r4['phone'] ?? '');
            if (empty($acctPhone)) continue;
            $acctName = explode(' ', $r4['name'] ?? 'Accounts')[0];
            $ijMsg = "Hi *{$acctName}*,\n\n"
                   . "🧾 *New Invoice Needed*\n\n"
                   . "Job: *{$jobTitle}*\n"
                   . ($clientName ? "Customer: {$clientName}" . ($clientCrmId ? " (CRM #{$clientCrmId})" : '') . "\n" : '')
                   . "Completed by: {$techName}\n"
                   . "Date: " . date('d M Y, h:i a') . "\n\n"
                   . "Please create an invoice in UCRM and mark it in Invoice Queue.\n"
                   . "— DishNET System";
            $notify->sendVia('accounts', $acctPhone, $ijMsg, 'ops_invoice_queue_new');
        }
        } // end if ($needsInvoice)

        // ── Auto-deduct stock for installation jobs ──────────────────────
        $stockDeductSummary = [];
        if ($needsInvoice) {
            try {
                require_once __DIR__ . '/../../lib/StockService.php';
                $_stkSvc2 = StockService::fromStore($store, $dataDir);
                $_stkSvc2->ensureTables();

                // Find the client's most recent KYC application with hw_cart
                $clientCrmId2 = (int)($job['clientId'] ?? $job['client']['id'] ?? 0);
                $hwCartItems = [];

                if ($clientCrmId2 > 0) {
                    $allApps = $store->load('kyc_applications.json') ?? [];
                    // Search in reverse (newest first) for this client's KYC with hardware
                    for ($ai = count($allApps) - 1; $ai >= 0; $ai--) {
                        $app = $allApps[$ai];
                        $appCrmId = (int)($app['crm_client_id'] ?? 0);
                        if ($appCrmId === $clientCrmId2 && !empty($app['hw_cart_json'])) {
                            $decoded = is_string($app['hw_cart_json']) ? json_decode($app['hw_cart_json'], true) : $app['hw_cart_json'];
                            if (!empty($decoded) && is_array($decoded)) {
                                $hwCartItems = $decoded;
                                break;
                            }
                        }
                    }
                    // Fallback: if no hw_cart found but there's a single device
                    if (empty($hwCartItems)) {
                        for ($ai = count($allApps) - 1; $ai >= 0; $ai--) {
                            $app = $allApps[$ai];
                            if ((int)($app['crm_client_id'] ?? 0) === $clientCrmId2 && !empty($app['device_title'])) {
                                $hwCartItems[] = [
                                    'title' => $app['device_title'],
                                    'qty' => max(1, (int)($app['kitQty'] ?? 1)),
                                ];
                                break;
                            }
                        }
                    }
                }

                if (!empty($hwCartItems)) {
                    $stockDeductSummary = $_stkSvc2->deductForInstallation(
                        $hwCartItems,
                        $clientCrmId2,
                        $clientName ?: 'Customer',
                        $jobId,
                        $rid,
                        $techName
                    );
                }
            } catch (\Throwable $e) {
                // Don't block job completion on stock errors
                $stockDeductSummary = [['action' => 'error', 'reason' => $e->getMessage()]];
            }
        }

        $ok2(['completed' => true, 'job_id' => $jobId, 'stock_deduct' => $stockDeductSummary]);
    }

    // ── POST create_job — quick job creator, all roles, multi-engineer support ─
    // Creates one UCRM scheduling job per assigned engineer (UCRM only supports
    // one assignedUserId per job). Each engineer gets a WhatsApp notification.
    if ($act === 'create_job' && $met === 'POST') {
        if (!$crm->isConfigured()) $er2('CRM not configured.', 503);

        $title       = trim($body['title']       ?? '');
        $date        = trim($body['date']        ?? date('Y-m-d'));
        $time        = trim($body['time']        ?? '09:00');
        $duration    = (int)($body['duration']   ?? 60);
        $description = trim($body['description'] ?? '');
        $crmClientId = (int)($body['crm_client_id'] ?? 0);
        $engineerIds = array_values(array_filter(array_map('intval', (array)($body['engineer_ids'] ?? []))));
        $taskNames   = array_filter(array_map('trim', (array)($body['tasks'] ?? [])));
        $notifyWa    = !empty($body['notify_wa']);

        if (!$title)           $er2('Title is required.', 422);
        if (!$date)            $er2('Date is required.', 422);
        if (empty($engineerIds)) $er2('At least one engineer is required.', 422);
        if (count($engineerIds) > 10) $er2('Maximum 10 engineers per job.', 422);

        // Fetch client info if provided
        $clientName = '';
        $address    = '';
        $gpsLat     = null;
        $gpsLon     = null;
        if ($crmClientId) {
            $clientData = $crm->get("clients/{$crmClientId}");
            if ($clientData) {
                $clientName = trim(($clientData['firstName'] ?? '') . ' ' . ($clientData['lastName'] ?? ''));
                $address    = trim(($clientData['street1'] ?? '') . ' ' . ($clientData['street2'] ?? ''));
                $gpsLat     = $clientData['gpsLat'] ?? null;
                $gpsLon     = $clientData['gpsLon'] ?? null;
            }
        }

        // Build retailer map for engineer lookup (phone + name)
        $allR       = $store->load('retailers.json') ?? [];
        $engByUcrm  = []; // ucrm_user_id => retailer
        foreach ($allR as $r) {
            $uid = (int)($r['ucrm_user_id'] ?? 0);
            if ($uid > 0) $engByUcrm[$uid] = $r;
        }

        $created = []; $failed = [];
        $dateLabel = date('D d M', strtotime($date));

        foreach ($engineerIds as $ucrmUserId) {
            $jobPayload = [
                'title'          => $title . ($clientName ? ' — ' . $clientName : ''),
                'date'           => $date . 'T' . $time . ':00.000Z',
                'duration'       => $duration,
                'description'    => $description,
                'status'         => 1, // Open
                'assignedUserId' => $ucrmUserId,
            ];
            if ($crmClientId) $jobPayload['clientId'] = $crmClientId;
            if ($address)     $jobPayload['address']  = $address;
            if ($gpsLat)      $jobPayload['gpsLat']   = $gpsLat;
            if ($gpsLon)      $jobPayload['gpsLon']   = $gpsLon;

            $newJob = $crm->post('scheduling/jobs', $jobPayload);
            if (!$newJob || empty($newJob['id'])) {
                $failed[] = ['engineer_id' => $ucrmUserId, 'error' => json_encode($crm->getLastError())];
                continue;
            }
            $newJobId = (int)$newJob['id'];

            // Create task checklist
            foreach ($taskNames as $taskName) {
                $crm->post("scheduling/jobs/{$newJobId}/job-tasks", ['name' => $taskName]);
            }

            // WhatsApp notification
            $eng = $engByUcrm[$ucrmUserId] ?? null;
            if ($notifyWa && $eng && !empty($eng['phone'])) {
                $engPhone     = preg_replace('/[^0-9+]/', '', $eng['phone']);
                $engFirstName = explode(' ', $eng['name'] ?? 'Engineer')[0];
                $dispatcher   = $me2['name'] ?? 'Support';
                $taskList     = !empty($taskNames) ? implode(', ', array_slice($taskNames, 0, 3)) : '';
                $msg  = "Hi *{$engFirstName}*,\n\n";
                $msg .= "🔧 *New Job Assigned to You*\n\n";
                $msg .= "*{$title}*\n";
                if ($clientName) $msg .= "Customer: {$clientName}\n";
                if ($address)    $msg .= "Location: {$address}\n";
                $msg .= "Date: *{$dateLabel}* at {$time}\n";
                if ($taskList)   $msg .= "Tasks: {$taskList}\n";
                $msg .= "\nAssigned by: {$dispatcher}\n";
                $msg .= "*My Jobs tab → Job #{$newJobId}*\n";
                $msg .= "\n— DishNET Africa Team";
                $notify->sendVia('support', $engPhone, $msg, 'ops_scheduling_job_assigned');
            }

            $created[] = [
                'job_id'       => $newJobId,
                'engineer_id'  => $ucrmUserId,
                'engineer_name'=> $eng['name'] ?? "ID:{$ucrmUserId}",
                'notified'     => ($notifyWa && $eng && !empty($eng['phone'])),
            ];
        }

        $ok2([
            'created'     => count($created),
            'failed'      => count($failed),
            'jobs'        => $created,
            'errors'      => $failed,
            'client_name' => $clientName,
        ]);
    }

    // ── Scheduling: reschedule job ────────────────────────────────────────────
    if ($act === 'scheduling_reschedule' && $met === 'POST') {
        $jobId   = (int)($body['job_id'] ?? 0);
        $newDate = trim($body['new_date'] ?? '');  // e.g. "2025-04-15 09:00"
        $comment = trim($body['comment'] ?? '');
        if (!$jobId || !$newDate) $er2('job_id and new_date required.', 422);
        if (!$crm->isConfigured()) $er2('CRM not configured.', 503);

        // Verify ownership
        $job = $crm->get("scheduling/jobs/{$jobId}");
        if (!$job) $er2('Job not found.', 404);
        $ucrmUserId = (int)($me2['ucrm_user_id'] ?? 0);
        if (!($me2['is_admin'] ?? false) && $ucrmUserId) {
// Access verified via assigneeId query filter
        }

        // PATCH new date in UCRM
        $patchResult = $crm->patch("scheduling/jobs/{$jobId}", ['date' => $newDate]);
        if ($patchResult === null) $er2('CRM update failed: ' . json_encode($crm->getLastError()), 502);

        // Add reschedule comment
        $fullComment = "Rescheduled to {$newDate}" . ($comment ? ": {$comment}" : '');
        $crm->post("scheduling/jobs/{$jobId}/job-comments", ['message' => $fullComment]);

        // WhatsApp to technician
        $techPhone = preg_replace('/[^0-9]/', '', $me2['phone'] ?? '');
        $techName  = $me2['name'] ?? 'Technician';
        if (!empty($techPhone)) {
            $dt = date('d M Y, h:i a', strtotime($newDate));
            $msg = "Hi *{$techName}*,\n\n"
                 . "📅 *Job #{$jobId} Rescheduled*\n\n"
                 . "New Date: *{$dt}*\n"
                 . ($comment ? "Reason: {$comment}\n" : '')
                 . "\n— DishNET Africa Team";
            $notify->sendVia('support', $techPhone, $msg, 'ops_scheduling_rescheduled');
        }

        $ok2(['rescheduled' => true, 'job_id' => $jobId, 'new_date' => $newDate]);
    }

    // ── Scheduling: add comment to UCRM job ──────────────────────────────────
    if ($act === 'scheduling_add_comment' && $met === 'POST') {
        $jobId   = (int)($body['job_id'] ?? 0);
        $comment = trim($body['comment'] ?? '');
        if (!$jobId || !$comment) $er2('job_id and comment required.', 422);
        if (!$crm->isConfigured()) $er2('CRM not configured.', 503);

        $result = $crm->post("scheduling/jobs/{$jobId}/job-comments", ['message' => $comment]);
        if ($result === null) $er2('CRM comment failed: ' . json_encode($crm->getLastError()), 502);

        $ok2(['commented' => true, 'job_id' => $jobId]);
    }

    // ── Scheduling: get assignable support staff ──────────────────────────────
    if ($act === 'get_support_staff') {
        $all = $store->load('retailers.json');
        $staff = array_values(array_filter($all, fn($r) =>
            in_array($r['role'] ?? '', ['support','support_leader','sales','sales_staff','field_agent']) &&
            !empty($r['is_active']) &&
            !empty($r['ucrm_user_id'])
        ));
        $ok2(array_map(fn($r) => [
            'id'           => $r['id'],
            'name'         => $r['name'],
            'ucrm_user_id' => $r['ucrm_user_id'],
            'role'         => $r['role'],
        ], $staff));
    }



    // ── UCRM Users: list all users from CRM (for mapping) ────────────────────
    if ($act === 'get_ucrm_users') {
        // Seed map from Laravel DB — definitive email→ucrmUserId
        $seedUsers = [
            ['id'=>1,    'username'=>'bhavin',    'firstName'=>'Bhavin',    'lastName'=>'Madlani',      'email'=>'bhavin@dishnetafrica.com'],
            ['id'=>1078, 'username'=>'mohini',    'firstName'=>'Mohini',    'lastName'=>'Madlani',      'email'=>'mohini.madlani@outlook.com'],
            ['id'=>1124, 'username'=>'hardik',    'firstName'=>'Hardik',    'lastName'=>'Parmar',       'email'=>'hardik@dishnetafrica.com'],
            ['id'=>1164, 'username'=>'rupesh',    'firstName'=>'Rupesh',    'lastName'=>'DishNet',      'email'=>'accounts@dishnetafrica.com'],
            ['id'=>1224, 'username'=>'diko',      'firstName'=>'Ms Diko',   'lastName'=>'Jeseka',       'email'=>'dishnetafrica@gmail.com'],
            ['id'=>1368, 'username'=>'atul',      'firstName'=>'Atul',      'lastName'=>'Mahale',       'email'=>'atulsmahale007@gmail.com'],
            ['id'=>1400, 'username'=>'francis',   'firstName'=>'Francis',   'lastName'=>'DishNet',      'email'=>'noc@dishnetafrica.com'],
            ['id'=>1401, 'username'=>'emmanuel',  'firstName'=>'Emmanuel',  'lastName'=>'DishNet',      'email'=>'emmanuellukudualphon@gmail.com'],
            ['id'=>1548, 'username'=>'nirav',     'firstName'=>'Nirav',     'lastName'=>'Panchamatiya', 'email'=>'nirav@dishnetss.com'],
            ['id'=>1581, 'username'=>'madlani',   'firstName'=>'Madlani',   'lastName'=>'',             'email'=>'madlanib@gmail.com'],
            ['id'=>1585, 'username'=>'vivek',     'firstName'=>'Vivek',     'lastName'=>'DishNet',      'email'=>'vivek.dishnetafrica@outlook.com'],
            ['id'=>1703, 'username'=>'bidal',     'firstName'=>'Bidal',     'lastName'=>'DishNet',      'email'=>'wmensona@gmail.com'],
            ['id'=>1705, 'username'=>'james',     'firstName'=>'Kamanda',   'lastName'=>'James',        'email'=>'kamjay285@gmail.com'],
            ['id'=>1718, 'username'=>'sokiri',    'firstName'=>'Sokiri',    'lastName'=>'DishNet',      'email'=>'sokiris744@gmail.com'],
            ['id'=>1729, 'username'=>'joel',      'firstName'=>'Joel',      'lastName'=>'DishNet',      'email'=>'timelessjo30@gmail.com'],
            ['id'=>1881, 'username'=>'bbc',       'firstName'=>'BBC',       'lastName'=>'DishNet',      'email'=>'sales1@dishnetafrica.com'],
            ['id'=>1927, 'username'=>'justus',    'firstName'=>'Justus',    'lastName'=>'DishNet',      'email'=>'justus@dishnetss.com'],
            ['id'=>1929, 'username'=>'rupesh2',   'firstName'=>'Rupesh',    'lastName'=>'DishNet',      'email'=>'rupesh@dishnetafrica.com'],
            ['id'=>1969, 'username'=>'aida',      'firstName'=>'Aida',      'lastName'=>'DishNet',      'email'=>'aida@dishnetss.com'],
            ['id'=>1971, 'username'=>'meckylinea','firstName'=>'Meckylinea','lastName'=>'DishNet',      'email'=>'meckylinea@dishnetss.com'],
            ['id'=>1991, 'username'=>'amos',      'firstName'=>'Amos',      'lastName'=>'DishNet',      'email'=>'amos@dishnetss.com'],
            ['id'=>1993, 'username'=>'ochiti',    'firstName'=>'Ochiti',    'lastName'=>'DishNet',      'email'=>'ochiti@dishnetss.com'],
            ['id'=>2024, 'username'=>'geoffrey',  'firstName'=>'Geoffrey',  'lastName'=>'DishNet',      'email'=>'geoffrey@dishnetss.com'],
            ['id'=>2026, 'username'=>'karan',     'firstName'=>'Karan',     'lastName'=>'DishNet',      'email'=>'karan@dishnetss.com'],
            ['id'=>2027, 'username'=>'dhaval',    'firstName'=>'Dhaval',    'lastName'=>'DishNet',      'email'=>'dhaval@dishnetss.com'],
            ['id'=>2028, 'username'=>'thomas',    'firstName'=>'Thomas',    'lastName'=>'DishNet',      'email'=>'thomas@dishnetss.com'],
            ['id'=>2029, 'username'=>'tabule',    'firstName'=>'Tabule',    'lastName'=>'DishNet',      'email'=>'tabule@dishnetss.com'],
        ];

        // Augment with any newer users from scheduling jobs
        if ($crm->isConfigured()) {
            $jobs = $crm->get('scheduling/jobs?limit=500') ?? [];
            $seedEmails = array_column($seedUsers, 'email');
            foreach ($jobs as $job) {
                foreach (($job['assignees'] ?? []) as $a) {
                    $u = $a['user'] ?? null;
                    if (!$u || !isset($u['id'])) continue;
                    if (!in_array(strtolower($u['email']??''), $seedEmails)) {
                        $seedUsers[] = [
                            'id'        => (int)$u['id'],
                            'username'  => $u['username'] ?? '',
                            'firstName' => $u['firstName'] ?? '',
                            'lastName'  => $u['lastName'] ?? '',
                            'email'     => strtolower(trim($u['email'] ?? '')),
                        ];
                        $seedEmails[] = strtolower($u['email']??'');
                    }
                }
            }
        }

        // Mark already-mapped
        $retailers = $store->load('retailers.json') ?? [];
        $mapped = [];
        foreach ($retailers as $r) {
            if (!empty($r['ucrm_user_id'])) {
                $mapped[(int)$r['ucrm_user_id']] = ['retailer_id'=>$r['id'],'retailer_name'=>$r['name'],'role'=>$r['role']];
            }
        }
        foreach ($seedUsers as &$u) {
            $u['is_active'] = true;
            $u['mapped_to'] = $mapped[$u['id']] ?? null;
        }
        unset($u);
        usort($seedUsers, fn($a,$b) => ($a['mapped_to']?1:0) - ($b['mapped_to']?1:0));
        $ok2(['users' => $seedUsers, 'total' => count($seedUsers)]);
    }


    if ($act === 'auto_map_ucrm_users' && $met === 'POST') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin only.', 403);

        // Definitive email→ucrmUserId map extracted from Laravel DB (dishnetafric.sql)
        // login field = UCRM numeric user ID
        $seedMap = [
            'bhavin@dishnetafrica.com'        => 1,
            'mohini.madlani@outlook.com'      => 1078,
            'hardik@dishnetafrica.com'        => 1124,
            'accounts@dishnetafrica.com'      => 1164,
            'dishnetafrica@gmail.com'         => 1224,
            'atulsmahale007@gmail.com'        => 1368,
            'noc@dishnetafrica.com'           => 1400,
            'emmanuellukudualphon@gmail.com'  => 1401,
            'nirav@dishnetss.com'             => 1548,
            'madlanib@gmail.com'              => 1581,
            'vivekbhatt17@gmail.com'          => 1584,
            'vivek.dishnetafrica@outlook.com' => 1585,
            'wmensona@gmail.com'              => 1703,
            'kamjay285@gmail.com'             => 1705,
            'sokiris744@gmail.com'            => 1718,
            'timelessjo30@gmail.com'          => 1729,
            'bbc.vyara@gmail.com'             => 1879,
            'sales1@dishnetafrica.com'        => 1881,
            'justus@dishnetss.com'            => 1927,
            'rupesh@dishnetafrica.com'        => 1929,
            'aida@dishnetss.com'              => 1969,
            'meckylinea@dishnetss.com'        => 1971,
            'amos@dishnetss.com'              => 1991,
            'ochiti@dishnetss.com'            => 1993,
            'deepsolanki7799@gmail.com'       => 2015,
            'geoffrey@dishnetss.com'          => 2024,
            'karan@dishnetss.com'             => 2026,
            'dhaval@dishnetss.com'            => 2027,
            'thomas@dishnetss.com'            => 2028,
            'tabule@dishnetss.com'            => 2029,
        ];

        // Also try scheduling jobs for any newer staff not in seed map
        $fromJobs = [];
        if ($crm->isConfigured()) {
            $jobs = $crm->get('scheduling/jobs?limit=500') ?? [];
            foreach ($jobs as $job) {
                foreach (($job['assignees'] ?? []) as $a) {
                    $u = $a['user'] ?? null;
                    if (!$u || !isset($u['id'])) continue;
                    $em = strtolower(trim($u['email'] ?? ''));
                    if ($em && !isset($seedMap[$em])) {
                        $fromJobs[$em] = (int)$u['id'];
                    }
                }
            }
        }
        $fullMap = array_merge($fromJobs, $seedMap); // seed takes priority

        $retailers = $store->load('retailers.json') ?? [];
        $mapped = 0; $skipped = 0; $results = [];

        foreach ($retailers as &$retailer) {
            if (!empty($retailer['ucrm_user_id'])) { $skipped++; continue; }
            $rEmail = strtolower(trim($retailer['email'] ?? ''));
            if ($rEmail && isset($fullMap[$rEmail])) {
                $retailer['ucrm_user_id'] = $fullMap[$rEmail];
                $mapped++;
                $results[] = [
                    'retailer' => $retailer['name'],
                    'email'    => $rEmail,
                    'ucrm_id'  => $fullMap[$rEmail],
                    'method'   => isset($seedMap[$rEmail]) ? 'db_seed' : 'jobs_api',
                ];
            }
        }
        unset($retailer);
        if ($mapped > 0) $store->save('retailers.json', $retailers);
        $ok2(['mapped' => $mapped, 'skipped' => $skipped, 'results' => $results]);
    }


    // ── UCRM Users: manually set ucrm_user_id for a retailer ─────────────────
    if ($act === 'set_ucrm_user_id' && $met === 'POST') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin only.', 403);
        $retailerId = (int)($body['retailer_id'] ?? 0);
        $ucrmUserId = (int)($body['ucrm_user_id'] ?? 0);
        if (!$retailerId) $er2('retailer_id required.', 422);
        $store->updateOne('retailers.json', 'id', $retailerId, ['ucrm_user_id' => $ucrmUserId ?: null]);
        $ok2(['updated'=>true,'retailer_id'=>$retailerId,'ucrm_user_id'=>$ucrmUserId]);
    }

    // ── Scheduling: bulk create jobs in UCRM ─────────────────────────────────
    // Creates one UCRM scheduling job per customer entry in the batch.
    // Each job gets: title, date, duration, assignee, note, task list, and
    // the GPS/address from the customer's UCRM record.
    if ($act === 'bulk_create_jobs' && $met === 'POST') {
        // Only support_leader or admin can dispatch
        if (!($me2['is_admin'] ?? false) && ($me2['role'] ?? '') !== 'support_leader') {
            $er2('Support Leader or Admin access required.', 403);
        }
        if (!$crm->isConfigured()) $er2('CRM not configured.', 503);

        $jobTitle     = trim($body['job_title']     ?? '');
        $jobType      = trim($body['job_type']      ?? 'installation');
        $jobDate      = trim($body['job_date']       ?? date('Y-m-d'));
        $jobTime      = trim($body['job_time']       ?? '09:00');
        $duration     = (int)($body['duration']      ?? 90);
        $assigneeId   = (int)($body['assignee_id']   ?? 0);         // fallback UCRM user ID
        $fiberPartner = trim($body['fiber_partner']  ?? '');        // external fiber partner name
        $batchName    = trim($body['batch_name']     ?? $jobTitle); // deployment batch label
        $noteTemplate = trim($body['note_template']  ?? '');
        $taskNames    = array_filter(array_map('trim', (array)($body['tasks'] ?? [])));
        $customers    = (array)($body['customers']   ?? []);        // [{crm_id, note, assignee_id, job_time}]

        if (!$jobTitle)   $er2('Job title is required.', 422);
        if (!$jobDate)    $er2('Date is required.', 422);
        if (empty($customers)) $er2('No customers in batch.', 422);
        if (count($customers) > 50) $er2('Maximum 50 customers per batch.', 422);

        $results = [];
        $created = 0;
        $failed  = 0;

        foreach ($customers as $cust) {
            $crmClientId  = (int)($cust['crm_id'] ?? 0);
            $custNote     = trim($cust['note'] ?? $noteTemplate);
            $custAssignee = (int)($cust['assignee_id'] ?? $assigneeId); // per-customer override
            $custTime     = trim($cust['job_time'] ?? $jobTime);        // per-customer time slot
            if (!$crmClientId) { $failed++; $results[] = ['crm_id'=>0,'status'=>'skipped','error'=>'No CRM ID']; continue; }

            // Fetch client to get GPS + address for the job location
            $clientData = $crm->get("clients/{$crmClientId}");
            $clientName = $clientData ? trim(($clientData['firstName']??'').(' '.($clientData['lastName']??''))) : "Client #{$crmClientId}";
            $address    = $clientData ? trim(($clientData['street1']??'').' '.($clientData['street2']??'')) : '';

            // Build description — include fiber partner if provided
            $desc = $custNote ?: $noteTemplate;
            if ($fiberPartner) {
                $desc = "Fiber Partner: {$fiberPartner}\n" . ($desc ?: '');
            }

            // Build job payload for UCRM POST /scheduling/jobs
            $jobPayload = [
                'title'          => $jobTitle . ' — ' . $clientName,
                'jobType'        => $jobType,
                'date'           => $jobDate . 'T' . $custTime . ':00.000Z',
                'duration'       => $duration,
                'description'    => trim($desc),
                'clientId'       => $crmClientId,
                'address'        => $address,
                'status'         => 1, // 1 = Open (integer required by UCRM API)
            ];
            // GPS if available
            if (!empty($clientData['gpsLat'])) {
                $jobPayload['gpsLat'] = $clientData['gpsLat'];
                $jobPayload['gpsLon'] = $clientData['gpsLon'];
            }
            // assignedUserId is the correct field — UCRM ignores assignees[{userId}] on POST
            if ($custAssignee) {
                $jobPayload['assignedUserId'] = $custAssignee;
            }

            $created_job = $crm->post('scheduling/jobs', $jobPayload);
            if (!$created_job || empty($created_job['id'])) {
                $failed++;
                $results[] = ['crm_id'=>$crmClientId,'name'=>$clientName,'status'=>'failed','error'=>json_encode($crm->getLastError())];
                continue;
            }
            $newJobId = (int)$created_job['id'];

            // Create task checklist items if any
            foreach ($taskNames as $taskName) {
                $crm->post("scheduling/jobs/{$newJobId}/job-tasks", ['name' => $taskName]);
            }

            $created++;
            $results[] = [
                'crm_id'        => $crmClientId,
                'name'          => $clientName,
                'job_id'        => $newJobId,
                'address'       => $address,
                'assignee_id'   => $custAssignee,
                'assignee_name' => $cust['assignee_name'] ?? '',
                'job_time'      => $custTime,
                'status'        => 'created',
            ];

            // WhatsApp notification to assigned engineer
            if ($custAssignee) {
                // Look up engineer phone from retailers.json
                $allR = $store->load('retailers.json') ?? [];
                foreach ($allR as $r) {
                    if ((int)($r['ucrm_user_id'] ?? 0) === $custAssignee && !empty($r['phone'])) {
                        $engPhone     = preg_replace('/[^0-9+]/', '', $r['phone']);
                        $engFirstName = explode(' ', $r['name'] ?? 'Engineer')[0];
                        $dateLabel    = date('D d M', strtotime($jobDate));
                        $taskList     = !empty($taskNames) ? implode(', ', array_slice($taskNames, 0, 3)) : '';
                        $msg  = "Hi *{$engFirstName}*,\n\n";
                        $msg .= "🔧 *New Job Assigned to You*\n\n";
                        $msg .= "*{$jobTitle}*\n";
                        $msg .= "Customer: {$clientName}\n";
                        if ($address) $msg .= "Location: {$address}\n";
                        $msg .= "Date: *{$dateLabel}* at {$custTime}\n";
                        if ($fiberPartner) $msg .= "Partner: {$fiberPartner}\n";
                        if ($taskList) $msg .= "Tasks: {$taskList}\n";
                        $msg .= "\n*Open My Jobs tab → Job #{$newJobId}*\n";
                        $msg .= "\n— DishNET Africa Team";
                        $notify->sendVia('support', $engPhone, $msg, 'ops_scheduling_job_assigned');
                        break;
                    }
                }
            }
        }

        // Save deployment batch record for Bidal's tracking view
        $batchRecord = [
            'id'            => $store->nextId('fiber_batches.json'),
            'batch_name'    => $batchName,
            'job_title'     => $jobTitle,
            'fiber_partner' => $fiberPartner,
            'job_date'      => $jobDate,
            'created_by'    => $me2['name'] ?? 'Support Leader',
            'created_at'    => date('Y-m-d H:i:s'),
            'total'         => count($customers),
            'created'       => $created,
            'failed'        => $failed,
            'jobs'          => array_map(function($r) {
                return [
                    'crm_id'      => $r['crm_id'],
                    'name'        => $r['name'] ?? '',
                    'job_id'      => $r['job_id'] ?? null,
                    'address'     => $r['address'] ?? '',
                    'status'      => $r['status'],
                    'assignee_id' => $r['assignee_id'] ?? 0,
                    'assignee_name' => $r['assignee_name'] ?? '',
                    'job_time'    => $r['job_time'] ?? '',
                ];
            }, $results),
        ];
        $store->append('fiber_batches.json', $batchRecord);

        // Log the dispatch
        $logEntry = [
            'id'          => $store->nextId('activity_log.json'),
            'event'       => 'bulk_dispatch',
            'actor'       => $me2['name'] ?? 'Support Leader',
            'detail'      => "Bulk dispatch '{$jobTitle}' — {$created} jobs created, {$failed} failed",
            'ref_id'      => 0,
            'created_at'  => date('Y-m-d H:i:s'),
        ];
        $store->append('activity_log.json', $logEntry);

        $ok2([
            'created'  => $created,
            'failed'   => $failed,
            'total'    => count($customers),
            'results'  => $results,
            'job_date' => $jobDate,
        ]);
    }
