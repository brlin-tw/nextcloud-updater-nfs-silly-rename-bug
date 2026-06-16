<?php
/**
 * On-access-scanner emulator for the NFS silly-rename reproducer.
 *
 * Mimics how real-time antimalware (Defender, on-access scanners, ...) touches files:
 * it opens a file, holds the descriptor only for a short "scan window"
 * (default ~3s), then closes it and moves on to the next file -- it does
 * NOT keep every file open forever. The silly-rename (and the resulting
 * rmdir "Directory not empty" failure) therefore only happens when the
 * updater's unlink() lands inside one of these short open windows -- which is
 * exactly why the production failure is intermittent / timing-dependent.
 *
 * Run several copies in parallel (like an AV worker pool) to raise the odds
 * that some file is mid-scan at any given moment.
 *
 * Newly added files are picked up while running: the staging tree is on NFS,
 * where inotify/fanotify do NOT deliver events for changes made by another
 * client (the updater), so we cannot subscribe to filesystem events. Instead we
 * *poll* the directory (readdir, reliable over NFS thanks to close-to-open
 * consistency) on a short interval and append any not-yet-queued files to a live
 * work queue -- so files the updater stages after we started still get held.
 *
 * Argv: [root] [scan_window_ms=15000]
 * When <root> is omitted, the Nextcloud updater staging directory
 * (<datadirectory>/updater-<instanceid>) is auto-detected via occ.
 * Loops forever (re-polling the tree, randomized order) until killed.
 */
$root = (isset($argv[1]) && $argv[1] !== '') ? $argv[1] : detectUpdaterDir();
if ($root === null || $root === '') {
    fwrite(STDERR, "Usage: php hold-open.php [root] [scan_window_ms]\n");
    fwrite(STDERR, "Could not auto-detect the Nextcloud updater staging directory.\n");
    exit(1);
}
$windowUs = (isset($argv[2]) ? (int)$argv[2] : 15000) * 1000;   // ms -> us

// The updater extracts the new release under <staging>/downloads/nextcloud and
// only that subtree gets moved into place (and thus silly-renamed). Hold files
// there, not in the staging root, so we land inside the move's window. Append
// the suffix only when the caller didn't already point us at it.
$holdRoot = rtrim($root, '/');
if (substr($holdRoot, -strlen('/downloads/nextcloud')) !== '/downloads/nextcloud') {
    $holdRoot .= '/downloads/nextcloud';
}

/**
 * Auto-detect the Nextcloud updater staging directory by querying occ for the
 * data directory and instance id: <datadirectory>/updater-<instanceid>.
 * occ is run as the user owning occ (typically www-data) to avoid the
 * "executed with the wrong user" refusal when this script runs as root.
 */
function detectUpdaterDir() {
    $occ = getenv('NC_OCC') ?: '/var/www/html/nextcloud/occ';
    if (!is_file($occ)) {
        return null;
    }
    $dataDir = occGet($occ, 'datadirectory');
    $instanceId = occGet($occ, 'instanceid');
    if ($dataDir === null || $instanceId === null) {
        return null;
    }
    return rtrim($dataDir, '/') . '/updater-' . $instanceId;
}

/**
 * Run `occ config:system:get <key>` as occ's owner and return the trimmed value
 * (or null on failure).
 */
function occGet($occ, $key) {
    $runAs = '';
    $ownerId = @fileowner($occ);
    if ($ownerId !== false && function_exists('posix_getpwuid')) {
        $owner = posix_getpwuid($ownerId);
        if ($owner && posix_geteuid() !== $ownerId) {
            $runAs = 'sudo -u ' . escapeshellarg($owner['name']) . ' ';
        }
    }
    $cmd = $runAs . 'php ' . escapeshellarg($occ)
        . ' config:system:get ' . escapeshellarg($key) . ' 2>/dev/null';
    $value = @shell_exec($cmd);
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    return $value === '' ? null : $value;
}

$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running) { $running = false; };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

$rescanIntervalUs = 1000 * 1000;   // poll the NFS dir for new files every ~1s
$pending = [];   // path => true: queued or currently held (avoids re-queuing)
$queue = [];     // ordered list of paths still to scan
$lastPoll = 0;

// Wait for the updater's downloads/nextcloud subtree to be created before
// holding anything: it does not exist until the updater stages the release,
// and there is nothing to hold until it does. Poll over NFS (no inotify).
fwrite(STDERR, "waiting for $holdRoot to appear...\n");
while ($running && !is_dir($holdRoot)) {
    usleep(200000);
}
if ($running) {
    fwrite(STDERR, "holding open: $holdRoot\n");
}

while ($running) {
    if (!is_dir($holdRoot)) {
        usleep(100000);
        continue;
    }

    // Poll for newly staged files on an interval (or whenever the queue drains).
    // inotify/fanotify can't see the updater's writes over NFS, so re-reading
    // the directory is the only reliable way to notice files added after start.
    $now = (int)(microtime(true) * 1e6);
    if ($now - $lastPoll >= $rescanIntervalUs || !$queue) {
        pollNewFiles($holdRoot, $pending, $queue);
        $lastPoll = $now;
    }

    if (!$queue) {
        usleep(100000);   // nothing to hold yet; wait for files to appear
        continue;
    }

    $path = array_shift($queue);
    // Open -> hold for the scan window -> read a byte -> close.
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        unset($pending[$path]);   // moved away by the updater; let it re-queue if it returns
        continue;
    }
    // Report what this worker is "scanning" (holding open) right now.
    fwrite(STDERR, '[' . getmypid() . "] scanning: $path\n");
    @fread($fh, 1);
    // Jitter the window +/-50% so holds aren't lock-step across scanners.
    $jittered = (int)($windowUs * (0.5 + (mt_rand(0, 1000) / 1000)));
    usleep($jittered);
    @fclose($fh);
    unset($pending[$path]);
}

/**
 * Walk the tree and append files not already queued/held to $queue (recording
 * them in $pending). Skips .nfs* silly-rename files. Tolerates directories
 * vanishing mid-walk as the updater removes them. The new batch is shuffled so
 * parallel scanners spread out instead of all sitting on the same file.
 */
function pollNewFiles($root, array &$pending, array &$queue) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );
    $new = [];
    foreach ($it as $path => $info) {
        if (!$info->isFile() || strpos($info->getFilename(), '.nfs') === 0) {
            continue;
        }
        if (isset($pending[$path])) {
            continue;   // already queued or currently held
        }
        $pending[$path] = true;
        $new[] = $path;
    }
    shuffle($new);
    foreach ($new as $p) {
        $queue[] = $p;
    }
}
