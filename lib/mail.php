<?php
declare(strict_types=1);
/**
 * Parse the USPTO emails that carry docket events:
 *  - "Correspondence Notification for Customer Number NNNNN" (Patent Center, daily): a table of Application | Document | Mailroom Date | Attorney Docket No.
 *  - "eGrant Notification": same table shape.
 *  - "Official USPTO Notification: U.S. Trademark Application SN <serial> -- Docket/Reference No. <ref>" (TM official notices).
 * Returns a list of items: [kind => patent|trademark, app_no, doc_code, date (Y-m-d), docket, summary].
 */
function parse_uspto_mail(string $subject, string $body, ?string $received = null): array {
    $items = [];
    // TSDR link in trademark notices: caseId=sn<serial>&docId=<PREFIX><yyyymmdd>. The prefix names the paper (tm_docid_kind); read before strip_tags drops href.
    $docPrefix = preg_match('/caseId=sn(\d{8})&(?:amp;)?docId=([A-Z]+)\d{8}/', $body, $dm) ? $dm[2] : ''; $docSerial = $dm[1] ?? '';
    // USPTO puts raw HTML in the text/plain part: drop head/style/script so strip_tags leaves only the words.
    $body = preg_replace('#<(style|head|script)\b[^>]*>.*?</\1>#is', '', $body);
    $blocks = html_entity_decode(strip_tags(preg_replace('#</(p|div|li|tr|h\d)>|<br\s*/?>#i', "\n", $body)), ENT_QUOTES, 'UTF-8');   // every block on its own line (filing receipt fields)
    $body = html_entity_decode(strip_tags(preg_replace('#</t[dh]>#i', ' | ', preg_replace('#</tr>|<br\s*/?>#i', "\n", $body))), ENT_QUOTES, 'UTF-8');
    $body = preg_replace("/[ \t\x{00a0}]+/u", ' ', $body); $body = preg_replace("/\n\s*\n+/", "\n", $body);
    if (preg_match('/(Correspondence|eGrant) Notification for Customer Number\s*(\d+)/i', $subject, $m)) {
        // Rows look like: | 18209049 | CTNF | 09/22/2026 | XYZ003 |   (or whitespace separated)
        // The trailing "|" is a lookahead: it is also the leading "|" of the next row. Consuming it dropped every second row
        // (XYZ001's Notice of Allowance, 2026-09-30, found by the DocketTrak reconciliation 2026-10-02).
        // The eGrant notification has FIVE columns: | Application | Patent | Document | Mailroom Date | Attorney Docket No. |
        // (| 30008944 | D1148592 | EGRANT.NTF | 09/22/2026 | XYZ002 |). Audit 2026-10-02: read as four columns, a design grant was "nothing to
        // docket" and a utility grant was filed under its PATENT number as if that were the application number. The patent column is optional.
        $pat = '(?:(?:D|PP|RE|H)?\d{6,8})';
        if (preg_match_all('/(?:^|\|)\s*(\d{8})\s*\|(?:\s*(' . $pat . ')\s*\|)?\s*([A-Z0-9.\-\/ ]+?)\s*\|\s*(\d{2}\/\d{2}\/\d{4})\s*\|\s*([^|\n]*?)\s*(?=\|)/m', $body, $rows, PREG_SET_ORDER)) {
            foreach ($rows as $r) $items[] = ['kind' => 'patent', 'app_no' => $r[1], 'doc_code' => trim($r[3]), 'date' => us_iso($r[4]), 'docket' => trim($r[5]), 'summary' => (PATENT_DOC_RULES[trim($r[3])]['label'] ?? trim($r[3]))] + ($r[2] !== '' ? ['patent_no' => $r[2]] : []);
        } else {
            preg_match_all('/^\s*(\d{8})\s+(?:(' . $pat . ')\s+)?([A-Z0-9.\-\/]+)\s+(\d{2}\/\d{2}\/\d{4})(?:\s+(\S+))?\s*$/m', $body, $rows, PREG_SET_ORDER);
            foreach ($rows as $r) $items[] = ['kind' => 'patent', 'app_no' => $r[1], 'doc_code' => $r[3], 'date' => us_iso($r[4]), 'docket' => trim($r[5] ?? ''), 'summary' => (PATENT_DOC_RULES[$r[3]]['label'] ?? $r[3])] + ($r[2] !== '' ? ['patent_no' => $r[2]] : []);
        }
        return $items;
    }
    // New trademark application (2026-10-02: "Madder should create a matter when a trademark is filed"): the TEAS / Trademark
    // Center filing receipt "The USPTO has received your trademark application (serial number 50139751)" carries the serial, mark,
    // owner, filing date and our docket number. Nothing is docketed; the matter is created (or completed) so later notices land on it.
    if (preg_match('/has received your trademark application \(serial number (\d{8})\)/i', $subject, $sm)) {
        // Labels may share a line once tags are stripped (</p>, </div>): each value runs to the next known label or the line end.
        $L = 'Serial number|Mark|Mark format|Owner name|Filing date|Docket number|Amount paid';
        $f = fn(string $label) => preg_match('/(?:^|\s)' . $label . ':[ \t\x{00a0}]*(.*?)\s*(?=(?:\s(?:' . $L . '):)|$)/miu', $blocks, $x) ? trim($x[1]) : '';
        $fd = $f('Filing date'); $fdIso = ($fd !== '' && ($ts = strtotime(preg_replace('/\s+at\s+.*$/i', '', $fd))) !== false) ? date('Y-m-d', $ts) : (($received && preg_match('/^\d{4}-\d{2}-\d{2}$/', $received)) ? $received : date('Y-m-d'));
        $docket = $f('Docket number'); if (!preg_match('/^[A-Z0-9][A-Z0-9.\-]{2,30}$/i', $docket)) $docket = '';
        return [['kind' => 'trademark', 'app_no' => $sm[1], 'doc_code' => 'APPLICATION FILED', 'date' => $fdIso, 'docket' => strtoupper($docket), 'summary' => 'Application filed',
                 'mark' => $f('Mark'), 'owner' => $f('Owner name')]];
    }
    // TTAB (estta@uspto.gov). The deadlines live only in the TTABVUE order PDF; ttab_enrich() (network) fetches it before docketing.
    if ($tt = parse_ttab_mail($subject, $body, $received)) return [$tt];
    // WIPO Madrid provisional refusal (e-marks@wipo.int): "1865135 812 Refusal Republic of Korea". Time limit is in the attached decision (madrid_enrich).
    if (preg_match('/^\s*(\d{7}) 812 Refusal\s+(.+?)\s*$/i', $subject, $m)) {
        $date = ($received && preg_match('/^\d{4}-\d{2}-\d{2}$/', $received)) ? $received : date('Y-m-d');
        return [['kind' => 'trademark', 'app_no' => $m[1], 'doc_code' => 'MADRID REFUSAL', 'date' => $date, 'docket' => '', 'summary' => 'Madrid provisional refusal: ' . $m[2] . ' (IR ' . $m[1] . ')', 'country' => $m[2], 'ir' => $m[1]]];
    }
    // Post-registration Office action (tmng.notices): subject "U.S. Trademark Application Registration No.  6135712 - MARK - XYZ003".
    // The body is a generic cover ("Office Action (Official Letter) or Notice has issued on <date>") linking docId PROARR<date>; checked
    // against TSDR 2026-09-30 for all 7 such e-mails since 2025-06: every one was "POST REGISTRATION ACTION MAILED" (acceptances come as
    // separate notices), so each is docketed as a post-reg Office action. caseId=sn<serial> in the link gives the serial when present.
    if (preg_match('/Trademark Application Registration No\.?\s*(\d{7,8})\s*-\s*(.*?)\s*-\s*(\S+)\s*$/i', $subject, $m)) {
        $date = ($received && preg_match('/^\d{4}-\d{2}-\d{2}$/', $received)) ? $received : date('Y-m-d');
        if (preg_match('/has issued\s+on\s+([A-Za-z]+\.? \d{1,2}, \d{4})/i', $body, $d) && ($ts = strtotime($d[1])) !== false) $date = date('Y-m-d', $ts);
        $serial = preg_match('/caseId=sn(\d{8})/i', $body, $s) ? $s[1] : $m[1];
        return [['kind' => 'trademark', 'app_no' => $serial, 'doc_code' => 'POSTREG OFFICE ACTION', 'date' => $date, 'docket' => $m[3], 'summary' => 'Post-registration Office action (Reg. No. ' . $m[1] . ')']];
    }
    // Application Office action / notice (tmng.notices, the older channel): "U.S. Trademark Application Serial No.  99401774 - MARK - XYZ001"
    // (REF sometimes missing). The generic cover links docId <PREFIX><date>; the prefix says which paper it is (TSDR-checked 2026-09-30, 62 e-mails).
    if (preg_match('/Trademark Application Serial No\.?\s*(\d{8})\s*-\s*(.*?)(?:\s*-\s*([A-Z]{2,4}\d{3}\S*))?\s*$/i', $subject, $m)) {
        $date = ($received && preg_match('/^\d{4}-\d{2}-\d{2}$/', $received)) ? $received : date('Y-m-d');
        if (preg_match('/has issued\s+on\s+([A-Za-z]+\.? \d{1,2}, \d{4})/i', $body, $d) && ($ts = strtotime($d[1])) !== false) $date = date('Y-m-d', $ts);
        $kind = tm_docid_kind($docPrefix);
        return [['kind' => 'trademark', 'app_no' => $m[1], 'doc_code' => $kind, 'date' => $date, 'docket' => $m[3] ?? '', 'summary' => ucwords(strtolower($kind)) . ($docPrefix ? " ($docPrefix)" : '')]];
    }
    // Trademark official notices only (TMOfficialNotices / tmng.notices): the subject names the serial and the docket.
    if (preg_match('/Official USPTO Notification?:.*?(?:SN|RN|Serial No\.?)\s*(\d{7,8})(?:\s*--\s*Docket\/Reference No\.\s*(\S+))?/i', $subject, $m) || preg_match('/Official USPTO Notice of [^:]+:.*?(?:SN|RN)\s*(\d{7,8})/i', $subject, $m)) {
        $serial = $m[1]; $docket = $m[2] ?? '';
        if ($docket === '' && preg_match('/Docket\/Reference (?:No\.|Number:)\s*([A-Z]{2,4}\d{3}[^\s:<|]*)/i', $subject . "\n" . $body, $dk)) $docket = $dk[1];
        $kinds = ['NOTICE OF PUBLICATION', 'PUBLICATION CONFIRMATION', 'APPROVAL OF EXTENSION REQUEST', 'NOTICE OF ALLOWANCE', 'NOTICE OF ABANDONMENT', 'NOTICE OF REGISTRATION', 'REGISTRATION CERTIFICATE', 'PRIORITY ACTION', 'OFFICE ACTION', 'NOTICE OF ACCEPTANCE', 'NOTICE OF REVIVAL', 'EXAMINER.{1,3}S AMENDMENT', 'SUSPENSION', 'COURTESY REMINDER'];   // .{1,3}: the USPTO writes a curly apostrophe (3 bytes)
        $kind = 'TRADEMARK NOTICE'; $head = substr($body, 0, 2500); $best = PHP_INT_MAX;   // classify by the EARLIEST heading match, not by list order
        foreach ($kinds as $k) if (preg_match('/' . $k . '/i', $head, $mm, PREG_OFFSET_CAPTURE) && $mm[0][1] < $best) { $best = $mm[0][1]; $kind = preg_replace('/\.\{1,3\}|\./', "'", $k); }
        if (in_array($kind, ['NOTICE OF REGISTRATION', 'REGISTRATION CERTIFICATE'], true)) $kind = 'REGISTRATION';
        if ($kind === 'PRIORITY ACTION') $kind = 'OFFICE ACTION';
        if ($kind === 'PUBLICATION CONFIRMATION') $kind = 'NOTICE OF PUBLICATION';   // the TMOG-day e-mail: same event, dated by the publication date
        // Office action cover (newer channel, since ~2026-06): the heading says "Office Action (Official Letter) has issued"; the docId prefix says which paper
        if ($docPrefix !== '' && in_array($kind, ['OFFICE ACTION', 'TRADEMARK NOTICE', 'SUSPENSION', "EXAMINER'S AMENDMENT"], true)) $kind = tm_docid_kind($docPrefix);
        $date = ($received && preg_match('/^\d{4}-\d{2}-\d{2}$/', $received)) ? $received : date('Y-m-d'); $extra = [];   // fallback = the day the e-mail ARRIVED (not the day the pull ran); a labelled date in the notice wins
        $mdy = '([A-Za-z]+\.? \d{1,2}, \d{4})';
        // Kind-specific phrasing first: the publication notice and its cover e-mail both name the TMOG date ("scheduled to publish ... on" /
        // "has been published ... on"); an Office action e-mail says "has issued on"; an extension approval carries the NOA date and the request number.
        if ($kind === 'NOTICE OF PUBLICATION' && preg_match('/(?:scheduled to publish|has been published|will publish)[^.]*?\bon\s+' . $mdy . '/i', $body, $d) && ($ts = strtotime($d[1])) !== false) $date = date('Y-m-d', $ts);
        elseif (preg_match('/has issued\s+on\s+' . $mdy . '/i', $body, $d) && ($ts = strtotime($d[1])) !== false) $date = date('Y-m-d', $ts);
        elseif ($kind === 'APPROVAL OF EXTENSION REQUEST' && preg_match('/Notice of Allowance Date:\s*' . $mdy . '/i', $body, $d) && ($ts = strtotime($d[1])) !== false) { $date = date('Y-m-d', $ts); if (preg_match('/Extension Request Number:\s*(\d)/i', $body, $n)) $extra['n'] = (int)$n[1]; }
        // 2026-10-13 (XYZ048): a post-registration notice (acceptance of a Section 8 / 15 / renewal) also prints "U.S. Registration Date",
        // which is the registration's date, not the notice's: only a registration notice is dated by it.
        elseif (preg_match('/(?:Issue Date|Mailing Date|Publication Date|' . ($kind === 'REGISTRATION' ? 'Registration Date|' : '') . 'Notice Date|Revival Date|Issued|Sent)[:\s]+(?:[A-Za-z]+day,?\s*)?' . $mdy . '/i', $body, $d) && ($ts = strtotime($d[1])) !== false) $date = date('Y-m-d', $ts);
        elseif (preg_match('/(?:Issue Date|Mailing Date|Publication Date|' . ($kind === 'REGISTRATION' ? 'Registration Date|' : '') . 'Notice Date)[:\s]+(\d{2}\/\d{2}\/\d{4})/i', $body, $d)) $date = us_iso($d[1]);
        $items[] = ['kind' => 'trademark', 'app_no' => $serial, 'doc_code' => $kind, 'date' => $date, 'docket' => $docket, 'summary' => ucwords(strtolower($kind))] + $extra;
    }
    return $items;
}
function us_iso(string $us): string { return DateTimeImmutable::createFromFormat('m/d/Y', trim($us))->format('Y-m-d'); }

/** Find the matter for a parsed item: by application/serial number first, then by docket number. */
function find_matter(array $it): ?array {
    $n = preg_replace('/\D/', '', (string)$it['app_no']);
    // never match on an empty number: that would pick whichever matter happens to have a blank app/reg number (TTAB orders carry none)
    // An application number wins over a patent / registration number that happens to read the same (application 12/723,855 and patent
    // 12,723,855 are both "12723855"), and an active record over an inactive copy of it (audit 2026-10-02).
    $m = $n === '' ? null : q1('SELECT * FROM matters WHERE kind=? AND (app_no=? OR reg_no=?) ORDER BY CASE WHEN app_no=? THEN 0 ELSE 1 END, dead, id DESC', [$it['kind'], $n, $n, $n]);
    if (!$m && $it['docket'] !== '') $m = q1('SELECT * FROM matters WHERE ref=? AND kind=? ORDER BY dead, id DESC', [$it['docket'], $it['kind']]);   // same kind only: a patent and a trademark can share a file number (XYZ001)
    return $m;
}
/** Dry-run or apply one parsed item. Returns a description of what happens. */
function apply_item(array $it, bool $apply, string $source = 'uspto_mail'): array {
    $m = find_matter($it);
    // Two applications, one file number (a provisional and the application that claims it; a re-filed mark). A notice found only by its
    // docket number must not be docketed on the record of a DIFFERENT application: that is how patents' maintenance fees ended up on their
    // provisionals' records and were closed when the provisional expired (audit 2026-10-02/03). It goes to the record of that file number
    // that has no application number yet, or to a new record with the same file number.
    $inum = preg_replace('/\D/', '', (string)$it['app_no']); $sibling = null;
    if ($m && strlen($inum) === 8 && ($mn = preg_replace('/\D/', '', (string)$m['app_no'])) !== '' && $mn !== $inum && preg_replace('/\D/', '', (string)$m['reg_no']) !== $inum) {
        $sibling = $m; $m = q1('SELECT * FROM matters WHERE ref=? AND kind=? AND app_no="" AND dead=0 ORDER BY id DESC', [$sibling['ref'], $it['kind']]);
        if ($m && $apply) { run('UPDATE matters SET app_no=?, updated_at=datetime("now") WHERE id=?', [$inum, $m['id']]); $m['app_no'] = $inum; }
        if ($it['docket'] === '') $it['docket'] = (string)$sibling['ref'];
    } elseif ($m && $apply && strlen($inum) === 8 && preg_replace('/\D/', '', (string)$m['app_no']) === '' && preg_replace('/\D/', '', (string)$m['reg_no']) !== $inum) {
        run('UPDATE matters SET app_no=?, updated_at=datetime("now") WHERE id=?', [$inum, $m['id']]); $m['app_no'] = $inum;   // a record made before filing: the first notice supplies its number
    }
    $res = ['item' => $it, 'matter' => $m, 'creates' => [], 'closes' => [], 'notes' => []];
    if ($sibling) $res['notes'][] = 'File number ' . $sibling['ref'] . ' also belongs to ' . fmt_app($sibling['app_no']) . ', a different application: ' . ($m ? 'docketed on its record for ' . fmt_app($inum) : 'a separate record ' . ($apply ? 'was' : 'will be') . ' made for ' . fmt_app($inum));
    // No serial and no docket number (a TTAB mail whose proceeding could not be tied to one of our marks): nothing to hang it on.
    if (!$m && trim((string)$it['docket']) === '' && preg_replace('/\D/', '', (string)$it['app_no']) === '') { $res['notes'][] = 'NOT DOCKETED: no serial or docket number could be read from this notice - review it by hand'; return $res; }
    if (!$m && $it['doc_code'] !== 'APPLICATION FILED') { $res['notes'][] = 'No matching matter: ' . ($apply ? 'created a placeholder matter' : 'a placeholder matter will be created'); }
    $stub = $m ?: ['id' => 0, 'filing_date' => null, 'ref' => $it['docket'] ?: $it['app_no'], 'kind' => $it['kind']];
    $new = $it['kind'] === 'patent' ? events_for_patent_doc($it['doc_code'], $it['date'], $stub) : events_for_tm_notice($it['doc_code'], $it['date'], $stub, $it);
    $existing = $m ? q('SELECT * FROM events WHERE matter_id=?', [$m['id']]) : [];
    foreach ($new as $e) {
        if ($e['type'] === '') { $res['notes'][] = $e['note']; continue; }
        $dup = array_filter($existing, fn($x) => $x['type'] === $e['type'] && $x['due_date'] === $e['due']);
        if ($dup) $res['notes'][] = 'Already docketed: ' . $e['type'] . ' ' . fmt_date($e['due']) . ' (' . reset($dup)['status'] . ')';
        else $res['creates'][] = $e;
    }
    // Auto-undocket candidates: open events of a type this document satisfies, but only OLDER deadlines than the ones
    // this document sets. A new CTNF closes last year's final-response deadline, never the one it just created.
    $closesTypes = $it['kind'] === 'patent' ? (PATENT_CLOSES[$it['doc_code']] ?? []) : (TM_CLOSES[$it['doc_code']] ?? []);
    if ($m && $closesTypes) {
        $newDue = array_column(array_filter($new, fn($e) => $e['type'] !== ''), 'due'); $latest = $newDue ? max($newDue) : '9999-12-31';
        $sets = array_map(fn($e) => $e['type'] . '|' . $e['due'], array_filter($new, fn($e) => $e['type'] !== ''));   // the deadlines this very document sets
        foreach ($existing as $ev) {
            if ($ev['status'] !== 'open') continue;
            if (in_array($ev['type'] . '|' . $ev['due_date'], $sets, true)) continue;   // already docketed by DocketTrak for this same document: it is what the document SETS, not what it satisfies
            if (!in_array('*', $closesTypes, true) && !in_array($ev['type'], $closesTypes, true)) continue;
            if (!in_array('*', $closesTypes, true)) {
                if ($ev['mail_date'] && $ev['mail_date'] > $it['date']) continue;                 // set by a later document
                // Same-day (an extension approval carries the NOA date, so it shares mail_date with the 1st-extension deadline it replaces)
                // or imported without a mail date: only deadlines OLDER than the ones this document sets.
                if ((!$ev['mail_date'] || $ev['mail_date'] === $it['date']) && $ev['due_date'] >= $latest) continue;
            }
            $res['closes'][] = $ev;
        }
    }
    // A filing receipt whose docket number belongs to an OLDER application (a reused file number, like XYZ001 / XYZ001) is a new matter.
    if ($it['doc_code'] === 'APPLICATION FILED' && $m && trim((string)$m['app_no']) !== '' && preg_replace('/\D/', '', (string)$m['app_no']) !== preg_replace('/\D/', '', (string)$it['app_no'])) { $m = null; $res['matter'] = null; }
    if (!$m && $it['doc_code'] === 'APPLICATION FILED') $res['notes'][] = 'New application: ' . ($apply ? 'matter created' : 'a matter will be created');
    if (!$apply) return $res;
    if (!$m && $it['doc_code'] === 'APPLICATION FILED') {
        $ref = $it['docket'] !== '' ? $it['docket'] : 'SN' . $it['app_no']; $cc = client_from_ref($ref);
        run('INSERT INTO matters(kind,ref,client_code,title,country,app_no,filing_date,owner,status,pto_status,pto_status_date,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            ['trademark', $ref, $cc, (string)($it['mark'] ?? ''), 'United States', $it['app_no'], $it['date'], (string)($it['owner'] ?? ''), 'New Application Recorded', 'Application filed', $it['date'], 'Created from the USPTO filing receipt']);
        $newId = (int)db()->lastInsertId();   // before the clients insert below, which moves last_insert_rowid (CHL001 landed on XYZ002)
        if ($cc !== '') run('INSERT OR IGNORE INTO clients(code) VALUES (?)', [$cc]);
        $m = q1('SELECT * FROM matters WHERE id=?', [$newId]); $res['matter'] = $m;
        audit('new_matter', $ref . ' created from the USPTO filing receipt: ' . ($it['mark'] ?? '') . ' (Serial No. ' . $it['app_no'] . ', filed ' . fmt_date($it['date']) . ')', (int)$m['id']);
    } elseif ($m && $it['doc_code'] === 'APPLICATION FILED') {   // made by hand before the filing: fill in what is blank
        run('UPDATE matters SET app_no=CASE WHEN app_no="" THEN ? ELSE app_no END, filing_date=COALESCE(filing_date, ?), title=CASE WHEN title IN ("", "(new from USPTO mail)") THEN ? ELSE title END, owner=CASE WHEN owner="" THEN ? ELSE owner END, updated_at=datetime("now") WHERE id=?',
            [$it['app_no'], $it['date'], (string)($it['mark'] ?? ''), (string)($it['owner'] ?? ''), $m['id']]);
        $m = q1('SELECT * FROM matters WHERE id=?', [$m['id']]); $res['matter'] = $m;
    }
    if (!$m) {
        run('INSERT INTO matters(kind,ref,client_code,title,app_no,pto_status,pto_status_date,notes) VALUES (?,?,?,?,?,?,?,?)',
            [$it['kind'], $stub['ref'], client_from_ref($stub['ref']), '(new from USPTO mail)', preg_replace('/\D/', '', $it['app_no']), $it['summary'], $it['date'], 'Auto-created from USPTO notification; fill in title/client']);
        $m = q1('SELECT * FROM matters WHERE id=last_insert_rowid()');
        if ($sibling) { run('UPDATE matters SET title=?, client_code=?, owner=?, notes=? WHERE id=?', [(string)$sibling['title'], (string)$sibling['client_code'], (string)$sibling['owner'], 'Made from a USPTO notification: a second application under file number ' . $sibling['ref'] . ' (the other is ' . fmt_app($sibling['app_no']) . ')', $m['id']]); $m = q1('SELECT * FROM matters WHERE id=?', [$m['id']]); }
        $res['matter'] = $m;
    }
    if ($it['docket'] !== '' && preg_match('/^[\d\/,]+$/', $m['ref'])) {
        $cc = client_from_ref($it['docket']);
        run('UPDATE matters SET ref=?, client_code=CASE WHEN client_code IN ("", "Unspecified", "Unsorted") AND ? <> "" THEN ? ELSE client_code END WHERE id=?', [$it['docket'], $cc, $cc, $m['id']]);
        if ($cc !== '') run('INSERT OR IGNORE INTO clients(code) VALUES (?)', [$cc]);
        $res['notes'][] = 'File number ' . $m['ref'] . ' renamed to ' . $it['docket'] . ' (docket number from the USPTO notification)'; $m['ref'] = $it['docket'];
    }
    $dup = q1('SELECT id FROM correspondence WHERE app_no=? AND doc_code=? AND mail_date=?', [$it['app_no'], $it['doc_code'], $it['date']]);
    if ($dup) { $res['notes'][] = 'Already ingested (duplicate notification), nothing changed'; $res['creates'] = []; $res['closes'] = []; return $res; }
    run('INSERT INTO correspondence(matter_id,app_no,doc_code,mail_date,docket,kind,summary,source,gmail_id) VALUES (?,?,?,?,?,?,?,?,?)', [$m['id'], $it['app_no'], $it['doc_code'], $it['date'], $it['docket'], $it['kind'], $it['summary'], $source, (string)($it['gmail_id'] ?? '')]);
    run('UPDATE matters SET pto_status=?, pto_status_date=?, updated_at=datetime("now") WHERE id=?', [$it['summary'], $it['date'], $m['id']]);
    if (in_array($it['doc_code'], ['PATENT.GRANT', 'EGRANT.NTF'], true)) run('UPDATE matters SET grant_date=?, status="Patented Case", reg_no=CASE WHEN ? <> "" THEN ? ELSE reg_no END WHERE id=?', [$it['date'], (string)($it['patent_no'] ?? ''), (string)($it['patent_no'] ?? ''), $m['id']]);
    if ($it['doc_code'] === 'REGISTRATION') run('UPDATE matters SET status="Registered", grant_date=CASE WHEN ? THEN ? ELSE COALESCE(grant_date, ?) END, reg_no=CASE WHEN ? <> "" THEN ? ELSE reg_no END WHERE id=?', [!empty($it['reg_verified']) ? 1 : 0, $it['date'], $it['date'], (string)($it['reg_no'] ?? ''), (string)($it['reg_no'] ?? ''), $m['id']]);
    if ($it['kind'] === 'trademark' && $it['doc_code'] === 'NOTICE OF REVIVAL') {   // 2026-09-30: revived trademark -> alive again, re-docket what the abandonment closed
        run('UPDATE matters SET dead=0, dead_reason="", dead_at=NULL, status="Revived", updated_at=datetime("now") WHERE id=?', [$m['id']]);
        foreach (q('SELECT * FROM events WHERE matter_id=? AND status IN ("done","proposed") AND note LIKE "%property dead%" AND due_date>=?', [$m['id'], $it['date']]) as $x) {
            run('UPDATE events SET status="open", done_by=NULL, done_at=NULL, proposed_by=NULL, proposed_at=NULL, note=note_add(note, ?) WHERE id=?', ['reopened: revived ' . fmt_date($it['date']), $x['id']]);
            $res['notes'][] = 'Reopened ' . $x['type'] . ' ' . fmt_date($x['due_date']);
        }
        if (cancel_abandon_report((int)$m['id'], 'cancelled: revived ' . fmt_date($it['date']) . ' before the abandonment email went')) $res['notes'][] = 'Abandonment email cancelled (revived)';
        audit('revive', 'Revived ' . fmt_date($it['date']) . ' (USPTO notice of revival)', (int)$m['id']);
    }
    if (isset(NOTICE_REPORTS[$it['doc_code']]) && empty($it['no_report']) && queue_notice_report($m, (string)$it['doc_code'], (string)$it['date'], (string)($it['gmail_id'] ?? ''))) $res['notes'][] = 'Client email queued: ' . NOTICE_REPORTS[$it['doc_code']];   // 2026-10-01
    if ($it['kind'] === 'patent' && in_array($it['doc_code'], OA_REPORT_CODES, true) && queue_oa_report($m, (string)$it['doc_code'], (string)$it['date'])) $res['notes'][] = 'Client report to-do queued: ' . ($it['doc_code'] === 'NOA' ? PNOA_REPORT_EVENT : OA_REPORT_EVENT);   // 2026-10-04 (lib/oareport.php)
    // 2026-10-04: "any notice from the USPTO for patents must be processed!" - every other patent paper gets a to-do too (an Issue Notification its report)
    if ($it['kind'] === 'patent' && ($ntd = queue_notice_todo($m, (string)$it['doc_code'], (string)$it['date']))) $res['notes'][] = 'To-do queued: ' . ((string)$it['doc_code'] === 'ISSUE.NTF' ? (string)q1('SELECT type FROM events WHERE id=?', [$ntd])['type'] : NOTICE_TODO_EVENT . ' (' . notice_label((string)$it['doc_code']) . ')');
    if (in_array($it['doc_code'], ['ABN', 'NOTICE OF ABANDONMENT'], true)) mark_abandoned((int)$m['id'], (string)$it['date'], $it['summary'] . ' (USPTO notice)');   // 2026-10-01: inactive + one client email
    foreach ($res['creates'] as $e) { run('INSERT INTO events(matter_id,type,due_date,status,source,doc_code,mail_date,note) VALUES (?,?,?,?,?,?,?,?)', [$m['id'], $e['type'], $e['due'], 'open', $source, $it['doc_code'], $it['date'], $e['note']]); }
    // Registration date confirmed against TSDR (enrich_item): a schedule DocketTrak docketed earlier from an estimated registration date is
    // put on the real one - its wrong-dated copies are cancelled as duplicates of the events just created (audit 2026-10-02: XYZ001 ...).
    if ($it['doc_code'] === 'REGISTRATION' && !empty($it['reg_verified']) && !q1('SELECT 1 FROM matters WHERE ref=? AND kind=? AND id<>?', [$m['ref'], $m['kind'], $m['id']]))
        foreach (reconcile_tm_maintenance((int)$m['id'], (string)$it['date'], 'USPTO TSDR') as $c) $res['notes'][] = $c['type'] . ' ' . fmt_date($c['from']) . ($c['action'] === 'cancelled' ? ' cancelled (duplicate from an estimated date)' : ' corrected to ' . fmt_date($c['to']));
    // Auto-undocket = PROPOSE only. an admin confirms in the queue. A proposal he rejected is not re-proposed.
    foreach ($res['closes'] as $ev) { if (run('UPDATE events SET status="proposed", proposed_by="system", proposed_at=datetime("now"), note=note_add(note, ?) WHERE id=? AND status="open" AND rejected_at IS NULL', ['USPTO ' . $it['doc_code'] . ' of ' . fmt_date($it['date']) . ' appears to satisfy this', $ev['id']]) === 1) audit_done($ev + ['matter_id' => $m['id']], 'USPTO ' . $it['doc_code'] . ' of ' . fmt_date($it['date']), false); }
    audit('ingest', $it['kind'] . ' ' . $it['app_no'] . ' ' . $it['doc_code'] . ' ' . $it['date'] . ' → matter ' . $m['ref'] . ': +' . count($res['creates']) . ' events, ' . count($res['closes']) . ' proposed closed', (int)$m['id']);
    return $res;
}

/** TTAB e-mail -> item (kind trademark, doc_code "TTAB <KIND>", pno/pty/eno of the order) or null. Filing receipts carry no deadline: null. */
function parse_ttab_mail(string $subject, string $body, ?string $received = null): ?array {
    $s = trim($subject); $k = null; $pno = '';
    if (preg_match('/^Notice of opposition - proceeding no\.\s*(9\d{7})/i', $s, $m)) { $k = 'INSTITUTED'; $pno = $m[1]; }
    elseif (preg_match('/^Petition for cancellation - proceeding no\.\s*(9\d{7})/i', $s, $m)) { $k = 'INSTITUTED'; $pno = $m[1]; }
    elseif (preg_match('/^Extension of time to oppose no\.\s*(\d{8})/i', $s, $m)) { $k = 'EXT OPPOSE'; $pno = $m[1]; }
    elseif (preg_match('/^Notice of Default\W+(9\d{7})/iu', $s, $m)) { $k = 'DEFAULT'; $pno = $m[1]; }
    elseif (preg_match('/^Motion to extend time to answer granted - proceeding no\.\s*(\d{8})/i', $s, $m)) { $k = 'ORDER'; $pno = $m[1]; }
    elseif (preg_match('/^TTAB Order\b.*proceeding\s*(\d{8})/i', $s, $m)) { $pno = $m[1]; $k = preg_match('/notice of institution order/i', $body) ? 'INSTITUTED' : (preg_match('/notice of default/i', $body) ? 'DEFAULT' : 'ORDER'); }
    if (!$k) return null;
    $date = ($received && preg_match('/^\d{4}-\d{2}-\d{2}$/', $received)) ? $received : date('Y-m-d');
    if (preg_match('/^\s*([A-Z][a-z]+ \d{1,2}, \d{4})\s*$/m', $body, $d) && ($ts = strtotime($d[1])) !== false) $date = date('Y-m-d', $ts);
    elseif (preg_match('/^\s*(\d{1,2}\/\d{1,2}\/\d{4})\s*$/m', $body, $d) && ($ts = strtotime($d[1])) !== false) $date = date('Y-m-d', $ts);
    $pty = $k === 'EXT OPPOSE' ? 'EXT' : ''; $eno = '';
    if (preg_match_all('/v\?pno=(\d+)&(?:amp;)?pty=([A-Z]{3})&(?:amp;)?eno=(\d+)/', $body, $l, PREG_SET_ORDER)) { $last = end($l); $pno = $last[1]; $pty = $last[2]; $eno = $last[3]; }
    $num = preg_match('/(?:Application )?Serial No(?:\(s\))?\.?\s*(\d{8})/i', $body, $n) ? $n[1] : (preg_match('/Registration No(?:\(s\))?\.?\s*(\d{6,8})/i', $body, $n) ? $n[1] : ($k === 'EXT OPPOSE' ? $pno : ''));
    return ['kind' => 'trademark', 'app_no' => $num, 'doc_code' => 'TTAB ' . $k, 'date' => $date, 'docket' => '', 'summary' => 'TTAB ' . strtolower($k) . ($k === 'EXT OPPOSE' ? '' : ' (proceeding ' . $pno . ')'), 'pno' => $pno, 'pty' => $pty ?: 'OPP', 'eno' => $eno];
}
/** Schedule rows of a TTAB order ("Time to Answer   3/26/2026") -> ['Time to Answer' => '2026-03-26', ...]. */
function ttab_schedule(string $text): array {
    $out = [];
    if (preg_match_all('/^\s*([A-Z][A-Za-z\'’ \-\/()0-9]+?)\s{2,}(\d{1,2}\/\d{1,2}\/\d{4})\s*$/m', $text, $mm, PREG_SET_ORDER))
        foreach ($mm as $m) { $ts = strtotime($m[2]); if ($ts !== false) $out[trim(str_replace('’', "'", $m[1]))] = date('Y-m-d', $ts); }
    return $out;
}
/** Time limit to respond to a Madrid provisional refusal, from the decision's text: ISO date, or ['months'|'days' => n] relative to notification, or null. */
function madrid_time_limit(string $text): array|string|null {
    $t = preg_replace('/[ \t]+/', ' ', $text);
    if (preg_match('/time limit ends on:?\s*(\d{4}-\d{2}-\d{2})/i', $t, $m)) return $m[1];
    if (preg_match('/Time Limit\)?\s*:?\s*\d{2}\/\d{2}\/\d{4}\s*\((\d{2})\/(\d{2})\/(\d{4})\)/i', $t, $m)) return "$m[3]-$m[2]-$m[1]";   // KR: issued(limit), DD/MM/YYYY
    if (preg_match('/(?:received|filed|submitted)[^.]{0,60}?\bby\s+([A-Z][a-z]+\.? \d{1,2}, \d{4})/i', preg_replace('/\s+/', ' ', $t), $m) && ($ts = strtotime($m[1])) !== false) return date('Y-m-d', $ts);
    $num = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'six' => 6, 'fifteen' => 15, 'thirty' => 30, 'sixty' => 60];
    if (preg_match('/(?:time limit is )?within (\d{1,3}|one|two|three|four|six|fifteen|thirty|sixty) (months?|days)\s+(?:from|of|upon)/i', preg_replace('/\s+/', ' ', $t), $m)) { $v = ctype_digit($m[1]) ? (int)$m[1] : $num[strtolower($m[1])]; return [str_starts_with(strtolower($m[2]), 'month') ? 'months' : 'days' => $v]; }
    return null;
}

/** Response period (months) stated in a post-registration Office action, and its issue date when printed: ['months' => n, 'issued' => ISO|null]; null when not stated.
 *  "A complete response must be received within 6 months from the issuance date of this Office action" / "The owner has six months from the issuance date of this letter". */
function postreg_period(string $text): ?array {
    $t = preg_replace('/\s+/', ' ', $text); $num = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6];
    if (!preg_match('/\b(?:within|has|have)\s+(\d{1,2}|one|two|three|four|five|six)\s*(?:\(\d\)\s*)?months?\s+(?:from|of|after)\s+the\s+(?:issuance|issue|mailing)\s+date/i', $t, $m)) return null;
    $n = ctype_digit($m[1]) ? (int)$m[1] : $num[strtolower($m[1])]; if ($n < 1 || $n > 6) return null;
    $iss = preg_match('/Issue date:\s*([A-Z][a-z]+ \d{1,2}, \d{4})/', $t, $d) && ($ts = strtotime($d[1])) !== false ? date('Y-m-d', $ts) : null;
    return ['months' => $n, 'issued' => $iss];
}
/** TSDR docId prefix of a trademark notice -> the kind the rules docket (checked against TSDR history for 62 e-mails, 2026-09-30).
 *  Unknown prefixes are treated as an Office action: a deadline to check beats a silent miss. */
function tm_docid_kind(string $prefix): string {
    return ['NFIN' => 'OFFICE ACTION', 'FREF' => 'OFFICE ACTION', 'PROA' => 'OFFICE ACTION', 'CEAP' => 'OFFICE ACTION', 'SUSIQ' => 'OFFICE ACTION',
            'NRAM' => 'INCOMPLETE RESPONSE', 'EXAMA' => "EXAMINER'S AMENDMENT", 'SUSLT' => 'SUSPENSION', 'PROARR' => 'POSTREG OFFICE ACTION'][strtoupper($prefix)] ?? 'OFFICE ACTION';
}
