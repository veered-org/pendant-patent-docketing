<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * Issued patents on the Dashboard's registration panel (2026-10-04: "registration reports to send - add patent registrations there too.
 * there's a letter, no invoice."). Same cards as the trademark registrations (lib/regreport.php): one per U.S. patent issued since REG_SINCE,
 * with its "Report Patent Issuance" to-do (lib/issuance.php writes the eGrant + the XYZ005-style letter on issue day; the letter has no invoice line).
 *
 *  - pat_rows()     issued U.S. patents since REG_SINCE (or with an issuance to-do), with the to-do, how it went out, eGrant / letter in the folder.
 *  - pat_card()     the card: title, patent no. + issue date, application no., timeline (maintenance windows; designs: term end), papers, actions.
 *  - pat_sweep()    hourly with reg_sweep: a patent with no to-do gets one: closed at once when Gmail shows the report already went out,
 *                   open when the letter is already in the folder, else prepare_issuance() (eGrant + letter + to-do). Open to-dos found
 *                   reported by hand in Gmail are closed.
 */
/**
 * Is the firm still of record at the USPTO? (2026-10-04: "you sure XYZ022 wasn't taken over by another attorney?" - it was: Westbridge IP,
 * customer 150359.) Patents: the Open Data Portal customer number (firm customer_numbers) or a correspondence address naming the firm;
 * trademarks: TSDR's correspondent (attorney / firm / e-mail). Returns '' when ours or unknown, else the other firm's name; remembered in
 * matters.other_firm so the cards can warn without asking the USPTO again. No report is queued for a property another firm handles.
 */
function firm_of_record(array $m): string {
    // the attorney's per-matter approvals (2026-10-04: "XYZ019 courtesy yes"): another firm is of record, but he wants the client report sent anyway
    $ok = json_decode((string)(q1("SELECT value FROM settings WHERE key='firm_of_record_ok'")['value'] ?? ''), true);
    if (is_array($ok) && !empty($m['ref']) && isset($ok[(string)$m['ref']])) { if (!empty($m['id'])) run("UPDATE matters SET other_firm='' WHERE id=?", [(int)$m['id']]); return ''; }
    $n = preg_replace('/\D/', '', (string)($m['app_no'] ?? '')); if ($n === '' || !function_exists('http_json')) return '';
    $other = '';
    try {
        if (($m['kind'] ?? '') === 'patent') {
            if (empty($GLOBALS['cfg']['odp_key'])) return '';
            $r = http_json("https://api.uspto.gov/api/v1/patent/applications/$n", ['X-API-KEY: ' . $GLOBALS['cfg']['odp_key']]); $b = $r['json']['patentFileWrapperDataBag'][0] ?? null; if (!$b) return '';   // unpublished: unknown
            $cn = (string)($b['applicationMetaData']['customerNumber'] ?? ''); $cor = trim((string)($b['correspondenceAddressBag'][0]['nameLineOneText'] ?? ''));
            if (!in_array($cn, firm('customer_numbers'), true) && !preg_match(firm('of_record_re'), $cor) && ($cn !== '' || $cor !== '')) $other = ($cor !== '' ? $cor : 'another firm') . ($cn !== '' ? ' (customer ' . $cn . ')' : '');
        } else {
            if (empty($GLOBALS['cfg']['tsdr_key'])) return '';
            $r = http_json("https://tsdrapi.uspto.gov/ts/cd/casestatus/sn$n/info.json", ['USPTO-API-KEY: ' . $GLOBALS['cfg']['tsdr_key']]); $c = $r['json']['trademarks'][0]['status']['correspondence'] ?? null; if (!$c) return '';
            $s = implode(' ', array_merge([(string)($c['attorneyName'] ?? ''), (string)($c['firmName'] ?? ''), (string)($c['individualFullName'] ?? '')], $c['correspondantEmail']['addresses'] ?? [], $c['attorneyEmail']['addresses'] ?? []));
            // XYZ003/XYZ005: the earlier attorney WITHDREW (2025-10-10) and TSDR still shows the name; when the newest attorney / correspondence
            // entry is a withdrawal, the named correspondent is stale, not another firm of record
            $att = array_values(array_filter($r['json']['trademarks'][0]['prosecutionHistory'] ?? [], fn($h) => preg_match('/ATTORNEY|CORRESPOND|REPRESENT|POWER OF/i', (string)($h['entryDesc'] ?? ''))));
            usort($att, fn($a, $b) => strcmp((string)$b['entryDate'], (string)$a['entryDate']));
            if ($att && preg_match('/WITHDRAWAL OF ATTORNEY/i', (string)$att[0]['entryDesc'])) $s = '';
            if (trim($s) !== '' && !preg_match(firm('of_record_re'), $s)) $other = trim(implode(', ', array_filter([(string)($c['attorneyName'] ?? ''), (string)($c['firmName'] ?? '')]))) ?: 'another correspondent';
        }
    } catch (Throwable $e) { return ''; }
    if (!empty($m['id'])) run('UPDATE matters SET other_firm=?, other_firm_at=? WHERE id=?', [$other, date('Y-m-d'), (int)$m['id']]);
    return $other;
}
/** For the nightly reminder queue (2026-10-04 audit, JJi037): another firm of record? Asks the USPTO at most weekly per matter, else the stored answer. */
function other_firm_cached(int $matterId): string {
    $m = q1('SELECT * FROM matters WHERE id=?', [$matterId]); if (!$m || !preg_match('/^(|United States|US|USA)$/i', trim((string)($m['country'] ?? '')))) return '';
    if ((string)($m['other_firm_at'] ?? '') >= add_days(today(), -7, false)) return trim((string)($m['other_firm'] ?? ''));
    try { $o = firm_of_record($m); usleep(500000); return $o; } catch (Throwable $e) { return trim((string)($m['other_firm'] ?? '')); }
}
/** The card's warning when another firm is of record. */
function other_firm_warning(array $r): string { return trim((string)($r['other_firm'] ?? '')) !== '' ? '<p class="pub-warn">Another firm is of record at the USPTO: <b>' . h((string)$r['other_firm']) . '</b>. Check before sending; mark the matter inactive if it is no longer ours.</p>' : ''; }
function pat_no(array $m): string { return (string)(($m['issue_patent_no'] ?? '') ?: ($m['reg_no'] ?? '')); }
function pat_is_design(array $m): bool { return is_design_matter($m) || preg_match('/^D/i', trim(pat_no($m))); }
/** eGrant / letter in the matter folder: ['dir', 'grant', 'letter' (PDF), 'letter_doc']. */
function pat_files(array $m): array {
    $out = ['dir' => null, 'grant' => '', 'letter' => '', 'letter_doc' => ''];
    if (!function_exists('mf_find') || !($d = mf_find((string)$m['ref']))) return $out;
    $out['dir'] = $d;
    foreach (mf_list($d) as $f) {   // newest first
        $n = (string)$f['name'];
        if (preg_match('/Issuance[ _-]?Letter/i', $n)) { if (preg_match('/\.pdf$/i', $n)) { if ($out['letter'] === '') $out['letter'] = $n; } elseif ($out['letter_doc'] === '' && preg_match('/\.docx?$/i', $n)) $out['letter_doc'] = $n; }
        elseif ($out['grant'] === '' && preg_match('/\.pdf$/i', $n) && preg_match('/U\.?\s?S\.? Patent (No\.? )?D?[\d,]{6,}|eGrant|Patent Grant|Letters Patent/i', $n)) $out['grant'] = $n;
    }
    return $out;
}
/** eGrant + letter PDF for the send page at any age (the generic list only offers the last 45 days), pre-ticked. */
function pat_attachables(array $m): array {
    $pf = pat_files($m); $out = []; if (!$pf['dir']) return [];
    foreach (mf_list($pf['dir']) as $f) if ($f['name'] !== '' && in_array($f['name'], [$pf['grant'], $pf['letter']], true))
        $out[] = ['path' => $pf['dir']['mode'] === 'local' ? mf_local($pf['dir'], $f['rel']) : '', 'name' => $f['name'], 'mtime' => $f['mtime'], 'size' => $f['size'], 'folder' => $pf['dir'], 'rel' => $f['rel']];
    return $out;
}
function pat_rows(): array {
    $out = []; if (!defined('ISSUANCE_REPORT_EVENT')) return $out;
    $rows = q("SELECT m.*, cl.name client_name FROM matters m LEFT JOIN clients cl ON cl.code=m.client_code
               WHERE m.kind='patent' AND (m.country IN ('', 'United States', 'US', 'USA') OR m.country IS NULL)
                 AND ((m.grant_date >= ? AND (trim(m.reg_no)<>'' OR trim(m.issue_patent_no)<>'')) OR m.id IN (SELECT matter_id FROM events WHERE type=? AND status<>'cancelled'))", [REG_SINCE, ISSUANCE_REPORT_EVENT]);
    foreach ($rows as $r) {
        $r['is_patent'] = true; $r['reg_date'] = substr((string)$r['grant_date'], 0, 10);
        $r['todo'] = q1('SELECT * FROM events WHERE matter_id=? AND type=? AND status<>"cancelled" ORDER BY id DESC LIMIT 1', [$r['id'], ISSUANCE_REPORT_EVENT]);
        if ($r['reg_date'] === '' && $r['todo']) $r['reg_date'] = (string)$r['todo']['mail_date'];
        if ($r['reg_date'] === '') continue;
        $r['sent'] = $r['todo'] ? q1("SELECT * FROM outbox WHERE event_id=? AND mode='sent' ORDER BY id DESC LIMIT 1", [$r['todo']['id']]) : null;
        $t = $r['todo']; $r['state'] = !empty($r['dead']) && (!$t || $t['status'] === 'open') ? 'inactive' : (!$t ? 'none' : ($t['status'] === 'open' ? 'tosend' : ($r['sent'] ? 'sent' : 'byhand')));
        $out[] = $r;
    }
    return $out;
}
function pat_open_count(): int {
    if (!defined('ISSUANCE_REPORT_EVENT')) return 0;
    return (int)(q1("SELECT count(*) c FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.type=? AND e.status='open' AND m.dead=0", [ISSUANCE_REPORT_EVENT])['c'] ?? 0);
}
/** Maintenance windows from the issue date (35 USC 41(b)): [label, opens, due, grace]. */
function pat_windows(string $issued): array {
    $o = []; foreach ([[3, '3½-year fee'], [7, '7½-year fee'], [11, '11½-year fee']] as [$y, $lbl]) $o[] = [$lbl, add_months($issued, $y * 12, false), add_months($issued, $y * 12 + 6, false), add_months($issued, $y * 12 + 12, false)];
    return $o;
}
function pat_card(array $r): string {
    $t = today(); $todo = $r['todo']; $back = url('dash', ['show' => 'reg']); $iss = $r['reg_date']; $design = pat_is_design($r);
    $chip = ['tosend' => ['Email to send', 'amber'], 'sent' => ['Reported', 'green'], 'byhand' => ['Reported', 'green'], 'none' => ['No report queued', 'grey'], 'inactive' => ['Inactive', 'grey']][$r['state']];
    $filed = substr((string)$r['filing_date'], 0, 10); $pn = pat_no($r); $title = clean_title((string)$r['title']);
    $steps = pub_step('Filed', $filed !== '' ? fmt_date($filed) : 'date unknown', true, false) . pub_step('Issued', fmt_date($iss), true, false);
    if ($design) { $end = add_months($iss, design_term_years($r, $iss) * 12, false); $steps .= pub_step('Term ends', fmt_date($end), false, true, design_term_years($r, $iss) . ' years, no fees'); $says = 'Issued on ' . fmt_long($iss) . '; design patent, no maintenance fees; in force until ' . fmt_long($end) . '.'; }
    else { $w = pat_windows($iss); $next = null; foreach ($w as $x) if ($x[3] >= $t) { $next = $x; break; }
        foreach (array_slice($w, 0, 2) as $x) $steps .= pub_step($x[0], fmt_date($x[1]) . ' – ' . fmt_date($x[2]), $x[3] < $t, $next === $x, 'window');
        $says = 'Issued on ' . fmt_long($iss) . ($next ? '; the ' . $next[0] . ' can be paid between ' . fmt_long($next[1]) . ' and ' . fmt_long($next[2]) . '.' : '.'); }
    $pf = pat_files($r);
    $papers = '<ul class="reg-papers">'
        . '<li class="' . ($pf['grant'] !== '' ? 'ok' : 'no') . '"><b>eGrant</b> ' . ($pf['grant'] !== '' ? mf_view_link((string)$r['ref'], $pf['grant']) : (!$pf['dir'] ? 'no ' . h($r['ref']) . ' folder in Dropbox' : 'not in the folder yet')) . '</li>'
        . '<li class="' . ($pf['letter'] !== '' ? 'ok' : ($pf['letter_doc'] !== '' ? 'part' : 'no')) . '"><b>Issuance letter</b> ' . ($pf['letter'] !== '' ? mf_view_link((string)$r['ref'], $pf['letter']) : ($pf['letter_doc'] !== '' ? mf_view_link((string)$r['ref'], $pf['letter_doc']) . ' (save it as PDF to attach it)' : 'not in the folder')) . '</li></ul>';
    $how = '';
    if ($r['state'] === 'sent') $how = 'Emailed ' . h(fmt_date(substr((string)($r['sent']['transmitted_at'] ?: $r['sent']['created_at']), 0, 10))) . ' by ' . h((string)$r['sent']['from_user']) . ' to ' . h((string)$r['sent']['to_email']);
    elseif ($r['state'] === 'byhand') $how = h(preg_replace('/^.*\|\s*/', '', (string)$todo['note']) ?: 'Marked reported');
    $acts = '';
    if ($r['state'] === 'tosend') $acts = '<a class="btn gold" href="' . h(url('send', ['id' => $todo['id'], 'back' => $back])) . '">Preview &amp; send</a> '
        . btn('reg_reported', ['id' => $todo['id'], 'back' => $back], 'Already reported', 'small ghost', 'Mark the ' . $r['ref'] . ' patent issuance report as already sent to the client (outside Pendant)?');
    elseif ($r['state'] === 'sent') $acts = '<a class="btn small ghost" href="' . h(url('send', ['id' => $todo['id'], 'ob' => $r['sent']['id'], 'back' => $back])) . '">View email</a>';
    $digits = preg_replace('/[^\dD]/i', '', $pn);
    return '<article class="pub-card ' . $r['state'] . '">'
        . '<div class="pub-top"><a class="pub-ref" href="' . h(url('matter', ['id' => $r['id']])) . '">' . h($r['ref']) . '</a><span class="pub-client">' . h(trunc((string)($r['client_name'] ?: $r['owner'] ?: ''), 42)) . '</span><span class="pub-chip ' . $chip[1] . '">' . $chip[0] . '</span></div>'
        . '<div class="pub-mark"><div class="pub-kind">' . ($design ? 'Design patent' : 'Utility patent') . '</div><div class="pub-markname pat">' . h(trunc($title ?: 'Patent', 110)) . '</div></div>'
        . '<div class="pub-facts">' . ($digits !== '' ? '<a href="https://ppubs.uspto.gov/pubwebapp/external.html?q=' . h($digits) . '.pn." target="_blank" rel="noopener"><b>U.S. Patent ' . h(fmt_patent_no($pn)) . '</b></a>' : '<b>U.S. Patent (number pending)</b>') . '<span>issued ' . h(fmt_long($iss)) . '</span>'
        . (trim((string)$r['app_no']) !== '' ? '<span>Appl. ' . h(fmt_app((string)$r['app_no'], 'United States')) . '</span>' : '') . '<span class="pub-basis ' . ($design ? 'madrid' : 'foreign') . '">' . ($design ? 'Design' : 'Utility') . '</span></div>'
        . '<ol class="pub-line">' . $steps . '</ol>' . $papers . other_firm_warning($r)
        . '<p class="pub-says"><span>The email tells the client</span>' . h($says) . ' eGrant and issuance letter attached; no invoice.</p>'
        . '<div class="pub-foot">' . ($how !== '' ? '<span class="pub-how">' . $how . '</span>' : '') . '<span class="pub-acts">' . $acts . '</span></div></article>';
}
/** The patent issuance report already went to the client outside Pendant: a firm address to someone outside, about this patent, on or after the issue date. */
function pat_found_in_gmail(array $m, string $issued): ?array {
    if (!function_exists('gmail_cfg') || !($c = gmail_cfg())) return null;
    $ref = trim((string)$m['ref']); if (!preg_match('/^[A-Z0-9.\-]{3,}$/i', $ref)) return null;
    $pn = preg_replace('/[^\dD]/i', '', pat_no($m)); $pnFmt = $pn !== '' ? fmt_patent_no($pn) : '';
    $ids = array_values(array_filter([$ref, $pn, $pnFmt]));
    $after = strtotime($issued); $c['max_threads'] = 25;   // from the issue day: earlier mail is the Issue Notification
    $q = 'from:(' . firm_from_query() . ') -subject:("Docket mail") after:' . date('Y/m/d', $after) . ' (' . implode(' OR ', array_map(fn($x) => '"' . $x . '"', $ids)) . ') (issued OR issuance OR eGrant OR "Patent No" OR "Patent Number" OR certificate OR granted OR "full report")';
    $best = null;
    foreach (gm_search_threads($q, $c) as $tid) {
        $res = gm_api('/users/me/threads/' . rawurlencode($tid), ['format' => 'metadata', 'metadataHeaders' => ['From', 'To', 'Cc', 'Subject']], $c);
        foreach ($res['messages'] ?? [] as $msg) {
            if (in_array('DRAFT', $msg['labelIds'] ?? [], true)) continue;
            $ts = (int)(($msg['internalDate'] ?? 0) / 1000); if ($ts < $after) continue;
            $H = []; foreach ($msg['payload']['headers'] ?? [] as $hd) $H[strtolower($hd['name'])] = (string)$hd['value'];
            $subj = (string)($H['subject'] ?? ''); $snip = html_entity_decode((string)($msg['snippet'] ?? ''), ENT_QUOTES); $text = $subj . ' ' . $snip;
            $about = stripos($subj, $ref) !== false || ($pn !== '' && (str_contains(preg_replace('/[^\dD]/i', '', $text), $pn) || str_contains($text, $pnFmt)));
            // the firm's own words (XYZ035 "patent certificate and my full report letter", XYZ005 "has been granted", XYZ001 "official Patent Certificate");
            // the subject is often the old thread's ("- Notice of Allowance"), so only the message itself rules out the Issue Notification / allowance mail
            if (!$about || !preg_match('/issu|eGrant|Patent No|Patent Number|certificate|granted|full report|report letter/i', $text) || preg_match('/will mature|Issue Notification document|issue fee|Filing Receipt/i', $snip)) continue;
            $from = hdr_addresses($H['from'] ?? ''); if (!$from || !firm_own_address($from[0])) continue;
            $to = array_values(array_filter(array_merge(hdr_addresses($H['to'] ?? ''), hdr_addresses($H['cc'] ?? '')), fn($a) => !firm_address($a)));
            if ($to && (!$best || $ts < $best['ts'])) $best = ['date' => date('Y-m-d', $ts), 'from' => $from[0], 'to' => $to, 'subject' => $subj, 'ts' => $ts];
        }
    }
    return $best;
}
function pat_sweep(bool $gmail = true): array {
    $log = []; if (!defined('ISSUANCE_REPORT_EVENT')) return $log;
    foreach (pat_rows() as $r) {
        if ($r['state'] === 'inactive') continue;
        if (in_array($r['state'], ['none', 'tosend'], true) && ($of = firm_of_record($r)) !== '') { $log[] = $r['ref'] . ': another firm of record (' . $of . ')' . ($r['state'] === 'none' ? '; no report queued' : '; the open report is flagged on its card'); usleep(600000); if ($r['state'] === 'none') continue; }
        $found = null;
        if ($gmail && in_array($r['state'], ['none', 'tosend'], true)) { try { $found = pat_found_in_gmail($r, $r['reg_date']); } catch (Throwable $e) { $log[] = $r['ref'] . ': Gmail ' . $e->getMessage(); } }
        $note = $found ? 'Reported by email ' . fmt_date($found['date']) . ' by ' . preg_replace('/@.*/', '', $found['from']) . ' to ' . implode(', ', $found['to']) . ' (found in Gmail: "' . substr($found['subject'], 0, 70) . '")' : '';
        if ($r['state'] === 'none') {
            $pf = pat_files($r);
            if ($found || $pf['letter'] !== '' || $pf['letter_doc'] !== '') {   // reported already, or the letter is written: the to-do without a new letter
                run('INSERT INTO events(matter_id,type,due_date,status,source,doc_code,mail_date,note,done_at,done_by) VALUES (?,?,?,?,"rule","EGRANT.PDF",?,?,?,?)',
                    [$r['id'], ISSUANCE_REPORT_EVENT, today(), $found ? 'done' : 'open', $r['reg_date'], $found ? $note : 'Email the client: U.S. Patent ' . fmt_patent_no(pat_no($r)) . ' issued ' . fmt_date($r['reg_date']) . '. The issuance letter is in the folder (' . ($pf['letter'] ?: $pf['letter_doc']) . ').', $found ? date('Y-m-d H:i:s') : null, $found ? preg_replace('/@.*/', '', $found['from']) : null]);
                if (function_exists('close_expected_issue')) close_expected_issue((int)$r['id'], (string)$r['reg_date']);
                audit('issuance', $r['ref'] . ' patent issuance report ' . ($found ? 'already sent by hand ' . $found['date'] : 'queued (letter in the folder)'), (int)$r['id']);
                $log[] = $r['ref'] . ($found ? ': already reported by hand ' . $found['date'] . ' (' . $found['from'] . ')' : ': client email queued (letter already in the folder)');
            } elseif ($pf['dir'] && function_exists('prepare_issuance')) {
                try { $l = prepare_issuance($r); $log[] = $l !== '' ? $l : $r['ref'] . ': eGrant not available yet'; } catch (Throwable $e) { $log[] = $r['ref'] . ': issuance FAILED ' . $e->getMessage(); }
            } else $log[] = $r['ref'] . ': no ' . $r['ref'] . ' folder in Dropbox; nothing prepared';
            continue;
        }
        if ($r['state'] === 'tosend' && $found) {
            run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=? AND status="open"', [preg_replace('/@.*/', '', $found['from']), $note, $r['todo']['id']]);
            audit('issuance', $r['ref'] . ' patent issuance report already sent by hand ' . $found['date'] . ': to-do closed', (int)$r['id']);
            $log[] = $r['ref'] . ': already reported by hand ' . $found['date'] . ' (' . $found['from'] . '), closed';
        }
    }
    return $log;
}
