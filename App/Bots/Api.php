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
        $api->get('/bots/queue', static fn (): Result => Queue::listing(), 'admin');
        $api->post('/bots/queue/{id:[0-9]+}/publication', static fn (Request $r, array $p): Result => Queue::publish((int) $p['id']), 'admin');

        $api->get('/bots/accounts', static fn (): Result => Result::from(Actions::adminBotAccounts()), 'admin');
        $api->post('/bots/accounts', static fn (): Result => Result::from(Actions::adminBotAccountCreate()), 'admin');
        $api->get('/bots/accounts/{id:[0-9]+}/runs', static function (Request $r, array $p): Result {
            $id = (int) $p['id'];
            if (Administration::accountById($id) === null) { Response::error(\TinyCat\Support\Localization::t('bots.messages.account_not_found'), 404, 'bot_account_not_found'); }
            return Result::from(Administration::runsPayload($id));
        }, 'admin');
        $api->put('/bots/accounts/{id:[0-9]+}/activation', static function (Request $r, array $p): Result {
            $account = Administration::accountById((int) $p['id']);
            if ($account === null) { Response::error(\TinyCat\Support\Localization::t('bots.messages.account_not_found'), 404, 'bot_account_not_found'); }
            $data = $r->validated(['enabled' => 'required|boolean']);
            $active = in_array($data['enabled'], [true, 1, '1'], true);
            \TinyCat\Users\Accounts::setActive($account, $active);
            return Result::from(Administration::accountsPayload((int) $p['id']) + \TinyCat\Support\AdminViews::activationNotice($active, '@' . $account['username']));
        }, 'admin');
        $api->get('/bots/accounts/{id:[0-9]+}/posts', static function (Request $request, array $params): array {
            $id = (int) $params['id'];
            if (Administration::accountById($id) === null) { Response::error(\TinyCat\Support\Localization::t('bots.messages.account_not_found'), 404, 'bot_account_not_found'); }
            return Administration::postsPayload($id);
        }, 'admin');
        $api->get('/bots/accounts/{id:[0-9]+}/posts/{postId:[0-9]+}/management', static function (Request $request, array $params): array {
            $id = (int) $params['id'];
            if (Administration::accountById($id) === null) { Response::error(\TinyCat\Support\Localization::t('bots.messages.account_not_found'), 404, 'bot_account_not_found'); }
            return Administration::postManagement($id, (int) $params['postId']);
        }, 'admin');
        $api->get('/bots/accounts/{id:[0-9]+}', static function (Request $r, array $p): array {
            $account = Administration::accountById((int) $p['id']);
            if ($account === null) { Response::error(\TinyCat\Support\Localization::t('bots.messages.account_not_found'), 404, 'bot_account_not_found'); }
            return ['item' => Administration::accountResource($account)];
        }, 'admin');
        $api->patch('/bots/accounts/{id:[0-9]+}', static fn (Request $r, array $p): Result => Result::from(Actions::adminBotAccountUpdate((int) $p['id'])), 'admin');
        $api->delete('/bots/accounts/{id:[0-9]+}', static fn (Request $r, array $p): Result => Result::from(Actions::adminBotAccountDelete((int) $p['id'])), 'admin');
        $api->get('/bots/sources', static fn (): Result => Result::from(Actions::adminBotSources()), 'admin');
        $api->get('/bots/sources/management', static function (): array {
            $data = Administration::sourceFormData(null);
            return ['item' => $data['source'] + ['post_template' => Importer::defaultSourceTemplate(), 'enabled' => true], 'bots' => $data['bots']];
        }, 'admin');
        $api->get('/bots/sources/{id:[0-9]+}/management', static function (Request $request, array $params): array {
            $source = Importer::findSource((int) $params['id']);
            if ($source === null) { Response::error(\TinyCat\Support\Localization::t('bots.messages.not_found'), 404, 'bot_source_not_found'); }
            $data = Administration::sourceFormData($source);
            return ['item' => Importer::sourceResource($source), 'bots' => $data['bots']];
        }, 'admin');
        $api->post('/bots/sources/previews', static fn (): array => SourcePreview::payload(), 'admin');
        $api->post('/bots/sources', static fn (): Result => Result::from(Actions::adminBotSourceCreate()), 'admin');
        $api->patch('/bots/sources/{id:[0-9]+}', static function (Request $r, array $p): Result {
            $id = (int) $p['id'];
            if ($r->input('feed_url') === null && $r->input('enabled') !== null) {
                $enabled = $r->input('enabled');
                if (!in_array($enabled, [true,false,0,1,'0','1'], true)) { Response::validation(['enabled' => [\TinyCat\Http\Response::issue('common_request_failed', \TinyCat\Support\Localization::t('common.request_failed'))]]); }
                $source = \TinyCat\Bots\Administration::sourceForAction($id, max(0, (int) $r->input('bot_id', 0)));
                if ((bool) $source['enabled'] !== in_array($enabled, [true,1,'1'], true)) { \TinyCat\Bots\Administration::toggleSource($source); }
                return Result::from(\TinyCat\Bots\Administration::payload($id) + \TinyCat\Support\AdminViews::activationNotice(in_array($enabled, [true, 1, '1'], true), (string) $source['name']));
            }
            return Result::from(Actions::adminBotSourceUpdate($id));
        }, 'admin');
        $api->delete('/bots/sources/{id:[0-9]+}', static fn (Request $r, array $p): Result => Result::from(Actions::adminBotSourceDelete((int) $p['id'])), 'admin');
        $api->post('/bots/sources/{id:[0-9]+}/runs', static fn (Request $r, array $p): Result => Actions::adminBotSourceAction((int) $p['id']), 'admin');
    }
}
