(function () {
  "use strict";
  var T = window.TinyCat = window.TinyCat || {};
  function prefix() { return document.documentElement && document.documentElement.dataset.interfacePrefix || ""; }
  var metadata;
  function routes() {
    if (metadata) { return metadata; }
    var data = document.documentElement && document.documentElement.dataset || {};
    var patterns;
    try { patterns = JSON.parse(data.interfaceRoutes || "[]").map(function (pattern) { return new RegExp(pattern, "u"); }); }
    catch (_) { patterns = []; }
    metadata = { locales: (data.interfaceLocales || "").split(","), patterns: patterns };
    return metadata;
  }
  function split(path) {
    var parts = path.match(/^\/([a-z]{2,3}(?:-[a-z0-9]+)*)(\/.*)?$/);
    var locales = routes().locales;
    return parts && locales.includes(parts[1]) ? { locale: parts[1], path: parts[2] || "/" } : { locale: "", path: path };
  }
  function supports(path) {
    return routes().patterns.some(function (pattern) { return pattern.test(path); });
  }
  function local(url, language) {
    if (typeof url !== "string" || !url.startsWith("/") || url.startsWith("//")) { return url; }
    var match = url.match(/^([^?#]*)(.*)$/), parts = split(match[1]);
    if (!supports(parts.path) || (!language && parts.locale)) { return url; }
    language = language || prefix();
    return language ? "/" + language + (parts.path === "/" ? "/" : parts.path) + match[2] : url;
  }
  T.urls = { local: local, split: split, prefix: prefix };
}());
