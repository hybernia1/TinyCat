(function () {
  "use strict";

  var TinyCat = window.TinyCat || {};
  var Editor = TinyCat.Editor || {};
  var dictionaries = {};
  var loadedLocales = {};
  var loadingLocales = {};
  var fallbackLocale = "en";
  var editorScriptElement = editorScript();
  var langBase = editorLangBase(editorScriptElement);
  var iconsHref = editorIconsHref(editorScriptElement);

  function editorScript() {
    return document.currentScript || document.querySelector('script[src*="/editor/editor.js"], script[src*="editor.js"]');
  }

  function editorLangBase(script) {
    var custom = script && script.dataset ? script.dataset.editorLangPath : "";
    var source = script && script.src ? script.src : window.location.origin + "/assets/editor/editor.js";

    if (custom) {
      return new URL(custom.replace(/\/?$/, "/"), window.location.href).href;
    }

    return new URL("lang/", source).href;
  }

  function editorIconsHref(script) {
    var custom = script && script.dataset ? script.dataset.editorIcons : "";
    var source = script && script.src ? script.src : window.location.origin + "/assets/editor/editor.js";

    if (custom) {
      return new URL(custom, window.location.href).href;
    }

    return new URL("../icons.svg", source).href;
  }

  function normalizeLocale(locale) {
    locale = String(locale || fallbackLocale).trim().toLowerCase();

    return locale ? locale.split("-")[0] : fallbackLocale;
  }

  function langUrl(locale) {
    return new URL(encodeURIComponent(locale) + ".json", langBase).href;
  }

  function loadLocale(locale) {
    locale = normalizeLocale(locale);

    if (loadedLocales[locale]) {
      return Promise.resolve(dictionaries[locale] || {});
    }

    if (loadingLocales[locale]) {
      return loadingLocales[locale];
    }

    if (!window.fetch) {
      dictionaries[locale] = dictionaries[locale] || {};
      loadedLocales[locale] = true;

      return Promise.resolve(dictionaries[locale]);
    }

    loadingLocales[locale] = fetch(langUrl(locale), {
      headers: {
        Accept: "application/json"
      }
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error("Editor locale not found: " + locale);
        }

        return response.json();
      })
      .then(function (data) {
        if (!data || typeof data !== "object" || Array.isArray(data)) {
          data = {};
        }

        dictionaries[locale] = Object.assign({}, data, dictionaries[locale] || {});
        loadedLocales[locale] = true;

        return dictionaries[locale];
      })
      .catch(function () {
        dictionaries[locale] = dictionaries[locale] || {};
        loadedLocales[locale] = true;

        return dictionaries[locale];
      });

    return loadingLocales[locale];
  }

  function ensureLocale(locale) {
    locale = normalizeLocale(locale);

    return loadLocale(fallbackLocale).then(function () {
      if (locale === fallbackLocale) {
        return dictionaries[locale] || {};
      }

      return loadLocale(locale);
    });
  }

  function qs(selector, root) {
    return (root || document).querySelector(selector);
  }

  function qsa(selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  }

  function ready(callback) {
    if (TinyCat.ready) {
      TinyCat.ready(callback);
      return;
    }

    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", callback, { once: true });
      return;
    }

    callback();
  }

  function emit(element, name, detail) {
    element.dispatchEvent(new CustomEvent(name, {
      bubbles: true,
      detail: detail || {}
    }));
  }

  function documentLocale(element) {
    var locale = element && element.dataset ? element.dataset.editorLang : "";

    return normalizeLocale(locale || document.documentElement.lang || fallbackLocale);
  }

  function t(locale, key) {
    var dictionary = dictionaries[normalizeLocale(locale)] || {};
    var fallback = dictionaries[fallbackLocale] || {};

    return dictionary[key] || fallback[key] || key;
  }

  function icon(name) {
    var svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    var use = document.createElementNS("http://www.w3.org/2000/svg", "use");

    svg.setAttribute("class", "icon");
    svg.setAttribute("aria-hidden", "true");
    svg.setAttribute("focusable", "false");
    use.setAttribute("href", iconsHref + "#" + name);
    use.setAttributeNS("http://www.w3.org/1999/xlink", "href", iconsHref + "#" + name);
    svg.appendChild(use);

    return svg;
  }

  function button(label, command, iconName) {
    var element = document.createElement("button");
    element.type = "button";
    element.className = "btn btn-sm btn-ghost tc-editor-button";
    element.dataset.editorCommand = command;
    element.title = label;
    element.setAttribute("aria-label", label);
    element.appendChild(icon(iconName));

    return element;
  }

  function separator() {
    var element = document.createElement("span");
    element.className = "tc-editor-separator";
    element.setAttribute("aria-hidden", "true");

    return element;
  }

  function formatSelect(locale) {
    var select = document.createElement("select");
    var formats = [
      ["P", t(locale, "paragraph")],
      ["H2", t(locale, "heading2")],
      ["H3", t(locale, "heading3")],
      ["BLOCKQUOTE", t(locale, "quote")]
    ];

    select.className = "select";
    select.dataset.editorFormat = "true";
    select.setAttribute("aria-label", t(locale, "paragraph"));

    formats.forEach(function (format) {
      var option = document.createElement("option");
      option.value = format[0];
      option.textContent = format[1];
      select.appendChild(option);
    });

    return select;
  }

  function toolbar(locale) {
    var element = document.createElement("div");
    element.className = "tc-editor-toolbar";
    element.setAttribute("role", "toolbar");
    element.setAttribute("aria-label", t(locale, "toolbar"));

    element.appendChild(formatSelect(locale));
    element.appendChild(separator());
    element.appendChild(button(t(locale, "bold"), "bold", "bold"));
    element.appendChild(button(t(locale, "italic"), "italic", "italic"));
    element.appendChild(button(t(locale, "underline"), "underline", "underline"));
    element.appendChild(separator());
    element.appendChild(button(t(locale, "unorderedList"), "insertUnorderedList", "list-unordered"));
    element.appendChild(button(t(locale, "orderedList"), "insertOrderedList", "list-ordered"));
    element.appendChild(separator());
    element.appendChild(button(t(locale, "link"), "createLink", "link"));
    element.appendChild(button(t(locale, "unlink"), "unlink", "unlink"));
    element.appendChild(separator());
    element.appendChild(button(t(locale, "undo"), "undo", "undo"));
    element.appendChild(button(t(locale, "redo"), "redo", "redo"));
    element.appendChild(button(t(locale, "clear"), "removeFormat", "remove-format"));
    element.appendChild(separator());
    element.appendChild(button(t(locale, "source"), "toggleSource", "code"));

    return element;
  }

  function sanitizeHtml(html) {
    var template = document.createElement("template");
    var allowedTags = {
      A: true,
      B: true,
      BLOCKQUOTE: true,
      BR: true,
      DIV: true,
      EM: true,
      H2: true,
      H3: true,
      I: true,
      LI: true,
      OL: true,
      P: true,
      STRONG: true,
      U: true,
      UL: true
    };

    template.innerHTML = html || "";

    qsa("*", template.content).forEach(function (node) {
      if (!allowedTags[node.tagName]) {
        node.replaceWith(document.createTextNode(node.textContent || ""));
        return;
      }

      Array.prototype.slice.call(node.attributes).forEach(function (attribute) {
        var name = attribute.name.toLowerCase();
        var value = attribute.value;

        if (node.tagName === "A" && name === "href" && /^(https?:|mailto:|\/|#)/i.test(value)) {
          return;
        }

        node.removeAttribute(attribute.name);
      });

      if (node.tagName === "A") {
        node.setAttribute("rel", "noopener");
      }
    });

    return template.innerHTML;
  }

  function normalizeUrl(value) {
    var url = String(value || "").trim();

    if (!url) {
      return "";
    }

    if (/^(https?:|mailto:|\/|#)/i.test(url)) {
      return url;
    }

    return "https://" + url;
  }

  function saveSelection(root) {
    var selection = window.getSelection();
    var range;

    if (!selection || !selection.rangeCount) {
      return null;
    }

    range = selection.getRangeAt(0);

    if (!root.contains(range.commonAncestorContainer)) {
      return null;
    }

    return range.cloneRange();
  }

  function restoreSelection(range) {
    var selection = window.getSelection();

    if (!range || !selection) {
      return;
    }

    selection.removeAllRanges();
    selection.addRange(range);
  }

  function activeLinkValue() {
    var selection = window.getSelection();
    var node;
    var link;

    if (!selection || !selection.anchorNode) {
      return "https://";
    }

    node = selection.anchorNode.nodeType === Node.TEXT_NODE ? selection.anchorNode.parentElement : selection.anchorNode;
    link = node && node.closest ? node.closest("a") : null;

    return link ? link.getAttribute("href") || "https://" : "https://";
  }

  function stats(text) {
    var clean = String(text || "").trim();
    var words = clean ? clean.split(/\s+/).length : 0;

    return {
      words: words,
      characters: clean.length
    };
  }

  function textFromHtml(html) {
    var template = document.createElement("template");

    template.innerHTML = html || "";

    return template.content.textContent || "";
  }

  function updateStats(instance) {
    var text = instance.mode === "source" ? textFromHtml(instance.source.value) : instance.surface.textContent;
    var data = stats(text);

    instance.footer.textContent = data.words + " " + t(instance.locale, "words") + " / " + data.characters + " " + t(instance.locale, "characters");
  }

  function sync(instance) {
    instance.textarea.value = instance.mode === "source" ? sanitizeHtml(instance.source.value) : sanitizeHtml(instance.surface.innerHTML);
    updateStats(instance);
    emit(instance.textarea, "tinycat:editor-sync", { html: instance.textarea.value });
  }

  function toggleSource(instance) {
    if (instance.mode === "source") {
      instance.mode = "visual";
      instance.root.dataset.editorMode = "visual";
      instance.textarea.value = sanitizeHtml(instance.source.value);
      instance.surface.innerHTML = instance.textarea.value;
      sync(instance);
      updateToolbar(instance);
      instance.surface.focus();
      return;
    }

    sync(instance);
    instance.mode = "source";
    instance.root.dataset.editorMode = "source";
    instance.source.value = instance.textarea.value;
    updateStats(instance);
    updateToolbar(instance);
    instance.source.focus();
  }

  async function exec(instance, command, value) {
    var range = command === "createLink" ? saveSelection(instance.surface) : null;

    if (command === "toggleSource") {
      toggleSource(instance);
      return;
    }

    if (instance.mode === "source") {
      return;
    }

    if (command === "createLink") {
      if (!TinyCat.EditorModal || !TinyCat.EditorModal.prompt) {
        return;
      }

      value = await TinyCat.EditorModal.prompt({
        title: t(instance.locale, "link"),
        label: t(instance.locale, "linkPrompt"),
        value: activeLinkValue(),
        inputType: "url",
        confirmLabel: t(instance.locale, "confirm"),
        cancelLabel: t(instance.locale, "cancel")
      });

      instance.surface.focus();
      restoreSelection(range);
      value = normalizeUrl(value);

      if (!value) {
        return;
      }
    } else {
      instance.surface.focus();
    }

    document.execCommand(command, false, value || null);
    sync(instance);
    updateToolbar(instance);
  }

  function updateToolbar(instance) {
    var active = ["bold", "italic", "underline", "insertUnorderedList", "insertOrderedList"];
    var sourceMode = instance.mode === "source";

    active.forEach(function (command) {
      qsa('[data-editor-command="' + command + '"]', instance.root).forEach(function (item) {
        item.setAttribute("aria-pressed", !sourceMode && document.queryCommandState(command) ? "true" : "false");
      });
    });

    qsa("[data-editor-command]", instance.root).forEach(function (item) {
      if (item.dataset.editorCommand === "toggleSource") {
        item.setAttribute("aria-pressed", sourceMode ? "true" : "false");
        return;
      }

      item.disabled = sourceMode;
    });

    qsa("[data-editor-format]", instance.root).forEach(function (item) {
      item.disabled = sourceMode;
    });
  }

  function bind(instance) {
    instance.root.addEventListener("mousedown", function (event) {
      if (event.target.closest("[data-editor-command]")) {
        event.preventDefault();
      }
    });

    instance.root.addEventListener("click", function (event) {
      var control = event.target.closest("[data-editor-command]");

      if (!control) {
        return;
      }

      event.preventDefault();
      exec(instance, control.dataset.editorCommand);
    });

    instance.root.addEventListener("change", function (event) {
      var select = event.target.closest("[data-editor-format]");

      if (!select) {
        return;
      }

      event.preventDefault();
      exec(instance, "formatBlock", select.value);
    });

    instance.surface.addEventListener("input", function () {
      sync(instance);
    });

    instance.source.addEventListener("input", function () {
      sync(instance);
    });

    instance.surface.addEventListener("paste", function (event) {
      event.preventDefault();
      document.execCommand("insertText", false, (event.clipboardData || window.clipboardData).getData("text"));
      sync(instance);
    });

    instance.surface.addEventListener("keyup", function () {
      updateToolbar(instance);
    });

    instance.surface.addEventListener("mouseup", function () {
      updateToolbar(instance);
    });

    if (instance.textarea.form) {
      instance.textarea.form.addEventListener("submit", function () {
        sync(instance);
      });
    }
  }

  Editor.register = function (locale, dictionary) {
    locale = normalizeLocale(locale);
    dictionaries[locale] = Object.assign(dictionaries[locale] || {}, dictionary || {});
  };

  Editor.load = function (locale) {
    return ensureLocale(locale || documentLocale(document.documentElement));
  };

  Editor.t = function (key, locale) {
    return t(locale || documentLocale(document.documentElement), key);
  };

  function buildEditor(textarea, locale) {
    var root = document.createElement("div");
    var surface = document.createElement("div");
    var source = document.createElement("textarea");
    var footer = document.createElement("div");
    var instance;

    if (textarea.dataset.editorReady === "true") {
      return textarea.__tinycatEditor;
    }

    textarea.dataset.editorReady = "true";
    textarea.classList.add("tc-editor-source");

    root.className = "tc-editor";
    root.dataset.editorRoot = "true";
    root.dataset.editorMode = "visual";

    surface.className = "tc-editor-surface";
    surface.contentEditable = "true";
    surface.setAttribute("role", "textbox");
    surface.setAttribute("aria-multiline", "true");
    surface.dataset.placeholder = textarea.dataset.editorPlaceholder || t(locale, "placeholder");
    surface.innerHTML = sanitizeHtml(textarea.value);

    if (textarea.dataset.editorMinHeight) {
      root.style.setProperty("--editor-min-height", textarea.dataset.editorMinHeight);
    }

    source.className = "textarea tc-editor-code";
    source.value = sanitizeHtml(textarea.value);
    source.spellcheck = false;
    source.setAttribute("aria-label", t(locale, "source"));

    footer.className = "tc-editor-footer";

    root.appendChild(toolbar(locale));
    root.appendChild(surface);
    root.appendChild(source);
    root.appendChild(footer);
    textarea.insertAdjacentElement("afterend", root);

    instance = {
      footer: footer,
      locale: locale,
      mode: "visual",
      root: root,
      source: source,
      surface: surface,
      textarea: textarea
    };

    textarea.__tinycatEditor = instance;
    bind(instance);
    sync(instance);

    return instance;
  }

  Editor.init = function (textarea) {
    var locale = documentLocale(textarea);

    if (textarea.dataset.editorReady === "true") {
      return textarea.__tinycatEditor;
    }

    if (textarea.dataset.editorPending === "true") {
      return textarea.__tinycatEditorPending;
    }

    textarea.dataset.editorPending = "true";
    textarea.__tinycatEditorPending = ensureLocale(locale).then(function () {
      delete textarea.dataset.editorPending;

      return buildEditor(textarea, locale);
    });

    return textarea.__tinycatEditorPending;
  };

  Editor.initAll = function () {
    return Promise.all(qsa("textarea[data-editor]").map(Editor.init));
  };

  TinyCat.Editor = Editor;
  window.TinyCat = TinyCat;
  ready(Editor.initAll);
}());
