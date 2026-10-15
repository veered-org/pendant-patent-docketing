<?php
/**
 * Minimal read-only Gmail REST client.
 *
 * cURL and json only — deliberately no google-api-php-client. The host is
 * FreeBSD with a broken Pillow/libtiff stack (see the e-signature page notes),
 * so every dependency added here is a future breakage.
 */

class GmailError extends RuntimeException {}

function gm_access_token(array $cfg)
{
    $cache = $cfg['token_cache'];
    if (is_readable($cache)) {
        $t = json_decode((string) file_get_contents($cache), true);
        if (!empty($t['access_token']) && !empty($t['expires_at']) && $t['expires_at'] > time() + 60) {
            return $t['access_token'];
        }
    }

    $res = gm_http('https://oauth2.googleapis.com/token', null, [
        'client_id'     => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'refresh_token' => $cfg['refresh_token'],
        'grant_type'    => 'refresh_token',
    ]);

    if (empty($res['access_token'])) {
        throw new GmailError('token refresh failed: ' . substr(json_encode($res), 0, 300));
    }

    $dir = dirname($cache);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    file_put_contents($cache, json_encode([
        'access_token' => $res['access_token'],
        'expires_at'   => time() + (int) ($res['expires_in'] ?? 3600),
    ]), LOCK_EX);
    @chmod($cache, 0600);

    return $res['access_token'];
}

/** Thread ids matching a query, newest first. */
function gm_search_threads($query, array $cfg)
{
    $res = gm_api('/users/me/threads', [
        'q'          => $query,
        'maxResults' => (int) $cfg['max_threads'],
    ], $cfg);

    $ids = [];
    foreach (($res['threads'] ?? []) as $t) {
        $ids[] = $t['id'];
    }
    return $ids;
}

/** Headers only — enough to render the result list without touching bodies. */
function gm_thread_summary($threadId, array $cfg)
{
    $res = gm_api('/users/me/threads/' . rawurlencode($threadId), [
        'format'         => 'metadata',
        'metadataHeaders' => ['Subject', 'From', 'To', 'Date'],
    ], $cfg);

    $msgs = $res['messages'] ?? [];
    if (!$msgs) {
        return null;
    }
    $first = gm_headers($msgs[0]);
    $last  = gm_headers($msgs[count($msgs) - 1]);

    return [
        'id'       => $threadId,
        'subject'  => $first['subject'] ?? '(no subject)',
        'from'     => $first['from'] ?? '',
        'count'    => count($msgs),
        'first_ts' => isset($msgs[0]['internalDate']) ? (int) ($msgs[0]['internalDate'] / 1000) : 0,
        'last_ts'  => isset($msgs[count($msgs) - 1]['internalDate'])
                        ? (int) ($msgs[count($msgs) - 1]['internalDate'] / 1000) : 0,
        'last_from' => $last['from'] ?? '',
    ];
}

/** Full thread with decoded bodies. */
function gm_thread_full($threadId, array $cfg)
{
    $res = gm_api('/users/me/threads/' . rawurlencode($threadId), ['format' => 'full'], $cfg);

    $out = [];
    foreach (($res['messages'] ?? []) as $m) {
        $h = gm_headers($m);
        $out[] = [
            'id'          => $m['id'],
            'subject'     => $h['subject'] ?? '(no subject)',
            'from'        => $h['from'] ?? '',
            'to'          => $h['to'] ?? '',
            'cc'          => $h['cc'] ?? '',
            'ts'          => isset($m['internalDate']) ? (int) ($m['internalDate'] / 1000) : 0,
            'body'        => gm_extract_body($m['payload'] ?? []),
            'attachments' => gm_extract_attachments($m['payload'] ?? []),
        ];
    }
    return $out;
}

function gm_headers(array $msg)
{
    $out = [];
    foreach (($msg['payload']['headers'] ?? []) as $h) {
        $out[strtolower($h['name'])] = $h['value'];
    }
    return $out;
}

/**
 * Prefer text/plain. HTML is only ever reached as a fallback and is stripped
 * rather than sanitised — no third-party HTML purifier on this host, and a
 * client email is not worth an XSS surface.
 */
function gm_extract_body(array $payload)
{
    $plain = gm_find_part($payload, 'text/plain');
    if ($plain !== null) {
        return ['text' => $plain, 'from_html' => false];
    }
    $html = gm_find_part($payload, 'text/html');
    if ($html !== null) {
        $txt = preg_replace('#<br\s*/?>#i', "\n", $html);
        $txt = preg_replace('#</p>#i', "\n\n", $txt);
        return ['text' => html_entity_decode(strip_tags($txt), ENT_QUOTES, 'UTF-8'), 'from_html' => true];
    }
    return ['text' => '(no readable body)', 'from_html' => false];
}

function gm_find_part(array $part, $mime)
{
    if (($part['mimeType'] ?? '') === $mime && !empty($part['body']['data'])) {
        return gm_b64url($part['body']['data']);
    }
    foreach (($part['parts'] ?? []) as $sub) {
        $found = gm_find_part($sub, $mime);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
}

function gm_extract_attachments(array $part, array &$acc = [])
{
    if (!empty($part['filename'])) {
        $acc[] = [
            'filename' => $part['filename'],
            'size'     => (int) ($part['body']['size'] ?? 0),
            'id'       => $part['body']['attachmentId'] ?? null,
        ];
    }
    foreach (($part['parts'] ?? []) as $sub) {
        gm_extract_attachments($sub, $acc);
    }
    return $acc;
}

function gm_b64url($data)
{
    return (string) base64_decode(strtr($data, '-_', '+/'));
}

function gm_api($path, array $params, array $cfg)
{
    $qs = [];
    foreach ($params as $k => $v) {
        if (is_array($v)) {
            foreach ($v as $item) {
                $qs[] = rawurlencode($k) . '=' . rawurlencode($item);
            }
        } else {
            $qs[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
    }
    $url = 'https://gmail.googleapis.com/gmail/v1' . $path . ($qs ? '?' . implode('&', $qs) : '');
    return gm_http($url, gm_access_token($cfg), null);
}

/**
 * Prefers ext-curl, falls back to a stream context. The pair host has curl and
 * allow_url_fopen off; a laptop may well have the reverse, and this file gets
 * run in both places.
 */
function gm_http($url, $bearer, $postFields)
{
    $headers = ['Accept: application/json'];
    if ($bearer !== null) {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }

    if (function_exists('curl_init')) {
        [$raw, $code, $err] = gm_http_curl($url, $headers, $postFields);
        if ($raw === false) {
            throw new GmailError('curl: ' . $err);
        }
    } elseif (ini_get('allow_url_fopen')) {
        [$raw, $code] = gm_http_stream($url, $headers, $postFields);
        if ($raw === false) {
            throw new GmailError('request failed (stream): ' . $url);
        }
    } else {
        throw new GmailError('no HTTP transport: enable ext-curl or allow_url_fopen');
    }

    $json = json_decode((string) $raw, true);
    if ($code >= 400) {
        $msg = $json['error']['message'] ?? $json['error_description'] ?? substr((string) $raw, 0, 200);
        // Per-minute quota ("Units per minute per user") trips on long
        // command-line backfills. Wait it out there; a web request must not hang.
        static $waits = 0;
        if (PHP_SAPI === 'cli' && $waits < 4 && ($code === 429 || ($code === 403 && stripos($msg, 'quota') !== false))) {
            $waits++;
            sleep(20 * $waits);
            return gm_http($url, $bearer, $postFields);
        }
        throw new GmailError('Gmail API ' . $code . ': ' . $msg);
    }
    $waits = 0;
    return is_array($json) ? $json : [];
}

function gm_http_curl($url, array $headers, $postFields)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$raw, $code, $err];
}

function gm_http_stream($url, array $headers, $postFields)
{
    $opts = [
        'http' => [
            'method'        => $postFields === null ? 'GET' : 'POST',
            'header'        => implode("\r\n", $headers),
            'timeout'       => 25,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ];
    if ($postFields !== null) {
        $opts['http']['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
        $opts['http']['content'] = http_build_query($postFields);
    }

    $raw  = @file_get_contents($url, false, stream_context_create($opts));
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $code = (int) $m[1];
        }
    }
    return [$raw, $code];
}
