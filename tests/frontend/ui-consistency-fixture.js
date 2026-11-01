(async function () {
  const T = TinyCat, checks = [], reads = [];
  const tick = () => new Promise(resolve => setTimeout(resolve, 50));
  function check(value, label) { if (!value) throw new Error(label); checks.push(label); }
  function key(node, value) { return node.dispatchEvent(new KeyboardEvent('keydown', { key: value, bubbles: true, cancelable: true })); }
  function empty(preview) { const node = document.createElement('div'); node.append(T.notifications.items([], preview)); return node; }
  try {
    const form = document.createElement('form'); form.className = 'global-search'; form.style.cssText = 'position:fixed;top:12px;left:12px;width:calc(100% - 24px)'; form.dataset.globalSearch = '';
    const anchor = document.createElement('div'); anchor.className = 'global-search-control'; anchor.dataset.popover = ''; anchor.dataset.popoverMode = 'input'; anchor.dataset.popoverMatchWidth = 'true';
    const input = document.createElement('input'); input.className = 'input global-search-input'; input.type = 'search'; input.dataset.globalSearchInput = ''; input.dataset.popoverTrigger = '';
    const box = document.createElement('div'); box.className = 'popover-panel global-search-results'; box.dataset.globalSearchResults = ''; box.hidden = true;
    const searchIcon = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); searchIcon.classList.add('icon'); searchIcon.setAttribute('aria-hidden', 'true');
    anchor.append(searchIcon, input, box); form.append(anchor); document.body.append(form);
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
    const row = document.createElement('a'); row.className = 'identity-row identity-row-compact';
    row.append(T.components.render('ui/avatar', { user: {}, small: true }));
    const copy = document.createElement('span'); copy.className = 'identity-copy'; const title = document.createElement('strong'); title.textContent = 'Name'; const meta = document.createElement('small'); meta.textContent = 'Metadata'; copy.append(title, meta); row.append(copy); document.body.append(row);
    check(row.querySelector('.avatar').getBoundingClientRect().width === 36 && getComputedStyle(meta).fontSize === '13px', 'Compact identity rows share avatar sizing and secondary typography');
    const modal = document.createElement('div'); modal.className = 'modal'; modal.dataset.open = 'true';
    const grid = document.createElement('div'); grid.className = 'following-modal-grid'; modal.append(grid);
    const more = T.components.render('ui/load-more', { attributes: { class: 'load-more' }, button_attributes: { class: 'btn btn-secondary', type: 'button', 'data-following-more': '', 'data-following-url': '/api/following' }, label: 'More', loading_label: 'Loading' }); modal.append(more); document.body.append(modal);
    const button = more.querySelector('button'), state = more.querySelector('.load-more-state');
    let finish; T.api.request = () => new Promise(resolve => { finish = resolve; });
    const pending = T.users.following.loadMore(button);
    check(button.disabled && more.classList.contains('is-loading') && more.getAttribute('aria-busy') === 'true' && !state.hidden, 'Following pagination uses the shared loading frame and live status');
    finish({ data: { items: [], done: true }, meta: { pagination: { page: 1, per_page: 25, total: 0 } } }); await pending;
    check(!more.isConnected, 'Exhausted following pagination removes its entire frame');
    modal.remove(); sidebar.remove(); row.remove(); form.remove();
    const draft = document.createElement('form'); draft.style.cssText = 'position:fixed;top:100px;left:12px;right:12px';
    draft.append(T.components.render('status/editor', {name:'body',value:'',placeholder:'Text',comment:false})); document.body.append(draft);
    const E = T.status.controllers.editor, pendingSuggestions = [];
    T.api.request = (url, options) => new Promise(resolve => pendingSuggestions.push({url,options,resolve}));
    E.initStatusEditors(draft);
    const editorRoot = draft.querySelector('[data-status-editor]'), editorInput = E.statusEditorInput(editorRoot), suggestions = E.statusEditorBox(editorRoot);
    function typeDraft(text) { E.setStatusEditorText(editorInput,text,text.length); editorInput.dispatchEvent(new Event('input',{bubbles:true})); }
    editorInput.focus(); typeDraft('@jan'); await new Promise(resolve => setTimeout(resolve,160));
    check(pendingSuggestions.length === 1, 'Mention autocomplete starts one debounced request');
    typeDraft('#');
    check(pendingSuggestions[0].options.signal.aborted, 'Switching to an empty tag cancels the user request immediately');
    pendingSuggestions[0].resolve({ok:true,data:{users:[{type:'user',value:'jana',title:'@jana'}]}}); await tick();
    check(suggestions.hidden, 'An obsolete user response cannot populate a new empty tag');
    typeDraft('#1'); await new Promise(resolve => setTimeout(resolve,160));
    check(pendingSuggestions.length === 1 && suggestions.hidden && E.statusEditorSource(editorRoot).value === '#1', 'A numeric hash literal remains text without a proposed numeric tag');
    for (const [typed,canonical] of [['#1980s','1980s'],['#straße','strasse'],['#Cafe\u0301','cafe']]) {
      typeDraft(typed); await new Promise(resolve => setTimeout(resolve,160));
      pendingSuggestions[pendingSuggestions.length-1].resolve({ok:true,data:{tags:[{type:'tag',value:canonical,title:'#'+canonical}]}}); await tick();
      check(!suggestions.hidden && suggestions.textContent === '#'+canonical, 'Autocomplete preserves the server canonical tag: '+typed);
      key(editorInput,'Enter');
      check(E.statusEditorSource(editorRoot).value === '#'+canonical+' ', 'Selecting a tag inserts its canonical value without a second transliteration');
    }
    for (const text of ['mail@jana','https://example.test/@jana','example.test/path#tag','##tag','word.#tag']) {
      typeDraft(text); check(E.statusEditorActiveToken(editorRoot) === null, 'Autocomplete ignores URL, email and invalid token boundaries: '+text);
    }
    typeDraft('Ahoj,@jana'); check(E.statusEditorActiveToken(editorRoot).query === 'jana', 'Autocomplete recognizes the same punctuation boundary as the server');
    E.hideStatusEditorSuggestions(editorRoot); draft.remove();
    document.getElementById('results').textContent = JSON.stringify({ ok: true, checks });
  } catch (error) { document.getElementById('results').textContent = JSON.stringify({ ok: false, error: String(error.stack || error), checks }); }
}());
