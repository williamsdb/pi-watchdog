<?php

declare(strict_types=1);

// Keep the SQLite database outside the web document root.
$db = new PDO('sqlite:' . dirname(__DIR__) . '/watchdog.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Latest result for each enabled check.
$sql = "
    SELECT
        c.id,
        c.name,
        c.type,
        c.target,
        r.checked_at,
        r.status,
        r.response_time,
        r.http_code,
        r.error
    FROM checks c
    LEFT JOIN results r ON r.id = (
        SELECT MAX(r2.id)
        FROM results r2
        WHERE r2.check_id = c.id
    )
    WHERE c.enabled = 1
    ORDER BY c.id
";
$checks = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// A monitor is stale if its latest result is at least 15 minutes old.
// Checks are scheduled every five minutes, so this allows for brief delays.
$nowTimestamp = time();
$staleAfterSeconds = 900;
$latestCheckTimestamp = null;

foreach ($checks as &$check) {
    $check['checkedAge'] = null;
    $check['isStale'] = false;

    if (!empty($check['checked_at'])) {
        $checkedTimestamp = (new DateTimeImmutable(
            $check['checked_at'],
            new DateTimeZone('UTC')
        ))->getTimestamp();

        $check['checkedAge'] = max(0, $nowTimestamp - $checkedTimestamp);
        $check['isStale'] = $check['checkedAge'] >= $staleAfterSeconds;

        if ($latestCheckTimestamp === null || $checkedTimestamp > $latestCheckTimestamp) {
            $latestCheckTimestamp = $checkedTimestamp;
        }
    } else {
        $check['isStale'] = true;
    }
}
unset($check);

$checksStale = $latestCheckTimestamp === null ||
    ($nowTimestamp - $latestCheckTimestamp) >= $staleAfterSeconds;

// Gather the last seven days of results once, then calculate each monitor's
// sample-based uptime and 24-hour history bar. Missing checks are shown as
// gaps rather than being counted as downtime.
$historyWindowSql = "
    SELECT check_id, checked_at, status
    FROM results
    WHERE checked_at >= strftime('%Y-%m-%d %H:%M:%S', 'now', '-7 days')
    ORDER BY id DESC
";
$windowResults = $db->query($historyWindowSql)->fetchAll(PDO::FETCH_ASSOC);

$daySeconds = 86400;
$weekSeconds = 7 * $daySeconds;
$bucketSeconds = 1800; // 30-minute blocks; 48 blocks represent 24 hours.
$bucketCount = 48;
$resultsByCheck = [];

foreach ($windowResults as $result) {
    $timestamp = (new DateTimeImmutable(
        $result['checked_at'],
        new DateTimeZone('UTC')
    ))->getTimestamp();

    $age = $nowTimestamp - $timestamp;
    if ($age < 0 || $age >= $weekSeconds) {
        continue;
    }

    $checkId = (int) $result['check_id'];
    $resultsByCheck[$checkId][] = [
        'timestamp' => $timestamp,
        'status' => $result['status'],
    ];
}

foreach ($checks as &$check) {
    $checkId = (int) $check['id'];
    $samples = $resultsByCheck[$checkId] ?? [];
    $check['uptime24'] = null;
    $check['uptime7d'] = null;
    $check['coverage24'] = 0.0;
    $check['coverage7d'] = 0.0;
    $check['historyBars'] = array_fill(0, $bucketCount, null);
    $check['historyTitles'] = array_fill(0, $bucketCount, 'No check recorded');

    $up24 = $total24 = $up7 = $total7 = 0;
    $bucketStats = array_fill(0, $bucketCount, ['up' => 0, 'down' => 0]);

    foreach ($samples as $sample) {
        $age = $nowTimestamp - $sample['timestamp'];
        $isUp = $sample['status'] === 'up';

        $total7++;
        if ($isUp) {
            $up7++;
        }

        if ($age < $daySeconds) {
            $total24++;
            if ($isUp) {
                $up24++;
            }

            $bucketIndex = (int) floor($age / $bucketSeconds);
            if ($bucketIndex >= 0 && $bucketIndex < $bucketCount) {
                $key = $isUp ? 'up' : 'down';
                $bucketStats[$bucketIndex][$key]++;
            }
        }
    }

    if ($total24 > 0) {
        $check['uptime24'] = 100 * $up24 / $total24;
    }
    if ($total7 > 0) {
        $check['uptime7d'] = 100 * $up7 / $total7;
    }

    // A five-minute schedule means approximately 288 checks/day and 2,016/week.
    $check['coverage24'] = min(100, 100 * $total24 / 288);
    $check['coverage7d'] = min(100, 100 * $total7 / 2016);

    for ($i = 0; $i < $bucketCount; $i++) {
        $stats = $bucketStats[$i];
        $count = $stats['up'] + $stats['down'];
        $end = $nowTimestamp - ($i * $bucketSeconds);
        $start = $end - $bucketSeconds;
        $label = gmdate('H:i', $start) . '–' . gmdate('H:i', $end) . ' UTC';

        if ($count === 0) {
            $check['historyBars'][$i] = 'empty';
            $check['historyTitles'][$i] = $label . ': no checks recorded';
        } elseif ($stats['down'] > 0 && $stats['up'] > 0) {
            $check['historyBars'][$i] = 'mixed';
            $check['historyTitles'][$i] = $label . ': ' . $stats['down'] . ' down, ' . $stats['up'] . ' up';
        } elseif ($stats['down'] > 0) {
            $check['historyBars'][$i] = 'down';
            $check['historyTitles'][$i] = $label . ': ' . $stats['down'] . ' down';
        } else {
            $check['historyBars'][$i] = 'up';
            $check['historyTitles'][$i] = $label . ': ' . $stats['up'] . ' up';
        }
    }
}
unset($check);

// Most recent results for the event/history table.
$historySql = "
    SELECT
        c.id AS check_id,
        c.name,
        r.checked_at,
        r.status,
        r.response_time,
        r.http_code,
        r.error
    FROM results r
    JOIN checks c ON c.id = r.check_id
    ORDER BY r.id DESC
    LIMIT 20
";
$history = $db->query($historySql)->fetchAll(PDO::FETCH_ASSOC);

function ageText(?int $seconds): string
{
    if ($seconds === null) {
        return 'Never checked';
    }

    if ($seconds < 60) {
        return 'Just now';
    }

    $minutes = (int) floor($seconds / 60);
    if ($minutes < 60) {
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
    }

    $hours = (int) floor($minutes / 60);
    if ($hours < 24) {
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }

    $days = (int) floor($hours / 24);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

function readCpuTemperature(): ?float
{
    $paths = glob('/sys/class/thermal/thermal_zone*/temp') ?: [];
    foreach ($paths as $path) {
        $value = @file_get_contents($path);
        if ($value !== false && is_numeric(trim($value))) {
            $temperature = (float) trim($value);
            if ($temperature > 1000) {
                $temperature /= 1000;
            }
            if ($temperature > 0 && $temperature < 150) {
                return $temperature;
            }
        }
    }
    return null;
}

function readMemoryUsage(): ?float
{
    $contents = @file_get_contents('/proc/meminfo');
    if ($contents === false) {
        return null;
    }

    $values = [];
    foreach (explode("\n", $contents) as $line) {
        if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/', $line, $matches)) {
            $values[$matches[1]] = (int) $matches[2];
        }
    }

    if (empty($values['MemTotal']) || !isset($values['MemAvailable'])) {
        return null;
    }

    return 100 * ($values['MemTotal'] - $values['MemAvailable']) / $values['MemTotal'];
}

function readSystemUptime(): ?string
{
    $contents = @file_get_contents('/proc/uptime');
    if ($contents === false || !preg_match('/^(\d+(?:\.\d+)?)/', $contents, $matches)) {
        return null;
    }

    $seconds = (int) $matches[1];
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($days > 0) {
        return $days . 'd ' . $hours . 'h';
    }
    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }
    return $minutes . 'm';
}

$cpuTemperature = readCpuTemperature();
$memoryUsage = readMemoryUsage();
$diskTotal = @disk_total_space(dirname(__DIR__));
$diskFree = @disk_free_space(dirname(__DIR__));
$diskUsage = ($diskTotal && $diskFree !== false)
    ? 100 * ($diskTotal - $diskFree) / $diskTotal
    : null;
$loadAverages = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
$systemUptime = readSystemUptime();

function h(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function statusClass(?string $status): string
{
    return match ($status) {
        'up' => 'up',
        'down' => 'down',
        default => 'unknown',
    };
}

function uptimeText(?float $value): string
{
    return $value === null ? '—' : number_format($value, 2) . '%';
}

function responseTime(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    $seconds = (float) $value;
    if ($seconds < 1) {
        return number_format($seconds * 1000, 0) . ' ms';
    }

    return number_format($seconds, 2) . ' s';
}

$up = 0;
$down = 0;
$unknown = 0;

foreach ($checks as $check) {
    if ($check['status'] === 'up') {
        $up++;
    } elseif ($check['status'] === 'down') {
        $down++;
    } else {
        $unknown++;
    }
}

$total = count($checks);
if ($total === 0 || $unknown === $total) {
    $overallClass = 'unknown';
    $overallText = $total === 0 ? 'No monitors configured' : 'Waiting for first checks';
} elseif ($down > 0) {
    $overallClass = 'down';
    $overallText = $down === 1 ? '1 service is down' : $down . ' services are down';
} elseif ($unknown > 0) {
    $overallClass = 'unknown';
    $overallText = 'Some monitors have not reported yet';
} else {
    $overallClass = 'up';
    $overallText = 'All systems operational';
}

$latestCheck = $latestCheckTimestamp === null
    ? null
    : gmdate('Y-m-d H:i:s', $latestCheckTimestamp);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="60">
    <meta name="theme-color" content="#111827">
    <!-- Favicon -->
    <link rel="shortcut icon" type="image/png" href="icon.png">
    <link rel="apple-touch-icon" href="icon.png">
    <title>Pi Watchdog</title>
    <link rel="stylesheet" href="watchdog.css">
</head>

<body>
    <header class="topbar">
        <div class="topbar-inner">
            <div class="brand">
                <div class="brand-mark" aria-hidden="true"><img src="icon.png" width="38" alt="Pi Watchdog"></div>
                <div>
                    <h1>Pi Watchdog</h1>
                    <p class="subtitle">Home server &amp; service monitoring</p>
                </div>
            </div>
            <div class="header-actions">
                <a class="admin-link" href="admin.php">Manage monitors</a>
                <div class="overall">
                    <span class="dot <?= h($overallClass) ?>"></span>
                    <?= h($overallText) ?>
                </div>
            </div>
        </div>
    </header>

    <main>
        <?php if ($checksStale): ?>
            <div class="warning-banner" role="alert">
                <strong>Monitoring may be delayed.</strong>
                No check result has been recorded in the last 15 minutes. Check that cron and check.php are running.
            </div>
        <?php endif; ?>

        <section class="system-health" aria-labelledby="system-health-heading">
            <div class="section-heading">
                <h2 id="system-health-heading">Pi system health</h2>
                <span class="section-note">Current readings</span>
            </div>
            <div class="health-grid">
                <div class="health-item">
                    <div class="health-label">CPU temperature</div>
                    <div class="health-value"><?= $cpuTemperature === null ? 'Unavailable' : h(number_format($cpuTemperature, 1)) . '°C' ?></div>
                </div>
                <div class="health-item">
                    <div class="health-label">Memory used</div>
                    <div class="health-value"><?= $memoryUsage === null ? 'Unavailable' : h(number_format($memoryUsage, 0)) . '%' ?></div>
                </div>
                <div class="health-item">
                    <div class="health-label">Disk used</div>
                    <div class="health-value"><?= $diskUsage === null ? 'Unavailable' : h(number_format($diskUsage, 0)) . '%' ?></div>
                </div>
                <div class="health-item">
                    <div class="health-label">Load average</div>
                    <div class="health-value"><?= is_array($loadAverages) ? h(number_format((float) $loadAverages[0], 2)) : 'Unavailable' ?></div>
                    <div class="health-label">1 minute</div>
                </div>
                <div class="health-item">
                    <div class="health-label">System uptime</div>
                    <div class="health-value"><?= h($systemUptime ?? 'Unavailable') ?></div>
                </div>
            </div>
        </section>

        <div class="summary" aria-label="Monitor summary">
            <div class="summary-item"><strong><?= $total ?></strong> monitors</div>
            <div class="summary-item"><span class="dot up"></span><strong><?= $up ?></strong> up</div>
            <div class="summary-item"><span class="dot down"></span><strong><?= $down ?></strong> down</div>
            <div class="summary-item"><span class="dot unknown"></span><strong><?= $unknown ?></strong> pending</div>
        </div>

        <section aria-labelledby="current-heading">
            <div class="section-heading">
                <h2 id="current-heading">Monitored services</h2>
                <span class="section-note">Refreshes every 60 seconds · bars show 30-minute intervals</span>
            </div>

            <div class="panel">
                <?php if (!$checks): ?>
                    <div class="empty">No enabled checks are configured.</div>
                <?php endif; ?>

                <?php foreach ($checks as $check): ?>
                    <?php $state = statusClass($check['status']); ?>
                    <div class="service">
                        <div>
                            <div class="service-name">
                                <span class="dot <?= h($state) ?>"></span>
                                <h3><a href="service.php?id=<?= (int) $check['id'] ?>"><?= h($check['name']) ?></a></h3>
                            </div>
                            <div class="target"><?= h($check['target']) ?></div>
                        </div>

                        <div>
                            <span class="column-label">Status</span>
                            <span class="status-text <?= h($state) ?>">
                                <?= h(ucfirst($check['status'] ?? 'pending')) ?>
                            </span>
                        </div>

                        <div class="response-col">
                            <span class="column-label">Response</span>
                            <span class="response"><?= h(responseTime($check['response_time'])) ?></span>
                            <?php if ($check['http_code'] !== null): ?>
                                <div class="last-checked">HTTP <?= h($check['http_code']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="last-col">
                            <span class="column-label">Last checked (UTC)</span>
                            <span class="last-checked"><?= h($check['checked_at'] ?? 'Never') ?></span>
                            <div class="last-checked">
                                <?= h(ageText($check['checkedAge'])) ?>
                                <?php if ($check['isStale']): ?>
                                    <span class="stale-label">STALE</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="service-history" aria-label="24-hour check history for <?= h($check['name']) ?>">
                        <div class="history-bars">
                            <?php for ($bar = 47; $bar >= 0; $bar--): ?>
                                <span
                                    class="history-bar <?= h($check['historyBars'][$bar]) ?>"
                                    title="<?= h($check['historyTitles'][$bar]) ?>"
                                    aria-label="<?= h($check['historyTitles'][$bar]) ?>"></span>
                            <?php endfor; ?>
                        </div>
                        <div class="history-ends"><span>24 hours ago</span><span>Now</span></div>
                        <div class="history-meta">
                            <div class="uptime-stats">
                                <span>24h uptime: <strong><?= h(uptimeText($check['uptime24'])) ?></strong></span>
                                <span>7d uptime: <strong><?= h(uptimeText($check['uptime7d'])) ?></strong></span>
                            </div>
                            <span>Check coverage: <?= h(number_format($check['coverage24'], 0)) ?>% / <?= h(number_format($check['coverage7d'], 0)) ?>% (24h / 7d)</span>
                        </div>
                    </div>

                    <?php if (!empty($check['error'])): ?>
                        <p class="service-error"><?= h($check['error']) ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="history-section" aria-labelledby="history-heading">
            <div class="section-heading">
                <h2 id="history-heading">Recent checks</h2>
                <span class="section-note">Latest 20 results · UTC</span>
            </div>

            <div class="panel table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Service</th>
                            <th>Status</th>
                            <th>Response</th>
                            <th>HTTP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $row): ?>
                            <?php $rowState = statusClass($row['status']); ?>
                            <tr>
                                <td><?= h($row['checked_at']) ?></td>
                                <td><a href="service.php?id=<?= (int) $row['check_id'] ?>"><?= h($row['name']) ?></a></td>
                                <td>
                                    <span class="table-status">
                                        <span class="dot <?= h($rowState) ?>"></span>
                                        <span class="status-text <?= h($rowState) ?>"><?= h(ucfirst($row['status'])) ?></span>
                                    </span>
                                </td>
                                <td><?= h(responseTime($row['response_time'])) ?></td>
                                <td><?= h($row['http_code'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$history): ?>
                            <tr>
                                <td colspan="5" class="empty">No results recorded yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <footer>
        <span>Pi Watchdog · Monitoring history stored locally in SQLite</span>
        <span>Latest check: <?= h($latestCheck ?? 'No checks yet') ?> UTC</span>
    </footer>
</body>

</html>