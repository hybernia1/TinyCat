(async function () {
  const T = window.TinyCat, checks = [], requests = [];
  let restores = 0, delayed = false, releaseRead = null;
  const mode = new URL(location.href).searchParams.get('case');
  function check(value, message) { if (!value) throw Error(message); checks.push(message); }
  const pause = () => new Promise(resolve => setTimeout(resolve, 25));
  async function wait(test, message) { for (let i = 0; i < 100 && !test(); i++) await pause(); check(test(), message); }
  function modal() { return T.dialog.current(); }
  function tab(name) { return [...modal().querySelectorAll('[data-tab]')].find(node => node.dataset.tab === name); }
  function editor(id, persistent) {
    const layout = T.ui.tabbedContent(id, [{name:'account',label:'Account',icon:'user'}, {name:'history',label:'History',icon:'clock'}], 'account', 'Editor');
    const form = T.ui.form('/api/test', 'PATCH'); form.id = id + '-form';
    form.append(T.dom.element('input', {name:'bio',value:'Saved'})); layout.panels.account.append(form);
    const dialog = T.ui.dialog(id, 'Editor', layout.content, persistent, null, {kind:'editor'});
    T.ui.pinDialogTabs(dialog, layout.content); return dialog;
  }
  T.api.request = (url, options) => {
    requests.push(url);
    if (!delayed) return Promise.resolve({data:{}});
    return new Promise((resolve, reject) => {
      releaseRead = resolve;
      options.signal.addEventListener('abort', () => reject(new DOMException('Cancelled', 'AbortError')), {once:true});
    });
  };
  T.modules.register('history-test', {modal(url, response, id) {
    if (url.pathname === '/api/bots/accounts/7' || url.pathname === '/api/users/7/avatar') return editor(id, false);
    return null;
  }});
  if (!history.state && mode !== 'deep') history.replaceState({publicIndex:0, retained:'list-state'}, '');
  T.navigation.registerPage({
    accepts: url => !url.hash,
    visit(url, restored, replace) {
      history[replace ? 'replaceState' : 'pushState']({publicIndex:1}, '', url); return Promise.resolve();
    },
    restore() { restores++; }
  });
  document.addEventListener('tinycat:dialog-error', event => { document.body.dataset.dialogError = event.detail.error.message; });
  T.lifecycle.start(); await pause();
  const base = location.pathname + location.search;
  const count = () => history.length;
  if (mode === 'confirm') {
    await T.navigation.go(base + '&other=1');
    let decision;
    T.dialog.confirm({message:'Confirm test action'}).then(value => { decision = value; });
    check(modal().dataset.modalKind === 'confirm' && !location.hash, 'Standalone confirmations do not create bookmark URLs');
    history.back();
    await wait(() => decision === false && !modal(), 'Ordinary Back dismisses and cancels a standalone confirmation');
    check(restores === 1, 'A cancelled standalone confirmation leaves no orphaned action during page restoration');
  } else if (mode === 'nonmodal') {
    await wait(() => !!document.body.dataset.dialogError, 'A non-modal fragment target is rejected');
    check(!modal() && !location.hash && !document.getElementById('opener').hasAttribute('data-open') && requests.length === 0, 'A crafted fragment cannot turn an arbitrary page element into a dialog');
    delete document.body.dataset.dialogError;
  } else if (mode === 'legacy') {
    const list = base + '&list=1';
    await T.dialog.openResource('bot-detail-modal', '/api/bots/accounts/7?panel=detail', {baseUrl:list});
    check(T.navigation.pageUrl() === list, 'A legacy entry point identifies its already-rendered list as the modal background');
    history.back(); await wait(() => !modal() && location.pathname + location.search === list, 'A legacy modal closes to its canonical list');
    check(restores === 0, 'Legacy modal close does not reload the existing list');
  } else if (mode === 'invalid') {
    check(!modal() && requests.length === 0, 'Malformed modal fragments never trigger resource requests');
  } else if (mode === 'anchor') {
    const original = location.href;
    await T.dialog.openResource('bot-detail-modal', '/api/bots/accounts/7?panel=detail');
    check(location.hash.includes('modal=bots/accounts/7') && !location.hash.includes('#section'), 'A modal replaces the visible fragment without corrupting the base anchor');
    history.back(); await wait(() => !modal() && location.href === original, 'Closing restores the original page anchor');
  } else if (mode === 'deep') {
    await wait(() => modal() && tab('history').getAttribute('aria-selected') === 'true', 'A direct URL opens its remote modal and selected tab');
    const length = count(); history.back();
    await wait(() => !modal() && !location.hash, 'Back from a direct modal URL stays on the underlying page');
    history.forward();
    await wait(() => modal() && tab('history').getAttribute('aria-selected') === 'true', 'Forward restores the deep-linked modal');
    check(count() === length && restores === 0, 'Deep-link traversal adds no duplicate entries or page reads');
  } else if (mode === 'reload' && sessionStorage.getItem('history-reload')) {
    const length = Number(sessionStorage.getItem('history-reload'));
    await wait(() => modal() && modal().dataset.modalUrl === '/api/users/7/avatar', 'Reload reconstructs the complete nested modal stack');
    check(count() === length, 'Reload does not seed another base or modal entry');
    history.back();
    await wait(() => modal() && modal().dataset.modalUrl === '/api/bots/accounts/7?panel=detail' && tab('history').getAttribute('aria-selected') === 'true', 'Back after reload restores the parent tab');
    history.back(); await wait(() => !modal() && !location.hash, 'The next Back closes the parent after reload');
    check(restores === 0, 'Reloaded modal history never reloads the underlying list');
  } else {
    window.scrollTo(0, 180); const scroll = scrollY;
    const opener = document.getElementById('opener'); opener.focus({preventScroll:true});
    await T.dialog.openResource('bot-detail-modal', '/api/bots/accounts/7?panel=detail');
    const length = count();
    check(location.hash.includes('modal=bots/accounts/7') && location.hash.includes('panel=detail'), 'Opening a modal adds its readable resource URL');
    check(history.state.publicIndex === 0 && history.state.retained === 'list-state' && history.state.tinycatModal.stack.length === 1, 'Modal metadata is isolated from the page navigation state');
    tab('history').click();
    check(location.hash.includes('tab=history') && count() === length, 'Tab selection replaces the modal entry');
    await T.dialog.openResource('avatar-modal', '/api/users/7/avatar');
    check(history.state.tinycatModal.stack.length === 2, 'A nested editor gets its own history step');
    if (mode === 'reload') {
      sessionStorage.setItem('history-reload', String(count())); location.reload(); return;
    }
    history.back();
    await wait(() => modal() && modal().id === 'bot-detail-modal' && location.hash.includes('tab=history'), 'Back closes only the nested editor');
    check(tab('history').getAttribute('aria-selected') === 'true', 'The existing parent keeps its active tab');
    history.back(); await wait(() => !modal() && !location.hash, 'Back closes the root modal');
    await pause(); check(Math.abs(scrollY - scroll) < 2 && document.activeElement === opener, 'Closing restores list scroll and opener focus');
    check(restores === 0, 'Modal Back does not call the page restoration provider');
    history.forward(); await wait(() => modal() && tab('history').getAttribute('aria-selected') === 'true', 'Forward fetches and reopens the selected modal tab');
    T.dialog.close(modal()); await wait(() => !location.hash, 'A programmatic save/close consumes the modal history entry');
    history.forward(); await wait(() => modal(), 'Forward still works after closing with a UI action');
    tab('account').click();
    const input = modal().querySelector('input'); input.value = 'Unsaved'; input.dispatchEvent(new Event('input', {bubbles:true}));
    history.back(); await wait(() => modal() && modal().dataset.modalKind === 'confirm', 'Back uses the shared unsaved-changes confirmation');
    modal().querySelector('.modal-footer button').click();
    await wait(() => location.hash.includes('modal=bots/accounts/7') && modal() && modal().id === 'bot-detail-modal', 'Cancel restores the same modal history entry');
    check(modal().querySelector('input').value === 'Unsaved', 'Cancel preserves the unsaved draft');
    check(!JSON.stringify(history.state).includes('Unsaved'), 'Form drafts are never serialized into history state');
    history.back(); await wait(() => modal() && modal().dataset.modalKind === 'confirm', 'A later Back can retry discarding');
    modal().querySelectorAll('.modal-footer button')[1].click();
    await wait(() => !modal() && !location.hash, 'Confirmed discard closes the editor on its original page');
    const persistent = editor('settings-editor-modal', true); T.lifecycle.hydrate(persistent); T.dialog.open(persistent);
    tab('history').click(); check(location.hash.includes('modal=settings-editor-modal') && location.hash.includes('tab=history'), 'Persistent settings share the same URL contract');
    persistent.querySelector('[data-modal-close]').click(); await wait(() => !modal() && !location.hash, 'The standard close button consumes persistent modal history');
    history.forward(); await wait(() => modal() === persistent && tab('history').getAttribute('aria-selected') === 'true', 'Persistent modals reopen without losing their tab');
    document.dispatchEvent(new KeyboardEvent('keydown', {key:'Escape',bubbles:true})); await wait(() => !modal() && !location.hash, 'Escape closes the modal and returns to the base before the race test');
    delayed = true; history.forward();
    // Persistent Forward is synchronous; use the remote entry for a pending-read race.
    await wait(() => modal() === persistent, 'Persistent Forward settles before opening a remote editor');
    await T.dialog.requestClose(persistent); await wait(() => !location.hash, 'Return to the base before opening a remote resource');
    delayed = false; await T.dialog.openResource('bot-detail-modal', '/api/bots/accounts/7?panel=detail');
    T.dialog.close(modal()); await wait(() => !location.hash, 'Close the remote editor before slow Forward');
    delayed = true; history.forward(); await wait(() => releaseRead !== null, 'Forward starts its resource request');
    history.back(); await wait(() => !location.hash, 'Back cancels a pending Forward read');
    releaseRead({data:{}}); await pause(); check(!modal() && !document.body.dataset.dialogError, 'A late response cannot resurrect a closed modal');
    delayed = false; await T.dialog.openResource('bot-detail-modal', '/api/bots/accounts/7?panel=detail');
    await T.navigation.go(base + '&next=1');
    check(!modal() && !location.hash && !history.state.tinycatModal, 'Ordinary page navigation clears modal state');
    history.back(); await wait(() => restores === 1, 'Back from a page visit restores the underlying page once');
    await T.navigation.go(base + '&other=1');
    await T.dialog.openResource('bot-detail-modal', '/api/bots/accounts/7?panel=detail');
    history.go(-2); await wait(() => !modal() && T.navigation.pageUrl() === base, 'Skipping a modal and its base restores the previous page');
    history.go(2); await wait(() => modal() && T.navigation.pageUrl().endsWith('&other=1'), 'Forward across pages restores the modal background before its editor');
    const draft = modal().querySelector('input'); draft.value = 'Cross-page draft'; draft.dispatchEvent(new Event('input', {bubbles:true}));
    history.go(-2); await wait(() => modal() && modal().dataset.modalKind === 'confirm', 'Cross-page Back also protects unsaved modal changes');
    modal().querySelector('.modal-footer button').click();
    await wait(() => location.search.endsWith('&other=1') && location.hash && modal() && modal().dataset.modalKind !== 'confirm', 'Cancelling a multi-step Back restores the exact history position');
    check(modal().querySelector('input').value === 'Cross-page draft', 'The cross-page cancellation retains the draft');
    history.go(-2); await wait(() => modal() && modal().dataset.modalKind === 'confirm', 'A multi-step Back can be retried');
    modal().querySelectorAll('.modal-footer button')[1].click();
    await wait(() => !modal() && T.navigation.pageUrl() === base, 'Accepted cross-page Back restores the correct page');
    history.go(2); await wait(() => modal() && T.navigation.pageUrl().endsWith('&other=1'), 'Restore the editor before a rapid Back sequence');
    const rapidDraft = modal().querySelector('input'); rapidDraft.value = 'Rapid Back draft'; rapidDraft.dispatchEvent(new Event('input', {bubbles:true}));
    history.back(); await wait(() => modal() && modal().dataset.modalKind === 'confirm', 'The first Back asks about the current draft');
    history.back();
    await wait(() => location.search.endsWith('&other=1') && location.hash && modal() && modal().dataset.modalKind !== 'confirm', 'A second Back dismisses the confirmation and restores the editor once');
    check(modal().querySelector('input').value === 'Rapid Back draft', 'Rapid Back never discards or resurrects stale dialogs');
    check(requests.every(url => url.startsWith('/api/')), 'Only same-origin API resources supply restored modal content');
  }
  check(!document.body.dataset.dialogError, 'No dialog errors occurred');
  document.getElementById('history-test-results').textContent = JSON.stringify({ok:true, mode, checks});
}()).catch(error => { document.getElementById('history-test-results').textContent = JSON.stringify({ok:false,error:error.stack}); });
