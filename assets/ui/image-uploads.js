import "../core/dom.js";
import "../core/lifecycle.js";
import "./toasts.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  TinyCat.images = {};
  var qsa = TinyCat.dom.all;
  function clientImageFile(file, maxDimension, maxBytes) {
    return new Promise(function (resolve, reject) {
      var bitmapPromise;

      if (!file || ["image/png", "image/jpeg", "image/webp"].indexOf(file.type) === -1) {
        reject(new Error("unsupported_image"));
        return;
      }

      if (maxBytes && file.size > maxBytes) {
        reject(new Error("image_too_large"));
        return;
      }

      if (typeof window.createImageBitmap !== "function" || typeof window.DataTransfer !== "function") {
        reject(new Error("image_resize_unavailable"));
        return;
      }

      bitmapPromise = window.createImageBitmap(file, { imageOrientation: "from-image" }).catch(function () {
        return window.createImageBitmap(file);
      });

      bitmapPromise.then(function (bitmap) {
        var sourceWidth = bitmap.width;
        var sourceHeight = bitmap.height;
        var scale = Math.min(1, maxDimension / Math.max(sourceWidth, sourceHeight));
        var width = Math.max(1, Math.round(sourceWidth * scale));
        var height = Math.max(1, Math.round(sourceHeight * scale));
        var canvas = document.createElement("canvas");
        var context = canvas.getContext("2d", { alpha: true });

        if (!context) {
          if (typeof bitmap.close === "function") {
            bitmap.close();
          }
          reject(new Error("image_resize_unavailable"));
          return;
        }

        canvas.width = width;
        canvas.height = height;
        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = "high";
        context.drawImage(bitmap, 0, 0, width, height);
        if (typeof bitmap.close === "function") {
          bitmap.close();
        }

        canvas.toBlob(function (blob) {
          var extension;
          var filename;

          if (!blob || blob.size < 1) {
            reject(new Error("image_resize_failed"));
            return;
          }

          extension = blob.type === "image/webp" ? "webp" : "png";
          filename = String(file.name || "image").replace(/\.[^.]+$/, "") + "." + extension;
          resolve(new File([blob], filename, { type: blob.type, lastModified: Date.now() }));
        }, "image/webp", 0.9);
      }).catch(function () {
        reject(new Error("image_resize_failed"));
      });
    });
  }
  function replaceImageInputFile(input, file) {
    var transfer = new DataTransfer();

    transfer.items.add(file);
    input.files = transfer.files;
  }
  function clientImagePreparing(input, preparing) {
    var form = input.form;
    var active;

    input.disabled = preparing;

    if (!form) {
      return;
    }

    active = Math.max(0, parseInt(form.dataset.clientImagePreparing || "0", 10) || 0) + (preparing ? 1 : -1);
    form.dataset.clientImagePreparing = String(active);
    qsa('button[type="submit"], input[type="submit"]', form).forEach(function (button) {
      if (preparing) {
        if (button.dataset.clientImageWasDisabled === undefined) {
          button.dataset.clientImageWasDisabled = button.disabled ? "1" : "0";
        }
        button.disabled = true;
      } else if (active === 0) {
        if (button.dataset.clientImageWasDisabled !== "1") {
          button.disabled = false;
        }
        delete button.dataset.clientImageWasDisabled;
      }
    });
  }
  function prepareClientImageInput(input, file) {
    var maxDimension = parseInt(input.dataset.clientImageMaxDimension || "0", 10) || 0;
    var maxBytes = parseInt(input.dataset.clientImageMaxBytes || "0", 10) || 0;

    if (!maxDimension || !maxBytes) {
      return Promise.resolve(file);
    }

    clientImagePreparing(input, true);
    return clientImageFile(file, maxDimension, maxBytes).finally(function () {
      clientImagePreparing(input, false);
    });
  }
  function initClientImageInput(input) {
    var selectionRequestId = 0;

    if (!input || input.dataset.clientImageInputReady === "true") {
      return;
    }

    input.dataset.clientImageInputReady = "true";
    input.addEventListener("change", async function () {
      var file = input.files && input.files[0] ? input.files[0] : null;
      var requestId = ++selectionRequestId;
      var prepared;

      if (!file) {
        return;
      }

      try {
        prepared = await prepareClientImageInput(input, file);
        if (requestId !== selectionRequestId) {
          return;
        }
        replaceImageInputFile(input, prepared);
      } catch (error) {
        if (requestId !== selectionRequestId) {
          return;
        }
        input.value = "";
        TinyCat.toasts.show(TinyCat.i18n.t("common.image_prepare_failed"), "danger");
      }
    });
  }
  Object.assign(TinyCat.images, { prepare: prepareClientImageInput, replace: replaceImageInputFile, hydrate: function (root) { qsa("[data-client-image-input]", root).forEach(initClientImageInput); } });
  TinyCat.lifecycle.register("images", { hydrate: TinyCat.images.hydrate });
}());
