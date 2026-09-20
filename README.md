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
		- [Handle HTTP errors](#handle-http-errors)
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

`cacheFile` may be `null` to disable the cache. In production, leave `scanForModifiedControllers` set to `false` (its default). KnRoute neither computes nor stores controller signatures in this mode. When the cache exists, it is loaded without reading the controllers directory. Warm or regenerate it during deployment whenever controllers change.

> **Security:** The route cache is an executable PHP file loaded with `require`. Anyone who can modify or replace it can execute arbitrary PHP code with the permissions of the application process. Store the cache outside user-upload directories and, preferably, outside the public web root. Its file and parent directory must be writable only by trusted deployment or application identities; never allow untrusted users, tenants, uploaded content, or unrelated services to write there. Do not use a user-controlled path for `cacheFile`.

In development, set `scanForModifiedControllers` to `true`. KnRoute first compares a metadata-only signature built from each controller's path, size, and modification time. It reads and hashes file contents only when that fast signature changes, and recompiles routes only when the content hash also changes. The directory traversal and metadata checks still have a cost, so this option must not be enabled in production.

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

Every path must start with `/` and must not end with `/`, except for the root route itself. For example, declare `/users`, not `/users/`. Request paths are normalized by trimming their trailing slash before matching, which keeps routing consistent across web servers that preserve or rewrite trailing slashes differently.

HTTP method names supplied to `Route` must be uppercase valid tokens. For example, use `GET`, not `get`.

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
	public function index(): void
	{
		HttpUtils::outputText("IndexController@index\n");
	}

	#[Post('/login')]
	public function login(): void
	{
		HttpUtils::outputJson(['error' => 'Bad credentials']);
	}
}
```

### Controller discovery rules

The scanned directory is recursive. Every scanned PHP file must declare at most one named class. This is a strict requirement, not a convention: discovery throws a `LogicException` when a file contains two or more named classes, even if the additional classes are abstract, helper classes, or do not declare any routes. Put each named class in its own file. Anonymous classes are ignored, abstract classes do not register routes, and only public methods declared directly on the concrete class are inspected; inherited methods are not registered again.

A concrete class declaring routes must be instantiable, and its constructor must not require arguments. Route actions must be non-static public methods and cannot be constructors or destructors.

Controller files should follow PSR-4 naming so the discovered class can be loaded by the application autoloader. The discovery pass tokenizes files but does not explicitly include them.

### Route matching and HTTP semantics

- Static routes take precedence over dynamic routes, and method-specific routes take precedence over `Any` routes.
- Equivalent routes for the same method are rejected during compilation instead of depending on file order.
- Dynamic routes that can match the same path for a shared HTTP method are rejected during compilation, even when their static structures differ. For example, `/users/{id:uint}` conflicts with both `/users/{value:segment}` and `/{everything:path}`. Two overlapping `Any` routes are rejected as well. Static routes may still specialize dynamic routes, and an explicit HTTP method may specialize an `Any` fallback because both cases have deterministic precedence. Routes with the same static structure use direct variable-domain checks. Detection across different structures is necessarily heuristic: it tests representative values and is capped at 4096 generated paths per direction. Very complex combinations can therefore still require the application to avoid or test potential ambiguity explicitly.
- Route compilation is deterministic across controller file and declaration order.
- `Any` accepts every request method, including `HEAD`, `OPTIONS`, and application-defined methods. Explicit method routes retain priority over `Any`.
- `HEAD` uses an explicit `HEAD` route when present. Otherwise, it executes the matching `GET` or `Any` route so the response keeps the same status and headers, while suppressing the response body.
- `OPTIONS` uses an explicit `OPTIONS` route first, then an `Any` route. Otherwise, KnRoute returns `204 No Content` with an `Allow` header. `OPTIONS *` lists application-supported methods, including implicit `HEAD` and `OPTIONS` support.
- A known path with an unsupported method returns `405 Method Not Allowed`; an unknown path returns `404 Not Found`; an unknown HTTP method returns `501 Not Implemented` unless an application route accepts it.

### Use variables inside a path

Variables use the strict `{name:type}` syntax. A variable name must:

- be a valid ASCII PHP parameter name;
- be unique within the route;
- contain no more than 32 characters;
- exactly match a parameter of the controller method.

Conversely, every required controller parameter must have a matching route variable. Optional controller parameters are allowed. Invalid declarations are rejected when routes are compiled.

Captured URL values are strings after URL decoding. During route compilation, KnRoute reads the matching controller parameter types and stores the required scalar conversions in the route cache. Parameters declared as `int`, `float`, or `bool` are cast accordingly at execution time; parameters declared as `string` or without a type remain strings. No reflection or type inspection is performed while handling a cached request.

Route parameters cannot be passed by reference. Named object types, intersection types, and ambiguous scalar unions such as `int|float` are rejected. A union containing `string` or `mixed` accepts the original string; a nullable union with exactly one of `int`, `float`, or `bool` uses that scalar conversion. Variadic action parameters are allowed only when they are not populated by a route variable; one URL capture is never expanded implicitly into variadic arguments.

```php
#[Post('/amd/{id:uint}/ryzen/{model:alnum}')]
public function topSecret(int $id, string $model): void
{
	echo "AmdController@topSecret(id={$id},model={$model})";
}
```

Matching is performed against the encoded request path. Captured values are then decoded once with `rawurldecode()` before the controller is called. Consequently, `%20` becomes a space while a literal `+` remains `+`. The `segment` type rejects both literal and percent-encoded `/` separators; use `path` when separators are expected inside the captured value.

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

`uint` and `int` reject leading zeroes such as `042` and values outside the platform's integer range; `int` also rejects `-0`. These bounds are embedded in the compiled route expressions, including cached routes. `slug` rejects leading, trailing, and consecutive hyphens. Use `path` only as the final variable unless the following static text makes the intended boundary unambiguous.

### Create a middleware

An exception is not required to create or use a middleware. In the usual case, `before()` performs its work and returns normally, then `after()` runs after the controller action. The authentication example below uses an application exception only because it needs to interrupt routing and prevent the controller action from running.

Create the application exception in its own file, `src/Exceptions/UnauthorizedException.php`:

```php
declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class UnauthorizedException extends RuntimeException {}
```

Then create the middleware in `src/Middlewares/AuthMiddleware.php`:

```php
declare(strict_types=1);

namespace App\Middlewares;

use Attribute;
use App\Exceptions\UnauthorizedException;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class AuthMiddleware implements IMiddleware
{
	/**
	 * Run before the controller action
	 * @return void
	 */
	public function before(): void
	{
		if (!isLogged()) {
			throw new UnauthorizedException();
		}
	}

	/**
	 * Run after the action, including when it throws
	 * @return void
	 */
	public function after(): void
	{
		// Cleanup, logging, response headers, etc.
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

Will be executed before instantiating the class and calling the method.

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
	 * Define logic executed before the action
	 * @return void
	 */
	public function before(): void
	{
		if (!is_null($this->requireType)) {
			HttpUtils::outputText("SecretMiddleware@before(requireType={$this->requireType})\n");
		}
	}

	public function after(): void {}
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

Middleware arguments are preserved in the route cache. Scalars, `null`, arrays, and enum cases are supported directly. Object arguments must implement a public static `__set_state()` method so PHP can reconstruct them when loading the cache. Cache generation throws a `LogicException` for unsupported values instead of silently replacing them with `null`.

### Use global middlewares

Global middleware `before()` methods execute in registration order, before route matching and automatic responses such as `OPTIONS`. Class and method middleware `before()` methods then execute before the controller action. When the controller action throws, middleware `after()` methods still run in reverse order before the original exception is propagated.

Middleware hook failures stop execution immediately. If a `before()` method throws, neither the action nor any `after()` method runs. If an `after()` method throws, the remaining `after()` methods do not run. In both cases, the original middleware exception is propagated from `Router::run()` and can be caught by the application.

```php
$router = new Router();
$router->addGlobalMiddleware(new CorsMiddleware());
$router->registerRoutesFromControllers($controllersPath, $cacheFile);

try {
	$router->run();
} catch (\App\Exceptions\UnauthorizedException) {
	\Karewan\KnRoute\HttpUtils::setStatus(401);
}
```

`UnauthorizedException` is needed here only to stop execution when authentication fails. Ordinary middlewares do not need an exception. This exception belongs to the application and is declared in its own file above; KnRoute does not define or swallow it. Except for KnRoute's explicit `HttpException`, an exception thrown by `before()` or `after()` leaves `Router::run()` unchanged, so the front controller can catch it and choose the HTTP response.

### Handle HTTP errors

Router-generated `404`, `405`, and `501` errors can be rendered with a handler for one status or a default handler. A status-specific handler takes precedence. The router sets the status and required protocol headers, such as `Allow` on a `405`, before invoking the handler.

```php
use Karewan\KnRoute\HttpError;
use Karewan\KnRoute\HttpUtils;

$router->setErrorHandler(404, function (HttpError $error): void {
	HttpUtils::outputHtml(
		View::render('errors.php', ['error' => $error]),
		$error->code,
	);
});

$router->setDefaultErrorHandler(function (HttpError $error): void {
	HttpUtils::outputError(
		code: $error->code,
		title: $error->title,
		detail: $error->detail,
	);
});
```

The resolution order is always the same: KnRoute first looks for a handler registered for the exact status, then uses the default handler. If neither exists, it only sets the HTTP status, preserving its previous behavior. Handlers run for router-generated `404`, `405`, and `501` responses and for an explicit `HttpException`.

A dedicated renderer class can keep this configuration out of the application bootstrap:

```php
final class JsonErrorRenderer
{
	public function render(HttpError $error): void
	{
		HttpUtils::outputError(
			code: $error->code,
			title: $error->title,
			detail: $error->detail,
			extensions: ['request_id' => RequestId::current()],
		);
	}
}

$errorRenderer = new JsonErrorRenderer();

$router->setDefaultErrorHandler($errorRenderer->render(...));
```

The handler is any PHP callable; it does not need to be a controller. A small renderer or view class is generally a clearer fit because error rendering is not a routed application action.

An action or middleware can intentionally use the same error pipeline by throwing `HttpException`:

```php
use Karewan\KnRoute\Exceptions\HttpException;

throw new HttpException(
	statusCode: 422,
	detail: 'The submitted email address is invalid.',
	title: 'Validation Failed',
);
```

Calling `HttpUtils::setStatus()`, `http_response_code()`, or an output helper with an error status does not invoke an error handler. Those calls mean that the application owns the response body. Other application and configuration exceptions continue to propagate normally.

`HttpUtils::outputError()` produces a standard `application/json` response ordered as `status`, `path`, `title`, `detail`, then any custom members. Only the error status is required. Omitted title and detail values come from KnRoute's catalogue of usable `4xx` and `5xx` HTTP errors, while `path` is always populated from the current request path. Custom members can be added with `extensions`; the standard members cannot be overwritten. Unassigned application-specific codes receive a generic title and detail based on their status class.

```php
HttpUtils::outputError(
	code: 404,
	extensions: ['trace_id' => '01K5...'],
);
```

### Inspect compiled routes

`dumpRoutesFromController()` returns a human-readable route table. It is useful in development and deployment diagnostics; it scans and compiles the supplied controllers.

```php
echo $router->dumpRoutesFromController(__DIR__ . '/App/Controllers');
```

### HttpUtils

All methods on `Karewan\KnRoute\HttpUtils` are static.

```php
function getHost(): string;
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
function getContentLength(): ?int;
function getUserAgent(): string;
function getLanguages(): string;
function getAcceptEncoding(): string;
function getReferer(): string;
function isXmlHttpRequest(): bool;
function setTrustedProxies(array $proxies): void;
function setTrustedProxyHeaders(array $headers): void;
function getIp(): string;
function getServerPort(): ?int;
function getClientPort(): ?int;
function getBody(): string;
function getJsonBody(bool $associative = false, int $flags = 0, int $depth = 512): mixed;
function outputJson(mixed $data, int $httpCode = 200, int $flags = 0, int $depth = 512): void;
function outputError(int $code, ?string $title = null, ?string $detail = null, array $extensions = [], int $flags = 0, int $depth = 512): void;
function outputHtml(string $html, int $httpCode = 200): void;
function outputText(string $text, int $httpCode = 200, string $charset = 'utf-8'): void;
function outputXml(string $xmlString, int $httpCode = 200, string $charset = 'utf-8'): void;
function outputString(string $contentType, string $str, int $httpCode = 200, string $charset = 'utf-8'): void;
function location(string $path = '/', int $httpCode = 302): void;
function setStatus(int $code): void;
```

`HttpUtils` does not cache request-derived values, making it safe for long-running workers that serve multiple requests. Header lookup is case-insensitive. Header names passed to `setHeader()` and `setHeaders()` are normalized to standard title case.

`getBody()` returns the request body unchanged, including leading and trailing whitespace. `getJsonBody()` decodes that raw body.

`outputJson()` always enables `JSON_THROW_ON_ERROR` and accepts the remaining `json_encode()` flags and depth. `getJsonBody()` remains permissive by default and returns `null` for invalid JSON; pass `JSON_BIGINT_AS_STRING` explicitly when preserving oversized integers as strings is required.

Response helpers and `Router::run()` return control to the caller after writing the response. This makes controllers and complete routing flows directly testable without terminating the PHP process.

Missing request URI, method, protocol, or query-string server values produce safe empty defaults. Port accessors return `null` when their server value is absent, invalid, or outside the `1..65535` range.

`getHost()` supports bracketed IPv6 literals and always removes the server port. Use `getServerPort()` when the port is needed.

`getContentLength()` returns a non-negative integer, or `null` when the header is absent or invalid.

No proxy address is trusted by default. If the application runs behind trusted reverse proxies, configure their individual IPv4/IPv6 addresses:

```php
HttpUtils::setTrustedProxies(['10.0.0.10', '10.0.0.11', '2001:db8::10']);
HttpUtils::setTrustedProxyHeaders(['Forwarded', 'X-Forwarded-For']);
```

Forwarding headers are disabled by default and must be explicitly allowed with `setTrustedProxyHeaders()`. `getIp()` supports both the standard `Forwarded` syntax and comma-separated address lists used by headers such as `X-Forwarded-For` and `CF-Connecting-IP`. They are ignored unless `REMOTE_ADDR` belongs to a configured trusted proxy. Proxy chains are traversed from right to left and stop at the first untrusted address, preventing client-supplied entries to its left from being trusted. Your edge proxy must overwrite or remove these headers before forwarding requests.

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
