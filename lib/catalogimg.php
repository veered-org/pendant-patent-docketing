<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * The picture the firm's own web catalogs already hold for a matter (2026-10-14): a granted patent's figure
 * (catalogs/patent/patents.db figure_file) or a logo trademark's drawing (catalogs/trademark/trademarks.db image_file,
 * drawing code 2/3/5 only; word marks get none). Pendant sits on the same server, so the files are read in place, nothing is
 * downloaded. Shown on the matter page and in the client report. Config `catalog_dir` overrides the location.
 */
function catalog_dir(): string {
    static $d = null; if ($d !== null) return $d;
    $c = $GLOBALS['cfg']['catalog_dir'] ?? (defined('DOCKET_ROOT') ? dirname(DOCKET_ROOT, 2) . '/catalogs' : '');
    return $d = ($c !== '' && is_dir($c)) ? rtrim($c, '/') : '';
}
function catalog_db(string $which): ?PDO {
    static $dbs = [];
    if (array_key_exists($which, $dbs)) return $dbs[$which];
    $f = catalog_dir() . ($which === 'patent' ? '/patent/patents.db' : '/trademark/trademarks.db');
    try { $dbs[$which] = catalog_dir() !== '' && is_file($f) ? new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]) : null; }
    catch (Throwable $e) { $dbs[$which] = null; }
    return $dbs[$which];
}
/** The firm's own copy of a logo mark's drawing by serial number, or null (2026-10-14: TSDR's /img/ answers 429, so hotlinks show blank). */
function catalog_logo_url(string $sn): ?string {
    static $memo = []; $sn = preg_replace('/\D/', '', $sn); if (strlen($sn) !== 8) return null;
    if (array_key_exists($sn, $memo)) return $memo[$sn];
    $c = catalog_image(['kind' => 'trademark', 'country' => 'United States', 'app_no' => $sn]);
    return $memo[$sn] = $c ? $c['url'] : null;
}
/** ['file' => absolute path, 'url' => public image URL, 'page' => public catalog page URL or '', 'kind' => 'figure'|'logo'] or null. */
function catalog_image(array $m): ?array {
    $kind = (string)($m['kind'] ?? ''); if (!in_array($kind, ['patent', 'trademark'], true)) return null;
    if (!preg_match('/^(|United States|US|USA)$/i', trim((string)($m['country'] ?? '')))) return null;
    $db = catalog_db($kind); if (!$db) return null;
    try {
        if ($kind === 'patent') {
            $pn = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string)($m['reg_no'] ?? ''))); if ($pn === '') return null;
            $st = $db->prepare('SELECT figure_file f, page_file p FROM patents WHERE patent_number=? LIMIT 1'); $st->execute([$pn]);
        } else {
            $sn = preg_replace('/\D/', '', (string)($m['app_no'] ?? '')); if (strlen($sn) !== 8) return null;
            $st = $db->prepare("SELECT image_file f, page_file p FROM trademarks WHERE serial_number=? AND substr(drawing_code,1,1) IN ('2','3','5') LIMIT 1"); $st->execute([$sn]);
        }
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return null; }
    $rel = ltrim((string)($r['f'] ?? ''), '/'); if ($rel === '' || str_contains($rel, '..')) return null;
    $file = catalog_dir() . "/$kind/$rel"; if (!is_file($file) || filesize($file) < 100) return null;
    if (!firm('catalogs_url')) return null;
    $base = rtrim(firm('catalogs_url'), '/') . '/' . $kind . '/'; $page = ltrim((string)($r['p'] ?? ''), '/');
    return ['file' => $file, 'url' => $base . $rel, 'page' => $page !== '' && !str_contains($page, '..') && is_file(catalog_dir() . "/$kind/$page") ? $base . $page : '', 'kind' => $kind === 'patent' ? 'figure' : 'logo'];
}
