<?php
// Nightly job: USPTO mail intake + USPTO sweep (propose only) + reminder queue + morning report.
// Usage: php bin/nightly.php [--limit=N] [--email=addr] [--base=URL] [--horizon] [--smoke]
//   --smoke  = the deploy guard's dry run: no backup, no sweep lookups, no e-mail; ANY PHP warning or a failed Gmail pull is fatal.
//              Run it against a scratch database (DOCKET_DB) and a stubbed Gmail (DOCKET_GMAIL_CONFIG): see tests/intake.php.
// A run that dies sends a one-line alarm to --email (2026-10-01: a statement swallowed by a comment killed the job silently for a day).
declare(strict_types=1);
$GLOBALS['cfg'] = $cfg = require dirname(__DIR__) . '/config.php';
foreach (['db', 'auth', 'dates', 'rules', 'mail', 'reminders', 'uspto', 'gmail_pull', 'held', 'send', 'phones', 'alarm', 'dropbox', 'folders', 'oafetch', 'mailactivity', 'issuedates', 'annuities', 'feepaid', 'govfees'] as $f) require dirname(__DIR__) . "/lib/$f.php";
$opt = getopt('', ['limit::', 'email::', 'base::', 'horizon', 'smoke']); $_SESSION = ['dk_user' => 'nightly'];
$smoke = isset($opt['smoke']); $alarmTo = (string)($opt['email'] ?? '');
// Warnings are kept (undefined variables, bad array keys ...): they go on the morning report, and in a smoke run the first one is fatal.
$warnings = [];
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$warnings, $smoke) {
    if (!(error_reporting() & $no)) return false;   // silenced with @
    $w = $msg . ' (' . basename($file) . ':' . $line . ')';
    if ($smoke) throw new ErrorException($w, 0, $no, $file, $line);
    if (count($warnings) < 25 && !in_array($w, $warnings, true)) $warnings[] = $w;
    return false;   // PHP still logs it as usual
});
try {
    $base = $opt['base'] ?? ($cfg['base_url'] ?? 'http://127.0.0.1:8090/');
    // Backup first: a copy of the database into Dropbox (offsite via sync), keeping the last 30 nights.
    $bdir = $cfg['backup_dir'] ?? dirname($cfg['db']) . '/backups';
    if (!$smoke) { @mkdir($bdir, 0755, true);
        if (is_dir($bdir)) { $dst = "$bdir/docket-" . today() . ".db"; @unlink($dst); db()->exec("VACUUM INTO '" . str_replace("'", "''", $dst) . "'"); $old = glob("$bdir/docket-*.db"); rsort($old); foreach (array_slice($old, 30) as $f) @unlink($f); echo "backup: $dst\n"; } }
    $limit = $smoke ? 0 : (isset($opt['limit']) && $opt['limit'] !== false && $opt['limit'] !== '' ? (int)$opt['limit'] : 250);
    $gmail = pull_uspto_mail(7, true);
    if ($smoke && $gmail['error']) throw new RuntimeException('Gmail pull: ' . $gmail['error']);
    $phones = pull_client_phones(3);   // client phone numbers from new Helcim invoices
    $sweep = run_sweep($limit);
    try { $tmp = tm_pendency_refresh(28); echo $tmp, "\n"; } catch (Throwable $e) { echo 'USPTO trademark pendency: ', $e->getMessage(), "\n"; }   // 2026-10-04: refresh the USPTO backlog about once a month (timeline card)
    $ann = run_annuities();   // 2026-10-02: foreign annuities from confirmed bases (next two always docketed)
    $night = run_nightly();
    // Weekly: renewal horizon (10 years out) — Mondays, or whenever it has not run for 7 days
    $hl = setting('horizon_last', '2000-01-01'); $horizon = (date('N') === '1' || $hl < date('Y-m-d', strtotime('-7 days')) || isset($opt['horizon'])) ? extend_renewal_horizon() : null;
    $html = build_report($sweep, $night, $base, $horizon);
    if (!$smoke) { try { $gfl = govfee_weekly(); } catch (Throwable $e) { $gfl = 'USPTO fee schedule check failed: ' . $e->getMessage(); } if ($gfl) $html = preg_replace('/(<h3[^>]*>)/', '<p style="margin:8px 0;color:#b42318">' . addcslashes(htmlspecialchars($gfl), '\\$') . '</p>$1', $html, 1); }   // 2026-10-09: fee suggestions on the send page (lib/govfees.php)
    $gl = $gmail['error'] ? '<span style="color:#b42318">Gmail pull FAILED: ' . htmlspecialchars($gmail['error']) . '</span>' : "Gmail: {$gmail['messages']} USPTO messages in the last 7 days, {$gmail['new']} new, {$gmail['items']} notices docketed ({$gmail['receipts']} receipts), {$gmail['held']} held, {$gmail['ignored']} ignored" . ($gmail['skipped'] ? ", {$gmail['skipped']} skipped" : '') . '. (Also pulled hourly.)';
    if ($warnings) $gl .= '</p><p style="margin:8px 0;color:#b42318"><b>' . count($warnings) . ' PHP warning(s) during this run</b> (the code needs a look): ' . htmlspecialchars(implode('; ', array_slice($warnings, 0, 8)));
    $html = preg_replace('/(<h3[^>]*>)/', '<p style="margin:8px 0"><b>USPTO mail</b>: ' . $gl . '</p>$1', $html, 1);
    // Mail-lookup activity (sign-ins, searches, opens, refusals) rides on this report; the Mail page sends no e-mail of its own.
    $ml = null; if (!$smoke) { try { $ml = mail_lookup_section(); } catch (Throwable $e) { $ml = ['html' => '<p style="color:#b42318">Mail lookup activity could not be read: ' . htmlspecialchars($e->getMessage()) . '</p>', 'last_id' => null]; } }
    if ($ml) $html = preg_replace('/(<p style="color:#999;font-size:12px;margin-top:20px">Madder)/', addcslashes($ml['html'], '\\$') . '$1', $html, 1);
    $rdir = dirname($cfg['db']) . '/reports'; @mkdir($rdir, 0755, true); $file = "$rdir/" . today() . '.html'; file_put_contents($file, $html);
    run('INSERT OR REPLACE INTO settings(key,value) VALUES ("report_last", ?)', [today()]);
    run('INSERT OR REPLACE INTO settings(key,value) VALUES ("nightly_warnings", ?)', [json_encode($warnings)]);
    echo "gmail: {$gmail['messages']} msgs, {$gmail['new']} new, {$gmail['items']} notices" . ($gmail['error'] ? " ERROR {$gmail['error']}" : '') . "\n";
    echo "sweep: {$sweep['checked']} checked, " . count($sweep['proposed']) . " proposed, " . count($sweep['failed']) . " failed, {$sweep['unpublished']} unpublished; reminders: {$night['sent']} queued, {$night['held']} held, {$night['skipped']} skipped\nreport: $file\n";
    if ($warnings) echo count($warnings) . " warning(s): " . implode('; ', $warnings) . "\n";
    if ($smoke) { echo "smoke ok\n"; exit(0); }
    if ($alarmTo !== '') { $res = madder_mail($alarmTo, 'Madder morning report ' . fmt_date(today()) . ($gmail['error'] || $warnings ? ' (with problems)' : ''), $html); echo "email to $alarmTo: $res\n";
        if ($res === 'sent' && !empty($ml['last_id'])) run('INSERT OR REPLACE INTO settings(key,value) VALUES ("maillookup_last_id", ?)', [(string)$ml['last_id']]); }
} catch (Throwable $e) {
    $msg = get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    fwrite(STDERR, "NIGHTLY FAILED: $msg\n");
    if ($smoke) exit(1);
    try { audit('error', 'nightly failed: ' . $msg); } catch (Throwable $e2) {}
    if ($alarmTo !== '') echo "alarm to $alarmTo: " . madder_alarm($alarmTo, 'the nightly job stopped part-way', [$msg, 'No morning report was written for ' . date('Y-m-d') . '.']) . "\n";
    exit(1);
}
