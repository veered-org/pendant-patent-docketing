<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * Notices of publication (2026-10-04): "an email thing in the dashboard for notices of publication received and emails to clients
 * based there-on. Make it pretty. Make it right depending on whether it's intent to use or currently in use, when it was filed."
 *
 *  - pub_story()       what the mark's filing basis means for the client: filed and still intent-to-use, filed ITU and since amended to
 *                      use, filed in use, part of each, Madrid 66(a), foreign 44(e). Basis at filing + current basis both come from TSDR.
 *  - pub_next_text()   the "what happens next" paragraph of the 'Report Notice of Publication' email ({PUB_NEXT}), by that story.
 *  - pub_rows()        every notice of publication of the last 120 days with its report to-do and what became of it.
 *  - pub_panel()       the Dashboard panel (?p=dash&show=pub): one card per mark, timeline, basis, and Preview & send.
 *  - pub_sweep()       hourly: queues a report for a notice that has none (notices before 2026-10-01 never got one), fills in a basis
 *                      TSDR has not given yet, and closes a report the client already got by hand from Gmail (the paralegal drafts, staff sends).
 */
const PUB_REPORT = 'Report Notice of Publication';
const PUB_SINCE = '2026-09-01';   // older notices were reported before Pendant existed: never backfilled

/** [key, short label, css class] for the basis at filing ($filed) and now ($curr), both DocketTrak style ("1a", "1b", "1a, 1b", "44e", "66a"). */
function pub_story(string $curr, string $filed): array {
    $has = fn(string $b, string $x) => (bool)preg_match('/(^|[\s,])' . preg_quote($x, '/') . '($|[\s,])/', $b);
    $curr = strtolower(preg_replace('/\s+/', ' ', trim($curr))); $filed = strtolower(preg_replace('/\s+/', ' ', trim($filed)));
    if ($curr === '' && $filed === '') return ['unknown', 'Basis not known yet', 'unk'];
    if ($curr === '') $curr = $filed;
    if ($has($curr, '66a')) return ['66a', 'Madrid extension (66a)', 'madrid'];
    if ($has($curr, '1b') && $has($curr, '1a')) return ['mixed', 'Part in use, part intent to use', 'mixed'];
    if ($has($curr, '1b')) return ['itu', 'Intent to use (1b)', 'itu'];
    if ($has($curr, '1a') && $has($filed, '1b')) return ['amended', 'Filed intent to use, now in use', 'amended'];
    if ($has($curr, '1a')) return ['use', 'In use when filed (1a)', 'use'];
    if ($has($curr, '44e')) return ['44e', 'Foreign registration (44e)', 'foreign'];
    return ['unknown', 'Basis: ' . $curr, 'unk'];
}
/** What the client will receive next, in one line, for the panel. */
function pub_next_label(string $key): string {
    return ['itu' => 'Notice of Allowance, then 6 months to file the Statement of Use', 'mixed' => 'Notice of Allowance, then a Statement of Use for the items not yet in use',
            'amended' => 'Registration certificate (use already shown)', 'use' => 'Registration certificate', '44e' => 'Registration certificate', '66a' => 'U.S. registration (protection granted)'][$key]
        ?? 'Notice of Allowance or registration certificate';
}
/** {PUB_NEXT}: the paragraph after the opposition sentence. Unknown basis keeps the paralegal's both-ways wording. */
function pub_next_text(string $key): string {
    $sou = 'It will set a six month deadline to file a Statement of Use showing the U.S. Trademark Office how you are using the mark in commerce. If the mark is not yet in use by then, the deadline can be extended in six month steps.';
    switch ($key) {
        case 'itu': return "Because we filed this application on an \"intent to use\" basis, a few weeks after the opposition period ends we expect to receive a \"Notice of Allowance\". $sou";
        case 'mixed': return "Because this application covers some goods or services that were already in use and others on an \"intent to use\" basis, a few weeks after the opposition period ends we expect to receive a \"Notice of Allowance\". It will set a six month deadline to file a Statement of Use for the goods or services that were not yet in use when we filed. If they are not yet in use by then, the deadline can be extended in six month steps.";
        case 'amended': return 'Although we filed this application on an "intent to use" basis, we have since shown the U.S. Trademark Office that the mark is in use, so no Statement of Use will be needed. A few weeks after the opposition period ends, we expect to receive your trademark registration certificate.';
        case 'use': return 'Because we filed this application showing that the mark is already in use, no further proof of use is needed. A few weeks after the opposition period ends, we expect to receive your trademark registration certificate.';
        case '44e': return 'Because this application is based on your foreign registration, no proof of use is needed before registration. A few weeks after the opposition period ends, we expect to receive your U.S. trademark registration certificate.';
        case '66a': return 'Because this application is the extension of your international registration to the United States under the Madrid Protocol, no proof of use is needed before registration. A few weeks after the opposition period ends, we expect the mark to register in the United States, and the U.S. Trademark Office will notify the International Bureau that protection is granted.';
    }
    return "Otherwise, a few weeks after the opposition period ends, we will receive one of:\n\n- a \"Notice of Allowance\" setting out a six month deadline to show how you are using the mark if we filed the mark on an \"intent to use basis\"; or\n\n- your trademark registration certificate.";
}
/** The story for a matter: stored basis + basis at filing (refreshed from TSDR by tsdr_lookup). */
function pub_story_for(array $m): array { return pub_story((string)($m['basis'] ?? ''), (string)($m['basis_filed'] ?? '')); }

/** Every notice of publication since PUB_SINCE (last 120 days), newest first, one row per mark, with its report to-do and how it went out. */
function pub_rows(): array {
    $since = max(PUB_SINCE, add_days(today(), -120, false)); $out = [];
    $rows = q("SELECT c.id cid, c.mail_date pub_date, c.created_at notice_at, m.*, cl.name client_name
               FROM correspondence c JOIN matters m ON m.id=c.matter_id LEFT JOIN clients cl ON cl.code=m.client_code
               WHERE c.doc_code='NOTICE OF PUBLICATION' AND c.mail_date >= ? AND m.kind='trademark' ORDER BY c.mail_date DESC, m.ref", [$since]);
    foreach ($rows as $r) {
        if (isset($out[$r['id']])) continue;
        $r['todo'] = q1("SELECT * FROM events WHERE matter_id=? AND type=? AND status<>'cancelled' AND mail_date >= ? ORDER BY id DESC LIMIT 1", [$r['id'], PUB_REPORT, add_days($r['pub_date'], -60, false)]);
        $r['sent'] = $r['todo'] ? q1("SELECT * FROM outbox WHERE event_id=? AND mode='sent' ORDER BY id DESC LIMIT 1", [$r['todo']['id']]) : null;
        $opp = q1("SELECT due_date FROM events WHERE matter_id=? AND type=? AND status<>'cancelled' AND due_date > ? ORDER BY due_date DESC LIMIT 1", [$r['id'], OPP_END_EVENT, $r['pub_date']]);
        $r['opp_end'] = $opp['due_date'] ?? add_days($r['pub_date'], 30, false);
        $r['story'] = pub_story_for($r);
        $t = $r['todo']; $r['state'] = !empty($r['dead']) ? 'inactive' : (!$t ? 'none' : ($t['status'] === 'open' ? 'tosend' : ($r['sent'] ? 'sent' : 'byhand')));
        $out[$r['id']] = $r;
    }
    return array_values($out);
}
function pub_open_count(): int {
    return (int)(q1("SELECT count(*) c FROM events e JOIN matters m ON m.id=e.matter_id WHERE e.type=? AND e.status='open' AND m.dead=0", [PUB_REPORT])['c'] ?? 0);
}

/** One step on the card's timeline. $on = reached, $now = the current position. */
function pub_step(string $label, string $date, bool $on, bool $now, string $sub = ''): string {
    return '<li class="' . ($on ? 'on' : '') . ($now ? ' now' : '') . '"><i></i><b>' . h($label) . '</b><span>' . h($date) . '</span>' . ($sub !== '' ? '<em>' . h($sub) . '</em>' : '') . '</li>';
}
function pub_card(array $r): string {
    $t = today(); [$key, $blabel, $bcls] = $r['story']; $todo = $r['todo']; $back = url('dash', ['show' => 'pub']);
    $logo = logo_serial($r); $title = clean_title((string)$r['title']);
    $mark = $logo !== '' ? '<img class="pub-logo" src="' . h(logo_url($logo)) . '" alt="' . h($title ?: 'design mark') . '" onerror="this.nextElementSibling&&this.nextElementSibling.classList.remove(\'sm\');this.remove()">' . ($title !== '' ? '<div class="pub-markname sm">' . h($title) . '</div>' : '')
                         : '<div class="pub-markname">' . h($title ?: 'design mark') . '</div>';
    $chip = ['tosend' => ['Email to send', 'amber'], 'sent' => ['Reported', 'green'], 'byhand' => ['Reported', 'green'], 'none' => ['No report queued', 'grey'], 'inactive' => ['Inactive', 'grey']][$r['state']];
    $filed = substr((string)$r['filing_date'], 0, 10); $pub = $r['pub_date']; $opp = $r['opp_end'];
    $nextLbl = in_array($key, ['itu', 'mixed'], true) ? 'Notice of Allowance' : (in_array($key, ['unknown'], true) ? 'Allowance or registration' : 'Registration');
    $steps = pub_step('Filed', $filed !== '' ? fmt_date($filed) : 'date unknown', true, false, in_array($key, ['itu', 'mixed', 'amended'], true) ? 'intent to use' : ($key === 'use' ? 'in use' : ($key === '66a' ? 'Madrid' : '')))
           . pub_step('Published', fmt_date($pub), $pub <= $t, $pub > $t)
           . pub_step('Opposition ends', fmt_date($opp), $opp < $t, $pub <= $t && $opp >= $t)
           . pub_step($nextLbl, 'a few weeks later', false, $opp < $t);
    $how = '';
    if ($r['state'] === 'sent') $how = 'Emailed ' . h(fmt_date(substr((string)($r['sent']['transmitted_at'] ?: $r['sent']['created_at']), 0, 10))) . ' by ' . h((string)$r['sent']['from_user']) . ' to ' . h((string)$r['sent']['to_email']);
    elseif ($r['state'] === 'byhand') $how = h(preg_replace('/^.*\|\s*/', '', (string)$todo['note']) ?: 'Marked reported');
    $acts = '';
    if ($r['state'] === 'tosend') {
        $acts = '<a class="btn gold" href="' . h(url('send', ['id' => $todo['id'], 'back' => $back])) . '">Preview &amp; send</a> '
              . btn('pub_reported', ['id' => $todo['id'], 'back' => $back], 'Already reported', 'small ghost', 'Mark the ' . $r['ref'] . ' publication report as already sent to the client (outside Pendant)?');
    } elseif ($r['state'] === 'none') $acts = btn('pub_queue', ['matter_id' => $r['id'], 'cid' => $r['cid'], 'back' => $back], 'Queue the client email', 'small');
    elseif (in_array($r['state'], ['sent', 'byhand'], true) && $r['sent']) $acts = '<a class="btn small ghost" href="' . h(url('send', ['id' => $todo['id'], 'ob' => $r['sent']['id'], 'back' => $back])) . '">View email</a>';
    $sn = preg_replace('/\D/', '', (string)$r['app_no']);
    return '<article class="pub-card ' . $r['state'] . '">'
        . '<div class="pub-top"><a class="pub-ref" href="' . h(url('matter', ['id' => $r['id']])) . '">' . h($r['ref']) . '</a><span class="pub-client">' . h(trunc((string)($r['client_name'] ?: $r['owner'] ?: ''), 42)) . '</span><span class="pub-chip ' . $chip[1] . '">' . $chip[0] . '</span></div>'
        . '<div class="pub-mark">' . $mark . '</div>'
        . '<div class="pub-facts">' . ($sn !== '' ? '<a href="https://tsdr.uspto.gov/#caseNumber=' . $sn . '&caseType=SERIAL_NO&searchType=statusSearch" target="_blank" rel="noopener">SN ' . h(fmt_app((string)$r['app_no'], 'United States')) . '</a>' : '') . '<span class="pub-basis ' . $bcls . '">' . h($blabel) . '</span></div>'
        . '<ol class="pub-line">' . $steps . '</ol>' . (function_exists('other_firm_warning') ? other_firm_warning($r) : '')
        . '<p class="pub-says"><span>The email tells the client</span>' . h(pub_next_label($key)) . ($key === 'unknown' ? ' <em>(basis not read from TSDR yet: both outcomes are described)</em>' : '') . '</p>'
        . '<div class="pub-foot">' . ($how !== '' ? '<span class="pub-how">' . $how . '</span>' : '') . '<span class="pub-acts">' . $acts . '</span></div></article>';
}
function pub_panel(): string {
    $rows = pub_rows(); $by = ['tosend' => [], 'done' => []];
    foreach ($rows as $r) $by[in_array($r['state'], ['tosend', 'none'], true) ? 'tosend' : 'done'][] = $r;
    $o = '<section class="pub"><div class="pub-head"><div><h2>Notices of publication</h2><p class="muted">Each notice the USPTO sends becomes one client email: the paralegal\'s wording, the next step set by the filing basis (intent to use or in use, read from TSDR), the filing and publication dates filled in, and the notice itself forwarded below the signature. Nothing is sent until you click Send on the preview.</p></div>'
       . '<div class="pub-tools">' . btn('pub_sweep', ['back' => url('dash', ['show' => 'pub'])], 'Check Gmail for reports sent by hand', 'small ghost') . '</div></div>';
    $o .= '<h3 class="pub-sub">To send <span>' . count($by['tosend']) . '</span></h3>';
    $o .= $by['tosend'] ? '<div class="pub-grid">' . implode('', array_map('pub_card', $by['tosend'])) . '</div>' : '<p class="pub-empty">Every notice of publication has been reported to the client.</p>';
    if ($by['done']) $o .= '<details class="pub-done"' . (!$by['tosend'] ? ' open' : '') . '><summary><h3 class="pub-sub">Reported <span>' . count($by['done']) . '</span></h3></summary><div class="pub-grid">' . implode('', array_map('pub_card', $by['done'])) . '</div></details>';
    return $o . '</section>';
}

/** Queue the report for one notice (also the panel's "Queue the client email" button). */
function pub_queue_for(array $m, array $c): ?int {
    $gid = (string)($c['gmail_id'] ?? '');
    return queue_notice_report($m, 'NOTICE OF PUBLICATION', (string)$c['mail_date'], $gid);
}
/** A report the client already got outside Pendant: a firm address wrote to someone outside, about this file, with the publication wording. */
function pub_found_in_gmail(array $m, string $pubDate): ?array {
    if (!function_exists('gmail_cfg') || !($c = gmail_cfg())) return null;
    $ref = trim((string)$m['ref']); if (!preg_match('/^[A-Z0-9.\-]{3,}$/i', $ref)) return null;
    $c['max_threads'] = 10; $after = strtotime(add_days($pubDate, -21, false));   // the notice comes ~6 days before the TMOG date
    foreach (gm_search_threads('subject:"' . $ref . '" ("examination phase" OR "opposition period" OR "approved for publication") -from:uspto.gov newer_than:120d', $c) as $tid) {
        $res = gm_api('/users/me/threads/' . rawurlencode($tid), ['format' => 'metadata', 'metadataHeaders' => ['From', 'To', 'Cc', 'Subject']], $c);
        foreach ($res['messages'] ?? [] as $msg) {
            if (in_array('DRAFT', $msg['labelIds'] ?? [], true)) continue;
            $ts = (int)(($msg['internalDate'] ?? 0) / 1000); if ($ts < $after) continue;
            $H = []; foreach ($msg['payload']['headers'] ?? [] as $hd) $H[strtolower($hd['name'])] = (string)$hd['value'];
            if (stripos($H['subject'] ?? '', $ref) === false) continue;
            $from = hdr_addresses($H['from'] ?? ''); if (!$from || !firm_own_address($from[0])) continue;
            $to = array_values(array_filter(array_merge(hdr_addresses($H['to'] ?? ''), hdr_addresses($H['cc'] ?? '')), fn($a) => !firm_address($a)));
            if ($to) return ['date' => date('Y-m-d', $ts), 'from' => $from[0], 'to' => $to];
        }
    }
    return null;
}
/** Hourly (and the panel's button): queue missing reports, read missing bases from TSDR, close reports already sent by hand. */
function pub_sweep(bool $gmail = true): array {
    $log = [];
    foreach (q("SELECT c.*, m.id mid FROM correspondence c JOIN matters m ON m.id=c.matter_id WHERE c.doc_code='NOTICE OF PUBLICATION' AND c.mail_date >= ? AND m.kind='trademark' AND m.dead=0", [max(PUB_SINCE, add_days(today(), -60, false))]) as $c) {
        $m = q1('SELECT * FROM matters WHERE id=?', [$c['mid']]);
        if (!q1('SELECT 1 FROM events WHERE matter_id=? AND type=? AND status<>"cancelled" AND mail_date >= ?', [$m['id'], PUB_REPORT, add_days($c['mail_date'], -60, false)]) && function_exists('firm_of_record') && ($of = firm_of_record($m)) !== '') { $log[] = $m['ref'] . ': another firm of record (' . $of . '); no publication report queued'; continue; }   // 2026-10-04
        if (pub_queue_for($m, $c)) $log[] = $m['ref'] . ': client email queued (publication ' . $c['mail_date'] . ')';
    }
    foreach (pub_rows() as $r) {
        if ($r['state'] === 'inactive') continue;
        $sn = preg_replace('/\D/', '', (string)$r['app_no']);
        if (trim((string)$r['basis_filed']) === '' && strlen($sn) === 8 && !empty($GLOBALS['cfg']['tsdr_key']) && function_exists('tsdr_lookup')) {
            try { $l = tsdr_lookup($sn); if (!empty($l['ok'])) $log[] = $r['ref'] . ': basis read from TSDR (filed ' . ($l['basis_filed'] ?: '?') . ', now ' . ($l['basis'] ?: '?') . ')'; } catch (Throwable $e) { $log[] = $r['ref'] . ': TSDR ' . $e->getMessage(); }
            usleep(1100000);   // TSDR 429s above ~1 call/s
        }
        if ($gmail && $r['state'] === 'tosend') {
            try { $f = pub_found_in_gmail($r, (string)$r['pub_date']); } catch (Throwable $e) { $log[] = $r['ref'] . ': Gmail ' . $e->getMessage(); continue; }
            if (!$f) continue;
            $who = preg_replace('/@.*/', '', $f['from']);
            run('UPDATE events SET status="done", done_by=?, done_at=datetime("now"), note=note_add(note, ?) WHERE id=? AND status="open"',
                [$who, 'Reported by email ' . fmt_date($f['date']) . ' by ' . $who . ' to ' . implode(', ', $f['to']) . ' (found in Gmail)', $r['todo']['id']]);
            audit('notice_report', $r['ref'] . ' publication report already sent by hand ' . $f['date'] . ' (' . $f['from'] . '): to-do closed', (int)$r['id']);
            $log[] = $r['ref'] . ': already reported by hand ' . $f['date'] . ' (' . $f['from'] . ' to ' . implode(', ', $f['to']) . '), closed';
        }
    }
    return $log;
}

/**
 * The card that opens the publication email (2026-10-04, "love it, make it prettier"): mark, numbers, basis, and a four-step
 * timeline in the firm's navy and gold. Email-safe HTML only (tables, inline styles), like filing_card_html(); it is shown read-only above
 * the editor on the send page and stored in outbox.card_html when sent.
 */
function pub_email_card(array $m, array $ev): string { return tm_card($m, $ev, 'Approved for publication'); }   // now the shared trademark card (lib/tmtimeline.php)

/** "October 6, 2026" (or "Oct 6" short). */
function fmt_date_words(string $iso, bool $short = false): string { return $iso === '' ? '' : (new DateTimeImmutable($iso))->format($short ? 'M j' : 'M j, Y'); }
