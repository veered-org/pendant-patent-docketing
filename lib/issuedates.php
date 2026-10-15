<?php
declare(strict_types=1);
/**
 * Exact issue dates (2026-10-02: "issue dates aren't necessarily after 3 weeks all the time - how can you verify"). The issue
 * notification from Patent Center states the issue date and patent number; the firm saves it in the matter folder as
 * "REF-NNx-Issue Notification.PDF" (image-only, so it is OCR'd). Until that file is there, the Expected Issue Date is only an estimate
 * (issue_tuesday) and says so; once it is there, the date on the paper replaces the estimate, after the application number on it is
 * checked against the matter. The grant notice on issue day closes the event as before.
 */
const ISSUE_VERIFIED = 'from the Issue Notification in the file';
function issue_schema(): void { static $d = false; if ($d) return; $d = true; db()->exec("CREATE TABLE IF NOT EXISTS issue_ntf_seen (path TEXT PRIMARY KEY, result TEXT, at TEXT DEFAULT (datetime('now')))"); }
/** Values row of an issue notification (OCR text): ['app' => digits, 'issue' => ISO date, 'patent' => 'D1151804' / '12735892'] or null. */
function parse_issue_notification(string $t): ?array {
    // "30/015,423 10/06/2026 D1151804 CNJO17 8181" under "APPLICATION NO. ISSUE DATE PATENT NO. ATTORNEY DOCKET NO. ..."
    if (!preg_match('/(\d{2}\s*\/\s*\d{3}\s*,\s*\d{3})\s+(\d{2}\/\d{2}\/\d{4})\s+((?:D|RE|PP)?\s?[\d,]{5,9})\b/i', $t, $m)) return null;
    $d = DateTimeImmutable::createFromFormat('!m/d/Y', $m[2]); if (!$d) return null;
    return ['app' => preg_replace('/\D/', '', $m[1]), 'issue' => $d->format('Y-m-d'), 'patent' => strtoupper(preg_replace('/[\s,]/', '', $m[3]))];
}
/** Check every open, not yet verified Expected Issue Date against an Issue Notification saved in its folder. Returns lines for the log. */
function verify_issue_dates(): array {
    issue_schema(); $out = [];
    // Already verified but no issuance report yet (XYZ017 / XYZ001 on 2026-10-02: verified, report never queued): queue it from the note.
    foreach (q("SELECT e.*, m.ref FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.type='Expected Issue Date' AND e.status IN ('open','done') AND m.kind='patent' AND e.note LIKE ? AND NOT EXISTS (SELECT 1 FROM events r WHERE r.matter_id=e.matter_id AND r.type IN (?,?))", ['%' . ISSUE_VERIFIED . '%', ISSUE_REPORT_EVENTS[0], ISSUE_REPORT_EVENTS[1]]) as $e) {
        if (!preg_match('/patent no\. ([A-Z]*\d+) ' . preg_quote(ISSUE_VERIFIED, '/') . ' \(([^)]*)\)/', (string)$e['note'], $pm)) continue;
        $mm = q1('SELECT * FROM matters WHERE id=?', [$e['matter_id']]); if (!$mm) continue;
        try { $rid = queue_issue_report($mm, (string)$e['due_date'], $pm[1], $pm[2]); } catch (Throwable $x) { $out[] = "$e[ref]: issuance report NOT queued: " . $x->getMessage(); continue; }
        $out[] = "$e[ref]: issuance report to the client " . ($rid ? 'queued' : 'not queued (non-US or already reported)');
    }
    foreach (q("SELECT e.*, m.ref, m.app_no FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.type='Expected Issue Date' AND e.status='open' AND m.kind='patent' AND COALESCE(e.note,'') NOT LIKE ?", ['%' . ISSUE_VERIFIED . '%']) as $e) {
        $dir = mf_find((string)$e['ref']); if (!$dir) continue;
        foreach (mf_list($dir) as $f) {
            if (!preg_match('/issue\s*notif/i', $f['name']) || !preg_match('/\.pdf$/i', $f['name'])) continue;
            $key = $dir['name'] . '/' . $f['rel'] . '@' . $f['mtime'];
            if (q1('SELECT 1 FROM issue_ntf_seen WHERE path=?', [$key])) continue;
            $path = mf_local($dir, $f['rel']); $p = $path !== '' ? parse_issue_notification(doc_text($path, 1)) : null;
            if (!$p) { run('INSERT OR REPLACE INTO issue_ntf_seen(path,result) VALUES (?,?)', [$key, 'unreadable']); $out[] = "$e[ref]: $f[name] could not be read; the estimate stays"; continue; }
            if ($p['app'] !== preg_replace('/\D/', '', (string)$e['app_no'])) { run('INSERT OR REPLACE INTO issue_ntf_seen(path,result) VALUES (?,?)', [$key, 'other application ' . $p['app']]); $out[] = "$e[ref]: $f[name] is for application $p[app], not $e[app_no]; ignored"; continue; }
            $why = 'Issue date ' . fmt_date($p['issue']) . ', patent no. ' . $p['patent'] . ' ' . ISSUE_VERIFIED . ' (' . $f['name'] . ')';
            run('UPDATE events SET due_date=?, note=note_add(note, ?) WHERE id=?', [$p['issue'], $why, $e['id']]);
            run('INSERT OR REPLACE INTO issue_ntf_seen(path,result) VALUES (?,?)', [$key, $p['issue'] . ' ' . $p['patent']]);
            audit('issue_date', "$e[ref] Expected Issue Date " . ($p['issue'] === $e['due_date'] ? "$p[issue] confirmed" : "$e[due_date] -> $p[issue]") . " " . ISSUE_VERIFIED, (int)$e['matter_id']);
            $out[] = "$e[ref]: issue date " . ($p['issue'] === $e['due_date'] ? 'confirmed ' : "$e[due_date] -> ") . "$p[issue], patent no. $p[patent]";
            $mm = q1('SELECT * FROM matters WHERE id=?', [$e['matter_id']]);
            try { if ($mm && queue_issue_report($mm, $p['issue'], $p['patent'], $f['name'])) $out[] = "$e[ref]: issuance report to the client queued"; else $out[] = "$e[ref]: issuance report not queued (non-US or already reported)"; }
            catch (Throwable $x) { $out[] = "$e[ref]: issuance report NOT queued: " . $x->getMessage(); }
            break;
        }
    }
    return $out;
}
/**
 * The issuance report to the client (2026-10-02), queued once the Issue Notification has been read: his XYZ049 wording with the
 * patent number and issue date from the paper and the maintenance-fee windows / due dates (utility), or the design-patent version.
 * The Issue Notification PDF is pre-ticked as the attachment; sending marks the to-do done.
 */
const ISSUE_REPORT_EVENTS = ['Report Issue Notification', 'Report Issue Notification (Design)'];
function is_issue_report(string $type): bool { return in_array($type, ISSUE_REPORT_EVENTS, true); }
/** "12,716,992" / "D1,151,804" / "RE50,123". */
function fmt_patent_no(string $n): string { $n = strtoupper(preg_replace('/[\s,]/', '', $n)); return preg_match('/^([A-Z]*)(\d+)$/', $n, $m) ? $m[1] . number_format((int)$m[2]) : $n; }
/** The three maintenance-fee lines, dated from the issue date exactly as the grant rule dockets them. */
function maint_schedule(string $issue): string {
    $l = [];
    foreach ([3 => '3 1/2', 7 => '7 1/2', 11 => '11 1/2'] as $y => $lbl) $l[] = "$lbl year maintenance fee — window opens " . fmt_long(add_months($issue, $y * 12, false)) . '; due ' . fmt_long(add_months($issue, $y * 12 + 6));
    return implode("\n", $l);
}
/** XYZ001's table: "Docketing Event / Due Date" rows, the expected issue date first, then each cycle's window, due date and grace period. */
function maint_table(string $issue, int $cycles = 1): string {   // 2026-10-04 (XYZ004): list only through the 3 1/2-year grace period
    $r = ['<b>Docketing Event — Due Date</b>', 'Expected Issue Date — ' . fmt_long($issue)];
    foreach (array_slice([3 => '3 1/2', 7 => '7 1/2', 11 => '11 1/2'], 0, max(1, $cycles), true) as $y => $lbl) {
        $r[] = "$lbl Year Maintenance Fee Window Open — " . fmt_long(add_months($issue, $y * 12, false));
        $r[] = "$lbl Year Maintenance Fee Due — " . fmt_long(add_months($issue, $y * 12 + 6));
        $r[] = "$lbl Year Maintenance Fee Grace Period — " . fmt_long(add_months($issue, $y * 12 + 12));
    }
    return implode("\n", $r);
}
function queue_issue_report(array $m, string $issue, string $patentNo, string $file): ?int {
    if (!preg_match('/^(|United States|US|USA)$/i', trim((string)($m['country'] ?? '')))) return null;
    $type = is_design_matter($m) ? ISSUE_REPORT_EVENTS[1] : ISSUE_REPORT_EVENTS[0];
    run('UPDATE matters SET issue_patent_no=? WHERE id=?', [$patentNo, $m['id']]);
    // 2026-10-04: the to-do is queued when the notice arrives (queue_issue_todo); reading the PDF fills that one in, never a second
    if ($ex = q1('SELECT * FROM events WHERE matter_id=? AND type IN (?,?) AND status<>"cancelled" ORDER BY id DESC LIMIT 1', [$m['id'], ISSUE_REPORT_EVENTS[0], ISSUE_REPORT_EVENTS[1]])) {
        if (!in_array($ex['status'], ['open', 'proposed'], true) || str_contains((string)$ex['note'], 'U.S. Patent ')) return null;
        run('UPDATE events SET type=?, note=note_add(note, ?) WHERE id=?', [$type, 'Email the client: U.S. Patent ' . fmt_patent_no($patentNo) . ' issues ' . fmt_date($issue) . ' (' . $file . ')', $ex['id']]);
        audit('issue_report', $m['ref'] . ' issuance report ready: U.S. Patent ' . fmt_patent_no($patentNo) . ' on ' . fmt_date($issue), (int)$m['id']);
        return (int)$ex['id'];
    }
    run('INSERT INTO events(matter_id,type,due_date,status,source,doc_code,mail_date,note) VALUES (?,?,?,"open","rule","ISSUE.NTF",?,?)',
        [$m['id'], $type, today(), $issue, 'Email the client: U.S. Patent ' . fmt_patent_no($patentNo) . ' issues ' . fmt_date($issue) . ' (' . $file . ')']);
    $id = (int)db()->lastInsertId(); audit('issue_report', $m['ref'] . ' issuance report queued: U.S. Patent ' . fmt_patent_no($patentNo) . ' on ' . fmt_date($issue), (int)$m['id']);
    return $id;
}

/** Patent number from the Issue Notification saved in the matter folder (2026-10-04, XYZ004: the email must carry it even before the hourly parse). '' if none. */
function issue_ntf_patent_no(string $ref): string { return (string)(issue_ntf_read($ref)['patent'] ?? ''); }
/** ['app','issue','patent'] read off the newest Issue Notification PDF in the matter folder (cached per request); null if none or unreadable. */
function issue_ntf_read(string $ref): ?array {
    static $memo = []; if (array_key_exists($ref, $memo)) return $memo[$ref];
    if (!function_exists('mf_find') || !function_exists('find_matter_dir') || !function_exists('parse_issue_notification') || !($dir = mf_find($ref))) return $memo[$ref] = null;
    $fs = array_filter(mf_list($dir), fn($f) => preg_match('/issue\s*notif/i', $f['name']) && preg_match('/\.pdf$/i', $f['name'])); usort($fs, fn($a, $b) => ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0));
    foreach ($fs as $f) if (($path = mf_local($dir, $f['rel'])) !== '' && ($p = parse_issue_notification(doc_text($path, 1)))) return $memo[$ref] = $p;
    return $memo[$ref] = null;
}

/** The renewal table as email HTML (2026-10-04): navy header, gold rule, one row per date, first cycle only. One line: mail_body_html turns newlines into <br>. */
function maint_table_html(string $issue): string {
    $rows = [['Expected issue date', $issue], ['3½-year maintenance fee: window opens', add_months($issue, 36, false)], ['3½-year maintenance fee: due', add_months($issue, 42)], ['3½-year maintenance fee: last day (grace period, with surcharge)', add_months($issue, 48)]];
    $td = 'padding:7px 12px;border-bottom:1px solid #e8e3d6;font-size:13px';
    $o = '<table data-maint="' . str_replace('-', '', $issue) . '" contenteditable="false" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:6px 0 4px;border-left:3px solid #c9a227;min-width:360px;max-width:560px">'
       . '<tr><th style="' . $td . ';background:' . NAVY . ';color:#fff;text-align:left;font-weight:600">Docketed event</th><th style="' . $td . ';background:' . NAVY . ';color:#fff;text-align:left;font-weight:600">Date</th></tr>';
    foreach ($rows as $i => [$l, $d]) $o .= '<tr style="background:' . ($i % 2 ? '#faf8f2' : '#ffffff') . '"><td style="' . $td . ';color:#333">' . $l . '</td><td style="' . $td . ';white-space:nowrap;color:' . NAVY . ';font-weight:600">' . fmt_long($d) . '</td></tr>';
    return $o . '</table>';
}
