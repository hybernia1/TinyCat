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
    'debug' => true,
  ),
  'site' => 
  array (
    'name' => 'TinyCat',
    'logo_media_id' => 0,
    'favicon_media_id' => 0,
    'footer_html' => '',
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
    'remember_days' => 30,
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
    'trusted_proxies' => 
    array (
    ),
    'rate_limit' => 
    array (
    ),
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
  'upload' => 
  array (
    'url' => '/uploads',
    'subfolder' => 'Y/m',
    'extensions' => 
    array (
      0 => 'jpg',
      1 => 'jpeg',
      2 => 'png',
      3 => 'gif',
      4 => 'webp',
      5 => 'svg',
      6 => 'ico',
      7 => 'pdf',
      8 => 'txt',
      9 => 'csv',
      10 => 'doc',
      11 => 'docx',
      12 => 'xls',
      13 => 'xlsx',
      14 => 'zip',
    ),
    'mime_types' => 
    array (
      0 => 'image/jpeg',
      1 => 'image/png',
      2 => 'image/gif',
      3 => 'image/webp',
      4 => 'image/svg+xml',
      5 => 'image/x-icon',
      6 => 'image/vnd.microsoft.icon',
      7 => 'application/pdf',
      8 => 'text/plain',
      9 => 'text/csv',
      10 => 'application/msword',
      11 => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      12 => 'application/vnd.ms-excel',
      13 => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      14 => 'application/zip',
    ),
    'overwrite' => false,
    'profiles' => 
    array (
      'image' => 
      array (
        'extensions' => 
        array (
          0 => 'jpg',
          1 => 'jpeg',
          2 => 'png',
          3 => 'gif',
          4 => 'webp',
          5 => 'svg',
          6 => 'ico',
        ),
        'mime_types' => 
        array (
          0 => 'image/jpeg',
          1 => 'image/png',
          2 => 'image/gif',
          3 => 'image/webp',
          4 => 'image/svg+xml',
          5 => 'image/x-icon',
          6 => 'image/vnd.microsoft.icon',
        ),
      ),
      'document' => 
      array (
        'extensions' => 
        array (
          0 => 'pdf',
          1 => 'txt',
          2 => 'csv',
          3 => 'doc',
          4 => 'docx',
          5 => 'xls',
          6 => 'xlsx',
        ),
        'mime_types' => 
        array (
          0 => 'application/pdf',
          1 => 'text/plain',
          2 => 'text/csv',
          3 => 'application/msword',
          4 => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
          5 => 'application/vnd.ms-excel',
          6 => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ),
      ),
    ),
  ),
  'install' => 
  array (
    'installed' => false,
    'installed_at' => NULL,
    'locale' => 'cs',
    'version' => '1.0.0',
  ),
);

$config['i18n']['directory'] = $path('lang');
$config['assets']['directory'] = $path('assets');
$config['upload']['directory'] = $path('uploads');
$config['security']['rate_limit']['storage'] = $path('storage/rate-limit');
return $config;
