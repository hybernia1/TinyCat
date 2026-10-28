import "../../core/dom.js";
import "./views.js";
import "../../ui/dialogs.js";
import "../../ui/images.js";
import "../../ui/toasts.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  var qs = TinyCat.dom.find;
  var prepareClientImageInput = TinyCat.images.prepare;
  var replaceImageInputFile = TinyCat.images.replace;
  function initAvatarUploadRoot(root) {
    var input = qs("[data-avatar-upload-input]", root);
    var preview = qs("[data-avatar-upload-preview]", root);
    var empty = qs("[data-avatar-upload-empty]", root);
    var emptyNote = qs("[data-avatar-upload-empty-note]", root);
    var form = root.closest("form");
    var action = form ? qs("[data-avatar-upload-action]", form) : null;
    var remove = form ? qs("[data-avatar-upload-remove]", form) : null;
    var editor = root.closest(".avatar-editor");
    var save = editor ? qs("[data-avatar-save]", editor) : null;
    var appearanceForm = editor ? qs("[data-avatar-appearance-form]", editor) : null;
    var toggle = editor ? qs("[data-avatar-creator-toggle]", editor) : null;
    var parameters = appearanceForm ? TinyCat.dom.all("[data-avatar-parameter]", appearanceForm) : [];
    function settings() {
      var result = { version: 1 };
      parameters.forEach(function (select) { result[select.dataset.avatarParameter] = select.value; });
      return result;
    }
    function syncSave(uploadReady) {
      if (toggle) { toggle.hidden = uploadReady; }
      if (appearanceForm) { appearanceForm.hidden = uploadReady || !toggle || toggle.getAttribute("aria-expanded") !== "true"; }
      if (!save) { return; }
      save.setAttribute("form", !uploadReady && appearanceForm ? appearanceForm.id : form.id);
      save.disabled = !uploadReady && (!appearanceForm || JSON.stringify(settings()) === appearanceForm.dataset.originalAppearance);
    }
    function showAppearance() {
      if (!appearanceForm || !originalSrc) { return; }
      var url = new URL(originalSrc, window.location.href);
      parameters.forEach(function (select) {
        var key = select.dataset.avatarParameter;
        if (select.value === select.dataset.avatarDefault) { url.searchParams.delete(key); }
        else { url.searchParams.set(key, select.value); }
      });
      preview.src = url.pathname + url.search;
      preview.hidden = false;
      qs('[name="appearance"]', appearanceForm).value = JSON.stringify(settings());
    }
    var originalSrc = preview ? String(preview.getAttribute("src") || "") : "";
    var previewRequestId = 0;
    var selectionRequestId = 0;

    if (!input || !preview || root.dataset.avatarUploadReady === "true") {
      return;
    }

    root.dataset.avatarUploadReady = "true";
    if (toggle) {
      toggle.addEventListener("click", function () {
        var expanded = toggle.getAttribute("aria-expanded") !== "true";
        toggle.setAttribute("aria-expanded", expanded ? "true" : "false");
        appearanceForm.hidden = !expanded;
        if (expanded && parameters[0]) { parameters[0].focus(); }
      });
    }
    parameters.forEach(function (select) {
      select.addEventListener("change", function () { showAppearance(); syncSave(false); });
    });

    function showOriginal() {
      syncSave(false);
      if (originalSrc) {
        preview.src = originalSrc;
        showAppearance();
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
      var reader = new FileReader();
      var requestId = ++previewRequestId;

      reader.addEventListener("load", function () {
        if (requestId !== previewRequestId || typeof reader.result !== "string") {
          return;
        }

        preview.src = reader.result;
        preview.hidden = false;
      });
      reader.addEventListener("error", showOriginal);
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

      if (action) {
        action.value = "upload";
      }

      if (!file || ["image/png", "image/jpeg", "image/webp"].indexOf(file.type) === -1) {
        showOriginal();
        return;
      }

      try {
        prepared = await prepareClientImageInput(input, file);
        if (requestId !== selectionRequestId) {
          return;
        }
        replaceImageInputFile(input, prepared);
        showFile(prepared);
        syncSave(true);
      } catch (error) {
        if (requestId !== selectionRequestId) {
          return;
        }
        input.value = "";
        TinyCat.toasts.show(TinyCat.i18n.t("common.image_prepare_failed"), "danger");
        showOriginal();
      }
    });

    if (remove && form) {
      remove.addEventListener("click", async function () {
        var confirmed = await TinyCat.dialog.confirm({
          title: root.dataset.avatarRemoveTitle || TinyCat.i18n.t("account.remove_avatar_title"),
          message: root.dataset.avatarRemoveMessage || TinyCat.i18n.t("account.remove_avatar_confirm"),
          confirmLabel: root.dataset.avatarRemoveOk || TinyCat.i18n.t("common.remove"),
          cancelLabel: root.dataset.avatarRemoveCancel || TinyCat.i18n.t("common.cancel"),
          variant: "danger"
        });

        if (!confirmed) {
          return;
        }

        selectionRequestId++;
        input.value = "";
        if (action) {
          action.value = "remove";
        }

        form.requestSubmit();
      });
    }
  }  TinyCat.users.avatarUpload = { hydrate: function (root) { TinyCat.dom.all("[data-avatar-upload]", root).forEach(initAvatarUploadRoot); } };
}());
