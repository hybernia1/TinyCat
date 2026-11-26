<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui\Admin;

use TinyCat\Core\Modules\Registrations;
use TinyCat\Core\Ui\Components;
use TinyCat\Support\Localization;
use TinyCat\Core\Ui\Markup;

/** The host owns layout and widget types; owners supply authorized data. */
final class Dashboard
{
    /** @return list<array<string, mixed>> */
    public static function widgets(): array
    {
        $result = [];
        foreach (Registrations::current()->entries->widgets as $widget) {
            try { if ($widget->visible !== null && !($widget->visible)()) { continue; } }
            catch (\Throwable $failure) { \TinyCat\Core\Runtime\Failure::report($failure); continue; }
            $view = ['id' => $widget->id, 'type' => $widget->type, 'title' => $widget->id, 'icon' => $widget->icon];
            try {
                if ($widget->title !== null) { $view['title'] = ($widget->title)(); }
                $data = ($widget->render)();
                if ($widget->type === 'custom') {
                    if (!is_string($data)) { throw new \LogicException('Custom widget must render a string.'); }
                    $view['content'] = new Markup($data);
                } else {
                    if (!is_array($data)) { throw new \LogicException('Typed widget must return data.'); }
                    if (isset($data['items'])) {
                        if (!is_array($data['items'])) { throw new \LogicException('Widget items must be an array.'); }
                        $data['items'] = array_slice(array_values($data['items']), 0, 8);
                    }
                    $view['data'] = $data;
                }
            } catch (\Throwable $error) {
                \TinyCat\Core\Runtime\Failure::report($error);
                $view['type'] = 'status'; $view['data'] = ['text' => Localization::t('common.request_failed'), 'tone' => 'danger'];
            }
            $result[] = $view;
        }
        return $result;
    }

    public static function respond(): void
    {
        \TinyCat\Core\Identity\Access::require_admin();
        \TinyCat\Http\Request::current()->validated(['stats' => 'string|max:80']);
        \TinyCat\Core\Ui\Page::render(['title' => Localization::t('common.dashboard'), 'current' => '/admin'], Components::render('ui/dashboard', ['widgets' => self::widgets()]));
    }
}
