<?php
declare(strict_types=1);
/**
 * Waiting on client (2026-10-09): what the firm is waiting on someone else for — the client to sign (trademark application,
 * Statement of Use, a document on the e-sign page), comments on a draft, an examiner to grant an interview. Rows come from sending a
 * signing email (automatic) or from the Dashboard form (file number + note). A row clears itself only on proof:
 *   - Trademark Center "Trademark application MARK ready for payment and submission - Docket number REF" (the client signed);
 *   - TEAS "Submission link for <form> for <serial / reg. no.>" (signature completed), or the USPTO filing receipt for that number;
 *   - the e-sign page's signed/<doc>.log.json for a pasted ?d=<doc> link.
 * Anything else (comments, interviews, a Trademark Center form with no docket number) waits for the Done button.
 */
const WAIT_KINDS = ['sign_tm' => 'Sign trademark application', 'sign_sou' => 'Sign Statement of Use', 'sign_postreg' => 'Sign declaration / renewal', 'sign_doc' => 'Sign document',
                    'comments' => 'Comments on draft', 'interview' => 'Examiner to grant interview', 'other' => 'Other'];

function waiting_schema(): void {
    static $d = false; if ($d) return; $d = true;
    db()->exec("CREATE TABLE IF NOT EXISTS waiting (id INTEGER PRIMARY KEY, matter_id INTEGER NOT NULL, kind TEXT NOT NULL, note TEXT DEFAULT '',
        deadline_event_id INTEGER, esign TEXT DEFAULT '', started_at TEXT DEFAULT (datetime('now')), created_by TEXT DEFAULT '',
        cleared_at TEXT, cleared_by TEXT, cleared_why TEXT DEFAULT '')");
    if (!q1("SELECT 1 FROM pragma_table_info('waiting') WHERE name='src'")) db()->exec("ALTER TABLE waiting ADD COLUMN src TEXT DEFAULT ''");   // the e-mail that opened the row (AIR)
}

/** Add a row (one open row per matter + kind: a second add updates its note). Returns the row id. */
function waiting_add(int $matterId, string $kind, string $note = '', ?int $deadlineId = null, string $esign = '', ?string $startedAt = null): int {
    waiting_schema(); if (!isset(WAIT_KINDS[$kind])) $kind = 'other';
    $esign = waiting_esign_id($esign);
    if ($kind !== 'other' && ($w = q1('SELECT id FROM waiting WHERE matter_id=? AND kind=? AND cleared_at IS NULL', [$matterId, $kind]))) {
        run('UPDATE waiting SET note=?, deadline_event_id=COALESCE(?, deadline_event_id), esign=CASE WHEN ?<>"" THEN ? ELSE esign END WHERE id=?', [$note, $deadlineId, $esign, $esign, $w['id']]);
        return (int)$w['id'];
    }
    run('INSERT INTO waiting(matter_id,kind,note,deadline_event_id,esign,started_at,created_by) VALUES (?,?,?,?,?,COALESCE(?, datetime("now")),?)', [$matterId, $kind, $note, $deadlineId, $esign, $startedAt, function_exists('auth_user') ? (string)auth_user() : '']);
    $id = (int)db()->lastInsertId(); audit('waiting_add', WAIT_KINDS[$kind] . ($note !== '' ? ": $note" : ''), $matterId);
    return $id;
}

function waiting_clear(int $id, string $by, string $why): void {
    waiting_schema(); $w = q1('SELECT * FROM waiting WHERE id=? AND cleared_at IS NULL', [$id]); if (!$w) return;
    run('UPDATE waiting SET cleared_at=datetime("now"), cleared_by=?, cleared_why=? WHERE id=?', [$by, $why, $id]);
    audit('waiting_clear', WAIT_KINDS[$w['kind']] . ': ' . $why, (int)$w['matter_id']);
}

/** "https://example.com/private/?d=abc-XYZ" or "abc-XYZ" -> "abc-XYZ"; '' for anything else. */
function waiting_esign_id(string $s): string {
    $s = trim($s); if ($s === '') return '';
    if (preg_match('/[?&]d=([A-Za-z0-9_\-]+)/', $s, $m)) return $m[1];
    return preg_match('/^[A-Za-z0-9_\-]{8,}$/', $s) ? $s : '';
}

/** The deadline shown on a row: its own linked event, else the matter's earliest open real deadline (not a to-do, reminder or report). */
function waiting_deadline(array $w): ?array {
    if (!empty($w['deadline_event_id']) && ($e = q1('SELECT * FROM events WHERE id=? AND status IN ("open","proposed")', [$w['deadline_event_id']]))) return $e;
    foreach (q('SELECT * FROM events WHERE matter_id=? AND status IN ("open","proposed") ORDER BY due_date, id', [$w['matter_id']]) as $e)
        if (!preg_match('/^(Remind|Report|Ask Client|Email|Send|Process|Confirm)\b/i', (string)$e['type']) && !is_notice_type((string)$e['type'])) return $e;
    return null;
}

/** "12 days until deadline" / "deadline today" / "deadline passed 3 days ago" / "deadline n/a". */
function waiting_deadline_text(?array $e): string {
    if (!$e) return 'deadline n/a';
    $d = days_until((string)$e['due_date']);
    return $d > 0 ? $d . ' day' . ($d === 1 ? '' : 's') . ' until deadline' : ($d === 0 ? 'deadline today' : 'deadline passed ' . abs($d) . ' day' . ($d === -1 ? '' : 's') . ' ago');
}

function waiting_open(): array {
    waiting_schema();
    return q('SELECT w.*, m.ref, m.title, m.kind mkind, m.app_no, m.reg_no FROM waiting w JOIN matters m ON m.id=w.matter_id WHERE w.cleared_at IS NULL ORDER BY w.started_at, w.id');
}
function waiting_count(): int { waiting_schema(); return (int)(q1('SELECT count(*) c FROM waiting WHERE cleared_at IS NULL')['c'] ?? 0); }

/** Proof that row $w is done, or null. Only USPTO mail seen after the row was opened counts. */
function waiting_proof(array $w): ?string {
    $since = (string)$w['started_at'];
    if ($w['esign'] !== '') {
        $dir = $GLOBALS['cfg']['esign_signed_dir'] ?? dirname(__DIR__, 2) . '/signed';
        if (is_file("$dir/{$w['esign']}.log.json")) return 'signed on the e-sign page (' . $w['esign'] . ')';
    }
    if (in_array($w['kind'], ['sign_tm', 'sign_postreg'], true)) {   // Trademark Center: the client signed (applications and, 2026-10-14, declarations / renewals)
        foreach (q('SELECT subject, ts FROM gmail_seen WHERE ts >= ? AND subject LIKE "%ready for payment and submission - Docket number%"', [$since]) as $g)
            if (preg_match('/Docket number (\S+)\s*$/i', (string)$g['subject'], $x) && strcasecmp($x[1], (string)$w['ref']) === 0) return 'client signed: "' . $g['subject'] . '" (' . substr((string)$g['ts'], 0, 10) . ')';
    }
    if (in_array($w['kind'], ['sign_sou', 'sign_doc', 'sign_tm', 'sign_postreg'], true)) {
        foreach (array_filter([preg_replace('/\D/', '', (string)$w['app_no']), preg_replace('/\D/', '', (string)$w['reg_no'])], fn($n) => strlen($n) >= 7) as $num)
            foreach (q('SELECT subject, ts FROM gmail_seen WHERE ts >= ? AND instr(subject, ?) > 0', [$since, $num]) as $g) {
                $s = (string)$g['subject'];
                if (preg_match('/^Submission link for .+ for ' . $num . '\s*$/i', $s)) return 'client signed: TEAS "' . $s . '" (' . substr((string)$g['ts'], 0, 10) . ')';
                if ($w['kind'] === 'sign_sou' && preg_match('/(Received Your (Serial|Registration) Number \(' . $num . '\)|' . $num . ': Received Your)/i', $s) && preg_match('/Allegation of Use|SOU|Statement of Use/i', $s)) return 'filed: "' . $s . '" (' . substr((string)$g['ts'], 0, 10) . ')';
            }
    }
    return null;
}

/**
 * 2026-10-09: the USPTO's "Automated Interview Request (AIR): Successfully forwarded request to Examiner for U.S. Application No.
 * 18911513 (Attorney Docket No. XYZ003)" opens "Examiner to grant interview" (two weeks back, each e-mail once). The requested date and
 * time come off the e-mail's snippet when Gmail is reachable. Cleared by hand (Done).
 */
function waiting_air_intake(): array {
    waiting_schema(); $out = []; $c = function_exists('gmail_cfg') ? gmail_cfg() : null;
    foreach (q('SELECT msg_id, subject, ts FROM gmail_seen WHERE ts >= datetime("now","-14 days") AND subject LIKE "%Automated Interview Request (AIR): Successfully forwarded%" ORDER BY ts') as $g) {
        if (q1('SELECT 1 FROM waiting WHERE src=?', [$g['msg_id']])) continue;
        $s = (string)$g['subject']; $m = null;
        if (preg_match('/Attorney Docket No\.?\s*([A-Z]{3}[\w.\-]*)\)/i', $s, $x)) $m = q1('SELECT * FROM matters WHERE ref=? ORDER BY dead, id DESC', [strtoupper($x[1])]);
        if (!$m && preg_match('/Application (?:No\.\s*)?(\d{8})/i', $s, $x)) { $ms = q('SELECT * FROM matters WHERE kind="patent" AND replace(replace(replace(app_no,"/",""),",","")," ","")=?', [$x[1]]); $m = count($ms) === 1 ? $ms[0] : null; }
        if (!$m) { $out[] = 'AIR not matched to a file: ' . $s; continue; }
        $when = '';
        if ($c) { try { $snip = (string)(gm_api('/users/me/messages/' . rawurlencode((string)$g['msg_id']), ['format' => 'metadata'], $c)['snippet'] ?? ''); if (preg_match('/ for (\d{1,2}-\d{1,2}-\d{4} \d{1,2}:\d{2} [AP]M ET)/', html_entity_decode($snip), $y)) $when = $y[1]; } catch (Throwable $e) {} }
        $id = waiting_add((int)$m['id'], 'interview', 'Interview request (AIR) forwarded to the examiner ' . substr((string)$g['ts'], 0, 10) . ($when !== '' ? "; requested for $when" : ''), null, '', (string)$g['ts']);
        run('UPDATE waiting SET src=? WHERE id=?', [$g['msg_id'], $id]); $out[] = "{$m['ref']} interview requested" . ($when !== '' ? " for $when" : '');
    }
    return $out;
}

/** Hourly: open rows from new AIR e-mails, then clear every row that has proof. Returns log lines. */
function waiting_sweep(): array {
    $out = waiting_air_intake();
    foreach (waiting_open() as $w) if ($p = waiting_proof($w)) { waiting_clear((int)$w['id'], 'auto', $p); $out[] = "{$w['ref']} " . WAIT_KINDS[$w['kind']] . ": $p"; }
    return $out;
}

/** After a signing email goes out (send_client): open the rows it starts. */
function waiting_after_sign_email(array $ev): void {
    $sl = sign_links_for((int)$ev['id']) ?: [];
    if ($ev['type'] === SIGN_EVENT) {
        foreach ($sl as $ref => $l) if ($mm = q1('SELECT id FROM matters WHERE ref=? AND kind="trademark" ORDER BY dead, id DESC', [$ref])) waiting_add((int)$mm['id'], 'sign_tm', 'signing link emailed ' . date('Y-m-d'));
    } elseif (defined('PAT_SIGN_EVENT') && $ev['type'] === PAT_SIGN_EVENT) {   // patent filing papers: cleared when the LAST link is signed (the last assignor, or the last form)
        $d = $sl ? reset($sl) : []; $ls = (array)($d['links'] ?? []); $last = $ls ? end($ls) : null;
        waiting_add((int)$ev['matter_id'], 'sign_doc', 'patent filing papers (' . count($ls) . ' signing links) emailed ' . date('Y-m-d'), null, $last ? (string)preg_replace('/^.*\?d=/', '', (string)$last['url']) : '');
    } elseif (defined('POSTREG_SIGN_EVENT') && $ev['type'] === POSTREG_SIGN_EVENT) {
        $l = $sl ? reset($sl) : [];
        waiting_add((int)$ev['matter_id'], 'sign_postreg', ucfirst(postreg_form_name((string)($l['form'] ?? ''))) . ' signing link emailed ' . date('Y-m-d') . (!empty($l['expires']) ? '; link expires ' . $l['expires'] : ''), (int)($l['deadline_id'] ?? 0) ?: null);
    } elseif ($ev['type'] === SOU_SIGN_EVENT) {
        $l = $sl ? reset($sl) : [];
        waiting_add((int)$ev['matter_id'], 'sign_sou', ucfirst(sou_form_name((string)($l['form'] ?? ''))) . ' signing link emailed ' . date('Y-m-d') . (!empty($l['expires']) ? '; link expires ' . $l['expires'] : ''), (int)($l['deadline_id'] ?? 0) ?: null);
    }
}

/** Dashboard panel: the list, Done buttons, and the add form. */
function waiting_panel(): string {
    $rows = waiting_open(); $back = url('dash', ['show' => 'wait']);
    $o = '<h2>Waiting on client</h2>';
    if ($rows) {
        $o .= '<table><thead><tr><th class="file">File</th><th>Waiting for</th><th>Note</th><th>Since</th><th>Deadline</th><th></th></tr></thead><tbody>';
        foreach ($rows as $w) {
            $dl = waiting_deadline($w); $txt = waiting_deadline_text($dl);
            $cls = !$dl ? 'later' : (($d = days_until((string)$dl['due_date'])) < 0 ? 'over' : ($d <= 3 ? 'd3' : ($d <= 14 ? 'd14' : ($d <= 60 ? 'd60' : 'later'))));
            $o .= '<tr><td class="file"><a href="' . url('matter', ['id' => $w['matter_id']]) . '"><b>' . h($w['ref']) . '</b></a></td><td>' . h(WAIT_KINDS[$w['kind']] ?? $w['kind']) . '<div class="muted">' . h(trunc((string)$w['title'], 40)) . '</div></td>'
                . '<td>' . h((string)$w['note']) . ($w['esign'] !== '' ? ' <span class="muted">(e-sign ' . h($w['esign']) . ')</span>' : '') . '</td><td>' . fmt_date(substr((string)$w['started_at'], 0, 10)) . '</td>'
                . '<td><span class="days ' . $cls . '"' . ($dl ? ' title="' . h($dl['type'] . ' ' . fmt_date((string)$dl['due_date'])) . '"' : '') . '>' . h($txt) . '</span></td>'
                . '<td>' . btn('wait_clear', ['id' => $w['id'], 'back' => $back], 'Done', 'small ghost') . '</td></tr>';
        }
        $o .= '</tbody></table>';
    } else $o .= '<p class="muted">Nothing waiting.</p>';
    $o .= '<form method="post" class="bar box">' . csrf_field() . '<input type="hidden" name="action" value="wait_add"><input type="hidden" name="back" value="' . h($back) . '">'
        . '<input type="text" name="ref" size="10" placeholder="file no." required style="text-transform:uppercase"> <select name="kind">';
    foreach (WAIT_KINDS as $k => $lab) $o .= '<option value="' . $k . '"' . ($k === 'other' ? ' selected' : '') . '>' . h($lab) . '</option>';
    return $o . '</select> <input type="text" name="note" size="44" placeholder="note"> <input type="text" name="esign" size="22" placeholder="e-sign link (optional)"> <button class="small gold">Add</button></form>';
}
