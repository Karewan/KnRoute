<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Dumper;

use ErrorException;
use Exception;
use Karewan\KnRoute\Attributes\Route;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use SplObjectStorage;
use stdClass;
use UnitEnum;

class RoutesDumper
{
	/** @var null|Exception */
	private ?Exception $signalingException = null;

	/** @var Route[] */
	private array $routes;

	/**
	 * Class constructor
	 * @param Route[] $routes
	 * @return void
	 */
	public function __construct(array $routes)
	{
		$this->routes = $routes;
		$this->validateRoutes();

		// Keep route precedence deterministic across files and PHP versions. Explicit methods
		// take precedence over Any routes, then static routes over dynamic routes.
		usort($this->routes, static fn(Route $a, Route $b): int => [
			(int) empty($a->getMethods()),
			(int) (bool) $a->compile()->getPathVariables(),
			$a->getPath(),
			implode("\0", $a->getMethods()),
			implode("\0", $a->getAction())
		] <=> [
			(int) empty($b->getMethods()),
			(int) (bool) $b->compile()->getPathVariables(),
			$b->getPath(),
			implode("\0", $b->getMethods()),
			implode("\0", $b->getAction())
		]);
	}

	/**
	 * Get compiled routes
	 * @return array
	 */
	public function getCompiledRoutes(): array
	{
		// Split static and dynamic routes, re-order when possible.
		[$staticRoutes, $dynamicRoutes] = $this->groupStaticRoutes();
		$compiledRoutes = [$this->compileStaticRoutes($staticRoutes)];
		$chunkLimit = count($dynamicRoutes);

		while (true) {
			try {
				$this->signalingException = new RuntimeException('Compilation failed: regular expression is too large');
				$compiledRoutes = array_merge($compiledRoutes, $this->compileDynamicRoutes($dynamicRoutes, $chunkLimit));

				break;
			} catch (Exception $e) {
				if (1 < $chunkLimit && $this->signalingException === $e) {
					$chunkLimit = 1 + ($chunkLimit >> 1);
					continue;
				}
				throw $e;
			}
		}

		$methods = [];
		$acceptsAnyMethod = false;
		foreach ($this->routes as $route) {
			if (!$route->getMethods()) {
				$acceptsAnyMethod = true;
				continue;
			}
			$methods += array_flip($route->getMethods());
		}
		$compiledRoutes[] = [$methods, $acceptsAnyMethod];

		return $compiledRoutes;
	}

	/**
	 * Reject ambiguous routes before generating or caching the matcher.
	 * @return void
	 */
	private function validateRoutes(): void
	{
		$seen = [];
		$validated = [];

		foreach ($this->routes as $route) {
			$regex = preg_replace('/\?P<[^>]+>/', '?:', $route->compile()->getRegex());
			$signature = $regex ?? $route->compile()->getRegex();
			$methods = $route->getMethods() ?: ['*'];

			foreach ($methods as $method) {
				if (isset($seen[$signature][$method])) {
					throw new LogicException(sprintf(
						'Conflicting %s routes "%s" and "%s"',
						$method === '*' ? 'Any' : $method,
						join('->', $seen[$signature][$method]->getAction()),
						join('->', $route->getAction())
					));
				}
				$seen[$signature][$method] = $route;
			}

			foreach ($validated as $otherRoute) {
				$commonMethods = self::commonMethods($route, $otherRoute);
				if ($commonMethods === null || !self::haveOverlappingPaths($route, $otherRoute)) continue;

				throw new LogicException(sprintf(
					'Ambiguous %s routes "%s" and "%s" can match the same path',
					$commonMethods === [] ? 'Any' : implode('|', $commonMethods),
					join('->', $otherRoute->getAction()),
					join('->', $route->getAction())
				));
			}
			$validated[] = $route;
		}
	}

	/** @return null|string[] null means that the method domains are disjoint. */
	private static function commonMethods(Route $first, Route $second): ?array
	{
		if (!$first->getMethods() && !$second->getMethods()) return [];
		// An explicit method intentionally takes precedence over an Any fallback.
		if (!$first->getMethods() || !$second->getMethods()) return null;
		$common = array_values(array_intersect($first->getMethods(), $second->getMethods()));
		return $common ?: null;
	}

	private static function haveOverlappingPaths(Route $first, Route $second): bool
	{
		// Static routes have deterministic precedence and are intentionally allowed to
		// specialize a dynamic route.
		if (!$first->compile()->getPathVariables() || !$second->compile()->getPathVariables()) return false;
		if (self::haveOverlappingVariableDomains($first, $second)) return true;

		$firstRegex = $first->compile()->getRegex();
		$secondRegex = $second->compile()->getRegex();
		foreach (self::representativePaths($first, $second) as $path) {
			if (preg_match($secondRegex, $path) === 1) return true;
		}
		foreach (self::representativePaths($second, $first) as $path) {
			if (preg_match($firstRegex, $path) === 1) return true;
		}

		return false;
	}

	/**
	 * Produce concrete paths at compile time, including the other route's static segments.
	 * This finds intersections where a broad segment/path variable consumes another route's structure.
	 * @return string[]
	 */
	private static function representativePaths(Route $route, Route $other): array
	{
		$values = ['a', 'A', '0', '1', '-1', 'a-b', 'deadbeef', '550e8400-e29b-41d4-a716-446655440000', 'a/b'];
		foreach ([$route, $other] as $candidateRoute) {
			$staticPath = preg_replace('/\{[A-Za-z_][A-Za-z0-9_]*:[a-z][a-z0-9_]*\}/', '/', $candidateRoute->getPath());
			foreach (explode('/', $staticPath ?? '') as $segment) {
				if ($segment !== '') $values[] = $segment;
			}
		}
		$values = array_values(array_unique($values));

		$parts = preg_split('/(\{[A-Za-z_][A-Za-z0-9_]*:[a-z][a-z0-9_]*\})/', $route->getPath(), flags: PREG_SPLIT_DELIM_CAPTURE);
		if ($parts === false) return [];

		$paths = [''];
		foreach ($parts as $index => $part) {
			$choices = ($index & 1) === 0 ? [$part] : $values;
			$expanded = [];
			foreach ($paths as $path) {
				foreach ($choices as $choice) {
					$expanded[] = $path . $choice;
					if (count($expanded) >= 4096) break 2;
				}
			}
			$paths = $expanded;
		}

		$regex = $route->compile()->getRegex();
		return array_values(array_filter($paths, static fn(string $path): bool => preg_match($regex, $path) === 1));
	}

	/**
	 * Detect overlapping built-in variable types for routes with the same static structure.
	 * This validation runs only while routes are compiled and adds nothing to runtime matching.
	 */
	private static function haveOverlappingVariableDomains(Route $first, Route $second): bool
	{
		$firstParts = preg_split('/\{[A-Za-z_][A-Za-z0-9_]*:([a-z][a-z0-9_]*)\}/', $first->getPath(), flags: PREG_SPLIT_DELIM_CAPTURE);
		$secondParts = preg_split('/\{[A-Za-z_][A-Za-z0-9_]*:([a-z][a-z0-9_]*)\}/', $second->getPath(), flags: PREG_SPLIT_DELIM_CAPTURE);
		if ($firstParts === false || $secondParts === false || count($firstParts) !== count($secondParts)) return false;

		for ($i = 0, $count = count($firstParts); $i < $count; $i++) {
			if (($i & 1) === 0) {
				if ($firstParts[$i] !== $secondParts[$i]) return false;
				continue;
			}
			if (!self::variableTypesOverlap($firstParts[$i], $secondParts[$i])) return false;
		}

		return true;
	}

	private static function variableTypesOverlap(string $first, string $second): bool
	{
		if ($first === $second) return true;

		$pair = [$first, $second];
		sort($pair);
		return !in_array(implode(':', $pair), [
			'alpha:int',
			'alpha:uint',
			'alpha:uuid',
			'alnum:uuid',
			'hex:uuid',
			'int:uuid',
			'uint:uuid',
		], true);
	}

	/**
	 * Dump array
	 * @param array $array
	 * @return string
	 */
	public static function dumpArray(array $array): string
	{
		$result = [];
		$count = count($array);
		$isList = array_keys($array) === range(0, $count - 1);

		foreach ($array as $key => $value) {
			switch (gettype($value)) {
				case 'NULL':
				case 'boolean':
				case 'integer':
				case 'double':
				case 'string':
					$exported = var_export($value, true);
					break;

				case 'array':
					$exported = self::dumpArray($value);
					break;

				case 'object':
					self::assertExportableObject($value, new SplObjectStorage());
					$exported = var_export($value, true);
					break;

				default:
					throw new LogicException(sprintf('Cannot export cache value of type %s', get_debug_type($value)));
			}

			$result[] = $isList ? $exported : var_export($key, true) . '=>' . $exported;
		}

		return '[' . implode(',', $result) . ']';
	}

	/**
	 * Validate that var_export() can reconstruct an object when the cache is loaded.
	 * Enums are emitted as case references. Other objects must implement the
	 * conventional public static __set_state(array $properties) factory.
	 */
	private static function assertExportableObject(object $object, SplObjectStorage $ancestors): void
	{
		if ($object instanceof UnitEnum) return;

		$class = $object::class;
		if (!method_exists($object, '__set_state')) {
			throw new LogicException(sprintf(
				'Cannot export cache value of type %s: the class must implement public static __set_state()',
				$class
			));
		}

		$setState = new ReflectionMethod($class, '__set_state');
		if (!$setState->isPublic() || !$setState->isStatic()) {
			throw new LogicException(sprintf(
				'Cannot export cache value of type %s: __set_state() must be public and static',
				$class
			));
		}

		if ($ancestors->offsetExists($object)) {
			throw new LogicException(sprintf('Cannot export cyclic cache value of type %s', $class));
		}

		$ancestors->offsetSet($object);
		foreach ((array) $object as $value) {
			self::assertExportableValue($value, $ancestors);
		}
		$ancestors->offsetUnset($object);
	}

	private static function assertExportableValue(mixed $value, SplObjectStorage $ancestors): void
	{
		if (is_array($value)) {
			foreach ($value as $nestedValue) self::assertExportableValue($nestedValue, $ancestors);
			return;
		}
		if (is_object($value)) {
			self::assertExportableObject($value, $ancestors);
			return;
		}
		if (is_resource($value)) {
			throw new LogicException(sprintf('Cannot export cache value of type %s', get_debug_type($value)));
		}
	}

	/**
	 * Group static routes
	 * @return array
	 */
	private function groupStaticRoutes(): array
	{
		$staticRoutes = $dynamicRegex = [];

		/** @var Route[] */
		$dynamicRoutes = [];

		foreach ($this->routes as $route) {
			$compiledRoute = $route->compile();
			$staticPrefix = rtrim($compiledRoute->getStaticPrefix(), '/');
			$regex = $compiledRoute->getRegex();

			if (!$compiledRoute->getPathVariables()) {
				$url = $route->getPath();

				foreach ($dynamicRegex as [$rx, $prefix]) {
					if (('' === $prefix || str_starts_with($url, $prefix)) && (preg_match($rx, $url) || preg_match($rx, $url . '/'))) {
						$dynamicRegex[] = [$regex, $staticPrefix];
						$dynamicRoutes[] = $route;
						continue 2;
					}
				}

				$staticRoutes[$url][] = $route;
			} else {
				$dynamicRegex[] = [$regex, $staticPrefix];
				$dynamicRoutes[] = $route;
			}
		}

		return [$staticRoutes, $dynamicRoutes];
	}

	/**
	 * Compile static routes
	 * @param array $staticRoutes
	 * @return array
	 */
	private function compileStaticRoutes(array $staticRoutes): array
	{
		if (!$staticRoutes) return [];

		$compiledRoutes = [];

		foreach ($staticRoutes as $url => $routes) {
			$compiledRoutes[$url] = [];
			foreach ($routes as $route) {
				$compiledRoutes[$url][] = $this->compileRoute($route, null);
			}
		}

		return $compiledRoutes;
	}

	/**
	 * Compile Dynamic Routes
	 * @param Route[] $collection
	 * @param int $chunkLimit
	 * @return array
	 */
	private function compileDynamicRoutes(array $collection, int $chunkLimit): array
	{
		if (!$collection) return [[], []];

		$regexpList = [];

		$state = (object) [
			'regex' => [],
			'routes' => [],
			'mark' => 0,
			'markTail' => 0,
			'vars' => [],
		];

		$state->getVars = static function ($m) use ($state) {
			$state->vars[] = $m[1];
			return '';
		};

		$chunkSize = 0;
		$prev = null;
		$perModifiers = [];
		$currModifier = -1;
		foreach ($collection as $route) {
			preg_match('#[a-zA-Z]*$#', $route->compile()->getRegex(), $rx);
			if ($chunkLimit < ++$chunkSize || $prev !== $rx[0] && $route->compile()->getPathVariables()) {
				$currModifier++;
				$chunkSize = 1;
				/** @var Route[] */
				$routes = [];
				$perModifiers[$currModifier] = [$rx[0], $routes];
				$prev = $rx[0];
			}
			$perModifiers[$currModifier][1][] = $route;
		}

		foreach ($perModifiers as [$modifiers, $routes]) {
			$rx = '{^(?';

			$startingMark = $state->mark;
			$state->mark += strlen($rx);
			$state->regex = $rx;

			$tree = new StaticPrefixCollection();
			foreach ($routes as $route) {
				preg_match('#^.\^(.*)\$.[a-zA-Z]*$#', $route->compile()->getRegex(), $rx);

				$state->vars = [];
				$regex = preg_replace_callback('#\?P<([^>]++)>#', $state->getVars, $rx[1]);

				$tree->addRoute($regex, [$regex, $state->vars, $route]);
			}

			$this->compileStaticPrefixCollection($tree, $state, 0);

			$rx = ")/?$}{$modifiers}";
			$state->regex .= $rx;
			$state->markTail = 0;

			// if the regex is too large, throw a signaling exception to recompute with smaller chunk size
			set_error_handler(fn($type, $message) => throw str_contains($message, $this->signalingException->getMessage()) ? $this->signalingException : new ErrorException($message));
			try {
				preg_match($state->regex, '');
			} finally {
				restore_error_handler();
			}

			$regexpList[$startingMark] = $state->regex;
		}

		$state->routes[$state->mark][] = null;
		unset($state->getVars);

		return [$regexpList, $state->routes];
	}

	/**
	 * Compile static prefix collection
	 * @param StaticPrefixCollection $tree
	 * @param stdClass $state
	 * @param int $prefixLen
	 * @return void
	 */
	private function compileStaticPrefixCollection(StaticPrefixCollection $tree, stdClass $state, int $prefixLen): void
	{
		$prevRegex = null;
		$routes = $tree->getRoutes();

		foreach ($routes as $route) {
			if ($route instanceof StaticPrefixCollection) {
				$prevRegex = null;
				$prefix = substr($route->getPrefix(), $prefixLen);
				$state->mark += strlen($rx = "|{$prefix}(?");
				$state->regex .= $rx;
				$this->compileStaticPrefixCollection($route, $state, $prefixLen + strlen($prefix));
				$state->regex .= ')';
				++$state->markTail;
				continue;
			}

			[$regex, $vars, $route] = $route;
			$compiledRoute = $route->compile();

			if ($compiledRoute->getRegex() === $prevRegex) {
				$state->routes[$state->mark][] = $this->compileRoute($route, $vars);
				continue;
			}

			$state->mark += 3 + $state->markTail + strlen($regex) - $prefixLen;
			$state->markTail = 2 + strlen(strval($state->mark));
			$rx = sprintf('|%s(*:%s)', substr($regex, $prefixLen), $state->mark);
			$state->regex .= $rx;

			$prevRegex = $compiledRoute->getRegex();
			$state->routes[$state->mark] = [$this->compileRoute($route, $vars)];
		}
	}

	/**
	 * Compile route
	 * @param Route $route
	 * @param array|null $vars
	 * @return array
	 */
	private function compileRoute(Route $route, array|null $vars): array
	{
		$action = $route->getAction();
		$action[] = $route->getMiddlewares();
		$action[] = $route->getArgumentConverters();

		$r = [
			$action,
			array_flip($route->getMethods())
		];

		if (!is_null($vars)) $r[] = $vars;

		return $r;
	}
}
