<?php

require_once LBPBINDIR . "/defines.php";

define('Z2M_INSTALL_DIR', '/opt/zigbee2mqtt');
define('Z2M_VERSION_FILE', LBPCONFIGDIR . '/installed-version.json');
define('Z2M_STATE_FILE', LBPCONFIGDIR . '/upgrade-state.json');
define('Z2M_UPGRADE_LOG', LBPLOGDIR . '/upgrade.log');
define('Z2M_RELEASES_CACHE', LBPDATADIR . '/releases-cache.json');
define('Z2M_RELEASES_CACHE_TTL', 3600);

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

    if ($state['status'] === 'running' && !empty($state['pid'])) {
        if (!z2mProcessIsRunning((int) $state['pid'])) {
            $state['status'] = 'failed';
            $state['message'] = 'The upgrade process is no longer running.';
        }
    }

    $state['log'] = z2mGetLogTail($logLines);
    return $state;
}

/**
 * Checks whether $pid is still running install-zigbee2mqtt.sh, via /proc
 * rather than kill -0: the web server user has no signal permission on the
 * root-owned installer process, so kill -0 cannot tell "running" from
 * "already gone" here - only existence + cmdline in /proc can.
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
    file_put_contents(Z2M_STATE_FILE, json_encode($state));
}

/**
 * Validates a version string before it is passed to the shell / matched
 * against the known release list.
 */
function z2mIsValidVersionString($version)
{
    return is_string($version) && $version !== '' && preg_match('/^[0-9A-Za-z._-]+$/', $version) === 1;
}
