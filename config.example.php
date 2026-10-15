<?php
// Madder configuration. install.sh copies this to config.php (mode 600) and fills in the first admin.
// The firm itself (name, phones, domains, customer numbers, signing attorney ...) is in firm.php.
date_default_timezone_set('America/New_York');   // the firm's clock: "today" must not flip at 8 pm
return [
  'db'    => getenv('DOCKET_DB') ?: __DIR__ . '/data/docket.db',
  // Sign-ins. role admin = confirms undocketing, edits templates, changes due dates; staff = everything else.
  // 'hash' is password_hash() output (install.sh writes it; add users with: php -r 'echo password_hash("pw", PASSWORD_DEFAULT);').
  // 'email' = the address client emails go out from for this user; '' = no Send button.
  'users' => [
    // '__ADMIN__' => ['hash' => '__HASH__', 'role' => 'admin', 'name' => '__NAME__', 'email' => '__EMAIL__'],
  ],
  // Plain-text sign-off per user: greeting line, name, optional title line. Rendered as an HTML signature with firm.php's details.
  'signatures' => [
    // 'jane' => "Your business is appreciated,\nJane Doe, Esq.",
  ],
  'from_email'  => '',          // default From: of client email
  'cc_email'    => '',          // copied on client email sent by anyone else (the supervising attorney)
  'bcc_email'   => '',          // a copy of every client email
  'mail_mode'   => getenv('DOCKET_MAIL_MODE') ?: 'outbox',   // 'outbox' = record only, nothing leaves; 'live' = send with PHP mail()
  // USPTO APIs (free): TSDR key at https://account.uspto.gov/api-manager/ , Open Data Portal key at https://data.uspto.gov/apis/getting-started
  'tsdr_key'    => '',
  'odp_key'     => '',
  // Gmail intake of USPTO notices (optional): path to gmail/config.php (see README). '' = paste notices by hand on the USPTO mail page.
  'gmail_config' => getenv('DOCKET_GMAIL_CONFIG') ?: '',
  // Matter folders on this machine (optional; Dropbox can be connected instead under Advanced > Dropbox).
  'oa_dropbox'  => getenv('DOCKET_FOLDERS') ?: '',
  'backup_dir'  => getenv('DOCKET_BACKUP_DIR') ?: __DIR__ . '/data/backups',   // nightly VACUUM INTO copies, 30 kept
  // Origin gate (optional): allow only listed countries. Needs a country-range SQLite file (see lib/geo.php); off by default.
  'geo_enabled' => false,
  'geo_db'      => __DIR__ . '/data/geo.db',
  'geo_always_allow' => [],
  'mail_users'  => [],
  'mail_base'   => 'mail/',
];
