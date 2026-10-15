<?php
/**
 * SERVER job (hourly, with oa_fetch; was a laptop job until 2026-10-01). 2026-09-30: every live PCT application gets its
 * "PCT National Phase 30 Mo. Deadline" (30 months from the earliest priority date; nothing else is docketed for PCT). For each /matters/<REF>.PCT folder:
 *   PCT filing date   the Patent Center acknowledgement receipt ("PCT/US25/28813   05/12/2025 ...").
 *   priority date     Box VI item (1) of the PCT/RO/101 request in the folder; else the filing date of the base US matter (<REF>) when it
 *                     falls in the 12 months (+2 restoration) before the PCT; else the PCT filing date itself (no priority claim).
 * A date is accepted only inside that window, which also settles day/month order. Deadlines already past are skipped. The <REF>.PCT matter
 * is created when missing; the event is added only when no 30-month / national-phase event exists for it.
 *   php bin/pct_deadlines.php [--apply]
 */
declare(strict_types=1);
$GLOBALS['cfg'] = $cfg = require dirname(__DIR__) . '/config.php';
foreach (['db', 'dates', 'oafetch', 'dropbox', 'folders'] as $f) require_once dirname(__DIR__) . "/lib/$f.php";
$apply = in_array('--apply', $argv, true);
if (folders_mode() === 'none') exit("No matter folders: Dropbox is not connected (Advanced > Dropbox); nothing to do\n");
/** Dates in a text snippet, both day/month readings for numeric ones: list of ISO strings. */
function date_candidates(string $s): array {
    $out = [];
    if (preg_match_all('/\b(\d{1,2}) ([A-Z][a-z]+)\.? (\d{4})\b|\b([A-Z][a-z]+)\.? (\d{1,2}),? (\d{4})\b/', $s, $mm, PREG_SET_ORDER))
        foreach ($mm as $m) { $ts = strtotime($m[1] !== '' ? "$m[1] $m[2] $m[3]" : "$m[5] $m[4] $m[6]"); if ($ts) $out[] = date('Y-m-d', $ts); }
    if (preg_match_all('/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})\b/', $s, $mm, PREG_SET_ORDER))
        foreach ($mm as $m) foreach ([[$m[1], $m[2]], [$m[2], $m[1]]] as [$mo, $d]) if (checkdate((int)$mo, (int)$d, (int)$m[3])) $out[] = sprintf('%04d-%02d-%02d', $m[3], $mo, $d);
    return array_values(array_unique($out));
}
$plan = [];
foreach (array_filter(mf_all(), fn($n) => preg_match('/^[A-Z]{3,4}\d{3}(\.\d+)?\.PCT$/i', $n)) as $ref) {
    $dir = mf_find($ref); if (!$dir) continue;
    $have = q1('SELECT 1 FROM matters m JOIN events e ON e.matter_id=m.id WHERE m.kind="patent" AND lower(m.ref)=lower(?) AND (e.type LIKE "%30 Mo%" OR e.type LIKE "National Phase%") AND e.status IN ("open","proposed","done")', [$ref]);
    if ($have) continue;   // already docketed: no need to read its papers again
    $base = preg_replace('/\.PCT$/i', '', $ref); $files = mf_names($dir);
    // Cheap first: a folder whose receipts are all older than 30 months cannot produce a live deadline.
    $pctNo = null; $filed = null;
    foreach ($files as $f) if (preg_match('/ack.?receipt/i', $f) && preg_match('/\.pdf$/i', $f) && !preg_match('/fee|IDS|change|POA|response|amend/i', $f)) {
        $p = mf_local($dir, $f); if ($p === '') continue;
        if (preg_match('#(PCT/US\d{2,4}/\d{5,6})\s+(\d{2})/(\d{2})/(\d{4})#', pdf_text($p, 1, 1), $m)) { $pctNo = $m[1]; $filed = "$m[4]-$m[2]-$m[3]"; break; }
    }
    if (!$filed) continue;   // older EFS-Web receipts: those cases are long past 30 months
    $lo = add_months($filed, -14, false); $ok = fn(string $d) => $d >= $lo && $d <= $filed;
    $prio = null; $src = '';
    foreach ($files as $f) if (preg_match('/RO.?101|RequestForm/i', $f) && preg_match('/\.pdf$/i', $f)) {
        $p = mf_local($dir, $f); if ($p === '') continue; $t = pdf_text($p);
        if (($i = strpos($t, 'item (1)')) !== false) foreach (date_candidates(substr($t, $i, 400)) as $d) if ($ok($d)) { $prio = $d; $src = "Box VI of $f"; break 2; }
    }
    $bd = q1('SELECT filing_date FROM matters WHERE kind="patent" AND ref=? AND filing_date IS NOT NULL', [$base])['filing_date'] ?? '';
    if (!$prio && $bd !== '' && $ok(substr($bd, 0, 10)) && substr($bd, 0, 10) < $filed) { $prio = substr($bd, 0, 10); $src = "filing date of $base"; }
    if (!$prio) { $prio = $filed; $src = 'PCT filing date (no priority claim found)'; }
    $due = add_months($prio, 30, false);
    echo "$ref $pctNo filed $filed, priority $prio ($src) -> 30 months $due" . ($due < today() ? ' (past, skipped)' : '') . "\n";
    if ($due >= today()) $plan[] = ['ref' => $ref, 'pct' => preg_replace('/\D/', '', $pctNo), 'pct_fmt' => $pctNo, 'filed' => $filed, 'prio' => $prio, 'src' => $src, 'due' => $due];
}
if (!$plan) exit("nothing to docket\n");
foreach ($plan as $p) {
    $m = q1('SELECT * FROM matters WHERE kind="patent" AND lower(ref)=lower(?) ORDER BY id DESC', [$p['ref']]);
    if ($m && q1('SELECT 1 FROM events WHERE matter_id=? AND (type LIKE "%30 Mo%" OR type LIKE "National Phase%") AND status IN ("open","proposed","done")', [$m['id']])) { echo "  {$p['ref']}: already docketed\n"; continue; }
    echo '  ' . $p['ref'] . ': ' . ($m ? '' : 'new matter + ') . 'PCT National Phase 30 Mo. Deadline ' . $p['due'] . "\n";
    if (!$apply) continue;
    if (!$m) {
        $cc = preg_match('/^([A-Z]{3,4})\d/', $p['ref'], $x) ? $x[1] : '';
        run('INSERT INTO matters(kind,ref,client_code,title,country,app_no,filing_date,app_type,notes) VALUES ("patent",?,?,?,"PCT",?,?,"PCT",?)', [$p['ref'], $cc, '(PCT application ' . $p['pct_fmt'] . ')', $p['pct'], $p['filed'], 'Created ' . today() . ' from the Dropbox folder for the 30-month deadline']);
        $m = q1('SELECT * FROM matters WHERE id=last_insert_rowid()'); if ($cc !== '') run('INSERT OR IGNORE INTO clients(code) VALUES (?)', [$cc]);
    }
    run('INSERT INTO events(matter_id,type,due_date,status,source,note) VALUES (?,?,?,"open","rule",?)', [$m['id'], 'PCT National Phase 30 Mo. Deadline', $p['due'], '30 months from priority ' . $p['prio'] . ' (' . $p['src'] . '); PCT filed ' . $p['filed']]);
    run('INSERT INTO audit(user,action,detail,matter_id) VALUES (?,?,?,?)', ['pct_deadlines', 'add_event', 'PCT National Phase 30 Mo. Deadline ' . $p['due'] . ' (priority ' . $p['prio'] . ')', $m['id']]);
}
echo $apply ? "applied\n" : "dry run; add --apply\n";
