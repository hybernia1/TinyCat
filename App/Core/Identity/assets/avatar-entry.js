import "./editor-controller.js";
import "./avatar-editor.js";
import "../../Ui/assets/runtime/lifecycle.js";
(function () {
  "use strict";
  var T = window.TinyCat;
  T.lifecycle.register("account-avatar", { hydrate: T.accounts.avatarUpload.hydrate, dispose: T.accounts.avatarUpload.dispose });
}());
