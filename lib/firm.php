<?php
declare(strict_types=1);
/**
 * Who the firm is (2026-10-15, packaging Pendant for other firms). Everything firm-specific that used to be written into the code
 * — name, phones, web sites, own e-mail domains, USPTO customer numbers, the signing attorney, public URLs — is read from here.
 * Values come from firm.php at the install root (not shipped; firm.example.php shows every key), then config.php 'firm' => [...]
 * overrides, then the neutral defaults below. An empty URL switches that feature's link off.
 */
function firm_all(): array {
    static $f = null;
    if ($f !== null) return $f;
    $f = [
        'name'        => 'Your Firm',                 // short name: page titles, footers
        'legal_name'  => 'Your Firm, LLC',            // client report cover
        'tagline'     => 'Patent and Trademark Attorneys',
        'initials'    => '',                          // the header monogram; '' = the firm name's capitals (Your Firm -> YF)
        'phones'      => [],                          // ['NJ' => '973-555-0100', ...] signature + "firm's own number" filter
        'sites'       => [],                          // ['Example.com' => 'https://example.com/'] signature links
        'public_url'  => '',                          // https://example.com : base of signing links (<public_url>/private/?d=ID)
        'catalogs_url'  => '',                        // https://example.com/catalogs/ : per-patent / per-mark pages, '' = link TSDR only
        'catalogs_root' => '',                        // the same catalogs on disk (server path), '' = none
        'patent_guide_url' => '',                     // linked from the patent timeline card
        'tm_timeline_url'  => '',                     // linked from the trademark timeline card
        'own_domains'   => [],                        // ['example.com']: mail from these is the firm's own
        'own_addresses' => [],                        // extra firm mailboxes outside own_domains (e.g. a gmail.com address)
        'internal_addresses' => [],                   // staff / partner personal addresses never treated as a client
        'ignore_domains' => [],                       // other domains (family, vendors) never treated as a client
        'staff_senders'  => [],                       // addresses the firm reports to clients from; [] = own_domains + own_addresses
        'customer_numbers' => [],                     // the firm's USPTO customer numbers (first = the one forms name)
        'skip_customer_numbers' => [],                // notices for these customer numbers are not ours to docket
        'of_record_re'  => '',                        // regex matching the firm on a correspondence address, '' = name only
        'attorney'      => 'Attorney Name, Esq.',     // signs letters and forms; author of generated .docx
        'attorney_reg'  => '',                        // USPTO registration number, e.g. '12,345'
        'sign_example'  => '/Jane Doe/',              // shown to clients as how to type an /S/ signature
        'signature_closing' => 'Your business is appreciated,',
        'signed_forms_to'   => '',                    // where signed /S/ forms are e-mailed
        'noreply_from'      => '',                    // From: of automatic notices to the firm
        'report_from'       => '',                    // From: of the morning report and alarms
        'mail_helper'   => '',                        // PHP file defining ff_send_mail($to,$subj,$html,$opts); '' = PHP mail()
        'mail_ssh'      => '',                        // user@host that has mail_helper, for jobs run off the server
        'legacy_signature' => '',                    // a sign-off older seeds baked into template bodies (stripped once)
        'dropbox_roots'      => ['/Matters'],         // Dropbox folders indexed for matter folders (<root>/<REF>)
        'dropbox_write_root' => '',                   // where new papers are saved; '' = the first root
        'dropbox_forbidden'  => [],                   // top-level Dropbox folders Pendant must never read or write
        'sienna_old_db' => '',                        // optional pre-1976 patent database for IDS citations
    ];
    $file = dirname(__DIR__) . '/firm.php';
    if (is_file($file) && is_array($x = require $file)) $f = array_replace($f, $x);
    if (is_array($x = $GLOBALS['cfg']['firm'] ?? null)) $f = array_replace($f, $x);
    if ($f['initials'] === '') $f['initials'] = preg_replace('/[^A-Z&]/', '', ucwords($f['name'])) ?: 'P';
    if ($f['of_record_re'] === '') $f['of_record_re'] = '/' . preg_quote($f['name'], '/') . '/i';
    putenv('PENDANT_ATTORNEY=' . $f['attorney']);   // the Python letter builders stamp it as the .docx author
    putenv('PENDANT_CUSTOMER_NO=' . ($f['customer_numbers'][0] ?? ''));   // bin/prepare_filing.py fills it into the POA / 3.73 forms
    return $f;
}
function firm(string $k) { return firm_all()[$k] ?? null; }
/** Is this address one of the firm's own (own_domains or own_addresses)? */
function firm_own_address(string $addr): bool {
    $addr = strtolower(trim($addr)); if ($addr === '') return false;
    foreach (firm('own_domains') as $d) if (str_ends_with($addr, '@' . strtolower($d))) return true;
    return in_array($addr, array_map('strtolower', firm('own_addresses')), true);
}
/** Gmail search fragment for mail sent by the firm: "example.com OR example.net OR x@gmail.com". */
function firm_from_query(): string { return implode(' OR ', array_merge(firm('own_domains'), firm('own_addresses'))) ?: 'me'; }
/** "Your Firm, Patent and Trademark Attorneys" */
function firm_line(): string { return firm('name') . (firm('tagline') ? ', ' . firm('tagline') : ''); }
