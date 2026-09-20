# KnRoute

Simple and fast PHP 8.3+ router with route attributes and caching.

## Table of content

- [KnRoute](#knroute)
	- [Table of content](#table-of-content)
	- [Installation](#installation)
		- [Requirements](#requirements)
		- [Getting started](#getting-started)
		- [Running the tests](#running-the-tests)
	- [Usage](#usage)
		- [Register routes from the controllers and run the router](#register-routes-from-the-controllers-and-run-the-router)
		- [Different types of routes](#different-types-of-routes)
		- [Add route attributes to controller methods](#add-route-attributes-to-controller-methods)
		- [Use variables inside a path](#use-variables-inside-a-path)
		- [Variable types with their corresponding regex](#variable-types-with-their-corresponding-regex)
		- [Create a middleware](#create-a-middleware)
		- [Use a middleware on a class](#use-a-middleware-on-a-class)
		- [Use a middleware on a class method](#use-a-middleware-on-a-class-method)
		- [Create a middleware with parameters](#create-a-middleware-with-parameters)
		- [Use a middleware with parameters](#use-a-middleware-with-parameters)
		- [Use global middlewares](#use-global-middlewares)
		- [HttpUtils class (all methods are static)](#httputils-class-all-methods-are-static)
	- [Changelog](#changelog)
	- [License](#license)

## Installation

### Requirements

PHP 8.3+

### Getting started

```
$ composer require karewan/knroute
```

### Running the tests

```shell
composer test
```

## Usage

### Register routes from the controllers and run the router

```php
declare(strict_types=1);

use Karewan\KnRoute\Router;

// Must be false in production
const IS_DEV = true;

// Init the router
$router = new Router();

// Scan controllers, register routes and optionnaly cache them
$router->registerRoutesFromControllers(
	// Folder to be scanned
	controllersPath: __DIR__ . '/App/Controllers',
	// Compiled routes cache
	cacheFile: __DIR__ . '/tmp/cache.php',
	// Development only: invalidate the cache when controller contents change
	scanForModifiedControllers: IS_DEV
);

// Run the router (nothing will be executed below this line)
$router->run();
```

In production, always leave `scanForModifiedControllers` set to `false` (its default). When the cache file exists, KnRoute loads it directly without scanning or reading the controllers directory. Generate or warm the cache during deployment and replace it whenever controllers change.

In development, set `scanForModifiedControllers` to `true`. KnRoute compares a fast content-based signature of the controller files with the signature stored in the cache. Unchanged routes are loaded from cache without repeating tokenization, reflection, attribute construction, or route compilation. This development check still reads the controller files and must not be enabled in production.

### Different types of routes

All are method attributes.

```php
// All HTTP methods
#[Any('/test')]

// HTTP DELETE method
#[Delete('/test')]

// HTTP GET method
#[Get('/test')]

// HTTP HEAD method
#[Head('/test')]

// HTTP OPTIONS method
#[Options('/test')]

// HTTP PATCH method
#[Patch('/test')]

// HTTP POST method
#[Post('/test')]

// HTTP PUT method
#[Put('/test')]

// Use an array of HTTP methods
#[Route(['GET', 'POST'], '/test')]
```

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

### Use variables inside a path

Name of the variable followed by the type.

```php
#[Post('/amd/{id:num}/ryzen/{model:alpha}')]
public function topSecret(int $id, string $model):void {
	echo "AmdController@topSecret(id={$id},model={$model})";
}
```

### Variable types with their corresponding regex

```
:alpha		[a-z0-9]+
:letters	[a-z]+
:num		[0-9]+
:slug		[a-z0-9\-]+
:hex		[a-f0-9]+'
:any		[^\/]+
:all		.*
```

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
		if(!isLogged()) {
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

Global middlewares execute before route matching and automatic responses such as `OPTIONS`.

```php
$router = new Router();
$router->addGlobalMiddleware(new CorsMiddleware());
$router->registerRoutesFromControllers($controllersPath, $cacheFile);
$router->run();
```

### HttpUtils class (all methods are static)

Karewan\KnRoute\HttpUtils::

```php
function getHost(): string;
function getPath(): string;
function getMethod(): string;
function getProtocol():string;
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

Global constant (bool)
```php
IS_XHR
```

## Changelog

See the changelog [here](CHANGELOG.md)

## License

See the license [here](LICENSE.txt)

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
