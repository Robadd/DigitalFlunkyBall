<?php
// Optional SSE transport. Each open stream holds one PHP worker, so only enable it
// in admin when the host's worker pool has room for every player plus headroom.
require __DIR__ . '/lib.php';

@set_time_limit(45);
@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
while (ob_get_level() > 0) {
    ob_end_flush();
}
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');

$token = clean_token(isset($_GET['t']) ? (string)$_GET['t'] : '');
if (isset($_SERVER['HTTP_LAST_EVENT_ID'])) {
    $last = (int)$_SERVER['HTTP_LAST_EVENT_ID'];
} else {
    $last = isset($_GET['v']) ? (int)$_GET['v'] : -1;
}

// Padding pushes the first bytes through proxy buffers.
echo ':' . str_repeat(' ', 2048) . "\n";
echo "retry: 1000\n\n";
flush();

$start = now();
$beat = $start;
while (now() - $start < 25) {
    touch_player($token);
    $g = sync_game();
    if ($g->v !== $last) {
        $last = $g->v;
        echo 'id: ' . $last . "\n";
        echo 'data: ' . json_encode(payload($g, $token), JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
        $beat = now();
    } elseif (now() - $beat >= 10) {
        echo ": ping\n\n";
        flush();
        $beat = now();
    }
    if (connection_aborted()) {
        exit;
    }
    usleep(300000);
}
echo "event: bye\ndata: {}\n\n";
flush();
