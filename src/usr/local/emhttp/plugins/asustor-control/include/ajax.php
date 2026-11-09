<?php
header('Content-Type: application/json');

$tz = trim(str_replace("\r", '', shell_exec("sed -n 's/^timeZone=\"\\(.*\\)\"/\\1/p' /boot/config/ident.cfg 2>/dev/null") ?: ''));
if ($tz && !file_exists("/usr/share/zoneinfo/$tz")) $tz = str_replace('Kiev', 'Kyiv', $tz);
if ($tz) @date_default_timezone_set($tz);

function ac_log($msg) {
    $logfile = '/var/local/emhttp/plugins/asustor-control/action.log';
    $dir = dirname($logfile);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ts = date('Y-m-d H:i:s');
    file_put_contents($logfile, "[$ts] $msg\n", FILE_APPEND | LOCK_EX);
    $lines = file($logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (count($lines) > 200) {
        file_put_contents($logfile, implode("\n", array_slice($lines, -200)) . "\n");
    }
}

function get_unraid_thresholds() {
    $warn = 45; $crit = 55; $warnssd = 60;
    $dynCfg = '/boot/config/plugins/dynamix/dynamix.cfg';
    if (file_exists($dynCfg)) {
        foreach (file($dynCfg, FILE_IGNORE_NEW_LINES) as $dl) {
            if (preg_match('/^hot="?(\d+)"?/', $dl, $m)) $warn = (int)$m[1];
            if (preg_match('/^max="?(\d+)"?/', $dl, $m)) $crit = (int)$m[1];
            if (preg_match('/^hotssd="?(\d+)"?/', $dl, $m)) $warnssd = (int)$m[1];
        }
    }
    return [$warn, $crit, $warnssd];
}

/**
 * Find a hwmon chip by name pattern.
 * Returns the hwmon sysfs path or null.
 */
function find_hwmon($namePattern) {
    foreach (glob('/sys/class/hwmon/hwmon*') as $h) {
        $nameFile = "$h/name";
        if (!file_exists($nameFile)) continue;
        $name = trim(file_get_contents($nameFile));
        if (preg_match($namePattern, $name)) return $h;
    }
    return null;
}

/**
 * Read fan/PWM from IT8625E (or similar) hwmon chip.
 * Returns ['pwm' => int|null, 'rpm' => int|null, 'hwmon' => string|null]
 * NEVER writes to sysfs — read-only.
 */
function read_fan_hwmon() {
    $h = find_hwmon('/^(it8|nct)/');
    if (!$h) return ['pwm' => null, 'rpm' => null, 'hwmon' => null];

    $pwm = null;
    $rpm = null;
    if (file_exists("$h/pwm1")) {
        $v = trim(@file_get_contents("$h/pwm1"));
        if ($v !== '' && $v !== false) $pwm = (int)$v;
    }
    if (file_exists("$h/fan1_input")) {
        $v = trim(@file_get_contents("$h/fan1_input"));
        if ($v !== '' && $v !== false) $rpm = (int)$v;
    }
    return ['pwm' => $pwm, 'rpm' => $rpm, 'hwmon' => $h];
}

/**
 * Read highest CPU core temperature from coretemp hwmon.
 * Returns int|null (degrees C).
 */
function read_cpu_temp() {
    $h = find_hwmon('/^coretemp$/');
    if (!$h) return null;

    $max = 0;
    foreach (glob("$h/temp*_input") as $f) {
        $v = trim(@file_get_contents($f));
        if ($v === '' || $v === false) continue;
        $t = intdiv((int)$v, 1000);
        if ($t > $max && $t < 120) $max = $t;
    }
    return $max > 0 ? $max : null;
}

/**
 * Read drive temperatures via smartctl.
 * Results are cached for 30 seconds to avoid hammering drives.
 * Returns ['drive_temps' => ['sda' => 42, ...], 'max_drive_temp' => 44]
 */
function read_drive_temps() {
    $cacheFile = '/var/local/emhttp/plugins/asustor-control/smartctl-cache.json';
    $cacheMaxAge = 30;

    if (file_exists($cacheFile)) {
        $age = time() - filemtime($cacheFile);
        if ($age < $cacheMaxAge) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached) return $cached;
        }
    }

    $drives = [];
    $maxTemp = 0;

    // Use Unraid's disks.ini for drive info — more reliable than scanning /dev
    $ini = '/var/local/emhttp/disks.ini';
    if (file_exists($ini)) {
        $dev = ''; $temp = ''; $status = '';
        foreach (file($ini, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\[/', $line)) {
                if ($dev !== '' && $temp !== '' && $temp !== '*' && $status !== 'DISK_NP') {
                    $t = (int)$temp;
                    $drives[$dev] = $t;
                    if ($t > $maxTemp) $maxTemp = $t;
                }
                $dev = ''; $temp = ''; $status = '';
            }
            if (preg_match('/^device="?([^"]*)"?$/', $line, $m)) $dev = $m[1];
            if (preg_match('/^temp="?([^"]*)"?$/', $line, $m)) $temp = $m[1];
            if (preg_match('/^status="?([^"]*)"?$/', $line, $m)) $status = $m[1];
        }
        // Handle last entry
        if ($dev !== '' && $temp !== '' && $temp !== '*' && $status !== 'DISK_NP') {
            $t = (int)$temp;
            $drives[$dev] = $t;
            if ($t > $maxTemp) $maxTemp = $t;
        }
    }

    $result = ['drive_temps' => $drives, 'max_drive_temp' => $maxTemp > 0 ? $maxTemp : null];

    // Cache the result
    $dir = dirname($cacheFile);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    file_put_contents($cacheFile, json_encode($result));

    return $result;
}

/**
 * Behaviour-log status written by the asustor-hwmon daemon (see hwlog-format.md).
 * Returns the decoded JSON plus file count / size of the log directory, or null.
 */
function read_hwlog_status() {
    $f = '/var/local/emhttp/plugins/asustor-control/hwlog-status.json';
    if (!file_exists($f)) return null;
    $st = json_decode(file_get_contents($f), true);
    if (!is_array($st)) return null;
    $files = 0; $bytes = 0;
    if (!empty($st['logdir']) && is_dir($st['logdir'])) {
        foreach (glob($st['logdir'] . '/hwlog-????-??-??.csv') as $lf) { $files++; $bytes += filesize($lf); }
    }
    $st['files'] = $files;
    $st['bytes'] = $bytes;
    return $st;
}

function read_state_json($name) {
    $f = "/var/local/emhttp/plugins/asustor-control/$name";
    if (!file_exists($f)) return null;
    $j = json_decode(@file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

function pid_alive($pidfile) {
    return file_exists($pidfile) && posix_kill((int)trim(@file_get_contents($pidfile)), 0);
}

define('LEDCFG', '/boot/config/plugins/asustor-control/leds.cfg');
$LED_DEFAULTS = ['leds_enabled' => 'yes', 'activity_poll_ms' => '200', 'bay_ports' => 'auto',
                 'copy_short' => 'log', 'copy_long' => 'log', 'long_press_s' => '3', 'kmods' => 'no', 'bay_order_confirmed' => 'no'];
$BUTTON_ACTIONS = ['none', 'log', 'notify', 'locate', 'script'];

function read_led_cfg() {
    global $LED_DEFAULTS;
    $cfg = $LED_DEFAULTS;
    if (file_exists(LEDCFG)) {
        foreach (file(LEDCFG, FILE_IGNORE_NEW_LINES) as $l) {
            if (preg_match('/^([a-z_]+)=([^#]*)/', $l, $m) && isset($cfg[$m[1]])) $cfg[$m[1]] = trim($m[2], " \"");
        }
    }
    return $cfg;
}

function write_led_cfg($cfg) {
    $out = "# Asustor Control: front-panel LEDs and buttons (written by the plugin page)\n";
    foreach ($cfg as $k => $v) $out .= "$k=$v\n";
    @mkdir(dirname(LEDCFG), 0755, true);
    file_put_contents(LEDCFG, $out);
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'getStatus':
        $lcd = '/var/local/emhttp/plugins/asustor-control/lcd.json';
        list($warn, $crit, $warnssd) = get_unraid_thresholds();

        $fan = read_fan_hwmon();
        $cpuTemp = read_cpu_temp();
        $driveData = read_drive_temps();

        $result = [
            'hw' => [
                'pwm' => $fan['pwm'],
                'rpm' => $fan['rpm'],
                'cpu_temp' => $cpuTemp,
                'max_drive_temp' => $driveData['max_drive_temp'],
                'drive_temps' => $driveData['drive_temps'],
                'hwmon' => $fan['hwmon'],
            ],
            'lcd' => file_exists($lcd) ? json_decode(file_get_contents($lcd), true) : null,
            'unraid_warn' => $warn,
            'unraid_crit' => $crit,
            'unraid_warnssd' => $warnssd,
            'lcd_running' => file_exists('/var/run/asustor-lcd.pid') && posix_kill((int)trim(@file_get_contents('/var/run/asustor-lcd.pid')), 0),
            'hwmon_running' => file_exists('/var/run/asustor-hwmon.pid') && posix_kill((int)trim(@file_get_contents('/var/run/asustor-hwmon.pid')), 0),
            'hwlog' => read_hwlog_status(),
            'drivers' => read_state_json('drivers.json'),
        ];
        echo json_encode($result);
        break;

    case 'getLcdConfig':
        $ini = '/boot/config/plugins/asustor-control/lcd.ini';
        if (file_exists($ini)) {
            $cfg = parse_ini_file($ini, true);
            echo json_encode($cfg['lcd'] ?? []);
        } else {
            echo json_encode([
                'enabled' => 'true',
                'port' => '/dev/ttyS1',
                'cycle_interval' => '5',
                'screens' => 'hostname,ip,temps,array,time',
            ]);
        }
        break;

    case 'saveLcdConfig':
        $keys = ['enabled', 'port', 'cycle_interval', 'screens'];
        $ini = "[lcd]\n";
        foreach ($keys as $k) {
            if (isset($_POST[$k])) {
                $val = $_POST[$k];
                if ($k === 'cycle_interval') {
                    $val = (string)(int)$val;
                } elseif ($k === 'enabled') {
                    $val = ($val === 'true') ? 'true' : 'false';
                } elseif ($k === 'port') {
                    $val = preg_replace('/[^a-zA-Z0-9\/]/', '', $val);
                } elseif ($k === 'screens') {
                    $val = preg_replace('/[^a-zA-Z0-9,]/', '', $val);
                }
                $ini .= "$k = $val\n";
            }
        }
        $dir = '/boot/config/plugins/asustor-control';
        @mkdir($dir, 0755, true);
        file_put_contents("$dir/lcd.ini", $ini);
        ac_log('Saved LCD config');
        echo json_encode(['status' => 'ok']);
        break;

    case 'restartLcd':
        $rc = '/etc/rc.d/rc.asustor-control';
        shell_exec("$rc stop_lcd 2>&1");
        sleep(1);
        shell_exec("$rc start_lcd 2>&1");
        ac_log('Restarted LCD daemon');
        echo json_encode(['status' => 'ok']);
        break;

    case 'stopLcd':
        shell_exec('/etc/rc.d/rc.asustor-control stop_lcd 2>&1');
        ac_log('Stopped LCD daemon');
        echo json_encode(['status' => 'ok']);
        break;

    case 'ledOn':
        shell_exec('/etc/rc.d/rc.asustor-control led_on 2>&1');
        ac_log('Status LED turned on');
        echo json_encode(['status' => 'ok']);
        break;

    case 'ledOff':
        shell_exec('/etc/rc.d/rc.asustor-control led_off 2>&1');
        ac_log('Status LED turned off');
        echo json_encode(['status' => 'ok']);
        break;

    case 'getLeds':
        $kmods = [];
        exec('/usr/local/bin/asustor-kmods status 2>&1', $kmods, $krc);
        echo json_encode([
            'leds' => read_state_json('leds.json'),
            'buttons' => read_state_json('buttons.json'),
            'leds_running' => pid_alive('/var/run/asustor-leds.pid'),
            'buttons_running' => pid_alive('/var/run/asustor-buttons.pid'),
            'led_class' => is_dir('/sys/class/leds/green:status') || is_dir('/sys/class/leds/sata1:green:disk'),
            'kmods' => ['available' => $krc === 0, 'provided' => $krc === 6, 'lines' => $kmods],
            'cfg' => read_led_cfg(),
        ]);
        break;

    case 'locate':
        $bay = $_POST['bay'] ?? '';
        if ($bay === 'stop') { $req = "stop 0"; }
        elseif (preg_match('/^[0-6]$/', $bay)) { $req = "$bay " . max(1, min(600, (int)($_POST['seconds'] ?? 30))); }
        else { echo json_encode(['error' => 'bad bay']); break; }
        file_put_contents('/var/local/emhttp/plugins/asustor-control/locate.req', "$req\n");
        echo json_encode(['status' => 'ok']);
        break;

    case 'saveLedConfig':
        $cfg = read_led_cfg();
        foreach (['leds_enabled', 'kmods', 'bay_order_confirmed'] as $k) if (isset($_POST[$k])) $cfg[$k] = $_POST[$k] === 'yes' ? 'yes' : 'no';
        foreach (['copy_short', 'copy_long'] as $k) if (isset($_POST[$k]) && in_array($_POST[$k], $BUTTON_ACTIONS, true)) $cfg[$k] = $_POST[$k];
        if (isset($_POST['long_press_s'])) $cfg['long_press_s'] = (string)max(1, min(30, (int)$_POST['long_press_s']));
        if (isset($_POST['activity_poll_ms'])) $cfg['activity_poll_ms'] = (string)max(50, min(2000, (int)$_POST['activity_poll_ms']));
        if (isset($_POST['bay_ports'])) {
            $bp = trim($_POST['bay_ports']);
            $cfg['bay_ports'] = ($bp === '' || $bp === 'auto' || !preg_match('/^\d{1,3}(,\d{1,3}){0,5}$/', $bp)) ? 'auto' : $bp;
        }
        write_led_cfg($cfg);
        // the drawing and the Main page labels follow the same bay order (kit enclosure cabling, unit "none")
        require_once __DIR__ . '/acs.php';
        $order = acs_save_bay_order($cfg['bay_ports'], $cfg['bay_order_confirmed'] === 'yes');
        shell_exec('/etc/rc.d/rc.asustor-control restart_leds 2>&1');
        ac_log('Saved LED/button config' . ($cfg['bay_order_confirmed'] === 'yes' ? ' (bay order confirmed)' : ''));
        echo json_encode(['status' => $order['ok'] ? 'ok' : 'error', 'note' => $order['note']]);
        break;

    case 'kmodsInstall':
        $out = [];
        exec('/usr/local/bin/asustor-kmods install 2>&1', $out, $rc);
        foreach ($out as $l) ac_log("kmods: $l");
        if ($rc === 0) {
            $cfg = read_led_cfg(); $cfg['kmods'] = 'yes'; write_led_cfg($cfg);   // load again at boot
            // leds-gpio re-drives the panel GPIOs (LCD power included): restart everything in order
            shell_exec('/etc/rc.d/rc.asustor-control restart 2>&1');
        }
        echo json_encode(['status' => $rc === 0 ? 'ok' : 'refused', 'rc' => $rc, 'lines' => $out]);
        break;

    case 'getHistory':
        $csv = '/var/local/emhttp/plugins/asustor-control/hwmon-history.csv';
        $rows = [];
        if (file_exists($csv)) {
            foreach (file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $f = explode(',', $line);
                if (count($f) >= 5) {
                    $rows[] = [
                        'ts' => (int)$f[0], 'pwm' => (int)$f[1],
                        'cpu' => (int)$f[2], 'drv' => (int)$f[3],
                        'rpm' => (int)$f[4],
                    ];
                }
            }
        }
        echo json_encode($rows);
        break;

    case 'getLog':
        $logfile = '/var/local/emhttp/plugins/asustor-control/action.log';
        $lines = [];
        if (file_exists($logfile)) {
            $lines = array_slice(file($logfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -50);
        }
        echo json_encode($lines);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
        break;
}
