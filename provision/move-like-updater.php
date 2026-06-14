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
            echo "Leftover entries:\n";
            foreach (array_diff(scandir($path), ['.', '..']) as $f) {
                echo "  $f\n";
                $full = escapeshellarg("$path/$f");
                echo "  lsof:\n";
                passthru("lsof -- $full 2>/dev/null");
            }
            echo "lsof +D over the directory:\n";
            passthru('lsof +D ' . escapeshellarg($path) . ' 2>/dev/null');
            exit(42);
        }
    }
}
echo "All rmdir() succeeded — not reproduced this run (retry; it is timing-dependent).\n";
exit(0);
