<?php
// Heartbeat (2026-10-01): run an hour after the nightly job. If the nightly did not finish today - no morning report,
// no reminder queue, no sweep, or a failed Gmail pull - one short alarm e-mail goes out. Silent when all is well.
// Usage: php bin/heartbeat.php [--email=addr] [--dry]      (--dry prints what it would send)
declare(strict_types=1);
$GLOBALS['cfg'] = $cfg = require dirname(__DIR__) . '/config.php';
foreach (['db', 'dates', 'alarm'] as $f) require dirname(__DIR__) . "/lib/$f.php";
$opt = getopt('', ['email::', 'dry']); $today = date('Y-m-d'); $bad = [];
if (!is_file(dirname($cfg['db']) . "/reports/$today.html") || setting('report_last') !== $today) $bad[] = "No morning report was written for $today (the last one is " . (setting('report_last') ?: 'unknown') . ').';
foreach (['nightly_last' => 'The reminder queue', 'sweep_last' => 'The USPTO sweep'] as $k => $label) if (substr((string)setting($k, ''), 0, 10) !== $today) $bad[] = "$label did not run today (last run " . (setting($k) ?: 'never') . ').';
$g = q1("SELECT ts, detail FROM audit WHERE action='gmail_pull' AND user='nightly' ORDER BY id DESC LIMIT 1");
if (!$g || et($g['ts'], 10) !== $today) $bad[] = 'The Gmail pull of USPTO notices did not run today (last run ' . ($g ? et($g['ts']) : 'never') . ').';
elseif (($err = json_decode((string)$g['detail'], true)['error'] ?? null)) $bad[] = 'The Gmail pull of USPTO notices failed: ' . $err;
$w = json_decode((string)setting('nightly_warnings', '[]'), true) ?: [];
if (!$bad && $w) $bad[] = count($w) . ' PHP warning(s) during the nightly run: ' . implode('; ', array_slice($w, 0, 5));
if (!$bad) { echo "heartbeat ok: nightly finished $today\n"; exit(0); }
echo "HEARTBEAT: " . implode(' | ', $bad) . "\n";
if (!isset($opt['dry']) && !empty($opt['email'])) echo "alarm to {$opt['email']}: " . madder_alarm((string)$opt['email'], $w && count($bad) === 1 && str_contains($bad[0], 'warning') ? 'the nightly job ran with warnings' : 'the nightly job did not finish', $bad) . "\n";
exit(1);
