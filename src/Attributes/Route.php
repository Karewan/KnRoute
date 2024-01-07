<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Attributes;

use Attribute;
use DomainException;
use Karewan\KnRoute\CompiledRoute;
use Karewan\KnRoute\RoutesCompiler;
use LogicException;

#[Attribute]
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
	 * @throws LogicException
	 * @throws DomainException
	 */
	public function compile(): CompiledRoute
	{
		if (!is_null($this->compiled)) return $this->compiled;
		return $this->compiled = RoutesCompiler::compile($this);
	}
}
