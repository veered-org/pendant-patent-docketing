<?php
// Seeds reminder templates from the attorney's real wording (memory: remind-client-emails, renewal-drafting-workflow). Nothing is approved until he clicks Approve.
$GLOBALS['cfg'] = require __DIR__ . '/../config.php'; foreach (['db', 'auth', 'dates', 'rules', 'mail', 'reminders', 'render', 'noa'] as $f) require __DIR__ . "/../lib/$f.php";
$sig = "";   // signatures are appended per sender at send time (config signatures); kept only for the one-time strip below
$oldsig = (string)firm('legacy_signature');   // a signature older seeds baked into template bodies, stripped once
$TL = preg_replace('/[\/]?#.*$/', '', (string)firm('tm_timeline_url')) ?: 'https://www.uspto.gov/trademarks/basics/trademark-process';
$sou = "REMINDER:  \n\nNow that we've passed through the examination and publication stage we need to submit a \"Statement of Use\" showing that the trademark is in use by {DUE}.  Alternatively, a six month extension can be filed.  \n\nFailure to take action will result in abandonment of your trademark application." . $sig;
$T = [
  // type, subject, body, needs_invoice, lead_days, internal_only
  ['Statement of Use or 1st Extension Due', '{FILE}: Serial No. {APP} for {MARK} - Statement of Use or 1st Extension Due {DUE}', $sou, 1, '30,7', 0],
  ['Statement of Use or 2nd Extension Due', '{FILE}: Serial No. {APP} for {MARK} - Statement of Use or 2nd Extension Due {DUE}', $sou, 1, '30,7', 0],
  ['Statement of Use or 3rd Extension Due', '{FILE}: Serial No. {APP} for {MARK} - Statement of Use or 3rd Extension Due {DUE}', $sou, 1, '30,7', 0],
  ['Statement of Use or 4th Extension Due', '{FILE}: Serial No. {APP} for {MARK} - Statement of Use or 4th Extension Due {DUE}', $sou, 1, '30,7', 0],
  ['Statement of Use or 5th Extension Due', '{FILE}: Serial No. {APP} for {MARK} - Statement of Use or 5th Extension Due {DUE}', str_replace('Alternatively, a six month extension can be filed.  ', 'No further extensions are available.  ', $sou), 1, '30,7', 0],
  ['3 1/2 Year Maintenance Fee Due', '{FILE}: U.S. Patent {PATENT} - 3.5 year maintenance fee due {DUE}',
    "The above-referenced U.S. Patent {PATENT} issued 3 years ago. As such, the maintenance fee window is now open. This fee is due to the U.S. Patent Office by {DUE}.\n\nAfter this date, the U.S. government charges a surcharge followed by marking your patent abandoned. As such, please kindly pay the attached invoice in a timely manner.\n\nPayment of this fee will keep your patent in force for an additional four years." . $sig, 1, '30,7', 0],
  ['7 1/2 Year Maintenance Fee Due', '{FILE}: U.S. Patent {PATENT} - 7.5 year maintenance fee due {DUE}',
    "The above-referenced U.S. Patent {PATENT} issued 7 years ago. As such, the maintenance fee window is now open. This fee is due to the U.S. Patent Office by {DUE}.\n\nAfter this date, the U.S. government charges a surcharge followed by marking your patent abandoned. As such, please kindly pay the attached invoice in a timely manner.\n\nPayment of this fee will keep your patent in force for an additional four years." . $sig, 1, '30,7', 0],
  ['11 1/2 Year Maintenance Fee Due', '{FILE}: U.S. Patent {PATENT} - 11.5 year maintenance fee due {DUE}',
    "The above-referenced U.S. Patent {PATENT} issued 11 years ago. As such, the final maintenance fee window is now open. This fee is due to the U.S. Patent Office by {DUE}.\n\nAfter this date, the U.S. government charges a surcharge followed by marking your patent abandoned. As such, please kindly pay the attached invoice in a timely manner.\n\nPayment of this fee will keep your patent in force for its remaining term." . $sig, 1, '30,7', 0],
  ['Section 8 and 15 Declarations End', '{FILE}: {MARK} (Reg. No. {REG}) - Section 8 & 15 declarations due {DUE}',
    "The above-referenced trademark has now been registered for five years. At this time, we must file the Section 8 Declaration of Use, together with the Section 15 Declaration of Incontestability. Filing them will keep the mark registered and make it incontestable, whereas failure to file will result in cancellation of the registration.\n\nPlease send me an up-to-date picture showing how the {MARK} mark is used in commerce and pay the attached invoice.\n\nWe must renew between now and {DUE}.\n\nPlease kindly confirm receipt." . $sig, 1, '30,7', 0],
  ['Section 8 Declaration and Section 9 Renewal Ends', '{FILE}: {MARK} (Reg. No. {REG}) - Section 8 & 9 renewal due {DUE}',
    "The above-referenced trademark registration is now due for its ten-year renewal. Failure to renew will result in cancellation of the registration.\n\nPlease send me an up-to-date picture showing how the {MARK} mark is used in commerce and pay the attached invoice.\n\nWe must renew between now and {DUE}.\n\nPlease kindly confirm receipt." . $sig, 1, '30,7', 0],
  ['Respond to Office Action', '{FILE}: Serial No. {APP} for {MARK} - Office Action response due {DUE}',
    "REMINDER:  \n\nThe U.S. Trademark Office issued an Office Action in the above matter. A response is due by {DUE}.\n\nPlease let me know your intentions at this time so that we can meet the deadline. Failure to respond will result in abandonment of the application." . $sig, 0, '30,7', 0],
  ['Respond to Office Action - CTNF', '{FILE}: Application {APP} - Office Action response due {DUE}',
    "REMINDER:  \n\nThe U.S. Patent Office issued an Office Action in the above matter. A response is due by {DUE} (extendable, with government extension fees, for up to three further months).\n\nPlease let me know your intentions at this time so that we can meet the deadline." . $sig, 0, '30,7', 0],
  ['Respond to Office Action - CTFR', '{FILE}: Application {APP} - Final Office Action response due {DUE}',
    "REMINDER:  \n\nThe U.S. Patent Office issued a Final Office Action in the above matter. A response is due by {DUE} (extendable, with government extension fees, for up to three further months).\n\nPlease let me know your intentions at this time so that we can meet the deadline." . $sig, 0, '30,7', 0],
  ['Respond to Office Action - NOA', '{FILE}: Application {APP} - Issue fee due {DUE}',
    "Good news: the U.S. Patent Office has allowed the above application. The issue fee must be paid by {DUE}. This deadline cannot be extended.\n\nPlease pay the attached invoice so that we can pay the issue fee and your patent can be granted." . $sig, 1, '30,7', 0],
  ['Non-Provisional Filing Deadline', '{FILE}: Provisional application {APP} - non-provisional filing deadline {DUE}',
    "REMINDER:  \n\nYour provisional patent application was filed one year ago. To keep your filing date, a non-provisional (or PCT) application must be filed by {DUE}. This deadline cannot be extended.\n\nPlease let me know your intentions at this time so that we can meet the deadline." . $sig, 0, '30,7', 0],
  ['Remind Client International Filing Deadline', '{FILE}: International (foreign) filing deadline {DUE}',
    "REMINDER:  \n\nThe deadline to file for patent protection outside the United States in the above matter is {DUE}. After this date foreign rights are lost.\n\nPlease let me know whether you wish to file abroad so that we can meet the deadline." . $sig, 0, '30,7', 0],
  ['Remind Client', '{FILE}: {TITLE} - {DEADLINE_EVENT} due {DEADLINE}', "REMINDER:  \n\nIn the above matter, {DEADLINE_EVENT} is due by {DEADLINE}.\n\nPlease let me know your intentions at this time so that we can meet the deadline." . $sig, 0, '0', 0],   // fires ON the tickler date (DocketTrak's reminder date), not 30 days before it
  // 'Publication - Opposition Period Ends' has NO template (2026-10-12): a notice on the day the period ends, never a reminder before it
];
// 2026-09-27 (the attorney: "a reminder 30 days before EACH deadline"): every real deadline type the docket holds gets a template, so none is
// silently reminder-less. Generic wording; like all templates these stay unapproved until he reads and approves each one.
$gen = fn(string $what, string $extra = '') => "REMINDER:  \n\nIn the above matter, the deadline for $what is {DUE}." . ($extra !== '' ? "  $extra" : '') . "\n\nPlease let me know your intentions at this time so that we can meet the deadline.";
$grace = 'The regular deadline has passed; the USPTO now charges a late surcharge, and after this date the rights are lost.';
foreach ([
  ['Respond to Office Action - Extended', '{FILE}: Serial No. {APP} for {MARK} - extended Office Action response due {DUE}', "REMINDER:  \n\nThe extended deadline to respond to the Office Action in the above matter is {DUE}. This deadline cannot be extended further.\n\nPlease let me know your intentions at this time so that we can meet the deadline. Failure to respond will result in abandonment of the application.", 0],
  // 2026-09-30 (XYZ003): an Office action against a REGISTRATION (Section 7/8/9/15 filing) is its own type: six months,
  // not extendable, and missing it abandons the post-registration filing (or cancels the registration), not "the application".
  [POSTREG_OA_TYPE, '{FILE}: {MARK} (Reg. No. {REG}) - Post-registration Office Action response due {DUE}', "REMINDER:  \n\nThe U.S. Trademark Office issued an Office Action concerning a post-registration filing for the above registration. A response is required by {DUE}. This deadline cannot be extended.\n\nPlease let me know your intentions at this time so that we can meet the deadline. Failure to respond will result in the filing being abandoned and, where the filing maintains the registration, the registration being cancelled.", 0],
  ['Final Response to Office Action', '{FILE}: Application {APP} - final deadline to respond to the Office Action {DUE}', "REMINDER:  \n\nThe final (fully extended) deadline to respond to the Office Action in the above matter is {DUE}. This deadline cannot be extended further, and extension fees apply.\n\nPlease let me know your intentions at this time so that we can meet the deadline.", 0],
  ['Final Response to Office Action - SEQ.REQ.DISC', '{FILE}: Application {APP} - final deadline to respond {DUE}', $gen('responding to the USPTO sequence-listing requirement', 'This deadline cannot be extended further.'), 0],
  ['Final Response to Office Action - PIER.RFI', '{FILE}: Application {APP} - final deadline to respond {DUE}', $gen('responding to the USPTO requirement for information', 'This deadline cannot be extended further.'), 0],
  ['Respond to Office Action - SEQ.REQ.DISC', '{FILE}: Application {APP} - response due {DUE}', $gen('responding to the USPTO sequence-listing requirement'), 0],
  ['Respond to Requirement for Information - PIER.RFI', '{FILE}: Application {APP} - response due {DUE}', $gen('responding to the USPTO requirement for information'), 0],
  ['Statement of Use', '{FILE}: Serial No. {APP} for {MARK} - Statement of Use due {DUE}', "REMINDER:  \n\nThe final deadline to file the Statement of Use showing that the trademark is in use is {DUE}. No further extensions are available.\n\nFailure to take action will result in abandonment of your trademark application.", 1],
  ['Maintenance Fee Due', '{FILE}: Patent {PATENT} - maintenance fee / annuity due {DUE}', $gen('the maintenance fee (annuity) on this patent', 'Please kindly pay the attached invoice in a timely manner.'), 1],
  ['3 1/2 Year Maintenance Fee Grace Period', '{FILE}: U.S. Patent {PATENT} - 3.5 year maintenance fee, last day {DUE}', $gen('the 3.5 year maintenance fee', $grace), 1],
  ['7 1/2 Year Maintenance Fee Grace Period', '{FILE}: U.S. Patent {PATENT} - 7.5 year maintenance fee, last day {DUE}', $gen('the 7.5 year maintenance fee', $grace), 1],
  ['11 1/2 Year Maintenance Fee Grace Period', '{FILE}: U.S. Patent {PATENT} - 11.5 year maintenance fee, last day {DUE}', $gen('the 11.5 year maintenance fee', $grace), 1],
  ['Section 8 Declaration Ends', '{FILE}: {MARK} (Reg. No. {REG}) - Section 8 declaration due {DUE}', $gen('the Section 8 declaration of use', 'Please send me an up-to-date picture showing how the {MARK} mark is used in commerce and pay the attached invoice.'), 1],
  ['Section 8 Declaration Grace Period Ends', '{FILE}: {MARK} (Reg. No. {REG}) - Section 8 declaration, last day {DUE}', $gen('the Section 8 declaration of use', $grace), 1],
  ['Section 8 and 15 Declarations Grace Period Ends', '{FILE}: {MARK} (Reg. No. {REG}) - Section 8 & 15, last day {DUE}', $gen('the Section 8 & 15 declarations', $grace), 1],
  ['Section 8 and 9 Grace Period Ends', '{FILE}: {MARK} (Reg. No. {REG}) - Section 8 & 9 renewal, last day {DUE}', $gen('the Section 8 & 9 renewal', $grace), 1],
  ['Section 71 Declaration Ends', '{FILE}: {MARK} (Reg. No. {REG}) - Section 71 declaration due {DUE}', $gen('the Section 71 declaration of use (Madrid registration)', 'Please send me an up-to-date picture showing how the {MARK} mark is used in commerce and pay the attached invoice.'), 1],
  ['Section 71 Declaration Grace Period Ends', '{FILE}: {MARK} (Reg. No. {REG}) - Section 71 declaration, last day {DUE}', $gen('the Section 71 declaration of use', $grace), 1],
  ['Section 71 and 15 Declarations End', '{FILE}: {MARK} (Reg. No. {REG}) - Section 71 & 15 declarations due {DUE}', $gen('the Section 71 & 15 declarations (Madrid registration)', 'Please send me an up-to-date picture showing how the {MARK} mark is used in commerce and pay the attached invoice.'), 1],
  ['Section 71 and 15 Declarations Grace Period Ends', '{FILE}: {MARK} (Reg. No. {REG}) - Section 71 & 15, last day {DUE}', $gen('the Section 71 & 15 declarations', $grace), 1],
  ['Renewal', '{FILE}: {MARK} - renewal due {DUE}', $gen('renewing this registration', 'Please kindly pay the attached invoice.'), 1],
  ['Renewal Period Ends', '{FILE}: {MARK} - renewal due {DUE}', $gen('renewing this registration', 'Please kindly pay the attached invoice.'), 1],
  ['Renewal Grace Period Ends', '{FILE}: {MARK} - renewal, last day {DUE}', $gen('renewing this registration', $grace), 1],
  ['Foreign Filing Deadline', '{FILE}: International (foreign) filing deadline {DUE}', $gen('filing for protection outside the United States', 'After this date foreign rights are lost.'), 0],
  ['Non-Provisional Deadline', '{FILE}: Provisional application {APP} - non-provisional filing deadline {DUE}', $gen('filing the non-provisional (or PCT) application', 'This deadline cannot be extended.'), 0],
] as [$type, $subj, $body, $inv]) $T[] = [$type, $subj, $body, $inv, '30,7', 0];
foreach ($T as [$type, $subj, $body, $inv, $lead, $internal])
    run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only) VALUES (?,?,?,?,?,?)', [$type, $subj, $body, $inv, $lead, $internal]);
// 2026-09-28: a client email the day a trademark maintenance window OPENS (XYZ049's Section 8 & 15 window opened with no
// email). Lead 0 = on the Begin date; {DEADLINE} is the paired End date. Invoice items, so they go out as Gmail drafts. Unapproved until he approves.
$keep = "Additionally, please remember to keep me up to date with regards to any changes in email address or physical address so I can reach you when the next fees are due.";
$win = fn(string $open, string $ask = 'Please send me an up-to-date picture showing how the {MARK} mark is used in commerce.') => "$open\n\n$ask\n\nPlease also pay the attached invoice {PAY_BY} as we must file by {DEADLINE}.\n\nPlease also kindly confirm receipt.";
foreach ([
  ['Section 8 and 15 Declarations Begin', '{FILE}: {MARK} (Reg. No. {REG}) - Section 8 & 15 window now open, due {DEADLINE}',
    $win('The above-referenced trademark has now been registered for five years, and the window to file the Section 8 and 15 declarations is now open. At this time, we must file the Section 8 Declaration of Use, together with the Section 15 Declaration of Incontestability. Filing them will keep the mark registered and make it incontestable, whereas failure to file will result in cancellation of the registration.')],
  ['Section 8 Declaration and Section 9 Renewal Begins', '{FILE}: {MARK} (Reg. No. {REG}) - ten-year renewal window now open, due {DEADLINE}',
    $win('The above-referenced trademark is approaching the ten-year anniversary of its registration, and the window to renew it is now open. Renewal will keep the mark registered for another ten years, whereas failure to renew will result in cancellation of the registration.')],
  ['Section 8 Declaration Begins', '{FILE}: {MARK} (Reg. No. {REG}) - Section 8 window now open, due {DEADLINE}',
    $win('The window to file the Section 8 declaration of use for the above-referenced trademark is now open. Filing it will keep the mark registered, whereas failure to file will result in cancellation of the registration.')],
  ['Section 71 Declaration Begins', '{FILE}: {MARK} (Reg. No. {REG}) - Section 71 window now open, due {DEADLINE}',
    $win('The window to file the Section 71 declaration of use for the above-referenced trademark (a Madrid registration) is now open. Filing it will keep the mark registered in the United States, whereas failure to file will result in cancellation of the registration.')],
  ['Section 71 and 15 Declarations Begin', '{FILE}: {MARK} (Reg. No. {REG}) - Section 71 & 15 window now open, due {DEADLINE}',
    $win('The above-referenced trademark (a Madrid registration) has now been registered in the United States for five years, and the window to file the Section 71 and 15 declarations is now open. Filing them will keep the mark registered and make it incontestable, whereas failure to file will result in cancellation of the registration.')],
  ['Renewal Period Begins', '{FILE}: {MARK} - renewal window now open, due {DEADLINE}',
    $win('The renewal window for the above-referenced trademark registration is now open. Renewal will keep the mark registered, whereas failure to renew will result in loss of the registration.', 'Please kindly confirm that you would like to renew.')],
] as [$type, $subj, $body]) run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only) VALUES (?,?,?,1,?,0)', [$type, $subj, $body, '0']);
// 2026-10-05 (XYZ002): the patent maintenance fee window opening had no template, so the send page used the generic "filing window
// opened" fallback. His Google Doc "Templates" > "Maintenance Fee Due" text, verbatim (no greeting: no name placeholder). Lead 0 = the day the
// window opens; {DEADLINE} is the paired "... Due" date. Fee fields copied from the Due template. Unapproved until he approves.
foreach (['3' => 'Doing same extends the life of the patent until the next maintenance fee is due.',
          '7' => 'Doing same extends the life of the patent until the next maintenance fee is due.',
          '11' => 'This is the last maintenance fee; doing same keeps the patent in force for the rest of its term.'] as $y => $life) {
    $type = "$y 1/2 Year Maintenance Fee Window Open";
    $body = "{SUMMARY}\n\nThe above-referenced patent has now been in force for $y.5 years. At this time, we must pay a maintenance fee to the U.S. Government to maintain your rights to the above-referenced patent. $life\n\nWe must submit between now and {DEADLINE}.\n\nPlease kindly confirm receipt, and pay the attached invoice so we can tend to this matter expeditiously.\n\n$keep";
    $f = q1('SELECT gov_fee, our_fee, fee_note FROM templates WHERE event_type=?', ["$y 1/2 Year Maintenance Fee Due"]) ?: ['gov_fee' => '', 'our_fee' => '', 'fee_note' => ''];
    if (run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only,gov_fee,our_fee,fee_note) VALUES (?,?,?,1,?,0,?,?,?)',
        [$type, "{FILE}: U.S. Patent {PATENT} - $y.5 year maintenance fee window now open, due {DEADLINE}", $body, '0', $f['gov_fee'], $f['our_fee'], $f['fee_note']])) echo "added template: $type\n";
}
// 2026-10-05: after "Extension filed" / "Statement of Use filed" on a Statement of Use deadline (his XYZ034 and XYZ004 emails).
foreach ([['Report Extension Filed', '{FILE}: {MARK_ID} - Extension of time filed', "The six month extension has been filed for your {MARK_ID} trademark application.\n\nI have docketed the following new deadline for you:\n\n{NEXT_SOU}\n\nPlease also record this date as failure to take action will result in abandonment of your trademark application.\n\nYour invoice is attached.", 1],
          ['Report Statement of Use Filed', '{FILE}: {MARK_ID} - Statement of Use filed', "The Statement of Use has been filed for your {MARK_ID} trademark application - a copy is attached. Your trademark certificate is expected within about two months.", 0]] as [$type, $subj, $body, $inv])
    if (run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only) VALUES (?,?,?,?,?,0)', [$type, $subj, $body, $inv, ''])) echo "added template: $type\n";
// 2026-10-13 (XYZ001): after "Done" on a patent issue-fee deadline with the receipt dropped in; his XYZ001 email of 2026-09-27.
if (run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only) VALUES (?,?,?,0,?,0)', ['Report Issue Fee Paid', '{FILE}: Serial #{APP} for {TITLE} - Issue Fee paid',
    "I have now reviewed the application and paid the Issue Fee for your patent application. A copy of the receipt is attached.\n\nAn Issue Notification should follow within a few weeks setting out the patent number and actual issue date. This will follow with your patent certificate. If you want to file this patent application again, seeking to get more claims approved, you must do so before the current patent application issues.\n\nCongratulations. I will be in touch again as I receive the further documents.", ''])) echo "added template: Report Issue Fee Paid\n";
// 2026-09-28: "fee paid" reports, sent from the "Maintenance fee paid / Renewal filed: report to client" button on a record.
// Never sent automatically (no events carry these types); {NEXT_FEES} = the next cycle's dates, one per line. Patent wording = his XYZ009 text.
$rec = "Please remember to keep me up to date with regards to any changes in email address or physical address so I can reach you when the next fees are due. I have recorded the dates in my system as follows though no guarantees can be made that I'll be able to send a reminder after this long period of time.  Thus please also record these dates:\n\n{NEXT_FEES}";
foreach ([
  ['Maintenance Fee Paid', '{FILE}: U.S. Patent {PATENT} - maintenance fee paid',
    "Please find attached the receipt indicating that the currently due maintenance fee has been paid to the U.S. Patent Office.\n\n$rec"],
  ['Final Maintenance Fee Paid', '{FILE}: U.S. Patent {PATENT} - final maintenance fee paid',
    "Please find attached the receipt indicating that the final maintenance fee has been paid to the U.S. Patent Office. No further maintenance fees are due; the patent will remain in force for the rest of its term.\n\nPlease remember to keep me up to date with regards to any changes in email address or physical address."],
  ['Trademark Renewal Filed', '{FILE}: {MARK} (Reg. No. {REG}) - renewal filed',
    "Please find attached the receipt indicating that the renewal for the above-referenced trademark has been filed with the U.S. Patent and Trademark Office.\n\n" . str_replace('when the next fees are due', 'when the next renewal is due', $rec)],
] as [$type, $subj, $body]) run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only) VALUES (?,?,?,0,?,0)', [$type, $subj, $body, '']);
// One-time: strip the signature that early seeds baked into bodies (it is now added per sender at send time).
run('UPDATE templates SET body=replace(body, ?, ?) WHERE body LIKE ?', [$oldsig, '', '%' . $oldsig . '%']);
// One-time (2026-09-24): "Remind Client" is a tickler, not a deadline; its wording must name the real deadline ({DEADLINE_EVENT} / {DEADLINE}).
run("UPDATE templates SET subject='{FILE}: {TITLE} - {DEADLINE_EVENT} due {DEADLINE}', body=replace(body, 'action is due by {DUE}', '{DEADLINE_EVENT} is due by {DEADLINE}') WHERE event_type='Remind Client' AND approved=0 AND body LIKE '%action is due by {DUE}%'");
// One-time (2026-09-24): a "Remind Client" tickler IS the reminder date (DocketTrak mailed on that day). Lead 30,3 meant 60 and 33 days before the real deadline, on top of the deadline's own 30/3-day reminders.
run("UPDATE templates SET lead_days='0' WHERE event_type='Remind Client' AND approved=0 AND lead_days='30,7'");
// 2026-09-27: foreign-filing and non-provisional deadlines are reminded 90 and 30 days before.
run("UPDATE templates SET lead_days='90,30' WHERE event_type IN ('Non-Provisional Filing Deadline','Non-Provisional Deadline','Foreign Filing Deadline','Remind Client International Filing Deadline') AND lead_days='30,7'");
// One-time (2026-09-27): the attorney's own wording from the "Templates" tab of his Accounts Receivable sheet (patent Notice of Allowance,
// Maintenance Fee, Foreign Filing Reminder; trademark reminder, trademark renewal 5-year and 10-year). Client-specific lines (bank accounts,
// sample names) and all fee amounts left out. Runs once (settings flag) so later edits on the Templates page are never overwritten.
if (!q1("SELECT 1 FROM settings WHERE key='tpl_sheet_2026_09_27'")) {
    $mf = fn(string $yrs, string $end) => "The above-referenced U.S. Patent {PATENT} issued $yrs years ago. As such, the maintenance fee window is now open. This fee is due to the U.S. Patent Office by {DUE}.\n\nAfter this date, the U.S. government charges a surcharge followed by marking your patent abandoned. As such, please kindly pay the invoice in a timely manner.\n\n$end";
    $sou = "Please be reminded that the Statement of Use or a six month extension is due for the above-referenced trademark by {DUE}.  Failure to take action will result in abandonment of your trademark application.\n\nYour reply is eagerly awaited.  No action will be taken without your express instructions.";
    $souLast = "Please be reminded that the Statement of Use is due for the above-referenced trademark by {DUE}. No further extensions are available.  Failure to take action will result in abandonment of your trademark application.\n\nYour reply is eagerly awaited.  No action will be taken without your express instructions.";
    $ren = fn(string $open) => "$open\n\nPlease send me an up-to-date picture showing how the {MARK} mark is used in commerce and pay the attached invoice.\n\nWe must renew between now and {DUE}.\n\nPlease kindly confirm receipt.";
    $five = $ren('The above-referenced trademark has now been registered for five years. At this time, we must file the Section 8 Declaration of Use, together with the Section 15 Declaration of Incontestability. Filing them will keep the mark registered and make it incontestable, whereas failure to file will result in cancellation of the registration.');
    $ten = $ren('The above-referenced trademark is approaching the ten-year anniversary of its registration. At this time, before the anniversary, we must file a request to renew the mark. Renewal will keep the mark registered for another ten years, whereas failure to renew will result in cancellation of the registration.');
    $ffr = "This is to remind you that the deadline to file this application in other countries, while claiming your filing date in the United States, is {DUE}.\n\nPlease send your instructions as to which countries you'd like to file this application, no later than {REPLY_BY} to ensure we can meet the deadline.";
    $new = [
      'Respond to Office Action - NOA' => "I am pleased to report that the U.S. Patent Office has allowed the above-referenced patent application. All that remains is to pay the Issue Fee by {DUE}. This deadline is not extendable.\n\nTherefore, please pay the invoice promptly, as failure to pay the issue fee on time will result in the abandonment of your patent application. Also, if desired, we can file the patent application again before this first patent issues if you want to try and get more subject matter allowed.",
      '3 1/2 Year Maintenance Fee Due' => $mf('3', 'Payment of this fee will keep your patent in force for an additional four years.'),
      '7 1/2 Year Maintenance Fee Due' => $mf('7', 'Payment of this fee will keep your patent in force for an additional four years.'),
      '11 1/2 Year Maintenance Fee Due' => $mf('11', 'Payment of this fee will keep your patent in force for its remaining term.'),
      'Remind Client International Filing Deadline' => $ffr, 'Foreign Filing Deadline' => $ffr,
      'Statement of Use or 1st Extension Due' => $sou, 'Statement of Use or 2nd Extension Due' => $sou, 'Statement of Use or 3rd Extension Due' => $sou, 'Statement of Use or 4th Extension Due' => $sou,
      'Statement of Use or 5th Extension Due' => $souLast, 'Statement of Use' => $souLast,
      'Section 8 and 15 Declarations End' => $five, 'Section 8 Declaration and Section 9 Renewal Ends' => $ten,
    ];
    foreach ($new as $type => $body) run('UPDATE templates SET body=?, approved=0, approved_by=NULL, approved_at=NULL WHERE event_type=?', [$body, $type]);
    run("UPDATE templates SET subject='{FILE}: International (foreign) filing deadline {DUE}' WHERE event_type IN ('Remind Client International Filing Deadline','Foreign Filing Deadline')");
    run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_sheet_2026_09_27', ?)", [date('Y-m-d H:i:s')]);
    echo "the attorney's sheet wording applied to " . count($new) . " templates\n";
}
// Fee defaults (USPTO schedule effective 2025-01-19, SMALL entity for patents; per class for trademarks). Only fills blanks; the attorney edits on the Templates page.
$FEES = [
  ['3 1/2 Year Maintenance Fee Due', '860', '', 'small entity; $2,150 undiscounted, $430 micro'],
  ['7 1/2 Year Maintenance Fee Due', '1616', '', 'small entity; $4,040 undiscounted, $808 micro'],
  ['11 1/2 Year Maintenance Fee Due', '3312', '', 'small entity; $8,280 undiscounted, $1,656 micro'],
  ['Respond to Office Action - NOA', '516', '525', 'issue fee, small entity; $1,290 undiscounted'],
  ['Section 8 and 15 Declarations End', '575', '', 'per class: Section 8 $325 + Section 15 $250'],
  ['Section 8 Declaration and Section 9 Renewal Ends', '650', '', 'per class: Section 8 $325 + Section 9 $325'],
  ['Statement of Use or 1st Extension Due', '150', '500', 'Statement of Use, per class; a 6-month extension instead is $125 per class'],
  ['Statement of Use or 2nd Extension Due', '150', '500', 'Statement of Use, per class; a 6-month extension instead is $125 per class'],
  ['Statement of Use or 3rd Extension Due', '150', '500', 'Statement of Use, per class; a 6-month extension instead is $125 per class'],
  ['Statement of Use or 4th Extension Due', '150', '500', 'Statement of Use, per class; a 6-month extension instead is $125 per class'],
  ['Statement of Use or 5th Extension Due', '150', '500', 'Statement of Use, per class; no further extension is available'],
  ['Non-Provisional Filing Deadline', '800', '', 'small entity utility filing + search + examination; $2,000 undiscounted'],
];
foreach ($FEES as [$type, $gov, $our, $note]) run("UPDATE templates SET gov_fee=?, our_fee=?, fee_note=? WHERE event_type=? AND gov_fee='' AND our_fee=''", [$gov, $our, $note, $type]);
// 2026-09-27 (the attorney): NO fees in reminder emails. Strip the fee placeholders from every template (approval kept: his own instruction),
// and the rendered fee line from reminders already queued but not sent.
foreach (q("SELECT event_type, body FROM templates WHERE body LIKE '%{FEES}%' OR body LIKE '%{GOV_FEE}%' OR body LIKE '%{OUR_FEE}%' OR body LIKE '%{TOTAL_FEE}%' OR body LIKE '%{FEE_NOTE}%'") as $t)
    run('UPDATE templates SET body=? WHERE event_type=?', [trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/^.*\{(FEES|GOV_FEE|OUR_FEE|TOTAL_FEE|FEE_NOTE)\}.*$\n?/m', '', $t['body']))), $t['event_type']]);
foreach (q("SELECT id, body FROM reminders WHERE status IN ('pending','held') AND (body LIKE '%Government fee:%' OR body LIKE '%Our fee:%')") as $r)
    run('UPDATE reminders SET body=? WHERE id=?', [trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/^(Government fee|Our fee):.*$\n?/m', '', $r['body']))), $r['id']]);
// 2026-09-28: the first mention of the mark in every template carries its Serial No. / Reg. No. ({MARK_ID}); approval kept.
$mk = 0; foreach (q('SELECT event_type, subject, body FROM templates') as $t) { $su = mark_id_template($t['subject']); $bo = mark_id_template($t['body']); if ($su !== $t['subject'] || $bo !== $t['body']) { run('UPDATE templates SET subject=?, body=? WHERE event_type=?', [$su, $bo, $t['event_type']]); $mk++; } }
if ($mk) echo "templates given {MARK_ID}: $mk, queued reminders re-rendered: " . refresh_queued() . "\n";
// 2026-09-28: Section 8 & 15 / 8 & 9 grace-period wording (his CPO text), sent the day after the regular deadline passes (lead GRACE_LEAD).
if (!q1("SELECT value FROM settings WHERE key='tpl_grace_2026_09_28'")) {
    $graceBody = fn(string $renew, bool $decl = false) => "The above-referenced trademark has now been registered for {REG_YEARS} years, and the deadline to " . ($decl ? 'file the Section 8 and 15 declarations' : 'renew the mark') . " between years {WINDOW_YEARS} has been missed. We are now in what is known as the \"Grace Period\" where higher government fees apply to submit the required " . ($decl ? 'declarations' : 'renewal') . ". As the Grace Period gives us an extra 6 months beyond the original deadline, the last day to " . ($decl ? 'file' : 'renew') . " is {DUE}. The Grace Period deadline cannot be extended. $renew\n\nPlease send me up-to-date pictures showing how the {MARK_ID} mark is used in commerce, as listed in your trademark registration, for the following {GOODS_WORD}:\n\n{GOODS_LIST}\n\nIn order to ensure that we have ample time to handle this matter, please provide your instruction no later than {GRACE_REPLY_BY}.\n\nNo further reminders regarding this matter will necessarily be sent.";
    foreach (['Section 8 and 15 Declarations Grace Period Ends' => ['{FILE}: {MARK_ID} - Grace Period: Section 8 & 15, last day {DUE}', 'Filing them will keep the mark registered and make it incontestable, whereas failure to file will result in cancellation of the registration.'],
              'Section 8 and 9 Grace Period Ends' => ['{FILE}: {MARK_ID} - Grace Period: Section 8 & 9 renewal, last day {DUE}', 'Renewal will keep the mark registered for another ten years, whereas failure to renew will result in cancellation of the registration.']] as $type => [$subj, $renew]) {
        run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only) VALUES (?,?,?,1,?,0)', [$type, $subj, '', (string)GRACE_LEAD]);
        run('UPDATE templates SET subject=?, body=?, lead_days=?, needs_invoice=1, approved=0, approved_by=NULL, approved_at=NULL WHERE event_type=?', [$subj, $graceBody($renew, str_contains($type, '15')), (string)GRACE_LEAD, $type]);
    }
    refresh_queued();
    run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_grace_2026_09_28', ?)", [date('Y-m-d H:i:s')]);
    echo "grace-period templates rewritten\n";
}
// 2026-09-28: US maintenance-fee / renewal DEADLINE emails (sent on or before the deadline) = "REMINDER:" + the window-opening
// email; once the deadline has passed, the grace-period email (his CPO wording) goes out the day after. Approval reset on every one.
if (!q1("SELECT value FROM settings WHERE key='tpl_deadline_grace_2026_09_28'")) {
    $reset = 'approved=0, approved_by=NULL, approved_at=NULL';
    foreach (['Section 8 and 15 Declarations End' => ['Section 8 and 15 Declarations Begin', 'Section 8 & 15 declarations due {DUE}'],
              'Section 8 Declaration and Section 9 Renewal Ends' => ['Section 8 Declaration and Section 9 Renewal Begins', 'ten-year renewal due {DUE}'],
              'Section 8 Declaration Ends' => ['Section 8 Declaration Begins', 'Section 8 declaration due {DUE}'],
              'Section 71 Declaration Ends' => ['Section 71 Declaration Begins', 'Section 71 declaration due {DUE}'],
              'Section 71 and 15 Declarations End' => ['Section 71 and 15 Declarations Begin', 'Section 71 & 15 declarations due {DUE}']] as $type => [$open, $what]) {
        $o = q1('SELECT body FROM templates WHERE event_type=?', [$open]); if (!$o) continue;
        $body = "REMINDER:\n\n" . preg_replace('/^\s*REMINDER:\s*/', '', str_replace('{DEADLINE}', '{DUE}', $o['body']));
        run("UPDATE templates SET subject=?, body=?, $reset WHERE event_type=?", ['{FILE}: {MARK_ID} - REMINDER: ' . $what, $body, $type]);
    }
    foreach (['3 1/2' => '3.5', '7 1/2' => '7.5', '11 1/2' => '11.5'] as $k => $yrs) {
        $d = q1('SELECT body FROM templates WHERE event_type=?', ["$k Year Maintenance Fee Due"]); if (!$d) continue;
        run("UPDATE templates SET subject=?, body=?, $reset WHERE event_type=?", ["{FILE}: U.S. Patent {PATENT} - REMINDER: $yrs year maintenance fee due {DUE}", "REMINDER:\n\n" . preg_replace('/^\s*REMINDER:\s*/', '', $d['body']), "$k Year Maintenance Fee Due"]);
        $next = $yrs === '11.5' ? 'keep the patent in force for the rest of its term' : 'extend the life of the patent until the next maintenance fee is due';
        $g = "The above-referenced patent has now been in force for $yrs years, and the deadline to pay the $yrs year maintenance fee, {MISSED_DEADLINE}, has been missed. We are now in what is known as the \"Grace Period\" where the U.S. Patent Office charges a surcharge to pay the maintenance fee. As the Grace Period gives us an extra 6 months beyond the original deadline, the last day to pay is {DUE}. The Grace Period deadline cannot be extended. Paying the fee will $next, whereas failure to pay will result in expiration of the patent.\n\nPlease kindly confirm that you would like to pay the maintenance fee, and pay the attached invoice.\n\nIn order to ensure that we have ample time to handle this matter, please provide your instruction no later than {GRACE_REPLY_BY}.\n\nNo further reminders regarding this matter will necessarily be sent.";
        run("UPDATE templates SET subject=?, body=?, lead_days=?, needs_invoice=1, $reset WHERE event_type=?", ["{FILE}: U.S. Patent {PATENT} - Grace Period: $yrs year maintenance fee, last day {DUE}", $g, (string)GRACE_LEAD, "$k Year Maintenance Fee Grace Period"]);
    }
    $tmGrace = fn(string $what, string $keep) => "The above-referenced trademark has now been registered for {REG_YEARS} years, and the deadline to $what between years {WINDOW_YEARS} has been missed. We are now in what is known as the \"Grace Period\" where higher government fees apply to submit the required filing. As the Grace Period gives us an extra 6 months beyond the original deadline, the last day to file is {DUE}. The Grace Period deadline cannot be extended. $keep\n\nPlease send me up-to-date pictures showing how the {MARK_ID} mark is used in commerce, as listed in your trademark registration, for the following {GOODS_WORD}:\n\n{GOODS_LIST}\n\nIn order to ensure that we have ample time to handle this matter, please provide your instruction no later than {GRACE_REPLY_BY}.\n\nNo further reminders regarding this matter will necessarily be sent.";
    foreach (['Section 8 Declaration Grace Period Ends' => ['Section 8 declaration', 'file the Section 8 declaration of use', 'Filing it will keep the mark registered, whereas failure to file will result in cancellation of the registration.'],
              'Section 71 Declaration Grace Period Ends' => ['Section 71 declaration', 'file the Section 71 declaration of use (a Madrid registration)', 'Filing it will keep the mark registered in the United States, whereas failure to file will result in cancellation of the registration.'],
              'Section 71 and 15 Declarations Grace Period Ends' => ['Section 71 & 15 declarations', 'file the Section 71 and 15 declarations (a Madrid registration)', 'Filing them will keep the mark registered and make it incontestable, whereas failure to file will result in cancellation of the registration.']] as $type => [$short, $what, $keep])
        run("UPDATE templates SET subject=?, body=?, lead_days=?, needs_invoice=1, $reset WHERE event_type=?", ["{FILE}: {MARK_ID} - Grace Period: $short, last day {DUE}", $tmGrace($what, $keep), (string)GRACE_LEAD, $type]);
    refresh_queued();
    run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_deadline_grace_2026_09_28', ?)", [date('Y-m-d H:i:s')]);
    echo "deadline = REMINDER + opening text; grace-period templates rewritten\n";
}
// 2026-09-28 (XYZ008): the picture request names the registered goods/services: "... used in commerce for {GOODS_INLINE} and pay ...".
if (!q1("SELECT value FROM settings WHERE key='tpl_goods_inline_2026_09_28'")) {
    $k = 0; foreach (q("SELECT event_type, body FROM templates WHERE event_type LIKE 'Section %' AND (event_type LIKE '%Begin%' OR event_type LIKE '%End' OR event_type LIKE '%Ends') AND event_type NOT LIKE '%Grace%'") as $t) {
        $b = preg_replace('/(mark is used in commerce) and pay the attached invoice/', '$1 for {GOODS_INLINE} and pay the attached invoice', $t['body'], 1, $c);
        if ($c) { run('UPDATE templates SET body=? WHERE event_type=?', [$b, $t['event_type']]); $k++; }
    }
    refresh_queued(); run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_goods_inline_2026_09_28', ?)", [date('Y-m-d H:i:s')]);
    echo "goods/services added to $k window-open / deadline templates\n";
}
// 2026-09-28: trademark renewal emails list the goods the way the grace email does ("At least one of: ..." per class).
if (!q1("SELECT value FROM settings WHERE key='tpl_goods_list_2026_09_28'")) {
    $k = 0; foreach (q("SELECT event_type, body FROM templates WHERE body LIKE '%{GOODS_INLINE}%'") as $t) {
        $b = preg_replace('/Please send me an? up[- ]to[- ]date picture showing how the (\{MARK(?:_ID)?\}) mark is used in commerce for \{GOODS_INLINE\} and pay the attached invoice\./',
            "Please send me up-to-date pictures showing how the $1 mark is used in commerce, as listed in your trademark registration, for the following {GOODS_WORD}:\n\n{GOODS_LIST}\n\nPlease also pay the attached invoice.", $t['body'], 1, $c);
        if ($c) { run('UPDATE templates SET body=? WHERE event_type=?', [$b, $t['event_type']]); $k++; }
    }
    refresh_queued(); run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_goods_list_2026_09_28', ?)", [date('Y-m-d H:i:s')]);
    echo "goods list (At least one of) put in $k renewal templates\n";
}
// 2026-09-29: Notice of Allowance report (his XYZ002 wording; mark in color; fee quote by class count; USPTO notice forwarded).
run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only) VALUES (?,?,?,0,?,0)', [NOA_TEMPLATE, '{FILE}: {MARK_ID} - Notice of Allowance',
    "Congratulations! The trademark office has issued a Notice of Allowance for:\n\n<b>U.S. Serial Number:</b> {APP}\n<b>Mark:</b> {MARK_COLOR}\n<b>Docket/Reference Number:</b> {FILE}\n<b>Statement of Use Deadline:</b> {DUE}\n\nNo opposition was filed during the publication period.\n\nNow that we have passed through the examination and publication stage we need to submit a \"Statement of Use\" (or six-month extension) showing that the trademark is in use by <b>{DUE}</b>.\n\nSee {$TL} for more information.\n\nPlease send:\n\n 1) the date the {GOODS_WORD} were first offered for sale; and\n\n 2) pictures showing your brand name used, as listed in your trademark application, for the following {GOODS_WORD}:\n\n{GOODS_LIST}\n\n{NOA_FEES} Failure to file a Statement of Use or Extension will result in abandonment of your trademark application.\n\nAwaiting your instructions.", '']);
// 2026-09-29: no blank line between "1)" and "2)" in the Notice of Allowance report.
run("UPDATE templates SET body=replace(body, ?, ?) WHERE event_type=?", ["first offered for sale; and\n\n 2)", "first offered for sale; and\n 2)", NOA_TEMPLATE]);
// 2026-09-29: "make it easier to understand and look nicer" — same facts and fees, short labelled sections.
if (!q1("SELECT value FROM settings WHERE key='tpl_noa_v2_2026_09_29'")) {
    run('UPDATE templates SET subject=?, body=?, approved=0, approved_by=NULL, approved_at=NULL WHERE event_type=?', ['{FILE}: {MARK_ID} - Notice of Allowance',
        "Congratulations! The U.S. Trademark Office has allowed your {MARK_COLOR} trademark. No one opposed it during the publication period. A copy of the Notice of Allowance is attached.\n\n<b>Mark:</b> {MARK_COLOR}\n<b>U.S. Serial Number:</b> {APP}\n<b>Our file number:</b> {FILE}\n<b>Statement of Use deadline:</b> {DUE}\n\n<b>What happens next</b>\nBefore the mark can be registered, we must file a \"Statement of Use\" showing the Trademark Office how you are using it, by <b>{DUE}</b>. If you are not using the mark yet, we can file a six-month extension instead.\n\n<b>What we need from you</b>\n 1) the date the {GOODS_WORD} were first offered for sale; and\n 2) pictures showing your brand name used, as listed in your trademark application, for the following {GOODS_WORD}:\n\n{GOODS_LIST}\n\n<b>Fees</b>\n{NOA_FEES}\n\nFailure to file a Statement of Use or Extension will result in abandonment of your trademark application.\n\nFor the full timeline, see {$TL}\n\nAwaiting your instructions.", NOA_TEMPLATE]);
    run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_noa_v2_2026_09_29', ?)", [date('Y-m-d H:i:s')]);
    echo "Notice of Allowance template rewritten (v2)\n";
}
// 2026-09-29: goods lead-in says "(at least one for each class)" in every template; NOA "mark yet" -> "mark by the deadline".
if (!q1("SELECT value FROM settings WHERE key='tpl_goods_each_class_2026_09_29'")) {
    $k = 0; foreach (q("SELECT event_type, body FROM templates WHERE body LIKE '%{GOODS_LIST}%'") as $t) {
        $b = preg_replace('/for the following \{GOODS_WORD\}:/', 'for the following {GOODS_WORD} (at least one for each class):', $t['body']);
        $b = str_replace('If you are not using the mark yet,', 'If you are not using the mark by the deadline,', $b);
        if ($b !== $t['body']) { run('UPDATE templates SET body=? WHERE event_type=?', [$b, $t['event_type']]); $k++; }
    }
    refresh_queued(); run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_goods_each_class_2026_09_29', ?)", [date('Y-m-d H:i:s')]);
    echo "goods lead-in updated in $k templates\n";
}
// 2026-09-29 ("B"): a labelled summary (mark / number / our file number / deadline) at the top of every client email, and a
// "GRACE PERIOD:" marker on the grace-period emails. Approvals kept (his decision covers all templates).
if (!q1("SELECT value FROM settings WHERE key='tpl_summary_2026_09_29'")) {
    $k = 0; $graceTypes = array_merge(GRACE_EMAIL_TYPES, ['Renewal Grace Period Ends']);
    foreach (q('SELECT event_type, body, internal_only FROM templates') as $t) {
        if ($t['internal_only'] || $t['event_type'] === NOA_TEMPLATE || str_contains($t['body'], '{SUMMARY}')) continue;
        $b = ltrim($t['body']); $head = '';
        if (preg_match('/^REMINDER:[ \t]*\R+/', $b, $mm)) { $head = "REMINDER:\n\n"; $b = substr($b, strlen($mm[0])); }
        elseif (in_array($t['event_type'], $graceTypes, true)) $head = "GRACE PERIOD:\n\n";
        run('UPDATE templates SET body=? WHERE event_type=?', [$head . "{SUMMARY}\n\n" . ltrim($b), $t['event_type']]); $k++;
    }
    refresh_queued(); run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_summary_2026_09_29', ?)", [date('Y-m-d H:i:s')]);
    echo "summary block added to $k templates\n";
}
// 2026-09-29: every deadline missed → application abandoned: how to revive, fees will be due, tell us ASAP, no follow-up reminders.
foreach ([
    [ABANDON_TEMPLATES['trademark'], '{FILE}: {MARK_ID} - deadline missed, application abandoned',
     "{SUMMARY}\n\nThe deadline for the {MISSED_ACTION} in the above-referenced trademark application was {DEADLINE}. That deadline has now passed without a filing. As a result, the application has been abandoned by the U.S. Patent and Trademark Office.\n\nThe application can still be revived. To do so, we must file a Petition to Revive together with the {MISSED_ACTION} and a statement that the delay was unintentional. The petition must be filed within two months of the date the USPTO issues its Notice of Abandonment. If that window is missed, the only option is to file a new application, which would lose the original filing date.\n\nReviving the application requires a USPTO petition fee, as well as our fee for preparing the petition and the {MISSED_ACTION}. We will send an invoice once you confirm that you would like to proceed.\n\nSince the application is now abandoned, please let us know as soon as possible whether you would like to revive it.\n\nNo further reminders regarding this matter are scheduled."],
    [ABANDON_TEMPLATES['patent'], '{FILE}: {TITLE} - deadline missed, application abandoned',
     "{SUMMARY}\n\nThe deadline for the {MISSED_ACTION} in the above-referenced patent application was {DEADLINE}. That deadline, including all available extensions of time, has now passed. As a result, the application is abandoned.\n\nThe application can still be revived. To do so, we must file a petition to revive an unintentionally abandoned application together with the {MISSED_ACTION} and a statement that the entire delay was unintentional. The petition should be filed promptly: the longer the delay, the more closely the U.S. Patent and Trademark Office examines whether it was unintentional, and a petition filed more than two years after abandonment requires an additional explanation.\n\nReviving the application requires a USPTO petition fee, as well as our fee for preparing the petition and the {MISSED_ACTION}. We will send an invoice once you confirm that you would like to proceed.\n\nSince the application is now abandoned, please let us know as soon as possible whether you would like to revive it.\n\nNo further reminders regarding this matter are scheduled."],
] as [$type, $subj, $body]) run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only) VALUES (?,?,?,0,?,0)', [$type, $subj, $body, '']);
// 2026-10-01: USPTO notices reported to the client from the "Report ..." to-do intake creates. Publication = the paralegal's verbatim
// wording (XYZ001, XYZ002); acceptance = new. Seeded unapproved: the attorney or staff approves each once.
foreach ([
  ['Report Notice of Publication', '{FILE}: {MARK_ID} - approved for publication',
     "I am pleased to report that your {MARK_ID} trademark application{FILED_CLAUSE} has passed through the examination phase at the U.S. Trademark Office and has been approved by the examining attorney.\n\nThis concludes the \"internal\" review process. The mark has proceeded to an \"external\" review process where third parties will have a chance to oppose the mark. The opposition period {PUB_STARTS} on {PUB_DATE} and will run for 30 days from that date. Third party oppositions are relatively rare, but they can happen.\n\n{PUB_NEXT}\n\nPlease be reminded that there are additional USPTO and law firm fees in connection with the above.\n\nA copy of the \"Notice of Publication\" is below my signature."],
  ['Report Notice of Acceptance', '{FILE}: {MARK_ID} - declaration accepted',
     "I am pleased to report that the U.S. Trademark Office has accepted the declaration filed to maintain your {MARK_ID} trademark registration. The registration remains in force.\n\n{NEXT_WINDOW}\n\nPlease remember to keep me up to date with regards to any changes in email address or physical address so I can reach you when the next filing is due.\n\nA copy of the \"Notice of Acceptance\" is below my signature."],
  ['Report Statement of Use Accepted', '{FILE}: {MARK_ID} - Statement of Use accepted',
     "I am pleased to report that the U.S. Trademark Office has accepted the Statement of Use filed for your {MARK_ID} trademark application. The mark will now register, and the registration certificate will issue in due course. I will send it to you when it arrives.\n\nA copy of the \"Notice of Acceptance\" is below my signature."],
] as [$type, $subj, $body]) run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only) VALUES (?,?,?,0,?,0)', [$type, $subj, $body, '0']);
// 2026-10-03: receipts (lib/held.php). "Plain old acceptance of something: save in file, email to report to client which will be in
// Pendant for me to verify." Seeded unapproved: approved once, and each email still waits for someone to click Send.
foreach ([
  // 2026-10-03 (XYZ064 report): "your trademark renewal documents", no filing date, and his standard next-renewal paragraph with the dates.
  ['Report Filing Receipt', '{FILE}: {MARK_ID} - filing received by the U.S. Trademark Office',
     "{SUMMARY}\n\nThe U.S. Trademark Office has confirmed receipt of {YOUR_FILING} for the above-referenced trademark. I will report to you when the Trademark Office acts on the filing.\n\n{NEXT_RENEWAL}\n\nA copy of the filing receipt is below my signature."],
  ['Report Maintenance Fee Paid', '{FILE}: U.S. Patent {PATENT} - maintenance fee paid',
     "The {FEE_YEAR}maintenance fee for U.S. Patent No. {PATENT} has been paid, and the U.S. Patent Office has confirmed receipt. The patent remains in force.\n\n{NEXT_WINDOW}\n\nA copy of the payment confirmation is below my signature."],
  // Assignments (2026-10-03): filed = his XYZ003 email of 2026-09-28; recorded = staff's XYZ003 / REL001 emails. The PDFs are attached.
  ['Report Assignment Filed', '{FILE}: {MARK_ID} - assignment filed for recordation',
     "The assignment of the above-referenced {PROPERTY} has been filed with the U.S. Patent Office for recordation, under Assignment ID {ASSIGNMENT_ID}. A copy of the filing is attached.\n\nOnce the U.S. Patent Office records the transfer, its records will list the new owner. I will confirm when that happens."],
  ['Report Assignment Recorded', '{FILE}: {MARK_ID} - assignment recorded',
     "The transfer of ownership of the above-referenced {PROPERTY} has now been recorded at the U.S. Patent Office at reel/frame {REEL_FRAME}. Please keep the attached Notice of Recordation, as well as the Assignment Cover Sheet, as evidence of the ownership transfer.\n\nThis completes the assignment."],
] as [$type, $subj, $body]) run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only) VALUES (?,?,?,0,?,0)', [$type, $subj, $body, '0']);
// 2026-10-03: the first receipt wording was replaced the same day; an unapproved copy already on the server takes the new text.
run('UPDATE templates SET subject=?, body=? WHERE event_type=? AND approved=0 AND body LIKE ?', ['{FILE}: {MARK_ID} - filing received by the U.S. Trademark Office',
    "{SUMMARY}\n\nThe U.S. Trademark Office has confirmed receipt of {YOUR_FILING} for the above-referenced trademark. I will report to you when the Trademark Office acts on the filing.\n\n{NEXT_RENEWAL}\n\nA copy of the filing receipt is below my signature.", 'Report Filing Receipt', '%{FILED_DATE}%']);
// 2026-10-02: patent issuance report, sent once the Issue Notification is read from the file. Utility = the firm's standing
// Issue Notification email (XYZ001, sent 2026-08-24; XYZ010) with the docketed renewal table; design = XYZ001 (2026-09-28).
$issueTpl = [
  // 2026-10-02: foreign annuities, reminders 60 and 30 days before; his Brazil wording of 2026-07-08 (XYZ005/007/018), with
  // the invoice attached (so it is a Gmail draft, like every invoice-bearing reminder).
  ['Annuity Due', '{FILE}: Maintenance fee due {DUE}',
     "{SUMMARY}\n\nThe next maintenance fee (annuity) for the above-referenced patent is due by {DUE}.\n\nMy invoice to renew is attached.  Please pay {PAY_BY}"],
  // 2026-10-02: New filing. Patent = his "Initial Filing" wording (Google Doc), as sent on REL001 / DEC020; trademark = his
  // CHL001 email of 2026-09-29, with headings. The summary card above each is added at send time (filing_card_html).
  ['Report Patent Filing', '{FILE}: U.S. Patent Application {APP} - Filing Report',
     "Congratulations!  Your patent application has been filed at the U.S. Patent Office and given serial no. {APP}.  My full report and the initial receipts from the Patent Office are attached.\n\nPlease read the report, as it has important information about your patent application, and I try to keep it short and easy to read."],
  ['Report Trademark Filing', '{FILE}: Trademark application filed for {MARK_ID}',
     "Congratulations - the following trademark application has been filed for you.  The filing receipt from the U.S. Trademark Office is attached.\n\n<b>Checking status</b>\nYou can check the status of your application at any time here: {STATUS_LINK}\n\nIt is also recommended to review an overview of the trademark process at: {$TL}\n\n<b>What happens next</b>\nThe current examination backlog is about {TM_BACKLOG} months. This internal review by the U.S. Trademark Office is then followed by an external review (3rd parties can oppose). Fees for these steps only happen if there is a rejection or a third party opposition to which we must respond. Until a trademark registration certificate is received, you should use the \"TM\" symbol next to your mark; once registered you can and should switch to ®.\n\n{ITU_NEXT}\n\n<b>Foreign filing</b>\nWe can also file this trademark application in other countries claiming today's filing date. However, to do this we must file within six months. Please let me know if you're interested in foreign filing."],
  ['Report Patent Issuance', '{FILE}: U.S. Patent {PATENT_NO}{FOR_TITLE}',
     "Congratulations!\n\nWe are pleased to report that the above-referenced patent has been granted. Please find attached the patent grant and our formal letter with details for maintaining your patent rights."],
  ['Report Issue Notification', '{FILE}: U.S. Patent {PATENT_NO}{FOR_TITLE}',
     "I am pleased to attach a copy of the Issue Notification document received from the U.S. Patent Office. The document indicates that your patent application will mature into U.S. Patent {PATENT_NO} on {ISSUE_DATE}.\n\nCongratulations! I will be in touch soon after issuance with a full report on the issuance of your patent.\n\nThe patent will be in force for four years. In order to keep the patent in force for its full 20-year term, please be advised of the following renewal window which we have docketed for you:\n\n{MAINT_TABLE}\n\nPlease also docket these dates on your own calendar in case, for whatever reason, our reminders do not reach you."],
  ['Report Issue Notification (Design)', '{FILE}: U.S. Patent {PATENT_NO}{FOR_TITLE}',
     "I am pleased to attach a copy of the Issue Notification document received from the U.S. Patent Office. The document indicates that your patent application will mature into U.S. Patent {PATENT_NO} on {ISSUE_DATE}.\n\nCongratulations. I will be in touch again as I receive the further documents."],
];
// 2026-10-04: client email once the opposition period has ended (sent by hand from the "Publication - Opposition Period Ends" event,
// greyed "Too early to send" until the day after); {OPP_NEXT} = Notice of Allowance (intent to use) or registration in due course.
$issueTpl[] = ['Report Opposition Period Ended', '{FILE}: {MARK_ID} - opposition period ended',
     "Congratulations - the opposition period for your trademark application ended on {DUE}.  Your application has now passed both the examination by the U.S. Trademark Office and the public review period during which third parties could oppose it.\n\n<b>What happens next</b>\n{OPP_NEXT}\n\n<b>Checking status</b>\nYou can check the status of your application at any time here: {STATUS_LINK}"];
foreach ($issueTpl as [$type, $subj, $body]) run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only) VALUES (?,?,?,0,?,0)', [$type, $subj, $body, '0']);
run("UPDATE templates SET lead_days='60,30', needs_invoice=1 WHERE event_type='Annuity Due' AND lead_days='0'");   // foreign annuities: reminders 60 and 30 days before, invoice attached (2026-10-02)
// One-time (2026-10-02): the first seed used the XYZ049 wording, which the attorney rejected; replace it unless someone has edited or approved it since.
if (!q1("SELECT value FROM settings WHERE key='tpl_issue_han001_2026_10_02'")) {
    $k = 0; foreach ($issueTpl as [$type, $subj, $body]) $k += run("UPDATE templates SET subject=?, body=? WHERE event_type=? AND approved=0 AND (body LIKE '%three times over its life%' OR body LIKE '%Design patents require no maintenance fees%')", [$subj, $body, $type]);
    refresh_queued(); run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_issue_han001_2026_10_02', ?)", [date('Y-m-d H:i:s')]);
    echo "issue notification templates replaced: $k\n";
}
// 2026-09-30: trademark maintenance close = "Please also pay the attached invoice {PAY_BY} as we must file by <deadline>.\n\n
// Please also kindly confirm receipt." ({PAY_BY} = 30 days from today, or "as soon as possible," under 30 days); address paragraph dropped.
if (!q1("SELECT value FROM settings WHERE key='tpl_pay_by_2026_09_30'")) {
    $k = 0; foreach (q("SELECT event_type, body FROM templates WHERE body LIKE '%We must file between now and {%'") as $t) {
        $b = str_replace('Please kindly confirm that you would like to renew, and pay the attached invoice.', "Please kindly confirm that you would like to renew.\n\nPlease also pay the attached invoice.", $t['body']);
        $b = preg_replace('/Please also pay the attached invoice\.\s*\n\s*\nWe must file between now and (\{(?:DEADLINE|DUE)\})\.\s*\n\s*\nPlease kindly confirm receipt\.(?:\s*\n\s*\nAdditionally, please remember to keep me up to date[^\n]*)?/',
            'Please also pay the attached invoice {PAY_BY} as we must file by $1.' . "\n\nPlease also kindly confirm receipt.", $b, 1, $c);
        if ($c) { run('UPDATE templates SET body=? WHERE event_type=?', [$b, $t['event_type']]); $k++; }
    }
    refresh_queued(); run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_pay_by_2026_09_30', ?)", [date('Y-m-d H:i:s')]);
    echo "pay-by close put in $k trademark templates\n";
}
echo "templates: " . q1('SELECT count(*) c FROM templates')['c'] . ", with fees: " . q1("SELECT count(*) c FROM templates WHERE gov_fee<>'' OR our_fee<>''")['c'] . "\n";

// One-time (2026-10-04): the trademark filing report's next-steps paragraph now follows the filing basis ({ITU_NEXT}), unless edited or approved since.
if (!q1("SELECT value FROM settings WHERE key='tpl_itu_next_2026_10_04'")) {
    $k = run("UPDATE templates SET body=replace(body, ?, '{ITU_NEXT}') WHERE event_type='Report Trademark Filing' AND approved=0 AND instr(body, ?) > 0", ['If all goes well, you will receive a Notice of Allowance setting out a six month window to file a Statement of Use (typically $500/class) showing the U.S. Trademark Office how you are using the mark. If we filed the mark showing use already, then the mark will, instead, go to issuance at this point (typically $525/mark). The fees paid to date cover only the actual filing and not these subsequent steps.', 'If all goes well, you will receive a Notice of Allowance setting out a six month window to file a Statement of Use (typically $500/class) showing the U.S. Trademark Office how you are using the mark. If we filed the mark showing use already, then the mark will, instead, go to issuance at this point (typically $525/mark). The fees paid to date cover only the actual filing and not these subsequent steps.']);
    run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_itu_next_2026_10_04', ?)", [date('Y-m-d H:i:s')]);
    echo "filing report next-steps paragraph made basis-aware: $k\n";
}

// One-time (2026-10-04, lib/pubreport.php): the publication report names the filing date and its next step follows the filing basis
// ({FILED_CLAUSE}, {PUB_NEXT}), unless the template was edited or approved since.
if (!q1("SELECT value FROM settings WHERE key='tpl_pub_next_2026_10_04'")) {
    $k = run("UPDATE templates SET body=replace(replace(body, ?, ?), ?, '{PUB_NEXT}') WHERE event_type='Report Notice of Publication' AND approved=0 AND instr(body, ?) > 0 AND instr(body, ?) > 0",
        ['your {MARK_ID} trademark application has passed through the examination phase', 'your {MARK_ID} trademark application{FILED_CLAUSE} has passed through the examination phase', "Otherwise, a few weeks after the opposition period ends, we will receive one of:\n\n- a \"Notice of Allowance\" setting out a six month deadline to show how you are using the mark if we filed the mark on an \"intent to use basis\"; or\n\n- your trademark registration certificate.", 'your {MARK_ID} trademark application has passed through the examination phase', "Otherwise, a few weeks after the opposition period ends, we will receive one of:\n\n- a \"Notice of Allowance\" setting out a six month deadline to show how you are using the mark if we filed the mark on an \"intent to use basis\"; or\n\n- your trademark registration certificate."]);
    run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_pub_next_2026_10_04', ?)", [date('Y-m-d H:i:s')]);
    echo "publication report made basis-aware (filing date + next step): $k\n";
}

// 2026-10-04 (lib/regreport.php): trademark registration report. staff's covering email (XYZ001, 2026-09-22: certificate + the paralegal's
// issuance letter attached) with the attorney's XYZ002 additions (2026-09-04: the Section 8 window, the (R) sentence). Seeded UNAPPROVED.
run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only) VALUES (?,?,?,0,?,0)', [REG_REPORT, '{FILE}: {MARK_ID} - Trademark Registration',
    "Congratulations! We are pleased to report that the above-referenced trademark has been registered. Attached, please find the Registration Certificate{LETTER_CLAUSE}.\n\n{REG_BLOCK}\n\n{REG_MAINT}\n\nYou should now use the \u{00AE} symbol with the mark, which is what allows you to collect maximum damages from infringers.", '0']);

// One-time (2026-10-04 overnight audit): typos and a missing summary block, in UNAPPROVED templates only (an approved template's wording is
// the attorney's; editing it would withdraw the approval).
if (!q1("SELECT value FROM settings WHERE key='tpl_audit_fixes_2026_10_04'")) {
    $n = 0;
    foreach ([['Respond to Office Action', 'Please review my earlier-sent email let me know', 'Please review my earlier-sent email and let me know'],
              ['3 1/2 Year Maintenance Fee Due', 'Doing same extends', 'Doing so extends'],
              ['11 1/2 Year Maintenance Fee Due', 'doing same keeps', 'doing so keeps'],
              ['PostReg Office Action - Response Required', "REMINDER:  \n\nThe U.S. Trademark Office issued an Office Action concerning", "REMINDER:\n\n{SUMMARY}\n\nThe U.S. Trademark Office issued an Office Action concerning"]] as [$type, $from, $to])
        $n += run('UPDATE templates SET body=replace(body, ?, ?) WHERE event_type=? AND approved=0 AND instr(body, ?) > 0', [$from, $to, $type, $from]);
    run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_audit_fixes_2026_10_04', ?)", [date('Y-m-d H:i:s')]);
    echo "overnight audit template fixes: $n\n";
}

// One-time (2026-10-05: "fix" the Section 8 wording): a Section 8 & 15 filing is a declaration of use + incontestability, not a
// renewal, and a missed Section 8 (or 8 & 9) filing cancels the registration; it is not "abandonment of the mark". Applied to approved
// templates too, keeping their approval (the attorney asked for this change). Section 71 & 15 templates already say it this way.
if (!q1("SELECT value FROM settings WHERE key='tpl_sec8_wording_2026_10_05'")) {
    $n = 0; $s815 = ['Section 8 and 15 Declarations Begin', 'Section 8 and 15 Declarations End', 'Section 8 and 15 Declarations Grace Period Ends'];
    $s89 = ['Section 8 Declaration and Section 9 Renewal Begins', 'Section 8 Declaration and Section 9 Renewal Ends', 'Section 8 and 9 Grace Period Ends'];
    $rep = function (array $types, string $from, string $to) use (&$n) { foreach ($types as $ty) $n += run('UPDATE templates SET body=replace(body, ?, ?) WHERE event_type=? AND instr(body, ?) > 0', [$from, $to, $ty, $from]); };
    $rep($s815, 'At this time, we must file a request to renew the mark.', 'At this time, we must file the Section 8 Declaration of Use, together with the Section 15 Declaration of Incontestability.');
    $rep($s815, 'Renewal will make the mark incontestable, whereas failure to renew will result in abandonment of the mark.', 'Filing them will keep the mark registered and make it incontestable, whereas failure to file will result in cancellation of the registration.');
    $rep(['Section 8 and 15 Declarations Grace Period Ends'], 'the deadline to renew the mark between years', 'the deadline to file the Section 8 and 15 declarations between years');
    $rep(['Section 8 and 15 Declarations Grace Period Ends'], 'to submit the required renewal.', 'to submit the required declarations.');
    $rep(['Section 8 and 15 Declarations Grace Period Ends'], 'the last day to renew is', 'the last day to file is');
    $rep($s89, 'whereas failure to renew will result in abandonment of the mark.', 'whereas failure to renew will result in cancellation of the registration.');
    run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_sec8_wording_2026_10_05', ?)", [date('Y-m-d H:i:s')]);
    echo "Section 8 wording fixed: $n template update(s)\n";
}

// 2026-10-04 (RWG127.003.003): Notice of Allowance on a Hague design (35/ series) - picked by tpl_type() instead of the utility wording:
// no maintenance fees on a design, and the issue fee (set by the international registration date) may be paid through WIPO instead.
run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only,gov_fee,our_fee,fee_note) VALUES (?,?,?,1,?,0,?,?,?)', [HAGUE_NOA_TEMPLATE,
    '{FILE}: Application {APP} - Issue fee due {DUE}',
    "{SUMMARY}\n\nWe are pleased to report that the U.S. Patent Office has allowed the U.S. designation of the above-referenced international design registration. Please find the Notice of Allowance attached. All that remains is to pay the Issue Fee by {DUE}. This deadline is not extendable.\n\nThe Issue Fee may be paid directly to the U.S. Patent Office, which we will handle for you, or through the International Bureau (WIPO) as the second part of the U.S. individual designation fee. Unless you advise that it will be paid through WIPO, please pay the attached invoice in a timely manner, as failure to pay the issue fee on time will result in abandonment of the U.S. designation.\n\nU.S. design patents do not require maintenance fees, so this is expected to be the final invoice for this case.",
    '30,7', '296', '525', 'issue fee, small entity, per the Notice of Allowance (amount set by the international registration date; check the NOA)']);
// 2026-10-04: patent Office Action reports (his verbatim wording; lib/oareport.php). Sent from the "Report Office Action" to-do's Send
// button with the saved PDF attached; {EXAMINER_BLOCK} = examiner + statistics sources (utility only), {PAY_BY} = two months before the deadline.
// Seeded unapproved (manual sends are fine); a patent NOA report uses the existing "Respond to Office Action - NOA" template.
foreach (oa_report_seeds() as [$type, $subj, $body, $inv]) run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only) VALUES (?,?,?,?,?,0)', [$type, $subj, $body, $inv, '0']);
// 2026-10-04: "any notice from the USPTO for patents must be processed!" - the generic client email for a 'Process USPTO Notice'
// to-do (lib/oareport.php): {NOTICE} = the paper's name, {NOTICE_NEXT} = its deadline when the same paper set one. Unapproved, no invoice.
[$ntType, $ntSubj, $ntBody] = notice_todo_seed();
if (run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only) VALUES (?,?,?,0,?,0)', [$ntType, $ntSubj, $ntBody, '0'])) echo "template seeded: $ntType\n";
// 2026-10-06 (RWG166.001.01): a provisional's filing receipt - the 12-month non-provisional / foreign-filing deadline, and contact us
// four months before it (lib/oareport.php PROV_RECEIPT_TEMPLATE). Unapproved, no invoice.
if (run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only) VALUES (?,?,?,0,?,0)', ['Report Provisional Filing Receipt',
    '{FILE}: U.S. Provisional Patent Application {APP} - {TITLE} - Filing Receipt',
    "Please find attached the official filing receipt from the U.S. Patent Office for the above-referenced provisional patent application, filed on {FILED}.\n\nA provisional application is not examined and expires twelve months after filing. To keep the benefit of this filing date, a non-provisional application and/or foreign (PCT) applications must be filed by {NONPROV_DEADLINE}. This deadline cannot be extended.\n\nPlease contact us by {CONTACT_BY}, four months before the deadline, to allow enough time for research and preparation of the non-provisional and/or foreign filings.",
    '0'])) echo "template seeded: Report Provisional Filing Receipt\n";

// One-time (2026-10-04, XYZ004): the Issue Notification email lists only the first cycle (to the 3 1/2-year grace period) and asks the
// client to docket the dates too. Unless edited or approved since.
if (!q1("SELECT value FROM settings WHERE key='tpl_issue_first_cycle_2026_10_04'")) {
    $k = run("UPDATE templates SET body=replace(body, ?, ?) WHERE event_type='Report Issue Notification' AND approved=0 AND instr(body, ?) > 0",
        ['please be advised of the following renewal windows which we have docketed for you:' . "\n\n{MAINT_TABLE}", 'please be advised of the following renewal window which we have docketed for you:' . "\n\n{MAINT_TABLE}\n\nPlease also docket these dates on your own calendar in case, for whatever reason, our reminders do not reach you.", 'renewal windows which we have docketed for you:']);
    refresh_queued(); run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_issue_first_cycle_2026_10_04', ?)", [date('Y-m-d H:i:s')]);
    echo "issue notification template: first cycle only + docket-it-yourself line: $k\n";
}

// One-time (2026-10-04, XYZ004 "make it more pretty"): summary card, bold patent number, a heading, and the renewal table as a styled
// table ({MAINT_TABLE} -> <maint>). Replaces the unapproved utility Issue Notification wording; an approved/edited template is left alone.
if (!q1("SELECT value FROM settings WHERE key='tpl_issue_pretty_2026_10_04'")) {
    $k = run("UPDATE templates SET body=? WHERE event_type='Report Issue Notification' AND approved=0 AND instr(body, 'renewal window which we have docketed for you') > 0", ["{SUMMARY}\n\nCongratulations! I am pleased to attach a copy of the Issue Notification received from the U.S. Patent Office. It confirms that your patent application will mature into <b>U.S. Patent {PATENT_NO}</b> on {ISSUE_DATE}. I will be in touch soon after issuance with a full report on your patent.\n\n<b>Keeping your patent in force</b>\nThe patent will be in force for four years. To keep it in force for its full 20-year term, maintenance fees are due 3½, 7½ and 11½ years after issuance. The first set of dates are as follows:\n\n{MAINT_TABLE}\n\nWe have docketed to send you reminders before each deadline. Please also docket these dates on your own calendar in case, for whatever reason, our reminders do not reach you."]);
    refresh_queued(); run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_issue_pretty_2026_10_04', ?)", [date('Y-m-d H:i:s')]);
    echo "issue notification template made pretty: $k\n";
}

// 2026-10-04: "We have docketed the first renewal window for you:" -> "The first set of dates are as follows:" (unapproved template only).
if (!q1("SELECT value FROM settings WHERE key='tpl_issue_first_set_2026_10_04'")) {
    $k = run("UPDATE templates SET body=replace(body, 'We have docketed the first renewal window for you:', 'The first set of dates are as follows:') WHERE event_type='Report Issue Notification' AND approved=0");
    refresh_queued(); run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_issue_first_set_2026_10_04', ?)", [date('Y-m-d H:i:s')]);
    echo "issue notification: first-set wording $k\n";
}

// 2026-10-04: the design Issue Notification says the term (15 years from issuance) and that no maintenance fees are due; same look as
// the utility version (summary card, bold patent number). Unapproved template only.
if (!q1("SELECT value FROM settings WHERE key='tpl_issue_design_2026_10_04'")) {
    $k = run("UPDATE templates SET body=? WHERE event_type='Report Issue Notification (Design)' AND approved=0", ["{SUMMARY}\n\nCongratulations! I am pleased to attach a copy of the Issue Notification received from the U.S. Patent Office. It confirms that your design patent application will mature into <b>U.S. Patent {PATENT_NO}</b> on {ISSUE_DATE}. I will be in touch again as I receive the further documents.\n\nThe patent is expected to be in force for 15 years from the date of issuance. No maintenance fees are required for same."]);
    refresh_queued(); run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_issue_design_2026_10_04', ?)", [date('Y-m-d H:i:s')]);
    echo "design issue notification wording: $k\n";
}

// 2026-10-04: never promise reminders ("if we don't, for some reason, we're liars") - say they are docketed. Any unapproved template.
if (!q1("SELECT value FROM settings WHERE key='tpl_no_reminder_promise_2026_10_04'")) {
    $k = run("UPDATE templates SET body=replace(body, 'We will send you reminders before each deadline.', 'We have docketed to send you reminders before each deadline.') WHERE approved=0 AND instr(body, 'We will send you reminders') > 0");
    refresh_queued(); run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_no_reminder_promise_2026_10_04', ?)", [date('Y-m-d H:i:s')]);
    echo "reminder promise reworded: $k\n";
}

// 2026-10-04 (XYZ001 / XYZ019): the patent Notice of Allowance email in the Issue Notification's look (summary card, Congratulations first,
// bold section headings); a separate US design version (no maintenance fees, 15-year term). Unapproved templates only.
if (!q1("SELECT value FROM settings WHERE key='tpl_noa_pretty_2026_10_04'")) {
    $k = run("UPDATE templates SET body=? WHERE event_type='Respond to Office Action - NOA' AND approved=0", ["{SUMMARY}\n\nCongratulations! I am pleased to report that the U.S. Patent Office has allowed the above-referenced patent application. A copy of the Notice of Allowance is attached.\n\n<b>What happens next</b>\nAll that remains is to pay the Issue Fee by {DUE}. This deadline is not extendable, and failure to pay the issue fee on time will result in abandonment of your patent application. Please pay the attached invoice in a timely manner so that we can pay the fee for you.\n\n<b>Optional: seeking more claims</b>\nIf desired, we can file a further patent application before the above-referenced patent issues, to attempt getting more subject matter allowed. Please let me know promptly if this is of interest.\n\nThis is expected to be the final invoice for this case until the patent has to be renewed in four years."]);
    $f = q1("SELECT gov_fee, our_fee, fee_note, lead_days FROM templates WHERE event_type='Respond to Office Action - NOA'") ?: ['gov_fee' => '', 'our_fee' => '', 'fee_note' => '', 'lead_days' => '30,7'];
    run('INSERT OR IGNORE INTO templates(event_type,subject,body,needs_invoice,lead_days,internal_only,gov_fee,our_fee,fee_note) VALUES (?,?,?,1,?,0,?,?,?)', [DESIGN_NOA_TEMPLATE, '{FILE}: Application {APP} - Issue fee due {DUE}', "{SUMMARY}\n\nCongratulations! I am pleased to report that the U.S. Patent Office has allowed the above-referenced design patent application. A copy of the Notice of Allowance is attached.\n\n<b>What happens next</b>\nAll that remains is to pay the Issue Fee by {DUE}. This deadline is not extendable, and failure to pay the issue fee on time will result in abandonment of your design patent application. Please pay the attached invoice in a timely manner so that we can pay the fee for you.\n\nOnce issued, the patent is expected to be in force for 15 years from the date of issuance. No maintenance fees are required for same, so this is expected to be the final invoice for this case.", $f['lead_days'], $f['gov_fee'], $f['our_fee'], 'design issue fee: check the Notice of Allowance']);
    refresh_queued(); run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_noa_pretty_2026_10_04', ?)", [date('Y-m-d H:i:s')]);
    echo "patent NOA email restyled: $k; design NOA template added\n";
}

// 2026-10-07: "The current examination backlog is about six months." -> the live USPTO figure ({TM_BACKLOG}, as on the timeline card).
if (!q1("SELECT value FROM settings WHERE key='tpl_tm_backlog_2026_10_07'")) {
    $k = run("UPDATE templates SET body=replace(body, 'The current examination backlog is about six months.', 'The current examination backlog is about {TM_BACKLOG} months.') WHERE event_type='Report Trademark Filing'");
    refresh_queued(); run("INSERT OR REPLACE INTO settings(key,value) VALUES ('tpl_tm_backlog_2026_10_07', ?)", [date('Y-m-d H:i:s')]);
    echo "trademark filing report backlog: $k\n";
}
