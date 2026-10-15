<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * Client report (2026-10-02): one PDF per client with every property - contact details, the deadlines of the next 12 months,
 * then each property with its numbers, dates, status and nearest deadlines. Create/Delete > Client report; the only input is the
 * client code (ABF) or part of the client's name. bin/client_report.py (reportlab, Lexend) draws it from the data gathered here.
 */
require_once __DIR__ . '/catalogimg.php';
const CLIENT_REPORT_PER_MATTER = 3;   // nearest deadlines listed per property
/** Internal to-dos and ticklers that are not the client's deadlines. */
function client_report_skips(string $type): bool { return (bool)preg_match('/^(Report |Remind Client$|Review File|TTAB: Review Order)/', $type); }
/** The docket's event names in plain words. Unknown types pass through unchanged. */
function client_event_label(string $t): string {
    static $map = [
        'Respond to Office Action - NOA' => 'Issue fee due', 'Respond to Office Action - CTNF' => 'Response to non-final Office action due',
        'Respond to Office Action - CTFR' => 'Response to final Office action due', 'Respond to Office Action - CTRS' => 'Response to restriction requirement due',
        'Respond to Office Action - CTEQ' => 'Response to Ex parte Quayle action due', 'Respond to Office Action - NTC.MISS.PRT' => 'Reply to notice to file missing parts due',
        'Final Response to Office Action' => 'Last day to respond to the Office action (with extensions)',
        'Respond to Office Action' => 'Response to Office action due', 'Respond to Office Action - Extended' => 'Last day to respond to the Office action (with extension)',
        'Respond to Office Action - Madrid' => 'Response to Office action due', 'PostReg Office Action - Response Required' => 'Response to post-registration Office action due',
        'Remind Client International Filing Deadline' => 'Foreign filing deadline', 'Foreign Filing Deadline' => 'Foreign filing deadline',
        'Non-Provisional Filing Deadline' => 'Non-provisional filing deadline', 'Non-Provisional Deadline' => 'Non-provisional filing deadline',
        'PCT National Phase 30 Mo. Deadline' => 'PCT national phase deadline (30 months)', 'Publication - Opposition Period Ends' => 'Opposition period ends',
        'Section 8 and 15 Declarations Begin' => 'Sections 8 & 15 declaration: window opens', 'Section 8 and 15 Declarations End' => 'Sections 8 & 15 declaration due',
        'Section 8 and 15 Declarations Grace Period Ends' => 'Sections 8 & 15 declaration: grace period ends',
        'Section 8 Declaration and Section 9 Renewal Begins' => 'Renewal (Sections 8 & 9): window opens', 'Section 8 Declaration and Section 9 Renewal Ends' => 'Renewal (Sections 8 & 9) due',
        'Section 8 and 9 Grace Period Ends' => 'Renewal (Sections 8 & 9): grace period ends',
        'Section 8 Declaration Begins' => 'Section 8 declaration: window opens', 'Section 8 Declaration Ends' => 'Section 8 declaration due', 'Section 8 Declaration Grace Period Ends' => 'Section 8 declaration: grace period ends',
        'Section 71 Declaration Begins' => 'Section 71 declaration: window opens', 'Section 71 Declaration Ends' => 'Section 71 declaration due', 'Section 71 Declaration Grace Period Ends' => 'Section 71 declaration: grace period ends',
        'Section 71 and 15 Declarations Begin' => 'Sections 71 & 15 declaration: window opens', 'Section 71 and 15 Declarations End' => 'Sections 71 & 15 declaration due', 'Section 71 and 15 Declarations Grace Period Ends' => 'Sections 71 & 15 declaration: grace period ends',
        'Renewal Period Begins' => 'Renewal: window opens', 'Renewal Period Ends' => 'Renewal due', 'Renewal' => 'Renewal due', 'Renewal Grace Period Ends' => 'Renewal: grace period ends',
        'Statement of Use' => 'Statement of Use due (final deadline)', 'Patent Expires' => 'Patent expires', 'Expected Issue Date' => 'Expected issue date', 'Annuity Due' => 'Annuity due', 'Maintenance Fee Due' => 'Maintenance fee due',
    ];
    if (isset($map[$t])) return $map[$t];
    if (preg_match('/^(\d+) 1\/2 Year Maintenance Fee (Window Open|Due|Grace Period)$/', $t, $m)) return $m[1] . '½-year maintenance fee' . ['Window Open' => ': window opens', 'Due' => ' due', 'Grace Period' => ': grace period ends'][$m[2]];
    if (preg_match('/^Statement of Use or (\d\w+) Extension Due$/', $t, $m)) return 'Statement of Use or ' . $m[1] . ' extension due';
    if (preg_match('/^Respond to Office Action - (.+)$/', $t, $m)) return 'Reply to USPTO notice due (' . $m[1] . ')';
    return $t;
}
/** The client the typed text means: ['code' => 'ABF'], or ['matches' => [[code, name, n], ...]] when it is a name fragment with several hits. */
function client_report_find(string $input): array {
    $x = trim($input); if ($x === '') return ['matches' => []];
    $code = strtoupper($x);
    if (preg_match('/^[A-Z]{2,5}$/', $code) && q1('SELECT 1 FROM matters WHERE client_code=? LIMIT 1', [$code])) return ['code' => $code];
    $rows = q('SELECT c.code, c.name, (SELECT count(*) FROM matters m WHERE m.client_code=c.code) n FROM clients c WHERE c.name LIKE ? AND EXISTS (SELECT 1 FROM matters m WHERE m.client_code=c.code) ORDER BY c.name LIMIT 40', ['%' . $x . '%']);
    if (count($rows) === 1) return ['code' => $rows[0]['code']];
    return ['matches' => $rows];
}
function client_report_data(string $code): ?array {
    $ms = q('SELECT * FROM matters WHERE client_code=?', [$code]); if (!$ms) return null;
    usort($ms, fn($a, $b) => [(int)$a['dead'], $a['kind']] <=> [(int)$b['dead'], $b['kind']] ?: strnatcasecmp($a['ref'], $b['ref']));
    $c = q1('SELECT * FROM clients WHERE code=?', [$code]) ?: ['code' => $code, 'name' => ''];
    $today = today(); $year = add_days($today, 365, false); $out = [];
    $num = function (array $m, string $n): string { $n = trim($n); if ($n === '') return ''; if (preg_match('/^(D|PP|RE)(\d{4,})$/i', $n, $x)) return strtoupper($x[1]) . number_format((int)$x[2]); return fmt_reg($n); };
    foreach ($ms as $m) {
        if ($m['dead'] && str_starts_with((string)$m['dead_reason'], 'Duplicate record')) continue;   // a second copy of a record listed above
        $us = (bool)preg_match('/^(|United States|US|USA)$/i', trim((string)$m['country']));
        $ev = array_values(array_filter(q('SELECT type, due_date FROM events WHERE matter_id=? AND status IN ("open","proposed") ORDER BY due_date, id', [$m['id']]), fn($e) => !client_report_skips((string)$e['type'])));
        $expires = ''; foreach ($ev as $e) if ($e['type'] === 'Patent Expires') $expires = (string)$e['due_date'];
        $dl = array_values(array_filter($ev, fn($e) => $e['type'] !== 'Patent Expires'));
        $row = fn($e) => ['type' => client_event_label((string)$e['type']), 'due' => $e['due_date'], 'overdue' => $e['due_date'] < $today];
        $st = trim(explode('.', (string)$m['pto_status'])[0]); if ($st === '' || preg_match('/^(e-Office Action Received|New Application Recorded)$/i', $st)) $st = trim((string)$m['status']) !== '' && !preg_match('/^(e-Office Action Received|New Application Recorded|Dead|Inactive)$/i', (string)$m['status']) ? trim((string)$m['status']) : $st;
        $out[] = [
            'ref' => $m['ref'], 'kind' => $m['kind'], 'title' => clean_title($m['title']), 'country' => $m['country'], 'country_code' => country_code((string)$m['country']),
            'app_type' => in_array($m['kind'], ['patent'], true) ? trim((string)$m['app_type']) : '', 'classes' => trim((string)$m['classes']),
            'app_no' => $us ? fmt_app($m['app_no'], 'United States') : trim((string)$m['app_no']), 'filing_date' => substr((string)$m['filing_date'], 0, 10),
            'reg_no' => $num($m, (string)$m['reg_no']), 'grant_date' => substr((string)$m['grant_date'], 0, 10),
            'status' => $st, 'status_date' => substr((string)$m['pto_status_date'], 0, 10), 'dead' => (int)$m['dead'],
            'dead_reason' => trim(preg_replace('/\s*\(marked inactive by [^)]*\)/', '', trunc(trim(explode('.', (string)($m['dead_reason'] ?: $m['pto_status']))[0]), 140))), 'expires' => $expires,
            'events' => array_map($row, array_slice($dl, 0, CLIENT_REPORT_PER_MATTER)), 'more' => max(0, count($dl) - CLIENT_REPORT_PER_MATTER), 'last' => $dl ? end($dl)['due_date'] : '',
            'image' => ($img = catalog_image($m)) ? $img['file'] : '',   // patent figure / logo from the firm's own catalogs (2026-10-14)
            'upcoming' => array_map($row, array_values(array_filter($dl, fn($e) => $e['due_date'] <= $year))),
        ];
    }
    return ['client' => ['code' => $code, 'name' => trim((string)($c['name'] ?? ''))],   // no e-mail addresses or phone numbers on the report (2026-10-03)
            'today' => $today, 'firm' => firm('legal_name'), 'per_matter' => CLIENT_REPORT_PER_MATTER, 'matters' => $out];
}
/** Build the PDF: ['path' => temp file, 'name' => download name] or ['error' => why]. The caller sends and deletes the file. */
function client_report_pdf(string $code): array {
    $d = client_report_data($code); if (!$d) return ['error' => "No properties on file for client $code."];
    $tmp = tempnam(sys_get_temp_dir(), 'pcr'); $json = "$tmp.json"; $pdf = "$tmp.pdf";
    file_put_contents($json, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    $py = function_exists('tool') ? tool('python3') : 'python3';
    $err = (string)shell_exec($py . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/client_report.py') . ' ' . escapeshellarg($json) . ' ' . escapeshellarg($pdf) . ' 2>&1');
    @unlink($json); @unlink($tmp);
    if (!is_file($pdf) || filesize($pdf) < 1000) { @unlink($pdf); return ['error' => 'The report could not be drawn: ' . trunc(trim($err) ?: 'no output from client_report.py', 300)]; }
    $name = preg_replace('/[^A-Za-z0-9._ &-]+/', '', $code . ' - Client Portfolio ' . today() . ($d['client']['name'] !== '' ? ' - ' . $d['client']['name'] : '')) . '.pdf';
    audit('client_report', "$code: " . count($d['matters']) . ' properties');
    return ['path' => $pdf, 'name' => $name];
}
