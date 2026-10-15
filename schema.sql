PRAGMA journal_mode=WAL;
CREATE TABLE IF NOT EXISTS clients (
  code TEXT PRIMARY KEY, name TEXT DEFAULT '', email TEXT DEFAULT '', alt_emails TEXT DEFAULT '', notes TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS matters (
  id INTEGER PRIMARY KEY, kind TEXT NOT NULL, ref TEXT NOT NULL, client_code TEXT DEFAULT '',
  title TEXT DEFAULT '', country TEXT DEFAULT 'United States', app_no TEXT DEFAULT '', reg_no TEXT DEFAULT '',
  pto_status TEXT DEFAULT '', pto_status_date TEXT, status TEXT DEFAULT '', family_id TEXT DEFAULT '',
  attorney TEXT DEFAULT '', filing_date TEXT, grant_date TEXT, notes TEXT DEFAULT '',
  dt_id INTEGER, dt_ref TEXT DEFAULT '', active INTEGER DEFAULT 1, dead INTEGER DEFAULT 0, dead_reason TEXT DEFAULT '', dead_at TEXT, live TEXT DEFAULT '', owner TEXT DEFAULT '', classes TEXT DEFAULT '', goods TEXT DEFAULT '',
  history TEXT DEFAULT '', comments TEXT DEFAULT '', entity TEXT DEFAULT '', pub_no TEXT DEFAULT '', expiration TEXT, app_type TEXT DEFAULT '', basis TEXT DEFAULT '',
  created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS matters_ref ON matters(ref);
CREATE INDEX IF NOT EXISTS matters_app ON matters(app_no);
CREATE INDEX IF NOT EXISTS matters_client ON matters(client_code);
CREATE TABLE IF NOT EXISTS events (
  id INTEGER PRIMARY KEY, matter_id INTEGER NOT NULL REFERENCES matters(id),
  type TEXT NOT NULL, due_date TEXT NOT NULL, status TEXT DEFAULT 'open',   -- open|proposed|done|cancelled
  source TEXT DEFAULT 'import', doc_code TEXT DEFAULT '', mail_date TEXT, note TEXT DEFAULT '',
  proposed_by TEXT, proposed_at TEXT, done_by TEXT, done_at TEXT, dt_event_id INTEGER, rejected_at TEXT,
  created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS events_due ON events(status, due_date);
CREATE INDEX IF NOT EXISTS events_matter ON events(matter_id);
CREATE INDEX IF NOT EXISTS events_matter_open ON events(matter_id, status, due_date);
CREATE TABLE IF NOT EXISTS templates (
  event_type TEXT PRIMARY KEY, subject TEXT DEFAULT '', body TEXT DEFAULT '',
  needs_invoice INTEGER DEFAULT 0, approved INTEGER DEFAULT 0, approved_by TEXT, approved_at TEXT,
  lead_days TEXT DEFAULT '30,3', internal_only INTEGER DEFAULT 0,
  gov_fee TEXT DEFAULT '', our_fee TEXT DEFAULT '', fee_note TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS gmail_seen (msg_id TEXT PRIMARY KEY, ts TEXT DEFAULT (datetime('now')), subject TEXT, result TEXT);
CREATE TABLE IF NOT EXISTS reminders (
  id INTEGER PRIMARY KEY, event_id INTEGER NOT NULL REFERENCES events(id), lead INTEGER NOT NULL,
  scheduled_for TEXT NOT NULL, status TEXT DEFAULT 'pending',   -- pending|sent|draft|held|skipped
  to_email TEXT DEFAULT '', subject TEXT DEFAULT '', body TEXT DEFAULT '', reason TEXT DEFAULT '',
  created_at TEXT DEFAULT (datetime('now')), sent_at TEXT,
  UNIQUE(event_id, lead)
);
CREATE TABLE IF NOT EXISTS correspondence (
  id INTEGER PRIMARY KEY, matter_id INTEGER, app_no TEXT, doc_code TEXT, mail_date TEXT, docket TEXT,
  kind TEXT, summary TEXT DEFAULT '', source TEXT DEFAULT '', created_at TEXT DEFAULT (datetime('now')),
  UNIQUE(app_no, doc_code, mail_date)
);
CREATE TABLE IF NOT EXISTS audit (
  id INTEGER PRIMARY KEY, ts TEXT DEFAULT (datetime('now')), user TEXT, action TEXT, detail TEXT, matter_id INTEGER
);
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT);
CREATE TABLE IF NOT EXISTS outbox (
  id INTEGER PRIMARY KEY, event_id INTEGER, matter_id INTEGER NOT NULL, from_user TEXT, from_email TEXT, to_email TEXT, cc_email TEXT DEFAULT '', subject TEXT, body TEXT,
  mode TEXT DEFAULT 'sent', created_at TEXT DEFAULT (datetime('now')), transmitted_at TEXT, transmit_error TEXT DEFAULT '', attachments TEXT DEFAULT '',
  in_reply_to TEXT DEFAULT '', refs_hdr TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS dt_sync (
  id INTEGER PRIMARY KEY, event_id INTEGER, matter_id INTEGER NOT NULL, action TEXT NOT NULL, detail TEXT DEFAULT '', status TEXT DEFAULT 'pending',
  by_user TEXT, created_at TEXT DEFAULT (datetime('now')), mirrored_by TEXT, mirrored_at TEXT
);
CREATE TABLE IF NOT EXISTS checks (
  id INTEGER PRIMARY KEY, matter_id INTEGER NOT NULL, ts TEXT DEFAULT (datetime('now')), by_user TEXT, source TEXT, status TEXT, summary TEXT
);
