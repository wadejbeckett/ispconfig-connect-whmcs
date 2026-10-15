<?php
// 127.0.0.5:80 - the panel "goes down mid-sweep": the first N requests are proxied to the real panel 2,
// every later one answers 502.
$counterFile = __DIR__ . '/outage-counter.txt';
$n = (int) @file_get_contents($counterFile) + 1;
file_put_contents($counterFile, (string) $n);
$allow = (int) (trim((string) @file_get_contents(__DIR__ . '/outage-allow.txt')) ?: 2);
file_put_contents(__DIR__ . '/outage-hits.log', "$n " . $_SERVER['REQUEST_URI'] . ($n <= $allow ? ' -> proxied' : ' -> 502') . "\n", FILE_APPEND);
if ($n > $allow) { http_response_code(502); header('Content-Type: text/html'); echo "<html><body>502 Bad Gateway</body></html>"; return true; }
$ch = curl_init('http://127.0.0.1:8082' . $_SERVER['REQUEST_URI']);
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => file_get_contents('php://input'), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
$out = curl_exec($ch);
http_response_code((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
header('Content-Type: application/json');
echo $out;
return true;
