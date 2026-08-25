<?php
// ═══════════════════════════════════════════════════════════════
// FIELD OPERATIONS / GPS
// ═══════════════════════════════════════════════════════════════


    // ── GET job_titles — fetch UCRM job title templates ─────────────────────
    if ($act === 'job_titles' && $met === 'GET') {
        // Try UCRM scheduling/job-titles endpoint
        $titles = $crm->get('scheduling/job-titles');
        if (is_array($titles) && count($titles)) {
            $ok2(['titles' => array_column($titles, 'title') ?: array_map(fn($t) => $t['title'] ?? $t['name'] ?? '', $titles)]);
        }
        // Fallback: DishNet common job titles
        $ok2(['titles' => [
            'Starlink Installation',
            'Starlink Dish Relocation',
            'Starlink Cable Repair',
            'Starlink Power Issue',
            'Fiber Installation',
            'Fiber Cable Repair',
            'Fiber ONU Replacement',
            'Fiber Splicing',
            'Router Configuration',
            'WiFi Extender Setup',
            'Network Troubleshooting',
            'Site Survey',
            'Client Equipment Swap',
            'LTE SIM Activation',
            'Invoice / Billing Visit',
            'Equipment Collection',
            'Follow-up Visit',
        ]]);
    }


    if ($act === 'staff_routes_admin' && $met === 'GET') {
        if (!($me2['is_admin'] ?? false) && ($me2['role'] ?? '') !== 'support_leader')
            $er2('Support leader or admin only.', 403);
        $date = $_GET['date'] ?? date('Y-m-d');
        $routes = $store->load('staff_routes.json') ?? [];
        $filtered = array_values(array_filter($routes, function($r) use ($date) { return $r['date'] === $date; }));
        $live = $store->load('staff_live_locations.json') ?? [];
        $checkins = $store->load('job_checkins.json') ?? [];
        foreach ($filtered as &$route) {
            $aid = $route['agent_id'];
            $route['live_location'] = $live[$aid] ?? null;
            $done = 0;
            foreach ($route['job_ids'] as $jid) {
                if (($checkins["j{$jid}_a{$aid}"]['status'] ?? '') === 'completed') $done++;
            }
            $route['jobs_done']  = $done;
            $route['jobs_total'] = count($route['job_ids']);
        }
        unset($route);
        $ok2(['routes' => $filtered, 'date' => $date]);
    }

    // ── POST assign_route — assign jobs to an engineer for a date ────────────
    if ($act === 'assign_route' && $met === 'POST') {
        if (!($me2['is_admin'] ?? false) && ($me2['role'] ?? '') !== 'support_leader')
            $er2('Support leader or admin only.', 403);
        $agentId = (int)($body['agent_id'] ?? 0);
        $jobIds  = array_map('intval', $body['job_ids'] ?? []);
        $date    = $body['date'] ?? date('Y-m-d');
        if (!$agentId || empty($jobIds)) $er2('agent_id and job_ids required.', 422);
        $retailers = $store->load('retailers.json') ?? [];
        $agent = null;
        foreach ($retailers as $r) { if ((int)$r['id'] === $agentId) { $agent = $r; break; } }
        if (!$agent) $er2('Agent not found.', 404);
        $routes = $store->load('staff_routes.json') ?? [];
        $routeKey = "{$agentId}_{$date}";
        $routes[$routeKey] = [
            'agent_id'    => $agentId,
            'agent_name'  => $agent['name'] ?? 'Unknown',
            'date'        => $date,
            'job_ids'     => $jobIds,
            'note'        => trim($body['note'] ?? ''),
            'assigned_by' => $me2['name'] ?? 'Admin',
            'assigned_at' => date('Y-m-d H:i:s'),
            'status'      => 'active',
        ];
        $store->save('staff_routes.json', $routes);
        $ok2(['route_assigned' => true, 'route_key' => $routeKey, 'job_count' => count($jobIds)]);
    }

    // ── GET staff_live_map — all online engineers with GPS ────────────────────
    if ($act === 'staff_live_map' && $met === 'GET') {
        if (!($me2['is_admin'] ?? false) && ($me2['role'] ?? '') !== 'support_leader')
            $er2('Support leader or admin only.', 403);
        $raw  = $store->load('staff_live_locations.json') ?? [];
        $now  = time();
        $live = [];
        foreach ($raw as $entry) {
            $ts       = (int)($entry['ts'] ?? 0);
            $ageMin   = $ts ? (int)round(($now - $ts) / 60) : 999;
            $isOnline = $ageMin <= 15; // stale after 15 min
            $live[] = [
                'agent_id'      => $entry['agent_id']   ?? '',
                'agent_name'    => $entry['agent_name'] ?? ($entry['name'] ?? ''), // normalise both field variants
                'role'          => $entry['role']        ?? '',
                'lat'           => $entry['lat']         ?? null,
                'lon'           => $entry['lon']         ?? null,
                'accuracy'      => $entry['accuracy']    ?? 0,
                'battery'       => $entry['battery']     ?? -1,
                'active_job'    => $entry['active_job']  ?? null,
                'is_online'     => $isOnline,
                'last_seen_min' => $ageMin,
                'jobs_today'    => $entry['jobs_today']  ?? 0,
                'updated_at'    => $entry['updated_at']  ?? '',
            ];
        }
        // Online first, then by recency
        usort($live, fn($a,$b) => ($b['is_online'] <=> $a['is_online']) ?: ($a['last_seen_min'] <=> $b['last_seen_min']));
        $ok2(['staff' => $live]);
    }

    // ── POST gps_heartbeat — engineer pushes GPS location ────────────────────
    if ($act === 'gps_heartbeat' && $met === 'POST') {
        $lat = (float)($body['lat'] ?? 0);
        $lon = (float)($body['lon'] ?? 0);
        if (!$lat || !$lon) $er2('lat and lon required.', 422);
        $live = $store->load('staff_live_locations.json') ?? [];
        $live[$me2['id']] = [
            'agent_id'   => $me2['id'],
            'agent_name' => $me2['name'] ?? '',   // FIX: JS reads agent_name, not name
            'name'       => $me2['name'] ?? '',   // keep for compatibility
            'role'       => $me2['role'] ?? '',
            'lat'        => $lat,
            'lon'        => $lon,
            'accuracy'   => (float)($body['accuracy'] ?? 0),
            'battery'    => isset($body['battery']) ? (int)$body['battery'] : -1,
            'active_job' => $body['job_id'] ?? null,
            'is_online'  => true,
            'updated_at' => date('Y-m-d H:i:s'),
            'ts'         => time(),
        ];
        // Append to trail for today
        $trailKey = 'staff_trail_' . $me2['id'] . '_' . date('Y-m-d');
        $trail = $store->load($trailKey . '.json') ?? [];
        $trail[] = ['lat' => $lat, 'lon' => $lon, 'at' => date('Y-m-d H:i:s'), 'ts' => time()];
        $store->save($trailKey . '.json', $trail);
        $store->save('staff_live_locations.json', $live);
        $ok2(['saved' => true]);
    }

    // ── GET staff_trail — GPS trail for one agent today ──────────────────────
    if ($act === 'staff_trail' && $met === 'GET') {
        if (!($me2['is_admin'] ?? false) && ($me2['role'] ?? '') !== 'support_leader')
            $er2('Support leader or admin only.', 403);
        $agentId  = (int)($_GET['agent_id'] ?? 0);
        $date     = $_GET['date'] ?? date('Y-m-d');
        if (!$agentId) $er2('agent_id required.', 422);
        $trailKey = 'staff_trail_' . $agentId . '_' . $date;
        $trail    = $store->load($trailKey . '.json') ?? [];
        $ok2(['trail' => $trail, 'agent_id' => $agentId, 'date' => $date]);
    }

    // ── GET job_checkins_today — all job check-ins for a date ────────────────
    if ($act === 'job_checkins_today' && $met === 'GET') {
        if (!($me2['is_admin'] ?? false) && ($me2['role'] ?? '') !== 'support_leader')
            $er2('Support leader or admin only.', 403);
        $date     = $_GET['date'] ?? date('Y-m-d');
        $checkins = $store->load('job_checkins.json') ?? [];
        $out = []; $inProgress = 0; $durations = [];

        foreach ($checkins as $entry) {
            if (substr($entry['checkin_at'] ?? '', 0, 10) !== $date) continue;
            $status  = empty($entry['checkout_at']) ? 'checked_in' : 'checked_out';
            $durMin  = $entry['duration_min'] ?? null;
            if ($durMin) $durations[] = $durMin;
            if ($status === 'checked_in') $inProgress++;
            $out[] = [
                'job_id'      => $entry['job_id']      ?? '',
                'agent_name'  => $entry['checkin_by']  ?? '',
                'checkin_at'  => $entry['checkin_at']  ?? '',
                'checkout_at' => $entry['checkout_at'] ?? null,
                'checkin_lat' => $entry['checkin_lat'] ?? null,
                'checkin_lon' => $entry['checkin_lon'] ?? null,
                'note'        => $entry['note']        ?? '',
                'status'      => $status,
                'duration_min'=> $durMin,
            ];
        }
        usort($out, fn($a,$b) => strcmp($b['checkin_at'], $a['checkin_at']));
        $avgDur = count($durations) ? (int)round(array_sum($durations) / count($durations)) : 0;
        $ok2([
            'checkins' => $out,
            'stats'    => ['total_checkins' => count($out), 'in_progress' => $inProgress, 'avg_duration' => $avgDur],
            'date'     => $date,
        ]);
    }

    // ── GET scheduling_jobs — jobs for a date / agent (route manager) ─────────
    if ($act === 'scheduling_jobs' && $met === 'GET') {
        if (!($me2['is_admin'] ?? false) && ($me2['role'] ?? '') !== 'support_leader')
            $er2('Support leader or admin only.', 403);
        $date    = $_GET['date']     ?? date('Y-m-d');
        $agentId = (int)($_GET['agent_id'] ?? 0);
        $tickets = $store->load('splynx_tickets.json') ?? [];
        $filtered = array_values(array_filter($tickets, function($t) use ($date, $agentId) {
            $matchDate  = !$date    || ($t['scheduled_date'] ?? '') === $date;
            $matchAgent = !$agentId || (int)($t['assigned_to_id'] ?? 0) === $agentId;
            return $matchDate && $matchAgent && ($t['status'] ?? '') !== 'completed';
        }));
        $ok2(['jobs' => $filtered, 'count' => count($filtered)]);
    }

    // ── POST install_checkin — engineer GPS check-in to UCRM scheduling job ──
    if ($act === 'install_checkin' && $met === 'POST') {
        if (!$isSupportAny2) $er2('Support access required.', 403);
        $jobId = (int)($body['job_id'] ?? $body['ticket_id'] ?? 0);
        $lat   = (float)($body['lat'] ?? 0);
        $lon   = (float)($body['lon'] ?? 0);
        $note  = trim($body['note'] ?? '');
        if (!$jobId) $er2('job_id required.', 422);

        // Save check-in record to job_checkins.json (keyed by job_id)
        $checkins = $store->load('job_checkins.json') ?? [];
        $checkins[$jobId] = [
            'job_id'      => $jobId,
            'checkin_at'  => date('Y-m-d H:i:s'),
            'checkin_lat' => $lat,
            'checkin_lon' => $lon,
            'checkin_by'  => $me2['name'] ?? 'Engineer',
            'checkin_uid' => $me2['id']   ?? 0,
            'checkout_at' => $checkins[$jobId]['checkout_at'] ?? null,
            'note'        => $note,
        ];
        $store->save('job_checkins.json', $checkins);

        // PATCH UCRM job status to 1 (In Progress)
        if ($crm->isConfigured()) {
            $crm->patch("scheduling/jobs/{$jobId}", ['status' => 1]);
        }

        // Update live location
        $live = $store->load('staff_live_locations.json') ?? [];
        $live[$me2['id']] = array_merge($live[$me2['id']] ?? [], [
            'agent_id'   => $me2['id'],
            'agent_name' => $me2['name'] ?? '',
            'lat'        => $lat ?: ($live[$me2['id']]['lat'] ?? null),
            'lon'        => $lon ?: ($live[$me2['id']]['lon'] ?? null),
            'active_job' => $jobId,
            'is_online'  => true,
            'ts'         => time(),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $store->save('staff_live_locations.json', $live);

        // Start trail if GPS available
        if ($lat && $lon) {
            $trailKey = 'staff_trail_' . $me2['id'] . '_' . date('Y-m-d');
            $trail    = $store->load($trailKey . '.json') ?? [];
            $trail[]  = ['lat' => $lat, 'lon' => $lon, 'at' => date('Y-m-d H:i:s'), 'ts' => time()];
            $store->save($trailKey . '.json', $trail);
        }

        $ok2(['checked_in' => true, 'job_id' => $jobId, 'at' => date('Y-m-d H:i:s')]);
    }

    // ── POST install_checkout — engineer GPS check-out from UCRM scheduling job ─
    if ($act === 'install_checkout' && $met === 'POST') {
        if (!$isSupportAny2) $er2('Support access required.', 403);
        $jobId = (int)($body['job_id'] ?? $body['ticket_id'] ?? 0);
        $lat   = (float)($body['lat'] ?? 0);
        $lon   = (float)($body['lon'] ?? 0);
        $note  = trim($body['note'] ?? '');
        if (!$jobId) $er2('job_id required.', 422);

        // Update check-in record with checkout time
        $checkins = $store->load('job_checkins.json') ?? [];
        $existing = $checkins[$jobId] ?? ['job_id' => $jobId, 'checkin_at' => null];
        $checkins[$jobId] = array_merge($existing, [
            'checkout_at'  => date('Y-m-d H:i:s'),
            'checkout_lat' => $lat,
            'checkout_lon' => $lon,
            'checkout_by'  => $me2['name'] ?? 'Engineer',
            'checkout_note'=> $note,
        ]);
        // Calculate duration
        if (!empty($existing['checkin_at'])) {
            $checkins[$jobId]['duration_min'] = (int)round(
                (time() - strtotime($existing['checkin_at'])) / 60
            );
        }
        $store->save('job_checkins.json', $checkins);

        // PATCH UCRM job status to 2 (Closed)
        if ($crm->isConfigured()) {
            $crm->patch("scheduling/jobs/{$jobId}", ['status' => 2]);
        }

        // Clear active_job from live location
        $live = $store->load('staff_live_locations.json') ?? [];
        if (isset($live[$me2['id']])) {
            $live[$me2['id']]['active_job']  = null;
            $live[$me2['id']]['updated_at']  = date('Y-m-d H:i:s');
            $store->save('staff_live_locations.json', $live);
        }

        $ok2(['checked_out' => true, 'job_id' => $jobId, 'at' => date('Y-m-d H:i:s'),
              'duration_min' => $checkins[$jobId]['duration_min'] ?? null]);
    }

    // ── POST install_mark_ready — alias for install_ready (PWA uses this name) ─
    if ($act === 'install_mark_ready' && $met === 'POST') {
        if (!$isSupportAny2) $er2('Support access required.', 403);
        $ticketId = (int)($body['ticket_id'] ?? 0);
        if (!$ticketId) $er2('ticket_id required.', 422);
        $ok = $splynxTickets->markReadyForCommissioning($ticketId, $me2['name'] ?? 'Engineer');
        $ok ? $ok2(['ready' => true]) : $er2('Ticket not found.', 404);
    }

    // ── GET my_route — engineer's assigned jobs for a date ────────────────────
    if ($act === 'my_route' && $met === 'GET') {
        if (!$isSupportAny2) $er2('Support access required.', 403);
        $date    = $_GET['date'] ?? date('Y-m-d');
        $tickets = $store->load('splynx_tickets.json') ?? [];
        $myJobs  = array_values(array_filter($tickets, function($t) use ($me2, $date) {
            $myId    = (int)($me2['id'] ?? 0);
            $engId   = (int)($t['assigned_engineer_id'] ?? 0);
            $engName = strtolower($t['assigned_engineer'] ?? '');
            $myName  = strtolower($me2['name'] ?? '');
            $isMe    = ($myId && $myId === $engId) || ($myName && $myName === $engName);
            $matchDate = !$date || ($t['scheduled_date'] ?? '') === $date;
            return $isMe && $matchDate;
        }));
        // Sort by scheduled_time
        usort($myJobs, function($a, $b) {
            return strcmp($a['scheduled_time'] ?? '00:00', $b['scheduled_time'] ?? '00:00');
        });
        $ok2(['date' => $date, 'jobs' => $myJobs, 'count' => count($myJobs)]);
    }
