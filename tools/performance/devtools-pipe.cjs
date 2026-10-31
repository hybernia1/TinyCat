"use strict";
// Chromium CDP over inherited pipes: no debugger URL or port from a file.
module.exports = function devtoolsPipe(child) {
  const listeners = new Map(); let buffer = "", closed = false;
  function emit(type, data) { (listeners.get(type) || new Set()).forEach(callback => callback({ data })); }
  function close() { if (closed) return; closed = true; emit("close"); }
  child.stdio[4].setEncoding("utf8");
  child.stdio[4].on("data", chunk => {
    buffer += chunk; let end;
    while ((end = buffer.indexOf("\0")) !== -1) { const message = buffer.slice(0, end); buffer = buffer.slice(end + 1); if (message) emit("message", message); }
  });
  child.on("exit", close); child.on("error", close);
  child.stdio[3].on("error", close); child.stdio[4].on("error", close);
  return {
    addEventListener(type, callback) { if (!listeners.has(type)) listeners.set(type, new Set()); listeners.get(type).add(callback); },
    removeEventListener(type, callback) { listeners.get(type)?.delete(callback); },
    send(message) { if (closed) throw Error("Browser pipe closed"); child.stdio[3].write(message + "\0"); },
    close() { child.stdio[3].end(); child.stdio[4].destroy(); close(); }
  };
};
