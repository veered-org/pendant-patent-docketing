<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * Dropbox from the server (2026-10-01: "move it all to the server starting with dropbox connection").
 * The pair host cannot run the Dropbox client, so Pendant talks to the Dropbox HTTP API with its own scoped app
 * (files.metadata.read, files.content.read, files.content.write). Authorisation is PKCE with an offline refresh token
 * (no app secret is stored): Advanced > Dropbox in Pendant. Credentials live in data/dropbox.json (600, deny-all).
 *
 * The matter folders are indexed in table dbx_files (one row per file, up to two levels below a folder), kept current with
 * list_folder/continue cursors, so every job reads names and dates from the database and downloads only the files it opens.
 * Folders named in firm('dropbox_forbidden') (e.g. a partner's private folder) are refused here, whatever the caller asks for.
 */
define('DBX_ROOTS', array_values(firm('dropbox_roots')));   // indexed roots (firm.php); the matter folders are <write root>/<REF>
define('DBX_WRITE_ROOT', (string)(firm('dropbox_write_root') ?: DBX_ROOTS[0]));

function dbx_forbidden(string $path): bool { foreach (firm('dropbox_forbidden') as $x) if (preg_match('#^/*' . preg_quote((string)$x, '#') . '(/|$)#i', trim($path))) return true; return false; }
function dbx_file(): string { return dirname($GLOBALS['cfg']['db']) . '/dropbox.json'; }
function dbx_conf(): array { $j = json_decode((string)@file_get_contents(dbx_file()), true); return is_array($j) ? $j : []; }
function dbx_save_conf(array $c): void { $f = dbx_file(); file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT)); @chmod($f, 0600); }
function dbx_ready(): bool { $c = dbx_conf(); return !empty($c['app_key']) && !empty($c['refresh_token']); }

/** PKCE step 1: the URL the attorney opens to allow the app (Dropbox then shows a code to paste back). */
function dbx_auth_url(string $appKey): string {
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $c = dbx_conf(); $c['app_key'] = trim($appKey); $c['pkce_verifier'] = $verifier; dbx_save_conf($c);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return 'https://www.dropbox.com/oauth2/authorize?' . http_build_query(['client_id' => trim($appKey), 'response_type' => 'code', 'token_access_type' => 'offline', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256']);
}
/** PKCE step 2: exchange the pasted code for a refresh token; records the account and its root namespace. */
function dbx_auth_finish(string $code): array {
    $c = dbx_conf(); if (empty($c['app_key']) || empty($c['pkce_verifier'])) return ['ok' => false, 'error' => 'Start again: enter the app key and open the Dropbox link first.'];
    $r = dbx_http('POST', 'https://api.dropboxapi.com/oauth2/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['code' => trim($code), 'grant_type' => 'authorization_code', 'client_id' => $c['app_key'], 'code_verifier' => $c['pkce_verifier']]));
    $j = json_decode($r['body'], true);
    if ($r['code'] !== 200 || empty($j['refresh_token'])) return ['ok' => false, 'error' => 'Dropbox refused the code (HTTP ' . $r['code'] . '): ' . trim((string)($j['error_description'] ?? $r['body']))];
    $c['refresh_token'] = $j['refresh_token']; $c['access_token'] = $j['access_token']; $c['expires_at'] = time() + (int)($j['expires_in'] ?? 14400) - 300; unset($c['pkce_verifier']);
    dbx_save_conf($c);
    $acct = dbx_rpc('users/get_current_account', null);
    if (!$acct['ok']) return ['ok' => false, 'error' => 'Connected, but the account lookup failed: ' . $acct['error']];
    $ri = $acct['json']['root_info'] ?? [];
    $c = dbx_conf(); $c['account'] = (string)($acct['json']['email'] ?? ''); $c['account_name'] = (string)($acct['json']['name']['display_name'] ?? '');
    // A team account whose ~/Dropbox shows the team space: paths are relative to the ROOT namespace, not the member's home.
    $c['root_ns'] = (($ri['root_namespace_id'] ?? '') !== '' && ($ri['root_namespace_id'] ?? '') !== ($ri['home_namespace_id'] ?? '')) ? (string)$ri['root_namespace_id'] : '';
    $c['connected_at'] = date('Y-m-d H:i:s'); dbx_save_conf($c);
    return ['ok' => true, 'account' => $c['account']];
}
function dbx_disconnect(): void { $c = dbx_conf(); foreach (['refresh_token', 'access_token', 'expires_at', 'account', 'account_name', 'root_ns', 'connected_at'] as $k) unset($c[$k]); dbx_save_conf($c); }

function dbx_http(string $method, string $url, array $headers, ?string $body, int $timeout = 120): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_HEADER => true]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); $err = curl_error($ch); curl_close($ch);
    if ($raw === false) return ['code' => 0, 'body' => '', 'headers' => '', 'error' => $err];
    return ['code' => $code, 'headers' => substr((string)$raw, 0, $hs), 'body' => substr((string)$raw, $hs), 'error' => ''];
}
function dbx_token(): ?string {
    $c = dbx_conf(); if (empty($c['refresh_token'])) return null;
    if (!empty($c['access_token']) && (int)($c['expires_at'] ?? 0) > time()) return $c['access_token'];
    $r = dbx_http('POST', 'https://api.dropboxapi.com/oauth2/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'refresh_token', 'refresh_token' => $c['refresh_token'], 'client_id' => $c['app_key']]));
    $j = json_decode($r['body'], true); if ($r['code'] !== 200 || empty($j['access_token'])) return null;
    $c['access_token'] = $j['access_token']; $c['expires_at'] = time() + (int)($j['expires_in'] ?? 14400) - 300; dbx_save_conf($c);
    return $c['access_token'];
}
function dbx_headers(array $extra = []): array {
    $t = dbx_token(); if (!$t) throw new RuntimeException('Dropbox is not connected (Advanced > Dropbox).');
    $h = array_merge(['Authorization: Bearer ' . $t], $extra);
    $ns = dbx_conf()['root_ns'] ?? ''; if ($ns !== '') $h[] = 'Dropbox-API-Path-Root: ' . json_encode(['.tag' => 'root', 'root' => $ns]);
    return $h;
}
/** RPC endpoint. One retry on 429 / 5xx after the server's Retry-After. */
function dbx_rpc(string $endpoint, ?array $args): array {
    for ($i = 0; $i < 3; $i++) {
        try { $h = dbx_headers($args === null ? [] : ['Content-Type: application/json']); } catch (Throwable $e) { return ['ok' => false, 'code' => 0, 'error' => $e->getMessage(), 'json' => null]; }
        $r = dbx_http('POST', "https://api.dropboxapi.com/2/$endpoint", $h, $args === null ? null : json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($r['code'] === 429 || $r['code'] >= 500) { sleep(preg_match('/Retry-After:\s*(\d+)/i', $r['headers'], $m) ? min(60, (int)$m[1]) : 5); continue; }
        $j = json_decode($r['body'], true);
        return ['ok' => $r['code'] === 200, 'code' => $r['code'], 'json' => $j, 'error' => $r['code'] === 200 ? '' : (string)($j['error_summary'] ?? $r['error'] ?: $r['body'])];
    }
    return ['ok' => false, 'code' => 429, 'json' => null, 'error' => 'Dropbox kept rate-limiting'];
}
/** Content download: bytes, or null. */
function dbx_download(string $path): ?string {
    if (dbx_forbidden($path)) throw new RuntimeException('refused: that folder is off limits');
    for ($i = 0; $i < 3; $i++) {
        $r = dbx_http('POST', 'https://content.dropboxapi.com/2/files/download', dbx_headers(['Dropbox-API-Arg: ' . dbx_arg(['path' => $path])]), '', 300);
        if ($r['code'] === 429 || $r['code'] >= 500) { sleep(5); continue; }
        return $r['code'] === 200 ? $r['body'] : null;
    }
    return null;
}
/** Upload (never overwrites: an existing name gets Dropbox's " (1)" rename). Returns the stored path, or throws. */
function dbx_upload(string $path, string $bytes, bool $overwrite = false): string {
    if (dbx_forbidden($path) || stripos($path, DBX_WRITE_ROOT . '/') !== 0) throw new RuntimeException("refused: uploads go under " . DBX_WRITE_ROOT . " only ($path)");
    for ($i = 0; $i < 3; $i++) {
        $r = dbx_http('POST', 'https://content.dropboxapi.com/2/files/upload', dbx_headers(['Content-Type: application/octet-stream', 'Dropbox-API-Arg: ' . dbx_arg(['path' => $path, 'mode' => $overwrite ? 'overwrite' : 'add', 'autorename' => !$overwrite, 'mute' => true])]), $bytes, 600);
        if ($r['code'] === 429 || $r['code'] >= 500) { sleep(5); continue; }
        $j = json_decode($r['body'], true);
        if ($r['code'] !== 200) throw new RuntimeException('Dropbox upload failed (HTTP ' . $r['code'] . '): ' . ($j['error_summary'] ?? $r['body']));
        dbx_index_entry($j + ['.tag' => 'file']);   // visible to the next job at once, before the next sync
        return (string)$j['path_display'];
    }
    throw new RuntimeException('Dropbox upload kept failing');
}
/** Dropbox-API-Arg must be ASCII: non-ASCII characters escaped as \uXXXX. */
function dbx_arg(array $a): string { return json_encode($a, JSON_UNESCAPED_SLASHES); }

// ---------------------------------------------------------------- index
function dbx_schema(): void {
    static $done = false; if ($done) return; $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS dbx_files (path_lower TEXT PRIMARY KEY, path TEXT NOT NULL, root TEXT NOT NULL, folder TEXT NOT NULL, rel TEXT NOT NULL, name TEXT NOT NULL,
        size INTEGER, client_modified TEXT, server_modified TEXT, rev TEXT, content_hash TEXT, seen_at TEXT DEFAULT (datetime('now')))");
    db()->exec("CREATE INDEX IF NOT EXISTS dbx_files_folder ON dbx_files(root, folder)");
    db()->exec("CREATE TABLE IF NOT EXISTS dbx_folders (root TEXT NOT NULL, name TEXT NOT NULL, path TEXT NOT NULL, PRIMARY KEY (root, name))");
}
/** Split a path into [root, folder, rel] for an indexed root, or null (outside the roots, too deep, or private). */
function dbx_split(string $path): ?array {
    if (dbx_forbidden($path)) return null;
    foreach (DBX_ROOTS as $root) {
        if (stripos($path, $root . '/') !== 0) continue;
        $rest = substr($path, strlen($root) + 1); $parts = explode('/', $rest);
        if (count($parts) < 1 || $parts[0] === '' || count($parts) > 3) return null;   // root/folder[/sub[/file]] at most two levels below the folder
        return [$root, $parts[0], implode('/', array_slice($parts, 1))];
    }
    return null;
}
function dbx_index_entry(array $e): void {
    dbx_schema(); $tag = $e['.tag'] ?? ''; $path = (string)($e['path_display'] ?? ''); $lower = strtolower((string)($e['path_lower'] ?? $path));
    if ($tag === 'deleted') { run('DELETE FROM dbx_files WHERE path_lower=? OR path_lower LIKE ?', [$lower, str_replace(['%', '_'], ['\%', '\_'], $lower) . '/%']); $s = dbx_split($path); if ($s && $s[2] === '') run('DELETE FROM dbx_folders WHERE root=? AND lower(name)=?', [$s[0], strtolower($s[1])]); return; }
    $s = dbx_split($path); if (!$s) return; [$root, $folder, $rel] = $s;
    if ($tag === 'folder') { if ($rel === '') run('INSERT OR REPLACE INTO dbx_folders(root,name,path) VALUES (?,?,?)', [$root, $folder, $path]); return; }
    if ($tag !== 'file' || $rel === '') return;
    // 2026-10-06 (TOD018): a file Pendant writes into a folder it just created (new filing) registers the folder too; until the
    // next sync, mf_find saw no folder, so the send page had no receipt to attach.
    if (!q1('SELECT 1 FROM dbx_folders WHERE root=? AND lower(name)=lower(?)', [$root, $folder])) run('INSERT OR IGNORE INTO dbx_folders(root,name,path) VALUES (?,?,?)', [$root, $folder, $root . '/' . $folder]);
    run('INSERT OR REPLACE INTO dbx_files(path_lower,path,root,folder,rel,name,size,client_modified,server_modified,rev,content_hash,seen_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,COALESCE((SELECT seen_at FROM dbx_files WHERE path_lower=?), datetime("now")))',
        [$lower, $path, $root, $folder, $rel, basename($path), (int)($e['size'] ?? 0), dbx_ts($e['client_modified'] ?? ''), dbx_ts($e['server_modified'] ?? ''), (string)($e['rev'] ?? ''), (string)($e['content_hash'] ?? ''), $lower]);
}
function dbx_ts(string $iso): string { return $iso === '' ? '' : gmdate('Y-m-d H:i:s', (int)strtotime($iso)); }   // UTC
/** Bring the index up to date. The first run lists everything (paged; resumes from the saved cursor if the time budget runs out). */
function dbx_sync(int $budgetSeconds = 600): array {
    dbx_schema(); $t0 = time(); $out = ['changes' => 0, 'complete' => true, 'error' => ''];
    foreach (DBX_ROOTS as $root) {
        $key = 'dbx_cursor:' . $root; $cur = q1('SELECT value FROM settings WHERE key=?', [$key])['value'] ?? '';
        do {
            $r = $cur === '' ? dbx_rpc('files/list_folder', ['path' => $root, 'recursive' => true, 'include_deleted' => false, 'limit' => 2000])
                             : dbx_rpc('files/list_folder/continue', ['cursor' => $cur]);
            if (!$r['ok']) {
                if (str_contains($r['error'], 'reset') || str_contains($r['error'], 'not_found')) { run('DELETE FROM settings WHERE key=?', [$key]); if (str_contains($r['error'], 'reset')) { run('DELETE FROM dbx_files WHERE root=?', [$root]); $cur = ''; continue; } }
                $out['error'] .= "$root: {$r['error']}; "; break;
            }
            db()->beginTransaction(); foreach ($r['json']['entries'] ?? [] as $e) { dbx_index_entry($e); $out['changes']++; } db()->commit();
            $cur = (string)$r['json']['cursor']; run('INSERT OR REPLACE INTO settings(key,value) VALUES (?,?)', [$key, $cur]);
            if (!empty($r['json']['has_more']) && time() - $t0 > $budgetSeconds) { $out['complete'] = false; break; }
        } while (!empty($r['json']['has_more']));
    }
    run('INSERT OR REPLACE INTO settings(key,value) VALUES ("dbx_sync_last", ?)', [date('Y-m-d H:i:s') . ($out['complete'] ? '' : ' (partial)')]);
    return $out;
}
