import "./user-menu.js";
import "../../Ui/assets/runtime/modules.js";
import "./avatar-editor.js";
import "./controller.js";
import "./email-availability.js";
(function () {
  "use strict";
  var TinyCat = window.TinyCat;
  TinyCat.accounts.hydrate = TinyCat.accounts.avatarUpload.hydrate;
  TinyCat.accounts.dispose = TinyCat.accounts.avatarUpload.dispose;
  TinyCat.modules.register("accounts", TinyCat.accounts);
}());
