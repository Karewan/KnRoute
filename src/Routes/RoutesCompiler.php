<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Routes;

use Karewan\KnRoute\Attributes\Route;
use LogicException;

class RoutesCompiler
{
	/**
	 * This string defines the characters that are automatically considered separators in front of
	 * optional placeholders (with default and no static text following). Such a single separator
	 * can be left out together with the optional placeholder from matching and generating URLs.
	 * @var string
	 */
	public const string SEPARATORS = '/,;.:-_~+*=@|';

	/**
	 * The maximum supported length of a PCRE subpattern name
	 * http://pcre.org/current/doc/html/pcre2pattern.html#SEC16.
	 * @var int
	 */
	public const int VARIABLE_MAXIMUM_LENGTH = 32;

	/**
	 * Var regex types
	 * @var array<string,string>
	 */
	private const array VAR_REGEX = [
		'alpha' => '[A-Za-z]+',
		'alnum' => '[A-Za-z0-9]+',
		'hex' => '[A-Fa-f0-9]+',
		'slug' => '[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*',
		'uuid' => '[A-Fa-f0-9]{8}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{12}',
		'segment' => '(?![^/]*%2[Ff])[^/]+',
		'path' => '.+'
	];

	/**
	 * Compile
	 * @param Route $route
	 */
	public static function compile(Route $route): CompiledRoute
	{
		[$pattern, $varsRegex] = self::parseVariables($route->getPath());

		$result = self::compilePattern($pattern, $varsRegex);

		return new CompiledRoute(
			$result['staticPrefix'],
			$result['regex'],
			$result['variables']
		);
	}

	/**
	 * Extract vars regex
	 * @param string $pattern
	 * @return array{string,array<string,string>}
	 */
	private static function parseVariables(string $pattern): array
	{
		$varsRegex = [];
		$normalizedPattern = '';
		$offset = 0;

		while (($brace = strcspn($pattern, '{}', $offset)) + $offset < strlen($pattern)) {
			$start = $offset + $brace;
			if ($pattern[$start] === '}') {
				throw new LogicException(sprintf('Unexpected "}" in route pattern "%s".', $pattern));
			}

			$end = strpos($pattern, '}', $start + 1);
			if ($end === false) {
				throw new LogicException(sprintf('Unclosed variable in route pattern "%s".', $pattern));
			}

			$declaration = substr($pattern, $start + 1, $end - $start - 1);
			if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*):([a-z][a-z0-9_]*)$/D', $declaration, $matches)) {
				throw new LogicException(sprintf('Invalid variable declaration "{%s}" in route pattern "%s"; expected "{name:type}".', $declaration, $pattern));
			}

			[, $name, $type] = $matches;
			if (strlen($name) > self::VARIABLE_MAXIMUM_LENGTH) {
				throw new LogicException(sprintf(
					'Variable name "%s" cannot exceed %d characters in route pattern "%s".',
					$name,
					self::VARIABLE_MAXIMUM_LENGTH,
					$pattern
				));
			}
			$regexp = match ($type) {
				'uint', 'int' => self::getIntegerRegexes()[$type],
				default => self::VAR_REGEX[$type] ?? null,
			};
			if ($regexp === null) {
				throw new LogicException(sprintf('Unknown variable type "%s" for "%s" in route pattern "%s".', $type, $name, $pattern));
			}
			if (isset($varsRegex[$name])) {
				throw new LogicException(sprintf('Route pattern "%s" cannot reference variable name "%s" more than once.', $pattern, $name));
			}

			$varsRegex[$name] = $regexp;
			$normalizedPattern .= substr($pattern, $offset, $start - $offset) . '{' . $name . '}';
			$offset = $end + 1;
		}

		$normalizedPattern .= substr($pattern, $offset);

		return [$normalizedPattern, $varsRegex];
	}

	/**
	 * Shared built-in expressions for standalone routes and the combined matcher.
	 * @internal
	 * @return array{uint:string,int:string}
	 */
	public static function getIntegerRegexes(): array
	{
		static $regexes = null;
		if ($regexes !== null) return $regexes;

		$positive = self::positiveIntegerRegex((string) PHP_INT_MAX);
		return $regexes = [
			'uint' => '(?:0|' . $positive . ')',
			'int' => '(?:0|' . $positive . '|-(?:' . self::positiveIntegerRegex(substr((string) PHP_INT_MIN, 1)) . '))',
		];
	}

	/** Build a decimal regexp for the inclusive range 1..$maximum. */
	private static function positiveIntegerRegex(string $maximum): string
	{
		$length = strlen($maximum);
		$patterns = $length > 1 ? ['[1-9][0-9]{0,' . ($length - 2) . '}'] : [];

		for ($i = 0; $i < $length; $i++) {
			$upper = (int) $maximum[$i] - 1;
			$lower = $i === 0 ? 1 : 0;
			if ($upper < $lower) continue;

			$prefix = substr($maximum, 0, $i);
			$digit = $upper === $lower ? (string) $lower : "[{$lower}-{$upper}]";
			$remaining = $length - $i - 1;
			$patterns[] = $prefix . $digit . ($remaining > 0 ? '[0-9]{' . $remaining . '}' : '');
		}

		$patterns[] = $maximum;
		return '(?:' . implode('|', $patterns) . ')';
	}

	/**
	 * Compile pattern
	 * @param string $pattern
	 * @param array<string,string> $varsRegex
	 * @return array
	 */
	private static function compilePattern(string $pattern, array $varsRegex): array
	{
		$tokens = [];
		$variables = [];
		$pos = 0;

		preg_match_all('#\{([A-Za-z_][A-Za-z0-9_]*)\}#', $pattern, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
		foreach ($matches as $match) {
			$varName = $match[1][0];
			// get all static text preceding the current variable
			$precedingText = substr($pattern, $pos, $match[0][1] - $pos);
			$pos = $match[0][1] + strlen($match[0][0]);

			if (!strlen($precedingText)) {
				$precedingChar = '';
			} else {
				$precedingChar = substr($precedingText, -1);
			}
			$isSeparator = '' !== $precedingChar && str_contains(static::SEPARATORS, $precedingChar);

			if ($isSeparator && $precedingText !== $precedingChar) {
				$tokens[] = ['text', substr($precedingText, 0, -strlen($precedingChar))];
			} elseif (!$isSeparator && '' !== $precedingText) {
				$tokens[] = ['text', $precedingText];
			}

			// Every placeholder left in the normalized pattern was declared as
			// "{name:type}" and resolved to a built-in expression by parseVariables().
			// Built-in type expressions already contain only non-capturing groups.
			$tokens[] = ['variable', $isSeparator ? $precedingChar : '', $varsRegex[$varName], $varName];
			$variables[] = $varName;
		}

		if ($pos < strlen($pattern)) {
			$tokens[] = ['text', substr($pattern, $pos)];
		}

		// compute the matching regexp
		$regexp = '';
		for ($i = 0, $nbToken = count($tokens); $i < $nbToken; ++$i) $regexp .= self::computeRegexp($tokens, $i);
		$regexp = '{^' . $regexp . '$}sD';

		return [
			'staticPrefix' => self::determineStaticPrefix($tokens),
			'regex' => $regexp,
			'variables' => $variables,
		];
	}

	/**
	 * Determine static prefix
	 * @param array $tokens
	 * @return string
	 */
	private static function determineStaticPrefix(array $tokens): string
	{
		if ('text' !== $tokens[0][0]) return '/' === $tokens[0][1] ? '' : $tokens[0][1];
		$prefix = $tokens[0][1];
		if (isset($tokens[1][1]) && '/' !== $tokens[1][1]) $prefix .= $tokens[1][1];
		return $prefix;
	}

	/**
	 * Compute regexp
	 * @param array $tokens
	 * @param int $index
	 * @return string
	 */
	private static function computeRegexp(array $tokens, int $index): string
	{
		$token = $tokens[$index];
		if ('text' === $token[0]) {
			// Text tokens
			return preg_quote($token[1]);
		} else {
			// Variable tokens
			return sprintf('%s(?P<%s>%s)', preg_quote($token[1]), $token[3], $token[2]);
		}
	}
}
