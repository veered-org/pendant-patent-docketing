<?php
declare(strict_types=1);
/**
 * Office-action saving helpers ("saving" = downloading from the USPTO into the matter folder; "filing" means sending papers TO the USPTO - 2026-09-26) (pure functions; the laptop runner is bin/oa_fetch.php).
 * 2026-09-26: when a patent or trademark Office Action comes in, download it (PDF and DOCX when both exist)
 * into the matter folder together with the references cited.
 */
const OA_PATENT_CODES = ['NOA', 'ISSUE.NTF', 'CTNF', 'CTFR', 'CTRS', 'CTEQ', 'CTAV', 'NTC.A.NONCPL', 'NTC.MISS.PRT', 'NTC.PRT.CRR', 'SEQ.REQ.DISC', 'NT.INC.REPLY', 'NRES', 'PIER.RFI', 'PETDEC', 'PET.DEC.OIPE', 'PET.DEC.PUBS', 'PEFN'];
const OA_TM_CODES = ['OFFICE ACTION', 'NOTICE OF ALLOWANCE', 'POSTREG OFFICE ACTION', 'NOTICE OF ACCEPTANCE', 'NOTICE OF PUBLICATION', 'REGISTRATION'];   // acceptances saved too (2026-10-03)   // NOA saved for the client report (2026-09-29)
const OA_RECEIPT_CODES = ['FILING RECEIPT', 'MAINT FEE PAID', 'ASSIGNMENT RECORDED', 'ASSIGNMENT FILED'];   // filed papers / fee receipts saved into the folder (lib/held.php, 2026-10-03)
/** The matter folder for a file number: exactly REF, or "REF (anything)" (e.g. "XYZ016 (P25JM3NW00053US)"); a sub-docket like SBS003.1 falls back to the parent folder SBS003. '' when none. */
function find_matter_dir(string $root, string $ref): string {
    foreach ([$ref, preg_replace('/\.\d+$/', '', $ref)] as $r) {
        if ($r === '' ) continue;
        if (is_dir("$root/$r")) return "$root/$r";
        foreach (glob("$root/" . str_replace(['[', ']'], ['\\[', '\\]'], $r) . ' (*') ?: [] as $d) if (is_dir($d)) return $d;
    }
    return '';
}
/** Next docket code in a matter folder: NN + letter, 01z -> 02a (see docket-letter-sequence). '' when the folder does not use codes. */
function next_doc_code(array $filenames, string $ref): string {
    $best = null;
    // RWG166.001.01 (2026-10-06): older files spelled the ref "RWG166-001.01-01e-ADS"; those codes count too (same ref_key), or a code is reused
    $same = fn(string $p) => strcasecmp($p, $ref) === 0 || (function_exists('ref_key') && ref_key(str_replace('-', '.', $p)) === ref_key(str_replace('-', '.', $ref)));
    foreach ($filenames as $f) if (preg_match('/^' . preg_quote($ref, '/') . '-(\d{2})([a-z])/i', $f, $m) || (preg_match('/^(.+?)-(\d{2})([a-z])-/i', $f, $mm) && $same($mm[1]) && ($m = [$mm[0], $mm[2], $mm[3]]))) { $k = (int)$m[1] * 100 + (ord(strtolower($m[2])) - 96); if ($best === null || $k > $best) $best = $k; }
    if ($best === null) return '';
    $n = intdiv($best, 100); $l = $best % 100;
    if ($l >= 26) { $n++; $l = 1; } else $l++;
    return sprintf('%02d%s', $n, chr(96 + $l));
}
/**
 * Trademark folders use the docket codes REF-NNx- (2026-10-03): "increment the number when it's a new stage - filing, examination,
 * allowance, issuance, renewals". A paper of the same stage as the newest code group gets the next letter; a new stage starts the next
 * number at "a" (XYZ001: 01d issuance letter, then 02a-02c the 2017 Section 8 & 15). Each renewal cycle is its own stage (a group last
 * touched more than two years earlier is a past cycle). A folder with no codes yet starts at 01a.
 */
function tm_stage(string $s): ?string {
    if (preg_match('/assignment|recordation|cover sheet|change of owner|AckReceipt-Recordation/i', $s)) return 'assignment';   // an ownership change is its own group (XYZ001-03a, XYZ003-03d/e)
    if (preg_match('/section.? ?(8|9|15|71)\b|sections 8|S0?8N|8 (&|and) (9|15)|renewal|incontestab|post.?reg|declaration of use|affidavit|notice of acceptance and renewal/i', $s)) return 'renewal';
    if (preg_match('/notice of allowance|\bNOA\b|statement of use|allegation of use|\bSOU\b|\bESU\b|extension.{0,30}statement/i', $s)) return 'allowance';
    if (preg_match('/office action|respon|\bROA\b|suspen|examiner|amendment|publication|extension of time|letter of protest|refusal|petition/i', $s)) return 'examination';
    if (preg_match('/registration|certificate|issuance/i', $s)) return 'issuance';
    if (preg_match('/application|filing|TEAS|drawing|search|clearance/i', $s)) return 'filing';   // not "specimen": every stage has one
    return null;
}
/** Next REF-NNx- code for a trademark paper of $stage dated $date. $files: [['name', 'mtime'], ...] (mf_list). */
function tm_next_code(array $files, string $ref, string $stage, string $date): string {
    // 2026-10-03: "nothing less than 03 for renewals even if prior numbers don't exist" - each stage has a floor.
    $floor = ['filing' => 1, 'examination' => 2, 'allowance' => 3, 'issuance' => 3, 'renewal' => 3][$stage] ?? 1;
    $groups = [];
    foreach ($files as $f) if (preg_match('/^' . preg_quote($ref, '/') . '-(\d{2})([a-z])/i', (string)$f['name'], $m)) { $groups[(int)$m[1]][] = $f; }
    if (!$groups) return sprintf('%02da', $floor);
    $nn = max(array_keys($groups)); $votes = []; $newest = 0;
    foreach ($groups[$nn] as $f) { if (($st = tm_stage((string)$f['name'])) !== null) $votes[$st] = ($votes[$st] ?? 0) + 1; $newest = max($newest, (int)($f['mtime'] ?? 0)); }
    arsort($votes); $gStage = $votes ? (string)array_key_first($votes) : null;
    $same = $gStage === $stage && ($stage !== 'renewal' || strtotime($date) - $newest < 2 * 365 * 86400);
    if ($same) { $code = next_doc_code(array_column($groups[$nn], 'name'), $ref); if ((int)substr($code, 0, 2) === $nn) return $code; }   // past "z": a new number after all
    return sprintf('%02da', max($nn + 1, $floor));
}
/** Patent folders (2026-10-04, RWG127.003.03): a Notice of Allowance starts a new stage - the next NUMBER at "a" (01e response -> 02a NOA);
 *  everything else takes the next letter. The PTO-892 saved with the allowance then follows it inside the new group (02b). */
function patent_next_code(array $filenames, string $ref, string $label): string {
    // 2026-10-09: maintenance fees start a new number too (XYZ001: 03 issuance, 04a 4th-year fee, 04b 8th-year fee - later fees
    // continue the maintenance group while it is the newest one). A folder without codes stays without them.
    if (preg_match('/maint(enance)?[\s_.-]*fee/i', $label)) {
        $nn = 0; $grp = []; foreach ($filenames as $f) if (preg_match('/^' . preg_quote($ref, '/') . '-(\d{2})[a-z]/i', $f, $m)) { $nn = max($nn, (int)$m[1]); $grp[(int)$m[1]][] = $f; }
        if (!$nn) return '';
        $mf = array_filter($grp[$nn], fn($f) => preg_match('/maint(enance)?[\s_.-]*fee/i', $f));
        if ($mf && count($mf) === count($grp[$nn])) { $c = next_doc_code($grp[$nn], $ref); if ((int)substr($c, 0, 2) === $nn) return $c; }
        return sprintf('%02da', $nn + 1);
    }
    if (stripos($label, 'Notice of Allowance') === false) return next_doc_code($filenames, $ref);
    $nn = 0; foreach ($filenames as $f) if (preg_match('/^' . preg_quote($ref, '/') . '-(\d{2})[a-z]/i', $f, $m)) $nn = max($nn, (int)$m[1]);
    return sprintf('%02da', $nn + 1);
}
/** "Office Action of 2026.09.22" — the firm's own wording (XYZ003-02k-Office Action of 2026.09.22.PDF). */
/** Registration certificate file name (2026-10-04): no date; the registration number and the literal element of the mark. "XYZ002-03a-Registration Certificate US 8,432,845 LEGENDARY WARES" */
function tm_cert_basename(string $ref, string $code, string $regNo, string $title): string {
    $t = trim(preg_replace('/\s+/', ' ', preg_replace('#[\\/:*?"<>|]+#', ' ', function_exists('clean_title') ? clean_title($title) : $title)));
    if (strlen($t) > 60 && preg_match('/^.{1,60}/u', $t, $cut)) $t = rtrim($cut[0]) . '…';
    $rn = preg_replace('/\D/', '', $regNo);
    return $ref . '-' . ($code !== '' ? $code . '-' : '') . 'Registration Certificate' . ($rn !== '' ? ' US ' . number_format((int)$rn) : '') . ($t !== '' ? ' ' . $t : '');
}
function oa_basename(string $ref, string $code, string $label, string $isoDate): string {
    return $ref . '-' . ($code !== '' ? $code . '-' : '') . $label . ' of ' . str_replace('-', '.', $isoDate);
}
/** Does the folder already hold this office action (saved by hand)? Matches "... Office Action of 2026.09.22 ..." case-insensitively. */
function oa_already_saved(array $filenames, string $isoDate, string $label = 'Office Action'): bool {
    $d = str_replace('-', '.', $isoDate);
    if ($label !== 'Office Action') { foreach ($filenames as $f) if (stripos($f, $label) !== false && str_contains($f, $d)) return true; return false; }
    foreach ($filenames as $f) if (preg_match('/[0o]ffice action.*' . preg_quote($d, '/') . '/i', $f) || preg_match('/' . preg_quote($d, '/') . '.*[0o]ffice action/i', $f)) return true;
    return false;
}
/** US patent / publication numbers on a PTO-892 (OCR text): US-10584023-B2, US 2020/0123456 A1, US-20200123456-A1 -> ppubs numbers. */
function parse_892_refs(string $text): array {
    $out = [];
    if (preg_match_all('/US[\s\-]?(\d{4})[\/\-]?(\d{7})[\s\-]?A\d/i', $text, $m, PREG_SET_ORDER)) foreach ($m as $x) $out[$x[1] . $x[2]] = ['num' => $x[1] . $x[2], 'kind' => 'publication'];
    if (preg_match_all('/US[\s\-]?(\d{7,8})[\s\-]?([AB]\d|[A-Z]\d?)?\b/i', $text, $m, PREG_SET_ORDER)) foreach ($m as $x) { $n = ltrim($x[1], '0'); if (strlen($n) >= 11) continue; if (preg_match('/^\d{4}\d{7}$/', $x[1])) continue; $out[$n] = ['num' => $n, 'kind' => 'patent']; }
    // OCR often drops the hyphen and glues the kind code: "US10584023B2" is caught above; "10584023" alone in the number column is not (too risky)
    return array_values($out);
}
/** TSDR document that is the trademark Notice of Allowance. */
function tsdr_is_noa(string $typeCode, string $desc): bool { return strtoupper($typeCode) === 'NOA' || (bool)preg_match('/notice of allowance/i', $desc); }
/** TSDR document types that are office actions (outgoing examiner papers with a response deadline). */
function tsdr_is_office_action(string $typeCode, string $desc): bool {
    if (in_array(strtoupper($typeCode), ['NFIN', 'FREF', 'PRIO', 'SUSP', 'OOA', 'NFOA', 'FOA', 'PROARR'], true)) return true;   // PROARR = "PostReg Office Action - Response Required"
    return (bool)preg_match('/office action|final action|non-final action|priority action|suspension/i', $desc) && !preg_match('/^notice of|response/i', $desc);
}
/** 2026-09-28: the filing date comes from the USPTO acknowledgement receipt saved in the matter folder
 *  (REF-NNx-AckReceipt-Filing.pdf and its variants). True for a receipt of the APPLICATION itself: fee, IDS, recordation etc. receipts are excluded by name. */
function is_filing_receipt_name(string $name): bool {
    return (bool)preg_match('/ack.{0,3}receipt|receipt.{0,3}ack|filing.?ack/i', $name) && (bool)preg_match('/filing|provisional/i', $name)
        && !preg_match('/fee|IDS|recordation|assignment|POA|response|amend|RCE|petition|declaration|correct|drawing|extension/i', $name) && preg_match('/\.pdf$/i', $name);
}
/** Parse `pdftotext -layout` page 1 of a receipt: ['app_no' => digits, 'date' => ISO, 'type' => Provisional|Utility|Design|National Stage|PCT] or null.
 *  Two layouts: EFS-Web ("Application Number:  14664046" / "Receipt Date:  20-MAR-2015") and Patent Center (header row, values on the next line). */
function parse_ack_receipt(string $t): ?array {
    $app = $date = $type = '';
    if (preg_match('/Application Number:\s+([\d\/,]{8,})/', $t, $m)) $app = $m[1];
    if (preg_match('/Receipt Date:\s+(\d{2})-([A-Z]{3})-(\d{4})/i', $t, $m)) { $mo = array_search(strtoupper($m[2]), ['', 'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC']); if ($mo) $date = sprintf('%04d-%02d-%02d', $m[3], $mo, $m[1]); }
    if (preg_match('/APPLICATION #\s+RECEIPT DATE[^\n]*\n\s*([\d\/,]{8,})\s+(\d{2})\/(\d{2})\/(\d{4})/', $t, $m)) { $app = $m[1]; $date = "$m[4]-$m[2]-$m[3]"; }
    if (preg_match('/Application Type:?\s+([^\n]+)/i', $t, $m)) $type = trim($m[1]);
    $digits = preg_replace('/\D/', '', $app);
    if (strlen($digits) < 8 || !$date || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) return null;
    $kind = preg_match('/371|national stage/i', $type) ? 'National Stage' : (preg_match('/\bPCT\b|international/i', $type) ? 'PCT'
          : (preg_match('/\bprovisional\b/i', $type) || str_starts_with($digits, '6') ? 'Provisional' : (preg_match('/design/i', $type) || preg_match('/^(29|30)\d{6}$/', $digits) ? 'Design' : 'Utility')));
    $title = preg_match('/Title of Invention:?[ \t]*\n?[ \t]*([^\n]+)/i', $t, $m) ? trim(preg_replace('/\s+/', ' ', $m[1])) : '';   // 2026-10-06 (RWG166.001.01)
    return ['app_no' => $digits, 'date' => $date, 'type' => $kind, 'title' => $title];
}
/** From `pdftotext -layout` of an Application Data Sheet: true when it shows NO domestic benefit and NO foreign priority claims,
 *  false when it shows any, null when the counts are not printed (older or hand-made ADS). */
function ads_claims_no_priority(string $t): ?bool {
    if (!preg_match('/# of benefit claims:\s*(\d+)/i', $t, $b) || !preg_match('/# of foreign priority claims:\s*(\d+)/i', $t, $f)) return null;
    return (int)$b[1] === 0 && (int)$f[1] === 0;
}
/** Earliest priority/benefit date an ADS claims (ISO), from the Domestic Benefit and Foreign Priority sections; null when none is listed.
 *  The ADS lists every link of a continuation chain, so the earliest date found is the one a foreign filing deadline runs from. */
function ads_earliest_priority(string $t): ?string {
    $a = stripos($t, 'Domestic Benefit/National Stage Information'); if ($a === false) return null;
    $b = stripos($t, 'Statement under 37 CFR 1.55', $a); $sec = substr($t, $a, $b === false ? 20000 : $b - $a);
    preg_match_all('/\b(\d{2})\/(\d{2})\/(\d{4})\b/', $sec, $m, PREG_SET_ORDER); $best = null;
    foreach ($m as $d) if (checkdate((int)$d[1], (int)$d[2], (int)$d[3])) { $iso = "$d[3]-$d[1]-$d[2]"; if (!$best || $iso < $best) $best = $iso; }
    return $best;
}

/** Reply period stated in a USPTO paper (text of its first pages): ['months' => n] or ['days' => n], null when none is stated.
 *  "SHORTENED STATUTORY PERIOD FOR REPLY IS SET TO EXPIRE 2 MONTH(S)", "is given TWO MONTHS from the mailing date", "within TWO (2) MONTHS",
 *  "ONE MONTH or THIRTY DAYS, whichever is longer" (-> the longer). Words or digits; OCR noise tolerated (MONTH(S), 0 for O). */
function parse_reply_period(string $text): ?array {
    $t = preg_replace('/\s+/', ' ', $text); $num = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5, 'SIX' => 6, 'THIRTY' => 30, 'FORTY-FIVE' => 45, 'FORTY FIVE' => 45, 'SIXTY' => 60];
    $n = '(ONE|TWO|THREE|FOUR|FIVE|SIX|THIRTY|FORTY[- ]FIVE|SIXTY|\d{1,2})(?:\s*\(\d{1,2}\))?';
    $ctx = '(?:period for (?:reply|response)|set to expire|is given|are given|has|have|within|must be (?:filed|submitted)|reply|respond|response|to file|to submit|to correct|to comply)';
    if (!preg_match_all('/' . $ctx . '[^.]{0,160}?\b' . $n . '\s*(MONTH|DAY)\(?S?\)?/i', $t, $mm, PREG_SET_ORDER)) return null;
    // Prefer a sentence about THIS paper ("of this decision/notice/communication"); a decision often recites an EARLIER notice's period
    // ("The Notice set a period for reply of two (2) months"). A granted petition with no own period carries no deadline.
    $own = array_values(array_filter($mm, fn($x) => preg_match('/\bthis (decision|notice|communication|letter|action|requirement|office action)\b/i', substr($t, (int)strpos($t, $x[0]), strlen($x[0]) + 90))));
    if (!$own && preg_match('/\b(is|are|was|hereby)\s+GRANTED\b/', $t) && !preg_match('/\b(DISMISSED|DENIED)\b/', $t)) return null;
    $m = $own[0] ?? $mm[0]; $v = ctype_digit($m[1]) ? (int)$m[1] : $num[strtoupper(str_replace(' ', '-', $m[1]))] ?? ($num[strtoupper($m[1])] ?? 0); if ($v <= 0) return null;
    $unit = strtoupper($m[2]) === 'MONTH' ? 'months' : 'days';
    // "ONE MONTH or THIRTY DAYS, whichever is longer"
    if ($unit === 'months' && preg_match('/' . preg_quote($m[0], '/') . '\s*(?:\([^)]*\))?\s*or\s*' . $n . '\s*DAYS?[^.]{0,40}whichever is longer/i', $t, $w)) { $d = ctype_digit($w[1]) ? (int)$w[1] : ($num[strtoupper($w[1])] ?? 0); if ($d > $v * 30) return ['days' => $d]; }
    if ($unit === 'months' && $v > 6) return null;
    return [$unit => $v];
}
