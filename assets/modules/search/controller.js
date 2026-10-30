import "../../core/dates.js";
import "../../core/dom.js";
import "../../core/http.js";
import "../../core/i18n.js";
import "../../core/lifecycle.js";
import "../../core/navigation.js";
import "../../ui/avatars.js";
import "../../ui/dialogs.js";
import "../../ui/fields.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  TinyCat.search = {};
  var appendUserAvatar = TinyCat.ui.appendAvatar;
  var compactUrl = TinyCat.navigation.compact;
  var createElement = TinyCat.dom.create;
  var hydrateDynamic = TinyCat.lifecycle.hydrate;
  var qs = TinyCat.dom.find;
  var qsa = TinyCat.dom.all;
  var unwrapResult = TinyCat.api.unwrap;
  function globalSearchInput(root) {
    return qs("[data-global-search-input]", root);
  }
  function globalSearchResults(root) {
    return qs("[data-global-search-results]", root);
  }
  function globalSearchShowMessage(root, message) {
    var box = globalSearchResults(root);
    var item;

    if (!box) {
      return;
    }

    box.innerHTML = "";
    item = createElement("div", "global-search-empty", message);
    box.appendChild(item);
    box.hidden = false;
  }
  function globalSearchHide(root) {
    var box = globalSearchResults(root);

    if (box) {
      box.hidden = true;
      box.innerHTML = "";
    }
  }
  function globalSearchItem(item) {
    var link = createElement("a", "global-search-item");
    var title = createElement("strong", "global-search-title", item.title || "");
    var metaText = item.type === "tag"
      ? TinyCat.i18n.plural("public.search_tag_posts", Number(item.posts_count || 0))
      : (item.type === "user" ? "@" + (item.title || "") : (TinyCat.dates.format(item.published_at) || item.excerpt || ""));
    var meta = createElement("span", "global-search-meta", metaText);
    var excerpt = createElement("span", "global-search-excerpt", item.excerpt || "");
    var avatar = createElement("span", "global-search-avatar");
    var text = createElement("span", "global-search-text");

    link.href = item.url || "#";
    link.dataset.globalSearchLink = "true";

    if (item.type === "tag") {
      avatar.textContent = "#";
    } else if (item.type === "search") {
      avatar.textContent = ">";
    } else if (item.type === "user") {
      appendUserAvatar(avatar, item.avatar_url, item.title || "", "", "U");
    } else {
      avatar.textContent = "#";
    }

    text.appendChild(title);
    if (metaText) {
      text.appendChild(meta);
    }

    if (item.excerpt && item.type !== "tag" && item.type !== "search") {
      text.appendChild(excerpt);
    }

    link.appendChild(avatar);
    link.appendChild(text);

    return link;
  }
  function renderGlobalSearchSection(root, box, label, items) {
    var section;
    var heading;

    if (!items || items.length === 0) {
      return;
    }

    section = createElement("section", "global-search-section");
    heading = createElement("div", "global-search-heading", label);
    section.appendChild(heading);

    items.forEach(function (item) {
      section.appendChild(globalSearchItem(item));
    });

    box.appendChild(section);
  }
  function renderGlobalSearchAll(root, box, query) {
    var link;

    if (!query) {
      return;
    }

    link = globalSearchItem({
      type: "search",
      title: (root.dataset.searchAll || TinyCat.i18n.t("public.search_all")) + ": " + query,
      excerpt: "",
      url: "/search?q=" + encodeURIComponent(query)
    });
    link.classList.add("global-search-all");
    box.appendChild(link);
  }
  function renderGlobalSearch(root, data) {
    var payload = unwrapResult(data) || {};
    var box = globalSearchResults(root);
    var query = payload.query || "";
    var tags = payload.tags || [];
    var users = payload.users || [];
    var content = payload.content || [];

    if (!box) {
      return;
    }

    box.innerHTML = "";

    if (tags.length === 0 && users.length === 0 && content.length === 0) {
      renderGlobalSearchAll(root, box, query);
      box.hidden = false;
      return;
    }

    renderGlobalSearchSection(root, box, root.dataset.searchTags || TinyCat.i18n.t("public.search_tags"), tags);
    renderGlobalSearchSection(root, box, root.dataset.searchUsers || TinyCat.i18n.t("public.search_users"), users);
    renderGlobalSearchSection(root, box, root.dataset.searchContent || TinyCat.i18n.t("public.search_content"), content);
    renderGlobalSearchAll(root, box, query);
    box.hidden = false;
  }
  function searchCaptchaDetails(error) {
    return error && error.data && error.data.error && error.data.error.details
      ? error.data.error.details
      : {};
  }
  function openGlobalSearchCaptcha(root, error) {
    var details = searchCaptchaDetails(error);
    var captcha = details.captcha;
    var verifyUrl = details.verify_url || "/api/security/challenges/search";
    var message = (error && error.data && error.data.message) || root.dataset.searchCaptchaRequired || TinyCat.i18n.t("public.search_captcha_required");
    var modal = qs("#global-search-captcha-modal");
    if (!captcha || !captcha.enabled) {
      globalSearchShowMessage(root, message);
      return;
    }

    if (modal) { TinyCat.dialog.close(modal); }
    var form = createElement("form", "stack");
    form.setAttribute("data-search-captcha-form", "");
    form.action = verifyUrl; form.method = "post";
    form.appendChild(createElement("div", "text-muted", message));
    TinyCat.ui.captcha(form, captcha);
    var submit = TinyCat.ui.button("common.confirm", { type: "submit", "class": "btn btn-primary" });
    submit.textContent = root.dataset.searchCaptchaSubmit || TinyCat.i18n.t("common.confirm");
    form.appendChild(submit);
    modal = TinyCat.ui.formDialog("global-search-captcha-modal", root.dataset.searchCaptchaTitle || "public.search_captcha_title", form, false, { size: "compact" });
    form.dataset.confirmUnsaved = "false";
    modal.addEventListener("tinycat:modal-close", function () { TinyCat.forms.cancel(form); });
    form.addEventListener("submit", function (event) {
      event.preventDefault();

      TinyCat.forms.request(form, verifyUrl, {
        method: "POST",
        body: new FormData(form)
      })
        .then(function () {
          if (!modal.isConnected) { return; }
          if (modal.parentNode) {
            TinyCat.dialog.close(modal);
            modal.remove();
          }

          root.__tinycatSearchCache = {};
          runGlobalSearch(root);
        })
        .catch(function (submitError) {
          if (submitError.name === "AbortError" || !modal.isConnected) { return; }
          var submitDetails = searchCaptchaDetails(submitError);

          if (submitDetails.captcha) {
            openGlobalSearchCaptcha(root, submitError);
            return;
          }

          globalSearchShowMessage(root, (submitError && submitError.data && submitError.data.message) || message);
        });
    });

    document.body.appendChild(modal);
    hydrateDynamic(modal);
    TinyCat.dialog.open(modal);

    return modal.querySelector(".modal-panel");
  }
  function runGlobalSearch(root) {
    var input = globalSearchInput(root);
    var query = input ? input.value.trim() : "";
    var cacheKey = query.toLowerCase();
    var url;
    var requestId;
    var controller;

    if (query === "") {
      globalSearchHide(root);
      return;
    }

    if (query.length < 2) {
      globalSearchShowMessage(root, root.dataset.searchMin || TinyCat.i18n.t("public.search_min"));
      return;
    }

    root.__tinycatSearchCache = root.__tinycatSearchCache || {};

    if (root.__tinycatSearchCache[cacheKey]) {
      renderGlobalSearch(root, root.__tinycatSearchCache[cacheKey]);
      return;
    }

    if (root.__tinycatSearchController) {
      root.__tinycatSearchController.abort();
    }

    controller = "AbortController" in window ? new AbortController() : null;
    root.__tinycatSearchController = controller;
    root.__tinycatSearchRequest = (root.__tinycatSearchRequest || 0) + 1;
    requestId = root.__tinycatSearchRequest;
    url = new URL(root.dataset.searchApi || "/api/search", window.location.href);
    url.searchParams.set("q", query);
    url = compactUrl(url);
    root.setAttribute("aria-busy", "true");

    TinyCat.api.request(url, {
      method: "GET",
      signal: controller ? controller.signal : undefined
    })
      .then(function (data) {
        if (requestId !== root.__tinycatSearchRequest) {
          return;
        }

        root.__tinycatSearchCache[cacheKey] = data;
        renderGlobalSearch(root, data);
      })
      .catch(function (error) {
        if (error && error.name === "AbortError") {
          return;
        }

        if (error && error.data && error.data.error && error.data.code === "captcha_required") {
          openGlobalSearchCaptcha(root, error);
          return;
        }

        if (requestId === root.__tinycatSearchRequest) {
          globalSearchShowMessage(root, (error && error.data && error.data.message) || root.dataset.searchEmpty || TinyCat.i18n.t("public.search_empty"));
        }
      })
      .finally(function () {
        if (requestId === root.__tinycatSearchRequest) {
          root.removeAttribute("aria-busy");
          if (root.__tinycatSearchController === controller) {
            root.__tinycatSearchController = null;
          }
        }
      });
  }
  function initGlobalSearchRoot(root) {
    var input = globalSearchInput(root);

    if (root.dataset.globalSearchReady === "true" || !input) {
      return;
    }

    root.dataset.globalSearchReady = "true";

    input.addEventListener("input", function () {
      window.clearTimeout(root.__tinycatSearchTimer);
      root.__tinycatSearchTimer = window.setTimeout(function () {
        runGlobalSearch(root);
      }, 220);
    });

    input.addEventListener("focus", function () {
      if (input.value.trim() !== "") {
        runGlobalSearch(root);
      }
    });

    input.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        globalSearchHide(root);
        input.blur();
      }
    });

    root.addEventListener("submit", function (event) {
      if (input.value.trim().length < 2) {
        event.preventDefault();
        globalSearchShowMessage(root, root.dataset.searchMin || TinyCat.i18n.t("public.search_min"));
      }
    });
  }
  TinyCat.search.hydrate = function (scope) {
    qsa("[data-global-search]", scope || document).forEach(initGlobalSearchRoot);

    if (TinyCat.__globalSearchEventsBound === true) {
      return;
    }

    TinyCat.__globalSearchEventsBound = true;

    document.addEventListener("pointerdown", function (event) {
      qsa("[data-global-search]").forEach(function (root) {
        if (!root.contains(event.target)) {
          globalSearchHide(root);
        }
      });
    });
  };
    TinyCat.search.hydrate = TinyCat.search.hydrate;
}());
