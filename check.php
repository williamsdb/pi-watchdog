<?php

declare(strict_types=1);

$db = new PDO('sqlite:' . __DIR__ . '/watchdog.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Load configuration.
$config = require __DIR__ . '/config.php';

$pushoverToken = $config['pushover']['token'] ?? '';
$pushoverUser  = $config['pushover']['user'] ?? '';
$pushoverPriority = $config['pushover']['priority'] ?? 1;

/**
 * Send a Pushover notification.
 */
function pushover(string $message, string $token, string $user, int $priority): bool
{
    if ($token === '' || $user === '') {
        return false;
    }

    $curl = curl_init('https://api.pushover.net/1/messages.json');

    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'token'   => $token,
            'user'    => $user,
            'priority'   => $priority,
            'message' => $message,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);

    unset($curl);

    if ($response === false || $httpCode !== 200) {
        error_log(
            'Pushover failed: ' .
                ($curlError !== '' ? $curlError : "HTTP $httpCode")
        );

        return false;
    }

    $result = json_decode($response, true);

    if (!isset($result['status']) || $result['status'] !== 1) {
        error_log('Pushover returned an unsuccessful response.');

        return false;
    }

    return true;
}

// Get enabled monitors.
$checks = $db->query(
    'SELECT id, name, type, target
     FROM checks
     WHERE enabled = 1'
)->fetchAll(PDO::FETCH_ASSOC);

// Retrieve the previous result BEFORE inserting the new one.
$previousResult = $db->prepare(
    'SELECT status
     FROM results
     WHERE check_id = :check_id
     ORDER BY id DESC
     LIMIT 1'
);

// Insert the latest result.
$insert = $db->prepare(
    'INSERT INTO results
        (check_id, status, response_time, http_code, error)
     VALUES
        (:check_id, :status, :response_time, :http_code, :error)'
);

foreach ($checks as $check) {
    $start = microtime(true);
    $status = 'down';
    $responseTime = null;
    $httpCode = null;
    $error = null;

    // Check the monitor's previous status.
    $previousResult->execute([
        ':check_id' => $check['id'],
    ]);

    $previousStatus = $previousResult->fetchColumn();

    if ($previousStatus === false) {
        $previousStatus = null;
    }

    // Perform the check.
    if ($check['type'] === 'ping') {
        $output = [];

        $command = 'ping -c 1 -W 3 '
            . escapeshellarg($check['target'])
            . ' 2>&1';

        exec($command, $output, $exitCode);

        $responseTime = microtime(true) - $start;

        if ($exitCode === 0) {
            $status = 'up';
        } else {
            $error = trim(implode("\n", $output));
        }
    } elseif ($check['type'] === 'http') {
        $curl = curl_init($check['target']);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_NOBODY => false,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $data): int {
                return strlen($data);
            },
        ]);

        curl_exec($curl);

        $httpCode = (int) curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );

        $responseTime = curl_getinfo(
            $curl,
            CURLINFO_TOTAL_TIME
        );

        $curlError = curl_error($curl);

        unset($curl);

        if ($curlError !== '') {
            $error = $curlError;
        } elseif ($httpCode >= 200 && $httpCode < 400) {
            $status = 'up';
        } else {
            $error = 'HTTP status ' . $httpCode;
        }
    }

    // Save the result regardless of notification availability.
    $insert->execute([
        ':check_id' => $check['id'],
        ':status' => $status,
        ':response_time' => $responseTime,
        ':http_code' => $httpCode,
        ':error' => $error,
    ]);

    printf(
        "%s: %s (%.3fs)%s\n",
        $check['name'],
        strtoupper($status),
        $responseTime ?? 0,
        $httpCode ? " HTTP $httpCode" : ''
    );

    if ($error !== null) {
        echo "  Error: $error\n";
    }

    // Notify only when an established status changes.
    // The first result establishes the baseline without a notification.
    if (
        $previousStatus !== null
        && $previousStatus !== $status
    ) {
        $time = date('H:i');

        if ($status === 'down') {
            $message = "🔴 {$check['name']} is DOWN\n"
                . "Time: {$time}";

            if ($error !== null) {
                $message .= "\nError: {$error}";
            }
        } else {
            $message = "🟢 {$check['name']} has recovered\n"
                . "Response time: "
                . number_format(($responseTime ?? 0) * 1000, 0)
                . " ms\n"
                . "Time: {$time}";
        }

        if ($pushoverToken !== '' && $pushoverUser !== '') {
            if (pushover($message, $pushoverToken, $pushoverUser, $pushoverPriority)) {
                echo "  Pushover notification sent.\n";
            } else {
                echo "  WARNING: Pushover notification failed.\n";
            }
        }
    }
}
