<?php
/**
 * SERVER job (hourly; was a laptop job until 2026-10-01). 2026-09-28: fill in missing filing dates from the USPTO acknowledgement
 * receipt in the matter folder.   php bin/filing_dates.php [--dry] [--only=REF]
 * For every live US patent matter with an application number but no filing date, reads the filing receipts (REF-NNx-AckReceipt-Filing.pdf
 * and variants) in /matters/<REF>/, keeps the ones whose application number matches the matter, and takes the earliest receipt date. It sets
 * filing_date (and app_type when blank), logs where the date came from, and adds the 12-month deadline (ensure_priority_deadline): always for
 * a provisional; for a non-provisional only when the ADS in the folder shows no benefit and no foreign priority claims. Designs get 6 months
 * (Paris / Hague), on the same ADS condition.
 */
declare(strict_types=1);
$GLOBALS['cfg'] = $cfg = require dirname(__DIR__) . '/config.php';
foreach (['db', 'dates', 'rules', 'oafetch', 'dropbox', 'folders'] as $f) require_once dirname(__DIR__) . "/lib/$f.php";
$opt = getopt('', ['dry', 'only::']); $dry = isset($opt['dry']); $only = $opt['only'] ?? null;
if (folders_mode() === 'none') exit("No matter folders: Dropbox is not connected (Advanced > Dropbox); nothing to do\n");
$rows = q("SELECT id, ref, app_no, app_type FROM matters WHERE kind='patent' AND dead=0 AND country LIKE 'United States%' AND app_no<>'' AND (filing_date IS NULL OR filing_date='')");
if ($only) $rows = array_values(array_filter($rows, fn($r) => strcasecmp($r['ref'], $only) === 0));
$found = 0; $seen = 0;
foreach ($rows as $r) {
    $dir = mf_find($r['ref']); if (!$dir) continue;
    $names = mf_names($dir); $best = null;
    foreach ($names as $name) {
        if (!is_filing_receipt_name($name)) continue; $seen++;
        $path = mf_local($dir, $name); if ($path === '') continue;
        $p = parse_ack_receipt(pdf_text($path, 1, 1));
        if (!$p || $p['app_no'] !== preg_replace('/\D/', '', $r['app_no']) || in_array($p['type'], ['PCT', 'National Stage'], true)) continue;   // another application's receipt, or a 371 date (not the priority date)
        if (!$best || $p['date'] < $best['date']) $best = $p + ['file' => $name];
    }
    if (!$best) continue;
    // A non-provisional's foreign deadline runs from its OWN date only if it claims no earlier priority: every ADS in the folder must say so.
    $noPri = null;
    if (in_array($best['type'], ['Utility', 'Design'], true)) foreach ($names as $name) {
        if (!preg_match('/\bADS\b|application data sheet/i', $name) || !preg_match('/\.pdf$/i', $name)) continue;
        $path = mf_local($dir, $name); if ($path === '') continue;
        $v = ads_claims_no_priority(pdf_text($path));
        if ($v === false) { $noPri = false; break; } if ($v === true) $noPri = true;
    }
    $found++;
    echo "  {$r['ref']} {$r['app_no']}: filed {$best['date']} ({$best['type']}) from {$best['file']}" . (in_array($best['type'], ['Utility', 'Design'], true) ? '; ADS: ' . ($noPri === null ? 'no priority info' : ($noPri ? 'no priority claimed' : 'claims priority')) : '') . "\n";
    if ($dry) continue;
    $m = q1('SELECT ref, filing_date FROM matters WHERE id=?', [$r['id']]); if (!$m || (string)$m['filing_date'] !== '') continue;
    run('UPDATE matters SET filing_date=?, app_type=CASE WHEN app_type="" OR app_type IS NULL THEN ? ELSE app_type END, updated_at=datetime("now") WHERE id=?', [$best['date'], $best['type'], $r['id']]);
    run('INSERT INTO audit(user,action,detail,matter_id) VALUES (?,?,?,?)', ['filing_dates', 'filing_date', "Filing date {$best['date']} from {$best['file']}", $r['id']]);
    $add = ensure_priority_deadline((int)$r['id'], $noPri === true || ($best['type'] === 'Design' && $noPri !== false));   // designs rarely claim priority: only an ADS that says so stops them
    if ($add) echo "      added $add\n";
}
// 2026-10-06 (RWG166.001.01): a matter made from USPTO mail ("(new from USPTO mail)") or with no title takes the title of invention
// off the acknowledgement receipt in its folder. A typed title is never replaced.
$tn = 0;
foreach (q("SELECT id, ref, app_no, title FROM matters WHERE kind='patent' AND dead=0 AND country LIKE 'United States%' AND app_no<>'' AND (trim(title) IN ('', '(new from USPTO mail)') OR upper(trim(title)) IN ('NO TITLE IMPORTED','NO TITLE SPECIFIED'))") as $r) {
    if ($only && strcasecmp($r['ref'], $only) !== 0) continue;
    $dir = mf_find($r['ref']); if (!$dir) continue;
    foreach (mf_names($dir) as $name) {
        if (!is_filing_receipt_name($name)) continue;
        $path = mf_local($dir, $name); if ($path === '') continue;
        $p = parse_ack_receipt(pdf_text($path, 1, 1));
        if (!$p || $p['app_no'] !== preg_replace('/\D/', '', $r['app_no']) || ($p['title'] ?? '') === '') continue;
        echo "  {$r['ref']}: title {$p['title']} from $name\n"; $tn++;
        if (!$dry) { run('UPDATE matters SET title=?, updated_at=datetime("now") WHERE id=? AND title=?', [$p['title'], $r['id'], $r['title']]); run('INSERT INTO audit(user,action,detail,matter_id) VALUES (?,?,?,?)', ['filing_dates', 'data_fill', "title filled from $name: {$p['title']}", $r['id']]); }
        break;
    }
}
if ($tn) echo "$tn title(s) filled from receipts\n";
echo "$found filing date(s) found in $seen receipt(s) for " . count($rows) . " matter(s) without one" . ($dry ? ' (dry run)' : '') . "\n";
