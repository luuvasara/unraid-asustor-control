<?php
/* EnclosureView: the data of the Enclosures tab, joined from the kit's libraries. Read-only.
 *
 *   $v = enclosure_view(['cabling_file' => '/boot/config/plugins/<name>/enclosures.json']);   // + 'root', 'dir' for tests
 *   $v['units'][0] = unit from DriveEnclosures::units() + 'bays' => [bay row, ...] (front order) + 'rows' => front grid of bay
 *                    rows + 'links' => DriveEnclosures::links()
 *   bay row: ['bay' => 'HDD3', 'kind' => '3.5in' | '', 'device' => 'sdk' | '', 'slot' => 'Disk 3' | '', 'slot_id' => 'disk3', 'model', 'size' (bytes),
 *             'temp' => 36 | null, 'standby' => bool, 'state' => 'active' | 'standby' | ... | 'empty', 'state_text', 'smart' => [...],
 *             'label' => DriveEnclosures label]
 *   $v['candidates'], $v['map'] (kernel name => ['bay', 'unit', 'label'] for the Main page labels)
 * PHP 7.4 and newer. */
require_once __DIR__ . '/DriveMap.php';
require_once __DIR__ . '/Enclosure.php';
require_once __DIR__ . '/DriveState.php';

if (!function_exists('enclosure_view')) {

function enclosure_view(array $opt = []): array {
  $root = (string)($opt['root'] ?? (getenv('DRIVEMAP_ROOT') ?: ''));
  $map = $opt['map'] ?? ($root === '' ? DriveMap::shared() : new DriveMap(['root' => $root, 'cache' => null]));
  $file = (string)($opt['cabling_file'] ?? '');
  $enc = new DriveEnclosures(['root' => $root, 'cabling' => $file !== '' ? enclosure_cabling($file) : []] + (isset($opt['dir']) ? ['dir' => $opt['dir']] : []));
  $drives = $map->drives();
  $byKey = [];
  foreach ($drives as $d) $byKey[$d['key']] = $d;
  $places = $enc->place($drives);
  $state = DriveState::all(['root' => $root]);
  $units = [];
  $short = [];
  foreach ($enc->units($places) as $u) {
    $kinds = $u['kinds'];
    $kindOf = function (string $bay) use ($kinds): string { foreach ($kinds as $pre => $k) if (strpos($bay, (string)$pre) === 0) return (string)$k; return ''; };
    $u['rows'] = [];
    $u['bays'] = [];
    foreach ($u['front'] as $row) {
      $r = [];
      foreach ($row as $c) {
        $d = $c['key'] !== null ? ($byKey[$c['key']] ?? null) : null;
        $s = $d ? ($state[$d['device']] ?? null) : null;
        $b = ['bay' => $c['bay'], 'kind' => $kindOf($c['bay']), 'device' => $d['device'] ?? '', 'slot' => enclosure_slot_label((string)($s['slot'] ?? ($d['slot'] ?? ''))), 'slot_id' => (string)($s['slot'] ?? ($d['slot'] ?? '')),
              'model' => $d['model'] ?? '', 'size' => (int)($d['size'] ?? 0), 'temp' => $s['temp'] ?? null, 'standby' => (bool)($s['standby'] ?? false),
              'state' => $d ? ($s['state'] ?? 'unknown') : 'empty', 'state_text' => $d ? ($s['state_text'] ?? 'Unraid lists no state for this drive') : 'No drive in this bay',
              'smart' => $s['smart'] ?? ['state' => 'unknown', 'text' => $d ? 'unknown' : '', 'title' => ''], 'label' => $d ? ($places[$d['key']]['label'] ?? '') : ''];
        $r[] = $b; $u['bays'][] = $b;
        if ($d) $short[$d['device']] = ['bay' => $c['bay'], 'unit' => $u['switch'] === 'none' ? $u['name'] : $u['name'] . ' ' . $u['unit'], 'label' => $b['label'], 'confirmed' => $u['confirmed']];
      }
      $u['rows'][] = $r;
    }
    $u['unplaced_rows'] = array_values(array_filter(array_map(function ($k) use ($byKey, $places) {
      return isset($byKey[$k]) ? ['device' => $byKey[$k]['device'], 'model' => $byKey[$k]['model'], 'label' => $places[$k]['label'] ?? ''] : null;
    }, $u['unplaced'])));
    $u['links'] = array_map(function ($l) use ($u) {
      $g = $l['controller'] !== null && is_array($u['cabling']) ? array_values((array)($u['groups'][(string)($u['cabling'][$l['controller'] - 1] ?? '')] ?? [])) : [];
      return $l + ['name' => $l['what'] === 'card' ? 'Card to slot' : "Controller {$l['controller']}" . ($g ? " ({$g[0]}-" . end($g) . ')' : '')];
    }, $enc->links($u));
    $units[] = $u;
  }
  return ['units' => $units, 'candidates' => $enc->candidates(), 'map' => $short, 'profiles' => $enc->profiles()];
}

define('ENCLOSURE_ICON', ['active' => 'fa-circle green-text', 'new' => 'fa-square', 'standby' => 'fa-circle grey-text', 'invalid' => 'fa-warning orange-text',
  'disabled' => 'fa-times red-text', 'missing' => 'fa-times red-text', 'unknown' => 'fa-question-circle', 'empty' => '']);

/* The hardware facts of a unit's card, all from sysfs and read-only: per controller its driver, PCIe error counters (AER),
 * whether it offers AHCI enclosure management (CAP bit 6, EMS: LED messages over SGPIO) or port multipliers (CAP bit 17),
 * and its SATA ports that have a link or a drive. $root: a captured tree or ''. */
function enclosure_hw(array $u, array $bays, string $root = ''): array {
  $r = rtrim($root, '/');
  $rd = function ($p) use ($r) { $v = @file_get_contents($r . $p); return $v === false ? '' : trim($v); };
  $aer = function ($a) use ($rd) {
    $o = [];
    foreach (['correctable', 'nonfatal', 'fatal'] as $k) { $t = $rd("/sys/bus/pci/devices/$a/aer_dev_$k"); $o[$k] = preg_match('/^TOTAL_\S+\s+(\d+)/m', $t, $m) ? (int)$m[1] : null; }
    return $o;
  };
  $byDev = [];
  foreach ($bays as $b) if ($b['device'] !== '') $byDev[$b['device']] = $b['bay'];
  $ports = [];
  foreach (glob("$r/sys/class/ata_port/ata*") ?: [] as $ap) {
    $n = substr(basename($ap), 3);
    $real = (string)@realpath("$ap/device");
    if ($real === '') foreach ($u['controllers'] as $c) foreach (glob("$r/sys/devices/pci*/*/*/*/$c/ata$n") ?: [] as $x) $real = $x;   // a captured tree without the link
    foreach ($u['controllers'] as $c) if (strpos($real . '/', "/$c/") !== false) $ports[$c][] = $n;
  }
  $devOfAta = [];
  foreach (glob("$r/sys/block/*") ?: [] as $bl) if (preg_match('#/ata(\d+)/#', (string)@realpath($bl), $m)) $devOfAta[$m[1]] = basename($bl);
  $ctl = [];
  foreach ($u['controllers'] as $i => $c) {
    $caps = '';
    $list = [];
    foreach ($ports[$c] ?? [] as $n) {
      if ($caps === '') $caps = $rd("/sys/class/scsi_host/host$n/ahci_host_caps");
      $spd = $rd("/sys/class/ata_link/link$n/sata_spd");
      $dev = $devOfAta[$n] ?? '';
      if ($dev === '' && ($spd === '' || $spd === '<unknown>')) continue;
      $list[] = ['ata' => "ata$n", 'port_no' => (int)$rd("/sys/class/ata_port/ata$n/port_no"), 'speed' => $spd, 'limit' => $rd("/sys/class/ata_link/link$n/hw_sata_spd_limit"),
                 'device' => $dev, 'bay' => $byDev[$dev] ?? '', 'rev' => $dev !== '' ? $rd("/sys/block/$dev/device/rev") : ''];
    }
    usort($list, function ($a, $b) { return $a['port_no'] <=> $b['port_no']; });
    $capv = ctype_xdigit($caps) ? hexdec($caps) : null;
    $drv = @readlink("$r/sys/bus/pci/devices/$c/driver");
    $ctl[] = ['n' => $i + 1, 'address' => $c, 'driver' => $drv ? basename($drv) : '', 'aer' => $aer($c), 'caps' => $caps,
              'ems' => $capv === null ? null : (bool)($capv & 0x40), 'pmp' => $capv === null ? null : (bool)($capv & 0x20000), 'ports' => $list];
  }
  return ['controllers' => $ctl, 'switch_aer' => $aer($u['switch']), 'ses' => (bool)glob("$r/sys/class/enclosure/*")];
}

/* "36 °C", "standby", "no reading", '' for an empty bay */
function enclosure_temp_text(array $b): string {
  return $b['temp'] !== null ? $b['temp'] . ' °C' : ($b['standby'] ? 'standby' : ($b['device'] !== '' ? 'no reading' : ''));
}

/* the full text of a tray, for its title and for screen readers */
function enclosure_bay_text(array $b): string {
  if ($b['device'] === '') return "{$b['bay']}: empty";
  return "{$b['bay']}: {$b['device']}" . ($b['slot'] !== '' ? ", {$b['slot']}" : '') . ', ' . enclosure_temp_text($b) . ", {$b['state_text']}"
    . ($b['smart']['text'] !== '' ? ", SMART {$b['smart']['text']}" : '');
}

/* the front view of one unit: a drawing of the unit's front and rear when its profile has a panel (enclosure_panel_html),
 * else the profile's rows of bays as a plain grid. Each tray holds its drive; updated live by kit/enclosures.js
 * (data-enc-unit, data-enc-bay) from the endpoint's action=view. */
function enclosure_front_html(array $u): string {
  if (enclosure_panel_ok($u)) return enclosure_panel_html($u);
  $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
  $uid = $h("{$u['profile']}-{$u['unit']}");
  $kinds = array_unique(array_filter(array_column($u['bays'], 'kind')));
  $cap = 'Front view, as the bays are labelled on the unit' . (count($kinds) > 1 ? ' (' . implode(', ', array_map(function ($p, $k) { return "$p bays $k"; }, array_keys($u['kinds']), array_values($u['kinds']))) . ')' : '');
  $o = "<figure class=\"enc-front\" aria-labelledby=\"enc-cap-$uid\" data-enc-unit=\"" . $h($u['switch']) . "\">\n<figcaption id=\"enc-cap-$uid\" class=\"enc-cap\">" . $h($cap) . "</figcaption>\n<div class=\"enc-chassis\">\n";
  foreach ($u['rows'] as $row) {
    $o .= '<ul class="enc-row" style="--enc-cols: ' . count($row) . "\">\n";
    foreach ($row as $b) {
      $t = $h(enclosure_bay_text($b));
      $o .= '<li class="enc-bay enc-' . $h($b['state']) . '" tabindex="0" title="' . $t . '" aria-label="' . $t . '" data-enc-bay="' . $h($b['bay']) . '" data-enc-dev="' . $h($b['device']) . "\">"
          . '<span class="enc-bay-name">' . $h($b['bay']) . '</span>';
      if ($b['device'] !== '') $o .= '<span class="enc-bay-dev"><i class="fa ' . (ENCLOSURE_ICON[$b['state']] ?? '') . '" aria-hidden="true"></i> ' . $h($b['device']) . '</span>'
          . '<span class="enc-bay-slot">' . $h($b['slot'] !== '' ? $b['slot'] : '-') . '</span><span class="enc-bay-temp">' . $h(enclosure_temp_text($b)) . '</span>';
      else $o .= '<span class="enc-bay-empty">empty</span>';
      $o .= "</li>\n";
    }
    $o .= "</ul>\n";
  }
  return $o . "</div>\n</figure>\n";
}


/* "40°", "stby", "?", '' (empty bay): the short temperature in a tray of the drawing */
function enclosure_temp_short(array $b): string {
  return $b['temp'] !== null ? $b['temp'] . '°' : ($b['standby'] ? 'stby' : ($b['device'] !== '' ? '?' : ''));
}

/* the profile's panel places every bay of the unit on the front */
function enclosure_panel_ok(array $u): bool {
  $placed = [];
  foreach ((array)($u['panel']['front']['trays'] ?? []) as $g) foreach ((array)($g['bays'] ?? []) as $b) $placed[(string)$b] = true;
  if (!$placed || empty($u['panel']['front']['size']) || empty($u['panel']['rear']['size'])) return false;
  foreach ($u['bays'] as $b) if (!isset($placed[$b['bay']])) return false;
  return true;
}

function enclosure_svg_n($v): string { return rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.'); }
function enclosure_svg_text($x, $y, string $t, string $cls, string $anchor = 'middle'): string {
  return '<text x="' . enclosure_svg_n($x) . '" y="' . enclosure_svg_n($y) . '" class="' . $cls . '" text-anchor="' . $anchor . '">' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</text>';
}
function enclosure_svg_rect(array $b, string $cls, float $rx = 2, string $title = ''): string {
  return '<rect x="' . enclosure_svg_n($b[0]) . '" y="' . enclosure_svg_n($b[1]) . '" width="' . enclosure_svg_n($b[2]) . '" height="' . enclosure_svg_n($b[3]) . '" rx="' . enclosure_svg_n($rx) . '" class="' . $cls . '">'
    . ($title !== '' ? '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></rect>' : '</rect>');
}
/* a slot name cut to what a tray can hold; the full name is in the tray's title */
function enclosure_svg_fit(string $s, int $max): string {
  return function_exists('mb_strlen') && mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : (strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s);
}

/* one tray of the drawing, upright as on the unit: bay, temperature, device, slot, a bar in the colour of Unraid's state,
 * a warning mark for a SMART error, the lock and the handle. The same children for an empty bay (dashed, "empty"), so the
 * live refresh only changes texts and classes. */
function enclosure_svg_tray(array $b, float $x, float $y, float $w, float $h): string {
  $s = $w < 40;                                      // a 2.5in tray
  $t = htmlspecialchars(enclosure_bay_text($b), ENT_QUOTES, 'UTF-8');
  $cx = $x + $w / 2;
  $bad = in_array($b['smart']['state'], ['error', 'failed'], true);
  $f = $s ? [13, 31, 44, 56, 62, 3, 74, 7, 4.5, 30, 16, 9] : [16, 40, 57, 72, 80, 4, 96, 9, 6, 56, 28, 14];
  [$yBay, $yTemp, $yDev, $ySlot, $yBar, $hBar, $yWarn, $rWarn, $rLock, $yLock, $yHandle, $hHandle] = $f;
  $warn = 'M' . enclosure_svg_n($cx) . ',' . enclosure_svg_n($y + $yWarn - $rWarn) . ' l' . enclosure_svg_n($rWarn) . ',' . enclosure_svg_n($rWarn * 1.7) . ' h-' . enclosure_svg_n(2 * $rWarn) . ' z';
  return '<g class="enc-bay enc-' . htmlspecialchars($b['state'], ENT_QUOTES, 'UTF-8') . ($s ? ' enc-tray-s' : '') . ($bad ? ' enc-smart-bad' : '') . '" tabindex="0" role="img" aria-label="' . $t . '" data-enc-bay="'
    . htmlspecialchars($b['bay'], ENT_QUOTES, 'UTF-8') . '" data-enc-dev="' . htmlspecialchars($b['device'], ENT_QUOTES, 'UTF-8') . '"><title>' . $t . '</title>'
    . enclosure_svg_rect([$x, $y, $w, $h], 'enc-tray', 3)
    . enclosure_svg_text($cx, $y + $yBay, $b['bay'], 'enc-t-bay')
    . enclosure_svg_text($cx, $y + $yTemp, enclosure_temp_short($b), 'enc-t-temp')
    . enclosure_svg_text($cx, $y + $yDev, $b['device'] !== '' ? $b['device'] : 'empty', 'enc-t-dev')
    . enclosure_svg_text($cx, $y + $ySlot, enclosure_svg_fit($b['device'] !== '' ? ($b['slot'] !== '' ? $b['slot'] : '-') : '', $s ? 6 : 8), 'enc-t-slot')
    . enclosure_svg_rect([$x + ($s ? 4 : 6), $y + $yBar, $w - ($s ? 8 : 12), $hBar], 'enc-bar', $hBar / 2)
    . '<path d="' . $warn . '" class="enc-warn"/>'
    . '<circle cx="' . enclosure_svg_n($cx) . '" cy="' . enclosure_svg_n($y + $h - $yLock) . '" r="' . enclosure_svg_n($rLock) . '" class="enc-lock"/>'
    . enclosure_svg_rect([$x + 3, $y + $h - $yHandle, $w - 6, $hHandle], 'enc-handle', 2)
    . '</g>';
}

/* one part of the drawing, front or rear, from the profile's panel. Types: cage, strip, lcd, buttons, leds, side (front of the
 * TL-D1600S); recess, grille, switch, lock, sff, fan, psu, buzzer, inlet (its rear); port (any socket or button: box, label,
 * "label_at": left | right | above | below, title). Every text a profile gives ("title", "titles", "note") replaces the
 * default, which describes a part the server cannot reach. $ctx: the unit, its bays by name, the escaper. */
function enclosure_svg_part(array $p, array $ctx): string {
  $h = $ctx['h']; $u = $ctx['u']; $byBay = $ctx['byBay'];
  $bx = (array)($p['box'] ?? []);
  $blade = 'M0,-3 C6,-20 19,-15 13,-3 Z';
  switch ($p['type'] ?? '') {
    case 'cage': return enclosure_svg_rect($bx, 'enc-cage', 4);
    case 'strip': return enclosure_svg_rect($bx, 'enc-strip', 2, (string)($p['title'] ?? 'Drive LEDs: one per bay, the arrow points to its tray. Set by the enclosure (green: ready, flashing: activity, red: error); the server cannot read them.'));
    case 'lcd': return enclosure_svg_rect($bx, 'enc-lcd', 3, (string)($p['title'] ?? 'LCD panel')) . enclosure_svg_text($bx[0] + $bx[2] / 2, $bx[1] + $bx[3] / 2 + 4, 'LCD', 'enc-t-small');
    case 'buttons':
      $n = max(1, (int)($p['rows'] ?? 2)); $c = max(1, (int)($p['cols'] ?? 1)); $o = '<g><title>' . $h($p['title'] ?? 'Buttons') . '</title>';
      $bw = ($bx[2] - ($c - 1) * 2) / $c; $bh = $bx[3] / $n;
      for ($j = 0; $j < $c; $j++) for ($i = 0; $i < $n; $i++) {
        $y = $i === 0 ? $bx[1] : $bx[1] + $i * $bh + 1;
        $o .= enclosure_svg_rect([$bx[0] + $j * ($bw + 2), $y, $bw, $bh - 1], 'enc-button', 2);
      }
      return $o . '</g>';
    case 'leds':
      $o = '';
      foreach ((array)$p['x'] as $i => $lx) $o .= '<g><title>' . $h(($p['titles'][$i] ?? '') . ' ' . ($p['note'] ?? 'Set by the enclosure; the server cannot read it.')) . '</title><circle cx="' . enclosure_svg_n($lx) . '" cy="' . enclosure_svg_n(is_array($p['y']) ? $p['y'][$i] : $p['y']) . '" r="3.2" class="enc-led"/>'
        . enclosure_svg_text($lx, (is_array($p['y']) ? $p['y'][$i] : $p['y']) - 6, (string)($p['names'][$i] ?? ''), 'enc-t-tiny') . '</g>';
      return $o;
    case 'side': return enclosure_svg_rect($bx, 'enc-side', 14) . enclosure_svg_text($bx[0] + $bx[2] / 2, $bx[1] + 34, (string)($p['text'] ?? ''), 'enc-t-model');
    case 'recess': return enclosure_svg_rect($bx, 'enc-recess', 4);
    case 'grille': return enclosure_svg_rect($bx, 'enc-grille', 2);
    case 'switch':
      return '<g><title>' . $h($p['title'] ?? 'Switch') . '</title>' . enclosure_svg_rect($bx, 'enc-switch', 2) . '</g>'
        . enclosure_svg_text($bx[0] - 6, $bx[1] + $bx[3] / 2 + 4, (string)($p['label'] ?? ''), 'enc-t-rear', 'end');
    case 'port':
      $at = (string)($p['label_at'] ?? 'left'); $l = (string)($p['label'] ?? '');
      [$tx, $ty, $an] = $at === 'right' ? [$bx[0] + $bx[2] + 6, $bx[1] + $bx[3] / 2 + 4, 'start'] : ($at === 'above' ? [$bx[0] + $bx[2] / 2, $bx[1] - 6, 'middle']
        : ($at === 'below' ? [$bx[0] + $bx[2] / 2, $bx[1] + $bx[3] + 14, 'middle'] : [$bx[0] - 6, $bx[1] + $bx[3] / 2 + 4, 'end']));
      return '<g><title>' . $h($p['title'] ?? $l) . '</title>' . enclosure_svg_rect($bx, 'enc-port', 2) . '</g>' . ($l !== '' ? enclosure_svg_text($tx, $ty, $l, 'enc-t-rear', $an) : '');
    case 'lock': return '<g><title>' . $h($p['title'] ?? '') . '</title>' . enclosure_svg_rect($bx, 'enc-switch', 4) . '</g>';
    case 'sff':
      $gk = (string)($p['group'] ?? ''); $gb = array_values((array)($u['groups'][$gk] ?? []));
      $ctlOf = []; if (is_array($u['cabling'])) foreach ($u['cabling'] as $ci => $gg) $ctlOf[(string)$gg] = $ci;
      $n = count(array_filter($gb, function ($bay) use ($byBay) { return ($byBay[$bay]['device'] ?? '') !== ''; }));
      $range = $gb ? $gb[0] . '-' . preg_replace('/^[A-Z]+/', '', (string)end($gb)) : '';
      $ci = $ctlOf[$gk] ?? null;
      $title = "SFF-8088 port $gk" . ($range !== '' ? " (bays $range)" : '') . ': ' . ($n ? "$n drive" . ($n > 1 ? 's' : '') . ' seen through it, so its cable is plugged in' : 'no drive seen through it (no cable, or its bays are empty)')
        . ($ci !== null && isset($u['controllers'][$ci]) ? '. Fed by controller ' . ($ci + 1) . " of the card ({$u['controllers'][$ci]})" . ($u['confirmed'] ? '' : ', cabling not confirmed') : '') . '.';
      return '<g class="enc-sff' . ($n ? ' enc-sff-on' : '') . '"><title>' . $h($title) . '</title>' . enclosure_svg_rect($bx, 'enc-port', 2)
        . enclosure_svg_rect([$bx[0] + 4, $bx[1] + 4, $bx[2] - 8, $bx[3] - 8], 'enc-port-in', 1) . '</g>'
        . enclosure_svg_text($bx[0] - 6, $bx[1] + $bx[3] / 2 + 4, $gk, 'enc-t-rear', 'end')
        . enclosure_svg_text($bx[0] + $bx[2] + 6, $bx[1] + $bx[3] / 2 + 4, $range . ($n ? ": $n" : ''), 'enc-t-rear', 'start');
    case 'fan':
      [$cx, $cy] = $p['c']; $k = enclosure_svg_n($p['r'] / 27);
      return '<g class="enc-fan" transform="translate(' . enclosure_svg_n($cx) . ',' . enclosure_svg_n($cy) . ') scale(' . $k . ')"><title>' . $h($p['title'] ?? 'System fan: run by the enclosure, from its fan switch. Its speed is not visible to the server.') . '</title>'
        . '<circle r="27" class="enc-fan-ring"/><path d="' . $blade . '"/><path d="' . $blade . '" transform="rotate(120)"/><path d="' . $blade . '" transform="rotate(240)"/><circle r="4.5" class="enc-fan-hub"/></g>';
    case 'psu': [$cx, $cy] = $p['c']; return '<circle cx="' . enclosure_svg_n($cx) . '" cy="' . enclosure_svg_n($cy) . '" r="' . enclosure_svg_n($p['r']) . '" class="enc-grille"><title>' . $h($p['title'] ?? 'Power supply fan') . '</title></circle>';
    case 'buzzer': [$cx, $cy] = $p['c']; return '<circle cx="' . enclosure_svg_n($cx) . '" cy="' . enclosure_svg_n($cy) . '" r="' . enclosure_svg_n($p['r']) . '" class="enc-grille"><title>' . $h($p['title'] ?? 'Buzzer') . '</title></circle>';
    case 'inlet':
      return '<g><title>' . $h($p['title'] ?? 'Power input') . '</title>' . enclosure_svg_rect($bx, 'enc-port', 3)
        . enclosure_svg_rect([$bx[0] + 7, $bx[1] + 11, 4, 10], 'enc-port-pin', 1) . enclosure_svg_rect([$bx[0] + $bx[2] / 2 - 2, $bx[1] + 7, 4, 10], 'enc-port-pin', 1)
        . enclosure_svg_rect([$bx[0] + $bx[2] - 11, $bx[1] + 11, 4, 10], 'enc-port-pin', 1) . '</g>'
        . enclosure_svg_text($bx[0] + $bx[2] / 2, $bx[1] - 8, 'power in', 'enc-t-rear');
  }
  return '';
}

/* the drawing of a unit: its front (every tray where it sits, the LCD, buttons and LEDs) and its rear (fans, switches, the
 * SFF-8088 ports with the drives the server sees behind each), from the profile's panel. The LEDs, the LCD, the fans and the
 * switches are the enclosure's own: they are drawn, never coloured as if read. */
function enclosure_panel_html(array $u): string {
  $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
  $P = $u['panel']; $F = $P['front']; $R = $P['rear'];
  $uid = $h("{$u['profile']}-{$u['unit']}");
  $byBay = []; foreach ($u['bays'] as $b) $byBay[$b['bay']] = $b;
  $kinds = array_unique(array_filter(array_column($u['bays'], 'kind')));
  $cap = 'Front and rear of the unit, the bays as labelled on it' . (count($kinds) > 1 ? ' (' . implode(', ', array_map(function ($p, $k) { return "$p bays $k"; }, array_keys($u['kinds']), array_values($u['kinds']))) . ')' : '')
    . '. A schematic after the maker\'s guide, not to scale.';
  $FX = 8; $TOP = 24; $GAP = 28; $RS = 0.8;                 // front at FX, rear right of it at RS scale
  $RX = $FX + $F['size'][0] + $GAP;
  $VW = $RX + $R['size'][0] * $RS + 8; $VH = $TOP + max($F['size'][1], $R['size'][1] * $RS + 90);
  $o = '<figure class="enc-front enc-panel" aria-labelledby="enc-cap-' . $uid . '" data-enc-unit="' . $h($u['switch']) . "\">\n<figcaption id=\"enc-cap-$uid\" class=\"enc-cap\">" . $h($cap) . "</figcaption>\n"
     . '<div class="enc-panel-wrap"><svg class="enc-svg" viewBox="0 0 ' . enclosure_svg_n($VW) . ' ' . enclosure_svg_n($VH) . '" role="group" aria-labelledby="enc-cap-' . $uid . '">';
  // ---- front
  $ctx = ['u' => $u, 'byBay' => $byBay, 'h' => $h];
  $o .= enclosure_svg_text($FX, 15, 'front', 'enc-t-head', 'start') . '<g transform="translate(' . $FX . ',' . $TOP . ')">' . enclosure_svg_rect($F['body'], 'enc-unit', 10);
  foreach ((array)($F['parts'] ?? []) as $p) $o .= enclosure_svg_part($p, $ctx);
  foreach ((array)$F['trays'] as $g) {
    foreach ((array)$g['bays'] as $i => $bay) {
      $b = $byBay[$bay] ?? null; if (!$b) continue;
      $x = $g['x'] + $i * $g['step']; $cx = $x + $g['w'] / 2;
      $o .= enclosure_svg_tray($b, $x, $g['y'], $g['w'], $g['h']);
      // the bay's drive LED next to its tray, with the arrow towards it ("led": "none" draws none)
      if (($g['led'] ?? 'below') === 'none') continue;
      $below = ($g['led'] ?? 'below') === 'below'; $ly = $below ? $g['y'] + $g['h'] + 11 : $g['y'] - 11;
      $lt = isset($g['led_title']) ? str_replace('%s', $bay, (string)$g['led_title']) : "$bay drive LED: set by the enclosure; the server cannot read it.";
      $o .= '<g><title>' . $h($lt) . '</title><circle cx="' . enclosure_svg_n($cx - 4) . '" cy="' . enclosure_svg_n($ly) . '" r="2.6" class="enc-led"/>'
        . '<path d="M' . enclosure_svg_n($cx + 2) . ',' . enclosure_svg_n($ly + ($below ? 2 : -2)) . ' h7 l-3.5,' . ($below ? '-5' : '5') . ' z" class="enc-arrow"/></g>';
    }
  }
  $o .= '</g>';
  // ---- rear (seen from behind)
  $present = count(array_filter($u['bays'], function ($b) { return $b['device'] !== ''; }));
  $o .= enclosure_svg_text($RX, 15, 'rear (seen from behind)', 'enc-t-head', 'start')
     . '<g transform="translate(' . enclosure_svg_n($RX) . ',' . $TOP . ') scale(' . $RS . ')">' . enclosure_svg_rect($R['body'], 'enc-unit', 10);
  foreach ((array)($R['parts'] ?? []) as $p) $o .= enclosure_svg_part($p, $ctx);
  $o .= '</g>';
  // what the server knows of the rear, under it
  $ty = $TOP + $R['size'][1] * $RS + 18;
  $lines = isset($R['notes']) ? array_map('strval', (array)$R['notes'])
    : ['Fans: run by the enclosure from its fan switch (Auto, High, Medium, Low).', 'Their speed and the switch position are not visible to the server.',
       'Ports: the bays each cable feeds, and the drives the server sees through it.',
       $present ? "Power: on ($present drive" . ($present > 1 ? 's answer' : ' answers') . ').' : 'Power: no drive of this unit answers.'];
  foreach ($lines as $i => $l) $o .= enclosure_svg_text($RX, $ty + $i * 15, $l, 'enc-t-note', 'start');
  $o .= "</svg></div>\n" . '<ul class="enc-legend">'
     . '<li><svg viewBox="0 0 20 8" aria-hidden="true"><rect width="20" height="6" y="1" rx="3" class="enc-key enc-key-active"/></svg>active</li>'
     . '<li><svg viewBox="0 0 20 8" aria-hidden="true"><rect width="20" height="6" y="1" rx="3" class="enc-key enc-key-standby"/></svg>standby (not woken to read it)</li>'
     . '<li><svg viewBox="0 0 20 8" aria-hidden="true"><rect width="20" height="6" y="1" rx="3" class="enc-key enc-key-invalid"/></svg>emulated or invalid</li>'
     . '<li><svg viewBox="0 0 20 8" aria-hidden="true"><rect width="20" height="6" y="1" rx="3" class="enc-key enc-key-disabled"/></svg>disabled or missing</li>'
     . '<li><svg viewBox="0 0 12 12" aria-hidden="true"><path d="M6,1 l5,9 h-10 z" class="enc-key-warn"/></svg>SMART error</li>'
     . '<li><svg viewBox="0 0 20 12" aria-hidden="true"><rect x="1" y="1" width="18" height="10" rx="2" class="enc-key-empty"/></svg>empty bay</li>'
     . "</ul>\n"
     . '<p class="enc-note">The state is Unraid\'s, the temperature the one it last read (°C; stby: in standby, ?: no reading). ' . htmlspecialchars((string)($P['note'] ?? 'The LEDs, the LCD, the fans and the switches are the enclosure\'s own: drawn where they sit, not read.'), ENT_NOQUOTES, 'UTF-8') . "</p>\n</figure>\n";
  return $o;
}

/* what the live refresh needs: [switch => [bay => [device, slot, temp, temp_short, state, icon, text, smart, standby]]] */
function enclosure_live(array $view): array {
  $out = [];
  foreach ($view['units'] as $u) foreach ($u['bays'] as $b)
    $out[$u['switch']][$b['bay']] = ['device' => $b['device'], 'slot' => $b['slot'], 'temp' => enclosure_temp_text($b), 'temp_short' => enclosure_temp_short($b), 'state' => $b['state'],
      'icon' => ENCLOSURE_ICON[$b['state']] ?? '', 'text' => enclosure_bay_text($b), 'smart' => $b['smart']['state'], 'standby' => $b['standby']];
  return $out;
}

/* The confirmed cabling of every unit: what any plugin on this server stored (/boot/config/plugins/*\/enclosures.json, the
 * same format), the plugin's own file last so that it wins. One confirmation serves every plugin that shows the enclosure. */
function enclosure_cabling(string $own): array {
  $all = [];
  foreach (glob(dirname($own, 2) . '/*/enclosures.json') ?: [] as $f) if ($f !== $own) $all = DriveEnclosures::readCabling($f) + $all;
  return DriveEnclosures::readCabling($own) + $all;
}

/* a slot as Unraid's Main page names it: Parity, Parity 2, Disk 3, Dev 7; a pool slot keeps its name (cache, cache2) */
function enclosure_slot_label(string $slot): string {
  if ($slot === 'parity') return 'Parity';
  if (preg_match('/^parity(\d+)$/', $slot, $m)) return "Parity {$m[1]}";
  if (preg_match('/^(disk|dev)(\d+)$/', $slot, $m)) return ucfirst($m[1]) . " {$m[2]}";
  return $slot;
}

/* 4 TB, 480 GB */
function enclosure_size(int $b): string {
  if ($b <= 0) return '';
  foreach (['TB' => 1e12, 'GB' => 1e9, 'MB' => 1e6] as $u => $f) if ($b >= $f) return rtrim(rtrim(number_format($b / $f, 1, '.', ''), '0'), '.') . " $u";
  return "$b B";
}

/* "8 GT/s x8" */
function enclosure_link_text(?float $speed, ?int $width): string {
  if ($speed === null && $width === null) return 'unknown';
  return trim(($speed !== null ? rtrim(rtrim(number_format($speed, 1, '.', ''), '0'), '.') . ' GT/s' : '') . ($width !== null ? " x$width" : ''));
}

}
