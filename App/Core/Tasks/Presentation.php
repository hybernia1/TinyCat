<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;

/** Declarative UI metadata comes exclusively from selected task registrations. */
final class Presentation
{
    /** @return array<string, array<string, mixed>> */
    public static function tasks(): array
    {
        $entries = [];
        foreach (\TinyCat\Core\Modules\Registrations::current()->entries->tasks as $task) {
            $admin = $task->definition()['admin'];
            $icon = (string) $admin['icon'];
            \TinyCat\Support\Html::assertIconName($icon);
            $title = $task->admin['title'] ?? '';
            $schedule = $task->admin['cadence'] ?? $task->admin['schedule'] ?? '';
            $entries[$task->id] = ['icon' => $icon, 'title' => $title === '' ? $task->id : ['t' => $title], 'schedule' => $schedule === '' ? '' : ['t' => $schedule]];
        }
        return $entries;
    }

    /** @return array<string, array<string, mixed>> */
    public static function options(): array
    {
        $entries = [];
        foreach (\TinyCat\Core\Modules\Registrations::current()->entries->tasks as $task) {
            foreach ($task->optionLabels as $name => $label) { $entries[$task->id . ':' . $name] = ['label' => ['t' => $label]]; }
        }
        return $entries;
    }
}
