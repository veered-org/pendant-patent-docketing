<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * The patent card on every US patent client email (2026-10-06: "we have a cool trademark box at the top of each email - can you
 * make one for patents now?"). Same navy/gold, email-safe tables as the trademark card (lib/tmtimeline.php).
 *
 *  - pt_milestones()  the application's road. Exact dates from the USPTO Open Data Portal history (published applications and patents) and
 *                     from the papers Pendant docketed itself (correspondence: unpublished applications); later steps are "about Month YYYY"
 *                     estimates from USPTO pendency. A provisional shows its 12-month non-provisional / foreign-filing deadline and the date
 *                     to contact us (four months before). A granted patent shows its maintenance fees (utility) and its expiry.
 *  - pt_card()        the card itself; shown read-only on the send page and stored in outbox.card_html.
 * The highlighted step is where the application is now: the last step reached; for a patent granted over six months ago, the next
 * maintenance fee (as the trademark card does for renewals).
 */
// USPTO average pendency in months (Patents Data at a Glance, FY2026): to a first action, and filing to grant. Used only for "about" dates.
const PT_PENDENCY = ['utility_first_action' => 22.0, 'utility_total' => 26.0, 'design_first_action' => 17.0, 'design_total' => 21.0];
const PT_FIRST_ACTION_CODES = ['CTNF', 'CTFR', 'CTRS', 'CTEQ', 'CTMS'];
const PT_ALLOWANCE_CODES = ['NOA', 'N/=.', 'MN/=.'];

function pt_card_headline(string $type, array $m): string {
    $prov = function_exists('is_provisional_matter') && is_provisional_matter($m);
    foreach ([['/Filing Receipt|APP\.FILE\.REC|FLRCPT/i', 'Filing receipt'], ['/Patent Filing|Application Filed/i', $prov ? 'Provisional application filed' : 'Application filed'],
              ['/Non-Provisional|Foreign Filing|International Filing/i', 'Upcoming filing deadline'], ['/Publication|NTC\.PUB/i', 'Application published'],
              ['/Issue Fee Paid/i', 'Issue fee paid'], ['/- NOA\b/', 'Notice of Allowance'],   // 2026-10-13: "Respond to Office Action - NOA" is the issue fee, not an office action
              ['/Office Action|Restriction|Election|Quayle|Response Due|Final Rejection/i', 'Office action'], ['/Allowance|Issue Fee/i', 'Notice of Allowance'],
              ['/Issue Notification/i', 'Patent issuing'], ['/Issuance|Grant|Patented/i', 'Patent granted'], ['/Maintenance Fee Paid/i', 'Maintenance fee paid'],
              ['/Maintenance Fee|Year Maintenance/i', 'Maintenance fee'], ['/Assignment/i', 'Ownership change'], ['/Remind Client/i', 'Upcoming deadline']] as [$re, $h])
        if (preg_match($re, $type)) return $h;
    return $prov ? 'Provisional application' : 'Patent update';
}
/** US patent matter with an application number, not dead, not the abandonment email: the card goes on. */
function pt_card_applies(array $m, ?array $ev): bool {
    if (($m['kind'] ?? '') !== 'patent' || !preg_match('/^(|United States|US|USA)$/i', trim((string)($m['country'] ?? ''))) || !empty($m['dead'])) return false;
    $type = (string)($ev['type'] ?? ''); if (defined('ABANDON_REPORT_EVENT') && $type === ABANDON_REPORT_EVENT) return false;
    return !preg_match('/abandon/i', $type) && strlen(preg_replace('/\D/', '', (string)($m['app_no'] ?? ''))) === 8;
}
/** ODP record for the card, once per application per request; [] for unpublished applications (and on any failure). */
function pt_odp(array $m): array {
    static $cache = []; $app = preg_replace('/\D/', '', (string)($m['app_no'] ?? ''));
    if ($app === '' || (function_exists('is_provisional_matter') && is_provisional_matter($m)) || empty($GLOBALS['cfg']['odp_key'])) return [];
    if (!array_key_exists($app, $cache)) { try { $r = odp_lookup($app); $cache[$app] = !empty($r['ok']) ? $r : []; } catch (Throwable $e) { $cache[$app] = []; } }
    return $cache[$app];
}
/** "US20250284266A1" -> "US 2025/0284266 A1" */
function pt_fmt_pub(string $n): string { $n = preg_replace('/^US\s*/i', '', trim($n)); return preg_match('/^(\d{4})(\d{7})([AB]\d)$/', $n, $x) ? "US $x[1]/$x[2] $x[3]" : 'US ' . $n; }
function pt_fmt_patent(string $n): string { $n = trim($n); return preg_match('/^(D|RE|PP)?(\d{5,8})$/i', $n, $x) ? strtoupper($x[1]) . number_format((int)$x[2]) : $n; }

/** [[label, date text, state done|here|now|renew|ahead, sub], ...] */
function pt_milestones(array $m): array {
    $t = today(); $live = pt_odp($m); $hist = $live['history'] ?? [];
    $design = is_design_matter($m); $prov = function_exists('is_provisional_matter') && is_provisional_matter($m);
    $filed = substr((string)(($live['filing_date'] ?? '') ?: ($m['filing_date'] ?? '')), 0, 10);
    $grant = trim((string)(($live['reg_no'] ?? '') ?: ($m['reg_no'] ?? ''))) !== '' ? substr((string)(($live['reg_date'] ?? '') ?: ($m['grant_date'] ?? '')), 0, 10) : '';
    $exact = fn(string $d) => (new DateTimeImmutable($d))->format('M j, Y');
    $about = fn(string $d) => $d < $t ? 'expected soon' : 'about ' . (new DateTimeImmutable($d))->format('M Y');
    $S = [];
    if ($filed !== '') $S[] = ['Filed', $exact($filed), 'done', $prov ? 'provisional' : ($design ? 'design' : '')];

    if ($prov) {   // Filed -> contact us (4 months before) -> non-provisional / PCT deadline
        $dl = !empty($m['id']) && function_exists('prov_deadline') ? prov_deadline((int)$m['id']) : null;
        $due = $dl ? (string)$dl['due_date'] : ($filed !== '' ? add_months($filed, 12, false) : '');
        if ($due !== '') {
            $contact = add_months($due, -(defined('PROV_CONTACT_MONTHS') ? PROV_CONTACT_MONTHS : 4), false);
            $S[] = ['Contact us', $exact($contact), 'ahead', 'to prepare the next filing'];
            $S[] = ['Non-provisional / foreign filing', $exact($due), 'ahead', 'filing deadline'];   // 2026-10-06: not "PCT"
        }
        return pt_mark_here($S, $grant, $t);
    }
    // Dates the application reached, from ODP's history, else the papers Pendant docketed.
    $hd = function (array $codes, bool $first = true) use ($hist): string { $d = array_values(array_filter(array_map(fn($h) => in_array($h['code'], $codes, true) ? substr((string)$h['date'], 0, 10) : '', $hist))); if (!$d) return ''; sort($d); return $first ? $d[0] : end($d); };
    $cd = function (array $codes, bool $first = true) use ($m): string {
        if (empty($m['id'])) return ''; $ph = implode(',', array_fill(0, count($codes), '?'));
        try { $r = q1('SELECT ' . ($first ? 'min' : 'max') . "(mail_date) d FROM correspondence WHERE matter_id=? AND doc_code IN ($ph)", array_merge([(int)$m['id']], $codes)); } catch (Throwable $e) { return ''; }
        return substr((string)($r['d'] ?? ''), 0, 10);
    };
    $fa = $hd(array_map(fn($c) => 'M' . $c, PT_FIRST_ACTION_CODES)) ?: $hd(PT_FIRST_ACTION_CODES) ?: $cd(PT_FIRST_ACTION_CODES);   // the MAIL date (MCTNF), which the response period runs from
    $noa = $hd(PT_ALLOWANCE_CODES, false) ?: $cd(['NOA'], false);
    $pub = substr((string)($live['pub_date'] ?? ''), 0, 10);
    $P = PT_PENDENCY; $k = $design ? 'design' : 'utility';

    if ($grant === '') {
        if (!$design) {   // utility: published 18 months from the earliest priority date
            $pri = $filed; foreach (($live['parents'] ?? []) as $p) if (!empty($p['date']) && $p['date'] < $pri) $pri = substr($p['date'], 0, 10);
            $S[] = ['Published', $pub !== '' ? $exact($pub) : ($pri !== '' ? $about(add_months($pri, 18, false)) : ''), $pub !== '' ? 'done' : 'ahead', ''];
        }
        $S[] = ['First action', $fa !== '' ? $exact($fa) : ($filed !== '' ? $about(add_months($filed, (int)round($P[$k . '_first_action']), false)) : ''), $fa !== '' ? 'done' : 'ahead', $fa === '' ? 'USPTO backlog ~' . (int)round($P[$k . '_first_action']) . ' mo' : 'examination'];
        // 2026-10-06 (XYZ006): the current office action's response deadline, "(extendable up to 3 months)".
        if ($noa === '' && ($r = pt_response_due($m, $hist, $hd, $cd, $t))) {
            if ($r['oa'] !== $fa) $S[] = ['Latest action', $exact($r['oa']), 'done', ''];
            $S[] = ['Response due', $exact($r['due']), 'ahead', $r['final'] ? 'final date, with extension fees' : 'up to 3 month extension possible'];
        }
        $S[] = ['Allowance', $noa !== '' ? $exact($noa) : ($fa !== '' ? 'upon Examiner agreement' : ''), $noa !== '' ? 'done' : 'ahead', $noa !== '' ? 'Notice of Allowance' : ''];
        $est = $noa !== '' ? add_months($noa, 3, false) : ($filed !== '' ? add_months($filed, (int)round($P[$k . '_total']), false) : '');
        // 2026-10-15: once the Issue Notification is in, "Granted" shows its issue date ("expected Oct 27, 2026")
        if ($iss = pt_issue_expected($m, $hist)) $S[] = ['Granted', ($iss['date'] < $t ? '' : 'expected ') . $exact($iss['date']), $iss['date'] <= $t ? 'done' : 'ahead', $iss['patent'] !== '' ? 'U.S. Patent ' . pt_fmt_patent($iss['patent']) : 'issue notification received'];
        else $S[] = ['Granted', $est === '' ? '' : ($noa === '' && $est < $t ? 'after allowance' : $about($est)), 'ahead', 'patent issues'];
        return pt_mark_here($S, $grant, $t);
    }
    // Granted: Filed, Granted, then maintenance fees (utility) and the expiry.
    $S[] = ['Granted', $exact($grant), 'done', ''];
    if (!$design) foreach ([3, 7, 11] as $y) {
        $open = add_months($grant, 12 * $y, false); $due = add_months($grant, 12 * $y + 6, false); $graceEnd = add_months($due, 6, false);
        $paid = !empty($m['id']) && q1('SELECT 1 FROM events WHERE matter_id=? AND type LIKE ? AND status="done"', [(int)$m['id'], $y . ' 1/2 Year Maintenance Fee%']);
        $S[] = [$y . '.5-year fee', (new DateTimeImmutable($open))->format('M Y') . ' – ' . (new DateTimeImmutable($due))->format('M Y'), ($paid || $graceEnd < $t) ? 'done' : 'ahead', $paid ? 'paid' : ''];
    }
    $exp = trim((string)($m['expiration'] ?? '')) !== '' ? substr((string)$m['expiration'], 0, 10) : ($design ? add_months($grant, 180, false) : ($filed !== '' ? add_months($filed, 240, false) : ''));
    if ($exp !== '') $S[] = ['Expires', $exact($exp), $exp < $t ? 'done' : 'ahead', $design ? '15 years from grant' : (trim((string)($m['expiration'] ?? '')) !== '' ? '' : 'estimated')];
    return pt_mark_here($S, $grant, $t);
}
/** The open office action and its response deadline: ['oa' => mail date, 'due' => date, 'final' => past the 3-month date] or null.
 *  The docketed response event when there is one; else 3 months from the latest action's mail date (6 with extensions), when the USPTO
 *  record shows no reply filed since. Past the 6-month date: null. */
function pt_response_due(array $m, array $hist, callable $hd, callable $cd, string $t): ?array {
    $mailCodes = array_map(fn($c) => 'M' . $c, PT_FIRST_ACTION_CODES);
    $oa = $hd($mailCodes, false) ?: $hd(PT_FIRST_ACTION_CODES, false) ?: $cd(PT_FIRST_ACTION_CODES, false);
    if ($oa === '') return null;
    foreach ($hist as $h) if (substr((string)$h['date'], 0, 10) > $oa && (preg_match('/^(A\.|A\.\.\.|AMSB|RCEX|N\/AP|AP\.B|PET\.)/', (string)$h['code']) || preg_match('/\b(response|amendment|request for continued examination|notice of appeal|reply)\b/i', (string)$h['desc']) && !preg_match('/^M?CT|Mail|Email/i', (string)$h['code'] . ' ' . (string)$h['desc']))) return null;
    $ev = !empty($m['id']) ? q1('SELECT due_date FROM events WHERE matter_id=? AND status IN ("open","proposed") AND (type LIKE "Respond to Office Action%" OR type LIKE "Final Response to Office Action%" OR type LIKE "Office Action Response%") ORDER BY due_date LIMIT 1', [(int)$m['id']]) : null;
    $roll = fn(string $d) => function_exists('roll_forward') ? roll_forward(new DateTimeImmutable($d))->format('Y-m-d') : $d;   // a weekend / holiday date moves to the next business day
    $three = $roll(add_months($oa, 3, false)); $six = $roll(add_months($oa, 6, false));
    if ($six < $t) return null;
    if ($ev && $ev['due_date'] <= $three) return ['oa' => $oa, 'due' => (string)$ev['due_date'], 'final' => false];
    return $three >= $t ? ['oa' => $oa, 'due' => $three, 'final' => false] : ['oa' => $oa, 'due' => $six, 'final' => true];
}
/** Highlight where the application is now: the last step reached ('here'); for a patent granted over six months ago, the next fee ('renew'). */
function pt_mark_here(array $S, string $grant, string $t): array {
    $last = null; foreach ($S as $i => $s) if ($s[2] === 'done') $last = $i;
    if ($grant !== '' && $grant < add_months($t, -6, false)) {
        foreach ($S as $i => $s) if ($s[2] === 'ahead' && str_contains($s[0], 'fee')) { $S[$i][2] = 'renew'; return $S; }
    }
    if ($last !== null) $S[$last][2] = 'here';
    return $S;
}

function pt_card(array $m, ?array $ev, string $headline = ''): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    if (!empty($m['id'])) $m = q1('SELECT * FROM matters WHERE id=?', [(int)$m['id']]) ?: $m;
    $steps = pt_milestones($m); $live = pt_odp($m);
    $prov = function_exists('is_provisional_matter') && is_provisional_matter($m); $design = is_design_matter($m);
    $headline = $headline !== '' ? $headline : pt_card_headline(trim((string)($ev['type'] ?? '') . ' ' . (string)($ev['doc_code'] ?? '')), $m);   // a 'Process USPTO Notice' to-do: its paper's code
    $title = clean_title((string)($m['title'] ?? '')) ?: clean_title((string)($live['title'] ?? ''));
    $filed = substr((string)(($m['filing_date'] ?? '') ?: ($live['filing_date'] ?? '')), 0, 10);
    $patNo = trim((string)($m['reg_no'] ?? '')); $grant = $patNo !== '' ? substr((string)($m['grant_date'] ?? ''), 0, 10) : '';
    $G = '#C9A227'; $GT = '#8a6d12'; $N = NAVY; $L = '#e2ddd2'; $BG = '#faf8f3'; $grey = fn($s) => ' <span style="color:#6b7280">&middot; ' . $e($s) . '</span>';
    $kicker = $patNo !== '' ? 'Your U.S. ' . ($design ? 'design patent' : 'patent') : ($prov ? 'Your U.S. provisional application' : 'Your U.S. ' . ($design ? 'design ' : '') . 'patent application');
    $titleCell = '<div style="font-size:17px;line-height:1.25;font-weight:bold;color:' . $N . '">' . $e($title !== '' ? $title : 'Patent application') . '</div>';
    $rows = [];
    if ($patNo !== '') $rows[] = ['U.S. Patent No.', $e(pt_fmt_patent($patNo)) . ($grant !== '' ? $grey(fmt_long($grant)) : '')];
    $rows[] = [$prov ? 'Provisional App. No.' : 'Application No.', $e(fmt_app((string)($m['app_no'] ?? ''), 'United States')) . ($filed !== '' ? $grey(fmt_long($filed)) : '')];
    if ($patNo === '' && !empty($live['pub_no'])) $rows[] = ['Publication No.', $e(pt_fmt_pub((string)$live['pub_no'])) . (!empty($live['pub_date']) ? $grey(fmt_long(substr($live['pub_date'], 0, 10))) : '')];
    [$aBefore, $aAfter] = assoc_card_rows($m);   // their file number above ours, the applicant below (2026-10-14)
    foreach ($aBefore as [$k, $v]) $rows[] = [$k, $e($v)];
    $rows[] = ['Our file number', $e((string)($m['ref'] ?? ''))];
    foreach ($aAfter as [$k, $v]) $rows[] = [$k, $e($v)];
    $info = ''; foreach ($rows as [$k, $v]) $info .= '<tr><td style="padding:2px 14px 2px 0;color:' . $GT . ';font-size:10px;text-transform:uppercase;letter-spacing:.07em;white-space:nowrap;vertical-align:top">' . $k . '</td><td style="padding:2px 0;color:' . $N . ';font-size:13px">' . $v . '</td></tr>';
    $n = count($steps); $tl = '<tr>'; $anyAbout = false;
    foreach ($steps as $i => [$lbl, $date, $st, $sub]) {
        if (str_starts_with((string)$date, 'about') || $date === 'expected soon') $anyAbout = true;
        $hl = in_array($st, ['now', 'renew', 'here'], true); $reached = in_array($st, ['done', 'here'], true);
        $dot = $st === 'done' ? 'background:' . $N . ';border:2px solid ' . $N : ($hl ? 'background:' . $G . ';border:2px solid ' . $G . ';box-shadow:0 0 0 3px #f3e6b8' : 'background:#fff;border:2px solid #cfc9bc');
        $lineL = $i > 0 ? 'border-top:2px solid ' . ($reached ? $N : $L) : 'border-top:2px solid transparent';
        $lineR = $i < $n - 1 ? 'border-top:2px solid ' . (in_array($steps[$i + 1][2], ['done', 'here'], true) ? $N : $L) : 'border-top:2px solid transparent';
        $tl .= '<td style="width:' . round(100 / $n, 2) . '%;text-align:center;vertical-align:top;padding:0 3px' . ($hl ? ';background:#FFF2CC;border-radius:6px' : '') . '">'
             . '<table cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse"><tr><td style="' . $lineL . ';width:50%;font-size:0;line-height:0">&nbsp;</td><td style="padding:0"><div style="width:12px;height:12px;border-radius:50%;' . $dot . ';margin:-7px auto 0"></div></td><td style="' . $lineR . ';width:50%;font-size:0;line-height:0">&nbsp;</td></tr></table>'
             . '<div style="font-size:11px;font-weight:bold;color:' . ($hl ? $GT : $N) . ';margin-top:5px;line-height:1.2">' . $e($lbl) . '</div>'
             . '<div style="font-size:11px;color:' . ($reached ? $N : '#6b7280') . ';margin-top:2px;line-height:1.3">' . $e($date) . '</div>'
             . ($sub !== '' ? '<div style="font-size:10px;color:#9a948a;margin-top:1px;line-height:1.3">' . $e($sub) . '</div>' : '') . '</td>';
    }
    $tl .= '</tr>';
    $foot = !firm('patent_guide_url') ? '' : '<a href="' . htmlspecialchars((string)firm('patent_guide_url')) . '" style="color:' . $N . ';font-weight:bold;text-decoration:none;border-bottom:1px solid ' . $G . '">How the patent process works &rarr;</a>'
          . ($anyAbout ? '<span style="color:#9a948a"> &nbsp;&middot;&nbsp; &ldquo;About&rdquo; dates are estimates from current USPTO processing times.</span>' : '');
    return '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;width:100%;max-width:600px;margin:4px 0 20px;font-family:Lexend,Arial,sans-serif;border:1px solid ' . $L . ';border-radius:8px;overflow:hidden;background:' . $BG . '">'
        . '<tr><td style="background:' . $N . ';color:#fff;padding:8px 18px;border-bottom:3px solid ' . $G . '"><span style="font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:' . $G . ';font-weight:bold">' . $e($kicker) . '</span> <span style="color:' . $G . '">&nbsp;&middot;&nbsp;</span> <span style="font-size:15px;font-weight:bold;letter-spacing:.01em">' . $e($headline) . '</span></td></tr>'
        . '<tr><td style="padding:10px 18px 2px">' . $titleCell . '</td></tr>'   // 2026-10-12: card too tall - tighter spacing
        . '<tr><td style="padding:4px 18px 10px"><table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse">' . $info . '</table></td></tr>'
        . ($n ? '<tr><td style="padding:13px 10px 8px;background:#fff;border-top:1px solid ' . $L . '"><table cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;table-layout:fixed">' . $tl . '</table></td></tr>' : '')
        . '<tr><td style="padding:6px 18px 8px;background:#fff;border-top:1px solid #f0ece3;font-size:11px;line-height:1.4">' . $foot . '</td></tr>'
        . '</table>';
}

/** The issue date announced by the Issue Notification, before the grant is on record: ['date', 'patent'] or null. From Pendant's issuance
 *  to-do (its mail_date is the issue date), else the notification's mail date (ODP history or Pendant's correspondence) -> issue_tuesday(). */
function pt_issue_expected(array $m, array $hist): ?array {
    $pat = preg_replace('/[^0-9A-Za-z]/', '', (string)($m['issue_patent_no'] ?? ''));
    if (!empty($m['id']) && defined('ISSUE_REPORT_EVENTS')) {
        try { $e = q1('SELECT mail_date FROM events WHERE matter_id=? AND type IN (?,?) AND status<>"cancelled" AND mail_date IS NOT NULL AND mail_date<>"" ORDER BY id DESC LIMIT 1', [(int)$m['id'], ISSUE_REPORT_EVENTS[0], ISSUE_REPORT_EVENTS[1]]); } catch (Throwable $x) { $e = null; }
        if ($e && preg_match('/^\d{4}-\d\d-\d\d/', (string)$e['mail_date'])) return ['date' => substr((string)$e['mail_date'], 0, 10), 'patent' => $pat];
    }
    $d = ''; foreach ($hist as $h) if (in_array($h['code'] ?? '', ['ISSUE.NTF', 'EISSUE.NTF'], true) && substr((string)$h['date'], 0, 10) > $d) $d = substr((string)$h['date'], 0, 10);
    if ($d === '' && !empty($m['id'])) { try { $d = substr((string)(q1('SELECT max(mail_date) d FROM correspondence WHERE matter_id=? AND doc_code IN ("ISSUE.NTF","EISSUE.NTF")', [(int)$m['id']])['d'] ?? ''), 0, 10); } catch (Throwable $x) { $d = ''; } }
    return $d !== '' && function_exists('issue_tuesday') ? ['date' => issue_tuesday($d), 'patent' => $pat] : null;
}
