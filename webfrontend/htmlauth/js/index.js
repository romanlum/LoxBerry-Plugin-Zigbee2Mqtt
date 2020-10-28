
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
 * Document ready function
 */
$(document).ready(function () {

    $("#saveapply").click(function () {
        saveAndApply();
    });
    $("#MqttConfig\\[usemqttgateway\\]").click(function () {
        viewhide();
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

