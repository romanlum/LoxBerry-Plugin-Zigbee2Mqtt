<?php

require_once LBPBINDIR . "/defines.php";

define('Z2M_INSTALL_DIR', '/opt/zigbee2mqtt');
define('Z2M_VERSION_FILE', LBPCONFIGDIR . '/installed-version.json');
define('Z2M_STATE_FILE', LBPCONFIGDIR . '/upgrade-state.json');
define('Z2M_UPGRADE_LOG', LBPLOGDIR . '/upgrade.log');
define('Z2M_RELEASES_CACHE', LBPDATADIR . '/releases-cache.json');
define('Z2M_RELEASES_CACHE_TTL', 3600);
// Written by install-zigbee2mqtt.sh (create_backup) before each version change
define('Z2M_BACKUP_DIR', LBPCONFIGDIR . '/backups');
define('Z2M_BACKUP_NAME_PATTERN', '/^zigbee2mqtt-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})-([0-9A-Za-z._-]+)\.tar\.gz$/');

// How long a "running" state may stay pid-less before it is considered dead.
// z2mMarkUpgradeRunning() cannot know the pid yet; the installer stamps the
// real one with its own first write_state, which happens within seconds of
// the dispatch. Anything still pid-less well past that never started.
define('Z2M_UPGRADE_HANDOVER_TIMEOUT', 120);

/**
 * Returns the version of the currently installed zigbee2mqtt, or null if
 * none is installed yet.
 */
function z2mGetInstalledVersion()
{
    $file = Z2M_INSTALL_DIR . '/package.json';
    if (!file_exists($file)) {
        return null;
    }
    $data = json_decode(file_get_contents($file), true);
    return $data['version'] ?? null;
}

/**
 * Returns the version pinned from the web UI, or null if the user never
 * picked one (the plugin's version.sh baseline is then in effect).
 */
function z2mGetPinnedVersion()
{
    if (!file_exists(Z2M_VERSION_FILE)) {
        return null;
    }
    $data = json_decode(file_get_contents(Z2M_VERSION_FILE), true);
    return $data['zigbee2mqttVersion'] ?? null;
}

/**
 * Fetches recent zigbee2mqtt release tags from GitHub, newest first, cached
 * for Z2M_RELEASES_CACHE_TTL seconds. Falls back to a stale cache (or an
 * empty list) if GitHub cannot be reached, rather than failing the page.
 */
function z2mGetAvailableReleases($perPage = 15)
{
    if (file_exists(Z2M_RELEASES_CACHE) && (time() - filemtime(Z2M_RELEASES_CACHE)) < Z2M_RELEASES_CACHE_TTL) {
        $cached = json_decode(file_get_contents(Z2M_RELEASES_CACHE), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $context = stream_context_create([
        'http' => [
            // GitHub's API rejects requests without a User-Agent header.
            'header' => "User-Agent: LoxBerry-Plugin-Zigbee2Mqtt\r\n",
            'timeout' => 5,
        ],
    ]);
    $response = @file_get_contents(
        'https://api.github.com/repos/Koenkk/zigbee2mqtt/releases?per_page=' . (int) $perPage,
        false,
        $context
    );

    if ($response !== false) {
        $releases = json_decode($response, true);
        if (is_array($releases)) {
            $versions = array_values(array_filter(array_map(function ($release) {
                return $release['tag_name'] ?? null;
            }, $releases)));
            file_put_contents(Z2M_RELEASES_CACHE, json_encode($versions));
            return $versions;
        }
    }

    if (file_exists(Z2M_RELEASES_CACHE)) {
        $cached = json_decode(file_get_contents(Z2M_RELEASES_CACHE), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    return [];
}

/**
 * Lists the backups taken before version changes, newest first, with the
 * zigbee2mqtt version they were taken from.
 */
function z2mListBackups()
{
    $backups = [];
    foreach (glob(Z2M_BACKUP_DIR . '/zigbee2mqtt-*.tar.gz') ?: [] as $path) {
        $name = basename($path);
        if (!preg_match(Z2M_BACKUP_NAME_PATTERN, $name, $m)) {
            continue;
        }
        $backups[] = [
            'file' => $name,
            'created' => "$m[1]-$m[2]-$m[3]T$m[4]:$m[5]:$m[6]Z",
            'version' => $m[7],
            'size' => filesize($path),
        ];
    }
    // Names start with a UTC timestamp, so they sort chronologically
    usort($backups, function ($a, $b) {
        return strcmp($b['file'], $a['file']);
    });
    return $backups;
}

/**
 * Returns the path of the backup with the given file name, or null if the
 * name is not a backup name or no such backup exists. The name comes from
 * the request, so it is matched against the exact naming scheme rather than
 * just stripped of path components.
 */
function z2mGetBackupPath($name)
{
    if (!is_string($name) || !preg_match(Z2M_BACKUP_NAME_PATTERN, $name)) {
        return null;
    }
    $path = Z2M_BACKUP_DIR . '/' . $name;
    return is_file($path) ? $path : null;
}

/**
 * Returns the current upgrade job state, including a tail of the upgrade
 * log. A "running" state whose process is no longer alive is reported as
 * "failed" - this also covers a state file restored from a config backup.
 */
function z2mGetUpgradeState($logLines = 200)
{
    $state = [
        'status' => 'idle',
        'version' => null,
        'started' => null,
        'finished' => null,
        'message' => '',
    ];

    if (file_exists(Z2M_STATE_FILE)) {
        $data = json_decode(file_get_contents(Z2M_STATE_FILE), true);
        if (is_array($data)) {
            $state = array_merge($state, $data);
        }
    }

    if ($state['status'] === 'running') {
        if (!empty($state['pid'])) {
            if (!z2mProcessIsRunning((int) $state['pid'])) {
                $state['status'] = 'failed';
                $state['message'] = 'The upgrade process is no longer running.';
            }
        } elseif (z2mUpgradeHandoverExpired($state['started'])) {
            $state['status'] = 'failed';
            $state['message'] = 'The upgrade process could not be started.';
        }
    }

    $state['log'] = z2mGetLogTail($logLines);
    return $state;
}

/**
 * True once the handover window between z2mMarkUpgradeRunning() and the
 * installer's own first state write has elapsed. A state still carrying no
 * pid by then means the dispatch never reached the script (e.g. a missing
 * sudoers rule), and without this it would stay "running" forever: the UI
 * would poll indefinitely and every later upgrade would be refused as
 * already running, with no way to clear it from the web UI.
 *
 * A missing or unparsable timestamp counts as expired - failing the job is
 * recoverable, wedging it is not.
 */
function z2mUpgradeHandoverExpired($started)
{
    if (!is_string($started) || $started === '') {
        return true;
    }
    $timestamp = strtotime($started);
    if ($timestamp === false) {
        return true;
    }
    return (time() - $timestamp) > Z2M_UPGRADE_HANDOVER_TIMEOUT;
}

/**
 * Checks whether $pid is still running install-zigbee2mqtt.sh, via /proc
 * rather than kill -0: kill -0 only confirms *some* process has this pid,
 * which - once it exits - the kernel is free to reassign to something
 * unrelated. Checking /proc/$pid/cmdline confirms it's still this script.
 */
function z2mProcessIsRunning($pid)
{
    $cmdlineFile = "/proc/$pid/cmdline";
    if (!file_exists($cmdlineFile)) {
        return false;
    }
    $cmdline = @file_get_contents($cmdlineFile);
    return $cmdline !== false && strpos($cmdline, 'install-zigbee2mqtt.sh') !== false;
}

/**
 * Returns the last $lines lines of the upgrade log.
 */
function z2mGetLogTail($lines = 200)
{
    if (!file_exists(Z2M_UPGRADE_LOG)) {
        return '';
    }
    $output = shell_exec('tail -n ' . (int) $lines . ' ' . escapeshellarg(Z2M_UPGRADE_LOG));
    return $output === null ? '' : $output;
}

/**
 * Truncates the upgrade log before a new job starts.
 */
function z2mResetLog()
{
    file_put_contents(Z2M_UPGRADE_LOG, '');
}

/**
 * Marks the upgrade state "running" synchronously before the background
 * install script is dispatched. Without this, a client polling right after
 * startUpgrade() could still see the previous job's leftover "success" or
 * "failed" state - the script only overwrites it with "running" itself once
 * it gets to run, which (via sudo/nohup/setsid) is not instant - and stop
 * polling before any progress ever shows up.
 */
function z2mMarkUpgradeRunning($version)
{
    $state = [
        'status' => 'running',
        'version' => $version,
        'pid' => null,
        'started' => gmdate('Y-m-d\TH:i:s\Z'),
        'finished' => null,
        'message' => '',
    ];
    // Written to a temp file and renamed into place, so a concurrent
    // z2mGetUpgradeState() never reads a truncated, half-written file
    // (which it would report as "idle", stopping the UI's polling).
    $tmp = Z2M_STATE_FILE . '.' . getmypid();
    file_put_contents($tmp, json_encode($state));
    rename($tmp, Z2M_STATE_FILE);
}

/**
 * Validates a version string before it is passed to the shell / matched
 * against the known release list.
 */
function z2mIsValidVersionString($version)
{
    return is_string($version) && $version !== '' && preg_match('/^[0-9A-Za-z._-]+$/', $version) === 1;
}
