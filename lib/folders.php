<?php
declare(strict_types=1);
/**
 * Matter folders, wherever they are (2026-10-01: everything that read ~/Dropbox/matters on the laptop now runs on the server).
 *   dbx   - the server: names and dates from the Dropbox index (lib/dropbox.php), files downloaded on demand into data/dbx_cache/,
 *           new files uploaded to Dropbox (the laptop's Dropbox client then syncs them down).
 *   local - a real directory: the laptop, or a scratch folder in tests ($GLOBALS['cfg']['folders_local'] or cfg oa_dropbox).
 * A folder handle is ['mode', 'root', 'name', 'dir'] (dir: the local directory, or the Dropbox path).
 * Also here: PDF text / OCR with whatever the machine has, and an HTTP GET that works with or without curl.
 */
const FOLDER_CACHE_DAYS = 14;

function folders_mode(): string {
    $local = folders_local_root();
    if ($local !== '' && is_dir($local)) return 'local';
    if (function_exists('dbx_ready') && dbx_ready()) return 'dbx';
    return 'none';
}
function folders_local_root(): string { return rtrim((string)($GLOBALS['cfg']['folders_local'] ?? ($GLOBALS['cfg']['oa_dropbox'] ?? '')), '/'); }

/** The matter folder for a file number: exactly REF, or "REF (anything)"; a sub-docket like SBS003.1 falls back to SBS003. null when none.
 *  2026-10-04 (RWG127.003.003 whose folder is RWG127.003.03): when nothing matches exactly, the dotted segments are compared as
 *  numbers, so ".003" finds ".03" and the other way round (mf_fuzzy). */
function mf_find(string $ref): ?array {
    $mode = folders_mode(); if ($ref === '' || $mode === 'none') return null;
    $cands = array_values(array_filter(array_unique([$ref, preg_replace('/\.\d+$/', '', $ref)]), 'strlen'));
    foreach ($cands as $r) {
        if ($mode === 'local') { $d = find_matter_dir(folders_local_root(), $r); if ($d !== '') return ['mode' => 'local', 'root' => folders_local_root(), 'name' => basename($d), 'dir' => $d]; continue; }
        dbx_schema();
        $row = q1('SELECT name, path FROM dbx_folders WHERE root=? AND lower(name)=lower(?)', [DBX_WRITE_ROOT, $r])
            ?: q1('SELECT name, path FROM dbx_folders WHERE root=? AND lower(name) LIKE lower(?) ESCAPE "\\" ORDER BY name LIMIT 1', [DBX_WRITE_ROOT, str_replace(['%', '_'], ['\\%', '\\_'], $r) . ' (%']);
        if ($row) return ['mode' => 'dbx', 'root' => DBX_WRITE_ROOT, 'name' => $row['name'], 'dir' => $row['path']];
    }
    $all = mf_all(); if (!$all) return null;
    foreach ($cands as $r) if (($name = mf_fuzzy($all, $r)) !== null) {
        if ($mode === 'local') return ['mode' => 'local', 'root' => folders_local_root(), 'name' => $name, 'dir' => folders_local_root() . '/' . $name];
        $row = q1('SELECT name, path FROM dbx_folders WHERE root=? AND name=?', [DBX_WRITE_ROOT, $name]); if ($row) return ['mode' => 'dbx', 'root' => DBX_WRITE_ROOT, 'name' => $row['name'], 'dir' => $row['path']];
    }
    return null;
}
/** A file number with its dotted numeric segments normalised: "RWG127.003.003" and "RWG127.003.03" both give "RWG127.3.3"; a " (anything)" suffix is dropped. */
function ref_key(string $ref): string {
    $r = strtoupper(trim(preg_replace('/\s+\(.*$/', '', $ref))); if ($r === '' || !str_contains($r, '.')) return $r;
    return implode('.', array_map(fn($s) => ctype_digit($s) ? (string)(int)$s : $s, explode('.', $r)));
}
/** The folder among $names whose ref_key equals $ref's, exact-length spelling preferred; null when none (or when the reference has no dotted segments). */
function mf_fuzzy(array $names, string $ref): ?string {
    $k = ref_key($ref); if ($k === '' || !str_contains($k, '.')) return null;
    $hits = array_values(array_filter($names, fn($n) => ref_key((string)$n) === $k)); if (!$hits) return null;
    usort($hits, fn($a, $b) => [abs(strlen($a) - strlen($ref)), $a] <=> [abs(strlen($b) - strlen($ref)), $b]);
    return (string)$hits[0];
}
/** A folder handle for REF that may not exist yet (oa_fetch saving into a new folder). */
function mf_new(string $ref): array {
    return folders_mode() === 'local' ? ['mode' => 'local', 'root' => folders_local_root(), 'name' => $ref, 'dir' => folders_local_root() . '/' . $ref]
                                      : ['mode' => 'dbx', 'root' => DBX_WRITE_ROOT, 'name' => $ref, 'dir' => DBX_WRITE_ROOT . '/' . $ref];
}
/** Every top-level folder name under the root (pct_deadlines looks for REF.PCT). */
function mf_all(): array {
    $mode = folders_mode();
    if ($mode === 'local') return array_values(array_map('basename', array_filter(glob(folders_local_root() . '/*') ?: [], 'is_dir')));
    if ($mode === 'dbx') { dbx_schema(); return array_column(q('SELECT name FROM dbx_folders WHERE root=? ORDER BY name', [DBX_WRITE_ROOT]), 'name'); }
    return [];
}
/** Files in a folder, newest first: [['name', 'rel', 'mtime' (unix), 'size'], ...]. $deep: also one level of subfolders. */
function mf_list(array $f, bool $deep = false): array {
    $out = [];
    if ($f['mode'] === 'local') {
        if (!is_dir($f['dir'])) return [];
        foreach (scandir($f['dir']) ?: [] as $n) {
            if ($n[0] === '.') continue; $p = $f['dir'] . '/' . $n;
            if (is_file($p)) $out[] = ['name' => $n, 'rel' => $n, 'mtime' => (int)filemtime($p), 'size' => (int)filesize($p)];
            elseif ($deep && is_dir($p)) foreach (scandir($p) ?: [] as $n2) if ($n2[0] !== '.' && is_file("$p/$n2")) $out[] = ['name' => $n2, 'rel' => "$n/$n2", 'mtime' => (int)filemtime("$p/$n2"), 'size' => (int)filesize("$p/$n2")];
        }
    } else {
        dbx_schema();
        foreach (q('SELECT rel, name, size, client_modified, server_modified FROM dbx_files WHERE root=? AND folder=?' . ($deep ? '' : ' AND rel NOT LIKE "%/%"'), [$f['root'], $f['name']]) as $r)
            $out[] = ['name' => $r['name'], 'rel' => $r['rel'], 'mtime' => (int)strtotime(($r['client_modified'] ?: $r['server_modified']) . ' UTC'), 'size' => (int)$r['size']];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}
function mf_names(array $f): array { return array_column(mf_list($f), 'name'); }
/** A local path for a file in the folder (downloaded and cached on the server). '' when it cannot be had. */
function mf_local(array $f, string $rel): string {
    if ($f['mode'] === 'local') { $p = $f['dir'] . '/' . $rel; return is_file($p) ? $p : ''; }
    dbx_schema(); $row = q1('SELECT path, rev, name FROM dbx_files WHERE root=? AND folder=? AND rel=?', [$f['root'], $f['name'], $rel]); if (!$row) return '';
    $dir = dirname($GLOBALS['cfg']['db']) . '/dbx_cache'; if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $ext = strtolower(pathinfo($row['name'], PATHINFO_EXTENSION)); $p = $dir . '/' . sha1($row['path'] . '|' . $row['rev']) . ($ext !== '' ? ".$ext" : '');
    if (is_file($p)) { @touch($p); return $p; }
    $bytes = dbx_download($row['path']); if ($bytes === null) return '';
    file_put_contents($p, $bytes); @chmod($p, 0600); return $p;
}
/** Save a file into the folder (subfolders created). Returns the name it was stored under (Dropbox may add " (1)"). */
function mf_write(array $f, string $rel, string $bytes, bool $overwrite = false): string {   // $overwrite: replace (Dropbox keeps the old version); default adds "(1)"
    if ($f['mode'] === 'local') { $p = $f['dir'] . '/' . $rel; if (!is_dir(dirname($p))) mkdir(dirname($p), 0755, true); if (file_put_contents($p, $bytes) === false) throw new RuntimeException("could not write $p"); return $rel; }
    $stored = dbx_upload($f['dir'] . '/' . $rel, $bytes, $overwrite);
    return substr($stored, strlen($f['dir']) + 1);
}
/** Rename a file inside a matter folder (Dropbox move, or a local rename). Returns the new name. */
function mf_rename(array $f, string $from, string $to): string {
    if ($f['mode'] === 'local') { if (!@rename($f['dir'] . '/' . $from, $f['dir'] . '/' . $to)) throw new RuntimeException("could not rename $from"); return $to; }
    $r = dbx_rpc('files/move_v2', ['from_path' => $f['dir'] . '/' . $from, 'to_path' => $f['dir'] . '/' . $to, 'autorename' => false]);
    if (!$r['ok']) throw new RuntimeException('Dropbox rename failed: ' . $r['error']);
    // keep Pendant's file index in step at once (2026-10-04: the index kept the old names until the hourly sync, so the send page offered files that no longer existed)
    dbx_index_entry(['.tag' => 'deleted', 'path_lower' => strtolower($f['dir'] . '/' . $from), 'path_display' => $f['dir'] . '/' . $from]);
    if (!empty($r['json']['metadata'])) dbx_index_entry($r['json']['metadata'] + ['.tag' => 'file']);
    return $to;
}
/** Drop cached downloads nobody has opened for FOLDER_CACHE_DAYS. */
function mf_cache_prune(): int {
    $n = 0; foreach (glob(dirname($GLOBALS['cfg']['db']) . '/dbx_cache/*') ?: [] as $p) if (is_file($p) && filemtime($p) < time() - FOLDER_CACHE_DAYS * 86400) { @unlink($p); $n++; }
    return $n;
}

// ---------------------------------------------------------------- PDF text and OCR
/** Is the poppler pdftotext on this machine usable? (On the pair host it exists but dies at the dynamic linker.) */
function have_pdftotext(): bool { static $ok = null; if ($ok === null) { exec('pdftotext -v 2>&1', $o, $rc); $ok = $rc === 0 || ($rc === 99 && str_contains(implode(' ', $o), 'pdftotext version')); } return $ok; }
/** Layout text of pages FIRST..LAST (LAST 0 = to the end), like `pdftotext -layout`. */
function pdf_text(string $path, int $first = 1, int $last = 0): string {
    if (have_pdftotext()) return (string)shell_exec('pdftotext -layout -f ' . $first . ($last > 0 ? ' -l ' . $last : '') . ' ' . escapeshellarg($path) . ' - 2>/dev/null');
    return (string)shell_exec('python3 ' . escapeshellarg(dirname(__DIR__) . '/bin/pdftext.py') . ' ' . escapeshellarg($path) . ' ' . $first . ' ' . $last . ' 2>/dev/null');
}
/** OCR of pages FIRST..LAST at 300 dpi (scans). pdftoppm when it works, else mutool. */
function pdf_ocr(string $path, int $first = 1, int $last = 3): string {
    $tmp = sys_get_temp_dir() . '/ocr-' . getmypid() . '-' . bin2hex(random_bytes(4)); @mkdir($tmp, 0700, true); $t = '';
    if (have_pdftotext()) exec('nice -n 15 pdftoppm -f ' . $first . ' -l ' . $last . ' -r 300 -gray ' . escapeshellarg($path) . ' ' . escapeshellarg("$tmp/p") . ' 2>/dev/null');
    // Ghostscript, not mutool: pair's libmupdf cannot decode JBIG2, and the scans in the folders are JBIG2 once shrunk (2026-10-01).
    else exec('nice -n 15 gs -q -dSAFER -dNOPAUSE -dBATCH -sDEVICE=pgmraw -r300 -dFirstPage=' . $first . ' -dLastPage=' . $last . ' -o ' . escapeshellarg("$tmp/p%03d.pgm") . ' ' . escapeshellarg($path) . ' 2>/dev/null');
    $pages = array_merge(glob("$tmp/p*.pgm") ?: [], glob("$tmp/p*.png") ?: []); natsort($pages);
    foreach ($pages as $pg) { $t .= "\n" . (string)shell_exec('nice -n 15 tesseract ' . escapeshellarg($pg) . ' stdout 2>/dev/null'); @unlink($pg); }
    @rmdir($tmp); return $t;
}
/** Text of the first pages; OCR when the PDF carries no text layer. DOCX: the document text. */
function doc_text(string $path, int $pages = 3): string {
    if (preg_match('/\.docx$/i', $path)) { $z = new ZipArchive(); if ($z->open($path) !== true) return ''; $x = (string)$z->getFromName('word/document.xml'); $z->close(); return html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $x))); }
    $t = pdf_text($path, 1, $pages);
    return strlen(trim($t)) > 200 ? $t : $t . "\n" . pdf_ocr($path, 1, $pages);
}

// ---------------------------------------------------------------- HTTP
/** GET: [code, body, content-type]. curl when present (the pair host has allow_url_fopen off), streams otherwise. Retries after 429/5xx/403. */
function http_get(string $url, array $headers = [], int $tries = 2): array {
    $headers = array_merge($headers, ['User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/128 Safari/537.36']); $code = 0; $body = ''; $ct = '';
    for ($i = 0; $i < $tries; $i++) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5]);
            $b = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch); $body = $b === false ? '' : (string)$b;
            if ($b !== false && $code < 429 && $code !== 403) return [$code, $body, $ct];
        } else {
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 180, 'ignore_errors' => true, 'follow_location' => 1, 'max_redirects' => 5]]);
            $b = @file_get_contents($url, false, $ctx); $code = 0; $ct = '';
            foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) $code = (int)$m[1]; elseif (stripos($h, 'Content-Type:') === 0) $ct = trim(substr($h, 13)); }
            $body = $b === false ? '' : (string)$b;
            if ($b !== false && $code < 429 && $code !== 403) return [$code, $body, $ct];
        }
        if ($i + 1 < $tries) sleep($code === 429 ? 25 : 5);
    }
    return [$code, $body, $ct];
}
