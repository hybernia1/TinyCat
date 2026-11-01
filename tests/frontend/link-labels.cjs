"use strict";
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname,'../..');
const modulePath = process.env.TINYCAT_TEST_PLAYWRIGHT_MODULE || path.join(root,'storage/ui-webkit-tools/node_modules/playwright');
if (!fs.existsSync(modulePath)) { console.log('SKIP native link labels: set TINYCAT_TEST_PLAYWRIGHT_MODULE'); process.exit(0); }
const {webkit} = require(modulePath);
const axe = fs.readFileSync(require.resolve('axe-core/axe.min.js',{paths:[modulePath]}),'utf8');
function read(file,...args) { return JSON.parse(execFileSync(process.env.TINYCAT_TEST_PHP || 'php',[path.join(root,file),...args],{encoding:'utf8',maxBuffer:8e6})); }
const scripts = read('tests/presentation/asset-graph-fixture.php','--all').map(asset=>asset.source).join('\n');
const templates = read('tests/presentation/component-parity.php','--templates');
const styles = read('tests/presentation/asset-graph-fixture.php','--styles');
(async function () {
  const server = http.createServer((request,response)=>{
    response.setHeader('Content-Type',request.url==='/fixture.svg'?'image/svg+xml':'text/html; charset=utf-8');
    response.end(request.url==='/fixture.svg'?'<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80"/></svg>':'<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+styles+'</style></head><body><main></main><script>'+scripts+'\nTinyCat.components.register('+JSON.stringify(templates)+');</script></body></html>');
  });
  let browser, checks=0;
  try {
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve)); browser=await webkit.launch();
    for (const locale of ['cs','en','de']) {
      const fixture=read('tests/presentation/link-labels-fixture.php',locale);
      for (const width of [320,1024]) {
        const page=await browser.newPage({viewport:{width,height:812}});
        await page.goto('http://127.0.0.1:'+server.address().port+'/');
        await page.evaluate(({fixture,locale})=>{
          document.documentElement.lang=locale; TinyCat.i18n.register(locale,fixture.labels);
          const main=document.querySelector('main'); main.innerHTML='<section id="server">'+fixture.html+fixture.source_html+'</section><section id="client"></section><section id="server-detail">'+fixture.detail_html+'</section><section id="client-detail"></section>';
          for (const item of fixture.posts) document.querySelector('#client').append(TinyCat.status.card(item,false,true));
          document.querySelector('#client').append(TinyCat.components.render('bots/sources',{items:fixture.sources,filters:{},bots:[],sorting:{},state:{}}));
          document.querySelector('#client-detail').append(TinyCat.components.render('status/actions',{item:fixture.posts[0],open_modal:false}));
          window.detailCommentUrls=Array.from(document.querySelectorAll('[data-modal-scroll]')).map(node=>node.getAttribute('href'));
          TinyCat.status.controllers.actions.updateStatusSummary({id:10,comments_count:7});
        },{fixture,locale});
        await page.addScriptTag({content:axe});
        const detailUrls=await page.evaluate(()=>detailCommentUrls);
        assert.deepEqual(detailUrls,['/status/10#status-comments-thread-10','/status/10#status-comments-thread-10']); checks++;
        const result=await page.evaluate(async()=>{
          const audit=await axe.run(document.querySelector('main'),{runOnly:{type:'rule',values:['identical-links-same-purpose','link-name','label-content-name-mismatch']}});
          const labels=scope=>Array.from(document.querySelectorAll(scope+' .status-card a[href]')).map(node=>({href:node.getAttribute('href'),time:node.classList.contains('status-time-button'),label:(node.getAttribute('aria-label') || node.textContent).replace(/\s+/g,' ').trim()}));
          return {violations:audit.violations.map(rule=>({id:rule.id,nodes:rule.nodes.map(node=>node.html)})),incomplete:audit.incomplete.filter(rule=>rule.id==='identical-links-same-purpose').map(rule=>({id:rule.id,nodes:rule.nodes.map(node=>node.html)})),server:labels('#server'),client:labels('#client'),updated:Array.from(document.querySelectorAll('[data-status-id="10"] a')).filter(node=>node.querySelector('[data-status-count="comments"]')).map(node=>node.textContent.trim())};
        });
        assert.deepEqual(result.violations,[],JSON.stringify({locale,width,...result})); checks++;
        assert.deepEqual(result.incomplete,[],JSON.stringify({locale,width,...result})); checks++;
        assert.deepEqual(result.client,result.server,'PHP and JavaScript expose the same destinations and labels'); checks++;
        assert.ok(result.updated.length===2 && result.updated.every(label=>label.endsWith(': 7')),'Accessible counter labels follow the real AJAX summary update'); checks++;
        for (const item of fixture.posts) {
          const anchors=result.server.filter(link=>link.href.startsWith(item.url));
          const comment=anchors.find(link=>link.href.includes('#status-comments-thread-'));
          const embed=anchors.find(link=>link.href.endsWith('/embed-code'));
          assert.ok(comment.label.includes('#'+item.id) && embed.label.includes('#'+item.id)); checks++;
          const permalink=anchors.filter(link=>link.href===item.url && !link.time);
          assert.ok(permalink.length>=2 && permalink.every(link=>link.label===permalink[0].label)); checks++;
        }
        await page.close();
      }
    }
    console.log('PASS native accessible link names, duplicate excerpts, PHP/client parity and axe link rules ('+checks+' checks)');
  } finally { if (browser) await browser.close(); await new Promise(resolve=>server.close(resolve)); }
}()).catch(error=>{console.error(error);process.exitCode=1;});
