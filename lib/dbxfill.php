<?php
declare(strict_types=1);
/**
 * Fill a record from its Dropbox folder (2026-10-15: "in 'create' record, can we enter just the file number and you'll find it in
 * DropBox and figure out the data to put in?"). Reads the matter folder named by the file number (lib/folders.php): the file names say
 * patent or trademark, the file-number suffix says the country (XYZ046.CA = Canada), and the key papers (registration certificate, filing
 * receipt / certificate, acknowledgement receipt, issued patent, application as filed) give the numbers and dates. A US serial number is
 * then completed from TSDR / ODP. Every value carries the file it came from; nothing is saved until the user presses Create / Apply.
 */
const DBXF_FIELDS = ['kind' => 'Kind', 'country' => 'Country', 'title' => 'Title / mark', 'app_no' => 'Application / serial no.', 'filing_date' => 'Filed',
                     'reg_no' => 'Registration / patent no.', 'grant_date' => 'Registered / issued', 'app_type' => 'Patent type'];
const DBXF_COUNTRIES = ['CA' => 'Canada', 'AU' => 'Australia', 'BR' => 'Brazil', 'CN' => 'China', 'JP' => 'Japan', 'KR' => 'Korea', 'IL' => 'Israel', 'IN' => 'India',
    'MX' => 'Mexico', 'GB' => 'United Kingdom', 'UK' => 'United Kingdom', 'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy', 'ES' => 'Spain', 'EP' => 'Europe (EPO)',
    'EPO' => 'Europe (EPO)', 'EU' => 'European Union', 'EM' => 'European Union', 'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colombia', 'NZ' => 'New Zealand',
    'SG' => 'Singapore', 'HK' => 'Hong Kong', 'TW' => 'Taiwan', 'ZA' => 'South Africa', 'RU' => 'Russia', 'UA' => 'Ukraine', 'TR' => 'Turkey', 'CH' => 'Switzerland',
    'PCT' => 'PCT', 'WO' => 'PCT', 'MADRID' => 'Madrid (WIPO)'];

/** Country from the file-number suffix (XYZ001.AU); United States when there is none (or it is a sub-docket like .1 / .DIV / .CIP). */
function dbxf_country(string $ref): string {
    foreach (array_reverse(explode('.', strtoupper(trim($ref)))) as $seg) if (isset(DBXF_COUNTRIES[$seg])) return DBXF_COUNTRIES[$seg];
    return 'United States';
}
/** Any date the papers write: 2021/06/29, 2021-06-29, 06/29/2021, June 29, 2021, 29 June 2021, bilingual "9 oct/Oct 2026", "25 févr/Feb 2025". */
function dbxf_date(string $s): string {
    $s = trim($s); $mon = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    $ok = fn(int $y, int $m, int $d) => checkdate($m, $d, $y) && $y > 1970 && $y < 2100 ? sprintf('%04d-%02d-%02d', $y, $m, $d) : '';
    if (preg_match('#^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})#', $s, $x)) return $ok((int)$x[1], (int)$x[2], (int)$x[3]);
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $s, $x)) return $ok((int)$x[3], (int)$x[1], (int)$x[2]);
    if (preg_match('#^(\d{1,2})\s+\S+/([A-Za-z]{3})\S*\s+(\d{4})#u', $s, $x) && isset($mon[strtolower($x[2])])) return $ok((int)$x[3], $mon[strtolower($x[2])], (int)$x[1]);
    if (preg_match('#^([A-Za-z]{3})[a-z]*\.?\s+(\d{1,2}),?\s+(\d{4})#', $s, $x) && isset($mon[strtolower($x[1])])) return $ok((int)$x[3], $mon[strtolower($x[1])], (int)$x[2]);
    if (preg_match('#^(\d{1,2})\s+([A-Za-z]{3})[a-z]*\.?,?\s+(\d{4})#', $s, $x) && isset($mon[strtolower($x[2])])) return $ok((int)$x[3], $mon[strtolower($x[2])], (int)$x[1]);
    return '';
}
const DBXF_DATE_RX = '(\d{4}[/\-.]\d{1,2}[/\-.]\d{1,2}|\d{1,2}/\d{1,2}/\d{4}|\d{1,2}\s+\S+/[A-Za-z]{3}\S*\s+\d{4}|[A-Z][a-z]{2,8}\.?\s+\d{1,2},?\s+\d{4}|\d{1,2}\s+[A-Z][a-z]{2,8}\.?,?\s+\d{4})';

/** Patent or trademark from the file names (and the papers' text). '' when it cannot tell. */
function dbxf_kind(array $names, string $text = ''): string {
    $all = implode("\n", $names) . "\n" . $text; $tm = 0; $pt = 0;
    $tm += preg_match_all('/trade-?mark|\bTMA ?\d|specimen|statement of use|\bSOU\b|\bTEAS|madrid|marque|\bclass(es)? \d|registration certificate|certificate of registration|declaration of use|section [89]\b/i', $all);
    $pt += preg_match_all('/patent|claims|specification|drawings|figures|AckReceipt|maint(enance)?\.? fee|annuit|\bIDS\b|prior art|inventor|provisional|national (entry|stage)|\bPCT\b|invention|examination|\bdesign\b/i', $all);
    return $tm === $pt ? '' : ($tm > $pt ? 'trademark' : 'patent');
}
/** Which papers to read, best first (at most $max). */
function dbxf_pick(array $files, int $max = 4): array {
    $rank = function (string $n): int {
        foreach (['/registration certificate|certificate of registration|letters patent|issued (design )?patent|grant/i' => 1, '/filing (receipt|certificate)|AckReceipt|acknowledg|national entry/i' => 2,
                  '/application as filed|as-filed|application-as-filed/i' => 3, '/notice of allowance|allowance|issue notification/i' => 4, '/office action/i' => 6] as $rx => $r) if (preg_match($rx, $n)) return $r;
        return 99;
    };
    $c = array_values(array_filter($files, fn($f) => preg_match('/\.(pdf|docx)$/i', $f['name']) && $rank($f['name']) < 99));
    usort($c, fn($a, $b) => [$rank($a['name']), -$a['mtime']] <=> [$rank($b['name']), -$b['mtime']]);
    return array_slice($c, 0, $max);
}
/** Numbers and dates written in one paper's text. Keys as DBXF_FIELDS (only those found). */
function dbxf_extract(string $t): array {
    $f = preg_replace('/[ \t]+/', ' ', $t); $r = [];
    $date = function (string $label) use ($f): string { return preg_match('#' . $label . '[^0-9A-Za-z\n]{0,40}(?:\n[^\n]{0,60}?)?' . DBXF_DATE_RX . '#iu', $f, $x) ? dbxf_date($x[1]) : ''; };
    // Canadian trademark certificate (extract page): APPL'N/DEM. NO 2 382 072 ... REGISTRATION/ENREGISTREMENT NO TMA1,453,356
    if (preg_match("#APPL'?N\.?/DEM\.? NO\.?\s*([\d ]{7,11})#i", $f, $x)) $r['app_no'] = preg_replace('/\D/', '', $x[1]);
    if (preg_match('#REGISTRATION/ENREGISTREMENT NO\.?\s*(TMA ?[\d,]{5,9})#i', $f, $x) || preg_match('#\b(TMA ?[\d,]{6,9})\b#', $f, $x)) $r['reg_no'] = 'TMA' . preg_replace('/\D/', '', $x[1]);
    if (($d = $date("REGISTRATION DATE/DATE D'ENREGISTREMENT:?")) !== '') $r['grant_date'] = $d;
    if (($d = $date('FILING DATE/DATE DE PRODUCTION:?')) !== '') $r['filing_date'] = $d;
    if (preg_match('#\n\s*([^\n]{2,80}?)\s*\n\s*Registraire des marques#u', $t, $x)) $r['title'] = trim($x[1]);
    // generic labels (Canadian patent filing certificate, USPTO papers, associates' reports)
    if (!isset($r['app_no']) && preg_match('~(?:Application|Serial)\s*(?:N[o°º]\.?|Number|#)\s*:?\s*((?:\d{2}/)?[\d,. ]{6,12}\d)~iu', $f, $x)) { $n = preg_replace('/\D/', '', $x[1]); if (strlen($n) >= 6) $r['app_no'] = $n; }
    if (!isset($r['filing_date']) && ($d = $date('(?:Filing Date|Date of Filing|Filed)\s*:?')) !== '') $r['filing_date'] = $d;
    if (!isset($r['reg_no']) && preg_match('#(?:Registration|Patent|Design)\s*(?:N[o°º]\.?|Number)\s*:?\s*([A-Z]{0,3}\s?[\d,]{5,10})#u', $f, $x)) { $n = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $x[1])); if (strlen(preg_replace('/\D/', '', $n)) >= 5) $r['reg_no'] = $n; }
    if (!isset($r['grant_date']) && ($d = $date('(?:Registration Date|Date of Registration|Date of Patent|Issue Date|Grant Date|Date of Grant|Registered on|Issued on)\s*:?')) !== '') $r['grant_date'] = $d;
    if (!isset($r['title']) && preg_match('#Title of (?:the )?invention\s*:?\s*([^\n]{6,200})#i', $f, $x)) $r['title'] = trim(preg_replace('/\s*\.{3}$/', '', $x[1]), " \t;:,.-");
    return $r;
}
/** The guess for a file number: ['found' => bool, 'folder' => name, 'fields' => [key => [value, source]], 'note' => text]. */
function dbx_guess(string $ref, int $maxDocs = 4): array {
    $ref = strtoupper(trim($ref)); $out = ['found' => false, 'folder' => '', 'fields' => [], 'note' => ''];
    if (dbx_forbidden_ref($ref)) { $out['note'] = 'That folder is off limits.'; return $out; }
    $f = mf_find($ref); if (!$f) { $out['note'] = 'No Dropbox folder named ' . $ref . '.'; return $out; }
    $out['found'] = true; $out['folder'] = $f['name']; $files = mf_list($f, true); $names = array_column($files, 'name');
    $set = function (string $k, string $v, string $src) use (&$out) { if (trim($v) !== '' && !isset($out['fields'][$k])) $out['fields'][$k] = [trim($v), $src]; };
    $set('country', dbxf_country($ref), 'file number');
    // the related US / parent record (XYZ046.CA -> XYZ046): same title, and usually the same kind
    $base = preg_replace('/\..*$/', '', $ref);
    $sib = $base !== $ref ? q1('SELECT kind, title FROM matters WHERE ref=? ORDER BY dead, id DESC LIMIT 1', [$base]) : null;
    $texts = '';
    foreach (dbxf_pick($files, $maxDocs) as $doc) {
        $p = mf_local($f, $doc['rel']); if ($p === '') continue;
        if (preg_match('/\.pdf$/i', $p) && dbxf_country($ref) === 'United States') {   // USPTO receipts: the filing-report parser knows them
            $j = filing_parse($p);
            if (($j['kind'] ?? '') === 'ack') { $set('kind', 'patent', $doc['name']); foreach (['app_no' => 'app_no', 'date' => 'filing_date', 'title' => 'title'] as $a => $b) $set($b, (string)($j[$a] ?? ''), $doc['name']); if (!empty($j['app_type'])) $set('app_type', filing_app_kind((string)$j['app_type'], (string)($j['app_no'] ?? '')), $doc['name']); }
            if (($j['kind'] ?? '') === 'tm_receipt') { $set('kind', 'trademark', $doc['name']); $set('app_no', (string)($j['serial'] ?? ''), $doc['name']); $set('filing_date', (string)($j['date'] ?? ''), $doc['name']); $set('title', (string)($j['mark'] ?? ''), $doc['name']); }
        }
        if (preg_match('/\.docx$/i', $p) && !class_exists('ZipArchive')) continue;
        $t = doc_text($p, 2); $texts .= "\n" . $t;
        $got = dbxf_extract($t);
        // a scanned CIPO filing certificate: its value boxes are shaded, so plain OCR loses them; a sparse-text pass reads the values in order
        // (certificate date, application no., reference, filing date) — the filing date is the first date after the application number
        if (!isset($got['app_no']) && preg_match('/\.pdf$/i', $p) && stripos($t, 'Canadian Intellectual Property') !== false && strlen(trim(pdf_text($p, 1, 1))) < 200) $got += dbxf_cipo_certificate(dbxf_ocr_sparse($p));
        foreach ($got as $k => $v) $set($k, (string)$v, $doc['name']);
    }
    $kind = dbxf_kind($names, $texts) ?: (string)($sib['kind'] ?? '');
    if ($kind !== '') $set('kind', $kind, $sib && dbxf_kind($names, $texts) === '' ? 'record ' . $base : 'file names');
    if ($sib && trim((string)$sib['title']) !== '') $set('title', (string)$sib['title'], 'record ' . $base);
    if (!isset($out['fields']['title']) && preg_match('/^\S+\s+\((.+)\)$/', $f['name'], $x)) $set('title', $x[1], 'folder name');
    // a US number: the USPTO record is the authority
    $k = $out['fields']['kind'][0] ?? ''; $app = $out['fields']['app_no'][0] ?? '';
    if (dbxf_country($ref) === 'United States' && strlen($app) === 8) {
        try { $live = $k === 'trademark' ? tsdr_lookup($app) : ($k === 'patent' ? odp_lookup($app) : ['ok' => false]); } catch (Throwable $e) { $live = ['ok' => false]; }
        if (!empty($live['ok'])) { $src = $k === 'trademark' ? 'TSDR' : 'USPTO (ODP)';
            foreach (['title' => 'title', 'filing_date' => 'filing_date', 'reg_no' => 'reg_no', 'reg_date' => 'grant_date'] as $a => $b) if (trim((string)($live[$a] ?? '')) !== '') $out['fields'][$b] = [substr((string)$live[$a], 0, $a === 'title' ? 300 : 10), $src]; }
    }
    if (!$out['fields'] || count($out['fields']) <= 1) $out['note'] = 'Folder ' . $f['name'] . ' found, but its papers did not give the numbers; fill them in by hand.';
    return $out;
}
/** Page 1 OCR in tesseract's sparse-text mode (finds words in shaded boxes that the default layout analysis skips). */
function dbxf_ocr_sparse(string $path): string {
    $tmp = sys_get_temp_dir() . '/ocrs-' . getmypid() . '-' . bin2hex(random_bytes(4)); @mkdir($tmp, 0700, true); $t = '';
    // Ghostscript renders on both machines (pair's pdftoppm is broken: a missing poppler library); tesseract 3 spells it -psm, 4+ --psm
    exec('nice -n 15 gs -q -dSAFER -dNOPAUSE -dBATCH -sDEVICE=pgmraw -r300 -dFirstPage=1 -dLastPage=1 -o ' . escapeshellarg("$tmp/p1.pgm") . ' ' . escapeshellarg($path) . ' 2>/dev/null');
    $psm = preg_match('/tesseract 3\./', (string)shell_exec('tesseract --version 2>&1')) ? '-psm 11' : '--psm 11';
    foreach (glob("$tmp/p*") ?: [] as $pg) { $t .= (string)shell_exec('nice -n 15 tesseract ' . escapeshellarg($pg) . ' stdout ' . $psm . ' 2>/dev/null'); @unlink($pg); }
    @rmdir($tmp); return $t;
}
/** Application no. (3,123,542) and filing date (the first yyyy/mm/dd after it) off a CIPO filing certificate's sparse OCR. */
function dbxf_cipo_certificate(string $t): array {
    if (!preg_match('/\b(\d ?, ?\d ?\d ?\d ?, ?\d ?\d ?\d)\b/', $t, $x, PREG_OFFSET_CAPTURE)) return [];   // tesseract 3 (pair) reads "3,1 23,542"
    $r = ['app_no' => preg_replace('/\D/', '', $x[1][0])];
    if (preg_match('#\b(\d{4}/\d{2}/\d{2})\b#', substr($t, $x[1][1]), $y) && ($d = dbxf_date($y[1])) !== '') $r['filing_date'] = $d;
    return $r;
}
function dbx_forbidden_ref(string $ref): bool { return function_exists('dbx_forbidden') && dbx_forbidden($ref); }
/** For an existing record: the guessed values that are missing or differ, [key => [current, new, source]]. */
function dbx_fill_diff(array $m, array $g): array {
    $d = [];
    foreach ($g['fields'] as $k => [$v, $src]) {
        if (in_array($k, ['kind', 'country'], true)) continue;
        $cur = trim((string)($m[$k] ?? '')); if (in_array($k, ['filing_date', 'grant_date'], true)) $cur = substr($cur, 0, 10);
        $norm = fn($s) => strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string)$s));
        if ($k === 'title' ? $cur === '' : $norm($cur) !== $norm($v)) $d[$k] = [$cur, $v, $src];
    }
    return $d;
}
