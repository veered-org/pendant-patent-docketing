<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * Trademark registrations (2026-10-04): "also need to add into the Dashboard sending trademark issuance notices and copies of the
 * trademark certificates that have not yet been sent to clients. go back to june 16th, 2026 and check."
 *
 * The firm's practice (Gmail, 2026-09): the paralegal drafts the Issuance Letter from Templates/Trademark Issuance Letter.doc into the matter
 * folder (REF-02b-Issuance Letter.pdf, beside REF-02a-Trademark Registration.pdf); staff sends a short covering email with both attached
 * ("Congratulations! We are pleased to report that the above-referenced trademark has been registered. Attached, please find the
 * Registration Certificate, as well as our letter with more details regarding maintaining your trademark rights." XYZ001, 2026-09-22), and
 * the issuance invoice separately. the attorney's own XYZ002 email (2026-09-04) adds the Section 8 window and the ® sentence.
 *
 *  - reg_rows()        every US registration since REG_SINCE with its report to-do, certificate / letter in the folder, and how it went out.
 *  - reg_panel()       the Dashboard panel (?p=dash&show=reg): one card per mark, Preview & send / Already reported / Queue.
 *  - reg_fill()        {REG_BLOCK} {REG_MAINT} {REG_DATE} {LETTER_CLAUSE} for the 'Report Trademark Registration' email.
 *  - reg_sweep()       hourly: queue a missing report, ask oa_fetch for a certificate that is not in the folder, close a report the client
 *                      already got by hand (Gmail: a firm address to someone outside, about this mark, on or after the registration date).
 * Nothing is ever sent by Madder on its own: the to-do waits for someone to click Send on the preview.
 */
const REG_REPORT = 'Report Trademark Registration';
const REG_SINCE = '2026-06-16';   // 2026-10-04: "go back to june 16th, 2026"

/** Madrid 66(a) registrations are maintained by a Section 71 declaration, everything else by Section 8. */
function reg_is_madrid(array $m): bool { return str_starts_with(preg_replace('/\D/', '', (string)($m['app_no'] ?? '')), '79') || str_contains(strtolower((string)($m['basis'] ?? '')), '66a'); }
/** The first maintenance window: opens on the 5th anniversary of registration, due by the 6th, six-month grace after (calendar dates, no roll). */
function reg_window(string $reg, bool $madrid = false): array {
    return ['section' => $madrid ? 'Section 71' : 'Section 8', 'opens' => add_months($reg, 60, false), 'due' => add_months($reg, 72, false), 'grace' => add_months($reg, 78, false)];
}
/** The registration certificate among a folder's file names (PDF): "REF-02a-Trademark Registration.pdf", "REF-03a-Registration Certificate of 2026.09.29.pdf". */
function reg_is_cert_name(string $n): bool {
    return (bool)preg_match('/\.pdf$/i', $n) && (bool)preg_match('/Trademark Registration|Registration Certificate|Certificate of Registration|Reg(istration)?\.? ?Cert/i', $n)
        && !preg_match('/^CA-|Madrid|International|Section|Renewal|Combined|Declaration|Notice of|Letter|Application/i', $n);
}
/** The issuance letter the paralegal (or the 2026-08-18 generator) saved: "REF-02b-Issuance Letter.pdf", "...-02b-Issuance_Letter.pdf", "...Trademark Issuance Letter (AI written...).pdf". */
function reg_is_letter_name(string $n): bool { return (bool)preg_match('/Issuance[ _-]?Letter|Registration[ _-]?Letter/i', $n); }
/** What the matter folder holds: ['dir' => handle|null, 'cert' => name|'', 'letter' => PDF name|'', 'letter_doc' => .doc/.docx name|'']. */
function reg_files(array $m): array {
    $out = ['dir' => null, 'cert' => '', 'letter' => '', 'letter_doc' => ''];
    if (!function_exists('mf_find') || !($d = mf_find((string)$m['ref']))) return $out;
    $out['dir'] = $d;
    foreach (mf_list($d) as $f) {   // newest first
        $n = (string)$f['name'];
        if ($out['cert'] === '' && reg_is_cert_name($n)) $out['cert'] = $n;
        if (reg_is_letter_name($n)) { if (preg_match('/\.pdf$/i', $n)) { if ($out['letter'] === '') $out['letter'] = $n; } elseif ($out['letter_doc'] === '' && preg_match('/\.docx?$/i', $n)) $out['letter_doc'] = $n; }
    }
    return $out;
}
/** Folder files for the send page: certificate + letter PDF, any age (matter_files only offers the last 45 days). */
function reg_attachables(array $m): array {
    $rf = reg_files($m); $out = [];
    if (!$rf['dir']) return [];
    foreach (mf_list($rf['dir']) as $f) if (in_array($f['name'], [$rf['cert'], $rf['letter']], true) && $f['name'] !== '')
        $out[] = ['path' => $rf['dir']['mode'] === 'local' ? mf_local($rf['dir'], $f['rel']) : '', 'name' => $f['name'], 'mtime' => $f['mtime'], 'size' => $f['size'], 'folder' => $rf['dir'], 'rel' => $f['rel']];
    return $out;
}

/** The registration date Madder holds for a matter (TSDR-verified at intake, or by the 2026-10-04 backfill). */
function reg_date_of(array $m): string {
    $g = substr((string)($m['grant_date'] ?? ''), 0, 10); if ($g !== '') return $g;
    return (string)(q1("SELECT mail_date FROM correspondence WHERE matter_id=? AND doc_code='REGISTRATION' ORDER BY mail_date DESC LIMIT 1", [$m['id']])['mail_date'] ?? '');
}
/** Every US registration since REG_SINCE, newest first, with its report to-do and what became of it. */
function reg_rows(): array {
    $out = [];
    $rows = q("SELECT m.*, cl.name client_name FROM matters m LEFT JOIN clients cl ON cl.code=m.client_code
               WHERE m.kind='trademark' AND trim(m.reg_no)<>'' AND (m.country IN ('', 'United States', 'US', 'USA') OR m.country IS NULL)
                 AND (m.grant_date >= ? OR m.id IN (SELECT matter_id FROM correspondence WHERE doc_code='REGISTRATION' AND mail_date >= ?)
                      OR m.id IN (SELECT matter_id FROM events WHERE type=? AND mail_date >= ?))", [REG_SINCE, REG_SINCE, REG_REPORT, REG_SINCE]);
    foreach ($rows as $r) {
        $r['reg_date'] = reg_date_of($r); if ($r['reg_date'] === '') continue;
        $r['todo'] = q1('SELECT * FROM events WHERE matter_id=? AND type=? AND status<>"cancelled" ORDER BY id DESC LIMIT 1', [$r['id'], REG_REPORT]);
        if ($r['reg_date'] < REG_SINCE && !$r['todo']) continue;
        $r['sent'] = $r['todo'] ? q1("SELECT * FROM outbox WHERE event_id=? AND mode='sent' ORDER BY id DESC LIMIT 1", [$r['todo']['id']]) : null;
        $r['cid'] = (int)(q1("SELECT id FROM correspondence WHERE matter_id=? AND doc_code='REGISTRATION' ORDER BY mail_date DESC, id DESC LIMIT 1", [$r['id']])['id'] ?? 0);
        $r['story'] = pub_story_for($r);
        $t = $r['todo']; $r['state'] = !empty($r['dead']) && (!$t || $t['status'] === 'open') ? 'inactive' : (!$t ? 'none' : ($t['status'] === 'open' ? 'tosend' : ($r['sent'] ? 'sent' : 'byhand')));
        $out[] = $r;
    }
    usort($out, fn($a, $b) => [$b['reg_date'], $a['ref']] <=> [$a['reg_date'], $b['ref']]);
    return $out;
}
function reg_open_count(): int {
    return (function_exists('pat_open_count') ? pat_open_count() : 0) + (int)(q1("SELECT count(*) c FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.type=? AND e.status='open' AND m.dead=0", [REG_REPORT])['c'] ?? 0);
}

function reg_card(array $r): string {
    $t = today(); [, $blabel, $bcls] = $r['story']; $todo = $r['todo']; $back = url('dash', ['show' => 'reg']);
    $logo = logo_serial($r); $title = clean_title((string)$r['title']);
    $mark = $logo !== '' ? '<img class="pub-logo" src="' . h(logo_url($logo)) . '" alt="' . h($title ?: 'design mark') . '" onerror="this.nextElementSibling&&this.nextElementSibling.classList.remove(\'sm\');this.remove()">' . ($title !== '' ? '<div class="pub-markname sm">' . h($title) . '</div>' : '')
                         : '<div class="pub-markname">' . h($title ?: 'design mark') . '</div>';
    $chip = ['tosend' => ['Email to send', 'amber'], 'sent' => ['Reported', 'green'], 'byhand' => ['Reported', 'green'], 'none' => ['No report queued', 'grey'], 'inactive' => ['Inactive', 'grey']][$r['state']];
    $reg = $r['reg_date']; $w = reg_window($reg, reg_is_madrid($r)); $filed = substr((string)$r['filing_date'], 0, 10);
    $steps = pub_step('Filed', $filed !== '' ? fmt_date($filed) : 'date unknown', true, false)
           . pub_step('Registered', fmt_date($reg), true, $w['opens'] > $t)
           . pub_step($w['section'] . ' opens', fmt_date($w['opens']), $w['opens'] <= $t, $w['opens'] <= $t && $w['due'] >= $t, '5th year')
           . pub_step($w['section'] . ' due', fmt_date($w['due']), $w['due'] < $t, false, '6th year');
    $rf = reg_files($r); $sn = preg_replace('/\D/', '', (string)$r['app_no']); $regNo = preg_replace('/\D/', '', (string)$r['reg_no']);
    $ofa = $rf['cert'] === '' && $r['cid'] ? q1('SELECT status, note, fetched_at FROM oa_files WHERE correspondence_id=?', [$r['cid']]) : null;
    $papers = '<ul class="reg-papers">'
        . '<li class="' . ($rf['cert'] !== '' ? 'ok' : 'no') . '"><b>Certificate</b> ' . ($rf['cert'] !== '' ? mf_view_link((string)$r['ref'], $rf['cert'])
            : (!$rf['dir'] ? 'no ' . h($r['ref']) . ' folder in Dropbox' : fetch_now_html($r['cid'] ? (int)$r['cid'] : null, 'certificate', url('dash', ['show' => 'reg']), 'span'))) . '</li>'
        . '<li class="' . ($rf['letter'] !== '' ? 'ok' : ($rf['letter_doc'] !== '' ? 'part' : 'no')) . '"><b>Issuance letter</b> ' . ($rf['letter'] !== '' ? mf_view_link((string)$r['ref'], $rf['letter']) : ($rf['letter_doc'] !== '' ? mf_view_link((string)$r['ref'], $rf['letter_doc']) . ' (save it as PDF to attach it)' : 'not in the folder (the email can go without it)')) . '</li></ul>';
    $how = '';
    if ($r['state'] === 'sent') $how = 'Emailed ' . h(fmt_date(substr((string)($r['sent']['transmitted_at'] ?: $r['sent']['created_at']), 0, 10))) . ' by ' . h((string)$r['sent']['from_user']) . ' to ' . h((string)$r['sent']['to_email']);
    elseif ($r['state'] === 'byhand') $how = h(preg_replace('/^.*\|\s*/', '', (string)$todo['note']) ?: 'Marked reported');
    $acts = '';
    if ($r['state'] === 'tosend') {
        $acts = '<a class="btn gold" href="' . h(url('send', ['id' => $todo['id'], 'back' => $back])) . '">Preview &amp; send</a> '
              . btn('reg_reported', ['id' => $todo['id'], 'back' => $back], 'Already reported', 'small ghost', 'Mark the ' . $r['ref'] . ' registration report as already sent to the client (outside Madder)?');
    } elseif (in_array($r['state'], ['none', 'inactive'], true)) $acts = btn('reg_queue', ['matter_id' => $r['id'], 'back' => $back], 'Queue the client email', 'small');
    elseif ($r['state'] === 'sent') $acts = '<a class="btn small ghost" href="' . h(url('send', ['id' => $todo['id'], 'ob' => $r['sent']['id'], 'back' => $back])) . '">View email</a>';
    return '<article class="pub-card ' . $r['state'] . '">'
        . '<div class="pub-top"><a class="pub-ref" href="' . h(url('matter', ['id' => $r['id']])) . '">' . h($r['ref']) . '</a><span class="pub-client">' . h(trunc((string)($r['client_name'] ?: $r['owner'] ?: ''), 42)) . '</span><span class="pub-chip ' . $chip[1] . '">' . $chip[0] . '</span></div>'
        . '<div class="pub-mark">' . $mark . '</div>'
        . '<div class="pub-facts"><a href="' . h(tsdr_url($regNo, true)) . '" target="_blank" rel="noopener"><b>Reg. No. US ' . h(fmt_reg($regNo)) . '</b></a><span>registered ' . h(fmt_long($reg)) . '</span>'
        . ($sn !== '' ? '<a href="' . h(tsdr_url($sn)) . '" target="_blank" rel="noopener">SN ' . h(fmt_app((string)$r['app_no'], 'United States')) . '</a>' : '') . '<span class="pub-basis ' . $bcls . '">' . h($blabel) . '</span></div>'
        . '<ol class="pub-line">' . $steps . '</ol>' . $papers . (function_exists('other_firm_warning') ? other_firm_warning($r) : '')
        . '<p class="pub-says"><span>The email tells the client</span>Registered on ' . h(fmt_long($reg)) . ', certificate attached' . ($rf['letter'] !== '' ? ' with the issuance letter' : '') . '; the ' . h($w['section']) . ' declaration is due between ' . h(fmt_long($w['opens'])) . ' and ' . h(fmt_long($w['due'])) . '.</p>'
        . '<div class="pub-foot">' . ($how !== '' ? '<span class="pub-how">' . $how . '</span>' : '') . '<span class="pub-acts">' . $acts . '</span></div></article>';
}
function reg_panel(): string {
    $rows = array_merge(reg_rows(), function_exists('pat_rows') ? pat_rows() : []); usort($rows, fn($a, $b) => [$b['reg_date'], $a['ref']] <=> [$a['reg_date'], $b['ref']]);   // + issued patents (lib/patreport.php)
    $by = ['tosend' => [], 'done' => []];
    foreach ($rows as $r) $by[in_array($r['state'], ['tosend', 'none'], true) ? 'tosend' : 'done'][] = $r;
    $o = '<section class="pub"><div class="pub-head"><div><h2>Trademark registrations &amp; issued patents</h2><p class="muted">Every U.S. trademark registration and patent issued since ' . h(fmt_long(REG_SINCE)) . '. Patents: the eGrant and the issuance letter attached, no invoice. Trademarks: one client email per mark, with the registration certificate (saved into the folder from TSDR) and the issuance letter attached when it is in the folder, the ' . 'Section 8 window filled in, and the USPTO notice forwarded below the signature. Reports already sent by hand are found in Gmail and closed. Nothing is sent until you click Send on the preview.</p></div>'
       . '<div class="pub-tools">' . btn('reg_sweep', ['back' => url('dash', ['show' => 'reg'])], 'Check Gmail for reports sent by hand', 'small ghost') . '</div></div>';
    $o .= '<h3 class="pub-sub">To send <span>' . count($by['tosend']) . '</span></h3>';
    $o .= $by['tosend'] ? '<div class="pub-grid">' . implode('', array_map(fn($r) => !empty($r['is_patent']) ? pat_card($r) : reg_card($r), $by['tosend'])) . '</div>' : '<p class="pub-empty">Every registration has been reported to the client.</p>';
    if ($by['done']) $o .= '<details class="pub-done"' . (!$by['tosend'] ? ' open' : '') . '><summary><h3 class="pub-sub">Reported <span>' . count($by['done']) . '</span></h3></summary><div class="pub-grid">' . implode('', array_map(fn($r) => !empty($r['is_patent']) ? pat_card($r) : reg_card($r), $by['done'])) . '</div></details>';
    return $o . '</section>' . fetch_forms_html();
}

/** Queue the report for one registration (also the panel's "Queue the client email" button, which overrides "inactive"). */
function reg_queue_for(array $m, bool $force = false): ?int {
    if (trim((string)$m['reg_no']) === '') return null;   // TSDR-verified registrations only (a misread notice sets no reg. no.)
    $c = q1("SELECT * FROM correspondence WHERE matter_id=? AND doc_code='REGISTRATION' ORDER BY mail_date DESC, id DESC LIMIT 1", [$m['id']]);
    if ($force && !empty($m['dead'])) $m['dead'] = 0;
    return queue_notice_report($m, 'REGISTRATION', reg_date_of($m) ?: (string)($c['mail_date'] ?? today()), (string)($c['gmail_id'] ?? ''));
}
/** {REG_BLOCK} {REG_MAINT} {REG_DATE} {LETTER_CLAUSE} for the registration email. */
function reg_fill(array $ev, array $m): array {
    $mm = !empty($ev['matter_id']) ? (q1('SELECT * FROM matters WHERE id=?', [(int)$ev['matter_id']]) ?: $m) : $m;
    $reg = reg_date_of($mm + ['id' => 0]) ?: (string)(($ev['mail_date'] ?? '') ?: $ev['due_date']); $w = reg_window($reg, reg_is_madrid($mm));
    $title = clean_title((string)($mm['title'] ?? '')); $L = ['<b>Mark:</b> ' . ($title !== '' ? '<m>' . $title . '</m>' : 'design mark')];
    if (($sn = logo_serial($mm)) !== '') $L[] = '<logo>' . $sn . '</logo>';
    if (trim((string)($mm['reg_no'] ?? '')) !== '') $L[] = '<b>U.S. Registration Number:</b> ' . num_html('US ' . fmt_reg((string)$mm['reg_no']), num_url($mm, 'reg'));
    $L[] = '<b>Registration Date:</b> ' . fmt_long($reg);
    if (trim((string)($mm['app_no'] ?? '')) !== '') $L[] = '<b>U.S. Serial Number:</b> ' . num_html(fmt_app((string)$mm['app_no'], 'United States'), num_url($mm, 'app'));
    $L[] = '<b>Docket/Reference Number:</b> ' . $mm['ref'];
    $letter = !empty($mm['ref']) ? reg_files($mm)['letter'] : '';
    return ['{REG_BLOCK}' => implode("\n", $L), '{REG_DATE}' => fmt_long($reg),
            '{REG_MAINT}' => 'To keep the registration in force, a Declaration of Use (' . $w['section'] . ') must be filed between ' . fmt_long($w['opens']) . ' and ' . fmt_long($w['due']) . ', and again in the year before every tenth anniversary of the registration. We have docketed to send you a reminder before then; please also record these dates.',
            '{LETTER_CLAUSE}' => $letter !== '' ? ', as well as our letter with more details regarding maintaining your trademark rights' : ''];
}

/**
 * A report the client already got outside Madder: a firm address (firm own_domains / own_addresses) wrote to someone
 * outside the firm on or after the registration date, about this mark (file number in the subject, or the serial / registration number),
 * in registration wording. Registration emails are often replies in an older thread (XYZ001: "Fwd: XYZ001: TM Reg. #... Issuance Letter").
 */
function reg_found_in_gmail(array $m, string $regDate): ?array {
    if (!function_exists('gmail_cfg') || !($c = gmail_cfg())) return null;
    $ref = trim((string)$m['ref']); if (!preg_match('/^[A-Z0-9.\-]{3,}$/i', $ref)) return null;
    $sn = preg_replace('/\D/', '', (string)$m['app_no']); $rn = preg_replace('/\D/', '', (string)$m['reg_no']);
    $ids = array_values(array_filter([$ref, $sn, $rn, $rn !== '' ? number_format((int)$rn) : '', $sn !== '' ? fmt_app($sn, 'United States') : '']));
    $after = strtotime(add_days($regDate, -3, false)); $c['max_threads'] = 40;
    // from the firm only, and not the weekly "Trademark database update" / "Docket mail" robots, which list every serial (XYZ001 was crowded out)
    $q = 'from:(' . firm_from_query() . ') -subject:("Trademark database update") -subject:("Docket mail") after:' . date('Y/m/d', $after)
       . ' (' . implode(' OR ', array_map(fn($x) => '"' . $x . '"', $ids)) . ') (registered OR registration OR issuance OR issued OR certificate OR "Reg. No")';
    $best = null;   // the EARLIEST matching message: the report itself, not a later reply in its thread
    foreach (gm_search_threads($q, $c) as $tid) {
        $res = gm_api('/users/me/threads/' . rawurlencode($tid), ['format' => 'metadata', 'metadataHeaders' => ['From', 'To', 'Cc', 'Subject']], $c);
        foreach ($res['messages'] ?? [] as $msg) {
            if (in_array('DRAFT', $msg['labelIds'] ?? [], true)) continue;
            $ts = (int)(($msg['internalDate'] ?? 0) / 1000); if ($ts < $after) continue;
            $H = []; foreach ($msg['payload']['headers'] ?? [] as $hd) $H[strtolower($hd['name'])] = (string)$hd['value'];
            $subj = (string)($H['subject'] ?? ''); $text = $subj . ' ' . html_entity_decode((string)($msg['snippet'] ?? ''), ENT_QUOTES);
            if (!reg_mail_matches($text, $subj, $ref, $sn, $rn)) continue;
            $from = hdr_addresses($H['from'] ?? ''); if (!$from || !firm_own_address($from[0])) continue;
            $to = array_values(array_filter(array_merge(hdr_addresses($H['to'] ?? ''), hdr_addresses($H['cc'] ?? '')), fn($a) => !firm_address($a)));
            if ($to && (!$best || $ts < $best['ts'])) $best = ['date' => date('Y-m-d', $ts), 'from' => $from[0], 'to' => $to, 'subject' => $subj, 'ts' => $ts];
        }
    }
    return $best;
}
/** Is this message (subject + snippet) about THIS registration? It names the file (subject) or the serial / registration number, in registration words. */
function reg_mail_matches(string $text, string $subject, string $ref, string $sn, string $rn): bool {
    $digits = preg_replace('/[^\d]/', '', $text);
    $about = stripos($subject, $ref) !== false || ($sn !== '' && (str_contains($digits, $sn) || str_contains($text, fmt_app($sn, 'United States')))) || ($rn !== '' && (str_contains($text, number_format((int)$rn)) || preg_match('/\b' . $rn . '\b/', $text)));
    return $about && (bool)preg_match('/regist|issuance|\bissued\b|certificate|®|\bReg\.? ?(No\b|#)/i', $text) && !preg_match('/approved for publication|examination phase|notice of allowance|statement of use/i', $subject);
}
/** Ask oa_fetch (hourly, and its 5-minute "wanted" timer) to save the certificate from TSDR when the folder has none. '' = nothing done. */
function reg_want_certificate(array $m, string $regDate): string {
    $c = q1("SELECT * FROM correspondence WHERE matter_id=? AND doc_code='REGISTRATION' ORDER BY mail_date DESC, id DESC LIMIT 1", [$m['id']]);
    if (!$c) {   // registered before the Gmail intake read registration notices: a row of its own, dated the registration date (TSDR files the certificate that day)
        run('INSERT INTO correspondence(matter_id,app_no,doc_code,mail_date,docket,kind,summary,source,gmail_id) VALUES (?,?,?,?,?,?,?,?,?)', [$m['id'], preg_replace('/\D/', '', (string)$m['app_no']), 'REGISTRATION', $regDate, $m['ref'], 'trademark', 'Registration', 'regreport', '']);
        $c = q1('SELECT * FROM correspondence WHERE id=?', [(int)db()->lastInsertId()]);
    }
    db()->exec("CREATE TABLE IF NOT EXISTS oa_files (correspondence_id INTEGER PRIMARY KEY, status TEXT, files TEXT DEFAULT '', note TEXT DEFAULT '', fetched_at TEXT DEFAULT (datetime('now')))");
    $f = q1('SELECT * FROM oa_files WHERE correspondence_id=?', [$c['id']]);
    if ($f && ($f['status'] === 'wanted' || $f['status'] === 'no_folder' || (in_array($f['status'], ['saved', 'already'], true) && $f['fetched_at'] >= date('Y-m-d H:i:s', time() - 3 * 86400)) || $f['fetched_at'] >= date('Y-m-d H:i:s', time() - 86400))) return '';
    run("INSERT OR REPLACE INTO oa_files(correspondence_id,status,files,note,fetched_at) VALUES (?, 'wanted', '[]', 'registration certificate requested by reg_sweep', datetime('now'))", [$c['id']]);
    return $m['ref'] . ': certificate not in the folder, saving it from TSDR';
}
/** Hourly (and the panel's button): queue missing reports, get missing certificates saved, close reports already sent by hand. */
function reg_sweep(bool $gmail = true): array {
    $log = [];
    foreach (reg_rows() as $r) {
        if (in_array($r['state'], ['none', 'tosend'], true) && function_exists('firm_of_record') && ($of = firm_of_record($r)) !== '') { $log[] = $r['ref'] . ': another firm of record (' . $of . ')' . ($r['state'] === 'none' ? '; no report queued' : '; the open report is flagged on its card'); usleep(1100000); if ($r['state'] === 'none') continue; }   // 2026-10-04
        if ($r['state'] === 'none' && $r['reg_date'] >= add_days(today(), -60, false) && empty($r['dead']) && ($id = reg_queue_for($r))) { $log[] = $r['ref'] . ': client email queued (registered ' . $r['reg_date'] . ')'; $r['todo'] = q1('SELECT * FROM events WHERE id=?', [$id]); $r['state'] = 'tosend'; }
        if ($r['state'] !== 'inactive' && reg_files($r)['cert'] === '' && reg_files($r)['dir']) { $got = reg_get_certificate_now($r); $log[] = $r['ref'] . ': ' . ($got !== '' ? 'certificate saved from TSDR as ' . $got : 'certificate not available from TSDR yet'); }
        if ($r['state'] === 'tosend' && ($l = reg_prepare_letter($r)) !== '') $log[] = $l;   // 2026-10-04: the issuance letter from the template
        if ($gmail && $r['state'] === 'tosend') {
            try { $f = reg_found_in_gmail($r, $r['reg_date']); } catch (Throwable $e) { $log[] = $r['ref'] . ': Gmail ' . $e->getMessage(); continue; }
            if (!$f) continue;
            $who = preg_replace('/@.*/', '', $f['from']);
            run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=? AND status="open"',
                [$who, 'Reported by email ' . fmt_date($f['date']) . ' by ' . $who . ' to ' . implode(', ', $f['to']) . ' (found in Gmail: "' . mb_strimwidth($f['subject'], 0, 70, '…') . '")', $r['todo']['id']]);
            audit('notice_report', $r['ref'] . ' registration report already sent by hand ' . $f['date'] . ' (' . $f['from'] . '): to-do closed', (int)$r['id']);
            $log[] = $r['ref'] . ': already reported by hand ' . $f['date'] . ' (' . $f['from'] . ' to ' . implode(', ', $f['to']) . '), closed';
        }
    }
    return $log;
}

// ---- 2026-10-04 (second ask): "instead of writing that, just get the certificate from TSDR and also prepare the issuance letter
// from the template." The send page, the panel's sweep and the backfill fetch the certificate at once and write the letter (.docx) into
// the folder; the letter is reviewed in Word and saved as PDF there (no Word / LibreOffice on the server), as for patent issuance letters.

/** Save the certificate from TSDR now (oa_fetch for this one file: TSDR document list, ORC certificate, stage code, Dropbox). Returns its file name or ''. */
function reg_get_certificate_now(array $m): string {
    $rf = reg_files($m); if ($rf['cert'] !== '') return $rf['cert']; if (!$rf['dir']) return '';
    $reg = reg_date_of($m); if ($reg === '' || trim((string)$m['reg_no']) === '') return '';
    reg_want_certificate($m, $reg);   // the registration row + oa_files 'wanted' (throttled; forced below)
    $c = q1("SELECT id FROM correspondence WHERE matter_id=? AND doc_code='REGISTRATION' ORDER BY mail_date DESC, id DESC LIMIT 1", [$m['id']]); if (!$c) return '';
    run("INSERT OR REPLACE INTO oa_files(correspondence_id,status,files,note,fetched_at) VALUES (?, 'wanted', '[]', 'registration certificate: fetched for the report', datetime('now'))", [$c['id']]);
    exec('nice -n 10 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/oa_fetch.php') . ' --wanted --only=' . escapeshellarg((string)$m['ref']) . ' 2>&1', $out);
    return reg_files($m)['cert'];
}
/** Opening of the earliest maintenance window whose deadline + 6-month grace has not passed (trademark-issuance-letters: 5-6 years, then (10k-1)-10k). */
function reg_opening(string $reg, string $asOf = ''): string {
    $asOf = $asOf ?: today();
    foreach (array_merge([[60, 72]], array_map(fn($k) => [($k * 10 - 1) * 12, $k * 120], range(1, 9))) as [$o, $d]) if (add_months($reg, $d + 6, false) >= $asOf) return add_months($reg, $o, false);
    return add_months($reg, 1068, false);
}
/**
 * Who the issuance letter goes to (2026-10-04, "address needed - look in their other files. look in tsdr."):
 *  1. a letter already in this mark's own folder (client_letter_address, own folder);
 *  2. otherwise the CURRENT owner and address from TSDR (newest owner group), with "Attention: <contact>" when the client record names a
 *     person other than the owner, or a letter in another of the client's folders names an attention line for this owner;
 *  greeting: that same letter's, else how the firm last greeted the client by email (Gmail), else the owner's name (individuals) or "Dear Sir or Madam,".
 * Returns ['lines' => [...], 'salutation' => 'Dear Mr. X,', 'from' => source] or [].
 */
function tm_letter_address(array $m, array $cl): array {
    $ad = function_exists('client_letter_address') ? client_letter_address($m, [(string)$m['owner'], (string)($cl['name'] ?? '')]) : [];
    if ($ad && preg_grep('/ADDRESS NEEDED/i', $ad['lines'] ?? [])) $ad = [];   // Madder's own placeholder letter is not an address
    if ($ad && !empty($ad['own'])) return $ad;
    $own = tm_tsdr_owner((string)$m['app_no']); if (!$own) return $ad;
    $tok = fn(string $s) => array_values(array_filter(array_map('strtolower', preg_split('/[^A-Za-z]+/', $s)), fn($t) => strlen($t) >= 4 && !in_array($t, ['corp', 'corporation', 'company', 'limited', 'group', 'supply', 'international', 'inc', 'llc'], true)));
    $ownerTok = $tok($own['name']); $other = [];
    if ($ad) { $hay = strtolower(implode(' ', $ad['lines'])); if (array_filter($ownerTok, fn($t) => str_contains($hay, $t))) $other = $ad; }   // an old letter to the SAME owner
    $lines = [$own['name']]; $att = '';
    foreach ($other['lines'] ?? [] as $l) if (preg_match('/^(Attention|Attn\.?)\s*:?\s*(.+)$/i', trim($l), $x)) $att = trim($x[2]);
    $contact = trim((string)($cl['name'] ?? ''));
    if ($att === '' && $contact !== '' && $own['entity'] !== 'INDIVIDUAL' && !array_intersect($tok($contact), $ownerTok) && preg_match('/^[A-Z][a-z]+(\s+[A-Z][a-z.]+){1,3}$/', $contact)) $att = $contact;   // a person's name, not the company
    if ($att !== '') $lines[] = 'Attention: ' . $att;
    $lines = array_merge($lines, $own['street']); $lines[] = $own['cityline']; if ($own['country'] !== '' && !preg_match('/^UNITED STATES/i', $own['country'])) $lines[] = ucwords(strtolower($own['country']));
    $sal = (string)($other['salutation'] ?? '');
    if ($sal === '' && function_exists('gmail_salutation')) { try { $sal = gmail_salutation(array_filter([(string)($cl['email_tm'] ?? ''), (string)($cl['email'] ?? '')])); } catch (Throwable $e) {} }
    if ($sal === '') $sal = $own['entity'] === 'INDIVIDUAL' ? 'Dear ' . $own['name'] . ',' : ($att !== '' ? 'Dear ' . $att . ',' : 'Dear Sir or Madam,');
    return ['lines' => $lines, 'salutation' => $sal, 'from' => 'TSDR owner record' . ($att !== '' ? ' + contact ' . $att : '') . ($other ? ' (attention from ' . $other['from'] . ')' : ''), 'own' => false];
}
/** Current owner on TSDR: name, entity type, street lines, "City, ST 12345", country. [] when TSDR has none. */
function tm_tsdr_owner(string $appNo): array {
    $sn = preg_replace('/\D/', '', $appNo); if (strlen($sn) !== 8 || empty($GLOBALS['cfg']['tsdr_key']) || !function_exists('http_json')) return [];
    $r = http_json("https://tsdrapi.uspto.gov/ts/cd/casestatus/sn$sn/info.json", ['USPTO-API-KEY: ' . $GLOBALS['cfg']['tsdr_key']]);
    $g = $r['json']['trademarks'][0]['parties']['ownerGroups'] ?? []; if (!$g) return [];
    $o = $g[max(array_map('intval', array_keys($g)))][0] ?? null; if (!$o || trim((string)($o['name'] ?? '')) === '') return [];
    $nice = fn(string $s) => $s === strtoupper($s) && preg_match('/[a-z]/i', $s) && !preg_match('/\b(INC|LLC|LTD|CORP)\b/', $s) ? ucwords(strtolower($s)) : $s;   // "252 standish ave" stays; ALL-CAPS street lines are softened
    $street = array_values(array_filter([trim((string)($o['address1'] ?? '')), trim((string)($o['address2'] ?? ''))], fn($x) => $x !== '' && strtolower($x) !== 'none'));
    $street = array_map(fn($x) => preg_replace_callback('/\b([a-z])([a-z]*)\b/', fn($w) => strtoupper($w[1]) . $w[2], $nice($x)), $street);
    $us = strtoupper((string)($o['addressStateCountry']['iso']['code'] ?? 'US')) === 'US'; $st = $us ? (string)($o['addressStateCountry']['stateCountry']['code'] ?? '') : ''; $city = ucwords(strtolower(trim((string)($o['city'] ?? ''))));
    return ['name' => trim((string)$o['name']), 'entity' => strtoupper((string)($o['entityType']['description'] ?? '')), 'street' => $street,
            'cityline' => trim($city . ($st !== '' && strlen($st) === 2 ? ', ' . $st : '') . ' ' . trim((string)($o['zip'] ?? ''))), 'country' => (string)($o['addressStateCountry']['iso']['name'] ?? '')];
}
/** How the firm last greeted this client by email: "Dear Mr. Sanchez," from the newest firm email to one of $emails that opens with "Dear ...". */
function gmail_salutation(array $emails): string {
    if (!$emails || !function_exists('gmail_cfg') || !($c = gmail_cfg())) return '';
    $c['max_threads'] = 6;
    $q = '(' . implode(' OR ', array_map(fn($e) => 'to:' . $e, $emails)) . ') (' . implode(' OR ', array_map(fn($x) => 'from:' . $x, explode(' OR ', firm_from_query()))) . ') "Dear" newer_than:3y';
    foreach (gm_search_threads($q, $c) as $tid) {
        $res = gm_api('/users/me/threads/' . rawurlencode($tid), ['format' => 'full'], $c);
        foreach (array_reverse($res['messages'] ?? []) as $msg) {
            if (in_array('DRAFT', $msg['labelIds'] ?? [], true)) continue;
            $H = []; foreach ($msg['payload']['headers'] ?? [] as $hd) $H[strtolower($hd['name'])] = (string)$hd['value'];
            $from = hdr_addresses($H['from'] ?? ''); if (!$from || !firm_own_address($from[0])) continue;
            $txt = (string)(gm_find_part($msg['payload'], 'text/plain') ?? strip_tags((string)(gm_find_part($msg['payload'], 'text/html') ?? '')));
            if (preg_match('/^\s*(Dear\s+(?:(?:Mr|Mrs|Ms|Dr|Mx)\.?\s+)?[A-Z][^,:\n]{1,40})[,:]/m', $txt, $x) && !preg_match('/Sir or Madam|Examiner|Commissioner/i', $x[1])) return trim($x[1]) . ',';
        }
    }
    return '';
}
/** Write REF-NNx-Issuance Letter (AI written - advice only until lawyer review).docx into the folder from the firm's template. '' = nothing done. */
function reg_prepare_letter(array $m, bool $redo = false): string {   // $redo: replace Madder's own unaddressed letter (same name, Dropbox keeps the old version)
    $rf = reg_files($m); if (!$rf['dir'] || (!$redo && ($rf['letter'] !== '' || $rf['letter_doc'] !== ''))) return '';
    $reg = reg_date_of($m); $rn = preg_replace('/\D/', '', (string)$m['reg_no']); if ($reg === '' || $rn === '') return '';
    $title = clean_title((string)$m['title']); $sn = logo_serial($m); $madrid = reg_is_madrid($m);
    $cl = q1('SELECT * FROM clients WHERE code=?', [$m['client_code']]) ?: [];
    $ad = tm_letter_address($m, $cl);
    $basis = strtolower((string)(($m['basis_filed'] ?? '') ?: ($m['basis'] ?? '')));
    // 2026-08-18: "My invoice is enclosed." only where issuance is a billing event: filed in use (no Statement of Use stage) or Madrid.
    $invoice = !str_contains($basis, '1b');
    $rows = [];
    // the same OWNER only (2026-10-04, XYZ019): an agent's prefix (JPT, CNJ, RWG...) covers many different owners
    foreach (q("SELECT id, title, reg_no, grant_date FROM matters WHERE kind='trademark' AND client_code=? AND dead=0 AND trim(reg_no)<>'' AND grant_date IS NOT NULL AND grant_date<>'' AND (country IN ('', 'United States', 'US', 'USA') OR country IS NULL) AND id<>?" . (trim((string)$m['owner']) !== '' ? ' AND norm(owner)=norm(?)' : ''), array_merge([$m['client_code'], $m['id']], trim((string)$m['owner']) !== '' ? [(string)$m['owner']] : [])) as $o)
        $rows[] = [clean_title((string)$o['title']) ?: 'design mark', fmt_reg((string)$o['reg_no']), substr((string)$o['grant_date'], 0, 10), (int)$o['id']];
    usort($rows, fn($a, $b) => strcmp($b[2], $a[2]));
    $rows = array_merge([[$title ?: 'design mark', fmt_reg($rn), $reg, 0]], array_slice($rows, 0, 24));
    // 2026-10-04: active marks only; highlight a mark whose maintenance window is open, opens within 3 months, or is in its grace period
    // (and is still open in Madder), with his 08-18 note (XYZ004 / XYZ009 wording) under the table.
    $port = []; $notes = ['open' => [], 'opens' => [], 'grace' => []];
    foreach ($rows as $i => [$mk, $rno, $rd, $mid]) {
        $open = reg_opening($rd); $due = add_months($open, 12, false); $grace = add_months($due, 6, false); $t = today(); $st = '';
        if ($i > 0 && $open <= add_months($t, 3, false) && $grace >= $t && q1("SELECT 1 FROM events WHERE matter_id=? AND status IN ('open','proposed') AND (type LIKE 'Section 8%' OR type LIKE 'Section 71%' OR type LIKE 'Renewal%') AND due_date BETWEEN ? AND ? LIMIT 1", [$mid, add_months($open, -1, false), add_months($grace, 1, false)]))
            $st = $t < $open ? 'opens' : ($t <= $due ? 'open' : 'grace');
        $label = $mk . ' (Reg. No. ' . $rno . ')';
        if ($st === 'opens') $notes['opens'][] = $label . ' opens on ' . fmt_long($open); elseif ($st === 'open') $notes['open'][] = $label; elseif ($st === 'grace') $notes['grace'][] = [$label, $due];
        $port[] = [$mk, $rno, fmt_long($rd), fmt_long($open), $st !== ''];
    }
    $and = fn(array $x) => count($x) > 1 ? implode(', ', array_slice($x, 0, -1)) . ' and ' . end($x) : (string)($x[0] ?? '');
    $n = count(array_filter($port, fn($r) => $r[4])); $s = [];
    if ($notes['open']) $s[] = 'The maintenance filing window for ' . $and($notes['open']) . (count($notes['open']) > 1 ? ' are' : ' is') . ' open now.';
    if ($notes['opens']) $s[] = 'The maintenance filing window for ' . $and($notes['opens']) . '.';
    foreach ($notes['grace'] as [$lbl, $due]) $s[] = 'The deadline for ' . $lbl . ' passed on ' . fmt_long($due) . '; it can still be cured within six months of that date, with a USPTO surcharge.';
    $portNote = $n ? 'Please note the highlighted row' . ($n > 1 ? 's' : '') . ' above. ' . implode(' ', $s) . ' If a maintenance filing is not made, the registration is cancelled outright, so please let me know if you would like me to prepare the filing.' : '';
    $tmp = scratch_dir() . '/' . preg_replace('/[^A-Za-z0-9.]/', '_', (string)$m['ref']) . '-tmletter'; $img = '';
    if ($sn !== '') { [$code, $bytes] = http_get(logo_url($sn)); if ($code === 200 && strlen((string)$bytes) > 200) { $img = "$tmp.img"; file_put_contents($img, $bytes); } }
    $fields = ['date' => fmt_long(today()), 'address_lines' => $ad['lines'] ?? [], 'salutation' => $ad['salutation'] ?? '', 'mark' => $title !== '' ? $title : 'your logo (design mark)',
               'display_text' => $img !== '' ? '' : ($title ?: 'design mark'), 'display_image' => $img, 'reg_no' => fmt_reg($rn), 'reg_date' => fmt_long($reg), 'ref' => (string)$m['ref'],
               'madrid' => $madrid, 'invoice' => $invoice, 'reminder' => fmt_long(add_days(reg_opening($reg), -14, false)), 'portfolio' => $port, 'portfolio_note' => $portNote];
    file_put_contents("$tmp.json", json_encode($fields));
    $o = (string)shell_exec('python3 ' . escapeshellarg(dirname(__DIR__) . '/bin/tm_issuance_letter.py') . ' fill ' . escapeshellarg(dirname(__DIR__) . '/templates/tm-issuance-letter.docx') . ' ' . escapeshellarg("$tmp.docx") . ' ' . escapeshellarg("$tmp.json") . ' 2>&1');
    if (!is_file("$tmp.docx")) { @unlink("$tmp.json"); @unlink($img); return $m['ref'] . ': the issuance letter could not be generated (' . trim($o) . ')'; }
    $prev = $redo && preg_match('/-Issuance Letter \(AI written - advice only until lawyer review\)\.docx$/', (string)$rf['letter_doc']) ? (string)$rf['letter_doc'] : '';   // only Madder's own letter is ever replaced
    if ($redo && $prev === '' && ($rf['letter'] !== '' || $rf['letter_doc'] !== '')) { foreach (["$tmp.json", "$tmp.docx", $img] as $x) if ($x !== '') @unlink($x); return $m['ref'] . ': not replaced (the letter in the folder is not one Madder wrote)'; }
    $code = tm_next_code(mf_list($rf['dir']), (string)$m['ref'], 'issuance', $reg);
    $name = mf_write($rf['dir'], $prev !== '' ? $prev : $m['ref'] . '-' . $code . '-Issuance Letter (AI written - advice only until lawyer review).docx', (string)file_get_contents("$tmp.docx"), $prev !== '');
    foreach (["$tmp.json", "$tmp.docx", $img] as $x) if ($x !== '') @unlink($x);
    audit('issuance', $m['ref'] . ' trademark issuance letter written: ' . $name . ($ad ? ' (addressed as in ' . $ad['from'] . ')' : ' (ADDRESS NEEDED)'), (int)$m['id']);
    return $m['ref'] . ': issuance letter written as ' . $name . ($ad ? '' : ' (ADDRESS NEEDED: no earlier letter to this client found)');
}
