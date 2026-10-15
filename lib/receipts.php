<?php
declare(strict_types=1);
/**
 * Receipts in the file (2026-10-01): "when a task is done, can you detect that by the receipt in the file? verify with me to mark
 * as done". Every new file in a matter folder is looked at once. A filing receipt is matched to the open deadline it satisfies, and that
 * deadline is PROPOSED done (never closed) with the file as evidence; an admin confirms or rejects it in the Confirm queue, where the
 * receipt opens with one click. A rejected proposal is never made again.
 *
 * What counts as a receipt (by name; drafts, analyses and our own papers do not):
 *   patents     "...AckReceipt-Response to Office Action of 2026.07.29", "...AckReceipt-RCE", "...AckReceipt-Issue Fee Payment",
 *               "...Maint fee 12th year", "...Maintenance Fee Year 7.5 Payment Receipt"
 *   trademarks  TEAS copies: ROA-<serial> (response), SOU-<serial> (statement of use), ESU / ESU1- (extension), S08N09 / S08N15 (Sections 8 & 9 / 8 & 15)
 * Filing receipts of the application itself are left to bin/filing_dates.php.
 */
const RECEIPT_LOOKBACK_DAYS = 60;   // on the first scan, older files are only recorded, never proposed

function receipts_schema(): void {
    static $done = false; if ($done) return; $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS receipt_seen (path_lower TEXT PRIMARY KEY, matter_id INTEGER, kind TEXT, result TEXT, at TEXT DEFAULT (datetime('now')))");
    db()->exec("CREATE TABLE IF NOT EXISTS receipt_hits (event_id INTEGER NOT NULL, path TEXT NOT NULL, name TEXT NOT NULL, saved TEXT, at TEXT DEFAULT (datetime('now')), PRIMARY KEY (event_id, path))");
}
/** What a file name says was filed: ['what' => response|issue_fee|maint|sou|extension|s8_15|s8_9, ...] or null. */
function receipt_kind(string $name, string $kind): ?array {
    if (!preg_match('/\.pdf$/i', $name) || preg_match('/draft|unsigned|analysis|agenda|interview|letter to|client email|\.docx?$/i', $name)) return null;
    $oaDate = preg_match('/of (\d{4})[.\-](\d{2})[.\-](\d{2})/', $name, $d) ? "$d[1]-$d[2]-$d[3]" : null;
    if ($kind === 'patent') {
        if (preg_match('/maint(enance)?[\s_.-]*fee/i', $name)) {
            $y = null;
            if (preg_match('/\b(4|8|12)(st|nd|rd|th)?[\s_-]*(year|yr)/i', $name, $m)) $y = (int)$m[1];
            elseif (preg_match('/year[\s_-]*(3\.5|7\.5|11\.5|4|8|12)\b/i', $name, $m)) $y = ['3.5' => 4, '7.5' => 8, '11.5' => 12][$m[1]] ?? (int)$m[1];
            return $y ? ['what' => 'maint', 'year' => $y] : null;
        }
        if (!preg_match('/ack.{0,3}receipt|receipt.{0,3}ack|acknowledg/i', $name)) return null;
        if (preg_match('/issue[\s_-]*fee/i', $name)) return ['what' => 'issue_fee'];
        if (preg_match('/\bRCE\b|continued examination/i', $name)) return ['what' => 'response', 'rce' => true, 'oa' => $oaDate];
        if (preg_match('/response|reply|amendment|election|restriction|reconsideration|appeal/i', $name) && !preg_match('/notice of appeal fee/i', $name)) return ['what' => 'response', 'oa' => $oaDate];
        return null;
    }
    if ($kind === 'trademark') {
        $name = preg_replace('/^[A-Z]{3,4}\d{3}(\.\d+)?-\d{2}[a-z]-/i', '', $name);   // the docket code (REF-02a-) is not part of what the paper is
        if (preg_match('/^ESU\d?-\d{8}|extension.{0,30}statement of use|SOU.{0,6}extension/i', $name)) return ['what' => 'extension'];   // before the SOU test: its name says "Statement of Use" too
        if (preg_match('/^ROA\d?-\d{8}|response to (office|post.?reg)/i', $name) && !preg_match('/receipt-\d{8}/i', $name)) return ['what' => 'response', 'oa' => $oaDate];
        if (preg_match('/^SOU\d?-\d{8}|statement of use|allegation of use/i', $name)) return ['what' => 'sou'];
        if (preg_match('/^S0?8N15|sections? 8 (&|and) 15|declaration of use and incontestability/i', $name)) return ['what' => 's8_15'];
        if (preg_match('/^S0?8N0?9|sections? 8 (&|and) 9|renewal/i', $name) && !preg_match('/reminder/i', $name)) return ['what' => 's8_9'];
    }
    return null;
}
/** Open events on the matter that this receipt (dated $saved) satisfies. */
function receipt_targets(int $matterId, array $k, string $saved): array {
    $open = q('SELECT * FROM events WHERE matter_id=? AND status="open" AND rejected_at IS NULL ORDER BY due_date', [$matterId]); $out = [];
    foreach ($open as $e) {
        $t = (string)$e['type']; $due = (string)$e['due_date'];
        switch ($k['what']) {
            case 'response':
                if (!preg_match('/^(Respond to Office Action|Final Response|PostReg Office Action)/', $t) || preg_match('/- NOA$/', $t)) break;
                // The receipt answers the action that set this deadline: named in the file ("... of 2026.07.29"), or dated after it.
                $anchor = $e['mail_date'] ?: add_months($due, preg_match('/^Final Response|Extended$/', $t) ? -6 : -3, false);
                if (!empty($k['oa'])) { if ($e['mail_date'] ? $e['mail_date'] === $k['oa'] : ($k['oa'] >= add_days($anchor, -45, false) && $k['oa'] <= add_days($anchor, 45, false))) $out[] = $e; }
                elseif ($saved > $anchor && $saved <= add_days($due, 3, false)) $out[] = $e;
                break;
            case 'issue_fee':
                if (preg_match('/- NOA$|Issue Fee/i', $t) && $saved >= add_months($due, -4, false) && $saved <= add_days($due, 3, false)) $out[] = $e;
                break;
            case 'maint':
                $lbl = [4 => '3 1/2', 8 => '7 1/2', 12 => '11 1/2'][$k['year']] ?? null;
                if ($lbl && str_starts_with($t, "$lbl Year Maintenance Fee")) $out[] = $e;
                break;
            case 'sou':   // a statement of use ends the whole extension chain
                if (preg_match('/^Statement of Use/', $t) && $due >= add_days($saved, -1, false)) $out[] = $e;
                break;
            case 'extension':   // the one extension due next
                if (preg_match('/^Statement of Use or \d\w* Extension Due/', $t) && $due >= add_days($saved, -1, false) && $saved >= add_months($due, -6, false) && !array_filter($out, fn($x) => preg_match('/Extension Due/', $x['type']))) $out[] = $e;
                break;
            case 's8_15':
                if (preg_match('/^Section 8 and 15/', $t) && $due >= add_months($saved, -13, false) && $due <= add_months($saved, 19, false)) $out[] = $e;
                break;
            case 's8_9':
                if (preg_match('/^Section 8 Declaration and Section 9|^Section 8 and 9/', $t) && $due >= add_months($saved, -13, false) && $due <= add_months($saved, 19, false)) $out[] = $e;
                break;
        }
    }
    return $out;
}
/** Look at every file not looked at before. Returns ['seen' => n, 'proposed' => [[ref, type, due, name], ...]]. */
function receipts_scan(): array {
    receipts_schema(); dbx_schema(); $r = ['seen' => 0, 'proposed' => []];
    $first = !q1('SELECT 1 FROM receipt_seen LIMIT 1');
    $since = gmdate('Y-m-d H:i:s', time() - RECEIPT_LOOKBACK_DAYS * 86400);
    $files = q('SELECT f.* FROM dbx_files f LEFT JOIN receipt_seen s ON s.path_lower=f.path_lower WHERE s.path_lower IS NULL AND f.root=? AND f.rel NOT LIKE "%/%" AND lower(f.name) LIKE "%.pdf"', [DBX_WRITE_ROOT]);
    foreach ($files as $f) {
        $r['seen']++; $saved = substr((string)($f['client_modified'] ?: $f['server_modified']), 0, 10); $result = '';
        $ref = preg_replace('/ \(.*$/', '', (string)$f['folder']);
        $m = q1('SELECT id, ref, kind FROM matters WHERE lower(ref)=lower(?) ORDER BY dead, id DESC LIMIT 1', [$ref]);
        $k = $m ? receipt_kind((string)$f['name'], (string)$m['kind']) : null;
        if ($k && ($f['client_modified'] ?: $f['server_modified']) >= $since) {
            foreach (receipt_targets((int)$m['id'], $k, $saved) as $e) {
                $why = 'Receipt in the file: ' . $f['name'] . ' (saved ' . fmt_date($saved) . ')';
                if (run('UPDATE events SET status="proposed", proposed_by="receipts", proposed_at=datetime("now"), note=note_add(note, ?) WHERE id=? AND status="open" AND rejected_at IS NULL', [$why, $e['id']]) === 1) {
                    run('INSERT OR REPLACE INTO receipt_hits(event_id,path,name,saved) VALUES (?,?,?,?)', [$e['id'], $f['path'], $f['name'], $saved]);
                    audit_done($e + ['matter_id' => $m['id']], $why, false);
                    $r['proposed'][] = [$m['ref'], $e['type'], $e['due_date'], $f['name']]; $result .= ($result ? '; ' : '') . $e['type'];
                }
            }
        }
        run('INSERT OR REPLACE INTO receipt_seen(path_lower,matter_id,kind,result) VALUES (?,?,?,?)', [$f['path_lower'], $m['id'] ?? null, $k['what'] ?? '', $first && !$result ? 'first scan' : $result]);
    }
    return $r;
}
/** A receipt Pendant itself just saved (bin/oa_fetch.php save_receipt): propose what it satisfies now, and mark the file seen so the folder scan
 *  does not look at it again. Returns the event types proposed. */
function receipt_propose(int $matterId, string $path, string $name, string $saved, string $kind): array {
    receipts_schema(); $k = receipt_kind($name, $kind); $done = [];
    if ($k) foreach (receipt_targets($matterId, $k, $saved) as $e) {
        $why = 'Receipt in the file: ' . $name . ' (saved ' . fmt_date($saved) . ')';
        if (run('UPDATE events SET status="proposed", proposed_by="receipts", proposed_at=datetime("now"), note=note_add(note, ?) WHERE id=? AND status="open" AND rejected_at IS NULL', [$why, $e['id']]) === 1) {
            run('INSERT OR REPLACE INTO receipt_hits(event_id,path,name,saved) VALUES (?,?,?,?)', [$e['id'], $path, $name, $saved]);
            audit_done($e + ['matter_id' => $matterId], $why, false); $done[] = $e['type'];
        }
    }
    run('INSERT OR REPLACE INTO receipt_seen(path_lower,matter_id,kind,result) VALUES (?,?,?,?)', [strtolower($path), $matterId, $k['what'] ?? '', $done ? implode('; ', $done) : 'saved by Pendant']);
    return $done;
}
/** The receipt behind a proposal, for the "Open receipt" button: ['path', 'name', 'saved'] or null. */
function receipt_for_event(int $eventId): ?array {
    receipts_schema(); return q1('SELECT path, name, saved FROM receipt_hits WHERE event_id=? ORDER BY at DESC LIMIT 1', [$eventId]) ?: null;
}
