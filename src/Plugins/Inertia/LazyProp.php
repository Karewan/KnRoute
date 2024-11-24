<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Plugins\Inertia;

use Closure;

class LazyProp
{
	/**
	 * Class constructor
	 * @param Closure $data
	 * @return void
	 */
	public function __construct(private Closure $data) {}

	/**
	 * Return the data
	 * @return mixed
	 */
	public function getData(): mixed
	{
		return ($this->data)();
	}
}
