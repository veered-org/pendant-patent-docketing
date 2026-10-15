<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * Patent Center downloads with Claude, supervised (2026-10-03). Unpublished applications are not in the Open Data Portal, so
 * oa_fetch.php marks their office actions 'manual'. the attorney or staff clicks "Download with Claude" (dashboard card: all; record page: one).
 * Pendant works out the folder and the exact file names from its Dropbox index (next docket code, oa_fetch naming) and opens
 * pendant-pc://run?d=<the request, base64url>. On a computer set up for it (the attorney: bin/pc_launch.sh; staff's Mac / Windows: the kit
 * in tools/pendant-claude/) that opens a terminal with an ordinary interactive Claude session (--chrome) which the person watches and
 * approves: it downloads each paper in their own signed-in browser and saves it into their synced Dropbox matter folder under the given
 * name. No server access is needed on that computer: Pendant sees the file arrive through Dropbox (pc_reconcile) and marks the row saved.
 * Patent Center refuses headless/scripted clients, and nothing here runs unattended.
 */
const PC_DAYS = 60;       // office actions older than this are not offered
const PC_BATCH = 8;       // documents per Claude session (keeps the pendant-pc: link short enough for Windows)
const PC_GIVE_UP_H = 24;  // a request with no file in Dropbox after this long is failed, so the button comes back

function pc_schema(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS pc_requests (id INTEGER PRIMARY KEY, correspondence_id INTEGER NOT NULL, matter_id INTEGER, requested_by TEXT, requested_at TEXT DEFAULT (datetime('now')),
        status TEXT DEFAULT 'sent', folder TEXT, action_base TEXT, ref892_base TEXT, finished_at TEXT, note TEXT DEFAULT '')");
    db()->exec('CREATE INDEX IF NOT EXISTS pc_requests_corr ON pc_requests(correspondence_id)');
}
function pc_can_run(): bool { return is_admin() || auth_user() === 'joshua'; }   // 2026-10-03: the attorney or staff

/** Office actions only Patent Center has (oa_files 'manual', last PC_DAYS days), with the latest request's status. */
function pc_waiting(): array {
    pc_schema();
    // 2026-10-04 (XYZ001): papers already in the folder drop off the "only in Patent Center" card (oa_report_heal marks them 'already')
    if (function_exists('oa_report_heal')) foreach (q('SELECT c.*, m.ref, f.status fstatus, f.files ffiles, f.note fnote FROM oa_files f JOIN correspondence c ON c.id=f.correspondence_id JOIN matters m ON m.id=c.matter_id WHERE f.status="manual" AND c.mail_date >= date("now", ?)', ['-' . PC_DAYS . ' days']) as $w) oa_report_heal($w, $w);
    return q('SELECT c.id, m.ref, c.doc_code, c.mail_date, (SELECT r.status FROM pc_requests r WHERE r.correspondence_id=c.id ORDER BY r.id DESC LIMIT 1) pc_status
        FROM oa_files f JOIN correspondence c ON c.id=f.correspondence_id JOIN matters m ON m.id=c.matter_id
        WHERE f.status="manual" AND c.mail_date >= date("now", ?) ORDER BY c.mail_date', ['-' . PC_DAYS . ' days']);
}
function pc_requests_for(array $corrIds): array {
    pc_schema(); if (!$corrIds) return [];
    $out = []; foreach (q('SELECT * FROM pc_requests WHERE correspondence_id IN (' . implode(',', array_map('intval', $corrIds)) . ') ORDER BY id') as $r) $out[$r['correspondence_id']] = $r;
    return $out;
}

/** Plan a request per correspondence row (reusing a pending one, so a second click does not take a new docket code). Returns request ids. */
function pc_plan(array $corrIds, string $by): array {
    pc_schema(); $ids = []; $taken = [];   // folder => names planned in this batch
    foreach ($corrIds as $cid) {
        $c = q1('SELECT c.id, c.matter_id, c.doc_code, c.mail_date, m.ref FROM correspondence c JOIN matters m ON m.id=c.matter_id WHERE c.id=?', [(int)$cid]); if (!$c) continue;
        if ($p = q1('SELECT id FROM pc_requests WHERE correspondence_id=? AND status="sent" ORDER BY id DESC LIMIT 1', [$c['id']])) { $ids[] = (int)$p['id']; continue; }
        $label = $c['doc_code'] === 'NOTICE OF ALLOWANCE' ? 'Notice of Allowance' : patent_file_label((string)$c['doc_code']);   // 2026-10-04: every patent notice under its own name
        $dir = mf_find($c['ref']); $folder = $dir['name'] ?? $c['ref'];
        $have = $dir ? mf_names($dir) : [];
        foreach (q('SELECT action_base, ref892_base FROM pc_requests WHERE folder=? AND status="sent"', [$folder]) as $o) { $have[] = $o['action_base'] . '.pdf'; $have[] = $o['ref892_base'] . '.pdf'; }   // other pending plans
        $have = array_merge($have, $taken[$folder] ?? []);
        $code = patent_next_code($have, $c['ref'], $label); $abase = oa_basename($c['ref'], $code, $label, $c['mail_date']);
        $code2 = $code !== '' ? next_doc_code(array_merge($have, [$abase . '.pdf']), $c['ref']) : ''; $rbase = oa_basename($c['ref'], $code2, 'References Cited (PTO-892)', $c['mail_date']);
        $taken[$folder][] = $abase . '.pdf'; $taken[$folder][] = $rbase . '.pdf';
        run('INSERT INTO pc_requests(correspondence_id,matter_id,requested_by,folder,action_base,ref892_base,note) VALUES (?,?,?,?,?,?,?)',
            [$c['id'], $c['matter_id'], $by, $folder, $abase, $rbase, $dir ? '' : 'no matter folder in Dropbox yet']);
        $ids[] = (int)db()->lastInsertId();
    }
    return $ids;
}

/** The request Claude reads (instructions + jobs), for the pendant-pc: link. */
function pc_request_text(array $reqIds, string $by): string {
    $rows = $reqIds ? q('SELECT r.*, m.ref, c.app_no, c.doc_code, c.mail_date FROM pc_requests r JOIN correspondence c ON c.id=r.correspondence_id JOIN matters m ON m.id=c.matter_id WHERE r.id IN (' . implode(',', array_map('intval', $reqIds)) . ') ORDER BY r.id') : [];
    $us = fn(string $iso) => substr($iso, 5, 2) . '/' . substr($iso, 8, 2) . '/' . substr($iso, 0, 4);
    $jobs = [];
    foreach ($rows as $i => $r) $jobs[] = ($i + 1) . ". {$r['ref']}: application {$r['app_no']}, document code {$r['doc_code']}, mail room date " . $us($r['mail_date']) . "\n"
        . "   Patent Center: https://patentcenter.uspto.gov/applications/{$r['app_no']}/ifw/docs\n"
        . "   Folder: <matters>/{$r['folder']}" . ($r['note'] === 'no matter folder in Dropbox yet' ? '   (it does not exist yet: create it)' : '') . "\n"
        . "   Save the document as: {$r['action_base']}.pdf   (and {$r['action_base']}.docx if Patent Center offers it as DOCX/Word)\n"
        . "   A PTO-892 (Notice of References Cited) with the same mail room date, if any: {$r['ref892_base']}.pdf";
    return "Pendant request from $by, " . date('Y-m-d H:i') . " (" . firm('name') . " docket).\n\n"
        . "These USPTO papers are in unpublished applications, so only Patent Center has them. Download each one in this computer's browser (Claude in Chrome) and save it into the Dropbox matter folder under the exact name given. The person who clicked the button is watching.\n\n"
        . "Documents:\n" . implode("\n\n", $jobs) . "\n\n"
        . "How:\n"
        . "- Open the Patent Center link in a tab of your tab group. If Patent Center shows a sign-in page, STOP and ask me to sign in; never type anything into a sign-in page. Continue when I say so.\n"
        . "- Find the row with that mail room date and document code and download its PDF (and DOCX if offered). Do the same for a PTO-892 of the same date.\n"
        . "- Each download lands in the browser's download folder (newest file there). Move it into the matter folder under the name above. <matters> is the Dropbox matters folder named when this session started.\n"
        . "- Check each saved PDF really is a PDF (starts with %PDF) and not an error page. Never overwrite an existing file: if the name is taken, stop and tell me.\n"
        . "- If a document is not listed or will not download, skip it and tell me why.\n"
        . "- Only read and download. Never click anything that files, submits, pays, signs or changes anything in Patent Center. Close the tabs you opened when done.\n\n"
        . "Finish with one line per document: the file(s) saved, or why not. Pendant notices the files once Dropbox syncs (usually within minutes) and marks them saved.";
}
/** The pendant-pc: link carries only a one-time token; the launcher fetches the request text from Pendant itself (a fixed address in
 *  the launcher), so a link made anywhere else cannot put its own instructions into a Claude session. Tokens last PC_TOKEN_H hours. */
const PC_TOKEN_H = 2;
function pc_link(array $reqIds, string $by): string {
    db()->exec("CREATE TABLE IF NOT EXISTS pc_tokens (token TEXT PRIMARY KEY, req_ids TEXT, by_user TEXT, created_at TEXT DEFAULT (datetime('now')), fetched_at TEXT)");
    $t = bin2hex(random_bytes(16));
    run('INSERT INTO pc_tokens(token,req_ids,by_user) VALUES (?,?,?)', [$t, implode(',', array_map('intval', $reqIds)), $by]);
    return 'pendant-pc://run?t=' . $t;
}
/** ?p=pc_req&t=TOKEN, before any session: the request text, or 404. */
function pc_serve_request(string $token): void {
    header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store');
    db()->exec("CREATE TABLE IF NOT EXISTS pc_tokens (token TEXT PRIMARY KEY, req_ids TEXT, by_user TEXT, created_at TEXT DEFAULT (datetime('now')), fetched_at TEXT)");
    $r = preg_match('/^[0-9a-f]{32}$/', $token) ? q1('SELECT * FROM pc_tokens WHERE token=? AND created_at > datetime("now", ?)', [$token, '-' . PC_TOKEN_H . ' hours']) : null;
    if (!$r) { http_response_code(404); exit("This Pendant link has expired. Click Download with Claude again.\n"); }
    run('UPDATE pc_tokens SET fetched_at=datetime("now") WHERE token=?', [$token]);
    exit(pc_request_text(array_map('intval', explode(',', (string)$r['req_ids'])), (string)$r['by_user']));
}

/** Mark rows saved once their files show up in the Dropbox index; fail requests with nothing after PC_GIVE_UP_H hours. */
function pc_reconcile(): void {
    pc_schema();
    $pending = q('SELECT r.*, m.ref, c.mail_date FROM pc_requests r JOIN correspondence c ON c.id=r.correspondence_id JOIN matters m ON m.id=c.matter_id WHERE r.status="sent"');
    if (!$pending) return;
    if (function_exists('dbx_ready') && dbx_ready() && (setting('pc_last_sync') ?? '') < gmdate('Y-m-d H:i:s', time() - 120)) {   // a quick incremental pull, at most every 2 minutes
        run('INSERT OR REPLACE INTO settings(key,value) VALUES ("pc_last_sync", ?)', [gmdate('Y-m-d H:i:s')]);
        try { dbx_sync(8); } catch (Throwable $e) {}
    }
    foreach ($pending as $r) {
        $dir = mf_find($r['ref']); $names = $dir ? mf_names($dir) : [];
        $got = array_values(array_filter($names, fn($n) => str_starts_with($n, $r['action_base'] . '.') || str_starts_with($n, $r['ref892_base'] . '.')));
        if (!$got && $names && (oa_already_saved($names, $r['mail_date']) || oa_already_saved($names, $r['mail_date'], preg_replace('/^.*-\d{2}[a-z]-| of \d{4}\.\d{2}\.\d{2}$/', '', (string)$r['action_base'])))) $got = array_values(array_filter($names, fn($n) => str_contains($n, str_replace('-', '.', $r['mail_date']))));
        if ($got && array_filter($got, fn($n) => !str_contains($n, 'PTO-892'))) {
            run('UPDATE pc_requests SET status="saved", finished_at=datetime("now") WHERE id=?', [$r['id']]);
            run('INSERT OR REPLACE INTO oa_files(correspondence_id,status,files,note,fetched_at) VALUES (?,?,?,?,datetime("now"))', [$r['correspondence_id'], 'saved', json_encode($got), 'From Patent Center (Claude, requested by ' . $r['requested_by'] . ')']);
            run('INSERT INTO audit(user,action,detail,matter_id) VALUES (?,?,?,?)', ['claude (' . $r['requested_by'] . ')', 'pc_saved', implode('; ', $got), $r['matter_id']]);
        } elseif ($r['requested_at'] < gmdate('Y-m-d H:i:s', time() - PC_GIVE_UP_H * 3600)) {
            run('UPDATE pc_requests SET status="failed", finished_at=datetime("now"), note=? WHERE id=?', ['No file reached the Dropbox folder within ' . PC_GIVE_UP_H . ' hours', $r['id']]);
        }
    }
}

/** Dashboard card, only while something is waiting; one click plans them all. */
function pc_dash_card(): string {
    pc_reconcile(); $w = pc_waiting(); if (!$w) return '';
    $tip = implode(', ', array_map(fn($r) => $r['ref'] . ' ' . $r['doc_code'] . ' ' . $r['mail_date'] . ($r['pc_status'] === 'sent' ? ' (sent to Claude)' : ''), $w));
    $inner = '<b>' . count($w) . '</b><span>only in Patent Center</span>';
    if (!pc_can_run()) return '<div class="card d3" title="' . h($tip) . '">' . $inner . '</div>';
    return '<form method="post" class="card d3" style="cursor:pointer" title="' . h('Download with Claude: ' . $tip) . '" onclick="this.submit()">' . csrf_field()
        . '<input type="hidden" name="action" value="pc_request"><input type="hidden" name="corr_id" value="all"><input type="hidden" name="back" value="' . h(here_uri()) . '">' . $inner . '</form>';
}
/** The "Saved to Dropbox" cell of a 'manual' row. $lead (2026-10-04, report to-dos): the sentence before the button instead of "Unpublished: ...". */
function pc_cell(array $corr, ?array $req, string $back, string $lead = ''): string {
    $st = $req['status'] ?? '';
    if ($st === 'sent') return '<span class="muted">Sent to Claude by ' . h((string)$req['requested_by']) . ' (' . h(et((string)$req['requested_at'], 16)) . '); waiting for ' . h($req['action_base']) . '.pdf in Dropbox</span> '
        . (pc_can_run() ? '<a class="btn small ghost" href="' . url('pc_launch', ['ids' => $req['id']]) . '">Open Claude again</a>' : '');
    $o = ($st === 'failed' ? '<span class="bad">Patent Center download failed</span> ' . h((string)$req['note']) . ' ' : '') . ($lead !== '' ? '<span class="muted">' . h($lead) . '</span> ' : ($st === 'failed' ? '' : '<span class="bad">Unpublished: only Patent Center has it</span> '));
    return $o . (pc_can_run() ? btn('pc_request', ['corr_id' => $corr['id'], 'back' => $back], $st === 'failed' ? 'Try again with Claude' : 'Download with Claude', 'small gold') : '');
}
