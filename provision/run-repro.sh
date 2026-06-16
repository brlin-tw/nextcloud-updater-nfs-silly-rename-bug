#!/bin/bash
# Reproduce the updater "Move new files in place" failure on the Nextcloud VM.
#
# Stages a realistic file tree in the updater staging dir on NFS, runs a pool of
# on-access-scanner emulators (hold-open.php) that hold files open in short scan
# windows, then performs the updater's move (rename to local disk + rmdir). When
# a scanner has a handle open at rmdir time, the NFS client has silly-renamed the
# file to .nfsXXXXXXXX and rmdir fails with "Directory not empty".
#
# Run on the nextcloud VM:  sudo /vagrant/provision/run-repro.sh
set -uo pipefail

STAGE=/data/nextcloud-data/updater-repro/downloads/nextcloud
DEST=/var/www/html/move-dest-$$        # local ext4, forces cross-device rename
NFILES=4000
SCANNERS="${SCANNERS:-6}"              # parallel scan "workers" (AV worker pool)
SCAN_WINDOW_MS="${SCAN_WINDOW_MS:-15000}" # how long each file is held open per scan.
                                          # Must outlive the move's pass over the
                                          # directory (unlink -> ... -> rmdir) so the
                                          # holder still has the fd open when lsof runs
                                          # at failure -- otherwise only a lingering
                                          # .nfs* file remains and lsof is empty.

# Each scanner holds only a few fds at a time, but raise the limit for headroom.
ulimit -n 65536 2>/dev/null || true

cleanup() {
  echo "=== cleanup ==="
  [ -n "${SCANNER_PIDS:-}" ] && kill $SCANNER_PIDS 2>/dev/null
  wait 2>/dev/null
  rm -rf "$DEST" /data/nextcloud-data/updater-repro 2>/dev/null
}
trap cleanup EXIT

# We emulate an on-access scanner with hold-open.php: each worker opens a file,
# holds it for ~SCAN_WINDOW_MS, then closes it and moves on -- like real
# antimalware. The bug trips only when the updater's unlink() lands inside one
# of those short open windows, so it stays timing-dependent.

echo "=== staging $NFILES files under $STAGE (NFS) ==="
rm -rf /data/nextcloud-data/updater-repro
mkdir -p "$STAGE/3rdparty/.patches" "$STAGE/themes/example/core/img" "$STAGE/core"
for i in $(seq 1 "$NFILES"); do
  d="$STAGE/3rdparty/.patches"
  [ $((i % 3)) -eq 0 ] && d="$STAGE/themes/example/core/img"
  [ $((i % 3)) -eq 1 ] && d="$STAGE/core"
  head -c 8192 /dev/urandom > "$d/file_$i.dat"
done
echo "staged $(find "$STAGE" -type f | wc -l) files."

echo "=== starting $SCANNERS scan workers (window ${SCAN_WINDOW_MS}ms) over $STAGE ==="
SCANNER_PIDS=""
for _ in $(seq 1 "$SCANNERS"); do
  php /vagrant/provision/hold-open.php "$STAGE" "$SCAN_WINDOW_MS" &
  SCANNER_PIDS="$SCANNER_PIDS $!"
done
sleep 2   # let the scanners get into their scan loop before the move

echo "=== running updater-style move (rename to $DEST + rmdir) ==="
ATTEMPTS=5
rc=0
for attempt in $(seq 1 "$ATTEMPTS"); do
  echo "--- attempt $attempt/$ATTEMPTS ---"
  # Re-stage if a previous attempt partially moved the tree.
  if [ ! -d "$STAGE" ] || [ -z "$(find "$STAGE" -type f 2>/dev/null)" ]; then
    echo "tree consumed by a prior attempt; re-staging quickly..."
    mkdir -p "$STAGE/3rdparty/.patches"
    for i in $(seq 1 500); do head -c 8192 /dev/urandom > "$STAGE/3rdparty/.patches/file_$i.dat"; done
    sleep 2   # let the holder open the re-staged files before moving
  fi
  php /vagrant/provision/move-like-updater.php "$STAGE" "$DEST"
  rc=$?
  rm -rf "$DEST"
  if [ "$rc" -eq 42 ]; then
    echo
    echo "############################################################"
    echo "# BUG REPRODUCED on attempt $attempt: rmdir failed on NFS  #"
    echo "# (.nfsXXXXXXXX silly-rename; open handle held by holder)   #"
    echo "############################################################"
    break
  fi
done

if [ "$rc" -ne 42 ]; then
  echo
  echo "Not reproduced in $ATTEMPTS attempts. Increase SCANNERS/NFILES or"
  echo "lengthen SCAN_WINDOW_MS and retry (the failure is timing-dependent)."
fi

exit 0
