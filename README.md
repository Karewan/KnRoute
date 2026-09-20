# KnRoute

Simple and fast PHP 8.3+ router with route attributes and caching.

## Table of contents

- [KnRoute](#knroute)
	- [Table of contents](#table-of-contents)
	- [Installation](#installation)
		- [Requirements](#requirements)
		- [Getting started](#getting-started)
	- [Usage](#usage)
		- [Register routes from the controllers and run the router](#register-routes-from-the-controllers-and-run-the-router)
		- [Different types of routes](#different-types-of-routes)
		- [Add route attributes to controller methods](#add-route-attributes-to-controller-methods)
		- [Controller discovery rules](#controller-discovery-rules)
		- [Route matching and HTTP semantics](#route-matching-and-http-semantics)
		- [Use variables inside a path](#use-variables-inside-a-path)
		- [Variable types](#variable-types)
		- [Create a middleware](#create-a-middleware)
		- [Use a middleware on a class](#use-a-middleware-on-a-class)
		- [Use a middleware on a class method](#use-a-middleware-on-a-class-method)
		- [Create a middleware with parameters](#create-a-middleware-with-parameters)
		- [Use a middleware with parameters](#use-a-middleware-with-parameters)
		- [Use global middlewares](#use-global-middlewares)
		- [Inspect compiled routes](#inspect-compiled-routes)
		- [HttpUtils](#httputils)
	- [Tests](#tests)
	- [Changelog](#changelog)
	- [License](#license)

## Installation

### Requirements

PHP 8.3+

### Getting started

```shell
composer require karewan/knroute
```

## Usage

### Register routes from the controllers and run the router

```php
declare(strict_types=1);

use Karewan\KnRoute\Router;

// Enable controller change detection only in development.
const IS_DEV = true;

$router = new Router();

$router->registerRoutesFromControllers(
	controllersPath: __DIR__ . '/App/Controllers',
	cacheFile: __DIR__ . '/tmp/cache.php',
	scanForModifiedControllers: IS_DEV
);

// Sends the response and terminates the request.
$router->run();
```

`cacheFile` may be `null` to disable the cache. In production, leave `scanForModifiedControllers` set to `false` (its default). When the cache exists, KnRoute loads it without reading the controllers directory. Warm or regenerate it during deployment whenever controllers change.

In development, set `scanForModifiedControllers` to `true`. KnRoute compares a fast content-based signature of the controller files with the signature stored in the cache. Unchanged routes are loaded from cache without repeating tokenization, reflection, attribute construction, or route compilation. This development check still reads the controller files and must not be enabled in production.

### Different types of routes

All route attributes target public controller methods and may be repeated on the same method.

```php
#[Get('/resource')]
#[Post('/resource')]
#[Put('/resource')]
#[Patch('/resource')]
#[Delete('/resource')]
#[Head('/resource')]
#[Options('/resource')]
#[Any('/resource')]                    // Fallback when no explicit method route matches
#[Route(['GET', 'POST'], '/resource')] // Selected methods
```

Every path must start with `/`. HTTP method names supplied to `Route` are case-sensitive tokens.

### Add route attributes to controller methods

```php
declare(strict_types=1);

namespace App\Controllers;

use Karewan\KnRoute\Attributes\Get;
use Karewan\KnRoute\Attributes\Post;
use Karewan\KnRoute\HttpUtils;

class IndexController
{
	#[Get('/')]
	public function index(): never
	{
		HttpUtils::outputText("IndexController@index\n");
	}

	#[Post('/login')]
	public function login(): never
	{
		HttpUtils::outputJson(['error' => 'Bad credentials']);
	}
}
```

### Controller discovery rules

The scanned directory is recursive. Each PHP file may declare at most one named class. Anonymous classes are ignored, abstract classes do not register routes, and only public methods declared directly on the concrete class are inspected; inherited methods are not registered again.

Controller files should follow PSR-4 naming so the discovered class can be loaded by the application autoloader. The discovery pass tokenizes files but does not explicitly include them.

### Route matching and HTTP semantics

- Static routes take precedence over dynamic routes, and method-specific routes take precedence over `Any` routes.
- Equivalent routes for the same method are rejected during compilation instead of depending on file order.
- Route compilation is deterministic across controller file and declaration order.
- `HEAD` uses an explicit `HEAD` route when present. Otherwise, a matching `GET` or `Any` route confirms the resource without executing its controller action, and the response body is suppressed.
- `OPTIONS` uses an explicit route when present. Otherwise, KnRoute returns `204 No Content` with an `Allow` header. `OPTIONS *` advertises server capabilities.
- A known path with an unsupported method returns `405 Method Not Allowed`; an unknown path returns `404 Not Found`; an unknown HTTP method returns `501 Not Implemented` unless an application route accepts it.

### Use variables inside a path

Variables use the strict `{name:type}` syntax. A variable name must:

- be a valid ASCII PHP parameter name;
- be unique within the route;
- contain no more than 32 characters;
- exactly match a parameter of the controller method.

Conversely, every required controller parameter must have a matching route variable. Optional controller parameters are allowed. Invalid declarations are rejected when routes are compiled.

```php
#[Post('/amd/{id:uint}/ryzen/{model:alnum}')]
public function topSecret(int $id, string $model): void
{
	echo "AmdController@topSecret(id={$id},model={$model})";
}
```

Matching is performed against the encoded request path. Captured values are then decoded once with `rawurldecode()` before the controller is called. Consequently, `%20` becomes a space while a literal `+` remains `+`.

### Variable types

| Type | Pattern | Examples |
| --- | --- | --- |
| `alpha` | ASCII letters | `abc`, `KnRoute` |
| `alnum` | ASCII letters and decimal digits | `abc123` |
| `uint` | Canonical unsigned decimal integer | `0`, `42` |
| `int` | Canonical signed or unsigned decimal integer | `0`, `42`, `-42` |
| `hex` | Uppercase or lowercase hexadecimal digits | `deadBEEF` |
| `slug` | Alphanumeric words separated by single hyphens | `my-page-2` |
| `uuid` | Canonical 8-4-4-4-12 hexadecimal UUID | `550e8400-e29b-41d4-a716-446655440000` |
| `segment` | One non-empty path segment; `/` is excluded | `file.txt`, `a+b` |
| `path` | A non-empty value that may contain `/` | `images/icons/logo.svg` |

`uint` and `int` reject leading zeroes such as `042`; `int` also rejects `-0`. `slug` rejects leading, trailing, and consecutive hyphens. Use `path` only as the final variable unless the following static text makes the intended boundary unambiguous.

### Create a middleware

```php
declare(strict_types=1);

namespace App\Middlewares;

use Attribute;
use Karewan\KnRoute\HttpUtils;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class AuthMiddleware implements IMiddleware
{
	/**
	 * Do your logic here
	 * @return void
	 */
	public function handle(): void
	{
		if (!isLogged()) {
			HttpUtils::dieStatus(401);
		}
	}
}
```

### Use a middleware on a class

Will be executed before instantiating the class.

```php
declare(strict_types=1);

namespace App\Controllers;

use App\Middlewares\AuthMiddleware;
use Karewan\KnRoute\Attributes\Get;
use Karewan\KnRoute\HttpUtils;

#[AuthMiddleware()]
class TestController
{
	#[Get('/')]
	public function index(): void
	{
		HttpUtils::outputText("TestController@index\n");
	}
}
```

### Use a middleware on a class method

Will be executed after instantiating the class and before calling the method.

```php
declare(strict_types=1);

namespace App\Controllers;

use App\Middlewares\AuthMiddleware;
use Karewan\KnRoute\Attributes\Get;
use Karewan\KnRoute\HttpUtils;

class TestController
{
	#[Get('/'), AuthMiddleware()]
	public function index(): void
	{
		HttpUtils::outputText("TestController@index\n");
	}
}
```

### Create a middleware with parameters

```php
declare(strict_types=1);

namespace App\Middlewares;

use Attribute;
use Karewan\KnRoute\HttpUtils;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class SecretMiddleware implements IMiddleware
{
	/**
	 * Class constructor
	 * @param null|int $requireType
	 * @return void
	 */
	public function __construct(private ?int $requireType = null) {}

	/**
	 * Define your logic here
	 * @return void
	 */
	public function handle(): void
	{
		if (!is_null($this->requireType)) {
			HttpUtils::outputText("SecretMiddleware@handle(requireType={$this->requireType})\n");
		}
	}
}
```

### Use a middleware with parameters

```php
declare(strict_types=1);

namespace App\Controllers;

use App\Middlewares\SecretMiddleware;
use Karewan\KnRoute\Attributes\Get;
use Karewan\KnRoute\HttpUtils;

#[SecretMiddleware(requireType: 10)]
class TestController
{
	#[Get('/'), SecretMiddleware(requireType: 99)]
	public function index(): void
	{
		HttpUtils::outputText("TestController@index\n");
	}
}
```

### Use global middlewares

Global middlewares execute in registration order, before route matching and automatic responses such as `OPTIONS`. The complete request order is: global middlewares, route matching, class middlewares, controller construction, method middlewares, then controller action.

```php
$router = new Router();
$router->addGlobalMiddleware(new CorsMiddleware());
$router->registerRoutesFromControllers($controllersPath, $cacheFile);
$router->run();
```

### Inspect compiled routes

`dumpRoutesFromController()` returns a human-readable route table. It is useful in development and deployment diagnostics; it scans and compiles the supplied controllers.

```php
echo $router->dumpRoutesFromController(__DIR__ . '/App/Controllers');
```

### HttpUtils

All methods on `Karewan\KnRoute\HttpUtils` are static.

```php
function getHost(bool $allowOptionalServerPort = false): string;
function getPath(): string;
function getMethod(): string;
function getProtocol(): string;
function hasHeader(string $name): bool;
function getHeader(string $name): string;
function getHeaders(): array;
function setHeader(string $key, string $value, int $httpCode = 0, bool $replace = true): void;
function setHeaders(array $headers, int $httpCode = 0, bool $replace = true): void;
function getQueryString(): string;
function getContentType(): string;
function getContentLength(): string;
function getUserAgent(): string;
function getLanguages(): string;
function getAcceptEncoding(): string;
function getReferer(): string;
function setTrustedProxyHeaders(array $headers): void;
function getIp(): string;
function getServerPort(): int;
function getClientPort(): int;
function getBody(): string;
function getJsonBody(bool $associative = false, int $depth = 512, int $flags = JSON_BIGINT_AS_STRING): mixed;
function outputJson(mixed $data, int $httpCode = 200): never;
function outputHtml(string $html, int $httpCode = 200): never;
function outputText(string $text, int $httpCode = 200, string $charset = 'utf-8'): never;
function outputXml(string $xmlString, int $httpCode = 200, string $charset = 'utf-8'): never;
function outputString(string $contentType, string $str, int $httpCode = 200, string $charset = 'utf-8'): never;
function location(string $path = '/', int $httpCode = 302): never;
function dieStatus(int $code): never;
```

`getBody()` returns the request body unchanged, including leading and trailing whitespace. `getJsonBody()` decodes that raw body.

No proxy header is trusted by default. If the application runs behind a trusted reverse proxy, explicitly configure the headers that proxy controls:

```php
HttpUtils::setTrustedProxyHeaders(['CF-Connecting-IP']);
```

Never trust client-controlled forwarding headers. When none of the configured headers contains a valid IP address, `getIp()` falls back to `REMOTE_ADDR`.

During `Router::run()`, KnRoute defines the boolean constant `IS_XHR`. It is `true` when the `X-Requested-With` header equals `XMLHttpRequest`.

```php
if (IS_XHR) {
	// Handle an XMLHttpRequest request.
}
```

## Tests

The test suite requires only PHP and Composer; it does not depend on PHPUnit or an external web server. Run all routing and cache tests from the project root:

```shell
composer test
```

The suite starts isolated PHP processes to exercise complete requests. It covers static and dynamic routes, every variable type, URL decoding, HTTP methods and automatic responses, middleware ordering, controller discovery, invalid declarations, conflicting routes, and cache invalidation.

A successful run ends with zero failures and a passing cache-behavior check. The command returns a non-zero exit code when any assertion fails, so it can be used directly in continuous integration.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

See [LICENSE.txt](LICENSE.txt).

```
Copyright © 2024 - 2026 Florent VIALATTE (github.com/Karewan/KnRoute)

Permission is hereby granted, free of charge, to any person obtaining
a copy of this software and associated documentation files (the
"Software"), to deal in the Software without restriction, including
without limitation the rights to use, copy, modify, merge, publish,
distribute, sublicense, and/or sell copies of the Software, and to
permit persons to whom the Software is furnished to do so, subject to
the following conditions:

The above copyright notice and this permission notice shall be
included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
```
