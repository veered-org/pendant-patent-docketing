<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
require_once __DIR__ . '/catalogimg.php';   // logo_url(): the firm's own copies of logo images
require_once __DIR__ . '/pubreport.php';
require_once __DIR__ . '/tmtimeline.php';   // the timeline card on every trademark client email (2026-10-04)   // notices of publication: basis-aware client email + Dashboard panel (2026-10-04)
require_once __DIR__ . '/regreport.php';
require_once __DIR__ . '/patreport.php';   // issued patents on the same Dashboard panel (2026-10-04)   // trademark registrations: certificate + issuance letter email, Dashboard panel (2026-10-04)
require_once __DIR__ . '/reconcile.php';   // maintenance dates checked against the USPTO's registration / grant date (audit 2026-10-02)
/**
 * Nightly job. For every open event whose type has a template: at each lead (30 days, 7 days) create a reminder.
 *  - template not approved         → held (nothing goes out on an unapproved template)
 *  - no client email on file       → held
 *  - otherwise                     → pending: NOTHING goes out until the attorney or staff opens it and clicks Send (invoice items are then saved as Gmail drafts)
 * Reminders are keyed (event, lead) so a re-run never sends twice.
 */
/** Template for an event (2026-10-04, RWG127.003.003): a Notice of Allowance on a Hague design (35/ series) uses its own wording
 *  (no maintenance fees, issue fee payable through WIPO too) when that template exists; everything else uses the event type. */
const HAGUE_NOA_TEMPLATE = 'Respond to Office Action - NOA (Hague Design)';
const DESIGN_NOA_TEMPLATE = 'Respond to Office Action - NOA (Design)';   // 2026-10-04 (XYZ019): US designs (29/, 30/) - no maintenance fees
function tpl_type(string $type, string $appNo): string {
    if ($type !== 'Respond to Office Action - NOA') return $type;
    $d = preg_replace('/\D/', '', $appNo);
    return preg_match('/^35\d{6}$/', $d) ? HAGUE_NOA_TEMPLATE : (preg_match('/^(29|30)\d{6}$/', $d) ? DESIGN_NOA_TEMPLATE : $type);
}
function tpl_for(array $tpls, string $type, string $appNo): ?array { return $tpls[tpl_type($type, $appNo)] ?? $tpls[$type] ?? null; }
function money(string $n): string { return $n === '' || !is_numeric($n) ? $n : '$' . number_format((float)$n, ((float)$n == floor((float)$n)) ? 0 : 2); }
/** "Government fee: $860; our fee: $500; total: $1,360 (small entity)" from a template's fee fields; '' when no fee is set. */
function fee_line(?array $t): string {
    if (!$t) return '';
    $g = trim((string)($t['gov_fee'] ?? '')); $o = trim((string)($t['our_fee'] ?? '')); $n = trim((string)($t['fee_note'] ?? ''));
    if ($g === '' && $o === '') return '';
    $parts = []; if ($g !== '') $parts[] = 'Government fee: ' . money($g); if ($o !== '') $parts[] = 'our fee: ' . money($o);
    if ($g !== '' && $o !== '' && is_numeric($g) && is_numeric($o)) $parts[] = 'total: ' . money((string)((float)$g + (float)$o));
    return ucfirst(implode('; ', $parts)) . ($n !== '' ? " ($n)" : '');
}
/** For a "Remind Client" tickler: the next real (non-notice) open deadline on the matter, with the matter columns the send page needs; null if none. */
function remind_target(array $ev): ?array {
    if (!preg_match('/^Remind Client/i', $ev['type']) || empty($ev['matter_id'])) return null;
    foreach (q('SELECT e.*, m.ref, m.title, m.app_no, m.reg_no, m.country, m.client_code FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.matter_id=? AND e.status="open" AND e.due_date >= ? AND e.id<>? ORDER BY e.due_date', [$ev['matter_id'], $ev['due_date'], $ev['id'] ?? 0]) as $cand)
        if (!is_notice_type($cand['type'])) return $cand;
    return null;
}
const OPP_END_EVENT = 'Publication - Opposition Period Ends';   // 2026-10-04: client email once it has passed
const OPP_REPORT_TEMPLATE = 'Report Opposition Period Ended';
/** Trademark filing report: the next steps after publication, by basis (blank/unknown basis keeps the attorney's both-ways CHL001 paragraph). */
function filing_next_text(string $basis): string {
    $tail = ' The fees paid to date cover only the actual filing and not these subsequent steps.';
    if (str_contains($basis, '1b')) return 'Because this application was filed on an intent-to-use basis, if all goes well you will receive a Notice of Allowance setting out a six month window to file a Statement of Use (typically $500/class) showing the U.S. Trademark Office how you are using the mark. If the mark is not yet in use by then, the window can be extended in six month steps.' . $tail;
    if (str_contains($basis, '1a')) return 'Because we filed this application showing the mark already in use, no Statement of Use is needed: if all goes well, the mark will go to issuance after the opposition period (typically $525/mark).' . $tail;
    if (preg_match('/44e|66a/', $basis)) return 'Because this application is based on your foreign registration, no Statement of Use is needed: if all goes well, the mark will go to issuance after the opposition period (typically $525/mark).' . $tail;
    return 'If all goes well, you will receive a Notice of Allowance setting out a six month window to file a Statement of Use (typically $500/class) showing the U.S. Trademark Office how you are using the mark. If we filed the mark showing use already, then the mark will, instead, go to issuance at this point (typically $525/mark).' . $tail;
}
/** Opposition period ended: what to expect next, by basis. */
function opposition_next_text(string $basis): string {
    if (str_contains($basis, '1b')) return "Because this application was filed on an intent-to-use basis, the next step is a Notice of Allowance from the U.S. Trademark Office, which we expect in the coming weeks. The Notice of Allowance opens a six month window to file a Statement of Use showing how the mark is being used; if the mark is not yet in use, that window can be extended in six month steps. I will be in touch as soon as the Notice of Allowance arrives.";
    if (preg_match('/1a|44e|66a/', $basis)) return "No further filings are needed from you at this stage: the registration certificate should now issue in due course, which we expect in the coming weeks. I will send it to you as soon as it arrives.";
    return "We now expect either a Notice of Allowance (if the application was filed on an intent-to-use basis), which opens a six month window to file a Statement of Use, or, otherwise, issuance of the registration certificate in due course. I will be in touch as soon as either arrives.";
}
function fill_template(string $tpl, array $ev, array $m, array $c, ?array $t = null): string {
    $g = trim((string)($t['gov_fee'] ?? '')); $o = trim((string)($t['our_fee'] ?? ''));
    // A window-opening event ("... Begin", "... Window Open") is NOT the deadline: {DEADLINE} is the paired End/Due event on the same matter.
    $dlType = paired_deadline_type($ev['type']); $dl = null;
    if ($dlType && !empty($ev['matter_id'])) $dl = q1('SELECT due_date FROM events WHERE matter_id=? AND type=? AND status IN ("open","proposed") ORDER BY due_date LIMIT 1', [$ev['matter_id'], $dlType]);
    // A "Remind Client" tickler (DocketTrak's reminder date, a month before the real deadline) points at the next real deadline on the matter.
    if (!$dlType && preg_match('/^Remind Client/i', $ev['type']) && !empty($ev['matter_id'])) {
        foreach (q('SELECT type, due_date FROM events WHERE matter_id=? AND status="open" AND due_date >= ? AND id<>? ORDER BY due_date', [$ev['matter_id'], $ev['due_date'], $ev['id'] ?? 0]) as $cand)
            if (!is_notice_type($cand['type'])) { $dl = $cand; $dlType = $cand['type']; break; }
    }
    $map = ['{DEADLINE}' => $dl ? fmt_long($dl['due_date']) : fmt_long($ev['due_date']), '{DEADLINE_EVENT}' => $dlType ?: $ev['type'],
            '{FEES}' => fee_line($t), '{GOV_FEE}' => money($g), '{OUR_FEE}' => money($o), '{TOTAL_FEE}' => (is_numeric($g) && is_numeric($o)) ? money((string)((float)$g + (float)$o)) : '', '{FEE_NOTE}' => trim((string)($t['fee_note'] ?? '')),
            '{FILE}' => $m['ref'], '{CLIENT}' => $c['name'] ?: $c['code'], '{TITLE}' => mark_html($m), '{MARK}' => mark_html($m), '{MARK_ID}' => mark_id_html($m),
            '{APP}' => num_html(fmt_app($m['app_no'], $m['country'] ?? ''), num_url($m, 'app')), '{REG}' => num_html(fmt_reg($m['reg_no']), num_url($m, 'reg')),
            '{PATENT}' => num_html(fmt_reg($m['reg_no']), num_url($m, 'reg')), '{DUE}' => fmt_long($ev['due_date']), '{EVENT}' => $ev['type'], '{COUNTRY}' => $m['country'], '{DAYS}' => (string)days_until($ev['due_date']),
            '{REG_YEARS}' => '', '{WINDOW_YEARS}' => '', '{GOODS_WORD}' => 'goods', '{GOODS_LIST}' => '', '{GRACE_REPLY_BY}' => '', '{MISSED_DEADLINE}' => '',
            '{MISSED_ACTION}' => missed_action((string)($ev['type'] ?? '')),
            '{MARK_COLOR}' => '<m>' . (clean_title($m['title'] ?? '') ?: 'design') . '</m>',   // the mark name alone, in color
            '{GOODS_INLINE}' => goods_inline(!empty($ev['matter_id']) ? (string)(q1('SELECT goods FROM matters WHERE id=?', [(int)$ev['matter_id']])['goods'] ?? '') : ''),
            '{REPLY_BY}' => fmt_long(min($ev['due_date'], max(add_days($ev['due_date'], -14, false), add_days(today(), 3, false)))),
            // 2026-09-30: invoice due 30 days from today; if the filing deadline is under 30 days away, "as soon as possible".
            '{PAY_BY}' => days_until($dl ? $dl['due_date'] : $ev['due_date']) < 30 ? 'as soon as possible,' : 'by ' . fmt_long(add_days(today(), 30, false))];
    // 2026-09-27: fees are never put in a reminder email. The fee placeholders render as nothing, and a line left empty goes.
    foreach (['{FEES}', '{GOV_FEE}', '{OUR_FEE}', '{TOTAL_FEE}', '{FEE_NOTE}'] as $k) $map[$k] = '';
    // 2026-10-04 (patent Office Action report, lib/oareport.php): the invoice is due two months before the response deadline, and the
    // examiner block (name, art unit, statistics sources) is filled for utility applications, left out for designs.
    if (function_exists('is_oa_report_template') && is_oa_report_template((string)($t['event_type'] ?? ''))) $map['{PAY_BY}'] = oa_pay_by($dl ? $dl['due_date'] : $ev['due_date']);
    if (str_contains($tpl, '{EXAMINER_BLOCK}')) $map['{EXAMINER_BLOCK}'] = function_exists('examiner_block') ? examiner_block(!empty($ev['matter_id']) ? (q1('SELECT * FROM matters WHERE id=?', [(int)$ev['matter_id']]) ?: $m) : $m, $ev) : '';
    // 2026-10-04 ('Report USPTO Notice', lib/oareport.php): the paper's name, and its deadline when the same paper set one
    if (preg_match('/\{(NOTICE|FILED\}|NONPROV_DEADLINE|CONTACT_BY)/', $tpl) && function_exists('notice_fill')) { $map += notice_fill($ev); if (preg_match('/^[AEIOU]/i', $map['{NOTICE}'])) $tpl = preg_replace('/\ba \{NOTICE\}/', 'an {NOTICE}', $tpl); }
    if (str_contains($tpl, '{NEXT_SOU}')) {   // the next open link of the Statement of Use chain after this deadline (Report Extension Filed)
        $nx = !empty($ev['matter_id']) ? q1('SELECT type, due_date FROM events WHERE matter_id=? AND status="open" AND type LIKE "Statement of Use%" AND due_date>? ORDER BY due_date LIMIT 1', [(int)$ev['matter_id'], (string)$ev['due_date']]) : null;
        $map['{NEXT_SOU}'] = $nx ? '<b>' . fmt_long($nx['due_date']) . '</b> ' . $nx['type'] : '';
    }
    if (str_contains($tpl, '{SUMMARY}')) $map['{SUMMARY}'] = summary_block($m, $t, $map['{DEADLINE}'], (string)($ev['type'] ?? ''));
    if (str_contains($tpl, '{NOA_FEES}') && function_exists('noa_fees')) $map['{NOA_FEES}'] = noa_fees(count(goods_classes(!empty($ev['matter_id']) ? (string)(q1('SELECT goods FROM matters WHERE id=?', [(int)$ev['matter_id']])['goods'] ?? '') : '')));
    // 2026-10-04 (lib/pubreport.php): the publication email's next step follows the basis at filing and now; the filing date is named.
    if (preg_match('/\{(PUB_NEXT|FILED_CLAUSE)\}/', $tpl) && function_exists('pub_story')) {
        $mm = !empty($ev['matter_id']) ? (q1('SELECT * FROM matters WHERE id=?', [(int)$ev['matter_id']]) ?: $m) : $m;
        $cur = function_exists('tm_basis') ? tm_basis($mm) : (string)($mm['basis'] ?? '');
        if (!empty($mm['id'])) $mm = q1('SELECT * FROM matters WHERE id=?', [(int)$mm['id']]) ?: $mm;   // tm_basis' TSDR call refreshes basis_filed
        $map['{PUB_NEXT}'] = pub_next_text(pub_story($cur, (string)($mm['basis_filed'] ?? ''))[0]);
        $fd = substr((string)($mm['filing_date'] ?? ''), 0, 10); $map['{FILED_CLAUSE}'] = $fd !== '' ? ', filed on ' . fmt_long($fd) . ',' : '';
    }
    if (str_contains($tpl, '{PUB_DATE}') || str_contains($tpl, '{PUB_STARTS}')) { $pd = (string)($ev['mail_date'] ?? '') ?: (string)$ev['due_date']; $map['{PUB_DATE}'] = fmt_long($pd); $map['{PUB_STARTS}'] = $pd > today() ? 'will start' : 'started'; }
    if (preg_match('/\{(PATENT_NO|ISSUE_DATE|MAINT_SCHEDULE|MAINT_TABLE|DESIGN_EXPIRES|TITLE_TEXT|FOR_TITLE|TITLED)\}/', $tpl) && function_exists('maint_schedule')) {
        $iss = function_exists('issue_report_issue_date') && in_array((string)($ev['type'] ?? ''), NOTICE_ISSUE_TYPES, true) ? issue_report_issue_date($ev) : ((string)($ev['mail_date'] ?? '') ?: (string)$ev['due_date']); $mm = !empty($ev['matter_id']) ? (q1('SELECT * FROM matters WHERE id=?', [(int)$ev['matter_id']]) ?: $m) : $m;
        $pno = (string)(($mm['issue_patent_no'] ?? '') ?: ($mm['reg_no'] ?? '')); if ($pno === '' && !empty($mm['ref']) && function_exists('issue_ntf_patent_no')) $pno = issue_ntf_patent_no((string)$mm['ref']); $map['{PATENT_NO}'] = num_html(fmt_patent_no($pno)); $map['{ISSUE_DATE}'] = fmt_long($iss);
        $map['{MAINT_SCHEDULE}'] = maint_schedule($iss); $map['{MAINT_TABLE}'] = '<maint>' . str_replace('-', '', $iss) . '</maint>';   // rendered as the styled table (lib/dates.php mail_body_html) $map['{DESIGN_EXPIRES}'] = fmt_long(add_months($iss, design_term_years($mm, $iss) * 12, false));
    }
    // 2026-10-02: the status link goes to this application's own TSDR page, not the TSDR home page.
    if (str_contains($tpl, '{STATUS_LINK}')) { $su = num_url($m, 'app'); $map['{STATUS_LINK}'] = $su !== '' ? num_html('Serial No. ' . fmt_app((string)$m['app_no'], (string)($m['country'] ?? '')) . ' on TSDR', $su) : 'http://tsdr.uspto.gov'; }
    // Title clauses (issuance + filing reports): with no title on record (XYZ001) the clause drops out.
    $tt = clean_title((string)($m['title'] ?? '')); $map['{TITLE_TEXT}'] = $tt; $map['{FOR_TITLE}'] = $tt !== '' ? ' for ' . $tt : ''; $map['{TITLED}'] = $tt !== '' ? ', titled ' . $tt . ',' : '';
    // 2026-10-04: what comes next depends on the filing basis: intent to use -> Notice of Allowance + Statement of Use; otherwise registration.
    if (preg_match('/\{(ITU_NEXT|OPP_NEXT)\}/', $tpl)) { $b = function_exists('tm_basis') ? tm_basis($m) : (string)($m['basis'] ?? ''); $map['{ITU_NEXT}'] = filing_next_text($b); $map['{OPP_NEXT}'] = opposition_next_text($b); }
    // 2026-10-07: the filing report's examination backlog is the live USPTO figure the timeline card shows.
    if (str_contains($tpl, '{TM_BACKLOG}') && function_exists('tm_backlog_months')) $map['{TM_BACKLOG}'] = (string)round((float)tm_backlog_months(str_contains(function_exists('tm_basis') ? tm_basis($m) : (string)($m['basis'] ?? ''), '66a')));   // whole months: "about 4 months" (2026-10-07)
    if (str_contains($tpl, '{NEXT_WINDOW}')) $map['{NEXT_WINDOW}'] = next_window_sentence($ev);
    if (preg_match('/\{(REG_BLOCK|REG_MAINT|REG_DATE|LETTER_CLAUSE)\}/', $tpl) && function_exists('reg_fill')) $map += reg_fill($ev, $m);   // 2026-10-04 (lib/regreport.php)
    // Receipt reports (lib/held.php): what was filed / paid, read back from the correspondence row the receipt made.
    if (preg_match('/\{(FILED_PAPER|FILED_DATE|FEE_YEAR|REEL_FRAME|PROPERTY|ASSIGNMENT_ID|YOUR_FILING|NEXT_RENEWAL)\}/', $tpl)) {
        $cr = !empty($ev['matter_id']) ? q1('SELECT summary FROM correspondence WHERE matter_id=? AND doc_code=? AND mail_date=? ORDER BY id DESC LIMIT 1', [$ev['matter_id'], (string)($ev['doc_code'] ?? ''), (string)($ev['mail_date'] ?? '')]) : null;
        $sum = (string)($cr['summary'] ?? '');
        $form = preg_match('/^Filed: (.+)$/', $sum, $x) ? trim($x[1]) : '';
        $map['{YOUR_FILING}'] = your_filing($form);   // 2026-10-03: "just put 'your trademark renewal documents'"
        $map['{NEXT_RENEWAL}'] = preg_match('/renewal|8 (&|and) (9|15)|section 8|section 71|incontestab/i', $form) ? next_renewal_paragraph($ev) : '';
        $map['{FILED_PAPER}'] = preg_replace_callback('/\b(To|Of|For|And|Under|The|In)\b/', fn($w) => strtolower($w[1]), preg_replace('/\s+/', ' ', preg_match('/^Filed: (.+)$/', $sum, $x) ? trim($x[1]) : 'filing'));
        $map['{FILED_DATE}'] = fmt_long((string)(($ev['mail_date'] ?? '') ?: $ev['due_date']));
        $map['{FEE_YEAR}'] = preg_match('/(3\.5|7\.5|11\.5)-year/', $sum, $y) ? $y[1] . '-year ' : '';
        $map['{REEL_FRAME}'] = preg_match('#reel/frame (\d+/\d+)#i', $sum, $rf) ? $rf[1] : '[reel/frame]';   // read off the Notice by oa_fetch
        $map['{ASSIGNMENT_ID}'] = preg_match('/Assignment ID (\d+)/', $sum, $ai) ? $ai[1] : '[Assignment ID]';
        $map['{PROPERTY}'] = ($m['kind'] ?? '') === 'trademark' ? (trim((string)($m['reg_no'] ?? '')) !== '' ? 'trademark' : 'trademark application') : (trim((string)($m['reg_no'] ?? '')) !== '' ? 'patent' : 'patent application');
    }
    if (preg_match('/\{(REG_YEARS|WINDOW_YEARS|GOODS_WORD|GOODS_LIST|GRACE_REPLY_BY|MISSED_DEADLINE)\}/', $tpl)) $map = array_merge($map, grace_fields($ev, $m));
    $out = strtr($tpl, $map);
    // An empty title / mark leaves "REF:  - renewal due" or "the  property": close those gaps (the attorney's double spaces between sentences stay).
    $out = preg_replace(['/^([^\n:]+):\s+-\s+/', '/\b(the|of|for) {2,}(?=\S)/'], ['$1: ', '$1 '], $out);
    // A mark that starts with THE (2026-10-04 audit, XYZ002): "how the THE DIGITAL HUMAN ADVANTAGE mark" -> "how THE DIGITAL HUMAN ADVANTAGE mark"
    $out = preg_replace('/\b[Tt]he ((?:<[mn][^>]*>)?)(THE\b)/', '$1$2', $out);
    // 2026-10-05 (KINGLINE): under the trademark timeline card, no line repeating what the card shows (mark, logo, numbers,
    // registration date, file number), whichever template or block wrote it ({SUMMARY}, {REG_BLOCK}, the Notice of Allowance text).
    if (function_exists('pt_card_applies') && pt_card_applies($m, $ev))   // the patent card (2026-10-06) shows title, numbers, dates, file number
        $out = preg_replace('#^<b>(Title|Application No\.|Application Number|U\.S\. Application No\.|Patent No\.|U\.S\. Patent No\.|Patent Number|Filing date|Filing Date|Issue Date|Our file number|Your reference|Applicant|Docket/Reference Number|Docket/Reference No\.):</b>.*$\n?#m', '', $out);
    if (function_exists('tm_card_applies') && tm_card_applies($m, $ev))
        $out = preg_replace('#^(<logo>.*|<b>(Mark|U\.S\. Serial Number|Serial Number|U\.S\. Registration Number|Registration Number|Registration Date|Our file number|Your reference|Applicant|Docket/Reference Number|Docket/Reference No\.):</b>.*)$\n?#m', '', $out);
    return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/^[ \t]+$/m', '', $out)));
}
/** DocketTrak's import left "NO TITLE IMPORTED" / "No Title Specified" in titles: treat those as no title at all. */
function clean_title(?string $t): string { $t = trim((string)$t); return preg_match('/^no title (imported|specified)$/i', $t) ? '' : $t; }
/** Email markup (2026-09-29, "A"): <m>…</m> = the trademark name (bold dark gold), <n>…</n> = a number (navy). Subjects get the
 *  plain text (fill_subject). The same data is treated the same way in every template. */
function num_html(string $v, string $url = ''): string { return trim($v) === '' ? '' : ($url !== '' ? '<n u="' . $url . '">' : '<n>') . $v . '</n>'; }
/**
 * Where a number in a client email links (2026-10-01): a US trademark SERIAL number -> its TSDR status page; a US trademark
 * REGISTRATION number or a US PATENT number -> that property's page in the firm's public catalogs (firm catalogs_url), falling back to TSDR for a
 * registration with no page yet. Patent APPLICATION numbers are not linked. $which: 'app' | 'reg'. '' = no link.
 */
function num_url(array $m, string $which): string {
    if (!preg_match('/^(|United States|US|USA)$/i', trim((string)($m['country'] ?? '')))) return '';
    $tm = ($m['kind'] ?? '') === 'trademark'; $app = preg_replace('/\D/', '', (string)($m['app_no'] ?? '')); $reg = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', preg_replace('/^\s*US\s*/i', '', (string)($m['reg_no'] ?? ''))));
    if ($tm && $which === 'app') return strlen($app) === 8 ? "https://tsdr.uspto.gov/#caseNumber=$app&caseType=SERIAL_NO&searchType=statusSearch" : '';
    // 2026-10-15: trademark serial AND registration numbers -> TSDR; a published (or issued) patent application -> Patent Center;
    // an issued patent -> its the firm's site page (Patent Center when the site has none yet)
    if ($tm && $which === 'reg') return $reg !== '' ? "https://tsdr.uspto.gov/#caseNumber=$reg&caseType=US_REG_NO&searchType=statusSearch" : '';
    $pc = strlen($app) === 8 ? "https://patentcenter.uspto.gov/applications/$app" : '';
    if (!$tm && $which === 'reg' && $reg !== '') return site_page('patent', $reg) ?: $pc;
    if (!$tm && $which === 'app' && (trim((string)($m['pub_no'] ?? '')) !== '' || $reg !== '')) return $pc;
    return '';
}
/** The property's page in the firm's catalogs: catalogs/patent/p/patent-<NUMBER>-<slug>.html, catalogs/trademark/t/trademark-<SERIAL>-<slug>.html. '' when none. */
function site_page(string $kind, string $num): string {
    static $cache = [];
    $root = rtrim((string)($GLOBALS['cfg']['site_catalogs'] ?? firm('catalogs_root')), '/'); $num = preg_replace('/[^0-9A-Za-z]/', '', $num);
    if ($num === '' || $root === '' || !firm('catalogs_url') || !is_dir($root)) return '';
    $sub = $kind === 'patent' ? 'patent/p/patent-' : 'trademark/t/trademark-';
    if (!array_key_exists("$kind:$num", $cache)) { $g = glob("$root/$sub$num-*.html") ?: []; $cache["$kind:$num"] = $g ? rtrim(firm('catalogs_url'), '/') . '/' . dirname($sub) . '/' . rawurlencode(basename($g[0])) : ''; }
    return $cache["$kind:$num"];
}
function mark_html(array $m): string { $t = clean_title($m['title'] ?? ''); return (($m['kind'] ?? '') === 'trademark' && $t !== '') ? '<m>' . $t . '</m>' : $t; }
function mark_id_html(array $m): string {
    if (($m['kind'] ?? '') !== 'trademark') return clean_title($m['title'] ?? '');
    $plain = mark_id($m); $t = clean_title($m['title'] ?? '');
    $u = num_url($m, trim((string)($m['reg_no'] ?? '')) !== '' ? 'reg' : 'app'); $open = $u !== '' ? '<n u="' . $u . '">' : '<n>';
    return $t !== '' && str_starts_with($plain, $t) ? '<m>' . $t . '</m>' . preg_replace('/\((Reg\. No\. |Serial No\. |Application No\. )([^)]+)\)$/', '($1' . $open . '$2</n>)', substr($plain, strlen($t))) : preg_replace('/(Reg\. No\. |Serial No\. |Application No\. )([^)]+)/', '$1' . $open . '$2</n>', $plain);
}
/**
 * Foreign-associate fields of a matter (2026-10-14, XYZ001): their_ref, instructing, ap_email, owner (the applicant), ref.
 * $m may be a full matters row, an event row joined to its matter (matter_id), or a bare ['id' => matter id]. null = no such matter.
 */
function assoc_matter(array $m): ?array {
    if (array_key_exists('their_ref', $m) && array_key_exists('instructing', $m)) return ['their_ref' => trim((string)$m['their_ref']), 'instructing' => trim((string)$m['instructing']), 'ap_email' => trim((string)($m['ap_email'] ?? '')), 'owner' => trim((string)($m['owner'] ?? '')), 'ref' => (string)($m['ref'] ?? '')];
    $id = (int)($m['matter_id'] ?? 0) ?: (isset($m['ref']) ? (int)(q1('SELECT id FROM matters WHERE ref=? LIMIT 1', [$m['ref']])['id'] ?? 0) : (int)($m['id'] ?? 0));
    if (!$id) return null;
    $r = q1('SELECT ref, their_ref, instructing, ap_email, owner FROM matters WHERE id=?', [$id]);
    return $r ? array_map(fn($v) => trim((string)$v), $r) : null;
}
/** A matter that came through a foreign associate: it has their file number or an instructing attorney. */
function is_assoc_matter(array $m): bool { $a = assoc_matter($m); return $a && ($a['their_ref'] !== '' || $a['instructing'] !== ''); }
/**
 * Subject (2026-10-14): their file number first, then ours, then the applicant: "HIP-123 / XYZ010 / Wingfield GmbH: ...".
 * Works on any subject that names our file number (template, reply "Re: ...", a several-file subject): their number goes right before
 * ours; a subject that never names ours gets the whole heading in front. Already carrying their number = left alone.
 */
function assoc_subject(string $subj, array $m): string {
    $a = assoc_matter($m); if (!$a || ($a['their_ref'] === '' && $a['instructing'] === '') || $a['ref'] === '') return $subj;
    if ($a['their_ref'] !== '' && stripos(str_ireplace($a['ref'], '', $subj), $a['their_ref']) !== false) return $subj;   // RWG/CAW: theirs sits inside ours
    $tail = $a['owner'] !== '' ? ' / ' . $a['owner'] : '';
    if ($a['their_ref'] === '' && ($tail === '' || stripos($subj, $a['owner']) !== false)) return $subj;
    preg_match('/^((?:\s*(?:re|fwd?|aw|wg)\s*:\s*)*)(.*)$/is', $subj, $sp); [$pre, $rest] = [$sp[1], $sp[2]];
    $pos = stripos($rest, $a['ref']);
    if ($pos === false) return $pre . ($a['their_ref'] !== '' ? $a['their_ref'] . ' / ' : '') . $a['ref'] . $tail . ': ' . $rest;
    $end = $pos + strlen($a['ref']); $alone = substr($rest, $end, 1) === ':';   // "XYZ010: ..." gets the applicant too; "XYZ014, XYZ015: ..." does not
    return $pre . substr($rest, 0, $pos) . ($a['their_ref'] !== '' ? $a['their_ref'] . ' / ' : '') . $a['ref'] . ($alone ? $tail : '') . substr($rest, $end);
}
/** Rows for the summary card above our file number: [label, plain value]. Their reference goes first (2026-10-14). */
function assoc_card_rows(array $m): array {
    $a = assoc_matter($m); if (!$a) return [[], []];
    $before = $a['their_ref'] !== '' ? [['Your reference', $a['their_ref']]] : [];
    $after = is_assoc_matter($m) && $a['owner'] !== '' ? [['Applicant', $a['owner']]] : [];
    return [$before, $after];
}
/**
 * The guard (2026-10-14, after XYZ001 went to another client's applicant): addresses in $to that are not on file for THIS
 * matter. An associate matter with an instructing attorney allows only that attorney, its own accounts payable, and the client's
 * accounts payable; any other matter allows every address the client record holds. Our own addresses are always fine.
 */
function unknown_recipients(string $to, array $m, array $c): array {
    $a = assoc_matter($m) ?? ['instructing' => '', 'ap_email' => ''];
    $ok = $a['instructing'] !== '' ? implode(';', [$a['instructing'], $a['ap_email'], $c['email_ap'] ?? ''])
                                   : implode(';', [$a['ap_email'], $c['email'] ?? '', $c['email_tm'] ?? '', $c['email_patent'] ?? '', $c['email_ap'] ?? '', $c['alt_emails'] ?? '']);
    $ok = array_map('strtolower', split_addresses($ok));
    return array_values(array_filter(split_addresses($to), fn($e) => !firm_address($e) && !in_array(strtolower($e), $ok, true)));
}
function plain_text(string $s): string { return str_replace(['<m>', '</m>', '<n>', '</n>', '<sm>', '</sm>'], '', preg_replace(['#<logo>\d*</logo>#', '#<n u="[^"]*">#'], '', $s)); }
function fill_subject(string $tpl, array $ev, array $m, array $c, ?array $t = null): string { return assoc_subject(plain_text(fill_template($tpl, $ev, $m, $c, $t)), $m); }
/** "B": the labelled summary at the top of a client email: mark or patent, its number, our file number, and the date that matters. */
/** US serial whose TSDR drawing is a logo (drawing code 2, 3 or 5), else ''. */
function logo_serial(array $m): string {
    if (($m['kind'] ?? '') !== 'trademark') return '';
    $d = $m['mark_drawing'] ?? null; if ($d === null && !empty($m['ref'])) $d = q1('SELECT mark_drawing FROM matters WHERE ref=? AND kind="trademark"', [$m['ref']])['mark_drawing'] ?? '';
    $sn = preg_replace('/\D/', '', (string)($m['app_no'] ?? ''));
    return in_array((string)$d, ['2', '3', '5'], true) && strlen($sn) === 8 ? $sn : '';
}
function logo_url(string $sn): string { return (function_exists('catalog_logo_url') ? catalog_logo_url($sn) : null) ?? 'https://tsdr.uspto.gov/img/' . $sn . '/large'; }   // our catalog copy first: TSDR rate-limits hotlinks (429)
function summary_block(array $m, ?array $t, string $deadline, string $evType): string {
    $us = (bool)preg_match('/^(|United States|US|USA)$/i', trim((string)($m['country'] ?? ''))); $L = [];
    $reg = trim((string)($m['reg_no'] ?? '')); $app = trim((string)($m['app_no'] ?? '')); $title = clean_title($m['title'] ?? '');
    if (($m['kind'] ?? '') === 'trademark') {
        $L[] = '<b>Mark:</b> ' . ($title !== '' ? '<m>' . $title . '</m>' : 'design mark');
        if ($us && ($sn = logo_serial($m)) !== '') $L[] = '<logo>' . $sn . '</logo>';   // small logo under the mark name (2026-09-30)
        if ($reg !== '') $L[] = '<b>Registration Number:</b> ' . num_html(($us ? 'US ' : '') . fmt_reg($reg), num_url($m, 'reg'));
        elseif ($app !== '') $L[] = '<b>' . ($us ? 'U.S. Serial Number' : 'Application Number') . ':</b> ' . num_html(fmt_app($app, $m['country'] ?? ''), num_url($m, 'app'));
    } else {
        if ($reg !== '') $L[] = '<b>' . ($us ? 'U.S. Patent Number' : 'Patent Number') . ':</b> ' . num_html(fmt_reg($reg), num_url($m, 'reg'));
        elseif ($app !== '') $L[] = '<b>Application Number:</b> <n>' . fmt_app($app, $m['country'] ?? '') . '</n>';
        if ($title !== '') $L[] = '<b>Title:</b> ' . $title;
    }
    [$aBefore, $aAfter] = assoc_card_rows($m);   // their file number first, the applicant after ours (2026-10-14)
    foreach ($aBefore as [$k, $v]) $L[] = '<b>' . $k . ':</b> ' . $v;
    $L[] = '<b>Our file number:</b> ' . $m['ref'];
    foreach ($aAfter as [$k, $v]) $L[] = '<b>' . $k . ':</b> ' . $v;
    // 2026-10-05 (KINGLINE): an email that opens with the trademark timeline card (or a patent filing report's card) already shows
    // the mark and its numbers there; not again in the body. Only the deadline, which the card does not give, stays.
    if ((function_exists('tm_card_applies') && tm_card_applies($m, ['type' => $evType])) || (function_exists('pt_card_applies') && pt_card_applies($m, ['type' => $evType])) || (function_exists('is_filing_report') && is_filing_report($evType))) $L = [];
    $type = (string)($t['event_type'] ?? $evType);
    $report = defined('FEE_PAID_TEMPLATES') && in_array($type, array_values(FEE_PAID_TEMPLATES), true);
    if (!$report && !str_starts_with($type, 'Report ') && $deadline !== '') $L[] = '<b>' . (is_grace_email_type($type) ? 'Last day (grace period)' : (in_array($type, ABANDON_TEMPLATES, true) ? 'Missed deadline' : 'Deadline')) . ':</b> ' . $deadline;
    return implode("\n", $L);
}
/** First mention of a trademark in an email (2026-09-28): "MARK (Reg. No. US 1,234,567)" once registered, else "MARK (Serial No. 99/616,693)". Patents: the title alone. */
function mark_id(array $m): string {
    $t = clean_title($m['title'] ?? ''); if (($m['kind'] ?? '') !== 'trademark') return $t;
    if ($t === '') $t = 'design';   // design-only marks carry no words: "the design (Reg. No. US 1,234,567) mark"
    $us = preg_match('/^(|United States|US|USA)$/i', trim((string)($m['country'] ?? '')));
    $reg = trim((string)($m['reg_no'] ?? '')); $app = trim((string)($m['app_no'] ?? ''));
    $no = $reg !== '' ? 'Reg. No. ' . ($us ? 'US ' : '') . fmt_reg($reg) : ($app !== '' ? ($us ? 'Serial No. ' : 'Application No. ') . fmt_app($app, $m['country'] ?? '') : '');
    return $no === '' ? $t : ($t === '' ? $no : "$t ($no)");
}
/** Template text with its first mention of the mark switched to {MARK_ID}; the older hand-written number forms fold into it. Idempotent. */
function mark_id_template(string $x): string {
    if (str_contains($x, '{MARK_ID}')) return $x;
    $x = str_replace(['Serial No. {APP} for {MARK}', '{MARK} (Reg. No. {REG})', '{MARK} (SN {APP})'], '{MARK_ID}', $x, $n);
    if ($n) return $x;
    $pos = array_filter([strpos($x, '{MARK}'), strpos($x, '{TITLE}')], fn($p) => $p !== false); if (!$pos) return $x;
    $p = min($pos); $len = substr($x, $p, 6) === '{MARK}' ? 6 : 7;
    return substr($x, 0, $p) . '{MARK_ID}' . substr($x, $p + $len);
}
/** Section 8 & 15 / 8 & 9 grace-period email (2026-09-28): goes out the day after the regular deadline passes. */
const GRACE_LEAD = 183;   // lead value meaning "the day after the original deadline" (= 6 months before the grace period ends)
const GRACE_EMAIL_TYPES = ['Section 8 and 15 Declarations Grace Period Ends', 'Section 8 and 9 Grace Period Ends', 'Section 8 Declaration Grace Period Ends',
    'Section 71 Declaration Grace Period Ends', 'Section 71 and 15 Declarations Grace Period Ends',
    '3 1/2 Year Maintenance Fee Grace Period', '7 1/2 Year Maintenance Fee Grace Period', '11 1/2 Year Maintenance Fee Grace Period'];
function is_grace_email_type(string $type): bool { return in_array($type, GRACE_EMAIL_TYPES, true); }
/** A deadline already past whose grace period is docketed (2026-09-29, XYZ001): the open "... Grace Period" event on the same
 *  matter that follows it within 7 months, when that grace type has a template; the send page then uses the grace-period wording. */
function grace_successor(array $ev): ?array {
    if (empty($ev['matter_id']) || stripos((string)$ev['type'], 'grace') !== false || days_until($ev['due_date']) >= 0) return null;
    foreach (q("SELECT e.*, m.ref, m.title, m.app_no, m.reg_no, m.country, m.client_code FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.matter_id=? AND e.status IN ('open','proposed') AND e.type LIKE '%Grace%' AND e.due_date > ? AND e.due_date <= ? ORDER BY e.due_date", [$ev['matter_id'], $ev['due_date'], add_months($ev['due_date'], 7, false)]) as $g)
        if (q1('SELECT 1 FROM templates WHERE event_type=?', [$g['type']])) return $g;
    return null;
}
/** Every deadline missed (2026-09-29): a past-due US application deadline with no grace period and no later extended/final
 *  deadline still open on the matter → the "application abandoned" email (how to revive, fees will be due, no further reminders). */
const ABANDON_TEMPLATES = ['trademark' => 'Abandoned: Trademark Application', 'patent' => 'Abandoned: Patent Application'];
function abandonment_template(array $ev, array $m): ?string {
    $tpl = ABANDON_TEMPLATES[$m['kind'] ?? ''] ?? null;
    if (!$tpl || empty($ev['matter_id']) || days_until($ev['due_date']) >= 0 || !preg_match('/^(|United States|US|USA)$/i', trim((string)($m['country'] ?? '')))) return null;
    if (!preg_match('/^(Respond|Response|Final Response|Reply|Statement of Use|Issue Fee)/i', (string)$ev['type']) || grace_successor($ev)) return null;
    // A 3-month deadline whose extended / final 6-month deadline is still ahead is not abandoned yet.
    if (!preg_match('/^Statement of Use/i', (string)$ev['type']) && q1("SELECT 1 FROM events WHERE matter_id=? AND id<>? AND status IN ('open','proposed') AND (type LIKE 'Final Response%' OR type LIKE '%Extended') AND due_date > ? AND due_date >= ?", [$ev['matter_id'], $ev['id'] ?? 0, $ev['due_date'], today()])) return null;
    return $tpl;
}
/**
 * Abandoned application (2026-10-01): the matter is marked Abandoned and inactive, and ONE client email is queued for the day
 * the abandonment became known (USPTO notice, nightly TSDR / Open Data Portal status, or marked inactive by hand as abandoned). The email is
 * the "Abandoned: ..." template, filled from the deadline that was missed. No Petition to Revive deadline is docketed.
 * The "Report Abandonment" event carries it: due = the day received (2026-10-13: "there's no due date for an abandoned mark,
 * report it on the day received"; it was the day after), mail_date = the abandonment date. It survives "close all" on the inactive
 * matter, is closed when the email goes, and is cancelled if the application is revived first.
 */
const ABANDON_REPORT_EVENT = 'Report Abandonment';
function is_abandon_status(string $status): bool { return (bool)preg_match('/abandon/i', $status) && !preg_match('/Petition to Revive|Revived/i', $status); }
/** The deadline whose miss abandoned the application: the latest application deadline due on or before the abandonment date. */
function abandon_missed_event(int $matterId, string $abnDate): ?array {
    return q1("SELECT e.*, m.ref, m.title, m.app_no, m.reg_no, m.country, m.client_code, m.kind FROM events e JOIN matters m ON m.id=e.matter_id
               WHERE e.matter_id=? AND e.due_date <= ? AND e.status<>'cancelled' AND e.type<>?
                 AND (e.type LIKE 'Respond%' OR e.type LIKE 'Final Response%' OR e.type LIKE 'Statement of Use%' OR e.type LIKE 'Issue Fee%' OR e.type LIKE 'Reply%')
               ORDER BY e.due_date DESC, e.id DESC LIMIT 1", [$matterId, $abnDate !== '' ? $abnDate : today(), ABANDON_REPORT_EVENT]) ?: null;
}
/** Mark a matter abandoned + inactive and queue its one abandonment email (once per abandonment). Returns the new event id, or null. */
function mark_abandoned(int $matterId, string $abnDate, string $how): ?int {
    $m = q1('SELECT * FROM matters WHERE id=?', [$matterId]); if (!$m) return null;
    $abnDate = $abnDate !== '' ? substr($abnDate, 0, 10) : today();
    // 2026-10-02: the matter is NOT made inactive here. It is marked Abandoned (its other reminders stop), and the attorney is asked
    // whether to make it inactive once the abandonment email has gone (XYZ001).
    run('UPDATE matters SET status="Abandoned", dead_reason=CASE WHEN dead=1 THEN dead_reason ELSE ? END, updated_at=datetime("now") WHERE id=?', [$how, $matterId]);
    // One email per abandonment: the USPTO notice and the TSDR status of the same abandonment must not queue two.
    if (q1('SELECT 1 FROM events WHERE matter_id=? AND type=? AND status<>"cancelled" AND mail_date >= ?', [$matterId, ABANDON_REPORT_EVENT, add_days($abnDate, -45, false)])) return null;
    $due = today();   // the day it became known (2026-10-13; was the day after)
    run('INSERT INTO events(matter_id,type,due_date,status,source,doc_code,mail_date,note) VALUES (?,?,?,"open","rule","ABN",?,?)',
        [$matterId, ABANDON_REPORT_EVENT, $due, $abnDate, 'Email the client that the application is abandoned (abandoned ' . fmt_date($abnDate) . '; ' . $how . ')']);
    $id = (int)db()->lastInsertId();
    audit('abandoned', $m['ref'] . ' abandoned ' . fmt_date($abnDate) . ' (' . $how . '); client email queued for ' . fmt_date($due), $matterId);
    return $id;
}
/**
 * USPTO trademark notices reported to the client (2026-10-01): a Notice of Publication ("passed through the examination phase",
 * the paralegal's wording) and a Notice of Acceptance (a post-registration declaration accepted). Each queues ONE "Report ..." to-do, due the
 * day it arrived; its template (approved once, like every template) fills the email and the notice itself is forwarded below the signature.
 * Nothing goes until the attorney or staff clicks Send. An abandonment notice has its own path (mark_abandoned).
 */
const NOTICE_REPORTS = ['NOTICE OF PUBLICATION' => 'Report Notice of Publication', 'NOTICE OF ACCEPTANCE' => 'Report Notice of Acceptance',
    'FILING RECEIPT' => 'Report Filing Receipt', 'MAINT FEE PAID' => 'Report Maintenance Fee Paid', 'ASSIGNMENT RECORDED' => 'Report Assignment Recorded', 'ASSIGNMENT FILED' => 'Report Assignment Filed',   // receipts: lib/held.php (2026-10-03)
    'REGISTRATION' => 'Report Trademark Registration'];   // 2026-10-04: certificate + issuance letter to the client (lib/regreport.php)
// "Notice of acceptance" is two different papers: on a pending application it accepts the Statement of Use (the mark will now register,
// XYZ004 / XYZ001); on a registration it accepts a Section 8 (and 15) or renewal declaration (YAYA). Each gets its own email.
const SOU_ACCEPTED_REPORT = 'Report Statement of Use Accepted';
function notice_report_kind(string $type): ?string { if ($type === SOU_ACCEPTED_REPORT) return 'NOTICE OF ACCEPTANCE'; $k = array_search($type, NOTICE_REPORTS, true); return $k === false ? null : $k; }
function notice_report_type(array $m, string $docCode): ?string {
    $type = NOTICE_REPORTS[$docCode] ?? null;
    return $type === 'Report Notice of Acceptance' && trim((string)($m['reg_no'] ?? '')) === '' ? SOU_ACCEPTED_REPORT : $type;
}
function queue_notice_report(array $m, string $docCode, string $date, string $gmailId = ''): ?int {
    $type = notice_report_type($m, $docCode);
    if (!$type || (!str_starts_with($docCode, 'ASSIGNMENT') && ($m['kind'] ?? '') !== ($docCode === 'MAINT FEE PAID' ? 'patent' : 'trademark')) || !empty($m['dead']) || !preg_match('/^(|United States|US|USA)$/i', trim((string)($m['country'] ?? '')))) return null;
    // A registration is reported only once TSDR confirmed it (intake sets the reg. no. from TSDR): a misread notice (a design-search-code notice
    // read as REGISTRATION, XYZ001 2026-06-17) must not tell a client the mark registered (2026-10-04).
    if ($docCode === 'REGISTRATION') { $rm = !empty($m['id']) ? (q1('SELECT reg_no, grant_date FROM matters WHERE id=?', [$m['id']]) ?: $m) : $m; $g = substr((string)($rm['grant_date'] ?? ''), 0, 10);
        if (trim((string)($rm['reg_no'] ?? '')) === '' || ($g !== '' && abs((int)((strtotime($g) - strtotime($date)) / 86400)) > 60)) return null; }   // an old registration's reg. no. is not this notice's
    // The notice and its TMOG-day confirmation (or a re-read of either) are one report.
    if (in_array($docCode, ['FILING RECEIPT', 'MAINT FEE PAID', 'ASSIGNMENT RECORDED', 'ASSIGNMENT FILED'], true) ? q1('SELECT 1 FROM events WHERE matter_id=? AND type=? AND status<>"cancelled" AND mail_date=? AND gmail_id=?', [$m['id'], $type, $date, $gmailId])
        : q1('SELECT 1 FROM events WHERE matter_id=? AND type=? AND status<>"cancelled" AND mail_date >= ?', [$m['id'], $type, add_days($date, -60, false)])) return null;   // receipts: one report per receipt
    if ($docCode === 'FILING RECEIPT' && sou_reported_since((int)$m['id'], $date)) return null;   // already reported with the Statement of Use email (2026-10-12)
    $what = ['FILING RECEIPT' => 'the USPTO received the filing', 'MAINT FEE PAID' => 'maintenance fee paid', 'ASSIGNMENT RECORDED' => 'assignment recorded', 'ASSIGNMENT FILED' => 'assignment filed for recordation'][$docCode] ?? str_replace(' Of ', ' of ', ucwords(strtolower($docCode)));
    run('INSERT INTO events(matter_id,type,due_date,status,source,doc_code,mail_date,note,gmail_id) VALUES (?,?,?,"open","rule",?,?,?,?)',
        [$m['id'], $type, today(), $docCode, $date, 'Email the client: ' . $what . ' (' . fmt_date($date) . ')', $gmailId]);
    $id = (int)db()->lastInsertId();
    audit('notice_report', $m['ref'] . ' ' . strtolower($docCode) . ' ' . fmt_date($date) . ': client email queued', (int)$m['id']);
    return $id;
}
/** What the client filed, in plain words (2026-10-03): "your trademark renewal documents", not the TEAS form title. */
function your_filing(string $form): string {
    foreach ([['/8 (&|and) 9|renewal/i', 'your trademark renewal documents'], ['/8 (&|and) 15|incontestab/i', 'your Declaration of Use and Incontestability'], ['/section 71/i', 'your Section 71 Declaration of Use'],
              ['/section 8\b|declaration of use/i', 'your Declaration of Use'], ['/extension.{0,30}statement of use/i', 'your request for an extension of time to file the Statement of Use'],
              ['/allegation of use|statement of use/i', 'your Statement of Use'], ['/extension.{0,30}respon/i', 'your request for an extension of time to respond'],
              ['/response to (office|post)/i', 'your response to the Office action'], ['/petition to revive/i', 'your petition to revive the application'], ['/amendment/i', 'your amendment']] as [$re, $words])
        if (preg_match($re, $form)) return $words;
    return $form !== '' ? 'your ' . strtolower($form) : 'your filing';
}
/** the attorney's standard paragraph and the next renewal dates (his XYZ064 email of 2026-10-02), for a maintenance filing's receipt email. */
function next_renewal_paragraph(array $ev): string {
    if (empty($ev['matter_id'])) return '';
    $from = (string)(($ev['mail_date'] ?? '') ?: $ev['due_date']); $rows = [];
    foreach ([['Section 8 Declaration and Section 9 Renewal Begins', 'Section 8 & 9 renewal opens'], ['Section 8 Declaration and Section 9 Renewal Ends', 'Section 8 & 9 renewal due'], ['Section 8 and 9 Grace Period Ends', 'Section 8 & 9 renewal last day to file with additional fee']] as [$type, $label]) {
        $e = q1("SELECT due_date FROM events WHERE matter_id=? AND type=? AND status IN ('open','proposed') AND due_date > ? ORDER BY due_date LIMIT 1", [$ev['matter_id'], $type, add_months($from, 12, false)]);
        if ($e) $rows[] = $label . ': ' . fmt_long($e['due_date']);
    }
    if (!$rows) return '';
    return "Please remember to keep me up to date with regards to any changes in email address or physical address so I can reach you when the next renewal is due. I have recorded the dates in my system as follows though no guarantees can be made that I'll be able to send a reminder after this long period of time. Thus please also record these dates:\n\n" . implode("\n", $rows);
}
/** The next maintenance window after a notice, for the acceptance email: one sentence, or '' when none is docketed. */
function next_window_sentence(array $ev): string {
    if (empty($ev['matter_id'])) return '';
    $from = (string)($ev['mail_date'] ?: $ev['due_date']);
    foreach (q("SELECT type, due_date FROM events WHERE matter_id=? AND status IN ('open','proposed') AND due_date > ? ORDER BY due_date", [$ev['matter_id'], $from]) as $b) {
        if (!preg_match('/\bBegins?$| Window Open$/', $b['type']) || !($end = paired_deadline_type($b['type']))) continue;
        $e = q1("SELECT due_date FROM events WHERE matter_id=? AND type=? AND status IN ('open','proposed') AND due_date > ? ORDER BY due_date LIMIT 1", [$ev['matter_id'], $end, $b['due_date']]);
        $name = preg_replace('/\s+(Begins?|Window Open)$/', '', $b['type']);
        if (str_ends_with($b['type'], ' Window Open')) return 'The next maintenance fee, the ' . strtolower(str_replace(' Year ', '-year ', $name)) . ', can be paid ' . ($e ? 'between ' . fmt_long($b['due_date']) . ' and ' . fmt_long($e['due_date']) : 'from ' . fmt_long($b['due_date'])) . '.';   // patents (2026-10-03)
        return 'The next filing to maintain the registration, the ' . $name . ', can be made ' . ($e ? 'between ' . fmt_long($b['due_date']) . ' and ' . fmt_long($e['due_date']) : 'from ' . fmt_long($b['due_date'])) . '.';
    }
    return '';
}
/** Revived (or marked active again) before the abandonment email went: it must not go. */
function cancel_abandon_report(int $matterId, string $why): int {
    $n = 0;
    foreach (q('SELECT id FROM events WHERE matter_id=? AND type=? AND status IN ("open","proposed")', [$matterId, ABANDON_REPORT_EVENT]) as $x) {
        run('UPDATE events SET status="cancelled", done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [$why, $x['id']]);
        run('UPDATE reminders SET status="skipped", reason=? WHERE event_id=? AND status IN ("pending","held")', [$why, $x['id']]); $n++;
    }
    return $n;
}
/** What the client failed to file, in words, for the abandonment email. */
function missed_action(string $type): string {
    if (preg_match('/^Statement of Use or/i', $type)) return 'Statement of Use or extension request';
    if (preg_match('/^Statement of Use/i', $type)) return 'Statement of Use';
    if (preg_match('/Issue Fee|- NOA$/i', $type)) return 'issue fee payment';
    if (preg_match('/Suspension Inquiry/i', $type)) return 'response to the suspension inquiry';
    if (preg_match('/CTRS|Restriction/i', $type)) return 'response to the restriction requirement';
    if (preg_match('/NTC\.|NT\.CR|MISS|NONCPL|OMIT/i', $type)) return 'reply to the USPTO notice';
    return 'response to the Office Action';
}
function reminder_fire(string $due, int $lead, string $type, ?int $matterId = null): string {
    if ($lead === GRACE_LEAD && is_grace_email_type($type)) return add_days(grace_deadline($due, $type, $matterId)[0], 1, false);
    return add_days($due, -$lead, false);
}
/** The regular deadline a grace period follows, and the registration age then: the registration-date anniversary (DocketTrak sometimes
 *  never docketed the regular "... End(s)" event, e.g. SSA 2026); without a registration date, 6 months before the grace period ends. */
function grace_deadline(string $graceEnd, string $type, ?int $matterId): array {
    // Patents: the grace period follows that stage's "... Maintenance Fee Due" date (weekend-rolled), docketed on the same matter.
    if (preg_match('/^(\S+ 1\/2) Year Maintenance Fee Grace Period$/', $type, $pm)) {
        $due = $matterId ? q1('SELECT due_date FROM events WHERE matter_id=? AND type=? AND due_date < ? AND due_date >= ? ORDER BY due_date DESC LIMIT 1', [$matterId, $pm[1] . ' Year Maintenance Fee Due', $graceEnd, add_months($graceEnd, -8, false)]) : null;
        return [$due['due_date'] ?? add_months($graceEnd, -6, false), (int)$pm[1]];
    }
    $reg = $matterId ? (string)(q1('SELECT grant_date FROM matters WHERE id=?', [$matterId])['grant_date'] ?? '') : '';
    if ($reg !== '' && $reg < $graceEnd) { $n = (new DateTimeImmutable($reg))->diff(new DateTimeImmutable($graceEnd))->y; if ($n >= 5) return [add_months($reg, 12 * $n, false), $n]; }
    return [add_months($graceEnd, -6, false), preg_match('/and 9|Renewal/', $type) ? 10 : 6];
}
function num_word(int $n): string { $w = [1 => 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 20 => 'twenty', 30 => 'thirty', 40 => 'forty', 50 => 'fifty', 60 => 'sixty']; return $w[$n] ?? (string)$n; }
/** Two-letter code for the Matters list (2026-09-30); WIPO ST.3 for the offices (WO = WIPO/PCT/Madrid, EP = EPO, EM = EUIPO). Unknown names pass through. */
function country_code(string $c): string {
    static $map = ['united states' => 'US', 'canada' => 'CA', 'wipo' => 'WO', 'pct' => 'WO', 'international' => 'WO', 'china' => 'CN', 'australia' => 'AU', 'brazil' => 'BR',
        'united kingdom' => 'GB', 'mexico' => 'MX', 'korea - south' => 'KR', 'south korea' => 'KR', 'japan' => 'JP', 'uganda' => 'UG', 'south africa' => 'ZA', 'israel' => 'IL',
        'india' => 'IN', 'european union' => 'EM', 'euipo' => 'EM', 'ctm' => 'EM', 'epo' => 'EP', 'egypt' => 'EG', 'chile' => 'CL', 'germany' => 'DE', 'france' => 'FR',
        'italy' => 'IT', 'spain' => 'ES', 'switzerland' => 'CH', 'new zealand' => 'NZ', 'singapore' => 'SG', 'taiwan' => 'TW', 'hong kong' => 'HK', 'russia' => 'RU', 'turkey' => 'TR'];
    $k = strtolower(trim($c)); return $map[$k] ?? (preg_match('/^[A-Za-z]{2}$/', $k) ? strtoupper($k) : $c);
}
/** Lead days for display: ascending, ", "-separated ("30,7" -> "7, 30"; 2026-09-30). Stored order does not matter to the nightly. */
function lead_list(string $l): string { $n = array_map('intval', array_filter(array_map('trim', explode(',', $l)), 'strlen')); sort($n); return implode(', ', array_unique($n)); }
const GOODS_INDENT = '';   // the indent is now CSS on the <sm> block (mail_body_html), so wrapped lines stay indented too
/** Goods / services from matters.goods ("025 - Shirts; Hats" per line) in the attorney's format: "At least one of: ..." per class, then "and at least one of: ...". */
function goods_block(string $goods): array {
    // Numbered classes, plus certification (A goods / B services) and collective membership (200) marks.
    $cls = []; foreach (preg_split('/\R/', trim($goods)) as $l) if (preg_match('/^\s*(\d{1,3}|[AB])\s*-\s*(.+)$/', $l, $mm)) $cls[$mm[1]] = trim($mm[2]);
    if (!$cls) return ['goods', '[goods/services are not on file in Madder: copy them from TSDR]'];
    $svc = count(array_filter(array_keys($cls), fn($c) => $c === 'B' || (ctype_digit((string)$c) && (int)$c >= 35 && (int)$c < 100))); $word = $svc === 0 ? 'goods' : ($svc === count($cls) ? 'services' : 'goods and services');
    // "Class 009: goods" on one line; the lead-in sentence says "(at least one for each class)" (2026-09-29).
    // 2026-09-30: indented, "Class 035:" bold, the goods/services in italics. <sm>: smaller type, indented block.
    $out = []; foreach ($cls as $c => $g) $out[] = GOODS_INDENT . '<sm><b>Class ' . (ctype_digit((string)$c) ? sprintf('%03d', (int)$c) : $c) . ':</b> <i>' . $g . '</i></sm>';
    return [$word, implode("\n\n", $out)];   // "(however ...)" line removed (2026-09-30)
}
/** Goods / services inline for a sentence (the attorney's XYZ008 edit: "... used in commerce for laser skin tightening services and pay ..."). */
function goods_inline(string $goods): string {
    $out = []; foreach (preg_split('/\R/', trim($goods)) as $l) if (preg_match('/^\s*(?:\d{1,3}|[AB])\s*-\s*(.+)$/', $l, $mm)) {
        $g = trim($mm[1]); if ($g !== '' && strtoupper($g) === $g) $g = strtolower($g);          // USPTO all-caps records read as shouting
        elseif (preg_match('/^[A-Z][a-z]/', $g)) $g = lcfirst($g);
        $out[] = $g;
    }
    return $out ? implode('; ', $out) : '[goods/services are not on file in Madder: copy them from TSDR]';
}
function grace_fields(array $ev, array $m): array {
    $mid = !empty($ev['matter_id']) ? (int)$ev['matter_id'] : null; $graceEnd = (string)$ev['due_date'];
    [$deadline, $n] = grace_deadline($graceEnd, (string)($ev['type'] ?? ''), $mid);
    // Answer-by: the 1st of the month after the grace period is a month old (deadline Sept 15 -> November 1); if that has already
    // passed (sent late by hand), a week from today; never after the last day.
    $reply = (new DateTimeImmutable(add_days($deadline, 30, false)))->modify('first day of next month')->format('Y-m-d');
    if ($reply < today()) $reply = add_days(today(), 7, false);
    if ($reply > $graceEnd) $reply = $graceEnd;
    [$word, $list] = goods_block($mid ? (string)(q1('SELECT goods FROM matters WHERE id=?', [$mid])['goods'] ?? '') : '');
    return ['{REG_YEARS}' => num_word($n), '{WINDOW_YEARS}' => ($n - 1) . ' and ' . $n, '{GOODS_WORD}' => $word, '{GOODS_LIST}' => $list, '{GRACE_REPLY_BY}' => fmt_long($reply), '{MISSED_DEADLINE}' => fmt_long($deadline)];
}
/** Who gets client email for a matter: the client's trademark/copyright or patent list when one is set (GEO: different people
 *  per kind, 2026-09-29), otherwise the client's main address. Several addresses are separated by "; ". */
function client_recipients(array $c, string $kind, int $matterId = 0): string {
    if ($matterId && ($am = assoc_matter(['id' => $matterId])) && trim($am['instructing']) !== '') return trim($am['instructing']);   // the associate's instructing attorney only
    $k = in_array($kind, ['trademark', 'copyright'], true) ? 'email_tm' : ($kind === 'patent' ? 'email_patent' : '');
    return ($k !== '' && trim((string)($c[$k] ?? '')) !== '') ? trim((string)$c[$k]) : trim((string)($c['email'] ?? ''));
}
const CATCHUP_DAYS = 7;
/**
 * One line per open deadline for the matter page: when its 30-day / 7-day reminders fall and what will happen on that date, so a
 * missing reminder is visible the day the deadline is docketed, not discovered 30 days out. '' for notice events and closed/dead items.
 */
function reminder_plan(array $ev): string {
    static $tpls = null, $mail = [];
    if ($tpls === null) { $tpls = []; foreach (q('SELECT * FROM templates') as $t) $tpls[$t['event_type']] = $t; }
    if (($ev['status'] ?? '') !== 'open' || !empty($ev['dead']) || is_oa_report((string)$ev['type']) || is_notice_todo((string)$ev['type']) || notice_todo_waiting($ev)) return '';   // report to-dos (2026-10-04) have no reminder schedule
    $t = $tpls[$ev['type']] ?? null;
    if (!$t) return is_notice_type($ev['type']) ? '' : '<span class="bad">No reminder: this event type has no template</span> <a href="' . url('templates', ['edit' => $ev['type']]) . '">create one</a>';
    $code = (string)($ev['client_code'] ?? '');
    $mk = $code . '|' . (string)($ev['kind'] ?? '');   // a per-kind list counts as an address on file
    if (!array_key_exists($mk, $mail)) $mail[$mk] = client_recipients(q1('SELECT * FROM clients WHERE code=?', [$code]) ?: [], (string)($ev['kind'] ?? ''), (int)($ev['matter_id'] ?? 0));
    $rows = []; foreach (q('SELECT lead, status FROM reminders WHERE event_id=?', [$ev['id']]) as $r) $rows[(int)$r['lead']] = $r['status'];
    $today = today(); $out = [];
    foreach (array_map('intval', explode(',', $t['lead_days'])) as $lead) {
        $fire = reminder_fire($ev['due_date'], $lead, $ev['type'], (int)($ev['matter_id'] ?? 0) ?: null); $lbl = ($lead === GRACE_LEAD && is_grace_email_type($ev['type']) ? 'grace period starts' : ($lead > 0 ? $lead . '-day' : 'on the day')) . ' ' . fmt_date($fire) . ': ';
        if (isset($rows[$lead]))  $out[] = $lbl . (['pending' => 'waiting for Send', 'held' => '<span class="bad">held</span>', 'sent' => 'sent', 'skipped' => 'skipped', 'draft' => 'Gmail draft'][$rows[$lead]] ?? h($rows[$lead]));
        elseif ($fire > $today)   $out[] = $lbl . 'scheduled';
        else                      $out[] = $lbl . '<span class="muted">date passed</span>';
    }
    $warn = [];
    if (!$t['approved']) $warn[] = '<a href="' . url('templates', ['edit' => $ev['type']]) . '">template not approved yet</a>, so these will be HELD';
    if (!$t['internal_only'] && $mail[$mk] === '') $warn[] = 'no client email on file, so these will be HELD';
    return 'Reminders: ' . implode(' · ', $out) . ($warn ? ' <span class="bad">(' . implode('; ', $warn) . ')</span>' : '');
}
/**
 * The date the Dashboard files an open event under (2026-10-01): the earliest reminder for it not yet dealt with (sent,
 * Gmail draft or skipped), so a deadline 30 days out shows on its 30-day reminder date and moves to the 7-day one once Send is
 * clicked; the real deadline once every reminder is done, and for types with no template. Pending/held rows keep their date.
 */
function dash_date(array $ev, array $tpls): string {
    // 2026-10-15 (XYZ028): the opposition-ended report can only go out the day after the period ends, so it is filed under that day.
    if (($ev['type'] ?? '') === OPP_END_EVENT) return add_days((string)$ev['due_date'], 1, false);
    $t = tpl_for($tpls, (string)$ev['type'], (string)($ev['app_no'] ?? '')); if (!$t) return $ev['due_date'];
    $done = []; foreach (q('SELECT lead FROM reminders WHERE event_id=? AND status IN ("sent","draft","skipped")', [$ev['id']]) as $r) $done[(int)$r['lead']] = true;
    $best = null;
    foreach (array_map('intval', array_filter(array_map('trim', explode(',', (string)$t['lead_days'])), 'strlen')) as $lead) {
        if (isset($done[$lead])) continue;
        $fire = reminder_fire($ev['due_date'], $lead, $ev['type'], (int)($ev['matter_id'] ?? 0) ?: null);
        if ($best === null || $fire < $best) $best = $fire;
    }
    return ($best !== null && $best < $ev['due_date']) ? $best : $ev['due_date'];
}
/**
 * Held reminders (template not approved / no client email) are RELEASED, not deleted, when the block is lifted: re-rendered from the
 * current template and set pending if nothing holds them any more. Deleting them and waiting for the nightly to re-create them lost
 * every reminder whose fire date had meanwhile slipped past the CATCHUP_DAYS guard (e.g. a template approved a week late).
 * $where filters on e (event), m (matter), r (reminder). Returns the number now pending.
 */
function release_held(string $where, array $args = []): int {
    $n = 0; $today = today();
    foreach (q('SELECT r.id rid, r.lead, e.*, m.ref, m.title, m.app_no, m.reg_no, m.country, m.client_code, m.kind, m.dead FROM reminders r JOIN events e ON e.id=r.event_id JOIN matters m ON m.id=e.matter_id WHERE r.status="held" AND ' . $where, $args) as $ev) {
        // A window opening stays sendable after its own date for as long as the deadline it opens is still ahead.
        $until = $ev['due_date'];
        if (($dlType = paired_deadline_type($ev['type'])) && ($dl = q1('SELECT due_date FROM events WHERE matter_id=? AND type=? AND status IN ("open","proposed") ORDER BY due_date LIMIT 1', [$ev['matter_id'], $dlType]))) $until = $dl['due_date'];
        if ($ev['status'] !== 'open' || $ev['dead'] || $until < $today) { run('UPDATE reminders SET status="skipped", reason=? WHERE id=?', ['Event no longer open (or past due) when the hold was lifted', $ev['rid']]); continue; }
        $t = q1('SELECT * FROM templates WHERE event_type=?', [tpl_type($ev['type'], (string)$ev['app_no'])]) ?: q1('SELECT * FROM templates WHERE event_type=?', [$ev['type']]); if (!$t) continue;
        $client = q1('SELECT * FROM clients WHERE code=?', [$ev['client_code']]) ?: ['code' => $ev['client_code'], 'name' => '', 'email' => ''];
        $to = $t['internal_only'] ? $GLOBALS['cfg']['bcc_email'] : client_recipients($client, (string)$ev['kind'], (int)$ev['matter_id']);
        $of = !$t['internal_only'] && function_exists('other_firm_cached') ? other_firm_cached((int)$ev['matter_id']) : '';
        if ($of !== '')       { $st = 'held'; $why = 'Another firm of record at the USPTO: ' . $of . '. Check before sending'; }   // 2026-10-04 audit (JJi037)
        elseif (!$t['approved'])  { $st = 'held'; $why = 'Template not approved'; }
        elseif ($to === '')   { $st = 'held'; $why = 'No client email on file for ' . $client['code']; }
        else                  { $st = 'pending'; $why = 'Released ' . $today . ': ' . ($t['needs_invoice'] ? 'needs an invoice; ' : '') . 'waiting for the attorney or staff to click Send'; $n++; }
        run('UPDATE reminders SET status=?, reason=?, to_email=?, subject=?, body=? WHERE id=?', [$st, $why, $to, fill_subject($t['subject'], $ev, $ev, $client, $t), fill_template($t['body'], $ev, $ev, $client, $t), $ev['rid']]);
    }
    return $n;
}
/**
 * Queued reminders (pending / held) carry the text rendered on the night they were queued. When a template is reworded afterwards
 * (Templates page, or a bulk script like tpl_doc_2026_09_28.php) that frozen text went out on "Review & send" (2026-09-28:
 * "send client emails use the old templates"). This re-renders their subject/body from the current template; status is untouched.
 * $where filters on e (event), m (matter), r (reminder). Returns the number of rows whose text changed.
 */
function refresh_queued(string $where = '1', array $args = []): int {
    $n = 0; $tpls = []; foreach (q('SELECT * FROM templates') as $t) $tpls[$t['event_type']] = $t;
    foreach (q('SELECT r.id rid, r.subject rsub, r.body rbody, e.*, m.ref, m.title, m.app_no, m.reg_no, m.country, m.client_code, m.kind FROM reminders r JOIN events e ON e.id=r.event_id JOIN matters m ON m.id=e.matter_id WHERE r.status IN ("pending","held") AND ' . $where, $args) as $ev) {
        $isAbn = $ev['type'] === ABANDON_REPORT_EVENT;
        $t = $isAbn ? ($tpls[ABANDON_TEMPLATES[$ev['kind']] ?? ''] ?? null) : tpl_for($tpls, $ev['type'], (string)$ev['app_no']); if (!$t) continue;
        $fillEv = $isAbn ? (abandon_missed_event((int)$ev['matter_id'], (string)$ev['mail_date']) ?: $ev) : $ev;
        $client = q1('SELECT * FROM clients WHERE code=?', [$ev['client_code']]) ?: ['code' => $ev['client_code'], 'name' => '', 'email' => ''];
        $sub = fill_subject($t['subject'], $fillEv, $ev, $client, $t); $body = fill_template($t['body'], $fillEv, $ev, $client, $t);
        if ($sub === (string)$ev['rsub'] && $body === (string)$ev['rbody']) continue;
        run('UPDATE reminders SET subject=?, body=? WHERE id=?', [$sub, $body, $ev['rid']]); $n++;
    }
    return $n;
}
function run_nightly(): array {
    $stats = ['checked' => 0, 'sent' => 0, 'draft' => 0, 'held' => 0, 'skipped' => 0];
    $tpls = []; foreach (q('SELECT * FROM templates') as $t) $tpls[$t['event_type']] = $t;
    $today = today();
    refresh_queued();
    // Window openings ("... Begin(s)", "... Window Open") are looked at up to CATCHUP_DAYS after their date: their email goes out on the
    // day itself, so a missed night (or a template added that day) would otherwise lose it. Everything else: only dates still ahead.
    $events = q('SELECT e.*, m.ref, m.title, m.app_no, m.reg_no, m.country, m.client_code, m.kind FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.status="open" AND ((m.dead=0 AND COALESCE(m.status,"")<>"Abandoned") OR e.type=?) AND e.due_date >= ? ORDER BY e.due_date', [ABANDON_REPORT_EVENT, add_days($today, -CATCHUP_DAYS, false)]);
    foreach ($events as $ev) {
        $isAbn = $ev['type'] === ABANDON_REPORT_EVENT;   // 2026-10-01: one email, on its own date, on an inactive matter
        if ($ev['due_date'] < $today && !paired_deadline_type($ev['type']) && !$isAbn) continue;
        if (is_oa_report($ev['type']) || is_notice_todo($ev['type']) || notice_todo_waiting($ev)) continue;   // 2026-10-04: the Office Action / patent NOA report goes from its own Send button with the PDFs attached, never as a nightly reminder; nor a 'Process USPTO Notice' to-do, nor an Issue Notification report before its PDF is read
        $t = $isAbn ? ($tpls[ABANDON_TEMPLATES[$ev['kind']] ?? ''] ?? null) : tpl_for($tpls, $ev['type'], (string)$ev['app_no']); if (!$t) continue;
        $fillEv = $isAbn ? (abandon_missed_event((int)$ev['matter_id'], (string)$ev['mail_date']) ?: $ev) : $ev;
        // A bare "Remind Client" tickler (DocketTrak's reminder date) is redundant when the deadline it points at has its own approved
        // template: that template's 30/7-day reminders cover it, and the client would otherwise get the same request twice.
        if ($ev['type'] === 'Remind Client' && ($tg = remind_target($ev)) && !empty($tpls[$tg['type']]['approved'])) { $stats['skipped']++; continue; }
        // ... and pointless when no open deadline follows it (the deadline was answered), or on a pending US trademark application (2026-09-29).
        if ($ev['type'] === 'Remind Client' && function_exists('is_pending_us_tm_app') && (!remind_target($ev) || is_pending_us_tm_app($ev))) { $stats['skipped']++; continue; }
        $stats['checked']++;
        $client = q1('SELECT * FROM clients WHERE code=?', [$ev['client_code']]) ?: ['code' => $ev['client_code'], 'name' => '', 'email' => ''];
        foreach ($isAbn ? [0] : array_map('intval', explode(',', $t['lead_days'])) as $lead) {
            $fire = reminder_fire($ev['due_date'], $lead, $ev['type'], (int)($ev['matter_id'] ?? 0) ?: null);
            if ($fire > $today) continue;                                   // not yet
            if (q1('SELECT id FROM reminders WHERE event_id=? AND lead=?', [$ev['id'], $lead])) continue;   // already handled
            if ($fire < add_days($today, -CATCHUP_DAYS, false)) {           // fire date long past (e.g. first run): record, do not send
                run('INSERT INTO reminders(event_id,lead,scheduled_for,status,reason) VALUES (?,?,?,"skipped",?)', [$ev['id'], $lead, $fire, 'Fire date passed more than ' . CATCHUP_DAYS . ' days before this run; not sent']);
                $stats['skipped']++; continue;
            }
            $subject = fill_subject($t['subject'], $fillEv, $ev, $client, $t); $body = fill_template($t['body'], $fillEv, $ev, $client, $t);
            $to = $t['internal_only'] ? $GLOBALS['cfg']['bcc_email'] : client_recipients($client, (string)$ev['kind'], (int)$ev['matter_id']);
            $of = !$t['internal_only'] && function_exists('other_firm_cached') ? other_firm_cached((int)$ev['matter_id']) : '';
            if ($of !== '')                 { $st = 'held'; $why = 'Another firm of record at the USPTO: ' . $of . '. Check before sending'; }   // 2026-10-04 audit (JJi037)
            elseif (!$t['approved'])        { $st = 'held'; $why = 'Template not approved'; }
            elseif ($to === '')             { $st = 'held'; $why = 'No client email on file for ' . $client['code']; }
            else                            { $st = 'pending'; $why = $t['needs_invoice'] ? 'Waiting for the attorney or staff: needs an invoice, so it will be saved as a Gmail draft' : 'Waiting for the attorney or staff to click Send'; }
            run('INSERT INTO reminders(event_id,lead,scheduled_for,status,to_email,subject,body,reason,sent_at) VALUES (?,?,?,?,?,?,?,?,?)',
                [$ev['id'], $lead, $fire, $st, $to, $subject, $body, $why, null]);
            $stats[$st === 'pending' ? 'sent' : 'held']++;
        }
    }
    run('INSERT OR REPLACE INTO settings(key,value) VALUES ("nightly_last", ?)', [date('Y-m-d H:i:s')]);
    audit('nightly', json_encode($stats));
    return $stats;
}

/**
 * Nightly USPTO sweep. Every US matter with an open event due between 90 days ago and a year out is checked against TSDR
 * (trademarks) or the Open Data Portal (published patents), nearest deadlines first, each matter at most once a week
 * unless something is due within 30 days. Anything the live record shows as satisfied is PROPOSED done, never closed.
 *
 * Audit 2026-10-02: (1) ROTATION. A matter whose next deadline is more than a year out was never looked at, so a registration
 * cancelled or a patent expired years ago kept its renewal and maintenance-fee deadlines (several hundred matters). Each night up to
 * SWEEP_ROTATION further matters, the ones not checked for SWEEP_ROTATION_DAYS, are checked as well, whatever their next date.
 * (2) An application abandoned long ago is made inactive without the client "your application is abandoned" email (that email is for
 * an abandonment that has just happened). (3) Maintenance dates are put on the USPTO's registration / grant date (lib/reconcile.php).
 */
const SWEEP_ROTATION = 40; const SWEEP_ROTATION_DAYS = 90; const ABANDON_EMAIL_DAYS = 90;
function run_sweep(int $limit = 250, bool $rotate = true): array {   // $rotate=false: the "Run nightly" button, which must answer within a web request
    $today = today(); $r = ['checked' => 0, 'proposed' => [], 'withdrawn' => [], 'failed' => [], 'unpublished' => 0, 'dead' => [], 'redated' => [], 'rotation' => 0];
    $rows = q('SELECT m.*, min(e.due_date) next_due FROM matters m JOIN events e ON e.matter_id=m.id AND e.status IN ("open","proposed")
               WHERE m.country="United States" AND m.dead=0 AND m.app_no<>"" AND m.kind IN ("trademark","patent") AND e.due_date BETWEEN ? AND ?
               GROUP BY m.id ORDER BY next_due LIMIT 2000', [add_days($today, -90, false), add_days($today, 365, false)]);
    $rot = ($limit > 0 && $rotate) ? q('SELECT m.*, NULL next_due, (SELECT max(c.ts) FROM checks c WHERE c.matter_id=m.id AND c.source<>"error") last_ts FROM matters m
               WHERE m.country="United States" AND m.dead=0 AND m.app_no<>"" AND m.kind IN ("trademark","patent")
                 AND NOT EXISTS (SELECT 1 FROM checks c WHERE c.matter_id=m.id AND c.source<>"error" AND c.ts > ?)
               ORDER BY last_ts IS NOT NULL, last_ts, m.id LIMIT ' . (SWEEP_ROTATION * 3), [add_days($today, -SWEEP_ROTATION_DAYS, false)]) : [];
    $main = 0; $seen = [];
    foreach (array_merge(array_map(fn($m) => $m + ['rot' => 0], $rows), array_map(fn($m) => $m + ['rot' => 1], $rot)) as $m) {
        if (isset($seen[$m['id']])) continue;
        if ($m['rot']) { if ($r['rotation'] >= SWEEP_ROTATION) break; }
        else {
            if ($main >= $limit) continue;
            $last = q1('SELECT ts, source FROM checks WHERE matter_id=? AND source<>"error" ORDER BY id DESC LIMIT 1', [$m['id']]);
            if ($last && $last['source'] === 'unpublished' && $last['ts'] > add_days($today, -30, false)) continue;
            if ($last && $last['ts'] > add_days($today, -7, false) && $m['next_due'] > add_days($today, 30, false)) continue;
        }
        $seen[$m['id']] = true;
        try { $live = $m['kind'] === 'trademark' ? tsdr_lookup($m['app_no']) : odp_lookup($m['app_no']);
              if (!$live['ok'] && str_contains($live['error'] ?? '', '429')) { sleep(15); $live = $m['kind'] === 'trademark' ? tsdr_lookup($m['app_no']) : odp_lookup($m['app_no']); } }   // TSDR rate limit: back off once
        catch (Throwable $e) { $live = ['ok' => false, 'error' => 'lookup threw: ' . $e->getMessage()]; }
        $r['checked']++; if ($m['rot']) $r['rotation']++; else $main++;
        usleep($m['kind'] === 'trademark' ? 1300000 : 300000);   // TSDR throttles bursts; keep under a call a second
        if (!$live['ok']) {
            run('INSERT INTO checks(matter_id,by_user,source,status,summary) VALUES (?,?,?,?,?)', [$m['id'], 'nightly', !empty($live['unpublished']) ? 'unpublished' : 'error', 'not checked', $live['error']]);
            if (!empty($live['unpublished'])) $r['unpublished']++; else $r['failed'][] = [$m, $live['error']];
            continue;
        }
        run('UPDATE matters SET pto_status=?, pto_status_date=COALESCE(NULLIF(?, ""), pto_status_date), reg_no=CASE WHEN ? <> "" THEN ? ELSE reg_no END, grant_date=COALESCE(NULLIF(?, ""), grant_date), updated_at=datetime("now") WHERE id=?',
            [$live['status'], $live['status_date'], $live['reg_no'], $live['reg_no'], $live['reg_date'], $m['id']]);
        // 2026-10-06 (XYZ001): a blank or placeholder title / filing date is filled from the USPTO record; a typed value is never overwritten.
        $fill = [];
        $lt = trim((string)($live['title'] ?? $live['mark'] ?? ''));   // ODP inventionTitle / TSDR mark literal
        if (clean_title($m['title'] ?? '') === '' && $lt !== '') $fill['title'] = $lt;
        if (trim((string)($m['filing_date'] ?? '')) === '' && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)($live['filing_date'] ?? ''))) $fill['filing_date'] = substr((string)$live['filing_date'], 0, 10);
        foreach ($fill as $col => $val) {
            run("UPDATE matters SET $col=?, updated_at=datetime('now') WHERE id=?", [$val, $m['id']]);
            audit('data_fill', "$col filled from USPTO {$live['source']}: $val", (int)$m['id']);
            $m[$col] = $val;
        }
        // Dates the USPTO's own registration / grant date gives (lib/reconcile.php). A file number shared by two records is left to a
        // person: the import put both records' deadlines on the first of them, so "wrong" dates there may be the other record's.
        if (!$live['dead'] && (string)$live['reg_date'] !== '' && !q1('SELECT 1 FROM matters WHERE ref=? AND kind=? AND id<>?', [$m['ref'], $m['kind'], $m['id']])) {
            try {
                $chg = $m['kind'] === 'trademark' ? reconcile_tm_maintenance((int)$m['id'], (string)$live['reg_date'], 'USPTO TSDR')
                     : array_merge(is_design_matter($m) ? [] : reconcile_patent_mf((int)$m['id'], (string)$live['reg_date'], 'USPTO Open Data Portal'),
                                   reconcile_patent_expiry(['filing_date' => $live['filing_date'] ?: $m['filing_date']] + $m, (string)$live['reg_date'], $live['parents'] ?? [], (int)($live['pta'] ?? 0), 'USPTO Open Data Portal'));
                foreach ($chg as $c) $r['redated'][] = [$m, $c];
            } catch (Throwable $e) { audit('error', 'reconcile ' . $m['ref'] . ': ' . $e->getMessage(), (int)$m['id']); }
        }
        $open = q('SELECT * FROM events WHERE matter_id=? AND status="open" ORDER BY due_date', [$m['id']]);
        $matches = check_matches($m, $live, $open);
        foreach ($matches as [$ev, $why]) {
            if (run('UPDATE events SET status="proposed", proposed_by="nightly", proposed_at=datetime("now"), note=note_add(note, ?) WHERE id=? AND status="open" AND rejected_at IS NULL', ['USPTO ' . $live['source'] . ': ' . $why, $ev['id']]) === 1) audit_done($ev + ['matter_id' => $m['id']], 'USPTO ' . $live['source'] . ': ' . $why, false);
            $r['proposed'][] = [$m, $ev, $why];
        }
        // 2026-10-01: the sweep's own earlier proposals are re-checked against today's record and today's rules; one the
        // record no longer supports goes back to open (not "rejected", so better evidence later can propose it again).
        $mine = q('SELECT * FROM events WHERE matter_id=? AND status="proposed" AND proposed_by="nightly" AND type NOT LIKE "Remind Client%" ORDER BY due_date', [$m['id']]);   // ticklers follow their deadline (propose_orphan_reminders)
        if ($mine) {
            $still = array_column(array_map(fn($x) => $x[0], check_matches($m, $live, $mine)), 'id');
            foreach ($mine as $ev) {
                if (in_array($ev['id'], $still)) continue;
                if (run('UPDATE events SET status="open", proposed_by=NULL, proposed_at=NULL, note=note_add(note, ?) WHERE id=? AND status="proposed" AND proposed_by="nightly"', ['proposal withdrawn ' . $today . ': the USPTO record no longer supports it', $ev['id']]) !== 1) continue;
                audit('withdraw', "$ev[type] (due $ev[due_date]) back to open: USPTO $live[source] re-check no longer supports the earlier proposal", (int)$m['id']);
                $r['withdrawn'][] = [$m, $ev];
            }
        }
        if (function_exists('propose_orphan_reminders')) propose_orphan_reminders((int)$m['id'], 'nightly');   // their Remind Client ticklers follow
        $stray = ($live['dead'] && preg_match('/Provisional Application Expired/i', (string)$live['status'])) ? q('SELECT id FROM events WHERE matter_id=? AND status="open" AND type NOT LIKE "Non-Provisional%" AND type NOT LIKE "Remind Client%" AND type NOT LIKE "Foreign Filing%"', [$m['id']]) : [];
        if ($stray) $r['failed'][] = [$m, count($stray) . ' open deadline(s) sit on the record of an expired provisional: they belong to the application that claims it (same file number). Nothing was closed; move them to that record'];
        elseif ($live['dead']) { $r['dead'][] = [$m, $live['status']];
            $sd = substr((string)$live['status_date'], 0, 10);
            // The abandonment email is for an abandonment that has just happened (2026-10-01). One the USPTO recorded long ago,
            // found now only because nobody had looked, makes the matter inactive like a cancelled registration: no email.
            if (is_abandon_status((string)$live['status']) && ($sd === '' || $sd >= add_days($today, -ABANDON_EMAIL_DAYS, false))) mark_abandoned((int)$m['id'], (string)($live['status_date'] ?: today()), 'USPTO ' . $live['source'] . ': ' . trim(explode('.', $live['status'])[0]));   // 2026-10-01
            else run('UPDATE matters SET dead=1, dead_reason=?, dead_at=? WHERE id=?', [$live['status'], $live['status_date'] ?: today(), $m['id']]); }
        run('INSERT INTO checks(matter_id,by_user,source,status,summary) VALUES (?,?,?,?,?)', [$m['id'], 'nightly', $live['source'], $live['status'], count($open) . ' open, ' . count($matches) . ' proposed']);
    }
    run('INSERT OR REPLACE INTO settings(key,value) VALUES ("sweep_last", ?)', [date('Y-m-d H:i:s')]);
    audit('sweep', json_encode(['checked' => $r['checked'], 'rotation' => $r['rotation'], 'proposed' => count($r['proposed']), 'redated' => count($r['redated']), 'failed' => count($r['failed']), 'unpublished' => $r['unpublished']]));
    return $r;
}
/** The morning report, as self-contained HTML. $base = where the links point (local sample or the private site). */
function build_report(array $sweep, array $nightly, string $base, ?array $horizon = null): string {
    $t = today(); $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $link = fn(array $m) => '<a href="' . $base . '?p=matter&id=' . $m['id'] . '">' . $h($m['ref']) . '</a>';
    $sec = fn(string $title, string $body) => "<h3 style=\"margin:18px 0 6px;color:#1b2a49\">$title</h3>$body";
    $table = function (array $rows, array $hdr) use ($h) { if (!$rows) return '<p style="color:#666">None.</p>'; $o = '<table style="border-collapse:collapse;width:100%;font-size:13px"><tr>'; foreach ($hdr as $x) $o .= '<th style="text-align:left;border-bottom:1px solid #ccc;padding:4px">' . $x . '</th>'; $o .= '</tr>'; foreach ($rows as $r) { $o .= '<tr>'; foreach ($r as $c) $o .= '<td style="border-bottom:1px solid #eee;padding:4px;vertical-align:top">' . $c . '</td>'; $o .= '</tr>'; } return $o . '</table>'; };
    $E = 'SELECT e.*, m.ref, m.id mid, m.title, m.client_code FROM events e JOIN matters m ON m.id=e.matter_id';
    $over = q("$E WHERE e.status='open' AND e.due_date < ? ORDER BY e.due_date", [$t]);
    $d3 = q("$E WHERE e.status='open' AND e.due_date BETWEEN ? AND ? ORDER BY e.due_date", [$t, add_days($t, 3, false)]);
    $d14 = q1("SELECT count(*) c FROM events WHERE status='open' AND due_date BETWEEN ? AND ?", [add_days($t, 4, false), add_days($t, 14, false)])['c'];
    $prop = q("$E WHERE e.status='proposed' ORDER BY e.due_date");
    $pend = q('SELECT r.*, m.ref, m.id mid, e.type, e.due_date FROM reminders r JOIN events e ON e.id=r.event_id JOIN matters m ON m.id=e.matter_id WHERE r.status="pending" ORDER BY e.due_date');
    $evrow = fn($e) => ['<a href="' . $base . '?p=matter&id=' . $e['mid'] . '">' . $h($e['ref']) . '</a>', $h($e['type']), fmt_date($e['due_date']), $h($e['note'])];
    $o = '<div style="font-family:Lexend,Segoe UI,sans-serif;color:#1e2430;max-width:900px"><h2 style="color:#1b2a49;margin:0 0 4px">Madder morning report · ' . fmt_date($t) . '</h2>';
    $o .= '<p style="color:#666;margin:0 0 12px">Review for accuracy. Nothing below was closed or sent automatically.</p>';
    if (function_exists('untransmitted') && ($un = untransmitted())) $o .= $sec('<span style="color:#b3261e">Emails NOT sent (' . count($un) . ')</span>', $table(array_map(fn($x) => ['<a href="' . $base . '?p=dash&show=unsent">' . $h($x['ref']) . '</a>', $h(substr((string)$x['created_at'], 0, 16)), $h($x['to_email']), $h($x['transmit_error'] ?: 'not transmitted')], $un), ['File', 'Clicked', 'To', 'Problem']));
    if (function_exists('held_open') && ($hl = held_open())) $o .= $sec('<span style="color:#b3261e">USPTO email to read (' . count($hl) . ')</span>', $table(array_map(fn($x) => [$h($x['received']), '<a href="' . $base . '?p=reminders#held">' . $h($x['subject']) . '</a><br><span style="color:#666">' . $h($x['sender']) . '</span>', $h($x['reason'])], $hl), ['Received', 'Email', 'Why held']));
    $o .= $sec('USPTO sweep: ' . $sweep['checked'] . ' matters checked, ' . count($sweep['proposed']) . ' events look satisfied', $table(array_map(fn($x) => [$link($x[0]), $h($x[1]['type']), fmt_date($x[1]['due_date']), $h($x[2])], $sweep['proposed']), ['File', 'Event', 'Was due', 'Evidence at the USPTO']) . ($sweep['unpublished'] ? '<p style="color:#666">' . $sweep['unpublished'] . ' unpublished application(s) skipped: only Patent Center can show those.</p>' : '') . ($sweep['failed'] ? '<p style="color:#b42318">Lookups failed: ' . implode('; ', array_map(fn($x) => $h($x[0]['ref']) . ' (' . $h($x[1]) . ')', $sweep['failed'])) . '</p>' : ''));
    if (!empty($sweep['withdrawn'])) $o .= $sec('Back to open: earlier USPTO proposals the record no longer supports', $table(array_map(fn($x) => [$link($x[0]), $h($x[1]['type']), fmt_date($x[1]['due_date'])], $sweep['withdrawn']), ['File', 'Event', 'Due']));
    if (!empty($sweep['redated'])) $o .= $sec('Dates corrected to the USPTO registration / grant date (' . count($sweep['redated']) . ')', $table(array_map(fn($x) => [$link($x[0]), $h($x[1]['type']), fmt_date($x[1]['from']), $x[1]['action'] === 'cancelled' ? 'cancelled (duplicate of ' . fmt_date($x[1]['to']) . ')' : fmt_date($x[1]['to'])], $sweep['redated']), ['File', 'Event', 'Was', 'Now']));
    if ($sweep['dead']) $o .= $sec('USPTO says no longer pending', $table(array_map(fn($x) => [$link($x[0]), $h($x[1])], $sweep['dead']), ['File', 'USPTO status']));
    $o .= $sec('Awaiting your confirmation (' . count($prop) . ')', $table(array_map($evrow, $prop), ['File', 'Event', 'Due', 'Why']) . '<p><a href="' . $base . '?p=queue">Open the confirm queue</a></p>');
    $o .= $sec('Overdue (' . count($over) . ')', $table(array_map($evrow, $over), ['File', 'Event', 'Due', 'Note']));
    $o .= $sec('Due within 3 days (' . count($d3) . ') · ' . $d14 . ' more within 14 days', $table(array_map($evrow, $d3), ['File', 'Event', 'Due', 'Note']));
    $o .= $sec('Client reminders waiting for someone to click Send (' . count($pend) . '; ' . $nightly['sent'] . ' new tonight)', $table(array_map(fn($r) => ['<a href="' . $base . '?p=send&id=' . $r['event_id'] . '&rem=' . $r['id'] . '">' . $h($r['ref']) . '</a>', $h($r['type']), fmt_date($r['due_date']), lead_label((int)$r['lead']), $h($r['to_email'])], $pend), ['File', 'Event', 'Due', 'Reminder', 'To']) . ($nightly['held'] ? '<p style="color:#b54708">' . $nightly['held'] . ' reminder(s) held: template not approved or no client email.</p>' : ''));
    if ($horizon !== null) $o .= $sec('Weekly renewal horizon: ' . $horizon['registrations'] . ' live registrations checked, ' . $horizon['events'] . ' renewal event(s) added on ' . $horizon['marks'] . ' mark(s)', $horizon['events'] ? $table(array_merge(...array_map(fn($x) => array_map(fn($e) => [$link($x[0]), $h($e[0]), fmt_date($e[1])], $x[1]), $horizon['added'])), ['File', 'Event', 'Date']) : '<p style="color:#666">Every live registration already carries its Section 8/9 renewals at least ' . HORIZON_YEARS . ' years out.</p>');
    else $o .= $sec('Renewal horizon', '<p style="color:#666">Weekly pass (Mondays); last run ' . $h(setting('horizon_last', 'never')) . '. Every live registration carries its Section 8/9 renewals ' . HORIZON_YEARS . ' years out.</p>');
    return $o . '<p style="color:#999;font-size:12px;margin-top:20px">Madder · ' . htmlspecialchars(firm('name')) . ' · generated ' . date('Y-m-d H:i') . '</p></div>';
}

/**
 * Renewal horizon (2026-09-23): every live US registration carries its Section 8/9 renewal events (Begins, Ends,
 * Grace Period Ends) for every cycle falling within ten years of today. Runs weekly from the nightly job; adds only what
 * is missing, never touches existing events. Uses the trademark calendar-date convention.
 */
const HORIZON_YEARS = 10;
function extend_renewal_horizon(): array {
    $today = today(); $limit = add_months($today, 12 * HORIZON_YEARS, false); $added = []; $marks = 0;
    // Madrid (66a) registrations are maintained under Section 71, not 8/9; marks the client chose to let lapse are left alone.
    // Audit 2026-10-02: a record DocketTrak carried as inactive (active=0) with nothing else docketed is skipped too: the client changed
    // attorneys, said to abandon ... and the horizon had been adding ten years of renewals to marks the firm no longer handles (XYZ003,
    // SN90251882). An inactive-flagged record that still carries its own deadlines (XYZ001, XYZ001) is extended as before.
    // One matter per registration: DocketTrak holds some marks two or three times (51 duplicate serials); only the first copy is extended.
    $regs = q('SELECT id, ref, grant_date, basis, status, pto_status FROM matters m WHERE kind="trademark" AND dead=0 AND country="United States" AND grant_date IS NOT NULL AND grant_date <> "" AND basis NOT LIKE "%66a%" AND app_no NOT LIKE "79%"
                 AND (COALESCE(active, 1)=1 OR EXISTS (SELECT 1 FROM events e WHERE e.matter_id=m.id AND e.status IN ("open","proposed") AND e.source<>"horizon"))
               AND id = (SELECT min(id) FROM matters d WHERE d.kind=m.kind AND d.app_no=m.app_no AND d.dead=0)');
    $regs = array_values(array_filter($regs, fn($m) => !preg_match('/lapse|not filing|Renewal Not Filed|Expiration Expected/i', $m['status'] . ' ' . $m['pto_status'])));
    // Older DocketTrak records use other names for the same renewal steps; treat them as the same event.
    $fam = ['Section 8 Declaration and Section 9 Renewal Begins' => '/Renewal Begins|Renewal Period Begins|Section 8 Declaration Begins/', 'Section 8 Declaration and Section 9 Renewal Ends' => '/Renewal Ends|Renewal Period Ends|^Renewal$|Section 8 Declaration Ends/', 'Section 8 and 9 Grace Period Ends' => '/Section 8 and 9 Grace|Renewal Grace|Section 8 Declaration Grace/'];
    foreach ($regs as $m) {
        $have = []; foreach (q('SELECT type, due_date FROM events WHERE matter_id=?', [$m['id']]) as $e) $have[$e['type'] . '|' . $e['due_date']] = true;
        $new = [];
        for ($y = 9; ; $y += 10) {
            $begins = add_months($m['grant_date'], $y * 12, false); if ($begins > $limit) break;
            foreach ([['Section 8 Declaration and Section 9 Renewal Begins', $begins], ['Section 8 Declaration and Section 9 Renewal Ends', add_months($m['grant_date'], $y * 12 + 12, false)], ['Section 8 and 9 Grace Period Ends', add_months($m['grant_date'], $y * 12 + 18, false)]] as [$type, $due]) {
                if ($due < $today || isset($have["$type|$due"])) continue;
                // Skip a cycle DocketTrak already docketed on a neighbouring date (its older records differ by a day or two)
                $near = false; foreach (q('SELECT type FROM events WHERE matter_id=? AND abs(julianday(due_date) - julianday(?)) <= 3', [$m['id'], $due]) as $x) if (preg_match($fam[$type], $x['type'])) $near = true;
                if ($near) continue;
                run('INSERT INTO events(matter_id,type,due_date,status,source,note) VALUES (?,?,?,"open","horizon",?)', [$m['id'], $type, $due, 'Docketed by the weekly renewal horizon (' . HORIZON_YEARS . ' years out)']);
                $new[] = [$type, $due];
            }
        }
        if ($new) { $marks++; $added[] = [$m, $new]; }
    }
    run('INSERT OR REPLACE INTO settings(key,value) VALUES ("horizon_last", ?)', [$today]);
    $n = array_sum(array_map(fn($x) => count($x[1]), $added));
    audit('horizon', "$marks marks, $n events added"); return ['marks' => $marks, 'events' => $n, 'added' => $added, 'registrations' => count($regs)];
}
/** A Statement of Use / extension report already went to the client for a filing on or after $date (so its receipt is not reported again). */
function sou_reported_since(int $matterId, string $date): bool {
    return (bool)q1("SELECT 1 FROM events WHERE matter_id=? AND type LIKE 'Statement of Use%' AND note LIKE '%USPTO filing (%' AND note LIKE '%emailed client %' AND done_at >= ?", [$matterId, add_days($date, -3, false)]);
}
