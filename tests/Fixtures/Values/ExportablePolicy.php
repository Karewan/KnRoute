<?php

declare(strict_types=1);

namespace Tests\Fixtures\Values;

final class ExportablePolicy
{
	public function __construct(public readonly string $name)
	{
	}

	public static function __set_state(array $properties): self
	{
		return new self($properties['name']);
	}
}
