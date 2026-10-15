<?php
declare(strict_types=1);
// The pair server runs on UTC: without this, date() flips to tomorrow at 8 pm Eastern, so evening work (notices pulled, "today" on the dashboard,
// 'emailed client <date>' notes, reminder fire dates) would carry the next day's date. All dates in Docket are US Eastern.
date_default_timezone_set($GLOBALS['cfg']['timezone'] ?? 'America/New_York');
/** USPTO deadline arithmetic. A period ending on a Saturday, Sunday or federal holiday in DC runs to the next business day (35 USC 21(b); 37 CFR 2.196). */
function federal_holidays(int $y): array {
    $h = [];
    $obs = function (string $md) use ($y, &$h) {   // fixed-date holiday, observed Fri/Mon when on weekend
        $d = new DateTimeImmutable("$y-$md"); $w = (int)$d->format('N');
        if ($w === 6) $d = $d->modify('-1 day'); elseif ($w === 7) $d = $d->modify('+1 day');
        $h[] = $d->format('Y-m-d');
    };
    $nth = function (string $which, string $dow, string $month) use ($y, &$h) { $h[] = (new DateTimeImmutable("$which $dow of $month $y"))->format('Y-m-d'); };
    $obs('01-01'); $nth('third', 'monday', 'january'); $nth('third', 'monday', 'february');
    $obs('04-16');   // DC Emancipation Day: a holiday "within the District of Columbia" under 35 USC 21(b) (MPEP 710.05)
    $nth('last', 'monday', 'may'); $obs('06-19'); $obs('07-04'); $nth('first', 'monday', 'september');
    $nth('second', 'monday', 'october'); $obs('11-11'); $nth('fourth', 'thursday', 'november'); $obs('12-25');
    if ($y % 4 === 1) { $d = new DateTimeImmutable("$y-01-20"); if ((int)$d->format('N') === 7) $d = $d->modify('+1 day'); if ((int)$d->format('N') < 6) $h[] = $d->format('Y-m-d'); }   // Inauguration Day (DC) counts under 35 USC 21(b)
    // New Year's Day of next year observed on Dec 31 of this year
    $ny = new DateTimeImmutable(($y + 1) . '-01-01'); if ((int)$ny->format('N') === 6) $h[] = "$y-12-31";
    return $h;
}
function is_business_day(DateTimeImmutable $d): bool {
    if ((int)$d->format('N') >= 6) return false;
    return !in_array($d->format('Y-m-d'), federal_holidays((int)$d->format('Y')), true);
}
function roll_forward(DateTimeImmutable $d): DateTimeImmutable { while (!is_business_day($d)) $d = $d->modify('+1 day'); return $d; }
/** Add calendar months the USPTO way: same day-of-month, clamped to month end; then roll forward. */
function add_months(string $iso, int $months, bool $roll = true): string {
    $d = new DateTimeImmutable($iso); $day = (int)$d->format('j');
    $t = $d->modify('first day of this month')->modify(sprintf('%+d months', $months));   // sprintf: "+-6 months" would silently mean +6
    $t = $t->setDate((int)$t->format('Y'), (int)$t->format('n'), min($day, (int)$t->format('t')));
    return ($roll ? roll_forward($t) : $t)->format('Y-m-d');
}
function add_days(string $iso, int $days, bool $roll = true): string {
    $t = (new DateTimeImmutable($iso))->modify(($days >= 0 ? '+' : '') . "$days days");
    return ($roll ? roll_forward($t) : $t)->format('Y-m-d');
}
/** A stored timestamp (SQLite datetime('now') = UTC) as US Eastern for the screen. Date-only values pass through untouched. */
function et(?string $utc, int $len = 16): string {
    $utc = trim((string)$utc); if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $utc)) return substr($utc, 0, $len);
    try { return substr((new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'), 0, $len); } catch (Throwable $e) { return substr($utc, 0, $len); }
}
function today(): string { return $GLOBALS['as_of'] ?? date('Y-m-d'); }
function days_until(string $iso): int { return (int)(new DateTimeImmutable(today()))->diff(new DateTimeImmutable($iso))->format('%r%a'); }
/** "30-day reminder" / "on-the-day reminder" for a reminder lead. */
function lead_label(int $lead): string { return $lead > 0 ? $lead . '-day reminder' : 'on-the-day reminder'; }
/** Docket's own screens and reports: ISO YYYY-MM-DD (2026-09-24). Client emails use fmt_long(). */
function fmt_date(?string $iso): string { return $iso ? (new DateTimeImmutable($iso))->format('Y-m-d') : ''; }
/** US application / serial numbers the way the USPTO writes them: 99384743 -> 99/384,743 (patent and trademark alike). Foreign and odd-length numbers are left alone. */
function fmt_app(?string $n, ?string $country = 'United States'): string {
    $n = trim((string)$n); $c = trim((string)$country);
    if ($n !== '' && preg_match('/^\d{8}$/', $n) && ($c === '' || preg_match('/^(United States|US|USA)$/i', $c))) return substr($n, 0, 2) . '/' . substr($n, 2, 3) . ',' . substr($n, 5);
    return $n;
}
/** Patent / registration numbers with thousands commas: 8968710 -> 8,968,710 (US-style 7-8 digit numbers only; anything else untouched). */
function fmt_reg(?string $n): string { $n = trim((string)$n); return preg_match('/^\d{6,8}$/', $n) ? number_format((int)$n) : $n; }
/** TSDR status page for a US trademark serial or registration number. */
function tsdr_url(string $n, bool $reg = false): string { return 'https://tsdr.uspto.gov/#caseNumber=' . preg_replace('/\D/', '', $n) . '&caseType=' . ($reg ? 'US_REGISTRATION_NO' : 'SERIAL_NO') . '&searchType=statusSearch'; }
/** Patent Center page for a US patent application number (2026-09-29: serial numbers link there; unpublished ones need his login). */
function patentcenter_url(string $app): string { return 'https://patentcenter.uspto.gov/applications/' . preg_replace('/\D/', '', $app); }
/** Client-facing form: "September 27, 2026" (the attorney: every date in an email is written out in full). */
function fmt_long(?string $iso): string { return $iso ? (new DateTimeImmutable($iso))->format('F j, Y') : ''; }
/** Rewrite any mm/dd/yyyy left in client-facing text (older queued reminders, hand-typed bodies) into the long form. */
function dates_long(string $text): string {
    $text = preg_replace_callback('/\b(\d{4})-(\d{2})-(\d{2})\b/', fn($m) => checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? (new DateTimeImmutable($m[0]))->format('F j, Y') : $m[0], $text);
    return preg_replace_callback('/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/', fn($m) => checkdate((int)$m[1], (int)$m[2], (int)$m[3]) ? (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2])))->format('F j, Y') : $m[0], $text);
}
// Light touches of the website's colours (2026-09-29): navy #0c203b, gold #c9a227 (a darker gold for text on white).
const NAVY = '#0c203b'; const GOLD_TEXT = '#a8841a';
const MARK_COLOR = GOLD_TEXT;
/** Email body (plain text plus <b>/<i>/<u> from the send-page editor) -> inner HTML: dates written out and underlined, line breaks kept. */
function mail_body_html(string $body): string {
    $t = htmlspecialchars(dates_long($body), ENT_QUOTES, 'UTF-8');
    $t = preg_replace('#&lt;(/?)(b|i|u)&gt;#i', '<$1$2>', $t);   // only the three formatting tags survive
    // <m>MARK</m> = the trademark in color (2026-09-29); data-m lets the send-page editor keep it.
    $t = preg_replace('#&lt;m&gt;(.*?)&lt;/m&gt;#s', '<span data-m="1" style="color:' . MARK_COLOR . ';font-weight:bold">$1</span>', $t);
    $t = preg_replace('#&lt;sm&gt;(.*?)&lt;/sm&gt;#s', '<span data-s="1" style="display:inline-block;margin-left:2.5em;font-size:' . GOODS_SIZE . '">$1</span>', $t);   // goods/services lists: smaller, indented (wrapped lines too)
    // <logo>SERIAL</logo> = the USPTO drawing of a logo mark, small (2026-09-30); data-logo lets the send-page editor keep it.
    $t = preg_replace_callback('#&lt;logo&gt;(\d{8})&lt;/logo&gt;#', fn($x) => '<img data-logo="' . $x[1] . '" src="' . htmlspecialchars(function_exists('logo_url') ? logo_url($x[1]) : 'https://tsdr.uspto.gov/img/' . $x[1] . '/large', ENT_QUOTES) . '" alt="mark" style="max-height:70px;max-width:200px;vertical-align:middle;border:1px solid #e2ddd2;border-radius:4px;padding:4px;background:#fff;margin:2px 0">', $t);
    // <n u="URL">NUMBER</n> = a linked number (2026-10-01: serial -> TSDR; registration / patent number -> its page in the firm's catalogs).
    $t = preg_replace_callback('#&lt;n u=&quot;(https://[^\s<>]*?)&quot;&gt;(.*?)&lt;/n&gt;#s', fn($x) => '<a data-n="1" data-u="' . $x[1] . '" href="' . $x[1] . '" style="color:' . NAVY . ';text-decoration:underline">' . $x[2] . '</a>', $t);
    $t = preg_replace('#&lt;n&gt;(.*?)&lt;/n&gt;#s', '<span data-n="1" style="color:' . NAVY . '">$1</span>', $t);   // numbers: navy
    // Bare web addresses become real links (2026-10-02: the trademark-timeline address in the filing email should work as a link
    // in every mail program). Addresses inside an attribute (href, data-u) are preceded by a quote and are left alone.
    $t = preg_replace_callback('#(?<![\'"=/])\b(https?://[^\s<>"]+?)(?=[.,;:)]?(?:\s|$|<))#i', fn($x) => '<a data-a="1" href="' . $x[1] . '" style="color:' . NAVY . ';text-decoration:underline">' . $x[1] . '</a>', $t);
    // <maint>YYYYMMDD</maint> = the renewal table for an issue date (2026-10-04, XYZ004: "more pretty"); data-maint lets the editor keep it.
    $t = preg_replace_callback('#&lt;maint&gt;(\d{8})&lt;/maint&gt;#', fn($x) => function_exists('maint_table_html') ? maint_table_html(substr($x[1], 0, 4) . '-' . substr($x[1], 4, 2) . '-' . substr($x[1], 6, 2)) : $x[0], $t);
    $t = preg_replace('/(?<!<u>)\b((?:January|February|March|April|May|June|July|August|September|October|November|December) \d{1,2}, \d{4})\b(?!<\/u>)/', '<u>$1</u>', $t);
    $t = preg_replace('/^(\s*)(REMINDER:|GRACE PERIOD:)/m', '$1<span style="color:' . GOLD_TEXT . ';font-weight:bold;letter-spacing:.04em">$2</span>', $t);
    $t = preg_replace_callback('/^ +/m', fn($m) => str_repeat('&nbsp;', strlen($m[0])), $t);   // indents (goods lists, " 1)") survive in HTML
    $t = str_replace(['<b>', '<u>'], ['<b style="color:' . NAVY . '">', '<u style="color:' . NAVY . '">'], $t);
    // A line that is nothing but bold text is a section heading: a touch larger, navy, a little space above (2026-09-29).
    $t = preg_replace('~^<b style="color:' . preg_quote(NAVY, '~') . '">([^<\n]+)</b>$~m', '<span style="display:inline-block;margin-top:6px;font-size:14px;font-weight:bold;color:' . NAVY . '">$1</span>', $t);
    return preg_replace('#(</table>)<br>#', '$1', str_replace("\n", '<br>', str_replace("\r", '', $t)));   // a block table needs no line break after it
}
const GOODS_SIZE = '12px';   // goods/services list in trademark emails, a step below the 13px body (2026-09-29)
/** The email exactly as the client receives it: body, then the sender's signature block. */
function mail_html(string $body, string $sig = ''): string {
    return '<div style="font-family:Lexend,Arial,sans-serif;font-size:13px;line-height:1.5">' . mail_body_html($body) . '</div>' . $sig;
}
