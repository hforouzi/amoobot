<?php

declare(strict_types=1);

/*
 * Router for `php -S` in ModernPanelHttpCapTest: answers that are far larger than the bot's 1 MiB cap.
 * - /gzip/...  : a tiny gzip body (OVERSIZED_GZIP_FILE) that unpacks to about 200 MB, sent whatever the client accepts;
 * - /plain/... : 200 MB of uncompressed JSON-looking text, written until the client hangs up.
 * The Accept-Encoding header of each request is appended to OVERSIZED_RECORD_FILE.
 */
file_put_contents((string) getenv('OVERSIZED_RECORD_FILE'), ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '-')."\n", FILE_APPEND);
header('Content-Type: application/json');
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_contains($path, '/gzip/')) {
    header('Content-Encoding: gzip');
    readfile((string) getenv('OVERSIZED_GZIP_FILE'));

    return true;
}
echo '{"success":true,"msg":"","obj":["';
$block = str_repeat('A', 1 << 20);
for ($i = 0; $i < 200 && !connection_aborted(); ++$i) {
    echo $block;
    flush();
}
echo '"]}';

return true;
