<?php

declare(strict_types=1);

namespace Karewan\KnRoute;

use Brick\VarExporter\VarExporter;
use DomainException;
use ErrorException;
use Exception;
use Karewan\KnRoute\Attributes\Controller;
use Karewan\KnRoute\Attributes\Route;
use Karewan\KnRoute\Dumper\RoutesDumper;
use Karewan\KnRoute\Exceptions\MethodNotAllowedException;
use Karewan\KnRoute\Exceptions\ResourceNotFoundException;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

class Router
{
	/**
	 * Compiled routes
	 * @var array
	 */
	private array $compiledRoutes = [];

	/**
	 * Register routes from controllers Route attributes
	 * @param string $path
	 * @param null|string $cacheFile
	 * @return void
	 * @throws LogicException
	 * @throws DomainException
	 * @throws ErrorException
	 * @throws Exception
	 */
	public function registerRoutesFromControllers(string $path, ?string $cacheFile)
	{
		if (!is_null($cacheFile) && is_file($cacheFile)) {
			$this->compiledRoutes = require $cacheFile;
			return;
		}

		/** @var Route[] */
		$routes = [];

		foreach ($this->findAllClass($path) as $class) {
			$controller = new ReflectionClass($class);

			foreach ($controller->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				$attributes = $method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);
				if (empty($attributes)) continue;

				$route = $attributes[0]->newInstance();
				$route->setAction([$controller->getName(), $method->getName()]);

				$routes[] = $route;
			}
		}

		$this->compiledRoutes = (new RoutesDumper($routes))->getCompiledRoutes();

		if (!is_null($cacheFile) && (is_dir($cacheDir = dirname($cacheFile)) || mkdir($cacheDir, 0770, true))) {
			file_put_contents(
				$cacheFile,
				'<?php return ' . str_replace(' ', '', str_replace(PHP_EOL, '', VarExporter::export($this->compiledRoutes))) . ';'
			);
		}
	}

	/**
	 * Run
	 * @return never
	 */
	public function run(): never
	{
		header('Content-Type:');

		try {
			// The route
			$route = $this->match(HttpUtils::getPath());

			// The controller and method
			$controller = array_shift($route);
			$method =  array_shift($route);

			// Reflect the controller class
			$class = new ReflectionClass($controller);

			// Handle controller middlewares
			$classAttributes = $class->getAttributes(Controller::class);
			if (!empty($classAttributes)) {
				foreach ($classAttributes[0]->getArguments()[0] as $classMiddleware) {
					(new $classMiddleware)->handle();
				}
			}

			// Instantiate the controller
			$controllerInstance = new $controller;

			// Handle method middlewares
			$methodAttributes = $class->getMethod($method)->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);
			if (!empty($methodAttributes)) {
				$methodArguments = $methodAttributes[0]->getArguments();
				foreach ($methodArguments[count($methodArguments) > 2 ? 2 : 1] ?? [] as $methodMiddleware) {
					(new $methodMiddleware)->handle();
				}
			}

			// Call the method
			call_user_func_array([$controllerInstance, $method], $route);
		} catch (MethodNotAllowedException $e) {
			header('Allow:' . join(', ', $e->getAllowedMethods()));

			if ($_SERVER['REQUEST_METHOD'] == 'HEAD') {
				header('Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate, max-age=0');
				header('Pragma: no-cache');
				header('Expires: 0');
			} else if ($_SERVER['REQUEST_METHOD'] != 'OPTIONS') {
				http_response_code(405);
			}
		} catch (ResourceNotFoundException $e) {
			http_response_code(404);
		}

		die();
	}

	/**
	 * Match pathinfo
	 * @param string $pathinfo
	 * @return array
	 */
	private function match(string $pathinfo): array
	{
		$allow = [];

		if ($ret = $this->doMatch($pathinfo, $allow)) {
			return $ret;
		}

		if ($allow) {
			throw new MethodNotAllowedException(array_keys($allow));
		}

		throw new ResourceNotFoundException(sprintf('No routes found for "%s".', $pathinfo));
	}

	/**
	 * Do match
	 * @param string $pathinfo
	 * @param array $allow
	 * @return null|array
	 */
	private function doMatch(string $pathinfo, array &$allow = []): ?array
	{
		$allow = [];
		$requestMethod = $_SERVER['REQUEST_METHOD'];


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
					if (is_null($r)) { // marks the last route in the regexp
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
}
