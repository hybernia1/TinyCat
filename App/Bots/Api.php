<?php
declare(strict_types=1);
namespace TinyCat\Bots;

use \TinyCat\Http\Request;
use \TinyCat\Http\Result;
use \TinyCat\Http\Response;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {

        $api->get('/bots/accounts', static fn (): Result => Result::from(Actions::adminBotAccounts()), 'admin');
        $api->post('/bots/accounts', static fn (): Result => Result::from(Actions::adminBotAccountCreate()), 'admin');
        $api->get('/bots/accounts/{id:[0-9]+}/management', static function (Request $request, array $params): array {
            $account = Administration::accountById((int) $params['id']);
            if ($account === null) { Response::error(\TinyCat\Support\Localization::t('bots.messages.account_not_found'), 404, 'bot_account_not_found'); }
            return ['item' => Administration::accountResource($account)];
        }, 'admin');
        $api->get('/bots/accounts/{id:[0-9]+}', static function (Request $r, array $p): array {
            $data = \TinyCat\Bots\Administration::accountDetailData((int) $p['id']);
            if ($data === null) { Response::error(\TinyCat\Support\Localization::t('bots.messages.account_not_found'), 404, 'bot_account_not_found'); }
            return ['item' => Administration::accountResource($data['account']), 'sources' => array_map(\TinyCat\Bots\Importer::sourceResource(...), $data['sources']), 'runs' => Resource::runs($data['runs'])];
        }, 'admin');
        $api->patch('/bots/accounts/{id:[0-9]+}', static fn (Request $r, array $p): Result => Result::from(Actions::adminBotAccountUpdate((int) $p['id'])), 'admin');
        $api->delete('/bots/accounts/{id:[0-9]+}', static fn (Request $r, array $p): Result => Result::from(Actions::adminBotAccountDelete((int) $p['id'])), 'admin');
        $api->get('/bots/sources', static fn (): Result => Result::from(Actions::adminBotSources()), 'admin');
        $api->post('/bots/sources', static fn (): Result => Result::from(Actions::adminBotSourceCreate()), 'admin');
        $api->patch('/bots/sources/{id:[0-9]+}', static function (Request $r, array $p): Result {
            $id = (int) $p['id'];
            if ($r->input('feed_url') === null && $r->input('enabled') !== null) {
                $enabled = $r->input('enabled');
                if (!in_array($enabled, [true,false,0,1,'0','1'], true)) { Response::validation(['enabled' => [\TinyCat\Http\Response::issue('common_request_failed', \TinyCat\Support\Localization::t('common.request_failed'))]]); }
                $source = \TinyCat\Bots\Administration::sourceForAction($id, max(0, (int) $r->input('bot_id', 0)));
                if ((bool) $source['enabled'] !== in_array($enabled, [true,1,'1'], true)) { \TinyCat\Bots\Administration::toggleSource($source); }
                return Result::from(\TinyCat\Bots\Administration::payload($id));
            }
            return Result::from(Actions::adminBotSourceUpdate($id));
        }, 'admin');
        $api->delete('/bots/sources/{id:[0-9]+}', static fn (Request $r, array $p): Result => Result::from(Actions::adminBotSourceDelete((int) $p['id'])), 'admin');
        $api->post('/bots/sources/{id:[0-9]+}/runs', static fn (Request $r, array $p): Result => Actions::adminBotSourceAction((int) $p['id']), 'admin');
    }
}
