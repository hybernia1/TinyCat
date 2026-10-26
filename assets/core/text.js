
(function () {
  "use strict";
  var TinyCat = window.TinyCat = window.TinyCat || {};
  function parseList(value) {
    return String(value || "")
      .split(",")
      .map(function (item) {
        return item.trim().replace(/\s+/g, " ");
      })
      .filter(Boolean);
  }
  function parseJsonArray(value) {
    try {
      var parsed = JSON.parse(String(value || "[]"));

      if (Array.isArray(parsed)) {
        return parsed.map(function (item) {
          return String(item || "").trim();
        }).filter(Boolean);
      }
    } catch (_error) {
      return parseList(value);
    }

    return [];
  }
  function uniqueList(values) {
    var seen = {};

    return values.filter(function (value) {
      var key = value.toLowerCase();

      if (seen[key]) {
        return false;
      }

      seen[key] = true;
      return true;
    });
  }
  TinyCat.text = { parseJsonArray: parseJsonArray, uniqueList: uniqueList };
}());
