<?php

declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$db = new PDO('sqlite:' . dirname(__DIR__) . '/watchdog.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$query = $db->prepare('SELECT * FROM checks WHERE id = ?');
$query->execute([$id ?: 0]);
$service = $query->fetch(PDO::FETCH_ASSOC);
if (!$service) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><meta charset="UTF-8"><title>Service not found</title><h1>Service not found</h1><a href="index.php">Back to dashboard</a></html>';
    exit;
}
$ranges = ['24h' => 86400, '7d' => 604800, '30d' => 2592000];
$range = is_string($_GET['range'] ?? null) && isset($ranges[$_GET['range']]) ? $_GET['range'] : '24h';
$seconds = $ranges[$range];
$now = time();
$start = $now - $seconds;
$from = gmdate('Y-m-d H:i:s', $start);
$until = gmdate('Y-m-d H:i:s', $now);
$params = [$id, $from, $until];
// Aggregate in SQLite so longer histories do not need to be loaded into PHP.
$query = $db->prepare("SELECT COUNT(*) total, SUM(status = 'up') up,
    AVG(CASE WHEN status = 'up' THEN response_time END) average,
    MIN(CASE WHEN status = 'up' THEN response_time END) fastest,
    MAX(CASE WHEN status = 'up' THEN response_time END) slowest
    FROM results WHERE check_id = ? AND checked_at >= ? AND checked_at <= ?");
$query->execute($params);
$stats = $query->fetch(PDO::FETCH_ASSOC);
$total = (int) $stats['total'];
$up = (int) $stats['up'];
$coverage = min(100, $total / ($seconds / 300) * 100);
$bucketSeconds = intdiv($seconds, 96);
$query = $db->prepare("SELECT CAST((CAST(strftime('%s', checked_at) AS INTEGER) - ?) / ? AS INTEGER) bucket,
    COUNT(*) total, SUM(status = 'up') up,
    AVG(CASE WHEN status = 'up' THEN response_time END) average,
    MIN(CASE WHEN status = 'up' THEN response_time END) fastest,
    MAX(CASE WHEN status = 'up' THEN response_time END) slowest
    FROM results WHERE check_id = ? AND checked_at >= ? AND checked_at <= ? GROUP BY bucket");
$query->execute(array_merge([$start, $bucketSeconds], $params));
$buckets = [];
foreach ($query as $row) {
    $buckets[min(95, (int) $row['bucket'])] = $row;
}
$query = $db->prepare('SELECT * FROM results WHERE check_id = ? ORDER BY id DESC LIMIT 1');
$query->execute([$id]);
$latest = $query->fetch(PDO::FETCH_ASSOC);
$stale = !$latest || $now - (new DateTimeImmutable($latest['checked_at'], new DateTimeZone('UTC')))->getTimestamp() >= 900;
$query = $db->prepare('SELECT * FROM results WHERE check_id = ? AND checked_at >= ? AND checked_at <= ? ORDER BY checked_at DESC, id DESC LIMIT 50');
$query->execute($params);
$recent = $query->fetchAll(PDO::FETCH_ASSOC);
$maximum = max(array_merge([1.0], array_map(static fn($b) => (float) $b['slowest'] * 1000, $buckets)));
function milliseconds(mixed $value): string
{
    return $value === null ? '—' : number_format((float) $value * 1000, 1) . ' ms';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#111827">
    <title><?= h($service['name']) ?> · Pi Watchdog</title>
    <link rel="icon" href="icon.png">
    <link rel="stylesheet" href="watchdog.css">
</head>
<body>
<header class="topbar"><div class="topbar-inner">
    <div><h1><?= h($service['name']) ?></h1><p class="subtitle"><?= h(strtoupper($service['type'])) ?> · <?= h($service['target']) ?></p></div>
    <a class="admin-link" href="index.php">← Dashboard</a>
</div></header>
<main>
    <div class="section-heading detail-intro">
        <div><h2>Service history</h2><p class="subtitle">Latest result: <strong class="status-text <?= h($latest['status'] ?? 'unknown') ?>"><?= h(ucfirst($latest['status'] ?? 'pending')) ?></strong> · <?= h($latest['checked_at'] ?? 'Never checked') ?> UTC<?= $service['enabled'] ? '' : ' · Monitor disabled' ?></p></div>
        <nav class="range-picker" aria-label="History period">
            <?php foreach ($ranges as $key => $duration): ?><a class="admin-link" href="service.php?id=<?= (int) $id ?>&amp;range=<?= h($key) ?>" <?= $range === $key ? 'aria-current="page"' : '' ?>><?= h(['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days'][$key]) ?></a><?php endforeach; ?>
        </nav>
    </div>
    <?php if ($stale): ?><div class="warning-banner">No result recorded in the last 15 minutes. The latest status may be out of date.</div><?php endif; ?>
    <div class="health-grid detail-stats">
        <div class="health-item"><div class="health-label">Uptime</div><div class="health-value"><?= $total ? h(number_format($up / $total * 100, 2)) . '%' : '—' ?></div></div>
        <div class="health-item"><div class="health-label">Checks / failed</div><div class="health-value"><?= $total ?> / <?= $total - $up ?></div></div>
        <div class="health-item"><div class="health-label">Average response</div><div class="health-value"><?= h(milliseconds($stats['average'])) ?></div></div>
        <div class="health-item"><div class="health-label">Fastest / slowest</div><div class="health-value"><?= h(milliseconds($stats['fastest'])) ?> / <?= h(milliseconds($stats['slowest'])) ?></div></div>
        <div class="health-item"><div class="health-label">Check coverage</div><div class="health-value"><?= h(number_format($coverage, 1)) ?>%</div></div>
    </div>
    <p class="section-note">Uptime is the percentage of recorded checks that succeeded. Coverage assumes one check every five minutes. Missing checks are not counted as downtime. Response statistics include successful checks only. All times are UTC.</p>
    <?php if (!$total): ?><div class="panel empty">No checks recorded in this period. Try a longer period or wait for the monitor to run.</div><?php endif; ?>
    <section class="history-section" aria-labelledby="availability-heading">
        <div class="section-heading"><h2 id="availability-heading">Availability</h2></div>
        <div class="panel chart-panel">
            <div class="availability-chart" role="img" aria-label="Availability in 96 intervals. Green: all checks up; amber: mixed results; red: all down; grey: no data.">
            <?php for ($i = 0; $i < 96; $i++):
                $b = $buckets[$i] ?? null;
                $state = !$b ? 'empty' : ((int) $b['up'] === (int) $b['total'] ? 'up' : ((int) $b['up'] === 0 ? 'down' : 'mixed'));
                $label = gmdate('d M H:i', $start + $i * $bucketSeconds) . '–' . gmdate('d M H:i', $start + ($i + 1) * $bucketSeconds) . ' UTC: ' . ($b ? $b['up'] . '/' . $b['total'] . ' checks up' : 'no checks');
            ?><span class="history-bar <?= h($state) ?>" tabindex="0" title="<?= h($label) ?>" aria-label="<?= h($label) ?>"></span><?php endfor; ?>
            </div>
            <div class="history-ends"><span><?= h($from) ?></span><span><?= h($until) ?> UTC</span></div>
            <p class="section-note">Green: up · Amber: mixed · Red: down · Grey: no checks. Hover or focus an interval for details.</p>
        </div>
    </section>
    <section class="history-section" aria-labelledby="response-heading">
        <div class="section-heading"><h2 id="response-heading">Response time</h2><span class="section-note">Milliseconds · successful checks</span></div>
        <div class="panel chart-panel">
            <svg class="response-chart" viewBox="0 0 1000 270" role="img" aria-labelledby="chart-title chart-desc">
                <title id="chart-title">Response times for <?= h($service['name']) ?></title>
                <desc id="chart-desc">Each point shows an interval average; vertical lines show fastest to slowest responses. Empty intervals have no point. Exact values are available by focusing points.</desc>
                <?php for ($tick = 0; $tick <= 4; $tick++): $y = 225 - $tick * 50; ?>
                    <line class="chart-grid" x1="70" x2="975" y1="<?= $y ?>" y2="<?= $y ?>" />
                    <text class="chart-label" x="60" y="<?= $y + 4 ?>" text-anchor="end"><?= h(number_format($maximum * $tick / 4, 1)) ?></text>
                <?php endfor; ?>
                <?php for ($i = 0; $i < 96; $i++):
                    $b = $buckets[$i] ?? null;
                    if (!$b || $b['average'] === null) { continue; }
                    $x = 75 + $i * 9.4;
                    $y = 225 - (float) $b['average'] * 1000 / $maximum * 200;
                    $label = gmdate('d M H:i', $start + $i * $bucketSeconds) . ' UTC · average ' . milliseconds($b['average']) . ', fastest ' . milliseconds($b['fastest']) . ', slowest ' . milliseconds($b['slowest']);
                ?>
                    <g tabindex="0" aria-label="<?= h($label) ?>"><title><?= h($label) ?></title>
                        <line class="chart-spread" x1="<?= $x ?>" x2="<?= $x ?>" y1="<?= 225 - (float) $b['fastest'] * 1000 / $maximum * 200 ?>" y2="<?= 225 - (float) $b['slowest'] * 1000 / $maximum * 200 ?>" />
                        <circle class="chart-point" cx="<?= $x ?>" cy="<?= $y ?>" r="3.5" />
                    </g>
                <?php endfor; ?>
                <text class="chart-label" x="70" y="255"><?= h(gmdate('d M H:i', $start)) ?></text>
                <text class="chart-label" x="975" y="255" text-anchor="end"><?= h(gmdate('d M H:i', $now)) ?> UTC</text>
            </svg>
            <p class="section-note">Dots: interval average · Vertical lines: fastest to slowest · Hover or focus points for details. Blank intervals have no successful timed checks.</p>
        </div>
    </section>
    <section class="history-section" aria-labelledby="recent-heading">
        <div class="section-heading"><h2 id="recent-heading">Recent checks</h2><span class="section-note">Latest 50 in selected period · UTC</span></div>
        <div class="panel table-wrap"><table><thead><tr><th>Time</th><th>Status</th><th>Response</th><th>HTTP</th><th>Error</th></tr></thead><tbody>
        <?php foreach ($recent as $row): ?><tr>
            <td><?= h($row['checked_at']) ?></td><td class="status-text <?= h($row['status']) ?>"><?= h(ucfirst($row['status'])) ?></td>
            <td><?= h(milliseconds($row['response_time'])) ?></td><td><?= h($row['http_code'] ?? '—') ?></td><td class="error-detail"><?= h($row['error'] ?? '—') ?></td>
        </tr><?php endforeach; ?>
        <?php if (!$recent): ?><tr><td colspan="5" class="empty">No results in this period.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</main>
<footer><span>Pi Watchdog · History stored locally in SQLite</span><span>Generated <?= h($until) ?> UTC · <a href="service.php?id=<?= (int) $id ?>&amp;range=<?= h($range) ?>">Refresh</a></span></footer>
</body>
</html>
