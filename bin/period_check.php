<?php
/**
 * SERVER job (hourly, after oa_fetch; was a laptop job until 2026-10-01). 2026-09-30: notices whose reply period varies
 * (SEQ.REQ.DISC, NT.INC.REPLY, petition decisions, unknown codes ...) are docketed at 2 months with PERIOD_UNVERIFIED in the note. Once the
 * paper is in the matter folder (saved by oa_fetch, or by hand from Patent Center), read pages 1-3 (text layer; OCR when it is a scan),
 * find the stated period ("set to expire 2 MONTH(S)", "is given TWO MONTHS", "ONE MONTH or THIRTY DAYS, whichever is longer") and re-date
 * the event. The tag is replaced either way, so a paper is read once.   php bin/period_check.php [--dry]
 */
declare(strict_types=1);
$GLOBALS['cfg'] = $cfg = require dirname(__DIR__) . '/config.php';
foreach (['db', 'dates', 'rules', 'reminders', 'oafetch', 'dropbox', 'folders'] as $f) require_once dirname(__DIR__) . "/lib/$f.php";
$dry = in_array('--dry', $argv, true);
if (folders_mode() === 'none') exit("No matter folders: Dropbox is not connected (Advanced > Dropbox); nothing to do\n");
$rows = q('SELECT e.id, e.matter_id, e.type, e.due_date, e.mail_date, e.doc_code, m.ref, m.kind FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.status="open" AND e.note LIKE ?', ['%' . PERIOD_UNVERIFIED . '%']);
echo count($rows) . " event(s) awaiting their reply period\n";
foreach ($rows as $r) {
    $dir = mf_find($r['ref']); $d = str_replace('-', '.', (string)$r['mail_date']);
    if (!$dir || !$r['mail_date']) { echo "  {$r['ref']} {$r['doc_code']}: no folder / no mail date\n"; continue; }
    // the paper itself: a file dated with the mail date (the firm's "... of 2026.04.07" naming), excluding receipts and our own papers
    $cands = array_values(array_filter(mf_names($dir), fn($n) => preg_match('/\.(pdf|docx)$/i', $n) && str_contains($n, $d) && !preg_match('/receipt|response|reply to|amendment|892|references|IDS/i', $n)));
    if (!$cands) { echo "  {$r['ref']} {$r['doc_code']} {$r['mail_date']}: paper not in the folder yet\n"; continue; }
    usort($cands, fn($a, $b) => (int)preg_match('/\.pdf$/i', $b) <=> (int)preg_match('/\.pdf$/i', $a));   // PDF first
    $per = null; $src = '';
    foreach ($cands as $n) { $p = mf_local($dir, $n); if ($p !== '' && ($per = parse_reply_period(doc_text($p)))) { $src = $n; break; } }
    if (!$per) { $upd = [null, 'no reply period found in ' . $cands[0] . '; 2 months kept - check the paper']; echo "  {$r['ref']} {$r['doc_code']}: no period stated; 2 months kept\n"; }
    else {
        $roll = ($r['kind'] ?? '') !== 'trademark';   // the firm's convention: patent dates roll off weekends/holidays, trademark dates stay put
        $due = isset($per['months']) ? add_months($r['mail_date'], $per['months'], $roll) : add_days($r['mail_date'], $per['days'], $roll);
        $what = isset($per['months']) ? $per['months'] . ' month' . ($per['months'] > 1 ? 's' : '') : $per['days'] . ' days';
        $upd = [$due !== $r['due_date'] ? $due : null, "reply period $what read from $src"];
        echo "  {$r['ref']} {$r['doc_code']} {$r['mail_date']}: $what -> due $due" . ($due !== $r['due_date'] ? " (was {$r['due_date']})" : ' (unchanged)') . "\n";
    }
    if ($dry) continue;
    [$due, $msg] = $upd; $e = q1('SELECT * FROM events WHERE id=?', [$r['id']]); if (!$e) continue;
    run('UPDATE events SET note=replace(note, ?, ?) WHERE id=?', [PERIOD_UNVERIFIED, '[' . $msg . ']', $r['id']]);
    if ($due) {
        run('UPDATE events SET due_date=? WHERE id=?', [$due, $r['id']]); run('DELETE FROM reminders WHERE event_id=? AND status IN ("pending","held","skipped")', [$r['id']]);
    }
    run('INSERT INTO audit(user,action,detail,matter_id) VALUES (?,?,?,?)', ['period_check', 'period', $e['type'] . ': ' . $msg . ($due ? ', due ' . $due : ''), $e['matter_id']]);
}
