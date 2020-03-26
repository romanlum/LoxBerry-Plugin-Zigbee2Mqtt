
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
    console.log(data);

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
    updateFormData("ServiceConfig");
}

/**
 * Document ready function
 */
$(document).ready(function () {

    $("#saveapply").click(function () {
        saveAndApply();
    });

    fetchFormData("ServiceConfig")
        .then(data => {
            setFormData("ServiceConfig", data);
        })

})

