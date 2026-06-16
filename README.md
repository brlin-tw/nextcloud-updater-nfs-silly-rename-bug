# NFS + on-access-scanner updater `rmdir` failure reproducer (Vagrant)

Reproduces the ODFWEB / Nextcloud updater **"Move new files in place"** failure
(`rmdir: Directory not empty` / `無法移除資料夾`) that happens in production when
the data/updater staging directory is on **NFS** and a local process holds files
open during the move.

The open-handle holder here is a small **on-access-scanner emulator**
(`provision/hold-open.php`): a pool of workers that, like real antimalware, each
open a file, hold it only for a bounded **scan window** (`SCAN_WINDOW_MS`,
default 15000ms), then close it and move on. The bug trips only when the
updater's `unlink()` lands inside one of those windows — so it stays
**intermittent / timing-dependent**, matching production (typically only a
handful of files, not the whole tree, get silly-renamed per run).

The default window (15s) is deliberately generous so the holding process is
still open when `rmdir` fails and the diagnostic can name it; lower it toward a
real scanner's sub-second scan time (`SCAN_WINDOW_MS=500`) for a more realistic
but rarer trip. See **Notes & tuning**.

> **Why not ClamAV?** This repro originally used ClamAV on-access (clamonacc)
> realtime scanning as the holder, but that does **not** work over NFS:
> clamonacc's fanotify (fd-holding) scanner cannot arm on an NFS mount — only its
> non-blocking inotify "extra scanning" thread comes up, which never holds a
> handle at `unlink()` time. So the bug never tripped. That is why the holder
> here is the `hold-open.php` emulator, which opens files directly over the NFS
> mount instead of relying on fanotify.

## Why it fails

`moveWithExclusions()` (`updater/lib/Updater.php:1163`) iterates CHILD_FIRST and, per entry:

1. `rename()`s each file from NFS to the local web root — a **cross-device**
   move, so PHP does copy + `unlink()`.
2. `rmdir()`s the now-"empty" directory.

If the holder has the file open when PHP `unlink()`s it, the NFS client
**silly-renames** it to `.nfsXXXXXXXX` in the same directory instead of deleting
it. The directory is therefore not empty and `rmdir()` throws.

## Topology

| VM         | IP            | Role                                                    |
|------------|---------------|---------------------------------------------------------|
| `nfs`      | 192.168.56.10   | NFS server, exports `/export/data`                      |
| `nextcloud`| 192.168.56.20   | Apache/PHP/MariaDB + Nextcloud; `/data` ← NFS; runs the on-access-scanner emulator |

The Nextcloud **data directory lives on the NFS mount** (`/data/nextcloud-data`),
so the updater staging dir is naturally on NFS.

## Usage

Refer to the following instructions to reproduce the bug:

1. Run the following command to provision the test VMs(NFS first, then nextcloud):

    ```bash
    vagrant up
    ```

1. Run the following command to emulate the on-access-scanner temporarily holding files in the updater staging directory:

    ```bash
    vagrant ssh nextcloud -c 'sudo php /vagrant/provision/hold-open.php'
    ```

1. Open <http://192.168.56.20/> in a Web browser and log-in using the admin/admin12345 default credentials.
1. In the User menu > Administration settings > Administration > Overview > Update section click the "Open updater" blue button to launch the updater and follow the steps to update the Nextcloud instance.

   **Note:** If no updates are available, switch the update channel to `Beta` and reload the page to trigger the update check.

## Expected behavior

Update completed without error.

## Current behavior

Update failed during the "Move new files in place" step with an `rmdir: Directory not empty` error, caused by a `.nfs*` file left behind by the on-access-scanner emulator scanning some of the files in the updater staging directory.

## Use the alternative reproducer script

The `provision/run-repro.sh` script stages a tree of files on the NFS export, starts the holder, simulates the logic of `moveWithExclusions()`, and reports any leftover `.nfs*` files that cause `rmdir` to fail.

Run the following command to execute the repro script on the `nextcloud` VM:

```bash
vagrant ssh nextcloud -c 'sudo /vagrant/provision/run-repro.sh'
```

Expected tail of output:

```text
=== REPRODUCED: rmdir failed (Directory not empty): /data/.../3rdparty/.patches ===
Leftover entries: 1 (silly-rename .nfs* held open by the scanner)
  .nfs00000000000c0b7d00001b8c
    held open by: pid 83943 (php) fd 5
############################################################
# BUG REPRODUCED on attempt 1: rmdir failed on NFS  #
############################################################
```

Usually only a **few** files (often just one) are silly-renamed per run — the
ones whose `unlink()` happened to land inside a scan window — matching the
production incident where a single leftover broke the step.

The "held open by" line names the culprit process. It is resolved by scanning
`/proc/<pid>/fd` symlinks, **not** `lsof`: path-based `lsof -- <path>` and
`lsof +D` do **not** match NFS silly-rename (`.nfs*`) files — lsof's device+inode
matching misses them, even though `lsof -p <pid>` would show the same handle. The
`/proc` scan is reliable for this case. (For the holder to be named, its scan
window must still be open when `rmdir` fails — see `SCAN_WINDOW_MS` below; with a
very short window the handle may already be closed, leaving only the transient
`.nfs*` file, which is itself a faithful illustration of how fleeting they are.)

## Files

- `Vagrantfile` — two-VM definition.
- `provision/nfs-server.sh` — NFS export.
- `provision/nextcloud.sh` — Apache/PHP/MariaDB + Nextcloud, NFS mount, data dir on NFS.
- `provision/hold-open.php` — on-access-scanner emulator; each worker opens a file, holds it for `SCAN_WINDOW_MS`, closes it, and moves on (like real AV).
- `provision/move-like-updater.php` — the updater's move logic, isolated.
- `provision/run-repro.sh` — stages files, starts the holder, runs the move, reports.

## Notes & tuning

- The failure is **timing-dependent** (as in production). `run-repro.sh` retries
  5×. Tune via env vars:
  - `SCANNERS` (default 6) — number of parallel scan workers (AV worker pool).
  - `SCAN_WINDOW_MS` (default 15000) — how long each file is held open per scan.
    Must outlive the move's pass over a directory so a scanner still holds the
    handle when `rmdir` fails; then the `/proc`-based diagnostic can name the
    culprit process. A shorter window is more realistic but often leaves only the
    transient `.nfs*` file with no live holder to report.
  - `NFILES` (default 4000) — size of the staged tree.

  More scanners / a longer window → higher hit probability (toward
  deterministic); fewer / shorter → rarer, more like a lightly-loaded scanner.
  Example: `SCANNERS=12 SCAN_WINDOW_MS=1500 sudo -E /vagrant/provision/run-repro.sh`.
- The same mechanism reproduces with any local open-handle holder (backup
  agents, indexers, opcache, antivirus) — this emulator just models the
  open→scan→close timing of a real on-access scanner.
- **Fix under test:** point the updater staging dir at local disk (Nextcloud
  `updatedirectory` in `config.php`) so source and destination share a
  filesystem; `rename()` becomes atomic with no copy+unlink and no silly rename.
