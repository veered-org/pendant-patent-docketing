<?php
declare(strict_types=1);
/**
 * Suggested USPTO fee beside "Open invoice system" (2026-10-09): a maintenance fee by year and entity size, a trademark
 * Section 8 / 9 / 15 / 71 filing by number of classes, each with the grace-period surcharge once the deadline has passed.
 * Amounts from https://www.uspto.gov/learning-and-resources/fees-and-payment/uspto-fee-schedule (effective 2025-01-19, revised
 * 2026-08-14, read 2026-10-09). The nightly re-reads that page weekly (govfee_check) and flags any amount that no longer matches.
 */
const GOVFEE_URL = 'https://www.uspto.gov/learning-and-resources/fees-and-payment/uspto-fee-schedule';
// Patent: [undiscounted, small, micro]; fee codes 1xxx / 2xxx / 3xxx.
const GOVFEE_MF = ['3' => [2150, 860, 430], '7' => [4040, 1616, 808], '11' => [8280, 3312, 1656]];
const GOVFEE_MF_CODE = ['3' => '1551/2551/3551', '7' => '1552/2552/3552', '11' => '1553/2553/3553'];
const GOVFEE_MF_LATE = [540, 216, 108];   // 1554-1556 / 2554-2556 / 3554-3556, same for each year
// Trademark, electronically filed, per class.
const GOVFEE_TM = ['7205' => 325, '7201' => 325, '7208' => 250, '7905' => 325, '7206' => 100, '7203' => 100, '7906' => 100];

/** 0 undiscounted / 1 small / 2 micro / null unknown, from the record (or the Open Data Portal, saved on the record). */
function govfee_entity(array $m): ?int {
    $e = strtolower(trim((string)($m['entity'] ?? '')));
    if ($e === '' && ($app = preg_replace('/\D/', '', (string)($m['app_no'] ?? ''))) !== '' && !empty($GLOBALS['cfg']['odp_key'])) {
        try { $r = odp_lookup($app); } catch (Throwable $x) { $r = []; }
        $e = strtolower((string)($r['entity'] ?? ''));
        $save = str_contains($e, 'micro') ? 'Micro' : (str_contains($e, 'small') ? 'Small' : (preg_match('/undiscounted|regular|large/', $e) ? 'Large' : ''));
        if ($save !== '') run('UPDATE matters SET entity=? WHERE id=? AND entity=""', [$save, $m['id']]);
    }
    return str_contains($e, 'micro') ? 2 : (str_contains($e, 'small') ? 1 : (preg_match('/undiscounted|regular|large/', $e) ? 0 : null));
}

/** The fee cycle an event belongs to, from lib/feepaid.php's cycles. */
function govfee_cycle(array $ev): ?array {
    if (empty($ev['matter_id']) || !fee_role((string)$ev['type'])) return null;
    $cycles = fee_cycles((int)$ev['matter_id']);
    foreach ($cycles as $c) foreach ($c['events'] as $e) if ((int)$e['id'] === (int)($ev['id'] ?? 0)) return $c;
    return null;
}

/** USPTO maintenance-fee payment page with the patent and application numbers filled in; '' when either is missing. */
function uspto_mf_url(array $m): string {
    $pat = strtoupper(preg_replace('/[\s,]/', '', (string)($m['reg_no'] ?? ''))); $app = preg_replace('/\D/', '', (string)($m['app_no'] ?? ''));
    return $pat !== '' && $app !== '' ? 'https://fees.uspto.gov/MaintenanceFees/?' . http_build_query(['patentNumber' => $pat, 'applicationNumber' => $app]) : '';
}

/** ['total' => int|null, 'html' => short line] for the fee being invoiced; null when the event is not a US maintenance fee / post-registration filing. */
function govfee_suggest(array $m, array $ev, ?array $cycle = null): ?array {
    $cycle = $cycle ?? govfee_cycle($ev); if (!$cycle) return null;
    $deadline = (string)($cycle['events']['due']['due_date'] ?? $cycle['due']);
    $graceEnd = (string)($cycle['events']['grace']['due_date'] ?? '');
    $late = today() > $deadline; $lapsed = $late && $graceEnd !== '' && today() > $graceEnd;
    $fam = (string)$cycle['family'];
    $usd = fn(int $n) => '$' . number_format($n);
    if ($m['kind'] === 'patent') {
        if (!preg_match('/^(|United States|US|USA)$/i', trim((string)($m['country'] ?? '')))) return null;
        $yr = preg_match('/^MF(3|7|11)$/', $fam, $x) ? $x[1] : null;
        if ($yr === null && !empty($m['grant_date'])) { $y = (strtotime($deadline) - strtotime((string)$m['grant_date'])) / (365.25 * 86400); $yr = $y < 5.5 ? '3' : ($y < 9.5 ? '7' : '11'); }
        if ($yr === null) return null;
        $ent = govfee_entity($m); $names = ['undiscounted', 'small entity', 'micro entity'];
        $lab = ['3' => '3.5', '7' => '7.5', '11' => '11.5'][$yr] . '-year fee';
        // 2026-10-13: "estimated USPTO fee: $808; check here", the link opening the USPTO payment page pre-filled.
        $chk = ($u = uspto_mf_url($m)) !== '' ? '; <a href="' . htmlspecialchars($u, ENT_QUOTES) . '" target="_blank" rel="noopener">check here</a>' : '';
        $gone = $lapsed ? ' · <span class="bad">grace period ended ' . fmt_date($graceEnd) . '</span>' : '';
        if ($ent === null) return ['total' => null, 'patent' => true, 'html' => 'estimated USPTO fee: ' . implode(' / ', array_map(fn($i) => $usd(GOVFEE_MF[$yr][$i] + ($late ? GOVFEE_MF_LATE[$i] : 0)) . ' ' . $names[$i], [0, 1, 2])) . $chk . $gone];
        $fee = GOVFEE_MF[$yr][$ent]; $sur = $late ? GOVFEE_MF_LATE[$ent] : 0;
        return ['total' => $fee + $sur, 'patent' => true, 'html' => 'estimated USPTO fee: <b>' . $usd($fee + $sur) . '</b>' . $chk . $gone];
    }
    if ($m['kind'] !== 'trademark') return null;
    // [label, base codes, grace codes]; Section 15 has no grace fee.
    $plan = ['Section 8 Declaration and Section 9 Renewal' => ['§8 + §9', ['7205', '7201'], ['7206', '7203']], 'Renewal Period' => ['§8 + §9', ['7205', '7201'], ['7206', '7203']],
             'Section 8 and 15 Declarations' => ['§8 + §15', ['7205', '7208'], ['7206']], 'Section 8 Declaration' => ['§8', ['7205'], ['7206']],
             'Section 71 Declaration' => ['§71', ['7905'], ['7906']], 'Section 71 and 15 Declarations' => ['§71 + §15', ['7905', '7208'], ['7906']]][$fam] ?? null;
    if (!$plan || ($fam === 'Renewal Period' && !preg_match('/^(|United States|US|USA)$/i', trim((string)($m['country'] ?? ''))))) return null;
    $per = array_sum(array_map(fn($c) => GOVFEE_TM[$c], $plan[1])); $perLate = $late ? array_sum(array_map(fn($c) => GOVFEE_TM[$c], $plan[2])) : 0;
    $n = count(array_unique(preg_match_all('/\d{1,3}/', (string)($m['classes'] ?? ''), $cm) ? array_map('intval', $cm[0]) : []));
    $tail = ($late ? ' + grace ' . $usd($perLate) : '') . ' per class' . ($lapsed ? ' · <span class="bad">grace period ended ' . fmt_date($graceEnd) . '</span>' : '');
    if ($n === 0) return ['total' => null, 'html' => 'USPTO fee: ' . $plan[0] . ' ' . $usd($per) . $tail . ' · classes not on the record'];
    return ['total' => $n * ($per + $perLate), 'html' => 'USPTO fee: <b>' . $usd($n * ($per + $perLate)) . '</b> · ' . $plan[0] . ' ' . $usd($per) . $tail . ' × ' . $n . ' class' . ($n > 1 ? 'es' : '')];
}

/** Weekly: re-read the USPTO fee schedule and compare every amount above. Returns [] when all match, else the differences; null if the page could not be read. */
function govfee_check(): ?array {
    $ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/128 Safari/537.36';   // curl on pair (allow_url_fopen off), streams on the laptop
    if (function_exists('curl_init')) { $ch = curl_init(GOVFEE_URL); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => $ua]); $raw = curl_exec($ch); curl_close($ch); }
    else $raw = @file_get_contents(GOVFEE_URL, false, stream_context_create(['http' => ['timeout' => 30, 'header' => "User-Agent: $ua\r\n"]]));
    if (!is_string($raw) || strlen($raw) < 20000) return null;
    $t = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $raw)), ENT_QUOTES)); $amt = fn(string $s) => (int)round((float)str_replace(',', '', $s));
    $bad = [];
    foreach (GOVFEE_MF_CODE as $yr => $code) {
        if (!preg_match('#' . preg_quote($code, '#') . ' .{0,200}?([\d,]+\.\d\d) ([\d,]+\.\d\d) ([\d,]+\.\d\d)#', $t, $x)) { $bad[] = "$code not found"; continue; }
        $got = [$amt($x[1]), $amt($x[2]), $amt($x[3])]; if ($got !== GOVFEE_MF[$yr]) $bad[] = "$code now " . implode('/', $got) . ' (Pendant has ' . implode('/', GOVFEE_MF[$yr]) . ')';
    }
    foreach (['1554/2554/3554', '1555/2555/3555', '1556/2556/3556'] as $code) {
        if (!preg_match('#' . preg_quote($code, '#') . ' .{0,200}?([\d,]+\.\d\d) ([\d,]+\.\d\d) ([\d,]+\.\d\d)#', $t, $x)) { $bad[] = "$code not found"; continue; }
        $got = [$amt($x[1]), $amt($x[2]), $amt($x[3])]; if ($got !== GOVFEE_MF_LATE) $bad[] = "$code now " . implode('/', $got) . ' (Pendant has ' . implode('/', GOVFEE_MF_LATE) . ')';
    }
    foreach (GOVFEE_TM as $code => $want) {
        if (!preg_match('#([\d,]+\.\d\d) ' . $code . '(?!,)\b#', $t, $x)) { $bad[] = "$code not found"; continue; }
        if ($amt($x[1]) !== $want) $bad[] = "$code now " . $amt($x[1]) . " (Pendant has $want)";
    }
    return $bad;
}

/** The last weekly check's differences ('' when none / never run), for the send page. */
function govfee_stale_html(): string {
    $c = json_decode((string)setting('govfee_check', ''), true);
    return is_array($c) && !empty($c['bad']) ? ' <span class="bad" title="' . h(implode('; ', $c['bad'])) . '">USPTO schedule changed ' . h((string)$c['date']) . ': check the amount</span>' : '';
}

/** Nightly hook: Mondays, or when the last check is a week old. Returns a report line or null. */
function govfee_weekly(): ?string {
    $c = json_decode((string)setting('govfee_check', ''), true) ?: [];
    if (date('N') !== '1' && ($c['date'] ?? '2000-01-01') >= date('Y-m-d', strtotime('-7 days'))) return null;
    $bad = govfee_check();
    if ($bad === null) return 'USPTO fee schedule could not be read (' . GOVFEE_URL . '); fee suggestions use the amounts from ' . ($c['date'] ?? '2026-10-09') . '.';
    run('INSERT OR REPLACE INTO settings(key,value) VALUES ("govfee_check", ?)', [json_encode(['date' => today(), 'bad' => $bad])]);
    return $bad ? 'USPTO fee schedule changed: ' . implode('; ', $bad) . '. Update lib/govfees.php.' : null;
}
