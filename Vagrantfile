# -*- mode: ruby -*-
# vi: set ft=ruby :
#
# Multi-VM reproducer for the ODFWEB / Nextcloud updater "Move new files in
# place" failure (rmdir: Directory not empty) that occurs when the data /
# updater staging directory lives on NFS and a local process (here: an
# on-access-scanner emulator) holds files open during the move, causing the
# NFS client to create .nfsXXXXXXXX silly-rename files.
#
# Topology:
#   nfs       192.168.56.10  - Ubuntu NFS server, exports /export/data
#   nextcloud 192.168.56.20  - Ubuntu + Apache/PHP/MariaDB + Nextcloud,
#                            mounts the export at /data, runs the on-access
#                            scanner emulator over /data during the move
#
# Usage:
#   vagrant up
#   vagrant ssh nextcloud -c 'sudo /vagrant/provision/run-repro.sh'

Vagrant.configure("2") do |config|
  config.vm.box = "bento/debian-13"
  config.vm.synced_folder ".", "/vagrant"

  config.vm.provider "virtualbox" do |vb|
    vb.memory = 2048
    vb.cpus = 2
  end

  config.vm.define "nfs" do |nfs|
    nfs.vm.hostname = "nfs"
    nfs.vm.network "private_network", ip: "192.168.56.10"
    nfs.vm.provision "shell", path: "provision/nfs-server.sh"
  end

  config.vm.define "nextcloud" do |nc|
    nc.vm.hostname = "nextcloud"
    nc.vm.network "private_network", ip: "192.168.56.20"
    nc.vm.provider "virtualbox" do |vb|
      vb.memory = 3072
      vb.cpus = 4
    end
    # NFS server must be up first so the mount in nextcloud.sh succeeds.
    nc.vm.provision "shell", path: "provision/nextcloud.sh"
  end
end
