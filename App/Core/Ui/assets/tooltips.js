import "./runtime/lifecycle.js";
(function () {
  "use strict";
  var source = null, tip = null, opening = null, closing = null, helpSequence = 0;
  function uniqueId(prefix) { var id; do { id = prefix + (++helpSequence); } while (document.getElementById(id)); return id; }
  function describe(element, id) { var ids = (element.getAttribute("aria-describedby") || "").split(/\s+/).filter(Boolean); if (!ids.includes(id)) ids.push(id); element.setAttribute("aria-describedby", ids.join(" ")); }
  function expanded(element) { return source === element && tip && !tip.hidden; }
  function toggle(element) { element = prepare(element); if (!element) return; if (expanded(element)) hide(); else show(element); }
  function prepare(element) {
    if (element.matches("iframe, svg, link, style")) return null;
    if (element.hasAttribute("title")) {
      element.dataset.tooltip = element.getAttribute("title");
      element.removeAttribute("title");
    }
    if (element.hasAttribute("data-help")) {
      var description = element.querySelector("[data-help-text]"), field = element.closest(".field, label");
      if (description) {
        if (!description.id) description.id = uniqueId("tinycat-help-text-");
        element.dataset.tooltip = description.textContent;
        if (field) {
          var caption = field.querySelector(".label");
          var label = caption && caption.firstChild && caption.firstChild.nodeType === 3 ? caption.firstChild.textContent.trim() : "";
          field.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(function (control) {
            if (control.closest(".field") !== field && control.closest("label") !== field) return;
            describe(control, description.id);
            if (field.tagName === "LABEL" && label && !control.hasAttribute("aria-label") && !control.hasAttribute("aria-labelledby")) control.setAttribute("aria-label", label);
          });
        }
      }
    }
    if (!element.dataset.tooltip) return null;
    if (element.matches('button, a[href], input, [role="img"]') && !element.hasAttribute("aria-label") && !element.hasAttribute("aria-labelledby") && !element.textContent.trim()) {
      element.setAttribute("aria-label", element.dataset.tooltip);
    }
    if (element.matches(".table-primary, .table-text, .table-text-url, .table-identity-body > .table-meta")) {
      var clipped = element.scrollHeight > element.clientHeight + 1 || element.scrollWidth > element.clientWidth + 1;
      if (clipped && !element.matches("a[href], button, input, select, textarea, [tabindex]")) {
        element.setAttribute("tabindex", "0"); element.dataset.tooltipFocusable = "";
      } else if (!clipped && element.hasAttribute("data-tooltip-focusable")) {
        element.removeAttribute("tabindex"); element.removeAttribute("data-tooltip-focusable");
      }
      if (!clipped && element.textContent.trim() === element.dataset.tooltip.trim()) return null;
    }
    return element;
  }
  function trigger(target) {
    var element = target && target.closest && target.closest("[data-tooltip], [title], [data-help]");
    return element ? prepare(element) : null;
  }
  function hide() {
    window.clearTimeout(opening); window.clearTimeout(closing);
    if (source && source.hasAttribute("data-help")) {
      source.setAttribute("aria-expanded", "false"); source.removeAttribute("aria-controls");
    }
    if (source && tip) {
      var ids = (source.getAttribute("aria-describedby") || "").split(/\s+/).filter(function (id) { return id && id !== tip.id; });
      if (ids.length) source.setAttribute("aria-describedby", ids.join(" ")); else source.removeAttribute("aria-describedby");
    }
    if (tip) {
      if (tip.hidePopover && tip.matches(":popover-open")) tip.hidePopover();
      tip.hidden = true;
    }
    source = null;
  }
  function show(element) {
    hide();
    if (!element.isConnected || !element.getClientRects().length || element.closest('[hidden], [aria-hidden="true"]')) return;
    source = element;
    if (!tip) {
      tip = document.createElement("div"); tip.className = "tooltip";
      tip.id = uniqueId("tinycat-tooltip-");
      tip.setAttribute("role", "tooltip"); tip.setAttribute("popover", "manual");
    }
    (source.closest(".modal") || document.body).appendChild(tip);
    tip.textContent = source.dataset.tooltip;
    if (source.hasAttribute("data-help")) { source.setAttribute("aria-expanded", "true"); source.setAttribute("aria-controls", tip.id); }
    tip.hidden = false;
    describe(source, tip.id);
    if (tip.showPopover) tip.showPopover();
    var viewport = window.visualViewport;
    var left = viewport ? viewport.offsetLeft : 0, top = viewport ? viewport.offsetTop : 0;
    var right = left + (viewport ? viewport.width : window.innerWidth), bottom = top + (viewport ? viewport.height : window.innerHeight);
    tip.style.setProperty("--tooltip-available-width", right - left - 24 + "px");
    tip.style.maxHeight = bottom - top - 24 + "px";
    var anchor = source.getBoundingClientRect(), bounds = tip.getBoundingClientRect();
    tip.style.left = Math.max(left + 12, Math.min(anchor.left, right - bounds.width - 12)) + "px";
    tip.style.top = Math.max(top + 12, Math.min(anchor.bottom + 8 + bounds.height <= bottom - 12 ? anchor.bottom + 8 : anchor.top - bounds.height - 8, bottom - bounds.height - 12)) + "px";
  }
  function hydrate(root) {
    if (root.matches && root.matches("[title], [data-tooltip], [data-help]")) prepare(root);
    root.querySelectorAll("[title], [data-tooltip], [data-help]").forEach(prepare);
  }
  window.TinyCat.lifecycle.register("tooltips", {
    hydrateOrder: 20,
    hydrate: hydrate,
    dispose: function (root) { if (source && (root === source || root.contains(source))) hide(); },
    init: function () {
      document.addEventListener("pointerover", function (event) {
        if (tip && tip.contains(event.target)) { window.clearTimeout(closing); return; }
        var element = trigger(event.target);
        if (!element || event.pointerType === "touch") return;
        if (element === source) { window.clearTimeout(closing); return; }
        hide(); source = element;
        opening = window.setTimeout(function () { show(element); }, 300);
      });
      document.addEventListener("pointerout", function (event) {
        if (!source || (event.relatedTarget && (source.contains(event.relatedTarget) || (tip && tip.contains(event.relatedTarget)))) || source.contains(document.activeElement)) return;
        closing = window.setTimeout(hide, 150);
      });
      document.addEventListener("focusin", function (event) { var element = trigger(event.target); if (element) show(element); });
      document.addEventListener("focusout", function (event) {
        if (source && !(event.relatedTarget && source.contains(event.relatedTarget)) && !source.matches(":hover")) hide();
      });
      document.addEventListener("pointerdown", function (event) {
        var element = trigger(event.target);
        if (element && element.hasAttribute("data-help")) {
          event.preventDefault();
          var wasExpanded = expanded(element);
          element.focus({ preventScroll: true });
          if (wasExpanded) hide(); else show(element);
          return;
        }
        if (event.pointerType === "touch" && element) { if (source === element && tip && !tip.hidden) hide(); else show(element); }
        else if (!tip || !tip.contains(event.target)) hide();
      });
      document.addEventListener("click", function (event) {
        var element = event.target.closest && event.target.closest("[data-help]");
        if (!element) return;
        event.preventDefault();
        if (event.detail === 0) toggle(element);
      });
      document.addEventListener("keydown", function (event) {
        var element = event.target.closest && event.target.closest("[data-help]");
        if (element && (event.key === "Enter" || event.key === " ")) {
          event.preventDefault(); event.stopPropagation();
          if (!event.repeat) toggle(element);
          return;
        }
        if (event.key === "Escape" && source && tip && !tip.hidden) {
          hide(); event.stopImmediatePropagation(); event.preventDefault();
        }
      }, true);
      document.addEventListener("scroll", function (event) { if (!tip || !tip.contains(event.target)) hide(); }, true);
      function reveal(event) { hide(); hydrate(event.target); }
      document.addEventListener("tinycat:modal-open", reveal);
      document.addEventListener("tinycat:modal-close", hide);
      document.addEventListener("tinycat:tab", reveal);
      window.addEventListener("resize", function () { hide(); hydrate(document); });
      if (window.visualViewport) window.visualViewport.addEventListener("resize", hide);
    }
  });
}());
