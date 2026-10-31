import "../core/lifecycle.js";
import "../core/i18n.js";
import "../core/icons.js";
import "./menus.js";
(function () {
  "use strict";
  var count = 0, sorting = new WeakMap();
  function sortControl(table, headings, labels, wrapper) {
    var links = headings.map(function (heading) { return heading.querySelector(".table-sort"); });
    var signature = JSON.stringify(links.map(function (link, index) { return link && [link.getAttribute("href"), link.dataset.history, link.dataset.ajaxTarget, headings[index].getAttribute("aria-sort"), labels[index]]; }));
    var old = sorting.get(table);
    if (old && old.control.isConnected && old.signature === signature) return;
    if (old) { old.control.remove(); sorting.delete(table); }
    if (!links.some(Boolean)) { delete table.dataset.mobileSort; return; }
    var parent = wrapper ? wrapper.parentElement : table.parentElement;
    if (!parent) return;
    var control = document.createElement("details"), summary = document.createElement("summary"), options = document.createElement("div");
    control.className = "popover-menu table-mobile-sort"; control.setAttribute("data-dismissible-menu", ""); control.setAttribute("data-list-menu", "");
    control.dataset.menuKey = "sort:" + (links.find(Boolean).dataset.ajaxTarget || table.id || table.dataset.mobileTableId);
    summary.className = "btn btn-ghost btn-sm";
    var activeIndex = headings.findIndex(function (heading) { return ["ascending", "descending"].includes(heading.getAttribute("aria-sort")); });
    summary.appendChild(window.TinyCat.icons.create(activeIndex >= 0 && headings[activeIndex].getAttribute("aria-sort") === "descending" ? "arrow-down" : "arrow-up"));
    if (activeIndex >= 0) summary.className = "btn btn-secondary btn-sm";
    var label = document.createElement("span"); label.textContent = window.TinyCat.i18n.t("common.sort"); summary.appendChild(label);
    options.className = "context-menu-popover list-menu-panel table-sort-options";
    links.forEach(function (link, index) {
      if (!link) return;
      var active = ["ascending", "descending"].includes(headings[index].getAttribute("aria-sort"));
      var option = link.cloneNode(true);
      option.className = "btn btn-sm table-sort-option " + (active ? "btn-secondary" : "btn-ghost");
      if (active) {
        option.setAttribute("aria-current", "true");
      }
      options.appendChild(option);
    });
    control.append(summary, options);
    window.TinyCat.menus.prepare(control);
    var actions = parent.querySelector(".admin-filter-actions");
    if (actions) actions.insertBefore(control, actions.querySelector(".admin-per-page-form"));
    else parent.insertBefore(control, wrapper || table);
    table.dataset.mobileSort = "";
    sorting.set(table, { control: control, signature: signature });
  }
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
    sortControl(table, headings, labels, wrapper);
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
