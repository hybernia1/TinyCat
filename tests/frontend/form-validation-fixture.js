(async function () {
  const T = window.TinyCat, checks = [];
  const check = (value, message) => { if (!value) throw Error(message); checks.push(message); };
  const pause = () => new Promise(resolve => setTimeout(resolve, 25));
  async function wait(test, message) { for (let i=0;i<100 && !test();i++) await pause(); check(test(), message); }
  const mode = new URL(location.href).searchParams.get('case');
  if (mode === 'creation') {
    const options = {username_pattern:'[A-Za-z][A-Za-z0-9_]+',password_max_length:1024,roles:['admin','user'],statuses:['active','ban']};
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
    const list = T.dom.element('div',{id:'users-list'}); document.body.append(list);
    T.users.list(list,{data:listing,meta:{pagination:{page:1,per_page:25,total:0}}});
    T.lifecycle.hydrate(list);
    check(!document.querySelector('.modal') && !Object.keys(reads).length, 'List hydration contains filters but no creation modal or resource read');
    const editor = await T.dialog.openResource('modal-users-creation','/api/users/creation');
    const username = editor.querySelector('[name="username"]'); username.value='UnsentAccount'; username.dispatchEvent(new Event('input',{bubbles:true}));
    T.users.list(list,{data:listing,meta:{pagination:{page:1,per_page:25,total:0}}}); T.lifecycle.hydrate(list);
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
  if (mode === 'settings') {
    const schema = window.settingsSchema || {sections:[
      {key:'site',label:'Site',icon:'home',fields:[{key:'site.name',label:'Name',type:'text',value:'Current site'},{key:'site.footer_html',label:'Footer',type:'textarea',value:'Saved footer'}]},
      {key:'email',label:'Email',icon:'mail',fields:[{key:'email.smtp',label:'SMTP',type:'email_smtp',value:{host:'smtp.example.test',port:587}}]}
    ]};
    for (const section of schema.sections) for (const field of section.fields) {
      if (field.key === 'site.name') field.value = 'Current site';
      if (field.key === 'site.footer_html') field.value = 'Saved footer';
      if (field.key === 'email.smtp') field.value = {host:'smtp.example.test',port:587};
    }
    let reads=0, writes=0;
    window.fetch = async (url, options) => {
      const get = new URL(url).pathname === '/api/settings/editor';
      if (get) reads++; else writes++;
      if (!get) {
        check(options.body.get('settings[email.smtp][host]') === 'smtp.example.test', 'Settings keep nested multipart field names');
        check(options.body.get('settings[site.footer_html]') === 'Edited footer', 'Settings submit the textarea draft');
      }
      return new Response(JSON.stringify({ok:true,status:200,code:'ok',params:{},message:null,data:get ? schema : {saved:true,images:{}},meta:{}}),{status:200,headers:{'Content-Type':'application/json'}});
    };
    T.lifecycle.start();
    check(reads === 0 && !document.querySelector('#modal-settings-editor'), 'Initial hydration neither requests nor creates settings');
    const dialog = await T.settings.open('email');
    check(reads === 1 && dialog.dataset.modalDisposeOnClose === 'true', 'Settings are fetched only when opened and are disposable');
    check(!dialog.querySelector('[data-tab-panel="email"]').hidden && location.hash.includes('modal=settings/editor'), 'Requested section and canonical resource history are shared');
    const form = dialog.querySelector('form'), footer = form.elements.namedItem('settings[site.footer_html]');
    for (const section of schema.sections) for (const spec of section.fields) {
      const suffix = spec.type === 'email_smtp' ? '[host]' : spec.type === 'email_templates' ? '[_present]' : '';
      check(Boolean(form.elements.namedItem('settings['+spec.key+']'+suffix)), 'The editor retains the submitted field for '+spec.key);
    }
    check(dialog.querySelectorAll('.image-preview-contain').length === 2 && dialog.querySelector('.grid-span-full'), 'Site images and full-width settings retain shared layout primitives');
    check([...dialog.querySelectorAll('.field > .label')].every(label => label.textContent.trim()), 'Every server field has a client-owned presentation label');
    check(footer.value === 'Saved footer' && form.elements.namedItem('settings[email.smtp][port]').value === '587', 'Textareas and nested SMTP values hydrate correctly');
    footer.value='Edited footer'; footer.dispatchEvent(new Event('input',{bubbles:true}));
    check(T.forms.dirty(dialog).length === 1, 'Settings edits use the shared draft guard');
    const confirmation = T.dialog.confirm;
    T.forms.confirmWith(async () => false);
    check(await T.dialog.requestClose(dialog,{history:false}) === false && dialog.isConnected && footer.value === 'Edited footer', 'Declining close preserves the complete settings draft');
    T.forms.confirmWith(confirmation);
    dialog.querySelector('.modal-footer [type="submit"]').click();
    await wait(() => writes === 1 && !T.forms.dirty(dialog).length, 'Saving settings uses shared AJAX and clears the saved draft');
    T.dialog.close(dialog,{history:false});
    check(!document.querySelector('#modal-settings-editor'), 'Closing settings disposes the editor DOM');
    const reopened = await T.settings.open('site');
    check(reads === 2 && reopened !== dialog, 'Reopening settings fetches current server data');
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
  T.lifecycle.start(); T.status.controllers.editor.initStatusEditors(document); T.dialog.open(dialog);
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
