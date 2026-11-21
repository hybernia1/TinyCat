"use strict";
const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const tick = () => new Promise(resolve => setImmediate(resolve));
const requests = [], services = {};
const T = {lifecycle:{register:(name, service)=>{services[name]=service;}},i18n:{t:key=>key}};
vm.runInNewContext(fs.readFileSync(path.resolve(__dirname,'../../App/Core/Ui/assets/cache-inspection.js'),'utf8').replace(/^import .*;\r?\n/gm,''), {
  window:{TinyCat:T,location:{href:'https://tinycat.test/admin/cache',origin:'https://tinycat.test'}}, URL, AbortController,
  fetch:(url,options)=>new Promise((resolve,reject)=>requests.push({url,options,resolve,reject}))
});
function row(url) {
  const nodes = ['content-encoding','cache-control','vary','etag'].map(header=>({dataset:{cacheHeader:header},textContent:'pending',classList:{values:new Set(),add(...names){names.forEach(name=>this.values.add(name));},remove(...names){names.forEach(name=>this.values.delete(name));}}}));
  return {dataset:{cacheProbeUrl:url},isConnected:true,nodes,querySelectorAll:()=>nodes};
}
const service = services['cache-inspection'];
const root = rows=>({querySelectorAll:()=>rows,contains:node=>rows.includes(node)});
const response = headers=>({ok:true,headers:new Headers(headers),arrayBuffer:async()=>new ArrayBuffer(0)});
(async()=>{
  const html=row('/'), css=row('/assets/style.css'), foreign=row('https://external.test/');
  const page=root([html,css,foreign]); service.hydrate(page);service.hydrate(page);
  assert.equal(requests.length,2,'Hydration is idempotent and never probes another origin');
  for (const request of requests) { assert.equal(request.options.credentials,'omit');assert.equal(request.options.redirect,'error');assert.equal(request.options.cache,'no-cache'); }
  requests[0].resolve(response({'content-encoding':'gzip','cache-control':'no-store'}));
  requests[1].resolve(response({'cache-control':'public, max-age=31536000, immutable'}));await tick();
  assert.equal(html.nodes[0].textContent,'gzip');assert.equal(html.nodes[1].textContent,'no-store');
  assert.equal(html.nodes[0].classList.values.has('badge-success'),true);
  assert.equal(css.nodes[0].textContent,'admin.cache.no_encoding');assert.equal(css.nodes[1].textContent,'public, max-age=31536000, immutable');
  assert.equal(css.nodes[0].classList.values.has('badge-info'),true);
  service.hydrate(page);assert.equal(requests.length,2,'Completed observations are not repeated');
  const disposed=row('/disposable');service.hydrate(root([disposed]));service.dispose(root([disposed]));
  assert.equal(requests[2].options.signal.aborted,true);
  requests[2].resolve(response({'content-encoding':'br'}));await tick();assert.equal(disposed.nodes[0].textContent,'pending','Disposed requests cannot update their former page');
  const failed=row('/failed');service.hydrate(root([failed]));requests[3].resolve({ok:false,status:403});await tick();
  assert.equal(failed.nodes[0].textContent,'admin.cache.observe_error','HTTP failures are not misreported as uncompressed responses');
  assert.equal(failed.nodes[0].classList.values.has('badge-danger'),true);
  console.log('PASS anonymous same-origin cache observations, headers, failure states and disposal');
})().catch(error=>{console.error(error);process.exitCode=1;});
