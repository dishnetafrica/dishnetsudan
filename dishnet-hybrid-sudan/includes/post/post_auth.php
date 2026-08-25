<?php
// ═══════════════════════════════════════════════════════════════
// AUTHENTICATION
// ═══════════════════════════════════════════════════════════════


// ── Create Agent Account (admin-password gated, no login required) ───────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='create_agent') {
    if (!csrfCheck()) { flash('Invalid request.','danger'); redirect('?page=login&m=create'); }
    $agentName  = trim($_POST['agent_name']     ?? '');
    $agentEmail = strtolower(trim($_POST['agent_email'] ?? ''));
    $agentPhone = trim($_POST['agent_phone']    ?? '');
    $agentPw    = trim($_POST['agent_password'] ?? '');
    $agentRole  = trim($_POST['agent_role']     ?? 'sales');
    $adminPw    = trim($_POST['admin_password'] ?? '');

    if (!$agentName || !$agentEmail || !$agentPw) {
        flash('Name, email and password are required.','danger');
        redirect('?page=login&m=create');
    }
    if (!filter_var($agentEmail, FILTER_VALIDATE_EMAIL)) {
        flash('Invalid email address.','danger');
        redirect('?page=login&m=create');
    }
    if (strlen($agentPw) < 6) {
        flash('Password must be at least 6 characters.','danger');
        redirect('?page=login&m=create');
    }
    if (!in_array($agentRole, ['sales','support','accountant'])) $agentRole = 'sales';

    // Verify admin password
    $allRetailers   = $store->load('retailers.json') ?: [];
    $adminVerified  = false;
    foreach ($allRetailers as $_ar) {
        if (($_ar['is_admin']??false) || ($_ar['role']??'')==='admin') {
            if (password_verify($adminPw, $_ar['password']??'')) { $adminVerified = true; break; }
        }
    }
    if (!$adminVerified) {
        flash('Admin password incorrect. Ask your admin for the admin password.','danger');
        redirect('?page=login&m=create');
    }

    // Check duplicate
    foreach ($allRetailers as $_ar) {
        if (strtolower($_ar['email']??'') === $agentEmail) {
            flash('An account with this email already exists.','warning');
            redirect('?page=login&m=create');
        }
    }

    $auth->createRetailer([
        'name'      => $agentName,
        'email'     => $agentEmail,
        'phone'     => $agentPhone,
        'password'  => $agentPw,
        'role'      => $agentRole,
        'is_admin'  => false,
        'is_active' => true,
        'wallet'    => 0,
    ]);
    logActivity($dataDir, 'account_created', 'Agent account created via login page',
        $agentName.' ('.$agentEmail.') role:'.$agentRole);
    flash('Account created for '.$agentName.'. They can now log in with '.$agentEmail.'.','success');
    redirect('?page=login');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='do_login') {
    // I-02: Rate limiting — check before any DB lookup
    // Accept email OR phone number in the identifier field (backwards-compat: also check 'email' field)
    $loginEmail = strtolower(trim($_POST['identifier'] ?? $_POST['email'] ?? ''));
    // v4.21.20: real client IP via getClientIp() helper — UCRM is behind
    // a reverse proxy so REMOTE_ADDR is always an internal 172.x. The
    // SUCCESSFUL login path (RetailerAuth::webLogin) already used the
    // forwarded-headers chain — this fixes the FAILED-login path for parity.
    $loginIp = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if ($loginIp === '') $loginIp = '0.0.0.0';
    $lockCheck  = $limiter->check($loginEmail, $loginIp);
    if ($lockCheck['locked']) {
        $mins = $lockCheck['retry_in_minutes'];
        flash("Too many failed login attempts. Account locked — try again in {$mins} minute" . ($mins===1?'':'s') . ".","danger");
        redirect('?page=login');
    }

    $retailer = $auth->webLogin($loginEmail, $_POST['password'] ?? '');
    if ($retailer) {
        $limiter->recordSuccess($loginEmail, $loginIp);   // clear failure counter
        redirect('?page=dashboard&tab=form');
    } else {
        $result = $limiter->recordFailure($loginEmail, $loginIp);
        // Log failed attempt
        $ua2     = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $device2 = preg_match('/Android/i',$ua2)?'Android':(preg_match('/iPhone|iPad/i',$ua2)?'iPhone/iPad':(preg_match('/Mobile/i',$ua2)?'Mobile':'Desktop'));
        $store->appendWithId('login_sessions.json', [
            'retailer_id'  => null,
            'name'         => $loginEmail,
            'email'        => $loginEmail,
            'role'         => 'unknown',
            'ip'           => $loginIp,
            'device'       => $device2,
            'browser'      => 'unknown',
            'user_agent'   => substr($ua2,0,200),
            'logged_in_at' => date('Y-m-d H:i:s'),
            'status'       => 'failed',
        ]);
        if ($result['locked']) {
            $mins = $result['retry_in_minutes'];
            flash("Too many failed attempts. Account locked for {$mins} minute" . ($mins===1?'':'s') . ".","danger");
        } else {
            $left = $result['attempts_remaining'];
            $warn = $left <= 2 ? " ({$left} attempt" . ($left===1?'':'s') . " remaining before lockout)" : '';
            flash("Invalid email or password.{$warn}", 'danger');
        }
        redirect('?page=login');
    }
}
if($page==='logout'){$auth->webLogout();redirect('?page=login');}

// ── FORGOT PASSWORD: send WhatsApp reset link ─────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='forgot_password') {
    $fpPhone = trim($_POST['phone'] ?? '');
    if (!$fpPhone) {
        flash('Please enter your phone number.', 'danger');
        redirect('?page=login&m=forgot');
    }
    $fpRetailer = $auth->findByPhone($fpPhone);
    if ($fpRetailer && ($fpRetailer['is_active'] ?? false)) {
        $fpToken   = $auth->createResetToken((int)$fpRetailer['id']);
        $fpBaseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on' ? 'https' : 'http')
                   . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                   . strtok($_SERVER['REQUEST_URI'] ?? '/public.php', '?');
        $fpLink    = $fpBaseUrl . '?page=reset_password&token=' . urlencode($fpToken);
        $fpMsg     = "\xF0\x9F\x94\x90 *DishNet Password Reset*\n\nHi {$fpRetailer['name']},\n\nTap the link below to set a new password. Valid for *30 minutes*.\n\n{$fpLink}\n\n_If you did not request this, ignore this message._";
        $notify->sendVia('support', $fpRetailer['phone'], $fpMsg, 'pwd_reset_request', [
            'name' => $fpRetailer['name'],
            'link' => $fpLink,
        ]);
    }
    flash('If your number is registered, a WhatsApp reset link has been sent.', 'success');
    redirect('?page=login&m=forgot');
}

// ── RESET PASSWORD: apply new password via token ──────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='reset_password') {
    $rpToken = trim($_POST['token'] ?? '');
    $rpPwd   = trim($_POST['new_password'] ?? '');
    $rpConf  = trim($_POST['confirm_password'] ?? '');
    if (strlen($rpPwd) < 6) {
        flash('Password must be at least 6 characters.', 'danger');
        redirect('?page=reset_password&token=' . urlencode($rpToken));
    }
    if ($rpPwd !== $rpConf) {
        flash('Passwords do not match.', 'danger');
        redirect('?page=reset_password&token=' . urlencode($rpToken));
    }
    if ($auth->consumeResetToken($rpToken, $rpPwd)) {
        flash('Password changed successfully. You can now log in.', 'success');
    } else {
        flash('This reset link has expired or is invalid. Please request a new one.', 'danger');
    }
    redirect('?page=login');
}

// ── FORCED PASSWORD CHANGE CHECK ─────────────────────────────────────────────
// If must_change_pwd is set, redirect any non-change-password action to profile
$_forcePwdChange = !empty($_SESSION['dn_retailer']['must_change_pwd']);
if ($_forcePwdChange && $page === 'dashboard' && ($_POST['action']??'') !== 'change_password') {
    // Allow: logout, change_password POST, and the profile tab
    if ($tab !== 'profile' && ($_POST['action']??'') !== 'change_password') {
        // Will be caught by the modal injected in the HTML
    }
}
