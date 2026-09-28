<?php
register_shutdown_function(function () { file_put_contents(__DIR__ . '/redirect-access.log', date('c') . ' [' . http_response_code() . '] ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND); });
// 127.0.0.2:80 - answers every request with a redirect to the catcher (another host).
$mode = trim((string) @file_get_contents(__DIR__ . '/redirect-mode.txt')) ?: '301';
http_response_code((int) $mode);
header('Location: http://127.0.0.4:8099' . $_SERVER['REQUEST_URI']);
header('Content-Type: text/html');
echo "<!DOCTYPE html>\n<html><head><title>{$mode} Moved</title></head><body><h1>Moved</h1><p>REDIRECT-BODY-MARKER</p></body></html>\n";
return true;
