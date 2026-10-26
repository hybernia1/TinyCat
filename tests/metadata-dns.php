<?php
declare(strict_types=1);

namespace TinyCat\Status {
    // Namespace-local doubles keep production URL guards and redirect handling
    // active while avoiding DNS and external HTTP in this fixture.
    function gethostbynamel(string $host): array|false
    {
        $GLOBALS['dnsCalls'][$host] = ($GLOBALS['dnsCalls'][$host] ?? 0) + 1;
        return $GLOBALS['dnsAnswers'][$host] ?? false;
    }
    function dns_get_record(string $host, int $type): array|false
    {
        $GLOBALS['dns6Calls'][$host] = ($GLOBALS['dns6Calls'][$host] ?? 0) + 1;
        return $GLOBALS['dns6Answers'][$host] ?? [];
    }
    function fopen(string $url, string $mode, bool $includePath, mixed $context): mixed
    {
        $GLOBALS['httpCalls'][] = $url;
        $headers = $GLOBALS['responses'][$url] ?? ['HTTP/1.1 404 Not Found'];
        $stream = \fopen('php://temp', 'w+');
        $GLOBALS['headers'][(int) $stream] = $headers;
        return $stream;
    }
    function stream_get_meta_data(mixed $stream): array
    {
        return ['wrapper_data' => $GLOBALS['headers'][(int) $stream]];
    }
}
namespace {
    define('TINYCAT', true);
    require_once dirname(__DIR__) . '/App/autoload.php';
    $checks = 0;
    $assert = static function (bool $condition, string $message) use (&$checks): void {
        $checks++;
        if (!$condition) { throw new RuntimeException($message); }
    };
    $GLOBALS['dnsCalls'] = $GLOBALS['dns6Calls'] = $GLOBALS['httpCalls'] = [];
    $GLOBALS['dnsAnswers'] = ['public.example' => ['93.184.216.34'], 'private.example' => ['10.0.0.1']];
    $GLOBALS['dns6Answers'] = [];
    $GLOBALS['responses'] = [
        'https://public.example/start' => ['HTTP/1.1 302 Found', 'Location: /next'],
        'https://public.example/next' => ['HTTP/1.1 302 Found', 'Location: https://private.example/secret'],
    ];
    \TinyCat\Status\Metadata::fetch('https://public.example/start');
    $assert($GLOBALS['dnsCalls']['public.example'] === 1 && $GLOBALS['dns6Calls']['public.example'] === 1, 'Repeated host checks within one redirect chain must resolve each DNS family once.');
    $assert($GLOBALS['httpCalls'] === ['https://public.example/start', 'https://public.example/next'], 'A redirect to a private host must be rejected before transport.');
    $assert($GLOBALS['dnsCalls']['private.example'] === 1, 'A new redirect host requires its own DNS validation.');

    // A new operation must see a changed address rather than reuse approval.
    $GLOBALS['dnsAnswers']['public.example'] = ['127.0.0.1'];
    \TinyCat\Status\Metadata::fetch('https://public.example/start');
    $assert($GLOBALS['dnsCalls']['public.example'] === 2 && count($GLOBALS['httpCalls']) === 2, 'A later operation re-resolves DNS and rejects an address that became private.');
    $GLOBALS['dnsAnswers']['mixed.example'] = ['93.184.216.34'];
    $GLOBALS['dns6Answers']['mixed.example'] = [['ipv6' => '::1']];
    $assert(!\TinyCat\Status\Metadata::isSafeRemoteUrl('https://mixed.example'), 'A public A record cannot hide a private AAAA record.');
    $assert(!\TinyCat\Status\Metadata::isSafeRemoteUrl('https://missing.example'), 'Missing DNS records remain blocked.');

    $scope = new ReflectionMethod(\TinyCat\Status\Metadata::class, 'withHostChecks');
    try {
        $scope->invoke(null, static function (): void {
            \TinyCat\Status\Metadata::isSafeRemoteUrl('https://private.example');
            throw new RuntimeException('forced failure');
        });
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'forced failure', 'An operation failure must propagate.');
    }
    $GLOBALS['dnsAnswers']['private.example'] = ['93.184.216.34'];
    $assert(\TinyCat\Status\Metadata::isSafeRemoteUrl('https://private.example'), 'An exception must clear scoped DNS decisions.');
    $assert((new ReflectionProperty(\TinyCat\Status\Metadata::class, 'hostChecks'))->getValue() === null, 'DNS decisions do not leak beyond their operation.');
    echo "PASS scoped DNS checks and redirect guards ({$checks} checks)\n";
}
