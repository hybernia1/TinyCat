import "../../core/dom.js";
import "../../core/http.js";
import "../../core/i18n.js";
import "../../core/lifecycle.js";
import "../../core/navigation.js";
import "../notifications/views.js";
import "./views.js";
import "../../ui/dialogs.js";
import "../../ui/fields.js";
import "../../ui/toasts.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var qs = TinyCat.dom.find;
  var qsa = TinyCat.dom.all;
  var hydrateDynamic = TinyCat.lifecycle.hydrate;
  var compactUrl = TinyCat.navigation.compact;
  var uiText = TinyCat.i18n.label;
  var unwrapResult = TinyCat.api.unwrap;
  var statusFeedMaxCards = 120;
  var statusFeedKeepCards = 96;
  var statusFeedPruneMargin = 700;
  var statusFeedLastScrollY = window.scrollY || 0;
  var statusFeedScrollQueued = false;
  var statusFeedRequests = new WeakMap();
  var statusFeedAutoLoadQueued = new WeakMap();
  function statusFeedPayload(data) {
    var payload = Object.assign({}, unwrapResult(data) || {});
    var pagination = TinyCat.ui.pagination(data);
    payload.next_url = pagination.next_url || "";
    payload.done = pagination.done;
    payload.count = Array.isArray(payload.items) ? payload.items.length : 0;
    return payload;
  }
  function statusFeedFragment(target, items) {
    if (target.dataset.feedComponent) { return { content: TinyCat.components.render(target.dataset.feedComponent, { items: items }) }; }
    return target.id === "notifications-list" ? { content: TinyCat.notifications.items(items, false) } : TinyCat.status.fragment(items);
  }
  function statusFeedTarget(control) {
    var selector = control ? control.dataset.statusFeedTarget : "";

    return selector ? qs(selector) : null;
  }
  function statusFeedNearViewport(control) {
    var rect;

    if (!control || control.hidden) {
      return false;
    }

    rect = control.getBoundingClientRect();

    return rect.top < window.innerHeight + 400;
  }
  function statusFeedCards(target) {
    return Array.prototype.slice.call(target ? target.children : []).filter(function (node) {
      return node.classList && (node.classList.contains("status-card") || node.hasAttribute("data-feed-item"));
    });
  }
  function statusFeedGap(target) {
    var style = window.getComputedStyle ? window.getComputedStyle(target) : null;
    var value = style ? parseFloat(style.rowGap || style.gap || "0") : 0;

    return Number.isFinite(value) ? value : 0;
  }
  function statusFeedSpacer(target) {
    var spacer = qs("[data-status-feed-spacer]", target);

    if (spacer) {
      return spacer;
    }

    spacer = document.createElement("div");
    spacer.className = "status-feed-spacer";
    spacer.setAttribute("aria-hidden", "true");
    spacer.dataset.statusFeedSpacer = "true";
    target.insertBefore(spacer, target.firstChild);

    return spacer;
  }
  function statusFeedSpacerHeight(target) {
    var spacer = qs("[data-status-feed-spacer]", target);

    return spacer ? Math.max(0, parseFloat(spacer.dataset.height || spacer.style.height || "0") || 0) : 0;
  }
  function setStatusFeedSpacerHeight(target, height) {
    var spacer = statusFeedSpacer(target);
    var value = Math.max(0, Math.round(height));

    spacer.dataset.height = String(value);
    spacer.style.height = value + "px";
    spacer.hidden = value < 1;
  }
  function statusFeedFirstPageUrl(url) {
    var next = new URL(url || window.location.href, window.location.href);

    next.searchParams.delete("offset");
    next.searchParams.delete("cursor_at");
    next.searchParams.delete("cursor_id");
    next.searchParams.delete("cursor_rank");
    next.searchParams.delete("cursor_name");

    return compactUrl(next);
  }
  function statusFeedControlForTarget(target) {
    var controls = qsa("[data-status-feed-more]");
    var i;

    for (i = 0; i < controls.length; i += 1) {
      if (statusFeedTarget(controls[i]) === target) {
        return controls[i];
      }
    }

    return null;
  }
  function statusFeedStartRequest(target, type) {
    var request;

    if (!target || statusFeedRequests.has(target)) {
      return null;
    }

    request = { type: type };
    statusFeedRequests.set(target, request);

    return request;
  }
  function statusFeedRequestActive(target, request) {
    return statusFeedRequests.get(target) === request;
  }
  function statusFeedFinishRequest(target, request) {
    if (statusFeedRequestActive(target, request)) {
      statusFeedRequests.delete(target);
    }
  }
  function appendStatusFeedItems(target, template) {
    var seen = {};

    qsa(".status-card[data-status-id], [data-feed-item][data-feed-id]", target).forEach(function (card) {
      var id = String(card.dataset.statusId || card.dataset.feedId || "");

      if (id) {
        seen[id] = true;
      }
    });

    qsa(".status-card[data-status-id], [data-feed-item][data-feed-id]", template.content).forEach(function (card) {
      var id = String(card.dataset.statusId || card.dataset.feedId || "");

      if (id && seen[id]) {
        card.remove();
        return;
      }

      if (id) {
        seen[id] = true;
      }
    });

    target.appendChild(template.content);
  }
  function queueStatusFeedMore(control) {
    var target = statusFeedTarget(control);

    if (!control || !target || !control.isConnected || control.hidden || statusFeedAutoLoadQueued.has(target)) {
      return;
    }

    statusFeedAutoLoadQueued.set(target, true);

    window.setTimeout(function () {
      statusFeedAutoLoadQueued.delete(target);

      if (
        control.isConnected
        && statusFeedControlForTarget(target) === control
        && statusFeedNearViewport(control)
      ) {
        loadStatusFeedMore(control);
      }
    }, 120);
  }
  function statusFeedCardProtected(card) {
    return Boolean(
      card.contains(document.activeElement)
        || qs(".modal[data-open='true'], [data-status-busy='true'], [data-modal-busy='true']", card)
    );
  }
  function pruneStatusFeed(target, refreshUrl) {
    var cards = statusFeedCards(target);
    var excess = cards.length - statusFeedKeepCards;
    var gap;
    var currentSpacerHeight;
    var remove = [];
    var removedHeight = 0;
    var anchor;
    var beforeTop;
    var afterTop;

    if (!target || cards.length <= statusFeedMaxCards || excess < 1) {
      return;
    }

    cards.some(function (card) {
      var rect = card.getBoundingClientRect();

      if (remove.length >= excess || rect.bottom > -statusFeedPruneMargin || statusFeedCardProtected(card)) {
        return true;
      }

      remove.push(card);
      removedHeight += rect.height;

      return false;
    });

    if (remove.length < 1) {
      return;
    }

    gap = statusFeedGap(target);
    currentSpacerHeight = statusFeedSpacerHeight(target);
    anchor = cards[remove.length] || null;
    beforeTop = anchor ? anchor.getBoundingClientRect().top : 0;
    setStatusFeedSpacerHeight(
      target,
      currentSpacerHeight + removedHeight + gap * (currentSpacerHeight > 0 ? remove.length : Math.max(0, remove.length - 1))
    );
    target.dataset.statusFeedPruned = "true";

    if (refreshUrl) {
      target.dataset.statusFeedRefreshUrl = statusFeedFirstPageUrl(refreshUrl);
    }

    remove.forEach(function (card) {
      card.remove();
    });

    if (anchor) {
      afterTop = anchor.getBoundingClientRect().top;

      if (Math.abs(afterTop - beforeTop) > 1) {
        window.scrollBy(0, afterTop - beforeTop);
      }
    }
  }
  async function refreshPrunedStatusFeed(target) {
    var control;
    var url = target ? target.dataset.statusFeedRefreshUrl : "";
    var data;
    var payload;
    var template;
    var request;

    if (!target || !url || TinyCat.dialog.current() || qs(".modal[data-open='true']", target)) {
      return;
    }

    request = statusFeedStartRequest(target, "refresh");

    if (!request) {
      return;
    }

    target.dataset.statusFeedRefreshing = "true";

    try {
      data = await TinyCat.api.request(url, { method: "GET", cache: "no-store" });
      payload = statusFeedPayload(data);
      template = statusFeedFragment(target, payload.items);

      if (!statusFeedRequestActive(target, request)) {
        return;
      }

      target.innerHTML = "";
      target.appendChild(template.content);
      delete target.dataset.statusFeedPruned;
      control = statusFeedControlForTarget(target);

      if (control) {
        if (payload.next_url && payload.done !== true) {
          control.dataset.statusFeedUrl = String(payload.next_url || "");
          control.hidden = false;
        } else {
          control.remove();
        }
      }

      hydrateDynamic(target);

      window.requestAnimationFrame(function () {
        target.scrollIntoView({ block: "start" });
      });
    } catch (error) {
      TinyCat.toasts.show((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
    } finally {
      delete target.dataset.statusFeedRefreshing;
      statusFeedFinishRequest(target, request);

      if (control && control.isConnected && statusFeedNearViewport(control)) {
        queueStatusFeedMore(control);
      }
    }
  }
  function maybeRefreshPrunedStatusFeeds() {
    qsa("[data-status-feed][data-status-feed-pruned='true']").forEach(function (target) {
      var spacer = qs("[data-status-feed-spacer]", target);
      var rect;

      if (!spacer || spacer.hidden || statusFeedRequests.has(target)) {
        return;
      }

      rect = spacer.getBoundingClientRect();

      if (rect.top < window.innerHeight && rect.bottom > -80) {
        refreshPrunedStatusFeed(target);
      }
    });
  }
  function queuePrunedStatusFeedRefreshCheck() {
    var y = window.scrollY || 0;
    var scrollingUp = y < statusFeedLastScrollY;

    statusFeedLastScrollY = y;

    if (!scrollingUp || statusFeedScrollQueued) {
      return;
    }

    statusFeedScrollQueued = true;
    window.requestAnimationFrame(function () {
      statusFeedScrollQueued = false;
      maybeRefreshPrunedStatusFeeds();
    });
  }
  async function loadStatusFeedMore(control) {
    var target = statusFeedTarget(control);
    var url = control ? control.dataset.statusFeedUrl : "";
    var button = qs("[data-status-feed-load]", control);
    var state = qs("[data-status-feed-state]", control);
    var data;
    var payload;
    var template;
    var request;

    if (!control || !target || !url || !control.isConnected || control.dataset.statusFeedBusy === "true") {
      return;
    }

    request = statusFeedStartRequest(target, "more");

    if (!request) {
      return;
    }

    control.dataset.statusFeedBusy = "true";
    control.classList.add("is-loading");

    if (button) {
      button.disabled = true;
    }

    if (state) {
      state.hidden = false;
    }

    try {
      data = await TinyCat.api.request(url, { method: "GET" });
      payload = statusFeedPayload(data);

      if (!statusFeedRequestActive(target, request) || !control.isConnected) {
        return;
      }

      if (Array.isArray(payload.items)) {
        template = statusFeedFragment(target, payload.items);
        appendStatusFeedItems(target, template);
        pruneStatusFeed(target, url);
        hydrateDynamic(target);
      }

      if (!payload.next_url || payload.done === true || Number(payload.count || 0) < 1) {
        if (target.dataset.statusFeedPruned === "true") {
          control.hidden = true;
          return;
        }

        control.remove();
        return;
      }

      control.dataset.statusFeedUrl = String(payload.next_url || "");
    } catch (error) {
      TinyCat.toasts.show((error.data && error.data.message) || error.message || uiText("requestFailed", "Request failed"), "danger");
    } finally {
      delete control.dataset.statusFeedBusy;
      control.classList.remove("is-loading");

      if (button) {
        button.disabled = false;
      }

      if (state) {
        state.hidden = true;
      }

      statusFeedFinishRequest(target, request);
      maybeRefreshPrunedStatusFeeds();
    }

    if (control.isConnected && statusFeedControlForTarget(target) === control && statusFeedNearViewport(control)) {
      queueStatusFeedMore(control);
    }
  }
  function statusCardId(card) {
    var match = card && card.id ? /^status-(\d+)$/.exec(card.id) : null;

    return match ? match[1] : "";
  }
  function statusFeedScope(form) {
    return form
      ? form.closest(".home-feed-section, .profile-main, .public-layout, .profile-layout")
      : null;
  }
  function statusFeedForForm(form) {
    var scope = statusFeedScope(form);

    return (scope ? qs("[data-status-feed]", scope) : null) || qs("[data-status-feed]");
  }
  function initStatusFeedLazy (scope) {
    qsa("[data-status-feed-more]", scope || document).forEach(function (control) {
      if (control.dataset.statusFeedReady === "true") {
        return;
      }

      control.dataset.statusFeedReady = "true";

      if ("IntersectionObserver" in window) {
        if (!TinyCat.__statusFeedObserver) {
          TinyCat.__statusFeedObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
              if (entry.isIntersecting) {
                loadStatusFeedMore(entry.target);
              }
            });
          }, { rootMargin: "420px 0px" });
        }

        TinyCat.__statusFeedObserver.observe(control);
      }
    });

    if (TinyCat.__statusFeedPruneScrollReady !== true) {
      TinyCat.__statusFeedPruneScrollReady = true;
      window.addEventListener("scroll", queuePrunedStatusFeedRefreshCheck, { passive: true });
    }

    if (TinyCat.__statusFeedClickReady === true) {
      return;
    }

    TinyCat.__statusFeedClickReady = true;

    document.addEventListener("click", function (event) {
      var trigger = event.target.closest && event.target.closest("[data-status-feed-load]");

      if (!trigger) {
        return;
      }

      event.preventDefault();
      loadStatusFeedMore(trigger.closest("[data-status-feed-more]"));
    });

  }
  TinyCat.status.controllers.feed = { statusFeedPayload: statusFeedPayload, statusFeedTarget: statusFeedTarget, statusFeedNearViewport: statusFeedNearViewport, statusFeedCards: statusFeedCards, statusFeedGap: statusFeedGap, statusFeedSpacer: statusFeedSpacer, statusFeedSpacerHeight: statusFeedSpacerHeight, setStatusFeedSpacerHeight: setStatusFeedSpacerHeight, statusFeedFirstPageUrl: statusFeedFirstPageUrl, statusFeedControlForTarget: statusFeedControlForTarget, statusFeedStartRequest: statusFeedStartRequest, statusFeedRequestActive: statusFeedRequestActive, statusFeedFinishRequest: statusFeedFinishRequest, appendStatusFeedItems: appendStatusFeedItems, queueStatusFeedMore: queueStatusFeedMore, statusFeedCardProtected: statusFeedCardProtected, pruneStatusFeed: pruneStatusFeed, refreshPrunedStatusFeed: refreshPrunedStatusFeed, maybeRefreshPrunedStatusFeeds: maybeRefreshPrunedStatusFeeds, queuePrunedStatusFeedRefreshCheck: queuePrunedStatusFeedRefreshCheck, loadStatusFeedMore: loadStatusFeedMore, statusCardId: statusCardId, statusFeedScope: statusFeedScope, statusFeedForForm: statusFeedForForm, initStatusFeedLazy: initStatusFeedLazy };
}());
