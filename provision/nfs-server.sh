#!/bin/bash
# Provision the NFS server VM: export /export/data to the Nextcloud VM.
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y nfs-kernel-server

mkdir -p /export/data
# nobody:nogroup so the apache/www-data uid on the client can write freely
# for this reproducer; real deployments map proper uids.
chown nobody:nogroup /export/data
chmod 0777 /export/data

# Export to the Nextcloud VM's private-network IP. no_root_squash keeps the
# reproducer simple; sync/no_subtree_check are the usual safe defaults.
EXPORT_LINE="/export/data 192.168.56.20(rw,sync,no_subtree_check,no_root_squash)"
if ! grep -qF "$EXPORT_LINE" /etc/exports 2>/dev/null; then
  echo "$EXPORT_LINE" >> /etc/exports
fi

exportfs -ra
systemctl enable --now nfs-kernel-server
systemctl restart nfs-kernel-server

echo "[nfs-server] exports:"
exportfs -v
