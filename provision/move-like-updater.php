<?php
/**
 * Mimics Nextcloud/ODFWEB updater moveWithExclusions(): CHILD_FIRST recursive
 * iteration over an NFS source, rename() each file to a local destination
 * (cross-device => copy+unlink), then rmdir() each emptied directory.
 *
 * Argv: <source-on-nfs> <local-dest>
 * Exits 42 and prints leftovers + lsof when an rmdir fails (bug reproduced).
 */
if ($argc < 3) {
    fwrite(STDERR, "Usage: php move-like-updater.php <source-on-nfs> <local-dest>\n");
    exit(1);
}
$source = rtrim(realpath($argv[1]), '/');
$dest = rtrim($argv[2], '/');
@mkdir($dest, 0755, true);

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($it as $path => $info) {
    $rel = substr($path, strlen($source) + 1);
    if ($info->isFile() || $info->isLink()) {
        $target = $dest . '/' . $rel;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        if (@rename($path, $target) === false) {
            fwrite(STDERR, "rename failed: $path\n");
        }
    } elseif ($info->isDir()) {
        if (@rmdir($path) === false) {
            echo "\n=== REPRODUCED: rmdir failed (Directory not empty): $path ===\n";
            $leftovers = array_values(array_diff(scandir($path), ['.', '..']));
            echo 'Leftover entries: ' . count($leftovers) . " (silly-rename .nfs* held open by the scanner)\n";
            // NOTE: path-based `lsof -- <path>` and `lsof +D` do NOT match NFS
            // silly-rename files (lsof's dev+inode matching misses them), so we
            // resolve the holder by scanning /proc/<pid>/fd symlinks instead --
            // this reliably names the process keeping the .nfs* file open.
            $sample = array_slice($leftovers, 0, 5);
            foreach ($sample as $f) {
                echo "  $f\n";
                foreach (holdersOf("$path/$f") as $h) {
                    echo "    held open by: $h\n";
                }
            }
            if (count($leftovers) > count($sample)) {
                echo '  ... and ' . (count($leftovers) - count($sample)) . " more leftover entries\n";
            }
            exit(42);
        }
    }
}

/**
 * Find which processes hold the given file open, by scanning /proc/<pid>/fd
 * symlinks for one that resolves to $target. Returns "pid <pid> (<comm>) fd <n>"
 * strings. Reliable for NFS silly-rename (.nfs*) files, unlike lsof path match.
 */
function holdersOf($target) {
    $out = [];
    foreach (glob('/proc/[0-9]*/fd/*') as $fd) {
        if (@readlink($fd) === $target) {
            // /proc/<pid>/fd/<n>
            $parts = explode('/', $fd);
            $pid = $parts[2];
            $n = $parts[4];
            $comm = @trim(@file_get_contents("/proc/$pid/comm")) ?: '?';
            $out[] = "pid $pid ($comm) fd $n";
        }
    }
    return $out ?: ['(no holder found — handle already closed)'];
}
echo "All rmdir() succeeded — not reproduced this run (retry; it is timing-dependent).\n";
exit(0);
