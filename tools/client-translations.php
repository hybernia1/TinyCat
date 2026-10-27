<?php
declare(strict_types=1);

/** Check dependencies; source scanning validates declarations but never selects messages. */
return static function (array $catalogs): void {
    foreach (\TinyCat\Support\ClientAssets::manifests() as $file) {
        $features = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        foreach ($features as $feature) {
            if (array_key_exists('translation_prefixes', $feature)) {
                throw new RuntimeException($file . ': translation_prefixes is no longer supported');
            }
            $keys = $feature['translations'] ?? null;
            if (!is_array($keys) || !array_is_list($keys)) { throw new RuntimeException($file . ': declare a translations list'); }
            $seen = [];
            $declaredCount = count($keys);
            foreach ($feature['messages'] ?? [] as $code => $key) {
                if (!is_string($code) || !preg_match('/^[a-z][a-z0-9_]*$/D', $code)) { throw new RuntimeException($file . ': invalid API message code'); }
                $keys[] = $key;
            }
            foreach ($keys as $index => $key) {
                if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/D', $key)) {
                    throw new RuntimeException($file . ': invalid translation dependency');
                }
                if (isset($seen[$key]) && $index < $declaredCount) { throw new RuntimeException($file . ': duplicate dependency ' . $key); }
                $seen[$key] = true;
                foreach ($catalogs as $locale => $catalog) {
                    if (!array_key_exists($key, $catalog['app'])) { throw new RuntimeException($file . ': missing ' . $locale . ' translation ' . $key); }
                }
            }
        }
    }
    foreach (\TinyCat\Support\ClientAssets::scopes() as $scope) {
        \TinyCat\Support\ClientAssets::messages($scope);
        $sources = \TinyCat\Support\AssetGraph::order(\TinyCat\Support\ClientAssets::entries($scope));
        foreach ($catalogs as $locale => $catalog) {
            $selected = \TinyCat\Support\ClientAssets::catalog($scope, $locale, $catalog['app']);
            if (in_array('assets/core/dates.js', $sources, true)) {
                $dateKeys = ['relative.now'];
                foreach (['past', 'future'] as $direction) {
                    foreach (['minute', 'hour', 'day', 'week', 'month', 'year'] as $unit) {
                        foreach (['single', 'one', 'few', 'many', 'other'] as $form) { $dateKeys[] = "relative.{$direction}.{$unit}.{$form}"; }
                    }
                }
                foreach ($dateKeys as $key) {
                    if (!array_key_exists($key, $selected)) { throw new RuntimeException("Missing date translation {$locale} {$key} in {$scope}"); }
                }
            }
            foreach ($sources as $source) {
                preg_match_all('/\b(?:U|T\.ui|TinyCat\.ui)\.(?:text|button|field|select)\(\s*["\']([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)["\']/i', \TinyCat\Support\AssetGraph::read($source), $matches);
                preg_match_all('/\b(?:U|T\.ui|TinyCat\.ui)\.dialog\(\s*[^,\r\n]+,\s*["\']([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)["\']/i', \TinyCat\Support\AssetGraph::read($source), $dialogTitles);
                foreach (array_merge($matches[1], $dialogTitles[1]) as $key) {
                    if (!array_key_exists($key, $selected)) { throw new RuntimeException($source . ': undeclared ' . $locale . ' translation ' . $key . ' in ' . $scope); }
                }
                preg_match_all('/\b(?:T|TinyCat)\.i18n\.plural\(\s*["\']([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)["\']/i', \TinyCat\Support\AssetGraph::read($source), $plurals);
                foreach ($plurals[1] as $key) {
                    foreach (['one', 'few', 'many', 'other'] as $form) {
                        if (!array_key_exists($key . '.' . $form, $selected)) { throw new RuntimeException($source . ': undeclared plural ' . $key . '.' . $form . ' in ' . $scope); }
                    }
                }
            }
        }
    }
};
