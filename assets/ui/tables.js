import "../core/lifecycle.js";
(function () {
  "use strict";
  var count = 0;
  function prepare(table) {
    if (table.dataset.tableLayout === "scroll" || !table.tHead || table.tHead.rows.length !== 1) return;
    var headings = Array.from(table.tHead.rows[0].cells), rows = [];
    Array.from(table.tBodies).forEach(function (body) { rows.push.apply(rows, Array.from(body.rows)); });
    if (headings.length < 2 || headings.some(function (cell) { return cell.colSpan > 1 || cell.rowSpan > 1; }) || rows.some(function (row) {
      return !(row.cells.length === 1 && row.cells[0].colSpan === headings.length) && (row.cells.length !== headings.length || Array.from(row.cells).some(function (cell) { return cell.colSpan > 1 || cell.rowSpan > 1; }));
    })) return;
    var id = table.id || table.dataset.mobileTableId || "tinycat-table-" + ++count;
    table.dataset.mobileTableId = id;
    table.setAttribute("role", "table"); table.tHead.setAttribute("role", "rowgroup");
    table.tHead.rows[0].setAttribute("role", "row");
    var labels = headings.map(function (heading, index) {
      if (!heading.id) heading.id = id + "-heading-" + index;
      heading.setAttribute("scope", "col"); heading.setAttribute("role", "columnheader");
      var clone = heading.cloneNode(true);
      clone.querySelectorAll('[aria-hidden="true"]').forEach(function (node) { node.remove(); });
      return clone.textContent.trim().replace(/\s+/g, " ");
    });
    Array.from(table.tBodies).forEach(function (body) { body.setAttribute("role", "rowgroup"); });
    rows.forEach(function (row) {
      row.setAttribute("role", "row");
      Array.from(row.cells).forEach(function (cell, index) {
        cell.setAttribute("role", "cell");
        if (cell.colSpan > 1) return;
        if (!cell.hasAttribute("headers")) cell.setAttribute("headers", headings[index].id);
        if (index === 0) { cell.classList.add("table-identity-cell"); return; }
        if (cell.classList.contains("table-actions-cell")) return;
        var label = cell.querySelector(":scope > .table-cell-label");
        if (!label) {
          label = document.createElement("span"); label.className = "table-cell-label"; label.setAttribute("aria-hidden", "true");
          var value = document.createElement("div"); value.className = "table-cell-value";
          while (cell.firstChild) value.appendChild(cell.firstChild);
          cell.appendChild(label); cell.appendChild(value);
        }
        label.textContent = labels[index];
      });
    });
    table.dataset.mobileCards = "";
    var wrapper = table.closest(".table-wrap");
    if (wrapper) wrapper.dataset.mobileTable = "";
  }
  window.TinyCat.lifecycle.register("tables", {
    hydrateOrder: 10,
    hydrate: function (root) {
      var table = root.closest && root.closest("table.table");
      if (table) prepare(table);
      root.querySelectorAll("table.table").forEach(prepare);
    }
  });
}());
