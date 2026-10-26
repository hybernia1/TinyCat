<?php
declare(strict_types=1);

use TinyCat\Support\Components;
use TinyCat\Support\Html;
use TinyCat\Support\Localization;
use TinyCat\Support\Markup;

if (!defined('TINYCAT')) { http_response_code(403); exit('Forbidden'); }
$id = (string) ($id ?? '');
if ($id === '') { http_response_code(404); return; }
$action = (string) ($action ?? '');
$hasForm = $action !== '';
$method = strtoupper((string) ($method ?? 'POST'));
$nativeMethod = in_array($method, ['GET', 'POST'], true);
$formMethod = $nativeMethod ? strtolower($method) : 'post';
$hiddenMethod = (string) ($methodOverride ?? ($nativeMethod ? '' : $method));
$titleId = (string) ($labelledBy ?? $id . '-title');
$panel = ['class' => trim('modal-panel ' . (string) ($size ?? '')), 'tabindex' => '-1'];
if ($hasForm) {
    $panel += ['action' => $action, 'method' => $formMethod,
        'enctype' => !empty($multipart) ? 'multipart/form-data' : null,
        'data-ajax-form' => ($ajax ?? true) ? '' : null,
        'data-ajax-target' => ($target ?? '') !== '' ? $target : null,
        'data-reset' => !empty($reset) ? 'true' : null,
        'data-modal-close-on-success' => !empty($closeOnSuccess) ? 'true' : null];
}
$panel += (array) ($hasForm ? ($formAttributes ?? []) : ($panelAttributes ?? []));
echo Components::render('ui/modal', [
    'root_attributes' => ['class' => trim('modal ' . (string) ($modalClass ?? '') . ($hasForm ? ' modal-form' : '')),
        'id' => $id, 'role' => 'dialog', 'aria-modal' => 'true', 'aria-hidden' => 'true',
        'aria-labelledby' => $titleId, 'data-open' => 'false', 'data-modal-dispose-on-close' => 'false'],
    'form' => $hasForm, 'panel_attributes' => $panel, 'title_id' => $titleId,
    'title' => (string) ($title ?? ''), 'icon' => (string) ($icon ?? ''),
    'close_label' => Localization::t('common.close'), 'close_icon' => new Markup(Html::icon('close')),
    'body_class' => trim('modal-body ' . (string) ($bodyClass ?? 'stack')), 'body' => new Markup((string) ($body ?? '')),
    'has_footer' => (string) ($footer ?? '') !== '', 'footer' => new Markup((string) ($footer ?? '')),
    'csrf' => $hasForm && (bool) ($csrf ?? ($formMethod !== 'get')), 'hidden_method' => $hasForm ? $hiddenMethod : '',
]);
