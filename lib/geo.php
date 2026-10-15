<?php
/**
 * IP origin gate. Copied 2026-10-01 from the Mail app (docket-mail/lib/geo.php) for Madder, which allows whole countries:
 * USA and Israel only (2026-10-01), data/geo-us-il.db, cut from docket-mail's geoallow-countries.db.
 *
 * Deny by default: an address is allowed only if it appears in the always-allow
 * list, or falls inside a range in the prebuilt allowlist database (Israel plus
 * US New York / New Jersey).
 *
 * This is a second layer, not the security boundary — the login is. State-level
 * IP geolocation is approximate: mobile carriers frequently present addresses
 * registered in a neighbouring state, and any VPN will land wherever it exits.
 * Expect occasional false denials and use geo_always_allow for known addresses.
 *
 * Allowlist data: DB-IP City Lite (https://db-ip.com), CC BY 4.0.
 */

/**
 * @return array{allow: bool, tag: ?string, reason: string}
 */
function geo_check($ip, array $cfg)
{
    if (empty($cfg['geo_enabled'])) {
        return ['allow' => true, 'tag' => null, 'reason' => 'geo_disabled'];
    }

    if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return ['allow' => false, 'tag' => null, 'reason' => 'no_client_ip'];
    }

    // Checked before the database so a missing or corrupt allowlist can never
    // lock out a known-good address. The region lookup still runs below, purely
    // so the audit log records where the request came from rather than the
    // uninformative "always_allow".
    $forced = false;
    foreach (($cfg['geo_always_allow'] ?? []) as $entry) {
        if (geo_ip_in_entry($ip, $entry)) {
            $forced = true;
            break;
        }
    }

    $path = $cfg['geo_db'] ?? '';
    if ($path === '' || !is_readable($path)) {
        // Fail closed — unless this address is pinned, which is the way back in.
        return $forced
            ? ['allow' => true,  'tag' => 'always_allow', 'reason' => 'always_allow']
            : ['allow' => false, 'tag' => null, 'reason' => 'geo_db_missing'];
    }

    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        return ['allow' => false, 'tag' => null, 'reason' => 'geo_db_error'];
    }

    $packed = @inet_pton($ip);
    if ($packed === false) {
        return $forced
            ? ['allow' => true,  'tag' => 'always_allow', 'reason' => 'always_allow']
            : ['allow' => false, 'tag' => null, 'reason' => 'bad_ip'];
    }

    if (strlen($packed) === 4) {
        $n  = sprintf('%u', ip2long($ip));
        $st = $pdo->prepare('SELECT end, tag FROM v4 WHERE start <= :n ORDER BY start DESC LIMIT 1');
        $st->execute([':n' => $n]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && (float) $row['end'] >= (float) $n) {
            return ['allow' => true, 'tag' => $row['tag'], 'reason' => 'in_range'];
        }
    } else {
        // SQLite compares BLOBs bytewise, and every value here is a fixed
        // 16 bytes, so ordinary range comparison is correct.
        $st = $pdo->prepare('SELECT end, tag FROM v6 WHERE start <= :p ORDER BY start DESC LIMIT 1');
        $st->bindValue(':p', $packed, PDO::PARAM_LOB);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && strcmp($row['end'], $packed) >= 0) {
            return ['allow' => true, 'tag' => $row['tag'], 'reason' => 'in_range'];
        }
    }

    // Not in an allowed region. Pinned addresses still get in, tagged as such.
    return $forced
        ? ['allow' => true,  'tag' => 'always_allow', 'reason' => 'always_allow']
        : ['allow' => false, 'tag' => null, 'reason' => 'out_of_area'];
}

/** Single address or CIDR block. */
function geo_ip_in_entry($ip, $entry)
{
    $entry = trim((string) $entry);
    if ($entry === '') {
        return false;
    }
    if (strpos($entry, '/') === false) {
        return $ip === $entry;
    }

    [$net, $bits] = explode('/', $entry, 2);
    $ipp  = @inet_pton($ip);
    $netp = @inet_pton($net);
    if ($ipp === false || $netp === false || strlen($ipp) !== strlen($netp)) {
        return false;
    }

    $bits = (int) $bits;
    if ($bits < 0 || $bits > strlen($ipp) * 8) {
        return false;
    }

    $whole = intdiv($bits, 8);
    $rem   = $bits % 8;
    if ($whole > 0 && strncmp($ipp, $netp, $whole) !== 0) {
        return false;
    }
    if ($rem === 0) {
        return true;
    }
    $mask = chr((0xFF << (8 - $rem)) & 0xFF);
    return (($ipp[$whole] & $mask) === ($netp[$whole] & $mask));
}
