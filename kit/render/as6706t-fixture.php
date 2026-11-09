<?php
/* as6706t-fixture.php <dir>: a made-up Asustor AS6706T (Lockerstor 6 Gen2) running Unraid, for DRIVEMAP_ROOT=<dir> (use an
 * empty dir). Facts it follows: the board's DMI names and the ASMedia ASM1166 SATA controller [1b21:1166], by which the
 * asustor-platform-driver tells an AS6706T apart; the six bays on ata1..ata6 of the ASM1166 (as Unraid numbers them on a unit:
 * sdb..sdg, the USB flash drive is sda); two NVMe drives in M.2 slots as the pool cache/cache2; the slots and temperatures of a
 * behaviour-log row of the plugin. Invented: every PCI address, the links, the models, serials and sizes, and which slot sits
 * in which bay. Also Unraid's state files (disks.ini with array and pool slots, smart/), so the drawing has states. */
$W = $argv[1] ?? ($AS6706T_FIXTURE ?? '');
if ($W === '') { fwrite(STDERR, "usage: as6706t-fixture.php DIR\n"); exit(2); }
if (!function_exists('asfx_put')) {
function asfx_put(string $f, string $v): void { @mkdir(dirname($f), 0755, true); file_put_contents($f, $v); }
function asfx_pci(string $W, string $path, string $id, string $sub, array $link): void {
  [$v, $d] = explode(':', $id); [$sv, $sd] = explode(':', $sub);
  asfx_put("$W$path/vendor", "0x$v\n"); asfx_put("$W$path/device", "0x$d\n");
  asfx_put("$W$path/subsystem_vendor", "0x$sv\n"); asfx_put("$W$path/subsystem_device", "0x$sd\n");
  asfx_put("$W$path/current_link_speed", "{$link[0]} GT/s PCIe\n"); asfx_put("$W$path/current_link_width", "{$link[1]}\n");
  asfx_put("$W$path/max_link_speed", "{$link[2]} GT/s PCIe\n"); asfx_put("$W$path/max_link_width", "{$link[3]}\n");
  @mkdir("$W/sys/bus/pci/devices", 0755, true);
  $l = "$W/sys/bus/pci/devices/" . basename($path);
  if (!is_link($l)) symlink('../../..' . substr($path, 4), $l);
}
}
$AS6706T_DMI = $AS6706T_DMI ?? ['sys_vendor' => 'Intel Corporation', 'product_name' => 'Jasper Lake Client Platform', 'board_vendor' => 'Intel Corporation',
  'board_name' => 'Jasper Lake Client Platform', 'bios_vendor' => 'Phoenix Technologies Ltd.', 'bios_version' => 'V1.21', 'bios_date' => '11/23/2022'];
foreach ($AS6706T_DMI as $k => $v) asfx_put("$W/sys/class/dmi/id/$k", "$v\n");
$rp = '/sys/devices/pci0000:00/0000:00:1c.0';
$ctl = "$rp/0000:01:00.0";
asfx_pci($W, $rp, '8086:4dbc', '8086:7270', ['8.0', 2, '8.0', 2]);
asfx_pci($W, $ctl, '1b21:1166', '1b21:2116', ['8.0', 2, '8.0', 2]);
@mkdir("$W/sys/bus/pci/drivers/ahci", 0755, true);
if (!is_link("$W$ctl/driver")) symlink(str_repeat('../', substr_count($ctl, '/')) . 'sys/bus/pci/drivers/ahci', "$W$ctl/driver");
foreach (['aer_dev_correctable', 'aer_dev_nonfatal', 'aer_dev_fatal'] as $f) asfx_put("$W$ctl/$f", "TOTAL_ERR_COR 0\n");
/* bay order = port order (the plugin's default); slot, kernel name, model, sectors, temperature (null: standby) */
$T8 = 15628053168; $T4 = 7814037168;
$AS6706T_DISKS = $AS6706T_DISKS ?? [
  1 => ['parity', 'sdb', 'WDC WD80EFZZ-68BTXN0', $T8, 40], 2 => ['disk1', 'sdc', 'WDC WD40EFRX-68N32N0', $T4, 39],
  3 => ['disk2', 'sdd', 'WDC WD40EFRX-68N32N0', $T4, 41], 4 => ['disk3', 'sde', 'ST4000VN008-2DR166', $T4, null],
  5 => ['disk4', 'sdf', 'ST4000VN008-2DR166', $T4, 43], 6 => ['parity2', 'sdg', 'WDC WD80EFZZ-68BTXN0', $T8, 39],
];
$ini = ''; $i = 0;
foreach ($AS6706T_DISKS as $port => [$slot, $k, $model, $sectors, $temp]) {
  $a = $port;
  $serial = sprintf('AS6%02d%s', $port, substr(md5($k), 0, 5));
  $real = "$ctl/ata$a/host" . ($a - 1) . "/target" . ($a - 1) . ":0:0/" . ($a - 1) . ":0:0:0/block/$k";
  @mkdir("$W$real", 0755, true); @mkdir("$W/sys/block", 0755, true);
  if (!is_link("$W/sys/block/$k")) symlink('..' . substr($real, 4), "$W/sys/block/$k");
  if (!is_link("$W$real/device")) symlink('../..', "$W$real/device");
  asfx_put("$W/sys/block/$k/size", "$sectors\n"); asfx_put("$W/sys/block/$k/queue/rotational", "1\n");
  asfx_put("$W/sys/block/$k/device/model", "$model\n"); asfx_put("$W/sys/block/$k/device/serial", "$serial\n"); asfx_put("$W/sys/block/$k/device/rev", "0A81\n");
  asfx_put("$W/sys/block/$k/{$k}1/partition", "1\n");
  asfx_put("$W/sys/class/ata_port/ata$a/port_no", "$port\n");
  if (!is_link("$W/sys/class/ata_port/ata$a/device")) symlink("$W$ctl/ata$a", "$W/sys/class/ata_port/ata$a/device");
  asfx_put("$W/sys/class/ata_link/link$a/sata_spd", "6.0 Gbps\n"); asfx_put("$W/sys/class/ata_link/link$a/hw_sata_spd_limit", "6.0 Gbps\n");
  $h = $a - 1; $sh = "$ctl/ata$a/host$h/scsi_host/host$h";
  asfx_put("$W$sh/proc_name", "ahci\n"); asfx_put("$W$sh/ahci_host_caps", "c734ff2f\n");
  @mkdir("$W/sys/class/scsi_host", 0755, true);
  if (!is_link("$W/sys/class/scsi_host/host$h")) symlink('../..' . substr($sh, 4), "$W/sys/class/scsi_host/host$h");
  @mkdir("$W/dev/disk/by-id", 0755, true); asfx_put("$W/dev/$k", '');
  @symlink("../../$k", "$W/dev/disk/by-id/ata-" . str_replace(' ', '_', $model) . "_$serial");
  $i++;
  $color = $temp === null ? 'green-blink' : 'green-on';
  $ini .= "[\"$slot\"]\nname=\"$slot\"\ndevice=\"$k\"\nid=\"" . str_replace(' ', '_', $model) . "_$serial\"\nstatus=\"DISK_OK\"\ncolor=\"$color\"\ntemp=\"" . ($temp ?? '*') . "\"\nspundown=\"" . ($temp === null ? 1 : 0) . "\"\nnumErrors=\"0\"\n";
  asfx_put("$W/var/local/emhttp/smart/$slot", "ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE\n"
    . "  5 Reallocated_Sector_Ct   0x0033   200   200   140    Pre-fail  Always       -       0\n"
    . "  9 Power_On_Hours          0x0032   061   061   000    Old_age   Always       -       " . (21000 + 911 * $port) . "\n"
    . "194 Temperature_Celsius     0x0022   114   109   000    Old_age   Always       -       " . ($temp ?? 36) . "\n"
    . "197 Current_Pending_Sector  0x0032   200   200   000    Old_age   Always       -       0\n"
    . "199 UDMA_CRC_Error_Count    0x0032   200   200   000    Old_age   Always       -       0\n");
}
/* two NVMe drives in M.2 slots (pool cache, cache2), behind an invented PCIe switch port */
foreach ([['nvme0n1', 'cache', 38, '0000:04:00.0'], ['nvme1n1', 'cache2', 43, '0000:05:00.0']] as $j => [$k, $slot, $temp, $addr]) {
  $p = "/sys/devices/pci0000:00/0000:00:1c.2/0000:02:00.0/0000:03:0" . ($j + 1) . ".0/$addr";
  asfx_pci($W, $p, '144d:a80a', '144d:a801', ['8.0', 1, '8.0', 1]);
  $real = "$p/nvme/nvme$j/$k";
  @mkdir("$W$real", 0755, true);
  if (!is_link("$W/sys/block/$k")) symlink('..' . substr($real, 4), "$W/sys/block/$k");
  asfx_put("$W/sys/block/$k/size", "1953525168\n"); asfx_put("$W/sys/block/$k/queue/rotational", "0\n");
  asfx_put("$W/sys/block/$k/device/model", "Samsung SSD 980 PRO 1TB\n"); asfx_put("$W/sys/block/$k/device/serial", sprintf("S5NV%02dEXAMPLE\n", $j));
  asfx_put("$W/dev/$k", '');
  $ini .= "[\"$slot\"]\nname=\"$slot\"\ndevice=\"$k\"\nid=\"Samsung_SSD_980_PRO_1TB_S5NV0{$j}EXAMPLE\"\nstatus=\"DISK_OK\"\ncolor=\"green-on\"\ntemp=\"$temp\"\nspundown=\"0\"\nnumErrors=\"0\"\n";
}
$ini .= "[\"flash\"]\nname=\"flash\"\ndevice=\"sda\"\nid=\"Example_Flash\"\nstatus=\"DISK_OK\"\ncolor=\"green-on\"\ntemp=\"*\"\nspundown=\"0\"\nnumErrors=\"0\"\n";
asfx_put("$W/var/local/emhttp/disks.ini", $ini);
