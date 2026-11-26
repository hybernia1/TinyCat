(async function () {
  const T = TinyCat, checks = [], reads = [];
  const tick = () => new Promise(resolve => setTimeout(resolve, 50));
  function check(value, label) { if (!value) throw new Error(label); checks.push(label); }
  function key(node, value) { return node.dispatchEvent(new KeyboardEvent('keydown', { key: value, bubbles: true, cancelable: true })); }
  function empty(preview) { const node = document.createElement('div'); node.append(T.notifications.items([], preview)); return node; }
  try {
    for (const scale of ['1','2']) {
      document.documentElement.style.setProperty('--ui-scale', scale);
      const skip = document.createElement('a'); skip.className = 'skip-link'; skip.href = '#main'; skip.textContent = 'Skip to the main content and navigation'; document.body.append(skip);
      check(skip.getBoundingClientRect().bottom <= 0, 'Skip link stays outside the viewport until focused at scale '+scale);
      skip.focus({preventScroll:true});
      const bounds = skip.getBoundingClientRect();
      check(bounds.top >= 0 && bounds.left >= 0 && bounds.right <= innerWidth + 1 && document.activeElement === skip, 'Keyboard focus reveals the full skip link within the viewport at scale '+scale);
      skip.remove();
    }
    document.documentElement.style.removeProperty('--ui-scale');
    for (const [tone, symbol] of [['info','info'],['success','check-circle'],['warning','alert'],['danger','x-circle']]) {
      for (const scale of ['1','1.5']) {
        document.documentElement.style.setProperty('--ui-scale', scale);
        const alert = T.components.render('ui/notice', {tone, title:'A clear status title', description:'Long explanation '.repeat(20), detail:'Additional detail'});
        document.body.append(alert);
        const icon = getComputedStyle(alert,'::after'), copy = alert.querySelector(':scope > div');
        const bounds=alert.getBoundingClientRect(), copyBox=copy.getBoundingClientRect();
        const iconTop=bounds.top+parseFloat(icon.top)+parseFloat(icon.marginTop), iconLeft=bounds.left+parseFloat(icon.left);
        check(icon.maskImage!=='none' && !alert.querySelector('svg') && icon.content==='""', 'Severity symbol is a decorative CSS mask without SVG markup: '+tone);
        check(iconLeft+parseFloat(icon.width)<copyBox.left && Math.abs(iconTop-copyBox.top)<2 && alert.scrollWidth<=alert.clientWidth+1, 'Alert symbol stays aligned with the start of multiline text at scale '+scale);
        check(alert.getAttribute('role')===(tone==='danger'?'alert':'status'), 'Alert retains appropriate live semantics');
        alert.remove();
      }
    }
    document.documentElement.style.removeProperty('--ui-scale');
    for (const tone of ['success','danger','warning','info']) {
      const badge=T.components.render('ui/status-badge',{tone,text:true,icon:true,label:'Readable state'});document.body.append(badge);
      check(getComputedStyle(badge,'::after').maskImage!=='none' && !badge.querySelector('svg') && badge.textContent==='Readable state' && !badge.hasAttribute('aria-label'), 'Text status badges use a decorative CSS mask and readable state.');
      const sample=document.createElement('span');sample.style.color='var(--'+tone+')';document.body.append(sample);
      check(getComputedStyle(badge,'::before').backgroundColor===getComputedStyle(sample).color, 'State symbol uses the shared severity colour: '+tone);
      badge.remove();sample.remove();
    }

    for (const tone of ['success','danger','warning','info']) {
      const toast=T.toasts.show('Readable notice',tone), decoration=getComputedStyle(toast,'::after');
      check(decoration.maskImage!=='none' && toast.children.length===2 && toast.querySelector('.toast-body').textContent==='Readable notice', 'Toasts share CSS severity symbols without a decoration node.');
      toast.querySelector('.toast-close').click();
    }
    let previousIdentitySize=0;
    for (const scale of ['1','1.5','2']) {
      document.documentElement.style.setProperty('--ui-scale',scale);
      const table=document.createElement('table');table.className='table';
      const cell=table.insertRow().insertCell(), identity=T.components.render('users/identity-row',{user:{id:17,username:'A user',status:'active',avatar_url:''}});
      cell.append(identity);document.body.append(table);
      const symbol=identity.querySelector('.avatar').getBoundingClientRect(), body=identity.querySelector('.table-identity-body').getBoundingClientRect();
      check(Math.abs(symbol.height-body.height)<1 && Math.abs(symbol.width-symbol.height)<1 && symbol.height>previousIdentitySize, 'Core identity symbol spans the title and state rows and scales together at '+scale);
      previousIdentitySize=symbol.height;table.remove();
    }
    document.documentElement.style.removeProperty('--ui-scale');

    const linkWidget=T.components.render('ui/dashboard-widget',{widget:{id:'fixture.links',title:'Links',icon:'users',type:'list',data:{items:[{label:'@admin',detail:'A comment',url:'/author/1'}]}}});
    document.body.append(linkWidget);
    const linkSamples=[...linkWidget.querySelectorAll('a')];
    for (const className of ['','status-inline-link','mention-link','hashtag']) {
      const link=document.createElement('a');link.className=className;link.href='/#link-style';link.textContent='Readable link';document.body.append(link);linkSamples.push(link);
    }
    for (const link of linkSamples) {
      check(getComputedStyle(link).textDecorationLine==='none','Dashboard and inline links have no default underline');
      link.focus();
      check(getComputedStyle(link).textDecorationLine==='none' && getComputedStyle(link).outlineStyle!=='none','Keyboard focus stays visible without adding a link underline');
    }
    linkSamples.forEach(link=>link.remove());linkWidget.remove();

    const header = T.components.render('ui/public-header', {hasGlobalSearch:true, urls:{home:'/',search:'/search/all'}, nav:[], languages:[]});
    const form = header.querySelector('[data-global-search]'); form.style.cssText = 'position:fixed;top:12px;left:12px;width:calc(100% - 24px)';
    const anchor = form.querySelector('[data-popover]'), input = form.querySelector('[data-global-search-input]'), box = form.querySelector('[data-global-search-results]'), searchIcon = anchor.querySelector(':scope > .icon');
    document.body.append(form);
    T.api.request = (url, options) => new Promise((resolve, reject) => reads.push({ url, options, resolve, reject }));
    input.setAttribute('aria-expanded', 'false');
    T.popovers.init(); T.search.hydrate(document);
    const inputBounds = input.getBoundingClientRect(), iconBounds = searchIcon.getBoundingClientRect();
    check(Math.abs(iconBounds.top + iconBounds.height / 2 - inputBounds.top - inputBounds.height / 2) < 1, 'Search icon stays vertically centered in the input');
    check(!input.hasAttribute('aria-expanded') && input.getAttribute('aria-controls') === box.id && !input.hasAttribute('role'), 'Search retains native searchbox semantics and valid controls without unsupported expanded state');
    input.focus(); input.value = 'alpha'; input.dispatchEvent(new Event('input', { bubbles: true }));
    await new Promise(resolve => setTimeout(resolve, 250));
    check(reads.length === 1, 'Search retains debounce and starts one request');
    reads[0].resolve({ ok: true, data: { query: 'alpha', users: [{ type: 'user', title: 'Alpha', url: '/@alpha' }] } }); await tick();
    check(box.matches(':popover-open') && document.activeElement === input, 'Search uses shared top layer without moving input focus');
    check(!input.hasAttribute('aria-expanded'), 'Opening suggestions does not add unsupported ARIA state to searchbox');
    const rect = box.getBoundingClientRect(), trigger = input.getBoundingClientRect();
    check(Math.abs(rect.width - trigger.width) < 2 && rect.left >= 11 && rect.right <= innerWidth - 11, 'Suggestion panel follows input width and viewport bounds');
    input.click(); check(box.matches(':popover-open'), 'Clicking inside search input does not toggle its panel closed');
    key(input, 'ArrowDown'); check(box.contains(document.activeElement) && document.activeElement.matches('a'), 'Shared keyboard navigation enters search results');
    key(document.activeElement, 'Escape'); await tick();
    check(!box.matches(':popover-open') && document.activeElement === input && reads.length === 1, 'Escape returns focus without reopening cached search results');
    check(!input.hasAttribute('aria-expanded'), 'Closing suggestions does not add unsupported ARIA state to searchbox');
    input.value = 'pending'; input.dispatchEvent(new Event('input', { bubbles: true })); await new Promise(resolve => setTimeout(resolve, 250));
    key(input, 'Escape');
    check(reads[1].options.signal.aborted, 'Escape cancels search while results are still loading');
    reads[1].resolve({ ok: true, data: { query: 'pending' } }); await tick(); check(!box.matches(':popover-open'), 'A late reply after Escape stays closed');
    input.value = 'outside'; input.dispatchEvent(new Event('input', { bubbles: true })); await new Promise(resolve => setTimeout(resolve, 250));
    document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));
    check(reads[2].options.signal.aborted, 'Shared outside dismissal cancels a pending search');
    reads[2].resolve({ ok: true, data: { query: 'outside' } }); await tick(); check(!box.matches(':popover-open'), 'A late reply after outside dismissal stays closed');
    input.value = 'empty'; input.dispatchEvent(new Event('input', { bubbles: true })); await new Promise(resolve => setTimeout(resolve, 250));
    reads[3].resolve({ ok: true, data: { query: 'empty' } }); await tick();
    check(box.querySelector('.empty-state-compact[role="status"]') && box.querySelector('[data-global-search-link]'), 'Empty suggestions use the shared compact state and keep full-search navigation');
    input.value = 'failure'; input.dispatchEvent(new Event('input', { bubbles: true })); await new Promise(resolve => setTimeout(resolve, 250));
    reads[4].reject({ data: { message: '<img src=x> unavailable' } }); await tick();
    check(box.querySelector('.alert-danger[role="alert"]') && box.textContent.includes('<img src=x>') && !box.querySelector('img'), 'Request failures use an accessible alert with literal untrusted text');
    input.value = 'z'; input.dispatchEvent(new Event('input', { bubbles: true })); await new Promise(resolve => setTimeout(resolve, 250));
    check(box.querySelector('.alert-info[role="status"]') && !box.querySelector('.empty-state'), 'Short-query guidance is distinct from empty results and failures');
    T.search.close(form);
    const sidebar = T.components.render('site/sidebar', { tags: [], authors: [] }); document.body.append(sidebar);
    check(sidebar.querySelectorAll('.empty-state-compact').length === 2, 'Both empty sidebar lists reuse the shared compact state');
    const preview = empty(true), page = empty(false);
    check(preview.querySelector('.empty-state[role="status"]') && preview.innerHTML === page.innerHTML, 'Notification preview and page render the same shared empty component');
    const tableWrap = document.createElement('div'); tableWrap.className = 'table-wrap';
    const emptyTable = document.createElement('table'); emptyTable.className = 'table';
    const tableHead = document.createElement('thead'), heading = document.createElement('tr');
    for (const text of ['Publication title', 'Author username', 'Last checked']) { const cell = document.createElement('th'); cell.textContent = text; heading.append(cell); }
    tableHead.append(heading);
    const tableBody = document.createElement('tbody'), emptyRow = document.createElement('tr'), emptyCell = document.createElement('td'); emptyCell.colSpan = 3;
    emptyCell.append(T.components.render('ui/empty-state', { label: 'No records.', icon: 'file-text' })); emptyRow.append(emptyCell); tableBody.append(emptyRow);
    emptyTable.append(tableHead, tableBody); tableWrap.append(emptyTable); document.body.append(tableWrap);
    const emptyLabel = tableWrap.querySelector('.empty-state-label').getBoundingClientRect(), tableBounds = tableWrap.getBoundingClientRect();
    check(emptyLabel.left >= tableBounds.left && emptyLabel.right <= tableBounds.right, 'Empty table text fits the visible viewport instead of the horizontal scroll width');
    check(Math.abs((emptyLabel.left + emptyLabel.right) / 2 - (tableBounds.left + tableBounds.right) / 2) < 2, 'Empty table content stays centered on mobile and desktop');
    tableWrap.remove();
    const row = document.createElement('a'); row.className = 'identity-row identity-row-compact';
    row.append(T.components.render('ui/avatar', { user: {}, small: true }));
    const copy = document.createElement('span'); copy.className = 'identity-copy'; const title = document.createElement('strong'); title.textContent = 'Name'; const meta = document.createElement('small'); meta.textContent = 'Metadata'; copy.append(title, meta); row.append(copy); document.body.append(row);
    const compactSymbolWidth=row.querySelector('.avatar').getBoundingClientRect().width;
    row.classList.remove('identity-row-compact');
    check(row.querySelector('.avatar').getBoundingClientRect().width === compactSymbolWidth && parseFloat(getComputedStyle(meta).fontSize)<parseFloat(getComputedStyle(title).fontSize), 'Compact and normal identity rows share the core symbol size and secondary typography');
    row.classList.add('identity-row-compact');
    const modal = document.createElement('div'); modal.className = 'modal'; modal.dataset.open = 'true';
    const grid = document.createElement('div'); grid.className = 'following-modal-grid'; modal.append(grid);
    const more = T.components.render('ui/load-more', { attributes: { class: 'load-more' }, button_attributes: { class: 'btn btn-secondary', type: 'button', 'data-following-more': '', 'data-following-url': '/api/following' }, label: 'More', loading_label: 'Loading' }); modal.append(more); document.body.append(modal);
    const button = more.querySelector('button'), state = more.querySelector('.load-more-state');
    let finish; T.api.request = () => new Promise(resolve => { finish = resolve; });
    const pending = T.socialUsers.following.loadMore(button);
    check(button.disabled && more.classList.contains('is-loading') && more.getAttribute('aria-busy') === 'true' && !state.hidden, 'Following pagination uses the shared loading frame and live status');
    finish({ data: { items: [], done: true }, meta: { pagination: { page: 1, per_page: 25, total: 0 } } }); await pending;
    check(!more.isConnected, 'Exhausted following pagination removes its entire frame');
    modal.remove(); sidebar.remove(); row.remove(); form.remove();
    const draft = document.createElement('form'); draft.style.cssText = 'position:fixed;top:100px;left:12px;right:12px';
    draft.append(T.components.render('publishing/editor', {name:'body',value:'',placeholder:'Text',comment:false,suggest_url:'/api/search/suggestions'})); document.body.append(draft);
    const E = T.publishing.controllers.editor, pendingSuggestions = [];
    T.api.request = (url, options) => new Promise(resolve => pendingSuggestions.push({url,options,resolve}));
    E.hydrate(draft);
    const editorRoot = draft.querySelector('[data-status-editor]'), editorInput = E.getInput(editorRoot), suggestions = E.getBox(editorRoot);
    function typeDraft(text) { E.setText(editorInput,text,text.length); editorInput.dispatchEvent(new Event('input',{bubbles:true})); }
    let syncValue;
    editorRoot.addEventListener('tinycat:editor-sync', event => { syncValue = event.detail.value; });
    typeDraft('Aktuální 🐈 draft');
    check(typeof syncValue === 'string' && syncValue === 'Aktuální 🐈 draft', 'Editor sync event carries the current draft string');
    for (const [marker,kind,method] of [['@','user','pointer'],['@','user','keyboard'],['#','tag','pointer'],['#','tag','keyboard']]) {
      const prefix = '🐈 ahoj ', suffix = ' konec', text = prefix + marker + 'ja' + suffix;
      E.setText(editorInput,text,(prefix + marker + 'ja').length);
      editorInput.dispatchEvent(new Event('input',{bubbles:true}));
      await new Promise(resolve => setTimeout(resolve,160));
      const item = {type:kind,value:'jana',title:marker+'jana'};
      pendingSuggestions[pendingSuggestions.length-1].resolve({ok:true,data:kind === 'user' ? {users:[item]} : {tags:[item]}}); await tick();
      if (method === 'pointer') suggestions.querySelector('[data-status-editor-suggestion]').dispatchEvent(new PointerEvent('pointerdown',{bubbles:true}));
      else key(editorInput,'Enter');
      check(E.getSource(editorRoot).value === prefix + marker + 'jana ' + suffix, 'Autocomplete preserves Unicode prefix and suffix for '+kind+' via '+method);
    }
    pendingSuggestions.length = 0;
    editorInput.focus(); typeDraft('@jan'); await new Promise(resolve => setTimeout(resolve,160));
    check(pendingSuggestions.length === 1, 'Mention autocomplete starts one debounced request');
    typeDraft('#');
    check(pendingSuggestions[0].options.signal.aborted, 'Switching to an empty tag cancels the user request immediately');
    pendingSuggestions[0].resolve({ok:true,data:{users:[{type:'user',value:'jana',title:'@jana'}]}}); await tick();
    check(suggestions.hidden, 'An obsolete user response cannot populate a new empty tag');
    typeDraft('#1'); await new Promise(resolve => setTimeout(resolve,160));
    check(pendingSuggestions.length === 1 && suggestions.hidden && E.getSource(editorRoot).value === '#1', 'A numeric hash literal remains text without a proposed numeric tag');
    for (const [typed,canonical] of [['#1980s','1980s'],['#straße','strasse'],['#Cafe\u0301','cafe']]) {
      typeDraft(typed); await new Promise(resolve => setTimeout(resolve,160));
      pendingSuggestions[pendingSuggestions.length-1].resolve({ok:true,data:{tags:[{type:'tag',value:canonical,title:'#'+canonical}]}}); await tick();
      check(!suggestions.hidden && suggestions.textContent === '#'+canonical, 'Autocomplete preserves the server canonical tag: '+typed);
      key(editorInput,'Enter');
      check(E.getSource(editorRoot).value === '#'+canonical+' ', 'Selecting a tag inserts its canonical value without a second transliteration');
    }
    for (const text of ['mail@jana','https://example.test/@jana','example.test/path#tag','##tag','word.#tag']) {
      typeDraft(text); check(E.activeToken(editorRoot) === null, 'Autocomplete ignores URL, email and invalid token boundaries: '+text);
    }
    typeDraft('Ahoj,@jana'); check(E.activeToken(editorRoot).query === 'jana', 'Autocomplete recognizes the same punctuation boundary as the server');
    E.hideSuggestions(editorRoot); draft.remove();
    const installation = document.createElement('main');
    installation.className = 'container-sm stack stack-gap-24';
    installation.innerHTML = window.installationFixture;
    document.body.append(installation);
    for (const theme of ['light', 'dark']) {
      document.documentElement.dataset.theme = theme;
      const steps = installation.querySelector('nav.tabs');
      check(steps.children.length === 5 && steps.querySelectorAll('[aria-current="step"]').length === 1, 'Installation exposes five steps and one current step: '+theme);
      check(steps.children[2].getAttribute('aria-current') === 'step' && steps.children[0].querySelector('svg'), 'Installation distinguishes current and completed steps: '+theme);
      const language = installation.querySelector('select[name="locale"]');
      check(language.options.length >= 2 && language.value === 'cs', 'Installation retains its selected native language: '+theme);
      language.value = 'en'; language.dispatchEvent(new Event('change', {bubbles:true}));
      check(new FormData(language.form).get('locale') === 'en', 'Native language selection is submitted by the original form: '+theme);
      language.focus();
      check(document.activeElement === language, 'Language select receives native keyboard focus: '+theme);
      const submit = installation.querySelector('.card-footer button[type="submit"]');
      check(submit.form === language.form, 'Footer submit retains its native form association: '+theme);
      for (const node of installation.querySelectorAll('nav.tabs, .select, .btn')) {
        const bounds = node.getBoundingClientRect();
        check(bounds.width > 0 && bounds.left >= 0 && bounds.right <= innerWidth + 1, 'Installation control fits the viewport: '+theme);
      }
      language.value = 'cs';
    }
    installation.remove();
    const avatarImage = '/avatar-fixture.svg';
    for (const state of ['empty', 'uploaded', 'generated']) {
      const user = { id: 2, username: 'Avatar fixture', avatar_url: state === 'empty' ? null : avatarImage, avatar_custom: state !== 'generated' };
      const dialog = T.accounts.modal(new URL('/api/users/2/avatar', location.href), { data: { user } }, 'avatar-layout-'+state);
      dialog.dataset.open = 'true'; document.body.append(dialog);
      for (const theme of ['light', 'dark']) {
        document.documentElement.dataset.theme = theme;
        const preview = dialog.querySelector('.avatar-upload-preview'), bounds = preview.getBoundingClientRect();
        check(bounds.width === 96 && bounds.height === 96, 'Avatar preview retains shared XL dimensions: '+state+' '+theme);
        const image = preview.querySelector('img'), fallback = preview.querySelector('.avatar-fallback');
        check((getComputedStyle(image).display === 'none') === (state === 'empty') && (getComputedStyle(fallback).display === 'none') === (state !== 'empty'), 'Shared avatar styles show exactly one preview state: '+state+' '+theme);
        const icon = fallback.querySelector('svg').getBoundingClientRect();
        if (state === 'empty') { check(Math.abs(icon.left+icon.width/2-bounds.left-bounds.width/2) < 1 && Math.abs(icon.top+icon.height/2-bounds.top-bounds.height/2) < 1, 'Shared avatar fallback stays centered: '+theme); }
        for (const node of dialog.querySelectorAll('.avatar-editor-preview, .avatar-upload-drop, .modal-footer .btn')) {
          const rect = node.getBoundingClientRect();
          check(rect.width > 0 && rect.left >= 0 && rect.right <= innerWidth+1, 'Avatar controls fit the viewport: '+state+' '+theme);
        }
      }
      dialog.remove();
    }
    const inboxHost = document.createElement('div'); inboxHost.className = 'container';
    const inbox = T.components.render('notifications/page', {items:[{id:101,message:'Long notification text '.repeat(8),actor:{},icon:'bell',url:'/fixture-notification',unread:true}],has_unread:true,next_url:''});
    // Exercise wider font metrics consistently across native browser environments.
    inbox.style.fontFamily = 'monospace';
    inboxHost.append(inbox); document.body.append(inboxHost);
    const inboxRow = inbox.querySelector('.notification-item'), inboxLink = inboxRow.querySelector('a'), deleteForm = inboxRow.querySelector('form[data-api-method="DELETE"]'), deleteButton = deleteForm.querySelector('button');
    inboxLink.scrollIntoView({block:'center'});
    const rowBox = inboxRow.getBoundingClientRect(), deleteBox = deleteButton.getBoundingClientRect();
    const paddingTarget = document.elementFromPoint(rowBox.left + 2, rowBox.top + rowBox.height / 2);
    check(paddingTarget === inboxLink || inboxLink.contains(paddingTarget), 'The notification link covers row padding as well as its text');
    check(deleteButton.contains(document.elementFromPoint(deleteBox.left + deleteBox.width / 2, deleteBox.top + deleteBox.height / 2)), 'Delete remains above the row link and receives pointer input independently');
    inboxLink.focus({preventScroll:true});
    const colourSample = document.createElement('span'); colourSample.style.backgroundColor = 'var(--surface-soft)'; document.body.append(colourSample);
    check(getComputedStyle(inboxRow).backgroundColor === getComputedStyle(colourSample).backgroundColor && getComputedStyle(inboxLink,'::after').outlineStyle === 'solid', 'Keyboard focus highlights and outlines the whole notification row');
    let opened = 0, deleted = 0;
    inboxLink.addEventListener('click', event => { event.preventDefault(); event.stopImmediatePropagation(); opened++; });
    deleteForm.addEventListener('submit', event => { event.preventDefault(); event.stopImmediatePropagation(); deleted++; });
    deleteButton.click();
    check(deleted === 1 && opened === 0, 'Deleting a notification never activates its destination link');
    check(inbox.querySelectorAll('h1').length === 1 && !inbox.querySelector('article.card') && inbox.getBoundingClientRect().width >= inboxHost.clientWidth - 1, 'Inbox keeps one heading, no outer card and uses its full core container');
    for (const scale of ['1.5','2']) {
      document.documentElement.style.setProperty('--ui-scale', scale);
      const geometry = {viewport:innerWidth,row:[inboxRow.clientWidth,inboxRow.scrollWidth],page:[inbox.clientWidth,inbox.scrollWidth],overflow:Array.from(inbox.querySelectorAll('*')).filter(node=>node.getBoundingClientRect().right>inbox.getBoundingClientRect().right+1).map(node=>({tag:node.tagName,class:node.className,width:node.getBoundingClientRect().width,scroll:node.scrollWidth})).slice(0,8)};
      check(inboxRow.scrollWidth <= inboxRow.clientWidth + 1 && inbox.scrollWidth <= inbox.clientWidth + 1, 'Notification text and independent actions stay inside the page at scale '+scale+' '+JSON.stringify(geometry));
      const bounds = inboxRow.getBoundingClientRect();
      for (const control of inboxRow.querySelectorAll('.avatar, .identity-copy, .notification-actions')) {
        const rect = control.getBoundingClientRect();
        check(rect.left >= bounds.left - 1 && rect.right <= bounds.right + 1, 'Notification avatar, text and actions fit the row at scale '+scale);
      }
    }
    document.documentElement.style.removeProperty('--ui-scale');
    colourSample.remove(); inboxHost.remove(); window.scrollTo(0,0);
    for (const [assembly, assets] of Object.entries(window.notificationAssemblySources)) {
      check(!assets.some(asset => asset.path.startsWith('App/Modules/Social/')), 'Notification assembly excludes Social: ' + assembly);
      const frame = document.createElement('iframe'); frame.src = '/notification-frame';
      await new Promise(resolve => { frame.onload = resolve; document.body.append(frame); });
      const w = frame.contentWindow, d = frame.contentDocument;
      w.eval(assets.filter(asset => asset.path !== 'App/Core/Ui/assets/bootstrap.js').map(asset => asset.source).join('\n'));
      const N = w.TinyCat;
      N.components.register(window.notificationTemplates); N.i18n.register('en', window.notificationLabels);
      const list = d.createElement('div'); list.id = 'notifications-list';
      const item = id => ({id, type:'generic', message:'Notice '+id, icon:'bell', actor:{}, excerpt:'', url:'/notifications/'+id, unread:true, created_at:'2026-01-01T12:00:00+00:00'});
      list.append(N.notifications.items(Array.from({length:40}, (_, i) => item(i + 1)), false)); d.body.append(list);
      const more = N.components.render('ui/load-more', {attributes:{class:'load-more','data-notification-more':''}, button_attributes:{type:'button',class:'btn','data-ajax':'','data-url':'/api/notifications?cursor_id=1','data-method':'GET','data-ajax-target':'#notifications-list'},label:'More',loading_label:'Loading'});
      d.body.append(more); N.lifecycle.start();
      const button = more.querySelector('[data-ajax]'); let finish, options, calls = 0;
      N.api.request = (_url, settings) => { calls++; options = settings; return new Promise(resolve => {finish = resolve;}); };
      button.click(); await tick();
      check(calls === 1 && button.getAttribute('aria-busy') === 'true' && options.signal, 'Core AJAX owns pagination request and loading: '+assembly);
      finish({data:{items:[item(40),item(41)],unread_count:41,latest_id:41},meta:{pagination:{next_url:'/api/notifications?cursor_id=41'}}}); await tick();
      check(list.querySelectorAll('[data-notification-id]').length === 41 && button.dataset.url.endsWith('cursor_id=41'), 'Notifications deduplicate a second page and retain cursor without Social: '+assembly);
      button.click(); await tick();
      finish({data:{items:[item(42)],unread_count:42,latest_id:42},meta:{pagination:{next_url:'',done:true}}}); await tick();
      check(list.querySelectorAll('[data-notification-id]').length === 42 && !more.isConnected, 'Final notification page removes the whole loading control: '+assembly);
      const pendingMore = N.components.render('ui/load-more', {attributes:{'data-notification-more':''},button_attributes:{type:'button','data-ajax':'','data-url':'/api/notifications','data-method':'GET','data-ajax-target':'#notifications-list'},label:'More'});
      d.body.append(pendingMore); pendingMore.querySelector('button').click(); await tick();
      N.content.remove(list);
      check(options.signal.aborted, 'Removing the inbox cancels the pending cursor request: '+assembly);
      finish({data:{items:[item(43)]},meta:{pagination:{done:true}}}); await tick();
      check(list.querySelectorAll('[data-notification-id]').length === 42, 'Late notification response cannot update a disposed inbox: '+assembly);
      frame.remove();
    }
    for (const density of ['regular','compact']) {
      let normal = null;
      for (const scale of [1,1.5,2]) {
        document.documentElement.dataset.uiDensity = density;
        document.documentElement.style.setProperty('--ui-scale', scale);
        const host = document.createElement('main'); host.className = 'container';
        const profile = T.components.render('site/public-page', {kind:'author', author:{id:17,username:'Long account name for scaling',avatar_url:''}, bio:'A long profile description '.repeat(8),items:[],feed:'all',archive_options:[]});
        host.append(profile); document.body.append(host);
        const main = profile.querySelector('.profile-main');
        for (const fixture of window.socialScaleFixtures) { main.append(T.components.render(fixture.component, fixture.data)); }
        main.append(T.components.render('publishing/link-summary', {link:{url:'https://example.test',title:'Long link title '.repeat(8),description:'Long description '.repeat(8)}}));
        const avatar = profile.querySelector('.profile-identity .avatar'), body = profile.querySelector('.profile-card-body');
        const size = {avatar:avatar.getBoundingClientRect().width,padding:parseFloat(getComputedStyle(body).paddingTop),gap:parseFloat(getComputedStyle(body).rowGap)};
        if (!normal) { normal = size; }
        check(['padding','gap'].every(key => Math.abs(size[key] - normal[key] * scale) < 1) && size.avatar <= normal.avatar * scale + 1 && (scale === 1 || size.avatar > normal.avatar), 'Social avatar grows within available space while card inset and spacing scale together: '+density+'/'+scale);
        for (const selector of ['.profile-card','.status-card','.status-comment','.status-link-card']) {
          const surface = profile.querySelector(selector);
          check(surface && surface.scrollWidth <= surface.clientWidth + 1, 'Social content contains long text without horizontal overflow: '+selector+'/'+density+'/'+scale);
        }
        check(host.scrollWidth <= host.clientWidth + 1, 'Scaled profile columns remain within the page: '+density+'/'+scale+' '+JSON.stringify([...host.querySelectorAll('*')].filter(node => node.getBoundingClientRect().right > host.getBoundingClientRect().right + 1).slice(0,8).map(node => node.className.baseVal || node.className)));
        host.remove();
      }
    }
    delete document.documentElement.dataset.uiDensity;
    document.documentElement.style.removeProperty('--ui-scale');
    const home = T.components.render('site/public-page', {kind:'home',items:[],feed:'all',all_feed_url:'/',following_feed_url:'/?feed=following',archive_options:[]});
    document.body.append(home);
    const homeToolbar = home.querySelector('.home-feed-toolbar'), homeEmpty = home.querySelector('.home-feed-section > [data-status-empty]');
    check(homeEmpty.getBoundingClientRect().top > homeToolbar.getBoundingClientRect().bottom, 'Nested home feed stacks keep space between the toolbar and empty content');
    for (const density of ['regular','compact']) {
      document.documentElement.dataset.uiDensity = density;
      document.documentElement.style.setProperty('--ui-scale', '2');
      check(home.scrollWidth <= home.clientWidth + 1 && homeToolbar.scrollWidth <= homeToolbar.clientWidth + 1, 'Feed switcher and filters wrap within the page at double text size: '+density);
      for (const control of homeToolbar.querySelectorAll('a, summary')) {
        check(control.getBoundingClientRect().right <= home.getBoundingClientRect().right + 1, 'Feed navigation remains reachable at double text size: '+density);
      }
    }
    delete document.documentElement.dataset.uiDensity;
    document.documentElement.style.removeProperty('--ui-scale');
    home.remove();
    const socialFeed = document.createElement('div'); socialFeed.className = 'container profile-main';
    const feed = document.createElement('div'); feed.className = 'status-feed'; socialFeed.append(feed); document.body.append(socialFeed);
    check(Math.abs(feed.getBoundingClientRect().width-socialFeed.getBoundingClientRect().width) < 1, 'Social owns its full-width profile feed layout');
    socialFeed.remove();
    const informationHost = document.createElement('div');
    informationHost.className = 'container stack stack-gap-16';
    const privacySection = T.components.render('site/privacy-section', {siteName:'TinyCat',privacyLimitRows:[{label:'Trusted account',post:'60 / hour',comment:'200 / hour',like:'1000 / hour',report:'30 / hour',search:'3000 / hour'}]});
    const information = T.components.render('ui/privacy-page', {siteName:'TinyCat',siteCaptchaEnabled:false,siteGoogleAnalyticsConfigured:false,sections:privacySection});
    informationHost.append(information); document.body.append(informationHost);
    check(information.getBoundingClientRect().width <= informationHost.getBoundingClientRect().width + 1, 'Privacy content and action limits remain within the page container');
    check(Math.abs(information.getBoundingClientRect().width - informationHost.getBoundingClientRect().width) < 1, 'Privacy uses the complete standard container without its own width cap');
    const limits = information.querySelector('.table-wrap');
    if (limits.scrollWidth > limits.clientWidth) {
      limits.scrollLeft = 40;
      check(limits.scrollLeft > 0, 'Wide action limits scroll inside their table instead of expanding the page');
    }
    informationHost.remove();
    delete document.documentElement.dataset.theme;
    document.getElementById('results').textContent = JSON.stringify({ ok: true, checks });
  } catch (error) { document.getElementById('results').textContent = JSON.stringify({ ok: false, error: String(error.stack || error), checks }); }
}());
