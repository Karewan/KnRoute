v4.0.1 (2026-09-21)
----------------------------
### Added
* **Stopping a request:** Added `StopRequestException`. Throw it from a middleware `before()` hook or from a controller action once the response has been produced, to stop the request without producing an error. Raised from a `before()` hook it cancels the controller action and the remaining `before()` hooks; nothing is rendered and the status is left untouched. The stack still unwinds, so every `after()` hook that started runs. It covers redirects, cache hits, `304 Not Modified` responses and other short circuits that `HttpException` cannot express, and replaces `die()`/`exit`, which skip the `after()` hooks and terminate long-running workers.

### Fixed
* **Middleware construction during compilation:** Route discovery no longer instantiates every middleware attribute of every route to validate its arguments. Constructor arguments, attribute targets, and repeatability are now validated through reflection with the strict-typing rules used at dispatch, so middleware constructors only run for the dispatched route, with or without a route cache. Cached request handling is unchanged.
* **Error responses for invalid request URIs:** `HttpUtils::outputError()` no longer rethrows `InvalidRequestUriException` while reporting it. A request URI that `getPath()` rejects now renders its `400` response with an empty `path` instead of escaping the error handler as an uncaught exception.
* **Middleware unwinding:** `after()` hooks now run whenever the matching `before()` hook completed, including when another `before()` hook, the controller, a route middleware, or another `after()` hook failed. A failing `before()` hook still cancels the controller action, and the middleware that raised it does not get its own `after()` hook because it never finished setting up. Previously a failure outside the controller skipped the remaining `after()` hooks, so global middleware could never release what it had acquired.
* **Error response ordering:** An `HttpException` is now rendered before the middleware stack that raised it unwinds, so every `after()` hook observes the status the client will receive. Previously only the hooks of the surrounding stacks did: a middleware's own `after()` hooks still saw the status the request started with.
* **Error responses for invalid UTF-8 paths:** `HttpUtils::outputError()` substitutes invalid UTF-8 sequences instead of throwing `JsonException`. A request path carrying raw non-UTF-8 bytes no longer turns its error response into an unhandled encoding failure.

### Changed
* **Header lookups:** `HttpUtils::getHeader()` resolves its CGI variable directly instead of normalizing every `$_SERVER` entry on each call, which also speeds up `getHost()`, `getIp()`, `getContentType()`, and `getContentLength()`. `Content-Length` and `Content-Type` are now resolved whether the SAPI exposes them as CGI variables or as request headers.
* **Route compilation:** Removed the untyped-variable fallback left over from the pre-v4 `{name}` syntax. Every route variable requires the `{name:type}` form, so the fallback expression and its separator lookahead were unreachable.


v4.0.0 (2026-09-20)
----------------------------
Version 4 is a full breaking release. Existing applications must review the migration notes below before upgrading from v3.

### Breaking changes
* **Middleware contract:** `IMiddleware::handle()` has been replaced by `before()` and `after()`. Before hooks run in declaration order; after hooks run in reverse order, including when the controller throws. Middleware constructor arguments are validated while routes are compiled.
* **Response lifecycle:** `Router::run()` and the `HttpUtils` output and redirect helpers no longer call `die()`. They now return `void`, so callers that relied on implicit process termination must return or exit explicitly.
* **Renamed APIs:** `Router::getFindedController()` and `getFindedMethod()` are now `getMatchedController()` and `getMatchedMethod()`. `HttpUtils::dieStatus()` is now `setStatus()`.
* **Removed Inertia integration:** The bundled Inertia plugin and the `Inertia`, `AlwaysProp`, and `LazyProp` classes have been removed.
* **Removed globals and mutable route APIs:** The global `IS_XHR` constant has been replaced by `HttpUtils::isXmlHttpRequest()`. `Route::setPath()`, `setVarsRegex()`, and `getVarRegex()` have been removed; route declarations are immutable.
* **Route syntax:** Paths must start with `/`, must not end with `/` except for the root route, and are now matched case-sensitively. Variables require the strict `{name:type}` form, valid unique PHP-compatible names, and matching controller parameters.
* **Variable types:** `letters`, `num`, `any`, and `all` have been removed; migrate them to `alpha`, `uint`, `segment`, and `path`. The `alpha`, `hex`, and `slug` definitions are stricter, and `alnum`, `int`, `uint`, `segment`, `path`, and `uuid` are available.
* **Controller validation:** Routed controllers must be instantiable without required constructor arguments. Actions must be public, non-static methods declared directly on the concrete controller. Route parameters cannot use references, incompatible object or union types, or route-backed variadics.
* **HTTP method behavior:** Custom methods must be uppercase. `Any` now includes `HEAD` and `OPTIONS`; explicit method routes take precedence over `Any`. `HEAD` falls back to `GET`, `OPTIONS` can be generated automatically, and unknown methods return `501` when no `Any` route exists.
* **Request and response helpers:** `getContentLength()` now returns `?int`; server and client port accessors return `?int`; `getBody()` preserves surrounding whitespace; and `outputXml()` now emits `application/xml`.
* **JSON helpers:** `getJsonBody()` now takes `flags` before `depth` and no longer enables `JSON_BIGINT_AS_STRING` by default. `outputJson()` accepts flags and depth and throws on encoding errors.
* **Host and proxy handling:** `getHost()` no longer accepts the port-retention argument and always removes the server port. Forwarding headers are disabled by default; trusting them now requires both `setTrustedProxies()` and `setTrustedProxyHeaders()`.
* **Controller loading:** Scanned controller files are no longer included by the router and must be autoloadable. Files with multiple named classes or stale/mismatched classmaps are rejected.

### Added
* **Error handling:** Added status-specific and default error handlers, `HttpException` for application-triggered HTTP errors, `HttpError` metadata, standard 4xx/5xx descriptions, and `HttpUtils::outputError()` for JSON error responses.
* **Middleware:** Added global middleware through `Router::addGlobalMiddleware()` and the new before/action/after lifecycle.
* **HTTP methods:** Added `Head` and `Options` attributes, automatic `OPTIONS` responses with `Allow`, `OPTIONS *`, and standards-compliant `HEAD` fallback with body suppression.
* **Route variables:** Added bounded signed and unsigned integers, alphanumeric, segment, path, and UUID variable types, plus cached scalar conversion for controller parameters typed as `int`, `float`, or `bool`.
* **Cache format versioning:** Route caches now carry a format version and are regenerated automatically when an incompatible v3 cache is encountered.

### Changed
* **Compilation and dispatch:** Conflict detection indexes complete literal prefixes instead of comparing every route under the same first segment. Route precedence keys are computed once per route. Empty global middleware stacks are bypassed, and global/local middleware execution no longer allocates intermediate closures while preserving hooks, error handling, and controller lifetime.
* **Routing performance:** Bounded integer expressions are shared within each compiled regexp and built once per process. Conflict detection skips static specializations and incompatible literal prefixes, and routes without local middleware execute without an extra middleware closure. Regenerate existing route caches to benefit from the smaller regexps.
* **Compact route cache:** Empty execution metadata is omitted, controller and middleware class names are interned in a shared symbol table, scalar converters use integer opcodes, and single HTTP methods are stored without per-route lookup tables. Existing caches are regenerated automatically.
* **Routing validation:** Route compilation rejects duplicate or ambiguous dynamic routes, invalid method tokens, invalid controller signatures, invalid middleware arguments, encoded separators in `segment` values, and integer values outside the platform range.
* **Route precedence:** Matching order is deterministic across files and PHP versions. Explicit methods take precedence over `Any`, and static routes take precedence over compatible dynamic routes.
* **Cached execution:** Middleware construction plans and scalar argument conversions are stored in the route cache, removing controller reflection from cached request handling.
* **Cache invalidation:** Development cache validation detects controller additions, removals, and content changes. Cache replacement is atomic, cleans up temporary files, and invalidates OPcache.
* **Long-running workers:** Request-derived values are no longer cached statically by `HttpUtils`, and matched controller/action state is cleared before every request.
* **Request normalization:** Malformed request URIs produce HTTP 400, leading slashes are preserved, trailing slashes remain normalized, route parameters use `rawurldecode()`, and literal `+` characters are preserved.
* **Header handling:** Header names are normalized case-insensitively. `getHost()` supports IPv6 literals, and trusted proxy chains are resolved from right to left.
* **Package metadata:** Removed the hardcoded Composer version; Packagist now derives it from release tags.

### Security
* **Trusted proxies:** Client forwarding headers are ignored unless the immediate peer is explicitly trusted, and traversal stops at the first untrusted address.
* **Executable route cache:** Route caches are executable PHP and must be stored outside user-controlled locations with appropriately restricted write permissions.

### Fixed
* Corrected HTTP 404, 405, 501, `HEAD`, `OPTIONS`, and `Allow` behavior.
* Preserved request-body whitespace and literal plus signs in decoded route parameters.
* Corrected typed controller argument conversion, route-variable patterns, controller discovery, middleware cache arguments, and route-cache freshness checks.


v3.0.7 (2026-07-30)
----------------------------
### Security
* **Refactored IP resolution:** `normalizeIp()` now safely handles comma-separated proxy lists (like `X-Forwarded-For`) by extracting and validating only the first IP, preventing silent failures and IP spoofing.
* **Removed deprecated proxy headers:** Dropped support for the highly unreliable and easily spoofable `HTTP_CLIENT_IP`.
* **Strict IP fallback:** `normalizeIp()` now explicitly falls back to `$_SERVER['REMOTE_ADDR']` instead of treating it identically to proxy headers.

### Added
* **Global proxy configuration:** Added the `setTrustedProxyHeaders()` method and `$trustedProxyHeaders` property to allow dynamic, application-wide configuration of trusted proxies (defaults to `CF-Connecting-IP` and `X-Forwarded-For`).
* **Host port handling:** Added an `$allowOptionalServerPort` parameter to `getHost()` to optionally retain the port number from the Host header.

### Changed
* **Optimized URI parsing:** Updated `getPath()` to use PHP's built-in `parse_url(..., PHP_URL_PATH)` instead of string manipulation (`explode`) for safer handling of malformed requests and absolute URIs.
* **Centralized header retrieval:** `getHost()` now uses `self::getHeader('Host')` instead of directly accessing `$_SERVER['HTTP_HOST']`.
* **Streamlined `hasHeader`:** Refactored `hasHeader()` to reuse `self::getHeader()` under the hood, removing duplicated initialization logic.
* **Code Style:** Converted single-line `if` statements to use standard block braces for better readability and PSR-12 compliance.

### Fixed
* **Docblock correction:** Fixed the PHPDoc in `setHeaders()` to correctly document the `$headers` parameter as an `array` instead of individual string parameters.


v3.0.6 (2025-10-28)
----------------------------
* Added a optional parameter "scanForModifiedControllers" to the "registerRoutesFromControllers" function for using the cache file in the dev env

v3.0.5 (2025-10-21)
----------------------------
* Added setHeaders method
* Added optional charset parameter to the outputText method
* Added outputXml method
* Added outputString method

v3.0.4 (2025-09-21)
----------------------------
* Fixed cache file race condition

v3.0.3 (2025-03-01)
----------------------------
* Fix Inertia URL query string handling

v3.0.2 (2025-02-17)
----------------------------
* Added two constants IS_XHR and IS_INERTIA (the second is set only if Inertia::init is called)

v3.0.1 (2025-01-22)
----------------------------
* Optionnal args for share and viewData methods

v3.0.0 (2024-11-24)
----------------------------
* ### Inertia.js plugin
* Added new HttpUtils methods
	* hasHeader
	* setHeader
	* location
* Various improvements
* ### Breaking changes
	* Bumped PHP version requirement to 8.3
	* Modified HttpUtils methods
		* getHeader to always return a string (instead of null)
		* getJsonBody to add the depth parameter and replace bigIntAsString var by flags var

v2.0.1 (2024-11-05)
----------------------------
* Removed brick/varexporter dependency replaced with an optimized method
* Improved dumpRoutesFromController output result

v2.0.0 (2024-01-29)
----------------------------
* ### Breaking changes
	* Changed Middlewares definition logic

v1.0.9 (2024-01-17)
----------------------------
* Apply urldecode on method arguments

v1.0.8 (2024-01-15)
----------------------------
* Nat sort routes path in dumpRoutesFromController() method

v1.0.7 (2024-01-14)
----------------------------
* Added dumpRoutesFromController() method for visualize routes and for debugging purpose

v1.0.6 (2024-01-14)
----------------------------
* Added new methods to the Router class
	* getFindedController()
	* getFindedMethod()

v1.0.5 (2024-01-08)
----------------------------
* Fixed missing space for "Allow" header
* Added/Fixed informations in "composer.json"

v1.0.4 (2024-01-07)
----------------------------
* Allowed Route and Middleware attributes to be repeated

v1.0.3 (2024-01-07)
----------------------------
* ### Breaking changes
	* Removed middlewares array from the Route attribute (and instanceof)
	* Remplaced Controller attribute by Middleware attribute, can be used from controller and/or method

v1.0.2 (2024-01-07)
----------------------------
* Auto create cache dir if not exist

v1.0.1 (2024-01-07)
----------------------------
* Added getProtocol() method in HttpUtils class

v1.0.0 (2024-01-07)
----------------------------
* Initial release
