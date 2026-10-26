(function () {
  "use strict";
  var T = window.TinyCat = window.TinyCat || {};
  function prefix() { return document.documentElement && document.documentElement.dataset.interfacePrefix || ""; }
  function split(path) {
    var parts = path.match(/^\/([a-z]{2})(\/.*)?$/);
    var locales = (document.documentElement && document.documentElement.dataset.interfaceLocales || "").split(",");
    return parts && locales.includes(parts[1]) ? { locale: parts[1], path: parts[2] || "/" } : { locale: "", path: path };
  }
  function supports(path) { return /^\/(?:privacy|search|login|register|logout|recovery|notifications|author\/[1-9][0-9]*|status\/[1-9][0-9]*|tag\/[^/]+)?$/.test(path); }
  function local(url, language) {
    if (typeof url !== "string" || !url.startsWith("/") || url.startsWith("//")) { return url; }
    var match = url.match(/^([^?#]*)(.*)$/), parts = split(match[1]);
    if (!supports(parts.path) || (!language && parts.locale)) { return url; }
    language = language || prefix();
    return language ? "/" + language + (parts.path === "/" ? "/" : parts.path) + match[2] : url;
  }
  T.urls = { local: local, split: split, prefix: prefix };
}());
