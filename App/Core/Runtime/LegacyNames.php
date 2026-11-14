<?php
declare(strict_types=1);
namespace TinyCat\Core\Runtime;

/** Published migration names and compatibility entry points; no duplicate implementations. */
final class LegacyNames
{
    public static function canonical(string $class): ?string
    {
        if (str_starts_with($class, 'TinyCat\\Core\\Publishing\\')) {
            $name = substr($class, strlen('TinyCat\\Core\\Publishing\\'));
            $groups = ['LinkParser' => 'Text', 'InlineText' => 'Text', 'PostText' => 'Text', 'Audio' => 'Links', 'Metadata' => 'Links', 'Providers' => 'Links', 'FeedParser' => 'Feeds', 'WordPressFeed' => 'Feeds', 'Rss' => 'Feeds', 'Oembed' => 'Discovery'];
            $relocated = [
                'AccountLifecycle' => 'TinyCat\\Social\\Content\\AccountLifecycle',
                'Archive' => 'TinyCat\\Social\\Content\\Archive',
                'Comments' => 'TinyCat\\Social\\Content\\Comments',
                'DiscussionResource' => 'TinyCat\\Social\\Content\\DiscussionResource',
                'FeedEntry' => 'TinyCat\\Core\\Feeds\\FeedEntry',
                'FeedImport' => 'TinyCat\\Social\\Content\\FeedImport',
                'FeedPipeline' => 'TinyCat\\Core\\Feeds\\FeedPipeline',
                'FeedSourceStore' => 'TinyCat\\Core\\Feeds\\FeedSourceStore',
                'FeedTarget' => 'TinyCat\\Core\\Feeds\\FeedTarget',
                'Images' => 'TinyCat\\Social\\Content\\Images',
                'ImageStorage' => 'TinyCat\\Social\\Content\\ImageStorage',
                'Integration' => 'TinyCat\\Social\\Content\\Integration',
                'InteractionPolicy' => 'TinyCat\\Social\\Content\\InteractionPolicy',
                'Interactions' => 'TinyCat\\Social\\Content\\Interactions',
                'InteractionSchema' => 'TinyCat\\Social\\Content\\InteractionSchema',
                'InteractionTarget' => 'TinyCat\\Core\\Ui\\InteractionTarget',
                'Links' => 'TinyCat\\Social\\Content\\Links',
                'Outbound' => 'TinyCat\\Social\\Content\\Outbound',
                'Policy' => 'TinyCat\\Social\\Content\\Policy',
                'PostCreation' => 'TinyCat\\Social\\Content\\PostCreation',
                'Posts' => 'TinyCat\\Social\\Content\\Posts',
                'Queries' => 'TinyCat\\Social\\Content\\Queries',
                'Reactions' => 'TinyCat\\Social\\Content\\Reactions',
                'ReadCache' => 'TinyCat\\Social\\Content\\ReadCache',
                'Redirects' => 'TinyCat\\Social\\Content\\Redirects',
                'Resource' => 'TinyCat\\Social\\Content\\Resource',
                'Schema' => 'TinyCat\\Social\\Content\\Schema',
                'Segments' => 'TinyCat\\Core\\Text\\Segments',
                'ServiceProvider' => 'TinyCat\\Social\\Content\\ServiceProvider',
                'Settings' => 'TinyCat\\Social\\Content\\Settings',
                'Tags' => 'TinyCat\\Social\\Content\\Tags',
                'TextPolicy' => 'TinyCat\\Core\\Text\\TextPolicy',
            ];
            return isset($groups[$name]) ? 'TinyCat\\Core\\' . $groups[$name] . '\\' . $name : ($relocated[$name] ?? null);
        }
        $special = [
            'TinyCat\\Status\\Comments' => 'TinyCat\\Social\\Content\\Comments',
            'TinyCat\\Social\\Posts\\Comments' => 'TinyCat\\Social\\Content\\Comments',
            'TinyCat\\Status\\Reactions' => 'TinyCat\\Social\\Content\\Reactions',
            'TinyCat\\Social\\Posts\\Reactions' => 'TinyCat\\Social\\Content\\Reactions',
            'TinyCat\\Status\\ReadCache' => 'TinyCat\\Social\\Content\\ReadCache',
            'TinyCat\\Social\\Posts\\ReadCache' => 'TinyCat\\Social\\Content\\ReadCache',
            'TinyCat\\Status\\Segments' => 'TinyCat\\Core\\Text\\Segments',
            'TinyCat\\Status\\Images' => 'TinyCat\\Social\\Content\\Images',

            'TinyCat\\Site\\LocaleRouting' => 'TinyCat\\Http\\LocaleRouting',
            'TinyCat\\Site\\Metadata' => 'TinyCat\\Core\\Ui\\Metadata',
            'TinyCat\\Site\\Errors' => 'TinyCat\\Core\\Runtime\\Errors',
            'TinyCat\\Status\\AccountLifecycle' => 'TinyCat\\Social\\Content\\AccountLifecycle',
            'TinyCat\\Status\\Audio' => 'TinyCat\\Core\\Links\\Audio',
            'TinyCat\\Status\\ImageStorage' => 'TinyCat\\Social\\Content\\ImageStorage',
            'TinyCat\\Status\\InlineText' => 'TinyCat\\Core\\Text\\InlineText',
            'TinyCat\\Status\\LinkParser' => 'TinyCat\\Core\\Text\\LinkParser',
            'TinyCat\\Status\\Links' => 'TinyCat\\Social\\Content\\Links',
            'TinyCat\\Status\\Metadata' => 'TinyCat\\Core\\Links\\Metadata',
            'TinyCat\\Status\\PostText' => 'TinyCat\\Core\\Text\\PostText',
            'TinyCat\\Status\\Providers' => 'TinyCat\\Core\\Links\\Providers',
            'TinyCat\\Status\\Redirects' => 'TinyCat\\Social\\Content\\Redirects',
            'TinyCat\\Users\\Authentication' => 'TinyCat\\Core\\Identity\\Authentication',
            'TinyCat\\Users\\EmailAvailability' => 'TinyCat\\Core\\Identity\\EmailAvailability',
            'TinyCat\\Users\\GeneratedAvatar' => 'TinyCat\\Core\\Identity\\GeneratedAvatar',
            'TinyCat\\Users\\Login' => 'TinyCat\\Core\\Identity\\Login',
            'TinyCat\\Users\\Recovery' => 'TinyCat\\Core\\Identity\\Recovery',

            'TinyCat\\Status\\Schema' => 'TinyCat\\Social\\Content\\Schema',
            'TinyCat\\Notifications\\Delivery' => 'TinyCat\\Social\\Posts\\NotificationEvents',
        ];
        if (isset($special[$class])) { return $special[$class]; }
        if (str_starts_with($class, 'TinyCat\\Support\\Updates\\')) {
            $name = substr($class, 24);
            if ($name === 'Module') { $name = 'ServiceProvider'; }
            return 'TinyCat\\Core\\Updates\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Notifications\\')) {
            $name = substr($class, 22);
            if ($name === 'Module') { $name = 'ServiceProvider'; }
            if ($name === 'Inbox') { return 'TinyCat\\Social\\Posts\\LegacyInbox'; }
            return 'TinyCat\\Core\\Notifications\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Installation\\')) {
            $name = substr($class, 21);
            if ($name === 'Module') { $name = 'ServiceProvider'; }
            return 'TinyCat\\Core\\Installation\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Publishing\\')) {
            $name = substr($class, 19);
            if ($name === 'Module') { $name = 'ServiceProvider'; }
            return 'TinyCat\\Core\\Publishing\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Moderation\\')) {
            $name = substr($class, 19);
            if ($name === 'Module') { $name = 'Registration'; }
            return 'TinyCat\\Social\\Moderation\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Scheduler\\')) {
            $name = substr($class, 18);
            if ($name === 'Module') { $name = 'ServiceProvider'; }
            if ($name === 'Schema') { $name = 'LegacySchema'; }
            return 'TinyCat\\Core\\Tasks\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Accounts\\')) {
            $name = substr($class, 17);
            if ($name === 'Module') { $name = 'ServiceProvider'; }
            if ($name === 'ReadModel') { $name = 'LegacyReadModel'; }
            return 'TinyCat\\Core\\Identity\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Security\\')) {
            $name = substr($class, 17);
            if ($name === 'Module') { $name = 'ServiceProvider'; }
            return 'TinyCat\\Core\\Security\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Settings\\')) {
            $name = substr($class, 17);
            if ($name === 'Module') { $name = 'ServiceProvider'; }
            return 'TinyCat\\Core\\Settings\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Updates\\')) {
            $name = substr($class, 16);
            if ($name === 'Module') { $name = 'ServiceProvider'; }
            return 'TinyCat\\Core\\Updates\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Health\\')) {
            $name = substr($class, 15);
            if ($name === 'Module') { $name = 'ServiceProvider'; }
            return 'TinyCat\\Core\\Health\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Status\\')) {
            $name = substr($class, 15);
            if ($name === 'Module') { $name = 'Registration'; }
            return 'TinyCat\\Social\\Posts\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Search\\')) {
            $name = substr($class, 15);
            if ($name === 'Module') { $name = 'Registration'; }
            return 'TinyCat\\Social\\Search\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Users\\')) {
            $name = substr($class, 14);
            if ($name === 'Module') { $name = 'Registration'; }
            return 'TinyCat\\Social\\Profiles\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Mail\\')) {
            $name = substr($class, 13);
            if ($name === 'Module') { $name = 'ServiceProvider'; }
            return 'TinyCat\\Core\\Mail\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Bots\\')) {
            $name = substr($class, 13);
            if ($name === 'Module') { $name = 'Registration'; }
            return 'TinyCat\\Social\\Automation\\' . $name;
        }
        if (str_starts_with($class, 'TinyCat\\Site\\')) {
            $name = substr($class, 13);
            if ($name === 'Module') { $name = 'Registration'; }
            return 'TinyCat\\Social\\Presentation\\' . $name;
        }
        return null;
    }
}
