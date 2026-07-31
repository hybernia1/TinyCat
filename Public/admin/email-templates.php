<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();
if (is_post()) {
    csrf_require();
    $templates = post('email_templates', []);
    if (is_array($templates)) {
        foreach (email_template_keys() as $key) {
            $template = is_array($templates[$key] ?? null) ? $templates[$key] : [];
            $enabled = !empty($template['enabled']) ? 1 : 0;
            if (one('SELECT id FROM email_templates WHERE template_key = ? LIMIT 1', [$key]) === null) {
                insert('email_templates', ['template_key' => $key, 'enabled' => $enabled]);
            } else {
                update('email_templates', ['enabled' => $enabled], ['template_key' => $key]);
            }
        }
    }
    flash('success', t('settings.messages.saved'));
    redirect('/admin/email-templates');
}

$states = all('SELECT template_key, enabled FROM email_templates');
$enabled = [];
foreach ($states as $state) {
    $enabled[(string) ($state['template_key'] ?? '')] = (bool) ($state['enabled'] ?? false);
}
$catalog = email_catalog(locale());
$templates = (array) ($catalog['templates'] ?? []);
layout('layout', [
    'title' => t('settings.email_templates_title'),
    'current' => '/admin/email-templates',
], static function () use ($templates, $enabled): void {
    ?>
    <section class="card">
        <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= icon('mail') ?> <?= et('settings.email_templates_title') ?></h2></div>
        <form method="post" action="/admin/email-templates">
            <?= csrf_field() ?>
            <div class="card-body stack">
                <p class="text-muted mb-0"><?= et('settings.email_templates_localized') ?></p>
                <?php foreach ($templates as $key => $template): $key = (string) $key; ?>
                    <fieldset class="card stack">
                        <legend><?= e($key) ?></legend>
                        <label class="check-line"><input type="checkbox" name="email_templates[<?= e($key) ?>][enabled]" value="1"<?= !empty($enabled[$key]) ? ' checked' : '' ?>> <span><?= et('settings.enabled') ?></span></label>
                        <div class="field"><span class="label"><?= et('settings.fields.email_template_subject') ?></span><div class="code-block"><?= e((string) ($template['subject'] ?? '')) ?></div></div>
                        <div class="field"><span class="label"><?= et('settings.fields.email_template_body') ?></span><pre class="code-block"><?= e((string) ($template['body'] ?? '')) ?></pre></div>
                    </fieldset>
                <?php endforeach; ?>
            </div>
            <div class="card-footer cluster justify-end"><button class="btn btn-primary" type="submit"><?= icon('save') ?> <span><?= et('common.save') ?></span></button></div>
        </form>
    </section>
    <?php
});
