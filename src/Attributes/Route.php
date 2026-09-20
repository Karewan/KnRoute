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

	/** @var string[] */
	private array $varsRegex = [];

	/** @var null|CompiledRoute */
	private ?CompiledRoute $compiled = null;

	/**
	 * Route
	 * @param string[] $methods
	 * @param string $path
	 * @return void
	 */
	public function __construct(
		private array $methods,
		private string $path
	) {
		if (!str_starts_with($path, '/')) {
			throw new InvalidArgumentException(sprintf('Route path "%s" must start with "/"', $path));
		}

		$seen = [];
		foreach ($methods as $method) {
			if (!is_string($method) || !preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $method)) {
				throw new InvalidArgumentException('HTTP methods must be valid case-sensitive tokens');
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
	 * Set path
	 * @param string $path
	 * @return void
	 */
	public function setPath(string $path): void
	{
		$this->path = $path;
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
	 * Set vars regex
	 * @param string[] $varsRegex
	 * @return void
	 */
	public function setVarsRegex(array $varsRegex): void
	{
		$this->varsRegex = $varsRegex;
	}

	/**
	 * Vars regex
	 * @param string $varName
	 * @return null|string
	 */
	public function getVarRegex(string $varName): ?string
	{
		return $this->varsRegex[$varName] ?? null;
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
}
