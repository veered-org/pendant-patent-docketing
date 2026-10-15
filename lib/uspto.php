<?php
declare(strict_types=1);
/** Live USPTO lookups for the Check button. TSDR for trademarks (all public), Open Data Portal for published patents. */
function http_json(string $url, array $headers): ?array {
    // curl first: the pair server has allow_url_fopen off (the laptop may have the reverse).
    $headers = array_merge($headers, ['User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/128 Safari/537.36', 'Accept: application/json']);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true]);
        $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($body === false) return null;
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 25, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx); if ($body === false) return null;
        $code = 0; foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) $code = (int)$m[1];
    }
    $j = json_decode((string)$body, true); return ['code' => $code, 'json' => is_array($j) ? $j : null];
}
function tsdr_lookup(string $serial): array {
    $r = http_json("https://tsdrapi.uspto.gov/ts/cd/casestatus/sn$serial/info.json", ['USPTO-API-KEY: ' . $GLOBALS['cfg']['tsdr_key']]);
    if (!$r || !$r['json'] || empty($r['json']['trademarks'][0])) return ['ok' => false, 'error' => 'TSDR returned ' . ($r['code'] ?? 'no response') . ' for serial ' . $serial];
    $t = $r['json']['trademarks'][0]; $s = $t['status'];
    // Remember the drawing type so logo marks show their image (matter page, client emails). 2026-09-30.
    $dc = substr(trim((string)($s['markDrawingCd'] ?? '')), 0, 1); if ($dc !== '') try { run("UPDATE matters SET mark_drawing=? WHERE kind='trademark' AND replace(replace(replace(app_no,'/',''),',',''),' ','')=?", [$dc, $serial]); } catch (Throwable $e) {}
    // Current filing basis, DocketTrak style ("1a", "1b", "1a, 1b", "44e", "66a"); remembered on the matter (2026-10-04: filing + opposition emails).
    $basis = implode(', ', array_keys(array_filter(['1a' => !empty($s['useCurr']), '1b' => !empty($s['ituCurr']), '44d' => !empty($s['sect44dCurr']), '44e' => !empty($s['sect44eCurr']), '66a' => !empty($s['sect66aCurr'])])));
    $filedBasis = implode(', ', array_keys(array_filter(['1a' => !empty($s['filedUse']), '1b' => !empty($s['filedItu']), '44d' => !empty($s['filed44d']), '44e' => !empty($s['filed44e']), '66a' => !empty($s['filed66a'])])));
    if ($filedBasis !== '') try { run("UPDATE matters SET basis_filed=? WHERE kind='trademark' AND replace(replace(replace(app_no,'/',''),',',''),' ','')=?", [$filedBasis, $serial]); } catch (Throwable $e) {}
    if ($basis !== '') try { run("UPDATE matters SET basis=? WHERE kind='trademark' AND replace(replace(replace(app_no,'/',''),',',''),' ','')=?", [$basis, $serial]); } catch (Throwable $e) {}
    $hist = array_map(fn($h) => ['date' => substr((string)($h['entryDate'] ?? ''), 0, 10), 'code' => (string)($h['entryCode'] ?? ''), 'desc' => (string)($h['entryDesc'] ?? '')], $t['prosecutionHistory'] ?? []);
    usort($hist, fn($a, $b) => strcmp($b['date'], $a['date']));
    return ['ok' => true, 'source' => 'TSDR', 'basis' => $basis, 'basis_filed' => $filedBasis, 'status' => $s['extStatusDesc'] ?? '', 'status_date' => substr((string)($s['statusDate'] ?? ''), 0, 10), 'reg_no' => (string)($s['usRegistrationNumber'] ?? ''), 'reg_date' => (string)($s['usRegistrationDate'] ?? ''),
            'filing_date' => (string)($s['filingDate'] ?? ''), 'mark' => trim((string)($s['markElement'] ?? '')), 'docket' => (string)($s['attrnyDktNumber'] ?? ''), 'dead' => is_dead_status($s['extStatusDesc'] ?? '', stripos((string)($s['tm5StatusDesc'] ?? ''), 'DEAD') === 0 ? 'Dead' : ''),   // tm5Status is a NUMBER (8 = registered); the word is in tm5StatusDesc ("DEAD/REGISTRATION/Cancelled/Invalidated")
            'is66a' => !empty($s['sect66aCurr']) || !empty($s['filed66a']),
            'flags' => ['sect8Acpt' => $s['sect8Acpt'] ?? null, 'sect15Ack' => $s['sect15Ack'] ?? null, 'renewalFiled' => $s['renewalFiled'] ?? null, 'sect8Filed' => $s['sect8Filed'] ?? null, 'sect71Acpt' => $s['sect71Acpt'] ?? null],
            'history' => $hist, 'link' => "https://tsdr.uspto.gov/#caseNumber=$serial&caseType=SERIAL_NO&searchType=statusSearch"];
}
/** A US trademark application's current filing basis: live from TSDR (it can be amended, e.g. 1(b) to 1(a)), else what the record holds. */
function tm_basis(array $m): string {
    static $cache = [];
    $sn = preg_replace('/\D/', '', (string)($m['app_no'] ?? ''));
    if (($m['kind'] ?? '') === 'trademark' && strlen($sn) === 8 && !empty($GLOBALS['cfg']['tsdr_key'])) {
        if (!array_key_exists($sn, $cache)) { try { $r = tsdr_lookup($sn); $cache[$sn] = $r['ok'] ? (string)($r['basis'] ?? '') : ''; } catch (Throwable $e) { $cache[$sn] = ''; } }
        if ($cache[$sn] !== '') return $cache[$sn];
    }
    return strtolower(preg_replace('/\s+/', '', (string)($m['basis'] ?? '')));
}
function odp_lookup(string $app): array {
    $r = http_json("https://api.uspto.gov/api/v1/patent/applications/$app", ['X-API-KEY: ' . $GLOBALS['cfg']['odp_key']]);
    if (!$r) return ['ok' => false, 'error' => 'No response from the Open Data Portal'];
    if ($r['code'] === 404 || empty($r['json']['patentFileWrapperDataBag'][0])) return ['ok' => false, 'unpublished' => true, 'error' => 'Not available from the Open Data Portal: unpublished applications (provisionals, designs, and utility filings inside 18 months) are only visible in Patent Center.', 'link' => "https://patentcenter.uspto.gov/applications/$app"];
    $w = $r['json']['patentFileWrapperDataBag'][0]; $m = $w['applicationMetaData'] ?? [];
    // Priority claims (2026-09-30): parents (provisional / continuation / CIP / divisional / PCT national stage), children, foreign.
    $pri = ['parents' => array_map(fn($x) => ['no' => (string)($x['parentApplicationNumberText'] ?? ''), 'date' => (string)($x['parentApplicationFilingDate'] ?? ''), 'type' => (string)($x['claimParentageTypeCode'] ?? ''), 'desc' => (string)($x['claimParentageTypeCodeDescriptionText'] ?? ''), 'status' => (string)($x['parentApplicationStatusDescriptionText'] ?? ''), 'patent' => (string)($x['parentPatentNumber'] ?? '')], $w['parentContinuityBag'] ?? []),
            'children' => array_map(fn($x) => ['no' => (string)($x['childApplicationNumberText'] ?? ''), 'date' => (string)($x['childApplicationFilingDate'] ?? ''), 'type' => (string)($x['claimParentageTypeCode'] ?? ''), 'desc' => (string)($x['claimParentageTypeCodeDescriptionText'] ?? ''), 'status' => (string)($x['childApplicationStatusDescriptionText'] ?? ''), 'patent' => (string)($x['childPatentNumber'] ?? '')], $w['childContinuityBag'] ?? []),
            'foreign' => array_map(fn($x) => ['no' => (string)($x['applicationNumberText'] ?? ''), 'date' => (string)($x['filingDate'] ?? ''), 'office' => (string)($x['ipOfficeName'] ?? '')], $w['foreignPriorityBag'] ?? [])];
    try { run("UPDATE matters SET priority_json=?, priority_at=datetime('now') WHERE kind='patent' AND replace(replace(replace(app_no,'/',''),',',''),' ','')=?", [json_encode($pri), preg_replace('/\D/', '', $app)]); } catch (Throwable $e) {}
    $hist = array_map(fn($e) => ['date' => (string)($e['eventDate'] ?? ''), 'code' => (string)($e['eventCode'] ?? ''), 'desc' => (string)($e['eventDescriptionText'] ?? '')], $w['eventDataBag'] ?? []);
    usort($hist, fn($a, $b) => strcmp($b['date'], $a['date']));
    $st = $m['applicationStatusDescriptionText'] ?? '';
    return ['ok' => true, 'source' => 'Open Data Portal', 'status' => $st, 'status_date' => (string)($m['applicationStatusDate'] ?? ''), 'reg_no' => (string)($m['patentNumber'] ?? ''), 'reg_date' => (string)($m['grantDate'] ?? ''),
            'filing_date' => (string)($m['filingDate'] ?? ''), 'docket' => (string)($m['docketNumber'] ?? ''), 'title' => (string)($m['inventionTitle'] ?? ''), 'entity' => (string)($m['entityStatusData']['businessEntityStatusCategory'] ?? ''),
            'dead' => is_dead_status($st), 'history' => $hist, 'link' => "https://patentcenter.uspto.gov/applications/$app",
            'parents' => $pri['parents'], 'pta' => (int)($w['patentTermAdjustmentData']['adjustmentTotalQuantity'] ?? 0), 'app_type' => (string)($m['applicationTypeCode'] ?? ''),
            'pub_date' => (string)($m['earliestPublicationDate'] ?? ''), 'pub_no' => (string)($m['earliestPublicationNumber'] ?? '')];   // the patent card (lib/ptcard.php)
}
/** Which open events the live record appears to satisfy. Returns [[event, evidence-string], ...]. Never closes anything itself.
 *  2026-10-01 (the attorney's queue review): a response only counts when it comes AFTER the latest Office action that set the deadline
 *  (XYZ019, XYZ003, RWG127 were matched to the response to the PREVIOUS action); an issue-fee deadline needs the issue fee, never
 *  the NOA itself (XYZ001); a maintenance fee needs a payment for the same year (4th/8th/12th); the Nth SOU extension needs extension N
 *  or a statement of use (XYZ002, XYZ014, XYZ005, XYZ079 were matched to the PREVIOUS extension). */
function check_matches(array $matter, array $live, array $open): array {
    $out = [];
    foreach ($open as $ev) {
        if (!empty($ev['rejected_at'])) continue;   // the attorney said "not done": do not propose it again
        $t = $ev['type']; $due = $ev['due_date']; $why = null;
        $hits = fn(string $re, string $lo, string $hi) => array_values(array_filter($live['history'], fn($h) => preg_match($re, $h['desc']) && !preg_match('/COURTESY REMINDER/i', $h['desc']) && $h['date'] >= $lo && $h['date'] <= $hi));
        // The action that set this deadline = the latest Office action on or before the due date (and not before the docketed mail date).
        $actionRe = $matter['kind'] === 'trademark'
            ? '/^(?!NOTIFICATION OF).*(NON-FINAL ACTION (E-)?MAILED|FINAL REFUSAL (E-)?MAILED|REFUSAL SENT TO IB|POST REGISTRATION ACTION MAILED|UNRESPONSIVE AMENDMENT - E-?MAILED|PRIORITY ACTION (E-)?MAILED)/i'
            : '/^Mail (Non-Final Rejection|Final Rejection|Restriction Requirement|Advisory Action|Ex Parte Quayle|Requirement for Information|Notice of Non-Compliant|Notice to File Missing)/i';
        $acts = $hits($actionRe, add_months($due, -8, false), $due);   // history is newest first
        $from = max((string)($ev['mail_date'] ?: ''), $acts ? $acts[0]['date'] : add_months($due, -7, false));
        $first = fn(array $h) => $h ? $h[0]['date'] . ' ' . $h[0]['desc'] : null;
        // An expired provisional cannot carry prosecution or maintenance deadlines: one found on its record belongs to the application
        // that claims it (same file number) and must not be closed because the provisional is gone (audit 2026-10-03: XYZ001, XYZ001).
        if ($live['dead'] && preg_match('/Provisional Application Expired/i', (string)$live['status']) && !preg_match('/^(Non-Provisional|Remind Client|Foreign Filing)/', $t)) continue;
        if ($live['dead']) { $why = 'USPTO status: ' . trim(explode('.', $live['status'])[0]) . '. Nothing further can fall due'; }
        elseif ($matter['kind'] === 'trademark') {
            if (preg_match('/^Respond to Office Action/', $t)) $why = $first($hits('/RESPONSE TO OFFICE ACTION|EXAMINERS AMENDMENT|EXAMINER.S AMENDMENT|APPROVED FOR PUB|NOTICE OF ALLOWANCE|PUBLISHED FOR OPPOSITION/i', $from, today()));
            elseif ($t === POSTREG_OA_TYPE) $why = $first($hits('/RESPONSE TO OFFICE ACTION-POST REG|POST REG.*RESPONSE|ACCEPTED|REGISTERED AND RENEWED/i', $from, today()));
            elseif (preg_match('/^Statement of Use or (\d)\w* Extension/', $t, $n)) {   // the Nth extension, or the SOU itself
                $why = $first($hits('/SOU EXTENSION ' . $n[1] . ' (FILED|GRANTED)|STATEMENT OF USE|USE AMENDMENT FILED|ALLEGATION OF USE|^REGISTERED/i', add_months($due, -6, false), today()));
            }
            elseif (preg_match('/^Statement of Use|^Publication - Opposition/', $t)) $why = $first(array_values(array_filter($hits('/EXTENSION|STATEMENT OF USE|USE AMENDMENT FILED|ALLEGATION OF USE|REGISTERED/i', add_months($due, -6, false), today()), fn($x) => !preg_match('/TO OPPOSE/i', $x['desc']))));
            elseif (preg_match('/^Section 8 and 15/', $t)) $why = ($live['flags']['sect8Acpt'] ?? false) && ($live['flags']['sect15Ack'] ?? false) ? 'TSDR: Section 8 accepted and Section 15 acknowledged' : $first($hits('/SEC\.? ?8.*(SEC\.? ?15|ACCEPTED)|SECTION 8 & 15/i', add_months($due, -18, false), today()));
            elseif (preg_match('/^Section 8 Declaration (Begins|Ends|Grace)/', $t)) $why = $first($hits('/SEC\.? ?8 \(6-YR\)|SECTION 8 RECEIVED/i', add_months($due, -18, false), today()));   // the 6-year Section 8 filed without Section 15 (audit 2026-10-02: this type was never proposed)
            elseif (preg_match('/^Section 8 Declaration and Section 9|^Section 8 and 9|^Renewal/', $t)) $why = $first($hits('/RENEWAL|SEC\.? ?9|SEC\.? ?8 \(10-YR\)|REGISTERED AND RENEWED/i', add_months($due, -14, false), today()));
            elseif (preg_match('/^Section 71/', $t)) $why = $first($hits('/SEC\.? ?71/i', add_months($due, -18, false), today()));
        } else {
            if (preg_match('/^Respond to Office Action - NOA/', $t)) $why = $first($hits('/Issue Fee Payment|Patent Issue Date|Recordation of Patent Grant/i', $from, today()));   // never the NOA itself
            elseif (preg_match('/^(Respond to Office Action|Final Response)/', $t)) $why = $first($hits('/Response after|Amendment|Reconsideration|Request for Continued Examination|Notice of Allowance|Response to Election|Response to Restriction|Applicant Response/i', $from, today()));
            elseif (preg_match('/^(\d+) 1\/2 Year Maintenance Fee/', $t, $y)) {   // 3 1/2 -> 4th year payment, 7 1/2 -> 8th, 11 1/2 -> 12th
                $why = $first($hits('/Payment of Maintenance Fee, ' . ((int)$y[1] + 1) . '(th|st|nd|rd) (Year|Yr)/i', add_months($due, -18, false), today()));
            }
            elseif (preg_match('/^Expected Issue Date/', $t) && $live['reg_no'] !== '') $why = 'Patent ' . $live['reg_no'] . ' granted ' . $live['reg_date'];
        }
        if ($why) $out[] = [$ev, $why];
    }
    return $out;
}
