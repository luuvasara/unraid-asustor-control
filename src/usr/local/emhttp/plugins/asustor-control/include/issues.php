<?php
/* issues.php - known issues and firmware (Hardware tab and the report). The data ships with the plugin (data/known-issues.json);
 * each issue with a check is evaluated against this server, the others are shown as information. READ-ONLY.
 * acs_issues() -> [['id', 'title', 'state' => ok|warn|info, 'status' => text, 'text', 'source'], ...] */
require_once __DIR__ . '/acs.php';

function acs_issue_data(): array {
  $j = json_decode((string)@file_get_contents(dirname(__DIR__) . '/data/known-issues.json'), true);
  return is_array($j) ? $j + ['issues' => [], 'firmware' => []] : ['issues' => [], 'firmware' => []];
}

/* what the driver watchdog wrote (drivers.json) and which modules are loaded */
function acs_drivers(): array {
  $j = json_decode((string)@file_get_contents(acs_state() . '/drivers.json'), true);
  $mods = [];
  foreach (['asustor', 'asustor_it87', 'asustor_gpio_it87', 'leds_gpio', 'gpio_keys_polled'] as $m) $mods[$m] = is_dir(acs_root() . "/sys/module/$m");
  return ['watchdog' => is_array($j) ? $j : null, 'modules' => $mods, 'leds' => is_dir(acs_root() . '/sys/class/leds/sata1:green:disk') || is_dir(acs_root() . '/sys/class/leds/green:status')];
}

function acs_dmi(string $k): string { return trim((string)@file_get_contents(acs_root() . "/sys/class/dmi/id/$k")); }

function acs_issues(?array $view = null): array {
  $v = $view ?? acs_view();
  $d = acs_drivers();
  $out = [];
  foreach (acs_issue_data()['issues'] as $i) {
    $hits = []; $na = '';
    switch ($i['check'] ?? '') {
      case 'drivers':
        $w = $d['watchdog'];
        if ($w === null) $na = 'The driver watchdog has not run (no drivers.json)';
        elseif (!in_array($w['state'] ?? '', ['ok', 'warn'], true)) $hits[] = (string)($w['message'] ?? 'missing');
        break;
      case 'leds':
        if (!$d['modules']['asustor']) $na = 'Asustor drivers not loaded';
        elseif (!$d['leds']) $hits[] = 'no LED devices (leds-gpio not loaded)';
        break;
      case 'bayorder':
        foreach ($v['units'] as $u) if (!$u['confirmed']) $hits[] = $u['name'];
        if (!$v['units']) $na = 'No AS6706T bays found';
        break;
      case 'cpuidle':
        $cd = trim((string)@file_get_contents(acs_root() . '/sys/devices/system/cpu/cpuidle/current_driver'));
        if (!$v['units']) $na = 'Checked on an AS6706T only';
        elseif ($cd === '' && !is_dir(acs_root() . '/sys/devices/system/cpu')) $na = 'Not readable';
        elseif ($cd === '' || $cd === 'none') $hits[] = 'current_driver: ' . ($cd === '' ? 'none (no cpuidle)' : $cd);
        break;
      case 'links':
        foreach ($v['units'] as $u) foreach ($u['links'] as $l) if ($l['state'] !== 'ok' && $l['state'] !== 'unknown') $hits[] = "{$l['to']}: " . enclosure_link_text($l['speed'], $l['width']) . ' of ' . enclosure_link_text($l['want_speed'], $l['want_width']);
        if (!$v['units']) $na = 'No AS6706T bays found';
        break;
      case 'aer':
        foreach ($v['units'] as $u) foreach ($u['hw']['controllers'] as $c) foreach ($c['aer'] as $k => $x) if ($x) $hits[] = "{$c['address']}: $x $k";
        if (!$v['units']) $na = 'No AS6706T bays found';
        break;
      case 'sata':
        foreach ($v['units'] as $u) foreach ($u['hw']['controllers'] as $c) foreach ($c['ports'] as $p)
          if ($p['device'] !== '' && $p['limit'] !== '' && $p['speed'] !== $p['limit']) $hits[] = ($p['bay'] ?: $p['device']) . ": {$p['speed']} of {$p['limit']}";
        if (!$v['units']) $na = 'No AS6706T bays found';
        break;
    }
    $check = ($i['check'] ?? 'info') !== 'info';
    $state = !$check || $na !== '' ? 'info' : ($hits ? 'warn' : 'ok');
    $status = !$check ? 'Information' : ($na !== '' ? $na : ($hits ? implode('; ', $hits) : 'Not seen on this server'));
    $out[] = ['id' => (string)$i['id'], 'title' => (string)$i['title'], 'state' => $state, 'status' => $status, 'text' => (string)$i['text'], 'source' => (string)($i['source'] ?? '')];
  }
  return $out;
}

/* firmware: the BIOS from DMI (readable), the parts from the data file, each drive's firmware from its controller port */
function acs_firmware(?array $view = null): array {
  $v = $view ?? acs_view();
  $bios = trim(acs_dmi('bios_vendor') . ' ' . acs_dmi('bios_version') . (acs_dmi('bios_date') !== '' ? ', ' . acs_dmi('bios_date') : ''));
  $rows = [['part' => 'BIOS', 'value' => $bios !== '' ? $bios : 'unknown', 'text' => 'Read from DMI. This plugin does not update it.', 'source' => '/sys/class/dmi/id (bios_vendor, bios_version, bios_date)']];
  foreach (acs_issue_data()['firmware'] as $f) $rows[] = ['part' => (string)$f['part'], 'value' => 'not readable', 'text' => (string)$f['text'], 'source' => (string)($f['source'] ?? '')];
  foreach ($v['units'] as $u) foreach ($u['hw']['controllers'] as $c) foreach ($c['ports'] as $p) if ($p['device'] !== '') {
    $model = ''; foreach ($u['bays'] as $b) if ($b['device'] === $p['device']) $model = $b['model'];
    $rows[] = ['part' => "Drive in {$p['bay']} ({$p['device']})", 'value' => $p['rev'] !== '' ? $p['rev'] : 'unknown', 'text' => $model, 'source' => 'sysfs (the drive\'s identify data, read by the kernel at boot)'];
  }
  return $rows;
}
