<?php
require_once "loxberry_system.php";
require_once "include/Z2mProxy.php";

// The Zigbee2MQTT web UI is a full single-page app served by Z2M's own
// frontend process. It used to be reached via an <iframe>/client-side
// redirect straight to that process's own, unauthenticated port; it is now
// reverse-proxied here instead, so it loads through LoxBerry's own
// authenticated, HTTPS-capable origin. Being a full HTML document in its own
// right, it is intentionally not wrapped in LoxBerry's header/footer chrome.
Z2mProxy::handleRequest();
