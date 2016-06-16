# -*- mode: ruby -*-
# vi: set ft=ruby sw=2 ts=2 :

Vagrant.configure("2") do |config|
  config.vm.box = "hashicorp/precise64"

  config.vm.define "lightweight-php" do |vm|
    vm.vm.hostname = "lightweight-php"
    vm.vm.network "private_network", ip: "192.168.23.23"
  end

  config.vm.provider "virtualbox" do |v|
    v.memory = 768
  end

  config.vm.provision "ansible" do |ansible|
    ansible.playbook = "provision.yml"
    ansible.extra_vars = { ansible_ssh_user: "vagrant", ansible_become: "yes" }
  end
end
