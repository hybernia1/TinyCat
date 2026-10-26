<?php
declare(strict_types=1);

use \TinyCat\Http\Api;
use \TinyCat\Http\Request;
use \TinyCat\Http\Response;
use \TinyCat\Http\Result;

$api = new Api(static fn (): ?array => \TinyCat\Users\Authentication::auth());
$api->get('/foundation/items/{id:[0-9]+}', static fn (Request $request, array $params): Result => new Result(
    ['id' => (int) $params['id'], 'value' => $request->input('value', '<b>literal user text</b>')],
    'Loaded.',
    ['source' => 'module']
));
$api->post('/foundation/items', static fn (Request $request): Result => new Result(['id' => 9, 'value' => $request->input('value')], 'Created.'), access: 'admin');
$api->get('/foundation/private', static fn (): array => ['private' => true], access: 'admin');
$api->get('/foundation/bad-resource', static fn (): array => ['items' => [['body_html' => '<b>fragment</b>']]]);
$api->get('/foundation/bad-ui', static fn (): array => ['items' => [['status_label' => 'Open']]]);
$api->get('/foundation/bad-error', static function (): never { Response::error('Invalid.', 422, 'bad', ['captcha_html' => '<form>fragment</form>']); });
$api->post('/foundation/validation', static function (): never { Response::validation(['name' => ['Required.']]); }, access: 'public');

$api->get('/foundation/database-error', static function (): array { \TinyCat\Database\Connection::all('SELECT * FROM deliberately_missing_table'); return []; });
