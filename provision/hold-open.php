<?php
/**
 * On-access-scanner emulator for the NFS silly-rename reproducer.
 *
 * Mimics how real-time antimalware (clamonacc, Defender, ...) touches files:
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
 * Argv: <root> [scan_window_ms=750]
 * Loops forever (re-scanning the tree, randomized order) until killed.
 */
if ($argc < 2) {
    fwrite(STDERR, "Usage: php hold-open.php <root> [scan_window_ms]\n");
    exit(1);
}
$root = $argv[1];
$windowUs = (isset($argv[2]) ? (int)$argv[2] : 15000) * 1000;   // ms -> us

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
