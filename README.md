# `giveitsmaller/sdk` — PHP SDK for GISL

The official PHP SDK for **Give It Smaller** (GISL) — a file compression and media-processing service. Compress, convert, thumbnail, and merge images, video, audio, and documents from PHP 8.1+.

> **Read-only mirror.** This repository is automatically published from Give It Smaller's private monorepo on each release. Do **not** open issues or pull requests here — they are not monitored. Licensed under [Apache-2.0](./LICENSE).

📖 Full documentation: [giveitsmaller.com](https://giveitsmaller.com)

## Install

The SDK code-targets PSR-18 / PSR-17 and resolves a concrete HTTP client at runtime via `php-http/discovery`. Install the SDK plus any PSR-18 implementation — Guzzle is the path of least resistance:

```bash
composer require giveitsmaller/sdk guzzlehttp/guzzle http-interop/http-factory-guzzle
```

Requires **PHP ^8.1**.

> **Bring your own PSR-18 client.** The SDK's runtime imports only PSR-18 interfaces, so you can use any implementation (e.g. Symfony HttpClient) instead of Guzzle — inject it via `Gisl::create(httpClient: ...)`. Published on [Packagist](https://packagist.org/packages/giveitsmaller/sdk) — the `composer require` above is all you need (no `repositories` block or auth token).

## Quickstart

The SDK is **file-first**: you always start from a file (`->file($path)` for one,
`->files([$paths])` for many) and call operations *on* it.

```php
<?php
require 'vendor/autoload.php';

use Gisl\Sdk\Gisl;
use Gisl\Sdk\Generated\SdkSpec\Enums\OptimizeFor;

$client = Gisl::create(apiKey: 'sk_...'); // or rely on GISL_API_KEY / ~/.gisl/credentials

$result = $client
    ->file('./photo.jpg')
    ->compress(optimize: OptimizeFor::Balanced)
    ->run(maxWait: '5m');

echo $result->url; // pre-signed download URL
```

> **Operation-first also ships.** A lower-level `$client->compress($path, [...])->run(new RunOptions(...))`
> form (and `thumbnail` / `convert`) is available as an escape hatch, but file-first is the
> recommended direction.

## Documentation

Full documentation — getting started, the client reference and ergonomic builders, operation
options, progress events (SSE), webhooks, error/retry guidance, and per-operation examples — lives
at [giveitsmaller.com](https://giveitsmaller.com).

## License

Apache-2.0 — see the [LICENSE](./LICENSE) file.
