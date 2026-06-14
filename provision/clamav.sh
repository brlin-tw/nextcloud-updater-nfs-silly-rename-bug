#!/bin/bash
# Provision ClamAV on-access (realtime) scanning over the NFS-mounted /data on
# the Nextcloud VM. clamonacc uses fanotify to open every file that is accessed
# under OnAccessIncludePath; that open handle is exactly what makes the NFS
# client silly-rename files during the updater's move, breaking rmdir().
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
DATA_DIR=/data

echo "=== [clamav] installing packages ==="
apt-get update -y
apt-get install -y clamav clamav-daemon clamav-freshclam lsof

echo "=== [clamav] fetching signature database (first freshclam) ==="
systemctl stop clamav-freshclam || true
freshclam || true            # may warn if DB already current; non-fatal
systemctl enable --now clamav-freshclam

echo "=== [clamav] enabling on-access scanning in clamd.conf ==="
CONF=/etc/clamav/clamd.conf
# Remove any prior OnAccess lines so re-provisioning is idempotent.
sed -i '/^OnAccess/d' "$CONF"
cat >>"$CONF" <<CLAMD

# --- on-access (realtime) scanning for the NFS data dir ---
OnAccessIncludePath ${DATA_DIR}
OnAccessExtraScanning yes
OnAccessPrevention no
OnAccessMaxFileSize 50M
# clamonacc refuses to start without an exclusion for the scanner's own UID,
# otherwise clamd's own reads would generate new scan events (infinite loop).
# Exclude the clamav uname only — root-triggered accesses (the reproducer's
# readers and the updater-style move run via sudo) are still scanned.
OnAccessExcludeUname clamav
CLAMD

# clamd keeps running as the default 'clamav' user; clamonacc runs as root for
# fanotify and passes the open fd to clamd via --fdpass, so clamd needs no root.
sed -i 's#^LocalSocket .*#LocalSocket /run/clamav/clamd.ctl#' "$CONF" || true

echo "=== [clamav] restarting clamd ==="
systemctl enable clamav-daemon
systemctl restart clamav-daemon

# Wait for clamd socket to come up (DB load can take a while).
echo -n "[clamav] waiting for clamd socket"
for _ in $(seq 1 60); do
  [ -S /run/clamav/clamd.ctl ] && break
  echo -n "."; sleep 2
done
echo

echo "=== [clamav] installing clamonacc systemd service ==="
cat >/etc/systemd/system/clamonacc.service <<UNIT
[Unit]
Description=ClamAV on-access scanner (clamonacc) for ${DATA_DIR}
Requires=clamav-daemon.service
After=clamav-daemon.service data.mount

[Service]
Type=simple
# --fdpass hands the open fd to clamd; clamonacc keeps files open while
# scanning, which is what triggers the NFS silly-rename during the move.
ExecStart=/usr/sbin/clamonacc --foreground --log=/var/log/clamav/clamonacc.log --fdpass
Restart=on-failure

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now clamonacc.service || {
  echo "[clamav] clamonacc failed to start; check: journalctl -u clamonacc" >&2
}

echo "=== [clamav] status ==="
systemctl --no-pager --full status clamonacc.service || true
echo "[clamav] on-access scanning active over ${DATA_DIR}"
