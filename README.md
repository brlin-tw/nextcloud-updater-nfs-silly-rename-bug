# NFS + ClamAV updater `rmdir` failure reproducer (Vagrant)

Reproduces the ODFWEB / Nextcloud updater **"Move new files in place"** failure
(`rmdir: Directory not empty` / `無法移除資料夾`) that happens in production when
the data/updater staging directory is on **NFS** and a local process holds files
open during the move. Here the open-handle holder is **ClamAV on-access
(realtime) scanning** — the same class of culprit as the production incident.

## Why it fails

`moveWithExclusions()` (`updaterOdfweb/index.php:889`, upstream
`updater/lib/Updater.php:1163`) iterates CHILD_FIRST and, per entry:

1. `rename()`s each file from NFS to the local web root — a **cross-device**
   move, so PHP does copy + `unlink()`.
2. `rmdir()`s the now-"empty" directory.

If clamonacc has the file open via fanotify when PHP `unlink()`s it, the NFS
client **silly-renames** it to `.nfsXXXXXXXX` in the same directory instead of
deleting it. The directory is therefore not empty and `rmdir()` throws.

## Topology

| VM         | IP            | Role                                                    |
|------------|---------------|---------------------------------------------------------|
| `nfs`      | 192.168.56.10   | NFS server, exports `/export/data`                      |
| `nextcloud`| 192.168.56.20   | Apache/PHP/MariaDB + Nextcloud; `/data` ← NFS; clamonacc|

The Nextcloud **data directory lives on the NFS mount** (`/data/nextcloud-data`),
so the updater staging dir is naturally on NFS — matching production
(`production-fstab.jpg`, `production-datafs-mount-parameters.jpg`).

## Usage

```bash
cd vagrant-nfs-clamav-repro
vagrant up                       # brings up both VMs (NFS first, then nextcloud)

# Reproduce the failure:
vagrant ssh nextcloud -c 'sudo /vagrant/provision/run-repro.sh'
```

Expected tail of output:

```text
=== REPRODUCED: rmdir failed (Directory not empty): /data/.../3rdparty/.patches ===
Leftover entries:
  .nfs00000000abc...
  lsof:
  clamd  <pid> root ... /data/.../.patches/.nfs00000000abc...
############################################################
# BUG REPRODUCED ...                                        #
############################################################
```

The `lsof` output names the process (clamd/clamonacc) holding the silly-renamed
file — the realtime-scanning equivalent of the production diagnosis.

## Files

- `Vagrantfile` — two-VM definition.
- `provision/nfs-server.sh` — NFS export.
- `provision/nextcloud.sh` — Apache/PHP/MariaDB + Nextcloud, NFS mount, data dir on NFS.
- `provision/clamav.sh` — ClamAV + on-access scanning (clamonacc) over `/data`.
- `provision/move-like-updater.php` — the updater's move logic, isolated.
- `provision/run-repro.sh` — stages files, drives access, runs the move, reports.

## Notes & tuning

- The failure is **timing-dependent**; `run-repro.sh` retries 5× and runs
  background readers to keep the scanner busy. Raise `NFILES`/`READERS` in the
  script if it does not trip on the first run.
- Confirm the scanner is live: `vagrant ssh nextcloud -c 'journalctl -u clamonacc -n 30'`.
- The same mechanism reproduces with any local open-handle holder (backup
  agents, indexers, opcache) — ClamAV is just the most common realtime one.
- **Fix under test:** point the updater staging dir at local disk (Nextcloud
  `updatedirectory` in `config.php`) so source and destination share a
  filesystem; `rename()` becomes atomic with no copy+unlink and no silly rename.
