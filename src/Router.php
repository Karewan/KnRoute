<?php

declare(strict_types=1);

namespace Karewan\KnRoute;

use Karewan\KnRoute\Attributes\Route;
use Karewan\KnRoute\Dumper\RoutesDumper;
use Karewan\KnRoute\Exceptions\HttpException;
use Karewan\KnRoute\Exceptions\MethodNotAllowedException;
use Karewan\KnRoute\Exceptions\MiddlewareExecutionException;
use Karewan\KnRoute\Exceptions\ResourceNotFoundException;
use Karewan\KnRoute\Exceptions\StopRequestException;
use Karewan\KnRoute\Routes\MiddlewareValidator;
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
use Throwable;

class Router
{
	/** @var string[] */
	private const array STANDARD_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
	private const int CACHE_FORMAT_VERSION = 1;

	/**
	 * Compiled routes
	 * @var array
	 */
	private array $compiledRoutes = [];

	/** @var array<string,int> */
	private array $knownMethods = [];

	private bool $acceptsAnyMethod = false;

	/** @var string[] */
	private array $cacheSymbols = [];

	/** @var IMiddleware[] */
	private array $globalMiddlewares = [];

	/** @var array<int,Closure> */
	private array $errorHandlers = [];

	private ?Closure $defaultErrorHandler = null;

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
	 * @return void
	 */
	public function run(): void
	{
		$this->matchedController = null;
		$this->matchedMethod = null;

		$requestMethod = HttpUtils::getMethod();
		$headOutputBufferLevel = null;

		try {
			if ($requestMethod === 'HEAD') {
				// Suppress output from every part of the request, including global middleware hooks.
				$headOutputBufferLevel = ob_get_level();
				ob_start(static fn(): string => '');
			}

			if (!$this->globalMiddlewares) {
				$this->dispatch($requestMethod);
			} else {
				$this->runMiddlewareStack($this->globalMiddlewares, $requestMethod);
			}
		} catch (MiddlewareExecutionException $e) {
			// HTTP errors are rendered while the stack unwinds; anything left is
			// an application failure, reported without the internal wrapper.
			throw $e->getMiddlewareException();
		} finally {
			if (!is_null($headOutputBufferLevel)) {
				while (ob_get_level() > $headOutputBufferLevel) ob_end_clean();
			}
		}
	}

	private function dispatch(string $requestMethod): void
	{
		try {
			// The route. Keep ordinary methods on the shortest possible hot path.
			switch ($requestMethod) {
			case 'HEAD':
				// A HEAD response must never contain a body, including for explicit HEAD routes.
				$route = $this->findHeadRoute(HttpUtils::getPath());
				break;

			case 'OPTIONS':
				// OPTIONS responses are not cacheable.
				header('Cache-Control: no-store');
				$route = $this->findOptionsRoute(HttpUtils::getPath());
				if (is_null($route)) return;
				break;

			case 'GET':
			case 'POST':
			case 'PUT':
			case 'PATCH':
			case 'DELETE':
				$route = $this->findRoute(HttpUtils::getPath(), $requestMethod);
				break;

			default:
				if (!$this->acceptsAnyMethod && !isset($this->knownMethods[$requestMethod])) {
					$this->handleHttpError(501);
					return;
				}
				$route = $this->findRoute(HttpUtils::getPath(), $requestMethod);
			}

			// The controller, method and execution metadata are all precompiled in the route cache.
			$this->matchedController = $this->cacheSymbols[$route[0]];
			$this->matchedMethod = $route[1];
			$metadata = $route[2] ?? [];
			unset($route[0], $route[1], $route[2]);

			$middlewareDefinitions = $argumentConverters = [];
			if ($metadata) {
				if (!array_is_list($metadata)) {
					$argumentConverters = $metadata;
				} elseif (isset($metadata[1]) && is_array($metadata[1]) && !array_is_list($metadata[1])) {
					[$middlewareDefinitions, $argumentConverters] = $metadata;
				} else {
					$middlewareDefinitions = $metadata;
				}
			}

			$middlewares = [];
			foreach ($middlewareDefinitions as $definition) {
				if (is_int($definition)) {
					$middleware = $this->cacheSymbols[$definition];
					$middlewares[] = new $middleware;
				} else {
					[$middlewareId, $arguments] = $definition;
					$middleware = $this->cacheSymbols[$middlewareId];
					$middlewares[] = new $middleware(...$arguments);
				}
			}

			foreach ($route as $name => $value) {
				$value = rawurldecode($value);
				$route[$name] = match ($argumentConverters[$name] ?? null) {
					0 => (int) $value,
					1 => (float) $value,
					2 => (bool) $value,
					default => $value,
				};
			}

			if (!$middlewares) {
				$this->executeController($route);
			} else {
				$this->runMiddlewareStack($middlewares, $route);
			}
		} catch (StopRequestException) {
			// An action without route middlewares produced the response itself.
		} catch (MethodNotAllowedException $e) {
			$this->handleHttpError(405, headers: [
				'Allow' => join(', ', $this->normalizeAllowedMethods($e->getAllowedMethods())),
			]);
		} catch (ResourceNotFoundException) {
			$this->handleHttpError(404);
		} catch (HttpException $e) {
			$this->handleHttpException($e);
		}
	}

	/** Execute in its own scope so controller destruction precedes error handling. */
	private function executeController(array $arguments): void
	{
		$controllerInstance = new $this->matchedController;
		$controllerInstance->{$this->matchedMethod}(...$arguments);
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
		$this->globalMiddlewares[] = $middleware;
	}

	/** @param callable(HttpError):void $handler */
	public function setErrorHandler(int $code, callable $handler): void
	{
		$this->assertErrorStatusCode($code);
		$this->errorHandlers[$code] = Closure::fromCallable($handler);
	}

	/** @param callable(HttpError):void $handler */
	public function setDefaultErrorHandler(callable $handler): void
	{
		$this->defaultErrorHandler = Closure::fromCallable($handler);
	}

	private function handleHttpException(HttpException $exception): void
	{
		$this->handleHttpError(
			$exception->getStatusCode(),
			$exception->getTitle(),
			$exception->getDetail(),
			$exception->getHeaders(),
		);
	}

	/** @param array<string,string> $headers */
	private function handleHttpError(int $code, ?string $title = null, ?string $detail = null, array $headers = []): void
	{
		$this->assertErrorStatusCode($code);
		http_response_code($code);
		foreach ($headers as $name => $value) header("{$name}: {$value}");

		$handler = $this->errorHandlers[$code] ?? $this->defaultErrorHandler;
		if (is_null($handler)) return;

		$handler(new HttpError(
			$code,
			$title ?? HttpStatus::getTitle($code),
			$detail ?? HttpStatus::getDetail($code),
			$headers,
		));
	}

	private function assertErrorStatusCode(int $code): void
	{
		if ($code < 400 || $code > 599) {
			throw new \InvalidArgumentException('An HTTP error status code must be between 400 and 599.');
		}
	}

	/**
	 * Execute hooks around request dispatch (method string) or a controller (argument array).
	 * @param IMiddleware[] $middlewares
	 */
	private function runMiddlewareStack(array $middlewares, string|array $action): void
	{
		$started = 0;
		$exception = null;

		foreach ($middlewares as $middleware) {
			try {
				$middleware->before();
			} catch (Throwable $e) {
				// A failed before hook cancels the action, never the unwinding.
				$exception = new MiddlewareExecutionException($e);
				break;
			}
			$started++;
		}

		if (is_null($exception)) {
			try {
				if (is_string($action)) $this->dispatch($action);
				else $this->executeController($action);
			} catch (Throwable $e) {
				$exception = $e;
			}
		}

		if (!is_null($exception)) {
			$raised = $exception instanceof MiddlewareExecutionException
				? $exception->getMiddlewareException()
				: $exception;

			if ($raised instanceof StopRequestException) {
				// The hook or the action produced the response itself.
				$exception = null;
			} elseif ($raised instanceof HttpException) {
				// Render the error response before unwinding, so after hooks observe
				// the status the client will receive instead of the initial one.
				$this->handleHttpException($raised);
				$exception = null;
			}
		}

		// Every middleware whose before hook completed gets its after hook, whatever
		// happened afterwards. A middleware whose own before hook failed does not:
		// it never finished setting up what its after hook would tear down.
		while ($started-- > 0) {
			try {
				$middlewares[$started]->after();
			} catch (StopRequestException) {
				// The after hook produced the response itself; keep unwinding.
			} catch (HttpException $e) {
				// An after hook may still replace the response with an error.
				$this->handleHttpException($e);
			} catch (Throwable $e) {
				$exception = new MiddlewareExecutionException($e, $exception);
			}
		}

		if (!is_null($exception)) throw $exception;
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

			if (($cachedRoutes[5] ?? null) === self::CACHE_FORMAT_VERSION) {
				// Production hot path: load the cache without touching the controllers directory.
				if (!$scanForModifiedControllers) {
					$this->setCompiledRoutes($cachedRoutes);
					return;
				}

				$controllerFiles = $this->findControllerFiles($controllersPath);
				$controllersQuickSignature = $this->getControllersQuickSignature($controllersPath, $controllerFiles);
				if (($cachedRoutes[7] ?? null) === $controllersQuickSignature) {
					$this->setCompiledRoutes($cachedRoutes);
					return;
				}

				$controllersSignature = $this->getControllersSignature($controllersPath, $controllerFiles);
				if (($cachedRoutes[6] ?? null) === $controllersSignature) {
					$cachedRoutes[7] = $controllersQuickSignature;
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
		$compiledRoutes[5] = self::CACHE_FORMAT_VERSION;
		if ($scanForModifiedControllers) {
			$compiledRoutes[6] = $controllersSignature;
			$compiledRoutes[7] = $controllersQuickSignature;
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
		$this->cacheSymbols = $compiledRoutes[4] ?? [];
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
		$middlewareValidator = new MiddlewareValidator();

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

			$controllerMiddlewares = $this->compileMiddlewares($controller->getAttributes(IMiddleware::class, ReflectionAttribute::IS_INSTANCEOF), $middlewareValidator);
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

				$methodMiddlewares = null;
				foreach ($routeAttributes as $attribute) {
					$route = $attribute->newInstance();
					$this->validateRouteParameters($route, $method);
					$route->setAction([$controller->getName(), $method->getName()]);
					$route->setExecutionMetadata(
						array_merge($controllerMiddlewares, $methodMiddlewares ??= $this->compileMiddlewares($method->getAttributes(IMiddleware::class, ReflectionAttribute::IS_INSTANCEOF), $middlewareValidator)),
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
	 * @return array<int,string|array{string,array}>
	 */
	private function compileMiddlewares(array $attributes, MiddlewareValidator $validator): array
	{
		$middlewares = [];
		foreach ($attributes as $attribute) {
			// Validate without instantiating: missing or incompatible constructor
			// arguments cannot survive into a production route cache, and middleware
			// constructors only run for the dispatched route.
			$validator->validate($attribute);
			$arguments = $attribute->getArguments();
			$middlewares[] = $arguments ? [$attribute->getName(), $arguments] : $attribute->getName();
		}

		return $middlewares;
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
	 * @return null|array
	 */
	private function findOptionsRoute(string $pathinfo): ?array
	{
		if ($pathinfo === '*') {
			header('Allow: ' . join(', ', $this->normalizeAllowedMethods($this->getDeclaredMethods())));
			http_response_code(204);
			return null;
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
		return null;
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
			if (is_string($requiredMethods) ? $requiredMethods !== $requestMethod : ($requiredMethods && !isset($requiredMethods[$requestMethod]))) {
				if (is_string($requiredMethods)) $allow[$requiredMethods] = true;
				else $allow += $requiredMethods;
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

					if (is_string($requiredMethods) ? $requiredMethods !== $requestMethod : ($requiredMethods && !isset($requiredMethods[$requestMethod]))) {
						if (is_string($requiredMethods)) $allow[$requiredMethods] = true;
						else $allow += $requiredMethods;
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

			if (is_null($requiredMethods) && !$matchAny) {
				continue;
			}

			if (is_string($requiredMethods) ? $requiredMethods !== $requestMethod : ($requiredMethods && !isset($requiredMethods[$requestMethod]))) {
				if (is_string($requiredMethods)) $allow[$requiredMethods] = true;
				else $allow += $requiredMethods;
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

					if (is_null($requiredMethods) && !$matchAny) {
						continue;
					}

					if (is_string($requiredMethods) ? $requiredMethods !== $requestMethod : ($requiredMethods && !isset($requiredMethods[$requestMethod]))) {
						if (is_string($requiredMethods)) $allow[$requiredMethods] = true;
						else $allow += $requiredMethods;
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
