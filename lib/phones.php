<?php
require_once __DIR__ . '/firm.php';
/**
 * Client phone numbers (2026-09-30). Sources: the "Billing to" block of Helcim invoice emails (name, company, address,
 * phone, email) and PaySimple customer exports (Phone # / Alt Phone #). Helcim transaction receipts and PaySimple receipts /
 * invoice emails carry only the firm's number, so they give nothing. clients.phone holds a comma-separated list.
 */
function norm_phone(string $s): string {
    $s = trim($s); $d = preg_replace('/\D/', '', $s);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    if (strlen($d) === 10 && !str_starts_with($s, '+') || (strlen($d) === 10 && str_starts_with($s, '+1'))) return substr($d, 0, 3) . '-' . substr($d, 3, 3) . '-' . substr($d, 6);
    return strlen($d) >= 7 ? '+' . ltrim($d, '+') : '';
}
function phone_list(string $s): array { $o = []; foreach (preg_split('/[,;\/]+/', $s) as $p) if (($n = norm_phone($p)) !== '' && !in_array($n, $o, true)) $o[] = $n; return $o; }
function firm_phones(): array { static $p = null; return $p ??= array_values(array_filter(array_map('norm_phone', array_values(firm('phones'))))); }
/** Adds numbers to a client's list (never removes); returns how many were new. */
function add_client_phones(string $code, array $phones, string $src): int {
    $c = q1('SELECT phone FROM clients WHERE code=?', [$code]); if (!$c) return 0;
    $have = phone_list((string)$c['phone']); $new = 0;
    foreach ($phones as $p) { $p = norm_phone($p); if ($p === '' || in_array($p, firm_phones(), true) || in_array($p, $have, true)) continue; $have[] = $p; $new++; }
    if ($new) { run('UPDATE clients SET phone=? WHERE code=?', [implode(', ', $have), $code]); audit('client_phone', "$code: " . implode(', ', $have) . " ($src)"); }
    return $new;
}
/** Name for matching: letters/digits only, company suffixes (Inc, LLC, Ltd, Corp, Co) dropped. */
function name_key(string $n): string { return search_norm(preg_replace('/[,.]?\s+(inc|incorporated|llc|l\.l\.c|ltd|limited|corp|corporation|co|company|pllc|pc)\.?\s*$/i', '', trim($n))); }
/** Client code for a payer: file-number prefix of the invoice id (CHL001.20260916 -> CHL), else a known address, else the name. */
function phone_client(string $inv, string $email, array $names): ?string {
    $inv = strtoupper(trim($inv));
    if (!preg_match('/^INV\d{6}/', $inv) && preg_match('/^([A-Z]{3,4})\d{3}/', $inv, $x)) {   // INV001502 = Helcim's own numbering, not a file number
        if (preg_match('/^[A-Z]{3,4}\d{3}[A-Z]?/', $inv, $ref) && ($mm = q1('SELECT client_code FROM matters WHERE upper(ref)=? AND client_code<>""', [$ref[0]]))) return $mm['client_code'];
        if (q1('SELECT 1 FROM clients WHERE code=?', [$x[1]])) return $x[1];
    }
    $e = strtolower(trim($email));
    if ($e !== '' && !firm_address($e)) foreach (q('SELECT code, email, email_tm, email_patent, email_ap, alt_emails FROM clients') as $c)
        if (in_array($e, array_map('strtolower', split_addresses(implode(',', [$c['email'], $c['email_tm'], $c['email_patent'], $c['email_ap'], $c['alt_emails']]))), true)) return $c['code'];
    // Same company e-mail domain as exactly one client (free mail domains excluded).
    if ($e !== '' && preg_match('/@([a-z0-9.\-]+)$/', $e, $dm) && !preg_match('/^(gmail|googlemail|yahoo|ymail|hotmail|outlook|live|msn|aol|icloud|me|mac|comcast|verizon|optonline|att|sbcglobal|protonmail|proton|mail|gmx|yandex|qq|163|126)\./', $dm[1])) {
        $hits = q("SELECT code FROM clients WHERE (email || ',' || email_tm || ',' || email_patent || ',' || email_ap || ',' || alt_emails) LIKE ?", ['%@' . $dm[1] . '%']);
        if (count($hits) === 1) return $hits[0]['code'];
    }
    static $byName = null;
    if ($byName === null) { $byName = []; foreach (q('SELECT code, name FROM clients WHERE name<>""') as $c) $byName[name_key($c['name'])][] = $c['code']; }
    foreach ($names as $n) { $k = name_key($n); if (strlen($k) < 5) continue; if (count($byName[$k] ?? []) === 1) return $byName[$k][0];
        if (preg_match('/^\s*([^,]+),\s*(.+)$/', $n, $lf) && count($byName[$k2 = name_key($lf[2] . ' ' . $lf[1])] ?? []) === 1) return $byName[$k2][0]; }   // "Last, First"
    return null;
}
/** "Billing to" block of a Helcim invoice email -> [invoice, name, company, phone, email]. */
function helcim_billing(string $subject, string $text): ?array {
    if (!preg_match('/^Invoice - (\S+)/', $subject, $iv)) return null;
    if (!preg_match('/Billing to\s*\n(.*?)\n\s*Item\s*\n/s', $text, $b)) return null;
    $lines = array_values(array_filter(array_map('trim', explode("\n", $b[1])), 'strlen'));
    $out = ['invoice' => $iv[1], 'name' => $lines[0] ?? '', 'company' => '', 'phone' => '', 'email' => ''];
    foreach ($lines as $i => $l) {
        if (filter_var($l, FILTER_VALIDATE_EMAIL)) $out['email'] = $l;
        elseif (preg_match('/^\+?[\d\s().\-]{7,20}$/', $l) && strlen(preg_replace('/\D/', '', $l)) >= 7) $out['phone'] = $l;
        elseif ($i === 1 && !preg_match('/\d/', $l)) $out['company'] = $l;
    }
    return $out;
}
/** Reads Helcim invoice emails from the last $days days (read-only token) and files the payers' phone numbers. */
function pull_client_phones(int $days = 3, bool $apply = true): array {
    $out = ['emails' => 0, 'phones' => 0, 'added' => 0, 'unmatched' => [], 'error' => null];
    $c = gmail_cfg(); if (!$c) { $out['error'] = 'Gmail is not configured on this machine.'; return $out; }
    $c['max_threads'] = 500;
    try {
        foreach (gm_search_threads('from:donotreply@app.helcim.com subject:Invoice newer_than:' . $days . 'd', $c) as $tid) {
            usleep(400000); try { $thread = gm_thread_full($tid, $c); } catch (Throwable $e) { if (!str_contains($e->getMessage(), '403')) throw $e; sleep(65); $thread = gm_thread_full($tid, $c); }   // Gmail per-minute quota
            foreach ($thread as $msg) {
            $text = preg_replace('/^[^\n]*[{};][^\n]*$/m', '', (string)($msg['body']['text'] ?? '')); $text = preg_replace("/\n\s*\n+/", "\n", preg_replace("/[ \t]+/", ' ', $text));
            $b = helcim_billing((string)$msg['subject'], $text); if (!$b) continue; $out['emails']++;
            if ($b['phone'] === '') continue; $out['phones']++;
            $code = phone_client($b['invoice'], $b['email'], [$b['company'], $b['name']]);
            if (!$code) { $out['unmatched'][$b['name'] . ' ' . norm_phone($b['phone'])] = $b['invoice'] . ' ' . $b['email']; continue; }
            $out['added'] += $apply ? add_client_phones($code, [$b['phone']], 'Helcim invoice ' . $b['invoice']) : 0;
            if (!$apply) $out['preview'][] = "$code <- " . norm_phone($b['phone']) . " ({$b['invoice']} {$b['name']})";
        }
        }
    } catch (Throwable $e) { $out['error'] = $e->getMessage(); }
    return $out;
}
/** PaySimple customer export (CSV) -> phones by email / company / person. */
function import_paysimple_phones(string $csv, bool $apply = true): array {
    $out = ['rows' => 0, 'added' => 0, 'unmatched' => []]; $f = fopen($csv, 'r'); $h = fgetcsv($f);
    while (($r = fgetcsv($f)) !== false) { $r = array_combine($h, array_pad($r, count($h), ''));
        $ph = array_filter([$r['Phone #'] ?? '', $r['Alt Phone #'] ?? '']); if (!$ph) continue; $out['rows']++;
        $code = phone_client('', (string)($r['Email Address'] ?? ''), [(string)($r['Company Name'] ?? ''), (string)($r['Customer Name'] ?? ''), trim(($r['First Name'] ?? '') . ' ' . ($r['Last Name'] ?? ''))]);
        if (!$code && ($r['Alt Email Address'] ?? '') !== '') $code = phone_client('', $r['Alt Email Address'], []);
        if (!$code) { $out['unmatched'][] = trim(($r['Company Name'] ?? '') . ' / ' . ($r['Customer Name'] ?? '') . ' ' . implode(', ', $ph)); continue; }
        $out['added'] += $apply ? add_client_phones($code, $ph, 'PaySimple export') : 0;
        if (!$apply) $out['preview'][] = "$code <- " . implode(', ', array_map('norm_phone', $ph));
    }
    return $out;
}
/** Booking-system (Easy!Appointments) customers: [{first_name,last_name,email,phone_number,mobile_number}, ...] (2026-10-01). */
function import_ea_phones(string $json, bool $apply = true): array {
    $out = ['rows' => 0, 'added' => 0, 'unmatched' => []];
    foreach (json_decode((string)file_get_contents($json), true) ?: [] as $r) { $out['rows']++;
        $ph = array_filter([$r['phone_number'] ?? '', $r['mobile_number'] ?? '']); $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $code = phone_client('', (string)($r['email'] ?? ''), [$name]);
        if (!$code) { $out['unmatched'][] = "$name " . implode(', ', $ph); continue; }
        $out['added'] += $apply ? add_client_phones($code, $ph, 'booking system') : 0; if (!$apply) $out['preview'][] = "$code <- " . implode(', ', array_map('norm_phone', $ph));
    }
    return $out;
}
/** The writer's own part of an email: everything above the quoted reply / forwarded block. */
function own_part(string $t): string {
    $t = preg_split('/^\s*(On .{5,200}wrote:|-{2,}\s*Original Message|From:\s.+|_{10,}|>.*)$/mi', $t, 2)[0];
    return $t;
}
/** Phone numbers in a block of text (US 10-digit forms and +international), firm numbers excluded. */
function text_phones(string $t): array {
    $t = preg_replace('/^.*\bfax\b.*$/mi', '', $t);   // fax lines are not phones
    preg_match_all('/(?<![\d\/])(?:\+?1[\s.\-]?)?\(?\b[2-9]\d{2}\)?[\s.\-]?\d{3}[\s.\-]\d{4}\b|\+\d{1,3}[\s.\-]?\(?\d{1,4}\)?(?:[\s.\-]?\d{2,4}){2,4}/', $t, $m);
    $o = []; foreach ($m[0] as $p) { $n = norm_phone($p); if ($n !== '' && !in_array($n, firm_phones(), true) && !in_array($n, $o, true) && strlen(preg_replace('/\D/', '', $n)) <= 15) $o[] = $n; }
    return $o;
}
/** Clients with no phone: read their own recent emails (Gmail, read-only) and take numbers from their signatures. */
function signature_phones(int $limit = 0, bool $apply = true): array {
    $out = ['clients' => 0, 'searched' => 0, 'found' => 0, 'added' => 0, 'error' => null]; $c = gmail_cfg(); if (!$c) { $out['error'] = 'Gmail not configured'; return $out; }
    $c['max_threads'] = 4;
    $rows = q("SELECT code, email, email_tm, email_patent, email_ap, alt_emails FROM clients WHERE COALESCE(phone,'')='' ORDER BY code" . ($limit ? " LIMIT $limit" : ''));
    foreach ($rows as $cl) { $out['clients']++;
        $addrs = array_values(array_filter(split_addresses(implode(',', [$cl['email'], $cl['email_tm'], $cl['email_patent'], $cl['email_ap'], $cl['alt_emails']])), fn($a) => !firm_address($a)));
        if (!$addrs) continue; $addrs = array_slice($addrs, 0, 6); $out['searched']++;
        try { sleep(3); $found = [];   // gentle: this token is shared with Mail lookup (a fast scan blocked his searches, 2026-10-01)
            foreach (gm_search_threads('from:(' . implode(' OR ', $addrs) . ')', $c) as $tid) { sleep(1);
                foreach (gm_thread_full($tid, $c) as $msg) { $from = strtolower(implode(',', hdr_addresses((string)$msg['from'])));
                    if (!array_filter($addrs, fn($a) => str_contains($from, strtolower($a)))) continue;   // only what the client wrote
                    foreach (text_phones(own_part((string)($msg['body']['text'] ?? ''))) as $p) $found[$p] = true; } }
        } catch (Throwable $e) { if (str_contains($e->getMessage(), '403')) { sleep(65); continue; } $out['error'] = $e->getMessage(); continue; }
        if ($found) $hits[$cl['code']] = array_keys($found);
    }
    // A number found for two or more clients is someone else's (a colleague cc'd, an agent, a forwarded signature): drop it.
    $cnt = []; foreach ($hits ?? [] as $ps) foreach ($ps as $p) $cnt[$p] = ($cnt[$p] ?? 0) + 1;
    foreach ($hits ?? [] as $code => $ps) { $ps = array_slice(array_values(array_filter($ps, fn($p) => $cnt[$p] === 1)), 0, 3); if (!$ps) continue; $out['found']++;
        $out['added'] += $apply ? add_client_phones($code, $ps, 'email signature') : 0; if (!$apply) $out['preview'][] = $code . ' <- ' . implode(', ', $ps); }
    $out['shared_dropped'] = array_keys(array_filter($cnt, fn($n) => $n > 1));
    return $out;
}
