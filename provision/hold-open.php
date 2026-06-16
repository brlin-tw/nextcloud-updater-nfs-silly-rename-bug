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
 * Argv: [root] [scan_window_ms=15000]
 * When <root> is omitted, the Nextcloud updater staging directory
 * (<datadirectory>/updater-<instanceid>) is auto-detected via occ.
 * Loops forever (re-scanning the tree, randomized order) until killed.
 */
$root = (isset($argv[1]) && $argv[1] !== '') ? $argv[1] : detectUpdaterDir();
if ($root === null || $root === '') {
    fwrite(STDERR, "Usage: php hold-open.php [root] [scan_window_ms]\n");
    fwrite(STDERR, "Could not auto-detect the Nextcloud updater staging directory.\n");
    exit(1);
}
$windowUs = (isset($argv[2]) ? (int)$argv[2] : 15000) * 1000;   // ms -> us

fwrite(STDERR, "holding open: $root\n");

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

while ($running) {
    if (!is_dir($root)) {
        usleep(100000);
        continue;
    }

    // Snapshot the current file list, shuffled so parallel scanners spread out
    // and don't all sit on the same file (mimics independent AV scan ordering).
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $path => $info) {
        if ($info->isFile() && strpos($info->getFilename(), '.nfs') !== 0) {
            $files[] = $path;
        }
    }
    shuffle($files);

    foreach ($files as $path) {
        if (!$running) {
            break;
        }
        // Open -> hold for the scan window -> read a byte -> close.
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            continue;   // already moved away by the updater; skip
        }
        @fread($fh, 1);
        // Jitter the window +/-50% so holds aren't lock-step across scanners.
        $jittered = (int)($windowUs * (0.5 + (mt_rand(0, 1000) / 1000)));
        usleep($jittered);
        @fclose($fh);
    }
}
