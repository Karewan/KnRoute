<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Routes;

class CompiledRoute
{
	/** @var string */
	private string $staticPrefix;

	/** @var string */
	private string $regex;

	/** @var string[] */
	private array $pathVariables;

	/**
	 * Compiled Route
	 * @param string $staticPrefix
	 * @param string $regex
	 * @param array $pathVariables
	 * @return void
	 */
	public function __construct(string $staticPrefix, string $regex, array $pathVariables)
	{
		$this->staticPrefix = $staticPrefix;
		$this->regex = $regex;
		$this->pathVariables = $pathVariables;
	}

	/**
	 * Static prefix
	 * @return string
	 */
	public function getStaticPrefix(): string
	{
		return $this->staticPrefix;
	}

	/**
	 * Regex
	 * @return string
	 */
	public function getRegex(): string
	{
		return $this->regex;
	}

	/**
	 * Path variables
	 * @return array
	 */
	public function getPathVariables(): array
	{
		return $this->pathVariables;
	}
}
