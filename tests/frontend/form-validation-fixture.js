(async function () {
  const T = window.TinyCat, checks = [];
  const check = (value, message) => { if (!value) throw Error(message); checks.push(message); };
  const pause = () => new Promise(resolve => setTimeout(resolve, 25));
  async function wait(test, message) { for (let i=0;i<100 && !test();i++) await pause(); check(test(), message); }
  const mode = new URL(location.href).searchParams.get('case');
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
