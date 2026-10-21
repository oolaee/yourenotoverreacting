<?php
// One-tap answers from the monthly emails (child's age, toddler book waitlist): tags the contact in GoHighLevel.
//   GET  /api/ynotag.php?t=age-0-6m&c={{contact.id}}   shows a page that saves the answer with a POST from script,
//                                                     so link scanners that open every link don't record answers
//   POST the same URL                                 tags the contact (an age answer replaces any earlier one);
//                                                     JSON for the script, the page again for the no-script button
// Only the tags in $TAGS can be set. Secrets live in ../../private_html/yno-ghl.php.
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$TAGS = ['age-expecting' => 'yno-age-expecting', 'age-0-6m' => 'yno-age-0-6m', 'age-6-12m' => 'yno-age-6-12m',
         'age-1-2y' => 'yno-age-1-2y', 'age-2y-plus' => 'yno-age-2y-plus', 'book2-waitlist' => 'yno-book2-waitlist'];
$t = (string)($_GET['t'] ?? '');
$c = (string)($_GET['c'] ?? '');
$valid = isset($TAGS[$t]) && preg_match('/^[A-Za-z0-9]{15,40}$/', $c);
$isAge = strncmp($t, 'age-', 4) === 0;
$priv = dirname(__DIR__, 2) . '/private_html';

function ghl($method, $path, $body, $cfg) {
    $ch = curl_init('https://services.leadconnectorhq.com' . $path);
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['token'], 'Version: 2021-07-28', 'Content-Type: application/json', 'Accept: application/json']];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode($res ?: '[]', true), (string)$res];
}

function save($t, $c, $TAGS, $isAge, $priv) {
    $log = fn($msg) => @file_put_contents($priv . '/ynotag.log', date('c') . ' ' . $msg . "\n", FILE_APPEND);
    // per-IP rate limit: 20 answers per 10 minutes
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'x');
    $rl = $priv . '/rl'; if (!is_dir($rl)) @mkdir($rl, 0770);
    $rf = $rl . '/tag-' . hash('sha256', $ip);
    $hits = array_filter(explode("\n", (string)@file_get_contents($rf)), fn($x) => $x !== '' && (int)$x > time() - 600);
    if (count($hits) >= 20) { $log("rate-limited c=$c"); return false; }
    $hits[] = time(); @file_put_contents($rf, implode("\n", $hits));

    $cfg = require $priv . '/yno-ghl.php';
    [$c0, $r0] = ghl('GET', "/contacts/$c", null, $cfg);   // must be a contact in our location
    if ($c0 !== 200 || ($r0['contact']['locationId'] ?? '') !== $cfg['location']) { $log("no contact c=$c ghl=$c0"); return false; }
    $c1 = 0;
    if ($isAge) {
        $others = array_values(array_filter($TAGS, fn($v, $k) => strncmp($k, 'age-', 4) === 0 && $k !== $t, ARRAY_FILTER_USE_BOTH));
        [$c1] = ghl('DELETE', "/contacts/$c/tags", ['tags' => $others], $cfg);
    }
    [$c2, , $raw2] = ghl('POST', "/contacts/$c/tags", ['tags' => [$TAGS[$t]]], $cfg);
    $ok = $c2 >= 200 && $c2 < 300;
    $log("c=$c t=$t ghl=$c0/$c1/$c2" . ($ok ? '' : ' ERR ' . substr($raw2, 0, 200)));
    return $ok;
}

$state = $valid ? 'pending' : 'invalid';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = $valid && save($t, $c, $TAGS, $isAge, $priv);
    if (empty($_POST['page'])) {
        header('Content-Type: application/json');
        http_response_code($ok ? 200 : ($valid ? 502 : 422));
        echo json_encode(['ok' => $ok]);
        exit;
    }
    $state = $valid ? ($ok ? 'ok' : 'fail') : 'invalid';
}

header('Content-Type: text/html; charset=utf-8');
$done = $isAge ? "Got it. I'll keep that in mind for what I send you." : "You're on the list. I'll email you when the toddler book is ready.";
$fail = "Something went wrong on my end. If you'd like, just reply to my email with your answer.";
$url = '/api/ynotag.php?t=' . rawurlencode($t) . '&c=' . rawurlencode($c);
$h = fn($s) => htmlspecialchars($s, ENT_QUOTES);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Thank you | You're Not Overreacting</title>
<style>
  body { margin: 0; background: #fbf7f0; color: #1f2430; font: 18px/1.6 -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; }
  main { max-width: 520px; margin: 0 auto; padding: 64px 24px; }
  .brand { font: bold 20px Georgia, 'Times New Roman', serif; color: #1d3461; margin: 0 0 28px; }
  .card { background: #fff; border: 1px solid #e4dccd; border-radius: 14px; padding: 28px 26px; }
  h1 { font: bold 28px/1.2 Georgia, 'Times New Roman', serif; color: #1d3461; margin: 0 0 10px; }
  p { margin: 0 0 14px; }
  .sig { color: #5d6475; font-size: 16px; margin: 18px 0 0; }
  a { color: #1d3461; font-weight: 700; }
  button { font-family: inherit; font-size: 17px; font-weight: 700; color: #1d3461; background: #f2b33d; border: 0; border-radius: 999px; padding: 12px 24px; cursor: pointer; }
</style>
</head>
<body>
<main>
  <p class="brand">You're Not Overreacting</p>
  <div class="card">
<?php if ($state === 'invalid'): ?>
    <h1>Hmm, that link didn't work</h1>
    <p>Something in the link got cut off. If you'd like, just reply to my email with your answer.</p>
<?php elseif ($state === 'ok'): ?>
    <h1>Thank you</h1>
    <p><?= $h($done) ?></p>
<?php elseif ($state === 'fail'): ?>
    <h1>Sorry, that didn't save</h1>
    <p><?= $h($fail) ?></p>
<?php else: ?>
    <h1 id="h">Saving your answer...</h1>
    <p id="m"></p>
    <noscript><form method="post" action="<?= $h($url) ?>"><input type="hidden" name="page" value="1"><button type="submit">Save my answer</button></form></noscript>
<?php endif; ?>
    <p class="sig">Dr. Oliver Morris, DO</p>
  </div>
  <p style="margin-top:22px;font-size:16px;"><a href="https://yourenotoverreacting.com/">Back to You're Not Overreacting</a></p>
</main>
<?php if ($state === 'pending'): ?>
<script>
fetch(<?= json_encode($url) ?>, { method: 'POST' })
  .then(r => r.json()).catch(() => ({ ok: false }))
  .then(j => {
    document.getElementById('h').textContent = j.ok ? 'Thank you' : 'Sorry, that didn\'t save';
    document.getElementById('m').textContent = j.ok ? <?= json_encode($done) ?> : <?= json_encode($fail) ?>;
  });
</script>
<?php endif; ?>
</body>
</html>
