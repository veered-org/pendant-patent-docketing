<?php
// Records TSDR's drawing type (matters.mark_drawing) for US trademarks that do not have it yet, so logo marks show their image
// on the matter page and in client emails (2026-09-30). Live marks first. Safe to re-run; tsdr_lookup() stores the code.
//   php bin/fetch_mark_drawings.php [--limit=N]
$GLOBALS['cfg'] = require __DIR__ . '/../config.php';
foreach (['db', 'auth', 'dates', 'rules', 'mail', 'reminders', 'uspto'] as $f) require __DIR__ . "/../lib/$f.php";
$opt = getopt('', ['limit::']); $limit = (int)($opt['limit'] ?? 0);
$rows = q("SELECT id, ref, app_no FROM matters WHERE kind='trademark' AND (country IN ('', 'United States', 'US', 'USA')) AND COALESCE(mark_drawing,'')='' AND length(replace(replace(replace(app_no,'/',''),',',''),' ',''))=8 ORDER BY dead, id DESC" . ($limit ? " LIMIT $limit" : ''));
$n = ['ok' => 0, 'logo' => 0, 'fail' => 0]; echo count($rows) . " to look up\n";
foreach ($rows as $i => $m) {
    $sn = preg_replace('/\D/', '', $m['app_no']); $r = tsdr_lookup($sn);
    if (!$r['ok'] && str_contains($r['error'] ?? '', '429')) { sleep(20); $r = tsdr_lookup($sn); }
    if ($r['ok']) { $n['ok']++; $d = q1('SELECT mark_drawing FROM matters WHERE id=?', [$m['id']])['mark_drawing'] ?? ''; if (in_array($d, ['2', '3', '5'], true)) $n['logo']++; }
    else { $n['fail']++; echo "  {$m['ref']} failed: {$r['error']}\n"; }
    if ($i % 100 === 99) echo ($i + 1) . ' done ' . json_encode($n) . "\n";
    usleep(1300000);
}
echo 'finished ' . json_encode($n) . "\n";
