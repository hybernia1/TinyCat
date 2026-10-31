import "./dialog-history.js";
import "../core/components.js";
import "../core/dom.js";
import "../core/http.js";
import "../core/i18n.js";
import "../core/icons.js";
import "../core/lifecycle.js";
import "../core/modules.js";
import "./form-state.js";
import "./forms.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};

  TinyCat.dialog = {
    create: function (options) {
      if (!(options.content instanceof Node) || !/^[a-z][a-z0-9_-]*$/i.test(options.id)
        || typeof options.title !== "string" || !options.title.trim()
        || typeof options.closeLabel !== "string" || !options.closeLabel.trim()) {
        throw new TypeError("A dialog requires a stable ID, accessible labels and DOM content.");
      }
      var titleId = options.id + "-title";
      var root = TinyCat.components.render("ui/modal", {
        root_attributes: { id: options.id, "class": "modal", role: "dialog", "aria-modal": "true", "aria-hidden": "true", "aria-labelledby": titleId, "data-open": "false", "data-modal-kind": options.kind || "content", "data-modal-size": options.size || "default", "data-modal-mobile": options.mobile || null, "data-modal-dispose-on-close": options.disposeOnClose === false ? "false" : "true" },
        panel_attributes: { "class": "modal-panel", tabindex: "-1" },
        title_id: titleId, title: options.title, icon: options.icon || "", close_label: options.closeLabel,
        close_icon: options.closeIcon instanceof Node ? options.closeIcon : document.createTextNode("\u00d7"),
        body: options.content, body_class: "modal-body", has_footer: options.footer instanceof Node, footer: options.footer
      });
      document.body.appendChild(root);
      return root;
    }
  };
  var qs = TinyCat.dom.find, qsa = TinyCat.dom.all, createElement = TinyCat.dom.create, emit = TinyCat.dom.emit;
  var setLoading = TinyCat.forms.loading;
  var dirtyForms = TinyCat.forms.dirty, resetDiscardedForms = TinyCat.forms.discard;
  var activeModal = null, modalStack = [], modalPageLock = null, modalCounterId = 0, pending = new Map();
  function getModal(target) {
    if (!target) {
      return null;
    }

    if (target instanceof Element) {
      return target;
    }

    if (target.charAt(0) === "#" || target.charAt(0) === ".") {
      return qs(target);
    }

    return document.getElementById(target);
  }
  var modalFocusable = TinyCat.dom.focusables;
  function focusFirst(modal) { TinyCat.dom.focusFirst(modal, ".modal-panel"); }
  function trapModalFocus(event, modal) {
    var focusable = modalFocusable(modal);
    var first = focusable[0];
    var last = focusable[focusable.length - 1];
    var current = document.activeElement;

    if (focusable.length === 0) {
      event.preventDefault();
      focusFirst(modal);
      return;
    }

    if (!modal.contains(current)) {
      event.preventDefault();
      (event.shiftKey ? last : first).focus();
      return;
    }

    if (event.shiftKey && (current === first || current === qs(".modal-panel", modal))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && current === last) {
      event.preventDefault();
      first.focus();
    }
  }
  async function confirmModalClose(modal) {
    var forms = dirtyForms(modal);

    if (forms.length === 0) {
      return true;
    }

    return TinyCat.forms.confirmDiscard(forms);
  }
  function shouldDisposeModal(modal) {
    // Remote modals are a server-rendered snapshot. Keeping that snapshot
    // after the user discards a form would make the next open reuse stale
    // fields (and, for card-hosted modals, leave the form inside the card).
    return Boolean(modal && (modal.dataset.remoteLoaded === "true" || modal.dataset.modalDisposeOnClose === "true"));
  }
  function modalUsesVisualViewport(modal) {
    return Boolean(modal && modal.matches && modal.matches('[data-modal-kind="editor"], [data-modal-mobile="fullscreen"]'));
  }
  function modalNeedsMobilePageLock(modal) {
    return modalUsesVisualViewport(modal)
      && window.matchMedia
      && window.matchMedia("(max-width: 759px), (hover: none) and (pointer: coarse)").matches;
  }
  function lockPageForModal(modal) {
    var body;
    var scrollY;

    if (modalPageLock !== null || !modalNeedsMobilePageLock(modal)) {
      return;
    }

    body = document.body;
    scrollY = window.scrollY || window.pageYOffset || 0;
    modalPageLock = {
      scrollY: scrollY,
      position: body.style.position,
      top: body.style.top,
      left: body.style.left,
      right: body.style.right,
      width: body.style.width
    };

    body.style.position = "fixed";
    body.style.top = "-" + Math.round(scrollY) + "px";
    body.style.left = "0";
    body.style.right = "0";
    body.style.width = "100%";
  }
  function unlockPageForModal() {
    var body = document.body;
    var lock = modalPageLock;

    if (lock === null) {
      return;
    }

    body.style.position = lock.position;
    body.style.top = lock.top;
    body.style.left = lock.left;
    body.style.right = lock.right;
    body.style.width = lock.width;
    modalPageLock = null;

    window.requestAnimationFrame(function () {
      window.scrollTo(0, lock.scrollY);
    });
  }
  function syncModalVisualViewport(modal) {
    var viewport = window.visualViewport;

    if (!modalUsesVisualViewport(modal)) {
      return;
    }

    if (!viewport || viewport.width <= 0 || viewport.height <= 0) {
      modal.style.removeProperty("--tinycat-modal-viewport-left");
      modal.style.removeProperty("--tinycat-modal-viewport-top");
      modal.style.removeProperty("--tinycat-modal-viewport-width");
      modal.style.removeProperty("--tinycat-modal-viewport-height");
      return;
    }

    modal.style.setProperty("--tinycat-modal-viewport-left", Math.round(viewport.offsetLeft) + "px");
    modal.style.setProperty("--tinycat-modal-viewport-top", Math.round(viewport.offsetTop) + "px");
    modal.style.setProperty("--tinycat-modal-viewport-width", Math.round(viewport.width) + "px");
    modal.style.setProperty("--tinycat-modal-viewport-height", Math.round(viewport.height) + "px");
  }
  function syncOpenModalVisualViewports() {
    modalStack.forEach(syncModalVisualViewport);
  }
  function scheduleOpenModalViewportSync() {
    if (TinyCat.__modalViewportSyncQueued === true) {
      return;
    }

    TinyCat.__modalViewportSyncQueued = true;
    window.requestAnimationFrame(function () {
      TinyCat.__modalViewportSyncQueued = false;
      syncOpenModalVisualViewports();
    });
  }
  function scheduleModalViewportSettles() {
    [120, 360].forEach(function (delay) {
      window.setTimeout(function () {
        if (activeModal && modalUsesVisualViewport(activeModal)) {
          scheduleOpenModalViewportSync();
        }
      }, delay);
    });
  }
  TinyCat.dialog.open = function (target, options) {
    var modal = getModal(target);
    var index;
    var previousModal = activeModal;

    if (!modal) {
      return null;
    }

    if (modal.parentNode !== document.body) {
      document.body.appendChild(modal);
    }

    index = modalStack.indexOf(modal);
    if (index !== -1) {
      modalStack.splice(index, 1);
    }

    if (previousModal && previousModal !== modal) {
      previousModal.setAttribute("aria-hidden", "true");
    }

    modalStack.push(modal);
    activeModal = modal;
    modal.dataset.open = "true";
    modal.setAttribute("aria-hidden", "false");
    modal.__tinycatPreviousFocus = previousModal === modal ? modal.__tinycatPreviousFocus : document.activeElement;
    if (!options || options.history !== false) TinyCat.dialogHistory.opened(modal, options);
    document.body.classList.add("has-modal");
    lockPageForModal(modal);
    syncModalVisualViewport(modal);
    scheduleModalViewportSettles();
    focusFirst(modal);
    emit(modal, "tinycat:modal-open");

    return modal;
  };
  TinyCat.dialog.close = function (target, options) {
    var modal = getModal(target) || activeModal;
    var index;
    var wasActive;
    var previousFocus;
    var dispose;

    if (!modal) {
      return;
    }
    if (pending.has(modal.id)) { pending.get(modal.id).abort(); pending.delete(modal.id); }

    dispose = shouldDisposeModal(modal);
    wasActive = modal === activeModal;
    previousFocus = modal.__tinycatPreviousFocus;

    modal.dataset.open = "false";
    modal.setAttribute("aria-hidden", "true");
    modal.style.removeProperty("--tinycat-modal-viewport-left");
    modal.style.removeProperty("--tinycat-modal-viewport-top");
    modal.style.removeProperty("--tinycat-modal-viewport-width");
    modal.style.removeProperty("--tinycat-modal-viewport-height");

    index = modalStack.indexOf(modal);
    if (index !== -1) {
      modalStack.splice(index, 1);
    }

    activeModal = modalStack.length > 0 ? modalStack[modalStack.length - 1] : null;
    document.body.classList.toggle("has-modal", activeModal !== null);

    if (activeModal === null) {
      unlockPageForModal();
    }

    if (activeModal) {
      activeModal.setAttribute("aria-hidden", "false");
    }

    if (wasActive && previousFocus && previousFocus.isConnected && previousFocus.focus) {
      previousFocus.focus();
    } else if (wasActive && activeModal) {
      focusFirst(activeModal);
    }

    delete modal.__tinycatPreviousFocus;

    if (!options || options.history !== false) TinyCat.dialogHistory.closed(modal);
    emit(modal, "tinycat:modal-close");

    if (dispose && modal.parentNode) {
      TinyCat.lifecycle.dispose(modal);
      modal.remove();
    }
  };
  TinyCat.dialog.requestClose = async function (target, options) {
    var modal = getModal(target) || activeModal;
    var hasUnsavedChanges;

    if (!modal) {
      return false;
    }

    hasUnsavedChanges = dirtyForms(modal).length > 0;

    if (!await confirmModalClose(modal)) {
      return false;
    }

    if (options && options.current && !options.current()) return false;

    if (hasUnsavedChanges) {
      resetDiscardedForms(modal);
    }

    TinyCat.dialog.close(modal, options);
    return true;
  };
  async function loadRemoteModal(target, url, host, force, trigger) {
    var modal = getModal(target);
    var modalIndex;
    var data;

    if (!url) {
      return modal;
    }

    if (modal && modal.dataset.remoteLoaded === "true" && modal.dataset.modalUrl === url && force !== true) {
      return modal;
    }

    if (pending.has(target)) { pending.get(target).abort(); }
    var controller = new AbortController(); pending.set(target, controller);
    try { data = await TinyCat.api.request(url, { method: "GET", signal: controller.signal }); }
    finally { if (pending.get(target) === controller) { pending.delete(target); } }
    if (controller.signal.aborted) { throw new DOMException("Dialog request cancelled.", "AbortError"); }
    if (trigger && trigger.isConnected === false) { throw new DOMException("Dialog trigger removed.", "AbortError"); }
    var resourceModal = TinyCat.modules.modal(new URL(url, window.location.href), data, target);
    if (!resourceModal) { throw new Error(TinyCat.i18n.t("common.request_failed")); }

    if (modal) {
      modalIndex = modalStack.indexOf(modal);

      if (modalIndex !== -1) {
        modalStack.splice(modalIndex, 1);
      }

      if (activeModal === modal) {
        activeModal = modalStack.length > 0 ? modalStack[modalStack.length - 1] : null;
      }

      TinyCat.lifecycle.dispose(modal);
      modal.remove();
      document.body.classList.toggle("has-modal", activeModal !== null);
    }

    (host || document.body).appendChild(resourceModal);
    modal = getModal(target);

    if (!modal) {
      throw new Error("Modal was not found.");
    }

    modal.dataset.remoteLoaded = "true";
    modal.dataset.modalUrl = url;
    TinyCat.lifecycle.hydrate(modal);

    return modal;
  }
  TinyCat.dialog.cancelPending = function () { pending.forEach(function (controller) { controller.abort(); }); pending.clear(); };
  TinyCat.dialog.openResource = async function (id, url, options) {
    options = options || {};
    var modal = await loadRemoteModal(id, url, document.body, false);
    if (options.current && !options.current()) { TinyCat.lifecycle.dispose(modal); modal.remove(); return null; }
    var tab = qsa("[data-tab]", modal).find(function (item) { return item.dataset.tab === options.tab; });
    if (tab) TinyCat.tabs.activate(tab, false);
    return TinyCat.dialog.open(modal, options);
  };
  async function openRemoteModal(trigger, force) {
    var parent = trigger && trigger.closest ? trigger.closest("[data-modal-parent-open]") : null;
    var target = trigger ? (trigger.dataset.modalOpen || (parent ? parent.dataset.modalParentOpen : "")) : "";
    var url = trigger ? (trigger.dataset.modalUrl || (parent ? parent.dataset.modalParentUrl : "")) : "";
    var host = document.body;
    var modal;

    if (!url) {
      return TinyCat.dialog.open(target);
    }

    trigger.dataset.modalBusy = "true";
    setLoading(trigger, true);

    try {
      modal = await loadRemoteModal(target, url, host, force, trigger);
      return TinyCat.dialog.open(modal);
    } finally {
      delete trigger.dataset.modalBusy;
      setLoading(trigger, false);
    }
  }
  function modalAnchorId(trigger) {
    var href = trigger ? String(trigger.getAttribute("href") || "") : "";
    var hash = "";

    if (!href) {
      return "";
    }

    try {
      hash = decodeURIComponent(new URL(href, window.location.href).hash.slice(1));
    } catch (_error) {
      return "";
    }

    return /^[A-Za-z][A-Za-z0-9_:-]*$/.test(hash) ? hash : "";
  }
  function scrollModalToAnchor(modal, id) {
    var target;
    var scroller;
    var offset;

    if (!modal || !id) {
      return;
    }

    target = qsa("[id]", modal).find(function (element) {
      return element.id === id;
    });
    scroller = modalScrollElement(modal);

    if (!target || !scroller) {
      return;
    }

    window.requestAnimationFrame(function () {
      offset = target.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop;
      scroller.scrollTop = Math.max(0, offset - 12);
    });
  }
  TinyCat.dialog.confirm = function (options) {
    var settings = typeof options === "string" ? { message: options } : (options || {});
    var modal, backdrop, close;
    var message = createElement("p", "modal-confirm-message", settings.message || "");
    var footer = document.createDocumentFragment();
    var cancel = createElement("button", "btn btn-secondary", settings.cancelLabel || TinyCat.i18n.t("common.cancel"));
    var confirm = createElement("button", "btn " + (settings.variant === "danger" ? "btn-danger" : "btn-primary"), settings.confirmLabel || TinyCat.i18n.t("common.confirm"));
    return new Promise(function (resolve) {
      var settled = false;
      function finish(value) {
        if (settled) { return; } settled = true;
        document.removeEventListener("keydown", onKeydown, true);
        if (modal.dataset.open === "true") TinyCat.dialog.close(modal);
        resolve(value);
      }

      function onKeydown(event) {
        if (event.key === "Escape") {
          event.preventDefault();
          event.stopPropagation();
          finish(false);
        }
      }

      modalCounterId += 1;
      var modalId = "confirm-modal-" + modalCounterId;
      message.id = modalId + "-description";
      cancel.type = "button"; confirm.type = "button";
      footer.appendChild(cancel); footer.appendChild(confirm);
      modal = TinyCat.dialog.create({ id: modalId, title: settings.title || TinyCat.i18n.t("common.confirm_action"), closeLabel: TinyCat.i18n.t("common.close"), closeIcon: TinyCat.icons.create("close"), content: message, footer: footer, kind: "confirm", size: "confirm" });
      modal.setAttribute("aria-describedby", message.id);
      backdrop = qs(".modal-backdrop", modal); close = qs("[data-modal-close]", modal);
      modal.addEventListener("tinycat:modal-close", function () { finish(false); });
      backdrop.addEventListener("click", function (event) {
        event.preventDefault();
        event.stopPropagation();
        finish(false);
      });
      close.addEventListener("click", function (event) { event.stopPropagation(); finish(false); });
      cancel.addEventListener("click", function () { finish(false); });
      confirm.addEventListener("click", function () { finish(true); });
      document.addEventListener("keydown", onKeydown, true);

      TinyCat.dialog.open(modal);
      confirm.focus();
    });
  };
  TinyCat.dialog.init = function () {
    document.addEventListener("click", function (event) {
      var target = event.target;
      var open = target.closest && target.closest("[data-modal-open]");
      var modalScroll = target.closest && target.closest("[data-modal-scroll]");
      var close = target.closest && target.closest("[data-modal-close]");
      var anchorId;
      var statusUrl;

      if (open) {
        if (open.dataset.modalBusy === "true") { event.preventDefault(); return; }
        statusUrl = TinyCat.modules.navigationUrl(open);
        event.preventDefault();
        if (statusUrl) {
          window.location.assign(statusUrl);
          return;
        }
        anchorId = modalAnchorId(open);
        openRemoteModal(open, open.dataset.modalRefresh === "true")
          .then(function (modal) {
            scrollModalToAnchor(modal, anchorId);
          })
          .catch(function (error) {
            if (error.name === "AbortError") { return; }
            TinyCat.dom.emit(document, "tinycat:dialog-error", { error: error });
          });
        return;
      }

      if (modalScroll) {
        anchorId = modalAnchorId(modalScroll);

        if (anchorId && modalScroll.closest(".modal[data-open='true']")) {
          event.preventDefault();
          scrollModalToAnchor(modalScroll.closest(".modal[data-open='true']"), anchorId);
          return;
        }
      }

      if (close || (target.classList && target.classList.contains("modal-backdrop"))) {
        event.preventDefault();
        TinyCat.dialog.requestClose(close ? close.closest(".modal") : target.closest(".modal"));
      }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && activeModal) {
        event.preventDefault();
        TinyCat.dialog.requestClose(activeModal);
      } else if (event.key === "Tab" && activeModal) {
        trapModalFocus(event, activeModal);
      }
    });

    document.addEventListener("focusin", function (event) {
      if (activeModal && !activeModal.contains(event.target)) {
        focusFirst(activeModal);
      } else if (activeModal && modalUsesVisualViewport(activeModal)) {
        scheduleModalViewportSettles();
      }
    });

    if (window.visualViewport) {
      ["resize", "scroll"].forEach(function (eventName) {
        window.visualViewport.addEventListener(eventName, scheduleOpenModalViewportSync, { passive: true });
      });
    }

    window.addEventListener("orientationchange", scheduleOpenModalViewportSync, { passive: true });
    window.addEventListener("resize", scheduleOpenModalViewportSync, { passive: true });
  };
  function modalScrollElement(modal) {
    var body = qs(".modal-body", modal);
    var panel = qs(".modal-panel", modal);
    var bodyStyle;

    if (body && body.scrollHeight > body.clientHeight + 2) {
      bodyStyle = window.getComputedStyle ? window.getComputedStyle(body) : null;

      if (!bodyStyle || bodyStyle.overflowY === "auto" || bodyStyle.overflowY === "scroll") {
        return body;
      }
    }

    return panel || body || modal;
  }

  TinyCat.dialog.current = function () { return activeModal; };
  TinyCat.dialog.scrollElement = modalScrollElement;
  TinyCat.forms.confirmWith(TinyCat.dialog.confirm);
  TinyCat.lifecycle.register("dialogs", { init: TinyCat.dialog.init });
}());
