<?php
declare(strict_types=1);
// Local sample serves web/ (config one level up); on the server index.php sits beside config.php.
define('DOCKET_ROOT', is_file(__DIR__ . '/config.php') ? __DIR__ : dirname(__DIR__));
$GLOBALS['cfg'] = $cfg = require DOCKET_ROOT . '/config.php';
// Origin gate (2026-10-01): Madder answers only connections from the USA and Israel. REMOTE_ADDR only (no trusted proxy);
// pinned addresses (geo_always_allow) always get in. Runs before the session, the database or any input.
require DOCKET_ROOT . '/lib/geo.php';
$geo = geo_check($_SERVER['REMOTE_ADDR'] ?? null, $cfg);
if (!$geo['allow']) {
    @file_put_contents(DOCKET_ROOT . '/data/geo-deny.log', gmdate('c') . ' ' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' ' . $geo['reason'] . "\n", FILE_APPEND | LOCK_EX);
    http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit("403 Forbidden\n");
}
foreach (['db', 'auth', 'dates', 'rules', 'mail', 'reminders', 'render', 'uspto', 'gmail_pull', 'held', 'send', 'oafetch', 'feepaid', 'noa', 'sendcheck', 'learn', 'phones', 'dropbox', 'folders', 'receipts', 'issuedates', 'issuance', 'filing', 'annuities', 'clientreport', 'pcfetch', 'docxpdf', 'tmdocket', 'signreq', 'ptcard', 'govfees', 'waiting', 'prepfiling'] as $f) require DOCKET_ROOT . "/lib/$f.php";
if (($_GET['p'] ?? '') === 'pc_req') pc_serve_request((string)($_GET['t'] ?? ''));   // the "Download with Claude" launcher fetches its request by one-time token (no session; lib/pcfetch.php)
auth_start();
auth_sso($cfg);
foreach ($_GET as $k => $v) if (is_array($v)) $_GET[$k] = '';                       // no parameter is a list, except ids[] on POST
foreach ($_POST as $k => $v) if (is_array($v)) $_POST[$k] = in_array($k, ['ids', 'close_ids', 'file_att'], true) ? array_values(array_filter($v, 'is_string')) : '';
if (isset($_GET['asof']) && is_admin()) { $_SESSION['asof'] = is_string($_GET['asof']) && is_iso_date($_GET['asof']) ? $_GET['asof'] : null; }
$GLOBALS['as_of'] = $_SESSION['asof'] ?? null;
if (isset($_GET['logout'])) { audit('logout'); auth_logout(); header('Location: ./'); exit; }
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$p = $_GET['p'] ?? 'dash';

// ------------------------------------------------------------------ login
// This is the ONLY sign-in page (2026-10-01): Mail and Locations send a signed-out visitor here with ?next=mail|locations
// and get them back after the sign-in; mail_signin() gives the attorney and staff the Mail session, the paralegal stays Madder-only.
$next = in_array($_REQUEST['next'] ?? '', ['mail', 'locations'], true) ? $_REQUEST['next'] : '';
$go = function () use ($cfg, $next): string {
    if ($next === '') return '?';
    if (!has_mail()) { $_SESSION['flash'] = 'Mail and Locations are not part of your login.'; return '?'; }
    return rtrim((string)($cfg['mail_base'] ?? 'mail/'), '/') . '/' . ($next === 'locations' ? 'visitors.php' : '');
};
if (auth_user() && $next !== '') { header('Location: ' . $go()); exit; }
if (!auth_user()) {
    $err = null;
    if (($_POST['action'] ?? '') === 'login') {
        $who = substr(trim((string)($_POST['user'] ?? '')), 0, 60);
        if (($wait = login_blocked($who)) > 0) $err = 'Too many failed sign-ins. Try again in ' . max(1, (int)ceil($wait / 60)) . ' minute(s).';   // nothing is checked while paused
        elseif (auth_attempt($cfg, $_POST['user'] ?? '', $_POST['pass'] ?? '')) { login_clear(); audit('login'); header('Location: ' . $go()); exit; }
        else { login_fail($who); audit('login_failed', 'username tried: ' . $who . ' from ' . login_ip()); $left = login_tries_left(); $err = 'Wrong username or password. The username is your first name (michael, joshua or christine) or your email address.' . ($left <= 2 ? ' ' . ($left > 0 ? $left . ' more tr' . ($left === 1 ? 'y' : 'ies') . ' before a ' . (int)(LOGIN_WINDOW / 60) . '-minute pause.' : 'Sign-in from this connection is now paused for ' . (int)(LOGIN_WINDOW / 60) . ' minutes.') : ''); }
    }
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Madder · ' . htmlspecialchars(firm('name')) . '</title><link rel="icon" type="image/png" href="madder-icon.png"><link rel="apple-touch-icon" href="madder-icon.png"><style>' . file_get_contents(DOCKET_ROOT . '/lib/style.css') . '</style></head><body><main><div class="login box"><img class="login-madder" src="madder.jpg" alt=""><h1 style="text-align:center"><span style="color:var(--gold)">' . htmlspecialchars(firm('initials')) . '</span> Madder</h1>';
    if ($err) echo '<p class="bad">' . h($err) . '</p>';
    echo '<form method="post"><input type="hidden" name="action" value="login">' . ($next ? '<input type="hidden" name="next" value="' . h($next) . '">' : '') . '<input type="text" name="user" placeholder="username" autofocus><input type="password" name="pass" placeholder="password"><button>Sign in</button></form>';
    if (!empty($cfg['mail_users'])) echo '<p class="muted">One sign-in for Madder, Mail and Locations.</p>';   // only where the Mail-lookup app shares the login
    echo '</div></main></body></html>';
    exit;
}

// ------------------------------------------------------------------ actions
function redirect(string $to, string $msg = ''): never { if ($msg) $_SESSION['flash'] = $msg; header('Location: ' . $to); exit; }
/** Only our own relative URLs may be redirect targets. Anything with a scheme or a leading slash is dropped. */
function safe_back(?string $b): string { $b = preg_replace('/[\x00-\x20\x7f]/', '', (string)$b); return ($b === '' || $b[0] !== '?' || str_contains($b, ':') || str_contains($b, '//') || str_contains($b, '\\')) ? '?' : $b; }
function is_iso_date(string $d): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)); }
function reports_dir(): string { return dirname($GLOBALS['cfg']['db']) . '/reports'; }
function retire_reminders(int $eventId, string $why): void { run('UPDATE reminders SET status="skipped", reason=? WHERE event_id=? AND status IN ("pending","held")', [$why, $eventId]); }
function back_to(string $back, ?int $eventId): string { return $eventId ? preg_replace('/#.*$/', '', $back) . '#ev' . $eventId : $back; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) redirect('?', 'Session expired, try again.');
    try { handle_action($cfg); } catch (Throwable $e) { audit('error', ($_POST['action'] ?? '?') . ': ' . $e->getMessage()); redirect(safe_back($_POST['back'] ?? '?'), 'That did not work: ' . $e->getMessage()); }
}
function handle_action(array $cfg): void {
    $a = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0); $back = safe_back($_POST['back'] ?? '?');
    $admin = ['cancel', 'reopen', 'snooze', 'run_nightly', 'rem_skip', 'ingest_apply', 'confirm_dead', 'revive', 'rename', 'dbx_start', 'dbx_finish', 'dbx_disconnect', 'dbx_sync_now'];
    if (in_array($a, $admin, true) && !is_admin()) redirect($back, 'Only an admin can do that.');
    if (in_array($a, ['confirm', 'reject', 'confirm_group', 'reject_group'], true) && !can_confirm()) redirect($back, 'Only the attorney or staff can do that.');
    $ev = $id ? q1('SELECT e.*, m.ref FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.id=?', [$id]) : null;
    if (in_array($a, ['confirm', 'reject', 'cancel', 'reopen', 'snooze', 'done_multi', 'send_client'], true) && !$ev) redirect($back, 'No such event.');
    switch ($a) {
        case 'done_multi':
            $ids = array_map('intval', (array)($_POST['ids'] ?? [])); $ids[] = $id; $ids = array_unique($ids);
            // 2026-10-05 (XYZ001): "Extension filed" / "Statement of Use filed" on a Statement of Use deadline (lib/render.php done_inline)
            $souKind = (string)($_POST['sou_kind'] ?? '');
            if ($souKind !== '' && preg_match('/^Statement of Use/', (string)$ev['type'])) {
                $later = array_column(q('SELECT id FROM events WHERE matter_id=? AND status="open" AND type LIKE "Statement of Use%" AND due_date>=? AND id<>?', [$ev['matter_id'], $ev['due_date'], $id]), 'id');
                $ids = $souKind === 'sou' ? array_values(array_unique(array_merge($ids, array_map('intval', $later)))) : array_values(array_diff($ids, array_map('intval', $later)));
                if ($souKind === 'sou') $_POST['no_next'] = '1';
                $_POST['why'] = trim('USPTO filing (' . auth_user() . '): ' . ($souKind === 'sou' ? 'STATEMENT OF USE' : 'EXTENSION request') . (trim((string)($_POST['why'] ?? '')) !== '' ? ' | ' . trim((string)$_POST['why']) : ''));   // the confirm step reads this (sou_closed_by)
            }
            // 2026-10-13 (XYZ001): issue fee paid with the receipt dropped in the Done box → saved into the file first (nothing is closed if that fails), then the client email
            $ifSaved = [];
            if (!empty($_POST['issue_fee']) && is_issue_fee_event($ev) && ($up = $_FILES['receipts'] ?? null) && is_array($up['name'])) {
                $fl = [];
                foreach ($up['name'] as $i => $nm) if (($up['error'][$i] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file($up['tmp_name'][$i])) {
                    $fh = fopen($up['tmp_name'][$i], 'rb'); $mg = fread($fh, 5); fclose($fh); if ($mg !== '%PDF-') redirect(back_to($back, $id), basename($nm) . ' is not a PDF; nothing was saved or marked done.');
                    $fl[] = [$up['tmp_name'][$i], basename($nm)]; }
                if ($fl) {
                    $mm = q1('SELECT * FROM matters WHERE id=?', [$ev['matter_id']]);
                    try { $ifSaved = issue_fee_save($mm, $fl); } catch (Throwable $e) { redirect(back_to($back, $id), $e->getMessage()); }
                    $_POST['why'] = trim('Issue fee paid; receipt saved as ' . implode(', ', $ifSaved) . (trim((string)($_POST['why'] ?? '')) !== '' ? ' | ' . trim((string)$_POST['why']) : ''));
                }
            }
            $n = 0; $msgs = [];
            foreach ($ids as $eid) {
                $x = q1('SELECT e.*, m.ref FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.id=? AND e.matter_id=? AND e.status="open"', [$eid, $ev['matter_id']]); if (!$x) continue;
                if (is_admin()) { run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), (string)($_POST['why'] ?? ''), $eid]); }
                else run('UPDATE events SET status="proposed", proposed_by=?, proposed_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), (string)($_POST['why'] ?? ''), $eid]);
                audit_done($x, trim((string)($_POST['why'] ?? '')), is_admin());
                $n++;
                if (is_admin()) retire_reminders($eid, 'event closed');
                if (is_admin() && $eid === $id && ($nx = next_sou_event($x['type'], $x['due_date'])) && empty($_POST['no_next']) && !q1('SELECT 1 FROM events WHERE matter_id=? AND type=?', [$x['matter_id'], $nx['type']])) { run('INSERT INTO events(matter_id,type,due_date,status,source,note) VALUES (?,?,?,"open","rule",?)', [$x['matter_id'], $nx['type'], $nx['due'], 'Created when "' . $x['type'] . '" was marked done (extension chain)']); $msgs[] = 'Next extension docketed: ' . $nx['type'] . ' ' . fmt_date($nx['due']) . '.'; }
            }
            audit(is_admin() ? 'done' : 'propose', "$ev[ref] $ev[type] (+" . ($n - 1) . ' related)'); 
            if (is_admin() && is_postreg_deadline((string)$ev['type'])) run('UPDATE events SET status="cancelled", done_at=datetime("now"), note=note_add(note, ?) WHERE matter_id=? AND type=? AND status="open"', [$ev['type'] . ' done ' . today(), $ev['matter_id'], POSTREG_SIGN_EVENT]);   // 2026-10-14: no signing to-do left behind
            if ($souKind === 'sou') run('UPDATE events SET status="cancelled", done_at=datetime("now"), note=note_add(note, ?) WHERE matter_id=? AND type=? AND status="open"', ['Statement of Use filed ' . today(), $ev['matter_id'], SOU_SIGN_EVENT]);   // 2026-10-12 (XYZ060): no signing to-do left behind
            if (isset(SOU_REPORT_TEMPLATES[$souKind === 'sou' ? 'sou_filed' : ($souKind === 'ext' ? 'sou_ext' : '')]))   // 2026-10-05: then the client email
                redirect(url('send', ['id' => $id, 'report' => $souKind === 'sou' ? 'sou_filed' : 'sou_ext', 'back' => $back]), ($souKind === 'sou' ? 'Statement of Use' : 'Extension') . ' recorded. Now the client email:');
            if (!empty($_POST['issue_fee']) && is_issue_fee_event($ev) && auth_email() !== '')   // 2026-10-13: the client email follows even when no receipt was dropped
                redirect(url('send', ['id' => $id, 'report' => 'issue_fee', 'back' => $back]), $ifSaved ? 'Saved ' . implode(', ', $ifSaved) . '. Now the client email:' : 'Issue fee recorded (no receipt was dropped; add it below with "Add an attachment"). Now the client email:');
            redirect(back_to($back, $id), (is_admin() ? "$n event(s) marked done." : "$n event(s) marked done, awaiting an admin's confirmation.") . ' ' . implode(' ', $msgs));
        case 'new_filing':   // Create/Delete > New filing (2026-10-02)
            if (auth_email() === '') redirect($back, 'Your login has no sending address.');
            $fl = []; $up = $_FILES['receipts'] ?? null;
            if ($up && is_array($up['name'])) foreach ($up['name'] as $i => $nm) if (($up['error'][$i] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file($up['tmp_name'][$i])) {
                $fh = fopen($up['tmp_name'][$i], 'rb'); $mg = fread($fh, 5); fclose($fh); if ($mg !== '%PDF-') redirect(url('newfiling', ['ref' => $_POST['ref'] ?? '']), basename($nm) . ' is not a PDF; nothing was saved.');
                $fl[] = [$up['tmp_name'][$i], basename($nm)]; }
            try { $r = new_filing((string)($_POST['ref'] ?? ''), $fl); } catch (Throwable $e) { redirect(url('newfiling', ['ref' => $_POST['ref'] ?? '']), $e->getMessage()); }
            redirect(url('send', ['id' => $r['event_id'], 'back' => url('matter', ['id' => $r['matter']['id']])]), 'Filed into ' . $r['matter']['ref'] . ': ' . implode('; ', $r['notes']));
        case 'prepare_filing':   // New filing > Prepare filing (2026-10-11, lib/prepfiling.php)
            $keep = array_intersect_key($_POST, array_flip(['ref', 'inventors', 'entity', 'assignment', 'assignee', 'assignee_address', 'assignee_type', 'signer', 'signer_title', 'ids', 'ids_refs', 'links']));
            try { $_SESSION['prep_result'] = $pr = prepare_filing($keep); unset($_SESSION['prep_form']); } catch (Throwable $e) { $_SESSION['prep_form'] = $keep; redirect(url('newfiling', ['pref' => strtoupper(trim((string)($keep['ref'] ?? '')))]), $e->getMessage()); }
            if (!empty($pr['event_id']) && auth_email() !== '') redirect(url('send', ['id' => $pr['event_id'], 'back' => url('newfiling', ['prepdone' => 1])]), $pr['ref'] . ': ' . count($pr['saved']) . ' papers saved in ' . $pr['folder'] . '. Now the email to the client:');   // 2026-10-11: draft the client email, like the trademark one
            redirect(url('newfiling', ['prepdone' => 1]));
        case 'sign_request':   // New filing > Ask client to sign trademark (2026-10-06, lib/signreq.php)
            if (auth_email() === '') redirect($back, 'Your login has no sending address.');
            $refs = sign_refs((string)($_POST['refs'] ?? ''));
            try { $r = sign_request($refs); } catch (Throwable $e) { redirect(url('newfiling', ['sign' => $_POST['refs'] ?? '']), $e->getMessage()); }
            redirect(url('send', ['id' => $r['event_id'], 'back' => url('newfiling')]), $r['missing'] ? 'No signature e-mail found for ' . implode(', ', $r['missing']) . '; it is not in this email.' : '');
        case 'sou_prepared':   // Statement of Use prepared (2026-10-08, lib/signreq.php): find the TEAS e-sign link, open the signing email
            if (auth_email() === '') redirect($back, 'Your login has no sending address.');
            $sev = q1('SELECT * FROM events WHERE id=?', [$id]); if (!$sev) redirect($back, 'No such event.');
            try { $r = sou_sign_request($sev); } catch (Throwable $e) { redirect($back, $e->getMessage()); }
            redirect(url('send', ['id' => $r['event_id'], 'back' => url('matter', ['id' => $r['matter']['id']])]));
        case 'postreg_prepared':   // Declaration / renewal prepared (2026-10-14, lib/signreq.php): the Trademark Center signing link → the signing email
            if (auth_email() === '') redirect($back, 'Your login has no sending address.');
            $sev = q1('SELECT * FROM events WHERE id=?', [$id]); if (!$sev) redirect($back, 'No such event.');
            try { $r = postreg_sign_request($sev); } catch (Throwable $e) { redirect($back, $e->getMessage()); }
            redirect(url('send', ['id' => $r['event_id'], 'back' => url('matter', ['id' => $r['matter']['id']])]));
        case 'add_matter':
            $ref = strtoupper(trim((string)($_POST['ref'] ?? ''))); $code = client_from_ref($ref); $kind = $_POST['kind'] ?? '';
            if ($code === '') redirect($back, 'File number must be three letters and three digits, e.g. XYZ001.');
            if (!in_array($kind, ['patent', 'trademark', 'copyright', 'general'], true)) redirect($back, 'Pick a kind.');
            if (($_POST['filing_date'] ?? '') !== '' && !is_iso_date($_POST['filing_date'])) redirect($back, 'Bad filing date.');
            if (q1('SELECT 1 FROM matters WHERE ref=? AND kind=?', [$ref, $_POST['kind']])) redirect($back, "$ref already exists.");
            $isNew = !q1('SELECT 1 FROM clients WHERE code=?', [$code]);
            run('INSERT OR IGNORE INTO clients(code) VALUES (?)', [$code]);
            if (trim($_POST['client_name'] ?? '') !== '') run('UPDATE clients SET name=CASE WHEN name="" THEN ? ELSE name END WHERE code=?', [trim($_POST['client_name']), $code]);
            if (trim($_POST['client_email'] ?? '') !== '') run('UPDATE clients SET email=CASE WHEN email="" THEN ? ELSE email END WHERE code=?', [trim($_POST['client_email']), $code]);
            run('INSERT INTO matters(kind,ref,client_code,title,country,app_no,filing_date,notes) VALUES (?,?,?,?,?,?,?,?)', [$_POST['kind'], $ref, $code, trim($_POST['title'] ?? ''), trim($_POST['country'] ?? 'United States') ?: 'United States', preg_replace('/\D/', '', $_POST['app_no'] ?? ''), $_POST['filing_date'] ?: null, 'Added by ' . auth_user() . ' ' . date('Y-m-d')]);
            $mid = (int)db()->lastInsertId(); audit('add_matter', "$ref " . ($isNew ? "(new client $code)" : ''), $mid);
            if ($_POST['kind'] === 'patent' && ($_POST['app_type'] ?? '') === 'provisional') run('UPDATE matters SET app_type="Provisional" WHERE id=?', [$mid]);
            if ($_POST['kind'] === 'patent') ensure_priority_deadline($mid, true);   // entered by hand, as before
            $tsd = $_POST['kind'] === 'trademark' ? tm_docket_from_tsdr($mid) : '';   // 2026-10-05 (ORI001): the open deadlines from the USPTO record
            redirect(url('matter', ['id' => $mid]), 'Matter ' . $ref . ' created' . ($isNew ? ', and client ' . $code . ' added' : '') . '.' . ($tsd !== '' ? ' ' . $tsd : ''));
        case 'check_apply':
            $ids = array_map('intval', (array)($_POST['ids'] ?? [])); $n = 0; $mid = (int)($_POST['matter_id'] ?? 0);
            foreach ($ids as $eid) { $x = q1('SELECT e.*, m.ref FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.id=? AND e.matter_id=? AND e.status="open"', [$eid, $mid]); if (!$x) continue;
                if (is_admin()) { run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'per USPTO check ' . date('Y-m-d'), $eid]); retire_reminders($eid, 'event closed'); }
                else run('UPDATE events SET status="proposed", proposed_by=?, proposed_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'per USPTO check ' . date('Y-m-d'), $eid]);
                audit_done($x, 'per USPTO check', is_admin());
                $n++; }
            audit('check_apply', "$n events marked from the USPTO record", $mid); redirect(url('matter', ['id' => $mid]), is_admin() ? "$n event(s) marked done from the USPTO record." : "$n event(s) proposed done, awaiting an admin.");
        case 'send_client':
            if (auth_email() === '') redirect($back, 'Your login has no sending address.');
            if ($ev && !in_array($ev['status'], ['open', 'proposed'], true) && !(!empty($_POST['feepaid']) && fee_paid_event_ok($ev)) && !isset(SOU_REPORT_TEMPLATES[(string)($_POST['report'] ?? '')])) redirect($back, 'That event is no longer open (' . $ev['status'] . '); nothing sent.');   // proposed = e.g. the sweep saw the fee paid; still reportable
            if (!empty($_POST['warned']) && empty($_POST['send_anyway'])) redirect($back, 'Not sent: the pre-send check flagged this email; tick "Send anyway" if it is still needed.');
            $to = trim($_POST['to'] ?? ''); if ($to === '') redirect($back, 'No recipient address.');
            $m = q1('SELECT * FROM matters WHERE id=?', [(int)($_POST['matter_id'] ?? 0)]); if (!$m) redirect($back, 'No such matter.');
            $mode = ($_POST['mode'] ?? 'sent') === 'draft' ? 'draft' : 'sent';
            // A double click posted XYZ001 twice (2026-09-29): the same email to the same people within 2 minutes is refused.
            if ($mode === 'sent' && empty($_POST['ob']) && q1("SELECT 1 FROM outbox WHERE matter_id=? AND to_email=? AND subject=? AND mode='sent' AND COALESCE(transmit_error,'')='' AND created_at >= datetime('now','-2 minutes')", [$m['id'], $to, (string)($_POST['subject'] ?? '')])) redirect($back, 'That email was already sent a moment ago; not sent again.');
            $irt = !empty($_POST['in_thread']) && preg_match('/^<[^<>\s]+>$/', trim((string)($_POST['thr_msgid'] ?? ''))) ? trim($_POST['thr_msgid']) : '';
            $refs = $irt !== '' && preg_match_all('/<[^<>\s]+>/', (string)($_POST['thr_refs'] ?? ''), $rm) ? implode(' ', array_slice($rm[0], -20)) : '';
            // Re-opened draft (ob=): update that row; otherwise a new row. Drafts are held in Docket until "Send now".
            $ob = !empty($_POST['ob']) ? q1('SELECT * FROM outbox WHERE id=? AND transmitted_at IS NULL AND mode IN ("draft","sent")', [(int)$_POST['ob']]) : null;
            if ($ob && !is_admin() && $ob['from_user'] !== auth_user()) redirect($back, 'That draft belongs to ' . $ob['from_user'] . '.');
            $fwd = '';
            // A notice report forwards the USPTO notice itself below the signature (the paralegal's wording says so); if Gmail cannot find it, the email goes without it.
            if ($ev && ($nk = notice_report_kind((string)$ev['type'])) && !str_starts_with($nk, 'ASSIGNMENT')) { try { $fn = trim((string)($ev['gmail_id'] ?? '')) !== '' ? gmail_notice_by_id((string)$ev['gmail_id']) : gmail_uspto_notice((string)$m['ref'], $nk === 'REGISTRATION' ? 'NOTICE OF REGISTRATION' : $nk); if ($fn) $fwd = forward_block($fn); } catch (Throwable $e) { audit('error', 'notice forward ' . $m['ref'] . ': ' . $e->getMessage(), (int)$m['id']); } }
            // (the Notice of Allowance report attaches the TSDR PDF from the file instead of forwarding the notice email (2026-09-29)
            if ($ob) { run('UPDATE outbox SET from_user=?, from_email=?, to_email=?, cc_email=?, subject=?, body=?, mode=?, transmit_error="" WHERE id=?', [auth_user(), auth_email(), $to, auth_cc(), $_POST['subject'] ?? '', str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')), $mode, $ob['id']]); $obId = (int)$ob['id']; }
            else { run('INSERT INTO outbox(event_id,matter_id,from_user,from_email,to_email,cc_email,subject,body,mode) VALUES (?,?,?,?,?,?,?,?,?)', [$id ?: null, $m['id'], auth_user(), auth_email(), $to, auth_cc(), $_POST['subject'] ?? '', str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')), $mode]); $obId = (int)db()->lastInsertId(); }
            run('UPDATE outbox SET fwd_html=? WHERE id=?', [$fwd, $obId]);
            try { $before = $ob ? outbox_attachments($ob) : []; $att = store_uploads($obId, $before); $newUp = array_slice($att, count($before)); $att = attach_matter_files($obId, $att, (array)($_POST['file_att'] ?? []), $m['ref']); $att = attach_order($att, count($before), count($newUp), (array)(json_decode((string)($_POST['att_order'] ?? '[]'), true) ?: [])); run('UPDATE outbox SET attachments=? WHERE id=?', [json_encode($att), $obId]); }
            catch (Throwable $e) { run('UPDATE outbox SET mode="draft" WHERE id=?', [$obId]); redirect(url('send', ['id' => $id, 'ob' => $obId, 'back' => $back]), 'Attachment problem: ' . $e->getMessage() . '. Held in drafts, nothing sent.'); }
            run('UPDATE outbox SET in_reply_to=?, refs_hdr=? WHERE id=?', [$irt, $refs, $obId]);
            // The earlier conversation goes under the reply, as Gmail would quote it; if Gmail cannot be reached the reply still goes, unquoted.
            $quote = ''; if ($irt !== '') { try { $quote = gmail_quote_html($irt); } catch (Throwable $e) { audit('error', 'quoting ' . $irt . ': ' . $e->getMessage(), (int)$m['id']); } }
            run('UPDATE outbox SET quote_html=? WHERE id=?', [$quote, $obId]);
            run('UPDATE outbox SET card_html=? WHERE id=?', [tm_card_applies($m, $ev) ? tm_card($m, $ev) : (pt_card_applies($m, $ev) ? pt_card($m, $ev, (string)($_POST['report'] ?? '') === 'issue_fee' ? 'Issue fee paid' : '') : ($ev && is_filing_report((string)$ev['type']) ? filing_card_html($m, $ev) : '')), $obId]);   // every US trademark email opens with the timeline card (2026-10-04); a patent filing report with its summary card
            // Accounts payable gets the email only when an invoice goes with it (2026-09-29, GEO): a file named "invoice", or a
            // PDF uploaded on an email whose template expects the invoice.
            $cl = q1('SELECT * FROM clients WHERE code=?', [$m['client_code']]); $ap = trim((string)($m['ap_email'] ?? '')) ?: trim((string)($cl['email_ap'] ?? '')); $apAdded = false;   // a matter's own accounts payable wins (2026-10-14)
            $tplInv = $ev ? (int)((q1('SELECT needs_invoice FROM templates WHERE event_type=?', [tpl_type($ev['type'], (string)$m['app_no'])]) ?: q1('SELECT needs_invoice FROM templates WHERE event_type=?', [$ev['type']]))['needs_invoice'] ?? 0) : 0;
            if ($ap !== '' && (array_filter($att, fn($a) => stripos((string)$a['name'], 'invoice') !== false) || ($tplInv && $newUp)) && stripos($to, $ap) === false) {
                run('UPDATE outbox SET to_email=? WHERE id=?', [$to . '; ' . $ap, $obId]); $apAdded = true;
            }
            // The guard (2026-10-14, XYZ001): an address not on file for this matter holds the email in drafts until it is confirmed.
            if ($mode === 'sent' && empty($_POST['confirm_to']) && ($unk = unknown_recipients($to, $m, $cl ?: []))) {
                run('UPDATE outbox SET mode="draft" WHERE id=?', [$obId]);
                redirect(url('send', array_filter(['id' => $id, 'ob' => $obId, 'rem' => $_POST['rem'] ?? null, 'report' => $_POST['report'] ?? null, 'feepaid' => $_POST['feepaid'] ?? null, 'back' => $back, 'unk' => implode(', ', $unk)])), 'Not sent: ' . implode(', ', $unk) . ' ' . (count($unk) > 1 ? 'are' : 'is') . ' not on file for ' . $m['ref'] . (is_assoc_matter($m) ? ' (an associate matter: its instructing attorney only)' : '') . '. Held in drafts; tick "These recipients are right" to send.');
            }
            $tx = ($mode === 'sent' && mail_live()) ? transmit_outbox($obId) : null;
            // $went: the email really left (or this copy only records to the outbox). A send the mail server refused must not look sent:
            // no "emailed client" note, the queued reminders stay queued, the client record is not touched (bug audit 2026-10-01).
            $went = $mode === 'sent' && ($tx === null || $tx['ok']);
            if ($id && $went) run('UPDATE events SET note=note_add(note, ?) WHERE id=?', ['emailed client ' . date('Y-m-d') . ' by ' . auth_user(), $id]);
            if ($went && $m && isset(SOU_REPORT_TEMPLATES[(string)($_POST['report'] ?? '')])) sou_report_close_receipt_todo((int)$m['id'], (string)$_POST['report'] === 'issue_fee' ? 'issue fee' : 'Statement of Use');   // one email per filing (2026-10-12)
            audit($mode === 'draft' ? 'draft_held' : ($went ? 'email' : 'email_not_sent'), ($mode === 'draft' ? 'Draft held for ' : ($went ? 'Emailed ' : 'NOT transmitted (' . ($tx['error'] ?? '') . ') to ')) . $to . ($apAdded ? '; ' . $ap . ' (invoice attached)' : '') . (count($att) ? ' with ' . count($att) . ' attachment(s)' : '') . ': ' . ($_POST['subject'] ?? ''), (int)$m['id']);
            // 2026-10-01: the client's MAIN address is never replaced by a send (a reply to someone in a thread used to overwrite it, so
            // every later reminder followed that one-off recipient). A client with no address yet gets this one; otherwise addresses not
            // seen before are added to the known addresses (the chips in the Client box), where they can be promoted by hand.
            if ($cl && $went && !is_assoc_matter($m)) {   // associate matters keep their recipients on the matter, not the client (2026-10-14)
                $sentTo = array_values(array_filter(split_addresses($to), fn($a) => !firm_address($a)));
                if (trim((string)$cl['email']) === '' && $sentTo) run('UPDATE clients SET email=? WHERE code=?', [implode(', ', $sentTo), $m['client_code']]);
                else {
                    // 2026-10-06: an address ADDED on the send page (typed, or a Gmail chip; not one the page filled in, e.g. from a
                    // thread) is kept for good: it joins the list this kind of email goes to (patent / trademark list if the client has
                    // one, else the main address), so it is filled in next time. An address with "billing" or "accounts" in it becomes an
                    // accounts-payable address instead: it gets every email that carries an invoice.
                    $pre = array_map('strtolower', split_addresses((string)($_POST['to_pre'] ?? '')));
                    $added = isset($_POST['to_pre']) ? array_values(array_filter($sentTo, fn($a) => !in_array(strtolower($a), $pre, true))) : [];   // a page opened before 2026-10-06 does not post to_pre
                    $k = in_array((string)$m['kind'], ['trademark', 'copyright'], true) ? 'email_tm' : ((string)$m['kind'] === 'patent' ? 'email_patent' : '');
                    $field = ($k !== '' && trim((string)($cl[$k] ?? '')) !== '') ? $k : 'email';
                    $lists = ['email_ap' => split_addresses((string)($cl['email_ap'] ?? '')), $field => split_addresses((string)($cl[$field] ?? ''))];
                    $kept = [];
                    foreach ($added as $a) { $dest = preg_match('/billing|accounts/i', $a) ? 'email_ap' : $field;
                        if (!in_array(strtolower($a), array_map('strtolower', $lists[$dest]), true)) { $lists[$dest][] = $a; $kept[] = $a . ($dest === 'email_ap' ? ' (invoices)' : ''); } }
                    if ($kept) {
                        $alt = array_values(array_filter(split_addresses((string)$cl['alt_emails']), fn($x) => !in_array(strtolower($x), array_map('strtolower', $added), true)));
                        run("UPDATE clients SET $field=?, email_ap=?, alt_emails=? WHERE code=?", [implode(', ', $lists[$field]), implode(', ', $lists['email_ap']), implode('; ', $alt), $m['client_code']]);
                        audit('client_email', $m['client_code'] . ': added ' . implode(', ', $kept), (int)$m['id']);
                    }
                    // anything else not seen before (e.g. a thread recipient left in) stays a known address, as before
                    $knownTo = array_map('strtolower', split_addresses(implode(';', [$cl['email'] ?? '', $cl['email_tm'] ?? '', $cl['email_patent'] ?? '', $cl['email_ap'] ?? '', $cl['alt_emails'] ?? '', implode(';', $added)])));
                    $fresh = array_values(array_filter($sentTo, fn($a) => !in_array(strtolower($a), $knownTo, true)));
                    if ($fresh) run('UPDATE clients SET alt_emails=? WHERE code=?', [trim(trim((string)(q1('SELECT alt_emails FROM clients WHERE code=?', [$m['client_code']])['alt_emails'] ?? '')) . '; ' . implode('; ', $fresh), '; '), $m['client_code']]);
                }
            }
            if (!empty($_POST['rem']) && ($mode === 'draft' || $went)) run('UPDATE reminders SET status=?, to_email=?, subject=?, body=?, sent_at=datetime("now"), reason=? WHERE id=? AND event_id=?', [$mode, $to, $_POST['subject'] ?? '', str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')), ($mode === 'sent' ? 'Sent' : 'Held in drafts') . ' by ' . auth_user() . ' as ' . auth_email(), (int)$_POST['rem'], $id]);
            if ($id && $went) run('UPDATE reminders SET status="skipped", reason=? WHERE event_id=? AND status IN ("pending","held")', ['Superseded: emailed by hand by ' . auth_user() . ' ' . date('Y-m-d'), $id]);
            // Fee paid: close the paid cycle's events once the report actually went (or this copy is in outbox mode).
            $closed = '';
            // The Notice of Allowance report went out: its dashboard to-do is finished (a Docket-only to-do, so no confirmation step).
            if (($_POST['report'] ?? '') === 'noa' && $mode === 'sent' && ($tx === null || $tx['ok']))
                foreach (q('SELECT * FROM events WHERE matter_id=? AND type=? AND status IN ("open","proposed")', [$m['id'], NOA_REPORT_EVENT]) as $x) {
                    run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'report emailed ' . date('Y-m-d'), $x['id']]);
                    audit_done($x, 'report emailed to ' . $to); $closed .= ' "Report Notice of Allowance" marked done.';
                }
            if ($id && $went && ($re = q1('SELECT * FROM events WHERE id=? AND status IN ("open","proposed")', [$id])) && (notice_report_kind((string)$re['type']) || is_issue_report((string)$re['type']) || $re['type'] === ISSUANCE_REPORT_EVENT || is_filing_report((string)$re['type']) || is_oa_report((string)$re['type']) || is_notice_todo((string)$re['type']))) {   // is_notice_todo: 'Process USPTO Notice' (2026-10-04); is_oa_report: the Office Action / patent NOA report (2026-10-04)
                run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'report emailed ' . date('Y-m-d'), $id]);
                audit_done($re, 'report emailed to ' . $to); $closed .= ' "' . $re['type'] . '" marked done.';
            }
            if ($id && $went && ($se = q1('SELECT * FROM events WHERE id=? AND type=? AND status IN ("open","proposed")', [$id, SIGN_EVENT]))) {   // 2026-10-06
                run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'signing links emailed ' . date('Y-m-d'), $id]);
                audit_done($se, 'signing links emailed to ' . $to); $closed .= ' "' . SIGN_EVENT . '" marked done.'; waiting_after_sign_email($se); $closed .= ' Added to Waiting on client.';
            }
            if ($id && $went && ($se = q1('SELECT * FROM events WHERE id=? AND type=? AND status IN ("open","proposed")', [$id, PAT_SIGN_EVENT]))) {   // 2026-10-11
                run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'signing links emailed ' . date('Y-m-d'), $id]);
                audit_done($se, 'signing links emailed to ' . $to); $closed .= ' "' . PAT_SIGN_EVENT . '" marked done.'; waiting_after_sign_email($se); $closed .= ' Added to Waiting on client.';
            }
            if ($id && $went && ($se = q1('SELECT * FROM events WHERE id=? AND type=? AND status IN ("open","proposed")', [$id, SOU_SIGN_EVENT]))) {   // 2026-10-08
                run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'signing link emailed ' . date('Y-m-d'), $id]);
                audit_done($se, 'signing link emailed to ' . $to); $closed .= ' "' . SOU_SIGN_EVENT . '" marked done.'; waiting_after_sign_email($se); $closed .= ' Added to Waiting on client.';
                $sl = sign_links_for($id) ?: []; $dl = (int)(($sl ? reset($sl) : [])['deadline_id'] ?? 0);
                if ($dl) run('UPDATE events SET note=note_add(note, ?) WHERE id=? AND status IN ("open","proposed")', ['prepared; sent to client to sign ' . date('Y-m-d'), $dl]);
            }
            if ($id && $went && ($se = q1('SELECT * FROM events WHERE id=? AND type=? AND status IN ("open","proposed")', [$id, POSTREG_SIGN_EVENT]))) {   // 2026-10-14
                run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'signing link emailed ' . date('Y-m-d'), $id]);
                audit_done($se, 'signing link emailed to ' . $to); $closed .= ' "' . POSTREG_SIGN_EVENT . '" marked done.'; waiting_after_sign_email($se); $closed .= ' Added to Waiting on client.';
                $sl = sign_links_for($id) ?: []; $dl = (int)(($sl ? reset($sl) : [])['deadline_id'] ?? 0);
                if ($dl) run('UPDATE events SET note=note_add(note, ?) WHERE id=? AND status IN ("open","proposed")', ['prepared; sent to client to sign ' . date('Y-m-d'), $dl]);
            }
            if ($id && $went && ($ae = q1('SELECT * FROM events WHERE id=? AND type=? AND status IN ("open","proposed")', [$id, ABANDON_REPORT_EVENT]))) {
                run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'abandonment email sent ' . date('Y-m-d'), $id]);
                audit_done($ae, 'abandonment email sent to ' . $to); $closed .= ' "' . ABANDON_REPORT_EVENT . '" marked done.';
                if (!(int)(q1('SELECT dead FROM matters WHERE id=?', [$m['id']])['dead'] ?? 0)) $back = url('matter', ['id' => $m['id'], 'ask_inactive' => 1]);   // 2026-10-02: now ask whether to make it inactive
            }
            // A window-opening reminder (… Begin(s) / … Window Open / Remind Client): the email IS the task (XYZ020.1, 2026-10-12).
            // the attorney's send closes it; anyone else's goes to the confirm queue. Only this event: the paired deadline stays open.
            if ($id && $went && ($we = q1('SELECT * FROM events WHERE id=? AND status="open"', [$id])) && (paired_deadline_type((string)$we['type']) !== null || preg_match('/^Remind Client/i', (string)$we['type']))) {
                if (is_admin()) { run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'reminder emailed ' . date('Y-m-d'), $id]); retire_reminders($id, 'event closed'); }
                else run('UPDATE events SET status="proposed", proposed_by=?, proposed_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'reminder emailed ' . date('Y-m-d'), $id]);
                audit_done($we, 'reminder emailed to ' . $to, is_admin()); $closed .= ' "' . $we['type'] . '"' . (is_admin() ? ' marked done.' : " marked done, awaiting an admin's confirmation.");
            }
            if (!empty($_POST['feepaid']) && $mode === 'sent' && ($tx === null || $tx['ok'])) {
                $n = 0; $cur = fee_current_next((int)$m['id'])[0]; $allowed = $cur ? array_map(fn($e) => (int)$e['id'], array_values($cur['events'])) : [];
                foreach (array_map('intval', (array)($_POST['close_ids'] ?? [])) as $eid) {
                    if (!in_array($eid, $allowed, true) || !($x = q1('SELECT * FROM events WHERE id=? AND matter_id=? AND status IN ("open","proposed")', [$eid, $m['id']]))) continue;
                    if (is_admin()) { run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'fee paid; receipt emailed ' . date('Y-m-d'), $eid]); retire_reminders($eid, 'fee paid'); }
                    else run('UPDATE events SET status="proposed", proposed_by=?, proposed_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'fee paid; receipt emailed ' . date('Y-m-d'), $eid]);
                    audit_done($x, 'fee paid; receipt emailed', is_admin());
                    $n++;
                }
                // Nothing left open (closed by hand earlier): mark the cycle as reported so the button moves on to the next cycle.
                if (!$n && $cur && ($de = $cur['events']['due'] ?? null)) run('UPDATE events SET note=note_add(note, ?) WHERE id=?', ['fee-paid report emailed ' . date('Y-m-d'), $de['id']]);
                if ($n) { audit(is_admin() ? 'done' : 'propose', "$m[ref] fee paid: $n event(s)", (int)$m['id']); $closed = ' ' . $n . (is_admin() ? ' fee event(s) marked done.' : " fee event(s) marked done, awaiting an admin's confirmation."); }
            }
            // Sent from a HELD reminder by the attorney = he accepts that template, with his edits (2026-09-29).
            if ($mode === 'sent' && is_admin() && !empty($_POST['learn']) && !empty($_POST['learn_tpl']) && ($tx === null || $tx['ok'])
                && ($lt = q1('SELECT * FROM templates WHERE event_type=?', [(string)$_POST['learn_tpl']])) && ($fe = q1('SELECT * FROM events WHERE id=? AND matter_id=?', [(int)($_POST['learn_ev'] ?? 0), $m['id']]))) {
                $lr = learn_template($lt, (string)($_POST['body'] ?? ''), $irt !== '' ? null : (string)($_POST['subject'] ?? ''), $fe, $m, $cl ?: ['code' => $m['client_code'], 'name' => '', 'email' => '']);
                if ($lr['ok']) {
                    run('UPDATE templates SET subject=?, body=?, approved=1, approved_by=?, approved_at=datetime("now") WHERE event_type=?', [$lr['subject'], $lr['body'], auth_user(), $lt['event_type']]);
                    $rel = release_held('e.type=?', [$lt['event_type']]); $rf = $lr['changed'] ? refresh_queued('e.type=?', [$lt['event_type']]) : 0;
                    audit('approve_tpl', $lt['event_type'] . ' accepted by sending ' . $m['ref'] . ($lr['changed'] ? ' (with his edits)' : '') . ($rel ? ", $rel held reminders released" : ''), (int)$m['id']);
                    $closed .= ' Template "' . $lt['event_type'] . '" ' . ($lr['changed'] ? 'saved with your edits and approved' : 'approved') . ($rel ? "; $rel held reminder(s) released" : '') . '.';
                } else { audit('learn_tpl_failed', $lt['event_type'] . ' edits from ' . $m['ref'] . ' could not be mapped onto the template', (int)$m['id']); $closed .= ' Template "' . $lt['event_type'] . '" NOT changed or approved: your edits touch client-specific text (e.g. the goods list) and could not be turned back into template wording. Edit it on the Templates page.'; }
            }
            if ($mode === 'draft') redirect(url('reminders', ['st' => 'all']), 'Held in drafts for ' . $to . (count($att) ? ' with ' . count($att) . ' attachment(s)' : '') . '. Open it under Drafts to add the invoice and send.');
            if ($tx === null) redirect($back, 'Recorded for ' . $to . ' but NOT transmitted: this copy of Madder is in outbox mode.' . $closed);
            redirect($back, $tx['ok'] ? 'Transmitted to ' . $to . ' as ' . auth_email() . (auth_cc() ? ' (cc ' . auth_cc() . ')' : '') . '. A copy is in your own mailbox.' . $closed : 'NOT transmitted: ' . $tx['error'] . '. It is kept under Reminders → Sent by hand for a retry.' . (!empty($_POST['feepaid']) ? ' The fee events were left open.' : ''));
        case 'transmit': if (!is_admin() && (q1('SELECT from_user FROM outbox WHERE id=?', [$id])['from_user'] ?? '') !== auth_user()) redirect($back, 'Not yours to transmit.'); $tx = transmit_outbox($id); redirect($back, $tx['ok'] ? 'Transmitted.' : 'NOT transmitted: ' . $tx['error']);
        case 'discard_outbox': if (!is_admin()) redirect($back, 'Admin only.'); run('UPDATE outbox SET mode="discarded" WHERE id=? AND transmitted_at IS NULL', [$id]); audit('outbox_discarded', "outbox $id"); redirect($back, 'Discarded; it will never be transmitted.');
        case 'fee_paid_undo':
            $mid = (int)($_POST['matter_id'] ?? 0); $mm = q1('SELECT * FROM matters WHERE id=?', [$mid]); if (!$mm) redirect($back, 'No such matter.');
            $b = fee_paid_last_batch($mid); if (!$b) redirect($back, 'Nothing to undo.');
            if (!fee_paid_can_undo($b)) redirect($back, 'Only an admin or the person who sent it can undo that.');
            $n = 0;
            foreach ($b['events'] as $x) {
                run('UPDATE events SET status="open", done_by=NULL, done_at=NULL, proposed_by=NULL, proposed_at=NULL, note=trim(replace(replace(note, ?, ""), " |  | ", " | "), " |") WHERE id=?', ['fee paid; receipt emailed ' . $b['date'], $x['id']]);
                run('DELETE FROM reminders WHERE event_id=? AND status IN ("skipped","held")', [$x['id']]);
                $n++;
            }
            audit('fee_paid_undo', "$mm[ref] fee paid undone: $n event(s) reopened", $mid);
            redirect($back, "$n event(s) reopened. The email that went out on $b[date] was not recalled.");
        case 'set_active':   // Active / Inactive selector on the matter page (2026-09-25)
            $mm = q1('SELECT * FROM matters WHERE id=?', [(int)($_POST['matter_id'] ?? 0)]); if (!$mm) redirect($back, 'No such matter.');
            $state = ($_POST['state'] ?? '') === 'inactive' ? 'inactive' : 'active'; $reason = trim((string)($_POST['reason'] ?? ''));
            if ($state === 'active') {
                if (!$mm['dead']) redirect($back, $mm['ref'] . ' is already active.');
                if (!is_admin()) redirect($back, 'Only an admin can make a property active again.');
                $_POST['matter_id'] = $mm['id']; $a = 'revive';   // same as "Not dead": reopens what the closure closed
            } else {
                if ($mm['dead'] && !q1('SELECT 1 FROM events WHERE matter_id=? AND status IN ("open","proposed")', [$mm['id']])) redirect($back, $mm['ref'] . ' is already inactive.');
                if ($reason === '') $reason = 'no reason given';   // 2026-09-30: never block on a missing reason
                run('UPDATE matters SET dead=1, dead_reason=?, dead_at=?, status="Inactive", updated_at=datetime("now") WHERE id=?', [$reason . ' (marked inactive by ' . auth_user() . ')', today(), $mm['id']]);
                if (is_abandon_status($reason)) mark_abandoned((int)$mm['id'], today(), $reason . ' (marked inactive by ' . auth_user() . ')');   // 2026-10-01: abandoned -> one client email
                if (is_admin()) { $_POST['matter_id'] = $mm['id']; $a = 'confirm_dead'; }   // closes every remaining event now
                else {   // staff: every remaining event is PROPOSED done; an admin confirms with one click in the queue
                    $n = run('UPDATE events SET status="proposed", proposed_by=?, proposed_at=datetime("now"), note=note_add(note, ?) WHERE matter_id=? AND status="open"', [auth_user(), 'Property is dead: ' . $reason, $mm['id']]);
                    audit('set_inactive', $mm['ref'] . ' marked inactive (' . $reason . '): ' . $n . ' events proposed done', (int)$mm['id']);
                    redirect($back, $mm['ref'] . ' marked inactive; ' . $n . ' event(s) proposed done, awaiting an admin\'s confirmation.');
                }
            }
            // fall through into confirm_dead / revive with $a rewritten
            if ($a === 'revive') goto do_revive;
        case 'confirm_dead':
            $mm = q1('SELECT * FROM matters WHERE id=?', [(int)($_POST['matter_id'] ?? 0)]); if (!$mm) redirect($back, 'No such matter.');
            if (!$mm['dead']) redirect($back, 'That matter is not marked dead. Run a USPTO check first, or cancel events one by one.');
            foreach (q('SELECT * FROM events WHERE matter_id=? AND status IN ("open","proposed") AND type<>?', [$mm['id'], ABANDON_REPORT_EVENT]) as $x) audit_done($x, 'property dead / inactive');
            $n = run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, "closed: property dead") WHERE matter_id=? AND status IN ("open","proposed") AND type<>?', [auth_user(), $mm['id'], ABANDON_REPORT_EVENT]);   // the abandonment email stays (2026-10-01)
            run('UPDATE reminders SET status="skipped", reason="event closed (property dead)" WHERE status IN ("pending","held") AND event_id IN (SELECT id FROM events WHERE matter_id=? AND type<>?)', [$mm['id'], ABANDON_REPORT_EVENT]);
            run('UPDATE matters SET dead=1, dead_reason=CASE WHEN dead_reason="" THEN pto_status ELSE dead_reason END, dead_at=COALESCE(dead_at, ?), status=CASE WHEN status="Abandoned" THEN status ELSE "Dead" END, updated_at=datetime("now") WHERE id=?', [today(), $mm['id']]);
            run('DELETE FROM reminders WHERE status IN ("pending","held") AND event_id IN (SELECT id FROM events WHERE matter_id=? AND type<>?)', [$mm['id'], ABANDON_REPORT_EVENT]);
            audit('confirm_dead', "$n events closed (dead property)", (int)$mm['id']);
            redirect($back, $mm['ref'] . ': ' . $n . ' event(s) closed. The property is inactive and will no longer be checked or reminded.');
        case 'revive': do_revive:
            $mm = q1('SELECT * FROM matters WHERE id=?', [(int)($_POST['matter_id'] ?? 0)]); if (!$mm) redirect($back, 'No such matter.');
            $n = run('UPDATE events SET status="open", done_by=NULL, done_at=NULL, proposed_by=NULL, proposed_at=NULL, note=replace(replace(note, " | closed: property dead", ""), "closed: property dead", "") WHERE matter_id=? AND (note LIKE "%closed: property dead%" OR (status="proposed" AND note LIKE "%Property is dead%"))', [$mm['id']]);
            run('DELETE FROM reminders WHERE status IN ("skipped","held") AND event_id IN (SELECT id FROM events WHERE matter_id=? AND status="open")', [$mm['id']]);
            cancel_abandon_report((int)$mm['id'], 'cancelled: marked active again by ' . auth_user());
            run('UPDATE matters SET dead=0, dead_reason="", dead_at=NULL WHERE id=?', [$mm['id']]); audit('revive', "$n events reopened", (int)$mm['id']);
            redirect($back, $mm['ref'] . ' is active again; ' . $n . ' event(s) reopened.');
        case 'confirm': $back = back_to($back, $id);
            if (run('UPDATE events SET status="done", done_by=?, done_at=datetime("now") WHERE id=? AND status="proposed"', [auth_user(), $id]) !== 1) redirect($back, 'Nothing to confirm.');
            retire_reminders($id, 'event closed'); audit_done($ev, 'confirmed by ' . auth_user() . ($ev['proposed_by'] ? ' (proposed by ' . $ev['proposed_by'] . ')' : ''));
            $nx = sou_chain_continue($ev, 'confirmed done');   // not when the Statement of Use itself was what closed it (audit 2026-10-02)
            redirect($back, 'Confirmed done and undocketed.' . ($nx ? ' Next extension docketed: ' . $nx['type'] . ' ' . fmt_date($nx['due']) . '.' : ''));
        // One receipt, several deadlines on one matter (a Statement of Use closes every later extension and the SOU itself): one decision
        // for all of them (2026-10-02, XYZ004 / XYZ005). Only events proposed by the receipt scan, on that one matter.
        case 'confirm_group': case 'reject_group':
            $ids = array_map('intval', (array)($_POST['ids'] ?? [])); $mid = (int)($_POST['matter_id'] ?? 0); $n = 0;
            foreach ($ids as $gid) {
                $gev = q1('SELECT * FROM events WHERE id=? AND matter_id=? AND status="proposed" AND proposed_by="receipts"', [$gid, $mid]); if (!$gev) continue;
                if ($a === 'confirm_group') { run('UPDATE events SET status="done", done_by=?, done_at=datetime("now") WHERE id=?', [auth_user(), $gid]); retire_reminders($gid, 'event closed'); audit_done($gev, 'confirmed by ' . auth_user() . ' (receipt in the file, with ' . (count($ids) - 1) . ' related)');
                    if (sou_closed_by((string)$gev['note']) === 'extension') sou_chain_continue($gev, 'confirmed done (extension receipt)'); }   // an extension receipt: the next extension falls due 6 months on
                else { run('UPDATE events SET status="open", proposed_by=NULL, proposed_at=NULL, rejected_at=datetime("now"), note=note_add(note, ?) WHERE id=?', ['not done per ' . (auth_name() ?: auth_user()), $gid]); audit('reject', "$gev[type] not done (proposal rejected with its group)", $mid); }
                $n++;
            }
            redirect($back, $a === 'confirm_group' ? "$n deadline(s) confirmed done and undocketed." : "$n deadline(s) reopened; they will not be proposed again.");
        case 'reject': $back = back_to($back, $id); run('UPDATE events SET status="open", proposed_by=NULL, proposed_at=NULL, rejected_at=datetime("now"), note=note_add(note, ?) WHERE id=? AND status="proposed"', ['not done per ' . (auth_name() ?: auth_user()), $id]); audit('reject', "$ev[type] not done (proposal rejected)", (int)$ev['matter_id']); redirect($back, 'Reopened. The USPTO sweep will not propose it again.');
        case 'cancel': $back = back_to($back, $id);  run('UPDATE events SET status="cancelled", done_by=?, done_at=datetime("now") WHERE id=?', [auth_user(), $id]); retire_reminders($id, 'event cancelled'); audit('cancel', "$ev[type] cancelled", (int)$ev['matter_id']); redirect($back, 'Cancelled.');
        case 'reopen': $back = back_to($back, $id);  run('UPDATE events SET status="open", done_by=NULL, done_at=NULL WHERE id=?', [$id]); run('DELETE FROM reminders WHERE event_id=? AND status IN ("skipped","held")', [$id]); audit('reopen', "$ev[type] reopened", (int)$ev['matter_id']); redirect($back, 'Reopened.');
        case 'snooze':
            $d = (string)($_POST['due'] ?? ''); if (!is_iso_date($d)) redirect($back, 'Bad date.');
            run('UPDATE events SET due_date=?, note=note_add(note, ?) WHERE id=?', [$d, 'due date changed from ' . fmt_date($ev['due_date']) . ' by ' . auth_user() . ($_POST['why'] ? ': ' . $_POST['why'] : ''), $id]);
            run('DELETE FROM reminders WHERE event_id=? AND status IN ("pending","held","skipped")', [$id]); audit('snooze', "$ev[type] due date changed to $d" . (trim((string)($_POST['why'] ?? '')) !== '' ? ' (' . trim($_POST['why']) . ')' : ''), (int)$ev['matter_id']); redirect($back, 'Due date changed.');
        case 'wait_add':   // 2026-10-09: Waiting on client, by hand (lib/waiting.php)
            $wr = strtoupper(trim((string)($_POST['ref'] ?? ''))); $wm = $wr !== '' ? q1('SELECT id FROM matters WHERE ref=? ORDER BY dead, id DESC', [$wr]) : null;
            if (!$wm) redirect($back, 'No matter ' . ($wr !== '' ? $wr : '(type a file number)') . '.');
            if (trim((string)($_POST['esign'] ?? '')) !== '' && waiting_esign_id((string)$_POST['esign']) === '') redirect($back, 'Not added: that e-sign link has no ?d= document.');
            waiting_add((int)$wm['id'], (string)($_POST['kind'] ?? 'other'), trim((string)($_POST['note'] ?? '')), null, (string)($_POST['esign'] ?? ''));
            redirect($back, $wr . ' added to Waiting on client.');
        case 'wait_clear':
            waiting_clear((int)($_POST['id'] ?? 0), (string)auth_user(), 'marked done by ' . auth_user()); redirect($back, 'Removed from Waiting on client.');
        case 'add_event':
            $mid = (int)($_POST['matter_id'] ?? 0); $type = trim((string)(($_POST['type_other'] ?? '') ?: ($_POST['type'] ?? ''))); $d = (string)($_POST['due'] ?? '');
            if ($type === '' || !is_iso_date($d)) redirect($back, 'Need an event type and a date.');
            if (!($am = q1('SELECT * FROM matters WHERE id=?', [$mid]))) redirect($back, 'No such matter.');
            if (preg_match('/^Remind Client$/i', $type) && is_pending_us_tm_app($am)) redirect($back, 'Not added: "Remind Client" is no longer docketed on pending trademark applications. Madder\'s own 30-day and 7-day reminders cover the deadline itself.');
            run('INSERT INTO events(matter_id,type,due_date,status,source,note) VALUES (?,?,?,"open","manual",?)', [$mid, $type, $d, trim($_POST['note'] ?? '') . ' (added by ' . auth_user() . ')']); audit('add_event', "$type $d added", $mid); redirect($back, 'Event added.');
        case 'set_client':   // one Save for the whole Client box (2026-09-30): a changed client code moves the matter (next case); otherwise save the details
            $mm0 = !empty($_POST['matter_id']) ? q1('SELECT client_code FROM matters WHERE id=?', [(int)$_POST['matter_id']]) : null;
            if ($mm0 && isset($_POST['client_code']) && strtoupper(trim((string)$_POST['client_code'])) !== (string)$mm0['client_code']) goto set_matter_client;
            $lists = []; foreach (['email_tm', 'email_patent'] as $k) { $a = split_addresses((string)($_POST[$k] ?? '')); if (trim((string)($_POST[$k] ?? '')) !== '' && !$a) redirect($back, 'Not saved: no valid address in the ' . ($k === 'email_tm' ? 'trademark' : 'patent') . ' list.'); $lists[$k] = implode(', ', $a); }
            $apx = split_addresses((string)($_POST['email_ap'] ?? '')); if (trim((string)($_POST['email_ap'] ?? '')) !== '' && !$apx) redirect($back, 'Not saved: the accounts payable address is not valid.');
            if ($mm0 && isset($_POST['their_ref'])) {   // the matter's own associate fields (2026-10-14)
                $ins = split_addresses((string)($_POST['instructing'] ?? '')); if (trim((string)($_POST['instructing'] ?? '')) !== '' && !$ins) redirect($back, 'Not saved: the instructing attorney address is not valid.');
                $map = split_addresses((string)($_POST['m_email_ap'] ?? '')); if (trim((string)($_POST['m_email_ap'] ?? '')) !== '' && !$map) redirect($back, 'Not saved: the matter\'s accounts payable address is not valid.');
                $old = q1('SELECT ref, their_ref, instructing, ap_email, owner FROM matters WHERE id=?', [(int)$_POST['matter_id']]);
                $new = ['their_ref' => trim((string)$_POST['their_ref']), 'instructing' => implode(', ', $ins), 'ap_email' => implode(', ', $map), 'owner' => trim((string)($_POST['owner'] ?? ''))];
                $chg = array_filter(array_keys($new), fn($k) => (string)$old[$k] !== $new[$k]);
                if ($chg) { run('UPDATE matters SET their_ref=?, instructing=?, ap_email=?, owner=?, updated_at=datetime("now") WHERE id=?', [$new['their_ref'], $new['instructing'], $new['ap_email'], $new['owner'], (int)$_POST['matter_id']]);
                    audit('matter_assoc', $old['ref'] . ': ' . implode('; ', array_map(fn($k) => "$k '" . $old[$k] . "' → '" . $new[$k] . "'", $chg)), (int)$_POST['matter_id']); }
            }
            run('UPDATE clients SET phone=? WHERE code=?', [implode(', ', phone_list((string)($_POST['phone'] ?? ''))), (string)($_POST['code'] ?? '')]);   // phone(s), always shown (2026-09-30)
            run('UPDATE clients SET email=?, name=?, email_tm=?, email_patent=?, email_ap=? WHERE code=?', [($mainx = split_addresses((string)($_POST['email'] ?? ''))) ? implode(', ', $mainx) : trim((string)($_POST['email'] ?? '')), trim((string)($_POST['name'] ?? '')), $lists['email_tm'], $lists['email_patent'], implode(', ', $apx), (string)($_POST['code'] ?? '')]); $rel = release_held('m.client_code=?', [(string)($_POST['code'] ?? '')]);
            audit('set_client', $_POST['code'] . " ($rel held reminders released)"); redirect($back, 'Client updated.' . ($rel ? " $rel held reminder(s) moved to the pending queue." : ''));
        case 'set_matter_client': set_matter_client:   // typed 3-4 letter client code on a record (2026-09-25: a box, not a pulldown)
            $mm = q1('SELECT * FROM matters WHERE id=?', [(int)($_POST['matter_id'] ?? 0)]); if (!$mm) redirect($back, 'No such matter.');
            $code = strtoupper(trim((string)($_POST['client_code'] ?? '')));
            if (!preg_match('/^[A-Z]{3,4}$/', $code)) redirect($back, 'Client code must be three or four letters (e.g. CAW, RWG, PAMI).');
            if ($code === $mm['client_code']) redirect($back, $mm['ref'] . ' is already under client ' . $code . '.');
            $isNew = !q1('SELECT 1 FROM clients WHERE code=?', [$code]); run('INSERT OR IGNORE INTO clients(code) VALUES (?)', [$code]);
            run('UPDATE matters SET client_code=?, updated_at=datetime("now") WHERE id=?', [$code, $mm['id']]);
            audit('set_matter_client', $mm['ref'] . ': client ' . ($mm['client_code'] ?: '(none)') . ' → ' . $code . ($isNew ? ' (new client)' : ''), (int)$mm['id']);
            redirect($back, $mm['ref'] . ' moved to client ' . $code . ($isNew ? ' (new client: add the name and email below)' : '') . '. Reminders now go to that client\'s address.');
        case 'set_goods':   // US trademark goods / services, one line per class (2026-09-29)
            redirect($back, 'Goods / services are not editable here; they come from the USPTO record.');   // 2026-09-30
            if (!in_array(auth_role(), ['admin', 'staff'], true)) redirect($back, 'Sign in first.');
            $mm = q1('SELECT * FROM matters WHERE id=?', [(int)($_POST['matter_id'] ?? 0)]); if (!$mm) redirect($back, 'No such matter.');
            $lines = []; $cls = []; $badl = [];
            foreach (preg_split('/\R/', trim((string)($_POST['goods'] ?? ''))) as $l) { $l = trim($l); if ($l === '') continue;
                if (!preg_match('/^(?:class\s*)?(\d{1,3}|[AaBb])\s*[-:.]\s*(.+)$/i', $l, $gm)) { $badl[] = $l; continue; }
                $c = ctype_digit($gm[1]) ? sprintf('%03d', (int)$gm[1]) : strtoupper($gm[1]); $lines[] = $c . ' - ' . trim($gm[2]); $cls[] = $c; }
            if ($badl) redirect($back, 'Not saved: each line needs a class number and a dash first, e.g. "025 - Shirts; Hats". Check: ' . trunc($badl[0], 60));
            run('UPDATE matters SET goods=?, classes=?, updated_at=datetime("now") WHERE id=?', [implode("\n", $lines), implode("\n", $cls), $mm['id']]);
            audit('set_goods', $mm['ref'] . ': goods/services ' . ($mm['goods'] === '' ? 'added' : 'changed') . ' (' . count($lines) . ' class' . (count($lines) === 1 ? '' : 'es') . ')', (int)$mm['id']);
            refresh_queued('e.matter_id=?', [$mm['id']]);
            redirect($back, 'Goods / services saved for ' . $mm['ref'] . '.');
        case 'delete_matter':   // Advanced > Delete record (2026-09-30): admin only, typed confirmation, full copy kept in deleted_records
            if (!is_admin()) redirect($back, 'Only an admin can delete a record.');
            $mm = q1('SELECT * FROM matters WHERE id=?', [(int)($_POST['matter_id'] ?? 0)]); if (!$mm) redirect(url('delete'), 'No such matter.');
            if (strtoupper(trim((string)($_POST['confirm_ref'] ?? ''))) !== strtoupper($mm['ref'])) redirect(url('delete', ['ref' => $mm['ref']]), 'Not deleted: type the file number exactly to confirm.');
            $mid = (int)$mm['id']; $evIds = array_column(q('SELECT id FROM events WHERE matter_id=?', [$mid]), 'id'); $in = $evIds ? implode(',', array_map('intval', $evIds)) : '0';
            $copy = ['matter' => $mm, 'events' => q('SELECT * FROM events WHERE matter_id=?', [$mid]), 'reminders' => q("SELECT * FROM reminders WHERE event_id IN ($in)"),
                     'outbox' => q('SELECT * FROM outbox WHERE matter_id=?', [$mid]), 'dt_sync' => q('SELECT * FROM dt_sync WHERE matter_id=?', [$mid]), 'checks' => q('SELECT * FROM checks WHERE matter_id=?', [$mid])];
            db()->beginTransaction();
            run('INSERT INTO deleted_records(by_user, ref, data) VALUES (?,?,?)', [auth_user(), $mm['ref'], json_encode($copy)]);
            run("DELETE FROM reminders WHERE event_id IN ($in)"); run('DELETE FROM outbox WHERE matter_id=?', [$mid]); run('DELETE FROM dt_sync WHERE matter_id=?', [$mid]); run('DELETE FROM checks WHERE matter_id=?', [$mid]);
            run('UPDATE correspondence SET matter_id=NULL WHERE matter_id=?', [$mid]); run('DELETE FROM events WHERE matter_id=?', [$mid]); run('DELETE FROM matters WHERE id=?', [$mid]);
            db()->commit();
            audit('delete_matter', $mm['ref'] . ' deleted (' . count($copy['events']) . ' events, ' . count($copy['outbox']) . ' emails; copy kept in deleted_records)', $mid);
            redirect(url('delete'), $mm['ref'] . ' deleted. A full copy was kept, so it can be restored if needed.');
        case 'rename':
            if (!is_admin()) redirect($back, 'Only an admin can change file numbers.');
            $new = strtoupper(trim((string)($_POST['ref'] ?? ''))); if (!preg_match('/^([A-Z]{3,4})\d{3}(\.[A-Z0-9.]+|-[A-Z0-9-]+)?$/', $new, $rm)) redirect($back, 'File number must look like ABC001 (optionally ABC001.CN).'); $code = client_from_ref($new) ?: $rm[1];
            $mm = q1('SELECT * FROM matters WHERE id=?', [(int)($_POST['matter_id'] ?? 0)]); if (!$mm) redirect($back, 'No such matter.');
            if (q1('SELECT 1 FROM matters WHERE ref=? AND kind=? AND id<>?', [$new, $mm['kind'], $mm['id']])) redirect($back, "$new is already in use."); run('INSERT OR IGNORE INTO clients(code) VALUES (?)', [$code]);
            run('UPDATE matters SET ref=?, client_code=?, dt_ref=COALESCE(NULLIF(dt_ref,""), ref), updated_at=datetime("now") WHERE id=?', [$new, $code, $mm['id']]);
            audit('rename', $mm['ref'] . ' → ' . $new, (int)$mm['id']); redirect($back, 'File number set to ' . $new . '.');
        case 'matter_note':
            foreach (['filing_date', 'grant_date'] as $k) if (($_POST[$k] ?? '') !== '' && !is_iso_date($_POST[$k] ?? '')) redirect($back, 'Bad date.');
            run('UPDATE matters SET notes=?, filing_date=NULLIF(?, ""), grant_date=NULLIF(?, "") WHERE id=?', [trim((string)($_POST['notes'] ?? '')), $_POST['filing_date'] ?? '', $_POST['grant_date'] ?? '', (int)$_POST['matter_id']]);
            $added = ensure_priority_deadline((int)$_POST['matter_id']); redirect($back, 'Saved.' . ($added ? " Added: $added." : ''));
        case 'approve_tpl': if (!can_approve_tpl()) redirect($back, 'Only the attorney or staff can approve templates.'); run('UPDATE templates SET approved=1, approved_by=?, approved_at=datetime("now") WHERE event_type=?', [auth_user(), $_POST['type']]); $rel = release_held('e.type=?', [$_POST['type']]); audit('approve_tpl', $_POST['type'] . " ($rel held reminders released)"); redirect($back, 'Template approved.' . ($rel ? " $rel held reminder(s) moved to the pending queue." : '') . ' New reminders of this type queue from the next nightly run.');
        case 'unapprove_tpl': if (!can_approve_tpl()) redirect($back, 'Only the attorney or staff can approve templates.'); run('UPDATE templates SET approved=0 WHERE event_type=?', [$_POST['type']]); audit('unapprove_tpl', $_POST['type']); redirect($back, 'Template approval withdrawn.');
        case 'save_tpl':   // 2026-09-28: staff edit templates too; approval stays his (a save un-approves)
            if (!in_array(auth_role(), ['admin', 'staff'], true)) redirect($back, 'Sign in first.');
            $t = trim((string)($_POST['type'] ?? '')); if ($t === '') redirect($back, 'No event type.'); if (!q1('SELECT 1 FROM templates WHERE event_type=?', [$t])) run('INSERT INTO templates(event_type) VALUES (?)', [$t]);
            run('UPDATE templates SET gov_fee=?, our_fee=?, fee_note=? WHERE event_type=?', [trim((string)($_POST['gov_fee'] ?? '')), trim((string)($_POST['our_fee'] ?? '')), trim((string)($_POST['fee_note'] ?? '')), $t]);
            run('UPDATE templates SET subject=?, body=?, needs_invoice=?, lead_days=?, internal_only=?, approved=0 WHERE event_type=?', [(string)($_POST['subject'] ?? ''), (string)(str_replace("\r\n", "\n", (string)($_POST['body'] ?? ''))), isset($_POST['needs_invoice']) ? 1 : 0, (($ld = implode(',', array_unique(array_filter(array_map('intval', array_filter(array_map('trim', explode(',', (string)($_POST['lead_days'] ?? ''))), fn($x) => $x !== '')), fn($n) => $n >= 0)))) === '' ? '30,7' : $ld), isset($_POST['internal_only']) ? 1 : 0, $t]);
            $rf = refresh_queued('e.type=?', [$t]); audit('save_tpl', $t . ($rf ? " ($rf queued reminders re-worded)" : '')); redirect(url('templates', ['edit' => $t]), 'Saved.' . ($rf ? " $rf queued reminder(s) now use the new wording." : '') . ' Editing a template withdraws its approval; approve it again when the wording is final.');
        // Advanced > Dropbox (2026-10-01): connect the server to the matter folders (PKCE; the app key is not a secret).
        case 'dbx_start':
            $key = trim((string)($_POST['app_key'] ?? '')); if (!preg_match('/^[a-z0-9]{8,32}$/i', $key)) redirect(url('dropbox'), 'That does not look like a Dropbox app key.');
            $_SESSION['dbx_url'] = dbx_auth_url($key); audit('dropbox', 'connection started (app key ' . $key . ')'); redirect(url('dropbox'), 'Open the Dropbox link below, click Allow, and paste the code back here.');
        case 'dbx_finish':
            $r = dbx_auth_finish((string)($_POST['code'] ?? '')); unset($_SESSION['dbx_url']);
            audit('dropbox', $r['ok'] ? 'connected as ' . $r['account'] : 'connection failed: ' . $r['error']);
            redirect(url('dropbox'), $r['ok'] ? 'Connected to Dropbox as ' . $r['account'] . '. The first index of the matter folders runs with the next hourly job (or click "Index now").' : $r['error']);
        case 'dbx_disconnect': dbx_disconnect(); audit('dropbox', 'disconnected'); redirect(url('dropbox'), 'Dropbox disconnected. The folder jobs stop until it is connected again.');
        case 'dbx_sync_now':
            set_time_limit(320); $s = dbx_sync(240); $rc = $s['complete'] ? receipts_scan() : ['seen' => 0, 'proposed' => []];
            redirect(url('dropbox'), "Dropbox: {$s['changes']} change(s) indexed" . ($s['complete'] ? '; ' . count($rc['proposed']) . ' deadline(s) proposed done from receipts.' : '; the first listing is not finished yet, click again or let the hourly job continue.') . ($s['error'] ? ' Error: ' . $s['error'] : ''));
        case 'report_cancel':   // 2026-10-03 (XYZ022): "I want to cancel the email to send. I sent one myself in Gmail."
            $re = q1('SELECT e.*, m.ref FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.id=? AND e.status IN ("open","proposed")', [$id]); if (!$re) redirect($back, 'Already closed.');
            if (!str_starts_with((string)$re['type'], 'Report ')) {   // a deadline reminder: only this email is dropped, never the deadline itself
                run('UPDATE reminders SET status="skipped", reason=? WHERE id=? AND event_id=? AND status IN ("pending","held")', ['sent by hand, per ' . auth_user(), (int)($_POST['rem'] ?? 0), $id]);
                audit('rem_skip', $re['type'] . ' ' . fmt_date($re['due_date']) . ': reminder email dropped, sent by hand (the deadline stays open)', (int)$re['matter_id']);
                redirect($back, $re['ref'] . ': this reminder will not be sent. The deadline itself stays on the docket.');
            }
            run('UPDATE events SET status="cancelled", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'cancelled: sent by hand (' . (auth_name() ?: auth_user()) . ' ' . date('Y-m-d') . ')', $id]);
            run('UPDATE reminders SET status="skipped", reason=? WHERE event_id=? AND status IN ("pending","held")', ['sent by hand, per ' . auth_user(), $id]);
            audit('cancel', $re['type'] . ' cancelled: the client was emailed by hand', (int)$re['matter_id']);
            redirect($back, $re['ref'] . ': cancelled. Madder will not send this email.');
        case 'pub_reported':   // publication report sent outside Madder (Dashboard > publication reports)
            $e = q1('SELECT e.*, m.ref FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.id=? AND e.type=? AND e.status="open"', [(int)($_POST['id'] ?? 0), PUB_REPORT]); if (!$e) redirect($back, 'That report is no longer open.');
            run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'Marked reported outside Madder by ' . auth_user() . ' ' . fmt_date(today()), $e['id']]);
            audit('notice_report', $e['ref'] . ' publication report marked as already sent', (int)$e['matter_id']); redirect($back, $e['ref'] . ': marked reported.');
        case 'pub_queue':
            $m = q1('SELECT * FROM matters WHERE id=?', [(int)($_POST['matter_id'] ?? 0)]); $c = q1('SELECT * FROM correspondence WHERE id=? AND doc_code="NOTICE OF PUBLICATION"', [(int)($_POST['cid'] ?? 0)]);
            if (!$m || !$c) redirect($back, 'No such notice.');
            redirect($back, pub_queue_for($m, $c) ? $m['ref'] . ': client email queued.' : $m['ref'] . ': not queued (inactive, not a US trademark, or already reported within 60 days).');
        case 'pub_sweep':
            $l = pub_sweep(true); redirect($back, $l ? implode(' · ', array_slice($l, 0, 8)) . (count($l) > 8 ? ' (+' . (count($l) - 8) . ' more)' : '') : 'Checked: nothing new, no reports found sent by hand.');
        case 'reg_reported':   // registration report sent outside Madder (Dashboard > registration reports, lib/regreport.php)
            $e = q1('SELECT e.*, m.ref FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.id=? AND e.type IN (?,?) AND e.status="open"', [(int)($_POST['id'] ?? 0), REG_REPORT, ISSUANCE_REPORT_EVENT]); if (!$e) redirect($back, 'That report is no longer open.');   // + patent issuance (lib/patreport.php)
            run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'Marked reported outside Madder by ' . auth_user() . ' ' . fmt_date(today()), $e['id']]);
            audit('notice_report', $e['ref'] . ' registration report marked as already sent', (int)$e['matter_id']); redirect($back, $e['ref'] . ': marked reported.');
        case 'reg_queue':
            $m = q1('SELECT * FROM matters WHERE id=? AND kind="trademark"', [(int)($_POST['matter_id'] ?? 0)]); if (!$m) redirect($back, 'No such matter.');
            redirect($back, reg_queue_for($m, true) ? $m['ref'] . ': client email queued.' : $m['ref'] . ': not queued (no registration number, not a US trademark, or already queued within 60 days).');
        case 'reg_sweep':
            $l = array_merge(reg_sweep(true), pat_sweep(true)); redirect($back, $l ? implode(' · ', array_slice($l, 0, 8)) . (count($l) > 8 ? ' (+' . (count($l) - 8) . ' more)' : '') : 'Checked: nothing new, no reports found sent by hand.');
        case 'held_done': case 'held_reopen':   // USPTO mail held for a person (lib/held.php)
            held_schema(); run('UPDATE uspto_held SET status=?, handled_by=?, handled_at=datetime("now"), note=? WHERE msg_id=?', [$a === 'held_done' ? 'handled' : 'held', auth_user(), trim((string)($_POST['note'] ?? '')), (string)($_POST['msg'] ?? '')]);
            audit('uspto_held', ($a === 'held_done' ? 'handled: ' : 'back to held: ') . (string)($_POST['msg'] ?? '') . (trim((string)($_POST['note'] ?? '')) !== '' ? ' (' . trim((string)$_POST['note']) . ')' : ''));
            redirect(url('reminders') . '#held', $a === 'held_done' ? 'Marked handled.' : 'Back on the held list.');
        case 'oa_fetch_now':   // "Fetch" on a report to-do (2026-10-04): the laptop's 5-minute job saves the paper (or marks it Patent Center only)
            oa_files_schema(); $cid = (int)($_POST['corr_id'] ?? 0); $c = q1('SELECT c.*, f.status fst FROM correspondence c LEFT JOIN oa_files f ON f.correspondence_id=c.id WHERE c.id=?', [$cid]); if (!$c) redirect($back, 'No such USPTO paper.');
            if (!in_array((string)$c['fst'], ['', 'retry', 'failed', 'wanted'], true)) redirect($back, 'That paper is already ' . $c['fst'] . '.');
            run('INSERT OR REPLACE INTO oa_files(correspondence_id,status,files,note,fetched_at) VALUES (?,"wanted","",?,datetime("now"))', [$cid, 'fetch requested by ' . auth_user()]);
            audit('oa_fetch_now', $c['doc_code'] . ' of ' . $c['mail_date'] . ' requested', (int)$c['matter_id']);
            // 2026-10-06 (XYZ008): start the download on the server right away. The 5-minute "wanted" timer was on the laptop,
            // which reads its own dev database since the move to the server (10-01), so a click waited for the hourly run.
            // If the host refuses to start it, the hourly oa_fetch still picks up status "wanted".
            $mref = (string)(q1('SELECT ref FROM matters WHERE id=?', [(int)$c['matter_id']])['ref'] ?? '');
            if ($mref !== '' && function_exists('exec')) @exec('(cd ' . escapeshellarg(DOCKET_ROOT) . ' && HOME=${HOME:-' . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '/tmp') : '/tmp') . '} nohup ' . (is_file('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php') . ' bin/oa_fetch.php --wanted --days=3650 --only=' . escapeshellarg($mref) . ' >> data/fetch-now.log 2>&1) > /dev/null 2>&1 &');
            redirect($back, 'Madder is trying to download the file now.');
        case 'pc_request':   // "Download with Claude" (lib/pcfetch.php; 2026-10-03): plan folder + file names, then open the madder-pc: launcher page
            if (!pc_can_run()) redirect($back, 'Only the attorney or staff can start the Patent Center downloads.');
            $cids = ($_POST['corr_id'] ?? '') === 'all' ? array_column(pc_waiting(), 'id') : [(int)($_POST['corr_id'] ?? 0)];
            $rids = pc_plan($cids, (string)auth_user());
            if ($rids) audit('pc_request', count($rids) . ' Patent Center download(s) sent to Claude');
            redirect(url('pc_launch', ['ids' => implode(',', $rids)]), $rids ? count($rids) . ' document(s) for Claude.' : 'Nothing to download.');
        case 'gmail_pull':
            set_time_limit(300); $g = pull_uspto_mail((int)($_POST['days'] ?? 7), true);
            redirect(url('inbox'), $g['error'] ? 'Gmail pull failed: ' . $g['error'] : "Gmail: {$g['messages']} USPTO messages in the window, {$g['new']} new, {$g['items']} notices docketed ({$g['receipts']} receipts), {$g['held']} held for review, {$g['ignored']} ignored" . ($g['skipped'] ? ", {$g['skipped']} skipped" : '') . '. Nothing was marked read.');
        case 'run_nightly':
            set_time_limit(600); $sw = run_sweep(60, false); $s = run_nightly(); $hz = extend_renewal_horizon(); $html = build_report($sw, $s, '', $hz); @mkdir(reports_dir(), 0755, true); file_put_contents(reports_dir() . '/' . today() . '.html', $html);
            redirect(url('report'), "Nightly run: {$sw['checked']} matters checked at the USPTO (60 per click here), " . count($sw['proposed']) . " events proposed done; {$s['sent']} reminders queued, {$s['held']} held.");
        case 'rem_skip': run('UPDATE reminders SET status="skipped", reason=? WHERE id=?', ['Skipped by ' . auth_user(), $id]); redirect($back, 'Skipped.');
        case 'rem_sent': run('UPDATE reminders SET status="sent", sent_at=datetime("now"), reason=? WHERE id=?', ['Sent manually by ' . auth_user(), $id]); redirect($back, 'Marked sent.');
        case 'ingest_apply': case 'ingest_preview':
            $items = parse_uspto_mail((string)($_POST['subject'] ?? ''), (string)(str_replace("\r\n", "\n", (string)($_POST['body'] ?? ''))));
            $_SESSION['ingest'] = ['subject' => $_POST['subject'] ?? '', 'body' => str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')), 'apply' => $a === 'ingest_apply', 'results' => array_map(fn($it) => apply_item($it, $a === 'ingest_apply'), $items)];
            redirect(url('inbox'), $a === 'ingest_apply' ? 'Applied.' : 'Preview only, nothing changed.');
    }
    redirect($back);
}

// ------------------------------------------------------------------ pages
$EVSQL = 'SELECT e.*, m.ref, m.client_code, m.title, m.app_no, m.reg_no, m.country, m.kind FROM events e JOIN matters m ON m.id=e.matter_id';
try { page($p, $EVSQL, $flash, $cfg); } catch (Throwable $e) {
    if (!headers_sent()) { http_response_code(500); layout_top('Error'); }
    audit('error', $p . ': ' . $e->getMessage());
    echo '<div class="flash" style="background:#fee4e2;border-color:#b42318"><b>Something went wrong on this page.</b> ' . h($e->getMessage()) . '</div><p><a href="?">Back to the dashboard</a></p>'; layout_bottom();
}
/** USPTO email held for a person (lib/held.php), with Read it / Handled; shown at the top of Reminders > To do (2026-10-03: one list). */
function held_section_html(): string {
    ob_start();
    $held = held_open();
    echo '<h2 id="held">USPTO email to read <small>(' . count($held) . ': read it, then mark Handled)</small></h2>';
    if (!$held) echo '<p class="muted">Nothing held. Every USPTO email has been docketed, saved as a receipt, or recognised as needing nothing.</p>';
    else { echo '<table><thead><tr><th>Received</th><th>From / subject</th><th>Why held</th><th></th></tr></thead><tbody>';
        foreach ($held as $x) echo '<tr><td class="nowrap">' . h($x['received']) . '</td><td><b>' . h(trunc($x['subject'], 90)) . '</b><div class="muted">' . h($x['sender']) . '</div><details><summary>Read it</summary><pre style="white-space:pre-wrap;max-height:420px;overflow:auto">' . h($x['body']) . '</pre></details></td><td class="note">' . ($x['verified'] ? '' : '<span class="bad">Unverified sender.</span> ') . h($x['reason']) . '</td><td class="acts"><form method="post" class="bar">' . csrf_field() . '<input type="hidden" name="action" value="held_done"><input type="hidden" name="msg" value="' . h($x['msg_id']) . '"><input type="text" name="note" placeholder="Notes" size="18"> <button class="small gold">Handled</button></form></td></tr>';
        echo '</tbody></table>'; }
    $done = q("SELECT * FROM uspto_held WHERE status='handled' ORDER BY handled_at DESC LIMIT 10");
    if ($done) { echo '<details><summary class="muted">Recently handled (' . count($done) . ')</summary><table><tbody>'; foreach ($done as $x) echo '<tr><td class="nowrap">' . h($x['received']) . '</td><td>' . h(trunc($x['subject'], 80)) . '</td><td class="note">' . h($x['handled_by'] . ' ' . et((string)$x['handled_at']) . ($x['note'] !== '' ? ': ' . $x['note'] : '')) . '</td><td>' . btn('held_reopen', ['msg' => $x['msg_id']], 'Hold again', 'small ghost') . '</td></tr>'; echo '</tbody></table></details>'; }
    return (string)ob_get_clean();
}
/** The Dashboard's row of cards (counts by due range, receipts, Patent Center, unsent, verify, emails, blocked, the Madder emblem).
 *  2026-10-03: it stays at the top of every page a card opens. Returns [html, ranges, byRange, show, unsent]. */
function dash_cards(string $EVSQL, string $page = 'dash'): array {
    $t = today(); $show = $page === 'dash' ? ($_GET['show'] ?? '') : '';
    $ranges = ['over' => ['overdue / today', '', $t], 'd3' => ['due within 3 days', add_days($t, 1, false), add_days($t, 3, false)],   // 2026-10-04: today's items sit with the overdue ones
               'd14' => ['due in 4 to 14 days', add_days($t, 4, false), add_days($t, 14, false)],
               'd60' => ['due in 15 to 60 days', add_days($t, 15, false), add_days($t, 60, false)],
               'd180' => ['due in 61 to 180 days', add_days($t, 61, false), add_days($t, 180, false)]];
    $tpls = []; foreach (q('SELECT * FROM templates') as $tp) $tpls[$tp['event_type']] = $tp;
    $all = q("$EVSQL WHERE e.status='open' AND e.due_date <= ?", [add_days($t, 180 + 366, false)]);
    foreach ($all as &$r) $r['dash_date'] = dash_date($r, $tpls); unset($r);
    usort($all, fn($a, $b) => [$a['dash_date'], $a['due_date'], $a['ref']] <=> [$b['dash_date'], $b['due_date'], $b['ref']]);
    $byRange = array_fill_keys(array_keys($ranges), []);
    foreach ($all as $r) foreach ($ranges as $k => [, $lo, $hi]) if ($r['dash_date'] >= $lo && $r['dash_date'] <= $hi) { $byRange[$k][] = $r; break; }
    $cnt = array_map('count', $byRange);
    // 2026-09-28: open on the most urgent range that has anything in it (overdue, then 3 days, then 4-14, ...).
    // 2026-09-28: coming back (e.g. after sending an email) stays on the card clicked last in this session.
    if (isset($ranges[$show])) $_SESSION['dk_dash_show'] = $show; elseif (isset($ranges[$_SESSION['dk_dash_show'] ?? ''])) $show = $_SESSION['dk_dash_show'];
    if (!isset($ranges[$show])) { $show = 'd14'; foreach ($cnt as $k => $n) if ($n > 0) { $show = $k; break; } }
    $held = q1('SELECT count(*) c FROM reminders WHERE status="held"')['c']; $nh = count(held_open()); $pend = q1('SELECT count(*) c FROM reminders WHERE status="pending"')['c'] + $nh + q1("SELECT count(*) c FROM events e LEFT JOIN reminders r ON r.event_id=e.id WHERE e.status='open' AND e.type LIKE 'Report %' AND r.id IS NULL")['c'];   // one card, one list (2026-10-03)
    $o = '<div class="cards">'; ob_start();
    foreach ($ranges as $k => [$label]) { $n = $cnt[$k]; echo '<a class="card ' . $k . ($k === $show && !in_array($_GET['show'] ?? '', ['unsent', 'pub', 'reg', 'rep', 'wait'], true) ? ' on' : '') . '" href="' . url('dash', ['show' => $k]) . '"><b>' . $n . '</b><span>' . $label . '</span></a>'; }
    receipts_schema(); $rcpt = (int)(q1('SELECT count(*) c FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.status="proposed" AND e.proposed_by="receipts" AND m.dead=0')['c'] ?? 0);
    if ($rcpt) echo '<a class="card d3" href="' . url('queue') . '#receipts"><b>' . $rcpt . '</b><span>filed: receipt found</span></a>';   // 2026-10-01
    $npub = pub_open_count();   // 2026-10-04: notices of publication -> client emails (lib/pubreport.php)
    $nreg = reg_open_count();   // 2026-10-04: trademark registrations -> certificate + letter to the client (lib/regreport.php)
    // 2026-10-09: publication and registration reports share one card
    $nrep = $npub + $nreg; echo '<a class="card pubcard' . ($nrep ? ' d3' : '') . (in_array($_GET['show'] ?? '', ['pub', 'reg', 'rep'], true) ? ' on' : '') . '" href="' . url('dash', ['show' => 'rep']) . '"><b>' . $nrep . '</b><span>publication / registration report' . ($nrep === 1 ? '' : 's') . ' to send</span></a>';
    $nwait = waiting_count(); echo '<a class="card pubcard' . ($nwait ? ' over' : '') . (($_GET['show'] ?? '') === 'wait' ? ' on' : '') . '" href="' . url('dash', ['show' => 'wait']) . '"><b>' . $nwait . '</b><span>waiting on client</span></a>';   // 2026-10-09 (lib/waiting.php)
    echo pc_dash_card();   // office actions only Patent Center has: one click queues them all for Claude (lib/pcfetch.php)
    // 2026-10-01: an email that failed to go out is shown until it is transmitted (or discarded), so it cannot sit unnoticed.
    $unsent = untransmitted(); if ($unsent) echo '<a class="card over' . (($_GET['show'] ?? '') === 'unsent' ? ' on' : '') . '" href="' . url('dash', ['show' => 'unsent']) . '"><b>' . count($unsent) . '</b><span>emails NOT sent</span></a>';
    // 2026-10-15: client emails to send and blocked reminders share one card (same list behind both)
    echo '<a class="card' . ($pend ? ' d3' : '') . '" href="' . url('reminders') . '"><b>' . ($pend - $nh) . '</b><span>client email' . ($pend - $nh === 1 ? '' : 's') . ' to send' . ($nh ? ' · <b>' . $nh . '</b> USPTO email' . ($nh > 1 ? 's' : '') . ' to read' : '') . ($held ? ' · <b>' . $held . '</b> reminder' . ((int)$held === 1 ? '' : 's') . ' blocked: ' . h(implode(', ', array_unique(array_map(fn($x) => blocked_why((string)$x['reason']), q('SELECT reason FROM reminders WHERE status="held"'))))) : '') . '</span></a><div class="card madder"><img src="madder.jpg" alt="' . h(firm('initials')) . ' Madder"></div></div>';   // 2026-10-01: the emblem replaces the DocketTrak-mirror card (mirror queue removed from the UI 2026-10-09)
    $o .= (string)ob_get_clean();
    if ($page !== 'dash') { $o = preg_replace('/ class="card ([a-z0-9]+) on"/', ' class="card $1"', $o); $hrefs = ['queue' => 'p=queue', 'reminders' => 'p=reminders'];
        if (isset($hrefs[$page])) $o = preg_replace_callback('/<a class="card([^"]*)" href="([^"]*' . preg_quote($hrefs[$page], '/') . '[^"]*)"/', fn($m) => '<a class="card' . $m[1] . ' on" href="' . $m[2] . '"', $o); }
    return [$o, $ranges, $byRange, $show, $unsent];
}
function page(string $p, string $EVSQL, ?string $flash, array $cfg): void {
switch ($p) {
case 'dash': {
    layout_top('Dashboard', $flash); $t = today(); $show = $_GET['show'] ?? '';
    // 2026-10-01: an event with reminders is filed under its next reminder not yet sent (dash_date), the real deadline once
    // they are all done. The ranges are applied in PHP to that date; the SQL only bounds the candidates (a grace email fires ~6 months
    // before the grace period ends, so look a year past the last range).
    [$cardsHtml, $ranges, $byRange, $show, $unsent] = dash_cards($EVSQL, 'dash');
    echo '<h1>Dashboard <small class="muted">as of ' . fmt_date($t) . '</small></h1>' . $cardsHtml;
    if (in_array($_GET['show'] ?? '', ['pub', 'reg', 'rep'], true)) { echo pub_panel() . reg_panel(); layout_bottom(); break; }   // 2026-10-09: one page for notices of publication (lib/pubreport.php) and registrations (lib/regreport.php)
    if (($_GET['show'] ?? '') === 'wait') { echo waiting_panel(); layout_bottom(); break; }   // 2026-10-09 (lib/waiting.php)   // trademark registrations (lib/regreport.php)
    if (($_GET['show'] ?? '') === 'unsent') {
        echo '<h2>Emails NOT sent</h2><p class="muted">Send was clicked but the email never left the server. Transmit it again, or open it to fix the address or attachment.</p>';
        if (!$unsent) echo '<p>None. Every email has gone out.</p>';
        else { echo '<table><thead><tr><th class="file">File</th><th>Clicked</th><th>From</th><th>To</th><th>Subject</th><th>Problem</th><th></th></tr></thead><tbody>';
            foreach ($unsent as $o) echo '<tr><td class="file"><a href="' . url('matter', ['id' => $o['matter_id']]) . '"><b>' . h($o['ref']) . '</b></a></td><td>' . h(et($o['created_at'])) . '</td><td>' . h($o['from_user']) . '</td><td>' . h($o['to_email']) . '</td><td>' . h(trunc((string)$o['subject'], 70)) . '</td><td class="bad">' . h($o['transmit_error'] ?: 'not transmitted') . '</td><td class="acts">'
                . btn('transmit', ['id' => $o['id'], 'back' => url('dash', ['show' => 'unsent'])], 'Transmit now', 'small gold', 'Send this email to ' . $o['to_email'] . ' now?')
                . ($o['event_id'] ? ' <a class="btn small ghost" href="' . url('send', ['id' => $o['event_id'], 'ob' => $o['id'], 'back' => url('dash', ['show' => 'unsent'])]) . '">Open, fix, resend</a>' : '')
                . (is_admin() ? ' ' . btn('discard_outbox', ['id' => $o['id'], 'back' => url('dash', ['show' => 'unsent'])], 'Discard', 'small ghost', 'Discard this email so it is never sent?') : '') . '</td></tr>';
            echo '</tbody></table>'; }
        layout_bottom(); break;
    }
    [$label] = $ranges[$show];
    echo '<h2>' . ucfirst($label) . '</h2>'; events_table(array_slice($byRange[$show], 0, 500), true, '', false);   // 2026-09-28: no Client column on the dashboard
    layout_bottom(); break; }
case 'matters': {
    $qs = trim((string)($_GET['q'] ?? '')); $kind = $_GET['kind'] ?? ''; $open = isset($_GET['open']);
    // Search (2026-09-30): one box, also in the header. Every word must appear somewhere in the
    // file no., client code/name, title/mark, owner, goods or the numbers, ignoring case, spaces and
    // punctuation ("us pool signs" finds USPOOLSIGNS.COM). A file number typed exactly opens that matter.
    $nq = search_norm($qs);
    if ($nq !== '' && !$kind && !$open) {
        $hit = q('SELECT id FROM matters WHERE norm(ref)=? LIMIT 2', [$nq]);
        if (count($hit) === 1) redirect(url('matter', ['id' => $hit[0]['id']]), '');
    }
    layout_top('Matters', $flash);
    echo '<h1>Matters <a class="btn small" style="margin-left:12px" href="' . url('newmatter') . '">+ New matter</a></h1><form method="get" class="bar"><input type="hidden" name="p" value="matters"><input type="text" name="q" value="' . h($qs) . '" placeholder="file no., mark or title, owner, client, serial or patent no." size="48"><select name="kind"><option value="">patents + trademarks</option><option value="patent"' . ($kind === 'patent' ? ' selected' : '') . '>patents</option><option value="trademark"' . ($kind === 'trademark' ? ' selected' : '') . '>trademarks</option></select><label><input type="checkbox" name="open" value="1"' . ($open ? ' checked' : '') . '> with open events only</label><button>Search</button></form>';
    $w = ['1=1']; $a = [];
    $rank = '';
    if ($nq !== '') {
        $hay = "norm(m.ref || ' ' || m.their_ref || ' ' || m.client_code || ' ' || m.title || ' ' || m.owner || ' ' || m.goods || ' ' || m.app_no || ' ' || m.reg_no || ' ' || m.pub_no || ' ' || COALESCE((SELECT name FROM clients WHERE code=m.client_code), ''))";
        foreach (preg_split('/\s+/', $qs) as $word) { $nw = search_norm($word); if ($nw === '') continue; $w[] = "instr($hay, ?) > 0"; $a[] = $nw; }
        // best first: the mark/title itself, then the file no., then a title that starts or contains it
        $rank = 'CASE WHEN norm(m.title)=? THEN 0 WHEN norm(m.ref)=? OR lower(m.client_code)=? THEN 1 WHEN norm(m.title) LIKE ? THEN 2 WHEN instr(norm(m.title), ?) > 0 THEN 3 ELSE 4 END, ';
    }
    if ($kind) { $w[] = 'm.kind=?'; $a[] = $kind; }
    if ($open) $w[] = 'o.open_n > 0';
    $rows = q('SELECT m.*, COALESCE(o.open_n, 0) open_n, o.next_due FROM matters m LEFT JOIN (SELECT matter_id, count(*) open_n, min(due_date) next_due FROM events WHERE status="open" GROUP BY matter_id) o ON o.matter_id=m.id WHERE ' . implode(' AND ', $w) . ' ORDER BY ' . $rank . '(o.next_due IS NULL), o.next_due, m.ref LIMIT 300', $rank ? array_merge($a, [$nq, $nq, $nq, $nq . '%', $nq]) : $a);
    echo '<p class="muted">' . count($rows) . ' shown (max 300)</p><table><thead><tr><th class="file">File</th><th>Kind</th><th>' . ($LL = num_labels($kind ?: (count(array_unique(array_column($rows, 'kind'))) === 1 ? (string)($rows[0]['kind'] ?? '') : '')))[0] . '</th><th>Country</th><th>' . $LL[1] . '</th><th>' . $LL[2] . '</th><th>PTO status</th><th>Open</th><th>Next due</th></tr></thead><tbody>';
    foreach ($rows as $m) echo '<tr><td class="file"><a href="' . url('matter', ['id' => $m['id']]) . '"><b>' . h($m['ref']) . '</b></a></td><td>' . h(ucfirst((string)$m['kind'])) . '</td><td class="title">' . h(trunc($m['title'], 50)) . '</td><td title="' . h($m['country']) . '">' . h(country_code((string)$m['country'])) . '</td><td>' . num_link($m) . '</td><td>' . num_link($m, true) . '</td><td class="note">' . h(trunc($m['pto_status'], 40)) . '</td><td>' . $m['open_n'] . '</td><td class="nowrap">' . ($m['next_due'] ? fmt_date($m['next_due']) . ' ' . badge_days($m['next_due']) : '') . '</td></tr>';
    echo '</tbody></table>'; layout_bottom(); break; }
case 'matter': {
    $m = q1('SELECT * FROM matters WHERE id=?', [(int)$_GET['id']]); if (!$m) redirect('?', 'No such matter.');
    $c = q1('SELECT * FROM clients WHERE code=?', [$m['client_code']]) ?: ['code' => $m['client_code'], 'name' => '', 'email' => ''];
    layout_top($m['ref'], $flash); $here = url('matter', ['id' => $m['id']]);   // relative (?p=...): safe_back() refuses paths, which sent every button on this page back to the dashboard
    // After the abandonment email (2026-10-02): ask, rather than making the matter inactive on the USPTO notice alone.
    if (!empty($_GET['ask_inactive']) && !$m['dead']) echo '<div class="box warnbox"><b>The abandonment email has gone. Mark ' . h($m['ref']) . ' inactive now?</b> <span class="muted">Every remaining deadline on it is closed and no further reminders go out. You can make it active again later if it is revived.</span><div class="bar" style="margin-top:8px">'
        . btn('set_active', ['matter_id' => $m['id'], 'state' => 'inactive', 'reason' => 'Abandoned' . ($m['dead_reason'] !== '' ? ': ' . $m['dead_reason'] : ''), 'back' => $here], 'Mark inactive', 'small gold') . ' <a class="btn small ghost" href="' . h($here) . '">Keep it active</a></div></div>';
    // Active / Inactive selector. Inactive = a reason + every remaining event closed (staff: proposed; the attorney: done). Active again = "Not dead".
    $left = q1('SELECT count(*) c FROM events WHERE matter_id=? AND status IN ("open","proposed")', [$m['id']])['c'];
    $lastChk = q1('SELECT * FROM checks WHERE matter_id=? ORDER BY id DESC LIMIT 1', [$m['id']]);
    // File number on the left, "last checked" + Check button on the right, one line (2026-09-30).
    echo '<div class="bar" style="justify-content:space-between;margin-bottom:14px"><div class="bar" style="margin:0;align-items:center"><h1 style="margin:0">' . folder_link($m) . '</h1>' . (is_admin() ? '<button type="button" class="small ghost" onclick="this.style.display=\'none\';var f=document.getElementById(\'refform\');f.style.display=\'inline\';f.ref.focus();f.ref.select()">change</button><form method="post" id="refform" class="inline" style="display:none">' . csrf_field() . '<input type="hidden" name="action" value="rename"><input type="hidden" name="matter_id" value="' . $m['id'] . '"><input type="hidden" name="back" value="' . h($here) . '"><input type="text" name="ref" value="' . h($m['ref']) . '" size="14" autocomplete="off" onkeydown="if(event.key===\'Escape\'){this.form.style.display=\'none\';this.form.previousElementSibling.style.display=\'\'}"></form>' : '') . '</div><div class="bar" style="margin:0"><span class="muted">' . ($lastChk ? 'last checked ' . h(et($lastChk['ts'])) . ($lastChk['by_user'] === 'nightly' ? '' : ' by ' . h($lastChk['by_user'])) : 'never checked') . '</span><a class="btn gold" href="' . url('check', ['id' => $m['id']]) . '">Check with the USPTO</a></div></div>';
    if (preg_match('/^(SN|RN)?[\\d\\/,\\s]+$/', $m['ref'])) echo '<div class="flash">This matter has no firm file number yet (DocketTrak used the USPTO number).' . (is_admin() ? ' <form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="rename"><input type="hidden" name="matter_id" value="' . $m['id'] . '"><input type="hidden" name="back" value="' . h($here) . '"><input type="text" name="ref" placeholder="e.g. ABC001" size="12" pattern="[A-Z]{2,4}[0-9]{3}.*"> <button class="small">Assign</button></form>' : '') . '</div>';
    $cur = $m['dead'] ? 'inactive' : 'active';
    // Active / Inactive in the box's upper right (2026-09-30); its controls belong to #actform further down.
    $pic = (($cimg = catalog_image($m)) ? '<a href="' . h($cimg['page'] ?: $cimg['url']) . '" target="_blank" rel="noopener"><img src="' . h($cimg['url']) . '" alt="' . ($cimg['kind'] === 'figure' ? 'patent figure' : 'mark drawing') . '" style="max-height:140px;max-width:280px;border:1px solid var(--line);border-radius:6px;' . ($cimg['kind'] === 'figure' ? '' : 'padding:4px;background:#fff') . '"></a>' : (($lsn = logo_serial($m)) !== '' ? '<a href="' . h(tsdr_url($m['app_no'])) . '" target="_blank" rel="noopener"><img src="' . h(logo_url($lsn)) . '" alt="mark drawing" style="max-height:110px;max-width:260px;border:1px solid var(--line);border-radius:6px;padding:4px;background:#fff"></a>' : ''));   // patent figure / logo, under the Active/Inactive selector (2026-10-14)
    echo '<div class="grid2"><div class="box" style="position:relative"><div class="actcorner">' . '<label class="act ' . $cur . '"><select name="state" form="actform" onchange="document.getElementById(\'actreason\').style.display=this.value==\'inactive\'?\'inline\':\'none\';document.getElementById(\'actsave\').style.display=this.value==\'' . $cur . '\'?\'none\':\'inline\'"><option value="active"' . ($m['dead'] ? '' : ' selected') . '>Active</option><option value="inactive"' . ($m['dead'] ? ' selected' : '') . '>Inactive</option></select></label>'
       . '<input type="text" id="actreason" name="reason" form="actform" placeholder="reason, optional (lapsed, sold, abandoned...)" size="34" style="display:none">'
       . '<button class="small gold" id="actsave" form="actform" style="display:none">' . ($m['dead'] ? 'Make active again' : (is_admin() ? 'Mark inactive: close ' . $left . ' future event(s)' : 'Mark inactive (an admin confirms)')) . '</button>' . $pic . '</div><dl>';
    $NL = num_labels((string)$m['kind']);
    foreach ([$NL[0] => h($m['title']), $NL[1] => num_link($m), $NL[2] => num_link($m, true), 'PTO status' => $m['pto_status'] . ($m['pto_status_date'] ? ' (' . fmt_date($m['pto_status_date']) . ')' : ''), 'Attorney' => $m['attorney']] as $k => $v) echo "<dt>$k</dt><dd>" . (in_array($k, $NL, true) ? $v : h($v)) . '</dd>';
    echo '</dl>';
    // Dates row + the Active/Inactive selector side by side (2026-09-25: to the right of Grant / reg. date, same size).
    // Two forms cannot nest, so the selector's controls point at their own form by id (the HTML form= attribute).
    $cur = $m['dead'] ? 'inactive' : 'active';
    echo '<form method="post" id="noteform">' . csrf_field() . '<input type="hidden" name="action" value="matter_note"><input type="hidden" name="matter_id" value="' . $m['id'] . '"><input type="hidden" name="back" value="' . h($here) . '"></form>'
       . '<form method="post" id="actform">' . csrf_field() . '<input type="hidden" name="action" value="set_active"><input type="hidden" name="matter_id" value="' . $m['id'] . '"><input type="hidden" name="back" value="' . h($here) . '"></form>'
       . '<div class="bar" style="margin-top:10px"><label>Filing date <input type="date" name="filing_date" form="noteform" value="' . h($m['filing_date']) . '"></label><label>Grant / reg. date <input type="date" name="grant_date" form="noteform" value="' . h($m['grant_date']) . '"></label>'
       . '</div>';
    if ($m['dead']) echo '<p class="bad" style="margin:0 0 8px"><b>Inactive.</b> ' . h($m['dead_reason'] ?: $m['pto_status']) . ($m['dead_at'] ? ' (' . fmt_date($m['dead_at']) . ')' : '') . '. No further USPTO checks or client reminders.' . ($left ? ' ' . $left . ' event(s) still open. ' . (is_admin() ? btn('confirm_dead', ['matter_id' => $m['id'], 'back' => $here], 'Done: close all ' . $left, 'small gold') : '<span class="muted">awaiting an admin</span>') : '') . '</p>';
    echo '<textarea name="notes" form="noteform" style="min-height:60px" placeholder="matter notes">' . h($m['notes']) . '</textarea><br><button class="small ghost" form="noteform">Save</button></div>';
    // Every address known for this client (main, lists, imported / seen on its mail): click one to add it to the address field last clicked (2026-09-30).
    $knownA = split_addresses(implode(';', [$c['email'] ?? '', $c['email_tm'] ?? '', $c['email_patent'] ?? '', $c['email_ap'] ?? '', $c['alt_emails'] ?? '']));
    $known = $knownA ? '<div class="bar" style="margin-top:8px"><span class="muted">Known addresses (click to add to the field you last clicked):</span>' . implode('', array_map(fn($a) => '<button type="button" class="small ghost addr-pick">' . h($a) . '</button>', $knownA)) . '</div>' : '';
    $knownJs = $knownA ? '<script>(function(){var last=null,f=document.querySelector(".addr-pick").form;f.querySelectorAll("input[name=email],input[name^=email_]").forEach(function(i){i.addEventListener("focus",function(){last=i;});});'
        . 'f.querySelectorAll(".addr-pick").forEach(function(b){b.addEventListener("click",function(){var i=last||f.querySelector("input[name=email]"),a=b.textContent;{var v=i.value.split(/[;,]/).map(function(x){return x.trim();}).filter(Boolean);if(v.indexOf(a)<0)v.push(a);i.value=v.join(", ");}i.focus();});});})();</script>' : '';
    echo '<div class="box"><h2 style="margin-top:0">Client</h2>'
       . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="set_client"><input type="hidden" name="matter_id" value="' . $m['id'] . '"><input type="hidden" name="code" value="' . h($c['code']) . '">'
       . '<div class="bar"><label>Client code <input type="text" name="client_code" value="' . h($m['client_code']) . '" size="6" maxlength="4" style="text-transform:uppercase;font-weight:600" pattern="[A-Za-z]{3,4}" title="three or four letters, e.g. CAW, RWG, PAMI; changing it moves this matter to that client"></label></div>'
       . '<input type="hidden" name="back" value="' . h($here) . '"><div class="bar"><label>Client <input type="text" name="name" value="' . h($c['name']) . '" placeholder="client name" size="28"></label><label>Email <input type="text" name="email" value="' . h($c['email']) . '" placeholder="separated by commas (reminders go here)" size="40"></label><label>Phone <input type="text" name="phone" value="' . h($c['phone'] ?? '') . '" placeholder="separated by commas" size="26"></label></div>'
       . $known . '<details style="margin-top:6px"><summary>Special Emails' . (($nAdv = (int)(trim((string)($c['email_tm'] ?? '')) !== '') + (int)(trim((string)($c['email_patent'] ?? '')) !== '') + (int)(trim((string)($c['email_ap'] ?? '')) !== '')) ? ' <span class="muted">(' . $nAdv . ' set)</span>' : '') . '</summary>'
       . '<div class="bar"><label>Trademarks &amp; copyrights <input type="text" name="email_tm" value="' . h($c['email_tm'] ?? '') . '" placeholder="optional: addresses for TM/copyright email, separated by commas" size="60"></label></div>'
       . '<div class="bar"><label>Patents <input type="text" name="email_patent" value="' . h($c['email_patent'] ?? '') . '" placeholder="optional: addresses for patent email, separated by commas" size="60"></label></div>'
       . '<div class="bar"><label>Accounts payable <input type="text" name="email_ap" value="' . h($c['email_ap'] ?? '') . '" placeholder="optional: added only when an invoice is attached" size="46"></label></div>'
       . '<div class="muted">When a list is filled in, emails about that kind of matter go to it instead of the main address. Accounts payable is added automatically to any email that carries an invoice.</div>'
       . '</details>'
       // Foreign associates (2026-10-14, XYZ001): per-matter fields; an instructing attorney makes every email go to that person only.
       . '<details style="margin-top:6px"' . (is_assoc_matter($m) ? ' open' : '') . '><summary>Foreign associate</summary>'
       . '<div class="bar"><label>Their file no. <input type="text" name="their_ref" value="' . h($m['their_ref'] ?? '') . '" size="18" placeholder="associate\'s reference"></label><label>Applicant <input type="text" name="owner" value="' . h($m['owner'] ?? '') . '" size="34" placeholder="owner / applicant"></label></div>'
       . '<div class="bar"><label>Instructing attorney <input type="text" name="instructing" value="' . h($m['instructing'] ?? '') . '" size="46" placeholder="emails for this matter go only here"></label></div>'
       . '<div class="bar"><label>Accounts payable (this matter) <input type="text" name="m_email_ap" value="' . h($m['ap_email'] ?? '') . '" size="40" placeholder="optional: overrides the client\'s"></label></div>'
       . '</details><div class="bar" style="margin-top:8px"><button class="small">Save</button></div></form>' . $knownJs;
    $others = q('SELECT id, ref, title, kind, app_no, reg_no, dead, status, country FROM matters WHERE client_code=? AND id<>? ORDER BY ref DESC', [$m['client_code'], $m['id']]);
    if ($others) { echo '<p class="muted" style="margin-bottom:4px">Other matters for this client (' . count($others) . '):</p><div class="scrollbox"><table><thead><tr><th class="file">File</th><th>Kind</th><th>' . ($OL = num_labels(count(array_unique(array_column($others, 'kind'))) === 1 ? (string)$others[0]['kind'] : ''))[0] . '</th><th>' . $OL[1] . '</th><th>' . $OL[2] . '</th></tr></thead><tbody>';
        foreach ($others as $o) echo '<tr' . ($o['dead'] ? ' class="deadrow"' : '') . '><td class="file"><a href="' . url('matter', ['id' => $o['id']]) . '"><b>' . h($o['ref']) . '</b></a></td><td>' . h($o['kind']) . '</td><td class="title">' . h(trunc($o['title'], 44)) . '</td><td>' . num_link($o) . '</td><td>' . num_link($o, true) . '</td></tr>';
        echo '</tbody></table></div>'; }
    echo '</div></div>';
    // Priority claims for US patents (2026-09-30). Fetched once from ODP when missing (retried weekly while unpublished); checks refresh it.
    if ($m['kind'] === 'patent' && preg_match('/^(|United States|US|USA)$/i', trim((string)$m['country']))) {
        $pd = preg_replace('/\D/', '', (string)$m['app_no']);
        if (strlen($pd) === 8 && (string)$m['priority_json'] === '' && (empty($m['priority_at']) || $m['priority_at'] < date('Y-m-d', strtotime('-7 days')))) {
            try { $pl = odp_lookup($pd); if (empty($pl['ok'])) run("UPDATE matters SET priority_at=datetime('now') WHERE id=?", [$m['id']]); } catch (Throwable $e) {}
            $m = q1('SELECT * FROM matters WHERE id=?', [$m['id']]);
        }
        echo priority_box($m);
    }
    // Goods / services (2026-09-29: US trademarks only for now). Stored one line per class, "025 - Shirts; Hats".
    if ($m['kind'] === 'trademark' && preg_match('/^(|United States|US|USA)$/i', trim((string)$m['country']))) {
        $gl = []; foreach (preg_split('/\R/', trim((string)$m['goods'])) as $l) if (preg_match('/^\s*(\d{1,3}|[AB])\s*-\s*(.+)$/', $l, $gm)) $gl[] = [$gm[1], trim($gm[2])];
        echo '<div class="box"><h2 style="margin-top:0">Goods / services' . ($m['app_no'] !== '' ? ' <a class="btn small ghost" target="_blank" rel="noopener" href="' . h(tsdr_url($m['app_no'])) . '">TSDR</a>' : '') . '</h2>';
        if ($gl) { echo '<table><tbody>'; foreach ($gl as [$gc, $gt]) echo '<tr><td class="nowrap" style="width:1%"><b>' . (ctype_digit($gc) ? 'Class ' . h($gc) : ($gc === 'A' ? 'Cert. (goods)' : 'Cert. (services)')) . '</b></td><td>' . h($gt) . '</td></tr>'; echo '</tbody></table>'; }
        else echo '<p class="bad">None on file yet (they come from the USPTO record). Renewal emails will show a note to copy them from TSDR.</p>';
        // Read-only (2026-09-30): the list comes from the USPTO record, never typed in here.
        echo '</div>';
    }
    // Fee paid / Undo (2026-09-28): in the Open events header, right of Status / Note.
    [$fpCur, $fpNext] = $m['dead'] ? [null, null] : fee_current_next((int)$m['id']); $fpHead = '';
    if ($fpCur && auth_email() !== '') { $fpEv = $fpCur['events']['due'] ?? reset($fpCur['events']); $fpHead .= '<a class="btn small gold" href="' . url('send', ['id' => $fpEv['id'], 'feepaid' => 1, 'back' => $here]) . '" title="' . h($fpCur['label'] . ' (due ' . fmt_date($fpCur['due']) . '): emails the receipt with the next dates' . ($fpNext ? ' (' . $fpNext['label'] . ')' : '') . ', then closes this cycle\'s events') . '">' . ($m['kind'] === 'trademark' ? 'Renewal filed' : 'Maint. Fee Paid?') . '</a>'; }
    // 2026-10-13: Renew Patent, left of Maint. Fee Paid?, on every issued US utility patent; opens the USPTO fee form pre-filled.
    if ($m['kind'] === 'patent' && !$m['dead'] && !is_design_matter($m) && preg_match('/^(|United States|US|USA)$/i', trim((string)$m['country']))
        && ($rpUrl = uspto_mf_url($m)) !== '')
        $fpHead = '<a class="btn small gold" target="_blank" rel="noopener" href="' . h($rpUrl) . '" title="USPTO maintenance fee page, patent and application numbers filled in">Renew Patent</a> ' . $fpHead;
    if (($fpLast = fee_paid_last_batch((int)$m['id'])) && fee_paid_can_undo($fpLast)) $fpHead .= ' ' . btn('fee_paid_undo', ['matter_id' => $m['id'], 'back' => $here], 'Undo', 'small ghost', 'Reopen the ' . count($fpLast['events']) . ' event(s) closed as fee paid on ' . $fpLast['date'] . '? The email that went out cannot be recalled.');
    // 2026-10-08 (XYZ060): not once the report went out (its to-do is done), only the open Statement of Use deadline left.
    if ($m['kind'] === 'trademark' && auth_email() !== '' && !q1('SELECT 1 FROM events WHERE matter_id=? AND type=? AND status="done"', [$m['id'], NOA_REPORT_EVENT]) && ($noaEv = q1('SELECT id FROM events WHERE matter_id=? AND type IN (?,?) AND status IN ("open","proposed") ORDER BY type="' . NOA_EVENT . '", due_date LIMIT 1', [$m['id'], NOA_REPORT_EVENT, NOA_EVENT])))
        $fpHead = '<a class="btn small gold" href="' . url('send', ['id' => $noaEv['id'], 'report' => 'noa', 'back' => $here]) . '" title="Congratulations email with the Statement of Use deadline, goods and fees; forwards the USPTO notice">Report Notice of Allowance</a> ' . $fpHead;
    // 2026-10-04: the patent Office Action / NOA report, from its to-do (lib/oareport.php); the PDFs saved in the folder are attached.
    if ($m['kind'] === 'patent' && auth_email() !== '' && ($oaEv = oa_report_open((int)$m['id'])))
        $fpHead = '<a class="btn small gold" href="' . url('send', ['id' => $oaEv['id'], 'report' => 'oa', 'back' => $here]) . '" title="' . h('Client report: ' . oa_report_label((string)$oaEv['doc_code']) . ' mailed ' . fmt_date((string)$oaEv['mail_date']) . '; the saved PDF is attached and the deadline / pay-by dates filled in') . '">Report ' . h(oa_report_label((string)$oaEv['doc_code'])) . '</a> ' . $fpHead;
    echo '<h2>Open events</h2>'; events_table(q("$EVSQL WHERE e.matter_id=? AND e.status IN ('open','proposed') ORDER BY e.due_date", [$m['id']]), false, $fpHead);
    $types = array_column(q('SELECT DISTINCT type FROM events ORDER BY type'), 'type');
    echo '<div class="box addev"><b>Add an event to this record</b><form method="post" class="bar">' . csrf_field() . '<input type="hidden" name="action" value="add_event"><input type="hidden" name="matter_id" value="' . $m['id'] . '"><input type="hidden" name="back" value="' . h($here) . '"><select name="type"><option value="">— choose —</option>'; foreach ($types as $t) echo '<option>' . h($t) . '</option>'; echo '</select> or <input type="text" name="type_other" placeholder="new event type" size="24"><input type="date" name="due" required><input type="text" name="note" placeholder="note" size="30"><button class="small">Add</button></form></div>';
    $hist = q("$EVSQL WHERE e.matter_id=? AND e.status IN ('done','cancelled') ORDER BY e.due_date", [$m['id']]);
    if ($hist) { echo '<h2>History <small>(completed or cancelled, oldest first)</small></h2><table><thead><tr><th>Was due</th><th>Event</th><th>Status</th><th>Completed</th><th>Note</th><th></th></tr></thead><tbody>';
        foreach ($hist as $r) echo '<tr><td class="nowrap">' . fmt_date($r['due_date']) . '</td><td>' . h($r['type']) . '</td><td>' . status_pill($r['status']) . '</td><td class="nowrap">' . ($r['done_at'] ? fmt_date(et($r['done_at'], 10)) : '<span class="muted">date not recorded</span>') . ($r['done_by'] ? ' <span class="muted">by ' . h($r['done_by']) . '</span>' : ' <span class="muted">in DocketTrak</span>') . '</td><td class="note">' . h($r['note']) . '</td><td class="acts">' . event_actions($r) . '</td></tr>';
        echo '</tbody></table>'; }
    if (is_admin()) { echo '<details><summary>Change a due date</summary><form method="post" class="bar">' . csrf_field() . '<input type="hidden" name="action" value="snooze"><input type="hidden" name="back" value="' . h($here) . '"><select name="id">'; foreach (q('SELECT id,type,due_date FROM events WHERE matter_id=? AND status="open" ORDER BY due_date', [$m['id']]) as $e) echo '<option value="' . $e['id'] . '">' . h($e['type'] . ' ' . fmt_date($e['due_date'])) . '</option>'; echo '</select><input type="date" name="due" required><input type="text" name="why" placeholder="reason (e.g. extension filed)" size="30"><button class="small">Change</button></form></details>'; }
    pc_reconcile();   // a "Download with Claude" file that has reached Dropbox marks its row saved (lib/pcfetch.php)
    $cor = q('SELECT * FROM correspondence WHERE matter_id=? ORDER BY mail_date DESC', [$m['id']]);
    if ($cor) { $oaf = []; foreach (q('SELECT * FROM oa_files WHERE correspondence_id IN (' . implode(',', array_map('intval', array_column($cor, 'id'))) . ')') as $f) $oaf[$f['correspondence_id']] = $f;
        echo '<h2>USPTO correspondence received</h2><table><thead><tr><th>Date</th><th>Document</th><th>What it is</th><th>Source</th><th>Saved to Dropbox</th></tr></thead><tbody>';
        foreach ($cor as $x) { $f = $oaf[$x['id']] ?? null; $fl = $f ? json_decode((string)$f['files'], true) ?: [] : [];
            $filed = !$f ? (in_array($x['doc_code'], array_merge(OA_PATENT_CODES, OA_TM_CODES, OA_RECEIPT_CODES), true) ? '<span class="muted">waiting for the hourly fetch</span>' : '') : ($f['status'] === 'manual' ? pc_cell($x, pc_requests_for([$x['id']])[$x['id']] ?? null, $here) : ($fl ? '<span class="ok">' . h(implode('; ', $fl)) . '</span>' . ($f['note'] ? ' <span class="muted">' . h($f['note']) . '</span>' : '') : '<span class="bad">' . h($f['status']) . '</span> ' . h($f['note'])));
            // 2026-10-06 (RWG166.001.01): the paper's own row offers the client email too, once it is in the file (same link as the to-do's Send to client)
            $todo = auth_email() !== '' ? q1('SELECT * FROM events WHERE matter_id=? AND doc_code=? AND mail_date=? AND status IN ("open","proposed") ORDER BY id DESC', [$m['id'], (string)$x['doc_code'], (string)$x['mail_date']]) : null;
            if ($todo && (is_notice_todo((string)$todo['type']) || is_oa_report((string)$todo['type'])) && oa_report_ready($todo)) $filed .= ' <a class="btn small gold" href="' . url('send', ['id' => $todo['id'], 'report' => 'oa', 'back' => $here]) . '">Send to client</a>';
            echo '<tr><td>' . fmt_date($x['mail_date']) . '</td><td>' . h($x['doc_code']) . '</td><td>' . h($x['summary']) . '</td><td class="muted">' . h($x['source']) . '</td><td class="note">' . $filed . '</td></tr>'; }
        echo '</tbody></table>'; }
    $rem = q('SELECT r.*, e.type FROM reminders r JOIN events e ON e.id=r.event_id WHERE e.matter_id=? ORDER BY r.scheduled_for DESC', [$m['id']]);
    $log = q("SELECT ts, user who, action, detail FROM audit WHERE matter_id=?
        UNION ALL SELECT created_at, from_user, CASE mode WHEN 'draft' THEN 'gmail_draft' ELSE 'email' END, 'To ' || to_email || CASE WHEN cc_email<>'' THEN ' cc ' || cc_email ELSE '' END || ': ' || subject FROM outbox WHERE matter_id=?
        UNION ALL SELECT COALESCE(r.sent_at, r.created_at), 'nightly', 'reminder_' || r.status, r.lead || '-day reminder for ' || e.type || ' → ' || r.to_email FROM reminders r JOIN events e ON e.id=r.event_id WHERE e.matter_id=? AND r.status IN ('sent','draft')
        UNION ALL SELECT proposed_at, proposed_by, 'proposed_done', type || ' (' || due_date || ')' FROM events WHERE matter_id=? AND proposed_at IS NOT NULL AND proposed_at < '2026-09-28 20:05:00'
        UNION ALL SELECT done_at, done_by, 'done', type || ' (' || due_date || ')' FROM events WHERE matter_id=? AND done_at IS NOT NULL AND done_by IS NOT NULL AND done_at < '2026-09-28 20:05:00'
        UNION ALL SELECT created_at, source, 'docketed', type || ' due ' || due_date || CASE WHEN doc_code<>'' THEN ' (USPTO ' || doc_code || ')' ELSE '' END FROM events WHERE matter_id=? AND source<>'import'
        UNION ALL SELECT ts, by_user, 'uspto_check', COALESCE(status,'') || ' ' || COALESCE(summary,'') FROM checks WHERE matter_id=?
        UNION ALL SELECT created_at, source, 'uspto_correspondence', doc_code || ' ' || mail_date || ' ' || summary FROM correspondence WHERE matter_id=?
        ORDER BY 1 DESC LIMIT 300", array_fill(0, 8, $m['id']));
    // Collapsed; click to expand (2026-09-30).
    echo '<script>addEventListener("DOMContentLoaded",function(){if(location.hash=="#chlog"){var d=document.getElementById("chlog");d.open=true;d.scrollIntoView();}});</script>';   // "emailed client" note links land here open
    echo '<details class="chlog" id="chlog"><summary><h2 style="display:inline">Change log</h2> <small class="muted">(' . count($log) . ')</small></summary>';
    if (!$log) echo '<p class="muted">Nothing yet beyond the DocketTrak import.</p>'; else {
        $logrow = fn($r) => '<tr><td class="nowrap">' . h(et((string)$r['ts'])) . '</td><td>' . h($r['who']) . '</td><td>' . h(str_replace('_', ' ', $r['action'])) . '</td><td class="note">' . h($r['detail']) . '</td></tr>';
        $hdr = '<table><thead><tr><th>When</th><th>Who</th><th>What</th><th>Detail</th></tr></thead><tbody>';
        echo $hdr . implode('', array_map($logrow, array_slice($log, 0, 10))) . '</tbody></table>';   // the latest 10 always visible; older entries fold away
        if (count($log) > 10) echo '<details class="logmore"><summary>Show the ' . (count($log) - 10) . ' older entr' . (count($log) - 10 === 1 ? 'y' : 'ies') . '</summary>' . $hdr . implode('', array_map($logrow, array_slice($log, 10))) . '</tbody></table></details>';
    }
    echo '</details>';
    if ($rem) { echo '<h2>Reminders</h2><table><thead><tr><th>Scheduled</th><th>Lead</th><th>Event</th><th>To</th><th>Status</th><th>Why</th></tr></thead><tbody>'; foreach ($rem as $r) echo '<tr><td>' . fmt_date($r['scheduled_for']) . '</td><td>' . $r['lead'] . 'd</td><td>' . h($r['type']) . '</td><td>' . h($r['to_email']) . '</td><td>' . status_pill($r['status']) . '</td><td class="note">' . h($r['reason']) . '</td></tr>'; echo '</tbody></table>'; }
    layout_bottom(); break; }
case 'done': {
    $ev = q1("$EVSQL WHERE e.id=?", [(int)$_GET['id']]); if (!$ev || $ev['status'] !== 'open') redirect(safe_back($_GET['back'] ?? '?'), 'That event is not open.');
    $back = safe_back($_GET['back'] ?? url('matter', ['id' => $ev['matter_id']]));
    $open = q('SELECT * FROM events WHERE matter_id=? AND status="open" ORDER BY due_date', [$ev['matter_id']]);
    $cands = related_done_candidates($ev, $open);
    $nx = next_sou_event($ev['type'], $ev['due_date']); if ($nx && q1('SELECT 1 FROM events WHERE matter_id=? AND type=?', [$ev['matter_id'], $nx['type']])) $nx = null;   // DocketTrak usually dockets the whole chain up front
    // Even with nothing related to choose, completion is a POST with a token: a bare link must never close an event.
    layout_top('Mark done', $flash);
    echo '<h1>Mark done: ' . h($ev['type']) . '</h1><div class="box"><p><a href="' . url('matter', ['id' => $ev['matter_id']]) . '"><b>' . h($ev['ref']) . '</b></a> ' . h($ev['title']) . ' · due ' . fmt_date($ev['due_date']) . ' ' . badge_days($ev['due_date']) . '</p>';
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="done_multi"><input type="hidden" name="id" value="' . $ev['id'] . '"><input type="hidden" name="back" value="' . h($back) . '">';
    if (!$cands) echo '<p>Mark this event done' . (is_admin() ? '' : ' (an admin confirms)') . '?</p>';
    if ($cands) { echo '<p>These open events on the same matter look like part of the same step. Untick any that are <b>not</b> finished:</b></p>'; foreach ($cands as $c) echo '<label class="chk"><input type="checkbox" name="ids[]" value="' . $c['id'] . '"' . (!empty($c['checked']) ? ' checked' : '') . '> ' . h($c['type']) . ' · due ' . fmt_date($c['due_date']) . ' ' . badge_days($c['due_date']) . ($c['note'] ? ' <span class="muted">' . h($c['note']) . '</span>' : '') . '</label>'; }
    if ($nx) echo '<p class="muted">Tick the later extensions only if a Statement of Use was filed.</p><p><label><input type="checkbox" name="no_next" value="1"> A Statement of Use was filed (do <b>not</b> docket ' . h($nx['type']) . ' for ' . fmt_date($nx['due']) . ')</label></p>';
    echo '<p><input type="text" name="why" placeholder="Notes" size="60"></p><div class="bar"><button class="gold">' . (is_admin() ? 'Mark done' : 'Mark done (an admin confirms)') . '</button> <a class="btn" style="background:#fff;color:var(--navy)" href="' . h($back) . '">Cancel</a></div></form></div>';
    layout_bottom(); break; }
case 'send': {
    if (auth_email() === '') redirect(safe_back($_GET['back'] ?? '?'), 'Your login has no sending address.');
    $ev = q1("$EVSQL WHERE e.id=?", [(int)$_GET['id']]); if (!$ev) redirect('?', 'No such event.');
    if (!in_array($ev['status'], ['open', 'proposed'], true) && !(!empty($_GET['feepaid']) && fee_paid_event_ok($ev)) && !isset(SOU_REPORT_TEMPLATES[(string)($_GET['report'] ?? '')])) redirect(safe_back($_GET['back'] ?? url('matter', ['id' => $ev['matter_id']])), 'That event is closed.');   // the filed / paid reports follow the Done click
    // Expected Issue Date is not a client deadline (2026-10-04, XYZ017): the generic "due by ... let me know your intentions" made no sense.
    // The client email is the issuance report, created on the issue day by run_issuance() with the eGrant + letter.
    if ($ev['type'] === 'Expected Issue Date') {
        if ($ir = q1("SELECT id FROM events WHERE matter_id=? AND type=? AND status IN ('open','proposed') ORDER BY id DESC LIMIT 1", [$ev['matter_id'], ISSUANCE_REPORT_EVENT])) redirect(url('send', ['id' => $ir['id'], 'back' => $_GET['back'] ?? '']), 'Opened the issuance report.');
        redirect(safe_back($_GET['back'] ?? url('matter', ['id' => $ev['matter_id']])), 'Nothing to send for the Expected Issue Date. On the issue day (' . fmt_long($ev['due_date']) . ') Madder saves the patent certificate into the file, writes the issuance letter and adds a "' . ISSUANCE_REPORT_EVENT . '" to-do with the client email.');
    }
    $abn = $ev['type'] === ABANDON_REPORT_EVENT;   // 2026-10-01
    if (!$abn && q1('SELECT dead FROM matters WHERE id=?', [$ev['matter_id']])['dead']) redirect(safe_back($_GET['back'] ?? url('matter', ['id' => $ev['matter_id']])), 'That property is dead; nothing to remind the client about.');
    $early = days_until($ev['due_date']) > SEND_WINDOW_DAYS ? 'Heads up: this deadline is ' . days_until($ev['due_date']) . ' days out; automatic reminders start ' . SEND_WINDOW_DAYS . ' days before. Sending now is fine if you mean to.'
           : (days_until($ev['due_date']) < 0 && !paired_deadline_type((string)$ev['type']) ? 'Heads up: this date passed ' . abs(days_until($ev['due_date'])) . ' day(s) ago (' . fmt_long($ev['due_date']) . '). The wording below still says "due by" that date; adjust it (grace period, surcharge) before sending.' : null);
    $m = q1('SELECT * FROM matters WHERE id=?', [$ev['matter_id']]); $c = q1('SELECT * FROM clients WHERE code=?', [$m['client_code']]) ?: ['code' => $m['client_code'], 'name' => '', 'email' => ''];
    $back = safe_back($_GET['back'] ?? url('matter', ['id' => $m['id']]));
    $t = q1('SELECT * FROM templates WHERE event_type=?', [tpl_type($ev['type'], (string)$m['app_no'])]) ?: q1('SELECT * FROM templates WHERE event_type=?', [$ev['type']]) ?: (paired_deadline_type($ev['type'])
        ? ['subject' => '{FILE}: {TITLE} - {DEADLINE_EVENT} due {DEADLINE}', 'body' => "REMINDER:  \n\nIn the above matter, the filing window opened on {DUE}. The deadline is {DEADLINE} ({DEADLINE_EVENT}).\n\nPlease let me know your intentions at this time so that we can meet the deadline."]
        : ['subject' => '{FILE}: {TITLE} - {DEADLINE_EVENT} due {DEADLINE}', 'body' => "REMINDER:  \n\nIn the above matter, {DEADLINE_EVENT} is due by {DEADLINE}.\n\nPlease let me know your intentions at this time so that we can meet the deadline.", 'needs_invoice' => 0, 'approved' => 0]);
    // Fee paid (2026-09-28): receipt + next cycle's dates; the paid cycle's events are closed when it is sent.
    $fp = null;
    $sr = isset(SOU_REPORT_TEMPLATES[(string)($_GET['report'] ?? '')]) ? (string)$_GET['report'] : '';   // Extension / Statement of Use filed email (2026-10-05)
    if ($sr) { $t = q1('SELECT * FROM templates WHERE event_type=?', [SOU_REPORT_TEMPLATES[$sr]]); if (!$t) redirect($back, 'The "' . SOU_REPORT_TEMPLATES[$sr] . '" template is missing; run bin/seed_templates.php.'); $early = null; }
    if (!empty($_GET['feepaid'])) {
        [$fpCur, $fpNext] = fee_current_next((int)$m['id']);
        if (!$fpCur) redirect($back, 'No open maintenance fee / renewal events on this record.');
        $fpT = q1('SELECT * FROM templates WHERE event_type=?', [fee_paid_template_type($m, $fpNext)]); if (!$fpT) redirect($back, 'The fee-paid template is missing; run bin/seed_templates.php.');
        $t = $fpT; $t['body'] = str_replace('{NEXT_FEES}', fee_next_lines($fpNext, $m['kind']), $t['body']); $t['subject'] = str_replace('{NEXT_FEES}', '', $t['subject']);
        $fp = ['cur' => $fpCur, 'next' => $fpNext, 'type' => $fpT['event_type']]; $early = null;
    }
    // Abandonment email (2026-10-01): the "Abandoned: ..." template, filled from the deadline that was missed.
    if ($abn) {
        $at = q1('SELECT * FROM templates WHERE event_type=?', [ABANDON_TEMPLATES[$m['kind']] ?? '']); if (!$at) redirect($back, 'The abandonment template for ' . $m['kind'] . 's is missing.');
        $t = $at; $abnMissed = abandon_missed_event((int)$m['id'], (string)$ev['mail_date']);
        $early = $abnMissed ? 'Abandoned ' . fmt_long((string)$ev['mail_date']) . '. The missed deadline below is ' . $abnMissed['type'] . ' (' . fmt_long($abnMissed['due_date']) . ').'
                            : 'Abandoned ' . fmt_long((string)$ev['mail_date']) . '. No missed deadline was found on this record: check the deadline date and what was not filed before sending.';
    }
    // Opposition period ended (2026-10-04): congratulations + Notice of Allowance (intent to use) or registration in due course. Not before the period is over.
    if ($ev['type'] === OPP_END_EVENT) {
        if ((string)$ev['due_date'] >= today()) redirect($back, 'Too early to send: the opposition period ends ' . fmt_long((string)$ev['due_date']) . '.');
        $ot = q1('SELECT * FROM templates WHERE event_type=?', [OPP_REPORT_TEMPLATE]); if (!$ot) redirect($back, 'The "' . OPP_REPORT_TEMPLATE . '" template is missing; run bin/seed_templates.php.');
        $t = $ot; $early = 'Before sending, check TTABVUE that no opposition or extension of time to oppose was filed' . (($sn = preg_replace('/\D/', '', (string)$m['app_no'])) !== '' ? ' (serial ' . $sn . ')' : '') . '. Next step is worded for filing basis "' . (tm_basis($m) ?: 'unknown') . '".';
    }
    // Notice of Allowance report (2026-09-29): his wording; the USPTO notice is forwarded below the signature.
    $noa = null; $noaErr = null;
    if ($ev['type'] === NOA_REPORT_EVENT && empty($_GET['report'])) $_GET['report'] = 'noa';   // 2026-10-06 (XYZ006): this to-do is always the NOA report
    if (!empty($_GET['report']) && $_GET['report'] === 'noa') {
        if (!in_array($ev['type'], [NOA_EVENT, NOA_REPORT_EVENT], true)) redirect($back, 'The Notice of Allowance report starts from the "' . NOA_REPORT_EVENT . '" or "' . NOA_EVENT . '" event.');
        $sou = $ev['type'] === NOA_EVENT ? $ev : q1("$EVSQL WHERE e.matter_id=? AND e.type=? AND e.status IN ('open','proposed') ORDER BY e.due_date LIMIT 1", [$m['id'], NOA_EVENT]);
        if (!$sou) redirect($back, 'No open "' . NOA_EVENT . '" event on ' . $m['ref'] . ', so there is no deadline to report.');
        $nt = q1('SELECT * FROM templates WHERE event_type=?', [NOA_TEMPLATE]); if (!$nt) redirect($back, 'The Notice of Allowance template is missing; run bin/seed_templates.php.');
        $t = $nt; $early = null; $noa = ['notice' => null, 'sou' => $sou];
    }
    // Patent Office Action / NOA report (2026-10-04, lib/oareport.php): his wording, filled from the "Respond to Office Action - <CODE>"
    // deadline; the saved action PDF (+ PTO-892) is pre-ticked below; the USPTO e-mail is not forwarded. The to-do is always this report.
    $oar = null;
    if (is_oa_report((string)$ev['type'])) {
        if (function_exists('pc_reconcile')) pc_reconcile();   // a file Claude just saved shows as saved
        $oar = oa_report_context($ev, $m);
        if (!$oar['tpl']) redirect($back, 'The "' . oa_report_template((string)$ev['doc_code'], (string)$m['app_no']) . '" template is missing; run bin/seed_templates.php.');
        $t = $oar['tpl']; $early = $oar['notice'];
    } elseif (is_notice_todo((string)$ev['type'])) {   // 2026-10-04: any other USPTO patent notice, 'Report USPTO Notice' (lib/oareport.php)
        if (function_exists('pc_reconcile')) pc_reconcile();
        $oar = notice_todo_context($ev, $m);
        if (!$oar['tpl']) redirect($back, 'The "' . $oar['tpl_name'] . '" template is missing; run bin/seed_templates.php.');
        $t = $oar['tpl']; $early = $oar['notice'];
    } elseif (!empty($_GET['report']) && $_GET['report'] === 'oa') redirect($back, 'The Office Action report starts from a "' . OA_REPORT_EVENT . '" or "' . PNOA_REPORT_EVENT . '" to-do.');
    // A "Remind Client" tickler borrows the template of the deadline it points at (e.g. the Statement of Use wording with fees).
    $fillEv = $noa ? $noa['sou'] : ($oar ? $oar['fill'] : ($abn ? ($abnMissed ?: $ev) : $ev));   // the report fills from the Statement of Use deadline, not the to-do dated the NOA issue day
    if (!$fp && !$noa && !$abn && !$oar && !$sr && ($target = remind_target($ev)) && ($tt = q1('SELECT * FROM templates WHERE event_type=?', [$target['type']]))) { $t = $tt; $fillEv = $target; }
    // Past the deadline with a grace period docketed: the grace-period email (surcharge, last day) instead of "due by" a date already gone.
    if (!$fp && !$noa && !$abn && !$oar && !$sr && ($gr = grace_successor($fillEv)) && ($gt = q1('SELECT * FROM templates WHERE event_type=?', [$gr['type']]))) { $t = $gt; $fillEv = $gr; $early = 'This deadline passed ' . abs(days_until($ev['due_date'])) . ' day(s) ago (' . fmt_long($ev['due_date']) . '), so the grace-period wording is used (' . $gr['type'] . ', last day ' . fmt_long($gr['due_date']) . ').'; }
    // Every deadline missed, no grace period: the "application abandoned" email (how to revive; fees will be due; no further reminders).
    elseif (!$fp && !$noa && !$abn && !$oar && !$sr && ($abT = abandonment_template($fillEv, $m)) && ($at = q1('SELECT * FROM templates WHERE event_type=?', [$abT]))) { $t = $at; $early = 'This deadline passed ' . abs(days_until($fillEv['due_date'])) . ' day(s) ago (' . fmt_long($fillEv['due_date']) . ') and no later deadline is docketed, so the "application abandoned" wording is used.'; }
    if ($ev['type'] === SOU_SIGN_EVENT && ($sl = sign_links_for((int)$ev['id']))) { $k = array_key_first($sl); $t = sou_sign_email((string)$k, $sl[$k]) + ['event_type' => SOU_SIGN_EVENT, 'approved' => 0, 'needs_invoice' => 0]; $early = !empty($sl[$k]['expires']) ? 'The TEAS signing link expires ' . fmt_long((string)$sl[$k]['expires']) . '.' : null; }   // 2026-10-08
    if ($ev['type'] === POSTREG_SIGN_EVENT && ($sl = sign_links_for((int)$ev['id']))) { $k = array_key_first($sl); $t = postreg_sign_email((string)$k, $sl[$k]) + ['event_type' => POSTREG_SIGN_EVENT, 'approved' => 0, 'needs_invoice' => 0]; $early = !empty($sl[$k]['expires']) ? 'The Trademark Center signing link expires ' . fmt_long((string)$sl[$k]['expires']) . '.' : null; }   // 2026-10-14
    if ($ev['type'] === PAT_SIGN_EVENT && ($sl = sign_links_for((int)$ev['id']))) { $t = prep_sign_email($sl) + ['event_type' => PAT_SIGN_EVENT, 'approved' => 0, 'needs_invoice' => 0]; $early = null; }   // 2026-10-11 (lib/prepfiling.php)
    if ($ev['type'] === SIGN_EVENT && ($sl = sign_links_for((int)$ev['id']))) { $t = sign_email($sl) + ['event_type' => SIGN_EVENT, 'approved' => 0, 'needs_invoice' => 0]; $early = null; }   // 2026-10-06 (lib/signreq.php)
    $subject = fill_subject($t['subject'], $fillEv, $m, $c, $t); $body = fill_template($t['body'], $fillEv, $m, $c, $t); $rem = null; $ob = null; $toPre = client_recipients($c, (string)$m['kind'], (int)$m['id']); $kindList = $toPre !== trim((string)$c['email']);
    // A queued reminder is always shown in the CURRENT template's wording (its stored text was rendered the night it was queued).
    if (!empty($_GET['rem'])) $rem = q1('SELECT * FROM reminders WHERE id=? AND event_id=?', [(int)$_GET['rem'], $ev['id']]) ?: null;
    if (!empty($_GET['ob']) && ($ob = q1('SELECT * FROM outbox WHERE id=? AND transmitted_at IS NULL AND mode IN ("draft","sent")', [(int)$_GET['ob']]))) { if (!is_admin() && $ob['from_user'] !== auth_user()) redirect($back, 'That draft belongs to ' . $ob['from_user'] . '.'); $subject = $ob['subject']; $body = $ob['body']; $toPre = $ob['to_email']; }
    if ($fp) $subject = preg_replace(['/: U\.S\. Patent\s+- /', '/ \(Reg\. No\. \)/'], [': ', ''], $subject);   // no patent / registration number on the record
    $subject = dates_long($subject); $body = strip_plain_signature(dates_long($body));
    $prior = q('SELECT * FROM outbox WHERE matter_id=? ORDER BY id DESC LIMIT 5', [$m['id']]);
    $findErr = null; $found = null; $thr = null; $thrErr = null;
    if ($ob && ($ob['in_reply_to'] ?? '') !== '') $thr = ['message_id' => $ob['in_reply_to'], 'references' => $ob['refs_hdr'], 'subject' => preg_replace('/^Re:\s*/i', '', $ob['subject']), 'from' => '', 'date' => '', 'thread_id' => '', 'outside' => []];
    elseif (!$ob) { try { $thr = gmail_reply_target($m['ref']); } catch (Throwable $e) { $thrErr = $e->getMessage(); } }
    // Replying in a thread goes to the people in that thread (2026-09-28: XYZ009 went to the client record's address, a different person).
    $toClient = $toPre; if ($thr && !$ob && !$kindList && !empty($thr['reply_to'])) $toPre = implode('; ', $thr['reply_to']);   // a per-kind client list wins over the thread
    if (!empty($_GET['find'])) { try { $found = gmail_find_addresses(array_column(q('SELECT ref FROM matters WHERE client_code=? ORDER BY id DESC LIMIT 15', [$m['client_code']]), 'ref'), (string)($c['name'] ?? '')); audit('gmail_addresses', 'searched Gmail for ' . $m['client_code'] . ' addresses: ' . count($found) . ' found', (int)$m['id']); } catch (Throwable $e) { $findErr = $e->getMessage(); } }
    $fileInfo = null; $warn = ($fp || $noa || $ob || $oar || $sr) ? [] : send_warnings($ev, $m, true, $fileInfo);   // pre-send check (2026-09-29, XYZ002, XYZ026)
    layout_top(!$fp && !$noa && !$oar && !$sr && !$abn && is_reminder_event($ev) ? 'Send reminder' : 'Send to client', $flash);
    if ($early) echo '<div class="flash">' . h($early) . '</div>';
    if ($oar) echo '<p class="muted">Papers: ' . oa_report_state_html($ev, url('send', ['id' => $ev['id'], 'report' => 'oa', 'back' => $_GET['back'] ?? ''])) . '. The saved PDF' . (!is_oa_report((string)$ev['type']) || (string)$ev['doc_code'] === 'NOA' ? '' : ' and the References Cited (PTO-892)') . ' from the ' . h($m['ref']) . ' folder are ticked below when they are there.</p>';
    if (is_filing_report((string)($ev['type'] ?? ''))) echo ($m['kind'] === 'patent' ? '<div class="box warnbox"><b>Before sending:</b> open the Filing Report (.docx) in the ' . h($m['ref']) . ' folder, review it, and save it as PDF in the same folder; it then appears below, already ticked with the receipts.</div>' : '') . '<p class="muted">' . h((string)$ev['note']) . '</p>' . (pt_card_applies($m, $ev) ? '' : '<div class="muted" style="margin:6px 0 2px">This summary opens the email:</div>' . filing_card_html($m, $ev));   // a patent gets the patent card below (2026-10-06)
    if (($ev['type'] ?? '') === ISSUANCE_REPORT_EVENT) echo '<div class="box warnbox"><b>Before sending:</b> review the Patent Issuance Letter (Open DOCX below). <div class="muted">' . h((string)$ev['note']) . '</div></div>';
    // 2026-10-05: no "The USPTO ... email for ... is found in Gmail ... Sending marks this to-do done" line (nor its variants)
    if (($nk = notice_report_kind((string)$ev['type'])) && !str_starts_with($nk, 'ASSIGNMENT')) echo ''
        . (in_array($nk, ['FILING RECEIPT', 'MAINT FEE PAID'], true) ? '<p class="muted">' . h((string)$ev['note']) . '. Check that the client should hear about it (a filing the client made without us, or one already reported, may not need this email); otherwise Cancel the to-do on the matter page.</p>' : '');
    if ($warn) echo '<div class="box warnbox"><b>This email may not need to be sent:</b><ul>' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $warn)) . '</ul><span class="muted">Check the record before sending. If the deadline is really handled, Confirm done (or Cancel) on the matter page instead.</span> <a class="btn small ghost" href="' . url('matter', ['id' => $m['id']]) . '">Open ' . h($m['ref']) . '</a></div>';
    elseif ($fileInfo) echo '<p class="muted">✓ ' . h($fileInfo) . '</p>';
    echo '<h1>' . ($fp ? ($m['kind'] === 'trademark' ? 'Renewal filed' : 'Maintenance fee paid') . ': report to client' : (!$noa && !$oar && !$sr && !$abn && is_reminder_event($ev) ? 'Send reminder' : 'Send to client')) . '</h1><div class="box"><p><a href="' . url('matter', ['id' => $m['id']]) . '"><b>' . h($m['ref']) . '</b></a> ' . h($m['title']) . ' · ' . h($ev['type']) . ' due ' . fmt_date($ev['due_date']) . ' ' . badge_days($ev['due_date']) . '</p>';
    if ($prior) { echo '<p class="muted">Already emailed on this matter: '; foreach ($prior as $p2) echo h(et($p2['created_at'], 10)) . ' ' . h($p2['from_user']) . ' → ' . h($p2['to_email']) . ' (' . h($p2['mode']) . '); '; echo '</p>'; }
    echo '<form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="action" value="send_client"><input type="hidden" name="id" value="' . $ev['id'] . '"><input type="hidden" name="matter_id" value="' . $m['id'] . '"><input type="hidden" name="back" value="' . h($back) . '">' . ($rem ? '<input type="hidden" name="rem" value="' . $rem['id'] . '">' : '') . ($ob ? '<input type="hidden" name="ob" value="' . $ob['id'] . '">' : '') . ($fp ? '<input type="hidden" name="feepaid" value="1">' : '') . ($noa ? '<input type="hidden" name="report" value="noa">' : '') . ($oar ? '<input type="hidden" name="report" value="oa">' : '') . ($sr ? '<input type="hidden" name="report" value="' . h($sr) . '">' : '');
    if ($rem) echo '<p class="muted">Scheduled ' . lead_label((int)$rem['lead']) . ', queued ' . fmt_date($rem['scheduled_for']) . '. Nothing has been sent yet.</p>';
    // A held reminder sent by the attorney accepts its template, with his edits (2026-09-29).
    if ($rem && $rem['status'] === 'held' && is_admin() && !$fp && !$noa && !empty($t['event_type']) && q1('SELECT 1 FROM templates WHERE event_type=?', [$t['event_type']]))
        echo '<input type="hidden" name="learn_tpl" value="' . h($t['event_type']) . '"><input type="hidden" name="learn_ev" value="' . (int)$fillEv['id'] . '"><label class="chk"><input type="checkbox" name="learn" value="1" checked> <b>Sending accepts the &ldquo;' . h($t['event_type']) . '&rdquo; template</b>' . (!empty($t['approved']) ? '' : ' (approves it)') . ', with any edits you make below saved into it. <span class="muted">Client-specific details (names, dates, goods) stay placeholders. Untick for a one-off change.</span></label>';
    if ($ob) echo '<p class="muted">Draft held since ' . h(et($ob['created_at'])) . ' by ' . h($ob['from_user']) . '. Nothing has been sent yet.</p>';
    echo '<div class="box" style="background:#faf9f6"><dl><dt>From</dt><dd><b>' . h(auth_email()) . '</b> (' . h(auth_name()) . ')</dd><dt>To</dt><dd><input type="text" name="to" value="' . h($toPre) . '" size="60" placeholder="client email; separate several with ;"><input type="hidden" name="to_pre" value="' . h($toPre) . '">' . ($c['email'] === '' ? ' <span class="bad">no address on file for client ' . h($c['code']) . '</span>' : '') . ($toPre !== $toClient && $toClient !== '' ? '<div class="muted">From the email thread. The client record has <a href="#" class="chip" onclick="document.querySelector(\'input[name=to]\').value=' . h(json_encode($toClient)) . ';return false">' . h($toClient) . '</a> (click to use that instead).</div>' : '') . (($apShow = trim((string)($m['ap_email'] ?? '')) ?: trim((string)($c['email_ap'] ?? ''))) !== '' ? '<div class="muted">Accounts payable (' . h($apShow) . ') is added automatically if an invoice is attached.</div>' : '') . (trim((string)($m['instructing'] ?? '')) !== '' ? '<div class="muted">Associate matter: addressed to the instructing attorney only' . (trim((string)($m['their_ref'] ?? '')) !== '' ? ' (their ref. ' . h($m['their_ref']) . ')' : ' <span class="bad">their file number is missing</span>') . '.</div>' : '') . ' <span class="muted">an address you add is kept for this client; one with &ldquo;billing&rdquo; or &ldquo;accounts&rdquo; in it gets the invoices</span> <a class="btn small ghost" href="' . h(url('send', array_filter(['id' => $ev['id'], 'rem' => $_GET['rem'] ?? null, 'report' => $_GET['report'] ?? null, 'feepaid' => $_GET['feepaid'] ?? null, 'back' => $_GET['back'] ?? null, 'find' => 1]))) . '">Find addresses in Gmail</a>' . ($findErr ? '<div class="bad">Gmail search failed: ' . h($findErr) . '</div>' : '') . ($found !== null ? '<div class="found">' . (!$found ? '<span class="muted">No outside addresses found on this client\'s file numbers in the last 6 years.</span>' : '<span class="muted">Click to add (threads, last seen):</span> ' . implode(' ', array_map(fn($e, $f) => '<a href="#" class="chip" data-email="' . h($e) . '" onclick="var t=document.querySelector(\'input[name=to]\');if(t.value.indexOf(this.dataset.email)<0)t.value=(t.value.trim()?t.value.trim().replace(/;?$/,\'; \'):\'\')+this.dataset.email;return false" title="' . h($f['name']) . '">' . h($e) . ' <small>' . $f['n'] . ', ' . h($f['last']) . '</small></a>', array_keys($found), $found))) . '</div>' : '') . '</dd>' . (auth_cc() !== '' ? '<dt>Cc</dt><dd><b>' . h(auth_cc()) . '</b></dd>' : '') . '<dt>Template</dt><dd>' . h($fp ? $fp['type'] : (string)($t['event_type'] ?? $ev['type'])) . ($t['approved'] ?? 0 ? ' <span class="ok">approved</span>' : ' <span class="muted">(not approved for automatic sending; manual sends are fine)</span>') . '</dd></dl></div>';
    if ($thr) {
        $rs = reply_subject($thr['subject']);
        echo '<div class="box" style="background:#faf9f6"><label><input type="checkbox" name="in_thread" value="1"' . ($noa || $oar || (function_exists('is_issue_report') && is_issue_report((string)($t['event_type'] ?? $ev['type']))) || ($ev['type'] ?? '') === REG_REPORT || ($ev['type'] ?? '') === SIGN_EVENT || fresh_subject_default($ev, (string)($_GET['report'] ?? '')) ? '' : ' checked') . ' id="inthr"> <b>Reply in the latest email thread on ' . h($m['ref']) . '</b></label>'; $thrInfo = '<div class="muted">&ldquo;' . h($thr['subject']) . '&rdquo;' . ($thr['date'] ? ' · last message ' . fmt_date($thr['date']) . ' from ' . h($thr['from']) : '') . ($thr['outside'] ? ' · with ' . h(implode(', ', array_slice($thr['outside'], 0, 4))) : '') . ($thr['thread_id'] ? ' · <a href="https://mail.google.com/mail/#all/' . h($thr['thread_id']) . '" target="_blank" rel="noopener">open in Gmail</a>' : '') . '</div><div class="muted">The subject becomes &ldquo;' . h($rs) . '&rdquo; so it groups with that conversation, and that last message (with the history under it) is quoted below your signature. Untick to send a new email instead.</div>';   // 2026-10-06: these two lines sit inside "Show the email thread"
        // 2026-10-05: the thread itself, collapsed; fetched only when opened, in a sandboxed frame (the client's HTML never runs here)
        echo '<details class="thrview"><summary>Show the email thread</summary>' . $thrInfo . '<iframe sandbox title="Email thread" data-src="' . h(url('threadquote', ['msgid' => $thr['message_id']])) . '" style="width:100%;height:420px;border:1px solid #ddd;background:#fff"></iframe></details>'
            . '<script>document.querySelectorAll("details.thrview").forEach(function(d){d.addEventListener("toggle",function(){var f=d.querySelector("iframe");if(d.open&&!f.src)f.src=f.dataset.src;});});</script>';
        echo '<input type="hidden" name="thr_msgid" value="' . h($thr['message_id']) . '"><input type="hidden" name="thr_refs" value="' . h($thr['references']) . '"></div>';
        echo '<script>(function(){var cb=document.getElementById("inthr"),s=null,own=' . json_encode($ob ? $ob['subject'] : $subject) . ',rs=' . json_encode($rs) . ';function f(){s=s||document.querySelector("input[name=subject]");if(cb.checked){if(s.value===own)s.value=rs;}else if(s.value===rs)s.value=own;}cb.addEventListener("change",f);document.addEventListener("DOMContentLoaded",f);})();</script>';
    } elseif ($thrErr) echo '<p class="muted">Could not search Gmail for an earlier thread (' . h($thrErr) . '); this goes as a new email.</p>';
    else echo '<p class="muted">No earlier email thread with the client on ' . h($m['ref']) . ' in Gmail; this goes as a new email.</p>';
    echo '<p><input type="text" name="subject" value="' . h(assoc_subject((string)$subject, $m)) . '" size="100"></p>';   // their file number first on every associate email (2026-10-14)
    if (tm_card_applies($m, $ev)) echo '<div class="cardpreview"><span class="muted">Opens the email (filled in automatically):</span>' . tm_card($m, $ev) . '</div>';
    elseif (pt_card_applies($m, $ev)) echo '<div class="cardpreview"><span class="muted">Opens the email (filled in automatically):</span>' . pt_card($m, $ev, (string)($_GET['report'] ?? '') === 'issue_fee' ? 'Issue fee paid' : '') . '</div>';   // 2026-10-06: the patent card   // 2026-10-04: the timeline card on every trademark email
    // One editor, what you see is what the client gets: bold / italic / underline only; dates are written out and underlined when sent.
     $tplType = $noa ? NOA_TEMPLATE : ($fp ? $fp['type'] : null) ?? (string)($t['event_type'] ?? $ev['type']);   // an OA report's $t is its own template already
    $editTpl = in_array(auth_role(), ['admin', 'staff'], true) ? ' &nbsp;<a class="btn small gold" target="_blank" rel="noopener" href="' . h(url('templates', ['edit' => $tplType])) . '" title="Opens this email\'s template in a new tab; reload this page afterwards to pick up the new wording">✎ Edit template</a>' : '';
    echo '<div class="box mailed"><div class="tb"><button type="button" class="small ghost" data-c="bold" title="Bold (Ctrl+B)"><b>B</b></button> <button type="button" class="small ghost" data-c="italic" title="Italic (Ctrl+I)"><i>I</i></button> <button type="button" class="small ghost" data-c="underline" title="Underline (Ctrl+U)"><u>U</u></button> ' . $editTpl . '</div>'
        . '<div id="mailed" contenteditable="true" style="min-height:260px;outline:none;font-family:Lexend,Arial,sans-serif;font-size:13px;line-height:1.5;padding:8px 0">' . mail_body_html($body) . '</div>' . signature_html(auth_user()) . '</div><textarea name="body" hidden>' . h($body) . '</textarea>';
    // 2026-10-13 (XYZ048): the USPTO notice a notice report forwards below the signature (index.php send_client), shown here as it will go
    if (($nkq = notice_report_kind((string)$ev['type'])) && !str_starts_with($nkq, 'ASSIGNMENT'))
        echo '<iframe sandbox title="Forwarded USPTO notice" src="' . h(url('noticequote', ['id' => $ev['id']])) . '" style="width:100%;height:420px;border:1px solid #e5e1d6;border-radius:6px;background:#fff;margin:-6px 0 12px"></iframe>';
    echo '<script>(function(){var ed=document.getElementById("mailed"),ta=document.querySelector("textarea[name=body]");try{document.execCommand("styleWithCSS",false,false);}catch(e){}'
        . 'document.querySelectorAll(".mailed .tb button").forEach(function(b){b.addEventListener("mousedown",function(e){e.preventDefault();document.execCommand(b.dataset.c);});});'
        . 'ed.addEventListener("keydown",function(e){var k={b:"bold",i:"italic",u:"underline"}[(e.key||"").toLowerCase()];if(k&&(e.ctrlKey||e.metaKey)&&!e.altKey&&!e.shiftKey){e.preventDefault();e.stopPropagation();document.execCommand(k);}});'
        . 'ed.addEventListener("paste",function(e){e.preventDefault();document.execCommand("insertText",false,(e.clipboardData||window.clipboardData).getData("text/plain"));});'
        . 'function ser(n){var o="";n.childNodes.forEach(function(c){if(c.nodeType==3){o+=c.nodeValue.replace(/\\u00a0/g," ");return;}if(c.nodeType!=1)return;var t=c.tagName;if(t=="BR"){o+="\\n";return;}var x=ser(c),s=c.style||{},fw=s.fontWeight,td=(s.textDecoration||"")+(s.textDecorationLine||"");'
        . 'if(t=="TABLE"&&c.getAttribute("data-maint")){if(o&&o.slice(-1)!="\\n")o+="\\n";o+="<maint>"+c.getAttribute("data-maint")+"</maint>\\n";return;}if(t=="IMG"&&c.getAttribute("data-logo")){o+="<logo>"+c.getAttribute("data-logo")+"</logo>";return;}if(c.getAttribute&&c.getAttribute("data-m")){o+="<m>"+c.textContent+"</m>";return;}if(c.getAttribute&&c.getAttribute("data-n")){var nu=c.getAttribute("data-u");o+=(nu?"<n u=\""+nu+"\">":"<n>")+c.textContent+"</n>";return;}if(c.getAttribute&&c.getAttribute("data-a")){o+=c.textContent;return;}if(c.getAttribute&&c.getAttribute("data-s")){o+="<sm>"+x+"</sm>";return;}if(t=="U"||td.indexOf("underline")>=0)x="<u>"+x+"</u>";if(t=="I"||t=="EM"||s.fontStyle=="italic")x="<i>"+x+"</i>";if(t=="B"||t=="STRONG"||fw=="bold"||+fw>=600)x="<b>"+x+"</b>";'
        . 'if(t=="DIV"||t=="P"){if(o&&o.slice(-1)!="\\n")o+="\\n";o+=(x=="\\n"?"":x)+"\\n";}else o+=x;});return o;}'
        . 'ed.closest("form").addEventListener("submit",function(){ta.value=ser(ed).replace(/<(b|i|u)><\\/\\1>/g,"").replace(/\\n+$/,"");});})();</script>';
    $atts = $ob ? outbox_attachments($ob) : [];
    if ($fp) {
        echo '<div class="box" style="background:#faf9f6"><b>Close when sent</b> <span class="muted">(' . h($fp['cur']['label']) . '; untick anything still outstanding)</span>';
        $fpBoxes = 0; foreach (['open', 'due', 'grace'] as $r) if (($x = $fp['cur']['events'][$r] ?? null) && in_array($x['status'], ['open', 'proposed'], true) && ++$fpBoxes) echo '<label class="chk"><input type="checkbox" name="close_ids[]" value="' . $x['id'] . '" checked> ' . h($x['type']) . ' · ' . fmt_date($x['due_date']) . '</label>';
        if (!$fpBoxes) echo ' <span class="muted">Nothing left to close: this cycle\'s events are already done.</span>';
        if (!$fp['next']) echo '<p class="muted">No later fee cycle is docketed on this record' . ($m['kind'] === 'trademark' ? ' (add the next renewal dates, or they will be missing from the email)' : ', so this uses the final-fee wording') . '.</p>';
        echo '</div>';
    }
    $mf = matter_files($m['ref']); $oaTick = $oar ? oa_report_files($ev, $oar['corr'], $mf) : [];
    // 2026-10-13 (XYZ048/XYZ022): a notice report attaches the notice itself once it is in the file ("XYZ022-03a-Notice of Acceptance of 2026.09.18.pdf")
    if (($nkt = notice_report_kind((string)$ev['type'])) && in_array($nkt, ['NOTICE OF ACCEPTANCE', 'NOTICE OF PUBLICATION'], true)) {
        $since = add_days((string)(($ev['mail_date'] ?? '') ?: $ev['due_date']), -3, false);
        foreach ($mf as $f) if (stripos($f['name'], ucwords(strtolower($nkt))) !== false && date('Y-m-d', (int)$f['mtime']) >= $since) { $oaTick[] = $f['name']; break; }
    }
    if ($sr) { if (!sou_receipt_files($mf, $sr)) { sou_folder_refresh((string)$m['ref']); $mf = matter_files($m['ref']); } $oaTick = array_merge($oaTick, sou_receipt_files($mf, $sr)); }   // the filing receipt, ticked (lib/noa.php)
    if (($ev['type'] ?? '') === ISSUANCE_REPORT_EVENT) { $ra = pat_attachables($m); $oaTick = array_merge($oaTick ?? [], array_column($ra, 'name')); $mf = array_merge($ra, array_values(array_filter($mf, fn($f) => !in_array($f['name'], $oaTick, true)))); }   // 2026-10-04: the eGrant + issuance letter however old, first and ticked (lib/patreport.php)
    if (($ev['type'] ?? '') === REG_REPORT) {   // 2026-10-04: the certificate and the issuance letter, however old, first and ticked (lib/regreport.php)
        // 2026-10-04: "instead of writing that, just get the certificate from TSDR and also prepare the issuance letter from the template"
        $got = reg_get_certificate_now($m); $wrote = reg_prepare_letter($m);
        $ra = reg_attachables($m); $oaTick = array_column($ra, 'name'); $mf = array_merge($ra, array_values(array_filter($mf, fn($f) => !in_array($f['name'], $oaTick, true))));
        // 2026-10-05: a mark filed in use (1(a)) goes straight to registration, so the issuance fee has not been billed yet
        // (only when TSDR says it was FILED 1(a) and not 1(b): a mark filed intent-to-use paid issuance with its Statement of Use)
        $mb = q1('SELECT * FROM matters WHERE id=?', [$m['id']]) ?: $m;
        if (trim((string)($mb['basis_filed'] ?? '')) === '' && strlen(preg_replace('/\D/', '', (string)$mb['app_no'])) === 8 && !empty($GLOBALS['cfg']['tsdr_key'])) { try { tsdr_lookup(preg_replace('/\D/', '', (string)$mb['app_no'])); } catch (Throwable $e) {} $mb = q1('SELECT * FROM matters WHERE id=?', [$m['id']]) ?: $mb; }
        $fb = strtolower((string)($mb['basis_filed'] ?? ''));
        if (preg_match('/\b1a\b/', $fb) && !preg_match('/\b1b\b/', $fb)) echo '<div class="box warnbox"><b>Filed in use (1(a)): attach the invoice.</b></div>';
        $rf = reg_files($m); echo '<div class="box' . ($rf['cert'] === '' || $rf['letter'] === '' ? ' warnbox' : '') . '"><b>Registration certificate:</b> ' . ($rf['cert'] !== '' ? mf_view_link((string)$m['ref'], $rf['cert']) . ' (ticked below)' : '<span class="bad">TSDR did not give the certificate' . (reg_files($m)['dir'] ? '' : ' (there is no ' . h($m['ref']) . ' folder in Dropbox)') . '</span>: attach it below')
            . '<br><b>Issuance letter:</b> ' . ($rf['letter'] !== '' ? h($rf['letter']) . ' (ticked below)' : ($rf['letter_doc'] !== '' ? h($rf['letter_doc']) . ($wrote !== '' && !str_contains($wrote, 'could not') ? ' was just written from the firm template' : '') . ': review it (Open DOCX below)' : ($wrote !== '' ? h($wrote) : 'none in the folder; the email can go without it (it then does not mention a letter)'))) . '</div>';
    }
    echo docx_box($m);   // 2026-10-05: open the Word letter, and its PDF (made on the laptop when it is closed) is ticked here
    if ($mf) {
        echo '<div class="box" style="background:#faf9f6"><b>Attach from the file</b> <span class="muted">(PDFs saved in the Dropbox folder in the last 45 days, newest first; they reach Madder within 10 minutes of saving)</span>';
        foreach (array_slice($mf, 0, ($ev['type'] ?? '') === REG_REPORT ? 8 : 6) as $i => $f) echo '<label class="chk"><input type="checkbox" name="file_att[]" value="' . h($f['name']) . '"' . (($fp && $i === 0) || ($noa && stripos($f['name'], 'Notice of Allowance') !== false) || in_array($f['name'], $oaTick, true) || (is_issue_report((string)$ev['type']) && preg_match('/issue\s*notif/i', $f['name'])) || (($ev['type'] ?? '') === FILING_REPORT_EVENTS['patent'] && preg_match('/(Filing Report.*\.pdf|AckReceipt-(filing|fees)\.pdf|Assignment Cover Sheet\.pdf)$/i', $f['name'])) || (($ev['type'] ?? '') === FILING_REPORT_EVENTS['trademark'] && preg_match('/^receipt-\d{8}\.pdf$/i', $f['name'])) || (($ev['type'] ?? '') === PAT_SIGN_EVENT && preg_match('/-(Specification|Figures)-final\.pdf$/i', $f['name'])) || (($ev['type'] ?? '') === 'Report Assignment Filed' && preg_match('/AckReceipt-Recordation\.pdf$/i', $f['name'])) || (($ev['type'] ?? '') === 'Report Assignment Recorded' && preg_match('/Assignment (Recordation|Cover Sheet)\.pdf$/i', $f['name'])) || (($ev['type'] ?? '') === ISSUANCE_REPORT_EVENT && preg_match('/(Patent Issuance Letter.*\.pdf|U\.?\s*S\.?\s*Patent\s+[D\d,]+.*\.pdf|eGrant.*\.pdf)$/i', $f['name'])) ? ' checked' : '') . '> 📎 </label>' . mf_view_link($m['ref'], $f['name']) . ' <small class="muted">' . date('Y-m-d H:i', $f['mtime']) . ', ' . number_format($f['size'] / 1024) . ' KB</small><br>';
        echo '</div>';
    }
    if ($sr && !sou_receipt_files($mf, $sr)) echo '<p id="souwait" class="muted">Waiting for the receipt in the file&hellip;</p><script>(function(){var u=' . json_encode(url('sourcpt', ['ref' => $m['ref'], 'kind' => $sr])) . ',n=0,el=document.getElementById("souwait");'
        . 'function add(nm){var box=document.getElementById("souatt");if(!box){box=document.createElement("div");box.className="box";box.id="souatt";box.style.background="#faf9f6";box.innerHTML="<b>Attach from the file</b>";el.parentNode.insertBefore(box,el);}'
        . 'if(document.querySelector("input[name=\'file_att[]\'][value=\""+CSS.escape(nm)+"\"]")){document.querySelector("input[name=\'file_att[]\'][value=\""+CSS.escape(nm)+"\"]").checked=true;return;}'
        . 'var l=document.createElement("label");l.className="chk";var i=document.createElement("input");i.type="checkbox";i.name="file_att[]";i.value=nm;i.checked=true;l.appendChild(i);l.appendChild(document.createTextNode(" "+nm));box.appendChild(l);}'
        . 'function chk(){fetch(u,{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){if(j.files&&j.files.length){j.files.forEach(add);el.textContent="Receipt attached: "+j.files.join(", ");return;}if(++n<240)setTimeout(chk,15000);else el.textContent="No receipt in the file yet.";}).catch(function(){if(++n<240)setTimeout(chk,15000);});}setTimeout(chk,10000);})();</script>';
    if ($noa && !array_filter($mf, fn($f) => stripos($f['name'], 'Notice of Allowance') !== false)) echo fetch_now_html(($nc = oa_report_corr($ev)) ? (int)$nc['id'] : null, 'Notice of Allowance', here_uri());   // 2026-10-06
    if ($oar && !$oaTick) echo fetch_now_html(($nc = oa_report_corr($ev)) ? (int)$nc['id'] : null, oa_report_label((string)$ev['doc_code']), here_uri());   // 2026-10-06
    if (!$mf && $fp) echo '<p class="bad">No recent PDF from the ' . h($m['ref']) . ' Dropbox folder has reached Madder yet (files arrive within 10 minutes of saving). Attach the receipt below instead.</p>';
    echo '<div class="box" style="background:#faf9f6"><b>Add an attachment</b> <a class="btn small ghost" href="https://payments.paysimple.com/" target="_blank" rel="noopener">Open invoice system</a>' . (($gf = govfee_suggest($m, $fillEv, $fp ? $fp['cur'] : null)) ? ' <span class="govfee">' . $gf['html'] . (empty($gf['patent']) ? ' <a class="muted" href="' . GOVFEE_URL . '" target="_blank" rel="noopener" title="USPTO fee schedule">schedule</a>' : '') . govfee_stale_html() . '</span>' : '') . (($t['needs_invoice'] ?? 0) && empty($gf['patent']) ? ' <span class="muted">(this reminder normally goes out with the invoice)</span>' : '') . '<div>' . ($atts ? implode(' ', array_map(fn($a) => '<span class="chip-static">📎 ' . h($a['name']) . ' <small>' . number_format($a['size'] / 1024) . ' KB</small></span>', $atts)) : '') . '</div><input type="file" name="attach[]" accept="application/pdf" multiple style="margin-top:6px"> <span class="muted">or drag PDFs onto this box</span><div id="dropnames" class="muted"></div></div>';
    // Drag-and-drop onto the attachment box: dropped PDFs join whatever was already chosen in the file input.
    echo '<script>(function(){var inp=document.querySelector("input[name=\'attach[]\']"),box=inp.closest(".box"),nm=document.getElementById("dropnames");function show(){nm.textContent=inp.files.length?"Attaching: "+Array.from(inp.files).map(function(f){return f.name;}).join(", "):"";}inp.addEventListener("change",show);'
        . '["dragenter","dragover"].forEach(function(e){box.addEventListener(e,function(v){v.preventDefault();box.style.outline="2px dashed #c9a227";});});["dragleave","drop"].forEach(function(e){box.addEventListener(e,function(v){v.preventDefault();box.style.outline="";});});'
        . 'box.addEventListener("drop",function(v){var dt=new DataTransfer(),bad=[];Array.from(inp.files).forEach(function(f){dt.items.add(f);});Array.from(v.dataTransfer.files).forEach(function(f){if(/\\.pdf$/i.test(f.name)||f.type=="application/pdf")dt.items.add(f);else bad.push(f.name);});inp.files=dt.files;show();if(window.ffAttUps)ffAttUps();if(bad.length)alert("Only PDFs can be attached; skipped "+bad.join(", "));});})();</script>';
    // 2026-10-08: a PaySimple invoice PDF saved with the file number in its name is attached on its own when focus comes back to Madder.
    // The server cannot see the laptop, so the browser reads the downloads folder (File System Access API; folder chosen once, remembered in IndexedDB).
    echo '<div id="invwatch" class="muted" hidden style="margin-top:4px"></div><script>(function(){var REF=' . json_encode(strtoupper(preg_match('/^[A-Za-z]{3}\d{3}/', (string)$m['ref'], $refm) ? $refm[0] : (string)$m['ref'])) . ';' . <<<'JS'
if(!window.showDirectoryPicker||!window.indexedDB)return;var inp=document.querySelector("input[name='attach[]']"),st=document.getElementById("invwatch"),h=null,done={},busy=false,last="";st.hidden=false;
function db(){return new Promise(function(ok,no){var r=indexedDB.open("ffmadder",1);r.onupgradeneeded=function(){r.result.createObjectStore("kv");};r.onsuccess=function(){ok(r.result);};r.onerror=function(){no(r.error);};});}
function kv(k,v){return db().then(function(d){return new Promise(function(ok,no){var t=d.transaction("kv",v===undefined?"readonly":"readwrite"),s=t.objectStore("kv"),r=v===undefined?s.get(k):s.put(v,k);r.onsuccess=function(){ok(r.result);};r.onerror=function(){no(r.error);};});});}
function btn(txt,fn){var b=document.createElement("button");b.type="button";b.className="small ghost";b.textContent=txt;b.addEventListener("click",fn);return b;}
function pick(){window.showDirectoryPicker({id:"ffinvoices",mode:"read",startIn:"downloads"}).then(function(d){h=d;return kv("invdir",d);}).then(scan).catch(function(){});}
function say(t,act){st.textContent=t+" ";if(act)st.appendChild(act);if(h){st.appendChild(document.createTextNode(" "));st.appendChild(btn("Change folder",pick));}}
function scan(){if(!h||busy)return;busy=true;h.queryPermission({mode:"read"}).then(function(p){
 if(p!=="granted"){say("Invoices from "+h.name+": not allowed.",btn("Allow",function(){h.requestPermission({mode:"read"}).then(scan);}));busy=false;return;}
 var best=null,cut=Date.now()-12*3600*1000,ps=[];
 return (async function(){for await(var e of h.values()){if(e.kind!=="file"||!/\.pdf$/i.test(e.name)||!/invoice/i.test(e.name)||e.name.toUpperCase().indexOf(REF)<0)continue;var f=await e.getFile();if(f.lastModified>=cut&&(!best||f.lastModified>best.lastModified))best=f;}})().then(function(){
  if(best&&!done[best.name+"|"+best.lastModified]){done[best.name+"|"+best.lastModified]=1;var dt=new DataTransfer();Array.from(inp.files).forEach(function(f){if(f.name!==best.name&&f.name!==last)dt.items.add(f);});dt.items.add(best);last=best.name;inp.files=dt.files;inp.dispatchEvent(new Event("change"));say("Invoice attached: "+best.name+".");}
  else if(!best)say("Invoices from "+h.name+": none for "+REF+" yet.");busy=false;});
}).catch(function(){busy=false;});}
kv("invdir").then(function(d){h=d||null;if(h)scan();else say("No invoice folder.",btn("Choose folder",pick));}).catch(function(){st.hidden=true;});
window.addEventListener("focus",scan);document.addEventListener("visibilitychange",function(){if(!document.hidden)scan();});
JS . '})();</script>';
    if (trim((string)($_GET['unk'] ?? '')) !== '') echo '<label class="chk warnchk"><input type="checkbox" name="confirm_to" value="1"> These recipients are right: ' . h((string)$_GET['unk']) . ' ' . (str_contains((string)$_GET['unk'], ',') ? 'are' : 'is') . ' not on file for ' . h($m['ref']) . '</label>';   // the guard (2026-10-14)
    if ($warn) echo '<input type="hidden" name="warned" value="1"><label class="chk warnchk"><input type="checkbox" name="send_anyway" value="1" id="sendany" onchange="document.getElementById(\'sendbtn\').disabled=!this.checked"> Send anyway: I checked, and this email is still needed</label>';
    // 2026-10-05 (XYZ019): remember the order attachments were added (pre-ticked first), sent as att_order (lib/send.php attach_order)
    echo '<input type="hidden" name="att_order" id="attorder"><script>(function(){var ord=[],box=document.getElementById("attorder"),inp=document.querySelector("input[name=\'attach[]\']");'
        . 'Array.from(document.querySelectorAll("input[name=\'file_att[]\']")).forEach(function(i){if(i.checked)ord.push("file:"+i.value);});'
        . 'document.addEventListener("change",function(e){var t=e.target;if(t.name!=="file_att[]")return;var k="file:"+t.value,x=ord.indexOf(k);if(t.checked&&x<0)ord.push(k);if(!t.checked&&x>=0)ord.splice(x,1);});'
        . 'function ups(){if(!inp)return;var now=Array.from(inp.files).map(function(f){return "up:"+f.name;});ord=ord.filter(function(k){return k.indexOf("up:")!==0||now.indexOf(k)>=0;});now.forEach(function(k){if(ord.indexOf(k)<0)ord.push(k);});}'
        . 'if(inp)inp.addEventListener("change",ups);window.ffAttUps=ups;window.ffAttTick=function(n){if(ord.indexOf("file:"+n)<0)ord.push("file:"+n);};'
        . 'box.form.addEventListener("submit",function(){ups();box.value=JSON.stringify(ord);});})();</script>';
    echo '<div class="bar"><input type="hidden" name="mode" value="sent"><button id="sendbtn" class="gold"' . ($warn ? ' disabled' : '') . ' onclick="var b=this;setTimeout(function(){b.disabled=true;b.textContent=\'Sending…\';},0)">' . (mail_live() ? 'Send now as ' : 'Record (outbox mode) as ') . h(auth_email()) . '</button>';
    echo ' <a class="btn ghost" href="' . h($back) . '">Cancel</a></div></form></div>'; echo fetch_forms_html();
    layout_bottom(); break; }
case 'queue': {
    layout_top('Confirm queue', $flash); echo dash_cards($EVSQL, 'queue')[0];   // the Dashboard cards stay on top (2026-10-03)
    echo '<h1>Verify completed</h1><p class="muted">Events marked done by staff, or that the USPTO feed says were satisfied. Nothing is undocketed until the attorney or staff confirms.</p>';
    $deadRows = q('SELECT m.*, count(e.id) n, min(e.due_date) first_due FROM matters m JOIN events e ON e.matter_id=m.id AND e.status IN ("open","proposed") AND e.type<>? WHERE m.dead=1 GROUP BY m.id ORDER BY m.ref', [ABANDON_REPORT_EVENT]);
    if ($deadRows) { echo '<h2>Inactive properties <small>(one click closes every remaining event on the matter)</small></h2><table><thead><tr><th class="file">File</th><th>Client</th><th>Title / mark</th><th>Why it is dead</th><th>Events left</th><th></th></tr></thead><tbody>';
        foreach ($deadRows as $d) echo '<tr><td class="file"><a href="' . url('matter', ['id' => $d['id']]) . '"><b>' . h($d['ref']) . '</b></a></td><td>' . h($d['client_code']) . '</td><td class="title">' . h(trunc($d['title'], 40)) . '</td><td class="note">' . h($d['dead_reason'] ?: $d['pto_status']) . ($d['dead_at'] ? ' (' . fmt_date($d['dead_at']) . ')' : '') . '</td><td>' . $d['n'] . ' <span class="muted">from ' . fmt_date($d['first_due']) . '</span></td><td class="acts">' . (is_admin() ? btn('confirm_dead', ['matter_id' => $d['id'], 'back' => here_uri()], 'Done: close all ' . $d['n'], 'small gold') . ' ' . btn('revive', ['matter_id' => $d['id'], 'back' => here_uri()], 'Not dead', 'small ghost') : '<span class="muted">awaiting an admin</span>') . '</td></tr>';
        echo '</tbody></table>'; }
    // 2026-10-01: deadlines a receipt in the file appears to satisfy, first, each with its receipt one click away.
    $rc = q("$EVSQL WHERE e.status='proposed' AND e.proposed_by='receipts' AND m.dead=0 ORDER BY e.due_date");
    if ($rc) {
        // One row per receipt per matter (2026-10-02): a Statement of Use receipt proposes the SOU and every later extension at once.
        $grp = []; foreach ($rc as $e) { $rp = receipt_for_event((int)$e['id']); $grp[$e['matter_id'] . '|' . ($rp['path'] ?? $e['id'])][] = $e + ['_rp' => $rp]; }
        echo '<h2 id="receipts">Receipts in the file <small>(open the receipt, then Confirm done or Not done)</small></h2><table><thead><tr><th class="file">File</th><th>Title / mark</th><th>Receipt</th><th>Closes</th><th></th></tr></thead><tbody>';
        foreach ($grp as $g) { $f = $g[0]; $ids = array_column($g, 'id'); $bk = here_uri();
            echo '<tr><td class="file"><a href="' . url('matter', ['id' => $f['matter_id']]) . '"><b>' . h($f['ref']) . '</b></a></td><td class="title">' . h(trunc((string)$f['title'], 40)) . '</td><td>' . ($f['_rp'] ? '<a href="' . url('receipt', ['id' => $f['id']]) . '" target="_blank" rel="noopener">📎 ' . h($f['_rp']['name']) . '</a><div class="muted">saved ' . h(fmt_date((string)$f['_rp']['saved'])) . '</div>' : '') . '</td><td>'
                . implode('<br>', array_map(fn($e) => h($e['type']) . ' <span class="muted">' . h(fmt_date($e['due_date'])) . '</span>', $g)) . '</td><td class="acts">'
                . (can_confirm() ? btn('confirm_group', ['ids' => $ids, 'matter_id' => $f['matter_id'], 'back' => $bk], count($g) > 1 ? 'Confirm all ' . count($g) . ' done' : 'Confirm done', 'small gold') . ' ' . btn('reject_group', ['ids' => $ids, 'matter_id' => $f['matter_id'], 'back' => $bk], 'Not done', 'small ghost') : '') . '</td></tr>'; }
        echo '</tbody></table>';
    }
    echo '<h2>Individual events</h2>'; events_table(q("$EVSQL WHERE e.status='proposed' AND COALESCE(e.proposed_by,'')<>'receipts' AND m.dead=0 ORDER BY e.due_date")); layout_bottom(); break; }
case 'pc_launch': {   // after "Download with Claude": opens madder-pc://run, which starts the supervised Claude session on a computer set up for it
    layout_top('Download with Claude', $flash); echo dash_cards($EVSQL, 'pc_launch')[0]; pc_schema(); pc_reconcile();
    $sel = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))))); $sel = $sel ? array_column(q('SELECT id FROM pc_requests WHERE status="sent" AND id IN (' . implode(',', $sel) . ') ORDER BY id'), 'id') : [];
    $q = q('SELECT r.status, r.requested_by, r.requested_at, r.note, r.action_base, m.ref, m.id mid, c.doc_code, c.mail_date FROM pc_requests r JOIN correspondence c ON c.id=r.correspondence_id JOIN matters m ON m.id=c.matter_id
        WHERE r.status="sent" OR r.finished_at > datetime("now","-1 day") ORDER BY r.id DESC LIMIT 40');
    echo '<h1>Download from Patent Center with Claude</h1><div class="box"><p>A terminal with a Claude session opens on this computer. Watch it and approve its steps: it downloads each paper in your browser and saves it into your Dropbox matter folder under the right name. If Patent Center asks you to sign in, sign in yourself and tell Claude to continue. Madder marks each document saved once Dropbox syncs it (usually within minutes).</p>';
    if (!$sel) echo '<p class="muted">Nothing selected. Use "Download with Claude" on a record, or the "only in Patent Center" card on the Dashboard.</p>';
    foreach (array_chunk($sel, PC_BATCH) as $i => $chunk) {
        $link = pc_link($chunk, (string)(auth_name() ?: auth_user()));
        echo '<p><a class="btn gold" href="' . h($link) . '">Start Claude on this computer' . (count($sel) > PC_BATCH ? ' (part ' . ($i + 1) . ': ' . count($chunk) . ' documents)' : '') . '</a></p>';
        if ($i === 0) echo '<script>setTimeout(function(){location.href=' . json_encode($link, JSON_UNESCAPED_SLASHES) . '},400)</script>';
    }
    echo '<p class="muted">Nothing happens? This computer is not set up yet: the attorney\'s laptop is; staff\'s Mac and Windows computers use the installer in Dropbox, matters/Docket/Madder Claude setup.</p></div>';
    if ($q) { echo '<h2>Requests</h2><table><thead><tr><th class="file">File</th><th>Document</th><th>Status</th><th>Asked by</th><th>Note</th></tr></thead><tbody>';
        foreach ($q as $r) echo '<tr><td class="file"><a href="' . url('matter', ['id' => $r['mid']]) . '"><b>' . h($r['ref']) . '</b></a></td><td>' . h($r['doc_code'] . ' of ' . fmt_date($r['mail_date'])) . '</td><td>' . h($r['status']) . '</td><td>' . h($r['requested_by'] . ', ' . et($r['requested_at'], 16)) . '</td><td class="note">' . h($r['note']) . '</td></tr>';
        echo '</tbody></table>'; }
    layout_bottom(); break; }
case 'mfview': {   // a file in a matter folder, opened in the browser (2026-10-04: clicking an attachment on the send page opens it)
    $ref = (string)($_GET['ref'] ?? ''); $name = (string)($_GET['name'] ?? ''); $dir = $ref !== '' ? mf_find($ref) : null; $hit = null;
    if ($dir && $name !== '') foreach (mf_list($dir) as $f) if ($f['name'] === $name) { $hit = $f; break; }
    $local = $hit ? mf_local($dir, $hit['rel']) : ''; if ($local === '') { http_response_code(404); exit('That file is no longer in the ' . h($ref) . ' folder.'); }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)); $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'txt' => 'text/plain; charset=utf-8',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc' => 'application/msword'];
    $inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'txt'], true);   // Word files download (browsers cannot show them)
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream')); header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._ (),&-]+/', '_', $name) . '"');
    header('Content-Length: ' . filesize($local)); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store'); readfile($local); exit; }
case 'docxkit': {   // the Open DOCX installer for this computer (built by tools/ffdocx/make_kits.py at deploy)
    $os = ($_GET['os'] ?? '') === 'Mac' ? 'Mac' : 'Windows'; $f = DOCKET_ROOT . "/templates/Madder-Open-DOCX-$os.zip";
    if (!is_file($f)) { http_response_code(404); exit('Installer not found.'); }
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="Madder-Open-DOCX-' . $os . '.zip"'); header('Content-Length: ' . filesize($f)); readfile($f); exit; }
case 'sourcpt': {   // the send page's "Waiting for the USPTO receipt" line checks back (lib/noa.php, 2026-10-12)
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $ref = (string)($_GET['ref'] ?? ''); sou_folder_refresh($ref); $files = sou_receipt_files(matter_files($ref), (string)($_GET['kind'] ?? ''));
    exit(json_encode(['files' => $files])); }
case 'fetchstatus': {   // the fetch line on the send page / registrations panel checks back while a download runs (lib/oareport.php fetch_now_state)
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    [$cls, $html, $poll] = fetch_now_state((int)($_GET['cid'] ?? 0), substr((string)($_GET['what'] ?? 'paper'), 0, 80));
    exit(json_encode(['cls' => $cls, 'html' => $html, 'poll' => $poll])); }
case 'docxpdf': {   // the send page asks on focus: is the PDF of this letter in the folder yet? (lib/docxpdf.php)
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $dir = mf_find((string)($_GET['ref'] ?? '')); $name = (string)($_GET['name'] ?? '');
    if (!$dir || !preg_match('/\.docx?$/i', $name) || str_contains($name, '/')) exit(json_encode(['ready' => false, 'error' => 'no such letter']));
    try { exit(json_encode(docx_pdf_status($dir, $name))); } catch (Throwable $e) { exit(json_encode(['ready' => false, 'error' => $e->getMessage()])); } }
case 'noticequote': {   // the forwarded USPTO notice under a notice report's email, for the send page (same lookup as send_client)
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src https: data: cid:; style-src 'unsafe-inline'; sandbox");
    $qe = q1('SELECT e.*, m.ref FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.id=?', [(int)($_GET['id'] ?? 0)]); $fq = '';
    if ($qe && ($nkq = notice_report_kind((string)$qe['type'])) && !str_starts_with($nkq, 'ASSIGNMENT')) {
        try { $fn = trim((string)($qe['gmail_id'] ?? '')) !== '' ? gmail_notice_by_id((string)$qe['gmail_id']) : gmail_uspto_notice((string)$qe['ref'], $nkq === 'REGISTRATION' ? 'NOTICE OF REGISTRATION' : $nkq); if ($fn) $fq = forward_block($fn); } catch (Throwable $e) { $fq = ''; }
    }
    exit('<!doctype html><meta charset="utf-8"><base target="_blank"><body style="font:14px/1.45 Arial,sans-serif;margin:10px">' . ($fq !== '' ? $fq : '<p style="color:#a33">The USPTO notice was not found in Gmail, so nothing will be forwarded below the email.</p>') . '</body>'); }
case 'threadquote': {   // the latest message of a client thread (with the history under it), for the send page's collapsed preview
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src https: data: cid:; style-src 'unsafe-inline'; sandbox");
    try { $q = gmail_quote_html((string)($_GET['msgid'] ?? '')); } catch (Throwable $e) { $q = ''; }
    exit('<!doctype html><meta charset="utf-8"><base target="_blank"><body style="font:14px/1.45 Arial,sans-serif;margin:10px">' . ($q !== '' ? $q : '<p style="color:#777">The message could not be fetched from Gmail.</p>') . '</body>'); }
case 'receipt': {   // the receipt behind a "Receipts in the file" proposal, shown in the browser
    $rp = receipt_for_event((int)($_GET['id'] ?? 0)); if (!$rp) redirect(url('queue'), 'No receipt recorded for that event.');
    $row = q1('SELECT root, folder, rel FROM dbx_files WHERE lower(path)=lower(?)', [$rp['path']]);
    $local = $row ? mf_local(['mode' => 'dbx', 'root' => $row['root'], 'name' => $row['folder'], 'dir' => $row['root'] . '/' . $row['folder']], $row['rel']) : '';
    if ($local === '') redirect(url('queue'), 'The receipt ' . $rp['name'] . ' is no longer in the folder.');
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._ -]+/', '_', $rp['name']) . '"'); header('X-Content-Type-Options: nosniff');
    readfile($local); exit; }
case 'dropbox': {   // Advanced > Dropbox
    if (!is_admin()) redirect('?', 'Only an admin can manage the Dropbox connection.');
    layout_top('Dropbox', $flash); $c = dbx_conf(); dbx_schema();
    echo '<h1>Dropbox</h1><p class="muted">The server reads the matter folders (' . h(implode(', ', array_map(fn($r) => ltrim($r, '/'), DBX_ROOTS))) . ') and saves Office Actions into them through Dropbox, so nothing depends on the laptop being on. Every hour it indexes what changed, looks for new receipts, saves new Office Actions, reads filing dates, reply periods and PCT dates.</p>';
    if (dbx_ready()) {
        $nf = q1('SELECT count(*) c FROM dbx_files')['c'] ?? 0; $nd = q1('SELECT count(*) c FROM dbx_folders')['c'] ?? 0;
        echo '<div class="box"><p><b>Connected</b> as ' . h(($c['account_name'] ?? '') . ' <' . ($c['account'] ?? '') . '>') . ' since ' . h($c['connected_at'] ?? '') . ($c['root_ns'] ?? '' ? ' (team space)' : '') . '.</p>'
           . '<p>Indexed: ' . number_format((int)$nd) . ' folders, ' . number_format((int)$nf) . ' files. Last index: ' . h(q1('SELECT value FROM settings WHERE key="dbx_sync_last"')['value'] ?? 'not yet') . '. Last hourly run: ' . h(q1('SELECT value FROM settings WHERE key="hourly_last"')['value'] ?? 'not yet') . '.</p>'
           . '<div class="bar">' . btn('dbx_sync_now', [], 'Index now', 'gold') . ' ' . btn('dbx_disconnect', [], 'Disconnect', 'ghost', 'Disconnect Dropbox? The folder jobs stop until it is connected again.') . '</div></div>';
    } else {
        echo '<div class="box"><h2 style="margin-top:0">Connect</h2><ol>'
           . '<li>At <a href="https://www.dropbox.com/developers/apps" target="_blank" rel="noopener">dropbox.com/developers/apps</a>: <b>Create app</b>, <b>Scoped access</b>, <b>Full Dropbox</b>, name it (e.g. "FF Madder").</li>'
           . '<li>On its <b>Permissions</b> tab tick <b>files.metadata.read</b>, <b>files.content.read</b> and <b>files.content.write</b>, then <b>Submit</b>.</li>'
           . '<li>On its <b>Settings</b> tab copy the <b>App key</b> and paste it here:</li></ol>'
           . '<form method="post" class="bar">' . csrf_field() . '<input type="hidden" name="action" value="dbx_start"><input type="text" name="app_key" size="24" placeholder="App key" value="' . h($c['app_key'] ?? '') . '"> <button class="gold">Get the Dropbox link</button></form>';
        if (!empty($_SESSION['dbx_url'])) echo '<p style="margin-top:14px">4. <a class="btn gold" href="' . h($_SESSION['dbx_url']) . '" target="_blank" rel="noopener">Open Dropbox and click Allow</a></p><p>5. Paste the code Dropbox shows:</p><form method="post" class="bar">' . csrf_field() . '<input type="hidden" name="action" value="dbx_finish"><input type="text" name="code" size="50" autocomplete="off" placeholder="code from Dropbox"> <button class="gold">Connect</button></form>';
        echo '</div>';
    }
    layout_bottom(); break; }
case 'reminders': {
    // 2026-09-28: one list per tab, To do (oldest first) / Done (newest first); automatic reminders and hand-sent e-mails together.
    layout_top('Emails to send & USPTO mail', $flash); echo dash_cards($EVSQL, 'reminders')[0]; $tab = ($_GET['st'] ?? '') === 'done' ? 'done' : 'todo';
    $rows = q('SELECT r.*, e.type, e.due_date, e.status es, m.ref, m.id mid, m.client_code FROM reminders r JOIN events e ON e.id=r.event_id JOIN matters m ON m.id=e.matter_id WHERE r.status ' . ($tab === 'done' ? 'IN ("sent","skipped")' : 'NOT IN ("sent","skipped")') . ' ORDER BY r.id DESC LIMIT 400');
    $ob = q('SELECT o.*, m.ref FROM outbox o JOIN matters m ON m.id=o.matter_id WHERE ' . ($tab === 'done' ? '(o.transmitted_at IS NOT NULL OR o.mode="discarded")' : '(o.transmitted_at IS NULL AND o.mode<>"discarded")') . ' ORDER BY o.id DESC LIMIT 200');
    $nTodo = q1('SELECT (SELECT count(*) FROM reminders WHERE status NOT IN ("sent","skipped")) + (SELECT count(*) FROM outbox WHERE transmitted_at IS NULL AND mode<>"discarded") c')['c'] + count(held_open())
        + q1("SELECT count(*) c FROM events e LEFT JOIN reminders r ON r.event_id=e.id WHERE e.status='open' AND e.type LIKE 'Report %' AND r.id IS NULL")['c'];
    $nDone = q1('SELECT (SELECT count(*) FROM reminders WHERE status IN ("sent","skipped")) + (SELECT count(*) FROM outbox WHERE transmitted_at IS NOT NULL OR mode="discarded") c')['c'];
    echo '<h1>Emails to send &amp; USPTO mail</h1><div class="bar"><div class="tabs"><a href="' . url('reminders') . '"' . ($tab === 'todo' ? ' class="on"' : '') . ">To do ($nTodo)</a> <a href=\"" . url('reminders', ['st' => 'done']) . '"' . ($tab === 'done' ? ' class="on"' : '') . ">Done ($nDone)</a></div>";
    if (is_admin()) echo btn('run_nightly', [], 'Run nightly job now', 'gold'); echo '</div>';
    $items = [];   // [date, html]
    foreach ($ob as $o) $items[] = [(string)($o['transmitted_at'] ?: $o['created_at']), '<details class="box"><summary>' . ($o['transmitted_at'] ? '<span class="pill sent">transmitted ' . h(et($o['transmitted_at'])) . '</span>' : ($o['mode'] === 'discarded' ? status_pill('discarded') : ($o['mode'] === 'draft' ? '<span class="pill draft">draft</span> ' . ($o['event_id'] ? '<a class="btn small gold" href="' . url('send', ['id' => $o['event_id'], 'ob' => $o['id'], 'back' => here_uri()]) . '">Open, add invoice, send</a>' : '') . (is_admin() ? ' ' . btn('discard_outbox', ['id' => $o['id'], 'back' => here_uri()], 'Discard', 'small ghost', 'Discard this draft?') : '') : '<span class="pill held">NOT transmitted</span>' . ($o['transmit_error'] ? ' <span class="bad">' . h($o['transmit_error']) . '</span>' : '') . ($o['event_id'] ? ' <a class="btn small ghost" href="' . url('send', ['id' => $o['event_id'], 'ob' => $o['id'], 'back' => here_uri()]) . '">Open, attach, resend</a>' : '') . ' ' . btn('transmit', ['id' => $o['id'], 'back' => here_uri()], 'Transmit now', 'small gold', 'Send this email to ' . $o['to_email'] . ' now?') . (is_admin() ? ' ' . btn('discard_outbox', ['id' => $o['id'], 'back' => here_uri()], 'Discard', 'small ghost', 'Discard this email so it is never sent?') : '')))) . ' <b>' . h(et($o['created_at'])) . '</b> · ' . h($o['ref']) . ' · from ' . h($o['from_email']) . ' · to ' . h($o['to_email']) . ($o['cc_email'] ? ' · cc ' . h($o['cc_email']) : '') . '</summary><p><b>Subject:</b> ' . h($o['subject']) . (outbox_attachments($o) ? ' · 📎 ' . h(implode(', ', array_column(outbox_attachments($o), 'name'))) : '') . '</p>' . mail_html(strip_plain_signature((string)$o['body']), signature_html((string)$o['from_user'])) . '</details>'];
    foreach ($rows as $r) {
        $x = '<details class="box"><summary>' . reminder_pill($r) . ' <b>' . fmt_date($tab === 'done' && $r['sent_at'] ? et($r['sent_at'], 10) : $r['scheduled_for']) . '</b> · ' . lead_label((int)$r['lead']) . ' · <a href="' . url('matter', ['id' => $r['mid']]) . '">' . h($r['ref']) . '</a> · ' . h($r['type']) . ' due ' . fmt_date($r['due_date']) . ' · to ' . h($r['to_email'] ?: '(no address)') . '</summary>';
        $x .= '<p class="muted">' . h($r['reason']) . ($r['sent_at'] ? ' · ' . h(et($r['sent_at'])) : '') . '</p><p><b>Subject:</b> ' . h($r['subject']) . '</p>' . mail_html(strip_plain_signature((string)$r['body']));
        if (in_array($r['status'], ['held', 'pending'], true)) $x .= '<div class="bar">' . (auth_email() !== '' && $r['es'] === 'open' ? '<a class="btn small" href="' . url('send', ['id' => $r['event_id'], 'rem' => $r['id'], 'back' => here_uri()]) . '">Review &amp; send</a>' : '') . ($r['es'] === 'open' ? ' ' . btn('report_cancel', ['id' => $r['event_id'], 'rem' => $r['id'], 'back' => here_uri()], 'Cancel', 'small ghost', 'Cancel this email? Madder will not send it.') : '') . (is_admin() ? btn('rem_skip', ['id' => $r['id'], 'back' => here_uri()], 'Skip', 'small ghost') : '') . '</div>';
        $items[] = [(string)(($tab === 'done' && $r['sent_at']) ? $r['sent_at'] : $r['scheduled_for']), $x . '</details>'];
    }
    // Report to-dos (receipts, notices, abandonment) that have no reminder row yet: the client email waits for Send all the same (2026-10-03).
    if ($tab === 'todo') foreach (q("SELECT e.*, m.ref, m.id mid FROM events e JOIN matters m ON m.id=e.matter_id LEFT JOIN reminders r ON r.event_id=e.id WHERE e.status='open' AND e.type LIKE 'Report %' AND r.id IS NULL ORDER BY e.due_date") as $e)
        $items[] = [(string)$e['due_date'], '<div class="box"><span class="pill pending">ready</span> <b>' . fmt_date($e['due_date']) . '</b> · <a href="' . url('matter', ['id' => $e['mid']]) . '">' . h($e['ref']) . '</a> · ' . h($e['type']) . ' <span class="muted">' . h((string)$e['note']) . '</span> ' . (auth_email() !== '' ? '<a class="btn small" href="' . url('send', ['id' => $e['id'], 'back' => here_uri()]) . '">Review &amp; send</a>' : '') . ' ' . btn('report_cancel', ['id' => $e['id'], 'back' => here_uri()], 'Cancel', 'small ghost', 'Cancel this email? Madder will not send it.') . '</div>'];
    usort($items, fn($a, $b) => $tab === 'done' ? strcmp($b[0], $a[0]) : strcmp($a[0], $b[0]));   // Done: newest first; To do: soonest first (2026-09-28)
    if ($tab === 'todo') { echo held_section_html(); echo '<h2>Client emails to review &amp; send <small>(ready = click Review &amp; send; blocked = waiting for a template approval or a client email address)</small></h2>'; }
    if (!$items) echo '<p class="muted">None.</p>';
    foreach ($items as [, $x]) echo $x;
    layout_bottom(); break; }
case 'templates': {
    layout_top('Templates', $flash); $edit = $_GET['edit'] ?? null;
    echo '<h1>Reminder templates</h1>';
    if ($edit !== null) {
        $t = q1('SELECT * FROM templates WHERE event_type=?', [$edit]) ?: ['event_type' => $edit, 'subject' => '', 'body' => '', 'needs_invoice' => 0, 'lead_days' => '30,7', 'internal_only' => 0, 'gov_fee' => '', 'our_fee' => '', 'fee_note' => ''];
        echo '<div class="box"><h2 style="margin-top:0">' . h($t['event_type']) . '</h2><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="save_tpl"><input type="hidden" name="type" value="' . h($t['event_type']) . '"><p><input type="text" name="subject" value="' . h($t['subject']) . '" size="90" placeholder="subject"></p><textarea name="body" style="min-height:210px">' . h($t['body']) . '</textarea>';
        echo '<div class="bar"><label>Government fee $<input type="text" name="gov_fee" value="' . h($t['gov_fee'] ?? '') . '" size="7"></label> <label>Our fee $<input type="text" name="our_fee" value="' . h($t['our_fee'] ?? '') . '" size="7"></label> <label>Fee note <input type="text" name="fee_note" value="' . h($t['fee_note'] ?? '') . '" size="34" placeholder="e.g. small entity, per class"></label> <span class="muted">internal reference only; never included in the email</span></div>';
        echo '<div class="bar"><label><input type="checkbox" name="needs_invoice"' . ($t['needs_invoice'] ? ' checked' : '') . '> needs an invoice (Gmail draft, never auto-sent)</label><label><input type="checkbox" name="internal_only"' . ($t['internal_only'] ? ' checked' : '') . '> internal only (to the attorney, not the client)</label><label>Lead days <input type="text" name="lead_days" value="' . h(lead_list($t['lead_days'])) . '" size="8"></label>' . (in_array(auth_role(), ['admin', 'staff'], true) ? '<button>Save</button>' : '') . ' <a class="btn ghost" href="' . url('templates') . '" style="background:#fff;color:var(--navy)">Close</a></div></form></div>';
    }
    $tpls = q('SELECT t.*, (SELECT count(*) FROM events e WHERE e.type=t.event_type AND e.status="open") open_n FROM templates t ORDER BY approved, event_type');
    echo '<table><thead><tr><th>Event type</th><th>Open events</th><th>Lead days</th><th>Invoice</th><th>Approved</th><th></th></tr></thead><tbody>';
    foreach ($tpls as $t) echo '<tr><td><a href="' . url('templates', ['edit' => $t['event_type']]) . '">' . h($t['event_type']) . '</a>' . ($t['internal_only'] ? ' <span class="src">internal</span>' : '') . '</td><td>' . (in_array($t['event_type'], array_merge(FEE_PAID_TEMPLATES, [NOA_TEMPLATE]), true) ? '—' : $t['open_n']) . '</td><td>' . (in_array($t['event_type'], FEE_PAID_TEMPLATES, true) ? 'report button' : h(lead_list($t['lead_days']))) . '</td><td>' . ($t['needs_invoice'] ? 'Gmail draft + invoice' : 'send on click') . '</td><td>' . ($t['approved'] ? '<span class="ok">✓ ' . h($t['approved_by']) . ' ' . h(et((string)$t['approved_at'], 10)) . '</span>' : '<span class="bad">not yet</span>') . '</td><td class="acts">' . (can_approve_tpl() ? ($t['approved'] ? btn('unapprove_tpl', ['type' => $t['event_type'], 'back' => here_uri()], 'Withdraw', 'small ghost') : btn('approve_tpl', ['type' => $t['event_type'], 'back' => here_uri()], 'Approve', 'small gold')) : '') . '</td></tr>';
    echo '</tbody></table>';
    $untpl = q('SELECT type, count(*) n FROM events WHERE status="open" AND type NOT IN (SELECT event_type FROM templates) GROUP BY type ORDER BY n DESC');
    echo '<h2>Event types without a template <small>(no reminders are sent for these)</small></h2><table><thead><tr><th>Event type</th><th>Open</th><th></th></tr></thead><tbody>'; foreach ($untpl as $u) echo '<tr><td>' . h($u['type']) . '</td><td>' . $u['n'] . '</td><td><a href="' . url('templates', ['edit' => $u['type']]) . '">create template</a></td></tr>'; echo '</tbody></table>';
    layout_bottom(); break; }
case 'inbox': {
    layout_top('USPTO mail', $flash); $ing = $_SESSION['ingest'] ?? null; unset($_SESSION['ingest']);
    echo '<h1>USPTO mail</h1><p class="muted">Every hour Madder reads all email from @uspto.gov in the attorney\'s Gmail with a read-only token: nothing is marked read or moved, and each message is handled once. Notices are docketed; filing and fee receipts are saved into the matter folder, the deadline they satisfy is proposed done, and a client email is queued for checking; workflow mail (e-signature requests, surveys, newsletters) is ignored; anything else is held below. Only email that carries the USPTO\'s own signature is acted on. Below that, paste a notification by hand (or load a sample), preview what it would do, then apply.</p>';
    if (is_admin()) echo '<div class="box"><form method="post" class="bar">' . csrf_field() . '<input type="hidden" name="action" value="gmail_pull"><label>Look back <input type="number" name="days" value="7" min="1" max="60" size="3"> days</label> <button class="gold">Pull from Gmail now</button> <span class="muted">' . (gmail_cfg() ? 'Gmail token found.' : 'Gmail is not configured on this machine.') . '</span></form></div>';
    $nh = count(held_open()); echo '<p class="box">' . ($nh ? '<b>' . $nh . ' USPTO email(s) to read</b> are listed' : 'USPTO email to read is listed') . ' on <a href="' . url('reminders') . '#held">Emails to send &amp; USPTO mail</a> (2026-10-03: one list).</p>';
    $seen = q('SELECT * FROM gmail_seen ORDER BY ts DESC LIMIT 30');
    if ($seen) { echo '<h2>Recent Gmail notices <small>(last 30)</small></h2><table><thead><tr><th>Pulled</th><th>Subject</th><th>Result</th></tr></thead><tbody>'; foreach ($seen as $g) echo '<tr><td class="nowrap">' . h(et($g['ts'])) . '</td><td>' . h(trunc($g['subject'], 70)) . '</td><td class="note">' . h($g['result']) . '</td></tr>'; echo '</tbody></table>'; }
    $samples = ['pc' => ['USPTO: Patent Electronic System - Correspondence Notification for Customer Number 12345', "Dear Patent Center Customer:\n\nThis is a courtesy notification regarding the following USPTO patent application(s) associated with your Customer Number, 12345, that have new outgoing correspondence.\n\n| Application | Document | Mailroom Date | Attorney Docket No. |\n| 17000001 | CTNF | 09/22/2026 | ABC001 |\n| 29000001 | PATENT.GRANT | 09/22/2026 | ABC002 |\n"],
                'tm' => ['Official USPTO Notification: U.S. Trademark Application SN 90000001 -- Docket/Reference No. ABC003', "UNITED STATES PATENT AND TRADEMARK OFFICE (USPTO)\nCommissioner for Trademarks\n\nOFFICIAL USPTO NOTICE OF PUBLICATION UNDER 12(a)\n\nUS Application Serial No. 90000001\nMark: SAMPLE MARK\nPublication Date: September 23, 2026\n"],
                'noa' => ['Official USPTO Notification: U.S. Trademark Application SN 90000002 -- Docket/Reference No. ABC004', "OFFICIAL USPTO NOTICE OF ALLOWANCE\n\nU.S. Serial Number: 90000002\nMark: SECOND SAMPLE\nIssue Date: September 23, 2026\n"]];
    $s = $samples[$_GET['sample'] ?? ''] ?? ['', '']; if ($ing) $s = [$ing['subject'], $ing['body']];
    echo '<div class="bar">Load a sample: <a href="' . url('inbox', ['sample' => 'pc']) . '">yesterday\'s Patent Center notification</a> · <a href="' . url('inbox', ['sample' => 'tm']) . '">trademark notice of publication</a> · <a href="' . url('inbox', ['sample' => 'noa']) . '">trademark notice of allowance</a></div>';
    echo '<form method="post">' . csrf_field() . '<p><input type="text" name="subject" value="' . h($s[0]) . '" size="110" placeholder="email subject"></p><textarea name="body" placeholder="email body">' . h($s[1]) . '</textarea><div class="bar"><button name="action" value="ingest_preview" class="ghost">Preview</button>' . (is_admin() ? '<button name="action" value="ingest_apply" class="gold">Apply to the docket</button>' : '') . '</div></form>';
    if ($ing) {
        echo '<h2>' . ($ing['apply'] ? 'Applied' : 'Preview') . ' <small>' . count($ing['results']) . ' item(s) found</small></h2>';
        if (!$ing['results']) echo '<p class="bad">Nothing recognised in that email.</p>';
        foreach ($ing['results'] as $r) {
            $it = $r['item']; $m = $r['matter'];
            echo '<div class="box"><b>' . h($it['kind']) . ' ' . h($it['app_no']) . '</b> · ' . h($it['doc_code']) . ' (' . h($it['summary']) . ') · ' . fmt_date($it['date']) . ' · docket ' . h($it['docket']) . '<br>';
            echo $m ? 'Matter: <a href="' . url('matter', ['id' => $m['id']]) . '">' . h($m['ref']) . '</a> ' . h($m['title']) . ' (' . h($m['client_code']) . ')' : '<span class="bad">No matching matter</span>';
            if ($r['creates']) { echo '<ul>'; foreach ($r['creates'] as $e) echo '<li class="ok">+ ' . h($e['type']) . ' due <b>' . fmt_date($e['due']) . '</b> <span class="muted">' . h($e['note']) . '</span></li>'; echo '</ul>'; }
            if ($r['closes']) { echo '<ul>'; foreach ($r['closes'] as $e) echo '<li>→ proposes closing <b>' . h($e['type']) . '</b> (' . fmt_date($e['due_date']) . ') for the attorney to confirm</li>'; echo '</ul>'; }
            foreach ($r['notes'] as $n) echo '<p class="muted">' . h($n) . '</p>';
            echo '</div>';
        }
    }
    layout_bottom(); break; }
case 'delete': {   // Advanced > Delete record
    layout_top('Delete record', $flash); echo '<h1>Delete record</h1>';
    if (!is_admin()) { echo '<p class="muted">Only an admin can delete a record.</p>'; layout_bottom(); break; }
    $ref = strtoupper(trim((string)($_GET['ref'] ?? '')));
    echo '<form method="get" class="bar"><input type="hidden" name="p" value="delete"><input type="text" name="ref" value="' . h($ref) . '" placeholder="file number, e.g. ABC001" size="16" style="text-transform:uppercase"><button class="small ghost">Find</button></form>';
    if ($ref !== '') {
        $dm = q('SELECT * FROM matters WHERE upper(ref)=?', [$ref]);
        if (!$dm) echo '<p class="bad">No record with file number ' . h($ref) . '.</p>';
        foreach ($dm as $d) {
            $ne = q1('SELECT count(*) c FROM events WHERE matter_id=?', [$d['id']])['c']; $no = q1('SELECT count(*) c FROM events WHERE matter_id=? AND status IN ("open","proposed")', [$d['id']])['c']; $nm = q1('SELECT count(*) c FROM outbox WHERE matter_id=?', [$d['id']])['c'];
            echo '<div class="box"><p><a href="' . url('matter', ['id' => $d['id']]) . '"><b>' . h($d['ref']) . '</b></a> · ' . h(ucfirst((string)$d['kind'])) . ' · ' . h($d['country']) . ' · ' . h($d['title'] ?: '(no title)') . ' · client ' . h($d['client_code']) . '</p>'
               . '<p>' . $ne . ' event(s), ' . $no . ' still open; ' . $nm . ' email(s) recorded. Deleting removes all of them from Madder. A full copy is kept so it can be restored.</p>'
               . '<form method="post" class="bar" onsubmit="return confirm(\'Delete ' . h($d['ref']) . ' permanently from Madder?\')">' . csrf_field() . '<input type="hidden" name="action" value="delete_matter"><input type="hidden" name="matter_id" value="' . $d['id'] . '"><input type="hidden" name="back" value="' . h(url('delete', ['ref' => $ref])) . '">'
               . '<label>Type the file number to confirm <input type="text" name="confirm_ref" size="12" autocomplete="off"></label><button class="small" style="background:var(--red);border-color:var(--red)">Delete record</button></form></div>';
        }
    }
    $recent = q('SELECT ts, by_user, ref FROM deleted_records ORDER BY id DESC LIMIT 10');
    if ($recent) { echo '<h2>Recently deleted</h2><table><thead><tr><th>When</th><th>By</th><th class="file">File</th></tr></thead><tbody>'; foreach ($recent as $r) echo '<tr><td>' . h(et($r['ts'])) . '</td><td>' . h($r['by_user']) . '</td><td class="file">' . h($r['ref']) . '</td></tr>'; echo '</tbody></table>'; }
    layout_bottom(); break; }
case 'newfiling': {   // Create/Delete > New filing (2026-10-02)
    layout_top('New filing', $flash); $ref = strtoupper(trim((string)($_GET['ref'] ?? '')));
    echo '<h1>New filing</h1>';
    $pf = $_SESSION['prep_form'] ?? []; $pv = fn(string $k) => h((string)($pf[$k] ?? '')); $pr = isset($_GET['prepdone']) ? ($_SESSION['prep_result'] ?? null) : null;
    $pref = strtoupper(trim((string)($_GET['pref'] ?? ''))); if ($pref === '' || ($pf['ref'] ?? '') === '' || strcasecmp($pref, (string)$pf['ref']) !== 0) $pf = [];   // a failed attempt's values come back only for the same file
    $pk = $pref !== '' ? prep_kind($pref) : '';
    if ($pr) echo '<div class="box"><h3>' . h($pr['ref']) . ($pr['title'] !== '' ? ' · ' . h($pr['title']) : '') . '</h3><p>Saved in ' . h($pr['folder']) . ':<br>' . implode('<br>', array_map('h', $pr['saved'])) . '</p>'
        . ($pr['links'] ? '<p>Signing links:<br>' . implode('<br>', array_map(fn($l) => h($l[0]) . ': <a href="' . h($l[1]) . '" target="_blank">' . h($l[1]) . '</a>', $pr['links'])) . '</p>' : '')
        . ($pr['notes'] ? '<p class="muted">' . implode('<br>', array_map('h', $pr['notes'])) . '</p>' : '') . '</div>';
    echo '<div class="box"><form method="get" class="bar"><input type="hidden" name="p" value="newfiling"><label>Prepare filing: <input type="text" name="pref" value="' . h($pref) . '" size="12" placeholder="SIA002" required style="text-transform:uppercase"></label><button class="small">Continue</button></form>';
    if ($pref !== '' && $pk !== 'patent') echo '<p class="muted">' . h($pref) . ': ' . ($pk === 'trademark' ? 'trademark' : ($pk === 'nofolder' ? 'no folder in Dropbox' : 'not recognized as a patent file')) . '</p></div>';
    elseif ($pref !== '') echo '<form method="post" id="pf">' . csrf_field() . '<input type="hidden" name="action" value="prepare_filing"><input type="hidden" name="ref" value="' . h($pref) . '">'
       . '<div class="bar"><label>Inventors<br><textarea name="inventors" rows="3" cols="40" placeholder="one per line" required>' . $pv('inventors') . '</textarea></label></div>'
       . '<div class="bar">' . implode(' ', array_map(fn($e) => '<label><input type="radio" name="entity" value="' . $e[0] . '"' . (($pf['entity'] ?? 'micro') === $e[0] ? ' checked' : '') . '> ' . $e[1] . '</label>', [['micro', 'Micro entity'], ['small', 'Small entity'], ['undiscounted', 'Undiscounted']])) . '</div>'
       . '<div class="bar"><label><input type="checkbox" name="assignment" value="1" id="pfa"' . (!empty($pf['assignment']) ? ' checked' : '') . '> Assignment</label></div>'
       . '<div id="pfas" style="margin-left:24px' . (!empty($pf['assignment']) ? '' : ';display:none') . '"><div class="bar"><label>Assignee <input type="text" name="assignee" value="' . $pv('assignee') . '" size="34"></label> <label>Type <input type="text" name="assignee_type" value="' . $pv('assignee_type') . '" size="28" placeholder="New Jersey limited liability company"></label></div>'
       . '<div class="bar"><label>Address<br><textarea name="assignee_address" rows="2" cols="40">' . $pv('assignee_address') . '</textarea></label></div>'
       . '<div class="bar"><label>Signs for assignee <input type="text" name="signer" value="' . $pv('signer') . '" size="24"></label> <label>Title <input type="text" name="signer_title" value="' . $pv('signer_title') . '" size="14" placeholder="CEO"></label></div></div>'
       . '<div class="bar"><label><input type="checkbox" name="ids" value="1" id="pfi"' . (!empty($pf['ids']) ? ' checked' : '') . '> IDS</label></div>'
       . '<div id="pfis" style="margin-left:24px' . (!empty($pf['ids']) ? '' : ';display:none') . '"><div class="bar"><label>References<br><textarea name="ids_refs" rows="4" cols="40" placeholder="blank = the Prior Art folder">' . $pv('ids_refs') . '</textarea></label></div></div>'
       . '<div class="bar"><label><input type="checkbox" name="links" value="1"' . (($pf['links'] ?? '1') ? ' checked' : '') . '> Signing links</label></div>'
       . '<p><button class="gold">Prepare ' . h($pref) . '</button></p></form>'
       . '<script>(function(){[["pfa","pfas"],["pfi","pfis"]].forEach(function(p){var c=document.getElementById(p[0]),d=document.getElementById(p[1]);c.onchange=function(){d.style.display=c.checked?"":"none";};});})();</script></div>';
    else echo '</div>';
    echo ''
       . '<div class="box"><form method="get" class="bar"><input type="hidden" name="p" value="newfiling"><label>Filed property: <input type="text" name="ref" value="' . h($ref) . '" size="12" placeholder="BOO001" required' . (isset($_GET['sign']) ? '' : ' autofocus') . ' style="text-transform:uppercase"></label><button class="small">Continue</button></form></div>'
       . '<div class="box"><form method="post" class="bar">' . csrf_field() . '<input type="hidden" name="action" value="sign_request"><label>Ask client to sign trademark application: <input type="text" name="refs" value="' . h((string)($_GET['sign'] ?? '')) . '" size="34" placeholder="BOO001; BOO002; BOO003" required style="text-transform:uppercase"></label><button class="small">Prepare the email</button></form></div>';   // 2026-10-06
    if ($ref !== '') {
        $ms = q('SELECT * FROM matters WHERE ref=? ORDER BY dead, id DESC', [$ref]);
        echo '<div class="box">' . ($ms ? '<p>' . implode('<br>', array_map(fn($x) => '<a href="' . url('matter', ['id' => $x['id']]) . '"><b>' . h($x['ref']) . '</b></a> · ' . h(ucfirst((string)$x['kind'])) . ' · ' . h(trunc((string)$x['title'], 60)) . ($x['app_no'] ? ' · ' . h(fmt_app((string)$x['app_no'], (string)$x['country'])) : '') . ($x['dead'] ? ' <span class="muted">(inactive)</span>' : ''), $ms)) . '</p>' : '<p class="muted">No record yet for ' . h($ref) . '; the receipts will create it.</p>')
           . '<form method="post" enctype="multipart/form-data" id="nf">' . csrf_field() . '<input type="hidden" name="action" value="new_filing"><input type="hidden" name="ref" value="' . h($ref) . '">'
           . '<div id="drop" style="border:2px dashed #c9a227;border-radius:8px;padding:28px;text-align:center;background:#faf8f3;cursor:pointer"><b>Drop the receipts here</b> from your Downloads folder<div class="muted" style="margin-top:6px">Patent: the acknowledgement receipt, the fee receipt, and the assignment cover sheet if one was recorded. Trademark: the Trademark Center receipt (receipt-SERIAL.pdf).<br>Madder reads them, so it knows whether this is a patent or a trademark filing.</div><input type="file" name="receipts[]" id="rf" accept="application/pdf" multiple style="display:none"><div id="fl" style="margin-top:10px"></div></div>'
           . '<p><button class="gold" id="go" disabled>Save into ' . h($ref) . ' and prepare the email</button></p></form></div>'
           . '<script>(function(){var d=document.getElementById("drop"),f=document.getElementById("rf"),l=document.getElementById("fl"),g=document.getElementById("go");function show(){l.innerHTML="";for(var i=0;i<f.files.length;i++){var s=document.createElement("div");s.textContent="📎 "+f.files[i].name;l.appendChild(s);}g.disabled=!f.files.length;}d.onclick=function(){f.click();};f.onchange=show;["dragover","dragenter"].forEach(function(e){d.addEventListener(e,function(v){v.preventDefault();d.style.background="#f3ecd8";});});["dragleave","drop"].forEach(function(e){d.addEventListener(e,function(v){v.preventDefault();d.style.background="#faf8f3";});});d.addEventListener("drop",function(v){f.files=v.dataTransfer.files;show();});})();</script>';
    }
    layout_bottom(); break; }
case 'clientreport': {   // Create/Delete > Client report (2026-10-02): type the client code, get the PDF
    $qv = trim((string)($_GET['code'] ?? '')); $found = null;
    if ($qv !== '') {
        $found = client_report_find($qv);
        if (!empty($found['code'])) {
            $r = client_report_pdf($found['code']);
            if (isset($r['path'])) { header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $r['name'] . '"'); header('Content-Length: ' . filesize($r['path'])); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store'); readfile($r['path']); @unlink($r['path']); exit; }
            $flash = $r['error'];
        } elseif (!$found['matches']) $flash = 'No client found for "' . $qv . '". Type the client code (the three letters of the file number, e.g. ABF) or part of the client name.';
    }
    layout_top('Client Portfolio', $flash);
    echo '<h1>Client Portfolio</h1><div class="box"><form method="get" class="bar"><input type="hidden" name="p" value="clientreport"><label>Client <input type="text" name="code" value="' . h($qv) . '" size="14" placeholder="ABF" autofocus required style="text-transform:uppercase"></label><button class="gold">Make the PDF</button></form>'
       . '<p class="muted">One PDF with all of the client\'s properties: the deadlines of the next 12 months, then every patent and trademark with its numbers, dates, status and nearest deadlines. Type the client code (or part of the client name) and press Enter.</p></div>';
    if ($found && !empty($found['matches'])) { echo '<div class="box"><b>Several clients match "' . h($qv) . '":</b><table><tr><th>Code</th><th>Client</th><th>Properties</th></tr>'; foreach ($found['matches'] as $x) echo '<tr><td><a href="' . url('clientreport', ['code' => $x['code']]) . '"><b>' . h($x['code']) . '</b></a></td><td>' . h($x['name']) . '</td><td>' . (int)$x['n'] . '</td></tr>'; echo '</table></div>'; }
    layout_bottom(); break; }
case 'newmatter': {
    layout_top('New matter', $flash); $code = strtoupper(trim((string)($_GET['code'] ?? ''))); $next = '';
    if (preg_match('/^[A-Z]{3}$/', $code)) { $mx = q1('SELECT max(CAST(substr(ref, 4, 3) AS INTEGER)) n FROM matters WHERE ref GLOB ? AND client_code=?', [$code . '[0-9][0-9][0-9]*', $code])['n'] ?? 0; $next = sprintf('%s%03d', $code, (int)$mx + 1); }
    $cl = $code ? q1('SELECT * FROM clients WHERE code=?', [$code]) : null;
    echo '<h1>New matter</h1><div class="box"><form method="get" class="bar"><input type="hidden" name="p" value="newmatter"><label>Client code (3 letters) <input type="text" name="code" value="' . h($code) . '" size="6" maxlength="4" style="text-transform:uppercase"></label><button class="small">Next file number</button>' . ($next ? ' <b>' . h($next) . '</b>' . ($cl ? ' · ' . h($cl['name'] ?: 'client exists, no name yet') : ' · <span class="bad">new client</span>') : '') . '</form>';
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="add_matter"><input type="hidden" name="back" value="' . h(here_uri()) . '"><div class="bar"><label>File number <input type="text" name="ref" value="' . h($next) . '" size="10" required style="text-transform:uppercase"></label><label>Kind <select name="kind"><option value="patent">patent</option><option value="trademark">trademark</option><option value="copyright">copyright</option><option value="general">general</option></select><script>(function(){var k=document.currentScript.previousElementSibling,f=k.form;if(!f)return;function set(){var t=f.querySelector("input[name=title]"),a=f.querySelector("input[name=app_no]");var L={trademark:["mark","serial no."],patent:["title","application no."]}[k.value]||["title","application no."];if(t)t.placeholder=L[0];if(a)a.placeholder=L[1];}k.addEventListener("change",set);set();})();</script></label><label>Patent type <select name="app_type"><option value="utility">utility / design / PCT</option><option value="provisional">provisional</option></select></label></div>';
    echo '<div class="bar"><input type="text" name="title" placeholder="title or mark" size="50"><input type="text" name="country" value="United States" size="16"><input type="text" name="app_no" placeholder="application no." size="18"><label>Filed <input type="date" name="filing_date"></label></div>';
    echo '<div class="bar"><input type="text" name="client_name" value="' . h($cl['name'] ?? '') . '" placeholder="client name (fills the client record)" size="34"><input type="email" name="client_email" value="' . h($cl['email'] ?? '') . '" placeholder="client email" size="30"></div>';
    echo '<p class="muted">A provisional gets its 12-month non-provisional deadline; any other patent filing gets the 12-month foreign-filing reminder.</p><button class="gold">Create matter</button></form></div>';
    layout_bottom(); break; }
case 'check': {
    $m = q1('SELECT * FROM matters WHERE id=?', [(int)$_GET['id']]); if (!$m) redirect('?', 'No such matter.');
    layout_top('Check ' . $m['ref'], $flash);
    echo '<h1>USPTO check: ' . h($m['ref']) . ' <small class="muted">' . h($m['title']) . '</small></h1>';
    $open = q('SELECT * FROM events WHERE matter_id=? AND status="open" ORDER BY due_date', [$m['id']]);
    if ($m['country'] !== 'United States' && $m['country'] !== '') $live = ['ok' => false, 'error' => 'Foreign matter (' . $m['country'] . '): no USPTO record to check. Ask the foreign associate.'];
    elseif ($m['kind'] === 'trademark' && $m['app_no'] !== '') $live = tsdr_lookup($m['app_no']);
    elseif ($m['kind'] === 'patent' && $m['app_no'] !== '') $live = odp_lookup($m['app_no']);
    else $live = ['ok' => false, 'error' => 'No application or serial number on this matter, so there is nothing to look up.'];
    if (!$live['ok']) {
        run('INSERT INTO checks(matter_id,by_user,source,status,summary) VALUES (?,?,?,?,?)', [$m['id'], auth_user(), 'none', 'not checked', $live['error']]);
        echo '<div class="box"><p class="bad">' . h($live['error']) . '</p>' . (!empty($live['link']) ? '<p><a href="' . h($live['link']) . '" target="_blank">Open in Patent Center</a></p>' : '') . '</div>';
        echo '<h2>Open events</h2>'; events_table(array_map(fn($e) => $e + ['ref' => $m['ref'], 'client_code' => $m['client_code'], 'title' => $m['title'], 'app_no' => $m['app_no'], 'reg_no' => $m['reg_no'], 'country' => $m['country'], 'kind' => $m['kind']], $open), false); layout_bottom(); break;
    }
    // refresh the matter from the live record
    run('UPDATE matters SET pto_status=?, pto_status_date=COALESCE(NULLIF(?, ""), pto_status_date), reg_no=CASE WHEN ? <> "" THEN ? ELSE reg_no END, grant_date=COALESCE(NULLIF(?, ""), grant_date), filing_date=COALESCE(filing_date, NULLIF(?, "")), updated_at=datetime("now") WHERE id=?',
        [$live['status'], $live['status_date'], $live['reg_no'], $live['reg_no'], $live['reg_date'], $live['filing_date'], $m['id']]);
    if (!empty($live['title']) && ($m['title'] === '' || stripos($m['title'], 'no title') !== false)) run('UPDATE matters SET title=? WHERE id=?', [$live['title'], $m['id']]);
    if ($live['dead']) run('UPDATE matters SET dead=1, dead_reason=?, dead_at=? WHERE id=?', [$live['status'], $live['status_date'] ?: today(), $m['id']]);
    $matches = check_matches($m, $live, $open);
    $summary = $live['status'] . ($live['status_date'] ? ' (' . fmt_date($live['status_date']) . ')' : '') . '; ' . count($open) . ' open, ' . count($matches) . ' look satisfied';
    run('INSERT INTO checks(matter_id,by_user,source,status,summary) VALUES (?,?,?,?,?)', [$m['id'], auth_user(), $live['source'], $live['status'], $summary]); audit('check', $summary, (int)$m['id']);
    echo '<div class="box"><dl><dt>Source</dt><dd>' . h($live['source']) . ' · <a href="' . h($live['link']) . '" target="_blank">open at the USPTO</a></dd><dt>Status</dt><dd><b>' . h($live['status']) . '</b>' . ($live['status_date'] ? ' as of ' . fmt_date($live['status_date']) : '') . ($live['dead'] ? ' <span class="bad">DEAD</span>' : '') . '</dd>';
    if ($live['reg_no']) echo '<dt>' . ($m['kind'] === 'patent' ? 'Patent' : 'Registration') . '</dt><dd>' . h(fmt_reg($live['reg_no'])) . ($live['reg_date'] ? ' (' . fmt_date($live['reg_date']) . ')' : '') . '</dd>';
    if (!empty($live['docket']) && $live['docket'] !== $m['ref']) echo '<dt>Docket at USPTO</dt><dd>' . h($live['docket']) . ' <span class="muted">(here: ' . h($m['ref']) . ')</span></dd>';
    if (!empty($live['entity'])) echo '<dt>Entity</dt><dd>' . h($live['entity']) . '</dd>';
    echo '</dl></div>';
    echo '<h2>Where we are</h2>';
    if ($live['dead']) echo '<p class="bad">The USPTO shows this matter as no longer pending. Every open event below can be closed unless a revival is planned.</p>';
    if ($matches) {
        echo '<div class="box"><p>The USPTO record shows the responsive act for these open events. Tick the ones to mark done:</p><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="check_apply"><input type="hidden" name="matter_id" value="' . $m['id'] . '">';
        foreach ($matches as [$ev, $why]) echo '<label class="chk"><input type="checkbox" name="ids[]" value="' . $ev['id'] . '" checked> <b>' . h($ev['type']) . '</b> due ' . fmt_date($ev['due_date']) . ' <span class="ok">← ' . h($why) . '</span></label>';
        echo '<div class="bar" style="margin-top:10px"><button class="gold">' . (is_admin() ? 'Mark these done' : 'Propose these as done') . '</button></div></form></div>';
    } elseif ($open) echo '<p>No open event appears to have been satisfied yet. The USPTO record and the docket agree.</p>';
    else echo '<p>No open events on this matter.</p>';
    $stillOpen = array_filter($open, fn($e) => !in_array($e['id'], array_map(fn($x) => $x[0]['id'], $matches), true));
    if ($stillOpen) { echo '<h2>Still open</h2>'; events_table(array_map(fn($e) => $e + ['ref' => $m['ref'], 'client_code' => $m['client_code'], 'title' => $m['title'], 'app_no' => $m['app_no'], 'reg_no' => $m['reg_no'], 'country' => $m['country'], 'kind' => $m['kind']], array_values($stillOpen)), false); }
    echo '<h2>USPTO history <small>(latest 15)</small></h2><table><thead><tr><th>Date</th><th>Code</th><th>Entry</th></tr></thead><tbody>';
    foreach (array_slice($live['history'], 0, 15) as $h) echo '<tr><td class="nowrap">' . fmt_date($h['date']) . '</td><td>' . h($h['code']) . '</td><td>' . h($h['desc']) . '</td></tr>';
    echo '</tbody></table><p><a class="btn ghost" href="' . url('matter', ['id' => $m['id']]) . '">Back to the matter</a></p>';
    layout_bottom(); break; }
case 'report': {
    layout_top('Morning report', $flash); $files = glob(reports_dir() . '/*.html'); rsort($files);
    $pick = (string)($_GET['d'] ?? ''); $file = $pick && is_iso_date($pick) ? reports_dir() . "/$pick.html" : ($files[0] ?? null);
    echo '<h1>Morning report</h1><div class="bar">'; foreach (array_slice($files, 0, 14) as $f) { $d = basename($f, '.html'); echo '<a class="btn small ghost" href="' . url('report', ['d' => $d]) . '">' . h($d) . '</a> '; }
    if (is_admin()) echo btn('run_nightly', [], 'Run the nightly job now', 'gold'); echo '</div>';
    echo $file && is_readable($file) ? '<div class="box">' . file_get_contents($file) . '</div>' : '<p class="muted">No report yet. It is produced by the nightly job.</p>';
    layout_bottom(); break; }
case 'audit': { layout_top('Change Log', $flash); echo '<h1>Change Log</h1><table><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Detail</th></tr></thead><tbody>'; foreach (q('SELECT * FROM audit ORDER BY id DESC LIMIT 300') as $a) echo '<tr><td class="nowrap">' . h(et($a['ts'], 19)) . '</td><td>' . h($a['user']) . '</td><td>' . h($a['action']) . '</td><td class="note">' . h($a['detail']) . '</td></tr>'; echo '</tbody></table>'; layout_bottom(); break; }
default: redirect('?');
}
}
