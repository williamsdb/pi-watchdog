<?php

declare(strict_types=1);

session_start();

$db = new PDO('sqlite:' . dirname(__DIR__) . '/watchdog.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create a CSRF token for administrative forms.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$error = '';
$success = '';
$editCheck = null;

function h(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function redirectToAdmin(string $message): never
{
    header('Location: admin.php?message=' . urlencode($message));
    exit;
}

// Display messages following redirects.
$messages = [
    'added'   => 'Monitor added successfully.',
    'updated' => 'Monitor updated successfully.',
    'enabled' => 'Monitor enabled.',
    'disabled' => 'Monitor disabled.',
    'deleted' => 'Monitor and its history deleted.',
];

if (isset($_GET['message'])) {
    $success = $messages[$_GET['message']] ?? '';
}

// Process administrative actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        http_response_code(403);
        exit('Invalid security token. Please reload the page and try again.');
    }

    $action = $_POST['action'] ?? '';
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

    try {
        switch ($action) {
            case 'add':
            case 'update':
                $name = trim($_POST['name'] ?? '');
                $type = $_POST['type'] ?? '';
                $target = trim($_POST['target'] ?? '');
                $enabled = isset($_POST['enabled']) ? 1 : 0;

                if ($name === '' || $target === '') {
                    throw new RuntimeException(
                        'Please enter both a name and a target.'
                    );
                }

                if (strlen($name) > 200) {
                    throw new RuntimeException(
                        'The monitor name must be 200 characters or fewer.'
                    );
                }

                if (
                    strlen($target) > 2048 ||
                    !in_array($type, ['ping', 'http'], true)
                ) {
                    throw new RuntimeException(
                        'Please provide a valid target and check type.'
                    );
                }

                if ($type === 'http') {
                    $validUrl = filter_var(
                        $target,
                        FILTER_VALIDATE_URL
                    );

                    $scheme = strtolower(
                        (string) parse_url($target, PHP_URL_SCHEME)
                    );

                    if (
                        $validUrl === false ||
                        !in_array($scheme, ['http', 'https'], true)
                    ) {
                        throw new RuntimeException(
                            'Enter a valid HTTP or HTTPS URL.'
                        );
                    }
                }

                if (
                    $type === 'ping' &&
                    filter_var($target, FILTER_VALIDATE_IP) === false
                ) {
                    throw new RuntimeException(
                        'For ping checks, enter a valid IP address.'
                    );
                }

                if ($action === 'add') {
                    $stmt = $db->prepare(
                        'INSERT INTO checks
                            (name, type, target, enabled)
                         VALUES
                            (:name, :type, :target, :enabled)'
                    );

                    $stmt->execute([
                        ':name' => $name,
                        ':type' => $type,
                        ':target' => $target,
                        ':enabled' => $enabled,
                    ]);

                    redirectToAdmin('added');
                }

                if (!$id || $id < 1) {
                    throw new RuntimeException(
                        'Invalid monitor ID.'
                    );
                }

                $stmt = $db->prepare(
                    'UPDATE checks
                     SET name = :name,
                         type = :type,
                         target = :target,
                         enabled = :enabled
                     WHERE id = :id'
                );

                $stmt->execute([
                    ':name' => $name,
                    ':type' => $type,
                    ':target' => $target,
                    ':enabled' => $enabled,
                    ':id' => $id,
                ]);

                redirectToAdmin('updated');

            case 'toggle':
                if (!$id || $id < 1) {
                    throw new RuntimeException(
                        'Invalid monitor ID.'
                    );
                }

                $stmt = $db->prepare(
                    'UPDATE checks
                     SET enabled = CASE
                         WHEN enabled = 1 THEN 0
                         ELSE 1
                     END
                     WHERE id = :id'
                );

                $stmt->execute([':id' => $id]);

                if ($stmt->rowCount() === 0) {
                    throw new RuntimeException(
                        'Monitor not found.'
                    );
                }

                $stmt = $db->prepare(
                    'SELECT enabled FROM checks WHERE id = :id'
                );
                $stmt->execute([':id' => $id]);
                $enabled = $stmt->fetchColumn();

                redirectToAdmin(
                    (int) $enabled === 1 ? 'enabled' : 'disabled'
                );

            case 'delete':
                if (!$id || $id < 1) {
                    throw new RuntimeException(
                        'Invalid monitor ID.'
                    );
                }

                // Remove the monitor and its history together.
                $db->beginTransaction();

                $stmt = $db->prepare(
                    'DELETE FROM results WHERE check_id = :id'
                );
                $stmt->execute([':id' => $id]);

                $stmt = $db->prepare(
                    'DELETE FROM checks WHERE id = :id'
                );
                $stmt->execute([':id' => $id]);

                if ($stmt->rowCount() === 0) {
                    $db->rollBack();

                    throw new RuntimeException(
                        'Monitor not found.'
                    );
                }

                $db->commit();

                redirectToAdmin('deleted');

            default:
                throw new RuntimeException(
                    'Unknown administrative action.'
                );
        }
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log('Pi Watchdog admin database error: ' . $e->getMessage());

        $error = 'A database error occurred. Please check the server log.';
    }
}

// Load a monitor for editing.
if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

    if ($editId && $editId > 0) {
        $stmt = $db->prepare(
            'SELECT id, name, type, target, enabled
             FROM checks
             WHERE id = :id'
        );

        $stmt->execute([':id' => $editId]);
        $editCheck = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$editCheck) {
        $error = 'The requested monitor could not be found.';
    }
}

// Preserve submitted values when validation fails.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '') {
    if (($_POST['action'] ?? '') === 'update') {
        $editCheck = [
            'id' => (int) ($_POST['id'] ?? 0),
            'name' => $_POST['name'] ?? '',
            'type' => $_POST['type'] ?? 'http',
            'target' => $_POST['target'] ?? '',
            'enabled' => isset($_POST['enabled']) ? 1 : 0,
        ];
    }
}

$checks = $db->query(
    'SELECT id, name, type, target, enabled
     FROM checks
     ORDER BY name'
)->fetchAll(PDO::FETCH_ASSOC);

$latestCheck = $db->query('SELECT MAX(checked_at) FROM results')->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Manage monitors — Pi Watchdog</title>

    <link rel="shortcut icon" type="image/png" href="icon.png">
    <link rel="apple-touch-icon" href="icon.png">

    <link rel="stylesheet" href="watchdog.css">
</head>

<body class="admin-page">
    <header class="topbar">
        <div class="topbar-inner">
            <div class="brand">
                <div class="brand-mark" aria-hidden="true"><img src="icon.png" width="38" alt="Pi Watchdog"></div>
                <div>
                    <h1>Pi Watchdog</h1>
                    <p class="subtitle">Manage monitored services</p>
                </div>
            </div>
            <div class="header-actions">
                <a class="admin-link" href="index.php">Back to dashboard</a>
            </div>
        </div>
    </header>

    <main>

        <?php if ($success !== ''): ?>
            <div class="message success">
                <?= h($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="message error">
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <section class="panel">
            <h2>Current monitors</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Target</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($checks as $check): ?>
                            <tr>
                                <td><?= h($check['name']) ?></td>

                                <td><?= h(strtoupper($check['type'])) ?></td>

                                <td class="target">
                                    <?= h($check['target']) ?>
                                </td>

                                <td class="<?= $check['enabled']
                                                ? 'enabled' : 'disabled' ?>">
                                    <?= $check['enabled']
                                        ? 'Enabled' : 'Disabled' ?>
                                </td>

                                <td>
                                    <div class="actions">
                                        <a class="button"
                                            href="admin.php?edit=<?= (int) $check['id'] ?>">
                                            Edit
                                        </a>

                                        <form method="post" action="admin.php">
                                            <input type="hidden" name="csrf_token"
                                                value="<?= h($csrfToken) ?>">
                                            <input type="hidden" name="action"
                                                value="toggle">
                                            <input type="hidden" name="id"
                                                value="<?= (int) $check['id'] ?>">

                                            <button type="submit"
                                                class="<?= $check['enabled']
                                                            ? 'button-warning'
                                                            : 'button-secondary' ?>">
                                                <?= $check['enabled']
                                                    ? 'Disable' : 'Enable' ?>
                                            </button>
                                        </form>

                                        <form method="post" action="admin.php"
                                            onsubmit="return confirm(
                                          'Delete this monitor and all its history? This cannot be undone.'
                                      );">
                                            <input type="hidden" name="csrf_token"
                                                value="<?= h($csrfToken) ?>">
                                            <input type="hidden" name="action"
                                                value="delete">
                                            <input type="hidden" name="id"
                                                value="<?= (int) $check['id'] ?>">

                                            <button type="submit"
                                                class="button-danger">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$checks): ?>
                            <tr>
                                <td colspan="5">No monitors configured.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel" id="monitor-form">
            <h2><?= $editCheck ? 'Edit monitor' : 'Add a monitor' ?></h2>

            <form method="post" action="admin.php">
                <input type="hidden" name="csrf_token"
                    value="<?= h($csrfToken) ?>">

                <input type="hidden" name="action"
                    value="<?= $editCheck ? 'update' : 'add' ?>">

                <?php if ($editCheck): ?>
                    <input type="hidden" name="id"
                        value="<?= (int) $editCheck['id'] ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div>
                        <label for="name">Monitor name</label>

                        <input type="text" id="name" name="name"
                            maxlength="200" required
                            value="<?= h($editCheck['name']
                                        ?? ($_POST['name'] ?? '')) ?>"
                            placeholder="e.g. Family History Website">
                    </div>

                    <div>
                        <label for="type">Check type</label>

                        <select id="type" name="type">
                            <option value="http"
                                <?= ($editCheck['type']
                                    ?? ($_POST['type'] ?? 'http')) === 'http'
                                    ? 'selected' : '' ?>>
                                HTTP — website or web service
                            </option>

                            <option value="ping"
                                <?= ($editCheck['type']
                                    ?? ($_POST['type'] ?? '')) === 'ping'
                                    ? 'selected' : '' ?>>
                                Ping — IP address
                            </option>
                        </select>
                    </div>

                    <div class="full-width">
                        <label for="target">Target</label>

                        <input type="text" id="target" name="target"
                            maxlength="2048" required
                            value="<?= h($editCheck['target']
                                        ?? ($_POST['target'] ?? '')) ?>"
                            placeholder="https://example.com/">
                    </div>

                    <div class="full-width">
                        <label class="checkbox">
                            <input type="checkbox" name="enabled" value="1"
                                <?= (int) ($editCheck['enabled']
                                    ?? (isset($_POST['enabled']) ? 1 : 0)) === 1
                                    || (!$editCheck &&
                                        $_SERVER['REQUEST_METHOD'] !== 'POST')
                                    ? 'checked' : '' ?>>
                            Enable this monitor
                        </label>
                    </div>

                    <div class="full-width">
                        <button type="submit">
                            <?= $editCheck ? 'Save changes' : 'Add monitor' ?>
                        </button>

                        <?php if ($editCheck): ?>
                            <a class="button button-secondary"
                                href="admin.php">Cancel editing</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </section>
    </main>

    <footer>
        <span>Pi Watchdog · Monitoring history stored locally in SQLite</span>
        <span>Latest check: <?= h($latestCheck ?: 'No checks yet') ?> UTC</span>
    </footer>
</body>

</html>