<?php
// Free fridge card signup: adds the contact to GoHighLevel (You're Not Overreacting location) and tags it
// so the welcome emails start. Secrets live outside the web root: ../../private_html/yno-ghl.php returns
// ['token' => ..., 'location' => ..., 'field_signup_source' => ..., 'field_email_interest' => ...].
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
function out($code, $arr) { http_response_code($code); echo json_encode($arr); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['ok' => false]);

$priv = dirname(__DIR__, 2) . '/private_html';
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
if (!empty($in['website'])) out(200, ['ok' => true]);            // honeypot: bots fill it, people never see it

$email = strtolower(trim((string)($in['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) out(422, ['ok' => false, 'error' => 'email']);
$first = trim(strip_tags((string)($in['first_name'] ?? '')));
$first = mb_substr(preg_replace("/[^\p{L}\p{M}\s'\-.]/u", '', $first), 0, 60);
$allowed = ['Getting ready for baby', 'The newborn weeks', 'Babies and toddlers', 'Buying a gift'];
$interests = array_values(array_intersect($allowed, (array)($in['interests'] ?? [])));

// per-IP rate limit: 6 signups per 10 minutes
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'x');
$rl = $priv . '/rl'; if (!is_dir($rl)) @mkdir($rl, 0770);
$rf = $rl . '/' . hash('sha256', $ip);
$hits = array_filter(explode("\n", (string)@file_get_contents($rf)), fn($t) => $t !== '' && (int)$t > time() - 600);
if (count($hits) >= 6) out(429, ['ok' => false]);
$hits[] = time(); @file_put_contents($rf, implode("\n", $hits));

$cfg = require $priv . '/yno-ghl.php';
function ghl($method, $path, $body, $cfg) {
    $ch = curl_init('https://services.leadconnectorhq.com' . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['token'], 'Version: 2021-07-28', 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body)]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode($res ?: '[]', true), (string)$res];
}

$custom = [['id' => $cfg['field_signup_source'], 'field_value' => 'website fridge card']];
if ($interests) $custom[] = ['id' => $cfg['field_email_interest'], 'field_value' => $interests];
$contact = ['locationId' => $cfg['location'], 'email' => $email, 'source' => 'yourenotoverreacting.com fridge card', 'customFields' => $custom];
if ($first !== '') $contact['firstName'] = $first;

[$c1, $r1, $raw1] = ghl('POST', '/contacts/upsert', $contact, $cfg);
$id = $r1['contact']['id'] ?? null;
$ok = $c1 >= 200 && $c1 < 300 && $id;
$raw2 = '';
if ($ok) {   // add tags separately so an existing contact keeps its other tags (e.g. yno-buyer)
    [$c2, , $raw2] = ghl('POST', "/contacts/$id/tags", ['tags' => ['yno-fridge-card', 'yno-lead-site']], $cfg);
    $ok = $c2 >= 200 && $c2 < 300;
}
if (!$ok) {   // never lose a signup: queue it for retry and log the error
    @file_put_contents($priv . '/pending-signups.jsonl', json_encode(['t' => date('c'), 'email' => $email, 'first' => $first, 'interests' => $interests]) . "\n", FILE_APPEND);
    @file_put_contents($priv . '/subscribe-errors.log', date('c') . " upsert=$c1 " . substr($raw1, 0, 300) . ' ' . substr($raw2, 0, 200) . "\n", FILE_APPEND);
}
out(200, ['ok' => true]);
