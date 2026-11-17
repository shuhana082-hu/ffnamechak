<?php
/**
 * Free Fire UID -> Nickname checker
 * ব্যবহার: /check.php?uid=123456789
 * রেসপন্স: {"ok":true,"nickname":"PlayerName","cached":false}
 */

// ============ কনফিগ ============
const ALLOWED_ORIGIN = '*';          // প্রোডাকশনে নিজের ডোমেইন দিন, যেমন 'https://devyasin.online'
const CACHE_TTL      = 600;          // সফল রেজাল্ট কত সেকেন্ড ক্যাশ থাকবে
const RATE_LIMIT     = 20;           // প্রতি IP-তে মিনিটে সর্বোচ্চ রিকোয়েস্ট
const PROVIDERS      = ['garena', 'gopaygames', 'codashop', 'codashop_dynamic']; // শুধু PHP-ভিত্তিক provider
// ===============================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) out(['ok' => false, 'error' => 'Server not set up (vendor missing)'], 500);
require $autoload;

use Refatbd\GameAccountLookup\Cache\ArrayCache;
use Refatbd\GameAccountLookup\GameAccountLookup;

// UID যাচাই
$uid = preg_replace('/\D/', '', (string)($_GET['uid'] ?? $_POST['uid'] ?? ''));
if (strlen($uid) < 5 || strlen($uid) > 15) {
    out(['ok' => false, 'error' => 'Invalid UID'], 400);
}

$dir = __DIR__ . '/cache';
if (!is_dir($dir)) @mkdir($dir, 0755, true);

// ক্যাশ থেকে দেখুন
$cacheFile = "$dir/uid_$uid.json";
if (is_file($cacheFile) && time() - filemtime($cacheFile) < CACHE_TTL) {
    $c = json_decode((string)file_get_contents($cacheFile), true);
    if (!empty($c['nickname'])) out(['ok' => true, 'nickname' => $c['nickname'], 'cached' => true]);
}

// রেট লিমিট (IP অনুযায়ী)
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ipFile = "$dir/ip_" . md5($ip) . '.json';
$fp = @fopen($ipFile, 'c+');
if ($fp) {
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $hits = $raw ? (json_decode($raw, true) ?: []) : [];
    $hits = array_values(array_filter($hits, fn($t) => $t > time() - 60));
    if (count($hits) >= RATE_LIMIT) {
        flock($fp, LOCK_UN); fclose($fp);
        out(['ok' => false, 'error' => 'Too many requests, try again in a minute'], 429);
    }
    $hits[] = time();
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($hits));
    flock($fp, LOCK_UN); fclose($fp);
}

// পুরনো ip_ ফাইল মাঝে মাঝে মুছে ফেলুন (১% সম্ভাবনায়)
if (random_int(1, 100) === 1) {
    foreach (glob("$dir/ip_*.json") ?: [] as $f) {
        if (time() - filemtime($f) > 300) @unlink($f);
    }
}

// আসল lookup
try {
    $lookup = GameAccountLookup::make([
        'cache'       => new ArrayCache(),
        'cache_ttl'   => 300,
        'session_ttl' => 1800,
    ]);

    $result = json_decode(json_encode(
        $lookup->check('freefire', $uid, providerOrder: PROVIDERS)
    ), true) ?: [];
} catch (Throwable $e) {
    error_log('[ff-name-check] ' . $e->getMessage());
    out(['ok' => false, 'error' => 'Lookup failed'], 502);
}

if (!empty($result['ok']) && !empty($result['nickname'])) {
    file_put_contents($cacheFile, json_encode(['nickname' => $result['nickname']], JSON_UNESCAPED_UNICODE));
    out(['ok' => true, 'nickname' => $result['nickname'], 'cached' => false]);
}

// ব্যর্থ হলে শুধু কোড পাঠান, provider-এর কাঁচা রেসপন্স নয়
out(['ok' => false, 'error' => 'Player not found or provider unavailable', 'code' => $result['code'] ?? null], 200);
