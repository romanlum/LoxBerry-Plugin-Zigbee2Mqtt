
/**
 * Fetches the form data from the backend
 * @param {string} name Name of the form
 */
function fetchFormData(name) {
    return new Promise((resolve, reject) => {
        const jqxhr = $.getJSON(`ajax.php/?action=getFormData&form=${name}`);
        jqxhr.done(function (data) {
            resolve(data);
        });

        jqxhr.fail(function (jqxhr, textStatus, error) {
            reject(error);
        });
    });
}

/**
 * updates the form data on the backend
 * @param {string} name Name of the form
 */
function updateFormData(name) {

    data = $(`#${name}`).serializeArray();
    /* Because serializeArray() ignores unset checkboxes and radio buttons: */
    const uncheckedItems = $(`#${name} input[type=checkbox]:not(:checked)`).map(
        function () {

            return {
                "name": this.name,
                "value": false
            }
        }).get();
    data = data.concat(uncheckedItems);

    return new Promise((resolve, reject) => {
        const jqxhr = $.post(`ajax.php/?action=setFormData&form=${name}`, data);
        jqxhr.done(function (data) {
            resolve(data);
        });

        jqxhr.fail(function (jqxhr, textStatus, error) {
            reject(error);
        });
    });
}

function applyChanges() {
    return new Promise((resolve, reject) => {
        const jqxhr = $.post(`ajax.php/?action=applyChanges`);
        jqxhr.done(function (data) {
            resolve(data);
        });

        jqxhr.fail(function (jqxhr, textStatus, error) {
            reject(error);
        });
    });
}

/**
 * Fetches the pid of the zigbee2mqtt service
 */
function getPid() {
    return new Promise((resolve, reject) => {
        const jqxhr = $.getJSON(`ajax.php/?action=getPid`);
        jqxhr.done(function (data) {
            if (data.pid != 0) {
                $("#servicepid").html(data.pid);
                $("#service_not_running").fadeOut();
                $("#service_running").fadeIn();
            }
            else {
                $("#service_not_running").fadeIn();
                $("#service_running").fadeOut();
            }

        });

        jqxhr.fail(function (jqxhr, textStatus, error) {
            $("#service_not_running").fadeIn();
            $("#service_running").fadeOut();
        });
    });
}

/**
 * Sets the form data
 * @param {string} name of the form
 * @param {object} data for the form values
 */
function setFormData(name, data) {
    Object.keys(data).forEach((key) => {
        try {
            let field = $(`#${name}\\[${key}\\]`);
            if (field !== 'undefined') {
                if (field.attr("type") !== "checkbox") {
                    field.val(data[key]);
                }
                else {
                    field.prop('checked', data[key]).checkboxradio('refresh');
                }
            }
        }
        catch (e) {
        }

    });
}

function saveAndApply() {

    $(".saveok").fadeOut();
    $(".saveerror").fadeOut();
    $(".submitting").fadeIn();

    const servicePromise = updateFormData("ServiceConfig");
    const mqttPromise = updateFormData("MqttConfig");

    Promise.all([servicePromise, mqttPromise]).then(function (values) {
        applyChanges().then(function (values) {
            $(".submitting").fadeOut();
            $(".saveok").fadeIn();
            getPid();
            location.reload();
        })
    })
        .catch(function (values) {
            $(".submitting").fadeOut();
            $(".saveerror").fadeIn();
        });


}

function viewhide() {
    if ($("#MqttConfig\\[usemqttgateway\\]").is(":checked")) {
        $(".ownbroker").fadeOut();
    } else {
        $(".ownbroker").fadeIn();
    }

}

/**
 * Known coordinators. Values from the vendor documentation; a preset only
 * fills the form fields, nothing is saved until "Save and apply".
 * SONOFF Dongle Max / Dongle-M: https://dongle.sonoff.tech/guide/dongle-m/donglem_connecting_to_zigbee2mqtt/
 */
const coordinatorPresets = {
    "dongle-m-net": { port: "tcp://Dongle-M.local:6638", adapter: "ember", baudrate: "115200", rtscts: "false" },
    "dongle-m-usb": { port: null, adapter: "ember", baudrate: "115200", rtscts: "false" }
};

/**
 * Fills port, adapter, baudrate and rtscts from the selected preset.
 * For USB the port is only kept if it already is a device path - the
 * device name differs per system, so it is not guessed.
 */
function applyPreset() {
    const preset = coordinatorPresets[$("#coordinatorPreset").val()];
    if (!preset) {
        return;
    }
    const port = $("#ServiceConfig\\[port\\]");
    if (preset.port !== null) {
        port.val(preset.port);
    } else if (!port.val().startsWith("/dev/")) {
        port.val("");
    }
    $("#ServiceConfig\\[adapter\\]").val(preset.adapter);
    $("#ServiceConfig\\[baudrate\\]").val(preset.baudrate);
    const rtscts = $("#ServiceConfig\\[rtscts\\]");
    rtscts.val(preset.rtscts);
    try { rtscts.selectmenu("refresh"); } catch (e) { }
    $("#testportresult").text("");
}

/**
 * Asks the backend whether the port in the form is reachable (tcp://) or
 * present (/dev/...). Tests the value in the form, not the saved one.
 */
function testPort() {
    const result = $("#testportresult");
    result.css("color", "grey").text("...");
    const jqxhr = $.post(`ajax.php/?action=testPort`, { port: $("#ServiceConfig\\[port\\]").val() }, null, "json");
    jqxhr.done(function (data) {
        let text = result.data(data.message) || data.message;
        if (data.ip) {
            text += " (" + data.ip + ")";
        }
        result.css("color", data.result ? "green" : "red").text(text);
    });
    jqxhr.fail(function () {
        result.css("color", "red").text("error");
    });
}

/**
 * Document ready function
 */
$(document).ready(function () {

    $("#saveapply").click(function () {
        saveAndApply();
    });
    $("#MqttConfig\\[usemqttgateway\\]").click(function () {
        viewhide();
    });
    $("#coordinatorPreset").change(function () {
        applyPreset();
    });
    $("#testport").click(function () {
        testPort();
    });

    fetchFormData("ServiceConfig")
        .then(data => {
            setFormData("ServiceConfig", data);
        });

    fetchFormData("MqttConfig")
        .then(data => {
            setFormData("MqttConfig", data);
            viewhide();
        });

    getPid();
    setInterval(function () { getPid(); }, 5000);

})

