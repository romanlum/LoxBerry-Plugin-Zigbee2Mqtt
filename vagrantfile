Vagrant.configure("2") do |config|
  config.vm.synced_folder ".", "/vagrant", disabled: true
  config.vm.provider :libvirt do |libvirt|
      libvirt.driver = "kvm"
      libvirt.uri = "qemu:///system"
  end
  config.vm.define "dietpi" do |node|
      node.vm.box = "romanlum/dietpi-bullseye"
      node.vm.provider :libvirt do |res|
         res.memory = 1024
         res.cpus = 1
      end
  end
end