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
        foreach ($templates as $key => $template) {
            if (!is_array($template)) continue;
            update('email_templates', [
                'subject' => plain_text_limit((string) ($template['subject'] ?? ''), 255),
                'body' => (string) ($template['body'] ?? ''),
                'enabled' => !empty($template['enabled']) ? 1 : 0,
            ], ['template_key' => (string) $key]);
        }
    }
    flash('success', t('settings.messages.saved'));
    redirect('/admin/email-templates');
}

$templates = all('SELECT template_key, subject, body, enabled FROM email_templates ORDER BY template_key ASC');
layout('layout', [
    'title' => t('settings.email_templates_title'),
    'current' => '/admin/email-templates',
], static function () use ($templates): void {
    ?>
    <section class="card">
        <div class="card-header"><h2 class="text-lg m-0 cluster gap-2"><?= icon('mail') ?> <?= et('settings.email_templates_title') ?></h2></div>
        <form method="post" action="/admin/email-templates">
            <?= csrf_field() ?>
            <div class="card-body stack">
                <?php foreach ($templates as $template): $key = (string) $template['template_key']; ?>
                    <fieldset class="card stack">
                        <legend><?= e($key) ?></legend>
                        <label class="check-line"><input type="checkbox" name="email_templates[<?= e($key) ?>][enabled]" value="1"<?= (bool) $template['enabled'] ? ' checked' : '' ?>> <span><?= et('settings.enabled') ?></span></label>
                        <label class="field"><span class="label"><?= et('settings.fields.email_template_subject') ?></span><input class="input" name="email_templates[<?= e($key) ?>][subject]" value="<?= e((string) $template['subject']) ?>" maxlength="255"></label>
                        <label class="field"><span class="label"><?= et('settings.fields.email_template_body') ?></span><textarea class="textarea" name="email_templates[<?= e($key) ?>][body]" rows="6"><?= e((string) $template['body']) ?></textarea></label>
                    </fieldset>
                <?php endforeach; ?>
            </div>
            <div class="card-footer cluster justify-end"><button class="btn btn-primary" type="submit"><?= icon('save') ?> <span><?= et('common.save') ?></span></button></div>
        </form>
    </section>
    <?php
});
