<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Routes;

use ArgumentCountError;
use Attribute;
use Error;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use TypeError;

/**
 * Validates middleware attributes without instantiating them, so middleware
 * constructors only ever run for the route being dispatched.
 *
 * The checks mirror what `new $middleware(...$arguments)` enforces when called
 * from the router (strict types), which is how cached routes build middlewares.
 */
class MiddlewareValidator
{
	private const array TARGET_NAMES = [
		Attribute::TARGET_CLASS => 'class',
		Attribute::TARGET_METHOD => 'method'
	];

	/**
	 * Attribute flags and constructor parameters by middleware class
	 * @var array<string,array{int,?ReflectionParameter[]}>
	 */
	private array $classes = [];

	/**
	 * @throws Error
	 * @throws ArgumentCountError
	 * @throws TypeError
	 */
	public function validate(ReflectionAttribute $attribute): void
	{
		$class = $attribute->getName();
		[$flags, $parameters] = $this->classes[$class] ??= $this->inspectClass($class);

		if (!($flags & $attribute->getTarget())) {
			throw new Error(sprintf(
				'Attribute "%s" cannot target %s',
				$class,
				self::TARGET_NAMES[$attribute->getTarget()] ?? 'this declaration'
			));
		}
		if ($attribute->isRepeated() && !($flags & Attribute::IS_REPEATABLE)) {
			throw new Error(sprintf('Attribute "%s" must not be repeated', $class));
		}

		$arguments = $attribute->getArguments();
		if ($parameters === null) {
			if ($arguments) {
				throw new Error(sprintf('Attribute class %s does not have a constructor, cannot pass arguments', $class));
			}
			return;
		}

		// Fast path: nothing to bind and nothing required
		if (!$arguments && (!$parameters || $parameters[0]->isOptional())) return;

		$this->validateArguments($class, $parameters, $arguments);
	}

	/** @return array{int,?ReflectionParameter[]} */
	private function inspectClass(string $class): array
	{
		$reflection = new ReflectionClass($class);

		$declaration = $reflection->getAttributes(Attribute::class)[0] ?? null;
		if ($declaration === null) {
			throw new Error(sprintf('Attempting to use non-attribute class "%s" as attribute', $class));
		}
		if (!$reflection->isInstantiable()) {
			throw new Error(sprintf('Cannot instantiate middleware %s', $class));
		}

		return [$declaration->newInstance()->flags, $reflection->getConstructor()?->getParameters()];
	}

	/**
	 * @param ReflectionParameter[] $parameters
	 * @param array<int|string,mixed> $arguments
	 */
	private function validateArguments(string $class, array $parameters, array $arguments): void
	{
		$byName = [];
		$variadic = null;
		foreach ($parameters as $parameter) {
			if ($parameter->isVariadic()) {
				$variadic = $parameter;
			} else {
				$byName[$parameter->getName()] = $parameter;
			}
		}

		$bound = [];
		foreach ($arguments as $key => $value) {
			if (is_int($key)) {
				$parameter = $parameters[$key] ?? $variadic;
				// PHP silently ignores extra positional arguments
				if ($parameter === null) continue;
			} elseif (isset($byName[$key])) {
				$parameter = $byName[$key];
				if (isset($bound[$parameter->getPosition()])) {
					throw new Error(sprintf('Named parameter $%s overwrites previous argument', $key));
				}
			} elseif ($variadic !== null) {
				$parameter = $variadic;
			} else {
				throw new Error(sprintf('Unknown named parameter $%s', $key));
			}

			$bound[$parameter->getPosition()] = true;

			$type = $parameter->getType();
			if ($type !== null && !$this->accepts($type, $value, $parameter->getDeclaringClass()?->getName() ?? $class)) {
				throw new TypeError(sprintf(
					'%s::__construct(): Argument #%d ($%s) must be of type %s, %s given',
					$class,
					$parameter->getPosition() + 1,
					$parameter->getName(),
					$type,
					get_debug_type($value)
				));
			}
		}

		foreach ($byName as $name => $parameter) {
			if (!isset($bound[$parameter->getPosition()]) && !$parameter->isDefaultValueAvailable()) {
				throw new ArgumentCountError(sprintf(
					'%s::__construct(): Argument #%d ($%s) not passed',
					$class,
					$parameter->getPosition() + 1,
					$name
				));
			}
		}
	}

	/**
	 * Strict-mode type check: the only implicit conversion is int to float.
	 */
	private function accepts(ReflectionType $type, mixed $value, string $class): bool
	{
		if ($value === null) return $type->allowsNull();

		if ($type instanceof ReflectionUnionType) {
			foreach ($type->getTypes() as $inner) {
				if ($this->accepts($inner, $value, $class)) return true;
			}
			return false;
		}

		if ($type instanceof ReflectionIntersectionType) {
			foreach ($type->getTypes() as $inner) {
				if (!$this->accepts($inner, $value, $class)) return false;
			}
			return true;
		}

		if (!$type instanceof ReflectionNamedType) return true;

		$name = $type->getName();
		if (!$type->isBuiltin()) {
			if ($name === 'self') $name = $class;
			return $value instanceof $name;
		}

		return match ($name) {
			'mixed' => true,
			'int' => is_int($value),
			'float' => is_float($value) || is_int($value),
			'string' => is_string($value),
			'bool' => is_bool($value),
			'true' => $value === true,
			'false' => $value === false,
			'array' => is_array($value),
			'iterable' => is_iterable($value),
			'object' => is_object($value),
			'callable' => is_callable($value),
			default => false
		};
	}
}
