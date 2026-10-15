<?php
declare(strict_types=1);
require_once __DIR__ . '/reconcile.php';   // patent_term_end() and the date reconciliation (audit 2026-10-02)
require_once __DIR__ . '/oareport.php';    // patent Office Action / NOA client-report to-dos (2026-10-04); here so every intake path queues them
/**
 * Docketing rules: what an incoming USPTO document creates, and what closes what.
 * Weekend/holiday convention, measured against 14,000 DocketTrak-computed dates (2026-09-23):
 *   PATENTS    roll to the next business day (37 CFR 1.7)   - maintenance due/grace and response periods.
 *   TRADEMARKS stay on the calendar date                    - post-registration windows and response periods.
 * "Window Open" / "Begins" dates are never rolled (they are starts, not deadlines).
 * Every rule returns a list of [type, due_date, note]. Event type names match the DocketTrak vocabulary
 * so the imported history and the new feed line up.
 */
// Patent Center document codes → events. months = shortened statutory period; max = statutory maximum with extensions.
const PATENT_DOC_RULES = [
    'CTNF'          => ['label' => 'Non-final rejection',              'months' => 3, 'max' => 6, 'type' => 'Respond to Office Action - CTNF'],
    'CTFR'          => ['label' => 'Final rejection',                  'months' => 3, 'max' => 6, 'type' => 'Respond to Office Action - CTFR'],
    'CTRS'          => ['label' => 'Restriction requirement',          'months' => 2, 'max' => 6, 'type' => 'Respond to Office Action - CTRS'],
    'CTEQ'          => ['label' => 'Ex parte Quayle action',           'months' => 2, 'max' => 6, 'type' => 'Respond to Office Action - CTEQ'],
    'NTC.A.NONCPL'  => ['label' => 'Notice of non-compliant amendment','months' => 2, 'max' => 6, 'type' => 'Respond to Office Action - NTC.A.NONCPL'],
    'NTC.MISS.PRT'  => ['label' => 'Notice to file missing parts',     'months' => 2, 'max' => 7, 'type' => 'Respond to Office Action - NTC.MISS.PRT'],
    'NTC.PRT.CRR'   => ['label' => 'Notice to file corrected parts',   'months' => 2, 'max' => 6, 'type' => 'Respond to Office Action - NTC.PRT.CRR'],
    'NOA'           => ['label' => 'Notice of allowance (issue fee)',  'months' => 3, 'max' => 0, 'type' => 'Respond to Office Action - NOA'],
    'CTAV'          => ['label' => 'Advisory action',                  'months' => 0, 'max' => 0, 'type' => ''],
    'ABN'           => ['label' => 'Notice of abandonment',            'months' => 0, 'max' => 0, 'type' => ''],   // 2026-10-01: matter abandoned + inactive, one client email (apply_item → mark_abandoned); no revival deadline
    'PATENT.GRANT'  => ['label' => 'Patent grant',                     'months' => 0, 'max' => 0, 'type' => 'GRANT'],
    'EGRANT.NTF'    => ['label' => 'eGrant notification (patent granted)', 'months' => 0, 'max' => 0, 'type' => 'GRANT'],   // the issue-day eGrant e-mail (XYZ010, 2026-09-08)
    'ISSUE.NTF'     => ['label' => 'Issue notification',               'months' => 0, 'max' => 0, 'type' => 'Expected Issue Date'],
    'NOTICE.OF.PUB' => ['label' => 'Notice of publication',            'months' => 0, 'max' => 0, 'type' => ''],
    'APP.FILE.REC'  => ['label' => 'Filing receipt',                   'months' => 0, 'max' => 0, 'type' => ''],
    'M327'          => ['label' => 'Power of attorney',                'months' => 0, 'max' => 0, 'type' => ''],
    // 2026-09-30: notices whose reply period varies. Docketed at 2 months on arrival; bin/period_check.php then reads the
    // period off pages 1-3 of the notice (OCR when it is a scan) and re-dates the event. The note carries PERIOD_UNVERIFIED until then.
    'SEQ.REQ.DISC'  => ['label' => 'Sequence listing requirement',     'months' => 2, 'max' => 0, 'type' => 'Respond to Office Action - SEQ.REQ.DISC', 'verify' => true],
    'NT.INC.REPLY'  => ['label' => 'Notice of incomplete reply',       'months' => 2, 'max' => 0, 'type' => 'Respond to Office Action - NT.INC.REPLY', 'verify' => true],
    'NRES'          => ['label' => 'Notice regarding response',        'months' => 2, 'max' => 0, 'type' => 'Respond to Office Action - NRES',         'verify' => true],
    'PIER.RFI'      => ['label' => 'Requirement for information',      'months' => 2, 'max' => 0, 'type' => 'Respond to Office Action - PIER.RFI',     'verify' => true],
    'PETDEC'        => ['label' => 'Petition decision',                'months' => 2, 'max' => 0, 'type' => 'Respond to Office Action - PETDEC',       'verify' => true],
    'PET.DEC.OIPE'  => ['label' => 'Petition decision (OIPE)',         'months' => 2, 'max' => 0, 'type' => 'Respond to Office Action - PET.DEC.OIPE', 'verify' => true],
    'PET.DEC.PUBS'  => ['label' => 'Petition decision (Publications)', 'months' => 2, 'max' => 0, 'type' => 'Respond to Office Action - PET.DEC.PUBS', 'verify' => true],
    'PEFN'          => ['label' => 'Petition decision',                'months' => 2, 'max' => 0, 'type' => 'Respond to Office Action - PEFN',         'verify' => true],
];
const PERIOD_UNVERIFIED = '[period unverified]';
// 2026-09-30: papers that never carry a deadline (were landing in "review manually").
const PATENT_NO_DEADLINE = ['892' => 'References cited', 'WELCOME.LET' => 'Welcome letter', 'EXIN' => 'Examiner interview summary', '1449' => 'IDS considered',
    'OA.APPENDIX' => 'Office action appendix', 'PREDOCK.NTF' => 'Pre-docketing notice', 'M903' => 'DO/EO acceptance', 'TRACK1.GRANT' => 'Track One granted',
    'TRACK1.DENY' => 'Track One denied', 'PPH.DECISION' => 'PPH decision', 'COCOUT' => 'Certificate of correction', 'CDEN' => 'Certificate of correction denied',
    'NTC.PUB' => 'Notice of publication', 'NTC.PUB.DATE' => 'Notice of publication date', 'N570' => 'Notice (N570)', 'N572' => 'Notice (N572)',
    'MAINT FEE PAID' => 'Maintenance fee payment receipt (Fee Storefront)', 'ASSIGNMENT RECORDED' => 'Assignment recorded (Notice of Recordation)', 'ASSIGNMENT FILED' => 'Assignment filed for recordation (Assignment Center receipt)'];   // lib/held.php: receipt saved, the fee proposed paid, client email queued
// Which open event types a given document code satisfies (auto-undocket candidates).
const PATENT_CLOSES = [
    'NOA'          => ['Respond to Office Action - CTNF', 'Respond to Office Action - CTFR', 'Respond to Office Action - CTRS', 'Final Response to Office Action', 'Respond to Office Action - CTEQ'],
    'CTNF'         => ['Respond to Office Action - CTRS', 'Respond to Office Action - NTC.MISS.PRT', 'Respond to Office Action - CTFR', 'Final Response to Office Action'],
    'CTFR'         => ['Respond to Office Action - CTNF', 'Final Response to Office Action'],
    'ISSUE.NTF'    => ['Respond to Office Action - NOA'],
    'PATENT.GRANT' => ['Respond to Office Action - NOA', 'Expected Issue Date'],
    'EGRANT.NTF'   => ['Respond to Office Action - NOA', 'Expected Issue Date'],
    'ABN'          => ['*'],
];
// Office action against a registration's post-registration filing (Section 7/8/9/15/71). The response period is READ FROM THE ACTION ITSELF
// (2026-10-01; enrich_item fetches it from TSDR): XYZ003 6/2025 "must be received within 6 months from the issuance date", XYZ003
// 4/2026 "The owner has six months from the issuance date of this letter". Not read: 6 months, tagged for checking (37 CFR 2.163(b), 2.176).
// 2026-09-30: its own type, not "Respond to Office Action" (whose wording says the APPLICATION is abandoned).
const POSTREG_OA_TYPE = 'PostReg Office Action - Response Required';
const TTAB_SCHEDULE_TYPES = ['TTAB: Time to Answer', 'TTAB: Deadline for Discovery Conference', 'TTAB: Discovery Opens', 'TTAB: Initial Disclosures Due', 'TTAB: Expert Disclosures Due',
    'TTAB: Discovery Closes', "TTAB: Plaintiff's Pretrial Disclosures Due", "TTAB: Plaintiff's 30-day Trial Period Ends", "TTAB: Defendant's Pretrial Disclosures Due",
    "TTAB: Defendant's 30-day Trial Period Ends", "TTAB: Plaintiff's Rebuttal Disclosures Due", "TTAB: Plaintiff's 15-day Rebuttal Period Ends", "TTAB: Plaintiff's Opening Brief Due",
    "TTAB: Defendant's Brief Due", "TTAB: Plaintiff's Reply Brief Due", 'TTAB: Request for Oral Hearing (optional) Due'];
const TM_CLOSES = [
    'POSTREG OFFICE ACTION' => [POSTREG_OA_TYPE],
    'TTAB EXT OPPOSE'       => ['Publication - Opposition Period Ends'],
    'NOTICE OF REVIVAL'     => ['Petition to Revive'],
    'TTAB DEFAULT'          => ['TTAB: Time to Answer'],
    'TTAB ORDER'            => TTAB_SCHEDULE_TYPES,   // a reset schedule supersedes the earlier dates
    'NOTICE OF PUBLICATION' => ['Respond to Office Action', 'Respond to Office Action - Extended'],
    'NOTICE OF ALLOWANCE'   => ['Publication - Opposition Period Ends'],
    'REGISTRATION'          => ['Statement of Use or 1st Extension Due', 'Statement of Use or 2nd Extension Due', 'Statement of Use or 3rd Extension Due', 'Statement of Use or 4th Extension Due', 'Statement of Use or 5th Extension Due', 'Statement of Use', 'Publication - Opposition Period Ends'],
    'OFFICE ACTION'         => ['Respond to Office Action', 'Respond to Office Action - Extended'],
    'APPROVAL OF EXTENSION REQUEST' => ['Statement of Use or 1st Extension Due', 'Statement of Use or 2nd Extension Due', 'Statement of Use or 3rd Extension Due', 'Statement of Use or 4th Extension Due', 'Statement of Use or 5th Extension Due'],
];

/** US design application: 29/ series, the new 30/ series, 35/ = Hague designations of the US; or typed Design. The import typed many 30/ and 35/ as Utility. */
function is_design_matter(array $m): bool { return (bool)preg_match('/^(29|30|35)\d{6}$/', preg_replace('/\D/', '', (string)($m['app_no'] ?? ''))) || stripos((string)($m['app_type'] ?? ''), 'design') !== false; }
/** Design term: 15 years from grant if filed on/after 2015-05-13 (PLTIA), else 14. Unknown filing date: judged by a grant before 2016-05-13. */
function design_term_years(array $m, string $grant): int { $f = substr((string)($m['filing_date'] ?? ''), 0, 10); return ($f !== '' ? $f < '2015-05-13' : $grant < '2016-05-13') ? 14 : 15; }
function events_for_patent_doc(string $code, string $mailDate, array $matter): array {
    $r = PATENT_DOC_RULES[$code] ?? null; $out = [];
    if (isset(PATENT_NO_DEADLINE[$code])) return [['type' => '', 'due' => '', 'note' => PATENT_NO_DEADLINE[$code] . ' received; no deadline']];
    // Unknown code: better a 2-month deadline to check than a silent miss (2026-09-30); the period check reads the real one.
    if (!$r) $r = ['label' => "USPTO paper $code", 'months' => 2, 'max' => 0, 'type' => "Respond to Office Action - $code", 'verify' => true];
    if ($r['type'] === 'GRANT') {
        // Design patents have no maintenance fees and run 15 years from grant (35 USC 173).
        if (is_design_matter($matter)) { $y = design_term_years($matter, $mailDate); return [['type' => 'Patent Expires', 'due' => add_months($mailDate, $y * 12, false), 'note' => "Design patent: $y years from grant; no maintenance fees fall due"]]; }
        // Maintenance windows run from the grant date. Window opens at 3/7/11 years, due at 3.5/7.5/11.5, grace ends 6 months later.
        foreach ([3 => '3 1/2', 7 => '7 1/2', 11 => '11 1/2'] as $y => $lbl) {
            $out[] = ['type' => "$lbl Year Maintenance Fee Window Open", 'due' => add_months($mailDate, $y * 12, false), 'note' => ''];
            $out[] = ['type' => "$lbl Year Maintenance Fee Due",         'due' => add_months($mailDate, $y * 12 + 6),      'note' => ''];
            $out[] = ['type' => "$lbl Year Maintenance Fee Grace Period", 'due' => add_months($mailDate, $y * 12 + 12),    'note' => 'Surcharge applies after the due date'];
        }
        // Audit 2026-10-02: the term runs from the earliest US non-provisional filing claimed (a continuation's parent), not always this
        // application's own filing date; patent term adjustment is added by the nightly check once the USPTO record shows it.
        $pj = json_decode((string)($matter['priority_json'] ?? ''), true);
        if ($te = patent_term_end($matter, $mailDate, is_array($pj) ? (array)($pj['parents'] ?? []) : [])) $out[] = ['type' => 'Patent Expires', 'due' => $te[0], 'note' => $te[1]];
        return $out;
    }
    if ($r['months'] > 0) $out[] = ['type' => $r['type'], 'due' => add_months($mailDate, $r['months']), 'note' => $r['label'] . ' mailed ' . fmt_date($mailDate) . (!empty($r['verify']) ? ' ' . PERIOD_UNVERIFIED . ' 2 months until the period is read from the notice' : ($r['max'] ? '' : ' (non-extendable)'))];
    if ($r['max'] > 0)    $out[] = ['type' => 'Final Response to Office Action', 'due' => add_months($mailDate, $r['max']), 'note' => 'Statutory maximum with extensions'];
    // US patents issue on Tuesdays, the second Tuesday after the issue notification is mailed (DocketTrak reconciliation 2026-10-02:
    // XYZ017 / XYZ001 mailed 09-23 issue 10-06, XYZ004 mailed 09-30 issues 10-13; the old "+21 days" landed on a Wednesday a week late).
    if ($code === 'ISSUE.NTF') $out[] = ['type' => 'Expected Issue Date', 'due' => issue_tuesday($mailDate), 'note' => 'ESTIMATE (second Tuesday after the issue notification); replaced by the date on the Issue Notification once it is saved in the file'];
    if ($r['months'] === 0 && $r['max'] === 0 && !$out) $out[] = ['type' => '', 'due' => '', 'note' => $r['label'] . ' received; no deadline created'];
    return $out;
}
function events_for_tm_notice(string $kind, string $date, array $matter, array $item = []): array {
    $out = []; $ordw = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th'];
    switch ($kind) {
        case 'APPROVAL OF EXTENSION REQUEST':   // $date = the NOA date; the Nth extension granted means the (N+1)th request (or the SOU) is due 6(N+1) months after the NOA
            $n = (int)($item['n'] ?? 0);
            if ($n < 1 || $n > 5) { $out[] = ['type' => '', 'due' => '', 'note' => 'Extension approved but the request number was not found: review manually']; break; }
            $out[] = $n >= 5 ? ['type' => 'Statement of Use', 'due' => add_months($date, 36, false), 'note' => '5th extension granted: the SOU is the last filing, 36 months from the NOA of ' . fmt_date($date)]
                             : ['type' => 'Statement of Use or ' . $ordw[$n + 1] . ' Extension Due', 'due' => add_months($date, 6 * ($n + 1), false), 'note' => $ordw[$n] . ' extension granted; NOA issued ' . fmt_date($date)];
            break;
        case 'NOTICE OF PUBLICATION':
            $out[] = ['type' => 'Publication - Opposition Period Ends', 'due' => add_days($date, 30, false), 'note' => 'Published for opposition ' . fmt_date($date)]; break;
        case 'OFFICE ACTION':
            if (str_starts_with(preg_replace('/\D/', '', (string)($item['app_no'] ?? $matter['app_no'] ?? '')), '79')) {   // 66(a) Madrid: six months, no extension (37 CFR 2.62(a)(2))
                $out[] = ['type' => 'Respond to Office Action - Madrid', 'due' => add_months($date, 6, false), 'note' => 'Office action issued ' . fmt_date($date) . ' (Madrid 66(a): 6 months, not extendable)']; break;
            }
            $out[] = ['type' => 'Respond to Office Action', 'due' => add_months($date, 3, false), 'note' => 'Office action issued ' . fmt_date($date)];
            $out[] = ['type' => 'Respond to Office Action - Extended', 'due' => add_months($date, 6, false), 'note' => 'With one 3-month extension ($125)']; break;
        // TTAB (2026-09-30). $item['order_text'] = the TTABVUE order PDF text, fetched by ttab_enrich(); DocketTrak's "TTAB: <row>" names.
        // Board deadlines are fixed dates in the order; fallbacks (no PDF) are the Board's standard periods, tagged for checking.
        case 'TTAB INSTITUTED': case 'TTAB ORDER':
            $rows = ttab_schedule((string)($item['order_text'] ?? ''));
            foreach ($rows as $name => $due) if ($due >= $date) $out[] = ['type' => 'TTAB: ' . $name, 'due' => $due, 'note' => 'TTAB order of ' . fmt_date($date) . ' (proceeding ' . ($item['pno'] ?? '?') . ')'];
            if (!$out && $kind === 'TTAB INSTITUTED') $out[] = ['type' => 'TTAB: Time to Answer', 'due' => add_days($date, 60), 'note' => 'Proceeding ' . ($item['pno'] ?? '?') . ' instituted ' . fmt_date($date) . ' ' . PERIOD_UNVERIFIED . ' 60 days; order PDF not read - check TTABVUE'];
            if (!$out) $out[] = ['type' => 'TTAB: Review Order', 'due' => $date, 'note' => 'TTAB order of ' . fmt_date($date) . ' (proceeding ' . ($item['pno'] ?? '?') . ') sets no schedule: read it on TTABVUE'];
            break;
        case 'TTAB EXT OPPOSE':   // the opposition period for this application now ends on the granted date (whichever side we are on)
            $until = preg_match('/granted until\s+(\d{1,2}\/\d{1,2}\/\d{4})/i', (string)($item['order_text'] ?? ''), $u) && ($ts = strtotime($u[1])) !== false ? date('Y-m-d', $ts) : null;
            $out[] = $until ? ['type' => 'Publication - Opposition Period Ends', 'due' => $until, 'note' => 'Time to oppose extended by TTAB order of ' . fmt_date($date)]
                            : ['type' => 'Publication - Opposition Period Ends', 'due' => add_days($date, 30, false), 'note' => 'Time to oppose extended by TTAB order of ' . fmt_date($date) . ' ' . PERIOD_UNVERIFIED . ' 30 days; order PDF not read - check TTABVUE'];
            break;
        case 'TTAB DEFAULT':   // "allowed until thirty days from the date of this order to show cause"
            $out[] = ['type' => 'TTAB: Show Cause (Notice of Default)', 'due' => add_days($date, 30), 'note' => 'Notice of default ' . fmt_date($date) . ' (proceeding ' . ($item['pno'] ?? '?') . ')']; break;
        case 'MADRID REFUSAL':   // $item['time_limit'] from the attached decision (madrid_enrich): ISO date, or months/days from the notification
            $lim = $item['time_limit'] ?? null; $what = 'Respond to Provisional Refusal - ' . ($item['country'] ?? 'Madrid');
            if (is_string($lim)) $out[] = ['type' => $what, 'due' => $lim, 'note' => 'IR ' . ($item['ir'] ?? '') . ': time limit stated in the refusal of ' . fmt_date($date) . '; needs a local agent'];
            elseif (is_array($lim)) $out[] = ['type' => $what, 'due' => isset($lim['months']) ? add_months($date, $lim['months'], false) : add_days($date, $lim['days'], false), 'note' => 'IR ' . ($item['ir'] ?? '') . ': ' . (isset($lim['months']) ? $lim['months'] . ' months' : $lim['days'] . ' days') . ' from the notification (counted from the WIPO e-mail ' . fmt_date($date) . ' - check); needs a local agent'];
            else $out[] = ['type' => $what, 'due' => add_months($date, 2, false), 'note' => 'IR ' . ($item['ir'] ?? '') . ' ' . PERIOD_UNVERIFIED . ' 2 months; time limit not read from the decision - check it'];
            break;
        case 'INCOMPLETE RESPONSE':   // notice that a response was incomplete / an amendment unresponsive: the notice states the time left
            $out[] = ['type' => str_starts_with(preg_replace('/\D/', '', (string)($item['app_no'] ?? '')), '79') ? 'Respond to Office Action - Madrid' : 'Respond to Office Action', 'due' => add_months($date, 1, false), 'note' => 'Notice of incomplete / unresponsive response ' . fmt_date($date) . ' ' . PERIOD_UNVERIFIED . ' 1 month; the notice states the time remaining - check it']; break;
        case "EXAMINER'S AMENDMENT": case 'SUSPENSION': case 'COURTESY REMINDER':   // 2026-09-30: no deadline (the Section 8/9 windows are already docketed from the registration date)
            $out[] = ['type' => '', 'due' => '', 'note' => ucwords(strtolower($kind)) . ' received; nothing to docket']; break;
        case 'NOTICE OF REVIVAL':   // 2026-09-30: trademarks only - re-docket what the abandonment closed
            foreach (tm_revival_events($matter, $date) as $e) $out[] = $e;
            if (!$out) $out[] = ['type' => '', 'due' => '', 'note' => 'Revived ' . fmt_date($date) . ': back to the examining attorney (the petition carried the response); nothing to docket'];
            break;
        case 'POSTREG OFFICE ACTION':
            $pm = (int)($item['period_months'] ?? 0);
            $out[] = $pm >= 1 && $pm <= 6
                ? ['type' => POSTREG_OA_TYPE, 'due' => add_months($date, $pm, false), 'note' => 'Post-registration Office action issued ' . fmt_date($date) . ' (' . $pm . ' month' . ($pm === 1 ? '' : 's') . ', as stated in the Office action)']
                : ['type' => POSTREG_OA_TYPE, 'due' => add_months($date, 6, false), 'note' => 'Post-registration Office action issued ' . fmt_date($date) . ' ' . PERIOD_UNVERIFIED . ' 6 months; the period was not read from the Office action - check it'];
            break;
        case 'NOTICE OF ALLOWANCE':
            $out[] = ['type' => 'Statement of Use or 1st Extension Due', 'due' => add_months($date, 6, false), 'note' => 'NOA issued ' . fmt_date($date)];
            // 2026-09-29: a to-do on the dashboard, dated the day the allowance issued, until the client report goes out.
            $out[] = ['type' => 'Report Notice of Allowance', 'due' => $date, 'note' => 'Email the client: Report Notice of Allowance']; break;
        case 'REGISTRATION':
            $sn8 = fn($x) => strlen($d = preg_replace('/\D/', '', (string)$x)) === 8 ? $d : '';   // the notice may carry the 7-digit registration number instead of the serial
            if (str_starts_with($sn8($matter['app_no'] ?? '') ?: $sn8($item['app_no'] ?? ''), '79')) {   // Madrid 66(a): Section 71 declarations, no Section 9 renewal at the USPTO (the international registration is renewed at WIPO)
                $out[] = ['type' => 'Section 71 and 15 Declarations Begin',            'due' => add_months($date, 60, false), 'note' => ''];
                $out[] = ['type' => 'Section 71 and 15 Declarations End',              'due' => add_months($date, 72, false), 'note' => ''];
                $out[] = ['type' => 'Section 71 and 15 Declarations Grace Period Ends','due' => add_months($date, 78, false), 'note' => 'Surcharge applies'];
                foreach ([9, 19, 29] as $y) {
                    $out[] = ['type' => 'Section 71 Declaration Begins',            'due' => add_months($date, $y * 12, false),      'note' => ''];
                    $out[] = ['type' => 'Section 71 Declaration Ends',              'due' => add_months($date, $y * 12 + 12, false), 'note' => 'The international registration itself is renewed at WIPO'];
                    $out[] = ['type' => 'Section 71 Declaration Grace Period Ends', 'due' => add_months($date, $y * 12 + 18, false), 'note' => 'Surcharge applies'];
                }
                break;
            }
            $out[] = ['type' => 'Section 8 and 15 Declarations Begin',            'due' => add_months($date, 60, false), 'note' => ''];
            $out[] = ['type' => 'Section 8 and 15 Declarations End',              'due' => add_months($date, 72, false), 'note' => ''];
            $out[] = ['type' => 'Section 8 and 15 Declarations Grace Period Ends','due' => add_months($date, 78, false), 'note' => 'Surcharge applies'];
            foreach ([9, 19, 29] as $y) {   // 10-, 20- and 30-year renewals, as DocketTrak dockets them
                $out[] = ['type' => 'Section 8 Declaration and Section 9 Renewal Begins', 'due' => add_months($date, $y * 12, false),      'note' => ''];
                $out[] = ['type' => 'Section 8 Declaration and Section 9 Renewal Ends',   'due' => add_months($date, $y * 12 + 12, false), 'note' => ''];
                $out[] = ['type' => 'Section 8 and 9 Grace Period Ends',                  'due' => add_months($date, $y * 12 + 18, false), 'note' => 'Surcharge applies'];
            }
            break;
        case 'APPLICATION FILED':   // the filing receipt: the matter is created in apply_item; no deadline yet (2026-10-02)
            $out[] = ['type' => '', 'due' => '', 'note' => 'Application filed ' . fmt_date($date) . '; nothing to docket until the USPTO acts']; break;
        case 'ASSIGNMENT FILED':   // Assignment Center confirmation (lib/held.php)
            $out[] = ['type' => '', 'due' => '', 'note' => 'Assignment filed for recordation ' . fmt_date($date) . '; nothing to docket']; break;
        case 'ASSIGNMENT RECORDED':   // PTAS Notice of Recordation (lib/held.php): saved into the file, client email queued
            $out[] = ['type' => '', 'due' => '', 'note' => 'Assignment recorded ' . fmt_date($date) . '; nothing to docket']; break;
        case 'FILING RECEIPT':   // TEAS / Trademark Center receipt (lib/held.php, 2026-10-03): the papers are saved, the deadline proposed done, the client told
            $out[] = ['type' => '', 'due' => '', 'note' => ($item['summary'] ?? 'Filing receipt') . ' (' . fmt_date($date) . '); nothing to docket']; break;
        case 'NOTICE OF ACCEPTANCE':   // nothing to docket (2026-09-27)
            $out[] = ['type' => '', 'due' => '', 'note' => 'Notice of acceptance received; nothing to docket (client email queued)']; break;
        case 'NOTICE OF ABANDONMENT':
            $out[] = ['type' => '', 'due' => '', 'note' => 'Abandonment notice ' . fmt_date($date) . ': matter marked abandoned and inactive; one client email queued for the next day']; break;   // 2026-10-01
        default:
            $out[] = ['type' => '', 'due' => '', 'note' => "$kind received; no deadline rule, review manually"];
    }
    return $out;
}
/**
 * Deadlines to re-docket when a trademark application is revived (2026-09-30). Abandoned at the Statement-of-Use stage (a SOU-chain
 * deadline was missed): the next extension/SOU deadline counted from the Notice of Allowance, the first one still ahead of the revival date
 * (the petition carried an extension or the SOU - checked by hand). Abandoned during examination: nothing (the petition carried the response).
 * Deadlines closed only because the matter was marked dead are reopened by apply_item().
 */
function tm_revival_events(array $matter, string $date): array {
    if (empty($matter['id']) || !function_exists('q')) return [];
    $chain = q('SELECT * FROM events WHERE matter_id=? AND (type LIKE "Statement of Use or %Extension Due" OR type="Statement of Use") ORDER BY due_date', [$matter['id']]);
    if (!$chain || array_filter($chain, fn($e) => $e['status'] === 'open' && $e['due_date'] >= $date)) return [];
    $first = array_values(array_filter($chain, fn($e) => str_starts_with($e['type'], 'Statement of Use or 1st')));
    $noa = $first ? add_months($first[0]['due_date'], -6, false) : (q1('SELECT mail_date FROM correspondence WHERE matter_id=? AND doc_code="NOTICE OF ALLOWANCE" ORDER BY mail_date DESC', [$matter['id']])['mail_date'] ?? null);
    if (!$noa) return [];
    $ord = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th'];
    for ($n = 1; $n <= 6; $n++) {
        $due = add_months($noa, 6 * $n, false); if ($due < $date) continue;
        return [['type' => $n <= 5 ? "Statement of Use or {$ord[$n]} Extension Due" : 'Statement of Use', 'due' => $due, 'note' => 'Re-docketed on revival ' . fmt_date($date) . ' (NOA ' . fmt_date($noa) . '); check whether the petition filed an extension or the SOU']];
    }
    return [];
}
/** SOU extension chain: closing "Statement of Use or Nth Extension Due" by an extension creates the (N+1)th, 6 months out. */
function next_sou_event(string $type, string $due): ?array {
    if (preg_match('/Statement of Use or (\d)(?:st|nd|rd|th) Extension Due/', $type, $m)) {
        $n = (int)$m[1]; if ($n >= 5) return null;
        $ord = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => '5th'][$n + 1];
        return ['type' => "Statement of Use or $ord Extension Due", 'due' => add_months($due, 6, false)];
    }
    return null;
}
/**
 * What closed a "Statement of Use or Nth Extension Due" deadline, read from the evidence on it (the latest USPTO / receipt part of its
 * note): 'sou' = the Statement of Use itself was filed (nothing further is due, so no next extension is docketed), 'extension' = an
 * extension request, '' = not stated. Audit 2026-10-02: confirming a proposal always docketed the next extension, even when the
 * evidence was the Statement of Use, and a receipt-based confirmation never docketed it, even for an extension.
 */
function sou_closed_by(string $note): string {
    $parts = array_values(array_filter(array_map('trim', explode('|', $note)), fn($x) => (bool)preg_match('/^(USPTO|Receipt in the file)/i', $x)));
    $last = $parts ? end($parts) : '';
    if ($last === '') return '';
    if (preg_match('/^Receipt in the file:\s*(.+?)(?:\s*\(saved|$)/i', $last, $m) && function_exists('receipt_kind') && ($k = receipt_kind($m[1], 'trademark'))) return $k['what'] === 'sou' ? 'sou' : ($k['what'] === 'extension' ? 'extension' : '');
    if (preg_match('/EXTENSION/i', $last)) return 'extension';
    return preg_match('/STATEMENT OF USE|USE AMENDMENT|ALLEGATION OF USE|REGISTERED/i', $last) ? 'sou' : '';
}
/** Docket the next link of the extension chain after $ev was closed, unless the Statement of Use closed it or that link already exists. */
function sou_chain_continue(array $ev, string $how): ?array {
    $nx = next_sou_event((string)$ev['type'], (string)$ev['due_date']); if (!$nx || sou_closed_by((string)($ev['note'] ?? '')) === 'sou') return null;
    if (q1('SELECT 1 FROM events WHERE matter_id=? AND type=? AND status<>"cancelled"', [$ev['matter_id'], $nx['type']])) return null;
    run('INSERT INTO events(matter_id,type,due_date,status,source,note) VALUES (?,?,?,"open","rule",?)', [$ev['matter_id'], $nx['type'], $nx['due'], 'Created when "' . $ev['type'] . '" was ' . $how . ' (extension chain)']);
    return $nx;
}

/** Events that merely announce something (a window opening, a reminder). Completing one never completes anything else. */
function is_notice_type(string $type): bool {
    return (bool)preg_match('/(Window Open|Begins?\b|Opposition Period Ends|^Patent Expires|^Expected Issue Date|^Remind Client|^Review File|^Report |^Process USPTO Notice|^Ask Client to Sign)/i', $type);
}
/** Open events on the same matter that completing $ev plausibly completes too. Offered as pre-checked choices, never applied silently. */
function related_done_candidates(array $ev, array $open): array {
    if (is_notice_type($ev['type'])) return [];
    $t = $ev['type']; $due = $ev['due_date']; $out = [];
    $win = fn(string $from, string $to) => fn(array $x) => $x['due_date'] >= $from && $x['due_date'] <= $to;
    if (preg_match('/^(Respond to Office Action|Final Response to Office Action)/', $t)) {
        $fam = '/^(Respond to Office Action|Final Response to Office Action)/'; $in = $win(add_months($due, -6, false), add_months($due, 6, false));
    } elseif (preg_match('/^Statement of Use/', $t)) {
        $fam = '/^Statement of Use/'; $in = fn($x) => $x['due_date'] >= $due;   // later extensions pre-ticked too (2026-09-29, XYZ003); untick them when only an extension was filed
    } elseif (preg_match('/^(\d+ 1\/2 Year) Maintenance Fee/', $t, $m)) {
        $fam = '/^' . preg_quote($m[1], '/') . ' Year Maintenance Fee/'; $in = fn($x) => true;
    } elseif (preg_match('/^(Section (8|71|9)|Renewal)/', $t)) {
        $fam = '/^(Section (8|71|9)|Renewal)/'; $in = $win(add_months($due, -13, false), add_months($due, 7, false));
    } elseif (preg_match('/^(Non-Provisional Filing Deadline|Maintenance Fee Due)/', $t)) {
        $fam = '/^(Non-Provisional Filing Deadline|Maintenance Fee Due)/'; $in = $win(add_months($due, -2, false), add_months($due, 2, false));
    } else { $fam = null; $in = fn($x) => false; }
    $unchecked = $unchecked ?? false;
    foreach ($open as $x) {
        if ($x['id'] == $ev['id'] || $x['status'] !== 'open') continue;
        if ($fam && preg_match($fam, $x['type']) && $in($x)) { $x['checked'] = !$unchecked; $out[] = $x; continue; }
        $x['checked'] = true;
        // A client reminder tied to this deadline: same window, either side
        if (preg_match('/^Remind Client/', $x['type']) && $x['due_date'] >= add_days($due, -45, false) && $x['due_date'] <= add_months($due, 6, false)) $out[] = $x;
    }
    return $out;
}

/** Client code = the leading letters of the file number: three letters (XYZ001), or a legacy P + three letters (XYZ003). Anything else has no derivable client. */
function client_from_ref(string $ref): string {
    if (preg_match('/^([A-Z]{3})\d{3}/', strtoupper($ref), $m)) return $m[1];
    if (preg_match('/^(P[A-Z]{3})\d{3}/', strtoupper($ref), $m)) return $m[1];
    return '';
}

/** A USPTO/DocketTrak status that means the property is gone: nothing further can fall due. "Cancellation pending/terminated" are TTAB proceedings, not death. */
function is_dead_status(string $status, string $live = ''): bool {
    if (preg_match('/Cancellation (Pending|Terminated)|Petition to Revive|Revived/i', $status)) return false;
    if (strcasecmp($live, 'Dead') === 0) return true;
    return (bool)preg_match('/^\s*(Abandoned|Cancelled|IR Cancelled|Expired|Provisional Application Expired)|Registration cancelled|has expired|Patent Expired|expired for failure|abandonment/i', $status);
}

/** The deadline that a window-opening event points at: "... Begin(s)" → "... End(s)", "... Window Open" → "... Due". null when the type is not a window opening. */
function paired_deadline_type(string $type): ?string {
    if (preg_match('/ Window Open$/', $type)) return preg_replace('/ Window Open$/', ' Due', $type);
    if (preg_match('/ Begins$/', $type)) return preg_replace('/ Begins$/', ' Ends', $type);
    if (preg_match('/ Begin$/', $type)) return preg_replace('/ Begin$/', ' End', $type);
    return null;
}
/** 2026-09-27: every US patent filing gets a 12-month deadline from its filing date (the earliest filing, unless an
 *  earlier priority date is claimed): provisional → Non-Provisional Filing Deadline, anything else → the foreign (Paris) filing
 *  deadline. Added once, only when the matter has none of these events yet and the date is still ahead. Returns what was added. */
function ensure_priority_deadline(int $mid, bool $nonProvOk = false): string {   // $nonProvOk: caller KNOWS no earlier priority is claimed (ADS shows none, or a person entered the matter)
    $m = q1('SELECT * FROM matters WHERE id=?', [$mid]);
    if (!$m || $m['kind'] !== 'patent' || !$m['filing_date'] || $m['dead'] || stripos((string)$m['country'], 'United States') === false) return '';
    if (preg_match('/^(PCT|National)/i', (string)$m['app_type'])) return '';
    $design = is_design_matter($m);   // designs: 6-month Paris / Hague period
    if (q1("SELECT 1 FROM events WHERE matter_id=? AND type IN ('Non-Provisional Filing Deadline','Non-Provisional Deadline','Foreign Filing Deadline','Remind Client International Filing Deadline')", [$mid])) return '';
    $prov = !$design && (stripos((string)$m['app_type'], 'provisional') === 0 || str_starts_with((string)$m['app_no'], '6'));
    if (!$prov && !$nonProvOk) return '';   // a non-provisional that claims a provisional or foreign priority runs from THAT date (XYZ001, XYZ017 2026-09-28)
    $type = $prov ? 'Non-Provisional Filing Deadline' : 'Remind Client International Filing Deadline';
    $due = add_months($m['filing_date'], $design ? 6 : 12);
    if ($due <= today()) return '';
    $fd = fmt_date($m['filing_date']);
    $note = $prov ? "12 months from provisional filing $fd" : ($design ? "6 months from design filing $fd (Paris Convention / Hague); no priority claim found" : "12 months from filing $fd (Paris Convention); no priority claim found");
    run('INSERT INTO events(matter_id,type,due_date,status,source,note) VALUES (?,?,?,"open","rule",?)', [$mid, $type, $due, $note]);
    audit('add_event', "$type $due added ($note)", $mid);
    return "$type " . fmt_date($due);
}
/** The Tuesday a patent issues on: the first Tuesday at least 13 days after the issue notification's mail date. */
function issue_tuesday(string $mailDate): string {
    $t = (new DateTimeImmutable($mailDate))->modify('+13 days');
    while ($t->format('N') !== '2') $t = $t->modify('+1 day');
    return $t->format('Y-m-d');
}
