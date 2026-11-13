# LoxBerry-Plugin-Zigbee2Mqtt

This plugin packages Zigbee2Mqtt for loxberry (https://www.zigbee2mqtt.io/) as a loxberry plugin.

## Running another radio daemon alongside this plugin

This plugin packages Zigbee2MQTT and nothing else. A Thread/Matter daemon
(matter.js, Matterbridge, python-matter-server, …) belongs in its own plugin —
the reasoning is in #133. The two can share a LoxBerry, provided the neighbour
honours three things this plugin already claims. None of them is discoverable
from the outside, so they are written down here.

**The MQTT base topic.** `config/mqtt.json`, key `topic`, default
`zigbee2mqtt`. `bin/update-config.php` writes it into Zigbee2MQTT's
`mqtt.base_topic`. A neighbour has to publish under a different base topic,
otherwise both sets of devices arrive mixed at the MQTT Gateway and the
Miniserver cannot tell them apart.

**TCP port 8881.** The Zigbee2MQTT frontend listens there. This is not a
setting: the number is written in `bin/update-config.php`
(`frontend.port`) and again in `config/zigbee2mqtt.service` and
`config/zigbee2mqttNode10.service` (`Z2M_ONBOARD_URL`). A neighbouring daemon
cannot find that out and must not take the port.

**The serial device.** `config/service.json`, key `port`, default
`/dev/ttyACM0`. That name is handed out in the order the kernel enumerates the
devices — a second USB radio can hold it after the next reboot, and the two
daemons then open each other's stick. Both sides should use a stable
`/dev/serial/by-id/...` path; see #132.
