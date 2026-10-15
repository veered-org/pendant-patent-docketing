<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * Pull USPTO notices straight out of the attorney's Gmail with the Mail-lookup app's
 * READ-ONLY token (gmail.readonly): nothing is marked read, moved or labelled,
 * so his inbox looks exactly as before. Every message id is remembered in
 * gmail_seen so a notice is docketed once. No forwarding filter is needed.
 */
function gmail_cfg(): ?array {
    $path = $GLOBALS['cfg']['gmail_config'] ?? ''; if ($path === '' || !is_file($path)) return null;
    $lib = dirname($path) . '/lib/gmail.php'; if (!is_file($lib)) return null;
    require_once $lib; $c = require $path; $c['max_threads'] = 100; return $c;
}
// Every e-mail from the USPTO (2026-10-03), plus WIPO's Madrid refusals. What each one becomes: lib/held.php.
const GMAIL_QUERY = 'newer_than:%dd (from:uspto.gov OR (from:e-marks@wipo.int subject:"812 Refusal"))';
function pull_uspto_mail(int $days = 7, bool $apply = true): array {
    $out = ['messages' => 0, 'new' => 0, 'items' => 0, 'skipped' => 0, 'held' => 0, 'ignored' => 0, 'receipts' => 0, 'results' => [], 'error' => null];
    $c = gmail_cfg(); if (!$c) { $out['error'] = 'Gmail is not configured on this machine (gmail_config).'; return $out; }
    held_schema(); $c['max_threads'] = 300;
    $seen = function (array $msg, string $result) use ($apply, &$out) { if ($apply) run('INSERT OR IGNORE INTO gmail_seen(msg_id,subject,result) VALUES (?,?,?)', [$msg['id'], (string)$msg['subject'], $result]); elseif (!str_starts_with($result, (string)($msg['subject'] ?? "\0"))) $out['preview'][] = ['subject' => (string)$msg['subject'], 'lines' => [$result]]; };
    try {
        foreach (gm_search_threads(sprintf(GMAIL_QUERY, $days), $c) as $tid) {
            foreach (gm_thread_full($tid, $c) as $msg) {
                $from = (string)($msg['from'] ?? '');
                if (!preg_match('/@([a-z0-9.\-]+\.)?uspto\.gov\b|e-marks@wipo\.int/i', $from)) continue;   // our own replies inside a USPTO thread
                $out['messages']++;
                if (q1('SELECT 1 FROM gmail_seen WHERE msg_id=?', [$msg['id']])) continue;
                $out['new']++; $subject = (string)$msg['subject'];
                if (($cn = firm('skip_customer_numbers')) && preg_match('/Customer Number\s*(' . implode('|', array_map('preg_quote', $cn)) . ')\b/i', $subject)) { $out['skipped']++; $seen($msg, 'skipped: not our customer number'); continue; }
                // One notice that cannot be processed must not stop the ones after it (audit 2026-10-02: an exception here ended the whole
                // pull, and since the message was never recorded it failed again every night, blocking everything listed after it).
                try {
                $bodyText = is_array($msg['body']) ? (string)($msg['body']['text'] ?? '') : (string)$msg['body'];
                if (is_array($msg['body']) && !empty($msg['body']['from_html']) && ($raw = gm_raw_html((string)$msg['id'], $c)) !== '') $bodyText = $raw;   // HTML-only notices: keep the TSDR link (docId), which the stripped text loses
                $recv = !empty($msg['ts']) ? date('Y-m-d', (int)$msg['ts']) : null;
                [$real, $why] = uspto_authentic($msg, $c);
                if ($ht = held_task($from, $subject, $bodyText, !empty($msg['ts']) ? date('Y-m-d H:i', (int)$msg['ts']) : '', $real, $apply)) { $out['items']++; $seen($msg, $ht); continue; }   // 2026-10-13: email-change alerts, examiner interview dates
                if (!$real) { $out['held']++; if ($apply) hold_message($msg, $bodyText, 'Not verified as the USPTO: ' . $why, false); $seen($msg, 'held: ' . $why); continue; }
                if (preg_match('/^\s*(fwd?|re)\s*:/i', $subject)) { $out['held']++; if ($apply) hold_message($msg, $bodyText, 'A reply or forward from someone at the USPTO', true); $seen($msg, 'held: reply/forward from the USPTO'); continue; }
                if ($ig = uspto_ignorable($from, $subject)) { $out['ignored']++; $seen($msg, 'ignored: ' . $ig); continue; }
                $items = parse_uspto_mail($subject, $bodyText, $recv);
                if (!$items && ($fr = parse_filing_receipt($subject, $bodyText, $recv))) $items = [$fr];
                if (!$items) $items = parse_maint_receipt($from, $subject, $bodyText, $recv);
                if (!$items && ($rc = parse_recordation($subject, $bodyText, $recv))) $items = [$rc];   // 2026-10-03
                if (!$items && ($af = parse_assignment_filed($subject, $recv))) {   // the cover sheet names the matter
                    foreach ($msg['attachments'] ?? [] as $a) if (!empty($a['id']) && preg_match('/cover.?sheet.*\\.pdf$/i', (string)$a['filename'])) {
                        $ids = assignment_cover_ids(pdf_bytes_text(gm_b64url((string)(gm_api('/users/me/messages/' . rawurlencode((string)$msg['id']) . '/attachments/' . rawurlencode($a['id']), [], $c)['data'] ?? ''))));
                        $af['kind'] = $ids['kind'] ?: 'trademark'; $af['docket'] = $ids['docket']; $af['app_no'] = $ids['number']; break;
                    }
                    if ($af['docket'] !== '' || $af['app_no'] !== '') $items = [$af];
                }
                if (!$items) { $out['held']++; if ($apply) hold_message($msg, $bodyText, 'New kind of USPTO email: read it, then mark Handled', true); $seen($msg, 'held: unknown kind'); continue; }
                $lines = [];
                foreach ($items as $it) {
                    $it['gmail_id'] = (string)$msg['id'];
                    if ($it['doc_code'] === 'MAINT FEE PAID' && ($mm = find_matter($it)) && ($y = maint_year_open((int)$mm['id'], $it['date']))) $it['summary'] = 'Maintenance fee paid: ' . ['4' => '3.5', '8' => '7.5', '12' => '11.5'][(string)$y] . '-year fee (U.S. Patent ' . number_format((int)$it['patent_no']) . ')';
                    if (in_array($it['doc_code'], ['FILING RECEIPT', 'MAINT FEE PAID', 'ASSIGNMENT RECORDED', 'ASSIGNMENT FILED'], true)) {
                        $out['receipts']++;
                        if ($it['date'] < setting('receipt_reports_from', '2026-10-02')) $it['no_report'] = true;   // the first look-back: file and propose, but no client emails for old receipts
                        if (!find_matter($it)) { $out['held']++; if ($apply) hold_message($msg, $bodyText, (str_starts_with($it['doc_code'], 'ASSIGNMENT') ? 'An assignment notice' : 'A filing receipt') . ' for ' . ($it['docket'] ?: $it['app_no']) . ', which matches no record in Pendant', true); $lines[] = 'held: receipt with no matching record'; continue; }
                    }
                    $it = enrich_item($it, $msg, $c); $r = apply_item($it, $apply, 'gmail'); $out['items']++;
                    $lines[] = ($r['matter']['ref'] ?? $it['docket'] ?: $it['app_no']) . ' ' . $it['doc_code'] . ' ' . $it['date'] . ': +' . count($r['creates']) . ' events, ' . count($r['closes']) . ' proposed closed' . ($r['notes'] ? ' (' . implode('; ', $r['notes']) . ')' : ''); $out['results'][] = $r;
                }
                if ($apply) $seen($msg, $lines ? implode(' | ', $lines) : 'nothing to docket');
                else $out['preview'][] = ['subject' => $subject, 'lines' => $lines];
                } catch (Throwable $e) { $out['failed'][] = $subject; $out['error'] = trim(($out['error'] ?? '') . ' "' . $subject . '": ' . $e->getMessage() . ';'); audit('error', 'gmail pull, message ' . $msg['id'] . ' "' . $subject . '": ' . $e->getMessage()); }
            }
        }
    } catch (Throwable $e) { $out['error'] = $e->getMessage(); audit('error', 'gmail pull: ' . $e->getMessage()); }
    if ($apply) try { foreach (held_retry() as $l) $out['results_held'][] = $l; } catch (Throwable $e) { audit('error', 'held retry: ' . $e->getMessage()); }
    audit('gmail_pull', json_encode(['messages' => $out['messages'], 'new' => $out['new'], 'items' => $out['items'], 'receipts' => $out['receipts'], 'held' => $out['held'], 'ignored' => $out['ignored'], 'error' => $out['error']]));
    return $out;
}

/** The raw text/html part of a message ('' when none): parse_uspto_mail strips it itself, keeping what the link attributes carry. */
function gm_raw_html(string $id, array $c): string {
    for ($i = 0; $i < 2; $i++) { try { $r = gm_api('/users/me/messages/' . rawurlencode($id), ['format' => 'full'], $c); return (string)(gm_find_part($r['payload'] ?? [], 'text/html') ?? ''); } catch (Throwable $e) { sleep(2); } }
    return '';   // a Gmail hiccup must not stop the pull: the stripped text still parses (minus the docId)
}
/** Absolute path of a command-line tool: pair's PHP shell has no /usr/local/bin on its PATH (pdftotext and tesseract live there). */
function tool(string $name): string { foreach (['/usr/local/bin', '/usr/bin', '/bin'] as $d) if (is_executable("$d/$name")) return "$d/$name"; return $name; }
/** Text of a PDF (bytes): the text layer, or OCR of the first 4 pages when it is a scan. */
function pdf_bytes_text(string $bytes): string {
    if (!str_starts_with($bytes, '%PDF')) return '';
    $tmp = tempnam(sys_get_temp_dir(), 'dkt'); file_put_contents($tmp, $bytes);
    // pair's poppler (pdftotext/pdftoppm) is broken (missing libgpgmepp) and its mutool is 1.13: Ghostscript does the job there
    $t = (string)shell_exec(tool('pdftotext') . ' -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null');
    if (strlen(trim($t)) < 200) $t = (string)shell_exec(tool('gs') . ' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=txtwrite -sOutputFile=- ' . escapeshellarg($tmp) . ' 2>/dev/null');
    if (strlen(trim($t)) < 200) {   // a scan: render pages 1-4 and OCR
        shell_exec(tool('gs') . ' -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pnggray -r300 -dFirstPage=1 -dLastPage=4 -sOutputFile=' . escapeshellarg($tmp . 'p%d.png') . ' ' . escapeshellarg($tmp) . ' 2>/dev/null');
        foreach (glob($tmp . 'p*.png') ?: [] as $pg) { $t .= "\n" . (string)shell_exec(tool('tesseract') . ' ' . escapeshellarg($pg) . ' stdout 2>/dev/null'); @unlink($pg); }
    }
    @unlink($tmp); return $t;
}
function http_bytes(string $url, array $headers = []): string {
    if (!function_exists('curl_init')) { $ctx = stream_context_create(['http' => ['header' => implode("\r\n", array_merge(['User-Agent: Mozilla/5.0 (X11; Linux x86_64) Chrome/128 Safari/537.36'], $headers)), 'timeout' => 60, 'ignore_errors' => true]]); $b = @file_get_contents($url, false, $ctx); return (is_string($b) && preg_match('#^HTTP/\S+ 200#', $http_response_header[0] ?? '')) ? $b : ''; }   // laptop PHP has no curl
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/128 Safari/537.36', CURLOPT_HTTPHEADER => $headers]);
    $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); return $code === 200 && is_string($b) ? $b : '';
}
/**
 * Fetch what a notice's deadline depends on before it is docketed (2026-09-30):
 *  - TTAB: the order PDF from TTABVUE (schedule / "granted until"), and when the serial is unknown or not ours, the proceeding page's
 *    numbers, our side first (the section naming the firm), so the proceeding lands on the right matter.
 *  - Madrid refusal: the attached decision (not the cover letter), for its time limit.
 */
function enrich_item(array $it, array $msg, array $c): array {
    try {
        if (str_starts_with($it['doc_code'], 'TTAB') && !empty($it['pno'])) {
            if (!empty($it['eno'])) $it['order_text'] = pdf_bytes_text(http_bytes("https://ttabvue.uspto.gov/ttabvue/ttabvue-{$it['pno']}-{$it['pty']}-{$it['eno']}.pdf"));
            if ($it['pty'] !== 'EXT' && ($it['app_no'] === '' || !find_matter($it))) {
                $page = html_entity_decode(strip_tags(http_bytes("https://ttabvue.uspto.gov/ttabvue/v?pno={$it['pno']}&pty={$it['pty']}")));
                $page = preg_replace('/\s+/', ' ', $page); $cut = stripos($page, 'Plaintiff Name'); $def = $cut !== false ? substr($page, 0, $cut) : $page; $pla = $cut !== false ? substr($page, $cut) : '';
                $nums = fn(string $x) => preg_match_all('/(?:Serial|Registration) #:\s*(\d{6,8})/', $x, $m) ? $m[1] : [];
                $ours = preg_match(firm('of_record_re'), $pla) ? array_merge($nums($pla), $nums($def)) : array_merge($nums($def), $nums($pla));
                foreach ($ours as $n) if (find_matter(['kind' => 'trademark', 'app_no' => $n, 'docket' => ''])) { $it['app_no'] = $n; break; }
                if ($it['app_no'] === '' && $ours) $it['app_no'] = $ours[0];
            }
        }
        if ($it['doc_code'] === 'POSTREG OFFICE ACTION' && ($pp = postreg_action_period((string)$it['app_no'], (string)$it['date']))) {   // 2026-10-01: the action itself says when the response is due
            $it['period_months'] = $pp['months']; if ($pp['issued']) $it['date'] = $pp['issued'];
        }
        // A registration: the date that counts is the USPTO's registration date, not the day the e-mail came (audit 2026-10-02).
        if ($it['doc_code'] === 'REGISTRATION') {
            $sn = preg_replace('/\D/', '', (string)$it['app_no']); if (strlen($sn) !== 8 && ($mm = find_matter($it))) $sn = preg_replace('/\D/', '', (string)$mm['app_no']);   // the notice may name the registration number
            $lv = strlen($sn) === 8 ? tsdr_lookup($sn) : ['ok' => false];
            if (!empty($lv['ok']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$lv['reg_date'])) { $it['date'] = substr((string)$lv['reg_date'], 0, 10); $it['reg_no'] = (string)$lv['reg_no']; $it['reg_verified'] = true; }
        }
        if ($it['doc_code'] === 'MADRID REFUSAL') {
            foreach ($msg['attachments'] ?? [] as $a) {
                if (empty($a['id']) || !preg_match('/\.pdf$/i', $a['filename']) || preg_match('/cover/i', $a['filename'])) continue;
                $r = gm_api('/users/me/messages/' . rawurlencode($msg['id']) . '/attachments/' . rawurlencode($a['id']), [], $c);
                $lim = madrid_time_limit(pdf_bytes_text(gm_b64url((string)($r['data'] ?? ''))));
                if ($lim !== null) { $it['time_limit'] = $lim; break; }
            }
        }
    } catch (Throwable $e) { audit('error', 'enrich ' . $it['doc_code'] . ': ' . $e->getMessage()); }
    return $it;
}
/** TSDR's document list for a serial: the post-registration Office action (type PROARR) issued on $date (else the newest within a week of it),
 *  downloaded and read for its response period. null when TSDR does not serve it (yet): the rule then dockets 6 months, tagged for checking. */
function postreg_action_period(string $serial, string $date): ?array {
    $serial = preg_replace('/\D/', '', $serial); $key = (string)($GLOBALS['cfg']['tsdr_key'] ?? ''); if (strlen($serial) !== 8 || $key === '') return null;
    $xml = http_bytes("https://tsdrapi.uspto.gov/ts/cd/casedocs/bundle.xml?sn=$serial", ['USPTO-API-KEY: ' . $key]); if ($xml === '') return null;
    $best = null;
    foreach (preg_split('/<\/Document>/', $xml) as $d) {
        $g = fn($t) => preg_match("/<$t>([^<]*)/", $d, $m) ? html_entity_decode($m[1]) : '';
        if ($g('DocumentTypeCode') !== 'PROARR' && !preg_match('/PostReg Office Action/i', $g('DocumentTypeDescriptionText'))) continue;
        $dd = substr($g('MailRoomDate'), 0, 10); if ($dd === '' || abs(strtotime($dd) - strtotime($date)) > 7 * 86400) continue;
        $url = preg_match('/<UrlPath>([^<]*\.pdf)/i', $d, $u) ? html_entity_decode($u[1]) : ''; if ($url === '') continue;
        if (!$best || abs(strtotime($dd) - strtotime($date)) < abs(strtotime($best[0]) - strtotime($date))) $best = [$dd, $url];
    }
    if (!$best) return null;
    sleep(2);   // TSDR throttles bursts
    return postreg_period(pdf_bytes_text(http_bytes($best[1], ['USPTO-API-KEY: ' . $key])));
}
/**
 * Addresses that have corresponded with the firm about this client's matters,
 * mined from Gmail headers (read-only): [email => ['n' => threads, 'last' => Y-m-d, 'name' => display name]].
 */
/** Addresses that are the firm, the USPTO, a vendor or a robot: never "the client". */
function firm_address(string $e): bool {
    $e = strtolower(trim($e));
    if (firm_own_address($e) || in_array($e, array_map('strtolower', firm('internal_addresses')), true)) return true;
    foreach (firm('ignore_domains') ?: [] as $d) if (str_ends_with($e, strtolower($d))) return true;
    return (bool)preg_match('/@(uspto\.gov|dockettrak\.com|google\.com|paysimple\.com|helcim\.com)$|^(noreply|no-reply|donotreply|mailer-daemon|notifications?)@/i', $e);
}
function hdr_addresses(string $v): array { preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $v, $m); return array_map('strtolower', $m[0]); }
/**
 * The most recent Gmail thread about file $ref that includes someone outside the firm (USPTO notices and internal-only threads are
 * skipped). A reminder sent as a reply to its last message lands inside that conversation for the client and in the attorney's mailbox.
 * Returns thread_id, message_id, references, subject, date, from, outside (addresses) — or null.
 */
function gmail_reply_target(string $ref): ?array {
    $ref = trim($ref); if (!preg_match('/^[A-Z0-9.\-]{3,}$/i', $ref)) return null;
    $c = gmail_cfg(); if (!$c) throw new RuntimeException('Gmail is not configured on this machine.');
    $c['max_threads'] = 15; $best = null;
    foreach (gm_search_threads('subject:"' . $ref . '" -from:uspto.gov newer_than:5y', $c) as $tid) {
        $res = gm_api('/users/me/threads/' . rawurlencode($tid), ['format' => 'metadata', 'metadataHeaders' => ['From', 'To', 'Cc', 'Subject', 'Message-ID', 'References', 'Auto-Submitted']], $c);
        // Unsent drafts and out-of-office / automatic replies are not the conversation (XYZ009, 2026-09-28: both hijacked the reply).
        $msgs = array_values(array_filter($res['messages'] ?? [], fn($x) => !in_array('DRAFT', $x['labelIds'] ?? [], true) && !is_auto_reply($x)));
        if (!$msgs) continue;
        $outside = [];
        foreach ($msgs as $msg) foreach (($msg['payload']['headers'] ?? []) as $hd)
            if (in_array(strtolower($hd['name']), ['from', 'to', 'cc'], true)) foreach (hdr_addresses((string)$hd['value']) as $a) if (!firm_address($a)) $outside[$a] = 1;
        if (!$outside) continue;
        $last = end($msgs); $H = []; foreach (($last['payload']['headers'] ?? []) as $hd) $H[strtolower($hd['name'])] = (string)$hd['value'];
        // Who the reply goes to: the outside people on the latest message an outside person WROTE; if no one outside ever wrote,
        // whoever the thread was started with. Never simply the last message's recipients: that repeats our own mis-addressed send.
        $hdrs = function (array $msg): array { $X = []; foreach (($msg['payload']['headers'] ?? []) as $hd) $X[strtolower($hd['name'])] = (string)$hd['value']; return $X; };
        $reply = [];
        foreach (array_reverse($msgs) as $msg) { $X = $hdrs($msg); $from = hdr_addresses($X['from'] ?? '');
            if ($from && !firm_address($from[0])) { foreach (['from', 'to', 'cc'] as $k) foreach (hdr_addresses($X[$k] ?? '') as $a) if (!firm_address($a)) $reply[$a] = 1; break; } }
        if (!$reply) { $X = $hdrs($msgs[0]); foreach (['to', 'cc'] as $k) foreach (hdr_addresses($X[$k] ?? '') as $a) if (!firm_address($a)) $reply[$a] = 1; }
        $mid = trim($H['message-id'] ?? ''); if (!preg_match('/^<[^<>\s]+>$/', $mid)) continue;
        if (stripos($H['subject'] ?? '', $ref) === false) continue;   // Gmail's subject: search is loose; the file number must really be in it
        $ts = (int)(($last['internalDate'] ?? 0) / 1000);
        if ($best && $ts <= $best['ts']) continue;
        preg_match_all('/<[^<>\s]+>/', $H['references'] ?? '', $rm);
        $best = ['thread_id' => $tid, 'message_id' => $mid, 'references' => implode(' ', array_slice(array_values(array_unique(array_merge($rm[0], [$mid]))), -20)),
                 'subject' => trim(preg_replace('/\s+/', ' ', $H['subject'] ?? '')), 'from' => $H['from'] ?? '', 'date' => date('Y-m-d', $ts), 'ts' => $ts, 'outside' => array_keys($outside), 'reply_to' => array_keys($reply ?: $outside)];
    }
    return $best;
}
/** An automatic reply (out of office, vacation responder): Auto-Submitted header, or the usual subject prefixes. */
function is_auto_reply(array $msg): bool {
    $H = []; foreach (($msg['payload']['headers'] ?? []) as $hd) $H[strtolower($hd['name'])] = (string)$hd['value'];
    if (isset($H['auto-submitted']) && strtolower(trim($H['auto-submitted'])) !== 'no') return true;
    return (bool)preg_match('/^\s*(automatic reply|auto(matic)?[- ]?reply|autoreply|out of (the )?office|abwesenheitsnotiz|réponse automatique)\b/i', $H['subject'] ?? '');
}
/** "Re: <thread subject>" without piling up Re:/Fwd: prefixes. */
function reply_subject(string $s): string { return 'Re: ' . trim(preg_replace('/^(\s*(re|fwd?|aw)\s*:\s*)+/i', '', $s)); }
/**
 * The message a reply answers, quoted below the signature the way Gmail does it, so the client sees the whole conversation even
 * though the sender's own mailbox never had it (XYZ001, 2026-10-01: staff's reply went out bare). '' when it cannot be fetched.
 */
function gmail_quote_html(string $msgid): string {
    $msgid = trim($msgid); if (!preg_match('/^<[^<>\s]+>$/', $msgid)) return '';
    $c = gmail_cfg(); if (!$c) return '';
    $r = gm_api('/users/me/messages', ['q' => 'rfc822msgid:' . trim($msgid, '<>'), 'maxResults' => 1], $c);
    $id = (string)($r['messages'][0]['id'] ?? ''); if ($id === '') return '';
    $m = gm_api('/users/me/messages/' . rawurlencode($id), ['format' => 'full'], $c);
    $H = []; foreach (($m['payload']['headers'] ?? []) as $hd) $H[strtolower($hd['name'])] = (string)$hd['value'];
    $html = (string)(gm_find_part($m['payload'] ?? [], 'text/html') ?? '');
    if ($html !== '') $html = quote_body_html($html);
    else { $txt = utf8_safe((string)(gm_find_part($m['payload'] ?? [], 'text/plain') ?? '')); if (trim($txt) === '') return ''; $html = nl2br(htmlspecialchars($txt, ENT_QUOTES, 'UTF-8'), false); }
    if (trim(strip_tags($html)) === '') return '';
    $ts = (int)(($m['internalDate'] ?? 0) / 1000); $when = (new DateTime('@' . ($ts ?: time())))->setTimezone(new DateTimeZone('America/New_York'))->format('D, M j, Y \a\t g:i A');
    $from = trim(utf8_safe(function_exists('iconv_mime_decode') ? (string)@iconv_mime_decode($H['from'] ?? '', ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : ($H['from'] ?? '')));
    return '<br><div class="gmail_quote"><div dir="ltr" class="gmail_attr">On ' . $when . ' ' . htmlspecialchars($from, ENT_QUOTES, 'UTF-8') . ' wrote:<br></div>'
        . '<blockquote class="gmail_quote" style="margin:0px 0px 0px 0.8ex;border-left:1px solid rgb(204,204,204);padding-left:1ex">' . $html . '</blockquote></div>';
}
/** A message's HTML cut down to what can sit inside our own email: the body only, without scripts, styles (they would restyle our
 *  message), forms or event handlers. */
function quote_body_html(string $html): string {
    $html = utf8_safe($html);
    if (preg_match('/<body\b[^>]*>(.*)<\/body\s*>/is', $html, $b)) $html = $b[1];
    elseif (preg_match('/<body\b[^>]*>(.*)$/is', $html, $b)) $html = $b[1];
    $html = preg_replace(['/<!--.*?-->/s', '/<(script|style|head|title|noscript|iframe|object|embed|form)\b.*?<\/\1\s*>/is', '/<\/?(html|body|head|meta|link|base|script|style|iframe|object|embed|form|input|button|textarea|select)\b[^>]*>/i', '/<!DOCTYPE[^>]*>/i'], '', $html);
    $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    return preg_replace('/(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\2/i', '$1="#"', $html);
}
/** Mail parts are not always UTF-8 (older Outlook and AOL send Windows-1252); a bad byte would garble the whole email. */
function utf8_safe(string $s): string {
    if (preg_match('//u', $s)) return $s;
    $t = function_exists('iconv') ? @iconv('Windows-1252', 'UTF-8//IGNORE', $s) : false;
    return is_string($t) && $t !== '' ? $t : preg_replace_callback('/[\x80-\xFF]/', fn($m) => chr(0xC0 | (ord($m[0]) >> 6)) . chr(0x80 | (ord($m[0]) & 0x3F)), $s);   // Latin-1 by hand
}
function gmail_find_addresses(array $refs, string $clientName = ''): array {
    $c = gmail_cfg(); if (!$c) throw new RuntimeException('Gmail is not configured on this machine.');
    $refs = array_values(array_unique(array_filter(array_map('trim', $refs), fn($r) => $r !== '' && preg_match('/^[A-Z0-9.\-]{3,}$/i', $r))));
    $terms = array_map(fn($r) => 'subject:' . $r, array_slice($refs, 0, 15));
    if ($clientName !== '' && strlen($clientName) >= 4) $terms[] = '"' . str_replace('"', '', $clientName) . '"';
    if (!$terms) return [];
    $c['max_threads'] = 60; $found = [];
    foreach (gm_search_threads('(' . implode(' OR ', $terms) . ') newer_than:6y', $c) as $tid) {
        $res = gm_api('/users/me/threads/' . rawurlencode($tid), ['format' => 'metadata', 'metadataHeaders' => ['From', 'To', 'Cc', 'Reply-To']], $c);
        foreach (($res['messages'] ?? []) as $msg) {
            $ts = isset($msg['internalDate']) ? date('Y-m-d', (int)($msg['internalDate'] / 1000)) : '';
            foreach (($msg['payload']['headers'] ?? []) as $hd) {
                if (!in_array($hd['name'], ['From', 'To', 'Cc', 'Reply-To'], true)) continue;
                foreach (explode(',', (string)$hd['value']) as $addr) {
                    if (!preg_match('/^\s*(?:"?([^"<]*?)"?\s*)?<?([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})>?\s*$/i', $addr, $m)) continue;
                    $e = strtolower($m[2]); if (firm_address($e)) continue;
                    $found[$e] ??= ['n' => 0, 'last' => '', 'name' => '', 'threads' => []];
                    if (!isset($found[$e]['threads'][$tid])) { $found[$e]['n']++; $found[$e]['threads'][$tid] = 1; }
                    if ($ts > $found[$e]['last']) $found[$e]['last'] = $ts;
                    if ($found[$e]['name'] === '' && trim($m[1] ?? '') !== '') $found[$e]['name'] = trim($m[1]);
                }
            }
        }
    }
    foreach ($found as &$f) unset($f['threads']); unset($f);
    uasort($found, fn($a, $b) => [$b['n'], $b['last']] <=> [$a['n'], $a['last']]);
    return $found;
}
