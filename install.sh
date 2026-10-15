#!/usr/bin/env bash
# Pendant installer: checks what the machine has, writes config.php + firm.php, creates the database and the first admin,
# seeds the client-email templates (unapproved), and prints the cron lines. Safe to re-run: nothing existing is overwritten.
#
#   ./install.sh            asks for the admin's username, name, e-mail and password
#   ./install.sh --yes      non-interactive: PENDANT_ADMIN_USER, PENDANT_ADMIN_PASS (+ optional PENDANT_ADMIN_NAME, PENDANT_ADMIN_EMAIL)
set -euo pipefail
cd "$(dirname "$0")"; ROOT="$(pwd)"
YES=no; [ "${1:-}" = "--yes" ] && YES=yes
say() { printf '%s\n' "$*"; }
miss=0

say "==> checking requirements"
command -v php >/dev/null || { say "PHP 8.1 or newer is required (php-cli, plus php-sqlite3 and php-curl)."; exit 1; }
php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);' || { say "PHP 8.1 or newer is required; this is $(php -r 'echo PHP_VERSION;')."; exit 1; }
for ext in pdo_sqlite curl mbstring; do
  php -r "exit(extension_loaded('$ext') ? 0 : 1);" || { say "  MISSING PHP extension: $ext"; [ "$ext" = pdo_sqlite ] && exit 1; miss=1; }
done
if command -v python3 >/dev/null; then
  for mod in docx pypdf reportlab PIL; do python3 -c "import $mod" 2>/dev/null || { say "  optional: python3 module '$mod' missing (letters, forms, client report PDF). pip install python-docx pypdf reportlab pillow"; miss=1; }; done
else say "  optional: python3 missing (letters, forms, client report PDF)"; miss=1; fi
for t in pdftotext tesseract; do command -v $t >/dev/null || { say "  optional: $t missing (reading PDFs in matter folders)"; miss=1; }; done
[ $miss = 0 ] && say "  all present"

say "==> files"
mkdir -p data/reports data/backups data/attachments; chmod 700 data
[ -f firm.php ] && say "  firm.php kept" || { cp firm.example.php firm.php; chmod 600 firm.php; say "  firm.php created: edit it (firm name, phones, domains, attorney)"; }
NEWCFG=no
if [ -f config.php ]; then say "  config.php kept"; else cp config.example.php config.php; chmod 600 config.php; NEWCFG=yes; say "  config.php created"; fi

if [ $NEWCFG = yes ]; then
  if [ $YES = yes ]; then U="${PENDANT_ADMIN_USER:?set PENDANT_ADMIN_USER}"; PW="${PENDANT_ADMIN_PASS:?set PENDANT_ADMIN_PASS}"; N="${PENDANT_ADMIN_NAME:-$U}"; E="${PENDANT_ADMIN_EMAIL:-}"
  else
    read -rp "  admin username (lower case, e.g. jane): " U
    read -rp "  full name (as signed on letters): " N
    read -rp "  e-mail address client email is sent from (blank = none): " E
    while :; do read -rsp "  password (12+ characters): " PW; echo; [ ${#PW} -ge 12 ] && break; say "  too short"; done
  fi
  [[ "$U" =~ ^[a-z][a-z0-9_]{1,30}$ ]] || { say "username must be lower-case letters/digits"; rm -f config.php; exit 1; }
  U="$U" N="$N" E="$E" PW="$PW" php -r '
    $f = "config.php"; $s = file_get_contents($f);
    $line = "    " . var_export(getenv("U"), true) . " => [\x27hash\x27 => " . var_export(password_hash(getenv("PW"), PASSWORD_DEFAULT), true)
          . ", \x27role\x27 => \x27admin\x27, \x27name\x27 => " . var_export(getenv("N"), true) . ", \x27email\x27 => " . var_export(getenv("E"), true) . "],";
    $s = preg_replace_callback("~^\s*// \x27__ADMIN__\x27.*$~m", fn() => $line, $s, 1, $n); if ($n !== 1) exit(1);   // callback: a bcrypt hash is full of $2y$...
    if (getenv("E") !== "") $s = preg_replace_callback("~\x27from_email\x27\s*=>\s*\x27\x27~", fn() => "\x27from_email\x27  => " . var_export(getenv("E"), true), $s, 1);
    file_put_contents($f, $s);
    $c = require $f; if (!password_verify(getenv("PW"), $c["users"][getenv("U")]["hash"] ?? "")) exit(2);' || { say "could not write the admin into config.php"; rm -f config.php; exit 1; }
  say "  admin '$U' created"
fi

say "==> database"
php -r '
  $GLOBALS["cfg"] = $cfg = require "config.php";
  $new = !is_file($cfg["db"]);
  $p = new PDO("sqlite:" . $cfg["db"]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  if ($new) { $p->exec(file_get_contents("schema.sql")); echo "  created ", $cfg["db"], "\n"; } else echo "  kept ", $cfg["db"], "\n";
  @chmod($cfg["db"], 0600);
  require "lib/db.php"; db();   // forward migrations
'
php bin/seed_templates.php | sed 's/^/  /' | tail -2

cat <<EOF
==> done
Try it:     ./run.sh     then open http://127.0.0.1:8090
Serve it:   point a web server's document root at $ROOT/web (PHP 8.1+); everything else stays outside the web root.
Jobs (crontab -e, as the user that owns this folder):
  30 5 * * *   php $ROOT/bin/nightly.php --email=you@example.com
  15 * * * *   php $ROOT/bin/hourly.php
  0  6 * * *   php $ROOT/bin/heartbeat.php --email=you@example.com
Next:       edit firm.php; add the USPTO API keys and other users in config.php; see README.md.
EOF
