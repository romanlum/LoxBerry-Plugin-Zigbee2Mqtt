let pollTimer = null;

/**
 * Fetches installed/pinned version, available releases and the current
 * upgrade job state
 */
function fetchVersionInfo() {
    return new Promise((resolve, reject) => {
        const jqxhr = $.getJSON(`ajax.php/?action=getVersionInfo`);
        jqxhr.done(function (data) {
            resolve(data);
        });

        jqxhr.fail(function (jqxhr, textStatus, error) {
            reject(error);
        });
    });
}

/**
 * Starts a zigbee2mqtt upgrade to the given version
 */
function startUpgrade(version) {
    return new Promise((resolve, reject) => {
        const jqxhr = $.post(`ajax.php/?action=startUpgrade`, { version: version });
        jqxhr.done(function (data) {
            resolve(data);
        });

        jqxhr.fail(function (jqxhr, textStatus, error) {
            reject(jqxhr.responseJSON || error);
        });
    });
}

/**
 * Fetches the current upgrade job state (status + log tail)
 */
function fetchUpgradeStatus() {
    return new Promise((resolve, reject) => {
        const jqxhr = $.getJSON(`ajax.php/?action=getUpgradeStatus`);
        jqxhr.done(function (data) {
            resolve(data);
        });

        jqxhr.fail(function (jqxhr, textStatus, error) {
            reject(error);
        });
    });
}

function setBusy(busy) {
    $("#upgradebtn").prop("disabled", busy);
    $("#targetVersion").prop("disabled", busy);
    if (busy) {
        $(".saveok").fadeOut();
        $(".saveerror").fadeOut();
        $(".submitting").fadeIn();
        $("#upgradelog").show();
    } else {
        $(".submitting").fadeOut();
    }
}

function appendLog(text) {
    const $log = $("#upgradelog");
    $log.text(text || "");
    $log.scrollTop($log[0].scrollHeight);
}

function pollUpgradeStatus() {
    clearTimeout(pollTimer);
    fetchUpgradeStatus().then(function (state) {
        appendLog(state.log);

        if (state.status === "running") {
            pollTimer = setTimeout(pollUpgradeStatus, 2000);
            return;
        }

        setBusy(false);
        if (state.status === "success") {
            $(".saveok").fadeIn();
            fetchVersionInfo().then(populateVersionInfo);
        } else if (state.status === "failed") {
            if (state.message) {
                $(".saveerror").text(state.message);
            }
            $(".saveerror").fadeIn();
        }
    }).catch(function () {
        // Transient network hiccup while polling - keep trying
        pollTimer = setTimeout(pollUpgradeStatus, 2000);
    });
}

function populateVersionInfo(data) {
    $("#installedVersion").text(data.installedVersion || "-");

    const $select = $("#targetVersion");
    $select.empty();
    (data.releases || []).forEach(function (version) {
        $select.append($("<option>").val(version).text(version));
    });

    const preselect = data.pinnedVersion || (data.releases && data.releases[0]) || "";
    if (preselect) {
        $select.val(preselect);
    }
    if ($.fn.selectmenu) {
        $select.selectmenu("refresh");
    }

    if (data.upgrade && data.upgrade.status === "running") {
        setBusy(true);
        pollUpgradeStatus();
    }
}

$(document).ready(function () {

    $("#upgradebtn").click(function () {
        const version = $("#targetVersion").val();
        if (!version) {
            return;
        }
        if (!confirm($("#upgradebtn").data("confirm"))) {
            return;
        }

        setBusy(true);
        startUpgrade(version).then(function () {
            pollUpgradeStatus();
        }).catch(function (err) {
            setBusy(false);
            if (err && err.error) {
                $(".saveerror").text(err.error);
            }
            $(".saveerror").fadeIn();
        });
    });

    fetchVersionInfo().then(populateVersionInfo);

})
