<?php
declare(strict_types=1);

use TinyCat\Core\Modules\{Contributions, Registrations, Registries, SettingsSection};

$core = Registrations::current()->entries;
$section = new SettingsSection('fixture.settings', 'fixture', static fn (): array => [
    'label' => 'Fixture', 'icon' => 'settings', 'fields' => [
        ['key' => 'fixture.images.enabled', 'label' => 'Images', 'type' => 'bool', 'default' => true],
        ['key' => 'fixture.images.max_upload_kb', 'label' => 'Image limit', 'type' => 'int', 'default' => 100, 'min' => 20, 'max' => 1024],
        ['key' => 'fixture.blocked_urls', 'label' => 'Blocked URLs', 'type' => 'textarea', 'default' => ''],
        ['key' => 'fixture.links.utm.enabled', 'label' => 'Tracking', 'type' => 'bool', 'default' => true],
        ['key' => 'fixture.links.utm.source', 'label' => 'Source', 'type' => 'optional_text', 'default' => ''],
        ['key' => 'fixture.links.utm.medium', 'label' => 'Medium', 'type' => 'text', 'default' => 'referral'],
        ['key' => 'fixture.links.utm.campaign', 'label' => 'Campaign', 'type' => 'optional_text', 'default' => ''],
    ],
]);
Registrations::configure(new Registries(['core' => $core, 'fixture.settings' => new Contributions(settings: [$section])]));
