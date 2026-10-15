<?php
register_shutdown_function(function () { file_put_contents(__DIR__ . '/html-access.log', date('c') . ' [' . http_response_code() . '] ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND); });
// 127.0.0.3:80 - a plain web server answering 200 with an HTML page (or variants).
$mode = trim((string) @file_get_contents(__DIR__ . '/html-mode.txt')) ?: 'html';
header('Content-Type: text/html; charset=utf-8');
if ($mode === 'empty') { return true; }
if ($mode === 'utf8') {
    // 118 ASCII bytes, then a 2-byte "é" straddling byte 120, then more.
    $head = "<!DOCTYPE html>";
    $pad = str_repeat('a', 119 - strlen($head));
    echo $head . $pad . "é" . str_repeat('b', 300) . "DEEP-UTF8-MARKER";
    return true;
}
$page = "<!DOCTYPE html>\n<html>\n<head>\n<title>Welcome to nginx!</title>\n<style>html { color-scheme: light dark; } body { width: 35em; margin: 0 auto; font-family: Tahoma, Verdana, Arial, sans-serif; }</style>\n</head>\n<body>\n<h1>Welcome to nginx!</h1>\n";
$page .= str_repeat("<p>If you see this page, the nginx web server is successfully installed and working. Further configuration is required.</p>\n", 40);
$page .= "<p>DEEP-HTML-MARKER-NEVER-LOGGED</p>\n</body>\n</html>\n";
echo $page;
return true;
