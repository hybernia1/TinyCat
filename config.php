<?php
declare(strict_types=1);

// App config file. Paths are derived from this file so the project stays portable.

$base = __DIR__;
$path = static function (string $path = '') use ($base): string {
    $path = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

    return rtrim($base, DIRECTORY_SEPARATOR) . ($path === '' ? '' : DIRECTORY_SEPARATOR . $path);
};

$config = array (
  'app' => 
  array (
    'name' => 'TinyCat',
    'version' => '1.0.0',
    'debug' => false,
  ),
  'site' => 
  array (
    'name' => 'TinyCat',
    'logo_url' => '',
    'logo_path' => '',
    'favicon_url' => '',
    'favicon_path' => '',
    'footer_html' => '',
    'image_url' => '/uploads/site',
    'image_subfolder' => 'Y/m',
    'image_quality' => 86,
    'image_max_size' => 67108864,
  ),
  'avatar' => 
  array (
    'url' => '/uploads/avatars',
    'subfolder' => 'Y/m',
    'size' => 200,
    'quality' => 86,
    'max_size' => 67108864,
  ),
  'database' => 
  array (
    'driver' => 'mysql',
    'host' => 'localhost',
    'name' => 'micro',
    'user' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
  ),
  'i18n' => 
  array (
  ),
  'auth' => 
  array (
    'login_url' => '/login',
    'home_url' => '/admin',
    'account_url' => '/account',
    'remember_days' => 30,
    'online_window' => 300,
    'online_touch_interval' => 60,
    'registration' => 
    array (
      'enabled' => false,
      'auto_approve' => false,
    ),
  ),
  'admin' => 
  array (
    'per_page' => 25,
    'per_page_options' => 
    array (
      0 => 10,
      1 => 25,
      2 => 50,
      3 => 100,
    ),
  ),
  'security' => 
  array (
    'captcha' => 
    array (
      'field' => 'tc_captcha',
      'tolerance' => 4,
      'ttl' => 600,
    ),
  ),
  'assets' => 
  array (
    'url' => '/assets',
    'icons' => 'icons.svg',
    'version' => true,
  ),
  'install' => 
  array (
    'installed' => true,
    'installed_at' => '2026-07-06T15:22:12+00:00',
    'locale' => 'en',
    'version' => '1.0.0',
  ),
);

$config['i18n']['directory'] = $path('lang');
$config['assets']['directory'] = $path('assets');
$config['avatar']['directory'] = $path('uploads/avatars');
$config['site']['image_directory'] = $path('uploads/site');
return $config;
