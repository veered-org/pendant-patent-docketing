<?php
declare(strict_types=1);
/**
 * Foreign patent annuities (2026-10-02): file numbers carry the country (XYZ001.AU). For a country with yearly fees, the next
 * annuities are docketed as "Annuity Due" events (reminders 60 and 30 days before). Each matter's basis (the date the annuities count
 * from, which annuity is next) comes from the foreign associate's report / emails and is CONFIRMED by the attorney before it is entered
 * (table annuity_basis); DocketTrak is never used. Rules (research 2026-10-02, data/annuity-research/country-rules.md):
 *   CA  yearly, fee N on the Nth anniversary of the filing date (PCT: international filing date), N = 2..19, pending or granted
 *   AU  yearly, fee N on the Nth anniversary of the filing date (PCT: international filing date), N = 4..19 (standard patent)
 *   BR  yearly, the Nth annuity (N = 3..20) is due at the END of its 3-month ordinary period: anniversary N-1 of filing + 3 months
 *       (XYZ005 filed 13 Jul 2012: 15th due 13 Oct 2026; XYZ018 int'l 13 May 2024: 4th due 13 Aug 2027 — as the associate stated)
 *   EP  pending application, renewal fee for year N (N >= 3) due on the last day of the month of anniversary N-1 of filing
 *   EPN granted EP in national states (e.g. DE, FR, GB): year N due on the last day of the month of anniversary N-1 of the EP filing
 *       date (XYZ005 / XYZ007 filed 5 Aug 2013: 14th due 31 Aug 2026, as Hernandez IP stated); also GB national patents (N >= 5)
 *   JP, KR  after grant only; year N (N >= 4) due on anniversary N-1 of the REGISTRATION date
 *   CN  after grant only (the grant-year fee is paid with registration); year N due on anniversary N-1 of the FILING date, through
 *       year 20 (invention), 10 (utility model), 15 (design filed from 1 June 2021, else 10); 6 months' grace with surcharge
 * Not yearly (not docketed here): IL (periods at grant, 6, 10, 14, 18 years), CL (decade fees), designs (5-year renewals), US.
 */
const ANNUITY_EVENT = 'Annuity Due';
const ANNUITY_AHEAD = 2;   // the next two unpaid annuities are always docketed; a done one makes room for the next

function annuity_schema(): void {
    static $d = false; if ($d) return; $d = true;
    db()->exec("CREATE TABLE IF NOT EXISTS annuity_basis (matter_id INTEGER PRIMARY KEY, scheme TEXT NOT NULL, base_date TEXT NOT NULL, next_year INTEGER NOT NULL,
        last_year INTEGER NOT NULL DEFAULT 20, states TEXT DEFAULT '', source TEXT DEFAULT '', confirmed_by TEXT, confirmed_at TEXT, created_at TEXT DEFAULT (datetime('now')))");
}
function last_day_of_month(string $iso): string { return (new DateTimeImmutable($iso))->format('Y-m-t'); }
/** Due date of annuity/year $n under $scheme counted from $base. */
function annuity_due(string $scheme, string $base, int $n): string {
    switch ($scheme) {
        case 'CA': case 'AU': return add_months($base, $n * 12, false);
        case 'BR': return add_months($base, ($n - 1) * 12 + 3, false);
        case 'EP': case 'EPN': case 'GB': return last_day_of_month(add_months($base, ($n - 1) * 12, false));
        case 'JP': case 'KR': case 'CN': return add_months($base, ($n - 1) * 12, false);
    }
    throw new InvalidArgumentException("no annuity rule for $scheme");
}
function annuity_country_name(string $scheme, string $states = ''): string {
    return ['CA' => 'Canada', 'AU' => 'Australia', 'BR' => 'Brazil', 'EP' => 'the European Patent Office', 'GB' => 'the United Kingdom', 'JP' => 'Japan', 'KR' => 'Korea', 'CN' => 'China'][$scheme]
        ?? ($scheme === 'EPN' ? implode(', ', array_map(fn($c) => ['DE' => 'Germany', 'FR' => 'France', 'GB' => 'the United Kingdom', 'IT' => 'Italy', 'ES' => 'Spain', 'NL' => 'the Netherlands', 'CH' => 'Switzerland', 'IE' => 'Ireland'][$c] ?? $c, array_filter(explode(',', $states)))) : $scheme);
}
function ordinal(int $n): string { $s = ['th', 'st', 'nd', 'rd']; $v = $n % 100; return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]); }
/** The year number an annuity event is for ("Year 15 annuity ..." in its note). */
function annuity_event_year(array $e): ?int { return preg_match('/^Year (\d+) /', (string)$e['note'], $m) ? (int)$m[1] : null; }

/** Keep the next ANNUITY_AHEAD unpaid annuities of one confirmed matter docketed. Returns the events created (type, due, note). */
function sync_annuities(int $matterId, bool $apply = true): array {
    annuity_schema(); $b = q1('SELECT * FROM annuity_basis WHERE matter_id=? AND confirmed_at IS NOT NULL', [$matterId]); if (!$b) return [];
    $m = q1('SELECT * FROM matters WHERE id=?', [$matterId]); if (!$m || $m['dead']) return [];
    $evs = q('SELECT * FROM events WHERE matter_id=? AND type=?', [$matterId, ANNUITY_EVENT]);
    $have = []; foreach ($evs as $e) if (($y = annuity_event_year($e)) !== null) $have[$y] = $e['status'];
    $paid = array_keys(array_filter($have, fn($s) => in_array($s, ['done', 'proposed'], true)));
    $y = max((int)$b['next_year'], $paid ? max($paid) + 1 : 0); $out = [];
    for ($open = 0; $y <= (int)$b['last_year'] && $open < ANNUITY_AHEAD; $y++) {
        if (isset($have[$y])) { if ($have[$y] === 'open') $open++; continue; }
        $due = annuity_due((string)$b['scheme'], (string)$b['base_date'], $y);
        $note = 'Year ' . $y . ' annuity in ' . annuity_country_name((string)$b['scheme'], (string)$b['states']) . ' (' . $b['scheme'] . ' rule, counted from ' . fmt_date((string)$b['base_date']) . ')';
        if ($apply) {
            run('INSERT INTO events(matter_id,type,due_date,status,source,doc_code,note) VALUES (?,?,?,"open","rule","ANNUITY",?)', [$matterId, ANNUITY_EVENT, $due, $note]);
            audit('annuity', "$m[ref] $note due " . fmt_date($due), $matterId);
        }
        $out[] = ['year' => $y, 'due' => $due, 'note' => $note]; $open++;
    }
    return $out;
}
/** Nightly: every confirmed basis. */
function run_annuities(): array { annuity_schema(); $out = auto_canada(); foreach (q('SELECT matter_id FROM annuity_basis WHERE confirmed_at IS NOT NULL') as $r) foreach (sync_annuities((int)$r['matter_id']) as $e) $out[] = $e; return $out; }

/* Canada, automatic (2026-10-15, "do that for any canadian trademark or patent"): no hand-confirmed basis needed.
 *   Patents: maintenance fees on every anniversary of the filing date (years 2-19), pending or granted. A live Canadian patent matter
 *     with a filing date and no basis gets one (confirmed_by 'auto'), starting at the first anniversary still ahead (earlier ones were
 *     paid at national entry or before Pendant). Skipped: designs (no yearly fees), matters already carrying a hand-docketed
 *     Maintenance Fee Due / Renewal (those stay as they are). PCT national entries: the filing date must be the international one.
 *   Trademarks: no maintenance fees; the registration is renewed at the end of each term — 10 years from registration (15 for
 *     registrations before 17 June 2019), renewable in the 6 months before. Once a registration date is entered, the next term's
 *     "Renewal Period Begins" / "Renewal Period Ends" are docketed, and the following term's once that one has passed. */
const CA_TM_NEW_TERM_FROM = '2019-06-17';
function is_canadian(array $m): bool { return in_array(strtolower(trim((string)$m['country'])), ['canada', 'ca'], true) || preg_match('/\.CA(\b|$)/i', (string)$m['ref']); }
function ca_is_design(array $m): bool { return stripos((string)$m['app_type'], 'design') !== false || preg_match('/^\d{6}$/', trim((string)$m['reg_no'])); }   // CA patents are 7-digit, industrial designs 6-digit
/** First anniversary year (>= 2) whose fee is due today or later. */
function ca_next_year(string $filed): int { for ($n = 2; $n < 20 && annuity_due('CA', $filed, $n) < today(); $n++); return $n; }
/** Expiry dates of a Canadian registration's terms, oldest first, up to $until. */
function ca_tm_expiries(string $reg, string $until): array {
    $x = add_months($reg, ($reg < CA_TM_NEW_TERM_FROM ? 15 : 10) * 12, false); $out = [];
    for (; $x <= $until; $x = add_months($x, 120, false)) $out[] = $x; $out[] = $x; return $out;
}
function auto_canada(bool $apply = true): array {
    annuity_schema(); $out = [];
    foreach (q("SELECT * FROM matters WHERE dead=0 AND kind IN ('patent','trademark')") as $m) {
        if (!is_canadian($m)) continue;
        if ($m['kind'] === 'patent') {
            $filed = substr((string)$m['filing_date'], 0, 10);
            if (!preg_match('/^\d{4}-\d\d-\d\d$/', $filed) || ca_is_design($m)) continue;
            if (q1('SELECT 1 FROM annuity_basis WHERE matter_id=?', [$m['id']])) continue;
            if (q1("SELECT 1 FROM events WHERE matter_id=? AND status IN ('open','proposed') AND type IN ('Maintenance Fee Due','Renewal')", [$m['id']])) continue;
            $n = ca_next_year($filed); if ($n > 19) continue;
            if ($apply) {
                run('INSERT INTO annuity_basis(matter_id,scheme,base_date,next_year,last_year,source,confirmed_by,confirmed_at) VALUES (?,"CA",?,?,19,?,"auto",datetime("now"))',
                    [$m['id'], $filed, $n, 'Automatic: Canadian filing date ' . $filed]);
                audit('annuity', "$m[ref] Canadian maintenance fees counted from the filing date " . fmt_date($filed) . ", next = year $n", (int)$m['id']);
            }
            $out[] = ['ref' => $m['ref'], 'what' => "maintenance fees from $filed, next year $n"];
            continue;
        }
        foreach (tm_renewal_dock($m, $apply) as $w) $out[] = ['ref' => $m['ref'], 'what' => $w];
    }
    foreach (q("SELECT * FROM matters WHERE dead=0 AND kind IN ('patent','trademark')") as $m) {
        if (!is_mexican($m) && !is_chinese($m)) continue;
        if ($m['kind'] === 'trademark') { foreach (tm_renewal_dock($m, $apply) as $w) $out[] = ['ref' => $m['ref'], 'what' => $w]; continue; }
        if (!is_chinese($m) || !($b = cn_patent_basis($m))) continue;
        if ($apply) {
            run('INSERT INTO annuity_basis(matter_id,scheme,base_date,next_year,last_year,source,confirmed_by,confirmed_at) VALUES (?,"CN",?,?,?,?,"auto",datetime("now"))',
                [$m['id'], $b['filed'], $b['next'], $b['last'], 'Automatic: Chinese ' . $b['type'] . ' filed ' . $b['filed'] . ', granted ' . $b['granted']]);
            audit('annuity', "$m[ref] Chinese annuities counted from the filing date " . fmt_date($b['filed']) . ", next = year $b[next]", (int)$m['id']);
        }
        $out[] = ['ref' => $m['ref'], 'what' => "maintenance fees from $b[filed], next year $b[next]"];
    }
    return $out;
}
/* China, automatic (2026-10-15). Trademarks: 10-year terms from the registration date, ending the day before the anniversary
 *   (certificate: 2025-12-14 to 2035-12-13); renewable in the 12 months before, 6 months' grace after. Patents: yearly annuities once
 *   granted (rule CN above); the type comes from app_type or the 5th digit of the application number (1/8 invention, 2/9 utility
 *   model, 3 design). Needs filing AND grant dates; skipped when annuities are already hand-docketed. */
function is_chinese(array $m): bool { return stripos((string)$m['ref'], 'MADRID') === false && (in_array(strtolower(trim((string)$m['country'])), ['china', 'cn'], true) || preg_match('/\.CN(\b|$)/i', (string)$m['ref'])); }   // a Madrid designation renews at WIPO
function cn_patent_basis(array $m): ?array {
    $iso = '/^\d{4}-\d\d-\d\d$/'; $filed = substr((string)$m['filing_date'], 0, 10); $granted = substr((string)$m['grant_date'], 0, 10);
    if (!preg_match($iso, $filed) || !preg_match($iso, $granted)) return null;
    if (q1('SELECT 1 FROM annuity_basis WHERE matter_id=?', [$m['id']])) return null;
    if (q1("SELECT 1 FROM events WHERE matter_id=? AND status IN ('open','proposed') AND type IN ('Maintenance Fee Due','Renewal','Annuity Due')", [$m['id']])) return null;
    $at = strtolower((string)$m['app_type']); $d = preg_replace('/\D/', '', (string)$m['app_no']); $k = strlen($d) >= 12 ? $d[4] : '';
    if (str_contains($at, 'design') || $k === '3') [$type, $last] = ['design', $filed >= '2021-06-01' ? 15 : 10];
    elseif (str_contains($at, 'utility model') || $k === '2' || $k === '9') [$type, $last] = ['utility model', 10];
    else [$type, $last] = ['invention patent', 20];
    $n = 2; while ($n <= $last && (annuity_due('CN', $filed, $n) < today() || annuity_due('CN', $filed, $n) <= $granted)) $n++;
    return $n > $last ? null : ['filed' => $filed, 'granted' => $granted, 'type' => $type, 'next' => $n, 'last' => $last];
}
/* Mexico, automatic (2026-10-15, after QUE010.MX): trademark registrations last 10 years and are renewed in the 6 months
 *   before the term ends (6 months' grace after). The term counts from the FILING date for applications filed before 10 August 2018
 *   (the certificate says so: "vigencia de diez años contados a partir de la fecha de presentación"), from the GRANT date for later
 *   ones. Registrations granted from 10 August 2018 also need a Declaration of Use within the 3 months after the 3rd anniversary of
 *   the grant. No patents here (none live). */
const MX_TM_GRANT_TERM_FROM = '2018-08-10';
function is_mexican(array $m): bool { return in_array(strtolower(trim((string)$m['country'])), ['mexico', 'mx'], true) || preg_match('/\.MX(\b|$)/i', (string)$m['ref']); }
/** [term start, label] of a Mexican registration, or null when the date it counts from is missing. */
function mx_tm_base(array $m): ?array {
    $filed = substr((string)$m['filing_date'], 0, 10); $granted = substr((string)$m['grant_date'], 0, 10); $iso = '/^\d{4}-\d\d-\d\d$/';
    if (!preg_match($iso, $filed)) return null;                     // without the filing date the rule can't be chosen
    if ($filed < MX_TM_GRANT_TERM_FROM) return [$filed, 'filed'];
    return preg_match($iso, $granted) ? [$granted, 'granted'] : null;
}
/** Expiry dates of a registration's 10-year terms counted from $base, oldest first, up to the first one after $until. */
function tm_expiries(string $base, string $until, int $firstYears = 10): array {
    $out = []; for ($x = add_months($base, $firstYears * 12, false); $x <= $until; $x = add_months($x, 120, false)) $out[] = $x; $out[] = $x; return $out;
}
/** Docket the next renewal of a live CA/MX registration: the first term end still renewable (up to 6 months after it) that is not
 *  already renewed. A term whose renewal event is done (or proposed done) counts as renewed, so the NEXT term is docketed right away
 *  (QUE010.MX: 2027 renewal filed 2026-10-13 → 2037 docketed). An open renewal event means it is already docketed. Returns what was added. */
function tm_renewal_dock(array $m, bool $apply = true): array {
    $mx = is_mexican($m); $cn = !$mx && is_chinese($m); $regNo = trim((string)$m['reg_no']);
    if ($cn) { $base = substr((string)$m['grant_date'], 0, 10); $how = 'registered';
        if ($regNo === '' || !preg_match('/^\d{4}-\d\d-\d\d$/', $base)) return [];
        $exps = array_map(fn($x) => (new DateTimeImmutable($x))->modify('-1 day')->format('Y-m-d'), tm_expiries($base, add_months(today(), 121, false))); }
    elseif ($mx) { $b = mx_tm_base($m); if (!$b || $regNo === '') return []; [$base, $how] = $b; $exps = tm_expiries($base, add_months(today(), 120, false)); }
    else { $base = substr((string)$m['grant_date'], 0, 10); $how = 'registered';
        if ($regNo === '' || !preg_match('/^\d{4}-\d\d-\d\d$/', $base)) return []; $exps = ca_tm_expiries($base, add_months(today(), 120, false)); }
    $out = []; $cut = add_months(today(), -6, false);
    $granted = substr((string)$m['grant_date'], 0, 10);
    if ($mx && preg_match('/^\d{4}-\d\d-\d\d$/', $granted) && $granted >= MX_TM_GRANT_TERM_FROM) {   // one Declaration of Use, 3rd anniversary of the grant + 3 months
        $dou = add_months($granted, 39, false);
        if ($dou >= today() && !q1("SELECT 1 FROM events WHERE matter_id=? AND status<>'cancelled' AND type LIKE '%Declaration of Use%'", [$m['id']])) {
            $note = 'Mexican registration ' . $regNo . ' granted ' . fmt_date($granted) . ': Declaration of Use due within 3 months after the 3rd anniversary';
            if ($apply) { run('INSERT INTO events(matter_id,type,due_date,status,source,doc_code,note) VALUES (?,?,?,"open","rule","MX-USE",?)', [$m['id'], 'Declaration of Use Due', $dou, $note]); audit('rule', "$m[ref] Declaration of Use Due " . fmt_date($dou) . " ($note)", (int)$m['id']); }
            $out[] = "Declaration of Use Due $dou";
        }
    }
    foreach ($exps as $exp) {
        if ($exp < $cut) continue;
        $evs = q("SELECT status, COALESCE(done_at, proposed_at, '') closed FROM events WHERE matter_id=? AND status<>'cancelled' AND (type LIKE 'Renewal%' OR type LIKE '%Renewal Ends') AND due_date BETWEEN ? AND ?", [$m['id'], add_months($exp, -7, false), add_months($exp, 7, false)]);
        if (in_array('open', array_column($evs, 'status'), true)) break;               // already docketed
        // renewed: the next term. Only a renewal closed in the 12 months before the term ends counts (a renewal can't be filed earlier;
        // XYZ003.CA's 2030 renewal was closed in DocketTrak in 2025, which is not a renewal)
        if (array_filter($evs, fn($e) => $e['closed'] !== '' && substr($e['closed'], 0, 10) >= add_months($exp, $cn ? -13 : -12, false))) continue;
        $label = $cn ? 'Chinese' : ($mx ? 'Mexican' : 'Canadian');
        $note = "$label registration $regNo " . ($how === 'filed' ? 'filed ' : 'of ') . fmt_date($base) . ': term ends ' . fmt_date($exp);
        $begins = $cn ? (new DateTimeImmutable(add_months($exp, -12, false)))->modify('+1 day')->format('Y-m-d') : add_months($exp, -6, false);
        foreach ([['Renewal Period Begins', $begins], ['Renewal Period Ends', $exp]] as [$type, $due]) {
            if ($apply) {
                run('INSERT INTO events(matter_id,type,due_date,status,source,doc_code,note) VALUES (?,?,?,"open","rule",?,?)', [$m['id'], $type, $due, $cn ? 'CN-RENEWAL' : ($mx ? 'MX-RENEWAL' : 'CA-RENEWAL'), $note]);
                audit('rule', "$m[ref] $type " . fmt_date($due) . " ($note)", (int)$m['id']);
            }
            $out[] = "$type $due";
        }
        break;
    }
    return $out;
}
/** Right after a Canadian record is created or its dates change: docket now rather than at night. Returns ' Annuity Due 2027-06-29, ...' or ''. */
function ca_docket_now(int $matterId): string {
    $m = q1('SELECT * FROM matters WHERE id=?', [$matterId]); if (!$m || (!is_canadian($m) && !is_mexican($m) && !is_chinese($m))) return '';
    $out = []; foreach (auto_canada() as $r) if ($r['ref'] === $m['ref'] && !str_starts_with($r['what'], 'maintenance')) $out[] = $r['what'];
    foreach (sync_annuities($matterId) as $e) $out[] = ANNUITY_EVENT . ' ' . $e['due'];
    return $out ? ' ' . implode(', ', $out) : '';
}
