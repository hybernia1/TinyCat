import "../../Ui/assets/runtime/dom.js";
import "./controller.js";
import "../../Ui/assets/dialogs.js";
import "../../Ui/assets/image-uploads.js";
import "../../Ui/assets/toasts.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat, uploads = new WeakMap();
  var qs = TinyCat.dom.find;
  var prepareClientImageInput = TinyCat.images.prepare;
  var replaceImageInputFile = TinyCat.images.replace;
  function initAvatarUploadRoot(root) {
    var input = qs("[data-avatar-upload-input]", root);
    var preview = qs("[data-avatar-upload-preview]", root);
    var empty = qs("[data-avatar-upload-empty]", root);
    var emptyNote = qs("[data-avatar-upload-empty-note]", root);
    var form = root.closest("form");
    var editor = root.closest(".avatar-editor");
    var save = editor ? qs("[data-avatar-save]", root.closest(".modal") || editor) : null;
    function syncSave(uploadReady) {
      if (save) { save.setAttribute("form", form.id); save.disabled = !uploadReady; }
    }
    var originalSrc = preview ? String(preview.getAttribute("src") || "") : "";
    var previewRequestId = 0;
    var selectionRequestId = 0, reader = null, disposed = false;
    function current() { return !disposed && root.isConnected !== false; }

    if (!input || !preview || root.dataset.avatarUploadReady === "true") {
      return;
    }

    root.dataset.avatarUploadReady = "true";
    uploads.set(root, function () { disposed = true; selectionRequestId++; previewRequestId++; if (reader) { reader.abort(); reader = null; } });
    function showOriginal() {
      syncSave(false);
      if (originalSrc) {
        preview.src = originalSrc;
        preview.hidden = false;
      } else {
        preview.removeAttribute("src");
        preview.hidden = true;
      }

      if (empty) {
        empty.hidden = Boolean(originalSrc);
      }

      if (emptyNote) {
        emptyNote.hidden = Boolean(originalSrc);
      }
    }

    preview.addEventListener("error", function () {
      originalSrc = "";
      showOriginal();
    });

    if (preview.complete && preview.getAttribute("src") && preview.naturalWidth < 1) {
      originalSrc = "";
      showOriginal();
    }

    function showFile(file) {
      if (reader) { reader.abort(); }
      reader = new FileReader();
      var requestId = ++previewRequestId;

      reader.addEventListener("load", function () {
        if (!current() || requestId !== previewRequestId || typeof reader.result !== "string") {
          return;
        }

        preview.src = reader.result;
        preview.hidden = false;
      });
      reader.addEventListener("error", function () { if (current() && requestId === previewRequestId) { showOriginal(); } });
      reader.readAsDataURL(file);

      if (empty) {
        empty.hidden = true;
      }

      if (emptyNote) {
        emptyNote.hidden = true;
      }
    }

    input.addEventListener("change", async function () {
      var file = input.files && input.files[0] ? input.files[0] : null;
      var requestId = ++selectionRequestId;
      var prepared;
      if (save) { save.disabled = true; }

      if (!file || ["image/png", "image/jpeg", "image/webp"].indexOf(file.type) === -1) {
        showOriginal();
        return;
      }

      try {
        prepared = await prepareClientImageInput(input, file);
        if (!current() || requestId !== selectionRequestId) {
          return;
        }
        replaceImageInputFile(input, prepared);
        showFile(prepared);
        syncSave(true);
      } catch (error) {
        if (!current() || requestId !== selectionRequestId) {
          return;
        }
        input.value = "";
        TinyCat.toasts.show(TinyCat.i18n.t("common.image_prepare_failed"), "danger");
        showOriginal();
      }
    });

  }
  TinyCat.accounts.avatarUpload = {
    hydrate: function (root) { TinyCat.dom.all("[data-avatar-upload]", root).forEach(initAvatarUploadRoot); },
    dispose: function (root) {
      var nodes = TinyCat.dom.all("[data-avatar-upload]", root);
      if (root.matches && root.matches("[data-avatar-upload]")) { nodes.push(root); }
      nodes.forEach(function (node) { var cleanup = uploads.get(node); if (cleanup) { cleanup(); uploads.delete(node); } });
    }
  };
}());
