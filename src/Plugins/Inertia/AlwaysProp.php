<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Plugins\Inertia;

use Closure;

class AlwaysProp
{
	/**
	 * Class constructor
	 * @param mixed $data
	 * @return void
	 */
	public function __construct(private mixed $data) {}

	/**
	 * Return the data
	 * @return mixed
	 */
	public function getData(): mixed
	{
		return $this->data instanceof Closure ? ($this->data)() : $this->data;
	}
}
