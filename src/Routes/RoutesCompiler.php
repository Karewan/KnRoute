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
		'uint' => '(?:0|[1-9][0-9]*)',
		'int' => '(?:0|-?[1-9][0-9]*)',
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
			if (!isset(self::VAR_REGEX[$type])) {
				throw new LogicException(sprintf('Unknown variable type "%s" for "%s" in route pattern "%s".', $type, $name, $pattern));
			}
			if (isset($varsRegex[$name])) {
				throw new LogicException(sprintf('Route pattern "%s" cannot reference variable name "%s" more than once.', $pattern, $name));
			}

			$varsRegex[$name] = self::VAR_REGEX[$type];
			$normalizedPattern .= substr($pattern, $offset, $start - $offset) . '{' . $name . '}';
			$offset = $end + 1;
		}

		$normalizedPattern .= substr($pattern, $offset);

		return [$normalizedPattern, $varsRegex];
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
		$defaultSeparator = '/';

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

			$regexp = $varsRegex[$varName] ?? null;
			if (null === $regexp) {
				$followingPattern = (string) substr($pattern, $pos);
				// Find the next static character after the variable that functions as a separator. By default, this separator and '/'
				// are disallowed for the variable. This default requirement makes sure that optional variables can be matched at all
				// and that the generating-matching-combination of URLs unambiguous, i.e. the params used for generating the URL are
				// the same that will be matched. Example: new Route('/{page}.{_format}', ['_format' => 'html'])
				// If {page} would also match the separating dot, {_format} would never match as {page} will eagerly consume everything.
				// Also even if {_format} was not optional the requirement prevents that {page} matches something that was originally
				// part of {_format} when generating the URL, e.g. _format = 'mobile.html'.
				$nextSeparator = self::findNextSeparator($followingPattern);
				$regexp = sprintf(
					'[^%s%s]+',
					preg_quote($defaultSeparator),
					$defaultSeparator !== $nextSeparator && '' !== $nextSeparator ? preg_quote($nextSeparator) : ''
				);
				if (('' !== $nextSeparator && !preg_match('#^\{[\w\x80-\xFF]+\}#', $followingPattern)) || '' === $followingPattern) {
					// When we have a separator, which is disallowed for the variable, we can optimize the regex with a possessive
					// quantifier. This prevents useless backtracking of PCRE and improves performance by 20% for matching those patterns.
					// Given the above example, there is no point in backtracking into {page} (that forbids the dot) when a dot must follow
					// after it. This optimization cannot be applied when the next char is no real separator or when the next variable is
					// directly adjacent, e.g. '/{x}{y}'.
					$regexp .= '+';
				}
			} else {
				$regexp = self::transformCapturingGroupsToNonCapturings($regexp);
			}

			$tokens[] = ['variable', $isSeparator ? $precedingChar : '', $regexp, $varName];
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
	 * Find next separator
	 * @param string $pattern
	 * @return string
	 */
	private static function findNextSeparator(string $pattern): string
	{
		if ('' == $pattern) {
			// return empty string if pattern is empty or false (false which can be returned by substr)
			return '';
		}

		// first remove all placeholders from the pattern so we can find the next real static character
		if ('' === $pattern = preg_replace('#\{[\w\x80-\xFF]+\}#', '', $pattern)) {
			return '';
		}

		return str_contains(static::SEPARATORS, $pattern[0]) ? $pattern[0] : '';
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

	/**
	 * Transform capturing groups to non capturings
	 * @param string $regexp
	 * @return string
	 */
	private static function transformCapturingGroupsToNonCapturings(string $regexp): string
	{
		for ($i = 0; $i < strlen($regexp); ++$i) {
			if ('\\' === $regexp[$i]) {
				++$i;
				continue;
			}
			if ('(' !== $regexp[$i] || !isset($regexp[$i + 2])) {
				continue;
			}
			if ('*' === $regexp[++$i] || '?' === $regexp[$i]) {
				++$i;
				continue;
			}
			$regexp = substr_replace($regexp, '?:', $i, 0);
			++$i;
		}

		return $regexp;
	}
}
