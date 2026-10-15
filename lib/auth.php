<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
function auth_start(): void {
    // Same session as the firm Docket Mail lookup ('DKTMAIL' cookie, path '/') so
    // signing into either app signs into both — see auth_sso().
    session_name('DKTMAIL');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
// If this browser is already signed into Docket Mail lookup (same shared
// session, 'docket_user' set by that app's login), adopt that identity here
// too, so users with a mail-lookup account never see a separate Docket login.
function auth_sso(array $cfg): void {
    // Docket keeps its OWN keys (dk_*): the Mail app owns the plain 'user' key
    // and writes its long random usernames there, which Docket must never read
    // as its own identity. Adopt (or re-adopt) the mapped Docket user whenever
    // the Mail login's 'docket_user' names someone Docket knows.
    if (!empty($_SESSION['dk_no_sso'])) return;   // signed out of Docket on purpose: wait for a real Docket sign-in
    $u = $_SESSION['docket_user'] ?? null;
    // Mail sessions opened before docket_user existed carry only the Mail
    // username: map it here too (mail_users in config) so no re-login is needed.
    if (!$u && !empty($_SESSION['user'])) $u = $cfg['mail_users'][$_SESSION['user']] ?? null;
    // Only when this browser is not signed into Docket yet: a Docket sign-in (or an earlier adoption) is never overridden by the
    // Mail app's identity (2026-09-29: the attorney's browser kept flipping to staff because the shared session named joshua).
    if ($u && isset($cfg['users'][$u]) && empty($_SESSION['dk_user'])) {
        $rec = $cfg['users'][$u];
        $_SESSION['dk_user'] = $u; $_SESSION['dk_role'] = $rec['role']; $_SESSION['dk_name'] = $rec['name'];
    }
    if (!empty($_SESSION['dk_user']) && !isset($cfg['users'][$_SESSION['dk_user']])) auth_logout();   // renamed/removed user
    if (!empty($_SESSION['dk_user'])) mail_signin($cfg, $_SESSION['dk_user']);   // sessions opened before the single sign-in get Mail too
}
// One sign-in for everything (2026-10-01): the Pendant login also signs the attorney and staff into Mail + Locations, which read
// the shared session's 'user' key (their Mail usernames, mail_users in config). the paralegal has no Mail account: any Mail sign-in
// left in this browser is dropped, so she sees Pendant only.
function mail_signin(array $cfg, string $u): void {
    $mu = array_search($u, $cfg['mail_users'] ?? [], true);
    if ($mu !== false) $_SESSION['user'] = (string)$mu; else unset($_SESSION['user']);
}
function has_mail(): bool { return !empty($_SESSION['user']); }
function auth_user(): ?string { return $_SESSION['dk_user'] ?? null; }
function auth_role(): string { return $_SESSION['dk_role'] ?? 'none'; }
function auth_name(): string { return (string)($_SESSION['dk_name'] ?? ''); }
function is_admin(): bool { return auth_role() === 'admin'; }
function can_confirm(): bool { return is_admin() || auth_user() === 'joshua'; }   // 2026-10-01: staff may Confirm done / Not done on proposed events
function can_approve_tpl(): bool { return is_admin() || auth_user() === 'joshua'; }   // 2026-09-28 let staff approve templates; the paralegal (staff, no sending identity) does not
function auth_attempt(array $cfg, string $u, string $p): bool {
    // Username, sign-in address ('login'), email address or name all work (2026-09-29 could not sign in); a pasted password loses stray spaces.
    $u = strtolower(trim($u)); $p = trim($p);
    if (!isset($cfg['users'][$u])) foreach ($cfg['users'] as $k => $rec)
        if ($u !== '' && ($u === strtolower((string)($rec['login'] ?? '')) || $u === strtolower((string)($rec['email'] ?? '')) || $u === strtolower((string)$rec['name']) || $u === strtolower(explode(' ', (string)$rec['name'])[0]))) { $u = $k; break; }
    if (!isset($cfg['users'][$u])) return false;
    $rec = $cfg['users'][$u];
    // 'hash' may be a list: every password the person already uses is accepted (staff, 2026-10-01: his Mail-page and Pendant passwords).
    $ok = false;
    if (isset($rec['hash'])) { foreach ((array)$rec['hash'] as $h) if (password_verify($p, $h)) $ok = true; }
    else $ok = hash_equals((string)$rec['pass'], $p);
    if (!$ok) return false;
    session_regenerate_id(true);
    $_SESSION['dk_user'] = $u; $_SESSION['dk_role'] = $rec['role']; $_SESSION['dk_name'] = $rec['name']; $_SESSION['docket_user'] = $u; unset($_SESSION['dk_no_sso']);
    mail_signin($cfg, $u);
    return true;
}
/**
 * Sign-in throttle (2026-10-01; the site has no IP gate). Five wrong passwords from one address within 15 minutes pause that address
 * for the rest of the 15 minutes; 15 wrong passwords for one username within an hour (from anywhere) pause that username for the
 * rest of the hour. While paused the password is not even checked. A correct sign-in clears the address. Mail-lookup SSO is unaffected.
 */
const LOGIN_MAX_IP = 5; const LOGIN_WINDOW = 900; const LOGIN_MAX_USER = 15; const LOGIN_USER_WINDOW = 3600;
function login_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 64); }
/** Seconds until this address / username may try again; 0 = go ahead. */
function login_blocked(string $user): int {
    $now = time(); $wait = 0;
    $ip = q('SELECT ts FROM login_fail WHERE ip=? AND ts>? ORDER BY ts DESC LIMIT ' . LOGIN_MAX_IP, [login_ip(), $now - LOGIN_WINDOW]);
    if (count($ip) >= LOGIN_MAX_IP) $wait = max($wait, (int)end($ip)['ts'] + LOGIN_WINDOW - $now);
    $u = strtolower(trim($user));
    if ($u !== '') { $us = q('SELECT ts FROM login_fail WHERE user=? AND ts>? ORDER BY ts DESC LIMIT ' . LOGIN_MAX_USER, [$u, $now - LOGIN_USER_WINDOW]); if (count($us) >= LOGIN_MAX_USER) $wait = max($wait, (int)end($us)['ts'] + LOGIN_USER_WINDOW - $now); }
    return max(0, $wait);
}
function login_tries_left(): int { return max(0, LOGIN_MAX_IP - (int)q1('SELECT count(*) c FROM login_fail WHERE ip=? AND ts>?', [login_ip(), time() - LOGIN_WINDOW])['c']); }
function login_fail(string $user): void {
    run('INSERT INTO login_fail(ip,user,ts) VALUES (?,?,?)', [login_ip(), strtolower(trim($user)), time()]);
    run('DELETE FROM login_fail WHERE ts<?', [time() - 86400]);
    if (login_tries_left() === 0) audit('login_locked', 'sign-in paused for ' . login_ip() . ' after ' . LOGIN_MAX_IP . ' wrong passwords (last username tried: ' . $user . ')');
    usleep(600000);   // a wrong password always costs a little time
}
function login_clear(): void { run('DELETE FROM login_fail WHERE ip=?', [login_ip()]); }
function auth_logout(): void { foreach (['dk_user', 'dk_role', 'dk_name', 'docket_user', 'user'] as $k) unset($_SESSION[$k]); $_SESSION['dk_no_sso'] = 1; }   // one sign-out: Mail + Locations too
function csrf(): string { return $_SESSION['csrf']; }
function csrf_ok(?string $t): bool { return is_string($t) && hash_equals($_SESSION['csrf'], $t); }
function auth_email(): string { $u = auth_user(); return $u ? (string)($GLOBALS['cfg']['users'][$u]['email'] ?? '') : ''; }
/** the attorney is copied on every client email sent by anyone else (his rule, 2026-09-24). */
function auth_cc(): string { $cc = (string)($GLOBALS['cfg']['cc_email'] ?? ''); return ($cc !== '' && strcasecmp($cc, auth_email()) !== 0) ? $cc : ''; }
/** The sender's own sign-off, appended to every client email at send time (never stored in templates). */
function auth_signature(): string { $u = auth_user(); return $u ? (string)($GLOBALS['cfg']['signatures'][$u] ?? '') : ''; }
/** Drop a plain-text signature left in older drafts / queued text; the HTML signature block is added at send time. */
function strip_plain_signature(string $body): string {
    $body = rtrim(str_replace("\r\n", "\n", $body));   // browsers submit CRLF; the stored signatures are LF
    foreach ($GLOBALS['cfg']['signatures'] ?? [] as $sig) { $sig = trim(str_replace("\r\n", "\n", (string)$sig)); if ($sig !== '' && str_ends_with($body, $sig)) return rtrim(substr($body, 0, -strlen($sig))); }
    // Any other plain closing block of ours (the firm's closing line ... firm name ...) at the very end.
    return preg_match('/\n\s*' . preg_quote((string)firm('signature_closing'), '/') . '[^\n]*\n(?:[^\n]*\n){0,6}[^\n]*$/', $body, $mm) && str_contains($mm[0], firm('name')) ? rtrim(substr($body, 0, -strlen($mm[0]))) : $body;
}
/** The navy/gold signature block (the attorney's Gmail signature) for a Docket user; greeting + name come from config signatures. */
function signature_html(string $user): string {
    if (($raw = (string)($GLOBALS['cfg']['signature_raw'][$user] ?? '')) !== '') return '<br>' . $raw;   // his Gmail signature exactly as Gmail sends it
    $lines = explode("\n", (string)($GLOBALS['cfg']['signatures'][$user] ?? '')); if (count($lines) < 2) return '';
    $title = trim($lines[2] ?? ''); if (str_starts_with($title, firm('name'))) $title = '';   // optional line under the name (staff: "Patent Agent and Trademark Paralegal*")
    $f = 'font-family:Lexend,&quot;Helvetica Neue&quot;,Arial,sans-serif'; $g = 'color:rgb(85,85,85)'; $a = 'color:rgb(12,32,59);text-decoration:none;font-weight:bold';
    return '<br><table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse"><tbody><tr><td style="border:0;border-left:3px solid rgb(201,162,39);padding:2px 0 2px 14px;' . $f . ';line-height:1.5">'
        . '<div style="font-size:13px;' . $g . '">' . htmlspecialchars(trim($lines[0])) . '</div>'
        . '<div style="font-size:15px;font-weight:bold;color:rgb(12,32,59);padding-top:3px">' . htmlspecialchars(trim($lines[1])) . '</div>'
        . ($title !== '' ? '<div style="font-size:13px;font-weight:bold;color:rgb(12,32,59);padding-top:1px">' . htmlspecialchars($title) . '</div>' : '')
        . '<div style="font-size:12px;' . $g . '">' . htmlspecialchars(firm_line()) . '</div>'
        . (($ph = firm('phones')) ? '<div style="font-size:12px;' . $g . ';padding-top:2px">' . implode(' &nbsp;·&nbsp; ', array_map(fn($k, $n) => (is_string($k) ? htmlspecialchars($k) . ': ' : '') . '<a href="tel:+1' . preg_replace('/\D/', '', $n) . '" style="' . $g . ';text-decoration:none">' . htmlspecialchars($n) . '</a>', array_keys($ph), $ph)) . '</div>' : '')
        . (($st = firm('sites')) ? '<div style="font-size:12px;padding-top:2px">' . implode(' &nbsp;·&nbsp; ', array_map(fn($k, $u) => '<a href="' . htmlspecialchars($u) . '" style="' . $a . '">' . htmlspecialchars($k) . '</a>', array_keys($st), $st)) . '</div>' : '')
        . '</td></tr><tr><td style="border:0;padding:9px 0 0 14px;' . $f . '"><div style="border-top:1px solid rgb(226,226,226);padding-top:7px;max-width:520px;font-size:10px;line-height:1.45;color:rgb(153,153,153)"><strong>Confidentiality:</strong> This e-mail may contain privileged communications. If you received this communication in error, please destroy all copies and contact the undersigned immediately.</div></td></tr></tbody></table>';
}
