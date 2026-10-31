<?php
declare(strict_types=1);
require_once __DIR__ . '/../cache-isolation.php';
define('TINYCAT', true);
$root = dirname(__DIR__, 2);
require $root . '/App/bootstrap.php';
(new ReflectionProperty(TinyCat\Settings\Store::class, 'settings'))->setValue(null, []);
TinyCat\Users\Authentication::reset(null, true);
$filters = ['q' => '', 'bot' => '3'];
$items = [['id' => 7, 'name' => str_repeat('Source', 25), 'feed_url' => 'https://example.test/' . str_repeat('long-path', 30), 'bot_user_id' => 3, 'bot_username' => 'demo_bot', 'enabled' => true, 'interval_minutes' => 60, 'publication_interval_minutes' => 30, 'last_error' => str_repeat('Error ', 25)]];
$list = TinyCat\Support\Components::render('bots/sources', ['items' => $items, 'bots' => [['id' => 3, 'username' => 'demo_bot']], 'filters' => $filters, 'sorting' => TinyCat\Support\AdminSorting::headings('sources', $filters + ['sort' => 'name', 'direction' => 'asc']), 'navigation' => ['visible' => false], 'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 1]]);
$content = TinyCat\Support\AdminViews::listCard('Sources', 'rss', 'bots-list', 'bots-filter-modal', new TinyCat\Support\Markup($list), primary: true);
$content .= TinyCat\Support\View::part('Bots/modals/source-filter', ['bots' => [['id' => 3, 'username' => str_repeat('bot', 50)]], 'filter_bot_id' => 3, 'filters' => $filters]);
$html = TinyCat\Support\View::render('admin', ['content' => $content, 'actions' => '<button class="btn btn-primary btn-sm">New source</button>', 'adminIdentity' => '@admin', 'appName' => 'TinyCat', 'nav' => [], 'siteLogoUrl' => '', 'title' => 'Sources', 'authUser' => null, 'profileUrl' => '', 'profileNeedsEmail' => false, 'userMenu' => '', 'settingsEditor' => '', 'titleIcon' => 'rss'], $root . '/App/Site/templates');
$context = match ($argv[1] ?? '') { '--modal' => 'modal', '--accounts' => 'accounts', default => 'page' };
if ($context === 'accounts') {
    $account = ['id' => 3, 'username' => str_repeat('demo_bot_', 20), 'status' => 'active', 'source_count' => 1, 'post_count' => 12, 'updated_at' => '2026-10-30T12:00:00Z'];
    $list = TinyCat\Support\Components::render('bots/accounts', ['items' => [$account, array_replace($account, ['id' => 4, 'status' => 'ban'])], 'filters' => [], 'sorting' => TinyCat\Support\AdminSorting::headings('accounts', ['sort' => 'source_count', 'direction' => 'desc']), 'navigation' => ['visible' => false], 'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 2]]);
    $content = TinyCat\Support\AdminViews::listCard('Accounts', 'users', 'bot-accounts-list', 'bot-accounts-filter-modal', new TinyCat\Support\Markup($list), primary: true);
    $content .= TinyCat\Support\View::part('Bots/modals/account-filter', ['filters' => [], 'prepared' => ['accounts_url' => '/api/bots/accounts', 'admin_user_statuses' => ['active' => 'Active', 'ban' => 'Inactive']]]);
    $html = TinyCat\Support\View::render('admin', ['content' => $content, 'actions' => '', 'adminIdentity' => '@admin', 'appName' => 'TinyCat', 'nav' => [], 'siteLogoUrl' => '', 'title' => 'Accounts', 'authUser' => null, 'profileUrl' => '', 'profileNeedsEmail' => false, 'userMenu' => '', 'settingsEditor' => '', 'titleIcon' => 'users'], $root . '/App/Site/templates');
}
if ($context === 'modal') {
    $model = ['runs' => [['source_name' => str_repeat('Source', 25), 'status' => 'error', 'started_at' => '2026-10-30T09:00:00Z', 'items_seen' => 0, 'error' => 'Feed unavailable']], 'filters' => ['q' => '', 'source' => '', 'status' => ''], 'sources' => ['' => 'All sources', '7' => str_repeat('Long source name ', 20)], 'sorting' => [], 'navigation' => ['visible' => false], 'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 1], 'api_url' => '/api/bots/accounts/3/runs', 'history_url' => false, 'list_target' => '#bot-detail-runs-list', 'filter_modal' => 'bot-detail-runs-filter-modal'];
    $model['sorting'] = TinyCat\Support\AdminSorting::headings('bot_runs', ['sort' => 'started_at', 'direction' => 'desc'], '/api/bots/accounts/3/runs', '/admin/bots/3');
    foreach ($model['sorting'] as &$heading) { $heading['history'] = false; }
    unset($heading);
    $html = TinyCat\Support\Components::render('ui/modal', ['root_attributes' => ['class' => 'modal', 'data-open' => 'true', 'data-modal-kind' => 'editor', 'data-modal-size' => 'workspace'], 'panel_attributes' => ['class' => 'modal-panel', 'data-tabs' => ''], 'title' => '@demo_bot', 'title_id' => 'fixture-title', 'close_label' => 'Close', 'close_icon' => new TinyCat\Support\Markup(TinyCat\Support\Html::icon('close')), 'body' => new TinyCat\Support\Markup(TinyCat\Support\Components::render('bots/runs-list', $model)), 'has_footer' => true, 'footer' => new TinyCat\Support\Markup('<button class="btn">Close</button>')]);
    $html .= TinyCat\Support\Components::render('bots/run-filter-form', $model);
}
$css = TinyCat\Support\AssetGraph::stylesheet(TinyCat\Support\ClientAssets::sourceStyles('/admin/bots/list'));
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>' . $css . '</style></head><body class="admin-shell-page">' . $html;
?>
<script>
window.TinyCat = { lifecycle: { register: function (name, hooks) { if (hooks.init) hooks.init(); if (name === 'tables') { window.tableService = hooks; hooks.hydrate(document); } if (name === 'tooltips') { window.tooltipService = hooks; hooks.hydrate(document); } } } };
window.TinyCat.i18n = { t: key => ({'common.menu':'Menu', 'common.sort':'Řazení'})[key] || key };
<?= preg_replace('/^import .*;\R/m', '', file_get_contents($root . '/assets/core/dom.js')) ?>
<?= preg_replace('/^import .*;\R/m', '', file_get_contents($root . '/assets/core/icons.js')) ?>
<?= preg_replace('/^import .*;\R/m', '', file_get_contents($root . '/assets/core/components.js')) ?>
window.TinyCat.components.register(<?= json_encode(array_intersect_key(TinyCat\Support\Components::templates(), array_flip(['ui/filter-chip', 'ui/list-summary'])), JSON_HEX_TAG | JSON_THROW_ON_ERROR) ?>);
<?= preg_replace('/^import .*;\R/m', '', file_get_contents($root . '/assets/ui/filters.js')) ?>
<?= preg_replace('/^import .*;\R/m', '', file_get_contents($root . '/assets/ui/tables.js')) ?>
<?= preg_replace('/^import .*;\R/m', '', file_get_contents($root . '/assets/ui/tooltips.js')) ?>
<?= preg_replace('/^import .*;\R/m', '', file_get_contents($root . '/assets/ui/menus.js')) ?>
<?= preg_replace('/^import .*;\R/m', '', file_get_contents($root . '/assets/ui/fields.js')) ?>
<?= preg_replace('/^import .*;\R/m', '', file_get_contents($root . '/assets/ui/tabs.js')) ?>
let escapedToModal = 0;
document.addEventListener('keydown', event => { if (event.key === 'Escape') escapedToModal++; }, true);
(async function () {
const context = <?= json_encode($context, JSON_THROW_ON_ERROR) ?>;
if (context === 'modal') {
  const body = document.querySelector('.modal-body'), listing = body.firstElementChild;
  const sections = [
    {name:'account', label:'Nastavení bot účtu', icon:'settings'},
    {name:'sources', label:'Zdroje', icon:'rss'},
    {name:'history', label:'Historie běhů', icon:'clock', content:listing},
    {name:'posts', label:'Příspěvky', icon:'file'}
  ];
  const layout = window.TinyCat.ui.tabbedContent('viewport-tabs', sections, 'history', 'Bot sections');
  body.replaceChildren(layout.content);
  window.TinyCat.ui.pinDialogTabs(document.querySelector('.modal'), layout.content);
  window.TinyCat.tabs.hydrate();
}
let navigationChanges = true;
if (context === 'modal') {
  const select = document.querySelector('[data-tab-select]');
  select.value = 'sources'; select.dispatchEvent(new Event('change', {bubbles:true}));
  navigationChanges = document.querySelector('[data-tab-panel="history"]').hidden && !document.querySelector('[data-tab-panel="sources"]').hidden && document.querySelector('[data-tab="sources"]').getAttribute('aria-selected') === 'true';
  select.value = 'history'; select.dispatchEvent(new Event('change', {bubbles:true}));
  navigationChanges = navigationChanges && !document.querySelector('[data-tab-panel="history"]').hidden && document.querySelector('[data-tab-select-icon] use').getAttribute('href').endsWith('#clock');
}
const form = document.querySelector('[data-filter-content]') || document.querySelector('form:not(.admin-search-form):not(.admin-per-page-form)');
const panel = document.querySelector('[data-filter-panel]');
panel.append(form); form.hidden = false; form.id = panel.dataset.filterPanel; form.dataset.filterContent = '';
window.TinyCat.filters.hydrate();
const menu = panel.parentElement, summary = document.querySelector('[data-list-summary]');
const results = [];
const labelsBefore = document.querySelectorAll('.table-cell-label').length;
window.tableService.hydrate(document);
const hydrationStable = labelsBefore === document.querySelectorAll('.table-cell-label').length;
for (const long of [false, true]) {
  const badge = document.createElement('span'); badge.className = 'badge'; badge.textContent = 'x'.repeat(400);
  if (long) summary.append(badge);
  for (const open of [false, true]) {
    menu.open = open;
    await new Promise(resolve => setTimeout(resolve, 100));
    const bounds = panel.getBoundingClientRect(), wrap = document.querySelector('.table-wrap');
    const summaryClean = !summary.querySelector('[data-list-result-count]') && (getComputedStyle(summary).display !== 'none') === !!summary.querySelector('.badge');
    const identity = wrap.querySelector('.table-identity'), primary = identity.querySelector('.table-primary');
    const primaryBounds = primary.getBoundingClientRect(), details = [...identity.querySelectorAll('.table-meta')];
    const identityAligned = details.every(detail => Math.abs(detail.getBoundingClientRect().left - primaryBounds.left) < 1 && detail.getBoundingClientRect().top >= primaryBounds.bottom && parseFloat(getComputedStyle(detail).fontSize) < parseFloat(getComputedStyle(primary).fontSize));
    const primaryStyle = getComputedStyle(primary);
    const textLimited = primaryBounds.width <= parseFloat(primaryStyle.maxInlineSize) + 1 && primaryBounds.height <= 2 * parseFloat(primaryStyle.lineHeight) + 1 && details.every(detail => detail.getBoundingClientRect().height <= 2 * parseFloat(getComputedStyle(detail).lineHeight) + 1);
    const fullTextKept = primary.dataset.tooltip === primary.textContent && [...identity.querySelectorAll('.table-text-url')].every(link => link.dataset.tooltip === link.textContent && link.getAttribute('href') === link.textContent && link.getBoundingClientRect().height <= parseFloat(getComputedStyle(link).lineHeight) + 1);
    primary.setAttribute('aria-describedby', 'fixture-description');
    primary.focus({preventScroll:true});
    const tooltip = document.querySelector('[role="tooltip"]'), tooltipBounds = tooltip.getBoundingClientRect();
    const tooltipFits = !primary.hasAttribute('title') && !tooltip.hidden && tooltip.textContent === primary.textContent && tooltipBounds.left >= 0 && tooltipBounds.right <= innerWidth && tooltipBounds.top >= 0 && tooltipBounds.bottom <= innerHeight && primary.getAttribute('aria-describedby').includes(tooltip.id);
    primary.dispatchEvent(new KeyboardEvent('keydown', {key:'Escape', bubbles:true}));
    const tooltipClosed = tooltip.hidden && primary.getAttribute('aria-describedby') === 'fixture-description' && escapedToModal === 0;
    primary.blur();
    const actionsAligned = [...wrap.querySelectorAll('.table-actions-cell')].every(cell => getComputedStyle(cell).textAlign === 'right') && [...wrap.querySelectorAll('.table-actions')].every(actions => getComputedStyle(actions).flexWrap === (innerWidth < 600 ? 'wrap' : 'nowrap') && actions.getBoundingClientRect().right <= actions.parentElement.getBoundingClientRect().right);
    const table = wrap.querySelector('table'), mobile = innerWidth < 600;
    let navigationFits = true;
    if (context === 'modal') {
      const row = document.querySelector('.modal-tabs'), select = row.querySelector('[data-tab-select]'), tabs = row.querySelector('.tabs');
      navigationFits = mobile ? getComputedStyle(tabs).display === 'none' && row.getBoundingClientRect().height <= 64 && select.getBoundingClientRect().width >= 200 && select.getBoundingClientRect().height >= 44 : getComputedStyle(tabs).display === 'flex' && getComputedStyle(row.querySelector('.modal-tab-switch')).display === 'none';
      navigationFits = navigationFits && document.querySelector('.modal-body').getBoundingClientRect().top >= row.getBoundingClientRect().bottom - 1 && row.getBoundingClientRect().top >= document.querySelector('.modal-header').getBoundingClientRect().bottom - 1;
    }
    const cardsFit = !mobile || (wrap.scrollWidth <= wrap.clientWidth && primaryBounds.width >= Math.min(200, wrap.clientWidth - 64) && [...table.querySelectorAll('tbody td')].every(cell => cell.getBoundingClientRect().right <= wrap.getBoundingClientRect().right + 1) && [...table.querySelectorAll('.table-cell-label')].every(label => getComputedStyle(label).display !== 'none' && label.textContent.trim()) && [...table.querySelectorAll('.table-actions .btn')].every(button => button.getBoundingClientRect().width >= 44 && button.getBoundingClientRect().height >= 44));
    const desktopTable = mobile || (getComputedStyle(table).display === 'table' && [...table.querySelectorAll('.table-cell-label')].every(label => getComputedStyle(label).display === 'none'));
    const sortMenu = wrap.parentElement.querySelector('.table-mobile-sort');
    const sortAvailable = mobile ? !!sortMenu && !sortMenu.open && sortMenu.querySelector('summary').getBoundingClientRect().height >= 30 && sortMenu.getBoundingClientRect().right <= innerWidth : [...table.querySelectorAll('.table-sort')].every(link => link.getBoundingClientRect().width > 1);
    wrap.scrollLeft = 64; const tableScrollLeft = wrap.scrollLeft; wrap.scrollLeft = 0;
    results.push({context, summaryClean, navigationFits, navigationChanges, cardsFit, desktopTable, sortAvailable, tooltipFits, tooltipClosed, identityAligned, textLimited, fullTextKept, actionsAligned, height:innerHeight, width:innerWidth, long, open, documentWidth:document.documentElement.scrollWidth, bodyWidth:document.body.scrollWidth, filterLeft:bounds.left, filterRight:bounds.right, filterWidth:bounds.width, filterBottom:bounds.bottom, filterTop:bounds.top, filterLimit:context === 'modal' ? document.querySelector('.modal-body').getBoundingClientRect().bottom : innerHeight, filterClientWidth:panel.clientWidth, filterScrollWidth:panel.scrollWidth, tableWidth:wrap.clientWidth, tableScrollWidth:wrap.scrollWidth, tableScrollLeft, tableOverflow:getComputedStyle(wrap).overflowX,
      offenders:[...document.querySelectorAll('body *')].filter(el => !el.closest('script,style,.admin-sidebar,.table-wrap') && el.getBoundingClientRect().width && (el.getBoundingClientRect().right > innerWidth + 1 || el.getBoundingClientRect().left < -1)).map(el => ({tag:el.tagName,cls:el.className,left:el.getBoundingClientRect().left,right:el.getBoundingClientRect().right})).slice(0,8)});
  }
  badge.remove();
}
const mobileSort = document.querySelector('.table-mobile-sort');
let sortingWorks = true; const sortingDiagnostics = {};
if (innerWidth < 600) {
  const summary = mobileSort.querySelector('summary'), originals = [...document.querySelectorAll('table .table-sort')], choices = [...mobileSort.querySelectorAll('.table-sort-option')];
  const actions = mobileSort.closest('.admin-filter-actions'), filterButton = actions.querySelector('.filter-menu > summary'), pageSize = actions.querySelector('.admin-per-page-form');
  sortingWorks = choices.length === originals.length && choices.filter(link => link.getAttribute('aria-current') === 'true').length === 1 && choices.every((link,index) => ['href','data-history','data-ajax-target'].every(attr => link.getAttribute(attr) === originals[index].getAttribute(attr)));
  sortingWorks = sortingWorks && Math.abs(summary.getBoundingClientRect().height - filterButton.getBoundingClientRect().height) < 1 && Math.abs(summary.getBoundingClientRect().top - filterButton.getBoundingClientRect().top) < 1 && summary.getBoundingClientRect().right <= pageSize.getBoundingClientRect().left;
  sortingDiagnostics.controls = [sortingWorks, summary.getBoundingClientRect().toJSON(), filterButton.getBoundingClientRect().toJSON(), pageSize.getBoundingClientRect().toJSON()];
  summary.click(); await new Promise(resolve => setTimeout(resolve, 100));
  const popup = mobileSort.querySelector('.list-menu-panel'), bounds = popup.getBoundingClientRect(), body = mobileSort.closest('.modal-body');
  sortingWorks = sortingWorks && mobileSort.open && bounds.width <= 300 && bounds.left >= 0 && bounds.right <= innerWidth && bounds.top >= 0 && bounds.bottom <= (body ? body.getBoundingClientRect().bottom : innerHeight);
  sortingDiagnostics.popup = [sortingWorks, mobileSort.open, bounds.toJSON(), body && body.getBoundingClientRect().toJSON()];
  summary.focus({preventScroll:true}); summary.dispatchEvent(new KeyboardEvent('keydown', {key:'Escape', bubbles:true}));
  sortingWorks = sortingWorks && !mobileSort.open && document.activeElement === summary;
  sortingDiagnostics.escape = [sortingWorks, mobileSort.open, document.activeElement === summary];
  summary.click(); await new Promise(resolve => setTimeout(resolve, 100));
  let activated = false;
  document.addEventListener('click', event => { if (event.target.closest('.table-sort-option')) { event.preventDefault(); activated = true; } }, true);
  choices[0].focus({preventScroll:true}); choices[0].click();
  const state = window.TinyCat.menus.capture(choices[0]);
  sortingWorks = sortingWorks && activated && mobileSort.open && !!state;
  sortingDiagnostics.choice = [sortingWorks, activated, mobileSort.open, state];
  const host = document.querySelector('.table-wrap').parentElement, replacement = host.cloneNode(true);
  replacement.querySelector('.table-mobile-sort').remove(); host.replaceWith(replacement);
  window.tableService.hydrate(replacement); window.TinyCat.menus.restore(state);
  const restored = replacement.querySelector('.table-mobile-sort');
  sortingWorks = sortingWorks && restored.open && restored.querySelector('.list-menu-panel').contains(document.activeElement);
  sortingDiagnostics.restored = [sortingWorks, restored.open, restored.querySelector('.list-menu-panel').contains(document.activeElement)];
  restored.open = false;
  sortingWorks = sortingWorks && window.TinyCat.menus.capture(restored.querySelector('a')) === null;
  document.activeElement.blur();
}
const chipRoot = document.createElement('div'), searchForm = document.createElement('form'), chipForm = document.createElement('form');
searchForm.className = 'admin-search-form'; searchForm.dataset.ajaxTarget = chipForm.dataset.ajaxTarget = '#chip-test';
chipForm.id = 'chip-filter-test'; chipForm.dataset.filterContent = '';
const searchInput = document.createElement('input'); searchInput.type = 'search'; searchInput.name = 'q'; searchInput.value = 'needle'; searchForm.append(searchInput);
for (const [name,value] of [['q','needle'],['page','4'],['per_page','50'],['sort','updated_at'],['direction','desc']]) {
  const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; chipForm.append(input);
}
for (const [name,value] of [['status','active'],['role','admin']]) {
  const field = document.createElement('select'); field.name = name;
  for (const optionValue of ['',value]) { const option = document.createElement('option'); option.value = option.textContent = optionValue; field.append(option); }
  field.value = value; chipForm.append(field);
}
let chipSummary = window.TinyCat.components.render('ui/list-summary', {target:'#chip-test',filters:{q:'needle'}}), submissions = 0;
chipRoot.append(searchForm, chipSummary, chipForm); document.body.append(chipRoot);
chipForm.addEventListener('submit', event => { event.preventDefault(); submissions++; });
window.TinyCat.filters.hydrate();
const statusChip = chipSummary.querySelector('[data-filter-remove="status"]');
chipForm.dataset.apiBusy = 'true'; statusChip.click();
const busyChipSafe = submissions === 0 && chipForm.elements.status.value === 'active'; delete chipForm.dataset.apiBusy;
statusChip.focus({preventScroll:true}); statusChip.click();
const chipState = window.TinyCat.filters.capture(chipForm);
let chipsWork = busyChipSafe && submissions === 1 && chipForm.elements.status.value === '' && chipForm.elements.role.value === 'admin' && chipForm.elements.q.value === 'needle' && chipForm.elements.page.value === '1' && chipForm.elements.per_page.value === '50' && chipForm.elements.sort.value === 'updated_at' && chipForm.elements.direction.value === 'desc';
window.TinyCat.filters.hydrate(); window.TinyCat.filters.restore(chipState);
chipsWork = chipsWork && !chipSummary.querySelector('[data-filter-remove="status"]') && document.activeElement === chipSummary.querySelector('[data-filter-remove="q"]');
chipSummary.querySelector('[data-filter-remove="role"]').click(); window.TinyCat.filters.hydrate();
const queryChip = chipSummary.querySelector('[data-filter-remove="q"]'); queryChip.focus({preventScroll:true}); queryChip.click();
const searchState = window.TinyCat.filters.capture(chipForm);
const newSummary = window.TinyCat.components.render('ui/list-summary', {target:'#chip-test',filters:{q:chipForm.elements.q.value}}); chipSummary.replaceWith(newSummary); chipSummary = newSummary;
window.TinyCat.filters.hydrate(); window.TinyCat.filters.restore(searchState);
chipsWork = chipsWork && submissions === 3 && searchInput.value === '' && chipForm.elements.q.value === '' && document.activeElement === searchInput && getComputedStyle(chipSummary).display === 'none';
const botField = document.createElement('select'); botField.name = 'bot';
for (const value of ['0','3']) { const option = document.createElement('option'); option.value = option.textContent = value; botField.append(option); }
botField.value = '3'; chipForm.append(botField);
const passive = document.createElement('input'); passive.type = 'checkbox'; passive.name = 'passive'; passive.value = 'yes'; chipForm.append(passive);
window.TinyCat.filters.hydrate();
chipsWork = chipsWork && !chipSummary.querySelector('[data-filter-remove="passive"]');
chipSummary.querySelector('[data-filter-remove="bot"]').click(); window.TinyCat.filters.hydrate();
chipsWork = chipsWork && botField.value === '0' && new FormData(chipForm).get('bot') === '0' && !chipSummary.querySelector('[data-filter-remove="bot"]');
window.TinyCat.forms = {dispose() {}}; window.TinyCat.filters.remove(chipForm.id); chipRoot.remove();
const touch = document.createElement('button'), unsafe = '<img src=x onerror=alert(1)> & full text';
touch.setAttribute('title', unsafe);
(document.querySelector('.modal-body') || document.body).append(touch);
let clicks = 0; touch.addEventListener('click', () => clicks++);
touch.dispatchEvent(new PointerEvent('pointerdown', {pointerType:'touch', bubbles:true}));
const hint = document.querySelector('[role="tooltip"]');
const touchSafe = !hint.hidden && hint.textContent === unsafe && !hint.querySelector('img') && !touch.hasAttribute('title') && touch.getAttribute('aria-label') === unsafe && touch.dispatchEvent(new MouseEvent('click', {bubbles:true, cancelable:true})) && clicks === 1;
document.body.dispatchEvent(new PointerEvent('pointerdown', {pointerType:'touch', bubbles:true}));
const outsideCloses = hint.hidden;
let hoverWorks = true;
if (innerWidth === 320 && innerHeight === 360) {
  await new Promise(resolve => setTimeout(resolve, 100));
  touch.dispatchEvent(new PointerEvent('pointerover', {pointerType:'mouse', bubbles:true}));
  const delayed = hint.hidden;
  await new Promise(resolve => setTimeout(resolve, 400));
  const shown = !hint.hidden;
  touch.dispatchEvent(new PointerEvent('pointerout', {pointerType:'mouse', relatedTarget:hint, bubbles:true}));
  hint.dispatchEvent(new PointerEvent('pointerover', {pointerType:'mouse', relatedTarget:touch, bubbles:true}));
  await new Promise(resolve => setTimeout(resolve, 220));
  const hoverable = !hint.hidden;
  hint.dispatchEvent(new PointerEvent('pointerout', {pointerType:'mouse', relatedTarget:document.body, bubbles:true}));
  await new Promise(resolve => setTimeout(resolve, 220));
  hoverWorks = delayed && shown && hoverable && hint.hidden;
  touch.dispatchEvent(new PointerEvent('pointerover', {pointerType:'mouse', bubbles:true}));
  touch.dispatchEvent(new PointerEvent('pointerout', {pointerType:'mouse', relatedTarget:document.body, bubbles:true}));
  await new Promise(resolve => setTimeout(resolve, 400));
  hoverWorks = hoverWorks && hint.hidden;
}
const frame = document.createElement('iframe'); frame.title = 'Embedded content';
window.tooltipService.hydrate(frame);
const frameTitleKept = frame.title === 'Embedded content' && !frame.hasAttribute('data-tooltip');
touch.dispatchEvent(new PointerEvent('pointerdown', {pointerType:'touch', bubbles:true}));
window.tooltipService.dispose(touch);
const disposeCloses = hint.hidden;
const short = document.createElement('span'); short.className = 'table-primary'; short.title = short.textContent = 'Short';
touch.parentElement.append(short); window.tooltipService.hydrate(short);
short.dispatchEvent(new PointerEvent('pointerdown', {pointerType:'touch', bubbles:true}));
const shortHidden = hint.hidden && !short.hasAttribute('tabindex'); short.remove();
touch.remove();
const fallback = document.createElement('div'); fallback.className = 'table-wrap';
const matrix = document.createElement('table'); matrix.className = 'table'; matrix.dataset.tableLayout = 'scroll';
matrix.createTHead().insertRow().append(document.createElement('th'), document.createElement('th'));
matrix.createTBody().insertRow().append(document.createElement('td'), document.createElement('td'));
fallback.append(matrix); document.querySelector('.table-wrap').parentElement.append(fallback);
window.tableService.hydrate(fallback);
fallback.scrollLeft = 64;
const fallbackUsable = !matrix.hasAttribute('data-mobile-cards') && (innerWidth >= 600 || (fallback.scrollWidth > fallback.clientWidth && fallback.scrollLeft > 0));
delete matrix.dataset.tableLayout; matrix.tHead.rows[0].cells[0].colSpan = 2;
window.tableService.hydrate(matrix);
const complexSpansKept = !matrix.hasAttribute('data-mobile-cards');
const empty = document.createElement('table'); empty.className = 'table';
empty.createTHead().insertRow().append(document.createElement('th'), document.createElement('th'));
const emptyCell = empty.createTBody().insertRow().insertCell(); emptyCell.colSpan = 2; emptyCell.textContent = 'No results';
fallback.replaceChildren(empty); window.tableService.hydrate(emptyCell);
const emptyKept = empty.hasAttribute('data-mobile-cards') && !emptyCell.querySelector('.table-cell-label') && emptyCell.textContent === 'No results';
fallback.remove();
const lazy = document.createElement('div'), lazyText = document.createElement('span');
lazy.hidden = true; lazyText.className = 'table-text'; lazyText.style.width = '80px'; lazyText.title = lazyText.textContent = 'Long hidden content '.repeat(30);
lazy.append(lazyText); document.body.append(lazy); window.tooltipService.hydrate(lazy);
const initiallyUnfocusable = !lazyText.hasAttribute('tabindex');
lazy.hidden = false; lazy.dispatchEvent(new CustomEvent('tinycat:modal-open', {bubbles:true}));
let lazyHintsReady = initiallyUnfocusable && lazyText.getAttribute('tabindex') === '0';
lazy.hidden = true; window.tooltipService.hydrate(lazy); lazy.hidden = false;
lazy.dispatchEvent(new CustomEvent('tinycat:tab', {bubbles:true}));
lazyHintsReady = lazyHintsReady && lazyText.getAttribute('tabindex') === '0'; lazy.remove();
results.forEach(result => Object.assign(result, {sortingWorks, sortingDiagnostics, chipsWork, touchSafe, outsideCloses, hoverWorks, frameTitleKept, disposeCloses, shortHidden, hydrationStable, fallbackUsable, complexSpansKept, emptyKept, lazyHintsReady}));
const output = document.createElement('pre'); output.id = 'layout-metrics'; output.textContent = JSON.stringify(results); document.body.append(output);
}());
</script></body></html>
