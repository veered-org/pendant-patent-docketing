<?php
declare(strict_types=1);
/**
 * New filing (2026-10-02): Create/Delete > New filing. Enter the file number, drop the receipts from the Downloads folder;
 *   patent    the receipts are named and saved into the matter folder (REL001 style: AckReceipt-filing, AckReceipt-fees, Assignment
 *             Cover Sheet), the Filing Report letter is built on the letterhead (bin/filing_report.py, .docx only; the PDF is saved by
 *             hand from Word, which is the review), and a "Report Patent Filing" to-do opens with the letter PDF + receipts pre-ticked.
 *   trademark the Trademark Center receipt (receipt-SERIAL.pdf) is saved into the folder and a "Report Trademark Filing" to-do opens
 *             with the attorney's CHL001 email (2026-09-29) and the receipt pre-ticked.
 * Both emails open with a summary card (filing_card_html), added at send time above the editable text.
 */
const FILING_REPORT_EVENTS = ['patent' => 'Report Patent Filing', 'trademark' => 'Report Trademark Filing'];
function is_filing_report(string $type): bool { return in_array($type, FILING_REPORT_EVENTS, true); }

function filing_parse(string $path): array {
    $j = json_decode((string)shell_exec('python3 ' . escapeshellarg(dirname(__DIR__) . '/bin/filing_report.py') . ' parse ' . escapeshellarg($path) . ' 2>/dev/null'), true);
    return is_array($j) ? $j : ['kind' => 'unknown'];
}
/** "Utility" / "Design" / "Provisional" / "National Stage" from the receipt's APPLICATION TYPE (and the serial series). */
function filing_app_kind(string $type, string $app): string {
    if (preg_match('/371|national stage/i', $type)) return 'National Stage';
    if (preg_match('/provisional/i', $type) && !preg_match('/non-?provisional/i', $type)) return 'Provisional';
    if (preg_match('/design/i', $type) || preg_match('/^(29|30)\d{6}$/', $app)) return 'Design';
    return 'Utility';
}
/** The letter's sections, worded as in the REL001 letter (2026-09-09) and the firm's Filing Report template. */
function filing_sections(array $f): array {
    $k = $f['app_kind']; $app = $f['appl']; $S = [];
    $what = ['Utility' => 'Utility Patent', 'Design' => 'Design Patent', 'Provisional' => 'Provisional Patent', 'National Stage' => 'U.S. national stage patent'][$k];
    $S[] = [null, "Congratulations!  The above-referenced $what application has been filed at the United States Patent Office.  Please find enclosed the acknowledgment receipt from the U.S. Patent Office indicating that Serial No. $app has been assigned to your " . strtolower($what) . " application.  A formal Filing Receipt from the Patent Office should be issued within the next few weeks."];
    if (!empty($f['track_one'])) { $S[] = ['Prioritized Examination (Track One)', 'As you instructed, the application was filed together with a request for prioritized examination under the Track One program, and the associated fees have been paid.  Under this program the Patent Office aims to reach a final disposition within twelve months of the grant of prioritized status, well ahead of the ordinary queue.']; }
    $S[] = [null, $k === 'Design' ? 'You may use the term “Patent Pending” in reference to your design, but it is recommended to keep the design confidential until the patent issues, as design applications are not published before then.  Where practicable, it is further recommended that confidentiality agreements be used when disclosing the design.'
        : ($k === 'Provisional' ? 'You may use the term “Patent Pending” in reference to your invention.  A provisional application is neither examined nor published, so it is recommended to keep the technology confidential and, where practicable, to use confidentiality agreements when disclosing it.'
        : 'You may use the term “Patent Pending” in reference to your invention, but it is recommended to keep the technology confidential until at least the formal Filing Receipt is issued by the U.S. Patent Office.  Until the application is published (approximately eighteen months from the earliest priority date) and where practicable, it is further recommended that confidentiality agreements be used when disclosing the technology.')];
    if ($k !== 'Provisional') $S[] = ['Information Disclosure Statement', 'Referring now to previous correspondence, any information, such as printed publications, relevant to the examination of your application must be reported to the U.S. Patent Office.  If you are aware, or become aware, of any information (beyond that already located and submitted to the USPTO) which should be filed, please forward the same to me immediately, as the duty to disclose such information continues until the patent issues.'];
    $S[] = ['Assignment Document', !empty($f['assignment'])
        ? 'The assignment of the invention from ' . $f['assignment']['conveying'] . ' to ' . $f['assignment']['receiving'] . ($f['assignment']['executed'] ? ', executed on ' . fmt_long($f['assignment']['executed']) . ',' : '') . ' has been submitted to the U.S. Patent Office for recordation against the new application.  A Notice of Recordation will issue in due course and I will forward it to you.'
        : 'The patent application is currently owned by you personally.  If you would like to transfer ownership to a company or another, this can be done at any time.'];
    if ($k === 'Provisional') $S[] = ['Non-Provisional and Foreign Filing', 'A provisional application lasts twelve months.  To keep the benefit of its filing date, a non-provisional application, and any foreign or international applications, must be filed by ' . fmt_long(add_months($f['filed_iso'], 12, false)) . '.  No reminders will necessarily be sent.'];
    elseif ($k !== 'National Stage') $S[] = ['Foreign Filing', 'The foreign filing deadline, while claiming your U.S. filing dates, is six months from the filing date for design patents and twelve months from the filing date for utility patents.  Thus, the deadline for foreign filing is ' . fmt_long(add_months($f['filed_iso'], $k === 'Design' ? 6 : 12, false)) . ' for the ' . strtolower($what) . ' application.  No reminders will necessarily be sent.'];
    if ($k !== 'Provisional') $S[] = ['Possibility of Rejection by the U.S. Patent Office', 'Given the subject matter and in view of recent court decisions, it is possible and even somewhat likely the U.S. Patent Office will reject the claims as failing to have novelty or unobviousness.  Such rejections are common place and we will likely have to analyze and respond to such rejections in due course.'];
    if (($f['entity'] ?? '') === 'micro') $S[] = ['Micro-Entity', 'You have signed the document indicating that you qualify for “micro-entity” fee payments, the lowest rate offered by the U.S. Patent Office.  This rate applies only to your first five non-provisional patent applications filed, and your income must be below $251,000/yr, gross.  If, at any time your income rises above this amount, you must inform me as failure to pay the proper fees will create problems with the validity of your patent.'];
    $S[] = ['In Closing', 'Please also retain all records concerning the conception and reduction to practice of this technology.'];
    $S[] = [null, 'Please kindly recommend my services to your colleagues, and if I can be of any further assistance, please feel free to contact me at any time.'];
    return array_map(fn($s) => ['heading' => $s[0], 'text' => $s[1]], $S);
}

/** The summary card at the top of the filing report email (navy band, gold labels), as sent; also previewed on the send page. */
function filing_card_html(array $m, array $ev): string {
    $tm = ($m['kind'] ?? '') === 'trademark'; $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $rows = $tm ? [['Mark', '<b style="color:' . MARK_COLOR . '">' . $e(clean_title((string)$m['title'])) . '</b>'], ['U.S. Serial Number', $e(fmt_app((string)$m['app_no'], 'United States'))], ['Filing date', $e(fmt_long((string)($ev['mail_date'] ?: $m['filing_date'])))], ['Our file number', $e($m['ref'])]]
                : [['Title', $e(clean_title((string)$m['title']))], ['Application No.', $e(fmt_app((string)$m['app_no'], (string)($m['country'] ?? '')))], ['Filing date', $e(fmt_long((string)($ev['mail_date'] ?: $m['filing_date'])))], ['Our file number', $e($m['ref'])]];
    [$aBefore, $aAfter] = assoc_card_rows($m);   // their file number above ours, the applicant below (2026-10-14)
    if ($aBefore || $aAfter) { $at = array_search('Our file number', array_column($rows, 0), true); array_splice($rows, $at + 1, 0, array_map(fn($r) => [$r[0], $e($r[1])], $aAfter)); array_splice($rows, $at, 0, array_map(fn($r) => [$r[0], $e($r[1])], $aBefore)); }
    $logo = $tm && function_exists('logo_serial') && ($sn = logo_serial($m)) !== '' ? '<tr><td colspan="2" style="padding:10px 18px 0"><img src="' . htmlspecialchars(logo_url($sn), ENT_QUOTES) . '" alt="mark" style="max-height:80px;max-width:220px;border:1px solid #e2ddd2;border-radius:4px;padding:3px;background:#fff"></td></tr>' : '';
    $h = '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;max-width:560px;margin:4px 0 18px;font-family:Lexend,Arial,sans-serif;border:1px solid #e2ddd2;border-radius:6px;overflow:hidden">'
       . '<tr><td colspan="2" style="background:' . NAVY . ';color:#fff;padding:12px 18px;font-size:15px;font-weight:bold;letter-spacing:.02em;border-bottom:3px solid #C9A227">' . ($tm ? 'Trademark application filed' : 'Patent application filed') . '</td></tr>' . $logo;
    foreach ($rows as [$k, $v]) $h .= '<tr><td style="padding:7px 18px;color:#8a6d12;font-size:11px;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap;vertical-align:top;background:#faf8f3">' . $k . '</td><td style="padding:7px 18px 7px 6px;color:' . NAVY . ';font-size:13px;background:#faf8f3">' . $v . '</td></tr>';
    return $h . '<tr><td colspan="2" style="height:8px;background:#faf8f3"></td></tr></table>';
}

/**
 * Process the receipts dropped on the New filing page. $files = [[tmp path, original name], ...]. Returns ['event_id' => int, 'notes' => [...]]
 * or throws with a message for the page.
 */
function new_filing(string $ref, array $files): array {
    $ref = strtoupper(trim($ref)); if (!preg_match('/^[A-Z]{2,5}\d{3}[A-Z0-9.\-]*$/', $ref)) throw new RuntimeException('That does not look like a file number (e.g. ABC001).');
    if (!$files) throw new RuntimeException('Drop the receipts from your Downloads folder first.');
    $parsed = [];
    foreach ($files as [$tmp, $name]) { $p = filing_parse($tmp); $p['_tmp'] = $tmp; $p['_name'] = $name; $parsed[] = $p; }
    $kinds = array_unique(array_map(fn($p) => $p['kind'] === 'tm_receipt' ? 'trademark' : (in_array($p['kind'], ['ack', 'payment', 'assignment'], true) ? 'patent' : 'unknown'), $parsed));
    if (in_array('unknown', $kinds, true)) throw new RuntimeException('Not recognized as a USPTO receipt: ' . implode(', ', array_column(array_filter($parsed, fn($p) => $p['kind'] === 'unknown'), '_name')) . '. Nothing was saved.');
    if (count($kinds) > 1) throw new RuntimeException('Patent and trademark receipts were mixed; drop one filing at a time. Nothing was saved.');
    $kind = reset($kinds);
    foreach ($parsed as $p) if (!empty($p['docket']) && strcasecmp(preg_replace('/\.\d+$/', '', $p['docket']), preg_replace('/\.\d+$/', '', $ref)) !== 0 && stripos($p['docket'], $ref) === false)
        throw new RuntimeException($p['_name'] . ' is for docket ' . $p['docket'] . ', not ' . $ref . '. Nothing was saved.');
    return $kind === 'trademark' ? new_tm_filing($ref, $parsed) : new_patent_filing($ref, $parsed);
}

function new_tm_filing(string $ref, array $parsed): array {
    $r = $parsed[0]; $notes = [];
    $it = ['kind' => 'trademark', 'app_no' => $r['serial'], 'doc_code' => 'APPLICATION FILED', 'date' => $r['date'] ?? today(), 'docket' => $ref, 'summary' => 'Application filed', 'mark' => $r['mark'] ?? '', 'owner' => $r['owner'] ?? ''];
    $res = apply_item($it, true, 'new_filing'); $m = $res['matter'] ?: find_matter($it); if (!$m) throw new RuntimeException('Could not create the matter.');
    $dir = mf_find($ref) ?: mf_new($ref); $names = mf_names($dir);
    foreach ($parsed as $p) { $nm = preg_match('/^receipt-\d{8}\.pdf$/i', $p['_name']) ? $p['_name'] : 'receipt-' . $p['serial'] . '.pdf';
        if (in_array($nm, $names, true)) { $notes[] = "$nm was already in the file"; continue; }
        $notes[] = 'saved ' . mf_write($dir, $nm, (string)file_get_contents($p['_tmp'])); }
    return ['event_id' => filing_event($m, 'trademark', (string)($r['date'] ?? today()), $notes), 'notes' => $notes, 'matter' => $m];
}

function new_patent_filing(string $ref, array $parsed): array {
    $ack = current(array_filter($parsed, fn($p) => $p['kind'] === 'ack')) ?: current(array_filter($parsed, fn($p) => $p['kind'] === 'payment'));
    if (!$ack || empty($ack['app_no'])) throw new RuntimeException('No acknowledgement receipt with an application number among the files. Nothing was saved.');
    $pay = current(array_filter($parsed, fn($p) => $p['kind'] === 'payment')) ?: []; $asg = current(array_filter($parsed, fn($p) => $p['kind'] === 'assignment')) ?: [];
    $app = $ack['app_no']; $filed = $ack['date'] ?: today(); $kindApp = filing_app_kind((string)($ack['app_type'] ?? ''), $app); $notes = [];
    // the matter: this file number with no number yet or the same number; a reused file number with another application = a new matter
    $m = q1('SELECT * FROM matters WHERE kind="patent" AND ref=? AND (app_no="" OR app_no=?) ORDER BY id DESC', [$ref, $app]) ?: q1('SELECT * FROM matters WHERE kind="patent" AND app_no=?', [$app]);
    if (!$m) {
        run('INSERT INTO matters(kind,ref,client_code,title,country,app_no,filing_date,status,pto_status,pto_status_date,app_type,notes) VALUES ("patent",?,?,?,"United States",?,?,"New Application Recorded","Application filed",?,?,?)',
            [$ref, client_from_ref($ref), (string)($ack['title'] ?? ''), $app, $filed, $filed, $kindApp, 'Created from the filing receipt (New filing)']);
        $id = (int)db()->lastInsertId(); if (client_from_ref($ref) !== '') run('INSERT OR IGNORE INTO clients(code) VALUES (?)', [client_from_ref($ref)]);
        $m = q1('SELECT * FROM matters WHERE id=?', [$id]); audit('new_matter', "$ref created from the filing receipt: Serial No. " . fmt_app($app, 'United States'), $id);
    } else run('UPDATE matters SET app_no=CASE WHEN app_no="" THEN ? ELSE app_no END, filing_date=COALESCE(filing_date, ?), title=CASE WHEN title IN ("", "(new from USPTO mail)") THEN ? ELSE title END, updated_at=datetime("now") WHERE id=?', [$app, $filed, (string)($ack['title'] ?? ''), $m['id']]);
    $m = q1('SELECT * FROM matters WHERE id=?', [$m['id']]);
    if (function_exists('ensure_priority_deadline') && ($pd = ensure_priority_deadline((int)$m['id'], false)) !== '') $notes[] = $pd;   // 12-month non-provisional / foreign-filing deadline, as Create record does
    // receipts into the file, REL001 naming, in the order filing receipt, fees, assignment
    $dir = mf_find($ref) ?: mf_new($ref); $names = mf_names($dir); $label = ['ack' => 'AckReceipt-filing', 'payment' => 'AckReceipt-fees', 'assignment' => 'Assignment Cover Sheet'];
    usort($parsed, fn($a, $b) => array_search($a['kind'], ['ack', 'payment', 'assignment']) <=> array_search($b['kind'], ['ack', 'payment', 'assignment']));
    $saved = [];
    foreach ($parsed as $p) {
        $code = next_doc_code(array_merge($names, $saved), $ref) ?: '01a';
        $nm = "$ref-$code-" . $label[$p['kind']] . '.pdf'; $saved[] = mf_write($dir, $nm, (string)file_get_contents($p['_tmp'])); $notes[] = 'saved ' . end($saved);
    }
    // the letter
    $people = array_filter([(string)($ack['inventor'] ?? ''), (string)($asg['receiving'] ?? '')]);
    $ad = client_letter_address($m, $people);
    if (!$ad && !empty($asg['receiving']) && !empty($asg['receiving_address'])) {   // a new client: the assignee from the cover sheet
        $ad = ['lines' => array_merge([$asg['receiving']], $asg['receiving_address']), 'salutation' => 'Dear Sir or Madam,', 'from' => 'the assignment cover sheet (assignee): add the contact person and salutation', 'own' => true];
    }
    $notes[] = $ad ? 'addressed as in ' . $ad['from'] : 'ADDRESS NEEDED: no earlier letter for this client / inventor was found';
    $f = ['app_kind' => $kindApp, 'appl' => ($kindApp === 'Design' ? '' : '') . fmt_app($app, 'United States'), 'filed_iso' => $filed, 'track_one' => !empty($pay['track_one']), 'entity' => $pay['entity'] ?? '',
          'assignment' => $asg ? ['conveying' => (string)($asg['conveying'] ?? ''), 'receiving' => (string)($asg['receiving'] ?? ''), 'executed' => (string)($asg['executed'] ?? '')] : null];
    $code = next_doc_code(array_merge($names, $saved), $ref) ?: '01a'; $letterName = "$ref-$code-Filing Report.docx";
    $fields = ['date' => fmt_long(today()), 'address_lines' => $ad['lines'] ?? [], 'salutation' => $ad['salutation'] ?? '', 'title' => clean_title((string)$m['title']) ?: (string)($ack['title'] ?? ''),
               'appl' => ($kindApp === 'Design' ? 'Design - ' : '') . fmt_app($app, 'United States'), 'filed' => fmt_long($filed), 'ref' => $ref, 'sections' => filing_sections($f)];
    $tmp = scratch_dir() . '/' . preg_replace('/[^A-Za-z0-9.]/', '_', $ref) . '-filing'; file_put_contents("$tmp.json", json_encode($fields));
    $err = (string)shell_exec('python3 ' . escapeshellarg(dirname(__DIR__) . '/bin/filing_report.py') . ' letter ' . escapeshellarg(dirname(__DIR__) . '/templates/filing-report-letter.docx') . ' ' . escapeshellarg("$tmp.docx") . ' ' . escapeshellarg("$tmp.json") . ' 2>&1');
    if (!is_file("$tmp.docx")) throw new RuntimeException('The receipts were saved, but the letter could not be built: ' . trim($err));
    $notes[] = 'letter saved as ' . mf_write($dir, $letterName, (string)file_get_contents("$tmp.docx")); @unlink("$tmp.docx"); @unlink("$tmp.json");
    if ($kindApp !== 'Utility') $notes[] = "$kindApp application: check the wording of the letter (the samples are utility filings)";
    $notes[] = 'if this application claims priority to an earlier one (continuation, CIP, PCT), add that to the letter (see REL001)';
    return ['event_id' => filing_event($m, 'patent', $filed, $notes), 'notes' => $notes, 'matter' => $m];
}

function filing_event(array $m, string $kind, string $filed, array $notes): int {
    $type = FILING_REPORT_EVENTS[$kind];
    $open = q1('SELECT id FROM events WHERE matter_id=? AND type=? AND status IN ("open","proposed")', [$m['id'], $type]);
    $note = 'Email the client: ' . ($kind === 'patent' ? 'filing report' : 'trademark filing') . '. ' . ($kind === 'patent' ? 'Open the letter, review it, save it as PDF into the folder, then Send. ' : '') . implode(' | ', $notes);
    if ($open) { run('UPDATE events SET note=?, mail_date=? WHERE id=?', [$note, $filed, $open['id']]); return (int)$open['id']; }
    run('INSERT INTO events(matter_id,type,due_date,status,source,doc_code,mail_date,note) VALUES (?,?,?,"open","rule","FILING",?,?)', [$m['id'], $type, today(), $filed, $note]);
    $id = (int)db()->lastInsertId(); audit('new_filing', "$m[ref] $kind filing: " . implode(' | ', $notes), (int)$m['id']); return $id;
}
