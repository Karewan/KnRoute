<?php

declare(strict_types=1);

namespace Karewan\KnRoute;

use Karewan\KnRoute\Attributes\Route;
use Karewan\KnRoute\Dumper\RoutesDumper;
use Karewan\KnRoute\Exceptions\MethodNotAllowedException;
use Karewan\KnRoute\Exceptions\ResourceNotFoundException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

class Router
{
	/** @var string[] */
	private const array HTTP_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'CONNECT', 'OPTIONS', 'TRACE'];

	/**
	 * Compiled routes
	 * @var array
	 */
	private array $compiledRoutes = [];

	/**
	 * The finded controller
	 * @var null|string
	 */
	private ?string $findedController = null;

	/**
	 * The finded method
	 * @var null|string
	 */
	private ?string $findedMethod = null;

	/**
	 * Run
	 * @return never
	 */
	public function run(): never
	{
		// Remove content-type by default (if not output => not content type)
		header('Content-Type:');

		// Set a constant to check if current request is XHR
		define('IS_XHR', HttpUtils::getHeader('X-Requested-With') == 'XMLHttpRequest');
		$requestMethod = $_SERVER['REQUEST_METHOD'];

		try {
			// The route. Keep ordinary methods on the shortest possible hot path.
			switch ($requestMethod) {
				case 'HEAD':
					// A HEAD response must never contain a body, including for explicit HEAD routes.
					ob_start(static fn(): string => '');
					$route = $this->findHeadRoute(HttpUtils::getPath());
					break;

				case 'OPTIONS':
					// OPTIONS responses are not cacheable.
					header('Cache-Control: no-store');
					$route = $this->findOptionsRoute(HttpUtils::getPath());
					break;

				default:
					$route = $this->findRoute(HttpUtils::getPath(), $requestMethod);
			}

			// The controller and method
			$this->findedController = array_shift($route);
			$this->findedMethod =  array_shift($route);

			// Reflect the controller class
			$controllerClass = new ReflectionClass($this->findedController);

			// Handle controller middlewares
			foreach ($controllerClass->getAttributes(IMiddleware::class, ReflectionAttribute::IS_INSTANCEOF) as $controllerAttribute) {
				$controllerAttribute->newInstance()->handle();
			}

			// Instantiate the controller
			$controllerInstance = new $this->findedController;

			// Reflect the controller method
			$controllerMethod = $controllerClass->getMethod($this->findedMethod);

			// Handle method middlewares
			foreach ($controllerMethod->getAttributes(IMiddleware::class, ReflectionAttribute::IS_INSTANCEOF) as $methodAttribute) {
				$methodAttribute->newInstance()->handle();
			}

			// Call the method
			$controllerMethod->invokeArgs($controllerInstance, array_map(fn(string $p): string => urldecode($p), $route));
		} catch (MethodNotAllowedException $e) {
			header('Allow: ' . join(', ', $this->normalizeAllowedMethods($e->getAllowedMethods())));
			http_response_code(405);
		} catch (ResourceNotFoundException $e) {
			http_response_code(404);
		}

		die();
	}

	/**
	 * Return the finded controller
	 * @return null|string
	 */
	public function getFindedController(): ?string
	{
		return $this->findedController;
	}

	/**
	 * Return the finded method
	 * @return null|string
	 */
	public function getFindedMethod(): ?string
	{
		return $this->findedMethod;
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
		if (
			!is_null($cacheFile) &&
			is_file($cacheFile) &&
			(!$scanForModifiedControllers || !$this->hasModifiedControllers($controllersPath, $cacheFile))
		) {
			$this->compiledRoutes = require $cacheFile;
			return;
		}

		$routes = $this->findRoutesFromControllers($controllersPath);
		$routeDumper = new RoutesDumper($routes);
		$this->compiledRoutes = $routeDumper->getCompiledRoutes();

		if (!is_null($cacheFile)) $this->saveCacheFile($routeDumper, $cacheFile);
	}

	/**
	 * Has modified controllers
	 * @param string $controllersPath
	 * @param string $cacheFile
	 * @return bool
	 */
	private function hasModifiedControllers(string $controllersPath, string $cacheFile): bool
	{
		$lastModified = 0;

		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($controllersPath)) as $f) {
			if (!$f->isFile()) continue;
			$mt = $f->getMTime();
			if ($mt > $lastModified) $lastModified = $mt;
		}

		return $lastModified > (@filemtime($cacheFile) ?: 0);
	}

	/**
	 * Save the cache file
	 * @param RoutesDumper $routeDumper
	 * @param string $cacheFile
	 * @return void
	 */
	private function saveCacheFile(RoutesDumper $routeDumper, string $cacheFile): void
	{
		$cacheDir = dirname($cacheFile);

		if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0770, true) && !is_dir($cacheDir)) {
			throw new RuntimeException(sprintf('Cache directory "%s" was not created', $cacheDir));
		}

		$tmpFile = tempnam($cacheDir, 'cache_');
		if ($tmpFile === false) {
			throw new RuntimeException(sprintf('Failed to create the tmp cache file'));
		}

		if (file_put_contents($tmpFile, '<?php return ' . $routeDumper->dumpArray($this->compiledRoutes) . ';') === false) {
			throw new RuntimeException(sprintf('Failed to write the tmp cache file'));
		}

		if (!rename($tmpFile, $cacheFile)) {
			throw new RuntimeException(sprintf('Failed to rename the tmp cache file'));
		}
	}

	/**
	 * Dump routes as string from controllers Route attributes
	 * @param string $controllersPath
	 * @return string
	 */
	public function dumpRoutesFromController(string $controllersPath): string
	{
		$routes = $this->findRoutesFromControllers($controllersPath);

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
	 * @param string $controllersPath
	 * @return Route[]
	 */
	private function findRoutesFromControllers(string $controllersPath): array
	{
		$routes = [];

		foreach ($this->findAllClass($controllersPath) as $class) {
			$controller = new ReflectionClass($class);

			foreach ($controller->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				foreach ($method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
					$route = $attribute->newInstance();
					$route->setAction([$controller->getName(), $method->getName()]);
					$routes[] = $route;
				}
			}
		}

		return $routes;
	}

	/**
	 * Find all class in a folder
	 * @param string $path
	 * @return string[]
	 */
	private function findAllClass(string $path): array
	{
		$tokens = [];
		$types = [];
		$namespace = '';

		/** @var SplFileInfo */
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
			if ($file->getExtension() !== 'php') continue;

			$tokens = token_get_all(file_get_contents($file->getRealPath()));
			$numTokens = count($tokens);

			for ($i = 0; $i < $numTokens; $i++) {
				// Skip literals
				if (is_string($tokens[$i])) continue;

				$className = '';

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

					case T_CLASS: // Scan previous tokens to see if they're double colons, which would mean this is a class constant
						for ($j = $i - 1; $j >= 0; $j--) {
							if ($tokens[$j][0] === T_DOUBLE_COLON) {
								break 2;
							}

							if ($tokens[$j][0] === T_WHITESPACE) {
								// Since we found whitespace, then we know this isn't a class constant
								// Now, check if it's an abstract class
								$isAbstract = isset($tokens[$j - 1][0]) && $tokens[$j - 1][0] === T_ABSTRACT;
								if ($isAbstract) break 2;
								break;
							}
						}

						// Get the class name
						while (isset($tokens[++$i][1])) {
							if ($tokens[$i][0] === T_STRING) {
								$className .= $tokens[$i][1];
								break;
							}
						}

						$types[] = ltrim($namespace . '\\' . $className, '\\');
						break 2;
				}
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

		if (isset($allow['GET'])) {
			$getAllow = [];
			$getPathMatched = false;
			if ($ret = $this->doSpecialMatch($pathinfo, 'GET', $getAllow, $getPathMatched, false)) {
				return $ret;
			}
		}

		// An Any route remains a valid last-resort HEAD handler.
		$anyAllow = [];
		$anyPathMatched = false;
		if ($ret = $this->doSpecialMatch($pathinfo, 'HEAD', $anyAllow, $anyPathMatched)) {
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
			header('Allow: ' . join(', ', self::HTTP_METHODS));
			http_response_code(204);
			die();
		}

		$allow = [];
		$pathMatched = false;
		$this->doSpecialMatch($pathinfo, "\0", $allow, $pathMatched, false);

		if (!$pathMatched) {
			throw new ResourceNotFoundException(sprintf('No routes found for "%s".', $pathinfo));
		}

		header('Allow: ' . join(', ', $this->normalizeAllowedMethods(array_keys($allow))));

		$optionsAllow = [];
		$optionsPathMatched = false;
		if ($ret = $this->doSpecialMatch($pathinfo, 'OPTIONS', $optionsAllow, $optionsPathMatched, false)) {
			return $ret;
		}

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
		foreach (self::HTTP_METHODS as $method) {
			if (isset($allowed[$method])) {
				$ordered[] = $method;
				unset($allowed[$method]);
			}
		}

		return array_merge($ordered, array_keys($allowed));
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
				$allow += array_fill_keys(self::HTTP_METHODS, 0);
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
						$allow += array_fill_keys(self::HTTP_METHODS, 0);
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
