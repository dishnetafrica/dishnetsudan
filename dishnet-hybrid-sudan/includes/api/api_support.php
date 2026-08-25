<?php
// ═══════════════════════════════════════════════════════════════
// SUPPORT / INSTALL / NOC
// ═══════════════════════════════════════════════════════════════


    // ── GET  install_queue ──────────────────────────────────────────────────
    if ($act === 'install_queue') {
        if (!$isSupportAny2) $er2('Support access required.', 403);
        $filter = $_GET['filter'] ?? 'pending';
        $tickets = $splynxTickets->getJobs($filter);
        $ok2(['tickets' => $tickets, 'count' => count($tickets), 'filter' => $filter]);
    }

    // ── GET  install_ticket ─────────────────────────────────────────────────
    if ($act === 'install_ticket') {
        if (!$isSupportAny2) $er2('Support access required.', 403);
        $ticketId = (int)($_GET['ticket_id'] ?? 0);
        if (!$ticketId) $er2('ticket_id required.', 422);
        $ticket = $splynxTickets->getTicketById($ticketId);
        if (!$ticket) $er2('Ticket not found.', 404);
        $ok2(['ticket' => $ticket]);
    }

    // ── GET  install_testing_queue ──────────────────────────────────────────
    if ($act === 'install_testing_queue') {
        if (!$isLeaderOrAdmin2) $er2('Support leader access required.', 403);
        $queue = $splynxTickets->getTestingQueue();
        $ok2(['queue' => $queue, 'count' => count($queue)]);
    }

    // ── GET  install_stats ──────────────────────────────────────────────────
    if ($act === 'install_stats') {
        if (!$isLeaderOrAdmin2) $er2('Support leader access required.', 403);
        $summary = $splynxTickets->getSummary();
        $testing = $splynxTickets->getTestingQueue();
        $summary['testing_queue'] = count($testing);
        $summary['splynx_live']   = $splynx->isConfigured();
        // Include area breakdown for the area filter
        $areaBreakdown = $splynxTickets->getAreaBreakdown();
        $summary['areas'] = $areaBreakdown;

        // If fiber_pipeline wrote a fresher live count (within 2 hours), use it
        // This keeps My Install Jobs in sync with the live Splynx count
        $liveCache = $store->load('splynx_dashboard_cache.json') ?: [];
        $liveAt = $liveCache['live_updated_at'] ?? '';
        if ($liveAt && (time() - strtotime($liveAt)) < 7200 && isset($liveCache['live_pipeline_count'])) {
            $summary['total_pending'] = (int)$liveCache['live_pipeline_count'];
        }

        $ok2($summary);
    }

    // ── POST install_assign ─────────────────────────────────────────────────
    if ($act === 'install_assign' && $met === 'POST') {
        if (!$isLeaderOrAdmin2) $er2('Support leader access required.', 403);
        $ticketId = (int)($body['ticket_id']   ?? 0);
        $engName  = trim($body['engineer_name'] ?? '');
        $engId    = trim($body['engineer_id']   ?? '');
        if (!$ticketId || !$engName) $er2('ticket_id and engineer_name required.', 422);
        $ok = $splynxTickets->assignEngineer($ticketId, $engName, $engId);
        $ok ? $ok2(['assigned' => true, 'engineer' => $engName]) : $er2('Ticket not found.', 404);
    }

    // ── POST install_submit_data ────────────────────────────────────────────
    if ($act === 'install_submit_data' && $met === 'POST') {
        if (!$isSupportAny2) $er2('Support access required.', 403);
        $ticketId = (int)($body['ticket_id'] ?? 0);
        if (!$ticketId) $er2('ticket_id required.', 422);
        $data = [
            'onu_serial'     => $body['onu_serial']     ?? '',
            'olt_port'       => $body['olt_port']        ?? '',
            'signal_db'      => $body['signal_db']       ?? null,
            'notes'          => $body['notes']            ?? '',
            'testing_status' => $body['testing_status']  ?? 'pending',
            'submitted_by'   => $me2['name'] ?? 'Engineer',
        ];
        $ok = $splynxTickets->saveInstallData($ticketId, $data);
        $ok ? $ok2(['saved' => true]) : $er2('Ticket not found.', 404);
    }

    // ── POST install_ready ──────────────────────────────────────────────────
    if ($act === 'install_ready' && $met === 'POST') {
        if (!$isSupportAny2) $er2('Support access required.', 403);
        $ticketId = (int)($body['ticket_id'] ?? 0);
        if (!$ticketId) $er2('ticket_id required.', 422);
        $ok = $splynxTickets->markReadyForCommissioning($ticketId, $me2['name'] ?? 'Engineer');
        $ok ? $ok2(['ready' => true]) : $er2('Ticket not found.', 404);
    }

    // ── POST install_commission ─────────────────────────────────────────────
    if ($act === 'install_commission' && $met === 'POST') {
        if (!$isLeaderOrAdmin2) $er2('Support leader access required.', 403);
        $ticketId = (int)($body['ticket_id'] ?? 0);
        $notes    = trim($body['notes'] ?? '');
        if (!$ticketId) $er2('ticket_id required.', 422);
        $ticket = $splynxTickets->getTicketById($ticketId);
        if ($ticket && !empty($ticket['splynx_service_id']) && $splynx->isConfigured()) {
            $splynxCusts->activateService((int)$ticket['splynx_service_id']);
        }
        $result = $splynxTickets->commissionInstallation($ticketId, $me2['name'] ?? 'Support Leader', $notes);
        if ($result['ok'] ?? false) {
            if ($ticket) {
                $engId  = $ticket['assigned_engineer_id'] ?? 0;
                $engineer = $engId ? $store->findOne('retailers.json', 'id', (int)$engId) : null;
                if ($engineer && !empty($engineer['phone'])) {
                    $notify->installApproved($engineer, $ticket, $me2['name'] ?? 'Support Leader', $notes);
                }
            }
            $ok2($result);
        } else {
            $er2($result['error'] ?? 'Failed', 404);
        }
    }

    // ── POST noc_update_status — Change ticket status locally + emit event for Splynx sync ─
    if ($act === 'noc_update_status' && $met === 'POST') {
        if (!$isLeaderOrAdmin2) $er2('Support leader access required.', 403);
        $ticketId  = (int)($body['ticket_id'] ?? 0);
        $newStatus = (int)($body['status_id'] ?? 0);
        if (!$ticketId || !$newStatus) $er2('ticket_id and status_id required.', 422);

        // Valid statuses Bidal can set
        $allowedStatuses = [1,2,3,4,5,7,8,9,10,11,12];
        if (!in_array($newStatus, $allowedStatuses, true)) $er2('Invalid status_id.', 422);

        // 1. Update local ticket (JSON blob — source of truth during dual-write period)
        $tickets = $store->load('splynx_tickets.json') ?? [];
        $found = false;
        $oldStatus = 0;
        $splynxTicketId = 0;
        $lblMap = [1=>'new',2=>'work in progress',3=>'resolved',4=>'waiting your answer',5=>'waiting on agent',7=>'survey done',8=>'fiber deployment in progress',9=>'ready onu mapped',10=>'cancel by customer',11=>'fiber not available',12=>'client not ready'];
        $statusLbl = $lblMap[$newStatus] ?? 'status-'.$newStatus;
        foreach ($tickets as &$t2) {
            if ((int)($t2['id'] ?? 0) === $ticketId) {
                $oldStatus = (int)($t2['status'] ?? 0);
                $splynxTicketId = (int)($t2['splynx_ticket_id'] ?? $t2['id'] ?? 0);
                $t2['status'] = $newStatus;
                $t2['status_label'] = $statusLbl;
                $t2['status_changed_at'] = date('Y-m-d H:i:s');
                $t2['status_changed_by'] = $me2['name'] ?? 'Unknown';
                if ($newStatus === 3) {
                    $t2['install_complete'] = true;
                    $t2['install_complete_at'] = $t2['install_complete_at'] ?? date('Y-m-d H:i:s');
                }
                if (in_array($newStatus, [10,11,12], true)) {
                    $t2['install_complete'] = false;
                    $t2['cancelled'] = true;
                }
                $found = true;
                break;
            }
        }
        unset($t2);
        if (!$found) $er2('Ticket not found locally.', 404);
        $store->save('splynx_tickets.json', array_values($tickets));

        // 2. Dual-write to normalized tickets table (Phase 2)
        try {
            $pdo = $store->getPdo();
            $tableCheck = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='tickets'")->fetch();
            if ($tableCheck) {
                foreach ($tickets as $_t) {
                    if ((int)($_t['id'] ?? 0) === $ticketId) {
                        $splynxTickets->syncTicketToTable($_t);
                        break;
                    }
                }
            }
        } catch (\Throwable $e) { /* dual-write is non-fatal */ }

        // 3. Emit event for async Splynx push (Phase 2: replaces inline API call)
        $eventId = null;
        try {
            require_once dirname(__DIR__, 2) . '/lib/EventBus.php';
            $bus = new EventBus($store->getPdo());
            $eventId = $bus->emit('ticket.status_changed', 'ticket', $ticketId, [
                'ticket_id'        => $ticketId,
                'splynx_ticket_id' => $splynxTicketId,
                'old_status'       => $oldStatus,
                'new_status'       => $newStatus,
                'status_label'     => $statusLbl,
                'changed_by'       => $me2['name'] ?? 'Unknown',
            ], 2, $me2['name'] ?? 'api');
        } catch (\Throwable $e) {
            // Event emit failure — fall back to direct Splynx push
            error_log("EventBus emit failed, falling back to direct push: " . $e->getMessage());
            if ($splynx->isConfigured() && $splynxTicketId) {
                $splynx->updateTicket($splynxTicketId, ['status' => $newStatus]);
            }
        }

        $ok2([
            'updated'       => true,
            'status_id'     => $newStatus,
            'status_label'  => $statusLbl,
            'event_queued'  => $eventId !== null,
            'event_id'      => $eventId,
        ]);
    }

    // ── POST noc_assign_engineer — Assign locally + emit event for Splynx sync ─
    if ($act === 'noc_assign_engineer' && $met === 'POST') {
        if (!$isLeaderOrAdmin2) $er2('Support leader access required.', 403);
        $ticketId = (int)($body['ticket_id']   ?? 0);
        $engName  = trim($body['engineer_name'] ?? '');
        $engId    = trim($body['engineer_id']   ?? '');
        if (!$ticketId || !$engName) $er2('ticket_id and engineer_name required.', 422);

        // 1. Local assign (JSON blob + dual-write via SplynxTicketService)
        $ok = $splynxTickets->assignEngineer($ticketId, $engName, $engId);
        if (!$ok) $er2('Ticket not found.', 404);

        // 2. Get ticket details for the event payload
        $ticket = $splynxTickets->getTicketById($ticketId);
        $splynxTid = (int)($ticket['splynx_ticket_id'] ?? $ticket['id'] ?? 0);
        $oldStatus = (int)($ticket['status'] ?? 0);

        // 3. Emit event for async Splynx push + engineer WA notification
        $eventId = null;
        try {
            require_once dirname(__DIR__, 2) . '/lib/EventBus.php';
            $bus = new EventBus($store->getPdo());

            // Look up engineer's phone for WA notification
            $engPhone = '';
            $engRecord = $engId ? $store->findOne('retailers.json', 'id', (int)$engId) : null;
            if ($engRecord) $engPhone = $engRecord['phone'] ?? '';

            $eventId = $bus->emit('ticket.assigned', 'ticket', $ticketId, [
                'ticket_id'          => $ticketId,
                'splynx_ticket_id'   => $splynxTid,
                'old_status'         => $oldStatus,
                'engineer_name'      => $engName,
                'engineer_id'        => $engId,
                'engineer_phone'     => $engPhone,
                'customer_name'      => $ticket['customer_name'] ?? '',
                'area'               => $ticket['area'] ?? '',
                'assigned_by'        => $me2['name'] ?? 'Unknown',
            ], 3, $me2['name'] ?? 'api');
        } catch (\Throwable $e) {
            // Fallback: direct Splynx push if EventBus unavailable
            error_log("EventBus emit failed for assign, direct push: " . $e->getMessage());
            if ($splynx->isConfigured() && $splynxTid && $oldStatus === 1) {
                $splynx->updateTicket($splynxTid, ['status' => 2]);
                $tks = $store->load('splynx_tickets.json') ?? [];
                foreach ($tks as &$tk) {
                    if ((int)($tk['id'] ?? 0) === $ticketId) {
                        $tk['status'] = 2;
                        $tk['status_label'] = 'work in progress';
                        break;
                    }
                }
                unset($tk);
                $store->save('splynx_tickets.json', array_values($tks));
            }
        }

        $ok2([
            'assigned'      => true,
            'engineer'      => $engName,
            'event_queued'  => $eventId !== null,
            'event_id'      => $eventId,
        ]);
    }

    // ── POST install_reject — Reject + emit event for WA notification ─────────
    if ($act === 'install_reject' && $met === 'POST') {
        if (!$isLeaderOrAdmin2) $er2('Support leader access required.', 403);
        $ticketId = (int)($body['ticket_id'] ?? 0);
        $reason   = trim($body['reason'] ?? '');
        if (!$ticketId) $er2('ticket_id required.', 422);
        $ticket = $splynxTickets->getTicketById($ticketId);
        $ok = $splynxTickets->rejectInstallation($ticketId, $me2['name'] ?? 'Support Leader', $reason);
        if ($ok) {
            // Emit event for async WA notification (replaces inline notify call)
            try {
                require_once dirname(__DIR__, 2) . '/lib/EventBus.php';
                $bus = new EventBus($store->getPdo());
                $engId    = $ticket['assigned_engineer_id'] ?? 0;
                $engineer = $engId ? $store->findOne('retailers.json', 'id', (int)$engId) : null;
                $bus->emit('install.rejected', 'ticket', $ticketId, [
                    'ticket_id'      => $ticketId,
                    'reason'         => $reason,
                    'rejected_by'    => $me2['name'] ?? 'Support Leader',
                    'customer_name'  => $ticket['customer_name'] ?? '',
                    'area'           => $ticket['area'] ?? '',
                    'engineer_name'  => $engineer['name'] ?? '',
                    'engineer_phone' => $engineer['phone'] ?? '',
                ], 3, $me2['name'] ?? 'api');
            } catch (\Throwable $e) {
                // Fallback: direct WA send
                if ($ticket) {
                    $engId2    = $ticket['assigned_engineer_id'] ?? 0;
                    $engineer2 = $engId2 ? $store->findOne('retailers.json', 'id', (int)$engId2) : null;
                    if ($engineer2 && !empty($engineer2['phone'])) {
                        $notify->installRejected($engineer2, $ticket, $me2['name'] ?? 'Support Leader', $reason);
                    }
                }
            }
            $ok2(['rejected' => true]);
        } else {
            $er2('Ticket not found.', 404);
        }
    }

    // ── POST install_upload_photo ───────────────────────────────────────────
    if ($act === 'install_upload_photo' && $met === 'POST') {
        if (!$isSupportAny2) $er2('Support access required.', 403);
        $ticketId  = (int)($body['ticket_id']  ?? 0);
        $photoType = trim($body['photo_type']  ?? 'site');
        $imgData   = $body['image_data']        ?? '';
        if (!$ticketId || !$imgData) $er2('ticket_id and image_data required.', 422);
        $uploadDir = $dataDir . '/uploads/install_photos/' . $ticketId . '/';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
        $filename = $photoType . '_' . time() . '.jpg';
        $raw   = preg_replace('/^data:image\\/\\w+;base64,/', '', $imgData);
        $bytes = base64_decode($raw);
        if ($bytes === false || strlen($bytes) < 100) $er2('Invalid image data.', 422);
        file_put_contents($uploadDir . $filename, $bytes);
        $splynxTickets->savePhoto($ticketId, $photoType, $filename, $me2['name'] ?? 'Engineer');
        $ok2(['saved' => true, 'filename' => $filename]);
    }

    // ── POST install_add_note ─────────────────────────────────────────────────
    if ($act === 'install_add_note' && $met === 'POST') {
        if (!$isSupportAny2) $er2('Support access required.', 403);
        $ticketId = (int)($body['ticket_id'] ?? 0);
        $note     = trim($body['note'] ?? '');
        if (!$ticketId || !$note) $er2('ticket_id and note required.', 422);
        $tickets = $store->load('splynx_tickets.json') ?? [];
        $found = false;
        foreach ($tickets as &$t2) {
            if ((int)($t2['id'] ?? 0) === $ticketId) {
                if (!isset($t2['ops_notes'])) $t2['ops_notes'] = [];
                $t2['ops_notes'][] = [
                    'note'   => $note,
                    'author' => $me2['name'] ?? 'Unknown',
                    'at'     => date('Y-m-d H:i'),
                ];
                $found = true;
                break;
            }
        }
        unset($t2);
        if (!$found) $er2('Ticket not found.', 404);
        $store->save('splynx_tickets.json', array_values($tickets));
        $ok2(['saved' => true]);
    }

    // ── GET support_engineers — list support-role retailers for engineer picker ─
    if ($act === 'support_engineers' && $met === 'GET') {
        // All authenticated users can fetch engineer list (needed for job creation)
        $all = $store->load('retailers.json') ?? [];
        $supportRoles = ['support_engineer', 'support', 'support_leader', 'admin'];
        $engineers = array_values(array_filter($all, function($r) use ($supportRoles) {
            return in_array($r['role'] ?? '', $supportRoles, true)
                && !empty($r['is_active'])
                && !empty($r['ucrm_user_id']); // must have UCRM ID to be assignable
        }));
        $safe = array_map(function($r) {
            return [
                'id'           => $r['id'],
                'name'         => $r['name'] ?? '',
                'role'         => $r['role'] ?? '',
                'phone'        => $r['phone'] ?? '',
                'ucrm_user_id' => (int)($r['ucrm_user_id'] ?? 0),
            ];
        }, $engineers);
        $ok2(['agents' => $safe]);
    }

    // ── POST kyc_save_photo — save KYC docs locally, no UCRM needed ─────────
    if ($act === 'kyc_save_photo' && $met === 'POST') {
        $appId = (int)($_POST['app_id'] ?? 0);
        if (!$appId) $er2('Missing app_id', 400);

        $kycPhotoDir = $dataDir . '/kyc_photos/';
        if (!is_dir($kycPhotoDir)) @mkdir($kycPhotoDir, 0755, true);

        $saved = [];
        $errors = [];

        $saveFile = function(string $tmpSrc, string $label, int $appId) use ($kycPhotoDir): array {
            $slug     = preg_replace('/[^a-z0-9]/', '_', strtolower($label));
            $fileName = 'app' . $appId . '_' . $slug . '_' . date('Ymd_His') . '.jpg';
            $savePath = $kycPhotoDir . $fileName;
            $saved    = false;

            // Try GD compress + resize
            if (function_exists('imagecreatefromstring')) {
                $raw = @file_get_contents($tmpSrc);
                $img = $raw ? @imagecreatefromstring($raw) : false;
                if ($img) {
                    $w = imagesx($img); $h = imagesy($img); $max = 1200;
                    if ($w > $max || $h > $max) {
                        $r = min($max/$w, $max/$h);
                        $nw = (int)round($w*$r); $nh = (int)round($h*$r);
                        $thumb = imagecreatetruecolor($nw, $nh);
                        imagecopyresampled($thumb,$img,0,0,0,0,$nw,$nh,$w,$h);
                        imagedestroy($img); $img = $thumb;
                    }
                    $saved = imagejpeg($img, $savePath, 78);
                    imagedestroy($img);
                }
            }
            // Fallback: plain copy (keep original ext)
            if (!$saved) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $tmpSrc);
                finfo_close($finfo);
                $extMap = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif',
                           'image/webp'=>'webp','image/heic'=>'heic','image/heif'=>'heif',
                           'application/pdf'=>'pdf'];
                $ext      = $extMap[$mime] ?? 'jpg';
                $fileName = 'app' . $appId . '_' . $slug . '_' . date('Ymd_His') . '.' . $ext;
                $savePath = $kycPhotoDir . $fileName;
                $saved    = copy($tmpSrc, $savePath);
            }
            if (!$saved) return ['ok'=>false,'msg'=>"Failed to save {$label}"];
            // Compress: max 1200px, 70% quality
            require_once $GLOBALS['_PLUGIN_ROOT'] . '/lib/ImageCompressor.php';
            compressImage($savePath);
            return ['ok'=>true,'path'=>'kyc_photos/'.$fileName,'size'=>round(filesize($savePath)/1024).'KB'];
        };

        foreach ([
            'customer_image' => 'photo',
            'id_document'    => 'id',
        ] as $fileKey => $fieldType) {
            if (!empty($_FILES[$fileKey]['tmp_name']) && is_uploaded_file($_FILES[$fileKey]['tmp_name'])) {
                $label = $fileKey === 'customer_image' ? 'Customer Photo' : 'ID Proof';
                $res   = $saveFile($_FILES[$fileKey]['tmp_name'], $label, $appId);
                if ($res['ok']) {
                    $saved[$fieldType] = $res;
                    // Update KYC record
                    $updateFields = $fileKey === 'customer_image'
                        ? ['photo_uploaded'=>true, 'photo_path'=>$res['path']]
                        : ['id_uploaded'=>true,    'id_path'   =>$res['path']];
                    $store->updateOne('kyc_applications.json','id',$appId,$updateFields);
                } else {
                    $errors[] = $res['msg'];
                }
            }
        }

        if (empty($saved) && empty($errors)) $er2('No files received', 400);
        if (!empty($errors) && empty($saved)) $er2(implode('; ', $errors), 500);

        $ok2(['saved'=>$saved, 'warnings'=>$errors]);
    }
