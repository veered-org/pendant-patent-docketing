<?php
declare(strict_types=1);
require_once __DIR__ . '/firm.php';
/**
 * Internal mail from the job scripts (morning report, alarms): through the pair helper ff-mail.php (envelope alignment). On the
 * server the helper is called directly; from the laptop the mail is handed to it over ssh. Returns "sent" or what went wrong.
 */
function pendant_mail(string $to, string $subject, string $html): string {
    $helper = (string)firm('mail_helper'); $opts = ['from' => (string)firm('report_from'), 'from_name' => 'Pendant', 'html' => true];
    if ($helper !== '' && is_file($helper)) { require_once $helper; return ff_send_mail($to, $subject, $html, $opts) ? 'sent' : 'send failed'; }
    if ($helper === '' || !firm('mail_ssh')) {   // no helper: the host's own mail()
        $h = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8" . ($opts['from'] !== '' ? "\r\nFrom: Pendant <{$opts['from']}>" : '');
        return mail($to, $subject, $html, $h) ? 'sent' : 'send failed';
    }
    $php = 'require_once ' . var_export($helper, true) . '; $b = base64_decode(file_get_contents("php://stdin")); echo ff_send_mail(' . var_export($to, true) . ', ' . var_export($subject, true) . ', $b, ' . var_export($opts, true) . ') ? "sent\n" : "send failed\n";';
    $p = proc_open('ssh -o BatchMode=yes -o ConnectTimeout=20 ' . escapeshellarg((string)firm('mail_ssh')) . ' ' . escapeshellarg('php -r ' . escapeshellarg($php)), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return 'could not start ssh';
    fwrite($pipes[0], base64_encode($html)); fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); proc_close($p);
    return trim($out ?: preg_replace('/mesg:.*\n?/', '', (string)$err)) ?: 'no answer from the server';
}
/** One short alarm e-mail: what failed, and what to do about it. */
function pendant_alarm(string $to, string $what, array $lines): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Lexend,Segoe UI,sans-serif;font-size:14px;color:#1e2430;max-width:760px"><h2 style="color:#b42318;margin:0 0 8px">Pendant: ' . $h($what) . '</h2><ul>'
          . implode('', array_map(fn($l) => '<li>' . $h($l) . '</li>', $lines)) . '</ul>'
          . '<p>Until this is fixed, new USPTO notices may not be docketed, the USPTO sweep may not have run and client reminders may not have been queued. '
          . 'In Pendant: Reminders &rarr; &ldquo;Run nightly job now&rdquo;, and Advanced &rarr; USPTO mail &rarr; &ldquo;Pull from Gmail now&rdquo;. If that fails too, the code needs a look.</p>'
          . '<p style="color:#999;font-size:12px">Pendant · ' . $h(firm('name')) . ' · ' . date('Y-m-d H:i') . '</p></div>';
    return pendant_mail($to, 'Pendant ALARM: ' . $what . ' (' . date('Y-m-d') . ')', $html);
}
