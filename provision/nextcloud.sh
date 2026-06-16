#!/bin/bash
# Provision the Nextcloud VM: Apache + PHP + MariaDB + Nextcloud, with the
# data directory (and therefore the updater staging dir) on the NFS mount.
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
NFS_SERVER=192.168.56.10
NFS_EXPORT=/export/data
DATA_DIR=/data
NC_VERSION="33.0.5"           # any maintained release works; updater logic unchanged
NC_DIR=/var/www/html/nextcloud
DB_NAME=nextcloud
DB_USER=nextcloud
DB_PASS=nextcloud
NC_ADMIN=admin
NC_ADMIN_PASS=admin12345

echo "=== [nextcloud] installing packages ==="
apt-get update -y
apt-get install -y \
  nfs-common \
  apache2 \
  mariadb-server \
  libapache2-mod-php \
  php php-cli php-gd php-mysql php-curl php-mbstring php-intl \
  php-xml php-zip php-bcmath php-gmp php-imagick \
  unzip curl

echo "=== [nextcloud] mounting NFS export at $DATA_DIR ==="
mkdir -p "$DATA_DIR"
# Mount now and persist. vers=4.1 mirrors the production mount parameters.
if ! mountpoint -q "$DATA_DIR"; then
  mount -t nfs4 -o vers=4.1,rw,hard,proto=tcp,timeo=600 \
    "${NFS_SERVER}:${NFS_EXPORT}" "$DATA_DIR"
fi
FSTAB_LINE="${NFS_SERVER}:${NFS_EXPORT} ${DATA_DIR} nfs4 vers=4.1,rw,hard,proto=tcp,timeo=600,_netdev 0 0"
grep -qF "$FSTAB_LINE" /etc/fstab || echo "$FSTAB_LINE" >> /etc/fstab

echo "=== [nextcloud] configuring MariaDB ==="
systemctl enable --now mariadb
mysql <<SQL
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "=== [nextcloud] downloading Nextcloud ${NC_VERSION} ==="
if [ ! -d "$NC_DIR" ]; then
  cd /tmp
  curl -fSL -o nextcloud.zip \
    "https://download.nextcloud.com/server/releases/nextcloud-${NC_VERSION}.zip"
  unzip -q nextcloud.zip -d /var/www/html
  rm -f nextcloud.zip
fi
chown -R www-data:www-data "$NC_DIR"

echo "=== [nextcloud] data dir on NFS ($DATA_DIR/nextcloud-data) ==="
NC_DATA="$DATA_DIR/nextcloud-data"
mkdir -p "$NC_DATA"
chown www-data:www-data "$NC_DATA"
chmod 0770 "$NC_DATA"

echo "=== [nextcloud] occ maintenance:install ==="
if ! sudo -u www-data php "$NC_DIR/occ" status 2>/dev/null | grep -q 'installed: true'; then
  sudo -u www-data php "$NC_DIR/occ" maintenance:install \
    --database "mysql" \
    --database-name "$DB_NAME" \
    --database-user "$DB_USER" \
    --database-pass "$DB_PASS" \
    --admin-user "$NC_ADMIN" \
    --admin-pass "$NC_ADMIN_PASS" \
    --data-dir "$NC_DATA"
fi

# Allow access from the host/private network.
sudo -u www-data php "$NC_DIR/occ" config:system:set trusted_domains 1 --value="192.168.56.20"

echo "=== [nextcloud] Apache vhost ==="
cat >/etc/apache2/sites-available/nextcloud.conf <<APACHE
<VirtualHost *:80>
  DocumentRoot ${NC_DIR}
  <Directory ${NC_DIR}/>
    Require all granted
    AllowOverride All
    Options FollowSymLinks MultiViews
    <IfModule mod_dav.c>
      Dav off
    </IfModule>
  </Directory>
</VirtualHost>
APACHE
a2dissite 000-default >/dev/null 2>&1 || true
a2ensite nextcloud >/dev/null
a2enmod rewrite headers env dir mime >/dev/null

echo "=== [nextcloud] raising PHP memory_limit to 1G for the Apache (Nextcloud) SAPI ==="
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
cat >"/etc/php/${PHP_VER}/apache2/conf.d/90-nextcloud.ini" <<'PHPINI'
memory_limit = 1G
PHPINI

systemctl restart apache2

echo "=== [nextcloud] creating updater staging dir keyed by instanceid ==="
INSTANCE_ID=$(sudo -u www-data php "$NC_DIR/occ" config:system:get instanceid)
UPDATER_DIR="$NC_DATA/updater-${INSTANCE_ID}"
mkdir -p "$UPDATER_DIR"
chown www-data:www-data "$UPDATER_DIR"
chmod 0770 "$UPDATER_DIR"
echo "[nextcloud] created $UPDATER_DIR"

echo "=== [nextcloud] done. Updater staging dir will live under $UPDATER_DIR (NFS). ==="
