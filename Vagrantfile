Vagrant.configure("2") do |config|

  config.ssh.insert_key = false
  config.vm.provider :libvirt do |libvirt|

    libvirt.driver = "kvm"
    libvirt.host = ""
    libvirt.connect_via_ssh = false
    libvirt.storage_pool_name = "default"

  end


end