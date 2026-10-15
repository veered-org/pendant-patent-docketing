<?php
declare(strict_types=1);
// Trademark Notice of Allowance report (2026-09-29): his XYZ002 wording, the mark in color, the "At least one of" goods list,
// the one-class / two-class fee quote, and the USPTO notice itself forwarded below the signature (as he forwards it by hand).
const NOA_TEMPLATE = 'Trademark Notice of Allowance';
// 2026-10-05: the client email after "Extension filed" / "Statement of Use filed" on a Statement of Use deadline (his XYZ034 / XYZ004 wording)
// 2026-10-13 (XYZ001): 'issue_fee' = the client email after the patent issue fee is paid (his XYZ001 wording), same send-page path.
const SOU_REPORT_TEMPLATES = ['sou_ext' => 'Report Extension Filed', 'sou_filed' => 'Report Statement of Use Filed', 'issue_fee' => 'Report Issue Fee Paid'];
const NOA_EVENT = 'Statement of Use or 1st Extension Due';
const NOA_REPORT_EVENT = 'Report Notice of Allowance';   // dashboard to-do, dated the NOA issue date; closed when the report is sent
/** Classes on file for a matter ("025 - Shirts" lines). */
function goods_classes(string $goods): array { $c = []; foreach (preg_split('/\R/', trim($goods)) as $l) if (preg_match('/^\s*(\d{1,3}|[AB])\s*-/', $l, $m)) $c[$m[1]] = 1; return array_keys($c); }
/** The firm's quote: one class, or one-class / both-classes. Three or more classes: bracketed so the sender fills it in (no rate invented). */
function noa_fees(int $n): string {
    // Same amounts as the attorney's quotes (XYZ002 / XYZ004), laid out one per line.
    if ($n <= 1) return "Statement of Use: \$500, plus \$525 for issuance of the registration\nSix-month extension (if you are not using the mark by the deadline): \$500";
    if ($n === 2) return "Statement of Use: \$500 for one class or \$800 for both classes, plus \$525 for issuance of the registration\nSix-month extension (if you are not using the mark by the deadline): \$500 for one class or \$750 for both classes";
    return "Statement of Use: \$500 for one class or [fee for all $n classes] for all $n classes, plus \$525 for issuance of the registration\nSix-month extension (if you are not using the mark by the deadline): \$500 for one class or [fee for all $n classes] for all $n classes";
}
/** The newest USPTO trademark notice of $kind for file $ref in Gmail (read-only): ['html','subject','date','from'] or null. */
function gmail_uspto_notice(string $ref, string $kind = 'NOTICE OF ALLOWANCE'): ?array {
    $ref = trim($ref); if (!preg_match('/^[A-Z0-9.\-]{3,}$/i', $ref)) return null;
    $c = gmail_cfg(); if (!$c) throw new RuntimeException('Gmail is not configured on this machine.');
    $c['max_threads'] = 10; $best = null;
    foreach (gm_search_threads('from:TMOfficialNotices@uspto.gov subject:"' . $ref . '" newer_than:2y', $c) as $tid) {
        $res = gm_api('/users/me/threads/' . rawurlencode($tid), ['format' => 'full'], $c);
        foreach ($res['messages'] ?? [] as $msg) {
            $H = []; foreach ($msg['payload']['headers'] ?? [] as $hd) $H[strtolower($hd['name'])] = (string)$hd['value'];
            if (stripos($H['subject'] ?? '', $ref) === false) continue;
            $html = gm_find_part($msg['payload'], 'text/html') ?? gm_find_part($msg['payload'], 'text/plain') ?? '';   // USPTO sends HTML inside text/plain
            if (stripos(strip_tags($html), $kind) === false) continue;
            $ts = (int)(($msg['internalDate'] ?? 0) / 1000);
            if ($best && $ts <= $best['ts']) continue;
            $best = ['html' => $html, 'subject' => trim($H['subject'] ?? ''), 'from' => $H['from'] ?? 'TMOfficialNotices@uspto.gov', 'date' => $H['date'] ?? '', 'ts' => $ts];
        }
    }
    return $best;
}
/** One USPTO e-mail by its Gmail id (a receipt report knows exactly which message it reports, lib/held.php): same shape as gmail_uspto_notice. */
function gmail_notice_by_id(string $id): ?array {
    if ($id === '' || !($c = gmail_cfg())) return null;
    $msg = gm_api('/users/me/messages/' . rawurlencode($id), ['format' => 'full'], $c); if (empty($msg['payload'])) return null;
    $H = []; foreach ($msg['payload']['headers'] ?? [] as $hd) $H[strtolower($hd['name'])] = (string)$hd['value'];
    $html = gm_find_part($msg['payload'], 'text/html') ?? gm_find_part($msg['payload'], 'text/plain') ?? '';
    if (!preg_match('/<(p|div|table|br)\b/i', $html)) $html = nl2br(htmlspecialchars($html, ENT_QUOTES, 'UTF-8'), false);   // a plain-text receipt keeps its lines
    return ['html' => $html, 'subject' => trim($H['subject'] ?? ''), 'from' => $H['from'] ?? 'USPTO', 'date' => $H['date'] ?? '', 'ts' => (int)(($msg['internalDate'] ?? 0) / 1000)];
}
/** Safe copy of a notice's HTML (no scripts, styles, forms, event handlers or javascript: links) inside a Gmail-style forward block. */
function forward_block(array $n): string {
    $h = (string)$n['html'];
    $h = preg_replace('#<(script|style|head|title|iframe|object|embed|form)\b.*?</\1\s*>#is', '', $h);
    $h = preg_replace('#</?(html|body|meta|link|base|input|button|textarea|select)\b[^>]*>#i', '', $h);
    $h = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $h);
    $h = preg_replace('/(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\2/i', '$1="#"', $h);
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    return '<br><div style="font-family:Arial,sans-serif;font-size:13px;color:#222">---------- Forwarded message ---------<br>From: ' . $e($n['from']) . '<br>Date: ' . $e($n['date']) . '<br>Subject: ' . $e($n['subject']) . '<br><br>' . $h . '</div>';
}

// 2026-10-12 (XYZ060): "Statement of Use filed" goes to the client email WITH the receipt attached. He saves the receipt into
// the file himself (TSDR lists the paper only much later), so the send page watches the folder and ticks it when it lands. The
// receipt e-mail's own "Report Filing Receipt" to-do is then not a second email.
const SOU_RECEIPT_RE = '/receipt|ESU\d|SOU|extension|statement of use|allegation of use/i';
/** Names of the SOU / extension filing papers saved in the last 3 days (the receipt the report attaches). */
function sou_receipt_files(array $mf, string $kind = ''): array {
    if ($kind === 'issue_fee') {   // the acknowledgement receipt (XYZ001 sent only that); any fresh issue-fee paper when there is none
        $all = array_values(array_map(fn($f) => $f['name'], array_filter($mf, fn($f) => $f['mtime'] >= time() - 3 * 86400 && preg_match(ISSUE_FEE_RECEIPT_RE, $f['name']))));
        return array_values(array_filter($all, fn($n) => stripos($n, 'AckReceipt') !== false)) ?: $all;
    }
    return array_values(array_map(fn($f) => $f['name'], array_filter($mf, fn($f) => $f['mtime'] >= time() - 3 * 86400 && preg_match(SOU_RECEIPT_RE, $f['name']))));
}
/** Re-read the matter's Dropbox folder now (the index otherwise catches up within 10 minutes), at most every 10 seconds per matter. */
function sou_folder_refresh(string $ref): void {
    if (!function_exists('folders_mode') || folders_mode() !== 'dbx' || !($f = mf_find($ref))) return;
    $stamp = dirname($GLOBALS['cfg']['db']) . '/receipt-now-' . preg_replace('/[^A-Za-z0-9.\-]/', '', strtoupper($ref)) . '.ts';
    if (is_file($stamp) && filemtime($stamp) > time() - 10) return;
    @touch($stamp);
    $r = dbx_rpc('files/list_folder', ['path' => $f['dir'], 'recursive' => false, 'include_deleted' => false, 'limit' => 2000]);
    if ($r['ok']) foreach ((array)($r['json']['entries'] ?? []) as $e) if (($e['.tag'] ?? '') === 'file') dbx_index_entry($e);
}
/** After the SOU / extension report went out: the same filing's "Report Filing Receipt" to-do is closed, not sent again. */
function sou_report_close_receipt_todo(int $matterId, string $what = 'Statement of Use'): int {
    $n = 0;
    foreach (q("SELECT id FROM events WHERE matter_id=? AND type='Report Filing Receipt' AND status='open' AND COALESCE(mail_date, due_date) >= ?", [$matterId, add_days(today(), -3, false)]) as $e) {
        run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=?', [auth_user(), 'reported with the ' . $what . ' email ' . today(), $e['id']]);
        run('UPDATE reminders SET status="skipped", reason=? WHERE event_id=? AND status IN ("pending","held")', ['reported with the ' . $what . ' email ' . today(), $e['id']]);
        $n++;
    }
    return $n;
}

// 2026-10-13 (XYZ001): "Done" on a patent issue-fee deadline asks for the Patent Center receipt(s); they are saved into the file
// under the next code of the allowance group (XYZ001-03a NOA → 03b "AckReceipt-Issue Fee Payment", as XYZ001-03c), then the client email.
const ISSUE_FEE_RECEIPT_RE = '/Issue Fee/i';
function is_issue_fee_event(array $ev): bool {
    if (!preg_match('/^Respond to Office Action - NOA|^Issue Fee/i', (string)($ev['type'] ?? ''))) return false;
    return (string)(q1('SELECT kind FROM matters WHERE id=?', [(int)$ev['matter_id']])['kind'] ?? '') === 'patent';
}
/** What an issue-fee paper is, from its first page: 'form' (the PTOL-85B, doc code IFEE), 'ack' (Electronic Acknowledgement Receipt),
 *  'payment' (Electronic Payment Receipt), '' otherwise; plus the application number printed on it ('' when none is found). */
function issue_fee_paper(string $text): array {
    $k = preg_match('/ELECTRONIC ACKNOWLEDGEMENT RECEIPT/i', $text) ? 'ack' : (preg_match('/ELECTRONIC PAYMENT RECEIPT/i', $text) ? 'payment' : (preg_match('/PTO.?L?.?85B|Doc Code:\s*IFEE|Issue Fee Transmittal/i', $text) ? 'form' : ''));
    // the number sits under the "APPLICATION #" column header (XYZ001: "30049338" on the 85B, "30/049,338" on the receipt)
    $app = preg_match('/(?:APPLICATION\s*(?:#|NUMBER|NO\.?)|Application Number)[^\n]*\n\s*(\d{2}\/?\d{3},?\d{3})\b/i', $text, $mm) || preg_match('/(?:APPLICATION\s*(?:#|NUMBER|NO\.?)|Application Number)\s*:?\s*(\d{2}\/?\d{3},?\d{3})\b/i', $text, $mm) ? preg_replace('/\D/', '', $mm[1]) : '';
    return [$k, $app];
}
/** $files = [[tmp path, original name], ...] (PDFs). Saved in the XYZ001 order and names: the 85B "Issue Fee Payment", then
 *  "AckReceipt-Issue Fee Payment", then "Payment Receipt-Issue Fee Payment". Throws (nothing saved) when a paper is for another application. */
function issue_fee_save(array $m, array $files): array {
    $label = ['form' => 'Issue Fee Payment', 'ack' => 'AckReceipt-Issue Fee Payment', 'payment' => 'Payment Receipt-Issue Fee Payment']; $order = ['form' => 0, 'ack' => 1, 'payment' => 2, '' => 3];
    $mn = preg_replace('/\D/', '', (string)$m['app_no']); $papers = [];
    foreach ($files as [$tmp, $name]) {
        [$k, $an] = issue_fee_paper(pdf_text($tmp, 1, 1));
        if ($an !== '' && $mn !== '' && $an !== $mn) throw new RuntimeException("$name is for application " . fmt_app($an, 'United States') . ', not ' . fmt_app($mn, 'United States') . '. Nothing was saved.');
        $papers[] = ['tmp' => $tmp, 'name' => $name, 'kind' => count($files) === 1 && $k === '' ? 'ack' : $k];
    }
    usort($papers, fn($a, $b) => $order[$a['kind']] <=> $order[$b['kind']]);
    $dir = mf_find((string)$m['ref']) ?: mf_new((string)$m['ref']); $names = mf_names($dir); $saved = []; $used = [];
    foreach ($papers as $p) {
        $lb = $label[$p['kind']] ?? 'Issue Fee Payment-' . preg_replace('/\.pdf$/i', '', $p['name']);
        $used[$lb] = ($used[$lb] ?? 0) + 1; if ($used[$lb] > 1) $lb .= ' ' . $used[$lb];
        $code = next_doc_code(array_merge($names, $saved), (string)$m['ref']);
        $saved[] = mf_write($dir, $m['ref'] . '-' . ($code !== '' ? "$code-" : '') . $lb . '.pdf', (string)file_get_contents($p['tmp']));
    }
    return $saved;
}
