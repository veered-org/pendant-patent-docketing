<?php
/**
 * SERVER job (hourly; was a laptop job until 2026-10-01): save incoming Office Actions into the matter's Dropbox folder, with the references cited.
 *   php bin/oa_fetch.php [--days=14] [--dry] [--only=REF] [--dest=DIR] [--force] [--doc=REF:patent|trademark:APPNO:CODE:YYYY-MM-DD]
 * Correspondence rows whose doc code is an office action and that are not saved yet are downloaded from the USPTO (Open Data Portal for
 * published patents: PDF + DOCX + PTO-892 + each cited US patent/publication; TSDR for trademarks: the action PDF, which carries the cited
 * registrations, plus its evidence attachments) and written into /matters/<REF>/ through the Dropbox API in that folder's own naming style
 * (the laptop's Dropbox client syncs them down). The result is recorded in oa_files so the record page shows what was saved.
 * Cited patents come from Google Patents: image-ppubs.uspto.gov refuses the pair host's address. Unpublished applications are flagged:
 * only Patent Center can serve those. --dest=DIR writes into a scratch folder instead (testing).
 */
declare(strict_types=1);
$GLOBALS['cfg'] = $cfg = require dirname(__DIR__) . '/config.php';
$opt = getopt('', ['wanted', 'days::', 'dry', 'only::', 'dest::', 'force', 'doc::', 'summary::', 'reg::']); $days = (int)($opt['days'] ?? 14) ?: 14; $dry = isset($opt['dry']); $only = $opt['only'] ?? null; $TEST = isset($opt['dest']);
if ($TEST) { $GLOBALS['cfg']['folders_local'] = rtrim($opt['dest'], '/'); @mkdir($GLOBALS['cfg']['folders_local'], 0755, true); }
foreach (['db', 'dates', 'rules', 'oafetch', 'dropbox', 'folders', 'receipts', 'held', 'gmail_pull'] as $f) require_once dirname(__DIR__) . "/lib/$f.php";
if (folders_mode() === 'none') exit("No matter folders: Dropbox is not connected (Advanced > Dropbox); nothing to do\n");

function save(array $dir, string $rel, string $bytes, bool $dry): ?string {
    if ($dry) { echo "      would write $rel (" . number_format(strlen($bytes) / 1024) . " KB)\n"; return $rel; }
    if (is_pdf($bytes)) $bytes = optimize_pdf($bytes, basename($rel));
    try { return mf_write($dir, $rel, $bytes); } catch (Throwable $e) { echo "      WRITE FAILED $rel: " . $e->getMessage() . "\n"; return null; }
}
// Lossless shrink before the file reaches Dropbox (G4 scans -> JBIG2 generic, kept only if smaller AND pixel-identical on every page).
// The server: bin/pdf_shrink.py with jbig2enc built in ~/opt/jbig2 (2026-10-01). The laptop: its pdf-shrink venv. Any failure = original bytes.
function optimize_pdf(string $bytes, string $name): string {
    $home = (string)(getenv('HOME') ?: ''); $jb = $home . '/opt/jbig2/bin/jbig2';
    if (is_file($jb)) $cmd = 'JBIG2=' . escapeshellarg($jb) . ' python3 ' . escapeshellarg(__DIR__ . '/pdf_shrink.py');
    elseif (is_file($py = (string)($GLOBALS['cfg']['pdf_shrink_python'] ?? ''))) $cmd = escapeshellarg($py) . ' ' . escapeshellarg(__DIR__ . '/pdf_optimize.py');
    else return $bytes;
    $tmp = tempnam(sys_get_temp_dir(), 'oaopt') . '.pdf'; file_put_contents($tmp, $bytes); $out = (string)shell_exec('nice -n 15 ' . $cmd . ' ' . escapeshellarg($tmp) . ' 2>/dev/null');
    $new = @file_get_contents($tmp); @unlink($tmp);
    if (str_contains($out, ' OK ') && $new !== false && is_pdf($new) && strlen($new) < strlen($bytes)) { echo "      optimized $name: " . number_format(strlen($bytes) / 1024) . ' -> ' . number_format(strlen($new) / 1024) . " KB\n"; return $new; }
    return $bytes;
}
/**
 * A filing receipt (lib/held.php): the filed papers go into the folder under the name receipts.php reads, and the deadline they satisfy is
 * proposed done at once (the Confirm queue's "Receipts in the file", with the file one click away).
 *   trademark: the filed form as TSDR renders it (form + specimen), e.g. S08N09-5053328.pdf, ROA-99671913.pdf
 *   patent maintenance fee: the Fee Storefront's itemized receipt (the e-mail's PDF attachment), e.g. "REF-Maintenance Fee Year 7.5 Payment Receipt.pdf"
 * Returns [correspondence id, status, files, note].
 */
function save_receipt(array $r, array $dir, array $have, array $cfg, bool $dry): array {
    $date = $r['mail_date']; $stamp = str_replace('-', '.', $date);
    $unique = function (string $stem) use ($have, $stamp): string { $n = "$stem.pdf"; foreach ($have as $h) if (strcasecmp($h, $n) === 0) return "$stem ($stamp).pdf"; return $n; };
    if (str_starts_with($r['doc_code'], 'ASSIGNMENT')) {   // 2026-10-03: the Assignment Center cover sheet / the PTAS Notice of Recordation
        foreach (mf_list($dir) as $f) if (preg_match($r['doc_code'] === 'ASSIGNMENT FILED' ? '/AckReceipt-Recordation|Cover Sheet/i' : '/Recordation\.pdf$|Notice of Recordation|Assignment Cover Sheet/i', $f['name']) && $f['mtime'] >= strtotime($date) - 86400)
            return [$r['id'], 'already', [], 'Already in the folder: ' . $f['name']];
        $att = gmail_attachment_bytes((string)$r['gmail_id']); if (!$att) return [$r['id'], 'failed', [], 'Gmail not reachable for the attachments'];
        $code = fn(array $extra = []) => $r['kind'] === 'trademark' ? tm_next_code(array_merge(mf_list($dir), $extra), $r['ref'], 'assignment', $date) : next_doc_code(array_merge($have, array_column($extra, 'name')), $r['ref']);
        $nm = fn(string $c, string $label) => $r['ref'] . '-' . ($c !== '' ? "$c-" : '') . $label . '.pdf';
        $files = []; $note = ''; $extra = [];
        if ($r['doc_code'] === 'ASSIGNMENT FILED') {
            $pdf = ''; foreach ($att as $fn => $b) if (is_pdf($b)) { $pdf = $b; break; }
            if ($pdf === '') return [$r['id'], 'failed', [], 'No cover sheet PDF attached'];
            $n = save($dir, $unique(preg_replace('/\.pdf$/', '', $nm($code(), 'AckReceipt-Recordation'))), $pdf, $dry); if ($n !== null) $files[] = $n;
        } else {
            foreach ($att as $fn => $b) if (is_pdf($b) && preg_match('/notice/i', $fn)) {
                $d = recordation_details(pdf_bytes_text($b));
                if ($d['reel_frame'] !== '' && !$dry && $r['id']) run('UPDATE correspondence SET summary=? WHERE id=?', [preg_replace('/ at reel\/frame [\d\/]+/', '', (string)$r['summary']) . ' at reel/frame ' . $d['reel_frame'], $r['id']]);
                $c1 = $code(); $n = save($dir, $nm($c1, 'Assignment Recordation'), $b, $dry); if ($n !== null) { $files[] = $n; $extra[] = ['name' => $n, 'mtime' => time()]; }
                $note .= $d['reel_frame'] !== '' ? 'reel/frame ' . $d['reel_frame'] . '; ' : 'reel/frame not read off the notice; ';
            }
            foreach ($att as $fn => $b) if (preg_match('/\.tiff?$/i', $fn)) { $pdf = tiff_to_pdf($b); if ($pdf === '') { $note .= 'cover sheet TIFF could not be converted; '; continue; }
                $n = save($dir, $nm($code($extra), 'Assignment Cover Sheet'), $pdf, $dry); if ($n !== null) $files[] = $n; }
        }
        return [$r['id'], $files ? 'saved' : 'failed', $files, trim($note)];
    }
    if ($r['doc_code'] === 'MAINT FEE PAID') {
        $yr = preg_match('/(3\.5|7\.5|11\.5)-year/', (string)$r['summary'], $y) ? $y[1] : '';
        $mfc = patent_next_code($have, $r['ref'], 'Maintenance Fee'); $mfc = $mfc !== '' ? "$mfc-" : '';   // 2026-10-09: a new number (XYZ001-04a)
        $name = $unique($r['ref'] . '-' . $mfc . 'Maintenance Fee ' . ($yr !== '' ? "Year $yr " : '') . 'Payment Receipt of ' . $stamp);
        if ($yr === '') $name = $unique($r['ref'] . '-' . $mfc . 'Maint fee Payment Receipt of ' . $stamp);   // no open fee to say which year: saved, nothing proposed
        $c = gmail_cfg(); if (!$c || $r['gmail_id'] === '') return [$r['id'], 'failed', [], 'Gmail not reachable for the receipt attachment'];
        $msg = gm_api('/users/me/messages/' . rawurlencode($r['gmail_id']), ['format' => 'full'], $c); $pdf = '';
        foreach (gm_extract_attachments($msg['payload'] ?? []) as $a) if (preg_match('/\.pdf$/i', (string)$a['filename']) && !empty($a['id'])) { $pdf = gm_b64url((string)(gm_api('/users/me/messages/' . rawurlencode($r['gmail_id']) . '/attachments/' . rawurlencode($a['id']), [], $c)['data'] ?? '')); break; }
        if (!is_pdf($pdf)) return [$r['id'], 'failed', [], 'No PDF receipt attached to the Fee Storefront e-mail'];
    } else {
        $serial = preg_match('/^\d{8}$/', (string)$r['matter_app']) ? $r['matter_app'] : $r['app_no'];
        [$c, $b] = http_get("https://tsdrapi.uspto.gov/ts/cd/casedocs/bundle.xml?sn=$serial", ['USPTO-API-KEY: ' . $cfg['tsdr_key']]); sleep(2);
        if ($c !== 200) return [$r['id'], 'retry', [], "TSDR document list failed (HTTP $c); will retry"];
        $docs = [];
        foreach (preg_split('/<\/Document>/', $b) as $d) { $g = fn($t) => preg_match("/<$t>([^<]*)/", $d, $m) ? html_entity_decode($m[1]) : ''; if ($g('SerialNumber') !== '') $docs[] = ['type' => $g('DocumentTypeCode'), 'date' => substr($g('MailRoomDate'), 0, 10), 'cat' => $g('CategoryTypeCode')]; }
        $doc = tsdr_filed_doc($docs, $date);
        if (!$doc) return [$r['id'], 'retry', [], "TSDR does not list the paper filed $date yet; will retry"];
        [$prefix, $useReg] = filed_paper_prefix(preg_replace('/^Filed: /', '', (string)$r['summary']), $doc['type']);
        $num = $useReg && trim((string)$r['reg_no']) !== '' ? preg_replace('/\D/', '', (string)$r['reg_no']) : $serial;
        $form = trim(preg_replace(['/[\/\\\\:*?"<>|]+/', '/\s+/'], [' ', ' '], preg_replace('/^Filed: /', '', (string)$r['summary'])));
        foreach (mf_list($dir) as $f) if ((stripos($f['name'], "$prefix-$num") === 0 || ($form !== '' && $prefix !== 'ESU' && stripos($f['name'], filed_paper_label($form, $date)) !== false)) && $f['mtime'] >= strtotime($date) - 86400) {   // saved by hand already (the firm's TEAS names)
            $p = !$dry && $r['matter_id'] ? receipt_propose((int)$r['matter_id'], $dir['dir'] . '/' . $f['rel'], $f['name'], $date, (string)$r['kind']) : [];
            return [$r['id'], 'already', [], 'Already in the folder: ' . $f['name'] . ($p ? '; proposed done: ' . implode(', ', $p) : '')];
        }
        $label = $form !== '' ? filed_paper_label($form, $date, $prefix === 'ESU' ? sou_extension_number((int)$r['matter_id'], $date, $have) : null) : "$prefix-$num";   // "Renewal 2026" (2026-10-03)
        $name = $unique($r['ref'] . '-' . tm_next_code(mf_list($dir), $r['ref'], tm_stage($form) ?? 'examination', $date) . '-' . $label);   // his REF-NNx- codes
        [$c2, $pdf] = http_get("https://tsdrapi.uspto.gov/ts/cd/casedocs/bundle.pdf?sn=$serial&type=" . rawurlencode($doc['type']), ['USPTO-API-KEY: ' . $cfg['tsdr_key']]); sleep(2);
        if ($c2 !== 200 || !is_pdf($pdf)) return [$r['id'], 'retry', [], "TSDR PDF of the {$doc['type']} failed (HTTP $c2); will retry"];
    }
    $stored = save($dir, $name, $pdf, $dry); if ($stored === null) return [$r['id'], 'failed', [], 'could not write ' . $name];
    $note = '';
    if (!$dry && $r['matter_id']) { $p = receipt_propose((int)$r['matter_id'], $dir['dir'] . '/' . $stored, $stored, $date, (string)$r['kind']); $note = $p ? 'proposed done: ' . implode(', ', $p) : 'no open deadline for it to close'; }
    return [$r['id'], 'saved', [$stored], $note];
}
function is_pdf(string $b): bool { return str_starts_with($b, '%PDF'); }
function is_docx(string $b): bool { return str_starts_with($b, "PK\x03\x04"); }
/** A cited US patent / publication as a PDF: ppubs first (works from home), then Google Patents (works from the pair host). */
function cited_pdf(array $x): array {
    [$c, $b] = http_get('https://image-ppubs.uspto.gov/dirsearch-public/print/downloadPdf/' . $x['num'], [], 1);
    if ($c === 200 && is_pdf($b)) return [200, $b];
    $ids = $x['kind'] === 'publication' ? ['US' . $x['num'] . 'A1', 'US' . $x['num'] . 'A9', 'US' . $x['num'] . 'A2'] : ['US' . $x['num']];
    foreach ($ids as $id) {
        [$c, $page] = http_get("https://patents.google.com/patent/$id/en", [], 1); usleep(800000);
        if ($c !== 200 || !preg_match('#https://patentimages\.storage\.googleapis\.com/[^"\s]+\.pdf#', $page, $m)) continue;
        [$c2, $pdf] = http_get($m[0], [], 2);
        if ($c2 === 200 && is_pdf($pdf)) return [200, $pdf];
    }
    return [$c, ''];
}

// ---- 1. what needs saving
db()->exec("CREATE TABLE IF NOT EXISTS oa_files (correspondence_id INTEGER PRIMARY KEY, status TEXT, files TEXT DEFAULT '', note TEXT DEFAULT '', fetched_at TEXT DEFAULT (datetime('now')))");
$codes = implode(',', array_map(fn($c) => "'" . $c . "'", array_merge(OA_PATENT_CODES, OA_TM_CODES, OA_RECEIPT_CODES)));
// 2026-10-04: EVERY USPTO patent paper is saved (it has a to-do waiting for it), except attachments of another paper (the 892 comes with
// its action) and the grant (lib/issuance.php saves the eGrant on issue day).
$skip = implode(',', array_map(fn($c) => "'" . $c . "'", array_merge(NOTICE_ATTACHMENT_CODES, NOTICE_IGNORE_CODES, ['PATENT.GRANT', 'EGRANT.NTF'])));
$rows = q("SELECT c.id, c.matter_id, m.ref, m.kind, m.country, c.app_no, c.doc_code, c.mail_date, m.title, c.summary, c.gmail_id, m.app_no matter_app, m.reg_no FROM correspondence c JOIN matters m ON m.id=c.matter_id LEFT JOIN oa_files f ON f.correspondence_id=c.id
  WHERE (c.doc_code IN ($codes) OR (m.kind='patent' AND c.doc_code NOT IN ($skip))) AND ((c.mail_date >= date('now', ?) AND (f.correspondence_id IS NULL OR f.status='retry')) OR f.status='wanted') ORDER BY c.mail_date", ['-' . (int)$days . ' days']);   // 'wanted' = the Fetch button (2026-10-04), any age
if (isset($opt['wanted'])) $rows = array_values(array_filter($rows, fn($r) => q1('SELECT 1 FROM oa_files WHERE correspondence_id=? AND status="wanted"', [$r['id']])));   // the 5-minute timer: only what someone asked for
if (!empty($opt['doc']) && count($d = explode(':', $opt['doc'])) === 5) { $d[3] = ['NOA' => 'NOTICE OF ALLOWANCE'][strtoupper($d[3])] ?? $d[3]; /* the IFW code is accepted too (2026-10-04) */ $rows = [['id' => 0, 'matter_id' => 0, 'ref' => $d[0], 'kind' => $d[1], 'country' => 'United States', 'app_no' => $d[2], 'doc_code' => $d[3], 'mail_date' => $d[4], 'title' => '', 'summary' => (string)($opt['summary'] ?? ''), 'gmail_id' => '', 'matter_app' => $d[2], 'reg_no' => (string)($opt['reg'] ?? '')]]; $TEST = true; }
if ($only) $rows = array_values(array_filter($rows, fn($r) => strcasecmp($r['ref'], $only) === 0));
echo count($rows) . " USPTO paper(s) to save" . ($dry ? ' (dry run)' : '') . "\n";
$results = [];
foreach ($rows as $r) {
    $ref = $r['ref']; $date = $r['mail_date']; $files = []; $note = ''; $status = 'saved';
    echo "  $ref {$r['doc_code']} $date ({$r['kind']} {$r['app_no']})\n";
    $dir = mf_find($ref);
    if ($TEST && !$dir) $dir = mf_new($ref);
    if (!preg_match('/^[A-Z]{3,4}\d{3}/', $ref) || !$dir) { $results[] = [$r['id'], 'no_folder', [], "No matter folder " . DBX_WRITE_ROOT . "/$ref; download by hand"]; echo "      no folder\n"; continue; }
    $have = mf_names($dir);
    if (in_array($r['doc_code'], OA_RECEIPT_CODES, true)) { $results[] = save_receipt($r, $dir, $have, $cfg, $dry); echo '      ' . ($results[count($results) - 1][2] ? implode('; ', $results[count($results) - 1][2]) : $results[count($results) - 1][1]) . '  ' . $results[count($results) - 1][3] . "\n"; continue; }
    $label = $r['kind'] === 'patent' && $r['doc_code'] !== 'NOTICE OF ALLOWANCE' ? patent_file_label((string)$r['doc_code'])   // 2026-10-04: "Notice of Publication", "Filing Receipt", ...
        : (['NOTICE OF ALLOWANCE' => 'Notice of Allowance', 'NOA' => 'Notice of Allowance', 'ISSUE.NTF' => 'Issue Notification', 'NOTICE OF ACCEPTANCE' => 'Notice of Acceptance', 'NOTICE OF PUBLICATION' => 'Notice of Publication', 'REGISTRATION' => 'Registration Certificate'][$r['doc_code']] ?? 'Office Action');
    $notice = !in_array($label, ['Office Action', 'Notice of Allowance'], true);   // saved as acceptances (2026-10-03), one PDF each
    if ($notice) foreach (mf_list($dir) as $f) if ((stripos($f['name'], $label === 'Registration Certificate' ? 'Registration' : $label) !== false || ($label === 'Notice of Acceptance' && stripos($f['name'], 'Accepted SOU') !== false)) && $f['mtime'] >= strtotime($date) - 86400 && !isset($opt['force'])) { $results[] = [$r['id'], 'already', [], 'Already in the folder: ' . $f['name']]; echo "      already saved\n"; continue 2; }
    if (oa_already_saved($have, $date, $label) && !isset($opt['force'])) { $results[] = [$r['id'], 'already', [], 'Already in the folder (saved by hand)']; echo "      already saved by hand\n"; continue; }
    $code = $r['kind'] === 'trademark' ? tm_next_code(mf_list($dir), $ref, ['NOTICE OF ALLOWANCE' => 'allowance', 'POSTREG OFFICE ACTION' => 'renewal', 'NOTICE OF PUBLICATION' => 'examination', 'REGISTRATION' => 'issuance',
        'NOTICE OF ACCEPTANCE' => trim((string)($r['reg_no'] ?? '')) !== '' ? 'renewal' : 'allowance'][$r['doc_code']] ?? 'examination', $date)   // 2026-10-03: stage codes
                                       : patent_next_code($have, $ref, $label);   // 2026-10-04: an allowance starts a new number
    $base = $r['doc_code'] === 'REGISTRATION' ? tm_cert_basename($ref, $code, (string)($r['reg_no'] ?? ''), (string)$r['title']) : oa_basename($ref, $code, $label, $date);   // 2026-10-04: certificate named by reg. no. + mark, no date
    // 2026-10-03: a Statement of Use acceptance is filed as "REF-03a-Accepted SOU Receipt" (no date)
    $souAccepted = $r['doc_code'] === 'NOTICE OF ACCEPTANCE' && trim((string)($r['reg_no'] ?? '')) === '';
    if ($souAccepted) $base = $ref . '-' . ($code !== '' ? "$code-" : '') . 'Accepted SOU Receipt';
    if ($r['kind'] === 'patent') {
        [$c, $b] = http_get("https://api.uspto.gov/api/v1/patent/applications/{$r['app_no']}/documents", ['X-API-KEY: ' . $cfg['odp_key']]);
        $j = json_decode($b, true);
        if ($c === 403 || $c === 404 || empty($j['documentBag'])) { $results[] = [$r['id'], 'manual', [], 'Unpublished application: only Patent Center has the papers. https://patentcenter.uspto.gov/applications/' . $r['app_no'] . '/ifw/docs']; echo "      unpublished -> Patent Center only\n"; continue; }
        $same = array_filter($j['documentBag'], fn($d) => substr((string)$d['officialDate'], 0, 10) === $date);
        $oa = array_values(array_filter($same, fn($d) => $d['documentCode'] === $r['doc_code']));
        if (!$oa) { $results[] = [$r['id'], 'retry', [], "Open Data Portal does not list the {$r['doc_code']} of $date yet; will retry"]; echo "      not in ODP yet\n"; continue; }
        foreach ($oa[0]['downloadOptionBag'] as $o) {
            $ext = $o['mimeTypeIdentifier'] === 'PDF' ? 'pdf' : ($o['mimeTypeIdentifier'] === 'MS_WORD' ? 'docx' : null); if (!$ext) continue;
            [$c2, $b2] = http_get($o['downloadUrl'], ['X-API-KEY: ' . $cfg['odp_key']]);
            if ($c2 === 200 && ($ext === 'pdf' ? is_pdf($b2) : is_docx($b2))) { if (($n = save($dir, "$base.$ext", $b2, $dry)) !== null) $files[] = $n; }
            else $note .= "$ext download failed (HTTP $c2); ";
        }
        // PTO-892 (references cited) of the same date, then each US patent/publication it lists
        $l892 = $notice ? [] : array_values(array_filter($same, fn($d) => $d['documentCode'] === '892'));   // only an action / allowance carries references (not a publication notice of the same day)
        if ($l892) {
            $pdfOpt = array_values(array_filter($l892[0]['downloadOptionBag'], fn($o) => $o['mimeTypeIdentifier'] === 'PDF'));
            [$c3, $b3] = $pdfOpt ? http_get($pdfOpt[0]['downloadUrl'], ['X-API-KEY: ' . $cfg['odp_key']]) : [0, ''];
            if ($c3 === 200 && is_pdf($b3)) {
                $rcode = $code !== '' ? next_doc_code(array_merge($have, [$base . '.pdf']), $ref) : '';
                $rbase = oa_basename($ref, $rcode, 'References Cited (PTO-892)', $date);
                if (($n = save($dir, "$rbase.pdf", $b3, $dry)) !== null) $files[] = $n;
                $tmp = tempnam(sys_get_temp_dir(), '892') . '.pdf'; file_put_contents($tmp, $b3);   // the 892 is usually a scan: OCR it for the numbers
                $text = pdf_text($tmp); if (trim($text) === '') $text = pdf_ocr($tmp, 1, 4); @unlink($tmp);
                $refs = parse_892_refs($text); $sub = oa_basename($ref, $rcode, 'References', $date); $got = 0;
                foreach ($refs as $x) {
                    [$c4, $b4] = cited_pdf($x);
                    if ($c4 === 200 && is_pdf($b4)) { if (save($dir, "$sub/US" . $x['num'] . '.pdf', $b4, $dry) !== null) $got++; } else $note .= "US{$x['num']} not found (HTTP $c4); ";
                }
                if ($refs) $files[] = $sub . '/ (' . $got . ' of ' . count($refs) . ' cited US documents)';
                if (!$refs) $note .= 'no US references read off the 892; ';
            } else $note .= '892 download failed; ';
        } elseif (!$notice) $note .= 'no PTO-892 on that date; ';
    } else {   // trademark: TSDR
        [$c, $b] = http_get("https://tsdrapi.uspto.gov/ts/cd/casedocs/bundle.xml?sn={$r['app_no']}", ['USPTO-API-KEY: ' . $cfg['tsdr_key']]); sleep(2);
        if ($c !== 200) { $results[] = [$r['id'], 'retry', [], "TSDR document list failed (HTTP $c); will retry"]; echo "      TSDR list failed $c\n"; continue; }
        $docs = [];
        foreach (preg_split('/<\/Document>/', $b) as $d) {
            $g = fn($t) => preg_match("/<$t>([^<]*)/", $d, $m) ? html_entity_decode($m[1]) : '';
            if ($g('SerialNumber') === '') continue;
            $docs[] = ['type' => $g('DocumentTypeCode'), 'desc' => $g('DocumentTypeDescriptionText') . ' ' . $g('DocumentTypeCodeDescriptionText'), 'date' => substr($g('MailRoomDate'), 0, 10), 'cat' => $g('CategoryTypeCode'), 'urls' => array_map('html_entity_decode', preg_match_all('/<UrlPath>([^<]*)/', $d, $u) ? $u[1] : [])];
        }
        // Acceptance: TSDR calls the Section 8/15 one "Notice Of Accept-Ack" (AAK); publication NOP; the certificate ORC
        $isNotice = fn($d) => match ($label) { 'Notice of Acceptance' => (bool)preg_match('/accept/i', $d['desc']), 'Notice of Publication' => in_array(strtoupper($d['type']), ['NOP', 'PB1'], true) || (bool)preg_match('/^\s*notice of publicat/i', $d['desc']),   // not EB4 "Notification of ..." (the e-mail copy)
            'Registration Certificate' => strtoupper($d['type']) === 'ORC' || (bool)preg_match('/registration certificate/i', $d['desc']), default => false };
        $oa = array_values(array_filter($docs, fn($d) => $d['date'] === $date && ($label === 'Notice of Allowance' ? tsdr_is_noa($d['type'], $d['desc']) : ($notice ? $isNotice($d) : $d['cat'] === 'O' && tsdr_is_office_action($d['type'], $d['desc'])))));
        // The publication email is dated by the TMOG publication date; TSDR files the Notice of Publication on its issue date, 2-4 weeks earlier.
        if (!$oa && $label === 'Notice of Publication') { $oa = array_values(array_filter($docs, fn($d) => $d['date'] <= $date && $d['date'] >= add_days($date, -45, false) && $isNotice($d))); usort($oa, fn($a, $b) => strcmp($b['date'], $a['date'])); }
        // Other notices: TSDR may date the paper a day or two before the e-mail (XYZ004 / XYZ001 SOU acceptance: TSDR 09-23, e-mail 09-24).
        elseif (!$oa && $notice) { $oa = array_values(array_filter($docs, fn($d) => $d['date'] <= $date && $d['date'] >= add_days($date, -7, false) && $isNotice($d))); usort($oa, fn($a, $b) => strcmp($b['date'], $a['date'])); }
        if (!$oa) { $results[] = [$r['id'], 'retry', [], "TSDR does not list the " . strtolower($label) . " dated $date yet; will retry"]; echo "      not in TSDR yet\n"; continue; }
        $main = null; $att = [];
        foreach ($oa[0]['urls'] as $u) { $bn = basename(parse_url($u, PHP_URL_PATH) ?: $u); if ($main === null && preg_match('/^OfficeAction.*\.pdf$/i', $bn)) $main = $u; elseif (preg_match('/\.(pdf|jpe?g|png|gif|tiff?)$/i', $bn)) $att[] = $u; }
        if ($main === null) foreach ($oa[0]['urls'] as $u) if (preg_match('/\.pdf$/i', $u)) { $main = $u; break; }
        // A trademark Notice of Allowance is XML only in TSDR; the bundle endpoint renders it as a PDF (2026-09-29: attach it).
        if (($label === 'Notice of Allowance' || $notice) && ($main === null || !preg_match('/\.pdf$/i', $main))) $main = "https://tsdrapi.uspto.gov/ts/cd/casedocs/bundle.pdf?sn={$r['app_no']}&type=" . rawurlencode($oa[0]['type'] ?: 'ENA');
        if ($main) { [$c2, $b2] = http_get($main, ['USPTO-API-KEY: ' . $cfg['tsdr_key']]); sleep(2); if ($c2 === 200 && is_pdf($b2)) { if (($n = save($dir, "$base.pdf", $b2, $dry)) !== null) $files[] = $n; } else $note .= "office action PDF failed (HTTP $c2); "; }
        else $note .= 'no PDF in the TSDR document; ';
        if ($label !== 'Office Action') $att = [];   // a Notice of Allowance has no evidence attachments
        if ($att) { $sub = oa_basename($ref, $code, 'Office Action attachments', $date); $got = 0; foreach ($att as $u) { [$c3, $b3] = http_get($u, ['USPTO-API-KEY: ' . $cfg['tsdr_key']]); sleep(2); if ($c3 === 200 && $b3 !== '') { if (save($dir, "$sub/" . basename(parse_url($u, PHP_URL_PATH)), $b3, $dry) !== null) $got++; } } $files[] = "$sub/ ($got evidence file" . ($got === 1 ? '' : 's') . ')'; }
        if ($label === 'Office Action') $note .= 'TSDR offers no DOCX for trademark actions; cited registrations are inside the action PDF; ';
    }
    if (!$files) $status = 'failed';
    $results[] = [$r['id'], $status, $files, trim($note)];
    echo '      ' . ($files ? implode('; ', $files) : 'NOTHING SAVED') . ($note ? "  [$note]" : '') . "\n";
}
// ---- 2. record
if ($results && !$dry && !$TEST) {
    foreach ($results as [$id, $st, $files, $note]) {
        run('INSERT OR REPLACE INTO oa_files(correspondence_id,status,files,note,fetched_at) VALUES (?,?,?,?,datetime("now"))', [$id, $st, json_encode($files), $note]);
        $c = q1('SELECT matter_id, doc_code, mail_date FROM correspondence WHERE id=?', [$id]);
        if ($c) run('INSERT INTO audit(user,action,detail,matter_id) VALUES (?,?,?,?)', ['oa_fetch', 'oa_' . $st, $c['doc_code'] . ' of ' . $c['mail_date'] . ': ' . ($files ? implode('; ', $files) : $note), $c['matter_id']]);
    }
    echo "recorded " . count($results) . " result(s)\n";
}
