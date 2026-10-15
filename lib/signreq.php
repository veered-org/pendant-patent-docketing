<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * Ask client to sign trademark (2026-10-06, BOO001-BOO003). New filing > "Ask client to sign trademark": type the file numbers
 * (BOO001; BOO002; BOO003). Madder finds Trademark Center's "Trademark application MARK ready for review and signature - Docket number
 * REF" e-mail for each in Gmail, takes the review-and-sign link (never the "Use this link by <deadline>" date: the attorney takes it out),
 * and opens the send page with his signing email (his SKY029/SKY030 wording). One to-do on the first file number carries it; Send closes it.
 * No template row: the email is built here, so the nightly reminders never pick the to-do up.
 */
const SIGN_EVENT = 'Ask Client to Sign Trademark';

function sign_schema(): void { static $d = false; if ($d) return; $d = true; db()->exec("CREATE TABLE IF NOT EXISTS sign_requests (event_id INTEGER PRIMARY KEY, refs TEXT, links TEXT, created_by TEXT, created_at TEXT DEFAULT (datetime('now')))"); }

/** "BOO001; boo002 BOO003" -> ['BOO001', 'BOO002', 'BOO003'] (file numbers only, order kept). */
function sign_refs(string $s): array {
    $out = [];
    foreach (preg_split('/[\s;,]+/', strtoupper($s)) as $r) if (preg_match('/^[A-Z]{3}\d{3}[A-Z0-9.\-]*$/', $r) && !in_array($r, $out, true)) $out[] = $r;
    return $out;
}

/** The newest Trademark Center signature request for each file number: [REF => ['mark', 'url', 'ts']]; a file number with none is left out. */
function gmail_sign_links(array $refs): array {
    $c = gmail_cfg(); if (!$c) throw new RuntimeException('Gmail is not configured on this machine.');
    $c['max_threads'] = 10; $out = [];
    foreach ($refs as $ref) {
        foreach (gm_search_threads('from:TMOfficialNotices@uspto.gov subject:"ready for review and signature" subject:"' . $ref . '" newer_than:90d', $c) as $tid) {
            $res = gm_api('/users/me/threads/' . rawurlencode($tid), ['format' => 'full'], $c);
            foreach ($res['messages'] ?? [] as $msg) {
                $subj = ''; foreach ($msg['payload']['headers'] ?? [] as $hd) if (strtolower($hd['name']) === 'subject') $subj = trim((string)$hd['value']);
                if (!preg_match('/^Trademark application (.+?) ready for review and signature - Docket number (\S+)\s*$/i', $subj, $sm) || strcasecmp($sm[2], $ref) !== 0) continue;
                $html = gm_find_part($msg['payload'], 'text/html') ?? gm_find_part($msg['payload'], 'text/plain') ?? '';
                if (!preg_match('#https://trademarkcenter\.uspto\.gov/[^\s"\'<>]*review-and-sign\?id=[0-9a-f\-]{20,}#i', $html, $um)) continue;
                $ts = (int)(($msg['internalDate'] ?? 0) / 1000);
                if (isset($out[$ref]) && $out[$ref]['ts'] >= $ts) continue;   // a corrected application sends a new link: the newest wins
                $out[$ref] = ['mark' => trim($sm[1]), 'url' => $um[0], 'ts' => $ts];
            }
        }
    }
    return $out;
}

/** "A", "A and B", "A, B and C" */
function sign_list(array $xs): string { $xs = array_values($xs); $n = count($xs); return $n < 2 ? (string)($xs[0] ?? '') : implode(', ', array_slice($xs, 0, $n - 1)) . ' and ' . $xs[$n - 1]; }

/** Subject + body of the signing email (send-page markup: <m>MARK</m>, <n u="URL">text</n>). */
function sign_email(array $links): array {
    $many = count($links) > 1; $s = $many ? 's' : '';
    $subject = implode(', ', array_keys($links)) . ': Your U.S. trademark application' . $s . ' for ' . sign_list(array_column($links, 'mark')) . ($many ? ' are' : ' is') . ' ready to sign';
    $body = "Please find below a link to view and sign your trademark application$s. First, read through and check for accuracy. Then please sign by typing your name between two slashes, like this: " . firm('sign_example') . "\n\n"
          . "After signing, email me to confirm the U.S. Trademark Office has notified me of your signature.  Further, there are no guarantees that your trademark application will be approved though a preliminary search was conducted to try and reduce the chances of rejection.\n\n";
    foreach ($links as $ref => $l) $body .= $ref . ': <m>' . $l['mark'] . '</m> - Use this link to <n u="' . $l['url'] . '">review and sign your trademark application</n>.' . "\n\n";
    return ['subject' => $subject, 'body' => rtrim($body)];
}

/** The trademark record for $ref, created (no serial number yet) when the filing receipt has not made one; the receipt then fills it in. */
function sign_matter(string $ref): array {
    $m = q1('SELECT * FROM matters WHERE ref=? AND kind="trademark" ORDER BY dead, id DESC', [$ref]); if ($m) return $m;
    $code = client_from_ref($ref); if ($code === '') throw new RuntimeException("$ref is not a file number.");
    run('INSERT OR IGNORE INTO clients(code) VALUES (?)', [$code]);
    run('INSERT INTO matters(kind,ref,client_code,title,country,app_no,notes) VALUES ("trademark",?,?,"","United States","",?)', [$ref, $code, 'Created for the signing request (New filing > Ask client to sign trademark)']);
    $id = (int)db()->lastInsertId(); audit('add_matter', "$ref (for the signing request)", $id);
    return q1('SELECT * FROM matters WHERE id=?', [$id]);
}

/** Make (or refresh) the to-do for these file numbers. Returns ['event_id', 'missing' => refs with no signature e-mail]. */
function sign_request(array $refs): array {
    sign_schema(); if (!$refs) throw new RuntimeException('Type at least one file number, e.g. BOO001; BOO002.');
    $links = gmail_sign_links($refs); $missing = array_values(array_diff($refs, array_keys($links)));
    if (!$links) throw new RuntimeException('No "ready for review and signature" e-mail from Trademark Center was found for ' . implode(', ', $refs) . '.');
    $ordered = []; foreach ($refs as $r) if (isset($links[$r])) $ordered[$r] = $links[$r];
    $ms = []; foreach (array_keys($ordered) as $r) $ms[$r] = sign_matter($r);
    foreach ($ordered as $r => $l) if (trim((string)$ms[$r]['title']) === '') run('UPDATE matters SET title=? WHERE id=?', [$l['mark'], $ms[$r]['id']]);
    $first = $ms[array_key_first($ordered)];
    $note = 'Email the client the signing link' . (count($ordered) > 1 ? 's' : '') . ': ' . implode('; ', array_map(fn($r, $l) => "$r $l[mark]", array_keys($ordered), $ordered));
    $open = q1('SELECT id FROM events WHERE matter_id=? AND type=? AND status IN ("open","proposed")', [$first['id'], SIGN_EVENT]);
    if ($open) { $eid = (int)$open['id']; run('UPDATE events SET note=?, due_date=? WHERE id=?', [$note, today(), $eid]); }
    else { run('INSERT INTO events(matter_id,type,due_date,status,source,note) VALUES (?,?,?,"open","rule",?)', [$first['id'], SIGN_EVENT, today(), $note]); $eid = (int)db()->lastInsertId(); }
    run('INSERT OR REPLACE INTO sign_requests(event_id,refs,links,created_by) VALUES (?,?,?,?)', [$eid, implode('; ', array_keys($ordered)), json_encode($ordered), auth_user()]);
    audit('sign_request', $note, (int)$first['id']);
    return ['event_id' => $eid, 'missing' => $missing, 'matter' => $first];
}

/** The stored links of a signing to-do, or null. */
function sign_links_for(int $eventId): ?array { sign_schema(); $r = q1('SELECT links FROM sign_requests WHERE event_id=?', [$eventId]); $l = $r ? json_decode((string)$r['links'], true) : null; return is_array($l) && $l ? $l : null; }

/*
 * Statement of Use prepared (2026-10-08, XYZ060): on an open "Statement of Use ..." deadline the matter page offers
 * "Statement of Use prepared". Madder finds TEAS's "<attorney> requests you to e-sign the Trademark/Service Mark Allegation of Use
 * form for <serial>" e-mail, takes its sign.service link, and opens the send page with his signing email (his XYZ001/XYZ002 wording,
 * 2023-12-05) in the client's thread. A to-do carries it; Send closes it and notes the deadline. The deadline itself stays open
 * until the filing is seen.
 */
const SOU_SIGN_EVENT = 'Ask Client to Sign Statement of Use';

function is_sou_deadline(string $type): bool { return (bool)preg_match('/^Statement of Use(?: or \d(?:st|nd|rd|th) Extension Due)?$/', $type); }

/** The newest TEAS e-signature request for this serial number: ['form', 'url', 'ts', 'expires' (Y-m-d or '')], or null. */
function gmail_teas_sign_link(string $serial): ?array {
    $serial = preg_replace('/\D/', '', $serial); if (strlen($serial) !== 8) return null;
    $c = gmail_cfg(); if (!$c) throw new RuntimeException('Gmail is not configured on this machine.');
    $c['max_threads'] = 10; $best = null;
    foreach (gm_search_threads('from:TEAS@uspto.gov subject:"requests you to e-sign" subject:' . $serial . ' newer_than:90d', $c) as $tid) {
        $res = gm_api('/users/me/threads/' . rawurlencode($tid), ['format' => 'full'], $c);
        foreach ($res['messages'] ?? [] as $msg) {
            $subj = ''; foreach ($msg['payload']['headers'] ?? [] as $hd) if (strtolower($hd['name']) === 'subject') $subj = trim((string)$hd['value']);
            if (!preg_match('/requests you to e-sign the (.+?) form for (\d{8})\s*$/i', $subj, $sm) || $sm[2] !== $serial) continue;
            $html = html_entity_decode((string)(gm_find_part($msg['payload'], 'text/html') ?? gm_find_part($msg['payload'], 'text/plain') ?? ''), ENT_QUOTES | ENT_HTML5);
            if (!preg_match('#https://teas\.uspto\.gov/[^\s"\'<>]*sign\.service\?[^\s"\'<>]+#i', $html, $um)) continue;
            $ts = (int)(($msg['internalDate'] ?? 0) / 1000); if ($best && $best['ts'] >= $ts) continue;   // a re-sent request (corrected form) wins
            $exp = preg_match('/will expire on \w{3} (\w{3} \d{1,2}) [\d:]+ \w+ (\d{4})/', $html, $em) && ($d = DateTimeImmutable::createFromFormat('M j Y', $em[1] . ' ' . $em[2])) ? $d->format('Y-m-d') : '';
            $best = ['form' => trim($sm[1]), 'url' => $um[0], 'ts' => $ts, 'expires' => $exp];
        }
    }
    return $best;
}

/** What the TEAS form is, in the client email: the Statement of Use, or an extension request. */
function sou_form_name(string $form): string { return stripos($form, 'extension') !== false ? 'request for an extension of time to file the Statement of Use' : 'Statement of Use'; }

/** Make (or refresh) the signing to-do for the open Statement of Use deadline $ev. Returns ['event_id', 'matter']. */
function sou_sign_request(array $ev): array {
    sign_schema();
    if (!is_sou_deadline((string)$ev['type']) || !in_array($ev['status'], ['open', 'proposed'], true)) throw new RuntimeException('That is not an open Statement of Use deadline.');
    $m = q1('SELECT * FROM matters WHERE id=?', [$ev['matter_id']]);
    if (!$m || $m['kind'] !== 'trademark' || preg_replace('/\D/', '', (string)$m['app_no']) === '') throw new RuntimeException('This trademark has no serial number, so the TEAS signature e-mail cannot be found.');
    $l = gmail_teas_sign_link((string)$m['app_no']);
    if (!$l) throw new RuntimeException('No TEAS "requests you to e-sign" e-mail for serial ' . fmt_app((string)$m['app_no']) . ' was found in Gmail (last 90 days). Send the form for e-signature from TEAS to yourself first.');
    $l += ['mark' => clean_title((string)$m['title']), 'deadline' => (string)$ev['due_date'], 'deadline_id' => (int)$ev['id']];
    $note = 'Email the client the ' . sou_form_name($l['form']) . ' signing link' . ($l['expires'] ? ' (link expires ' . $l['expires'] . ')' : '');
    $open = q1('SELECT id FROM events WHERE matter_id=? AND type=? AND status IN ("open","proposed")', [$m['id'], SOU_SIGN_EVENT]);
    if ($open) { $eid = (int)$open['id']; run('UPDATE events SET note=?, due_date=? WHERE id=?', [$note, today(), $eid]); }
    else { run('INSERT INTO events(matter_id,type,due_date,status,source,note) VALUES (?,?,?,"open","rule",?)', [$m['id'], SOU_SIGN_EVENT, today(), $note]); $eid = (int)db()->lastInsertId(); }
    run('INSERT OR REPLACE INTO sign_requests(event_id,refs,links,created_by) VALUES (?,?,?,?)', [$eid, $m['ref'], json_encode([$m['ref'] => $l]), auth_user()]);
    audit('sou_sign_request', $note, (int)$m['id']);
    return ['event_id' => $eid, 'matter' => $m];
}

/** Subject + body of the Statement of Use signing email (his XYZ001 wording). */
function sou_sign_email(string $ref, array $l): array {
    $what = sou_form_name((string)$l['form']); $mark = (string)($l['mark'] ?? '');
    $body = "The $what for your trademark" . ($mark !== '' ? " <m>$mark</m>" : '') . " is ready for your signature. Click the link below to open it on the U.S. Trademark Office website. First, read through and check for accuracy. Then sign there by typing your name between two slashes, like this: " . firm('sign_example') . "\n\n"
          . "$ref: <n u=\"" . $l['url'] . "\">Click here to review and sign the $what</n>.\n\n"
          . "After signing, send me an email to make sure the U.S. Trademark Office has notified me that you have signed.";
    if (($l['deadline'] ?? '') !== '') $body .= "\n\nThe deadline to file is " . fmt_long((string)$l['deadline']) . '.';
    return ['subject' => "$ref: " . ucfirst($what) . ($mark !== '' ? " for $mark" : '') . ' ready to sign', 'body' => $body];
}

/*
 * Declaration / renewal prepared (2026-10-14, XYZ001): on an open post-registration deadline (§8, §8 & 9, §8 & 15, §71,
 * §71 & 15, renewal, and their grace periods) the matter page offers "Send prepared declaration". Madder finds Trademark Center's
 * "Trademark Combined Sections 8 & 9 Declaration of Use and Renewal MARK ready for review and signature - Docket number REF" e-mail
 * (newest wins: a corrected filing sends a new link), and opens the send page with the signing email (the SOU wording). A to-do
 * carries it; Send closes it and notes the deadline, which stays open until the filing is seen.
 */
const POSTREG_SIGN_EVENT = 'Ask Client to Sign Declaration';

/** A post-registration deadline (not the window opening). */
function is_postreg_deadline(string $type): bool { return (bool)preg_match('/^(?:Section (?:8|71)\b.*\bEnds?|Renewal(?: (?:Period|Grace Period) Ends)?)$/', $type); }

/** The newest Trademark Center post-registration signature request for $ref: ['form', 'url', 'ts', 'expires'], or null. $mark is cut off the
 *  subject's "<form> <MARK>"; $serial (when the e-mail shows the TSDR mark image) must match. */
function gmail_postreg_sign_link(string $ref, string $mark = '', string $serial = ''): ?array {
    $c = gmail_cfg(); if (!$c) throw new RuntimeException('Gmail is not configured on this machine.');
    $c['max_threads'] = 10; $best = null; $serial = preg_replace('/\D/', '', $serial);
    foreach (gm_search_threads('from:TMOfficialNotices@uspto.gov subject:"ready for review and signature" subject:"' . $ref . '" newer_than:90d', $c) as $tid) {
        $res = gm_api('/users/me/threads/' . rawurlencode($tid), ['format' => 'full'], $c);
        foreach ($res['messages'] ?? [] as $msg) {
            $subj = ''; foreach ($msg['payload']['headers'] ?? [] as $hd) if (strtolower($hd['name']) === 'subject') $subj = trim((string)$hd['value']);
            if (!($p = postreg_subject($subj, $mark)) || strcasecmp($p['ref'], $ref) !== 0) continue;
            $html = html_entity_decode((string)(gm_find_part($msg['payload'], 'text/html') ?? gm_find_part($msg['payload'], 'text/plain') ?? ''), ENT_QUOTES | ENT_HTML5);
            if (!preg_match('#https://trademarkcenter\.uspto\.gov/post-registration/[^\s"\'<>]*review-and-sign\?id=[0-9a-f\-]{20,}#i', $html, $um)) continue;
            if (strlen($serial) === 8 && preg_match('#tsdr\.uspto\.gov/img/(\d{8})/#', $html, $im) && $im[1] !== $serial) continue;   // another registration under the same file number
            $ts = (int)(($msg['internalDate'] ?? 0) / 1000); if ($best && $best['ts'] >= $ts) continue;
            $best = ['form' => $p['form'], 'url' => $um[0], 'ts' => $ts, 'expires' => postreg_expires($html)];
        }
    }
    return $best;
}

/** "Trademark Combined Sections 8 & 9 Declaration of Use and Renewal MARK ready for review and signature - Docket number REF" -> ['form', 'ref'];
 *  null for an application (that is sign_request's). */
function postreg_subject(string $subj, string $mark = ''): ?array {
    if (!preg_match('/^Trademark (.+?) ready for review and signature - Docket number (\S+)\s*$/i', $subj, $sm) || preg_match('/^application\b/i', $sm[1])) return null;
    $form = trim($sm[1]); $mark = trim($mark);
    if ($mark !== '' && strlen($form) > strlen($mark) && strcasecmp(substr($form, -strlen($mark)), $mark) === 0) $form = rtrim(substr($form, 0, -strlen($mark)));
    elseif (preg_match('/^(.*\b(?:Renewal|Use|Incontestability|Nonuse|Excusable Nonuse))\b/i', $form, $fm)) $form = $fm[1];   // mark unknown: cut after the form's last word
    return ['form' => $form, 'ref' => strtoupper($sm[2])];
}

/** "will expire <b><em>Sunday December 13, 2026 at 12:33:00 PM ET</em></b>" -> '2026-12-13' ('' if absent). */
function postreg_expires(string $html): string {
    $t = preg_replace('/\s+/', ' ', strip_tags($html));
    return preg_match('/will expire (?:on )?(?:\w+day,? )?(\w+ \d{1,2}, \d{4})/i', $t, $em) && ($d = DateTimeImmutable::createFromFormat('!F j, Y', $em[1])) ? $d->format('Y-m-d') : '';
}

/** "Combined Sections 8 & 9 Declaration of Use and Renewal" -> "Declaration of Use and Renewal (Sections 8 & 9)", for the client email. */
function postreg_form_name(string $form): string {
    $form = trim(preg_replace('/^Combined\s+/i', '', $form));
    return preg_match('/^(Sections? [\d &and]+?) (Declaration.*|Renewal.*)$/i', $form, $m) ? "$m[2] ($m[1])" : ($form !== '' ? $form : 'declaration');
}

/** Make (or refresh) the signing to-do for the open post-registration deadline $ev. Returns ['event_id', 'matter']. */
function postreg_sign_request(array $ev): array {
    sign_schema();
    if (!is_postreg_deadline((string)$ev['type']) || !in_array($ev['status'], ['open', 'proposed'], true)) throw new RuntimeException('That is not an open declaration or renewal deadline.');
    $m = q1('SELECT * FROM matters WHERE id=?', [$ev['matter_id']]);
    if (!$m || $m['kind'] !== 'trademark') throw new RuntimeException('That deadline is not on a trademark.');
    $mark = clean_title((string)$m['title']);
    $l = gmail_postreg_sign_link((string)$m['ref'], $mark, (string)$m['app_no']);
    if (!$l) throw new RuntimeException('No Trademark Center "ready for review and signature - Docket number ' . $m['ref'] . '" e-mail for a declaration or renewal was found in Gmail (last 90 days). Send the filing for signature from Trademark Center first (docket number ' . $m['ref'] . ').');
    $dl = $ev; if (days_until((string)$ev['due_date']) < 0 && function_exists('grace_successor') && ($g = grace_successor($ev))) $dl = $g;   // past the deadline: the grace period's last day
    $l += ['mark' => $mark, 'deadline' => (string)$dl['due_date'], 'deadline_id' => (int)$ev['id']];
    $note = 'Email the client the ' . postreg_form_name($l['form']) . ' signing link' . ($l['expires'] ? ' (link expires ' . $l['expires'] . ')' : '');
    $open = q1('SELECT id FROM events WHERE matter_id=? AND type=? AND status IN ("open","proposed")', [$m['id'], POSTREG_SIGN_EVENT]);
    if ($open) { $eid = (int)$open['id']; run('UPDATE events SET note=?, due_date=? WHERE id=?', [$note, today(), $eid]); }
    else { run('INSERT INTO events(matter_id,type,due_date,status,source,note) VALUES (?,?,?,"open","rule",?)', [$m['id'], POSTREG_SIGN_EVENT, today(), $note]); $eid = (int)db()->lastInsertId(); }
    run('INSERT OR REPLACE INTO sign_requests(event_id,refs,links,created_by) VALUES (?,?,?,?)', [$eid, $m['ref'], json_encode([$m['ref'] => $l]), auth_user()]);
    audit('postreg_sign_request', $note, (int)$m['id']);
    return ['event_id' => $eid, 'matter' => $m];
}

/** Subject + body of the declaration / renewal signing email (the SOU wording). */
function postreg_sign_email(string $ref, array $l): array {
    $what = postreg_form_name((string)$l['form']); $mark = (string)($l['mark'] ?? '');
    $body = "The $what for your trademark" . ($mark !== '' ? " <m>$mark</m>" : '') . " is ready for your signature. Click the link below to open it on the U.S. Trademark Office website. First, read through and check for accuracy. Then sign there by typing your name between two slashes, like this: " . firm('sign_example') . "\n\n"
          . "$ref: <n u=\"" . $l['url'] . "\">Click here to review and sign the $what</n>.\n\n"
          . "After signing, send me an email to make sure the U.S. Trademark Office has notified me that you have signed.";
    if (($l['deadline'] ?? '') !== '') $body .= "\n\nThe deadline to file is " . fmt_long((string)$l['deadline']) . '.';
    return ['subject' => "$ref: " . ucfirst($what) . ($mark !== '' ? " for $mark" : '') . ' ready to sign', 'body' => $body];
}

/** 2026-10-14: Statements of Use, declarations and renewals (signing emails, reminders incl. "Remind Client" ticklers pointing at
 *  them, filed reports) start a NEW email by default, not a reply in the file's latest thread (whose subject can name other files). */
function fresh_subject_default(array $ev, string $report = ''): bool {
    if (in_array($report, ['sou_filed', 'sou_ext'], true)) return true;
    $type = (string)($ev['type'] ?? '');
    if ($type === 'Remind Client' && function_exists('remind_target') && ($tg = remind_target($ev))) $type = (string)$tg['type'];
    return in_array($type, [SOU_SIGN_EVENT, POSTREG_SIGN_EVENT], true) || is_sou_deadline($type) || (bool)preg_match('/^(?:Section (?:8|71)\b|Renewal\b)/', $type);
}
