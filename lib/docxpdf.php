<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * Word letters on the send page (2026-10-05): each recent .docx in the matter folder gets an "Open DOCX" link
 * (ffdocx:// URL; one-time handler per computer in tools/ffdocx: Linux ffdocx-open, Windows ffdocx_win.ps1 + Word, Mac ffdocx_mac.sh).
 * When the letter is closed, that computer writes the PDF beside it (its own fonts, so Lexend is embedded) and drops "(AI written ...)"
 * from both names. Each time the browser tab gets focus the page asks ?p=docxpdf, which looks in
 * Dropbox directly (not the 10-minute index) and ticks the PDF as an attachment once it is at least as new as the .docx.
 */
const DOCX_AI_NOTE = '/\s*\(AI written[^)]*\)/i';   // "REF-NNx-Issuance Letter (AI written - advice only until lawyer review).docx"

/** The letter's name once reviewed: without the "(AI written ...)" note (the opener renames the .docx when it makes the PDF). */
function docx_clean_name(string $docx): string { return (string)preg_replace(DOCX_AI_NOTE, '', $docx); }
/** The PDF the opener writes for a letter: the reviewed name, ".pdf". */
function docx_pdf_name(string $docx): string { return (string)preg_replace('/\.docx?$/i', '', docx_clean_name($docx)) . '.pdf'; }

/** Word files saved in the folder in the last $days days, newest first (Office / LibreOffice lock files left out). */
function docx_list(array $dir, int $days = 45, int $max = 4): array {
    $out = [];
    foreach (mf_list($dir) as $f) if (preg_match('/\.docx?$/i', $f['name']) && !preg_match('/^(~\$|\.~lock\.)/', $f['name']) && $f['mtime'] >= time() - $days * 86400) $out[] = $f;
    return array_slice($out, 0, $max);
}

/** ffdocx://open/matters/REF/name.docx: the Dropbox path, which the laptop handler maps onto ~/Dropbox. */
function docx_open_url(array $dir, string $rel, string $action = 'open'): string {   // 'check': PDF + rename now, without opening
    $p = '/' . basename((string)$dir['root']) . '/' . $dir['name'] . '/' . $rel;
    return 'ffdocx://' . $action . implode('/', array_map('rawurlencode', explode('/', $p)));
}

/** The file number as a link that opens the matter folder on this computer (2026-10-08): ffdocx://folder/matters/<folder>; the
 *  handler (tools/ffdocx, same one as Open DOCX) finds Dropbox wherever it is on that computer. */
function folder_link(array $m): string {
    $f = function_exists('mf_find') ? mf_find((string)$m['ref']) : null;
    $u = 'ffdocx://folder/' . rawurlencode($f ? basename((string)$f['root']) : ltrim(DBX_WRITE_ROOT, '/')) . '/' . rawurlencode($f ? (string)$f['name'] : (string)$m['ref']);
    $rel = ($f ? basename((string)$f['root']) : ltrim(DBX_WRITE_ROOT, '/')) . '/' . ($f ? (string)$f['name'] : (string)$m['ref']);
    return '<a class="folderlink" data-rel="' . h($rel) . '" href="' . h($u) . '" style="color:inherit;text-decoration:underline;text-underline-offset:4px;text-decoration-thickness:2px" title="Open the ' . h($f ? $f['name'] : $m['ref']) . ' folder">' . h($m['ref']) . '</a>' . folder_link_script();
}
/** 2026-10-08: a computer without the handler gets nothing from the click (a web page cannot open a local folder by itself), so
 *  after 2.5 s with the page still in focus a box offers the one-time installer and, meanwhile, the folder's full path to paste into
 *  Explorer / Finder, built from the Dropbox location they type once (remembered in this browser). */
function folder_link_script(): string {
    static $done = false; if ($done) return ''; $done = true;
    return '<script>(function(){var K="madder_dropbox",kit=/Mac/i.test(navigator.platform)?"Mac":(/Win/i.test(navigator.platform)?"Windows":"");'
        . 'function get(){try{return localStorage.getItem(K)||"";}catch(e){return "";}}function put(v){try{localStorage.setItem(K,v);}catch(e){}}'
        . 'function esc(t){var d=document.createElement("div");d.textContent=t;return d.innerHTML;}'
        . 'var BS=String.fromCharCode(92);function full(root,rel){if(!root)return "";var w=root.indexOf(BS)>=0||kit==="Windows",sep=w?BS:"/";while(root.length>1&&(root.slice(-1)===BS||root.slice(-1)==="/"))root=root.slice(0,-1);return root+sep+rel.split("/").join(sep);}'
        . 'function show(a){var old=document.getElementById("fldbox");if(old)old.remove();var b=document.createElement("div");b.id="fldbox";b.className="box warnbox";'
        . 'b.style.cssText="position:absolute;z-index:50;max-width:560px;font-size:14px;font-weight:normal;margin-top:6px";var rel=a.dataset.rel;'
        . 'b.innerHTML="<div style=\"display:flex;justify-content:space-between;gap:10px\"><b>This computer can&rsquo;t open folders from Madder yet.</b><a href=\"#\" class=\"fldx\">close</a></div>"'
        . '+(kit?"<p style=\"margin:6px 0\">One-time setup: <a href=\""+' . json_encode(url('docxkit', [])) . '+"&os="+kit+"\">download the installer</a>, unzip it, run <b>Install on "+kit+"</b>. After that the file number opens the folder. <i>This installer has not been tested yet, though we expect it to work.</i></p>":"")'
        . '+"<p style=\"margin:6px 0\">Until then, your Dropbox folder on this computer: <input class=\"fldroot\" size=\"34\" placeholder=\""+(kit==="Windows"?["C:","Users","you","Dropbox"].join(BS):"/Users/you/Dropbox")+"\"></p>"'
        . '+"<p class=\"fldpath\" style=\"margin:6px 0\"></p>";'
        . 'a.parentNode.insertBefore(b,a.nextSibling);var inp=b.querySelector(".fldroot"),out=b.querySelector(".fldpath");inp.value=get();'
        . 'function draw(){var p=full(inp.value.trim(),rel);out.innerHTML=p?"Folder: <code>"+esc(p)+"</code> <button type=\"button\" class=\"small\">Copy</button> <span class=\"muted\">paste it into "+(kit==="Mac"?"Finder (Go &gt; Go to Folder)":"the File Explorer address bar")+"</span>":"";'
        . 'var bt=out.querySelector("button");if(bt)bt.onclick=function(){navigator.clipboard.writeText(p).then(function(){bt.textContent="Copied";});};}'
        . 'inp.addEventListener("input",function(){put(inp.value.trim());draw();});draw();b.querySelector(".fldx").onclick=function(e){e.preventDefault();b.remove();};}'
        . 'document.querySelectorAll(".folderlink").forEach(function(a){a.addEventListener("click",function(){var left=false;function gone(){left=true;}'
        . 'window.addEventListener("blur",gone,{once:true});document.addEventListener("visibilitychange",gone,{once:true});'
        . 'setTimeout(function(){window.removeEventListener("blur",gone);if(left||document.hidden)return;show(a);},2500);});});})();</script>';
}
/** Look the letter (under either name) and its PDF up in Dropbox now and put them in the index, so the send page can attach the PDF at once. */
function docx_refresh(array $dir, string $docx): void {
    if ($dir['mode'] !== 'dbx') return;
    foreach (array_unique([$docx, docx_clean_name($docx), docx_pdf_name($docx)]) as $n) {
        $path = $dir['dir'] . '/' . $n; if (dbx_forbidden($path)) continue;
        $r = dbx_rpc('files/get_metadata', ['path' => $path]);
        if ($r['ok'] && is_array($r['json'])) dbx_index_entry($r['json']);
        elseif ($r['code'] === 409 && str_contains($r['error'], 'not_found')) dbx_index_entry(['.tag' => 'deleted', 'path_display' => $path, 'path_lower' => strtolower($path)]);
    }
}

/** ['docx' => current name (the reviewed name once renamed), 'pdf' => name|'', 'ready' => PDF exists and is not older than the .docx,
 *   'open' => ffdocx link, 'view' => ?p=mfview link, 'docx_mtime', 'pdf_mtime'] */
function docx_pdf_status(array $dir, string $docx, bool $refresh = true): array {
    if ($refresh) docx_refresh($dir, $docx);
    $want = docx_pdf_name($docx); $clean = docx_clean_name($docx); $d = null; $dc = null; $p = null;
    foreach (mf_list($dir) as $f) { if ($f['name'] === $docx) $d = $f; if ($f['name'] === $clean) $dc = $f; if ($f['name'] === $want) $p = $f; }
    $d = $d ?? $dc; $name = $d['name'] ?? $docx;
    return ['docx' => $name, 'pdf' => $p ? $want : '', 'ready' => $p !== null && ($d === null || $p['mtime'] >= $d['mtime'] - 5),
            'open' => docx_open_url($dir, $d['rel'] ?? $name), 'check' => docx_open_url($dir, $d['rel'] ?? $name, 'check'), 'view' => url('mfview', ['ref' => (string)$dir['name'], 'name' => $name]),
            'docx_mtime' => $d['mtime'] ?? 0, 'pdf_mtime' => $p['mtime'] ?? 0];
}

/** The send-page box: one row per letter with its Open DOCX link; on focus the script asks for the PDF, ticks it, and says so once
 *  the letter has been updated and its PDF made (2026-10-05: no instructions in the box). '' when no letters. */
function docx_box(array $m): string {
    $dir = mf_find((string)$m['ref']); if (!$dir) return '';
    $rows = '';
    foreach (docx_list($dir) as $f) {
        if (docx_clean_name($f['name']) !== $f['name'] && in_array(docx_clean_name($f['name']), array_column(docx_list($dir), 'name'), true)) continue;   // both names: the reviewed one
        $rows .= '<div class="docxrow" data-name="' . h($f['name']) . '">'
            . '<a class="btn small gold docxopen" href="' . h(docx_open_url($dir, $f['rel'])) . '">Open DOCX</a> '
            . '<a class="btn small ghost docxcheck" href="' . h(docx_open_url($dir, $f['rel'], 'check')) . '">Check updates</a> '   // 2026-10-05: when the PDF was not picked up
            . '<span class="docxname">' . h($f['name']) . '</span> <span class="docxst"></span></div>';   // the name is not a link: a .docx would only download
    }
    if ($rows === '') return '';
    $api = url('docxpdf', ['ref' => (string)$m['ref']]);
    return '<div class="box" id="docxbox" style="background:#faf9f6"><b>Letters (.docx) in the file</b>' . $rows . '</div>'
        . '<script>(function(){var box=document.getElementById("docxbox"),api=' . json_encode($api) . ',busy=false,until=0;'
        . 'function tick(pdf){var list=document.getElementById("docxatt");if(!list){list=document.createElement("div");list.id="docxatt";box.appendChild(list);}'
        . 'var ex=Array.from(document.querySelectorAll("input[name=\'file_att[]\']")).filter(function(i){return i.value===pdf;})[0];'
        . 'if(window.ffAttTick)ffAttTick(pdf);if(ex){ex.checked=true;return;}var l=document.createElement("label");l.className="chk";var i=document.createElement("input");i.type="checkbox";i.name="file_att[]";i.value=pdf;i.checked=true;'
        . 'l.appendChild(i);l.appendChild(document.createTextNode(" "+pdf));list.appendChild(l);}'
        . 'function check(){if(busy)return;busy=true;var rows=Array.from(box.querySelectorAll(".docxrow")),left=rows.length,waiting=false;'
        . 'rows.forEach(function(r){fetch(api+"&name="+encodeURIComponent(r.dataset.name),{credentials:"same-origin"}).then(function(x){return x.json();}).then(function(j){'
        . 'if(j.docx&&j.docx!==r.dataset.name){r.dataset.name=j.docx;r.querySelector(".docxopen").href=j.open;r.querySelector(".docxcheck").href=j.check;r.querySelector(".docxname").textContent=j.docx;}'
        . 'if(j.ready){var s=r.querySelector(".docxst");if(r.dataset.opened&&!r.dataset.done){s.className="docxst ok";s.textContent="Letter updated and PDF created: "+j.pdf+" (attached)";}r.dataset.done="1";tick(j.pdf);}'
        . 'else if(r.dataset.opened)waiting=true;}).catch(function(){}).finally(function(){'
        . 'if(--left===0){busy=false;if(waiting&&Date.now()<until)setTimeout(check,5000);}});});}'
        // 2026-10-05 (staff's Windows PC): no handler installed = the click goes nowhere and the page keeps focus; then offer the installer
        . 'var kit=/Mac/i.test(navigator.platform)?"Mac":(/Win/i.test(navigator.platform)?"Windows":"");'
        . 'box.querySelectorAll(".docxopen").forEach(function(a){a.addEventListener("click",function(){var r=a.closest(".docxrow"),left=false;r.dataset.opened="1";delete r.dataset.done;'
        . 'function gone(){left=true;}window.addEventListener("blur",gone,{once:true});document.addEventListener("visibilitychange",gone,{once:true});'
        . 'setTimeout(function(){window.removeEventListener("blur",gone);if(left||document.hidden||!kit)return;var s=r.querySelector(".docxst");s.className="docxst bad";'
        . 's.innerHTML="Didn&rsquo;t open? Not set up on this computer: <a href=\\""+' . json_encode(url('docxkit', [])) . '+"&os="+kit+"\\">download the installer</a> (unzip, run <b>Install on "+kit+"</b>).";},2500);});});'
        . 'box.querySelectorAll(".docxcheck").forEach(function(a){a.addEventListener("click",function(){var r=a.closest(".docxrow");r.dataset.opened="1";delete r.dataset.done;until=Date.now()+120000;setTimeout(check,3000);});});'
        . 'function back(){if(document.visibilityState==="visible"){until=Date.now()+120000;check();}}'
        . 'window.addEventListener("focus",back);document.addEventListener("visibilitychange",back);check();})();</script>';
}
