<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * Every e-mail from @uspto.gov is picked up (2026-10-03: "pick up all trademark and patent notices - hold them somewhere if you
 * don't know what to do with them yet"). The Gmail pull sorts each message into one of:
 *   docketed  a notice parse_uspto_mail() understands (Patent Center, eGrant, TM official notices, TTAB, new-application receipts)
 *   receipt   a filing receipt: TEAS / Trademark Center "Received Your ..." or a Maintenance Fee Storefront payment. The filed papers are
 *             saved into the matter folder (bin/oa_fetch.php, from TSDR / the e-mail's attachment), the deadline they satisfy is PROPOSED
 *             done, and a "Report ..." client email is queued for the attorney to check and send ("plain old acceptance of something: save in
 *             file, email to report to client which will be in Madder for me to verify").
 *   ignored   USPTO workflow and bulk mail that needs nothing: e-signature requests, "ready for review/payment", surveys, newsletters
 *   held      anything else (an examiner's e-mail, assignments, ESTTA receipts, account notices ...): kept in uspto_held, shown on the
 *             USPTO mail page and in the morning report until someone marks it handled.
 * Real mail only: a message counts as the USPTO's when Gmail saw a passing DKIM signature from uspto.gov (all USPTO systems sign; our mail
 * reaches Gmail through pair's forwarder, so SPF says pair, not the USPTO). An unsigned message is never acted on: it is held and flagged.
 * Examiners' own Outlook mail is unsigned (checked 2026-10-03 over 601 messages), so it is always held for a person to read.
 */
function held_schema(): void {
    static $done = false; if ($done) return; $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS uspto_held (msg_id TEXT PRIMARY KEY, received TEXT, sender TEXT, subject TEXT, body TEXT, reason TEXT, verified INTEGER DEFAULT 0,
        status TEXT DEFAULT 'held', handled_by TEXT, handled_at TEXT, note TEXT DEFAULT '', at TEXT DEFAULT (datetime('now')))");
}
function hold_message(array $msg, string $body, string $reason, bool $verified): void {
    held_schema();
    $text = preg_replace("/\n\s*\n+/", "\n\n", html_entity_decode(strip_tags(preg_replace(['#<(style|head|script)\b[^>]*>.*?</\1>#is', '#</(p|div|li|tr|h\d)>|<br\s*/?>#i'], ['', "\n"], $body)), ENT_QUOTES, 'UTF-8'));
    run('INSERT OR IGNORE INTO uspto_held(msg_id,received,sender,subject,body,reason,verified) VALUES (?,?,?,?,?,?,?)',
        [$msg['id'], !empty($msg['ts']) ? date('Y-m-d H:i', (int)$msg['ts']) : '', (string)($msg['from'] ?? ''), (string)$msg['subject'], (preg_match('/^.{0,20000}/su', trim((string)$text), $cut) ? $cut[0] : substr(trim((string)$text), 0, 20000)), $reason, $verified ? 1 : 0]);
}
function held_open(): array { held_schema(); return q("SELECT * FROM uspto_held WHERE status='held' ORDER BY received DESC"); }

/** Did Gmail verify the message as the USPTO's? [bool, why]. Reads the Authentication-Results header Gmail stamped on arrival. */
function uspto_authentic(array $msg, array $c): array {
    if (!preg_match('/@([a-z0-9.\-]+\.)?uspto\.gov\b/i', (string)($msg['from'] ?? ''))) return [true, 'not a uspto.gov sender'];   // WIPO etc.: matched by subject, as before
    try { $r = gm_api('/users/me/messages/' . rawurlencode((string)$msg['id']), ['format' => 'metadata', 'metadataHeaders' => ['Authentication-Results']], $c); }
    catch (Throwable $e) { return [false, 'could not read its signature (' . $e->getMessage() . ')']; }
    $ar = ''; foreach ($r['payload']['headers'] ?? [] as $h) if (strcasecmp((string)$h['name'], 'Authentication-Results') === 0) { $ar = (string)$h['value']; break; }   // the first one is Gmail's own
    if (preg_match('/\bdkim=pass\b[^;]*header\.(?:i|d)=@?([a-z0-9.\-]+\.)?uspto\.gov\b/i', $ar)) return [true, 'DKIM uspto.gov'];
    return [false, preg_match('/dkim=none|message not signed/i', $ar) ? 'not signed by uspto.gov (normal for an examiner\'s own email; check the sender)' : 'signature did not verify as uspto.gov'];
}

/** USPTO mail that needs nothing: a reason string, or null. */
function uspto_ignorable(string $from, string $subject): ?string {
    $s = $subject; $f = strtolower($from);
    if (preg_match('/requests you to e-sign|^Submission link for|ready for (review and signature|payment and submission)/i', $s)) return 'e-signature / submission workflow';
    if (str_contains($f, 'subscriptions.uspto.gov')) return 'USPTO newsletter';
    if (str_contains($f, 'oedonline@') || preg_match('/^(USPTO[:\s-]+)?OED\b|OEDonline/i', $s)) return 'OED account notice';   // 2026-10-03: "ignore OED notices"
    if (str_contains($f, 'feedback.uspto.gov') || preg_match('/\bsurvey\b|requests your feedback/i', $s)) return 'survey';
    if (preg_match('/^Automatic reply:|^Thank you for Contacting USPTO/i', $s)) return 'automatic reply';
    if (preg_match('/Request to Divide/i', $s)) return 'request to divide (2026-10-13: ignore)';
    if (preg_match('/^MyUSPTO: \d+ (TM|patent)/i', $s)) return 'MyUSPTO status alert (the official notice / USPTO sweep covers it)';
    return null;
}

/**
 * TEAS / Trademark Center filing receipt -> item, or null.
 *   TEAS:          "XYZ022 Registration Number 5053328: Received Your Combined Declaration of Use  and Renewal under Sections 8 & 9"
 *                  "XYZ005 Serial Number 99735886: Received Your Allegation of Use"
 *   Trademark Ctr: "Received Your Registration Number (5051107): Combined Sections 8 & 9 Declaration of Use and Renewal form"
 * The filing date is the receipt's own (Eastern time) "SUBMIT DATE"; else the day it arrived.
 */
function parse_filing_receipt(string $subject, string $body, ?string $received = null): ?array {
    $s = preg_replace('/\s+/', ' ', trim($subject)); $ref = ''; $which = ''; $num = ''; $form = '';
    if (preg_match('/^(?:(\S+) )?(Serial|Registration) Number:? (\d{7,8}): Received Your (.+)$/i', $s, $m)) { $ref = (string)$m[1]; $which = $m[2]; $num = $m[3]; $form = $m[4]; }
    elseif (preg_match('/^Received Your (Serial|Registration) Number \((\d{7,8})\): (.+?)(?: form)?$/i', $s, $m)) { $which = $m[1]; $num = $m[2]; $form = $m[3]; }
    else return null;
    if (!preg_match('/^[A-Z]{3,4}\d{3}(\.\d+)?$/i', $ref)) $ref = '';   // our file numbers (XYZ022, SBS003.1); "CAW112896" style client references are not
    $text = preg_replace('/[\s\x{00a0}]+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<(style|head|script)\b[^>]*>.*?</\1>#is', '', (string)$body)), ENT_QUOTES, 'UTF-8'));
    if ($ref === '' && preg_match('/DOCKET\/REFERENCE NUMBER\s*\|?\s*([A-Z]{3,4}\d{3}(?:\.\d+)?)\b/i', $text, $d)) $ref = $d[1];
    $date = ($received && preg_match('/^\d{4}-\d{2}-\d{2}$/', $received)) ? $received : date('Y-m-d');
    if (preg_match('/SUBMIT DATE\s*\|?\s*\w{3} (\w{3}) (\d{1,2}) [\d:]+ \w+ (\d{4})/', $text, $d) && ($ts = strtotime("$d[1] $d[2] $d[3]")) !== false) $date = date('Y-m-d', $ts);
    $serial = strcasecmp($which, 'Serial') === 0 ? $num : (preg_match('/serial (?:no\.?|number) ?(\d{8})/i', $text, $sn) ? $sn[1] : '');
    $form = trim(preg_replace('/\s+/', ' ', $form));
    return ['kind' => 'trademark', 'app_no' => $serial !== '' ? $serial : $num, 'doc_code' => 'FILING RECEIPT', 'date' => $date, 'docket' => strtoupper($ref),
            'summary' => 'Filed: ' . $form, 'form' => $form, 'reg_no' => strcasecmp($which, 'Registration') === 0 ? $num : ''];
}
/** File-name prefix for a filed trademark paper (the firm's TEAS names: S08N09-<reg>, S08N15-<reg>, SOU-<serial>, ROA-<serial> ...), which
 *  receipts.php also reads. [prefix, use the registration number?] */
function filed_paper_prefix(string $form, string $tsdrCode = ''): array {
    $map = [['/8 (&|and) 9|renewal/i', 'S08N09', true], ['/8 (&|and) 15|incontestab/i', 'S08N15', true], ['/section 8\b|declaration of use/i', 'S08', true], ['/section 7|amend(ment)? .*registration|correct(ion)? .*registration/i', 'S07', true],
            ['/allegation of use|statement of use/i', 'SOU', false], ['/extension of time to file a statement|extension request.*statement/i', 'ESU', false],
            ['/extension of time to file a response|extension.*response/i', 'EXT', false], ['/response to (office|post)/i', 'ROA', false], ['/petition to revive/i', 'PTR', false],
            ['/voluntary amendment|preliminary amendment/i', 'VAM', false], ['/post.?approval|post.?publication|post.?notice of allowance/i', 'PPA', false], ['/change (of )?address|correspondence/i', 'COA', false]];
    foreach ($map as [$re, $p, $reg]) if (preg_match($re, $form)) return [$p, $reg];
    return [$tsdrCode !== '' ? $tsdrCode : 'FILED', false];
}
/** Short file label for a filed trademark paper (2026-10-03: "way too long a file name. Just call it 'Renewal 2026'").
 *  Each label still says what receipts.php needs to recognise the paper. */
function filed_paper_label(string $form, string $date, ?int $ext = null): string {
    $y = substr($date, 0, 4);
    if (preg_match('/extension of time to file a statement|extension request.*statement/i', $form)) return 'SOU Extension' . ($ext ? " $ext" : '');   // numbered (2026-10-03: "SOU Extension 2")
    foreach ([['/8 (&|and) 9|renewal/i', "Section 8 and 9 Renewal $y"], ['/8 (&|and) 15|incontestab/i', "Section 8 and 15 $y"], ['/section 71/i', "Section 71 $y"], ['/section 8\b|declaration of use/i', "Section 8 $y"],
              ['/section 7|amend(ment)? .*registration/i', 'Section 7 Amendment'],
              ['/allegation of use|statement of use/i', 'Statement of Use'], ['/extension of time to file a response|extension.*response/i', 'Extension to Respond'],
              ['/response to (office|post)/i', 'Response to Office Action'], ['/petition to revive/i', 'Petition to Revive'], ['/amendment/i', 'Amendment'], ['/change (of )?address|correspondence/i', 'Change of Address']] as [$re, $label])
        if (preg_match($re, $form)) return $label;
    return trim(mb_strimwidth_safe($form, 40));
}
function mb_strimwidth_safe(string $s, int $n): string { return preg_match('/^.{0,' . $n . '}/su', $s, $m) ? $m[0] : substr($s, 0, $n); }
/** Which Statement of Use extension a request filed on $date is: the "Statement of Use or Nth Extension Due" deadline it answers, else one
 *  more than the SOU Extension files already in the folder. */
function sou_extension_number(int $matterId, string $date, array $names): ?int {
    $e = $matterId ? q1("SELECT type FROM events WHERE matter_id=? AND status IN ('open','proposed') AND type LIKE 'Statement of Use or % Extension Due' AND due_date >= ? ORDER BY due_date LIMIT 1", [$matterId, add_days($date, -1, false)]) : null;
    if ($e && preg_match('/or (\d)\w* Extension/', (string)$e['type'], $m)) return (int)$m[1];
    $n = count(array_filter($names, fn($x) => (bool)preg_match('/SOU Extension|extension.{0,30}statement of use/i', $x)));
    return $n + 1;
}
/** "Assignment Notice of Recordation (WUID: 901041085)" from PTAS -> item (2026-10-03: "should find the attachment and email the
 *  client"). The body names our docket number; the kind comes from the matter (WUID 9xxxxxxxx = trademark, 5xxxxxxxx = patent when a file
 *  number is both). The Notice.pdf (reel/frame) and CoverSheet.tif are saved by oa_fetch. */
function parse_recordation(string $subject, string $body, ?string $received = null): ?array {
    if (!preg_match('/Assignment Notice of Recordation \(WUID:\s*(\d+)\)/i', $subject, $w)) return null;
    $text = html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8');
    $ref = preg_match('/DOCKET NUMBER:\s*([A-Z]{3,4}\d{3}(?:\.[A-Z0-9]+)?)/i', $text, $d) ? strtoupper($d[1]) : '';
    $date = ($received && preg_match('/^\d{4}-\d{2}-\d{2}$/', $received)) ? $received : date('Y-m-d');
    $kind = '';
    if ($ref !== '') { $kinds = array_column(q('SELECT DISTINCT kind FROM matters WHERE ref=?', [preg_replace('/\.[A-Z]{2}$/i', '', $ref)]), 'kind'); $kind = count($kinds) === 1 ? $kinds[0] : (str_starts_with($w[1], '9') ? 'trademark' : 'patent'); }
    return ['kind' => $kind ?: (str_starts_with($w[1], '9') ? 'trademark' : 'patent'), 'app_no' => '', 'doc_code' => 'ASSIGNMENT RECORDED', 'date' => $date, 'docket' => $ref,
            'summary' => 'Assignment recorded (WUID ' . $w[1] . ')', 'wuid' => $w[1], 'assignee' => preg_match('/FIRST ASSIGNEE:\s*(.+)/i', $text, $a) ? trim($a[1]) : ''];
}
/** "Assignment confirmation: Assignment ID - 2119539" (Assignment Center) -> item; the matter comes from the attached cover sheet's
 *  ATTORNEY DOCKET NUMBER and property number (assignment_cover_ids). 2026-10-03: save the cover sheet as AckReceipt-Recordation
 *  and draft the client email. */
function parse_assignment_filed(string $subject, ?string $received = null): ?array {
    if (!preg_match('/^Assignment confirmation: Assignment ID\s*-\s*(\d+)/i', trim($subject), $m)) return null;
    $date = ($received && preg_match('/^\d{4}-\d{2}-\d{2}$/', $received)) ? $received : date('Y-m-d');
    return ['kind' => '', 'app_no' => '', 'doc_code' => 'ASSIGNMENT FILED', 'date' => $date, 'docket' => '', 'summary' => 'Assignment filed for recordation (Assignment ID ' . $m[1] . ')', 'assignment_id' => $m[1]];
}
/** Kind, docket number and first property number off an Assignment Center cover sheet's text. */
function assignment_cover_ids(string $text): array {
    $t = preg_replace('/[ \t]+/', ' ', $text);
    $kind = preg_match('/TRADEMARK ASSIGNMENT COVER SHEET/i', $t) ? 'trademark' : (preg_match('/PATENT ASSIGNMENT COVER SHEET/i', $t) ? 'patent' : '');
    $ref = preg_match('/ATTORNEY DOCKET\s*NUMBER:\s*([A-Z]{3,4}\d{3}(?:\.\d+)?)\b/i', $t, $d) ? strtoupper($d[1]) : '';
    $num = preg_match('/(?:Serial|Application|Registration|Patent) Number:\s*(\d{6,8})/i', $t, $n) ? $n[1] : '';
    return ['kind' => $kind, 'docket' => $ref, 'number' => $num];
}
/** The PDF / TIFF attachments of one Gmail message: [filename => bytes]. */
function gmail_attachment_bytes(string $msgId): array {
    $c = gmail_cfg(); if (!$c || $msgId === '') return [];
    $m = gm_api('/users/me/messages/' . rawurlencode($msgId), ['format' => 'full'], $c); $out = [];
    foreach (gm_extract_attachments($m['payload'] ?? []) as $a) if (!empty($a['id']) && preg_match('/\.(pdf|tiff?)$/i', (string)$a['filename']))
        $out[(string)$a['filename']] = gm_b64url((string)(gm_api('/users/me/messages/' . rawurlencode($msgId) . '/attachments/' . rawurlencode($a['id']), [], $c)['data'] ?? ''));
    return $out;
}
/** A TIFF (the PTAS cover sheet) as a PDF: tiff2pdf where installed (the server), else Python PIL. '' when neither works. */
function tiff_to_pdf(string $tiff): string {
    $in = tempnam(sys_get_temp_dir(), 'tif') . '.tif'; $out = $in . '.pdf'; file_put_contents($in, $tiff);
    $t2p = function_exists('tool') ? tool('tiff2pdf') : 'tiff2pdf';
    shell_exec(escapeshellarg($t2p) . ' -o ' . escapeshellarg($out) . ' ' . escapeshellarg($in) . ' 2>/dev/null');
    $py = implode("\n", ['import sys', 'from PIL import Image', 'im = Image.open(sys.argv[1]); dpi = float((im.info.get("dpi") or (300, 300))[0]) or 300.0; fr = []', 'try:',
        '    while True: fr.append(im.copy() if im.mode == "1" else im.convert("1")); im.seek(im.tell() + 1)', 'except EOFError: pass',
        'fr[0].save(sys.argv[2], save_all=True, append_images=fr[1:], resolution=dpi)']);   // bilevel: no JPEG encoder needed
    if (!is_file($out) || filesize($out) === 0) shell_exec('python3 -c ' . escapeshellarg($py) . ' ' . escapeshellarg($in) . ' ' . escapeshellarg($out) . ' 2>/dev/null');
    $pdf = is_file($out) ? (string)file_get_contents($out) : ''; @unlink($in); @unlink($out);
    return str_starts_with($pdf, '%PDF') ? $pdf : '';
}
/** Reel/frame, recordation date, assignor and assignee off the Notice of Recordation's text. */
function recordation_details(string $text): array {
    $t = preg_replace('/[ \t]+/', ' ', $text);
    return ['reel_frame' => preg_match('#REEL/FRAME:\s*(\d+\s*/\s*\d+)#i', $t, $m) ? preg_replace('/\s+/', '', $m[1]) : '',
            'recorded' => preg_match('#RECORDATION DATE:\s*(\d{2}/\d{2}/\d{4})#i', $t, $m) ? date('Y-m-d', (int)strtotime($m[1])) : ''];
}
/**
 * Did someone at the firm already email the client about this, by hand, on or after $since? (2026-10-03: XYZ064 and XYZ004 were reported
 * by the attorney the morning the receipts came, so Madder's own report would have been a second email.) A sent message whose subject names
 * the file number, to someone outside the firm, that reads like a report. Returns ['date', 'subject'] or null; null too when Gmail is
 * not reachable (the to-do then stays, and the Send page shows what was already emailed).
 */
function reported_by_hand(string $ref, string $since): ?array {
    $c = gmail_cfg(); if (!$c || !preg_match('/^[A-Z0-9.\-]{3,}$/i', $ref)) return null;
    $c['max_threads'] = 10; $after = date('Y/m/d', strtotime($since . ' -1 day'));
    try {
        foreach (gm_search_threads('subject:"' . $ref . '" after:' . $after . ' (' . implode(' OR ', array_map(fn($x) => 'from:' . $x, firm('staff_senders') ?: explode(' OR ', firm_from_query()))) . ')', $c) as $tid) {
            $res = gm_api('/users/me/threads/' . rawurlencode($tid), ['format' => 'metadata', 'metadataHeaders' => ['From', 'To', 'Cc', 'Subject']], $c);
            foreach ($res['messages'] ?? [] as $msg) {
                if (in_array('DRAFT', $msg['labelIds'] ?? [], true) || (int)(($msg['internalDate'] ?? 0) / 1000) < strtotime($since)) continue;
                $H = []; foreach ($msg['payload']['headers'] ?? [] as $hd) $H[strtolower($hd['name'])] = (string)$hd['value'];
                $from = hdr_addresses($H['from'] ?? ''); if (!$from || !firm_address($from[0])) continue;
                $out = array_filter(array_merge(hdr_addresses($H['to'] ?? ''), hdr_addresses($H['cc'] ?? '')), fn($a) => !firm_address($a)); if (!$out) continue;
                if (stripos($H['subject'] ?? '', $ref) === false || !preg_match('/filed|receipt|recorded|recordation|accepted|paid|attached|registered|published/i', (string)($msg['snippet'] ?? ''))) continue;
                return ['date' => date('Y-m-d', (int)(($msg['internalDate'] ?? 0) / 1000)), 'subject' => trim((string)($H['subject'] ?? ''))];
            }
        }
    } catch (Throwable $e) { return null; }
    return null;
}
/** Hourly: an open "Report ..." to-do whose client was already emailed by hand is cancelled, with a note saying when (never sent twice). */
function cancel_reports_sent_by_hand(): array {
    $done = [];
    foreach (q("SELECT e.*, m.ref FROM events e JOIN matters m ON m.id=e.matter_id LEFT JOIN reminders r ON r.event_id=e.id AND r.status='sent' WHERE e.status='open' AND e.type IN ('Report Maintenance Fee Paid','Report Assignment Recorded','Report Assignment Filed') AND r.id IS NULL") as $e) {   // 2026-10-06: filing receipts are always reported (any email on the file that day used to count)
        $h = reported_by_hand((string)$e['ref'], (string)($e['mail_date'] ?: $e['due_date'])); if (!$h) continue;
        run('UPDATE events SET status="cancelled", done_at=datetime("now"), note=note_add(note, ?) WHERE id=? AND status="open"', ['already reported by hand ' . $h['date'] . ': "' . $h['subject'] . '"', $e['id']]);
        run('UPDATE reminders SET status="skipped", reason=? WHERE event_id=? AND status IN ("pending","held")', ['already reported by hand ' . $h['date'], $e['id']]);
        audit('report_by_hand', $e['ref'] . ' ' . $e['type'] . ': already emailed ' . $h['date'] . ' ("' . $h['subject'] . '"); to-do cancelled', (int)$e['matter_id']);
        $done[] = $e['ref'] . ' ' . $e['type'] . ' (emailed ' . $h['date'] . ')';
    }
    return $done;
}
/** Which TSDR document is the paper filed on $date: incoming category, else a known incoming code; never a specimen or office note. */
function tsdr_filed_doc(array $docs, string $date): ?array {
    $day = array_values(array_filter($docs, fn($d) => $d['date'] === $date));
    foreach ($day as $d) if ($d['cat'] === 'I') return $d;
    foreach ($day as $d) if (in_array(strtoupper($d['type']), ['PRA', 'VAM', 'S89', '815', 'S08', 'SOU', 'ROA', 'ELR', 'PSE', 'PPA', 'ESU', 'COA', 'S71', 'S7'], true)) return $d;
    return null;
}

/** Maintenance Fee Storefront receipt -> one item per patent paid ("payment has been processed for patent number(s): 10383759, 11..."). */
function parse_maint_receipt(string $from, string $subject, string $body, ?string $received = null): array {
    if (!preg_match('/MaintenanceFees/i', $from) || !preg_match('/processed for patent number\(s\):\s*([\d,\s]+)/i', html_entity_decode(strip_tags($body)), $m)) return [];
    $date = ($received && preg_match('/^\d{4}-\d{2}-\d{2}$/', $received)) ? $received : date('Y-m-d'); $out = [];
    foreach (preg_split('/[\s,]+/', trim($m[1])) as $pn) if (preg_match('/^\d{7,8}$/', $pn))
        $out[] = ['kind' => 'patent', 'app_no' => $pn, 'doc_code' => 'MAINT FEE PAID', 'date' => $date, 'docket' => '', 'summary' => 'Maintenance fee paid (U.S. Patent ' . number_format((int)$pn) . ')', 'patent_no' => $pn];
    return $out;
}
/** Which maintenance fee a payment on $paid is: 4, 8 or 12 (the 3.5 / 7.5 / 11.5-year fee), or null. From the grant date (each fee can be paid
 *  from 6 months before its due date to the end of its 6-month grace period); without one, the earliest maintenance fee still open. */
function maint_year_open(int $matterId, string $paid): ?int {
    $g = (string)(q1('SELECT grant_date FROM matters WHERE id=?', [$matterId])['grant_date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $g)) {
        foreach ([4 => 3, 8 => 7, 12 => 11] as $fee => $opens) if ($paid >= add_months(substr($g, 0, 10), $opens * 12, false) && $paid <= add_months(substr($g, 0, 10), $opens * 12 + 13, false)) return $fee;
        return null;   // not inside any fee's window: let a person look (no year named, nothing proposed)
    }
    // the earliest fee still open (a fee paid in its grace period is past its due date; one paid early is up to 6 months before it)
    foreach (q("SELECT type, due_date FROM events WHERE matter_id=? AND status IN ('open','proposed') AND type LIKE '%Maintenance Fee%' ORDER BY due_date", [$matterId]) as $e)
        if (preg_match('/^(3 1\/2|7 1\/2|11 1\/2) Year/', $e['type'], $m) && $e['due_date'] >= add_months($paid, -18, false)) return ['3 1/2' => 4, '7 1/2' => 8, '11 1/2' => 12][$m[1]];
    return null;
}

/*
 * 2026-10-13: three more kinds of USPTO mail.
 *   "Alert USPTO Change of Email Address" -> a to-do dated today asking whether to mark the matter inactive (the client moved its
 *       correspondence away from us). A change TO a firm address is ours and needs nothing.
 *   An examiner's e-mail agreeing to an interview (usually "RE: USPTO Automated Interview Request (AIR) ...") -> an "Examiner Interview"
 *       event due on the interview date, the time leading its note (shown under the date on the dashboard).
 *   "Request to Divide" (TEAS save-form mail) -> ignored (uspto_ignorable).
 */
if (!defined('EMAIL_CHANGE_EVENT')) define('EMAIL_CHANGE_EVENT', 'Correspondence email changed: mark inactive?');
if (!defined('INTERVIEW_EVENT')) define('INTERVIEW_EVENT', 'Examiner Interview');   // 2026-10-13 (lib/held.php)

/** ['email' => new address, 'numbers' => [serial/registration ...]] from a Change of Email Address alert, or null. */
function parse_email_change(string $subject, string $body): ?array {
    if (!preg_match('/Change of (Correspondence )?E-?mail Address/i', $subject)) return null;
    $t = html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8');
    preg_match('/Email Address for Correspondence to\s+([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $t, $e);
    if (!preg_match('/Serial\/registration numbers?:\s*([\d,;\s]+)/i', $t, $n)) return null;
    preg_match_all('/\b\d{7,8}\b/', $n[1], $nums);
    return $nums[0] ? ['email' => strtolower($e[1] ?? ''), 'numbers' => array_values(array_unique($nums[0]))] : null;
}

/** Open the "mark inactive?" to-do on each matter the alert names. Returns log lines. */
function email_change_tasks(array $p, string $received, bool $apply = true): array {
    $out = [];
    if ($p['email'] !== '' && firm_address($p['email'])) return ['email moved to ' . $p['email'] . ' (ours): nothing to do'];
    foreach ($p['numbers'] as $n) {
        $m = find_matter(['kind' => 'trademark', 'app_no' => $n, 'docket' => '']);
        if (!$m) { $out[] = "$n: no matching trademark in Madder"; continue; }
        if ($m['dead']) { $out[] = "$m[ref]: already inactive"; continue; }
        $why = 'USPTO notice of ' . substr($received, 0, 10) . ': correspondence email changed to ' . ($p['email'] ?: '(address not given)') . '. Mark the matter inactive and take it off the docket?';
        $open = q1('SELECT * FROM events WHERE matter_id=? AND type=? AND status IN ("open","proposed")', [$m['id'], EMAIL_CHANGE_EVENT]);
        if ($open) {
            if ($apply && !str_contains((string)$open['note'], $p['email'])) run('UPDATE events SET note=? WHERE id=?', [$why, $open['id']]);
            $out[] = "$m[ref]: to-do already open"; continue;
        }
        if ($apply) {
            run('INSERT INTO events(matter_id,type,due_date,status,source,doc_code,mail_date,note) VALUES (?,?,?,"open","gmail","EMAIL.CHANGE",?,?)', [$m['id'], EMAIL_CHANGE_EVENT, today(), substr($received, 0, 10) ?: today(), $why]);
            audit('email_change', $why, (int)$m['id']);
        }
        $out[] = "$m[ref]: correspondence email changed to $p[email], asked whether to mark inactive";
    }
    return $out;
}

/** ['date' => Y-m-d, 'time' => '1:00 PM ET', 'link' => meeting URL or ''] when an examiner's e-mail agrees to an interview, else null. */
function parse_interview_confirm(string $subject, string $body, string $received): ?array {
    if (!preg_match('/interview/i', $subject . ' ' . substr($body, 0, 400))) return null;
    $t = html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8');
    $t = preg_split('/\n\s*(From:|-{3,}\s*Original Message|On .{5,80} wrote:|Sent from )/i', $t)[0];   // the examiner's own words, not the quoted request
    if (!preg_match('/\b(confirm\w*|grant\w*|approv\w*|agree\w*|schedul\w*|accept\w*|works for me|is fine|set up|see you|invit\w*)\b/i', $t)) return null;
    if (preg_match('/\b(den(y|ied)|declin\w*|not available|unavailable|unable|cannot|can\'t|not able)\b/i', $t)) return null;
    $rx = $received !== '' ? substr($received, 0, 10) : today(); $y = (int)substr($rx, 0, 4); $date = null;
    $mon = 'Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?';
    if (preg_match('/\b(' . $mon . ')\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b(?:,?\s*(\d{4}))?/i', $t, $d)) $date = [(int)date('n', strtotime($d[1] . ' 1 2000')), (int)$d[2], isset($d[3]) && $d[3] !== '' ? (int)$d[3] : null];
    elseif (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/', $t, $d)) $date = [(int)$d[1], (int)$d[2], isset($d[3]) && $d[3] !== '' ? ((int)$d[3] < 100 ? 2000 + (int)$d[3] : (int)$d[3]) : null];
    if (!$date || !checkdate($date[0], $date[1], $date[2] ?? $y)) return null;
    $iso = sprintf('%04d-%02d-%02d', $date[2] ?? $y, $date[0], $date[1]);
    if ($date[2] === null && $iso < add_days($rx, -7, false)) $iso = sprintf('%04d-%02d-%02d', $y + 1, $date[0], $date[1]);   // "January 5th" written in December
    $time = '';
    if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*([ap])\.?\s*m\.?\b(?:\s*\(?(E[SD]?T|Eastern|C[SD]?T|Central|M[SD]?T|Mountain|P[SD]?T|Pacific)\)?)?/i', $t, $h)) {
        $zone = isset($h[4]) && $h[4] !== '' ? strtoupper(substr($h[4], 0, 1)) . 'T' : 'ET';
        $time = (int)$h[1] . ':' . ($h[2] !== '' ? $h[2] : '00') . ' ' . strtoupper($h[3]) . 'M ' . $zone;
    } elseif (preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)\b/', $t, $h)) $time = date('g:i A', strtotime("$h[1]:$h[2]")) . ' ET';
    preg_match('#https://[^\s<>"]*(teams\.microsoft|webex|zoomgov|zoom\.us)[^\s<>"]*#i', $t, $l);
    return ['date' => $iso, 'time' => $time, 'link' => $l[0] ?? ''];
}

/** The interview event for an examiner's agreeing e-mail. Returns a log line, or null when the matter is not found. */
function interview_task(array $p, string $from, string $subject, string $body, string $received, bool $verified, bool $apply = true): ?string {
    $m = null; $s = $subject . ' ' . $body;
    if (preg_match('/Attorney Docket No\.?\s*([A-Z]{3}[\w.\-]*)\)/i', $subject, $x)) $m = q1('SELECT * FROM matters WHERE ref=? AND kind="patent" ORDER BY dead, id DESC', [strtoupper($x[1])]);
    if (!$m && preg_match('/\b(\d{2})\/?(\d{3}),?(\d{3})\b/', $s, $x)) $m = find_matter(['kind' => 'patent', 'app_no' => $x[1] . $x[2] . $x[3], 'docket' => '']);
    if (!$m) return null;
    preg_match('/^\s*"?([^"<@]+?)"?\s*</', $from, $nm); $who = trim($nm[1] ?? '');
    if (preg_match('/^([^,]+),\s*(.+)$/', $who, $w)) $who = $w[2] . ' ' . $w[1];   // "Rubin, Blake" -> "Blake Rubin"
    $note = ($p['time'] !== '' ? $p['time'] . ' | ' : '') . 'Interview confirmed by ' . ($who !== '' ? "Examiner $who" : 'the examiner') . ' (email of ' . substr($received, 0, 10) . ($verified ? '' : '; sender not verified') . ')' . ($p['link'] !== '' ? ' | ' . $p['link'] : '');
    $open = q1('SELECT * FROM events WHERE matter_id=? AND type=? AND status="open"', [$m['id'], INTERVIEW_EVENT]);
    if ($apply) {
        if ($open) run('UPDATE events SET due_date=?, note=? WHERE id=?', [$p['date'], $note, $open['id']]);
        else run('INSERT INTO events(matter_id,type,due_date,status,source,doc_code,mail_date,note) VALUES (?,?,?,"open","gmail","INTERVIEW",?,?)', [$m['id'], INTERVIEW_EVENT, $p['date'], substr($received, 0, 10) ?: today(), $note]);
        audit('interview', INTERVIEW_EVENT . ' ' . $p['date'] . ' ' . $note, (int)$m['id']);
        if (function_exists('waiting_clear')) foreach (q('SELECT id FROM waiting WHERE matter_id=? AND kind="interview" AND cleared_at IS NULL', [$m['id']]) as $w) waiting_clear((int)$w['id'], 'auto', 'examiner confirmed the interview for ' . $p['date'] . ($p['time'] !== '' ? ' ' . $p['time'] : ''));
    }
    return "$m[ref]: examiner interview " . $p['date'] . ($p['time'] !== '' ? ' ' . $p['time'] : '') . ($open ? ' (updated)' : ' docketed');
}

/** The three rules above for one message: a result line when handled, else null. */
function held_task(string $from, string $subject, string $body, string $received, bool $verified, bool $apply = true): ?string {
    if (!preg_match('/@([a-z0-9.\-]+\.)?uspto\.gov\b/i', $from)) return null;
    if ($verified && ($ec = parse_email_change($subject, $body))) return 'email change: ' . implode('; ', email_change_tasks($ec, $received, $apply));
    // An examiner's own Outlook mail is unsigned (see above), so this one also runs unverified: it only puts a date on the dashboard.
    if (!preg_match('/PTOAutomatedInterviewRequest/i', $from) && ($iv = parse_interview_confirm($subject, $body, $received)) && ($r = interview_task($iv, $from, $subject, $body, $received, $verified, $apply))) return $r;
    return null;
}

/** Re-run held_task / uspto_ignorable over messages still waiting in the held list (new rules reach mail that came before them). */
function held_retry(): array {
    held_schema(); $out = [];
    foreach (held_open() as $h) {
        $r = $h['verified'] ? uspto_ignorable((string)$h['sender'], (string)$h['subject']) : null; $r = $r !== null ? 'ignored: ' . $r : held_task((string)$h['sender'], (string)$h['subject'], (string)$h['body'], (string)$h['received'], (bool)$h['verified']);
        if ($r === null) continue;
        run('UPDATE uspto_held SET status="handled", handled_by="auto", handled_at=datetime("now"), note=? WHERE msg_id=?', [$r, $h['msg_id']]);
        run('UPDATE gmail_seen SET result=? WHERE msg_id=?', [$r, $h['msg_id']]); $out[] = $h['subject'] . ': ' . $r;
    }
    return $out;
}
