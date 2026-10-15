<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * Real transmission of outbox rows. mail_mode in config:
 *   'outbox' = record only (laptop sample, tests)   'live' = transmit on Send (pair server).
 * Sent with PHP mail() and an aligned envelope sender (-f), exactly like the
 * pair helper ff-mail.php, plus Cc (the attorney on staff's sends), a Bcc copy to
 * the sender so the message lands in their own mailbox, an HTML body with the
 * dates written out and underlined, and any attachments (invoices) as PDF parts.
 */
function mail_live(): bool { return ($GLOBALS['cfg']['mail_mode'] ?? 'outbox') === 'live'; }
function split_addresses(string $s): array { return array_values(array_unique(array_filter(array_map('trim', preg_split('/[;,]+/', $s)), fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false))); }
function attachments_dir(): string { $d = dirname($GLOBALS['cfg']['db']) . '/attachments'; if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }
/** Attachment list of an outbox row: [['file' => stored name, 'name' => original name, 'size' => bytes], ...] */
function outbox_attachments(array $o): array { $a = json_decode((string)($o['attachments'] ?? ''), true); return is_array($a) ? $a : []; }
/** Store uploaded PDFs for an outbox row; returns the new attachment list. Only PDFs up to 15 MB. */
/**
 * 2026-10-05 (XYZ019): attachments go out in the order they were added on the page (the auto-ticked ones first), and a file
 * with the same name as one already attached is dropped (the letter went twice: a hand upload and the folder's copy). $order is the
 * page's list: "file:<name>" for a ticked folder file, "up:<name>" for an upload. Entries before $from (a re-opened draft's) stay put.
 */
function attach_order(array $att, int $from, int $nUp, array $order): array {
    $clean = fn(string $n) => preg_replace('/[^A-Za-z0-9._ -]+/', '_', basename($n)) ?: 'invoice.pdf';
    $rank = []; foreach (array_values($order) as $i => $k) { [$kind, $name] = array_pad(explode(':', (string)$k, 2), 2, ''); $rank[($kind === 'up' ? 'up:' . $clean($name) : 'file:' . $name)] ??= $i; }
    $head = array_slice($att, 0, $from); $new = [];
    foreach (array_slice($att, $from) as $j => $a) $new[] = ['a' => $a, 'k' => ($j < $nUp ? 'up:' : 'file:') . $a['name'], 'j' => $j];
    usort($new, fn($x, $y) => [$rank[$x['k']] ?? PHP_INT_MAX, $x['j']] <=> [$rank[$y['k']] ?? PHP_INT_MAX, $y['j']]);
    $out = $head; $seen = []; foreach ($head as $a) $seen[strtolower($a['name'])] = 1;
    foreach ($new as $x) { $n = strtolower($x['a']['name']); if (isset($seen[$n])) continue; $seen[$n] = 1; $out[] = $x['a']; }
    return $out;
}
function store_uploads(int $obId, array $existing): array {
    $files = $_FILES['attach'] ?? null; if (!$files || !is_array($files['name'])) return $existing;
    foreach ($files['name'] as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $tmp = $files['tmp_name'][$i]; $size = (int)$files['size'][$i];
        if ($size > 15 * 1024 * 1024) throw new RuntimeException("$name is over 15 MB");
        $fh = fopen($tmp, 'rb'); $magic = fread($fh, 5); fclose($fh);
        if ($magic !== '%PDF-') throw new RuntimeException("$name is not a PDF");
        $clean = preg_replace('/[^A-Za-z0-9._ -]+/', '_', basename($name)) ?: 'invoice.pdf';
        $stored = $obId . '-' . count($existing) . '-' . $clean;
        if (!move_uploaded_file($tmp, attachments_dir() . '/' . $stored)) throw new RuntimeException("could not store $name");
        $existing[] = ['file' => $stored, 'name' => $clean, 'size' => $size];
    }
    return $existing;
}
/** Copy chosen PDFs from the matter's pushed Dropbox files (data/files/<folder>/) into the outbox row's attachments. */
function attach_matter_files(int $obId, array $existing, array $names, string $ref): array {
    if (!$names) return $existing;
    $have = []; foreach (matter_files($ref) as $f) $have[$f['name']] = $f;
    // 2026-10-04: a file older than matter_files' 45 days that the send page offered (a registration certificate, lib/regreport.php) is
    // looked up in the folder by its exact name.
    $dir = null; foreach ($names as $n) if ((string)$n !== '' && !isset($have[(string)$n]) && function_exists('mf_find') && ($dir = $dir ?? mf_find($ref))) foreach (mf_list($dir) as $x) if ($x['name'] === (string)$n && preg_match('/\.pdf$/i', $x['name']))
        $have[$x['name']] = ['path' => $dir['mode'] === 'local' ? mf_local($dir, $x['rel']) : '', 'name' => $x['name'], 'mtime' => $x['mtime'], 'size' => $x['size'], 'folder' => $dir, 'rel' => $x['rel']];
    foreach ($names as $n) {
        if ((string)$n === "") continue;
        $f = $have[(string)$n] ?? null; if (!$f) throw new RuntimeException("$n is no longer in the file");
        if ($f['size'] > 15 * 1024 * 1024) throw new RuntimeException("$n is over 15 MB");
        $clean = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $f['name']); $stored = $obId . '-' . count($existing) . '-' . $clean;
        $src = $f['path'] !== '' ? $f['path'] : mf_local($f['folder'], $f['rel']);   // from Dropbox: downloaded now
        if ($src === '' || !copy($src, attachments_dir() . '/' . $stored)) throw new RuntimeException("could not attach $n");
        $existing[] = ['file' => $stored, 'name' => $clean, 'size' => $f['size']];
    }
    return $existing;
}
function build_mail(array $o): array {
    $from = $o['from_email']; $name = $GLOBALS['cfg']['users'][$o['from_user']]['name'] ?? firm('name');
    $to = split_addresses((string)$o['to_email']); $cc = split_addresses((string)$o['cc_email']);
    if (!$to) throw new RuntimeException('no valid recipient address');
    $enc = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $headers = 'From: ' . $enc($name) . " <$from>\r\nReply-To: $from\r\n" . ($cc ? 'Cc: ' . implode(', ', $cc) . "\r\n" : '') . "Bcc: $from\r\nMIME-Version: 1.0\r\nX-Mailer: FF-Docket";
    // Reply in the client's existing thread: only well-formed <id@host> tokens reach the headers (no header injection).
    $irt = trim((string)($o['in_reply_to'] ?? ''));
    if (preg_match('/^<[^<>\s]+>$/', $irt)) { preg_match_all('/<[^<>\s]+>/', (string)($o['refs_hdr'] ?? ''), $rm); $headers .= "\r\nIn-Reply-To: $irt\r\nReferences: " . implode(' ', array_slice(array_values(array_unique(array_merge($rm[0], [$irt]))), -20)); }
    $html = (string)($o['card_html'] ?? '') . mail_html(strip_plain_signature((string)$o['body']), signature_html((string)$o['from_user'])) . (string)($o['fwd_html'] ?? '') . ($irt !== '' && preg_match('/^<[^<>\s]+>$/', $irt) ? (string)($o['quote_html'] ?? '') : '');   // forwarded USPTO notice, if any
    $att = outbox_attachments($o);
    // Base64 keeps every line at 76 characters: the HTML is one long line, and mail servers break lines over 998 characters
    // wherever they fall (XYZ001 2026-09-29 reached the client as "confirm rec eipt").
    $html64 = rtrim(chunk_split(base64_encode($html)));
    if (!$att) { $headers .= "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64"; $body = $html64; }
    else {
        $b = 'ffdocket-' . bin2hex(random_bytes(8));
        $headers .= "\r\nContent-Type: multipart/mixed; boundary=\"$b\"";
        $body = "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n$html64\r\n";
        foreach ($att as $a) {
            $path = attachments_dir() . '/' . $a['file']; if (!is_file($path)) throw new RuntimeException('attachment missing: ' . $a['name']);
            $body .= "--$b\r\nContent-Type: application/pdf; name=\"{$a['name']}\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$a['name']}\"\r\n\r\n" . chunk_split(base64_encode((string)file_get_contents($path))) . "\r\n";
        }
        $body .= "--$b--\r\n";
    }
    // Subject: one line only (a pasted line break would otherwise inject headers), UTF-8 encoded when it is not plain ASCII.
    $subj = trim(preg_replace('/[\r\n\t]+/', ' ', (string)$o['subject']));
    if (preg_match('/[^\x20-\x7E]/', $subj)) $subj = $enc($subj);
    return ['to' => implode(', ', $to), 'subject' => $subj, 'body' => $body, 'headers' => $headers, 'from' => $from, 'attachments' => count($att)];
}
/** Emails someone clicked Send on that never left the server (mail() failed, or an attachment went missing): newest first.
 *  Only on the live server; the laptop sample records every send without transmitting it. */
function untransmitted(): array {
    if (!mail_live()) return [];
    return q('SELECT o.*, m.ref FROM outbox o JOIN matters m ON m.id=o.matter_id WHERE o.mode="sent" AND o.transmitted_at IS NULL ORDER BY o.id DESC');
}
function transmit_outbox(int $id): array {
    $o = q1('SELECT * FROM outbox WHERE id=?', [$id]); if (!$o) return ['ok' => false, 'error' => 'no such outbox row'];
    if ($o['transmitted_at']) return ['ok' => true, 'error' => 'already transmitted ' . $o['transmitted_at']];
    if ($o['mode'] !== 'sent') return ['ok' => false, 'error' => 'only "sent" rows are transmitted (this one is ' . $o['mode'] . ')'];
    try {
        $m = build_mail($o);
        $ok = mail($m['to'], $m['subject'], $m['body'], $m['headers'], '-f' . $m['from']);
        if (!$ok) throw new RuntimeException('mail() returned false');
        run('UPDATE outbox SET transmitted_at=datetime("now"), transmit_error="" WHERE id=?', [$id]);
        audit('transmitted', 'to ' . $m['to'] . ($o['cc_email'] ? ' cc ' . $o['cc_email'] : '') . ($m['attachments'] ? " with {$m['attachments']} attachment(s)" : '') . ': ' . $o['subject'], (int)$o['matter_id']);
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        run('UPDATE outbox SET transmit_error=? WHERE id=?', [$e->getMessage(), $id]);
        audit('transmit_failed', $e->getMessage() . ' - ' . $o['subject'], (int)$o['matter_id']);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
