<?php
// 127.0.0.4:8099 - the redirect target. Any hit here means the module FOLLOWED a redirect.
$body = file_get_contents('php://input');
file_put_contents(__DIR__ . '/catcher-hits.log', date('c') . ' ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' body_bytes=' . strlen($body) . ' has_password=' . (str_contains($body, 'password') ? 'yes' : 'no') . "\n", FILE_APPEND);
header('Content-Type: application/json');
echo '{"code":"ok","message":"","response":"caught-by-redirect-target"}';
return true;
