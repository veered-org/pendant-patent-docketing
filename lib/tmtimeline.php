<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * The trademark timeline card on EVERY trademark client email (2026-10-04: "I love the timeline ... add that to all the trademark
 * emails ... include a link to the 'timeline' part of my website. look at USPTO for current backlog until examination and write the dates
 * as 'about' when we don't know an exact date. refresh the USPTO backlog about once a month.")
 *
 *  - tm_pendency()        USPTO's own processing times (Trademarks Data Visualization Center, /dashboard/data/trademarks/tm_dashboard_data.csv),
 *                         refreshed by the nightly job when older than 28 days (tm_pendency_refresh); built-in values if it was never fetched.
 *  - tm_milestones()      the mark's road: exact dates from its TSDR prosecution history (first action, approval, publication, allowance,
 *                         Statement of Use, registration), estimates written "about Month YYYY" from the pendency figures. A registered mark
 *                         shows its maintenance road instead (Section 8 / 15, renewals; Section 71 for Madrid extensions).
 *  - tm_card()            email-safe HTML (tables, inline styles) in navy and gold: headline for the email's purpose, mark, numbers, timeline,
 *                         link to firm tm_timeline_url. Shown read-only on the send page, stored in outbox.card_html.
 */
const TM_PENDENCY_CSV = 'https://www.uspto.gov/dashboard/data/trademarks/tm_dashboard_data.csv';
const TM_PENDENCY_JSON = 'https://www.uspto.gov/dashboard/data/trademarks/trademarks.settings.json';
// Fallback = USPTO's figures for Q3 FY2026 (report date 2026-06-01), read 2026-10-04.
const TM_PENDENCY_DEFAULT = ['first_action' => 4.25, 'madrid_first_action' => 1.6, 'total' => 7.8, 'sou_days' => 64, 'renewal_days' => 50, 'quarter' => 'Q3 2026', 'report_date' => '2026-06-01', 'fetched' => ''];

function tm_pendency(): array {
    $j = json_decode((string)(q1("SELECT value FROM settings WHERE key='uspto_tm_pendency'")['value'] ?? ''), true);
    return is_array($j) ? $j + TM_PENDENCY_DEFAULT : TM_PENDENCY_DEFAULT;
}
/** Latest numeric value of a CSV metric row ("-" and blanks skipped), with its quarter label. */
function tm_pendency_metric(array $rows, string $name): ?array {
    $hdr = $rows[0] ?? [];
    foreach ($rows as $r) if (trim((string)($r[0] ?? '')) === $name) { for ($i = count($r) - 1; $i > 0; $i--) if (is_numeric(trim((string)$r[$i]))) return [(float)trim($r[$i]), trim((string)($hdr[$i] ?? ''))]; }
    return null;
}
/** Re-read USPTO's processing times when the stored copy is older than $maxAgeDays. Returns a log line. */
function tm_pendency_refresh(int $maxAgeDays = 28): string {
    $cur = tm_pendency();
    if ($cur['fetched'] !== '' && strtotime($cur['fetched']) > time() - $maxAgeDays * 86400) return 'USPTO trademark pendency: current (fetched ' . $cur['fetched'] . ')';
    $get = function (string $u): string { if (function_exists('http_bytes')) return http_bytes($u);   // lib/gmail_pull.php: curl, or streams where PHP has no curl
        $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/128 Safari/537.36']);
        $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); return $code === 200 && is_string($b) ? $b : ''; };
    $csv = $get(TM_PENDENCY_CSV . '?' . time());
    if ($csv === '' || stripos($csv, 'First Action Pendency') === false) return 'USPTO trademark pendency: could not read the dashboard data (kept ' . ($cur['quarter'] ?: 'built-in values') . ')';
    $rows = array_map('str_getcsv', preg_split('/\r?\n/', preg_replace('/^\xEF\xBB\xBF/', '', $csv)));
    $fa = tm_pendency_metric($rows, 'Pendency by Filing Method - Base (Stand-alone) First Action (months)') ?? tm_pendency_metric($rows, 'First Action Pendency (months)');
    $tot = tm_pendency_metric($rows, 'Pendency by Filing Method - Base (Stand-alone) Total (months)') ?? tm_pendency_metric($rows, 'Total Pendency (months)');
    $mad = tm_pendency_metric($rows, 'Pendency by Filing Method - Madrid First Action (months)'); $sou = tm_pendency_metric($rows, 'Statement of Use Pendency (days)'); $ren = tm_pendency_metric($rows, 'Post Registration Renewal Pendency (days)');
    if (!$fa || $fa[0] <= 0 || $fa[0] > 24) return 'USPTO trademark pendency: unexpected first-action figure; kept ' . ($cur['quarter'] ?: 'built-in values');
    $rd = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $get(TM_PENDENCY_JSON . '?' . time())), true)['reportDate'] ?? '';
    $new = ['first_action' => $fa[0], 'madrid_first_action' => $mad[0] ?? $cur['madrid_first_action'], 'total' => $tot[0] ?? $cur['total'], 'sou_days' => (int)($sou[0] ?? $cur['sou_days']),
            'renewal_days' => (int)($ren[0] ?? $cur['renewal_days']), 'quarter' => $fa[1], 'report_date' => (string)$rd, 'fetched' => date('Y-m-d')];
    run("INSERT OR REPLACE INTO settings(key,value) VALUES ('uspto_tm_pendency', ?)", [json_encode($new)]);
    return sprintf('USPTO trademark pendency refreshed (%s): first action %.2f months, total %.1f months, Statement of Use %d days', $new['quarter'], $new['first_action'], $new['total'], $new['sou_days']);
}

/** TSDR record for a matter, once per request ([] when unavailable: the card then falls back to what Pendant holds). */
function tm_tsdr(array $m): array {
    static $cache = [];
    $sn = preg_replace('/\D/', '', (string)($m['app_no'] ?? ''));
    if (strlen($sn) !== 8 || empty($GLOBALS['cfg']['tsdr_key']) || !function_exists('tsdr_lookup')) return [];
    if (!array_key_exists($sn, $cache)) { try { $r = tsdr_lookup($sn); $cache[$sn] = !empty($r['ok']) ? $r : []; } catch (Throwable $e) { $cache[$sn] = []; } }
    return $cache[$sn];
}
/** First / last date in the TSDR history whose entry code is one of $codes (history is newest first). */
function tm_hist(array $hist, array $codes, bool $first = true, string $after = ''): string {
    $d = array_values(array_filter(array_map(fn($h) => in_array($h['code'], $codes, true) && $h['date'] > $after ? $h['date'] : '', $hist)));
    if (!$d) return ''; sort($d); return $first ? $d[0] : end($d);
}
// The USPTO backlog to a first action, as written on the timeline card and in the filing report (2026-10-07).
function tm_backlog_months(bool $madrid = false): string { $P = tm_pendency(); return rtrim(rtrim(number_format((float)$P[$madrid ? 'madrid_first_action' : 'first_action'], 1), '0'), '.'); }
function tm_about(string $iso): string { return 'about ' . (new DateTimeImmutable($iso))->format('M Y'); }
function tm_exact(string $iso): string { return (new DateTimeImmutable($iso))->format('M j, Y'); }
function tm_months(string $iso, float $months): string { $d = (int)round($months * 30.44); return add_days($iso, $d, false); }

/**
 * The steps for the card: [[label, date text, state done|here|renew|ahead, sub], ...]. A step with an exact past date is done; the last
 * step reached is "here" (highlighted); later steps are ahead. Future dates are estimates ("about Feb 2027") unless the USPTO has fixed them.
 */
function tm_milestones(array $m, string $focus = ''): array {
    $t = today(); $P = tm_pendency(); $live = tm_tsdr($m); $hist = $live['history'] ?? [];
    $basis = strtolower((string)(($live['basis'] ?? '') ?: ($m['basis'] ?? ''))); $filedBasis = strtolower((string)($m['basis_filed'] ?? ''));
    $is66 = str_contains($basis, '66a'); $itu = str_contains($basis, '1b');
    $filed = substr((string)(($live['filing_date'] ?? '') ?: ($m['filing_date'] ?? '')), 0, 10);
    $regDate = substr((string)(($live['reg_date'] ?? '') ?: ($m['grant_date'] ?? '')), 0, 10); if (trim((string)(($live['reg_no'] ?? '') ?: ($m['reg_no'] ?? ''))) === '') $regDate = '';
    if ($regDate === '' && $hist) $regDate = tm_hist($hist, ['R.PR', 'R.SR']);
    // Registered (2026-10-04): Filed, Examined, Published, Registered, then the first renewal in yellow. A mark past its first
    // window shows the next one ("Next renewal"); a window stays current through its 6-month grace period, "filed DATE" once TSDR shows it.
    if ($regDate !== '') {
        $S = []; if ($filed !== '') $S[] = ['Filed', tm_exact($filed), 'done', ''];
        $ex = $hist ? tm_hist($hist, ['GNRT', 'GNEA', 'CNSA', 'GNSL', 'GNFR', 'GNPR', 'CNEA', 'CNRT']) : ''; if ($ex !== '') $S[] = ['Examined', tm_exact($ex), 'done', ''];
        $pb = $hist ? tm_hist($hist, ['PUBO'], false) : ''; if ($pb !== '' && $pb <= $regDate) $S[] = ['Published', tm_exact($pb), 'done', ''];
        // 2026-10-06 (XYZ014): a registration of the last six months is where the mark is now - "Registered" is the highlighted step
        // and the first renewal follows as an ordinary dot.
        $recent = $regDate >= add_months($t, -6, false);
        $S[] = ['Registered', tm_exact($regDate), $recent ? 'here' : 'done', ''];
        $win = [[5, 6, $is66 ? 'Declaration of Use (§71)' : 'Declaration of Use (§8)']]; if (!$is66) for ($y = 10; $y <= 60; $y += 10) $win[] = [$y - 1, $y, 'Renewal (year ' . $y . ')'];
        foreach ($win as $k => [$a, $b, $lbl]) {
            $open = add_months($regDate, $a * 12, false); $due = add_months($regDate, $b * 12, false); $grace = add_months($due, 6, false);
            if ($grace < $t) continue;   // the first window not yet over
            $filedIn = ''; foreach ($hist as $h) if ($h['date'] >= add_months($open, -1, false) && $h['date'] <= $grace && preg_match('/SEC(TION|\.)? ?(8|71)|RENEWAL|SECTION 9/i', (string)$h['desc']) && !preg_match('/COURTESY|REMINDER/i', (string)$h['desc'])) { $filedIn = $h['date']; break; }   // the USPTO's courtesy reminder is not a filing (JJi037, 2026-10-04 audit)
            // 2026-10-06 (STA001): a window already filed is a done step; the yellow box moves on to the window after it.
            if ($filedIn !== '') { $S[] = [$k === 0 ? 'Declaration filed' : 'Renewal filed', tm_exact($filedIn), 'done', '']; continue; }
            $S[] = [$k === 0 ? 'First renewal' : 'Next renewal', (new DateTimeImmutable($open))->format('M Y') . ' – ' . (new DateTimeImmutable($due))->format('M Y'), $recent ? 'ahead' : 'renew',
                    $filedIn !== '' ? 'filed ' . tm_exact($filedIn) : ($due < $t ? 'grace period to ' . (new DateTimeImmutable($grace))->format('M j, Y') : ($k === 0 ? $lbl : $lbl))];
            break;
        }
        return $S;
    }
    // Pending application.
    $exam = $hist ? tm_hist($hist, ['GNRT', 'GNEA', 'CNSA', 'GNSL', 'GNFR', 'GNPR', 'CNEA', 'CNRT']) : '';
    $appr = $hist ? tm_hist($hist, ['CNSA'], false) : ''; $pub = $hist ? tm_hist($hist, ['PUBO'], false) : '';
    if ($pub === '' && !empty($m['id'])) $pub = (string)(q1("SELECT mail_date FROM correspondence WHERE matter_id=? AND doc_code='NOTICE OF PUBLICATION' ORDER BY mail_date DESC LIMIT 1", [(int)$m['id']])['mail_date'] ?? '');
    $noa = $hist ? tm_hist($hist, ['NOAM'], false) : ''; $souFiled = $noa !== '' ? tm_hist($hist, ['IUAF', 'SUPC'], true, $noa) : ''; $souOk = $hist ? tm_hist($hist, ['CNPR', 'SUNA'], false) : '';
    if ($noa !== '') $itu = true;   // allowed on intent to use: still that road after the SOU turns the basis into 1(a) (XYZ004)
    $S = []; $faMonths = $is66 ? (float)$P['madrid_first_action'] : (float)$P['first_action'];
    $S[] = ['Filed', $filed !== '' ? tm_exact($filed) : 'date unknown', 'done', $itu || str_contains($filedBasis, '1b') ? 'intent to use' : ($is66 ? 'Madrid' : '')];
    if ($exam !== '') $S[] = ['Examined', tm_exact($exam), 'done', $appr !== '' && $appr === $exam ? 'approved' : ''];
    else { $e = $filed !== '' ? tm_months($filed, $faMonths) : ''; $S[] = ['Examined', $e === '' ? 'in turn' : ($e < $t ? 'expected soon' : tm_about($e)), 'ahead', 'USPTO backlog ~' . tm_backlog_months($is66) . ' mo']; }
    if ($pub !== '') $S[] = ['Published', tm_exact($pub), $pub <= $t ? 'done' : 'ahead', 'opposition to ' . (new DateTimeImmutable(add_days($pub, 30, false)))->format('M j')];
    else { $base = $appr !== '' ? add_days($appr, 28, false) : ($exam !== '' ? tm_months(max($exam, $t), 1.5) : ($filed !== '' ? tm_months($filed, $faMonths + 1) : '')); $S[] = ['Published', $base === '' ? '' : ($base < $t ? 'expected soon' : tm_about($base)), 'ahead', '30-day opposition']; }
    if ($itu) {
        if ($noa !== '') $S[] = ['Allowance', tm_exact($noa), 'done', ''];
        else { $n = $pub !== '' ? add_days($pub, 56, false) : ($filed !== '' ? tm_months($filed, $faMonths + 3) : ''); $S[] = ['Allowance', $n === '' ? '' : ($n < $t ? 'expected soon' : tm_about($n)), 'ahead', 'Notice of Allowance']; }
        if ($souFiled !== '') $S[] = ['Statement of Use', tm_exact($souFiled), 'done', $souOk !== '' ? 'accepted ' . tm_exact($souOk) : 'filed'];
        else { $due = $noa !== '' ? (string)(q1("SELECT due_date FROM events WHERE matter_id=? AND type LIKE 'Statement of Use%' AND status='open' ORDER BY due_date LIMIT 1", [(int)($m['id'] ?? 0)])['due_date'] ?? add_months($noa, 6, false)) : '';
            $S[] = ['Statement of Use', $due !== '' ? 'due ' . tm_exact($due) : 'within 6 months', 'ahead', $due !== '' ? 'or extend 6 months' : 'of the allowance']; }
        $r = $souOk !== '' ? add_days($souOk, 21, false) : ($souFiled !== '' ? add_days($souFiled, (int)$P['sou_days'], false) : '');
        $S[] = ['Registered', $r === '' ? 'after use is accepted' : ($r < $t ? 'expected soon' : tm_about($r)), 'ahead', ''];
    } else {
        // in use: the opposition period gets its own step (the publication email's card, 2026-10-04); intent-to-use folds it into Published
        $S[2][3] = '30-day opposition'; $oe = $pub !== '' ? add_days($pub, 30, false) : '';
        $S[] = ['Opposition ends', $oe !== '' ? tm_exact($oe) : 'a month after', $oe !== '' && $oe < $t ? 'done' : 'ahead', ''];
        $r = $pub !== '' ? add_days($pub, 49, false) : ($filed !== '' ? tm_months($filed, max((float)$P['total'], $faMonths + 3)) : '');
        $S[] = ['Registered', $r === '' ? '' : ($r < $t ? 'expected soon' : tm_about($r)), 'ahead', $is66 ? 'protection granted' : 'certificate'];
    }
    // 2026-10-08 (XYZ060): an email about the Statement of Use (its signing link, its deadline) highlights that step, not the allowance before it.
    if (preg_match('/Statement of Use/i', $focus) && !preg_match('/Accepted|Filed/i', $focus))
        foreach ($S as $i => $s) if ($s[0] === 'Statement of Use' && $s[2] === 'ahead') { $S[$i][2] = 'here'; break; }
    // 2026-10-07 (XYZ005): highlight where the mark is now - the last step reached - not the next one (as the patent card does).
    $hl = false; foreach ($S as $s) if (in_array($s[2], ['here', 'renew'], true)) $hl = true;
    if (!$hl) { $last = null; foreach ($S as $i => $s) if ($s[2] === 'done') $last = $i; if ($last !== null) $S[$last][2] = 'here'; elseif ($S) $S[0][2] = 'now'; }
    return $S;
}
/** The headline at the top of the card, by what the email is about. */
function tm_card_headline(string $type): string {
    foreach ([['/Opposition Period Ended/i', 'Opposition period over'], ['/Notice of Publication|Opposition/i', 'Approved for publication'], ['/Trademark Filing|APPLICATION FILED/i', 'Application filed'], ['/Filing Receipt/i', 'Filing received'],
              ['/Notice of Allowance|- NOA$/i', 'Notice of Allowance'], ['/Statement of Use Accepted/i', 'Statement of Use accepted'], ['/Sign Statement of Use/i', 'Statement of Use ready to sign'], ['/Statement of Use/i', 'Statement of Use deadline'],
              ['/Registration|Issuance|Registered/i', 'Registered'], ['/Acceptance|Maintenance Fee Paid/i', 'Registration maintained'], ['/Section 8|Section 71|Section 9|Renewal|Grace/i', 'Renewal window'],
              ['/Office Action|Suspension|Reply Due|Examiner/i', 'Office action'], ['/Assignment/i', 'Ownership change'], ['/Remind Client/i', 'Upcoming deadline']] as [$re, $h])
        if (preg_match($re, $type)) return $h;
    return 'Trademark update';
}
/** US trademark, not dead / abandoned: the card goes on. */
function tm_card_applies(array $m, ?array $ev): bool {
    if (($m['kind'] ?? '') !== 'trademark' || !preg_match('/^(|United States|US|USA)$/i', trim((string)($m['country'] ?? ''))) || !empty($m['dead'])) return false;
    $type = (string)($ev['type'] ?? ''); if (defined('ABANDON_REPORT_EVENT') && $type === ABANDON_REPORT_EVENT) return false;
    return !preg_match('/abandon/i', $type) && strlen(preg_replace('/\D/', '', (string)($m['app_no'] ?? ''))) === 8;
}
function tm_card(array $m, ?array $ev, string $headline = ''): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    if (!empty($m['id'])) $m = q1('SELECT * FROM matters WHERE id=?', [(int)$m['id']]) ?: $m;
    $steps = tm_milestones($m, (string)($ev['type'] ?? '')); if (!empty($m['id'])) $m = q1('SELECT * FROM matters WHERE id=?', [(int)$m['id']]) ?: $m;   // the TSDR read refreshed basis / reg. no.
    $headline = $headline !== '' ? $headline : tm_card_headline((string)($ev['type'] ?? ''));
    $title = clean_title((string)($m['title'] ?? '')); $reg = trim((string)($m['reg_no'] ?? '')); $filed = substr((string)($m['filing_date'] ?? ''), 0, 10);
    [$key] = function_exists('pub_story_for') ? pub_story_for($m) : ['unknown'];
    $basisWords = ['itu' => 'Intent to use', 'mixed' => 'Part in use, part intent to use', 'amended' => 'Intent to use, since shown in use', 'use' => 'In use', '44e' => 'Foreign registration', '66a' => 'International registration (Madrid Protocol)'][$key] ?? '';
    $G = '#C9A227'; $GT = '#8a6d12'; $N = NAVY; $L = '#e2ddd2'; $BG = '#faf8f3';
    $sn = function_exists('logo_serial') ? logo_serial($m) : '';
    $markCell = $sn !== '' ? '<img src="' . $e(logo_url($sn)) . '" alt=""' . $e($title ?: 'mark') . '" style="max-height:64px;max-width:180px;border:1px solid ' . $L . ';border-radius:4px;padding:4px;background:#fff;display:block">' . ($title !== '' ? '<div style="font-size:12px;color:#6b7280;margin-top:4px">' . $e($title) . '</div>' : '')
                         : '<div style="font-size:22px;line-height:1.15;font-weight:bold;color:' . MARK_COLOR . ';letter-spacing:.03em">' . $e($title ?: 'Design mark') . '</div>';
    $rows = [];
    if ($reg !== '') $rows[] = ['Registration No.', $e('US ' . fmt_reg($reg)) . (trim((string)($m['grant_date'] ?? '')) !== '' ? ' <span style="color:#6b7280">&middot; ' . $e(fmt_long(substr((string)$m['grant_date'], 0, 10))) . '</span>' : '')];
    // 2026-10-06: the filing date follows the serial number in grey, like the registration date (no separate "Filed" row).
    $rows[] = ['U.S. Serial Number', $e(fmt_app((string)($m['app_no'] ?? ''), 'United States')) . ($filed !== '' ? ' <span style="color:#6b7280">&middot; ' . $e(fmt_long($filed)) . '</span>' : '') . ($basisWords !== '' && $reg === '' ? ' <span style="color:#6b7280">&middot; ' . $e($basisWords) . '</span>' : '')];
    [$aBefore, $aAfter] = assoc_card_rows($m);   // their file number above ours, the applicant below (2026-10-14)
    foreach ($aBefore as [$k, $v]) $rows[] = [$k, $e($v)];
    $rows[] = ['Our file number', $e((string)($m['ref'] ?? ''))];
    foreach ($aAfter as [$k, $v]) $rows[] = [$k, $e($v)];
    $info = ''; foreach ($rows as [$k, $v]) $info .= '<tr><td style="padding:2px 14px 2px 0;color:' . $GT . ';font-size:10px;text-transform:uppercase;letter-spacing:.07em;white-space:nowrap;vertical-align:top">' . $k . '</td><td style="padding:2px 0;color:' . $N . ';font-size:13px">' . $v . '</td></tr>';
    $n = count($steps); $tl = '<tr>'; $anyAbout = false;
    foreach ($steps as $i => [$lbl, $date, $st, $sub]) {
        if (str_starts_with((string)$date, 'about') || $date === 'expected soon') $anyAbout = true;
        $hl = in_array($st, ['now', 'renew', 'here'], true);   // 'here' (2026-10-06): a reached step that is where the mark is now
        $dot = $st === 'done' ? 'background:' . $N . ';border:2px solid ' . $N : ($hl ? 'background:' . $G . ';border:2px solid ' . $G . ';box-shadow:0 0 0 3px #f3e6b8' : 'background:#fff;border:2px solid #cfc9bc');
        $lineL = $i > 0 ? 'border-top:2px solid ' . (in_array($st, ['done', 'now', 'here'], true) ? $N : $L) : 'border-top:2px solid transparent';
        $lineR = $i < $n - 1 ? 'border-top:2px solid ' . (in_array($steps[$i + 1][2], ['done', 'now', 'here'], true) ? $N : $L) : 'border-top:2px solid transparent';
        $tl .= '<td style="width:' . round(100 / $n, 2) . '%;text-align:center;vertical-align:top;padding:0 3px' . (in_array($st, ['renew', 'here'], true) ? ';background:#FFF2CC;border-radius:6px' : '') . '">'   // the first renewal in yellow (2026-10-04)
             . '<table cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse"><tr><td style="' . $lineL . ';width:50%;font-size:0;line-height:0">&nbsp;</td><td style="padding:0"><div style="width:12px;height:12px;border-radius:50%;' . $dot . ';margin:-7px auto 0"></div></td><td style="' . $lineR . ';width:50%;font-size:0;line-height:0">&nbsp;</td></tr></table>'
             . '<div style="font-size:11px;font-weight:bold;color:' . ($hl ? $GT : $N) . ';margin-top:5px;line-height:1.2">' . $e($lbl) . '</div>'
             . '<div style="font-size:11px;color:' . (in_array($st, ['done', 'here'], true) ? $N : '#6b7280') . ';margin-top:2px;line-height:1.3">' . $e($date) . '</div>'
             . ($sub !== '' ? '<div style="font-size:10px;color:#9a948a;margin-top:1px;line-height:1.3">' . $e($sub) . '</div>' : '') . '</td>';
    }
    $tl .= '</tr>'; $P = tm_pendency();
    $foot = !firm('tm_timeline_url') ? '' : '<a href="' . htmlspecialchars((string)firm('tm_timeline_url')) . '" style="color:' . $N . ';font-weight:bold;text-decoration:none;border-bottom:1px solid ' . $G . '">How the trademark process works &rarr;</a>'
          . ($anyAbout ? '<span style="color:#9a948a"> &nbsp;&middot;&nbsp; &ldquo;About&rdquo; dates are estimates from current USPTO processing times' . ($P['quarter'] !== '' ? ' (' . $e($P['quarter']) . ')' : '') . '.</span>' : '');
    return '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;width:100%;max-width:600px;margin:4px 0 20px;font-family:Lexend,Arial,sans-serif;border:1px solid ' . $L . ';border-radius:8px;overflow:hidden;background:' . $BG . '">'
        . '<tr><td style="background:' . $N . ';color:#fff;padding:8px 18px;border-bottom:3px solid ' . $G . '"><span style="font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:' . $G . ';font-weight:bold">Your U.S. trademark</span> <span style="color:' . $G . '">&nbsp;&middot;&nbsp;</span> <span style="font-size:15px;font-weight:bold;letter-spacing:.01em">' . $e($headline) . '</span></td></tr>'
        . '<tr><td style="padding:10px 18px"><table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse"><tr><td style="padding:0 22px 0 0;vertical-align:middle">' . $markCell . '</td>'   // 2026-10-12: card too tall - mark beside its numbers
        . '<td style="vertical-align:middle"><table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse">' . $info . '</table></td></tr></table></td></tr>'
        . '<tr><td style="padding:13px 10px 8px;background:#fff;border-top:1px solid ' . $L . '"><table cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;table-layout:fixed">' . $tl . '</table></td></tr>'
        . '<tr><td style="padding:6px 18px 8px;background:#fff;border-top:1px solid #f0ece3;font-size:11px;line-height:1.4">' . $foot . '</td></tr>'
        . '</table>';
}
