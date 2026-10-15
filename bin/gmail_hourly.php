<?php
/**
 * SERVER job, from bin/hourly.php: pull new USPTO e-mail every hour instead of once a night (2026-10-03, XYZ022: a TEAS receipt
 * that came at 23:27 sat unread until morning). Same pull as the nightly (lib/gmail_pull.php); already-seen messages are skipped.
 *   php bin/gmail_hourly.php [--days=3]
 */
declare(strict_types=1);
$GLOBALS['cfg'] = $cfg = require dirname(__DIR__) . '/config.php';
foreach (['db', 'auth', 'dates', 'rules', 'mail', 'reminders', 'uspto', 'gmail_pull', 'held', 'send', 'oafetch'] as $f) require_once dirname(__DIR__) . "/lib/$f.php";
date_default_timezone_set('America/New_York');
$days = (int)(getopt('', ['days::'])['days'] ?? 3) ?: 3;
$g = pull_uspto_mail($days, true);
foreach ($g['results'] as $r) echo '  ' . ($r['matter']['ref'] ?? '?') . ' ' . $r['item']['doc_code'] . ' ' . $r['item']['date'] . ($r['notes'] ? ' (' . implode('; ', $r['notes']) . ')' : '') . "\n";
foreach (cancel_reports_sent_by_hand() as $x) echo "  already emailed by hand, to-do cancelled: $x\n";   // 2026-10-03 (XYZ064, XYZ004)
if ($g['error']) { echo "ERROR {$g['error']}\n"; exit(1); }
echo "gmail: {$g['new']} new of {$g['messages']}; {$g['items']} docketed ({$g['receipts']} receipts), {$g['held']} held, {$g['ignored']} ignored\n";
