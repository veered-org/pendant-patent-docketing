<?php
declare(strict_types=1);
/**
 * Patent issuance (2026-10-02): on issue day the USPTO's eGrant is saved into the matter folder, the Patent Issuance Letter is
 * filled into the firm's XYZ005 letter (templates/patent-issuance-letter.docx) and saved beside it, and a "Report Patent Issuance"
 * to-do is queued with the eGrant and the letter PDF pre-ticked. The letter PDF is saved by hand from Word (no Word/LibreOffice on
 * the server), which is also the review step. Sources:
 *   eGrant + term adjustment  Open Data Portal (EGRANT.PDF; /adjustment), the same day the patent issues, published or not
 *   claims check              bin/claims_compare.py: the eGrant's independent claims against the last-filed claims (or claims the
 *                             examiner wrote out in full in the NOA); the "checked for accuracy" sentence stays only when they match
 *   addressee                 the latest letter in the client's folders (bin/issuance_letter.py address); none found = ADDRESS NEEDED
 */
const ISSUANCE_REPORT_EVENT = 'Report Patent Issuance';
const ISSUANCE_LOOKBACK_DAYS = 45;   // patents issued longer ago than this are not picked up (they were reported by hand)

function odp_get(string $path): array {
    [$c, $b] = http_get('https://api.uspto.gov/api/v1/patent/applications/' . $path, ['X-API-KEY: ' . $GLOBALS['cfg']['odp_key']]);
    $j = $c === 200 ? json_decode($b, true) : null; return is_array($j) ? $j : [];
}
function odp_download(string $url): string { [$c, $b] = http_get($url, ['X-API-KEY: ' . $GLOBALS['cfg']['odp_key']]); return $c === 200 ? (string)$b : ''; }
/** The newest document of a code in the ODP document bag, in a format: download URL or ''. */
function odp_doc_url(array $bag, string $code, string $mime): string {
    usort($bag, fn($a, $b) => strcmp((string)$b['officialDate'], (string)$a['officialDate']));
    foreach ($bag as $d) if (($d['documentCode'] ?? '') === $code) foreach ($d['downloadOptionBag'] ?? [] as $o) if (($o['mimeTypeIdentifier'] ?? '') === $mime) return (string)$o['downloadUrl'];
    return '';
}
/** First file inside an ODP tar (XML downloads come as tar archives), or the bytes themselves when not a tar. */
function untar_first(string $bytes, string $ext = 'xml'): string {
    if (strlen($bytes) < 512 || substr($bytes, 257, 5) !== 'ustar') return $bytes;
    for ($o = 0; $o + 512 <= strlen($bytes);) {
        $name = rtrim(substr($bytes, $o, 100), "\0"); $size = octdec(trim(rtrim(substr($bytes, $o + 124, 12), "\0"))); if ($name === '') break;
        $data = substr($bytes, $o + 512, (int)$size); if (str_ends_with(strtolower($name), '.' . $ext)) return $data;
        $o += 512 + (int)(ceil($size / 512) * 512);
    }
    return '';
}
function fmt_weekday(string $iso): string { return (new DateTimeImmutable($iso))->format('l, F j, Y'); }
function scratch_dir(): string { $d = dirname($GLOBALS['cfg']['db']) . '/issuance_tmp'; if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }

/**
 * Name + address lines and salutation for the letter, with the file they came from. A letter in the patent's own folder (its filing
 * report) is used as is. A letter from another folder of the client is used only when its addressee names an inventor or applicant
 * of this patent: one client code can be several clients (CNJ = a foreign associate's different clients, RWG = referrals).
 */
function client_letter_address(array $m, array $names = []): array {
    $code = (string)$m['client_code']; $ref = (string)$m['ref']; if ($code === '') return [];
    $cands = [];
    foreach (mf_all() as $folder) {
        if (stripos($folder, $code) !== 0) continue;
        $dir = mf_find(preg_replace('/ \(.*$/', '', $folder)); if (!$dir) continue;
        $own = strcasecmp(preg_replace('/ \(.*$/', '', $folder), $ref) === 0;
        foreach (mf_list($dir) as $f) if (preg_match('/(Letter|Report)/i', $f['name']) && preg_match('/\.(docx|pdf)$/i', $f['name']) && !preg_match('/Issue Notification|Novelty|Search Report|AckReceipt/i', $f['name'])) $cands[] = [$own ? 1 : 0, $f['mtime'], $dir, $f];
    }
    usort($cands, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);   // own folder first, then newest
    $tokens = array_values(array_filter(array_map('strtolower', preg_split('/[^A-Za-z]+/', implode(' ', $names))), fn($t) => strlen($t) >= 4 && !in_array($t, ['corp', 'corporation', 'company', 'limited', 'group', 'systems', 'inc', 'llc'], true)));
    foreach (array_slice($cands, 0, 10) as [$own, , $dir, $f]) {
        $p = mf_local($dir, $f['rel']); if ($p === '') continue;
        $j = json_decode((string)shell_exec('python3 ' . escapeshellarg(dirname(__DIR__) . '/bin/issuance_letter.py') . ' address ' . escapeshellarg($p) . ' 2>/dev/null'), true);
        if (empty($j['lines'])) continue;
        $hay = strtolower(implode(' ', $j['lines']) . ' ' . ($j['salutation'] ?? ''));
        if ($own || array_filter($tokens, fn($t) => str_contains($hay, $t))) return $j + ['from' => $f['name'], 'own' => (bool)$own];
    }
    return [];
}

/** Everything for one issued patent. Returns a log line, or '' when there is nothing to do yet. */
function prepare_issuance(array $m): string {
    $app = preg_replace('/\D/', '', (string)$m['app_no']); if ($app === '') return '';
    $docs = odp_get($app . '/documents'); $bag = $docs['documentBag'] ?? [];
    $egUrl = odp_doc_url($bag, 'EGRANT.PDF', 'PDF'); if ($egUrl === '') return '';            // not issued yet, or ODP not updated yet
    $meta = (odp_get($app)['patentFileWrapperDataBag'][0]['applicationMetaData'] ?? []);
    $patentNo = (string)($meta['patentNumber'] ?? '') ?: (string)($m['issue_patent_no'] ?? '') ?: (string)$m['reg_no'];
    $issued = substr((string)($meta['grantDate'] ?? ''), 0, 10) ?: (string)$m['grant_date'];
    if ($patentNo === '' || $issued === '') return "$m[ref]: eGrant found but no patent number / grant date yet; will retry";
    $design = is_design_matter($m) || preg_match('/^D/i', $patentNo);
    $filed = substr((string)($meta['filingDate'] ?? ''), 0, 10) ?: (string)$m['filing_date'];
    $pta = $design ? 0 : (int)(odp_get($app . '/adjustment')['patentFileWrapperDataBag'][0]['patentTermAdjustmentData']['adjustmentTotalQuantity'] ?? 0);
    $dir = mf_find((string)$m['ref']) ?: mf_new((string)$m['ref']); $names = mf_names($dir); $notes = [];
    if (array_filter($names, fn($n) => preg_match('/Patent Issuance Letter/i', $n))) return '';   // reported by hand already
    $pn = fmt_patent_no($patentNo); $tmp = scratch_dir() . '/' . preg_replace('/[^A-Za-z0-9.]/', '_', (string)$m['ref']);
    // 1. the eGrant into the file (unless it was saved by hand)
    $eg = odp_download($egUrl); if (!str_starts_with($eg, '%PDF')) return "$m[ref]: eGrant download failed; will retry";
    $letterCode = next_doc_code($names, (string)$m['ref']); $letterCode = $letterCode !== '' ? $letterCode : '01a';
    $grantName = null;
    if (!array_filter($names, fn($n) => preg_match('/U\.?\s*S\.?\s*Patent\s+[D\d,]+|eGrant Certificate/i', $n))) {
        $grantCode = next_doc_code(array_merge($names, ["$m[ref]-$letterCode-x"]), (string)$m['ref']);
        $grantName = mf_write($dir, "$m[ref]-$grantCode-U.S. Patent " . preg_replace('/,/', '', $pn) . '.pdf', $eg); $notes[] = "eGrant saved as $grantName";
    }
    // 2. claims: the eGrant's independent claims against the last-filed claims (and the examiner's amendment in the NOA)
    file_put_contents("$tmp-egrant.pdf", $eg); $clm = untar_first(odp_download(odp_doc_url($bag, 'CLM', 'XML'))); $noa = untar_first(odp_download(odp_doc_url($bag, 'NOA', 'XML')));
    $claims = ['ok' => false];
    if ($design) $claims = ['ok' => true, 'design' => true];
    elseif ($clm !== '') {
        file_put_contents("$tmp-clm.xml", $clm); file_put_contents("$tmp-noa.xml", $noa);
        $claims = json_decode((string)shell_exec('python3 ' . escapeshellarg(dirname(__DIR__) . '/bin/claims_compare.py') . ' ' . escapeshellarg("$tmp-egrant.pdf") . ' ' . escapeshellarg("$tmp-clm.xml") . ' ' . escapeshellarg("$tmp-noa.xml") . ' 2>/dev/null'), true) ?: ['ok' => false, 'diffs' => [['note' => 'comparison failed to run']]];
    } else $claims = ['ok' => false, 'diffs' => [['note' => 'no filed claims (CLM) in the Open Data Portal']]];
    $notes[] = $claims['ok'] ? ($design ? 'design patent: single formal claim' : 'claims checked: independent claims ' . implode(', ', $claims['granted_independent'] ?? []) . ' match the allowed claims')
        : 'CLAIMS DIFFER from the last-filed claims, so the letter leaves out "the independent claim language ... has been checked": ' . implode('; ', array_map(fn($d) => ($d['granted'] ?? '') ? 'claim ' . $d['granted'] . ': ' . mb_substr((string)($d['changes'] ?? $d['note'] ?? ''), 0, 160) : (string)($d['note'] ?? ''), $claims['diffs'] ?? []))
          . (!empty($claims['examiner_amendment']) ? ' (the Notice of Allowance has an examiner\'s amendment: check it, then add the sentence back if they agree)' : '');
    // 3. dates: term (utility: filing + 20 years + PTA; design: 15 years from issue), first maintenance reminder (30 days before 3.5-year due)
    // 2026-10-05 (RWG111.002.03-CIP): a utility term runs 20 years from the EARLIEST priority filing date, i.e. the earliest
    // non-provisional parent (continuation / CIP / divisional / PCT), plus PTA; provisionals do not count (35 USC 154(a)(2)-(3)).
    $prio = $filed;
    if (!$design) foreach (odp_get($app . '/continuity')['patentFileWrapperDataBag'][0]['parentContinuityBag'] ?? [] as $par) {
        $pd = substr((string)($par['parentApplicationFilingDate'] ?? ''), 0, 10);
        if ($pd !== '' && !preg_match('/provisional/i', (string)($par['claimParentageTypeCodeDescriptionText'] ?? '')) && ($prio === '' || $pd < $prio)) $prio = $pd;
    }
    if ($prio !== $filed) $notes[] = 'term counted from the earliest priority filing date ' . fmt_long($prio);
    $expires = $design ? add_months($issued, design_term_years($m, $issued) * 12, false) : add_days(add_months($prio ?: $issued, 240, false), $pta, false);
    $lead = max(array_map('intval', explode(',', (string)(q1('SELECT lead_days FROM templates WHERE event_type=?', ['3 1/2 Year Maintenance Fee Due'])['lead_days'] ?? '30')) ?: [30]));
    $reminder = add_months($issued, 36, false);   // 2026-10-05: the date given is when the 3.5-year fee window OPENS (3 years from issue)
    // 4. addressee from the client's latest letter
    $people = array_merge(array_map(fn($i) => trim(($i['firstName'] ?? '') . ' ' . ($i['lastName'] ?? '')), $meta['inventorBag'] ?? []), array_map(fn($a) => (string)($a['applicantNameText'] ?? ''), $meta['applicantBag'] ?? []), [(string)($meta['firstApplicantName'] ?? ''), (string)$m['owner']]);
    $ad = client_letter_address($m, $people); $notes[] = $ad ? 'addressed as in ' . $ad['from'] . ($ad['own'] ? '' : ' (another file of this client; the addressee matches an inventor/applicant)') : 'ADDRESS NEEDED: no earlier letter for this patent or its inventors/applicant was found';
    $appl = ($design ? 'Design - ' : '') . fmt_app((string)$m['app_no'], (string)($m['country'] ?? ''));
    $letterName = "$m[ref]-$letterCode-Patent Issuance Letter.docx";
    $fields = ['date' => fmt_long(today()), 'address_lines' => $ad['lines'] ?? [], 'salutation' => $ad['salutation'] ?? '', 'title' => clean_title((string)$m['title']) ?: (string)($meta['inventionTitle'] ?? ''),
               'appl' => $appl, 'filed' => $filed ? fmt_long($filed) : '', 'patent_no' => $pn, 'issued' => fmt_long($issued), 'ref' => (string)$m['ref'], 'design' => (bool)$design, 'pta_days' => $pta, 'priority' => $prio !== $filed && $prio !== '' ? fmt_long($prio) : '',
               'expires' => fmt_weekday($expires), 'reminder' => fmt_long($reminder), 'claims_ok' => (bool)$claims['ok'], 'filename' => $letterName];
    file_put_contents("$tmp-fields.json", json_encode($fields));
    shell_exec('python3 ' . escapeshellarg(dirname(__DIR__) . '/bin/issuance_letter.py') . ' fill ' . escapeshellarg(dirname(__DIR__) . '/templates/patent-issuance-letter.docx') . ' ' . escapeshellarg("$tmp-letter.docx") . ' ' . escapeshellarg("$tmp-fields.json") . ' 2>&1');
    if (!is_file("$tmp-letter.docx")) return "$m[ref]: the issuance letter could not be generated";
    $saved = mf_write($dir, $letterName, (string)file_get_contents("$tmp-letter.docx")); $notes[] = "letter saved as $saved";
    foreach (['egrant.pdf', 'clm.xml', 'noa.xml', 'fields.json', 'letter.docx'] as $x) @unlink("$tmp-$x");
    // 5. the to-do
    run('UPDATE matters SET grant_date=COALESCE(grant_date, ?), issue_patent_no=CASE WHEN issue_patent_no="" THEN ? ELSE issue_patent_no END WHERE id=?', [$issued, $patentNo, $m['id']]);
    run('INSERT INTO events(matter_id,type,due_date,status,source,doc_code,mail_date,note) VALUES (?,?,?,"open","rule","EGRANT.PDF",?,?)',
        [$m['id'], ISSUANCE_REPORT_EVENT, today(), $issued, 'Email the client: U.S. Patent ' . $pn . ' issued ' . fmt_date($issued) . '. Open the letter, review it, save it as PDF into the folder, then Send. ' . implode(' | ', $notes)]);
    close_expected_issue((int)$m['id'], $issued);
    audit('issuance', "$m[ref] U.S. Patent $pn: " . implode(' | ', $notes), (int)$m['id']);
    return "$m[ref]: U.S. Patent $pn issued $issued; " . implode('; ', $notes);
}
/** 2026-10-06 (XYZ017, XYZ001): once the patent has issued and its "Report Patent Issuance" to-do exists, the Expected Issue Date
 *  is answered - close it so the file shows once under "overdue / today". */
function close_expected_issue(int $matterId, string $issued): void {
    run('UPDATE events SET status="done", done_by="pendant", done_at=datetime("now"), note=note_add(note, ?) WHERE matter_id=? AND type="Expected Issue Date" AND status IN ("open","proposed")',
        ['issued ' . $issued . '; see the ' . ISSUANCE_REPORT_EVENT . ' to-do', $matterId]);
}
/** Hourly: US patents that issued in the last ISSUANCE_LOOKBACK_DAYS with no issuance to-do yet. */
function run_issuance(): array {
    $out = []; $since = add_days(today(), -ISSUANCE_LOOKBACK_DAYS, false);
    $ms = q("SELECT DISTINCT m.* FROM matters m JOIN events e ON e.matter_id=m.id WHERE m.kind='patent' AND m.dead=0 AND (e.type='Expected Issue Date' AND e.due_date BETWEEN ? AND ?)
             AND NOT EXISTS (SELECT 1 FROM events r WHERE r.matter_id=m.id AND r.type=?)", [$since, today(), ISSUANCE_REPORT_EVENT]);
    foreach ($ms as $m) {
        if (!preg_match('/^(|United States|US|USA)$/i', trim((string)($m['country'] ?? '')))) continue;
        try { $l = prepare_issuance($m); if ($l !== '') $out[] = $l; } catch (Throwable $e) { $out[] = "$m[ref]: issuance FAILED: " . $e->getMessage(); }
    }
    return $out;
}
