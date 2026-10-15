<?php
declare(strict_types=1);
/**
 * Maintenance schedules checked against the USPTO's own dates (audit 2026-10-02).
 *
 * What the audit found: DocketTrak docketed many registration maintenance schedules from an ESTIMATED date (1 to 6 weeks before the mark
 * actually registered) and later added a second schedule from the real registration date without removing the first (XYZ001, XYZ001,
 * PPO ...), so those marks carried two sets of Section 8/15/9 events, one of them wrong. Patent maintenance fees and "Patent Expires"
 * had the same kind of drift in a few records (XYZ003, XYZ039), and "Patent Expires" was 20 years from the application's OWN filing date,
 * ignoring an earlier parent (continuation / divisional / CIP / national stage) and patent term adjustment.
 *
 * These functions put the OPEN events of one matter on the dates the USPTO's registration / grant date gives. A wrong-dated event whose
 * correct twin is already docketed is cancelled (it is a duplicate); otherwise it is re-dated. Closed events are never touched.
 * Each change is written to the matter's change log. Returns [['id','type','from','to','action' => redated|cancelled], ...].
 */
const RECONCILE_MAX_DAYS = 100;   // further off than this is not "the same deadline docketed from a wrong date": leave it for a person

/** Month offsets from the registration date that an event type can sit on; null = not a registration-maintenance type. */
function tm_maint_offsets(string $type): ?array {
    $cyc = fn(int $first) => array_map(fn($k) => $first + 120 * $k, range(0, 9));
    if (preg_match('/^Section (8|71) and 15 Declarations (Begin|End|Grace Period Ends)$/', $type, $m)) return ['Begin' => [60], 'End' => [72], 'Grace Period Ends' => [78]][$m[2]];
    if (preg_match('/^Section 8 Declaration (Begins|Ends|Grace Period Ends)$/', $type, $m)) return ['Begins' => [60], 'Ends' => [72], 'Grace Period Ends' => [78]][$m[1]];
    if (preg_match('/^Section 71 Declaration (Begins|Ends|Grace Period Ends)$/', $type, $m)) return ['Begins' => array_merge([60], $cyc(108)), 'Ends' => array_merge([72], $cyc(120)), 'Grace Period Ends' => array_merge([78], $cyc(126))][$m[1]];
    if ($type === 'Section 8 Declaration and Section 9 Renewal Begins') return $cyc(108);
    if ($type === 'Section 8 Declaration and Section 9 Renewal Ends') return $cyc(120);
    if ($type === 'Section 8 and 9 Grace Period Ends') return $cyc(126);
    return null;
}
/** The date this event should carry, given the registration date; null when the type is not one of ours. */
function tm_maint_expected(string $type, string $due, string $reg): ?string {
    $offs = tm_maint_offsets($type); if ($offs === null) return null;
    $best = null; $bd = PHP_INT_MAX; $d = new DateTimeImmutable($due);
    foreach ($offs as $o) { $c = add_months($reg, $o, false); $x = abs((int)$d->diff(new DateTimeImmutable($c))->format('%r%a')); if ($x < $bd) { $bd = $x; $best = $c; } }
    return $best;
}
function reconcile_apply(array $ev, string $expected, string $why, bool $apply): array {
    $twin = q1('SELECT id FROM events WHERE matter_id=? AND type=? AND due_date=? AND status<>"cancelled" AND id<>?', [$ev['matter_id'], $ev['type'], $expected, $ev['id']]);
    $chg = ['id' => (int)$ev['id'], 'type' => $ev['type'], 'from' => $ev['due_date'], 'to' => $expected, 'action' => $twin ? 'cancelled' : 'redated'];
    if (!$apply) return $chg;
    if ($twin) {
        run('UPDATE events SET status="cancelled", done_by="audit", done_at=datetime("now"), note=note_add(note, ?) WHERE id=? AND status IN ("open","proposed")', ['cancelled ' . today() . ': duplicate docketed from a wrong date; the correct one is ' . $expected . ' (' . $why . ')', $ev['id']]);
        run('UPDATE reminders SET status="skipped", reason="event cancelled (duplicate from a wrong date)" WHERE event_id=? AND status IN ("pending","held")', [$ev['id']]);
        audit('redate', "$ev[type] $ev[due_date] cancelled: duplicate of the one on $expected ($why)", (int)$ev['matter_id']);
    } else {
        run('UPDATE events SET due_date=?, note=note_add(note, ?) WHERE id=? AND status IN ("open","proposed")', [$expected, 'date corrected ' . today() . ' from ' . $ev['due_date'] . ' (' . $why . ')', $ev['id']]);
        run('DELETE FROM reminders WHERE event_id=? AND status IN ("pending","held","skipped")', [$ev['id']]);   // re-planned from the new date, as when a date is changed by hand
        audit('redate', "$ev[type] moved from $ev[due_date] to $expected ($why)", (int)$ev['matter_id']);
    }
    return $chg;
}
/** Trademark: Section 8 / 15 / 9 / 71 events against the registration date. Registrations before 1989-11-16 (20-year terms) are left alone. */
function reconcile_tm_maintenance(int $matterId, string $reg, string $src = 'USPTO', bool $apply = true): array {
    $reg = substr($reg, 0, 10); $out = [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reg) || $reg < '1989-11-16') return $out;
    foreach (q('SELECT * FROM events WHERE matter_id=? AND status IN ("open","proposed") ORDER BY due_date, id', [$matterId]) as $ev) {
        $exp = tm_maint_expected((string)$ev['type'], (string)$ev['due_date'], $reg); if ($exp === null || $exp === $ev['due_date']) continue;
        // Anything from the anniversary to the next business day after it is the same deadline (37 CFR 2.196): leave it.
        if ($ev['due_date'] >= $exp && $ev['due_date'] <= roll_forward(new DateTimeImmutable($exp))->format('Y-m-d')) continue;
        if (abs(days_between($exp, (string)$ev['due_date'])) > RECONCILE_MAX_DAYS) continue;
        $out[] = reconcile_apply($ev, $exp, "registered $reg per $src", $apply);
    }
    return $out;
}
/** Utility patent: 3 1/2, 7 1/2 and 11 1/2 year maintenance events against the grant date (due and grace dates roll to a business day). */
function reconcile_patent_mf(int $matterId, string $grant, string $src = 'USPTO', bool $apply = true): array {
    $grant = substr($grant, 0, 10); $out = [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $grant)) return $out;
    foreach (q('SELECT * FROM events WHERE matter_id=? AND status IN ("open","proposed") ORDER BY due_date, id', [$matterId]) as $ev) {
        if (!preg_match('/^(3|7|11) 1\/2 Year Maintenance Fee (Window Open|Due|Grace Period)$/', (string)$ev['type'], $m)) continue;
        $y = (int)$m[1];
        $cal = add_months($grant, 12 * $y + ['Window Open' => 0, 'Due' => 6, 'Grace Period' => 12][$m[2]], false);   // the calendar date
        $exp = $m[2] === 'Window Open' ? $cal : roll_forward(new DateTimeImmutable($cal))->format('Y-m-d');
        if (($ev['due_date'] >= $cal && $ev['due_date'] <= $exp) || abs(days_between($exp, (string)$ev['due_date'])) > RECONCILE_MAX_DAYS) continue;   // calendar date .. next business day = the same deadline
        $out[] = reconcile_apply($ev, $exp, "granted $grant per $src", $apply);
    }
    return $out;
}
function days_between(string $a, string $b): int { return (int)(new DateTimeImmutable($a))->diff(new DateTimeImmutable($b))->format('%r%a'); }

/**
 * When a patent's term ends: 20 years from the earliest US non-provisional filing it claims (its own filing date, or the filing date of a
 * parent it continues / divides / continues-in-part, or the international filing date for a national stage), plus patent term adjustment;
 * a design patent, 15 years from grant (14 when filed before 2015-05-13). Terminal disclaimers are not in the data and are not considered.
 * $parents = odp_lookup()['parents'] / matters.priority_json parents. Returns [date, note] or null when it cannot be computed.
 */
function patent_term_end(array $matter, ?string $grant, array $parents = [], int $pta = 0): ?array {
    if (is_design_matter($matter)) {
        if (!$grant) return null; $y = design_term_years($matter, $grant);
        return [add_months($grant, 12 * $y, false), "Design patent: $y years from grant $grant; no maintenance fees fall due"];
    }
    $base = substr((string)($matter['filing_date'] ?? ''), 0, 10); if ($base === '') return null; $from = 'its filing date';
    foreach ($parents as $p) {
        $d = substr((string)($p['date'] ?? ''), 0, 10); $t = strtoupper((string)($p['type'] ?? ''));
        if ($d !== '' && in_array($t, ['CON', 'DIV', 'CIP', 'NST'], true) && $d < $base) { $base = $d; $from = 'parent ' . fmt_app((string)($p['no'] ?? '')) . ' (' . $t . ')'; }
    }
    $end = add_months($base, 240, false); if ($pta > 0) $end = add_days($end, $pta, false);
    return [$end, "20 years from $base ($from)" . ($pta > 0 ? " + $pta days of patent term adjustment" : '') . '; before any terminal disclaimer'];
}
/** The open "Patent Expires" event put on the computed date. */
function reconcile_patent_expiry(array $matter, ?string $grant, array $parents, int $pta, string $src = 'USPTO', bool $apply = true): array {
    $te = patent_term_end($matter, $grant, $parents, $pta); $out = []; if (!$te) return $out;
    foreach (q('SELECT * FROM events WHERE matter_id=? AND type="Patent Expires" AND status IN ("open","proposed")', [$matter['id']]) as $ev) {
        if ($ev['due_date'] === $te[0]) continue;
        $out[] = ['id' => (int)$ev['id'], 'type' => $ev['type'], 'from' => $ev['due_date'], 'to' => $te[0], 'action' => 'redated'];
        if (!$apply) continue;
        run('UPDATE events SET due_date=?, note=? WHERE id=?', [$te[0], $te[1] . ' (was ' . $ev['due_date'] . '; corrected ' . today() . " per $src)", $ev['id']]);
        audit('redate', "Patent Expires moved from $ev[due_date] to $te[0] ($te[1])", (int)$matter['id']);
    }
    return $out;
}
