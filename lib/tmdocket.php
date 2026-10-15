<?php
declare(strict_types=1);
/**
 * 2026-10-05 (ORI001): a US trademark added by hand came in with no open deadlines. tm_docket_from_tsdr() reads the TSDR record
 * once: fills the mark, filing date and registration number when blank, and dockets the deadlines that follow from the LATEST step in
 * the prosecution history, using the same rules as the USPTO e-mail intake (events_for_tm_notice):
 *   registered                      -> Section 8 / 15 / 9 windows
 *   Notice of Allowance (+N extensions granted, no Statement of Use filed) -> the next Statement of Use / extension deadline
 *   published, opposition period not over                               -> opposition period end
 *   Office action not yet answered  -> response deadlines (an extension of the response period granted: only the extended one)
 * Only deadlines still ahead are added, never twice. Returns a one-line summary for the flash message.
 */
function tm_docket_from_tsdr(int $mid): string {
    $m = q1('SELECT * FROM matters WHERE id=?', [$mid]); if (!$m || $m['kind'] !== 'trademark') return '';
    $sn = preg_replace('/\D/', '', (string)$m['app_no']);
    if (strlen($sn) !== 8 || !preg_match('/^(|United States|US|USA)$/i', trim((string)$m['country'])) || empty($GLOBALS['cfg']['tsdr_key'])) return '';
    try { $r = tsdr_lookup($sn); } catch (Throwable $e) { return 'USPTO record not read (' . $e->getMessage() . ')'; }
    if (empty($r['ok'])) return 'USPTO record not read (' . ($r['error'] ?? 'no answer') . ')';
    $set = [];
    if (trim((string)$m['title']) === '' && ($r['mark'] ?? '') !== '') $set['title'] = $r['mark'];
    if (empty($m['filing_date']) && ($r['filing_date'] ?? '') !== '') $set['filing_date'] = substr($r['filing_date'], 0, 10);
    if (trim((string)$m['reg_no']) === '' && ($r['reg_no'] ?? '') !== '' && $r['reg_no'] !== '0000000') $set['reg_no'] = $r['reg_no'];
    $set['pto_status'] = (string)$r['status']; $set['pto_status_date'] = (string)$r['status_date'];
    foreach ($set as $k => $v) run("UPDATE matters SET $k=? WHERE id=?", [$v, $mid]);
    $m = q1('SELECT * FROM matters WHERE id=?', [$mid]);
    if (!empty($r['dead'])) return 'USPTO: ' . mb_strimwidth((string)$r['status'], 0, 80, '…') . ' (dead: nothing docketed)';
    $hist = $r['history'];   // newest first
    $last = function (callable $f, string $after = '') use ($hist): string { foreach ($hist as $h) if ($h['date'] > $after && $f($h)) return $h['date']; return ''; };
    $isOA = fn($h) => in_array($h['code'], ['GNRT', 'GNFR', 'GNEA', 'GNSL', 'GNPR'], true);
    $isReply = fn($h) => preg_match('/RESPONSE|REQUEST FOR RECON|AMENDMENT/i', $h['desc']) && preg_match('/RECEIVED|ENTERED|FILED/i', $h['desc']) && !preg_match('/EXTENSION/i', $h['desc']);
    $events = []; $why = '';
    $reg = substr((string)($r['reg_date'] ?? ''), 0, 10);
    $noa = $last(fn($h) => $h['code'] === 'NOAM');
    $pub = $last(fn($h) => preg_match('/PUBLISHED FOR OPPOSITION/i', $h['desc']));
    $oa = $last($isOA);
    if ($reg !== '') { $events = events_for_tm_notice('REGISTRATION', $reg, $m); $why = 'registered ' . fmt_date($reg); }
    elseif ($noa !== '') {
        if ($last(fn($h) => in_array($h['code'], ['IUAF', 'SUPC'], true) || preg_match('/STATEMENT OF USE|ALLEGATION OF USE/i', $h['desc']) && preg_match('/RECEIVED|FILED/i', $h['desc']), $noa) !== '') $why = 'Statement of Use filed';
        else {
            $n = 0; foreach ($hist as $h) if ($h['date'] > $noa && preg_match('/EXTENSION/i', $h['desc']) && preg_match('/GRANTED|APPROVED/i', $h['desc']) && !preg_match('/RESPONSE PERIOD/i', $h['desc'])) $n++;
            $events = $n ? events_for_tm_notice('APPROVAL OF EXTENSION REQUEST', $noa, $m, ['n' => min($n, 5)]) : array_values(array_filter(events_for_tm_notice('NOTICE OF ALLOWANCE', $noa, $m), fn($e) => $e['type'] !== 'Report Notice of Allowance'));
            $why = 'allowed ' . fmt_date($noa) . ($n ? ", $n extension(s) granted" : '');
        }
    } elseif ($pub !== '' && ($oa === '' || $pub > $oa)) { $events = events_for_tm_notice('NOTICE OF PUBLICATION', $pub, $m); $why = 'published ' . fmt_date($pub); }
    elseif ($oa !== '' && $last($isReply, $oa) === '') {
        $events = events_for_tm_notice('OFFICE ACTION', $oa, $m, ['app_no' => $sn]);
        $ext = $last(fn($h) => preg_match('/EXTENSION TO RESPONSE PERIOD|EXTENSION.*RESPONSE/i', $h['desc']) && preg_match('/GRANTED|RECEIVED/i', $h['desc']), $oa);
        if ($ext !== '') $events = array_values(array_filter($events, fn($e) => $e['type'] !== 'Respond to Office Action'));   // the 3-month date was extended
        $why = 'Office action ' . fmt_date($oa) . ($ext !== '' ? ', response period extended ' . fmt_date($ext) : '') . ', not yet answered';
    } else $why = 'nothing open at the USPTO';
    $added = [];
    foreach ($events as $e) {
        if (($e['type'] ?? '') === '' || ($e['due'] ?? '') < today()) continue;
        if (q1('SELECT 1 FROM events WHERE matter_id=? AND type=? AND due_date=?', [$mid, $e['type'], $e['due']])) continue;
        run('INSERT INTO events(matter_id,type,due_date,status,source,note) VALUES (?,?,?,"open","tsdr",?)', [$mid, $e['type'], $e['due'], trim('From the USPTO record when added: ' . ($e['note'] ?? ''))]);
        $added[] = $e['type'] . ' ' . fmt_date($e['due']);
    }
    audit('tsdr_docket', $m['ref'] . ': ' . $why . ($added ? '; docketed ' . implode('; ', $added) : '; nothing docketed'), $mid);
    return 'USPTO: ' . $why . ($added ? '. Docketed: ' . implode('; ', $added) . '.' : '.');
}
