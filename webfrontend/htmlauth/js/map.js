const mapColors = {
    Coordinator: "#1565c0",
    Router: "#6dac20",
    EndDevice: "#90a4ae"
};

let network = null;

/**
 * Colour of a link by its link quality (0..255).
 */
function lqiColor(lqi) {
    if (lqi >= 100) {
        return "#6dac20";
    }
    if (lqi >= 50) {
        return "#e0620d";
    }
    return "#c62828";
}

/**
 * Draws the map. Links that were reported by both ends are drawn once,
 * with the better of the two link qualities.
 */
function drawMap(data) {
    const nodes = data.nodes.map(function (node) {
        const failed = node.failed.length > 0;
        return {
            id: node.id,
            label: node.name,
            title: node.id + " (" + node.type + ")" + (failed ? " - failed: " + node.failed.join(", ") : ""),
            shape: node.type === "Coordinator" ? "box" : "dot",
            size: node.type === "EndDevice" ? 10 : 16,
            color: {
                background: mapColors[node.type] || "#90a4ae",
                border: failed ? "#c62828" : (mapColors[node.type] || "#90a4ae")
            },
            borderWidth: failed ? 3 : 1,
            font: { color: node.type === "Coordinator" ? "#ffffff" : "#333333" }
        };
    });

    const names = {};
    data.nodes.forEach(function (node) {
        names[node.id] = node.name;
    });

    const pairs = {};
    data.links.forEach(function (link) {
        const key = [link.source, link.target].sort().join("|");
        if (!pairs[key] || pairs[key].lqi < link.lqi) {
            pairs[key] = link;
        }
    });
    const links = Object.keys(pairs).map(function (key) {
        return pairs[key];
    });

    const edges = links.map(function (link) {
        return {
            from: link.source,
            to: link.target,
            label: String(link.lqi),
            color: { color: lqiColor(link.lqi) },
            width: link.lqi >= 100 ? 3 : (link.lqi >= 50 ? 2 : 1),
            font: { size: 11, align: "middle" }
        };
    });

    $("#devicemap").show();
    const options = {
        autoResize: true,
        physics: {
            solver: "repulsion",
            repulsion: { nodeDistance: 180 },
            stabilization: { enabled: true, iterations: 200 }
        },
        interaction: { hover: true }
    };
    if (network !== null) {
        network.destroy();
    }
    network = new vis.Network(document.getElementById("devicemap"), { nodes: nodes, edges: edges }, options);

    // the same links as a table, sorted from weakest to strongest
    const body = $("#maplinks tbody").empty();
    links.sort(function (a, b) {
        return a.lqi - b.lqi;
    }).forEach(function (link) {
        $("<tr>")
            .append($("<td>").text(names[link.source] || link.source))
            .append($("<td>").text(names[link.target] || link.target))
            .append($("<td>").css("color", lqiColor(link.lqi)).text(link.lqi))
            .appendTo(body);
    });
    $("#maplinks").show();
}

/**
 * Asks the page for a new scan and draws the result.
 */
function scanNetwork() {
    const status = $("#mapstatus");
    $("#mapscan").prop("disabled", true);
    status.css("color", "grey").text(status.data("scanning"));
    const jqxhr = $.ajax({ type: "POST", url: "map.php", data: { scan: 1 }, dataType: "json", timeout: 160000 });
    jqxhr.done(function (data) {
        if (data.result) {
            status.css("color", "green").text(status.data("done") + " " + data.time + ": " + data.nodes.length + " " + status.data("devices") + ", " + data.links.length + " " + status.data("links"));
            drawMap(data);
        } else {
            status.css("color", "red").text((status.data(data.message) || data.message) + (data.error ? " " + data.error : ""));
        }
    });
    jqxhr.fail(function () {
        status.css("color", "red").text("error");
    });
    jqxhr.always(function () {
        $("#mapscan").prop("disabled", false);
    });
}

$(document).ready(function () {
    $("#mapscan").click(function () {
        scanNetwork();
    });
});
