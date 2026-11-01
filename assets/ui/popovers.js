import "../core/dom.js";
import "../core/lifecycle.js";
(function () {
  "use strict";
  var T = window.TinyCat, bindings = new WeakMap(), active = new Set(), restoringFocus = new WeakSet(), sequence = 0, initialized = false, queued = false;
  function parts(root) {
    if (!root) return null;
    var children = Array.from(root.children || []);
    var triggers = children.filter(function (node) { return node.matches("summary, [data-popover-trigger]"); });
    var panels = children.filter(function (node) { return node.matches(".popover-panel"); });
    return triggers.length === 1 && panels.length === 1 ? { trigger: triggers[0], panel: panels[0] } : null;
  }
  function isOpen(root) { return root.tagName === "DETAILS" ? root.open : root.dataset.popoverOpen === "true"; }
  function close(root, focus) {
    var pair = bindings.get(root) || parts(root);
    if (!pair) return;
    var notify = active.has(root) || isOpen(root) || root.dataset.popoverMode === "input";
    Array.from(active).forEach(function (child) { if (child !== root && root.contains(child)) close(child); });
    active.delete(root);
    if (pair.panel.hidePopover && pair.panel.matches(":popover-open")) pair.panel.hidePopover();
    if (root.tagName === "DETAILS") root.open = false;
    else { delete root.dataset.popoverOpen; pair.panel.hidden = true; }
    pair.trigger.setAttribute("aria-expanded", "false");
    if (focus && pair.trigger.isConnected) {
      restoringFocus.add(pair.trigger);
      try { pair.trigger.focus({ preventScroll: true }); } finally { restoringFocus.delete(pair.trigger); }
    }
    if (notify) root.dispatchEvent(new CustomEvent("tinycat:popover-close", { bubbles: true }));
  }
  function closeAll(except) {
    Array.from(active).forEach(function (root) { if (root !== except && !(except && root.contains(except))) close(root); });
  }
  function dismiss(except) {
    closeAll(except);
    document.querySelectorAll('[data-popover-mode="input"]').forEach(function (root) {
      if (root !== except && !(except && root.contains(except))) close(root);
    });
  }
  function position(root) {
    var pair = bindings.get(root) || parts(root);
    if (!pair || !isOpen(root)) return;
    var panel = pair.panel, viewport = window.visualViewport, scroll = panel.scrollTop;
    var left = (viewport ? viewport.offsetLeft : 0) + 12, top = (viewport ? viewport.offsetTop : 0) + 12;
    var right = left + (viewport ? viewport.width : window.innerWidth) - 24;
    var bottom = top + (viewport ? viewport.height : window.innerHeight) - 24;
    var body = root.closest(".modal-body"), clip = body && body.getBoundingClientRect();
    if (clip) { top = Math.max(top, clip.top + 4); bottom = Math.min(bottom, clip.bottom - 4); }
    panel.style.setProperty("--popover-available-width", Math.max(0, right - left) + "px");
    panel.style.maxHeight = "";
    panel.style.left = "0px"; panel.style.top = "0px";
    var anchor = pair.trigger.getBoundingClientRect(), bounds = panel.getBoundingClientRect();
    if (root.dataset.popoverMatchWidth === "true") {
      panel.style.width = Math.min(anchor.width, right - left) + "px";
      bounds = panel.getBoundingClientRect();
    }
    var below = Math.max(0, bottom - anchor.bottom - 4), above = Math.max(0, anchor.top - 4 - top);
    var upwards = bounds.height > below && above > below;
    panel.style.maxHeight = Math.floor(upwards ? above : below) + "px";
    bounds = panel.getBoundingClientRect();
    panel.style.left = Math.round(Math.max(left, Math.min(anchor.right - bounds.width, right - bounds.width))) + "px";
    panel.style.top = Math.round(Math.max(top, upwards ? anchor.top - 4 - bounds.height : Math.min(anchor.bottom + 4, bottom - bounds.height))) + "px";
    panel.scrollTop = scroll;
  }
  function open(root) {
    prepareRoot(root);
    var pair = bindings.get(root);
    if (!pair || !root.isConnected) return;
    dismiss(root);
    if (root.tagName === "DETAILS") root.open = true;
    else root.dataset.popoverOpen = "true";
    pair.panel.hidden = false;
    pair.panel.style.position = "fixed";
    if (pair.panel.showPopover && !pair.panel.matches(":popover-open")) pair.panel.showPopover();
    pair.trigger.setAttribute("aria-expanded", "true");
    active.add(root);
    position(root);
  }
  function actions(panel) {
    return Array.from(panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex="0"]')).filter(function (node) { return node.getClientRects().length > 0 && !node.matches(':disabled, [aria-disabled="true"]'); });
  }
  function prepareRoot(root) {
    if (!root || bindings.has(root)) return;
    var pair = parts(root);
    if (!pair) throw new TypeError("A popover requires one direct trigger and one .popover-panel.");
    bindings.set(root, pair);
    if (!pair.panel.id) {
      var id;
      do { id = "popover-panel-" + (++sequence); } while (document.getElementById(id));
      pair.panel.id = id;
    }
    if (pair.panel.showPopover) pair.panel.setAttribute("popover", "manual");
    pair.trigger.setAttribute("aria-controls", pair.panel.id);
    pair.trigger.setAttribute("aria-expanded", isOpen(root) ? "true" : "false");
    pair.trigger.addEventListener("click", function (event) {
      if (root.dataset.popoverMode === "input") return;
      if (event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || pair.trigger.target === "_blank") return;
      event.preventDefault();
      if (isOpen(root)) close(root); else open(root);
    });
    pair.trigger.addEventListener("keydown", function (event) {
      if (!["ArrowDown", "ArrowUp"].includes(event.key)) return;
      if (root.dataset.popoverMode === "input" && !isOpen(root)) return;
      event.preventDefault(); open(root);
      var nodes = actions(pair.panel), node = event.key === "ArrowUp" ? nodes[nodes.length - 1] : nodes[0];
      if (node) node.focus({ preventScroll: true });
    });
    pair.panel.addEventListener("keydown", function (event) {
      if (event.target.closest("input, select, textarea, [contenteditable]")) return;
      if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) return;
      var nodes = actions(pair.panel), index = nodes.indexOf(document.activeElement);
      if (!nodes.length) return;
      event.preventDefault();
      index = event.key === "Home" ? 0 : event.key === "End" ? nodes.length - 1 : (index + (event.key === "ArrowUp" ? -1 : 1) + nodes.length) % nodes.length;
      nodes[index].focus({ preventScroll: true });
    });
  }
  function prune() {
    Array.from(active).forEach(function (root) {
      if (!root.isConnected || root.closest('[hidden], [data-open="false"]')) close(root);
    });
  }
  function prepare(scope) {
    prune();
    if (scope.matches && scope.matches("[data-popover]")) prepareRoot(scope);
    scope.querySelectorAll("[data-popover]").forEach(function (root) {
      prepareRoot(root);
      if (isOpen(root)) open(root);
    });
  }
  function reposition() {
    if (queued || !active.size) return;
    queued = true;
    window.requestAnimationFrame(function () { queued = false; prune(); active.forEach(position); });
  }
  T.popovers = {
    open: open, close: close, closeAll: dismiss, position: position, prepare: prepare,
    restoringFocus: function (trigger) { return restoringFocus.has(trigger); },
    capture: function (source) {
      var root = source && source.closest && source.closest("[data-menu-key]");
      var pair = root && parts(root);
      return pair && isOpen(root) ? { key: root.dataset.menuKey, scroll: pair.panel.scrollTop, focus: actions(pair.panel).indexOf(document.activeElement) } : null;
    },
    restore: function (state) {
      if (!state) return;
      var root = Array.from(document.querySelectorAll("[data-menu-key]")).find(function (node) { return node.dataset.menuKey === state.key; });
      if (!root) return;
      open(root);
      var pair = parts(root), node = actions(pair.panel)[state.focus];
      pair.panel.scrollTop = state.scroll;
      if (node) node.focus({ preventScroll: true });
    },
    init: function () {
      prepare(document);
      if (initialized) return;
      initialized = true;
      document.addEventListener("click", function (event) {
        var root = event.target.closest && event.target.closest("[data-popover]");
        dismiss(root);
        if (root && event.target.closest("[data-popover-close]")) close(root);
      });
      document.addEventListener("focusin", function (event) { dismiss(event.target.closest && event.target.closest("[data-popover]")); });
      document.addEventListener("pointerdown", function (event) {
        var root = event.target.closest && event.target.closest("[data-popover]");
        dismiss(root);
      });
      document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape") return;
        var inputRoot = event.target.closest && event.target.closest('[data-popover-mode="input"]');
        if (!active.size && !inputRoot) return;
        event.preventDefault(); event.stopImmediatePropagation();
        close(Array.from(active).pop() || inputRoot, true);
      }, true);
      document.addEventListener("toggle", function (event) {
        var root = event.target;
        if (root.matches && root.matches(".popover-panel[popover]") && event.newState === "closed" && !root.matches(":popover-open") && isOpen(root.parentElement)) {
          close(root.parentElement); return;
        }
        if (!root.matches || !root.matches("details[data-popover]")) return;
        if (root.open) open(root); else close(root);
      }, true);
      document.addEventListener("tinycat:modal-open", function () { dismiss(); });
      document.addEventListener("tinycat:modal-close", function () { dismiss(); });
      document.addEventListener("scroll", function (event) {
        if (event.target.closest && event.target.closest(".popover-panel")) return;
        reposition();
      }, true);
      window.addEventListener("resize", reposition);
      document.addEventListener("load", reposition, true);
      if (window.visualViewport) {
        window.visualViewport.addEventListener("resize", reposition);
        window.visualViewport.addEventListener("scroll", reposition);
      }
      if (window.MutationObserver) new MutationObserver(function () { prune(); if (active.size) reposition(); }).observe(document.body, { childList: true, subtree: true });
    }
  };
  T.lifecycle.register("popovers", {
    init: T.popovers.init, hydrateOrder: 20, hydrate: prepare,
    dispose: function (scope) { Array.from(active).forEach(function (root) { if (root === scope || scope.contains(root)) close(root); }); }
  });
}());
