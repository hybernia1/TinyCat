(async function () {
  const T = window.TinyCat, checks = [];
  const check = (value, message) => { if (!value) throw Error(message); checks.push(message); };
  const pause = () => new Promise(resolve => setTimeout(resolve, 25));
  async function wait(test, message) { for (let i=0;i<100 && !test();i++) await pause(); check(test(), message); }
  const mode = new URL(location.href).searchParams.get('case');
  if (mode === 'auth-switch') {
    const reads = [], opener = T.dom.element('button',{type:'button'},'Login'), confirm = T.dialog.confirm;
    document.body.append(opener);
    opener.dataset.modalOpen = 'auth-modal'; opener.dataset.modalUrl = '/api/users/authentication?mode=login&next=%2Fsocial';
    T.api.request = async url => {
      const address = new URL(url,location.href), mode = address.searchParams.get('mode');
      check(address.pathname === '/api/users/authentication', 'Authentication switches only read their authorized resource');
      reads.push(mode);
      const other = mode === 'login' ? 'register' : 'login';
      return {data:{mode,next:'/social',registration_enabled:true,options:{},captcha:{enabled:false},switch_url:'/'+other,switch_modal_url:'/api/users/authentication?mode='+other+'&next=%2Fsocial'}};
    };
    T.lifecycle.start(); opener.focus(); opener.click();
    await wait(() => !!T.dialog.current(), 'Login is created on demand');
    let modal = T.dialog.current();
    check(!modal.querySelector('[data-modal-resource-refresh]') && await T.dialog.refresh(modal)===null, 'Login explicitly omits resource refresh');
    const username = modal.querySelector('[name="username"]'); username.value='PreservedDraft'; username.dispatchEvent(new Event('input',{bubbles:true}));
    T.forms.confirmWith(async () => false);
    modal.querySelector('[data-modal-open="auth-modal"]').click(); await pause();
    check(reads.join(',')==='login' && username.value==='PreservedDraft' && modal.isConnected, 'Declining a switch preserves the draft and avoids a resource read');
    T.forms.confirmWith(async () => true);
    modal.querySelector('[data-modal-open="auth-modal"]').click();
    await wait(() => reads.includes('register') && T.dialog.current()!==modal, 'Switch requests register mode instead of refreshing login');
    modal = T.dialog.current();
    check(!modal.querySelector('[data-modal-resource-refresh]') && await T.dialog.refresh(modal)===null, 'Registration explicitly omits resource refresh');
    check(!!modal.querySelector('[name="email"]') && location.hash.includes('mode=register') && modal.contains(document.activeElement), 'Registration fields, focus and history follow the selected resource');
    modal.querySelector('[data-modal-open="auth-modal"]').click();
    await wait(() => reads.join(',')==='login,register,login' && T.dialog.current()!==modal, 'Registration switches back through the same loader');
    T.forms.confirmWith(confirm);
    T.dialog.close(T.dialog.current(),{history:false});
    check(document.activeElement===opener, 'Closing returns focus to the original opener');
    document.getElementById('validation-test-results').textContent=JSON.stringify({ok:true,mode,checks}); return;
  }
  if (mode === 'javascript-audit') {
    T.lifecycle.start();
    const root=document.createElement('div');root.innerHTML='<button tabindex="-1">Inactive</button><button id="audit-active">Active</button><fieldset disabled><button>Disabled</button></fieldset><div inert><button>Inert</button></div>';
    document.body.append(root);check(T.dom.focusables(root).length===1&&T.dom.focusables(root)[0].id==='audit-active','Traversal excludes negative tabindex, disabled fieldsets and inert ancestors');root.remove();
    const editing=document.createElement('div');editing.contentEditable='plaintext-only';document.body.append(editing);check(T.dom.focusables(document.body).includes(editing),'Editable hosts remain tabbable without an explicit tabindex');editing.tabIndex=-1;check(!T.dom.focusables(document.body).includes(editing),'An explicit negative tabindex still excludes an editable host');editing.remove();
    const form=document.createElement('form');form.id='audit-form';form.action='/api/local-audit';form.method='post';
    for(let i=0;i<250;i++){const field=document.createElement('input');field.name='value'+i;field.dataset.maxBytes='100';field.value='sample';form.append(field);}
    const submit=document.createElement('button');submit.type='submit';submit.name='intent';submit.value='publish';submit.setAttribute('form',form.id);document.body.append(form,submit);
    const encode=TextEncoder.prototype.encode;let encoded=0;TextEncoder.prototype.encode=function(...args){encoded++;return encode.apply(this,args);};
    try {
      form.elements[0].dispatchEvent(new Event('input',{bubbles:true}));check(encoded===1,'One input validates only the changed field');
      const proxy=document.createElement('div');proxy.dataset.validationFor='value0';form.append(proxy);encoded=0;proxy.dispatchEvent(new Event('input',{bubbles:true}));check(encoded===1,'Editor proxy validates its canonical source');
      encoded=0;T.forms.syncConstraints(form);check(encoded===250,'Full-form validation remains available before submission');
    } finally {TextEncoder.prototype.encode=encode;}
    const request=T.api.request;let sent;
    try {
      T.api.request=async(url,options)=>{check(new URL(url,location.href).pathname==='/api/local-audit','Fixture request stays intercepted');sent=options.body;return {data:{}};};
      await new Promise((resolve,reject)=>{T.forms.bind(form,{apply:resolve,error:reject});form.requestSubmit(submit);});
      check(sent.get('intent')==='publish'&&sent.getAll('intent').length===1,'Associated submitter is serialized once by bound forms');
    } finally {T.forms.dispose(form);form.remove();submit.remove();T.api.request=request;}
  } else if (mode === 'code-copy') {
    const value='  echo "žluťoučký <b>& test";\n\tsecond line\n';
    const form=T.ui.form('/api/copy-fixture','POST'),pre=document.createElement('pre'),code=document.createElement('code'),inline=document.createElement('code'),editor=document.createElement('div'),link=document.createElement('a');
    code.textContent=value;pre.append(code);inline.textContent='inline <value>';
    editor.contentEditable='true';editor.innerHTML='<code>draft</code>';link.href='#';link.innerHTML='<code>linked</code>';
    const draft=T.dom.element('input',{class:'input',name:'draft',value:'clean'});
    form.append(pre,inline,editor,link,draft,T.ui.button('common.save',{type:'submit'}));document.body.append(form);
    const writes=[];Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async text=>{writes.push(text);}}});
    T.lifecycle.start();T.lifecycle.hydrate(form);T.lifecycle.hydrate(form);
    const button=pre.querySelector('[data-copy-code]');
    check(button&&pre.querySelectorAll('[data-copy-code]').length===1&&form.querySelectorAll('[data-copy-code]').length===2,'Hydration adds exactly one copy action per readonly code');
    check(!editor.querySelector('button')&&!link.querySelector('button'),'Editable and linked code remain untouched');
    check(code.textContent===value&&pre.textContent===value,'Copy controls never enter the code text');
    let submits=0;form.addEventListener('submit',event=>{event.preventDefault();submits++;});
    button.click();await wait(()=>writes.length===1,'Clipboard API receives a write');
    check(writes[0]===value&&submits===0,'Copy preserves Unicode, markup literals and exact whitespace without submitting');
    const updated=document.createElement('code');updated.textContent='new\ncontent';code.replaceWith(updated);T.lifecycle.hydrate(pre);
    check(pre.querySelectorAll('[data-copy-code]').length===1,'Replacing code retargets its existing control');
    button.click();await wait(()=>writes.length===2,'Retargeted control writes');check(writes[1]===updated.textContent,'Copy reads current content instead of a cached value');
    const dialog=T.ui.adminDialog('code-copy-fixture','common.edit',form,false);document.body.append(dialog);T.dialog.open(dialog);T.lifecycle.hydrate(dialog);
    Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async()=>{throw Error('denied');}}});
    const previousCommand=document.execCommand;let fallbackText='',inside=false;
    document.execCommand=name=>{fallbackText=document.activeElement.value;inside=dialog.contains(document.activeElement);return name==='copy';};
    button.focus();button.click();await wait(()=>fallbackText.length>0,'Denied Clipboard API falls back to local selection');
    check(fallbackText===updated.textContent&&inside&&document.activeElement===button&&!dialog.querySelector('textarea.sr-only'),'Fallback stays in the dialog, cleans up and restores focus');
    check(!T.forms.dirty(dialog).length&&submits===0,'Copy does not dirty or submit the editor');
    draft.value='unsaved';draft.dispatchEvent(new Event('input',{bubbles:true}));
    document.execCommand=()=>false;document.querySelector('.toast-stack')?.remove();button.click();await wait(()=>!!document.querySelector('.toast-danger'),'Failed copying is reported as an error');
    check(!button.hasAttribute('aria-busy')&&document.activeElement===button,'Failure restores action state and focus');
    check(draft.value==='unsaved'&&T.forms.dirty(dialog).length===1,'Copy failure preserves an existing unsaved draft');
    let rejectPending,unexpectedFallback=false;
    Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:()=>new Promise((_resolve,reject)=>{rejectPending=reject;})}});
    document.execCommand=()=>{unexpectedFallback=true;return true;};button.click();await wait(()=>!!rejectPending,'Clipboard request can remain pending');
    T.dialog.close(dialog,{history:false});const focusAfterClose=document.activeElement;rejectPending(Error('denied'));await pause();
    check(!unexpectedFallback&&document.activeElement===focusAfterClose,'Disposing the source prevents late fallback and focus stealing');
    document.execCommand=previousCommand;
    document.getElementById('validation-test-results').textContent=JSON.stringify({ok:true,checks});return;
  }
  if (mode === 'scheduler-help') {
    T.lifecycle.start();
    const dialog=T.scheduler.modal(new URL('/api/scheduler/configuration',location.href),{data:{token:'fixture',execution_url:'/api/scheduler/executions',tasks:[{id:'cleanup',query_url:'/api/scheduler/triggers/fixture',http_command:'fixture-http',cli_command:'fixture-cli'}]}},'scheduler-configuration-native');
    T.dialog.open(dialog);
    const panel=dialog.querySelector('.modal-panel'),height=panel.getBoundingClientRect().height;
    const select=dialog.querySelector('[data-tab-select]');select.value='cleanup';select.dispatchEvent(new Event('change',{bubbles:true}));
    check(dialog.dataset.modalSize==='workspace'&&panel.getBoundingClientRect().height===height,'Configuration tabs use the same stable workspace frame as account and settings');
    check(!dialog.querySelector('header')&&!dialog.querySelector('.modal-body h3'),'Configuration panels omit repeated tab headings');
    const input=dialog.querySelector('[data-configuration-section="cleanup"] [data-scheduler-url]'),section=input.closest('[data-configuration-section]');
    check(!section.querySelector('details'),'Task sections contain no independent disclosure layout');
    const titleHint=input.closest('.field').querySelector('[data-help]');
    check(titleHint&&!section.querySelector('p [data-help]'),'Connection hints remain beside fields without scheduling cadence or its tooltip');
    titleHint.click();check(titleHint.getAttribute('aria-expanded')==='true'&&!section.hidden,'Clicking task help preserves its section');
    titleHint.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
    titleHint.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',bubbles:true}));
    check(titleHint.getAttribute('aria-expanded')==='true'&&!section.hidden,'Keyboard help preserves its section');
    titleHint.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
    const queryHint=input.closest('.field').querySelector('.label [data-help]');
    check(queryHint&&input.closest('.field').querySelector('.code-block'),'URL help describes its input beside the field caption');
    T.dialog.close(dialog,{history:false});
    document.getElementById('validation-test-results').textContent=JSON.stringify({ok:true,checks});return;
  }
  if (mode === 'field-types') {
    const form=T.ui.form('/api/fields','POST');document.body.append(form);
    const types={search:'search',password:'lock',email:'mail',url:'link',tel:'phone',date:'calendar',month:'calendar',week:'calendar',time:'clock','datetime-local':'calendar',text:null,number:null,checkbox:null};
    for(const [type,icon] of Object.entries(types)) {
      form.append(T.components.render('ui/field',{label:type,attributes:{class:type==='checkbox'?null:'input',name:type,type}}));
      const input=form.elements.namedItem(type),field=input.closest('.field'),svg=field.querySelector('.input-icon svg');
      check(icon ? svg&&svg.querySelector('use').getAttribute('href').endsWith('#'+icon) : !svg,'Semantic icon for '+type);
      check(input.labels.length===1&&input.labels[0]===field,'Label association retained for '+type);
    }
    const input=form.elements.namedItem('email');input.value='fixture@example.test';
    T.forms.applyErrors(form,{email:['Invalid address']});input.focus();
    check(getComputedStyle(input).backgroundImage.includes('linear-gradient')&&getComputedStyle(input).boxShadow!=='none','Invalid input has underline and danger focus');
    check(input.getAttribute('aria-describedby').includes(input.dataset.tinycatFieldErrorId),'Error remains described');
    T.forms.clearErrors(form);
    check(getComputedStyle(input).backgroundImage==='none'&&!input.hasAttribute('aria-invalid'),'Clearing validation restores normal input');
    check(new FormData(form).get('email')==='fixture@example.test','Icon preserves submitted values');
    const proxy=document.createElement('div');proxy.className='status-editor-input';proxy.contentEditable='true';proxy.dataset.validationFor='body';proxy.tabIndex=0;form.append(proxy);proxy.setAttribute('aria-invalid','true');proxy.focus();
    check(getComputedStyle(proxy).backgroundImage.includes('linear-gradient')&&getComputedStyle(proxy).boxShadow!=='none','Rich text uses the same error treatment');
    for(const density of ['regular','compact']) {
      document.documentElement.dataset.uiDensity=density;document.documentElement.style.setProperty('--ui-scale','1');const initial=input.getBoundingClientRect().height;
      document.documentElement.style.setProperty('--ui-scale','1.5');
      check(input.getBoundingClientRect().height>initial*1.35,'Field scales in '+density+' profile');
      check(document.documentElement.scrollWidth<=innerWidth,'No overflow in '+density+' profile: '+JSON.stringify([...form.querySelectorAll('*')].filter(e=>e.getBoundingClientRect().right>innerWidth).map(e=>[e.tagName,e.type,e.className,e.getBoundingClientRect().width])));
    }
    document.getElementById('validation-test-results').textContent=JSON.stringify({ok:true,checks});return;
  }
  if (mode === 'help-info' || mode === 'help-info-cs') {
    document.documentElement.dataset.uiDensity='compact';
    const requests=[];
    window.fetch=async(url,options)=>{
      requests.push(options.method);
      return new Response(JSON.stringify({ok:true,status:200,code:'ok',params:{},message:null,data:window.settingsSchema,meta:{}}),{headers:{'Content-Type':'application/json'}});
    };
    T.lifecycle.start();
    const settings=await T.settings.open('site');
    const siteLabel=settings.querySelector('[name="settings[site.name]"]').closest('.field').querySelector('.label').textContent.trim();
    check(siteLabel === (mode === 'help-info-cs' ? 'N\u00e1zev webu' : 'Site name'), 'Compiled settings metadata translates at render time');
    const smtpLabel=settings.querySelector('[name="settings[email.smtp][password]"]').closest('.field').querySelector('.label').textContent.trim();
    check(smtpLabel === T.i18n.t('settings.fields.smtp_password'), 'Nested SMTP metadata uses the current client locale');
    const hints=[...settings.querySelectorAll('[data-help]')];
    check(hints.length>0&&hints.every(hint=>hint.closest('.label')), 'Actual settings bundle places hints beside field labels');
    check(!settings.querySelector('.field > span.help'), 'Settings no longer render blocks of help below controls');
    const text='Literal <img src=x> '+('Long explanatory text with details. '.repeat(160));
    const form=T.ui.form('/api/help-fixture','POST');
    form.append(T.components.render('ui/field',{label:'Flag',help:text,help_id:'flag-help',attributes:{class:'input',name:'flag',type:'checkbox','aria-describedby':'kept-hint'}}));
    const kept=T.dom.element('span',{id:'kept-hint',hidden:true},'Existing description');form.append(kept,T.ui.button('common.save',{type:'submit'}));
    const dialog=T.ui.adminDialog('help-info-fixture','common.edit',form,false);document.body.append(dialog);T.dialog.open(dialog);
    T.lifecycle.hydrate(dialog);T.lifecycle.hydrate(dialog);
    const hint=dialog.querySelector('[data-help]'), control=form.elements.namedItem('flag');
    check(control.getAttribute('aria-label')==='Flag'&&control.getAttribute('aria-describedby').split(/\s+/).filter(id=>id==='flag-help').length===1&&control.getAttribute('aria-describedby').includes('kept-hint'), 'Hints preserve a clean field name and stable screen-reader descriptions across hydration');
    hint.click();
    let tip=document.getElementById(hint.getAttribute('aria-controls'));
    check(hint.getAttribute('aria-expanded')==='true'&&!tip.hidden&&tip.textContent===text&&!tip.querySelector('img'), 'Help opens as literal escaped text through the shared tooltip');
    check(!control.checked&&requests.every(method=>method==='GET'), 'Opening help neither toggles its checkbox nor submits a form');
    hint.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));
    check(tip.hidden&&dialog.isConnected&&dialog.dataset.open==='true', 'Escape closes help without closing its modal');
    hint.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',bubbles:true,cancelable:true}));
    check(!tip.hidden,'Enter opens help');
    hint.dispatchEvent(new KeyboardEvent('keydown',{key:' ',bubbles:true,cancelable:true}));
    check(tip.hidden,'Space closes help without submitting');
    function tap() { hint.dispatchEvent(new PointerEvent('pointerdown',{pointerType:'touch',bubbles:true,cancelable:true}));hint.dispatchEvent(new MouseEvent('click',{detail:1,bubbles:true,cancelable:true})); }
    tap();check(!tip.hidden&&!control.checked,'Touch opens help without label activation');
    tip.scrollTop=30;tip.dispatchEvent(new Event('scroll'));
    check(!tip.hidden&&tip.scrollHeight>tip.clientHeight&&tip.getBoundingClientRect().right<=innerWidth&&tip.getBoundingClientRect().bottom<=innerHeight,'Long help remains readable and scrolls locally within the viewport');
    tap();check(tip.hidden&&!control.checked,'A second touch closes help');
    T.forms.applyErrors(form,{flag:['Fixture error']});T.forms.clearErrors(form);
    check(control.getAttribute('aria-describedby').includes('flag-help')&&control.getAttribute('aria-describedby').includes('kept-hint'),'Validation feedback preserves help and other descriptions');
    hint.click();T.dialog.close(dialog,{history:false});
    check(!document.querySelector('.tooltip:not([hidden])')&&settings.isConnected,'Disposing a nested editor removes its help without disturbing its parent');
    T.dialog.close(settings,{history:false});
    document.getElementById('validation-test-results').textContent=JSON.stringify({ok:true,mode,checks});return;
  }
  if (mode === 'queue-edit') {
    document.body.classList.add('admin-shell-page');
    document.documentElement.dataset.uiDensity = 'compact';
    const data = {update_url:'/api/bots/accounts/2/posts/7',suggest_url:'/api/bots/accounts/2/posts/7/suggestions',item:{id:7,editable_body:'Scheduled post draft',author:{id:2,username:'bot'},capabilities:{edit:true}},constraints:window.socialConstraints,options:{images_enabled:true}};
    const dialog = T.modules.modal(new URL('/api/bots/accounts/2/posts/7/management',location.href),{data},'queue-edit-fixture');
    check(dialog && dialog.querySelector('form'), 'The delivered queue bundle opens the bot post editor');
    document.body.append(dialog); T.lifecycle.start(); T.dialog.open(dialog);
    const form = dialog.querySelector('form'), body = form.elements.namedItem('body');
    check(body.value === data.item.editable_body && form.getAttribute('action') === '/api/bots/accounts/2/posts/7', 'Queue editing retains the existing draft and canonical write endpoint');
      check(dialog.querySelector('[data-status-editor-source]').dataset.statusSuggestUrl === data.suggest_url, 'Queue editing uses internal suggestions independently of the public website');
    check(T.api.csrfToken() === 'test' && T.status.method(form,'update') === 'PATCH', 'Queue editing retains CSRF and its update method');
    check(dialog.querySelector('[data-status-editor-input]') && dialog.querySelector('input[type="file"]'), 'The delivered editor contains its rich text and image controls');
    const editor = dialog.querySelector('[data-status-editor-input]'), button = dialog.querySelector('button[type="submit"]'), panel = dialog.querySelector('.modal-panel'), modalBody = dialog.querySelector('.modal-body');
    const before = [editor.getBoundingClientRect().height,button.getBoundingClientRect().height,parseFloat(getComputedStyle(modalBody).paddingTop)];
    for (const scale of ['1.25','1.5','2']) {
      document.documentElement.style.setProperty('--ui-scale',scale);
      const after = [editor.getBoundingClientRect().height,button.getBoundingClientRect().height,parseFloat(getComputedStyle(modalBody).paddingTop)];
      check(after.every((size,index)=>size>before[index]), 'One UI scale value enlarges the editor, action buttons and modal spacing together: '+JSON.stringify({scale,before,after,font:getComputedStyle(document.documentElement).fontSize}));
      check(panel.getBoundingClientRect().width<=innerWidth&&panel.getBoundingClientRect().height<=innerHeight, 'An enlarged editor stays within the viewport: '+scale);
      check(button.getBoundingClientRect().right<=panel.getBoundingClientRect().right+1&&button.getBoundingClientRect().bottom<=innerHeight+1, 'Enlarged save controls remain reachable: '+scale);
      check(body.value===data.item.editable_body, 'Changing the UI scale preserves the editor draft: '+scale);
    }
    document.documentElement.style.removeProperty('--ui-scale');
    const card = document.createElement('div'); card.className='status-card'; card.dataset.statusId='7'; document.body.append(card);
    const originalCard = T.status.card, hydrated = [];
    T.status.card = () => { const node = document.createElement('div'); node.className='status-card'; node.dataset.statusId='7'; return node; };
    T.lifecycle.register('test-bot-mutation-hydration', {hydrate: root => { hydrated.push(root); }});
    window.fetch = async () => new Response(JSON.stringify({ok:true,status:200,code:'ok',params:{},message:null,data:{action:'update',item:{id:7}},meta:{}}),{status:200,headers:{'Content-Type':'application/json'}});
    button.click(); await wait(() => !card.isConnected && !form.dataset.apiBusy, 'Bot post edit applies its mutation');
    const replacement = document.querySelector('.status-card[data-status-id="7"]');
    check(hydrated.length === 1 && hydrated[0] === replacement, 'Bot post edit hydrates only its replacement once through the shared binder: '+JSON.stringify(hydrated.map(node=>node.className || node.nodeName)));
    T.status.card = originalCard; replacement.remove();
    if (dialog.isConnected) T.dialog.close(dialog,{history:false});
    document.getElementById('validation-test-results').textContent=JSON.stringify({ok:true,mode,checks}); return;
  }
  if (mode === 'health-shell') {
    check(T.adminDefinitions && T.adminDefinitions['core.scheduler-runs'] && !T.adminDefinitions['core.health-history'], 'Health actions use the standard scheduler bundle without a separate history declaration');
    const toggle = T.dom.element('button',{'data-admin-nav-toggle':'',type:'button'});
    const sidebar = T.dom.element('aside',{'data-admin-sidebar':''});
    sidebar.append(T.dom.element('a',{href:'#'},'Account')); document.body.append(toggle,sidebar);
    window.fetch = async (url,options) => {
      const pathname = new URL(url,location.href).pathname;
      if (pathname === '/api/settings/editor') return new Response(JSON.stringify({ok:true,status:200,code:"ok",params:{},message:null,data:window.settingsSchema,meta:{}}),{headers:{'Content-Type':'application/json'}});
      check(pathname === '/api/site-health/checks' && options.method === 'POST', 'Health checks use the shared POST transport');
      sessionStorage.setItem('health-ajax','true');
      return new Response(JSON.stringify({ok:true,status:201,code:'health_check_finished',params:{},message:null,data:{execution:{ok:true},redirect:'/admin/cron?task=site-health'},meta:{}}),{status:201,headers:{'Content-Type':'application/json'}});
    };
    T.lifecycle.start(); toggle.click();
    check(document.body.classList.contains('admin-nav-open'), 'Health hamburger navigation opens');
    toggle.click(); check(!document.body.classList.contains('admin-nav-open'), 'Health hamburger navigation closes');
    const dialog = await T.settings.open('site');
    check(dialog.isConnected && dialog.querySelector('form'), 'Settings modal opens from the detail-only health bundle');
    T.dialog.close(dialog,{history:false});
    const local = T.urls.local;
    let canonicalRedirect = false;
    T.urls.local = function (url,locale) {
      if (url === '/admin/cron?task=site-health') { canonicalRedirect = true; return '#health-complete'; }
      return local(url,locale);
    };
    const form = document.querySelector('form[data-ajax-form]');
    form.querySelector('button').click();
    await wait(() => canonicalRedirect && location.hash === '#health-complete', 'Health AJAX completion follows its canonical UI redirect');
    check(sessionStorage.getItem('health-ajax') === 'true', 'The health action never falls back to a native API page submission');
    document.getElementById('validation-test-results').textContent = JSON.stringify({ok:true,mode,checks});
    return;
  }
  if (mode === 'social-forms') {
    check(!T.publishing.controllers.editor && !T.accounts.avatarUpload, 'The public bundle initially excludes editor implementations');
    await T.deferred.resource(new URL('/api/moderation/report-options', location.href));
    check(T.publishing.controllers.editor && T.accounts.avatarUpload, 'The native browser loads editor implementations on demand');
    const follow=T.dom.element('form',{method:'post',action:'/api/profiles/2/followers/me','data-follow-form':'','data-author-id':'2'});
    follow.append(T.ui.hidden('action','follow'),T.ui.button('public.follow',{type:'submit'})); document.body.append(follow);
    const react=T.dom.element('form',{method:'post',action:'/api/statuses/7/likes/me','data-status-form':'','data-status-id':'7'});
    const like=T.ui.button('common.save',{type:'submit','data-status-like-button':'','aria-pressed':'false'});
    react.append(T.ui.hidden('action','react'),like); document.body.append(react);
    const calls=[]; let release;
    window.fetch=async(url,options)=>{
      const method=options.body.get('_method'), isFollow=new URL(url).pathname.includes('/followers/');
      calls.push(method);
      if(calls.length===1) await new Promise(resolve=>{release=resolve;});
      const data=isFollow?{author_id:2,following:method==='PUT',followers_count:method==='PUT'?1:0}:{action:'react',status:{id:7,liked:method==='PUT',likes_count:method==='PUT'?1:0}};
      return new Response(JSON.stringify({ok:true,status:200,code:'ok',params:{},message:null,data,meta:{}}),{status:200,headers:{'Content-Type':'application/json'}});
    };
    T.lifecycle.start(); T.lifecycle.hydrate(document); follow.querySelector('button').click(); await wait(()=>!!release,'Social forms reach the shared transport');
    follow.requestSubmit(); await pause(); check(calls.length===1,'A pending follow cannot send a second mutation'); release();
    await wait(()=>follow.elements.namedItem('action').value==='unfollow'&&!follow.dataset.apiBusy,'Following updates the domain state and releases the shared lock');
    follow.querySelector('button').click(); await wait(()=>calls.length===2&&!follow.dataset.apiBusy,'Unfollowing uses the current state after the first result');
    like.click(); await wait(()=>like.getAttribute('aria-pressed')==='true'&&!react.dataset.apiBusy,'The shared lifecycle applies a status reaction');
    like.click(); await wait(()=>calls.length===4&&!react.dataset.apiBusy,'A second status reaction uses the updated state');
    T.forms.setSubmitGuard(react,()=>false); like.click(); await pause(); check(calls.length===4&&!react.dataset.apiBusy,'A declined domain submit guard preserves state without a network mutation'); T.forms.setSubmitGuard(react,null);
    check(calls.join(',')==='PUT,DELETE,PUT,DELETE','Follow and status mutations use one transport with explicit desired-state methods');
    check(!follow.querySelector('button').disabled&&!like.disabled&&!follow.hasAttribute('aria-busy'),'Shared loading restores both Social forms');
    document.getElementById('validation-test-results').textContent=JSON.stringify({ok:true,mode,checks}); return;
  }
  if (mode === 'social-constraints') {
    const constraints = window.socialConstraints, form = T.ui.form('/api/statuses', 'POST');
    form.append(T.components.render('status/composer-body', {constraints,viewer:{id:1},value:''}));
    const commentForm = T.components.render('status/comment-form', {constraints,content_id:7,parent_id:0,viewer:{id:1}});
    check(new FormData(commentForm).get('parent_id') === '0', 'Root comments submit numeric zero through native FormData');
    const replyForm = T.components.render('status/comment-form', {constraints,content_id:7,parent_id:20,viewer:{id:1}});
    check(new FormData(replyForm).get('parent_id') === '20', 'Replies retain their parent ID through native FormData');
    document.body.append(form, commentForm); T.lifecycle.start(); T.publishing.controllers.editor.hydrate(document);
    for (const [host,name] of [[form,'body'],[commentForm,'comment']]) {
      const source=host.elements.namedItem(name), editor=source.closest('[data-status-editor]').querySelector('[data-status-editor-input]');
      check(Number(source.dataset.maxLength)===constraints[name].max_length&&!source.hasAttribute('maxlength'),'Social '+name+' uses server-derived Unicode constraints');
      editor.textContent='\u{1F642}'.repeat(constraints[name].max_length); editor.dispatchEvent(new Event('input',{bubbles:true}));
      check(T.forms.validate(host),'Social '+name+' accepts the exact emoji character boundary');
      editor.textContent+='\u{1F642}'; editor.dispatchEvent(new Event('input',{bubbles:true}));
      check(!T.forms.validate(host)&&Array.from(source.value).length===constraints[name].max_length+1,'Social '+name+' retains its overflowing draft and rejects it through the common validator');
    }
    T.forms.clearErrors(commentForm);
    const draft = commentForm.elements.namedItem('comment').value;
    const summary = T.forms.applyErrors(commentForm, {parent_id:['The parent is unavailable.']});
    check(summary.getBoundingClientRect().width >= commentForm.clientWidth - 1, 'A hidden-field error spans the complete comment form instead of its avatar column');
    check(commentForm.elements.namedItem('comment').value === draft, 'A form-level comment error preserves the draft');
    document.getElementById('validation-test-results').textContent=JSON.stringify({ok:true,mode,checks}); return;
  }
  if (mode === 'constraints') {
    const c = window.inputConstraints, form = T.ui.form('/api/constraint-fixture', 'POST');
    form.append(T.ui.field('Title', 'title', '', 'input', {'data-max-length':c.title.max_length,required:c.title.required}));
    form.append(T.components.render('publishing/editor',{name:'body',value:'',max_bytes:c.body.max_bytes,required:c.body.required}));
    form.append(T.ui.field('Interval','interval',String(c.interval.min),'input',{type:'number',min:c.interval.min,max:c.interval.max,required:c.interval.required}));
    form.append(T.components.render('publishing/editor',{name:'comment',value:'\u{1F600}\u{1F600}',max_characters:c.comment.max_length,required:true}));
    const submit = T.ui.button('common.save',{type:'submit'}); form.append(submit);
    const dialog = T.ui.adminDialog('constraint-fixture','common.edit',form,false); document.body.append(dialog);
    let calls=0; window.fetch=async()=>{ calls++; return new Response(JSON.stringify({ok:true,status:201,code:'ok',params:{},message:null,data:{},meta:{}}),{status:201,headers:{'Content-Type':'application/json'}}); };
    T.lifecycle.start(); T.publishing.controllers.editor.hydrate(document); T.dialog.open(dialog);
    const title=form.elements.namedItem('title'), body=form.elements.namedItem('body'), editor=form.querySelector('[data-status-editor-input]');
    const input=(field,value)=>{ if(field.isContentEditable) field.textContent=value; else field.value=value; field.dispatchEvent(new Event('input',{bubbles:true})); };
    const comment=form.elements.namedItem('comment'), commentEditor=comment.closest('[data-status-editor]').querySelector('[data-status-editor-input]');
    input(commentEditor,'\u{1F600}\u{1F600}\u{1F600}');
    check(comment.value==='\u{1F600}\u{1F600}\u{1F600}'&&!comment.hasAttribute('maxlength'),'Character budgets preserve an overflowing Unicode draft without a UTF-16 cap');
    check(!T.forms.validate(form),'The shared validator rejects an extra Unicode character in the rich editor');
    input(commentEditor,'\u{1F600}\u{1F600}');
    input(title,'😀😀'); input(editor,'ěěě');
    check(!title.hasAttribute('maxlength') && !body.hasAttribute('maxlength'), 'Unicode and byte constraints do not impose a conflicting UTF-16 maxlength');
    check(T.forms.validate(form) && body.value==='ěěě', 'Server-derived character and byte boundaries accept the exact Unicode limits');
    input(editor,'ěěěě'); submit.click(); await pause();
    check(calls===0 && body.value==='ěěěě' && editor.getAttribute('aria-invalid')==='true','Byte overflow blocks submission through the shared field proxy without truncating the draft');
    input(editor,'ěěě'); input(title,'😀😀😀'); check(!T.forms.validate(form),'Unicode length rejects one extra code point'); await pause();
    input(title,'😀😀'); input(form.elements.namedItem('interval'),String(c.interval.min-1)); check(!T.forms.validate(form),'Server-derived numeric lower bounds use native validation'); await pause();
    input(form.elements.namedItem('interval'),String(c.interval.max)); submit.click(); await wait(()=>calls===1,'A corrected boundary submission sends exactly one request');
    check(!form.querySelector('[aria-invalid="true"]'),'Correcting constraints clears their shared validation feedback');
    document.getElementById('validation-test-results').textContent=JSON.stringify({ok:true,mode,checks}); return;
  }
  if (mode === 'creation') {
    const defaults = T.dom.element('form');
    const text = T.dom.element('input', { name: 'name' }), checkbox = T.dom.element('input', { name: 'enabled', type: 'checkbox' });
    const select = T.dom.element('select', { name: 'choice' }), note = T.dom.element('textarea', { name: 'note' });
    select.append(new Option('First', 'first'), new Option('Second', 'second'));
    defaults.append(text, checkbox, select, note);
    text.value = 'prepared'; checkbox.checked = true; select.value = 'second'; note.value = 'prepared note';
    T.ui.filterPanel('fixture-filter-defaults', defaults);
    text.value = 'draft'; checkbox.checked = false; select.value = 'first'; note.value = 'draft note';
    T.filters.hydrate(); defaults.reset();
    check(text.value === 'prepared' && checkbox.checked && select.value === 'second' && note.value === 'prepared note', 'Shared filters restore prepared text, checkbox, select and textarea defaults without redefining them on hydration');
    text.value = 'synced'; checkbox.checked = false; T.filters.defaults(defaults, ['name']); defaults.reset();
    check(text.value === 'synced' && checkbox.checked, 'Partial filter synchronization preserves unrelated reset defaults');
    T.filters.remove('fixture-filter-defaults');
    const options = {username_pattern:'[A-Za-z][A-Za-z0-9_]+',username_min_length:2,username_max_length:32,email_max_bytes:254,bio_max_length:500,password_min_bytes:8,password_max_length:1024,roles:['admin','user'],statuses:['active','ban']};
    const listing = {items:[],filters:{},options,roles:['admin','user'],statuses:['active','ban']};
    const reads = {};
    window.fetch = async (url, config) => {
      const path = new URL(url).pathname;
      reads[path] = (reads[path] || 0) + 1;
      const data = path === '/api/users/creation' ? {user:{},options} : path === '/api/bots/accounts/creation' ? {options} : path === '/api/updates/release-notes' ? {notes:'<img src=x onerror=alert(1)>Literal release notes'} : path === '/api/users' && config.method === 'GET' ? listing : {item:{id:8}};
      return new Response(JSON.stringify({ok:true,status:200,code:'ok',params:{},message:null,data,meta:{pagination:{page:1,per_page:25,total:0}}}),{status:200,headers:{'Content-Type':'application/json'}});
    };
    let initBlocked = false;
    T.lifecycle.register('eager-modal-negative-test',{init:() => {
      try { T.dialog.create({id:'forbidden',title:'Forbidden',closeLabel:'Close',content:document.createElement('div')}); }
      catch (error) { initBlocked = /initialization or hydration/.test(error.message); }
    }});
    T.lifecycle.start();
    check(initBlocked && !document.querySelector('.modal'), 'Initialization cannot construct a hidden modal');
    const install = T.components.render('installation/admin', {username_pattern:options.username_pattern,username_hint:'',password_min:options.password_min_bytes,password_max:options.password_max_length});
    document.body.append(install); T.lifecycle.hydrate(install);
    const installPassword = install.querySelector('[name="password"]');
    installPassword.value='éééé'; T.forms.syncConstraints(installPassword);
    check(installPassword.checkValidity() && !installPassword.hasAttribute('minlength'), 'Pre-database installer accepts the eight-byte Unicode password boundary');
    installPassword.value='ééé'; T.forms.syncConstraints(installPassword);
    check(!installPassword.checkValidity(), 'Pre-database installer rejects a password below the byte minimum');
    T.lifecycle.dispose(install); install.remove();
    const profileForm = T.components.render('users/account-form', {dialog_id:'identity-constraints',user:{id:2,bio:'',locale:'en',theme:'system'},languages:{en:'English'},options});
    document.body.append(profileForm); T.lifecycle.hydrate(profileForm);
    const bio = profileForm.elements.namedItem('bio'); bio.value = '😀'.repeat(options.bio_max_length);
    T.forms.syncConstraints(bio); check(bio.checkValidity() && !bio.hasAttribute('maxlength'), 'Bio accepts the Unicode code point boundary without a UTF-16 limit');
    bio.value += '😀'; T.forms.syncConstraints(bio);
    check(!bio.checkValidity() && Array.from(bio.value).length === options.bio_max_length + 1, 'An overlong bio is rejected without truncating the draft');
    T.lifecycle.dispose(profileForm); profileForm.remove();
    const list = T.dom.element('div',{id:'users-list'}); document.body.append(list);
    T.modules.result({dataset:{apiMethod:"GET"},getAttribute:name=>name==="href"?"/api/users":null},{data:listing,meta:{pagination:{page:1,per_page:25,total:0}}},list);
    T.lifecycle.hydrate(list);
    check(!document.querySelector('.modal') && !Object.keys(reads).length, 'List hydration contains filters but no creation modal or resource read');
    const editor = await T.dialog.openResource('modal-users-creation','/api/users/creation');
    const username = editor.querySelector('[name="username"]'); username.value='UnsentAccount'; username.dispatchEvent(new Event('input',{bubbles:true}));
    check(username.dataset.minLength === String(options.username_min_length) && username.dataset.maxLength === String(options.username_max_length) && !username.hasAttribute('maxlength'), 'Account creation derives Unicode username bounds from Identity.');
    const pass = editor.querySelector('[name="password"]'); pass.value = 'éééé'; T.forms.syncConstraints(pass);
    check(pass.checkValidity() && !pass.hasAttribute('minlength'), 'An eight-byte Unicode password passes the owner byte minimum.');
    pass.value = 'ééé'; T.forms.syncConstraints(pass); check(!pass.checkValidity(), 'A seven-or-fewer-byte password fails the same minimum.'); pass.value = '';
    T.modules.result({dataset:{apiMethod:"GET"},getAttribute:name=>name==="href"?"/api/users":null},{data:listing,meta:{pagination:{page:1,per_page:25,total:0}}},list); T.lifecycle.hydrate(list);
    check(document.getElementById('modal-users-creation') === editor && username.value === 'UnsentAccount', 'Refreshing the list preserves an open creation draft');
    const confirmation = T.dialog.confirm; T.forms.confirmWith(async () => false);
    const trigger = T.dom.element('button',{'data-modal-open':'modal-users-creation','data-modal-url':'/api/users/creation','data-modal-refresh':'true'}); document.body.append(trigger); trigger.click(); await pause();
    check(reads['/api/users/creation'] === 1 && editor.isConnected && username.value === 'UnsentAccount', 'Forced resource refresh cannot bypass the unsaved-change guard');
    const normalFetch = window.fetch; let releaseReload;
    window.fetch = (url,config) => new URL(url).pathname === '/api/users/creation' ? new Promise(resolve => { releaseReload = () => normalFetch(url,config).then(resolve); }) : normalFetch(url,config);
    T.forms.confirmWith(async () => true); trigger.click();
    await wait(() => Boolean(releaseReload), 'An accepted refresh may begin its resource read');
    username.value='NewerDraft'; username.dispatchEvent(new Event('input',{bubbles:true})); releaseReload();
    await wait(() => trigger.dataset.modalBusy !== 'true', 'The pending replacement settles');
    check(editor.isConnected && username.value === 'NewerDraft', 'Edits made while a refresh is loading cannot be overwritten by its late response');
    window.fetch = normalFetch;
    T.forms.confirmWith(confirmation);
    editor.querySelector('[name="password"]').value='TestPassword123!';
    editor.querySelector('.modal-footer [type="submit"]').click();
    await wait(() => !editor.isConnected, 'Successful account creation closes and disposes its resource editor');
    const beforeReopen = reads['/api/users/creation'];
    const again = await T.dialog.openResource('modal-users-creation','/api/users/creation');
    check(reads['/api/users/creation'] === beforeReopen + 1 && again !== editor && !again.querySelector('[name="username"]').value, 'Reopening creation fetches a fresh empty form');
    T.dialog.close(again,{history:false});
    const bot = await T.dialog.openResource('modal-bots-accounts-creation','/api/bots/accounts/creation');
    check(bot.querySelector('[name="username"]').pattern === options.username_pattern && bot.querySelector('form').getAttribute('action') === '/api/bots/accounts', 'Bot creation uses remote constraints and its existing POST resource');
    check(document.getElementById(bot.querySelector('[name="username"]').getAttribute('aria-describedby')), 'Bot username constraints retain an accessible help description');
    T.dialog.close(bot,{history:false});
    const notes = await T.dialog.openResource('modal-updates-release-notes','/api/updates/release-notes');
    check(notes.querySelector('code').textContent.includes('<img') && !notes.querySelector('img'), 'Release notes load on demand as safe literal text');
    T.dialog.close(notes,{history:false});
    check(!document.querySelector('.modal'), 'All resource dialogs are removed after closing');
    document.getElementById('validation-test-results').textContent=JSON.stringify({ok:true,mode,checks}); return;
  }
  if (mode === 'settings' || mode === 'settings-module') {
    if (mode === 'settings-module') {
      window.settingsSchema = {sections: window.moduleSettings.sections};
    }
    const schema = window.settingsSchema || {sections:[
      {key:'site',label:'Site',icon:'home',fields:[{key:'site.name',label:'Name',type:'text',value:'Current site'},{key:'site.meta_description',label:'Description',type:'textarea',value:'Saved description'}]},
      {key:'email',label:'Email',icon:'mail',fields:[{key:'email.smtp',label:'SMTP',type:'email_smtp',value:{host:'smtp.example.test',port:587}}]}
    ]};
    for (const section of schema.sections) for (const field of section.fields) {
      if (field.key === 'site.name') field.value = 'Current site';
      if (field.key === 'site.meta_description') field.value = 'Saved description';
      if (field.key === 'email.smtp') field.value = {host:'smtp.example.test',port:587};
    }
    let reads=0, writes=0;
    window.fetch = async (url, options) => {
      const get = new URL(url).pathname === '/api/settings/editor';
      if (get) reads++; else writes++;
      if (!get) {
        check(!options.body.has('settings[email.smtp][host]'), 'Saving Site excludes the Email draft');
        check(options.body.get('settings[site.meta_description]') === 'Edited description', 'Settings submit the textarea draft');
      }
      return new Response(JSON.stringify({ok:true,status:200,code:'ok',params:{},message:null,data:schema,meta:{}}),{status:200,headers:{'Content-Type':'application/json'}});
    };
    T.lifecycle.start();
    check(reads === 0 && !document.querySelector('#modal-settings-editor'), 'Initial hydration neither requests nor creates settings');
    const dialog = await T.settings.open('email');
    check(reads === 1 && dialog.dataset.modalDisposeOnClose === 'true', 'Settings are fetched only when opened and are disposable');
    check(!dialog.querySelector('[data-tab-panel="email"]').hidden && location.hash.includes('modal=settings/editor'), 'Requested section and canonical resource history are shared');
    const control = name => [...dialog.querySelectorAll('form')].map(item => item.elements.namedItem(name)).find(Boolean), description = control('settings[site.meta_description]');
    for (const section of schema.sections) for (const spec of section.fields) {
      const suffix = spec.type === 'email_smtp' ? '[host]' : spec.type === 'email_templates' ? '[_present]' : '';
      check(Boolean(control('settings['+spec.key+']'+suffix)), 'The editor retains the submitted field for '+spec.key);
    }
    check(dialog.querySelectorAll('.image-preview-contain').length === 2 && dialog.querySelector('.grid-span-full'), 'Site images and full-width settings retain shared layout primitives');
    check([...dialog.querySelectorAll('.field > .label')].every(label => label.textContent.trim()), 'Every server field has a client-owned presentation label');
    check(description.value === 'Saved description' && control('settings[email.smtp][port]').value === '587', 'Textareas and nested SMTP values hydrate correctly');
    if (mode === 'settings-module') {
      check([...dialog.querySelectorAll('form')].every(item => item.checkValidity()), 'Unchanged Fixture defaults pass native form validation');
      for (const key of ['fixture.blocked_urls','fixture.links.utm.source','fixture.links.utm.campaign']) {
        const field = control('settings['+key+']');
        check(field.value === '' && !field.required, 'Fixture optional settings retain valid empty defaults: '+key);
      }
      check(control('settings[fixture.links.utm.medium]').required, 'Fixture UTM medium retains required native validation');
    }
    description.value='Edited description'; description.dispatchEvent(new Event('input',{bubbles:true}));
    const emailDraft = control('settings[email.smtp][host]'); emailDraft.value='unsaved.example.test'; emailDraft.dispatchEvent(new Event('input',{bubbles:true}));
    check(T.forms.dirty(dialog).length === 2, 'Settings sections use independent shared draft guards');
    check(dialog.querySelectorAll('[data-tab-dirty]').length === 2, 'Every changed section has a dirty marker');
    check(dialog.querySelector('[data-tab-select] option[value="email"]').textContent.endsWith(' *'), 'Mobile sections show the same dirty marker');
    const confirmation = T.dialog.confirm;
    T.forms.confirmWith(async () => false);
    check(await T.dialog.requestClose(dialog,{history:false}) === false && dialog.isConnected && description.value === 'Edited description', 'Declining close preserves the complete settings draft');
    try { await T.dialog.refresh(dialog); } catch (error) { check(error.name === 'AbortError', 'Declining refresh cancels resource replacement'); }
    check(reads === 1, 'A rejected refresh does not request data');
    T.forms.confirmWith(confirmation);
    T.tabs.activate(dialog.querySelector('[data-tab="site"]'), false);
    dialog.querySelector('.modal-footer [type="submit"]').click();
    await wait(() => writes === 1 && T.forms.dirty(dialog).length === 1, 'Saving clears only the submitted section draft');
    check(emailDraft.value === 'unsaved.example.test', 'Saving Site preserves the Email draft');
    check(!dialog.querySelector('[data-tab="site"] [data-tab-dirty]') && dialog.querySelector('[data-tab="email"] [data-tab-dirty]'), 'Saving clears only the saved section marker');
    emailDraft.value='smtp.example.test'; emailDraft.dispatchEvent(new Event('input',{bubbles:true}));
    check(!dialog.querySelector('[data-tab-dirty]') && !dialog.querySelector('[data-tab-select] option[value="email"]').textContent.endsWith(' *'), 'Restoring original values clears desktop and mobile markers');
    check(control('settings[site.meta_description]').value === 'Saved description', 'Saved controls reconcile the canonical resource');
    const currentHash = location.hash, entries = history.length;
    const refreshed = await T.dialog.refresh(dialog);
    check(reads === 2 && refreshed !== dialog && location.hash === currentHash && history.length === entries && !refreshed.querySelector('[data-tab-panel="site"]').hidden, 'Shared refresh retains tab and history without adding an entry');
    check(refreshed.querySelectorAll('[data-modal-resource-refresh]').length === 1, 'Resource editor exposes one common refresh action');
    T.tabs.activate(refreshed.querySelector('[data-tab="email"]'), false);
    check(location.hash.includes('tab=email'), 'A refreshed editor retains live tab history binding');
    T.dialog.close(refreshed);
    await wait(() => !location.hash, 'Closing a refreshed editor consumes its original history entry');
    check(!document.querySelector('#modal-settings-editor'), 'Closing settings disposes the editor DOM');
    const reopened = await T.settings.open('site');
    check(reads === 3 && reopened !== dialog, 'Reopening settings fetches current server data');
    T.dialog.close(reopened,{history:false});
    document.getElementById('validation-test-results').textContent = JSON.stringify({ok:true,mode,checks});
    return;
  }
  if (mode === 'moderation') {
    const author = {id:1,username:'Evidence author',url:'/author/1',avatar_url:'',avatar_initial:'E'};
    const post = {id:42,url:'/status/42',author,segments:[{type:'text',text:'Original frontend card'}],published_at:'2026-01-01',image:{url:''},links:[],likes_count:3,comments_count:1,capabilities:{edit:false,delete:false,report:false,interact:false}};
    const data = {content:{id:42,status:'hidden'},preview:{item:post,comments:[{id:9,content_id:42,author,created_at:'2026-01-01',segments:[{type:'text',text:'Preserved comment'}],likes_count:1,capabilities:{edit:false,delete:false,interact:false},replies:[]}]},reports:[{id:12,reporter_name:'Reporter',reason:'spam',status:'resolved',created_at:'2026-01-01',note:'Full note <b>literal</b>'}]};
    T.modules.register('moderation',T.moderation);
    const dialog = T.moderation.modal(new URL('/api/moderation/reports/12',location.href),{data},'moderation-native');
    T.lifecycle.start(); T.dialog.open(dialog);
    check(dialog.querySelector('.status-card .status-body').textContent === 'Original frontend card', 'Moderation renders the frontend card');
    check(dialog.querySelector('.status-comment-body').textContent === 'Preserved comment', 'Moderation includes the actual frontend comment thread');
    check(!dialog.querySelector('[data-tab-panel="preview"] form'), 'The evidence preview has no mutation forms');
    check(dialog.querySelector('form').action.includes('/api/moderation/posts/42/publication'), 'Only an explicit admin publication action can restore hidden content');
    T.tabs.activate(dialog.querySelector('[data-tab="reports"]'),false);
    const table = dialog.querySelector('table'), eye = table.querySelector('[data-modal-url]');
    check(table.querySelectorAll('th').length === 5 && table.querySelectorAll('tbody tr').length === 1, 'Report history uses the standard table columns and rows');
    check(eye.classList.contains('btn-icon') && eye.querySelector('svg'), 'Every row uses the standard icon preview action');
    window.fetch = async () => new Response(JSON.stringify({ok:true,status:200,code:'ok',params:{},message:null,data,meta:{}}),{status:200,headers:{'Content-Type':'application/json'}});
    eye.click();
    await wait(() => T.dialog.current() !== dialog, 'The eye action opens the nested report preview');
    const detail = T.dialog.current();
    check(detail.textContent.includes(data.reports[0].note) && !detail.querySelector('script'), 'The report preview renders the complete note as safe text');
    T.dialog.close(detail);
    check(T.dialog.current() === dialog && !dialog.querySelector('[data-tab-panel="reports"]').hidden, 'Closing the preview returns to the report table');
    check(document.documentElement.scrollWidth <= innerWidth, 'The standard report table fits the mobile modal viewport');
    document.getElementById('validation-test-results').textContent = JSON.stringify({ok:true,mode,checks});
    return;
  }
  if (mode === 'report') {
    const dialog = T.status.modal(new URL('/api/moderation/report-options?id=42', location.href), {data:{reasons:['spam','abuse']}}, 'status-report-modal-42');
    const form = dialog.querySelector('form'), submit = dialog.querySelector('.modal-footer [type="submit"]');
    document.body.append(dialog);
    check(form.elements.namedItem('id').value === '42', 'The report retains its content ID field');
    check(submit.form === form, 'The footer submit is associated with the report form despite its named id control');
    let calls = 0;
    window.fetch = async (url, options) => {
      calls++;
      check(new URL(url).pathname === '/api/moderation/reports' && options.method === 'POST', 'Reporting uses the canonical POST endpoint');
      check(options.body.get('id') === '42' && options.body.get('reason') === 'abuse' && options.body.get('note') === 'Report fixture', 'Reporting sends the content ID, selected reason and note');
      return new Response(JSON.stringify({ok:true,status:201,code:'report_created',params:{},message:'Report sent',data:{action:'report',status_id:42},meta:{}}), {status:201,headers:{'Content-Type':'application/json'}});
    };
    T.lifecycle.start(); T.status.controllers.actions.initStatusForms(); T.dialog.open(dialog);
    form.elements.namedItem('reason').value = 'abuse';
    form.elements.namedItem('note').value = 'Report fixture';
    submit.click();
    await wait(() => calls === 1 && !T.dialog.current(), 'A report submission sends one request and closes the modal after success');
    document.getElementById('validation-test-results').textContent = JSON.stringify({ok:true,mode,checks});
    return;
  }
  const form = T.ui.form('/api/users', 'POST'); form.dataset.modalCloseOnSuccess = 'true';
  form.append(T.components.render('users/admin-fields', {user:{},create:true,options:{username_pattern:'[A-Za-z0-9_]+',role_options:{admin:'Admin',user:'User'},password_max_length:1024}}));
  const accountSection = form.querySelector('[data-editor-section="account"]');
  if (mode === 'nested') {
    const nested = T.ui.field('Title', 'settings[site_title]', 'Draft');
    nested.querySelector('input').setAttribute('aria-describedby','retained-hint'); accountSection.append(nested);
    const help = document.createElement('span'); help.id='retained-hint'; help.textContent='Original help'; nested.append(help);
  }
  if (mode === 'proxy') {
    const root = T.dom.element('div', {'data-status-editor':''});
    root.append(T.ui.field('Body','body','Draft','textarea', {id:'body-source',maxlength:'500','data-status-editor-source':''})); accountSection.append(root);
  }
  form.append(T.ui.button('common.create', {type:'submit'}));
  const dialog = T.ui.sectionedDialog('user-create-modal', 'New user', form, true);
  check(new FormData(form).get('status') === 'active', 'Tabbed editors preserve hidden controls outside their sections');
  const username = form.elements.namedItem('username'), password = form.elements.namedItem('password');
  password.value = mode === 'native' ? '' : 'Test password 123'; username.value = mode === 'native' ? '' : 'ValidUser';
  const errorName = mode === 'scroll' ? 'password' : mode === 'nested' ? 'settings.site_title' : mode === 'proxy' ? 'body' : mode === 'summary' ? 'unmapped' : 'username';
  let calls = 0, submittedStatus;
  window.fetch = async (url, options) => {
    calls++; submittedStatus = options.body.get('status');
    const ok = mode === 'success' && submittedStatus === 'active';
    const status = ok ? 201 : 422;
    const errors = {[errorName]:[{code:'test_validation',message:'Specific validation message.',params:{}}]};
    return new Response(JSON.stringify({ok,status,code:ok?'created':'validation_error',params:{},message:ok?null:'Check the fields.',data:ok?{}:null,meta:{},error:ok?null:{details:{errors}}}),{status,headers:{'Content-Type':'application/json'}});
  };
  T.lifecycle.start(); T.publishing.controllers.editor.hydrate(document); T.dialog.open(dialog);
  const access = [...dialog.querySelectorAll('[data-tab]')].find(tab => tab.dataset.tab === 'access');
  if (mode === 'scroll') {
    const spacer = document.createElement('div'); spacer.style.height='1100px'; spacer.style.flexShrink='0'; password.closest('.field').before(spacer);
  } else T.tabs.activate(access, false);
  const submit = dialog.querySelector('.modal-footer [type="submit"]'); submit.click();
  if (mode === 'success') {
    await wait(() => calls === 1 && !T.dialog.current() && !location.hash, 'A valid new user submission succeeds instead of producing an invisible status error');
    check(submittedStatus === 'active', 'The create-user payload retains its required status');
  } else {
    await wait(() => !!dialog.querySelector('[data-field-error]'), 'Validation feedback is rendered in the modal');
    await pause();
    const expected = mode === 'summary' ? dialog.querySelector('[role="alert"]') : mode === 'nested' ? form.elements.namedItem('settings[site_title]') : mode === 'proxy' ? dialog.querySelector('[data-status-editor-input]') : mode === 'scroll' ? password : username;
    const panel = mode === 'scroll' ? 'access' : 'account';
    if (mode !== 'summary') {
      check(!dialog.querySelector('[data-tab-panel="'+panel+'"]').hidden, 'Validation reveals the tab containing the first invalid field');
      check(expected.getAttribute('aria-invalid') === 'true', 'Validation marks the visible invalid field');
      check(dialog.querySelector('[data-tab-select]').value === panel, 'Validation synchronizes the mobile section selector');
      const message = dialog.querySelector('[data-field-error]');
      check(getComputedStyle(expected).borderTopColor === getComputedStyle(message).color, 'Invalid styling uses the shared danger color even while focused');
    }
    check(document.activeElement === expected, 'Validation focuses the actionable field or form summary');
    check(mode === 'native' ? calls === 0 : calls === 1 && submittedStatus === 'active', 'Native failures stay local and server requests preserve hidden form state');
    if (mode === 'native') {
      check(password.getAttribute('aria-invalid') === 'true', 'One native validation attempt marks errors in all tabs but focuses only the first');
      username.value = 'Corrected'; username.dispatchEvent(new Event('input', {bubbles:true}));
      check(!username.hasAttribute('aria-invalid') && password.getAttribute('aria-invalid') === 'true', 'Editing clears only that field feedback, preserving errors in other tabs');
    }
    if (mode === 'scroll') {
      const bounds = password.getBoundingClientRect(), body = dialog.querySelector('.modal-body').getBoundingClientRect();
      check(dialog.querySelector('.modal-body').scrollTop > 0 && bounds.top >= body.top && bounds.bottom <= body.bottom, 'A distant invalid field is scrolled into the visible modal body');
    }
    if (mode === 'summary') {
      check(expected.textContent === 'Specific validation message.', 'Unmapped or hidden validation errors remain visible instead of disappearing behind a generic toast');
      submit.click(); await wait(() => calls === 2, 'A form-level validation failure can be retried'); await pause();
      check(dialog.querySelectorAll('[role="alert"][data-field-error]').length === 1, 'Retries replace form-level feedback without duplication');
    }
    if (mode === 'nested' || mode === 'proxy') {
      const name = mode === 'nested' ? 'settings[site_title]' : 'body';
      T.forms.clearErrors(form, name);
      check(!expected.hasAttribute('aria-invalid') && !dialog.querySelector('[data-field-error]'), 'Clearing a field also clears its proxy and inline message');
      check(mode === 'nested' ? expected.getAttribute('aria-describedby') === 'retained-hint' : expected.getAttribute('aria-describedby').includes('counter'), 'Clearing feedback preserves the original help or character-counter description');
    }
  }
  document.getElementById('validation-test-results').textContent = JSON.stringify({ok:true,mode,checks});
}()).catch(error => { document.getElementById('validation-test-results').textContent = JSON.stringify({ok:false,error:error.stack}); });
