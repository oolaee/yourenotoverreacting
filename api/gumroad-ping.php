<?php
// Gumroad "Ping" (Settings > Advanced) for each sale: adds the buyer to GoHighLevel and tags them.
//   yno-purchased  always (ends the welcome sequence's sales emails)
//   yno-buyer      only if the buyer agreed to hear from us at checkout (starts the after-purchase emails)
//   yno-gift-2pack when they chose the two-copy version
// The ping URL carries a shared key: /api/gumroad-ping.php?k=... (key in ../../private_html/yno-ghl.php).
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
function out($code, $arr) { http_response_code($code); echo json_encode($arr); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['ok' => false]);

$priv = dirname(__DIR__, 2) . '/private_html';
$cfg = require $priv . '/yno-ghl.php';
if (empty($cfg['gumroad_ping_key']) || !hash_equals($cfg['gumroad_ping_key'], (string)($_GET['k'] ?? ''))) out(403, ['ok' => false]);

$p = $_POST;
$log = fn($msg) => @file_put_contents($priv . '/gumroad-pings.log', date('c') . ' ' . $msg . "\n", FILE_APPEND);
$product = (string)($p['permalink'] ?? ($p['product_permalink'] ?? ''));
$isTest = ($p['test'] ?? '') === 'true';
$sale = (string)($p['sale_id'] ?? '');
if (stripos((string)($p['product_name'] ?? ''), 'Overreacting') === false) { $log("skip sale=$sale product=$product (not YNO)"); out(200, ['ok' => true]); }

$email = strtolower(trim((string)($p['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $log("bad email sale=$sale"); out(200, ['ok' => true]); }
$name = trim(strip_tags((string)($p['full_name'] ?? '')));
$first = $name !== '' ? mb_substr(explode(' ', $name)[0], 0, 60) : '';
$variants = is_array($p['variants'] ?? null) ? implode(' ', $p['variants']) : (string)($p['variants'] ?? '');
$twoCopies = stripos($variants, 'Two copies') !== false;
$canContact = ($p['can_contact'] ?? 'true') !== 'false';
$refunded = ($p['refunded'] ?? '') === 'true';
$day = substr((string)($p['sale_timestamp'] ?? date('c')), 0, 10);

function ghl($method, $path, $body, $cfg) {
    $ch = curl_init('https://services.leadconnectorhq.com' . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['token'], 'Version: 2021-07-28', 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body)]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode($res ?: '[]', true), (string)$res];
}

$custom = [['id' => $cfg['field_first_purchase_date'], 'field_value' => $day],
           ['id' => $cfg['field_gift_buyer'], 'field_value' => $twoCopies ? 'Yes' : 'No']];
$contact = ['locationId' => $cfg['location'], 'email' => $email, 'source' => 'gumroad', 'customFields' => $custom];
if ($first !== '') $contact['firstName'] = $first;

$tags = ['yno-purchased'];
if ($canContact) $tags[] = 'yno-buyer';
if ($twoCopies) $tags[] = 'yno-gift-2pack';
if ($refunded) $tags[] = 'yno-refunded';
if ($isTest) $tags[] = 'yno-internal-test';

[$c1, $r1, $raw1] = ghl('POST', '/contacts/upsert', $contact, $cfg);
$id = $r1['contact']['id'] ?? null;
$ok = $c1 >= 200 && $c1 < 300 && $id;
$c2 = 0; $raw2 = '';
if ($ok) { [$c2, , $raw2] = ghl('POST', "/contacts/$id/tags", ['tags' => $tags], $cfg); $ok = $c2 >= 200 && $c2 < 300; }
$log("sale=$sale test=" . ($isTest ? 1 : 0) . " product=$product two=" . ($twoCopies ? 1 : 0) . " contact=" . ($canContact ? 1 : 0) . " ghl=$c1/$c2" . ($ok ? '' : ' ERR ' . substr($raw1 . ' ' . $raw2, 0, 300)));
if (!$ok) @file_put_contents($priv . '/pending-buyers.jsonl', json_encode(['t' => date('c'), 'email' => $email, 'first' => $first, 'tags' => $tags, 'day' => $day]) . "\n", FILE_APPEND);
out(200, ['ok' => true]);
