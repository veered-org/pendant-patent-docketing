<?php
declare(strict_types=1);
/**
 * Patent Office Action / Notice of Allowance reports (2026-10-04), modelled on the trademark Notice-of-Allowance report (lib/noa.php):
 * when a US patent office action (CTNF, CTFR, CTRS, CTEQ) or a patent NOA comes in, a to-do dated the mail date sits under "overdue / today"
 * with the download state of the papers (saved to Dropbox / "Sign in to Patent Center in Chrome first, then click: Download with Claude" /
 * not yet fetched) and a gold "Send to client" button: ?p=send&id=<to-do>&report=oa. The send page fills the attorney's wording from the open
 * "Respond to Office Action - <CODE>" deadline (the 3-month date; {PAY_BY} = two months before it), pre-ticks the saved action PDF and the
 * PTO-892, and sending marks the to-do done. The USPTO e-mail is NOT forwarded (the PDF is attached). Loaded by lib/rules.php, so every
 * intake path (web, nightly, gmail_hourly) creates the to-do.
 */
const OA_REPORT_EVENT = 'Report Office Action';          // to-do for CTNF / CTFR / CTRS / CTEQ (doc_code says which)
const PNOA_REPORT_EVENT = 'Report Patent Allowance';     // to-do for a patent NOA ("Report Notice of Allowance" stays the trademark one)
const OA_REPORT_CODES = ['CTNF', 'CTFR', 'CTRS', 'CTEQ', 'NOA'];
const OA_REPORT_DAYS = 60;                               // one to-do per matter + paper within this many days; the backfill looks this far back
const OA_REPORT_TEMPLATES = ['CTNF' => 'Report Office Action', 'CTEQ' => 'Report Office Action', 'CTFR' => 'Report Final Office Action', 'CTRS' => 'Report Restriction Requirement'];
function is_oa_report(string $type): bool { return $type === OA_REPORT_EVENT || $type === PNOA_REPORT_EVENT; }
function is_oa_report_template(string $type): bool { return in_array($type, OA_REPORT_TEMPLATES, true); }
/** The template an office-action report uses; the patent NOA report uses "Respond to Office Action - NOA" through tpl_type() (Hague designs get their own). */
function oa_report_template(string $docCode, string $appNo = ''): string { return $docCode === 'NOA' ? tpl_type('Respond to Office Action - NOA', $appNo) : (OA_REPORT_TEMPLATES[$docCode] ?? OA_REPORT_TEMPLATES['CTNF']); }
function oa_report_label(string $docCode): string { return ['CTNF' => 'Office Action', 'CTFR' => 'Final Office Action', 'CTRS' => 'Restriction Requirement', 'CTEQ' => 'Ex Parte Quayle Action', 'NOA' => 'Notice of Allowance', 'ISSUE.NTF' => 'Issue Notification'][$docCode] ?? ($docCode === '' ? 'Office Action' : notice_label($docCode)); }
function oa_files_schema(): void { static $d = false; if ($d) return; $d = true; db()->exec("CREATE TABLE IF NOT EXISTS oa_files (correspondence_id INTEGER PRIMARY KEY, status TEXT, files TEXT DEFAULT '', note TEXT DEFAULT '', fetched_at TEXT DEFAULT (datetime('now')))"); }

/** Queue the to-do for a patent paper on a live US patent matter; null when not applicable or already queued (same paper within OA_REPORT_DAYS). */
function queue_oa_report(array $m, string $docCode, string $date, string $how = ''): ?int {
    if (!in_array($docCode, OA_REPORT_CODES, true) || ($m['kind'] ?? '') !== 'patent' || !empty($m['dead']) || empty($m['id'])) return null;
    if (!preg_match('/^(|United States|US|USA)$/i', trim((string)($m['country'] ?? '')))) return null;
    $type = $docCode === 'NOA' ? PNOA_REPORT_EVENT : OA_REPORT_EVENT;
    if (q1('SELECT 1 FROM events WHERE matter_id=? AND type=? AND doc_code=? AND status<>"cancelled" AND mail_date >= ?', [$m['id'], $type, $docCode, add_days($date, -OA_REPORT_DAYS, false)])) return null;
    run('INSERT INTO events(matter_id,type,due_date,status,source,doc_code,mail_date,note) VALUES (?,?,?,"open","rule",?,?,?)',
        [$m['id'], $type, $date, $docCode, $date, 'Email the client: ' . oa_report_label($docCode) . ' (' . $docCode . ') mailed ' . fmt_date($date) . ($how !== '' ? ' (' . $how . ')' : '')]);
    $id = (int)db()->lastInsertId();
    audit('oa_report', ($m['ref'] ?? '') . ' ' . $docCode . ' ' . fmt_date($date) . ': client report to-do queued', (int)$m['id']);
    return $id;
}
/** The open report to-do on a matter (matter page header button), oldest first. */
function oa_report_open(int $matterId): ?array { return q1('SELECT * FROM events WHERE matter_id=? AND type IN (?,?) AND status IN ("open","proposed") ORDER BY due_date LIMIT 1', [$matterId, OA_REPORT_EVENT, PNOA_REPORT_EVENT]); }

/** The correspondence row the to-do reports (same matter, paper and mail date), with its oa_files row joined. */
function oa_report_corr(array $ev): ?array {
    oa_files_schema();
    return q1('SELECT c.*, f.status fstatus, f.files ffiles, f.note fnote FROM correspondence c LEFT JOIN oa_files f ON f.correspondence_id=c.id WHERE c.matter_id=? AND c.doc_code=? AND c.mail_date=? ORDER BY c.id DESC LIMIT 1', [$ev['matter_id'], (string)$ev['doc_code'], (string)$ev['mail_date']])
        // an issue report queued by verify_issue_dates carries the ISSUE date as mail_date: the matter's latest Issue Notification is the paper
        ?: ((string)$ev['doc_code'] === 'ISSUE.NTF' ? q1('SELECT c.*, f.status fstatus, f.files ffiles, f.note fnote FROM correspondence c LEFT JOIN oa_files f ON f.correspondence_id=c.id WHERE c.matter_id=? AND c.doc_code="ISSUE.NTF" AND c.mail_date<=? ORDER BY c.mail_date DESC, c.id DESC LIMIT 1', [$ev['matter_id'], (string)$ev['mail_date']]) : null);
}
/** 2026-10-06 (XYZ008): one line for a paper Madder has not downloaded yet, with a link that asks for it now (same as the Fetch
 *  button: the click starts bin/oa_fetch.php --wanted on the server). The link submits a small form of its own (button form="..."), so it also works inside
 *  the send form; print fetch_forms_html() once after that form closes. While the download runs, the line checks back every 15 seconds
 *  (?p=fetchstatus) and says when the paper is in the file or why it failed. */
function fetch_now_html(?int $corrId, string $what, string $back, string $tag = 'p'): string {
    if (!$corrId) return '<' . $tag . ' class="bad">Madder hasn\'t downloaded the ' . h($what) . ' yet.</' . $tag . '>';
    $id = 'ffetch' . $corrId;
    $GLOBALS['fetch_forms'][$id] = '<form method="post" id="' . $id . '" style="display:none">' . csrf_field() . '<input type="hidden" name="action" value="oa_fetch_now"><input type="hidden" name="corr_id" value="' . $corrId . '"><input type="hidden" name="back" value="' . h($back) . '"></form>';
    [$cls, $inner, $poll] = fetch_now_state($corrId, $what);
    return '<' . $tag . ' class="' . $cls . '" data-fetch="' . h(url('fetchstatus', ['cid' => $corrId, 'what' => $what])) . '"' . ($poll ? ' data-poll="1"' : '') . '>' . $inner . '</' . $tag . '>';
}
/** [css class, inner html, keep checking?] for the fetch line. */
function fetch_now_state(int $corrId, string $what): array {
    oa_files_schema(); $f = q1('SELECT status, note, files FROM oa_files WHERE correspondence_id=?', [$corrId]); $st = (string)($f['status'] ?? ''); $w = h($what);
    $again = ' <button type="submit" form="ffetch' . $corrId . '" class="linkbtn">Click here to try again.</button>';
    if ($st === 'wanted') return ['muted', 'Madder is trying to download the file now. Will let you know when it\'s downloaded or if there\'s an error.', true];
    if (in_array($st, ['saved', 'already'], true)) {
        $names = array_map('basename', array_filter((array)(json_decode((string)$f['files'], true) ?: []), 'is_string'));
        $ref = (string)(q1('SELECT m.ref FROM correspondence c JOIN matters m ON m.id=c.matter_id WHERE c.id=?', [$corrId])['ref'] ?? '');
        $here = []; try { $here = $ref !== '' && function_exists('matter_files') ? array_column(matter_files($ref), 'name') : []; } catch (Throwable $e) {}
        $arrived = $names ? array_values(array_intersect($names, $here)) : $here;
        if ($names && $arrived) return ['ok', 'Downloaded: ' . h(implode('; ', $arrived)) . '. <a href="" onclick="location.reload();return false">Reload the page</a> to attach it.', false];
        return ['muted', 'Downloaded' . ($names ? ': ' . h(implode('; ', $names)) : '') . '. Waiting for it to reach Madder (within 10 minutes).', true];
    }
    if ($st === 'manual') return ['bad', 'The ' . $w . ' could not be downloaded: the application is unpublished, so only Patent Center has it. Use Download with Claude.', false];
    if (in_array($st, ['failed', 'retry'], true)) return ['bad', 'The ' . $w . ' could not be downloaded: ' . h(fetch_error_text((string)$f['note'])) . $again, false];
    return ['bad', 'Madder hasn\'t downloaded the ' . $w . ' yet. <button type="submit" form="ffetch' . $corrId . '" class="linkbtn">Click here to get it now.</button>', false];
}
/** The download job's note, in plain words. */
function fetch_error_text(string $note): string {
    $n = trim($note); $code = preg_match('/HTTP (\d{3})/', $n, $mm) ? (int)$mm[1] : 0;
    if ($code === 429) return 'the USPTO is limiting downloads right now (too many requests in a short time). Wait a few minutes before trying again.';
    if ($code === 404) return 'the USPTO site says the document is not there.';
    if ($code === 403 || $code === 401) return 'the USPTO site refused the download.';
    if ($code >= 500) return 'the USPTO site had a server error (HTTP ' . $code . '); it is usually back within the hour.';
    if ($code === 0 && preg_match('/HTTP 0\b/', $n)) return 'the USPTO site could not be reached.';
    if (preg_match('/does not list/i', $n)) return 'the USPTO has not posted it yet. Madder checks again every hour.';
    if (preg_match('/Gmail not reachable/i', $n)) return 'Gmail could not be reached to get the attachment.';
    if (preg_match('/could not write/i', $n)) return 'the file could not be saved into the Dropbox folder.';
    return $n !== '' ? $n : 'no reason was recorded.';
}
/** The hidden fetch forms + the script that keeps a running download's line up to date. */
function fetch_forms_html(): string {
    $o = implode('', $GLOBALS['fetch_forms'] ?? []); if ($o === '') return ''; $GLOBALS['fetch_forms'] = [];
    return $o . '<script>(function(){var t0=Date.now();function tick(){var els=document.querySelectorAll("[data-fetch][data-poll]");if(!els.length||Date.now()-t0>3600000)return;els.forEach(function(el){fetch(el.dataset.fetch,{credentials:"same-origin",cache:"no-store"}).then(function(r){return r.json();}).then(function(j){el.className=j.cls;el.innerHTML=j.html;if(!j.poll)el.removeAttribute("data-poll");}).catch(function(){});});setTimeout(tick,15000);}setTimeout(tick,15000);})();</script>';
}
/** The deadline event the report is about: the open "Respond to Office Action - <CODE>" set by this paper (same mail date, else the nearest due after it). */
function oa_report_deadline(array $ev): ?array {
    $type = 'Respond to Office Action - ' . $ev['doc_code'];
    return q1('SELECT e.*, m.ref, m.title, m.app_no, m.reg_no, m.country, m.client_code, m.kind FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.matter_id=? AND e.type=? AND e.status IN ("open","proposed") AND e.due_date >= ? ORDER BY (e.mail_date=?) DESC, e.due_date LIMIT 1',
        [$ev['matter_id'], $type, (string)$ev['mail_date'], (string)$ev['mail_date']]);
}
/** File names in the folder that belong to this paper: what oa_fetch / Claude saved (oa_files), else anything carrying the mail date stamp. */
function oa_report_files(array $ev, ?array $corr, array $mf): array {
    $names = []; $fl = $corr && $corr['ffiles'] ? (json_decode((string)$corr['ffiles'], true) ?: []) : [];
    foreach ($fl as $n) if (is_string($n) && !str_contains($n, '/') && preg_match('/\.pdf$/i', $n)) $names[] = basename($n);
    $stamp = str_replace('-', '.', (string)$ev['mail_date']); $label = oa_report_label((string)$ev['doc_code']);
    $byLabel = fn(string $n) => stripos($n, $label) !== false || stripos($n, str_replace(' ', '-', $label)) !== false;
    if (in_array((string)$ev['doc_code'], array_merge(OA_REPORT_CODES, ['ISSUE.NTF']), true)) { foreach ($mf as $f) if (!in_array($f['name'], $names, true) && (str_contains($f['name'], $stamp) || $byLabel($f['name'])) && preg_match('/office action|notice.of.allowance|restriction|PTO-892|references cited|quayle|issue.notif/i', $f['name'])) $names[] = $f['name']; }
    // 2026-10-04: any other USPTO notice is its label ("Notice of Publication") or its mail-date stamp, never our own filings / the 892
    else foreach ($mf as $f) if (!in_array($f['name'], $names, true) && ($byLabel($f['name']) || (str_contains($f['name'], $stamp) && !preg_match('/respon|reply|amend|AckReceipt|receipt-|PTO-892|references|\bIDS\b|invoice|letter|report/i', $f['name'])))) $names[] = $f['name'];
    return array_values(array_unique($names));
}
/** Where the papers are, one line (dashboard row, matter page, send page): saved (file names) / Patent Center only (Download with Claude) / not yet fetched. */
/** 2026-10-04 (XYZ001): a paper saved into the folder by hand (or by another computer's Claude) still showed "Download with Claude".
 *  When the folder has it, the oa_files row becomes 'already' with the names, so every view (row, card, send page) agrees. Returns the healed row. */
function oa_report_heal(array $ev, ?array $c): ?array {
    if (!$c || !in_array((string)($c['fstatus'] ?? ''), ['', 'manual', 'wanted', 'retry'], true) || !function_exists('matter_files')) return $c;
    $ref = (string)($ev['ref'] ?? (q1('SELECT ref FROM matters WHERE id=?', [(int)$ev['matter_id']])['ref'] ?? '')); if ($ref === '') return $c;
    $names = oa_report_files($ev, $c, matter_files($ref)); if (!$names) return $c;
    oa_files_schema(); run('INSERT OR REPLACE INTO oa_files(correspondence_id,status,files,note,fetched_at) VALUES (?,"already",?,?,datetime("now"))', [(int)$c['id'], json_encode($names), 'found in the matter folder (saved by hand)']);
    if (function_exists('pc_schema')) { pc_schema(); run('UPDATE pc_requests SET status="done", finished_at=datetime("now"), note=? WHERE correspondence_id=? AND status IN ("sent","failed")', ['saved by hand', (int)$c['id']]); }
    return ['fstatus' => 'already', 'ffiles' => json_encode($names), 'fnote' => 'found in the matter folder (saved by hand)'] + $c;
}
function oa_report_state_html(array $ev, string $back): string {
    $c = oa_report_heal($ev, oa_report_corr($ev)); $st = (string)($c['fstatus'] ?? '');
    if (!$c || $st === '') {   // nothing recorded yet: a copy saved by hand (RWG127.003.003) still counts
        $ref = (string)($ev['ref'] ?? (q1('SELECT ref FROM matters WHERE id=?', [(int)$ev['matter_id']])['ref'] ?? ''));
        $hand = $ref !== '' && function_exists('matter_files') ? oa_report_files($ev, $c, matter_files($ref)) : [];
        if ($hand) return '<span class="ok">saved to Dropbox: ' . h(implode('; ', $hand)) . ' (saved by hand)</span>';
        return '<span class="muted">' . ($c ? 'not yet fetched (the hourly job saves it)' : 'USPTO paper not on record yet') . '</span>';
    }
    if ($st === 'wanted') { $ref = (string)($ev['ref'] ?? (q1('SELECT ref FROM matters WHERE id=?', [(int)$ev['matter_id']])['ref'] ?? '')); $hand = $ref !== '' && function_exists('matter_files') ? oa_report_files($ev, $c, matter_files($ref)) : [];
        return $hand ? '<span class="ok">saved to Dropbox: ' . h(implode('; ', $hand)) . '</span>' : '<span class="muted">fetch requested ' . h(et((string)$c['fetched_at'], 16)) . '; the laptop job picks it up within 5 minutes</span>';
    }
    if ($st === 'manual') { if (function_exists('pc_requests_for')) { $req = pc_requests_for([$c['id']])[$c['id']] ?? null; return pc_cell($c, $req, $back, 'Sign in to Patent Center in Chrome first, then click:'); } return '<span class="bad">only in Patent Center</span>'; }
    $fl = array_values(array_filter((array)(json_decode((string)$c['ffiles'], true) ?: []), 'is_string'));
    if ($st === 'already' && !$fl) { $ref = (string)($ev['ref'] ?? (q1('SELECT ref FROM matters WHERE id=?', [(int)$ev['matter_id']])['ref'] ?? '')); $fl = $ref !== '' && function_exists('matter_files') ? oa_report_files($ev, $c, matter_files($ref)) : []; }   // oa_fetch's "already saved by hand" row names no files: read them off the folder
    if (in_array($st, ['saved', 'already'], true)) return '<span class="ok" title="' . h(implode('; ', $fl) . ($c['fnote'] ? ' | ' . $c['fnote'] : '')) . '">saved to Dropbox' . ($fl ? ': ' . h(implode('; ', array_map(fn($n) => basename($n), $fl))) : '') . ($st === 'already' ? ' (saved by hand)' : '') . '</span>';
    if ($st === 'retry') return '<span class="muted">not in the Open Data Portal yet; the hourly job will retry</span>';
    return '<span class="bad">' . h($st) . '</span> <span class="muted">' . h((string)$c['fnote']) . '</span>';
}
/** The to-do's buttons: download state + gold "Send to client" (lib/render.php event_actions). */
function oa_report_actions(array $ev, string $back): string {
    $o = oa_report_state_html($ev, $back);
    // 2026-10-04: until the paper is in the folder the button is "Fetch" (the laptop job then saves it within minutes, or marks it
    // Patent Center only, which brings up Download with Claude); "Send to client" only once there is a PDF to attach.
    if (oa_report_ready($ev)) { if (auth_email() !== '') $o .= ' <a class="btn small gold" href="' . url('send', ['id' => $ev['id'], 'report' => 'oa', 'back' => $back]) . '">Send to client</a>'; }
    elseif (($c = oa_report_corr($ev)) && in_array((string)($c['fstatus'] ?? ''), ['', 'retry', 'failed'], true)) $o .= ' ' . btn('oa_fetch_now', ['corr_id' => $c['id'], 'back' => $back], 'Fetch', 'small gold');
    return $o;
}
/** Is there a saved PDF to attach (job-saved, saved by hand, or already in the folder)? */
function oa_report_ready(array $ev): bool {
    $c = oa_report_heal($ev, oa_report_corr($ev)); $st = (string)($c['fstatus'] ?? '');
    if (in_array($st, ['saved', 'already'], true)) return true;
    if ($st !== '' && $st !== 'retry' && $st !== 'wanted') return false;
    $ref = (string)($ev['ref'] ?? (q1('SELECT ref FROM matters WHERE id=?', [(int)$ev['matter_id']])['ref'] ?? ''));
    return $ref !== '' && function_exists('matter_files') && oa_report_files($ev, $c, matter_files($ref)) !== [];
}

/** Everything the send page needs for a report to-do: the template, the deadline event the wording fills from, the files to pre-tick, a notice line. */
function oa_report_context(array $ev, array $m): array {
    $code = (string)$ev['doc_code']; $tplName = oa_report_template($code, (string)$m['app_no']);
    $t = q1('SELECT * FROM templates WHERE event_type=?', [$tplName]) ?: ($code === 'NOA' ? q1('SELECT * FROM templates WHERE event_type=?', ['Respond to Office Action - NOA']) : null);
    $dl = oa_report_deadline($ev); $note = [];
    if (!$dl) {   // no deadline docketed (closed by hand?): the statutory period from the mail date, flagged
        $months = PATENT_DOC_RULES[$code]['months'] ?? 3; $dl = $ev; $dl['type'] = 'Respond to Office Action - ' . $code; $dl['due_date'] = add_months((string)$ev['mail_date'], $months);
        $note[] = 'No open "Respond to Office Action - ' . $code . '" deadline is docketed on ' . $m['ref'] . ': the deadline below is ' . $months . ' months from the mail date; check it.';
    } else $note[] = 'Deadline from "' . $dl['type'] . '" due ' . fmt_long($dl['due_date']) . ($code === 'NOA' ? '' : '; pay by ' . oa_pay_by($dl['due_date'])) . '.';
    if ($code !== 'NOA' && !is_design_matter($m)) { $ex = examiner_find($m, $ev); $note[] = $ex ? 'Examiner ' . examiner_display($ex) . ', Art Unit ' . $ex['au'] . ' (' . $ex['source'] . '); the allowance statistics are left for you to fill in.' : 'Examiner not on record: fill in the bracketed [EXAMINER NAME] / [ARTUNIT] parts and the statistics before sending.'; }
    return ['tpl' => $t, 'fill' => $dl, 'corr' => oa_report_corr($ev), 'notice' => oa_report_label($code) . ' (' . $code . ') mailed ' . fmt_long((string)$ev['mail_date']) . '. ' . implode(' ', $note)];
}
/** {PAY_BY} for an office-action report: two months before the response deadline (2026-10-04); already past = a week from today, never after the deadline. */
function oa_pay_by(string $deadline): string {
    $pb = add_months($deadline, -2, false);
    if ($pb < today()) $pb = min(add_days(today(), 7, false), $deadline);
    return fmt_long($pb);
}

// ---------------------------------------------------------------- every other USPTO patent notice
/**
 * 2026-10-04: "any notice from the USPTO for patents must be processed!" (XYZ004's Issue Notification of 2026-09-30 sat unnoticed).
 * Every patent correspondence row on a live US matter that no more specific to-do covers queues a 'Process USPTO Notice' to-do dated the mail
 * date (under "overdue / today" until done): the papers state + Fetch / Download with Claude until the PDF is saved, then a gold "Send to
 * client" (template 'Report USPTO Notice', or a code-specific one in NOTICE_TODO_TEMPLATES), or Done for internal-only papers. An Issue
 * Notification gets its proper 'Report Issue Notification' to-do at intake; its Send waits until verify_issue_dates() has read the PDF.
 */
const NOTICE_TODO_EVENT = 'Process USPTO Notice';
const NOTICE_TEMPLATE = 'Report USPTO Notice';
// lib/issuedates.php / lib/issuance.php are not loaded on every intake path (nightly, gmail_hourly): their names, repeated here.
const NOTICE_ISSUE_TYPES = ['Report Issue Notification', 'Report Issue Notification (Design)'];   // = ISSUE_REPORT_EVENTS
const NOTICE_ISSUE_VERIFIED = 'from the Issue Notification in the file';                          // = ISSUE_VERIFIED
const NOTICE_TODO_TEMPLATES = [];   // code => template, for patent notices with their own wording (none yet: "Report Filing Receipt" / "Report Notice of Publication" are the trademark emails)
// Attachments of another paper (the examiner's search notes, the 892 / 1449 / references, the e-mail copy): no to-do of their own.
const NOTICE_ATTACHMENT_CODES = ['892', '1449', 'SRNT', 'SRFW', 'IIFW', 'FWCLM', 'SRCH', 'SRCHINFO', 'EXSRCH', 'BIB', 'OA.EMAIL', 'OA.APPENDIX', 'SCORE', 'NPL', 'FOR'];
// Ignored outright (2026-10-06): no to-do, nothing saved to the folder.
const NOTICE_IGNORE_CODES = ['WELCOME.LET'];
// File-name / email labels (Title Case) where the rule label does not read as a paper's name.
const NOTICE_FILE_LABELS = ['NTC.PUB' => 'Notice of Publication', 'NOTICE.OF.PUB' => 'Notice of Publication', 'NTC.PUB.DATE' => 'Notice of Publication Date', 'APP.FILE.REC' => 'Filing Receipt',
    'N570' => 'Power of Attorney Acceptance', 'N572' => 'Power of Attorney Notice', 'M327' => 'Power of Attorney Notice', 'WELCOME.LET' => 'Welcome Letter', 'TRACK1.DENY' => 'Track One Denial',
    'TRACK1.GRANT' => 'Track One Grant', 'PPH.DECISION' => 'PPH Decision', 'PETDEC' => 'Petition Decision', 'PET.DEC.OIPE' => 'Petition Decision', 'PET.DEC.PUBS' => 'Petition Decision', 'PEFN' => 'Petition Decision',
    'EGRANT.NTF' => 'eGrant Notification', 'ISSUE.NTF' => 'Issue Notification', 'NOA' => 'Notice of Allowance', 'ABN' => 'Notice of Abandonment', 'CTMS' => 'Miscellaneous Communication'];
function is_notice_todo(string $type): bool { return $type === NOTICE_TODO_EVENT; }
/** "Notice of Publication", "Petition Decision (OIPE)"; an unknown code: "USPTO Notice <CODE>". */
function notice_label(string $code): string {
    if (isset(NOTICE_FILE_LABELS[$code])) return NOTICE_FILE_LABELS[$code];
    $l = (string)(PATENT_DOC_RULES[$code]['label'] ?? PATENT_NO_DEADLINE[$code] ?? ''); if ($l === '' || str_starts_with($l, 'USPTO paper')) return 'USPTO Notice ' . $code;
    return preg_replace_callback('/(?<=\s)(Of|To|The|And|For|A|An|In|On|By)\b/', fn($m) => strtolower($m[1]), ucwords($l));
}
/** The name a patent paper is saved under (oa_fetch / pcfetch): office actions keep the firm's "Office Action", everything else its own label. */
function patent_file_label(string $code): string { return in_array($code, ['CTNF', 'CTFR', 'CTRS', 'CTEQ'], true) ? 'Office Action' : ($code === 'NOA' ? 'Notice of Allowance' : notice_label($code)); }
/** Does a more specific to-do already cover this paper? (office action / allowance report, abandonment email, receipt reports, the issue-day grant report) */
function notice_todo_covered(array $m, string $code, string $date): bool {
    if (in_array($code, OA_REPORT_CODES, true) || in_array($code, ['ISSUE.NTF', 'ABN'], true) || $code === 'MAINT FEE PAID' || str_starts_with($code, 'ASSIGNMENT')) return true;
    // a grant: run_issuance() queues "Report Patent Issuance" off the Expected Issue Date; without one docketed near the grant, nothing would
    if (in_array($code, ['PATENT.GRANT', 'EGRANT.NTF'], true)) return (bool)q1('SELECT 1 FROM events WHERE matter_id=? AND ((type=? AND status<>"cancelled") OR (type="Expected Issue Date" AND due_date BETWEEN ? AND ?))', [$m['id'], 'Report Patent Issuance', add_days($date, -45, false), add_days($date, 45, false)]);
    return false;
}
/** Would this paper get a to-do of its own here: 'notice' (generic), 'issue' (Report Issue Notification) or null. */
function notice_todo_kind(array $m, string $code, string $date): ?string {
    if (($m['kind'] ?? '') !== 'patent' || !empty($m['dead']) || empty($m['id']) || trim($code) === '' || in_array($code, NOTICE_ATTACHMENT_CODES, true) || in_array($code, NOTICE_IGNORE_CODES, true)) return null;
    if (!preg_match('/^(|United States|US|USA)$/i', trim((string)($m['country'] ?? '')))) return null;
    if ($code === 'ISSUE.NTF') return 'issue';
    return notice_todo_covered($m, $code, $date) ? null : 'notice';
}
/** Queue the to-do at intake (apply_item, every path). Generic: one per matter + code + mail date. Returns the new event id, else null. */
function queue_notice_todo(array $m, string $code, string $date, string $how = ''): ?int {
    $k = notice_todo_kind($m, $code, $date); if ($k === null) return null;
    if ($k === 'issue') return queue_issue_todo($m, $date, $how);
    if (q1('SELECT 1 FROM events WHERE matter_id=? AND type=? AND doc_code=? AND mail_date=?', [$m['id'], NOTICE_TODO_EVENT, $code, $date])) return null;
    $known = isset(PATENT_DOC_RULES[$code]) || isset(PATENT_NO_DEADLINE[$code]) || isset(NOTICE_FILE_LABELS[$code]);
    run('INSERT INTO events(matter_id,type,due_date,status,source,doc_code,mail_date,note) VALUES (?,?,?,"open","rule",?,?,?)',
        [$m['id'], NOTICE_TODO_EVENT, $date, $code, $date, 'USPTO ' . ($known ? notice_label($code) : $code) . ' mailed ' . fmt_date($date) . ': save it, then report to the client or click Done' . ($how !== '' ? ' (' . $how . ')' : '')]);
    $id = (int)db()->lastInsertId(); audit('notice_todo', ($m['ref'] ?? '') . ' ' . $code . ' ' . fmt_date($date) . ': ' . NOTICE_TODO_EVENT . ' to-do queued', (int)$m['id']);
    return $id;
}
/** The Issue Notification's report to-do, queued when the notice comes in (before the PDF is saved); verify_issue_dates() later fills it in. */
function queue_issue_todo(array $m, string $date, string $how = ''): ?int {
    if (q1('SELECT 1 FROM events WHERE matter_id=? AND type IN (?,?) AND status<>"cancelled"', [$m['id'], NOTICE_ISSUE_TYPES[0], NOTICE_ISSUE_TYPES[1]])) return null;
    run('INSERT INTO events(matter_id,type,due_date,status,source,doc_code,mail_date,note) VALUES (?,?,?,"open","rule","ISSUE.NTF",?,?)',
        [$m['id'], is_design_matter($m) ? NOTICE_ISSUE_TYPES[1] : NOTICE_ISSUE_TYPES[0], $date, $date, 'USPTO Issue Notification mailed ' . fmt_date($date) . ': save it; the client email is offered once Madder has read the patent number and issue date off it' . ($how !== '' ? ' (' . $how . ')' : '')]);
    $id = (int)db()->lastInsertId(); audit('issue_report', ($m['ref'] ?? '') . ' Issue Notification of ' . fmt_date($date) . ': report to-do queued (waits for the PDF)', (int)$m['id']);
    return $id;
}
/** Has the Issue Notification been read off the file (the Expected Issue Date carries the date from the paper)? */
function issue_report_parsed(array $ev): bool { return (bool)q1('SELECT 1 FROM events WHERE matter_id=? AND type="Expected Issue Date" AND status<>"cancelled" AND note LIKE ?', [(int)$ev['matter_id'], '%' . NOTICE_ISSUE_VERIFIED . '%']); }
/** {ISSUE_DATE}: the date read off the Issue Notification; a report queued before that (mail_date = the notice's) has no other. */
function issue_report_issue_date(array $ev): string {
    $v = !empty($ev['matter_id']) ? q1('SELECT due_date FROM events WHERE matter_id=? AND type="Expected Issue Date" AND status<>"cancelled" AND note LIKE ? ORDER BY id DESC LIMIT 1', [(int)$ev['matter_id'], '%' . NOTICE_ISSUE_VERIFIED . '%']) : null;
    if (!empty($v['due_date'])) return (string)$v['due_date'];
    // 2026-10-04 (XYZ004): not verified yet - the issue date printed on the saved notice, else the open Expected Issue Date, never the mail date
    $ref = (string)($ev['ref'] ?? (!empty($ev['matter_id']) ? (q1('SELECT ref FROM matters WHERE id=?', [(int)$ev['matter_id']])['ref'] ?? '') : ''));
    if ($ref !== '' && function_exists('issue_ntf_read') && ($p = issue_ntf_read($ref)) && !empty($p['issue'])) return (string)$p['issue'];
    $x = !empty($ev['matter_id']) ? q1('SELECT due_date FROM events WHERE matter_id=? AND type="Expected Issue Date" AND status="open" ORDER BY id DESC LIMIT 1', [(int)$ev['matter_id']]) : null;
    return (string)($x['due_date'] ?? '') ?: ((string)($ev['mail_date'] ?? '') ?: (string)$ev['due_date']);
}
/** Is this to-do still waiting for its paper (generic notice, or an Issue Notification not read yet)? Then its row shows the papers + Fetch. */
function notice_todo_waiting(array $ev): bool { return in_array((string)$ev['type'], NOTICE_ISSUE_TYPES, true) && (string)($ev['doc_code'] ?? '') === 'ISSUE.NTF' && !issue_report_parsed($ev); }
/** Row actions (lib/render.php event_actions): papers state, Fetch until saved, then the gold Send (generic: report=oa; issue: its own report). */
function notice_todo_actions(array $ev, string $back): string {
    $o = oa_report_state_html($ev, $back); $c = oa_report_corr($ev); $st = (string)($c['fstatus'] ?? '');
    if (is_notice_todo((string)$ev['type']) && oa_report_ready($ev)) return $o . (auth_email() !== '' ? ' <a class="btn small gold" href="' . url('send', ['id' => $ev['id'], 'report' => 'oa', 'back' => $back]) . '">Send to client</a>' : '');
    if (!is_notice_todo((string)$ev['type']) && oa_report_ready($ev)) return $o . ' <span class="muted">Madder reads the patent number and issue date off it within the hour; the client email is offered then</span>';
    if ($c && in_array($st, ['', 'retry', 'failed'], true)) $o .= ' ' . btn('oa_fetch_now', ['corr_id' => $c['id'], 'back' => $back], 'Fetch', 'small gold');
    return $o;
}
/** The send page for a 'Process USPTO Notice' to-do: template, the event the wording fills from (the to-do), files to tick, a notice line. */
// 2026-10-06 (RWG166.001.01): a provisional's filing receipt names the non-provisional / foreign-filing deadline and asks the client to
// contact us four months before it.
const PROV_RECEIPT_TEMPLATE = 'Report Provisional Filing Receipt';
const PROV_CONTACT_MONTHS = 4;
function is_provisional_matter(array $m): bool { return stripos((string)($m['app_type'] ?? ''), 'provisional') !== false || preg_match('/^6\d{7}$/', preg_replace('/\D/', '', (string)($m['app_no'] ?? ''))); }
/** The provisional's 12-month deadline (non-provisional / foreign filing), open or proposed; null when none is docketed. */
function prov_deadline(int $matterId): ?array {
    return q1('SELECT * FROM events WHERE matter_id=? AND status IN ("open","proposed") AND (type LIKE "Non-Provisional%" OR type LIKE "Foreign Filing%" OR type LIKE "%International Filing Deadline%") AND type NOT LIKE "Remind Client%" ORDER BY due_date LIMIT 1', [$matterId]) ?: null;
}
function notice_todo_context(array $ev, array $m): array {
    $code = (string)$ev['doc_code']; $tplName = NOTICE_TODO_TEMPLATES[$code] ?? NOTICE_TEMPLATE; $dl = notice_deadline($ev);
    if ($code === 'APP.FILE.REC' && is_provisional_matter($m) && q1('SELECT 1 FROM templates WHERE event_type=?', [PROV_RECEIPT_TEMPLATE])) { $tplName = PROV_RECEIPT_TEMPLATE; $dl = prov_deadline((int)$m['id']) ?? $dl; }
    return ['tpl' => q1('SELECT * FROM templates WHERE event_type=?', [$tplName]), 'tpl_name' => $tplName, 'fill' => $ev, 'corr' => oa_report_corr($ev),
            'notice' => 'USPTO ' . notice_label($code) . ' (' . $code . ') mailed ' . fmt_long((string)$ev['mail_date']) . '. ' . ($dl ? 'It set "' . $dl['type'] . '" due ' . fmt_long((string)$dl['due_date']) . ' (named in the email).' : 'No deadline came with it.') . ' If the client need not hear about it, click Done on the to-do instead.'];
}
/** The open deadline the same paper set (same matter, code and mail date), if any. */
function notice_deadline(array $ev): ?array {
    foreach (q('SELECT * FROM events WHERE matter_id=? AND doc_code=? AND mail_date=? AND status IN ("open","proposed") AND id<>? ORDER BY due_date', [(int)($ev['matter_id'] ?? 0), (string)($ev['doc_code'] ?? ''), (string)($ev['mail_date'] ?? ''), (int)($ev['id'] ?? 0)]) as $x)
        if (!is_notice_type((string)$x['type']) && !is_notice_todo((string)$x['type'])) return $x;
    return null;
}
/** {NOTICE} / {NOTICE_NEXT} for fill_template. */
function notice_fill(array $ev): array {
    $dl = notice_deadline($ev);
    $m = !empty($ev['matter_id']) ? q1('SELECT * FROM matters WHERE id=?', [(int)$ev['matter_id']]) : null; $pd = $m ? prov_deadline((int)$m['id']) : null;
    $fd = substr((string)($m['filing_date'] ?? ''), 0, 10);
    return ['{NOTICE}' => notice_label((string)($ev['doc_code'] ?? '')), '{NOTICE_NEXT}' => $dl ? 'The deadline to respond is ' . fmt_long((string)$dl['due_date']) . '.' : '',
            '{FILED}' => $fd !== '' ? fmt_long($fd) : '', '{NONPROV_DEADLINE}' => $pd ? fmt_long((string)$pd['due_date']) : '',
            '{CONTACT_BY}' => $pd ? fmt_long(add_months((string)$pd['due_date'], -PROV_CONTACT_MONTHS, false)) : ''];
}
/** The generic template bin/seed_templates.php seeds (unapproved, no invoice). [type, subject, body] */
function notice_todo_seed(): array { return [NOTICE_TEMPLATE, '{FILE}: Application {APP} - {NOTICE}', "{SUMMARY}\n\nPlease find attached a {NOTICE} received from the U.S. Patent Office in the above matter.\n\n{NOTICE_NEXT}"]; }

// ---------------------------------------------------------------- examiner
/** "SMITH, JOHN A" (ODP) or "JOHN A SMITH" (the action's header) -> ['last','first','mi']. */
function parse_examiner_name(string $name): array {
    $name = trim(preg_replace('/\s+/', ' ', $name)); if ($name === '') return [];
    if (str_contains($name, ',')) { [$last, $rest] = array_map('trim', explode(',', $name, 2)); $p = $rest === '' ? [] : explode(' ', $rest); }
    else { $p = explode(' ', $name); $last = array_pop($p); }
    $first = $p ? array_shift($p) : ''; $mi = $p ? rtrim(implode(' ', $p), '.') : '';
    return ['last' => strtoupper($last), 'first' => strtoupper($first), 'mi' => strtoupper($mi)];
}
/** Examiner and art unit off the first page of a Patent Center office action (pdftotext -layout, or the DOCX text): ['name','au'] or []. */
function parse_examiner(string $text): array {
    $t = preg_replace('/[ \t\x{00a0}]+/u', ' ', $text);
    if (preg_match('/Examiner\s*\|?\s*Art Unit[^\n]*\n\s*\|?\s*([A-Z][A-Za-z.\'\- ]{2,60}?)\s*\|?\s{1,}(\d{4})\b/u', $t, $m)) return ['name' => trim($m[1]), 'au' => $m[2]];
    if (preg_match('/Examiner\s+Art Unit\s+AIA[^\n]*\s+([A-Z][A-Za-z.\'\- ]{2,60}?)\s+(\d{4})\s/u', $t, $m)) return ['name' => trim($m[1]), 'au' => $m[2]];
    if (preg_match('/Examiner:\s*([A-Z][A-Za-z.,\'\- ]{2,60}?)\s*(?:\n|\|).{0,80}?Art Unit:?\s*(\d{4})/su', $t, $m)) return ['name' => trim($m[1]), 'au' => $m[2]];
    return [];
}
/** The examiner for a matter: the Open Data Portal (published applications), else the saved action's DOCX / PDF in the folder; [] when unknown. Cached per matter. */
function examiner_find(array $m, array $ev): array {
    static $cache = []; $mid = (int)($m['id'] ?? $ev['matter_id'] ?? 0); if (isset($cache[$mid])) return $cache[$mid];
    $out = []; $app = preg_replace('/\D/', '', (string)($m['app_no'] ?? ''));
    if (empty($GLOBALS['cfg']['offline']) && strlen($app) === 8 && function_exists('http_json') && !empty($GLOBALS['cfg']['odp_key'])) {
        try { $r = http_json("https://api.uspto.gov/api/v1/patent/applications/$app", ['X-API-KEY: ' . $GLOBALS['cfg']['odp_key']]); $md = $r['json']['patentFileWrapperDataBag'][0]['applicationMetaData'] ?? [];
              if (!empty($md['examinerNameText'])) $out = ['name' => (string)$md['examinerNameText'], 'au' => (string)($md['groupArtUnitNumber'] ?? ''), 'source' => 'USPTO Open Data Portal']; } catch (Throwable $e) {}
    }
    if (!$out && function_exists('mf_find') && ($c = oa_report_corr(['matter_id' => $mid, 'doc_code' => $ev['doc_code'] ?? '', 'mail_date' => $ev['mail_date'] ?? ''])) && in_array((string)($c['fstatus'] ?? ''), ['saved', 'already'], true) && ($dir = mf_find((string)($m['ref'] ?? '')))) {
        $fl = array_values(array_filter((array)(json_decode((string)$c['ffiles'], true) ?: []), fn($n) => is_string($n) && !str_contains($n, '/') && preg_match('/\.(docx|pdf)$/i', $n)));
        usort($fl, fn($a, $b) => (int)preg_match('/\.pdf$/i', $a) <=> (int)preg_match('/\.pdf$/i', $b));   // DOCX first: its text is clean
        foreach ($fl as $n) { $p = mf_local($dir, $n); if ($p === '') continue; $x = parse_examiner(preg_match('/\.docx$/i', $p) ? doc_text($p) : pdf_text($p, 1, 2)); if ($x) { $out = $x + ['source' => 'the saved ' . (preg_match('/\.docx$/i', $n) ? 'DOCX' : 'PDF')]; break; } }
    }
    return $cache[$mid] = $out;
}
function examiner_display(array $ex): string { $p = parse_examiner_name((string)$ex['name']); return trim(ucwords(strtolower(trim($p['first'] . ' ' . ($p['mi'] !== '' ? $p['mi'] . ' ' : '') . $p['last'])))); }
/**
 * {EXAMINER_BLOCK} (2026-10-04, verbatim): the examiner and the three statistics sources. Utility applications only: a design
 * application gets nothing (the blank lines collapse). Unknown examiner: the bracketed placeholders stay, so they cannot be missed.
 * The percentage and "[good]" are always left for the sender (item 4 of the plan: Claude fills them in during the download session).
 */
function examiner_block(array $m, array $ev): string { return is_design_matter($m) ? '' : examiner_render(examiner_find($m, $ev)); }
/** The block's text for a known examiner (['name','au']) or [] = unknown (bracketed). */
function examiner_render(array $ex): string {
    $p = $ex ? parse_examiner_name((string)$ex['name']) : [];
    $au = $ex['au'] ?? ''; $au = $au !== '' ? $au : '[ARTUNIT]';
    $last = $p['last'] ?? '[LAST]'; $first = $p['first'] ?? '[FIRST]'; $mi = $p ? $p['mi'] : '[MI]';
    $name = $ex ? examiner_display($ex) : '[EXAMINER NAME]';
    $u1 = 'https://portal.unifiedpatents.com/prosecution/examiner/' . ($p ? ucfirst(strtolower($last)) . ',%20' . ucfirst(strtolower($first)) . ($mi !== '' ? '%20' . $mi : '') : '[Last],%20[First]%20[MI]');
    $tail = $au . '-' . $last . '-' . $first . ($mi !== '' ? '-' . $mi : '');
    return "In the present case, your examiner is $name. He/She has [good] allowance statistics:\nSource 1: $u1\nSource 2: https://ironcrowai.com/prosecution-statistics/$tail\nSource 3: https://www.patentbots.com/stats/examiner/$tail\n\nHe/She ultimately allows about X% of cases which increases with a telephone or video interview.";
}

/** The three templates bin/seed_templates.php seeds (unapproved): the attorney's verbatim wording of 2026-10-04. [type, subject, body, needs_invoice] */
function oa_report_seeds(): array {
    $tail = "{EXAMINER_BLOCK}\n\nPlease find attached:\n- a copy of the Office Action\n- links to download references cited in the Office Action\n- my invoice\n\nThe invoice is for the first stage of a full line-by-line review of the Office Action and a written report. The invoice also includes the interview with the Examiner. This is approximately 4.5 hours of work.\n\nAfter this stage is complete, we will need to respond in writing with arguments and/or amendments to the claims to overcome the rejection. The time needed for this step depends on the results of the first step though is typically between 2 to 4 more hours.\n\nThe deadline to respond to the Office Action is {DEADLINE}. Therefore, please pay the invoice by {PAY_BY} in order to allow enough time to do the work.";
    return [
        ['Report Office Action', '{FILE}: Application {APP} - Office Action', "{SUMMARY}\n\nPlease find attached an Office Action from the U.S. Patent Office which is the first substantive examination of the claims listed in your patent application. As is typical, the first Office Action rejects all the claims. Now, we review and negotiate with the examiner.\n\n" . $tail, 1],
        ['Report Final Office Action', '{FILE}: Application {APP} - Final Office Action', "{SUMMARY}\n\nPlease find attached a Final Office Action from the U.S. Patent Office. Now, we review and negotiate with the examiner.\n\n" . $tail, 1],
        ['Report Restriction Requirement', '{FILE}: Application {APP} - Restriction Requirement', "{SUMMARY}\n\nPlease find attached a Restriction Requirement from the U.S. Patent Office: the examiner considers the application to contain more than one invention and asks us to elect one group of claims to examine first. The groups are listed in the attached document. The deadline to respond is {DEADLINE}.", 0],
    ];
}
