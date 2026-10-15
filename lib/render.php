<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
if (!defined('EMAIL_CHANGE_EVENT')) define('EMAIL_CHANGE_EVENT', 'Correspondence email changed: mark inactive?');
if (!defined('INTERVIEW_EVENT')) define('INTERVIEW_EVENT', 'Examiner Interview');   // 2026-10-13 (lib/held.php)
const SEND_WINDOW_DAYS = 30;   // no client email about a deadline more than this far out
function h($s): string { return htmlspecialchars(is_array($s) ? '' : (string)$s, ENT_QUOTES, 'UTF-8'); }
/** A matter-folder file name that opens the file in a new tab (?p=mfview). */
function mf_view_link(string $ref, string $name, string $label = ''): string { return '<a href="' . h(url('mfview', ['ref' => $ref, 'name' => $name])) . '" target="_blank" rel="noopener" title="Open ' . h($name) . '">' . h($label !== '' ? $label : $name) . '</a>'; }
/** 2026-10-05: [title, application number, granted number] labels in each kind's own words; a mixed list keeps the combined ones. */
function num_labels(string $kind): array {
    return ['trademark' => ['Mark', 'Serial No.', 'Registration No.'], 'patent' => ['Title', 'Application No.', 'Patent No.'], 'copyright' => ['Title', 'Application No.', 'Registration No.']][$kind]
        ?? ['Title / mark', 'Application / serial', 'Patent / registration'];
}
function url(string $p, array $a = []): string { return '?' . http_build_query(['p' => $p] + $a); }
function badge_days(string $due): string {
    $d = days_until($due);
    $cls = $d < 0 ? 'over' : ($d <= 3 ? 'd3' : ($d <= 14 ? 'd14' : ($d <= 60 ? 'd60' : 'later')));
    $txt = $d < 0 ? abs($d) . 'd overdue' : ($d === 0 ? 'today' : "in {$d}d");
    return "<span class=\"days $cls\">$txt</span>";
}
function status_pill(string $s): string { return '<span class="pill ' . h($s) . '">' . h($s) . '</span>'; }
/** A reminder's state in words (2026-10-03: "can you label these things better?"): ready / blocked: why / sent / skipped. */
function reminder_pill(array $r): string {
    if ($r['status'] === 'pending') return '<span class="pill pending">ready</span>';
    if ($r['status'] === 'held') return '<span class="pill held">blocked: ' . h(blocked_why((string)($r['reason'] ?? ''))) . '</span>';
    return status_pill((string)$r['status']);
}
function blocked_why(string $reason): string {
    if (preg_match('/template/i', $reason)) return 'template not approved';
    if (preg_match('/another firm of record/i', $reason)) return 'another firm of record';
    if (preg_match('/no (client )?e-?mail|no recipient|address/i', $reason)) return 'no client email address';
    return $reason !== '' ? strtolower(rtrim($reason, '.')) : 'waiting';
}
function layout_top(string $title, ?string $flash = null): void {
    $u = auth_user(); $cfg = $GLOBALS['cfg'];
    $nav = [['dash', 'Dashboard'], ['matters', 'Matters']];   // 2026-10-15: Confirm queue moved under Advanced (its count shows there). 2026-10-06: "Emails to send & USPTO mail" off the menu; the dashboard cards still open it
    // 2026-10-01: "Create/Delete" gets its own dropdown; DocketTrak is no longer in the menu (the ?p=dtsync page was removed 2026-10-09).
    $menus = [['Create/Delete', [['newfiling', 'New filing'], ['clientreport', 'Client Portfolio'], ['newmatter', 'Create record'], ['delete', 'Delete record']]],
              ['Advanced', [['queue', 'Confirm queue'], ['templates', 'Templates'], ['report', 'Report'], ['audit', 'Change Log'], ['inbox', 'USPTO mail'], ['dropbox', 'Dropbox']]]];
    $cur = $_GET['p'] ?? 'dash';
    $qc = (q1('SELECT count(*) c FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.status="proposed" AND m.dead=0')['c'] ?? 0) + (q1('SELECT count(DISTINCT m.id) c FROM matters m JOIN events e ON e.matter_id=m.id AND e.status IN ("open","proposed") AND e.type<>? WHERE m.dead=1', [ABANDON_REPORT_EVENT])['c'] ?? 0);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' · Pendant</title>';
    echo '<link rel="icon" type="image/png" href="pendant-icon.png"><link rel="apple-touch-icon" href="pendant-icon.png"><style>' . file_get_contents(__DIR__ . '/style.css') . '</style></head><body>';
    $mail = rtrim((string)($cfg['mail_base'] ?? 'mail/'), '/') . '/';
    // Mail and Locations live in the Mail-lookup app; only logins that have an account there (mail_users map) get the links. the paralegal: Docket only.
    $hasMail = in_array($u, $cfg['mail_users'] ?? [], true);
    echo '<header><div class="hrow"><div class="brand"><span class="mono">' . h(firm('initials')) . '</span> Pendant</div><nav class="menu" aria-label="Private pages">' . ($hasMail ? '<a href="' . h($mail) . '">Mail</a>' : '') . '<a href="./" class="here">Pendant</a>' . ($hasMail ? '<a href="' . h($mail) . 'visitors.php">Locations</a>' : '') . '</nav>';
    echo '<nav>';   // one line (wraps only when it does not fit): brand, apps, menu, search, who (2026-09-30)
    foreach ($nav as [$p, $l]) echo '<a href="' . url($p) . '"' . ($cur === $p ? ' class="on"' : '') . '>' . $l . ($p === 'queue' && $qc ? " <b class=\"cnt\">$qc</b>" : '') . '</a>';
    foreach ($menus as [$label, $items]) {
        $on = in_array($cur, array_column($items, 0), true);
        $hasQ = in_array('queue', array_column($items, 0), true) && $qc;   // the outstanding confirm count rides on the Advanced menu too
        echo '<details class="adv"><summary' . ($on ? ' class="on"' : '') . '>' . h($label) . ($hasQ ? " <b class=\"cnt\">$qc</b>" : '') . ' ▾</summary><div class="advmenu">';
        foreach ($items as [$p, $l]) echo '<a href="' . url($p) . '"' . ($cur === $p ? ' class="on"' : '') . '>' . $l . ($p === 'queue' && $qc ? " <b class=\"cnt\">$qc</b>" : '') . '</a>';
        echo '</div></details>';
    }
    // a click outside closes an open dropdown; opening one closes the other
    echo '<script>document.addEventListener("click",function(e){document.querySelectorAll("details.adv[open]").forEach(function(d){if(!d.contains(e.target))d.open=false;});});</script>';
    echo '</nav><form method="get" class="hsearch" role="search"><input type="hidden" name="p" value="matters"><input type="search" name="q" value="' . h($cur === 'matters' ? trim((string)($_GET['q'] ?? '')) : '') . '" placeholder="Search" aria-label="Search matters: file no., mark, title, owner, client" title="File no., mark, title, owner, client"></form><div class="who">' . h(auth_name()) . ' <span class="role">' . h(auth_role()) . '</span> <a href="?logout=1">sign out</a></div></div></header><main>';   // row 2: menu, search (the "Today is" box is gone; ?asof= still works by URL)
    if ($flash) echo '<div class="flash">' . h($flash) . '</div>';
}
function layout_bottom(): void {
    // 2026-10-12: Done / Confirm / Cancel … happen in place: the form posts in the background, the row fades, and <main> is swapped
    // for the page the server comes back with (related rows it closed vanish too, counts update), scroll untouched. A server that sends
    // you elsewhere (ask_inactive, Send page) is followed. Any other button: the page reloads at the same scroll position.
    echo <<<'JS'
<script>(function(){
var K="pendantScroll",here=location.pathname+location.search,
    INPLACE=/^(done_multi|confirm|confirm_group|reject|reject_group|cancel|reopen|wait_clear|rem_skip|discard_outbox|report_cancel|reg_reported|pub_reported|held_reopen|fee_paid_undo)$/;
function toast(t){if(!t)return;var d=document.createElement("div");d.className="flash";d.textContent=t;d.style.cssText="position:fixed;top:10px;left:50%;transform:translateX(-50%);z-index:999;max-width:90vw;box-shadow:0 4px 14px rgba(0,0,0,.18)";document.body.appendChild(d);setTimeout(function(){d.remove();},6000);}
document.addEventListener("submit",function(e){
  var f=e.target;if(e.defaultPrevented||(f.method||"").toLowerCase()!=="post"||f.target==="_blank")return;
  var a=f.querySelector("input[name=action]");
  var hasFile=[].some.call(f.querySelectorAll("input[type=file]"),function(i){return i.files&&i.files.length;});   // 2026-10-13 (XYZ001): a dropped receipt must really upload, and the server then opens the client email
  if(!a||!INPLACE.test(a.value)||hasFile||!window.fetch||!window.DOMParser){try{sessionStorage.setItem(K,JSON.stringify({u:here,y:scrollY,t:Date.now()}));}catch(x){}return;}
  e.preventDefault();
  var fd=new FormData(f);if(e.submitter&&e.submitter.name)fd.append(e.submitter.name,e.submitter.value);
  var row=f.closest("tr")||(f.closest("details")&&f.closest("details").closest("tr"));
  f.querySelectorAll("button").forEach(function(b){b.disabled=true;});
  if(row){row.style.transition="opacity .35s";row.style.opacity=".25";}
  fetch(location.href,{method:"POST",body:new URLSearchParams(fd),credentials:"same-origin"}).then(function(r){
    var u=new URL(r.url);if(u.pathname+u.search!==here){location.href=r.url;return;}
    return r.text().then(function(h){
      var d=new DOMParser().parseFromString(h,"text/html"),m=d.querySelector("main"),cur=document.querySelector("main");
      if(!m||!cur){location.reload();return;}
      var fl=m.querySelector(".flash"),msg=fl?fl.textContent:"";if(fl)fl.remove();
      var y=scrollY;cur.innerHTML=m.innerHTML;
      cur.querySelectorAll("script").forEach(function(s){var n=document.createElement("script");n.textContent=s.textContent;s.replaceWith(n);});
      scrollTo(0,y);toast(msg);
    });
  }).catch(function(){f.submit();});
});
var s=null;try{s=JSON.parse(sessionStorage.getItem(K)||"null");sessionStorage.removeItem(K);}catch(x){}
if(!s||s.u!==here||Date.now()-s.t>60000||location.hash)return;
if("scrollRestoration" in history)history.scrollRestoration="manual";scrollTo(0,s.y);
var fl=document.querySelector(".flash");if(fl&&s.y>80){fl.style.cssText="position:fixed;top:10px;left:50%;transform:translateX(-50%);z-index:999;max-width:90vw;box-shadow:0 4px 14px rgba(0,0,0,.18)";setTimeout(function(){fl.style.display="none";},6000);}
})();</script>
JS;
    echo '</main><footer>Pendant · ' . htmlspecialchars(firm('name')) . '</footer></body></html>';
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }
function btn(string $action, array $data, string $label, string $cls = '', string $confirm = ''): string {
    $s = '<form method="post" class="inline"' . ($confirm ? ' onsubmit="return confirm(\'' . h($confirm) . '\')"' : '') . '>' . csrf_field() . '<input type="hidden" name="action" value="' . h($action) . '">';
    foreach ($data as $k => $v) foreach (is_array($v) ? $v : [$v] as $one) $s .= '<input type="hidden" name="' . h($k) . (is_array($v) ? '[]' : '') . '" value="' . h((string)$one) . '">';   // arrays post as name[] (grouped confirm)
    return $s . '<button class="' . $cls . '">' . h($label) . '</button></form>';
}
/** This page as a "?p=..." link: safe_back() accepts only those, so REQUEST_URI (a path) sent every button back to the Dashboard. */
function here_uri(): string { $q = (string)($_SERVER['QUERY_STRING'] ?? ''); return '?' . ($q !== '' ? $q : 'p=dash'); }
/** Done, inline (2026-10-04): a button that unfolds the same form the ?p=done page shows (related events to close, the SOU question,
 *  notes) under the row's buttons. 2026-10-08: the buttons stay put; the form is moved below the whole button row while open. Posts done_multi like the page; Esc or the button again folds it away. */
function done_inline(array $ev, string $back): string {
    $open = q('SELECT * FROM events WHERE matter_id=? AND status="open" ORDER BY due_date', [$ev['matter_id']]);
    $cands = related_done_candidates($ev, $open);
    $nx = next_sou_event((string)$ev['type'], (string)$ev['due_date']); if ($nx && q1('SELECT 1 FROM events WHERE matter_id=? AND type=?', [$ev['matter_id'], $nx['type']])) $nx = null;
    $f = '<form method="post" class="donebox">' . csrf_field() . '<input type="hidden" name="action" value="done_multi"><input type="hidden" name="id" value="' . (int)$ev['id'] . '"><input type="hidden" name="back" value="' . h($back) . '">';
    // 2026-10-05 (XYZ001): a Statement of Use / extension deadline asks what was filed, nothing pre-ticked: an extension closes only
    // this deadline (and its client reminders); the Statement of Use closes the later extensions and the final deadline too.
    if (preg_match('/^Statement of Use or \d/', (string)$ev['type'])) {
        foreach ($cands as $c) if (preg_match('/^Remind Client/', $c['type'])) $f .= '<input type="hidden" name="ids[]" value="' . (int)$c['id'] . '">';
        $f .= '<input type="text" name="why" placeholder="Notes" autocomplete="off"><div style="margin-top:6px"><button class="small gold" name="sou_kind" value="ext">Extension filed</button> <button class="small gold" name="sou_kind" value="sou">Statement of Use filed</button></div></form>';
        return '<details class="doneinline" ontoggle="var d=this,f=d._f||(d._f=d.querySelector(\'form.donebox\'));if(d.open){d.parentNode.appendChild(f);f.onkeydown=function(e){if(e.key===\'Escape\')d.open=false};var i=f.querySelector(\'input[name=why]\');i&&i.focus()}else d.appendChild(f)" onkeydown="if(event.key===\'Escape\')this.open=false"><summary class="btn small">' . (is_admin() ? 'Done' : 'Mark done') . '</summary>' . $f . '</details>';
    }
    // 2026-10-13 (XYZ001): issue fee paid → drop the Patent Center receipt(s); saved into the file, then the client email (index.php done_multi)
    if (function_exists('is_issue_fee_event') && is_issue_fee_event($ev)) {
        $k = 'if' . (int)$ev['id'];
        $f = str_replace('<form method="post" class="donebox">', '<form method="post" class="donebox" enctype="multipart/form-data">', $f) . '<input type="hidden" name="issue_fee" value="1">'
           . '<div id="' . $k . 'd" style="border:2px dashed #c9a227;border-radius:8px;padding:14px;margin:6px 0;text-align:center;background:#faf8f3;cursor:pointer"><b>Drop the issue fee receipt</b><input type="file" name="receipts[]" id="' . $k . 'f" accept="application/pdf" multiple style="display:none"><div id="' . $k . 'l"></div></div>'
           . '<script>(function(){var d=document.getElementById("' . $k . 'd"),f=document.getElementById("' . $k . 'f"),l=document.getElementById("' . $k . 'l");function show(){l.innerHTML="";for(var i=0;i<f.files.length;i++){var s=document.createElement("div");s.textContent="📎 "+f.files[i].name;l.appendChild(s);}var b=d.closest("form").querySelector("button.gold");if(b)b.textContent=f.files.length?"Save receipt and email client":"Mark done and email client";}d.onclick=function(){f.click();};f.onchange=show;["dragover","dragenter"].forEach(function(e){d.addEventListener(e,function(v){v.preventDefault();d.style.background="#f3ecd8";});});["dragleave","drop"].forEach(function(e){d.addEventListener(e,function(v){v.preventDefault();d.style.background="#faf8f3";});});d.addEventListener("drop",function(v){f.files=v.dataTransfer.files;show();});})();</script>';
    }
    $ifLabel = function_exists('is_issue_fee_event') && is_issue_fee_event($ev) && auth_email() !== '' ? 'Mark done and email client' : '';   // 2026-10-13
    if ($cands) { $f .= '<div class="muted">Also finished (untick any that are not):</div>'; foreach ($cands as $c) $f .= '<label class="chk"><input type="checkbox" name="ids[]" value="' . (int)$c['id'] . '"' . (!empty($c['checked']) ? ' checked' : '') . '> ' . h($c['type']) . ' · ' . fmt_date($c['due_date']) . '</label>'; }
    if ($nx) $f .= '<label class="chk"><input type="checkbox" name="no_next" value="1"> A Statement of Use was filed (do not docket ' . h($nx['type']) . ')</label>';
    $f .= '<input type="text" name="why" placeholder="Notes" autocomplete="off"><button class="small gold">' . h($ifLabel ?: (is_admin() ? 'Mark done' : 'Mark done (an admin confirms)')) . '</button></form>';
    return '<details class="doneinline" ontoggle="var d=this,f=d._f||(d._f=d.querySelector(\'form.donebox\'));if(d.open){d.parentNode.appendChild(f);f.onkeydown=function(e){if(e.key===\'Escape\')d.open=false};var i=f.querySelector(\'input[name=why]\');i&&i.focus()}else d.appendChild(f)" onkeydown="if(event.key===\'Escape\')this.open=false"><summary class="btn small">' . (is_admin() ? 'Done' : 'Mark done') . '</summary>' . $f . '</details>';
}
/** A deadline whose client email is a reminder (2026-10-08: its button reads "Send reminder"); to-dos (signing links, reports) are not. */
function is_reminder_event(array $ev): bool {
    $t = (string)($ev['type'] ?? '');
    if (in_array($t, [ABANDON_REPORT_EVENT, 'Report Notice of Allowance', 'Report Patent Issuance', 'Report Patent Filing', 'Report Trademark Filing', 'Expected Issue Date', OPP_END_EVENT], true)) return false;
    if (defined('SIGN_EVENT') && in_array($t, [SIGN_EVENT, SOU_SIGN_EVENT, POSTREG_SIGN_EVENT], true)) return false;
    if (defined('PAT_SIGN_EVENT') && $t === PAT_SIGN_EVENT) return false;
    if (notice_report_kind($t) || (function_exists('is_issue_report') && is_issue_report($t)) || (function_exists('is_oa_report') && is_oa_report($t)) || (function_exists('is_notice_todo') && is_notice_todo($t)) || (function_exists('is_filing_report') && is_filing_report($t))) return false;
    return !str_starts_with($t, 'Report ');
}
function event_actions(array $ev): string {
    $o = [];
    $back = ['back' => here_uri()];
    // Expected Issue Date (2026-10-04, XYZ017): greyed "Too early to send" until the issue day's "Report Patent Issuance" to-do exists, then it opens that report.
    $issueReport = ($ev['type'] ?? '') === 'Expected Issue Date' && in_array($ev['status'], ['open', 'proposed'], true) && auth_email() !== ''
        ? (q1("SELECT id FROM events WHERE matter_id=? AND type=? AND status IN ('open','proposed') ORDER BY id DESC LIMIT 1", [$ev['matter_id'], ISSUANCE_REPORT_EVENT]) ?: false) : null;
    // 2026-10-04 (XYZ004): until the Issue Notification is in the folder (it is what queues the "Report Issue Notification" email), show where it is + Fetch / Download with Claude.
    if ($issueReport !== null && (string)($ev['mail_date'] ?? '') !== '' && function_exists('oa_report_actions') && !q1('SELECT 1 FROM events WHERE matter_id=? AND type IN (?,?)', [$ev['matter_id'], ISSUE_REPORT_EVENTS[0], ISSUE_REPORT_EVENTS[1]])) {
        $ntf = ['doc_code' => 'ISSUE.NTF'] + $ev; if (!oa_report_ready($ntf)) $o[] = oa_report_actions($ntf, here_uri());
    }
    if ($issueReport === false) $o[] = '<button type="button" class="small ghost" disabled style="opacity:.45;cursor:not-allowed" title="The client email is prepared on the issue day (' . h(fmt_long((string)$ev['due_date'])) . '): Pendant saves the certificate, writes the issuance letter and adds a &quot;' . h(ISSUANCE_REPORT_EVENT) . '&quot; to-do.">Too early to send</button>';
    elseif ($issueReport) $o[] = '<a class="btn small gold" href="' . url('send', ['id' => $issueReport['id'], 'back' => here_uri()]) . '">Send report</a>';
    // Opposition period (2026-10-04): greyed until the period has ended (the day after its last day), then the "opposition period ended" report.
    if (($ev['type'] ?? '') === OPP_END_EVENT && in_array($ev['status'], ['open', 'proposed'], true) && auth_email() !== '')
        $o[] = (string)$ev['due_date'] >= today()
            ? '<button type="button" class="small ghost" disabled style="opacity:.45;cursor:not-allowed" title="The opposition period ends ' . h(fmt_long((string)$ev['due_date'])) . '; the report can be sent from the next day.">Too early to send</button>'
            : '<a class="btn small ' . (str_contains((string)($ev['note'] ?? ''), 'emailed client') ? 'ghost' : 'gold') . '" href="' . url('send', ['id' => $ev['id'], 'back' => here_uri()]) . '">' . (str_contains((string)($ev['note'] ?? ''), 'emailed client') ? 'Send report again' : 'Send report') . '</a>';
    // Patent Office Action / NOA report to-do (2026-10-04, lib/oareport.php): where the papers are + the gold Send button, one line.
    $oaRep = is_oa_report((string)($ev['type'] ?? '')) && in_array($ev['status'], ['open', 'proposed'], true);
    if ($oaRep) $o[] = oa_report_actions($ev, here_uri());
    // 2026-10-04: every other USPTO patent notice ('Process USPTO Notice') and an Issue Notification report not read yet: papers + Fetch, then Send.
    if (!$oaRep && in_array($ev['status'], ['open', 'proposed'], true) && function_exists('notice_todo_actions') && (is_notice_todo((string)($ev['type'] ?? '')) || notice_todo_waiting($ev))) { $o[] = notice_todo_actions($ev, here_uri()); $oaRep = true; }
    if (!$oaRep && in_array($ev['status'], ['open', 'proposed'], true) && auth_email() !== '' && !in_array($ev['type'] ?? '', ['Expected Issue Date', OPP_END_EVENT, EMAIL_CHANGE_EVENT, INTERVIEW_EVENT], true)) $o[] = ($ev['type'] ?? '') === 'Report Notice of Allowance'
        ? '<a class="btn small gold" href="' . url('send', ['id' => $ev['id'], 'report' => 'noa', 'back' => here_uri()]) . '">Send report</a>'
        : '<a class="btn small ' . (($ev['type'] ?? '') === ABANDON_REPORT_EVENT || notice_report_kind((string)($ev['type'] ?? '')) || (function_exists('is_issue_report') && is_issue_report((string)($ev['type'] ?? ''))) || in_array($ev['type'] ?? '', ['Report Patent Issuance', 'Report Patent Filing', 'Report Trademark Filing'], true) ? 'gold' : 'ghost') . '" href="' . url('send', ['id' => $ev['id'], 'back' => here_uri()]) . '">' . (($ev['type'] ?? '') === ABANDON_REPORT_EVENT ? 'Send abandonment email' : (notice_report_kind((string)($ev['type'] ?? '')) || (function_exists('is_issue_report') && is_issue_report((string)($ev['type'] ?? ''))) || in_array($ev['type'] ?? '', ['Report Patent Issuance', 'Report Patent Filing', 'Report Trademark Filing'], true) ? 'Send report' : (is_reminder_event($ev) ? 'Send reminder' : 'Send to client'))) . '</a>';
    // Statement of Use prepared (2026-10-08): finds the TEAS e-sign e-mail and opens the "please sign" email to the client.
    if (function_exists('is_sou_deadline') && is_sou_deadline((string)($ev['type'] ?? '')) && in_array($ev['status'], ['open', 'proposed'], true) && auth_email() !== '')
        $o[] = btn('sou_prepared', ['id' => $ev['id']] + $back, 'Send prepared SOU', 'small gold');
    // Declaration / renewal prepared (2026-10-14, XYZ001): the Trademark Center signing link → the "please sign" email. Only on the
    // matter's earliest open post-registration deadline, and only once its window is open (within a year + grace).
    if (function_exists('is_postreg_deadline') && is_postreg_deadline((string)($ev['type'] ?? '')) && in_array($ev['status'], ['open', 'proposed'], true) && auth_email() !== '' && days_until((string)$ev['due_date']) <= 366
        && !array_filter(q("SELECT type FROM events WHERE matter_id=? AND status IN ('open','proposed') AND due_date < ?", [$ev['matter_id'], $ev['due_date']]), fn($x) => is_postreg_deadline((string)$x['type'])))
        $o[] = btn('postreg_prepared', ['id' => $ev['id']] + $back, 'Send prepared declaration', 'small gold');
    // 2026-10-13: the client moved its USPTO correspondence email away: take it off the docket, or keep it.
    if (($ev['type'] ?? '') === EMAIL_CHANGE_EVENT && $ev['status'] === 'open') {
        preg_match('/changed to (\S+?)\.? Mark/', (string)$ev['note'], $em);
        $o[] = btn('set_active', ['matter_id' => $ev['matter_id'], 'state' => 'inactive', 'reason' => 'Correspondence email moved to ' . ($em[1] ?? 'another address') . ' at the USPTO'] + $back, 'Mark inactive', 'small gold', 'Mark this matter inactive and close every remaining deadline on it?');
        $o[] = btn('done_multi', ['id' => $ev['id'], 'why' => 'Kept on the docket'] + $back, 'Keep on docket', 'small ghost');
        return implode(' ', $o);
    }
    if ($ev['status'] === 'open') {
        $o[] = done_inline($ev, here_uri());   // 2026-10-04: the "what was done" box opens right under the button, no separate page
        if (is_admin()) $o[] = btn('cancel', ['id' => $ev['id']] + $back, 'Cancel', 'small ghost', 'Cancel this event (no action needed)?');
    } elseif ($ev['status'] === 'proposed') {
        if (($ev['proposed_by'] ?? '') === 'receipts' && function_exists('receipt_for_event') && receipt_for_event((int)$ev['id'])) $o[] = '<a class="btn small ghost" target="_blank" rel="noopener" href="' . url('receipt', ['id' => $ev['id']]) . '">Open receipt</a>';
        if (can_confirm()) { $o[] = btn('confirm', ['id' => $ev['id']] + $back, 'Confirm done', 'small gold'); $o[] = btn('reject', ['id' => $ev['id']] + $back, 'Not done', 'small ghost'); }
        else $o[] = '<span class="muted">awaiting an admin</span>';
    } else {
        if (is_admin()) $o[] = btn('reopen', ['id' => $ev['id']] + $back, 'Reopen', 'small ghost');
    }
    return implode(' ', $o);
}
function events_table(array $rows, bool $showMatter = true, string $headExtra = '', bool $showClient = true): void {
    if (!$rows) { echo ($headExtra !== '' ? '<div class="bar">' . $headExtra . '</div>' : '') . '<p class="muted">Nothing here.</p>'; return; }
    echo '<table><thead><tr><th>Due</th><th></th>' . ($showMatter ? '<th class="file">File</th>' . ($showClient ? '<th>Client</th>' : '') . '<th>Matter</th>' : '') . '<th>Event</th><th>Status</th><th>Note</th><th class="acts">' . $headExtra . '</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        // Dashboard rows carry dash_date (the next reminder still to send): shown and counted from, with the real deadline under it.
        $dd = $r['dash_date'] ?? $r['due_date']; $opp = $r['type'] === OPP_END_EVENT && $dd !== $r['due_date']; $rem = $dd !== $r['due_date'] && !$opp;
        echo '<tr id="ev' . $r['id'] . '"><td class="nowrap">' . ($opp ? 'Send ' . fmt_date($dd) . '<div class="note">Period ends ' . fmt_date($r['due_date']) . '</div>' : ($rem ? 'Remind ' . fmt_date($dd) . '<div class="note">Deadline ' . fmt_date($r['due_date']) . '</div>' : fmt_date($r['due_date']))) . (defined('INTERVIEW_EVENT') && $r['type'] === INTERVIEW_EVENT && preg_match('/^(\d{1,2}:\d{2} [AP]M \w+)/', (string)$r['note'], $tm) ? '<div><b>' . h($tm[1]) . '</b></div>' : '') . '</td><td>' . badge_days($dd) . '</td>';
        if ($showMatter) echo '<td class="file"><a href="' . url('matter', ['id' => $r['matter_id']]) . '"><b>' . h($r['ref']) . '</b></a></td>' . ($showClient ? '<td>' . h($r['client_code']) . '</td>' : '') . '<td class="title">' . h(trunc($r['title'], 48)) . ' <span class="muted">' . num_link($r, trim((string)($r['reg_no'] ?? '')) !== '') . '</span></td>';
        echo '<td>' . ($rem ? 'Reminder: ' : '') . h($r['type']) . ($r['source'] !== 'import' ? ' <span class="src">' . h($r['source']) . '</span>' : '') . (!$showMatter && ($rp = reminder_plan($r)) !== '' ? '<div class="note">' . $rp . '</div>' : '') . '</td><td>' . status_pill($r['status']) . '</td><td class="note">' . note_html($r) . '</td><td class="acts">' . event_actions($r) . '</td></tr>';
    }
    echo '</tbody></table>';
}
/**
 * An event's note with each part that names something linked to it (2026-10-01, Verify completed: "have this link to see
 * the item being referred to"). Parts are the " | " pieces note_add() writes:
 *   "USPTO TSDR: …" / "TSDR: …" / "USPTO status: …" on a US trademark  -> its TSDR record (status + prosecution history)
 *   "USPTO Open Data Portal: …" / "USPTO <doc code> of <date> …" on a US patent -> Patent Center's document list for the application
 *   "Receipt in the file: <name>"  -> the receipt PDF (same as the Open receipt button)
 *   "emailed client <date> by <who>" -> the matter's change log, where the email is listed
 */
function note_html(array $r): string {
    $note = (string)($r['note'] ?? ''); if ($note === '') return '';
    $us = preg_match('/^(United States|US|USA)?$/i', trim((string)($r['country'] ?? '')));
    $digits = preg_replace('/\D/', '', (string)($r['app_no'] ?? ''));
    $official = '';
    if ($us && ($r['kind'] ?? '') === 'trademark' && preg_match('/^\d{7,8}$/', $digits)) $official = tsdr_url($digits);
    elseif ($us && ($r['kind'] ?? '') === 'patent' && preg_match('/^\d{8}$/', $digits)) $official = patentcenter_url($digits) . '/ifw/docs';
    $a = fn(string $url, string $txt, string $title) => '<a href="' . h($url) . '" target="_blank" rel="noopener" title="' . h($title) . '">' . h($txt) . '</a>';
    $out = [];
    foreach (explode(' | ', $note) as $part) {
        if ($official !== '' && preg_match('/^(USPTO |TSDR:)/', $part)) $out[] = $a($official, $part, ($r['kind'] === 'trademark' ? 'TSDR' : 'Patent Center documents') . ' (new window)');
        elseif (str_starts_with($part, 'Receipt in the file:') && isset($r['id']) && function_exists('receipt_for_event') && receipt_for_event((int)$r['id'])) $out[] = $a(url('receipt', ['id' => $r['id']]), $part, 'Open the receipt (new window)');
        elseif (preg_match('/^emailed client /', $part) && isset($r['matter_id'])) $out[] = '<a href="' . url('matter', ['id' => $r['matter_id']]) . '#chlog" title="The email is in this matter\'s change log">' . h($part) . '</a>';
        else $out[] = h($part);
    }
    return implode(' | ', $out);
}
/** Serial / registration as shown on screen: US trademark numbers link to TSDR in a new window; patent numbers get commas. */
/** Priority-claims box for a patent (ODP data in matters.priority_json). Numbers link to the Pendant record when there is one. */
function priority_box(array $m): string {
    $p = json_decode((string)($m['priority_json'] ?? ''), true);
    $link = function (string $no) { $d = preg_replace('/\D/', '', $no); $mm = strlen($d) >= 8 ? q1("SELECT id, ref FROM matters WHERE kind='patent' AND replace(replace(replace(app_no,'/',''),',',''),' ','')=?", [$d]) : null;
        $txt = preg_match('/^\d{8}$/', $no) ? fmt_app($no) : (preg_match('/^PCT/i', $no) ? $no : $no);
        return $mm ? '<a href="' . url('matter', ['id' => $mm['id']]) . '">' . h($txt) . '</a> <span class="muted">(' . h($mm['ref']) . ')</span>' : (preg_match('/^\d{8}$/', $no) ? '<a target="_blank" rel="noopener" href="https://patentcenter.uspto.gov/applications/' . h($no) . '">' . h($txt) . '</a>' : h($txt)); };
    $o = '<div class="box"><h2 style="margin-top:0">Priority claims</h2>';
    if (!is_array($p)) return $o . '<p class="muted">' . (($m['app_no'] ?? '') === '' ? 'No application number, so nothing to look up.' : 'Not available yet (unpublished applications are not in the USPTO Open Data Portal).') . '</p></div>';
    $rows = [];
    foreach ($p['parents'] ?? [] as $x) $rows[] = ['Parent: ' . ($x['desc'] !== '' ? $x['desc'] : $x['type']), $link($x['no']), $x['date'], $x['status'] . ($x['patent'] !== '' ? ' · Pat. ' . fmt_reg($x['patent']) : '')];
    foreach ($p['foreign'] ?? [] as $x) $rows[] = ['Foreign priority' . ($x['office'] !== '' ? ': ' . $x['office'] : ''), h($x['no']), $x['date'], ''];
    foreach ($p['children'] ?? [] as $x) $rows[] = ['Child: ' . ($x['desc'] !== '' ? $x['desc'] : $x['type']), $link($x['no']), $x['date'], $x['status'] . ($x['patent'] !== '' ? ' · Pat. ' . fmt_reg($x['patent']) : '')];
    if (!$rows) return $o . '<p class="muted">None: no US parent or child applications and no foreign priority claim on record.' . (!empty($m['priority_at']) ? ' (USPTO data of ' . h(substr($m['priority_at'], 0, 10)) . ')' : '') . '</p></div>';
    $o .= '<table><thead><tr><th>Relationship</th><th>Application</th><th>Filed</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as [$a, $b, $c, $d]) $o .= '<tr><td>' . h($a) . '</td><td class="nowrap">' . $b . '</td><td class="nowrap">' . ($c !== '' ? fmt_date($c) : '') . '</td><td class="note">' . h($d) . '</td></tr>';
    return $o . '</tbody></table>' . (!empty($m['priority_at']) ? '<p class="muted" style="margin:6px 0 0">USPTO data of ' . h(substr($m['priority_at'], 0, 10)) . '</p>' : '') . '</div>';
}
function num_link(array $m, bool $reg = false): string {
    $n = trim((string)($reg ? $m['reg_no'] : $m['app_no'])); if ($n === '') return '';
    $txt = $reg ? fmt_reg($n) : fmt_app($n, $m['country'] ?? '');
    $us = preg_match('/^(United States|US|USA)?$/i', trim((string)($m['country'] ?? '')));
    if (($m['kind'] ?? '') === 'trademark' && $us && preg_match('/^\d{7,8}$/', $n)) return '<a href="' . h(tsdr_url($n, $reg)) . '" target="_blank" rel="noopener" title="TSDR (new window)">' . h($txt) . '</a>';
    // US patents: the serial (and the patent number, which Patent Center files under its application) open Patent Center.
    $app = preg_replace('/\D/', '', (string)($m['app_no'] ?? ''));
    if (($m['kind'] ?? '') === 'patent' && $us && preg_match('/^\d{8}$/', $app)) return '<a href="' . h(patentcenter_url($app)) . '" target="_blank" rel="noopener" title="Patent Center (new window; unpublished applications need a login)">' . h($txt) . '</a>';
    return h($txt);
}
function trunc(?string $s, int $n): string { $s = (string)$s; return strlen($s) > $n ? substr($s, 0, $n - 1) . '…' : $s; }
