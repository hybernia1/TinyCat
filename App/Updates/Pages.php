<?php
declare(strict_types=1);
namespace TinyCat\Updates;

use TinyCat\Http\Request;
use TinyCat\Http\Session;
use TinyCat\Http\Web;
use TinyCat\Security\Csrf;
use TinyCat\Site\Layout;
use TinyCat\Support\Localization;
use TinyCat\Support\Version;
use TinyCat\Support\View;
use TinyCat\Users\Access;


/** HTTP authorization, actions and prepared page composition. */
final class Pages
{
    public static function administration(): void
    {
        Access::require_admin();

        if (Request::current()->isPost()) {
            Csrf::requireToken();
            $action = (string) Web::post('action', 'check');

            try {
                if ($action === 'check') {
                    $release = Manager::check(true);
                    Session::flash('updater_release', $release);
                    Session::flash('success', !empty($release['available'])
                        ? Localization::t('updates.messages.available', ['version' => (string) ($release['version'] ?? '')])
                        : Localization::t('updates.messages.current'));
                } elseif ($action === 'install') {
                    $result = Manager::installLatest();
                    Session::flash('updater_result', $result);
                    Session::flash('success', Localization::t('updates.messages.installed', ['version' => (string) ($result['version'] ?? Version::CURRENT)]));
                } elseif ($action === 'disable-maintenance') {
                    Manager::disableMaintenance();
                    Session::flash('success', Localization::t('updates.messages.maintenance_disabled'));
                } else {
                    throw new \RuntimeException(Localization::t('updates.messages.invalid_action'));
                }
            } catch (\Throwable $exception) {
                Session::flash('updater_error', $exception->getMessage());
                Session::flash('error', Localization::t('updates.messages.failed'));
            }

            Web::redirect('/admin/updates');
        }

        $release = Session::flash('updater_release');
        $release = is_array($release) ? $release : Manager::cachedRelease();
        $result = Session::flash('updater_result');
        $result = is_array($result) ? $result : [];
        $error = trim((string) Session::flash('updater_error'));
        $maintenance = Manager::maintenanceState();
        $extensions = [
            'cURL' => extension_loaded('curl'),
            'Sodium' => extension_loaded('sodium'),
            'ZIP / Phar' => class_exists('ZipArchive') || class_exists('PharData'),
        ];
        $releaseCompatible = !is_array($release) || !array_key_exists('compatible', $release) || !empty($release['compatible']);
        $canInstall = !in_array(false, $extensions, true) && $releaseCompatible;
        $history = Manager::migrationHistory();

        Layout::render([
            'title' => Localization::t('updates.title'),
            'current' => '/admin/updates',
        ], View::render('administration', compact('release', 'result', 'error', 'maintenance', 'extensions', 'releaseCompatible', 'canInstall', 'history'), __DIR__ . '/templates'));
    }
}
