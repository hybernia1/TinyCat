(async function () {
  const checks = [], P = TinyCat.popovers;
  // Flush queued layout work deterministically: virtual-time Chromium may defer
  // compositor frames while advancing timers inside viewport fixture iframes.
  const animationFrames = [];
  window.requestAnimationFrame = callback => { animationFrames.push(callback); return animationFrames.length; };
  function check(value, label) { if (!value) throw new Error(label); checks.push(label); }
  function tick() { return new Promise(resolve => setTimeout(() => { animationFrames.splice(0).forEach(callback => callback(performance.now())); resolve(); }, 40)); }
  function make(kind, bottom) {
    const root = document.createElement(kind === 'notifications' ? 'div' : 'details');
    root.dataset.popover = '';
    root.style.cssText = 'position:fixed;right:4px;'+(bottom ? 'bottom:8px' : 'top:12px');
    const trigger = document.createElement(kind === 'notifications' ? 'a' : 'summary');
    if (kind === 'notifications') { trigger.href = '/notifications'; trigger.dataset.popoverTrigger = ''; }
    trigger.textContent = kind; root.append(trigger);
    const panel = document.createElement('div'); panel.className = 'popover-panel' + (kind === 'notifications' ? ' notification-panel' : kind === 'filters' ? ' filter-menu-panel' : '');
    const first = document.createElement('button'), last = document.createElement('button');
    first.className = 'context-menu-action'; last.className = 'context-menu-action';
    first.textContent = 'First'; last.textContent = 'Last'; last.dataset.popoverClose = '';
    panel.append(first, last); root.append(panel); document.body.append(root);
    return { root, trigger, panel, first, last };
  }
  function key(node, value) { return node.dispatchEvent(new KeyboardEvent('keydown', { key: value, bubbles: true, cancelable: true })); }
  function fits(panel) { const b = panel.getBoundingClientRect(); return b.left >= 11 && b.right <= innerWidth - 11 && b.top >= 11 && b.bottom <= innerHeight - 11 && b.height > 0; }
  try {
    const viewport = new EventTarget();
    Object.assign(viewport, { width: innerWidth, height: innerHeight, offsetLeft: 0, offsetTop: 0 });
    Object.defineProperty(window, 'visualViewport', { configurable: true, value: viewport });
    const menu = make('actions'), notes = make('notifications'); notes.root.style.left = '4px'; notes.root.style.right = 'auto';
    const collision = document.createElement('div'); collision.id = 'popover-panel-1'; document.body.append(collision);
    P.init(); P.init(); P.prepare(document);
    check(menu.panel.id !== collision.id && notes.panel.id !== menu.panel.id, 'Generated panel IDs avoid existing elements and other popovers');
    menu.trigger.click(); await tick();
    check(menu.root.open && menu.panel.matches(':popover-open'), 'One activation after repeated hydration opens exactly once in top layer');
    check(menu.trigger.getAttribute('aria-expanded') === 'true' && menu.trigger.getAttribute('aria-controls') === menu.panel.id, 'Trigger exposes state and panel identity');
    check(fits(menu.panel), 'Panel stays inside horizontal and vertical viewport bounds');
    const actionBounds = menu.panel.getBoundingClientRect();
    check(actionBounds.width <= 200 && actionBounds.height <= 120, 'A two-action context menu keeps compact intrinsic dimensions: '+JSON.stringify(actionBounds.toJSON()));
    key(menu.trigger, 'ArrowDown'); check(document.activeElement === menu.first, 'ArrowDown opens and focuses first action');
    key(menu.first, 'End'); check(document.activeElement === menu.last, 'End focuses last action');
    key(menu.last, 'ArrowDown'); check(document.activeElement === menu.first, 'Arrow navigation wraps');
    key(menu.first, 'Home'); check(document.activeElement === menu.first, 'Home focuses first action');
    let escaped = 0;
    document.addEventListener('keydown', event => { if (event.key === 'Escape') escaped++; }, true);
    key(menu.first, 'Escape'); await tick();
    check(!menu.root.open && document.activeElement === menu.trigger && escaped === 0, 'Escape restores trigger focus before an enclosing modal handles it');
    key(menu.trigger, 'ArrowUp'); check(document.activeElement === menu.last, 'ArrowUp enters at last action');
    menu.last.click(); check(!menu.root.open, 'Choosing a close action dismisses the surface');
    P.open(menu.root); notes.trigger.click(); await tick();
    check(!menu.root.open && notes.trigger.getAttribute('aria-expanded') === 'true' && notes.panel.matches(':popover-open'), 'Notifications and details share exclusive opening');
    check(Math.abs(notes.panel.getBoundingClientRect().width - Math.min(360, innerWidth - 24)) < 2, 'Notification width comes from shared surface variables regardless of stylesheet order');
    const modified = new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true });
    // Avoid navigating the headless fixture while checking the controller preserves browser modifiers.
    notes.trigger.addEventListener('click', event => { check(!event.defaultPrevented, 'Modified notification link retains native navigation'); event.preventDefault(); }, { once: true });
    notes.trigger.dispatchEvent(modified);
    document.body.click(); check(notes.trigger.getAttribute('aria-expanded') === 'false', 'Outside click closes notification preview through the same controller');
    P.open(menu.root); notes.trigger.focus(); check(!menu.root.open, 'Tab or focus leaving the surface dismisses it without stealing focus');
    const filter = make('filters', true); filter.panel.style.height = '480px';
    const input = document.createElement('input'); input.value = 'draft'; filter.panel.prepend(input);
    for (let i = 0; i < 30; i++) { const row = document.createElement('div'); row.textContent = 'Long filter option '+i; filter.panel.append(row); }
    P.prepare(filter.root); filter.trigger.click(); await tick();
    check(fits(filter.panel) && filter.panel.getBoundingClientRect().bottom <= filter.trigger.getBoundingClientRect().top, 'Tall panels flip above a low trigger and scroll within available height');
    check(Math.abs(filter.panel.getBoundingClientRect().width - Math.min(300, innerWidth - 32)) < 2 && getComputedStyle(filter.panel).paddingTop === '12px', 'Filter width and padding survive global cascade order');
    input.focus(); check(key(input, 'ArrowDown') && key(input, 'Home') && key(input, 'Enter'), 'Native form editing and submission keys are preserved');
    P.prepare(document); check(input.value === 'draft' && document.activeElement === input && filter.root.open, 'Hydration preserves form contents and keyboard focus');
    filter.panel.scrollTop = 40; await tick(); check(filter.root.open && filter.panel.scrollTop === 40, 'Long content scrolls without dismissing the popover');
    window.dispatchEvent(new Event('resize')); await tick(); check(fits(filter.panel) && filter.panel.scrollTop === 40 && input.value === 'draft', 'Resize recomputes available bounds without resetting scroll or form values');
    filter.root.remove(); await tick(); check(!filter.panel.matches(':popover-open') && filter.trigger.getAttribute('aria-expanded') === 'false', 'Removing AJAX content cleans up its open top-layer panel');
    Object.assign(viewport, { width: Math.floor(innerWidth * 0.75), height: Math.min(200, innerHeight - 60), offsetLeft: 12, offsetTop: 30 });
    P.open(notes.root); viewport.dispatchEvent(new Event('resize')); await tick();
    const zoomed = notes.panel.getBoundingClientRect();
    check(zoomed.left >= viewport.offsetLeft + 11 && zoomed.right <= viewport.offsetLeft + viewport.width - 11 && zoomed.top >= viewport.offsetTop + 11 && zoomed.bottom <= viewport.offsetTop + viewport.height - 11 && zoomed.height > 0,
      'Notification surface fits a reduced and offset visual viewport (simulated zoom and keyboard): '+JSON.stringify({width:viewport.width,height:viewport.height,panel:zoomed.toJSON()}));
    viewport.offsetLeft += 6; viewport.offsetTop += 5; viewport.dispatchEvent(new Event('scroll')); await tick();
    const panned = notes.panel.getBoundingClientRect();
    check(panned.left >= viewport.offsetLeft + 11 && panned.right <= viewport.offsetLeft + viewport.width - 11 && panned.top >= viewport.offsetTop + 11, 'Panning a zoomed visual viewport repositions the panel: '+JSON.stringify(panned.toJSON()));
    const search = document.createElement('div'); search.dataset.popover = ''; search.dataset.popoverMode = 'input'; search.dataset.popoverMatchWidth = 'true';
    search.style.cssText = 'position:fixed;top:70px;left:0';
    const searchInput = document.createElement('input'); searchInput.dataset.popoverTrigger = ''; searchInput.style.width = innerWidth+'px';
    const suggestions = document.createElement('div'); suggestions.className = 'popover-panel'; suggestions.textContent = 'Search result'; search.append(searchInput, suggestions); document.body.append(search);
    P.prepare(search); P.open(search); await tick();
    const suggestionBounds = suggestions.getBoundingClientRect();
    check(suggestionBounds.width <= viewport.width - 24 && suggestionBounds.right <= viewport.offsetLeft + viewport.width - 11, 'Input-matched suggestions also shrink to the visible mobile viewport');
    search.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerType: 'touch' }));
    check(suggestions.matches(':popover-open'), 'Touching inside a popover preserves it');
    document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerType: 'touch' }));
    check(!suggestions.matches(':popover-open'), 'Touching outside dismisses the mobile popover');
    search.remove();
    Object.assign(viewport, { width: innerWidth, height: innerHeight, offsetLeft: 0, offsetTop: 0 });
    viewport.dispatchEvent(new Event('resize')); await tick(); P.closeAll();
    P.open(notes.root);
    check(Math.abs(notes.panel.getBoundingClientRect().width - Math.min(360, innerWidth - 24)) < 2, 'Returning from zoom restores the normal panel width');
    P.closeAll();
    P.open(menu.root); document.dispatchEvent(new CustomEvent('tinycat:modal-open', { bubbles: true }));
    check(!menu.root.open, 'Opening a modal dismisses background popovers');
    const outer = document.createElement('div'); outer.className = 'modal-body'; outer.style.cssText = 'position:fixed;inset:40px 20px;overflow:hidden'; document.body.append(outer);
    const inside = make('modal-filter'); inside.root.style.cssText = 'position:absolute;top:10px;right:0'; outer.append(inside.root);
    inside.panel.style.height = '900px'; P.prepare(inside.root); inside.trigger.click(); await tick();
    const bounds = inside.panel.getBoundingClientRect(), clip = outer.getBoundingClientRect();
    check(bounds.top >= clip.top && bounds.bottom <= clip.bottom && fits(inside.panel), 'Modal popovers respect the visible modal body bounds');
    check(document.elementFromPoint(bounds.left + 5, bounds.top + 5) === inside.panel || inside.panel.contains(document.elementFromPoint(bounds.left + 5, bounds.top + 5)), 'Top-layer surface stays above clipping ancestors');
    P.closeAll(); inside.root.remove(); outer.remove();
    for (const surface of ['user-menu-popover', 'language-menu-list', 'archive-filter-menu']) {
      const specific = make(surface); specific.panel.classList.add(surface);
      P.prepare(specific.root); specific.trigger.click(); await tick();
      check(fits(specific.panel) && specific.panel.scrollWidth <= specific.panel.clientWidth, surface+' fits the mobile viewport without horizontal content scrolling');
      P.close(specific.root); specific.root.remove();
    }
    const focusHost = document.createElement('main'); focusHost.tabIndex = -1; document.body.append(focusHost);
    const touchMenu = make('touch-focus'); focusHost.append(touchMenu.root); P.prepare(touchMenu.root); P.open(touchMenu.root);
    touchMenu.last.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerType: 'touch' })); focusHost.focus();
    check(touchMenu.root.open, 'An in-panel touch keeps the panel open when WebKit focuses its ancestor before click');
    touchMenu.last.click(); check(!touchMenu.root.open, 'The touched action still closes its panel on click');
    P.open(touchMenu.root); touchMenu.first.focus();
    touchMenu.first.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerType: 'touch' }));
    touchMenu.first.dispatchEvent(new PointerEvent('pointercancel', { bubbles: true, pointerType: 'touch' })); focusHost.focus();
    check(!touchMenu.root.open, 'A cancelled touch does not suppress subsequent outside focus');
    P.open(touchMenu.root); touchMenu.first.focus();
    touchMenu.first.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerType: 'touch' })); key(touchMenu.first, 'Tab'); focusHost.focus();
    check(!touchMenu.root.open, 'Keyboard navigation still closes a panel on outside focus after a touch');
    focusHost.remove();
    const fallback = make('fallback'); fallback.panel.showPopover = undefined; fallback.panel.hidePopover = undefined;
    P.prepare(fallback.root); fallback.trigger.click(); await tick();
    check(fallback.root.open && fits(fallback.panel), 'Browsers without the native API use the same bounded controller');
    key(fallback.trigger, 'Escape'); check(!fallback.root.open, 'Fallback closes through shared keyboard handling');
    P.open(fallback.root); TinyCat.lifecycle.popovers.dispose(fallback.root);
    check(!fallback.root.open && fallback.trigger.getAttribute('aria-expanded') === 'false', 'Lifecycle disposal closes the surface before DOM replacement');
    menu.root.open = true; await tick(); check(menu.panel.matches(':popover-open'), 'Native details state changes synchronize the same surface');
    menu.root.open = false; await tick(); check(!menu.panel.matches(':popover-open'), 'Native details closing removes the top-layer surface');
    const invalid = document.createElement('details'); invalid.dataset.popover = ''; document.body.append(invalid);
    let rejected = false; try { P.prepare(invalid); } catch (error) { rejected = error instanceof TypeError; }
    invalid.remove(); check(rejected, 'Malformed or independently owned panels fail the shared runtime contract');
    document.getElementById('results').textContent = JSON.stringify({ ok: true, checks });
  } catch (error) { document.getElementById('results').textContent = JSON.stringify({ ok: false, error: String(error.stack || error), checks }); }
}());
