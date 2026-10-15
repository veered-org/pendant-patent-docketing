<?php
declare(strict_types=1);
/**
 * "Fee paid" report (2026-09-28): on any record with a maintenance fee / renewal cycle, one click sends the client the
 * payment receipt (newest PDF in the file), lists the NEXT cycle's dates for their own records, and closes the paid cycle's events.
 *
 * A cycle = one fee family's window-open / deadline / grace events: "7 1/2 Year Maintenance Fee Window Open|Due|Grace Period",
 * "Section 8 and 15 Declarations Begin|End|Grace Period Ends", the ten-year Section 8 & 9 set, Section 71, Renewal Period ...
 */
const FEE_PAID_TEMPLATES = ['patent' => 'Maintenance Fee Paid', 'patent_final' => 'Final Maintenance Fee Paid', 'trademark' => 'Trademark Renewal Filed'];

/** [family, role, label] for a fee/renewal event type; role = open | due | grace. null for anything else. */
function fee_role(string $type): ?array {
    if (preg_match('/^(3|7|11) 1\/2 Year Maintenance Fee (Window Open|Due|Grace Period)$/', $type, $m))
        return ["MF$m[1]", ['Window Open' => 'open', 'Due' => 'due', 'Grace Period' => 'grace'][$m[2]], "$m[1].5 year fee"];
    if ($type === 'Maintenance Fee Due') return ['MF', 'due', 'Next maintenance fee'];
    $tm = ['Section 8 and 15 Declarations' => 'Section 8 & 15 declarations', 'Section 71 and 15 Declarations' => 'Section 71 & 15 declarations',
           'Section 8 Declaration and Section 9 Renewal' => 'Section 8 & 9 renewal', 'Section 8 Declaration' => 'Section 8 declaration',
           'Section 71 Declaration' => 'Section 71 declaration', 'Renewal Period' => 'Renewal'];
    $grace = ['Section 8 and 9 Grace Period Ends' => 'Section 8 Declaration and Section 9 Renewal', 'Renewal Grace Period Ends' => 'Renewal Period'];
    if (isset($grace[$type])) return [$grace[$type], 'grace', $tm[$grace[$type]]];
    if ($type === 'Renewal') return ['Renewal Period', 'due', 'Renewal'];
    foreach ($tm as $fam => $label) {
        if (preg_match('/^' . preg_quote($fam, '/') . ' (Begins?|Ends?|Grace Period Ends)$/', $type, $m))
            return [$fam, str_starts_with($m[1], 'Begin') ? 'open' : (str_starts_with($m[1], 'End') ? 'due' : 'grace'), $label];
    }
    return null;
}

/**
 * The matter's fee cycles, earliest deadline first: each ['family','label','due' (deadline date),'events' => [role => event]].
 * Events of one family belong to the same cycle when they fall within 13 months before / 7 months after its deadline, so a
 * recurring family (Section 8 & 9 every ten years) splits into separate cycles. Cancelled events are ignored.
 */
function fee_cycles(int $matterId): array {
    $evs = []; foreach (q('SELECT * FROM events WHERE matter_id=? AND status<>"cancelled" ORDER BY due_date, id', [$matterId]) as $e) if ($r = fee_role($e['type'])) $evs[] = $e + ['_f' => $r[0], '_r' => $r[1], '_l' => $r[2]];
    $cycles = [];
    foreach ($evs as $e) if ($e['_r'] === 'due') $cycles[] = ['family' => $e['_f'], 'label' => $e['_l'], 'due' => $e['due_date'], 'events' => ['due' => $e]];
    foreach ($evs as $e) {
        if ($e['_r'] === 'due') continue;
        $best = null;
        foreach ($cycles as $i => $c) {
            if ($c['family'] !== $e['_f'] || isset($c['events'][$e['_r']])) continue;
            if ($e['due_date'] < add_months($c['due'], -13, false) || $e['due_date'] > add_months($c['due'], 7, false)) continue;
            if ($best === null || abs(strtotime($e['due_date']) - strtotime($c['due'])) < abs(strtotime($e['due_date']) - strtotime($cycles[$best]['due']))) $best = $i;
        }
        if ($best !== null) $cycles[$best]['events'][$e['_r']] = $e;
        else $cycles[] = ['family' => $e['_f'], 'label' => $e['_l'], 'due' => $e['due_date'], 'events' => [$e['_r'] => $e]];   // a grace/open event with no deadline beside it
    }
    usort($cycles, fn($a, $b) => strcmp($a['due'], $b['due']));
    return $cycles;
}

/** The cycle being paid now (the one holding the earliest open or proposed-done fee event: the sweep proposes a cycle done when it sees the payment, and that is exactly when the report goes) and the one after it; [null, null] when nothing is open. */
function fee_current_next(int $matterId): array {
    $cycles = fee_cycles($matterId); $cur = null; $curDate = null;
    // 2026-10-02 (XYZ064): a cycle whose window (opens .. grace ends) holds today is the one being paid, even when its events
    // were already closed by hand; otherwise "Renewal filed" reported the NEXT cycle as paid and listed the one after it. Skipped once
    // its fee-paid report has gone out, so the button then moves on to the next open cycle as before.
    $today = date('Y-m-d');
    foreach ($cycles as $i => $c) {
        $from = isset($c['events']['open']) ? $c['events']['open']['due_date'] : add_months($c['due'], -12, false);
        $to = isset($c['events']['grace']) ? $c['events']['grace']['due_date'] : add_months($c['due'], 6, false);
        if ($from > $today || $to < $today || !isset($c['events']['due'])) continue;
        foreach ($c['events'] as $e) if (preg_match('/fee paid; receipt emailed|fee-paid report emailed/', (string)$e['note'])) continue 2;
        return [$c, $cycles[$i + 1] ?? null];
    }
    foreach ($cycles as $i => $c) foreach ($c['events'] as $e) if (($e['status'] === 'open' || $e['status'] === 'proposed') && ($curDate === null || $e['due_date'] < $curDate)) { $cur = $i; $curDate = $e['due_date']; }
    if ($cur === null) return [null, null];
    return [$cycles[$cur], $cycles[$cur + 1] ?? null];
}

/** A fee-paid report may start from an already-closed event when it belongs to the cycle being reported (XYZ064 2026-10-02: closed by hand before "Renewal filed"). */
function fee_paid_event_ok(array $ev): bool {
    $cur = fee_current_next((int)$ev['matter_id'])[0];
    return $cur !== null && in_array((int)$ev['id'], array_map(fn($e) => (int)$e['id'], array_values($cur['events'])), true);
}

/** Client-facing lines for the next cycle ("11.5 year fee opens: August 20, 2030" ...). */
function fee_next_lines(?array $next, string $kind): string {
    if (!$next) return '';
    $late = $kind === 'trademark' ? 'last day to file with additional fee' : 'last day to pay with additional fee';
    $words = ['open' => 'opens', 'due' => 'due', 'grace' => $late]; $out = [];
    foreach (['open', 'due', 'grace'] as $r) if (isset($next['events'][$r])) $out[] = $next['label'] . ' ' . $words[$r] . ': ' . fmt_long($next['events'][$r]['due_date']);
    return implode("\n", $out);
}

/** Which template a fee-paid report uses for this matter. */
function fee_paid_template_type(array $m, ?array $next): string {
    if ($m['kind'] === 'trademark') return FEE_PAID_TEMPLATES['trademark'];
    return $next ? FEE_PAID_TEMPLATES['patent'] : FEE_PAID_TEMPLATES['patent_final'];
}

/**
 * Recent PDFs from the matter's Dropbox folder, as pushed to the server by the laptop (bin/push_files.php → data/files/<folder>/),
 * newest first: [['path','name','mtime','size'], ...].
 */
function matter_files(string $ref): array {
    // 2026-10-01: straight from Dropbox once it is connected (the newest 6 PDFs of the last 45 days, as the laptop push used to send);
    // path '' = downloaded when attached (attach_matter_files). Until then, the files the laptop pushed. 2026-10-04: a local
    // folder root (the laptop, the tests' scratch folders) is read the same way through mf_find, so the ".003 / .03" matching applies there too.
    if (function_exists('folders_mode') && folders_mode() !== 'none') {
        $f = mf_find($ref); if (!$f) return []; $out = [];
        foreach (mf_list($f) as $x) if (preg_match('/\.pdf$/i', $x['name']) && $x['size'] <= 15 * 1024 * 1024 && $x['mtime'] >= time() - 45 * 86400) $out[] = ['path' => $f['mode'] === 'local' ? mf_local($f, $x['rel']) : '', 'name' => $x['name'], 'mtime' => $x['mtime'], 'size' => $x['size'], 'folder' => $f, 'rel' => $x['rel']];
        return array_slice($out, 0, 6);
    }
    $root = dirname($GLOBALS['cfg']['db']) . '/files'; if (!is_dir($root)) return [];
    $dir = find_matter_dir($root, $ref); if ($dir === '') return [];
    $out = [];
    foreach (scandir($dir) ?: [] as $n) if (preg_match('/\.pdf$/i', $n) && is_file("$dir/$n")) $out[] = ['path' => "$dir/$n", 'name' => $n, 'mtime' => filemtime("$dir/$n"), 'size' => filesize("$dir/$n")];
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

/** The most recent fee-paid close-out on a matter: the events it closed (still done/proposed) + the date; null if none. */
function fee_paid_last_batch(int $matterId): ?array {
    $evs = q('SELECT * FROM events WHERE matter_id=? AND status IN ("done","proposed") AND note LIKE "%fee paid; receipt emailed %"', [$matterId]); if (!$evs) return null;
    $at = fn($e) => (string)($e['status'] === 'done' ? $e['done_at'] : $e['proposed_at']);
    $latest = max(array_map($at, $evs)); $batch = array_values(array_filter($evs, fn($e) => $at($e) === $latest));
    preg_match_all('/fee paid; receipt emailed (\d{4}-\d{2}-\d{2})/', (string)$batch[0]['note'], $mm);
    return ['events' => $batch, 'date' => end($mm[1]) ?: substr($latest, 0, 10), 'by' => (string)($batch[0]['done_by'] ?: $batch[0]['proposed_by'])];
}
/** the attorney may undo any fee-paid close-out; anyone else only their own. */
function fee_paid_can_undo(array $b): bool { return is_admin() || $b['by'] === auth_user(); }
