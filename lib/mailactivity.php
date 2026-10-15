<?php
declare(strict_types=1);
/**
 * Mail-lookup activity for the morning report (2026-10-01: one set of e-mails, headed "Madder").
 * The Mail page (~/Projects/docket-mail) used to send its own "Docket mail:" e-mails (each sign-in, the first search
 * of the day, a daily digest). It no longer sends anything; its audit log is read here instead.
 * The window runs from the last row reported (setting maillookup_last_id), so a night without a report skips nothing.
 * @return array{html:string, last_id:int}|null  null when the Mail app's config/audit log cannot be found (laptop, tests)
 */
function mail_lookup_section(): ?array {
    global $cfg;
    $mc = $cfg['gmail_config'] ?? '';
    if ($mc === '' || !is_readable($mc)) return null;
    $m = (function (string $f) { return require $f; })($mc);
    if (!is_array($m) || empty($m['audit_db']) || !is_readable($m['audit_db'])) return null;
    $pdo = new PDO('sqlite:' . $m['audit_db']); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $last = (int)setting('maillookup_last_id', '0');
    if ($last === 0) $last = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM events WHERE ts < ' . strtotime('-1 day'))->fetchColumn();
    $geo = in_array('geo', $pdo->query('PRAGMA table_info(events)')->fetchAll(PDO::FETCH_COLUMN, 1), true) ? 'geo' : 'NULL AS geo';
    $rows = $pdo->query("SELECT id, ts, user, ip, $geo, action, docket, subject, detail FROM events WHERE id > $last ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $who = fn($u) => (string)($m['user_labels'][$u] ?? ($u ?: 'unknown'));
    $title = '<h3 style="margin:18px 0 6px;color:#1b2a49">Mail lookup activity</h3>';
    if (!$rows) return ['html' => $title . '<p style="color:#666">None since the last report.</p>', 'last_id' => $last];

    $n = ['login' => 0, 'search' => 0, 'open' => 0, 'deny' => 0, 'fail' => 0]; $people = [];
    foreach ($rows as $r) {
        $k = match ($r['action']) { 'login' => 'login', 'search' => 'search', 'open' => 'open', 'deny', 'geo_deny' => 'deny', 'login_fail', 'error' => 'fail', default => null };
        if ($k) $n[$k]++;
        $p = &$people[$who($r['user'])]; $p ??= ['login' => 0, 'search' => 0, 'open' => 0, 'deny' => 0, 'fail' => 0, 'files' => [], 'places' => [], 'last' => 0];
        if ($k) $p[$k]++;
        if ($r['action'] === 'search' && $r['docket']) $p['files'][$r['docket']] = true;
        if (!empty($r['geo'])) $p['places'][$r['geo']] = true;
        $p['last'] = max($p['last'], (int)$r['ts']); unset($p);
    }
    $pl = fn(int $c, string $one, string $many) => "$c " . ($c === 1 ? $one : $many);
    $o = $title . '<p style="margin:4px 0">' . date('D j M H:i', (int)$rows[0]['ts']) . ' to ' . date('D j M H:i', (int)end($rows)['ts']) . ': '
       . $pl($n['login'], 'sign-in', 'sign-ins') . ', ' . $pl($n['search'], 'search', 'searches') . ', ' . $pl($n['open'], 'message', 'messages') . ' opened'
       . ($n['deny'] ? ', <b style="color:#b42318">' . $n['deny'] . ' refused</b>' : '') . ($n['fail'] ? ', <b style="color:#b42318">' . $pl($n['fail'], 'failure', 'failures') . '</b>' : '') . '.</p>';
    $o .= '<table style="border-collapse:collapse;width:100%;font-size:13px"><tr>';
    foreach (['Who', 'Where', 'Sign-ins', 'Searches', 'Opened', 'Refused', 'Files', 'Last seen'] as $x) $o .= '<th style="text-align:left;border-bottom:1px solid #ccc;padding:4px">' . $x . '</th>';
    $o .= '</tr>';
    $td = fn($c) => '<td style="border-bottom:1px solid #eee;padding:4px;vertical-align:top">' . $c . '</td>';
    foreach ($people as $name => $p)
        $o .= '<tr>' . $td($h($name)) . $td($h(implode(', ', array_keys($p['places'])) ?: '-')) . $td($p['login']) . $td($p['search']) . $td($p['open'])
            . $td($p['deny'] ? '<b style="color:#b42318">' . $p['deny'] . '</b>' : '0') . $td($h(implode(', ', array_keys($p['files'])))) . $td(date('D j M H:i', $p['last'])) . '</tr>';
    $o .= '</table>';
    // Full log, collapsed by default in mail clients that honour <details>; refusals and failures are always listed.
    $line = fn($r) => '<tr>' . $td(date('d M H:i', (int)$r['ts'])) . $td($h($who($r['user']))) . $td($h($r['geo'] ?? '-')) . $td($h($r['ip'] ?? ''))
        . $td($h($r['action'])) . $td($h($r['docket'] ?? '')) . $td($h(substr((string)($r['subject'] ?? $r['detail'] ?? ''), 0, 90))) . '</tr>';
    $bad = array_filter($rows, fn($r) => in_array($r['action'], ['deny', 'geo_deny', 'login_fail', 'error'], true));
    if ($bad) $o .= '<p style="margin:8px 0 2px;color:#b42318"><b>Refused or failed requests</b></p><table style="border-collapse:collapse;width:100%;font-size:12px">' . implode('', array_map($line, $bad)) . '</table>';
    $o .= '<details style="margin-top:6px"><summary style="cursor:pointer;color:#555;font-size:12px">Full log (' . count($rows) . ' entries)</summary><table style="border-collapse:collapse;width:100%;font-size:12px">'
        . implode('', array_map($line, $rows)) . '</table></details>';
    return ['html' => $o, 'last_id' => (int)end($rows)['id']];
}
