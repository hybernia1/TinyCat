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
    draft.append(T.components.render('publishing/editor', {name:'body',value:'',placeholder:'Text',comment:false,suggest_url:'/api/search/suggestions'})); document.body.append(draft);
    const E = T.status.controllers.editor, pendingSuggestions = [];
    T.api.request = (url, options) => new Promise(resolve => pendingSuggestions.push({url,options,resolve}));
    E.hydrate(draft);
    const editorRoot = draft.querySelector('[data-status-editor]'), editorInput = E.getInput(editorRoot), suggestions = E.getBox(editorRoot);
    function typeDraft(text) { E.setText(editorInput,text,text.length); editorInput.dispatchEvent(new Event('input',{bubbles:true})); }
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
      const steps = installation.querySelector('ol');
      check(steps.children.length === 5 && steps.querySelectorAll('[aria-current="step"]').length === 1, 'Installation exposes five steps and one current step: '+theme);
      check(steps.children[2].getAttribute('aria-current') === 'step' && steps.children[0].querySelector('svg'), 'Installation distinguishes current and completed steps: '+theme);
      const choices = installation.querySelectorAll('label.check-card');
      check(choices.length >= 2 && installation.querySelector('input[value="cs"]').checked, 'Installation retains its selected native language: '+theme);
      choices[1].click();
      check(choices[1].querySelector('input').checked && installation.querySelectorAll('input[name="locale"]:checked').length === 1, 'Clicking the shared card selects exactly one language: '+theme);
      choices[1].querySelector('input').focus();
      check(choices[1].matches(':focus-within'), 'Native language focus stays inside its shared card: '+theme);
      for (const node of installation.querySelectorAll('.result-item, .check-card, .btn')) {
        const bounds = node.getBoundingClientRect();
        check(bounds.width > 0 && bounds.left >= 0 && bounds.right <= innerWidth + 1, 'Installation control fits the viewport: '+theme);
      }
      installation.querySelector('input[value="cs"]').click();
    }
    installation.remove();
    const avatarImage = '/avatar-fixture.svg';
    for (const state of ['empty', 'uploaded', 'generated']) {
      const user = { id: 2, username: 'Avatar fixture', avatar_url: state === 'empty' ? null : avatarImage, avatar_custom: state !== 'generated' };
      const dialog = T.accounts.modal(new URL('/api/users/2/avatar', location.href), { data: { user, appearance_options: window.avatarFixtureOptions } }, 'avatar-layout-'+state);
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
    const socialFeed = document.createElement('div'); socialFeed.className = 'container profile-main';
    const feed = document.createElement('div'); feed.className = 'status-feed'; socialFeed.append(feed); document.body.append(socialFeed);
    check(Math.abs(feed.getBoundingClientRect().width-socialFeed.getBoundingClientRect().width) < 1, 'Social owns its full-width profile feed layout');
    socialFeed.remove();
    delete document.documentElement.dataset.theme;
    document.getElementById('results').textContent = JSON.stringify({ ok: true, checks });
  } catch (error) { document.getElementById('results').textContent = JSON.stringify({ ok: false, error: String(error.stack || error), checks }); }
}());
