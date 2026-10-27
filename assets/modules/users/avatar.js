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
    var originalSrc = preview ? String(preview.getAttribute("src") || "") : "";
    var previewRequestId = 0;
    var selectionRequestId = 0;

    if (!input || !preview || root.dataset.avatarUploadReady === "true") {
      return;
    }

    root.dataset.avatarUploadReady = "true";

    function showOriginal() {
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
