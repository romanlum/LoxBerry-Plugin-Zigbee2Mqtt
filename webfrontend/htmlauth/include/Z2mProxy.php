<?php

/**
 * Reverse proxy for the Zigbee2MQTT frontend.
 *
 * Zigbee2MQTT's own frontend process listens on a plain HTTP port with no
 * authentication of its own. Instead of sending the browser there directly
 * (the old iframe/redirect behavior), this fetches the frontend's response
 * server-side and streams it back through this authenticated, HTTPS-capable
 * LoxBerry page. The frontend's WebSocket (used for live device/state
 * updates) cannot be relayed by PHP, so HTML responses get a small shim that
 * redirects it to a dedicated WebSocket relay daemon instead (see
 * bin/wsproxy/wsproxy.js).
 */
class Z2mProxy
{
    const UPSTREAM_HOST = '127.0.0.1';
    const UPSTREAM_PORT = 8881;
    const WS_PROXY_PORT = 8882;

    /**
     * Fetches the resource matching the current request from the
     * Zigbee2MQTT frontend and streams the response back to the browser.
     */
    public static function handleRequest()
    {
        // Do not rely solely on PATH_INFO here.  Some Apache configurations
        // disable it for PHP files, which makes requests such as
        // ui.php/assets/index.js return 404 before this script is invoked.
        // Rewritten asset URLs therefore use _z2m_path as a portable route.
        $path = isset($_GET['_z2m_path']) ? $_GET['_z2m_path'] :
            (isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '/');
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            $path = '/';
        }

        $queryParams = $_GET;
        unset($queryParams['_z2m_path']);
        $query = count($queryParams) > 0
            ? '?' . http_build_query($queryParams)
            : '';
        $upstreamUrl = 'http://' . self::UPSTREAM_HOST . ':' . self::UPSTREAM_PORT . $path . $query;

        $ch = curl_init($upstreamUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => self::buildUpstreamHeaders(),
        ]);
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
        }

        $response = curl_exec($ch);
        if ($response === false) {
            http_response_code(502);
            header('Content-Type: text/plain');
            echo 'Zigbee2MQTT frontend is not reachable: ' . curl_error($ch);
            curl_close($ch);
            return;
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        $body = substr($response, $headerSize);
        $selfPath = $_SERVER['SCRIPT_NAME'];
        $assetProxyPath = dirname($selfPath) . '/ui-assets.php';

        $isTextResource = $contentType !== null && (
            stripos($contentType, 'text/html') !== false ||
            stripos($contentType, 'javascript') !== false ||
            stripos($contentType, 'text/css') !== false
        );
        if ($isTextResource) {
            $body = self::rewriteHtml($body, $assetProxyPath);
        }

        http_response_code($status);
        self::forwardHeaders(substr($response, 0, $headerSize), $assetProxyPath);
        if ($contentType) {
            header('Content-Type: ' . $contentType);
        }

        echo $body;
    }

    /**
     * Only a minimal, safe subset of the incoming request headers needs to
     * reach the upstream frontend.
     */
    private static function buildUpstreamHeaders()
    {
        $headers = [];
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['HTTP_ACCEPT'])) {
            $headers[] = 'Accept: ' . $_SERVER['HTTP_ACCEPT'];
        }
        return $headers;
    }

    /**
     * Re-emits the upstream response headers, skipping the ones curl/PHP
     * already manage themselves, and rewrites a root-absolute Location so
     * redirects stay inside the proxy.
     */
    private static function forwardHeaders($rawHeaders, $selfPath)
    {
        $skip = ['transfer-encoding', 'content-encoding', 'content-length', 'connection', 'content-type'];
        foreach (explode("\r\n", trim($rawHeaders)) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            list($name, $value) = explode(':', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (in_array(strtolower($name), $skip, true)) {
                continue;
            }
            if (strtolower($name) === 'location' && strpos($value, '/') === 0) {
                $value = $selfPath . '?_z2m_path=' . rawurlencode($value);
            }
            header($name . ': ' . $value, false);
        }
    }

    /**
     * Rewrites root-absolute asset references so they resolve back through
     * the dedicated asset proxy (instead of the LoxBerry web root), and injects a
     * WebSocket shim that redirects Z2M's live-update socket to the
     * dedicated wss:// relay daemon, since PHP itself cannot proxy it.
     */
    private static function rewriteHtml($html, $assetProxyPath)
    {
        // Query based routing works even when Apache has AcceptPathInfo off.
        // It is also used in JavaScript/CSS responses, where Vite may emit
        // additional root-absolute references for lazy-loaded chunks.
        $html = preg_replace_callback(
            '/(href|src)=("|\')\/(?!\/)([^"\']*)\2/i',
            function ($matches) use ($assetProxyPath) {
                return $matches[1] . '=' . $matches[2] . $assetProxyPath
                    . '?path=' . rawurlencode('/' . $matches[3]) . $matches[2];
            },
            $html
        );
        // Recent Zigbee2MQTT frontend builds may emit Vite assets relative
        // to the document (assets/index-*.js).  Without this rewrite the
        // browser looks for them in the plugin's own directory.
        $html = preg_replace_callback(
            '/(href|src)=("|\')(?:\.\/)?(assets\/[^"\']*)\2/i',
            function ($matches) use ($assetProxyPath) {
                return $matches[1] . '=' . $matches[2] . $assetProxyPath
                    . '?path=' . rawurlencode('/' . $matches[3]) . $matches[2];
            },
            $html
        );
        $html = preg_replace_callback(
            '/(["\'])\/(assets\/[^"\']*)\1/',
            function ($matches) use ($assetProxyPath) {
                return $matches[1] . $assetProxyPath . '?path='
                    . rawurlencode('/' . $matches[2]) . $matches[1];
            },
            $html
        );
        $html = preg_replace_callback(
            '/url\(\/(assets\/[^\)]*)\)/i',
            function ($matches) use ($assetProxyPath) {
                return 'url(' . $assetProxyPath . '?path='
                    . rawurlencode('/' . $matches[1]) . ')';
            },
            $html
        );

        $shim = '<script>(function(){'
            . 'var NativeWebSocket=window.WebSocket;'
            . 'window.WebSocket=function(url,protocols){'
            . 'var target="wss://"+window.location.hostname+":' . self::WS_PROXY_PORT . '/api";'
            . 'return protocols!==undefined?new NativeWebSocket(target,protocols):new NativeWebSocket(target);'
            . '};'
            . 'window.WebSocket.prototype=NativeWebSocket.prototype;'
            . '})();</script>';

        return preg_replace('/<head[^>]*>/i', '$0' . $shim, $html, 1);
    }
}
