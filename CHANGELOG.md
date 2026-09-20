v4.0.0 (unreleased)
----------------------------
### Added
* **HTTP method attributes:** Added `Head` and `Options` route attributes.
* **Global middleware:** Added `Router::addGlobalMiddleware()` for logic that must run before routes and automatic responses.
* **Routing tests:** Added a dependency-free functional test suite covering route results, parameters, HTTP methods, and error statuses.
* **Cache format versioning:** Added an integer format version to route caches so incompatible or legacy caches are automatically regenerated at runtime.
* **Cache format tests:** Added explicit coverage for cached execution metadata, middleware plans, argument conversions, legacy cache regeneration, and cached/uncached parity across every routing scenario.

### Changed
* **Package versioning:** Removed the hardcoded version from `composer.json`; Composer and Packagist now infer releases from VCS tags.
* **Route dumper:** Removed unused recursion state and obsolete host-matching code from `RoutesDumper`.
* **Cached route execution:** Controller and method middleware metadata and scalar argument conversions are now precompiled into the route cache, removing reflection and `invokeArgs()` from the production request path.
* **Development cache validation:** Controller caches now use a fast path, size, and modification-time signature before falling back to content hashing, avoiding full controller reads on unchanged development requests. Signatures are neither computed nor stored when controller scanning is disabled.
* **Stateless HTTP utilities:** Removed request-derived static caches from `HttpUtils` for safe use in long-running workers; request and response header names are now handled case-insensitively and normalized.
* **Content length:** `HttpUtils::getContentLength()` now returns an integer, or `null` when the header is absent.
* **Matched route accessors:** Renamed `Router::getFindedController()` and `Router::getFindedMethod()` to `getMatchedController()` and `getMatchedMethod()`.
* **XML responses:** `HttpUtils::outputXml()` now uses the `application/xml` media type.
* **XHR constant:** `Router::run()` now preserves an existing `IS_XHR` constant instead of attempting to redefine it.
* **Route declarations:** Route paths may no longer have a trailing slash (except `/`), request trailing slashes remain normalized during matching, and custom HTTP methods must be declared in uppercase.

### Fixed
* **HTTP method handling:** Fixed `Router` assigning a boolean instead of the request method, which caused automatic `OPTIONS` responses to incorrectly return HTTP 405.
* **Typed route parameters:** Controller methods are now invoked through reflection so compatible captured values, such as a numeric route parameter passed to an `int`, are coerced correctly.
* **URL-decoded route parameters:** Route parameters now use `rawurldecode()` so literal plus signs in URL paths are preserved instead of being converted to spaces.
* **Request body integrity:** `HttpUtils::getBody()` no longer trims leading or trailing whitespace from request bodies.
* **Controller discovery:** Class discovery no longer explicitly includes scanned files; files declaring multiple named classes are rejected, anonymous and abstract classes are ignored, and only methods declared directly on each concrete class are registered.
* **Route paths:** Route declarations without a leading `/` are now rejected.
* **Deterministic route ordering:** Route compilation now uses a total, deterministic order across files and PHP versions, with explicit-method and static routes retaining precedence.
* **Route variables:** Variable declarations now require the strict `{name:type}` syntax with valid, unique PHP-compatible names; malformed declarations, unknown types, and mismatches with controller parameters are rejected during compilation.
* **Variable types:** Corrected the misleading `alpha`, `hex`, and `slug` patterns and added `alnum`, `int`, `uint`, `segment`, `path`, and `uuid` types.
* **Case-sensitive routes:** Request paths are no longer converted to lowercase before route matching.
* **HEAD and OPTIONS semantics:** HEAD now executes its GET fallback while suppressing the response body, automatic OPTIONS responses advertise the available methods, and invalid HEAD requests correctly return HTTP 405.
* **HTTP method routing:** Explicit routes now take priority over `Any`, conflicting routes and invalid method tokens are rejected during compilation, and unsupported methods return HTTP 501.
* **Routes cache:** Cache invalidation now detects controller additions, deletions, and content changes; writes clean up temporary files and invalidate OPcache after atomic replacement.

### Security
* **Proxy headers:** Forwarding headers are now accepted only when `REMOTE_ADDR` matches an IP explicitly configured through `HttpUtils::setTrustedProxies()`; applications must also configure allowed header names with `setTrustedProxyHeaders()`.

### Removed
* **Inertia.js support:** Removed the Inertia plugin and its `Inertia`, `AlwaysProp`, and `LazyProp` classes.
* **Legacy variable types:** Removed `letters`, `num`, `any`, and `all`; use `alpha`, `uint`, `segment`, and `path` respectively.


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
