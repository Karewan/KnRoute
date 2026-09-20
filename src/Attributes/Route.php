<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Attributes;

use Attribute;
use InvalidArgumentException;
use Karewan\KnRoute\Routes\CompiledRoute;
use Karewan\KnRoute\Routes\RoutesCompiler;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class Route
{
	/** @var string[] */
	private array $action = [];

	/** @var array<int,array{string,array}> */
	private array $middlewares = [];

	/** @var array<string,string> */
	private array $argumentConverters = [];

	/** @var null|CompiledRoute */
	private ?CompiledRoute $compiled = null;

	/**
	 * Route
	 * @param string[] $methods
	 * @param string $path
	 * @return void
	 */
	public function __construct(
		private readonly array $methods,
		private readonly string $path
	) {
		self::validatePath($path);

		$seen = [];
		foreach ($methods as $method) {
			if (!is_string($method) || !preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $method)) {
				throw new InvalidArgumentException('HTTP methods must be valid uppercase tokens; for example "GET"');
			}
			if ($method !== strtoupper($method)) {
				throw new InvalidArgumentException(sprintf('HTTP method "%s" must be uppercase; use "%s" instead', $method, strtoupper($method)));
			}
			if (isset($seen[$method])) {
				throw new InvalidArgumentException(sprintf('HTTP method "%s" is declared more than once', $method));
			}
			$seen[$method] = true;
		}
	}

	/**
	 * Methods
	 * @return string[]
	 */
	public function getMethods(): array
	{
		return $this->methods;
	}

	/**
	 * Path
	 * @return string
	 */
	public function getPath(): string
	{
		return $this->path;
	}

	/**
	 * Set action
	 * @param string[] $action
	 * @return void
	 */
	public function setAction(array $action): void
	{
		$this->action = $action;
	}

	/**
	 * Get action
	 * @return string[]
	 */
	public function getAction(): array
	{
		return $this->action;
	}

	/**
	 * @param array<int,array{string,array}> $middlewares
	 * @param array<string,string> $argumentConverters
	 */
	public function setExecutionMetadata(array $middlewares, array $argumentConverters): void
	{
		$this->middlewares = $middlewares;
		$this->argumentConverters = $argumentConverters;
	}

	/** @return array<int,array{string,array}> */
	public function getMiddlewares(): array
	{
		return $this->middlewares;
	}

	/** @return array<string,string> */
	public function getArgumentConverters(): array
	{
		return $this->argumentConverters;
	}

	/**
	 * Compile the route
	 * @return CompiledRoute
	 */
	public function compile(): CompiledRoute
	{
		if (!is_null($this->compiled)) return $this->compiled;
		return $this->compiled = RoutesCompiler::compile($this);
	}

	private static function validatePath(string $path): void
	{
		if (!str_starts_with($path, '/')) {
			throw new InvalidArgumentException(sprintf('Route path "%s" must start with "/"; for example "/users"', $path));
		}
		if ($path !== '/' && str_ends_with($path, '/')) {
			throw new InvalidArgumentException(sprintf('Route path "%s" must not end with "/"; use "%s" instead', $path, rtrim($path, '/')));
		}
	}
}
