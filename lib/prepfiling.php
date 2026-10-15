<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * Prepare filing (2026-10-11): before a new US patent application is filed, from the New filing page.
 *   - the latest specification and figures in the file are copied to REF-NNx-Specification-final / REF-NNx-Figures-final
 *     (every format of that version: .docx + .pdf, .pptx + .pdf);
 *   - the title is read from the specification (the paragraph after "TITLE");
 *   - declaration (PTO/AIA/01) per inventor; power of attorney: PTO/AIA/82B per inventor-applicant, or with an assignment
 *     PTO/AIA/80 by the assignee + the 3.73(c) statement (PTO/AIA/96); micro entity: PTO/SB/15A (per inventor, or by the assignee);
 *     small entity needs no paper; assignment: Templates/Patent Assignment.docx; IDS: Sienna (the Patent Tools IDS generator).
 *   - each form also gets a link on the /S/ signing page (/private/?d=..., sign_pdf.py); nothing is sent to anyone.
 * The forms are built by bin/prepare_filing.py from the blank USPTO forms in templates/forms/.
 */
const PAT_SIGN_EVENT = 'Ask Client to Sign Patent Papers';   // the to-do whose Send page carries the signing email (2026-10-11)
const PREP_FORMS = ['declaration' => 'Declaration', 'poa82b' => 'POA', 'poa80' => 'POA', 's373c' => '3.73(c) Statement', 'micro' => 'Micro Entity Certification'];

/** [code, rest-of-name] for "REF-01e-Specification-final.docx"; the ref part may be spelled differently (XYZ003 folder, XYZ002 files). */
function prep_code(string $name): ?array {
    return preg_match('/^.+?-(\d{2})([a-z])\d?-(.+)$/i', $name, $m) ? [(int)$m[1] * 100 + (ord(strtolower($m[2])) - 96), $m[3]] : null;
}
/** The newest version of the specification ('spec') or figures ('fig'): every file of that code and stem. [] when none. */
function prep_latest(array $files, string $what): array {
    $re = $what === 'spec' ? '/specification|\bspec\b/i' : '/figure|drawing/i';
    $best = null; $hits = [];
    foreach ($files as $f) {
        $n = (string)$f['name']; $c = prep_code($n); if (!$c || !preg_match($re, $c[1]) || preg_match('/comment|marked|redline|track/i', $c[1])) continue;
        if ($what === 'spec' && preg_match('/figure|drawing|claims?\b(?!.*spec)/i', $c[1])) continue;
        $stem = strtolower(pathinfo($c[1], PATHINFO_FILENAME));
        if ($best === null || $c[0] > $best[0]) { $best = [$c[0], $stem]; $hits = []; }
        if ($c[0] === $best[0] && $stem === $best[1]) $hits[] = $f;
    }
    return $hits;
}

function prep_py(string $args): string {
    return (string)shell_exec('python3 ' . escapeshellarg(dirname(__DIR__) . '/bin/prepare_filing.py') . ' ' . $args . ' 2>&1');
}

/** Where the /S/ signing page keeps its documents (the server: /private/docs beside /private/madder). '' = no links (laptop, tests). */
function prep_esign_dir(): string {
    $d = (string)($GLOBALS['cfg']['esign_docs'] ?? dirname(__DIR__, 2) . '/docs');
    return is_dir($d) && is_file(dirname($d) . '/sign_pdf.py') ? $d : '';
}

/** A signing link for a flat form: base PDF + preview PNGs + the page's JSON. Returns the URL. */
function prep_sign_link(string $dir, string $ref, string $pdf, array $sign, array $doc): string {
    $id = $doc['id'] ?? prep_sign_id($ref, $doc['slug']);
    copy($pdf, "$dir/$id-base.pdf");
    $pngs = [];
    exec('gs -q -dNOPAUSE -dBATCH -sDEVICE=png16m -r110 -o ' . escapeshellarg("$dir/$id-p%d.png") . ' ' . escapeshellarg($pdf) . ' 2>&1', $o, $rc);
    for ($i = 1; is_file("$dir/$id-p$i.png"); $i++) { $pngs[] = "$id-p$i.png"; @chmod("$dir/$id-p$i.png", 0644); }
    $cfg = ['doc_id' => $id, 'base_pdf' => "$id-base.pdf", 'preview_png' => $pngs, 'matter' => $ref, 'title' => $doc['title'], 'inventor' => $doc['inventor'],
            'signer' => $doc['signer'], 'signer_label' => 'Signing as', 'capacity' => $doc['capacity'], 'instrument' => $doc['instrument'],
            'doc_heading' => $doc['heading'], 'page_title' => 'Sign the ' . $doc['form'], 'bar_sub' => 'Secure document signing',
            'view_label' => 'View the full form', 'button' => 'Sign & Submit', 'done_heading' => 'Thank you — the ' . $doc['form'] . ' is signed.',
            'form_name' => $doc['form_name'], 'signed_prefix' => "$ref-" . $doc['prefix'] . '-signed',
            'email_to' => firm('signed_forms_to'), 'email_from' => firm('noreply_from'), 'email_subject' => 'SIGNED ' . $doc['form'] . " — $ref (" . $doc['signer'] . ')',
            'placements' => $doc['placements'] ?? [['page' => (int)$sign['page'], 'sig' => $sign['sig'], 'date' => $sign['date']]]];
    if (!empty($doc['intro'])) $cfg['intro'] = $doc['intro'];
    foreach ($doc['extra'] ?? [] as $k => $v) $cfg[$k] = $v;   // sig_mode draw, base_from_signed, subject_label ...
    file_put_contents("$dir/$id.json", json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    @chmod("$dir/$id.json", 0644); @chmod("$dir/$id-base.pdf", 0644);
    return rtrim((string)firm('public_url'), '/') . '/private/?d=' . $id;
}

/** Patent or trademark, from what is in the matter folder (2026-10-11: Prepare filing is used for trademarks too; the patent
 *  parts show only for a patent file). Papers decide first, then the record. '' = no folder / cannot tell. */
function prep_kind(string $ref): string {
    $f = mf_find(strtoupper(trim($ref))); $p = 0; $t = 0;
    if ($f) foreach (mf_list($f, true) as $x) {
        $n = (string)$x['rel'];
        if (preg_match('/specification|figures|drawings?\b|\bclaims\b|novelty|prior art\/|invention disclosure|disclosure from inventor|\bIDS\b|provisional/i', $n)) $p++;
        if (preg_match('/trademark|specimen|\bTEAS\b|knock-?out|TSDR|^receipt-\d{8}|mark drawing|statement of use|clearance/i', $n)) $t++;
    }
    if ($p !== $t) return $p > $t ? 'patent' : 'trademark';
    $m = q1('SELECT kind FROM matters WHERE ref=? AND kind IN ("patent","trademark") ORDER BY dead, id DESC', [strtoupper(trim($ref))]);
    return $m ? (string)$m['kind'] : ($f ? '' : 'nofolder');
}

/** The Patent Center fixers (Vermilion uspto-docx / uspto-pdf): beside Madder on the server (/patent-tools/bin), or the repo on the laptop. */
function prep_tools_dir(): string {
    foreach ([(string)($GLOBALS['cfg']['uspto_tools'] ?? ''), dirname(__DIR__, 3) . '/patent-tools/bin', getenv('HOME') . '/Projects/veered-public/vermilion-patent-pdf-tools/bin'] as $d)
        if ($d !== '' && is_file("$d/uspto-docx") && is_file("$d/uspto-pdf")) return $d;
    return '';
}

/** The patent record for $ref (created, no application number yet, when there is none; the filing receipt fills it in later). */
function prep_matter(string $ref, string $title): array {
    $m = q1('SELECT * FROM matters WHERE ref=? AND kind="patent" ORDER BY dead, id DESC', [$ref]);
    if (!$m) {
        $code = client_from_ref($ref); if ($code !== '') run('INSERT OR IGNORE INTO clients(code) VALUES (?)', [$code]);
        run('INSERT INTO matters(kind,ref,client_code,title,country,app_no,notes) VALUES ("patent",?,?,?,"United States","",?)', [$ref, $code, $title, 'Created by Prepare filing (New filing)']);
        $id = (int)db()->lastInsertId(); audit('add_matter', "$ref (Prepare filing)", $id); $m = q1('SELECT * FROM matters WHERE id=?', [$id]);
    } elseif (trim((string)$m['title']) === '' && $title !== '') run('UPDATE matters SET title=? WHERE id=?', [$title, $m['id']]);
    return q1('SELECT * FROM matters WHERE id=?', [$m['id']]);
}

/** The "Ask Client to Sign Patent Papers" to-do with its links (sign_requests, as the trademark signing email). Returns the event id. */
function prep_sign_todo(string $ref, string $title, array $links, array $attach): int {
    sign_schema(); $m = prep_matter($ref, $title);
    $note = 'Email the client the signing links: ' . implode('; ', array_column($links, 0));
    $open = q1('SELECT id FROM events WHERE matter_id=? AND type=? AND status IN ("open","proposed")', [$m['id'], PAT_SIGN_EVENT]);
    if ($open) { $eid = (int)$open['id']; run('UPDATE events SET note=?, due_date=? WHERE id=?', [$note, today(), $eid]); }
    else { run('INSERT INTO events(matter_id,type,due_date,status,source,note) VALUES (?,?,?,"open","rule",?)', [$m['id'], PAT_SIGN_EVENT, today(), $note]); $eid = (int)db()->lastInsertId(); }
    $data = [$ref => ['kind' => 'patent', 'title' => $title, 'links' => array_map(fn($l) => ['label' => $l[0], 'url' => $l[1]], $links), 'attach' => $attach]];
    run('INSERT OR REPLACE INTO sign_requests(event_id,refs,links,created_by) VALUES (?,?,?,?)', [$eid, $ref, json_encode($data), function_exists('auth_user') ? (string)auth_user() : '']);
    return $eid;
}

/** The signing email (the attorney's trademark signing email, SKY029, for a patent application's papers). Send-page markup: <n u="URL">text</n>. */
function prep_sign_email(array $sl): array {
    $ref = (string)array_key_first($sl); $d = $sl[$ref]; $title = (string)($d['title'] ?? ''); $links = (array)($d['links'] ?? []);
    $typed = array_values(array_filter($links, fn($l) => !str_starts_with((string)$l['label'], 'Assignment')));
    $drawn = array_values(array_filter($links, fn($l) => str_starts_with((string)$l['label'], 'Assignment')));
    $forms = array_values(array_unique(array_map(fn($l) => strtolower(preg_replace('/ — .*$/', '', (string)$l['label'])) === 'poa' ? 'power of attorney' : strtolower(preg_replace('/ — .*$/', '', (string)$l['label'])), $typed)));
    $forms = array_map(fn($f) => str_replace('3.73(c) statement', 'statement of ownership (37 CFR 3.73(c))', $f), $forms);
    $subject = "$ref: Your U.S. patent application" . ($title !== '' ? " for $title" : '') . ' is ready to sign';
    $body = 'Please find below links to view and sign the papers for your patent application' . ($title !== '' ? ', "' . $title . '"' : '') . '. First, read through each document and check for accuracy.';
    if (!empty($d['attach'])) $body .= ' The specification and figures, as they will be filed, are attached for your review.';
    $body .= "

";
    if ($typed) $body .= 'The ' . sign_list($forms) . ' ' . (count($forms) > 1 ? 'are' : 'is') . " signed by typing your name between two slashes, like this: " . firm('sign_example') . "

";
    if ($drawn) $body .= 'The assignment is signed by hand: use your mouse, or your finger on a phone or tablet, to write your signature in the box.' . (count($drawn) > 1 ? ' The inventors sign the assignment one after the other, in the order listed below.' : '') . "

";
    $body .= "After signing, email me to confirm, and we will file the application.

";
    foreach ($links as $l) { [$what, $who] = array_pad(explode(' — ', (string)$l['label'], 2), 2, ''); $what = $what === 'POA' ? 'Power of Attorney' : $what;
        $body .= ($who !== '' ? preg_replace('/\s*\(after .*\)$/', '', $who) . ': ' : '') . '<n u="' . $l['url'] . '">review and sign the ' . $what . '</n>' . "

"; }
    return ['subject' => $subject, 'body' => rtrim($body)];
}

/** A unique signing-page id: ref-slug-random. */
function prep_sign_id(string $ref, string $slug): string { return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $ref)) . '-' . $slug . '-' . substr(strtr(base64_encode(random_bytes(9)), '+/', '-_'), 0, 12); }

/** What the signer reads above the form ('' = the signing page's own declaration wording). */
function prep_intro(string $kind, bool $inventor, string $assignee, string $title): string {
    $who = $inventor ? 'you, as the applicant,' : $assignee . ', as owner of the application,';
    return match ($kind) {
        'poa82b', 'poa80' => "By this form $who appoint" . ($inventor ? '' : 's') . ' ' . firm('name') . (($cn = firm('customer_numbers')[0] ?? '') !== '' ? ' (USPTO Customer Number ' . $cn . ')' : '') . ' to represent ' . ($inventor ? 'you' : 'it') . ' before the United States Patent and Trademark Office and direct' . ($inventor ? '' : 's') . ' correspondence to the firm.' . ($inventor ? '' : ' You are signing on behalf of the company, not in your personal capacity.') . ' Please review the form:',
        's373c' => "This statement (USPTO form PTO/AIA/96) confirms that $assignee owns the entire right, title and interest in the application, by the assignment from the inventor(s), which is being recorded with the USPTO. You are signing on behalf of the company. Please review the form:",
        'micro' => 'This form (USPTO form PTO/SB/15A) certifies that the applicant qualifies for the reduced micro-entity government fees' . ($title !== '' ? " on the application for $title" : '') . '. By signing you confirm each of the four numbered statements on the form, including the gross-income limits. If any statement is not true, do not sign; call us' . (($ph = array_values(firm('phones'))[0] ?? '') !== '' ? ' at ' . $ph : '') . ' instead. Please review the form:',
        default => '',
    };
}

/** The IDS through Sienna (ids_gen.py): ODP, then the offline pre-2001 index. $refs empty = the PDF names in the "Prior Art" subfolder. */
function prep_ids(array $folder, string $ref, array $inventors, string $title, array $refs, string $out): array {
    $c = $GLOBALS['cfg']; $notes = [];
    $gen = (string)($c['sienna'] ?? ''); foreach ([$gen, dirname(__DIR__, 3) . '/patent-tools/bin/ids_gen.py', getenv('HOME') . '/Projects/veered-public/sienna-ids-generator/ids_gen.py'] as $g) if ($g !== '' && is_file($g)) { $gen = $g; break; }
    if (!is_file($gen)) return ['ok' => false, 'notes' => ['the IDS generator (Sienna ids_gen.py) is not on this machine']];
    $tmp = dirname($out) . '/ids-in'; @mkdir($tmp, 0700, true); $args = '';
    if ($refs) { file_put_contents("$tmp/refs.txt", implode("\n", $refs) . "\n"); $args .= ' --from-file ' . escapeshellarg("$tmp/refs.txt"); }
    else {
        $pa = array_filter(mf_list($folder, true), fn($f) => preg_match('#^prior art/[^/]+\.pdf$#i', (string)$f['rel']));
        if (!$pa) return ['ok' => false, 'notes' => ['IDS: no references given and no PDFs in the Prior Art folder']];
        @mkdir("$tmp/pa", 0700); foreach ($pa as $f) touch("$tmp/pa/" . basename((string)$f['name']));
        $args .= ' --from-folder ' . escapeshellarg("$tmp/pa"); $notes[] = 'IDS references: the ' . count($pa) . ' PDFs in Prior Art';
    }
    $old = (string)($c['sienna_old_db'] ?? firm('sienna_old_db')); if (!is_file($old)) $old = getenv('HOME') . '/Projects/sienna-olddb/old_patents.sqlite';
    if (is_file($old)) $args .= ' --old-db ' . escapeshellarg($old);
    foreach (['applicant' => implode(', ', $inventors), 'app-no' => 'To be assigned', 'filed' => 'Herewith', 'title' => $title, 'docket' => $ref, 'attorney' => firm('attorney') . (firm('attorney_reg') ? ', Reg. No. ' . firm('attorney_reg') : '')] as $k => $v) $args .= " --$k " . escapeshellarg($v);
    $env = 'USPTO_ODP_KEY=' . escapeshellarg((string)($c['odp_key'] ?? '')) . ' ';
    $o = (string)shell_exec($env . 'python3 ' . escapeshellarg($gen) . ' --source odp --no-google' . $args . ' -o ' . escapeshellarg($out) . ' --csv ' . escapeshellarg("$tmp/refs.csv") . ' 2>&1');
    if (is_file("$tmp/refs.csv") && ($fh = fopen("$tmp/refs.csv", 'r'))) {
        fgetcsv($fh, null, ',', '"', ''); while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) if (($r[7] ?? '') !== '' || (($r[3] ?? '') === '' && ($r[4] ?? '') === '')) $notes[] = 'IDS: ' . ($r[1] ?? '?') . ' ' . trim(($r[7] ?? '') ?: 'not found, fill in by hand');
        fclose($fh);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
    return ['ok' => is_file($out), 'notes' => is_file($out) ? $notes : array_merge($notes, ['IDS failed: ' . trim(substr($o, -300))])];
}

/**
 * $in: ref, inventors (one per line), entity (micro|small|undiscounted), assignment (bool), assignee, assignee_address (lines),
 * assignee_type, signer, signer_title, ids (bool), ids_refs (one per line), links (bool).
 * Returns ['ref', 'title', 'saved' => [names], 'links' => [[label, url]], 'notes' => [...]].
 */
function prepare_filing(array $in): array {
    $ref = strtoupper(trim((string)($in['ref'] ?? '')));
    if (!preg_match('/^[A-Z]{2,5}\d{3}[A-Z0-9.\-]*$/', $ref)) throw new RuntimeException('That does not look like a file number (e.g. ABC001).');
    $inv = array_values(array_filter(array_map(fn($l) => trim(preg_replace('/\s+/', ' ', $l)), preg_split('/\R|;/', (string)($in['inventors'] ?? '')) ?: []), 'strlen'));
    if (!$inv) throw new RuntimeException('Inventor names are needed.');
    $asg = !empty($in['assignment']);
    $assignee = trim((string)($in['assignee'] ?? '')); $addr = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($in['assignee_address'] ?? '')) ?: []), 'strlen'));
    $signer = trim((string)($in['signer'] ?? '')); $signerTitle = trim((string)($in['signer_title'] ?? ''));
    if ($asg && ($assignee === '' || $signer === '')) throw new RuntimeException('Assignment: the assignee and the person signing for it are needed.');
    $entity = in_array($in['entity'] ?? '', ['micro', 'small', 'undiscounted'], true) ? $in['entity'] : 'undiscounted';
    $folder = mf_find($ref); if (!$folder) throw new RuntimeException("No folder for $ref in Dropbox.");
    if (prep_kind($ref) !== 'patent') throw new RuntimeException("$ref is not a patent file.");
    $files = mf_list($folder); $names = array_column($files, 'name');
    $saved = []; $notes = []; $links = [];
    $next = function () use (&$names, &$saved, $ref): string { return next_doc_code(array_merge($names, $saved), $ref) ?: '01a'; };
    // 1. the final specification and figures
    $title = '';
    foreach (['spec' => 'Specification', 'fig' => 'Figures'] as $what => $label) {
        $hits = prep_latest($files, $what);
        if (!$hits) { $notes[] = "no $label found in the file"; continue; }
        $docx = current(array_filter($hits, fn($f) => preg_match('/\.docx$/i', (string)$f['name'])));
        if ($what === 'spec' && $docx && ($p = mf_local($folder, (string)$docx['rel'])) !== '') $title = trim(prep_py('title ' . escapeshellarg($p)));
        if (preg_match('/final/i', (string)$hits[0]['name'])) { $notes[] = 'already final: ' . implode(', ', array_column($hits, 'name')); continue; }
        $code = $next(); $tools = prep_tools_dir();
        if ($tools === '') $notes[] = 'the Patent Center checks (uspto-docx / uspto-pdf) are not on this machine: copied unchecked';
        $exts = array_map(fn($f) => strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)), $hits);
        if ($what === 'fig' && !in_array('pdf', $exts, true)) $notes[] = 'FIGURES NEED A PDF: the latest version is ' . implode(', ', array_column($hits, 'name')) . ' only (Patent Center takes PDF)';
        if ($what === 'spec' && !in_array('docx', $exts, true)) $notes[] = 'SPECIFICATION NEEDS A DOCX: the latest version is ' . implode(', ', array_column($hits, 'name')) . ' only';
        foreach ($hits as $f) {
            $p = mf_local($folder, (string)$f['rel']); if ($p === '') { $notes[] = 'could not read ' . $f['name']; continue; }
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)); $nm = "$ref-$code-$label-final.$ext"; $bytes = (string)file_get_contents($p);
            if ($tools !== '') {   // 2026-10-11: the final spec and figures must go into Patent Center without errors
                $out = scratch_dir() . '/final-' . bin2hex(random_bytes(4)) . '-' . preg_replace('/[^A-Za-z0-9.]/', '_', $nm);
                $raw = prep_py('finalize ' . escapeshellarg($p) . ' ' . escapeshellarg($out) . ' ' . $what . ' ' . escapeshellarg($tools));
                $fr = null; foreach (array_reverse(preg_split('/\R/', trim($raw)) ?: []) as $line) if (is_array($fr = json_decode($line, true))) break;
                if (is_array($fr) && !empty($fr['ok']) && is_file($out)) { $bytes = (string)file_get_contents($out); $notes = array_merge($notes, str_replace(basename($out), $nm, (array)$fr['notes'])); }
                else $notes[] = "$nm copied UNCHECKED: " . (is_array($fr) ? implode('; ', (array)$fr['notes']) : trim(substr($raw, -300)));
                @unlink($out);
            }
            $saved[] = mf_write($folder, $nm, $bytes);
        }
        $notes[] = "$label-final made from " . implode(', ', array_column($hits, 'name'));
    }
    if ($title === '') { $title = clean_title((string)(q1('SELECT title FROM matters WHERE ref=? AND kind="patent" ORDER BY id DESC', [$ref])['title'] ?? '')); $notes[] = $title !== '' ? 'title taken from the record (none found in the specification)' : 'TITLE NEEDED: none in the specification or the record'; }
    // 2. the forms
    $tmp = scratch_dir() . '/prep-' . preg_replace('/[^A-Za-z0-9]/', '_', $ref) . '-' . bin2hex(random_bytes(4)); @mkdir($tmp, 0700, true);
    $jobs = []; $n = count($inv); $first = $inv[0];
    $label = fn(string $base, string $who) => $n > 1 ? "$base ($who)" : $base;
    foreach ($inv as $i => $who) $jobs[] = ['kind' => 'declaration', 'title' => $title, 'inventor' => $who, 'label' => $label('Declaration', $who) . '-unsigned', 'signer' => $who, 'inv' => true];
    if ($asg) {
        $jobs[] = ['kind' => 'poa80', 'assignee' => $assignee, 'address_lines' => $addr, 'signer' => $signer, 'signer_title' => $signerTitle, 'label' => 'POA-unsigned'];
        $jobs[] = ['kind' => 's373c', 'first_inventor' => $first, 'title' => $title, 'assignee' => $assignee, 'assignee_type' => trim((string)($in['assignee_type'] ?? '')), 'signer' => $signer, 'signer_title' => $signerTitle, 'label' => '3.73(c) Statement-unsigned'];
    } else foreach ($inv as $who) $jobs[] = ['kind' => 'poa82b', 'applicant' => $who, 'forms_total' => $n, 'label' => $label('POA', $who) . '-unsigned', 'signer' => $who, 'inv' => true];
    if ($entity === 'micro') {
        if ($asg) $jobs[] = ['kind' => 'micro', 'title' => $title, 'first_inventor' => $first, 'signer' => $signer, 'joint' => false, 'label' => 'Micro Entity Certification-unsigned'];
        else foreach ($inv as $who) $jobs[] = ['kind' => 'micro', 'title' => $title, 'first_inventor' => $first, 'signer' => $who, 'joint' => $n > 1, 'label' => $label('Micro Entity Certification', $who) . '-unsigned', 'inv' => true];
    }
    if ($asg) {
        $tpl = ($tf = mf_find('Templates')) ? mf_local($tf, 'Patent Assignment.docx') : ''; if ($tpl === '') $tpl = dirname(__DIR__) . '/templates/Patent Assignment.docx';
        $jobs[] = ['kind' => 'assignment', 'template' => $tpl, 'inventors' => $inv, 'title' => $title, 'assignee' => $assignee, 'address_lines' => $addr, 'label' => 'Assignment-unsigned', 'ext' => 'docx', 'font' => dirname(__DIR__) . '/templates/Lexend-Regular.ttf'];
    }
    foreach ($jobs as $i => &$j) { $j['out'] = "$tmp/$i." . ($j['ext'] ?? 'pdf'); if ($j['kind'] === 'assignment') $j['pdf'] = "$tmp/$i.pdf"; } unset($j);
    file_put_contents("$tmp/jobs.json", json_encode(['forms' => dirname(__DIR__) . '/templates/forms', 'font' => dirname(__DIR__) . '/templates/Lexend-Regular.ttf', 'jobs' => $jobs]));
    $raw = prep_py('build ' . escapeshellarg("$tmp/jobs.json")); $res = null; foreach (array_reverse(preg_split('/\R/', trim($raw)) ?: []) as $line) if (is_array($res = json_decode($line, true))) break;   // the JSON line (library warnings may surround it)
    if (!is_array($res)) throw new RuntimeException('The forms could not be built: ' . trim(substr($raw, -400)));
    $esign = !empty($in['links']) ? prep_esign_dir() : '';
    if (!empty($in['links']) && $esign === '') $notes[] = 'no signing links: the signing page is not on this machine';
    foreach ($jobs as $i => $j) {
        $r = $res[$i] ?? null;
        if (!$r || empty($r['ok'])) { $notes[] = $j['label'] . ' FAILED: ' . implode('; ', (array)($r['notes'] ?? [])); continue; }
        $code = $next(); $saved[] = $nm = mf_write($folder, "$ref-$code-" . $j['label'] . '.' . ($j['ext'] ?? 'pdf'), (string)file_get_contents($j['out']));
        if ($j['kind'] === 'assignment') {   // the PDF beside the .docx, signed by hand (mouse / finger) on the signing page, one link per assignor
            if (!is_file($j['pdf'])) { $notes[] = 'Assignment PDF could not be built: ' . implode('; ', (array)($r['notes'] ?? [])); continue; }
            $saved[] = mf_write($folder, "$ref-$code-" . $j['label'] . '.pdf', (string)file_get_contents($j['pdf']));
            if ($esign === '') continue;
            $prev = null;
            foreach ($inv as $k => $who) {
                $b = $r['sign']['boxes'][$k]; $id = prep_sign_id($ref, 'assignment');
                $extra = ['sig_mode' => 'draw', 'subject_label' => 'Invention'] + ($prev ? ['base_from_signed' => $prev] : []);
                $links[] = ['Assignment — ' . $who . ($prev ? ' (after ' . $inv[$k - 1] . ')' : ''), prep_sign_link($esign, $ref, $j['pdf'], [], [
                    'id' => $id, 'slug' => 'assignment', 'title' => $title, 'inventor' => $first, 'signer' => $who,
                    'capacity' => 'the inventor, assigning the invention and the patent application to ' . $assignee, 'instrument' => 'this assignment',
                    'heading' => 'Patent Application Assignment — ' . ($title !== '' ? $title : $ref), 'form' => 'Assignment', 'form_name' => 'Patent Application Assignment Agreement',
                    'prefix' => 'Assignment', 'placements' => [['page' => (int)$b['page'], 'sig_box' => $b['sig_box'], 'date' => $b['date']]],
                    'intro' => "By signing you assign to $assignee all of your right, title and interest in the invention, the patent application and any resulting patents, as set out in the agreement. Sign with your mouse or finger in the box. Please review the agreement:",
                    'extra' => $extra])];
                $prev = $id;
            }
            continue;
        }
        if ($esign !== '' && !empty($r['sign'])) {
            $form = PREP_FORMS[$j['kind']]; $isInv = !empty($j['inv']);
            $links[] = [$form . ' — ' . $j['signer'], prep_sign_link($esign, $ref, $j['out'], $r['sign'], [
                'slug' => ['declaration' => 'declaration', 'poa82b' => 'poa', 'poa80' => 'poa', 's373c' => 'statement373c', 'micro' => 'microentity'][$j['kind']],
                'title' => $title, 'inventor' => $first, 'signer' => $j['signer'],
                'capacity' => $isInv ? ($j['kind'] === 'declaration' ? 'the named inventor' : 'the applicant and inventor, signing in your own name') : ($signerTitle !== '' ? "the $signerTitle of $assignee, authorized to sign on its behalf" : "authorized to sign on behalf of $assignee"),
                'instrument' => 'this ' . strtolower($form === 'POA' ? 'power of attorney' : $form), 'heading' => "$form — " . ($title !== '' ? $title : $ref), 'form' => $form === 'POA' ? 'Power of Attorney' : $form,
                'form_name' => ['declaration' => 'Declaration — PTO/AIA/01', 'poa82b' => 'Power of Attorney — PTO/AIA/82B', 'poa80' => 'Power of Attorney — PTO/AIA/80 (signed by the assignee)', 's373c' => 'Statement under 37 CFR 3.73(c) — PTO/AIA/96', 'micro' => 'Certification of Micro Entity Status (Gross Income Basis) — PTO/SB/15A'][$j['kind']],
                'prefix' => preg_replace('/[^A-Za-z0-9]+/', '', ucwords(str_replace('-unsigned', '', $j['label']))),
                'intro' => prep_intro($j['kind'], $isInv, $assignee, $title),
            ])];
        }
    }
    if ($entity === 'small') $notes[] = 'small entity: no form; claim it in Patent Center';
    if (!empty($in['ids'])) {
        $refs = array_values(array_filter(array_map('trim', preg_split('/\R|;/', (string)($in['ids_refs'] ?? '')) ?: []), 'strlen'));
        $r = prep_ids($folder, $ref, $inv, $title, $refs, "$tmp/ids.docx"); $notes = array_merge($notes, $r['notes']);
        if ($r['ok']) { $code = $next(); $saved[] = mf_write($folder, "$ref-$code-IDS.docx", (string)file_get_contents("$tmp/ids.docx")); }
    }
    exec('rm -rf ' . escapeshellarg($tmp));
    $m = q1('SELECT id FROM matters WHERE ref=? AND kind="patent" ORDER BY id DESC', [$ref]);
    if ($m && $entity !== 'undiscounted') run('UPDATE matters SET entity=? WHERE id=? AND entity=""', [ucfirst($entity), $m['id']]);
    audit('prepare_filing', "$ref: " . count($saved) . ' papers prepared (' . $entity . ($asg ? ', assignment' : '') . (!empty($in['ids']) ? ', IDS' : '') . ')', $m['id'] ?? null);
    $eid = $links ? prep_sign_todo($ref, $title, $links, array_values(array_filter($saved, fn($n) => preg_match('/-(Specification|Figures)-final\.pdf$/i', $n)))) : null;
    return ['ref' => $ref, 'title' => $title, 'folder' => $folder['name'], 'saved' => $saved, 'links' => $links, 'notes' => $notes, 'event_id' => $eid];
}
