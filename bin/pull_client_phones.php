<?php
// Client phone numbers from Helcim invoice emails and PaySimple customer exports (2026-09-30). Dry run unless --apply.
//   php bin/pull_client_phones.php --days=3000 [--apply]          Helcim invoice emails (Gmail, read-only)
//   php bin/pull_client_phones.php --csv=a.csv,b.csv [--apply]     PaySimple Customers_*.csv exports
//   php bin/pull_client_phones.php --ea=customers.json [--apply]    booking-system customers
//   php bin/pull_client_phones.php --signatures [--limit=N] [--apply]  clients with no phone: numbers in their own email signatures
$GLOBALS['cfg'] = require __DIR__ . '/../config.php';
foreach (['db', 'auth', 'dates', 'rules', 'mail', 'reminders', 'uspto', 'gmail_pull', 'send', 'phones'] as $f) require_once __DIR__ . "/../lib/$f.php";
$opt = getopt('', ['days::', 'csv::', 'ea::', 'signatures', 'limit::', 'apply']); $apply = isset($opt['apply']);
if (!empty($opt['csv'])) foreach (explode(',', $opt['csv']) as $f) { $r = import_paysimple_phones($f, $apply); echo "$f: " . json_encode($r, JSON_PRETTY_PRINT) . "\n"; }
if (!empty($opt['days'])) { $r = pull_client_phones((int)$opt['days'], $apply); echo 'Helcim: ' . json_encode($r, JSON_PRETTY_PRINT) . "\n"; }
if (!empty($opt['ea'])) { $r = import_ea_phones($opt['ea'], $apply); echo 'Booking: ' . json_encode($r, JSON_PRETTY_PRINT) . "\n"; }
if (isset($opt['signatures'])) { $r = signature_phones((int)($opt['limit'] ?? 0), $apply); echo 'Signatures: ' . json_encode($r, JSON_PRETTY_PRINT) . "\n"; }
