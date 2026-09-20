<?php

declare(strict_types=1);

namespace Karewan\KnRoute;

use Karewan\KnRoute\Attributes\Route;
use Karewan\KnRoute\Dumper\RoutesDumper;
use Karewan\KnRoute\Exceptions\MethodNotAllowedException;
use Karewan\KnRoute\Exceptions\ResourceNotFoundException;
use Closure;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use LogicException;
use RuntimeException;

class Router
{
	/** @var string[] */
	private const array STANDARD_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
	private const int CACHE_FORMAT_VERSION = 6;

	/**
	 * Compiled routes
	 * @var array
	 */
	private array $compiledRoutes = [];

	/** @var array<string,int> */
	private array $knownMethods = [];

	private bool $acceptsAnyMethod = false;

	private ?Closure $globalMiddlewareRunner = null;

	/**
	 * The matched controller
	 * @var null|string
	 */
	private ?string $matchedController = null;

	/**
	 * The matched method
	 * @var null|string
	 */
	private ?string $matchedMethod = null;

	/**
	 * Run
	 * @return never
	 */
	public function run(): never
	{
		$requestMethod = $_SERVER['REQUEST_METHOD'];

		try {
			// The route. Keep ordinary methods on the shortest possible hot path.
			switch ($requestMethod) {
				case 'HEAD':
					// A HEAD response must never contain a body, including for explicit HEAD routes.
					ob_start(static fn(): string => '');
					if ($this->globalMiddlewareRunner) ($this->globalMiddlewareRunner)();
					$route = $this->findHeadRoute(HttpUtils::getPath());
					break;

				case 'OPTIONS':
					// OPTIONS responses are not cacheable.
					header('Cache-Control: no-store');
					if ($this->globalMiddlewareRunner) ($this->globalMiddlewareRunner)();
					$route = $this->findOptionsRoute(HttpUtils::getPath());
					break;

				case 'GET':
				case 'POST':
				case 'PUT':
				case 'PATCH':
				case 'DELETE':
					if ($this->globalMiddlewareRunner) ($this->globalMiddlewareRunner)();
					$route = $this->findRoute(HttpUtils::getPath(), $requestMethod);
					break;

				default:
					if ($this->globalMiddlewareRunner) ($this->globalMiddlewareRunner)();
					if (!$this->acceptsAnyMethod && !isset($this->knownMethods[$requestMethod])) {
						http_response_code(501);
						die();
					}
					$route = $this->findRoute(HttpUtils::getPath(), $requestMethod);
			}

			// The controller, method and execution metadata are all precompiled in the route cache.
			$this->matchedController = $route[0];
			$this->matchedMethod = $route[1];
			$middlewares = $route[2];
			$argumentConverters = $route[3];
			unset($route[0], $route[1], $route[2], $route[3]);

			foreach ($middlewares as [$middleware, $arguments]) {
				(new $middleware(...$arguments))->handle();
			}

			$controllerInstance = new $this->matchedController;

			foreach ($route as $name => $value) {
				$value = rawurldecode($value);
				$route[$name] = match ($argumentConverters[$name] ?? null) {
					'int' => (int) $value,
					'float' => (float) $value,
					'bool' => (bool) $value,
					default => $value,
				};
			}

			$controllerInstance->{$this->matchedMethod}(...$route);
		} catch (MethodNotAllowedException $e) {
			header('Allow: ' . join(', ', $this->normalizeAllowedMethods($e->getAllowedMethods())));
			http_response_code(405);
		} catch (ResourceNotFoundException $e) {
			http_response_code(404);
		}

		die();
	}

	/**
	 * Return the matched controller
	 * @return null|string
	 */
	public function getMatchedController(): ?string
	{
		return $this->matchedController;
	}

	/**
	 * Return the matched method
	 * @return null|string
	 */
	public function getMatchedMethod(): ?string
	{
		return $this->matchedMethod;
	}

	/**
	 * Add a middleware executed before every route and automatic response.
	 * @param IMiddleware $middleware
	 * @return void
	 */
	public function addGlobalMiddleware(IMiddleware $middleware): void
	{
		$previous = $this->globalMiddlewareRunner;
		$this->globalMiddlewareRunner = is_null($previous)
			? $middleware->handle(...)
			: static function () use ($previous, $middleware): void {
				$previous();
				$middleware->handle();
			};
	}

	/**
	 * Register routes from controllers Route attributes
	 * @param string $controllersPath
	 * @param null|string $cacheFile
	 * @param bool $scanForModifiedControllers
	 * @return void
	 */
	public function registerRoutesFromControllers(string $controllersPath, ?string $cacheFile, bool $scanForModifiedControllers = false): void
	{
		if (!is_null($cacheFile) && is_file($cacheFile)) {
			$cachedRoutes = require $cacheFile;
			if (!is_array($cachedRoutes)) {
				throw new RuntimeException(sprintf('Invalid routes cache file "%s"', $cacheFile));
			}

			if (($cachedRoutes[4] ?? null) === self::CACHE_FORMAT_VERSION) {
				// Production hot path: load the cache without touching the controllers directory.
				if (!$scanForModifiedControllers) {
					$this->setCompiledRoutes($cachedRoutes);
					return;
				}

				$controllerFiles = $this->findControllerFiles($controllersPath);
				$controllersQuickSignature = $this->getControllersQuickSignature($controllersPath, $controllerFiles);
				if (($cachedRoutes[6] ?? null) === $controllersQuickSignature) {
					$this->setCompiledRoutes($cachedRoutes);
					return;
				}

				$controllersSignature = $this->getControllersSignature($controllersPath, $controllerFiles);
				if (($cachedRoutes[5] ?? null) === $controllersSignature) {
					$cachedRoutes[6] = $controllersQuickSignature;
					$this->setCompiledRoutes($cachedRoutes);
					$this->saveCacheFile($cachedRoutes, $cacheFile);
					return;
				}
			}
		}

		$controllerFiles ??= $this->findControllerFiles($controllersPath);
		if ($scanForModifiedControllers) {
			$controllersQuickSignature ??= $this->getControllersQuickSignature($controllersPath, $controllerFiles);
			$controllersSignature ??= $this->getControllersSignature($controllersPath, $controllerFiles);
		}
		$routes = $this->findRoutesFromControllers($controllerFiles);
		$routeDumper = new RoutesDumper($routes);
		$compiledRoutes = $routeDumper->getCompiledRoutes();
		$compiledRoutes[4] = self::CACHE_FORMAT_VERSION;
		if ($scanForModifiedControllers) {
			$compiledRoutes[5] = $controllersSignature;
			$compiledRoutes[6] = $controllersQuickSignature;
		}
		$this->setCompiledRoutes($compiledRoutes);

		if (!is_null($cacheFile)) $this->saveCacheFile($compiledRoutes, $cacheFile);
	}

	/**
	 * Load compiled routes and their method metadata.
	 * @param array $compiledRoutes
	 * @return void
	 */
	private function setCompiledRoutes(array $compiledRoutes): void
	{
		$this->compiledRoutes = $compiledRoutes;
		[$this->knownMethods, $this->acceptsAnyMethod] = $compiledRoutes[3] ?? [[], false];
	}

	/**
	 * Find controller PHP files in deterministic order.
	 * @param string $controllersPath
	 * @return string[]
	 */
	private function findControllerFiles(string $controllersPath): array
	{
		$files = [];

		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($controllersPath)) as $f) {
			if ($f->isFile() && $f->getExtension() === 'php') {
				$files[] = $f->getRealPath();
			}
		}

		sort($files, SORT_STRING);
		return $files;
	}

	/**
	 * Return a content-based signature that detects additions, removals and edits.
	 * @param string $controllersPath
	 * @param string[] $controllerFiles
	 * @return string
	 */
	private function getControllersSignature(string $controllersPath, array $controllerFiles): string
	{
		$context = hash_init('xxh128');
		$baseLength = strlen(rtrim($controllersPath, '/\\')) + 1;

		foreach ($controllerFiles as $file) {
			hash_update($context, substr($file, $baseLength) . "\0");
			if (!hash_update_file($context, $file)) {
				throw new RuntimeException(sprintf('Failed to hash controller "%s"', $file));
			}
			hash_update($context, "\0");
		}

		return hash_final($context);
	}

	/**
	 * Return a metadata-only signature used to avoid reading unchanged controller files.
	 * @param string $controllersPath
	 * @param string[] $controllerFiles
	 */
	private function getControllersQuickSignature(string $controllersPath, array $controllerFiles): string
	{
		$context = hash_init('xxh128');
		$baseLength = strlen(rtrim($controllersPath, '/\\')) + 1;

		foreach ($controllerFiles as $file) {
			clearstatcache(true, $file);
			$metadata = @stat($file);
			if ($metadata === false) {
				throw new RuntimeException(sprintf('Failed to stat controller "%s"', $file));
			}
			hash_update($context, substr($file, $baseLength) . "\0" . $metadata['size'] . "\0" . $metadata['mtime'] . "\0");
		}

		return hash_final($context);
	}

	/**
	 * Save the cache file
	 * @param array $compiledRoutes
	 * @param string $cacheFile
	 * @return void
	 */
	private function saveCacheFile(array $compiledRoutes, string $cacheFile): void
	{
		$cacheDir = dirname($cacheFile);

		if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0770, true) && !is_dir($cacheDir)) {
			throw new RuntimeException(sprintf('Cache directory "%s" was not created', $cacheDir));
		}

		$tmpFile = tempnam($cacheDir, 'cache_');
		if ($tmpFile === false) {
			throw new RuntimeException(sprintf('Failed to create the tmp cache file'));
		}

		try {
			if (file_put_contents($tmpFile, '<?php return ' . RoutesDumper::dumpArray($compiledRoutes) . ';', LOCK_EX) === false) {
				throw new RuntimeException('Failed to write the temporary routes cache file');
			}

			if (!rename($tmpFile, $cacheFile)) {
				throw new RuntimeException(sprintf('Failed to replace routes cache file "%s"', $cacheFile));
			}

			if (function_exists('opcache_invalidate')) {
				opcache_invalidate($cacheFile, true);
			}
		} finally {
			if (is_file($tmpFile)) unlink($tmpFile);
		}
	}

	/**
	 * Dump routes as string from controllers Route attributes
	 * @param string $controllersPath
	 * @return string
	 */
	public function dumpRoutesFromController(string $controllersPath): string
	{
		$routes = $this->findRoutesFromControllers($this->findControllerFiles($controllersPath));

		usort($routes, fn(Route $a, Route $b): int => strnatcmp($a->getPath(), $b->getPath()));

		$longestPath = 1;
		$longestMethods = 3;
		foreach ($routes as $r) {
			$longestMethods = max($longestMethods, array_sum(array_map('strlen', $r->getMethods())) + (count($r->getMethods()) - 1) + 2);
			$longestPath = max($longestPath, strlen($r->getPath()));
		}

		$dump = str_pad('METHOD', $longestMethods + 4, ' ', STR_PAD_RIGHT);
		$dump .= str_pad('PATH', $longestPath + 4, ' ', STR_PAD_RIGHT);
		$dump .= "ACTION\n";

		$dump .= str_pad('-------', $longestMethods + 4, ' ', STR_PAD_RIGHT);
		$dump .= str_pad('----', $longestPath + 4, ' ', STR_PAD_RIGHT);
		$dump .= "------\n";

		foreach ($routes as $r) {
			$dump .= str_pad('[' . (count($r->getMethods()) ? join('|', $r->getMethods()) : '*') . ']', $longestMethods + 4, ' ', STR_PAD_RIGHT);
			$dump .= str_pad($r->getPath(), $longestPath + 4, ' ', STR_PAD_RIGHT);
			$dump .= join('->', $r->getAction()) . "\n";
		}

		$dump .= "-------\n";
		$dump .= count($routes) . " ROUTES\n";

		return $dump;
	}

	/**
	 * Find routes in path
	 * @param string[] $controllerFiles
	 * @return Route[]
	 */
	private function findRoutesFromControllers(array $controllerFiles): array
	{
		$routes = [];

		foreach ($this->findAllClass($controllerFiles) as [$class, $file]) {
			$controller = new ReflectionClass($class);
			if (realpath($controller->getFileName() ?: '') !== realpath($file)) {
				throw new LogicException(sprintf(
					'Controller %s was loaded from "%s" instead of scanned file "%s"',
					$class,
					$controller->getFileName() ?: '[internal]',
					$file
				));
			}
			if ($controller->isAbstract()) {
				continue;
			}

			$controllerMiddlewares = $this->compileMiddlewares($controller->getAttributes(IMiddleware::class, ReflectionAttribute::IS_INSTANCEOF));
			$controllerValidated = false;

			foreach ($controller->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $controller->getName()) {
					continue;
				}

				$routeAttributes = $method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);
				if (!$routeAttributes) continue;

				$this->validateControllerAction($method);
				if (!$controllerValidated) {
					$this->validateController($controller);
					$controllerValidated = true;
				}

				foreach ($routeAttributes as $attribute) {
					$route = $attribute->newInstance();
					$this->validateRouteParameters($route, $method);
					$route->setAction([$controller->getName(), $method->getName()]);
					$route->setExecutionMetadata(
						array_merge($controllerMiddlewares, $this->compileMiddlewares($method->getAttributes(IMiddleware::class, ReflectionAttribute::IS_INSTANCEOF))),
						$this->compileArgumentConverters($route, $method)
					);
					$routes[] = $route;
				}
			}
		}

		return $routes;
	}

	private function validateController(ReflectionClass $controller): void
	{
		if (!$controller->isInstantiable()) {
			throw new LogicException(sprintf('Controller %s must be instantiable', $controller->getName()));
		}

		$constructor = $controller->getConstructor();
		if ($constructor && $constructor->getNumberOfRequiredParameters() > 0) {
			throw new LogicException(sprintf(
				'Controller %s constructor must not require arguments',
				$controller->getName()
			));
		}
	}

	private function validateControllerAction(ReflectionMethod $method): void
	{
		$action = $method->getDeclaringClass()->getName() . '::' . $method->getName() . '()';
		if ($method->isConstructor() || $method->isDestructor()) {
			throw new LogicException(sprintf('Controller action %s must not be a constructor or destructor', $action));
		}
		if ($method->isStatic()) {
			throw new LogicException(sprintf('Controller action %s must not be static', $action));
		}
	}

	/**
	 * Compile attribute construction so middleware discovery needs no reflection at runtime.
	 * @param ReflectionAttribute[] $attributes
	 * @return array<int,array{string,array}>
	 */
	private function compileMiddlewares(array $attributes): array
	{
		return array_map(
			static fn(ReflectionAttribute $attribute): array => [$attribute->getName(), $attribute->getArguments()],
			$attributes
		);
	}

	/** @return array<string,string> */
	private function compileArgumentConverters(Route $route, ReflectionMethod $method): array
	{
		$converters = [];
		$variables = array_fill_keys($route->compile()->getPathVariables(), true);

		foreach ($method->getParameters() as $parameter) {
			if (!isset($variables[$parameter->getName()])) continue;
			$converter = $this->getParameterConverter($parameter, $method);
			if ($converter !== null) $converters[$parameter->getName()] = $converter;
		}

		return $converters;
	}

	private function getParameterConverter(ReflectionParameter $parameter, ReflectionMethod $method): ?string
	{
		$type = $parameter->getType();
		if ($type === null) return null;

		$types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
		foreach ($types as $namedType) {
			if ($namedType instanceof ReflectionNamedType && $namedType->isBuiltin() && in_array($namedType->getName(), ['string', 'mixed'], true)) {
				return null;
			}
		}

		$convertible = [];
		foreach ($types as $namedType) {
			if (!$namedType instanceof ReflectionNamedType || !$namedType->isBuiltin()) {
				$this->throwIncompatibleRouteParameter($parameter, $method);
			}

			$name = $namedType->getName();
			if ($name === 'null') continue;
			if (!in_array($name, ['int', 'float', 'bool'], true)) {
				$this->throwIncompatibleRouteParameter($parameter, $method);
			}
			$convertible[$name] = true;
		}

		if (count($convertible) === 1) return array_key_first($convertible);
		$this->throwIncompatibleRouteParameter($parameter, $method);
	}

	private function throwIncompatibleRouteParameter(ReflectionParameter $parameter, ReflectionMethod $method): never
	{
		throw new LogicException(sprintf(
			'Route parameter "$%s" of %s::%s() must be untyped, string, mixed, a scalar int/float/bool type, or an unambiguous nullable scalar union',
			$parameter->getName(),
			$method->getDeclaringClass()->getName(),
			$method->getName()
		));
	}

	/**
	 * Ensure captured variables can be passed as named controller arguments.
	 */
	private function validateRouteParameters(Route $route, ReflectionMethod $method): void
	{
		$variables = array_fill_keys($route->compile()->getPathVariables(), true);
		$parameters = [];

		foreach ($method->getParameters() as $parameter) {
			$parameters[$parameter->getName()] = true;
			if (isset($variables[$parameter->getName()]) && $parameter->isPassedByReference()) {
				throw new LogicException(sprintf(
					'Route parameter "$%s" of %s::%s() must not be passed by reference',
					$parameter->getName(),
					$method->getDeclaringClass()->getName(),
					$method->getName()
				));
			}
			if (isset($variables[$parameter->getName()]) && $parameter->isVariadic()) {
				throw new LogicException(sprintf(
					'Variadic parameter "$%s" of %s::%s() cannot be populated by a route variable',
					$parameter->getName(),
					$method->getDeclaringClass()->getName(),
					$method->getName()
				));
			}
			if (!$parameter->isOptional() && !$parameter->isVariadic() && !isset($variables[$parameter->getName()])) {
				throw new LogicException(sprintf(
					'Required parameter "$%s" of %s::%s() is missing from route pattern "%s"',
					$parameter->getName(),
					$method->getDeclaringClass()->getName(),
					$method->getName(),
					$route->getPath()
				));
			}
		}

		foreach ($variables as $variable => $_) {
			if (!isset($parameters[$variable])) {
				throw new LogicException(sprintf(
					'Route variable "%s" has no matching parameter in %s::%s()',
					$variable,
					$method->getDeclaringClass()->getName(),
					$method->getName()
				));
			}
		}
	}

	/**
	 * Find all class in a folder
	 * @param string[] $controllerFiles
	 * @return array<int,array{string,string}>
	 */
	private function findAllClass(array $controllerFiles): array
	{
		$types = [];

		foreach ($controllerFiles as $file) {
			$fileTypes = [];
			$namespace = '';
			$content = file_get_contents($file);
			if ($content === false) {
				throw new RuntimeException(sprintf('Failed to read controller "%s"', $file));
			}
			$tokens = token_get_all($content);
			$numTokens = count($tokens);

			for ($i = 0; $i < $numTokens; $i++) {
				// Skip literals
				if (is_string($tokens[$i])) continue;

				switch ($tokens[$i][0]) {
					case T_NAMESPACE:
						$namespace = '';

						// Ignore whitespace between the namespace keyword and the namespace itself
						do {
							$i++;
						} while (isset($tokens[$i][0]) && $tokens[$i][0] === T_WHITESPACE);

						// Collect the namespace
						while (isset($tokens[$i][0]) && in_array($tokens[$i][0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NS_SEPARATOR, T_STRING], true)) {
							$namespace .= $tokens[$i][1];
							$i++;
						}
						break;

					case T_CLASS:
						while (++$i < $numTokens) {
							if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
								continue;
							}
							if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
								$fileTypes[] = ltrim($namespace . '\\' . $tokens[$i][1], '\\');
							}
							break;
						}
						break;
				}
			}

			if (count($fileTypes) > 1) {
				throw new LogicException(sprintf('Controller file "%s" must declare at most one named class', $file));
			}
			if ($fileTypes) {
				$types[] = [$fileTypes[0], $file];
			}
		}

		return $types;
	}

	/**
	 * Match pathinfo
	 * @param string $pathinfo
	 * @return array
	 */
	private function findRoute(string $pathinfo, string $requestMethod): array
	{
		$allow = [];

		if ($ret = $this->doMatch($pathinfo, $requestMethod, $allow)) {
			return $ret;
		}

		if ($allow) {
			throw new MethodNotAllowedException(array_keys($allow));
		}

		throw new ResourceNotFoundException(sprintf('No routes found for "%s".', $pathinfo));
	}

	/**
	 * Match a HEAD route, falling back to GET when no explicit HEAD route exists.
	 * @param string $pathinfo
	 * @return array
	 */
	private function findHeadRoute(string $pathinfo): array
	{
		$allow = [];
		$pathMatched = false;

		if ($ret = $this->doSpecialMatch($pathinfo, 'HEAD', $allow, $pathMatched, false)) {
			return $ret;
		}

		// RFC 9110 defines HEAD as GET without response content. Execute the matching
		// GET (or Any) route so it can produce the same status and headers; run()'s
		// output buffer suppresses only the response body.
		$getAllow = [];
		$getPathMatched = false;
		if ($ret = $this->doSpecialMatch($pathinfo, 'GET', $getAllow, $getPathMatched)) {
			return $ret;
		}

		if ($pathMatched) {
			throw new MethodNotAllowedException(array_keys($allow));
		}

		throw new ResourceNotFoundException(sprintf('No routes found for "%s".', $pathinfo));
	}

	/**
	 * Match an explicit OPTIONS route or generate an automatic empty response.
	 * @param string $pathinfo
	 * @return array
	 */
	private function findOptionsRoute(string $pathinfo): array
	{
		if ($pathinfo === '*') {
			header('Allow: ' . join(', ', $this->normalizeAllowedMethods($this->getDeclaredMethods())));
			http_response_code(204);
			die();
		}

		$allow = [];
		$pathMatched = false;
		$this->doSpecialMatch($pathinfo, "\0", $allow, $pathMatched, false);

		if (!$pathMatched) {
			throw new ResourceNotFoundException(sprintf('No routes found for "%s".', $pathinfo));
		}

		$optionsAllow = [];
		$optionsPathMatched = false;
		if ($ret = $this->doSpecialMatch($pathinfo, 'OPTIONS', $optionsAllow, $optionsPathMatched, false)) {
			header('Allow: ' . join(', ', $this->normalizeAllowedMethods(array_keys($allow))));
			return $ret;
		}

		// Any means every request method, including OPTIONS. An Any controller is
		// responsible for its own OPTIONS response because its accepted method set
		// cannot be represented by a finite Allow header.
		$anyAllow = [];
		$anyPathMatched = false;
		if ($ret = $this->doSpecialMatch($pathinfo, 'OPTIONS', $anyAllow, $anyPathMatched)) {
			return $ret;
		}

		header('Allow: ' . join(', ', $this->normalizeAllowedMethods(array_keys($allow))));
		http_response_code(204);
		die();
	}

	/**
	 * Complete and order the methods advertised in an Allow header.
	 * @param string[] $methods
	 * @return string[]
	 */
	private function normalizeAllowedMethods(array $methods): array
	{
		$allowed = array_fill_keys($methods, true);
		if (isset($allowed['GET'])) {
			$allowed['HEAD'] = true;
		}
		$allowed['OPTIONS'] = true;

		$ordered = [];
		foreach ($this->getDeclaredMethods() as $method) {
			if (isset($allowed[$method])) {
				$ordered[] = $method;
				unset($allowed[$method]);
			}
		}

		return array_merge($ordered, array_keys($allowed));
	}

	/**
	 * Return methods explicitly declared by the application in a stable order.
	 * @return string[]
	 */
	private function getDeclaredMethods(): array
	{
		$methods = $this->knownMethods;
		$ordered = [];

		foreach (self::STANDARD_METHODS as $method) {
			if (isset($methods[$method])) {
				$ordered[] = $method;
				unset($methods[$method]);
			}
		}

		return array_merge($ordered, array_keys($methods));
	}

	/**
	 * Fast route matching path used by ordinary HTTP methods.
	 * @param string $pathinfo
	 * @param string $requestMethod
	 * @param array $allow
	 * @return null|array
	 */
	private function doMatch(string $pathinfo, string $requestMethod, array &$allow = []): ?array
	{
		$allow = [];

		foreach ($this->compiledRoutes[0][$pathinfo] ?? [] as [$ret, $requiredMethods]) {
			if ($requiredMethods && !isset($requiredMethods[$requestMethod])) {
				$allow += $requiredMethods;
				continue;
			}

			return $ret;
		}

		$matchedPathinfo = $pathinfo;

		foreach ($this->compiledRoutes[1] as $offset => $regex) {
			while (preg_match($regex, $matchedPathinfo, $matches)) {
				foreach ($this->compiledRoutes[2][$m = (int) $matches['MARK']] as $r) {
					if (is_null($r)) {
						continue 3;
					}

					[$ret, $requiredMethods, $vars] = $r;

					if ($requiredMethods && !isset($requiredMethods[$requestMethod])) {
						$allow += $requiredMethods;
						continue;
					}

					foreach ($vars as $i => $v) {
						if (isset($matches[1 + $i])) {
							$ret[$v] = $matches[1 + $i];
						}
					}

					return $ret;
				}

				$regex = substr_replace($regex, 'F', $m - $offset, 1 + strlen(strval($m)));
				$offset += strlen(strval($m));
			}
		}

		return null;
	}

	/**
	 * Route matching with method and path-state controls for HEAD and OPTIONS.
	 * Kept separate from doMatch to avoid adding special-method bookkeeping to the hot path.
	 * @param string $pathinfo
	 * @param string $requestMethod
	 * @param array $allow
	 * @param bool $pathMatched
	 * @param bool $matchAny
	 * @return null|array
	 */
	private function doSpecialMatch(string $pathinfo, string $requestMethod, array &$allow, bool &$pathMatched, bool $matchAny = true): ?array
	{
		$allow = [];
		$pathMatched = false;

		foreach ($this->compiledRoutes[0][$pathinfo] ?? [] as [$ret, $requiredMethods]) {
			$pathMatched = true;

			if (!$requiredMethods && !$matchAny) {
				continue;
			}

			if ($requiredMethods && !isset($requiredMethods[$requestMethod])) {
				$allow += $requiredMethods;
				continue;
			}

			return $ret;
		}

		$matchedPathinfo = $pathinfo;

		foreach ($this->compiledRoutes[1] as $offset => $regex) {
			while (preg_match($regex, $matchedPathinfo, $matches)) {
				foreach ($this->compiledRoutes[2][$m = (int) $matches['MARK']] as $r) {
					if (is_null($r)) { // marks the last route in the regexp
						continue 3;
					}

					[$ret, $requiredMethods, $vars] = $r;
					$pathMatched = true;

					if (!$requiredMethods && !$matchAny) {
						continue;
					}

					if ($requiredMethods && !isset($requiredMethods[$requestMethod])) {
						$allow += $requiredMethods;
						continue;
					}

					foreach ($vars as $i => $v) {
						if (isset($matches[1 + $i])) {
							$ret[$v] = $matches[1 + $i];
						}
					}

					return $ret;
				}

				$regex = substr_replace($regex, 'F', $m - $offset, 1 + strlen(strval($m)));
				$offset += strlen(strval($m));
			}
		}

		return null;
	}
}
