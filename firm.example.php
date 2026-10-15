<?php
// Your firm's profile (read by lib/firm.php). install.sh copies this to firm.php; edit it before going live.
// Every key is optional: what you leave out falls back to the neutral default in lib/firm.php. An empty URL turns that link off.
return [
    'name'        => 'Your Firm',                        // page titles, footers, the signature block
    'legal_name'  => 'Your Firm, LLC',                   // client report cover
    'tagline'     => 'Patent and Trademark Attorneys',
    'phones'      => ['Office' => '555-555-0100'],       // label => number (signature); also never saved as a client's phone
    'sites'       => ['Example.com' => 'https://example.com/'],
    'public_url'  => '',                                 // https://example.com : base of e-signature links (<public_url>/private/?d=ID)
    'catalogs_url'  => '',                               // your own public pages per patent / mark, if you publish them
    'catalogs_root' => '',
    'patent_guide_url' => '',                            // "How the patent process works" link on the patent timeline card
    'tm_timeline_url'  => '',                            // "How the trademark process works" link on the trademark timeline card
    'own_domains'   => ['example.com'],                  // mail from these domains is the firm's own (never "the client")
    'own_addresses' => [],                               // other firm mailboxes, e.g. a gmail.com address the firm writes from
    'internal_addresses' => [],                          // staff / partners' personal addresses
    'ignore_domains' => [],                              // vendors and other domains that are never a client
    'staff_senders'  => [],                              // addresses reports go out from; [] = own_domains + own_addresses
    'customer_numbers' => [],                            // your USPTO customer number(s); the first is named in power-of-attorney text
    'skip_customer_numbers' => [],                       // customer numbers whose notices are not yours to docket
    'of_record_re'  => '',                               // regex for your firm on a USPTO correspondence address; '' = the firm name
    'attorney'      => 'Jane Doe, Esq.',                 // signs generated letters and forms
    'attorney_reg'  => '',                               // USPTO registration number
    'sign_example'  => '/Jane Doe/',                     // how clients are shown to type an /S/ signature
    'signature_closing' => 'Your business is appreciated,',
    'signed_forms_to'   => '',                           // where e-signed forms are e-mailed
    'noreply_from'      => '',                           // From: of automatic notices
    'report_from'       => '',                           // From: of the morning report and alarms
    'mail_helper'   => '',                               // optional PHP file defining ff_send_mail($to, $subject, $html, $opts); '' = PHP mail()
    'mail_ssh'      => '',
    'dropbox_roots'      => ['/Matters'],                // Dropbox folders holding the matter folders (<root>/<FILE NO.>)
    'dropbox_write_root' => '',
    'dropbox_forbidden'  => [],                          // top-level Dropbox folders Madder must never touch
];
