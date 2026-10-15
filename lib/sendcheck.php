<?php
declare(strict_types=1);
/**
 * Pre-send check (2026-09-29, after XYZ002): reasons a "Send to client" email may be out of date. The send page shows
 * them in a red box and the Send button then needs "Send anyway" ticked. Never blocks outright and never changes the docket.
 */

/** A pending US trademark application: US, no registration yet, not a Madrid/foreign record. "Remind Client" is no longer docketed on these. */
function is_pending_us_tm_app(array $m): bool {
    return ($m['kind'] ?? '') === 'trademark' && trim((string)($m['reg_no'] ?? '')) === '' && empty($m['dead'])
        && preg_match('/^(United States|US|USA)?$/i', trim((string)($m['country'] ?? ''))) && !preg_match('/\.(Madrid|[A-Z]{2,3})$/i', (string)($m['ref'] ?? ''));
}

/** For a "Remind Client" tickler whose deadline is no longer open: that deadline (proposed or done), else null. */
function remind_closed_target(array $ev): ?array {
    foreach (q('SELECT * FROM events WHERE matter_id=? AND status IN ("proposed","done") AND due_date >= ? AND due_date <= ? AND id<>? ORDER BY due_date', [$ev['matter_id'], $ev['due_date'], add_months($ev['due_date'], 6, false), $ev['id']]) as $x)
        if (!is_notice_type($x['type'])) return $x;
    return null;
}

/** @return string[] plain-text reasons, most important first; [] = nothing found. $live = also ask TSDR / the Open Data Portal. */
function send_warnings(array $ev, array $m, bool $live = true, ?string &$info = null): array {
    $w = []; $info = null;
    $isRemind = (bool)preg_match('/^Remind Client$/i', (string)$ev['type']);
    $target = $isRemind ? remind_target($ev) : null;
    $subject = $target ?: $ev;   // the deadline the email is really about

    if (($am = assoc_matter($m)) && $am['instructing'] !== '' && $am['their_ref'] === '') $w[] = 'Associate matter with no file number of theirs on record: they need it in every email (add it on the matter page).';   // 2026-10-14, XYZ001
    if ($ev['status'] === 'proposed') $w[] = 'This event is already marked done, awaiting your confirmation' . (trim((string)$ev['note']) !== '' ? ': ' . trim((string)$ev['note']) : '') . '.';
    if ($isRemind && is_pending_us_tm_app($m)) $w[] = '"Remind Client" is no longer used on pending trademark applications; the deadline\'s own 30-day and 7-day reminders cover it.';
    if ($isRemind && !$target) {
        $ct = remind_closed_target($ev);
        $w[] = $ct ? 'The deadline this reminder points at (' . $ct['type'] . ', due ' . fmt_date($ct['due_date']) . ') is already ' . ($ct['status'] === 'done' ? 'done' : 'marked done, awaiting confirmation') . (trim((string)$ct['note']) !== '' ? ': ' . trim((string)$ct['note']) : '') . '.'
                   : 'No open deadline follows this reminder on ' . $m['ref'] . ', so there is nothing for the client to act on.';
    }
    if ($subject !== $ev && $subject['status'] === 'proposed') $w[] = 'The deadline it points at (' . $subject['type'] . ') is already marked done, awaiting confirmation.';

    // Already emailed about this deadline recently (by hand or by a reminder).
    $ids = array_values(array_unique(array_filter([(int)$ev['id'], (int)($target['id'] ?? 0)])));
    $sent = q('SELECT created_at, to_email, from_user FROM outbox WHERE event_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND mode="sent" AND COALESCE(transmit_error,"")="" AND created_at >= ? ORDER BY id DESC LIMIT 1', array_merge($ids, [add_days(today(), -45, false)]));
    if ($sent) $w[] = 'Already emailed about this deadline on ' . substr($sent[0]['created_at'], 0, 10) . ' (' . $sent[0]['from_user'] . ' to ' . $sent[0]['to_email'] . ').';

    // The matter folder (Dropbox, via the pushed file-name index): a response saved after the latest office action.
    if (preg_match('/^(Respond to Office Action|Final Response)/', $subject['type']) && ($ff = file_response_check((string)$m['ref']))) { if ($ff[0]) $w[] = $ff[1]; else $info = $ff[1]; }

    // The live USPTO record: does it show this deadline answered, or the property dead?
    $us = preg_match('/^(United States|US|USA)?$/i', trim((string)($m['country'] ?? '')));
    if ($live && $us && trim((string)$m['app_no']) !== '' && in_array($m['kind'], ['trademark', 'patent'], true) && !is_notice_type($subject['type'])) {
        try {
            $r = $m['kind'] === 'trademark' ? tsdr_lookup($m['app_no']) : odp_lookup($m['app_no']);
            if (!empty($r['ok'])) {
                foreach (check_matches($m, $r, [$subject + ['rejected_at' => null]]) as [, $why]) $w[] = 'The USPTO record suggests this is already handled: ' . $why . '.';
                if (($r['status'] ?? '') !== '' && $m['kind'] === 'trademark' && preg_match('/^Respond to Office Action|^Final Response/', $subject['type']) && preg_match('/registered|published|approved for pub|allowance/i', $r['status']) && !array_filter($w, fn($x) => str_starts_with($x, 'The USPTO record')))
                    $w[] = 'USPTO status is now "' . trim(explode('.', $r['status'])[0]) . '", which comes after an office action is resolved.';
            }
        } catch (Throwable $e) { /* a failed lookup is not a warning; the page says nothing */ }
    }
    return $w;
}

/** After a deadline is proposed or marked done: its "Remind Client" tickler(s) with no open deadline left behind them are proposed
 *  done too, so they stop showing on the dashboard (XYZ002 2026-09-29). Returns how many. Rejected proposals are left alone. */
function propose_orphan_reminders(int $mid, string $by): int {
    $n = 0;
    foreach (q('SELECT * FROM events WHERE matter_id=? AND status="open" AND type="Remind Client" AND rejected_at IS NULL', [$mid]) as $r) {
        if (remind_target($r) || !($ct = remind_closed_target($r))) continue;
        $why = 'its deadline (' . $ct['type'] . ', due ' . fmt_date($ct['due_date']) . ') is ' . ($ct['status'] === 'done' ? 'done' : 'proposed done');
        if (run('UPDATE events SET status="proposed", proposed_by=?, proposed_at=datetime("now"), note=note_add(note, ?) WHERE id=? AND status="open"', [$by, 'Reminder only: ' . $why, $r['id']]) === 1) { audit_done($r + ['matter_id' => $mid], 'reminder only: ' . $why, false); $n++; }
    }
    return $n;
}

/** Names + dates of the documents in a matter folder, from data/files_index.json.gz (pushed by the laptop every 10 minutes). */
function matter_file_index(string $ref): ?array {
    // 2026-10-01: from the Dropbox index once connected (the firm's Dropbox roots, never a forbidden one; newest 40 of the last 3 years), else the laptop's pushed index.
    if (function_exists('folders_mode') && folders_mode() === 'dbx') {
        foreach (array_unique([$ref, preg_replace('/\.\d+$/', '', $ref)]) as $r) {
            $rows = q('SELECT name, client_modified, server_modified FROM dbx_files WHERE (lower(folder)=lower(?) OR lower(folder) LIKE lower(?)) AND rel NOT LIKE "%/%" AND COALESCE(NULLIF(client_modified, ""), server_modified) >= ? ORDER BY COALESCE(NULLIF(client_modified, ""), server_modified) DESC LIMIT 40',
                [$r, $r . ' (%', gmdate('Y-m-d', time() - 1100 * 86400)]);
            if ($rows) return array_map(fn($x) => [(int)strtotime(($x['client_modified'] ?: $x['server_modified']) . ' UTC'), $x['name']], $rows);
        }
        return null;
    }
    static $idx = null;
    if ($idx === null) { $f = dirname($GLOBALS['cfg']['db']) . '/files_index.json.gz'; $idx = is_file($f) ? (json_decode((string)@gzdecode((string)file_get_contents($f)), true) ?: []) : []; }
    foreach ([$ref, preg_replace('/\.\d+$/', '', $ref)] as $r) if (isset($idx[$r])) return $idx[$r];
    return null;
}
/** [true, warning] when a response-looking document was saved after the latest office action in the file; [false, info] when not; null = no folder. */
function file_response_check(string $ref): ?array {
    $files = matter_file_index($ref); if (!$files) return null;
    $isOa = fn($n) => preg_match('/Office Action|Final Rejection|Restriction|Notice of (Allowance|Abandonment)|Advisory Action/i', $n) && !preg_match('/Response|Analysis|AckReceipt|Agenda|Interview|Prior Art|comments/i', $n);
    $isResp = fn($n) => preg_match('/Response to|AckReceipt|RCE|Amendment|Notice of Appeal|Appeal Brief|Request for Reconsideration|Statement of Use|Extension/i', $n) && !preg_match('/Analysis|Agenda|Interview Summary/i', $n);
    $oa = null; foreach ($files as [$t, $n]) if ($isOa($n)) { $oa = [$t, $n]; break; }   // newest first
    $d = fn($t) => date('Y-m-d', (int)$t);
    if (!$oa) return null;
    $after = array_values(array_filter($files, fn($x) => $x[0] > $oa[0] + 60 && $isResp($x[1])));
    if ($after) {
        $ack = array_values(array_filter($after, fn($x) => stripos($x[1], 'AckReceipt') !== false));
        if ($ack) return [true, 'Filed, per the file: "' . $ack[0][1] . '" saved ' . $d($ack[0][0]) . ', after the latest office action ("' . $oa[1] . '", ' . $d($oa[0]) . ').'];
        $r = $after[0]; return [true, 'A response was drafted: "' . $r[1] . '" saved ' . $d($r[0]) . ', after the latest office action ("' . $oa[1] . '", ' . $d($oa[0]) . '). No filing receipt in the file yet; check whether it was filed.'];
    }
    return [false, 'In the file: latest office action "' . $oa[1] . '" saved ' . $d($oa[0]) . '; nothing that looks like a response saved since.'];
}
