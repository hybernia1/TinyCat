"use strict";
const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const root = path.resolve(__dirname, '../..');
const load = (context, file) => vm.runInContext(fs.readFileSync(path.join(root, file), 'utf8').replace(/^import .*;\r?\n/gm, ''), context, {filename:file});
function lifecycle() {
  const calls = {hydrate:0, editorDispose:0, imageDispose:0, mediaDispose:0}, editor = {};
  const T = {dom:{all: selector => selector === '[data-status-editor]' ? [editor] : []}, navigation:{register() {}}, publishing:{controllers:{
    media:{initStatusVideoRoot() {}, initStatusLinkImageRoot() {}, initStatusImageRoot() {}, dispose() {calls.mediaDispose++;}, disposeImages() {calls.imageDispose++;}},
    translation:{hydrate() {}, dispose() {}}, editor:{hydrate() {calls.hydrate++;}, hideSuggestions() {calls.editorDispose++;}}
  }}, status:{result() {}, navigation:{}, controllers:{feed:{initStatusFeedLazy() {}, dispose() {}},actions:{prepareForms() {}},comments:{}}}};
  const context = vm.createContext({window:{TinyCat:T},document:{}});
  for (const file of ['App/Core/Ui/assets/runtime/modules.js','App/Modules/Social/assets/content/presentation.js','App/Modules/Social/assets/content/editor-entry.js','App/Modules/Social/assets/content/index.js','App/Modules/Social/assets/status/index.js']) load(context,file);
  T.modules.hydrate({}); T.modules.dispose({});
  assert.deepEqual(calls,{hydrate:1,editorDispose:1,imageDispose:1,mediaDispose:1},'Each shared capability has exactly one lifecycle owner');
}
async function avatar(outcome, pendingReader = false) {
  let resolve, reject, replacements = 0, toasts = 0, reader;
  const input = {files:[{type:'image/png'}],value:'selected',addEventListener(name,fn) {this[name]=fn;}};
  const preview = {getAttribute() {return '';},addEventListener() {},removeAttribute() {},src:'original'};
  const node = {dataset:{},isConnected:true,closest() {return null;},matches:selector => selector === '[data-avatar-upload]'};
  const T = {accounts:{},dom:{find:selector => selector === '[data-avatar-upload-input]' ? input : selector === '[data-avatar-upload-preview]' ? preview : null,all:(_selector,scope) => scope === node ? [] : [node]},
    images:{prepare:() => pendingReader ? Promise.resolve({}) : new Promise((yes,no) => {resolve=yes;reject=no;}),replace() {replacements++;}},toasts:{show() {toasts++;}},i18n:{t:key=>key}};
  class Reader {constructor() {reader=this;this.events={};} addEventListener(name,fn) {this.events[name]=fn;} readAsDataURL() {} abort() {this.aborted=true;}}
  const context = vm.createContext({window:{TinyCat:T},FileReader:Reader});
  load(context,'App/Core/Identity/assets/avatar-editor.js'); T.accounts.avatarUpload.hydrate({});
  const pending = input.change(); if (pendingReader) await pending;
  T.accounts.avatarUpload.dispose(node); node.isConnected=false;
  if (pendingReader) {
    assert.equal(reader.aborted,true,'Disposing avatar aborts the active FileReader');
    reader.result='late-data'; reader.events.load(); assert.notEqual(preview.src,'late-data');
  } else {
    if (outcome === 'success') resolve({}); else reject(new Error('failed'));
    await pending; assert.equal(replacements,0,'A disposed avatar cannot replace an upload'); assert.equal(toasts,0,'A disposed avatar cannot emit late errors');
  }
}
async function composer(outcome) {
  let resolve, reject, replacements=0, toasts=0;
  const input={files:[{type:'image/png'}],value:'selected',dataset:{},addEventListener(name,fn) {this[name]=fn;}};
  const preview={getAttribute:()=>'',addEventListener() {}};
  const node={dataset:{},isConnected:true,closest:()=>null,matches:s=>s==='[data-status-image]'};
  const T={dom:{find:s=>s==='[data-status-image-input]'?input:s==='[data-status-image-preview]'?preview:null,all:()=>[]},publishing:{controllers:{}},navigation:{},
    images:{prepare:()=>new Promise((yes,no)=>{resolve=yes;reject=no;}),replace(){replacements++;}},toasts:{show(){toasts++;}},i18n:{t:k=>k}};
  const context=vm.createContext({window:{TinyCat:T}}); load(context,'App/Modules/Social/assets/content/media.js');
  const media=T.publishing.controllers.media; media.initStatusImageRoot(node); const pending=input.change(); media.disposeImages(node); node.isConnected=false;
  if(outcome==='success') resolve({}); else reject(new Error('failed'));
  await pending; assert.equal(replacements,0); assert.equal(toasts,0);
}
async function upload(outcome) {
  let resolve,reject,replacements=0,toasts=0,service;
  const input={dataset:{clientImageMaxDimension:'100',clientImageMaxBytes:'1000'},files:[{type:'image/png',size:10}],isConnected:true,form:null,addEventListener(name,fn){this[name]=fn;},matches:s=>s==='[data-client-image-input]'};
  class Transfer {constructor(){this.items={add(){replacements++;}};this.files=[];}}
  const T={dom:{all:(_s,scope)=>scope===input?[]:[input]},lifecycle:{register(_name,value){service=value;}},toasts:{show(){toasts++;}},i18n:{t:k=>k}};
  const document={createElement:()=>({getContext:()=>({drawImage(){}}),toBlob:fn=>fn({size:1,type:'image/webp'})})};
  const context=vm.createContext({window:{TinyCat:T,DataTransfer:Transfer,createImageBitmap:()=>new Promise((yes,no)=>{resolve=yes;reject=no;})},document,DataTransfer:Transfer,File:class {}});
  load(context,'App/Core/Ui/assets/image-uploads.js'); service.hydrate({}); const pending=input.change(); service.dispose(input);input.isConnected=false;
  if(outcome==='success') resolve({width:1,height:1,close(){}});
  else {reject(new Error('failed'));await Promise.resolve();reject(new Error('retry failed'));}
  await pending; assert.equal(replacements,0);assert.equal(toasts,0);
}
let completed = false;
process.on("beforeExit", () => { if (!completed && !process.exitCode) { console.error("Lifecycle regression did not finish"); process.exitCode = 1; } });
(async()=>{
  lifecycle();
  for(const outcome of ['success','error']) {await avatar(outcome);await composer(outcome);await upload(outcome);}
  await avatar('success',true);
  completed = true;
  console.log('PASS single lifecycle ownership and disposed avatar/composer/upload success, failures and FileReader cancellation');
})().catch(error=>{console.error(error);process.exitCode=1;});
