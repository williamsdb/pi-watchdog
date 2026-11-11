<?php
// Run with: php tests/service-history.php. Uses an isolated temporary database.
declare(strict_types=1);
$directory = sys_get_temp_dir() . '/watchdog-history-' . bin2hex(random_bytes(6));
mkdir($directory . '/public', 0700, true);
copy(dirname(__DIR__) . '/public/service.php', $directory . '/public/service.php');
$db = new PDO('sqlite:' . $directory . '/watchdog.db');
$db->exec(file_get_contents(dirname(__DIR__) . '/schema.sql'));
$db->exec("INSERT INTO checks (name, type, target) VALUES ('Test <script>', 'http', 'http://example.test')");
$db->exec("INSERT INTO checks (name, type, target, enabled) VALUES ('Empty', 'ping', 'localhost', 0)");
$insert = $db->prepare('INSERT INTO results (check_id, checked_at, status, response_time, error) VALUES (1, ?, ?, ?, ?)');
foreach ([[-300, 'up', .1, null], [-600, 'up', .3, null], [-900, 'down', 10, '<script>failure</script>'], [-172800, 'up', .5, null]] as $sample) {
    $insert->execute([gmdate('Y-m-d H:i:s', time() + $sample[0]), $sample[1], $sample[2], $sample[3]]);
}
function renderPage(string $directory, array $params): string
{
    $runner = $directory . '/runner.php';
    file_put_contents($runner, '<?php $_GET = ' . var_export($params, true) . '; include ' . var_export($directory . '/public/service.php', true) . ';');
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 ' . escapeshellarg($runner) . ' 2>&1');
    if ($output === null || str_contains($output, 'Warning:') || str_contains($output, 'Fatal error:')) {
        throw new RuntimeException('Page failed: ' . $output);
    }
    return $output;
}
function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
try {
    $html = renderPage($directory, ['id' => 1]);
    check(str_contains($html, '66.67%'), '24h uptime excludes older samples');
    check(str_contains($html, '200.0 ms'), 'Average excludes failed response');
    check(str_contains($html, '100.0 ms / 300.0 ms'), 'Successful response extrema');
    check(str_contains($html, '&lt;script&gt;failure&lt;/script&gt;') && !str_contains($html, '<script>'), 'Output escaped');
    check(substr_count($html, 'class="history-bar ') === 96, 'Bounded availability chart');
    check(substr_count($html, 'class="chart-point"') > 0, 'Response chart populated');
    check(str_contains(renderPage($directory, ['id' => 1, 'range' => '7d']), '75.00%'), '7d includes older sample');
    check(str_contains(renderPage($directory, ['id' => 1, 'range' => '30d']), '75.00%'), '30d includes older sample');
    check(str_contains(renderPage($directory, ['id' => 2]), 'No checks recorded in this period.'), 'Empty service');
    check(str_contains(renderPage($directory, ['id' => 2]), 'Monitor disabled'), 'Disabled service');
    check(str_contains(renderPage($directory, ['id' => '999']), 'Service not found'), 'Unknown ID');
    check(str_contains(renderPage($directory, ['id' => ['1']]), 'Service not found'), 'Malformed ID');
    check(str_contains(renderPage($directory, ['id' => 1, 'range' => ['7d']]), '66.67%'), 'Malformed range falls back');
    $db->exec("UPDATE results SET checked_at = datetime('now', '-1 day')");
    check(str_contains(renderPage($directory, ['id' => 1]), 'latest status may be out of date'), 'Stale status');
    echo "Service history checks passed.\n";
} finally {
    $db = null;
    foreach (glob($directory . '/public/*') as $file) { unlink($file); }
    rmdir($directory . '/public');
    foreach (glob($directory . '/*') as $file) { unlink($file); }
    rmdir($directory);
}
