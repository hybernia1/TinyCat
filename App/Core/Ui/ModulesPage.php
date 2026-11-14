<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

use TinyCat\Core\Modules\Administration;
use TinyCat\Http\{Api, Request, Response, Router};
use TinyCat\Support\{AdminViews, Localization, Markup, View};

/** Always available to administrators, independently of optional feature adapters. */
final class ModulesPage
{
    public static function register(Api $api): void
    {
        $api->get('/modules', Administration::snapshot(...), 'admin');
        $api->post('/modules/activation', static function (Request $request): array {
            $request->validated(['id' => 'required|string|max:190', 'enabled' => 'required|boolean', 'revision' => 'required|string|max:64']);
            try { $result = Administration::change((string) $request->input('id'), filter_var($request->input('enabled'), FILTER_VALIDATE_BOOL), (string) $request->input('revision')); }
            catch (\DomainException $error) { Response::error(Localization::t($error->getMessage()), $error->getCode(), 'module_change_rejected'); }
            catch (\Throwable $error) { error_log('Module activation failed: ' . $error->getMessage()); Response::error(Localization::t('modules.failed'), 409, 'module_change_failed'); }
            return ['active' => $result['active'], 'backup' => $result['backup'] ?? '', 'redirect' => '/admin/modules'];
        }, 'admin');
        $api->get('/modules/packages/creation', static fn (): array => ['limits' => ['bytes' => \TinyCat\Core\Modules\ModulePackage::MAX_BYTES], 'permissions' => ['install' => Administration::snapshot()['manageable']]], 'admin');
        $api->post('/modules/packages', static function (): array {
            $file = $_FILES['package'] ?? null;
            if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) { Response::error(Localization::t('modules.packages.failed'), 422, 'module_package_invalid'); }
            try { $result = \TinyCat\Http\Session::withoutLock(static fn (): array => \TinyCat\Core\Modules\ModulePackage::install($file['tmp_name'])); }
            catch (\Throwable $error) { error_log('Module package installation failed: ' . $error->getMessage()); Response::error(Localization::t('modules.packages.failed'), 422, 'module_package_rejected'); }
            return $result + ['redirect' => '/admin/modules'];
        }, 'admin');
        Router::route('GET', '/admin/modules', self::respond(...));
    }

    public static function respond(): void
    {
        \TinyCat\Core\Identity\Access::require_admin();
        $snapshot = Administration::snapshot();
        $rows = [];
        foreach ($snapshot['items'] as $item) {
            $item['dependencies'] = implode(', ', array_map(Administration::name(...), $item['requires']));
            $item['blocked'] = implode(', ', array_map(Administration::name(...), $item['dependents']));
            $rows[] = $item;
        }
        foreach ($snapshot['groups'] as &$group) { $group['names'] = implode(', ', array_map(Administration::name(...), $group['modules'])); }
        unset($group);
        $content = View::render('modules', ['snapshot' => $snapshot, 'rows' => $rows], __DIR__ . '/templates');
        Page::render(['title' => Localization::t('modules.title'), 'current' => '/admin/modules', 'actions' => $snapshot['manageable'] ? AdminViews::modalAction('modal-modules-packages-creation', Localization::t('modules.packages.upload'), 'plus', '/api/modules/packages/creation') : ''], AdminViews::listCard(Localization::t('modules.title'), 'settings', 'modules-list', '', new Markup($content), primary: true));
    }
}
