<?php
declare(strict_types=1);
/**
 * 2026-09-29: sending an email from a HELD reminder accepts its template, with any edits he made. The edited email is mapped
 * back onto the template line by line: unchanged lines keep their placeholders exactly; a changed stretch is saved with the values
 * from its own original lines ({DUE}, {MARK} ...) turned back into placeholders. The result is only saved when re-filling it
 * reproduces the email he sent; otherwise the template is left as it was and he is told.
 */

/** Editor output vs rendered text: auto-underlined dates and the bold REMINDER / GRACE PERIOD markers come back as tags; trailing spaces vary. */
function learn_norm(string $s): string {
    $s = str_replace("\r\n", "\n", $s);
    $s = preg_replace('#<u>((?:January|February|March|April|May|June|July|August|September|October|November|December) \d{1,2}, \d{4})</u>#', '$1', $s);
    $s = preg_replace('#<b>(REMINDER:|GRACE PERIOD:)</b>#', '$1', $s);
    $s = preg_replace('/[ \t]+$/m', '', $s);
    return trim(preg_replace("/\n{3,}/", "\n\n", $s));
}

/** Reverse-substitute: filled values of $phs found in $text become their placeholders again, longest value first. */
function learn_unfill(string $text, array $phs, callable $val): string {
    $pairs = [];
    foreach (array_unique($phs) as $ph) { $v = learn_norm($val($ph)); if (strlen($v) >= 3) $pairs[$v] = $pairs[$v] ?? $ph; }
    uksort($pairs, fn($a, $b) => strlen($b) <=> strlen($a));
    return strtr($text, $pairs);
}

/** @return array{changed:bool, ok:bool, body:string, subject:string} */
function learn_template(array $t, string $edBody, ?string $edSubject, array $fillEv, array $m, array $c): array {
    $render = fn(string $tpl) => learn_norm(strip_plain_signature(dates_long(fill_template($tpl, $fillEv, $m, $c, $t))));
    $val = fn(string $ph) => strip_plain_signature(dates_long(fill_template($ph, $fillEv, $m, $c, $t)));
    $E = learn_norm($edBody); $origB = $render((string)$t['body']);
    $newBody = (string)$t['body'];
    if ($E !== $origB) {
        $tl = explode("\n", str_replace("\r\n", "\n", (string)$t['body']));
        $units = array_map(fn($l) => trim($l) === '' ? [''] : explode("\n", (preg_match('/^[ \t]*/', $l, $ws) ? $ws[0] : '') . $render($l)), $tl);   // keep a line's own indent (" 1) ...")
        $el = explode("\n", $E); $U = count($units); $N = count($el);
        $at = fn(int $u, int $j) => $j + count($units[$u]) <= $N && array_map('trim', array_slice($el, $j, count($units[$u]))) === array_map('trim', $units[$u]);   // indents: fill_template trims a lone placeholder's
        $out = []; $i = 0; $j = 0;
        $flush = function (int $i0, int $i1, int $j0, int $j1) use (&$out, $tl, $el, $val) {   // template lines [i0,i1) became edited lines [j0,j1)
            $phs = []; for ($k = $i0; $k < $i1; $k++) if (preg_match_all('/\{[A-Z_]+\}/', $tl[$k], $mm)) $phs = array_merge($phs, $mm[0]);
            for ($k = $j0; $k < $j1; $k++) $out[] = learn_unfill($el[$k], $phs, $val);
        };
        while ($i < $U) {
            if ($at($i, $j)) { $out[] = $tl[$i]; $j += count($units[$i]); $i++; continue; }
            $best = null;   // nearest non-blank anchor: fewest template + edited lines skipped
            for ($a = $i; $a < $U; $a++) { if ($units[$a] === ['']) continue; for ($b = $j; $b < $N; $b++) if ($at($a, $b) && ($best === null || ($a - $i) + ($b - $j) < $best[2])) { $best = [$a, $b, ($a - $i) + ($b - $j)]; break; } }
            if (!$best) { $flush($i, $U, $j, $N); $i = $U; $j = $N; break; }
            $flush($i, $best[0], $j, $best[1]); $i = $best[0]; $j = $best[1];
        }
        if ($j < $N) { $all = []; foreach ($tl as $l) if (preg_match_all('/\{[A-Z_]+\}/', $l, $mm)) $all = array_merge($all, $mm[0]); foreach (array_slice($el, $j) as $l) $out[] = learn_unfill($l, $all, $val); }
        $newBody = implode("\n", $out);
    }
    $newSubj = (string)$t['subject'];
    if ($edSubject !== null && trim($edSubject) !== '' && trim($edSubject) !== trim(plain_text(dates_long(fill_template((string)$t['subject'], $fillEv, $m, $c, $t))))) {
        preg_match_all('/\{[A-Z_]+\}/', (string)$t['subject'], $mm);
        $newSubj = learn_unfill(trim($edSubject), $mm[0], fn($ph) => plain_text(dates_long(fill_template($ph, $fillEv, $m, $c, $t))));
    }
    // A placeholder that vanished while its text is still in the email would be frozen into the template (the goods list, a mark): refuse.
    preg_match_all('/\{[A-Z_]+\}/', (string)$t['body'], $had); preg_match_all('/\{[A-Z_]+\}/', $newBody, $has);
    foreach (array_diff(array_unique($had[0]), $has[0]) as $gone) { $v = learn_norm($val($gone)); if ((strlen($v) >= 3 && str_contains($E, $v)) || str_contains($v, "\n") || strlen($v) > 60) return ['changed' => true, 'ok' => false, 'body' => $newBody, 'subject' => $newSubj]; }
    $changed = $newBody !== (string)$t['body'] || $newSubj !== (string)$t['subject'];
    $ok = $render($newBody) === $E && ($edSubject === null || trim($edSubject) === '' || trim(plain_text(dates_long(fill_template($newSubj, $fillEv, $m, $c, $t)))) === trim($edSubject));
    return ['changed' => $changed, 'ok' => $ok, 'body' => $newBody, 'subject' => $newSubj];
}
