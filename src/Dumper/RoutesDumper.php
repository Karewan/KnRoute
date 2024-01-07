<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Dumper;

use DomainException;
use ErrorException;
use Exception;
use Karewan\KnRoute\Attributes\Route;
use LogicException;
use RuntimeException;
use stdClass;

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
	}

	/**
	 * Get compiled routes
	 * @return array
	 * @throws LogicException
	 * @throws DomainException
	 * @throws ErrorException
	 * @throws Exception
	 */
	public function getCompiledRoutes(): array
	{
		// Group hosts by same-suffix, re-order when possible
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

		return $compiledRoutes;
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
				$host = '';
				$url = $route->getPath();

				foreach ($dynamicRegex as [$hostRx, $rx, $prefix]) {
					if (('' === $prefix || str_starts_with($url, $prefix)) && (preg_match($rx, $url) || preg_match($rx, $url . '/')) && (!$host || !$hostRx || preg_match($hostRx, $host))) {
						$dynamicRegex[] = [null, $regex, $staticPrefix];
						$dynamicRoutes[] = $route;
						continue 2;
					}
				}

				$staticRoutes[$url][] = $route;
			} else {
				$dynamicRegex[] = [null, $regex, $staticPrefix];
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
			set_error_handler(fn ($type, $message) => throw str_contains($message, $this->signalingException->getMessage()) ? $this->signalingException : new ErrorException($message));
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
		$r = [
			$route->getAction(),
			array_flip($route->getMethods())
		];

		if (!is_null($vars)) $r[] = $vars;

		return $r;
	}
}
