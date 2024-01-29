# KnRoute

Simple and fast PHP 8.1+ router with routes attributes and caching

### Changelog

See the changelog [here](CHANGELOG.md)

### Requirements

PHP 8.1+

### Getting started

```
$ composer require karewan/knroute
```

### Usage example

```php
declare(strict_types=1);

use Karewan\KnRoute\Router;

$router = new Router();
$router->registerRoutesFromControllers(
	__DIR__ . 'App/Controllers',
	DEBUG ? null : __DIR__ . 'tmp/cache.php'
);
$router->run();
```

```php
declare(strict_types=1);

namespace App\Controllers;

use App\Middlewares\AuthMiddleware;
use App\Middlewares\SecretMiddleware;
use App\Middlewares\TestMiddleware;
use Karewan\KnRoute\Attributes\Delete;
use Karewan\KnRoute\Attributes\Get;
use Karewan\KnRoute\Attributes\Post;
use Karewan\KnRoute\Attributes\Route;

#[AuthMiddleware()]
class AmdController
{
	public function __construct()
	{
		echo "AmdController@__construct\n";
	}

	#[Get('/amd')]
	public function index(): void
	{
		echo "AmdController@index\n";
	}

	#[Get('/amd/{id:num}'), TestMiddleware()]
	public function get(int $id): void
	{
		echo "AmdController@get(id={$id})\n";
	}

	#[Post('/amd/{id:num}')]
	public function save(int $id): void
	{
		echo "AmdController@save(id={$id})\n";
	}

	#[Delete('/amd/{id:num}')]
	public function delete(int $id): void
	{
		echo "AmdController@delete(id={$id})\n";
	}

	#[
		Route(['GET', 'POST', 'PUT'], '/amd/special-page'),
		TestMiddleware()
	]
	public function specialPage(): void
	{
		echo "AmdController@specialPage\n";
	}

	#[
		Get('/amd/{id:num}/ryzen/{model:alpha}/{hexRef:hex}'),
		TestMiddleware(),
		SecretMiddleware(requireType: 99)
	]
	public function topSecret(int $id, string $model, string $hexRef): void
	{
		echo "AmdController@topSecret(id={$id},model={$model},hexRef={$hexRef})\n";
	}
}
```

```php
declare(strict_types=1);

namespace App\Middlewares;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class AuthMiddleware implements IMiddleware
{
	public function handle(): void
	{
		echo "AuthMiddleware@handle()\n";
		// if (true) {
		// 	http_response_code(401);
		// 	die();
		// }
	}
}
```

```php
declare(strict_types=1);

namespace App\Middlewares;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class TestMiddleware implements IMiddleware
{
	public function handle(): void
	{
		echo "TestMiddleware@handle()\n";
		// if (true) {
		// 	http_response_code(401);
		// 	die();
		// }
	}
}
```

```php
declare(strict_types=1);

namespace App\Middlewares;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class SecretMiddleware implements IMiddleware
{
	public function __construct(private ?int $requireType = null)
	{
	}

	public function handle(): void
	{
		echo "SecretMiddleware@handle(requireType={$this->requireType})\n";
		// if (true) {
		// 	http_response_code(401);
		// 	die();
		// }
	}
}
```

### Routes types

```php
// All HTTP methods
#[Any('/test')]

// HTTP DELETE method
#[Delete('/test')]

// HTTP GET method
#[Get('/test')]

// HTTP PATCH method
#[Patch('/test')]

// HTTP POST method
#[Post('/test')]

// HTTP PUT method
#[Put('/test')]

// List of HTTP methods
#[Route(['GET', 'POST'], '/test')]
```

### Variables

Name of the variable followed by the type.

```php
#[Post('/amd/{id:num}/ryzen/{model:alpha}')]
public function topSecret(int $id, string $model):void {
	echo "AmdController@topSecret(id={$id},model={$model})";
}
```

### Variable types

```
:alpha		[a-z0-9]+
:letters	[a-z]+
:num		[0-9]+
:slug		[a-z0-9\-]+
:hex		[a-f0-9]+'
:any		[^\/]+
:all		.*
```

### HttpUtils class (all static methods)

```php
function getHost(): string;
function getPath(): string;
function getMethod(): string;
function getProtocol():string;
function getHeader(string $name): ?string;
function getHeaders(): array;
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
function getJsonBody(bool $associative = false, bool $bigIntAsString = true): mixed;
function outputJson(mixed $data, int $httpCode = 200): never;
function outputHtml(string $html, int $httpCode = 200): never;
function outputText(string $text, int $httpCode = 200): never;
function dieStatus(int $code): never;
```

### License

See the license [here](LICENSE.txt)

```
Copyright © 2024 Florent VIALATTE (github.com/Karewan/KnRoute)

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
