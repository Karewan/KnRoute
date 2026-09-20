v4.0.0 (unreleased)
----------------------------
### Removed
* **Inertia.js support:** Removed the Inertia plugin and its `Inertia`, `AlwaysProp`, and `LazyProp` classes.


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
