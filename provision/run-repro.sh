#!/bin/bash
# Reproduce the updater "Move new files in place" failure on the Nextcloud VM.
#
# Stages a realistic file tree in the updater staging dir on NFS, generates
# continuous read access so ClamAV on-access (clamonacc) holds files open, then
# performs the updater's move (rename to local disk + rmdir). When clamonacc has
# a handle open at rmdir time, the NFS client has silly-renamed the file to
# .nfsXXXXXXXX and rmdir fails with "Directory not empty".
#
# Run on the nextcloud VM:  sudo /vagrant/provision/run-repro.sh
set -uo pipefail

STAGE=/data/nextcloud-data/updater-repro/downloads/nextcloud
DEST=/var/www/html/move-dest-$$        # local ext4, forces cross-device rename
NFILES=4000
READERS=4
HOLDER_LOG=/tmp/nfs-holder-$$.log      # processes caught holding .nfs* files

cleanup() {
  echo "=== cleanup ==="
  [ -n "${READER_PIDS:-}" ] && kill $READER_PIDS 2>/dev/null
  [ -n "${MONITOR_PID:-}" ] && kill $MONITOR_PID 2>/dev/null
  wait 2>/dev/null
  rm -rf "$DEST" /data/nextcloud-data/updater-repro 2>/dev/null
  rm -f "$HOLDER_LOG" 2>/dev/null
}
trap cleanup EXIT

echo "=== checking clamonacc is active ==="
if ! systemctl is-active --quiet clamonacc; then
  echo "WARNING: clamonacc not active; the bug needs the realtime scanner running." >&2
  systemctl status --no-pager clamonacc || true
fi

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

echo "=== starting $READERS background readers (provoke on-access scans) ==="
READER_PIDS=""
for _ in $(seq 1 "$READERS"); do
  ( while true; do
      find "$STAGE" -type f -exec cat {} + >/dev/null 2>&1
    done ) &
  READER_PIDS="$READER_PIDS $!"
done
sleep 3   # let clamonacc start opening files

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
  fi
  php /vagrant/provision/move-like-updater.php "$STAGE" "$DEST"
  rc=$?
  rm -rf "$DEST"
  if [ "$rc" -eq 42 ]; then
    echo
    echo "############################################################"
    echo "# BUG REPRODUCED on attempt $attempt: rmdir failed on NFS  #"
    echo "# (.nfsXXXXXXXX silly-rename held by clamonacc/clamd)       #"
    echo "############################################################"
    break
  fi
done

if [ "$rc" -ne 42 ]; then
  echo
  echo "Not reproduced in $ATTEMPTS attempts. Increase NFILES/READERS or"
  echo "confirm clamonacc is scanning /data (journalctl -u clamonacc)."
fi

exit 0
