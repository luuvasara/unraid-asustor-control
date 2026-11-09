<?php
/* acs.php - shared by the pages and endpoints of asustor-control. READ-ONLY here: the bays come from sysfs and Unraid's state
 * files through the plugin kit (kit/EnclosureView.php, profile kit/enclosures/asustor-as6706t.json); nothing is run and no
 * drive is opened. The bay order is the one in leds.cfg (bay_ports, the LEDs and Buttons tab), stored for the kit in
 * /boot/config/plugins/asustor-control/enclosures.json by acs_save_bay_order(). */
require_once dirname(__DIR__) . '/kit/EnclosureView.php';

const ACS = 'asustor-control';
const ACS_DEFAULTS = ['MAIN_BADGES' => 'yes'];

function acs_root(): string { return rtrim((string)(getenv('DRIVEMAP_ROOT') ?: ''), '/'); }
function acs_cfgdir(): string { return acs_root() . '/boot/config/plugins/' . ACS; }
function acs_state(): string { return acs_root() . '/var/local/emhttp/plugins/' . ACS; }
function acs_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* the plugin's cfg, whitelisted */
function acs_cfg(): array {
  $c = @parse_ini_file(acs_cfgdir() . '/' . ACS . '.cfg') ?: [];
  $out = ACS_DEFAULTS;
  if (in_array($c['MAIN_BADGES'] ?? '', ['yes', 'no'], true)) $out['MAIN_BADGES'] = $c['MAIN_BADGES'];
  return $out;
}

/* the AS6706T's bays (the kit's view), with the card facts; [] units on any other server. Cached for this request. */
function acs_view(): array {
  static $v = null;
  if ($v !== null) return $v;
  $v = enclosure_view(['cabling_file' => acs_cfgdir() . '/enclosures.json']);
  $v['units'] = array_values(array_filter($v['units'], function ($u) { return $u['profile'] === 'asustor-as6706t'; }));
  foreach ($v['units'] as &$u) $u['hw'] = enclosure_hw($u, $u['bays'], acs_root());
  unset($u);
  return $v;
}

/* drives Unraid lists that are in no bay: the M.2 slots inside the unit, the flash drive, USB drives.
 * [['device' => 'nvme0n1', 'slot' => 'cache', 'temp' => 38|null, 'standby' => bool, 'state_text' => ...], ...] */
function acs_other_drives(array $view): array {
  $inBay = [];
  foreach ($view['units'] as $u) foreach ($u['bays'] as $b) if ($b['device'] !== '') $inBay[$b['device']] = true;
  $out = [];
  foreach (DriveState::all(['root' => acs_root()]) as $dev => $s) {
    if (isset($inBay[$dev])) continue;
    $out[] = ['device' => $dev, 'slot' => enclosure_slot_label((string)$s['slot']), 'temp' => $s['temp'], 'standby' => $s['standby'], 'state_text' => $s['state_text'],
              'where' => strpos($dev, 'nvme') === 0 ? 'M.2 slot (inside)' : ((string)$s['slot'] === 'flash' ? 'USB flash (boot)' : 'not in a bay')];
  }
  return $out;
}

/* leds.cfg (bay_ports, bay_order_confirmed); the defaults of include/ajax.php */
function acs_led_cfg(): array {
  $cfg = ['bay_ports' => 'auto', 'bay_order_confirmed' => 'no'];
  foreach (@file(acs_cfgdir() . '/leds.cfg', FILE_IGNORE_NEW_LINES) ?: [] as $l)
    if (preg_match('/^(bay_ports|bay_order_confirmed)=([^#]*)/', $l, $m)) $cfg[$m[1]] = trim($m[2], " \"");
  return $cfg;
}

/* The bay order of the LEDs and Buttons tab, for the kit: bay_ports lists ATA port numbers (ataN) in bay order; the kit wants,
 * for the ASM1166's port_no 1..6, the bay of each. auto = port order. Returns [positions] or null when bay_ports names a port
 * that is not on the AS6706T's controller. */
function acs_port_order(string $bayPorts): ?array {
  if ($bayPorts === '' || $bayPorts === 'auto') return [1, 2, 3, 4, 5, 6];
  $ata = explode(',', $bayPorts);
  if (count($ata) !== 6) return null;
  $pos = [];
  foreach ($ata as $bay => $a) {
    if (!ctype_digit($a)) return null;
    $real = (string)@realpath(acs_root() . "/sys/class/ata_port/ata$a/device");
    $no = trim((string)@file_get_contents(acs_root() . "/sys/class/ata_port/ata$a/port_no"));
    if (!ctype_digit($no) || (int)$no < 1 || (int)$no > 6 || !preg_match('#/([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7])/ata' . $a . '$#', $real, $m)) return null;
    $id = strtolower(trim((string)@file_get_contents(acs_root() . "/sys/bus/pci/devices/{$m[1]}/vendor")) . ':' . trim((string)@file_get_contents(acs_root() . "/sys/bus/pci/devices/{$m[1]}/device")));
    if ($id !== '0x1b21:0x1166') return null;
    $pos[(int)$no - 1] = $bay + 1;
  }
  ksort($pos);
  return count($pos) === 6 && array_keys($pos) === range(0, 5) ? array_values($pos) : null;
}

/* store the bay order for the kit (drawing, Main page labels): {"none": {"groups": [1], "ports": [...], "confirmed": bool}};
 * the entry is removed when the order is the default and not confirmed. ['ok' => bool, 'note' => text] */
function acs_save_bay_order(string $bayPorts, bool $confirmed): array {
  $file = acs_cfgdir() . '/enclosures.json';
  $all = DriveEnclosures::readCabling($file);
  $ports = acs_port_order($bayPorts);
  if ($ports === null) { unset($all['none']); $note = 'The bay order names a port that is not on the AS6706T\'s SATA controller: the drawing keeps the default order.'; }
  elseif ($ports === [1, 2, 3, 4, 5, 6] && !$confirmed) { unset($all['none']); $note = ''; }
  else { $all['none'] = ['groups' => [1], 'ports' => $ports, 'confirmed' => $confirmed]; $note = ''; }
  @mkdir(dirname($file), 0755, true);
  if (!$all) { @unlink($file); return ['ok' => true, 'note' => $note]; }
  if (@file_put_contents("$file.new", json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false || !@rename("$file.new", $file))
    return ['ok' => false, 'note' => 'The bay order could not be saved for the drawing.'];
  return ['ok' => true, 'note' => $note];
}

/* SMART summary of a slot from Unraid's last report (/var/local/emhttp/smart/<slot>); [] when there is none */
function acs_smart_attrs(string $slot): array {
  if (!preg_match('/^[A-Za-z0-9_~-]{1,32}$/', $slot)) return [];
  $s = @file_get_contents(acs_root() . "/var/local/emhttp/smart/$slot");
  if ($s === false) return [];
  $want = ['9' => 'hours', '5' => 'realloc', '197' => 'pending', '199' => 'crc'];
  $o = [];
  foreach (explode("\n", $s) as $l) {
    $c = preg_split('/\s+/', trim($l));
    if (count($c) >= 10 && isset($want[$c[0]]) && !isset($o[$want[$c[0]]]) && preg_match('/^\d+/', $c[9], $m)) $o[$want[$c[0]]] = (int)$m[0];
  }
  return $o;
}

/* the page head: kit and plugin styles, the shared script; once per page (every tab renders into one DOM) */
function acs_head(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  $d = dirname(__DIR__);
  $v = function ($f) use ($d) { return substr((string)@md5_file("$d/$f"), 0, 10); };
  echo '<link rel="stylesheet" href="/plugins/asustor-control/kit/enclosures.css?v=', $v('kit/enclosures.css'), '">',
       '<link rel="stylesheet" href="/plugins/asustor-control/assets/style.css?v=', $v('assets/style.css'), '">',
       '<script src="/plugins/asustor-control/js/asustor-control.js?v=', $v('js/asustor-control.js'), '"></script>';
}
