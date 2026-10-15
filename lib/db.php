<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';   // who the firm is (name, phones, domains, customer numbers ...)
function search_norm($s): string { return strtolower(preg_replace('/[^0-9A-Za-z]/', '', (string)$s)); }
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = $GLOBALS['cfg'];
        $pdo = new PDO('sqlite:' . $cfg['db']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys=ON'); $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->sqliteCreateFunction('norm', 'search_norm', 1);   // search: lower-case letters and digits only
        // note_add(existing, addition): append with " | ", no leading separator when the note was empty
        // tiny forward migrations (schema.sql only CREATEs): columns added after the first deploy
        $cols = array_column($pdo->query('PRAGMA table_info(outbox)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if ($cols && !in_array('cc_email', $cols, true)) $pdo->exec("ALTER TABLE outbox ADD COLUMN cc_email TEXT DEFAULT ''");
        if ($cols && !in_array('transmitted_at', $cols, true)) { $pdo->exec('ALTER TABLE outbox ADD COLUMN transmitted_at TEXT'); $pdo->exec("ALTER TABLE outbox ADD COLUMN transmit_error TEXT DEFAULT ''"); }
        $ccols = array_column($pdo->query('PRAGMA table_info(clients)')->fetchAll(PDO::FETCH_ASSOC), 'name');   // per-kind recipient lists (2026-09-29, GEO)
        if ($ccols && !in_array('email_tm', $ccols, true)) { $pdo->exec("ALTER TABLE clients ADD COLUMN email_tm TEXT DEFAULT ''"); $pdo->exec("ALTER TABLE clients ADD COLUMN email_patent TEXT DEFAULT ''"); }
        if ($ccols && !in_array('email_ap', $ccols, true)) $pdo->exec("ALTER TABLE clients ADD COLUMN email_ap TEXT DEFAULT ''");   // accounts payable: only on emails carrying an invoice
        if ($cols && !in_array('fwd_html', $cols, true)) $pdo->exec("ALTER TABLE outbox ADD COLUMN fwd_html TEXT DEFAULT ''");   // forwarded USPTO notice below the signature
        if ($cols && !in_array('attachments', $cols, true)) $pdo->exec("ALTER TABLE outbox ADD COLUMN attachments TEXT DEFAULT ''");
        if ($cols && !in_array('in_reply_to', $cols, true)) { $pdo->exec("ALTER TABLE outbox ADD COLUMN in_reply_to TEXT DEFAULT ''"); $pdo->exec("ALTER TABLE outbox ADD COLUMN refs_hdr TEXT DEFAULT ''"); }
        $mc = array_column($pdo->query('PRAGMA table_info(matters)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if ($mc && !in_array('issue_patent_no', $mc, true)) $pdo->exec("ALTER TABLE matters ADD COLUMN issue_patent_no TEXT DEFAULT ''");   // from the Issue Notification (2026-10-02)
        if ($cols && !in_array('card_html', $cols, true)) $pdo->exec("ALTER TABLE outbox ADD COLUMN card_html TEXT DEFAULT ''");   // filing report summary card (2026-10-02)
        if ($cols && !in_array('quote_html', $cols, true)) $pdo->exec("ALTER TABLE outbox ADD COLUMN quote_html TEXT DEFAULT ''");   // the earlier message quoted under a reply
        $ec = array_column($pdo->query('PRAGMA table_info(events)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if ($ec && !in_array('gmail_id', $ec, true)) $pdo->exec("ALTER TABLE events ADD COLUMN gmail_id TEXT DEFAULT ''");   // the USPTO e-mail a "Report ..." forwards (lib/held.php, 2026-10-03)
        $kc = array_column($pdo->query('PRAGMA table_info(correspondence)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if ($kc && !in_array('gmail_id', $kc, true)) $pdo->exec("ALTER TABLE correspondence ADD COLUMN gmail_id TEXT DEFAULT ''");   // oa_fetch saves a fee receipt's attachment from it
        $tc = array_column($pdo->query('PRAGMA table_info(templates)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if ($tc && !in_array('gov_fee', $tc, true)) foreach (['gov_fee', 'our_fee', 'fee_note'] as $col) $pdo->exec("ALTER TABLE templates ADD COLUMN $col TEXT DEFAULT ''");
        $ac = array_column($pdo->query('PRAGMA table_info(audit)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if ($ac && !in_array('matter_id', $ac, true)) $pdo->exec('ALTER TABLE audit ADD COLUMN matter_id INTEGER');
        $pdo->exec("CREATE TABLE IF NOT EXISTS oa_files (correspondence_id INTEGER PRIMARY KEY, status TEXT, files TEXT DEFAULT '', note TEXT DEFAULT '', fetched_at TEXT DEFAULT (datetime('now')))");   // office actions filed to Dropbox by the laptop job (bin/oa_fetch.php)
        $pdo->exec("CREATE TABLE IF NOT EXISTS deleted_records (id INTEGER PRIMARY KEY, ts TEXT DEFAULT (datetime('now')), by_user TEXT, ref TEXT, data TEXT)");   // Advanced > Delete record keeps a full copy (restorable)
        $mcols = array_column($pdo->query('PRAGMA table_info(matters)')->fetchAll(), 'name');
        if ($mcols && !in_array('priority_json', $mcols, true)) { $pdo->exec("ALTER TABLE matters ADD COLUMN priority_json TEXT DEFAULT ''"); $pdo->exec('ALTER TABLE matters ADD COLUMN priority_at TEXT'); }   // ODP parent/child/foreign claims (2026-09-30)
        if ($mcols && !in_array('other_firm_at', $mcols, true)) $pdo->exec("ALTER TABLE matters ADD COLUMN other_firm_at TEXT DEFAULT ''");   // when firm_of_record last asked the USPTO
        // Foreign associates (2026-10-14, XYZ001): their file number, who instructs the matter at their firm (the email goes to
        // that person only), and a per-matter accounts-payable address that overrides the client's.
        if ($mcols && !in_array('their_ref', $mcols, true)) foreach (['their_ref', 'instructing', 'ap_email'] as $col) $pdo->exec("ALTER TABLE matters ADD COLUMN $col TEXT DEFAULT ''");
        if ($mcols && !in_array('other_firm', $mcols, true)) $pdo->exec("ALTER TABLE matters ADD COLUMN other_firm TEXT DEFAULT ''");   // another firm of record at the USPTO (lib/patreport.php firm_of_record), 2026-10-04
        if ($mcols && !in_array('basis_filed', $mcols, true)) $pdo->exec("ALTER TABLE matters ADD COLUMN basis_filed TEXT DEFAULT ''");   // TSDR basis at filing (filedUse/filedItu/...), 2026-10-04: publication emails
        if ($mcols && !in_array('mark_drawing', $mcols, true)) $pdo->exec("ALTER TABLE matters ADD COLUMN mark_drawing TEXT DEFAULT ''");   // TSDR markDrawingCd: 4 = standard characters; 2/3/5 = design / stylized (logo shown)
        if ($ccols && !in_array('phone', $ccols, true)) $pdo->exec("ALTER TABLE clients ADD COLUMN phone TEXT DEFAULT ''");   // client phone number(s) (2026-09-30)
        $pdo->exec('CREATE TABLE IF NOT EXISTS login_fail (id INTEGER PRIMARY KEY, ip TEXT, user TEXT, ts INTEGER)');   // sign-in throttle (lib/auth.php)
        $pdo->exec('CREATE TABLE IF NOT EXISTS gmail_seen (msg_id TEXT PRIMARY KEY, ts TEXT DEFAULT (datetime(\'now\')), subject TEXT, result TEXT)');
        $pdo->sqliteCreateFunction('note_add', fn($note, $add) => trim(trim((string)$note) === '' ? (string)$add : trim((string)$note) . ' | ' . (string)$add), 2);
    }
    return $pdo;
}
/**
 * The pair server's SQLite (3.50) is built without double-quoted string literals
 * (SQLITE_DQS=0), so status="open" there is "no such column". This codebase never
 * double-quotes identifiers, so rewrite every "..." to a single-quoted literal.
 */
function sql_lit(string $sql): string {
    return strpos($sql, '"') === false ? $sql
        : preg_replace_callback('/"([^"]*)"/', fn ($m) => "'" . str_replace("'", "''", $m[1]) . "'", $sql);
}
function q(string $sql, array $args = []): array { $s = db()->prepare(sql_lit($sql)); $s->execute($args); return $s->fetchAll(); }
function q1(string $sql, array $args = []): ?array { $r = q($sql, $args); return $r[0] ?? null; }
function run(string $sql, array $args = []): int { $s = db()->prepare(sql_lit($sql)); $s->execute($args); return $s->rowCount(); }
function setting(string $k, ?string $default = null): ?string { $r = q1('SELECT value FROM settings WHERE key=?', [$k]); return $r ? $r['value'] : $default; }
function audit(string $action, string $detail = '', ?int $matterId = null): void {
    run('INSERT INTO audit(user,action,detail,matter_id) VALUES (?,?,?,?)', [(function_exists('auth_user') ? auth_user() : null) ?? 'system', $action, $detail, $matterId]);
}
/** One audit row per event marked done (or proposed done), on the matter's change log (2026-09-28). */
function audit_done(array $ev, string $how, bool $done = true): void {
    $ref = $ev['ref'] ?? (isset($ev['matter_id']) ? (q1('SELECT ref FROM matters WHERE id=?', [(int)$ev['matter_id']])['ref'] ?? '') : '');
    audit($done ? 'marked_done' : 'proposed_done', ($ref !== '' ? $ref . ' ' : '') . ($ev['type'] ?? '?') . ' (due ' . substr((string)($ev['due_date'] ?? ''), 0, 10) . ')' . ($done ? ' marked done' : ' proposed done') . ($how !== '' ? ': ' . $how : ''), isset($ev['matter_id']) ? (int)$ev['matter_id'] : null);
}
