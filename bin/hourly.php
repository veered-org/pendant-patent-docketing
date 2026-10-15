<?php
/**
 * SERVER job, every hour (cron line docket-hourly). 2026-10-01: everything that ran on the laptop now runs here, so nothing stops
 * when the laptop is off. In order:
 *   1. Dropbox index      bring dbx_files up to date (the first run lists both roots; it resumes next hour if it runs out of time)
 *   2. receipts           new receipts in the matter folders -> deadlines proposed done (Confirm queue, "Receipts in the file")
 *   3. gmail              new USPTO e-mail (bin/gmail_hourly.php; was nightly only until 2026-10-03)
 *   3b. oa_fetch          save new Office Actions + references, and filing / fee receipts, into the matter folders (Dropbox upload)
 *   4. filing_dates       missing filing dates from the acknowledgement receipts
 *   5. period_check       reply periods read off notices docketed at a provisional 2 months
 *   6. pct_deadlines      30-month national-phase deadlines for <REF>.PCT folders
 *   7. pub_reports        notices of publication: queue the client email, read the filing basis from TSDR, close any already sent by hand (lib/pubreport.php)
 *   8. reg_reports        trademark registrations: queue the client email, get the certificate saved from TSDR, close any already sent by hand (lib/regreport.php)
 * Each step runs as its own process (one failing does not stop the rest), at low priority, under a lock so two hours never overlap.
 *   php bin/hourly.php [--only=step]
 */
declare(strict_types=1);
$GLOBALS['cfg'] = $cfg = require dirname(__DIR__) . '/config.php';
foreach (['db', 'dates', 'rules', 'reminders', 'dropbox', 'folders', 'receipts', 'issuedates', 'oafetch', 'issuance', 'uspto', 'gmail_pull', 'render', 'signreq', 'waiting'] as $f) require_once dirname(__DIR__) . "/lib/$f.php";
date_default_timezone_set('America/New_York');
$only = getopt('', ['only::'])['only'] ?? null;
$lock = fopen(dirname($cfg['db']) . '/hourly.lock', 'c'); if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("another hourly run is still going\n");
@proc_nice(15);
$log = function (string $s) { echo '[' . date('H:i:s') . "] $s\n"; };
$step = fn(string $n) => $only === null || $only === $n;
if ($step('waiting')) { try { $l = waiting_sweep(); $log('waiting: ' . (count($l) ? count($l) . ' change(s)' : 'nothing new')); foreach ($l as $x) $log("  $x"); } catch (Throwable $e) { $log('waiting FAILED: ' . $e->getMessage()); } }   // 2026-10-09: Waiting on client clears itself on proof (lib/waiting.php)
if (!dbx_ready()) { $log('Dropbox is not connected (Advanced > Dropbox): skipping the folder jobs'); run('INSERT OR REPLACE INTO settings(key,value) VALUES ("hourly_last", ?)', [date('Y-m-d H:i:s') . ' (no Dropbox)']); exit(0); }
if ($step('sync')) { $s = dbx_sync(1500); $log("dropbox: {$s['changes']} change(s)" . ($s['complete'] ? '' : ' (first listing not finished; continues next hour)') . ($s['error'] ? " ERROR {$s['error']}" : '')); if (!$s['complete']) { run('INSERT OR REPLACE INTO settings(key,value) VALUES ("hourly_last", ?)', [date('Y-m-d H:i:s') . ' (indexing)']); exit(0); } }
if ($step('receipts')) { $r = receipts_scan(); $log("receipts: {$r['seen']} new file(s), " . count($r['proposed']) . ' deadline(s) proposed done'); foreach ($r['proposed'] as [$ref, $type, $due, $name]) $log("  $ref $type ($due) <- $name"); }
if ($step('issuance')) { $l = run_issuance(); $log('issuance: ' . (count($l) ? count($l) . ' patent(s) prepared' : 'nothing new')); foreach ($l as $x) $log("  $x"); }   // 2026-10-02: eGrant + letter on issue day
if ($step('issue_dates')) { $l = verify_issue_dates(); $log('issue_dates: ' . (count($l) ? count($l) . ' issue notification(s) read' : 'nothing new')); foreach ($l as $x) $log("  $x"); }   // 2026-10-02
foreach (['gmail' => 'gmail_hourly.php', 'oa_fetch' => 'oa_fetch.php --days=14', 'filing_dates' => 'filing_dates.php', 'period_check' => 'period_check.php', 'pct_deadlines' => 'pct_deadlines.php --apply'] as $n => $cmd) {
    if (!$step($n)) continue;
    $out = []; exec('nice -n 15 ' . escapeshellarg(PHP_BINARY) . ' ' . __DIR__ . '/' . $cmd . ' 2>&1', $out, $rc);
    $log("$n" . ($rc ? " FAILED (exit $rc)" : '') . ': ' . (trim((string)end($out)) ?: 'done')); if ($rc || count($out) > 1) foreach (array_slice($out, 0, 40) as $l) echo "    $l\n";
}
if ($step('reg_reports')) { try { $l = array_merge(reg_sweep(true), pat_sweep(true));   // + issued patents (lib/patreport.php)
    $log('reg_reports: ' . (count($l) ? count($l) . ' change(s)' : 'nothing new')); foreach ($l as $x) $log("  $x"); } catch (Throwable $e) { $log('reg_reports FAILED: ' . $e->getMessage()); } }   // 2026-10-04
if ($step('pub_reports')) { try { $l = pub_sweep(true); $log('pub_reports: ' . (count($l) ? count($l) . ' change(s)' : 'nothing new')); foreach ($l as $x) $log("  $x"); } catch (Throwable $e) { $log('pub_reports FAILED: ' . $e->getMessage()); } }   // 2026-10-04
$p = mf_cache_prune(); if ($p) $log("cache: $p old download(s) removed");
run('INSERT OR REPLACE INTO settings(key,value) VALUES ("hourly_last", ?)', [date('Y-m-d H:i:s')]);
