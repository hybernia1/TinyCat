# Runtime diagnostics

Set `app.debug` in the private `config.php` (the source repository also provides
`config.example.php`; the private configuration is never replaced by updates):

```php
'app' => ['debug' => 'log'],
```

- `'on'` displays PHP diagnostics and exception details. HTTP API exceptions
  retain the standard JSON envelope. Enable only while debugging.
- `'off'` displays no technical diagnostics and writes no debug log. Normal
  HTTP error responses, command failures and scheduler history still exist.
- `'log'` hides technical diagnostics and appends records to `storage/debug.log`.

The default is `'off'`; legacy boolean `true`/`false` mean `'on'`/`'off'`.
Unknown values fail closed to `'off'`. Quote the strings in PHP configuration.
The policy is installed when core loads configuration, before modules start,
and applies to HTTP, CLI and scheduler execution. The independent `feeds/`
application has its own configuration and is outside this runtime.

The log uses JSON Lines: one UTF-8 JSON object per line, with `time` (UTC
RFC 3339), `level`, `message`, `file`, `line`, and nullable `exception` class.
Concurrent appends use an exclusive file lock. Embedded newlines are JSON
escaped. PHP warnings, notices, deprecations, fatal errors and reported caught
exceptions use the same format. Explicitly suppressed PHP errors (`@`) remain
suppressed. An exception reported at multiple boundaries is recorded once.
Records do not collect request bodies, cookies, headers, command arguments or
stack arguments. Exception messages can still contain sensitive application
data; keep this private file protected and manage its retention on the host.

Core owns the handlers; modules do not register debug modes. Existing technical
catch boundaries call `TinyCat\Core\Runtime\Debug::report($exception)` when
recovering from an unexpected failure. Expected validation and business errors
need no diagnostic record. Exceptions deliberately consumed without reporting
cannot be observed by PHP's global exception handler.

Classification, safe public failures and recovery boundaries follow the
[error handling contract](../contracts/error-handling.md). An embedding host's
existing exception handler is preserved; CLI tests install their diagnostic
handler before application startup so debug off cannot hide assertion failures.

If storage is unwritable, logging fails quietly without breaking the original
response. PHP startup errors and failures before configuration can be read
remain the hosting runtime's responsibility; configure production PHP with
`display_errors=Off`. This facility does not capture browser JavaScript errors.
