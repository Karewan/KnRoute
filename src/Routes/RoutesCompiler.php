<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Routes;

use DomainException;
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
		'slug' => '[a-z0-9\-]+',
		'hex' => '[a-f0-9]+',
		'alpha' => '[a-z0-9]+',
		'letters' => '[a-z]+',
		'num' => '[0-9]+',
		'any' => '[^\/]+',
		'all' => '.*'
	];

	/**
	 * Compile
	 * @param Route $route
	 */
	public static function compile(Route $route): CompiledRoute
	{
		self::extractVarsRegex($route, $route->getPath());

		$result = self::compilePattern($route, $route->getPath());

		return new CompiledRoute(
			$result['staticPrefix'],
			$result['regex'],
			$result['variables']
		);
	}

	/**
	 * Extract vars regex
	 * @param Route $route
	 * @param string $pattern
	 * @return string
	 */
	private static function extractVarsRegex(Route $route, string $pattern): string
	{
		$varsRegex = [];
		$pattern = $route->getPath();

		$start = -1;
		while (($start = strpos($pattern, '{', $start + 1)) !== false) {
			if (!($end = strpos($pattern, '}', $start + 1))) continue;

			$var = substr($pattern, $start + 1, $end - $start - 1);

			$args = explode(':', $var);
			if (!isset($args[1], self::VAR_REGEX[$args[1]])) throw new LogicException('Bad var type for "' . $args[0] . '"');

			$varsRegex[$args[0]] = self::VAR_REGEX[$args[1]];

			$pattern = str_replace($var, $args[0], $pattern);
		}

		$route->setPath($pattern);
		$route->setVarsRegex($varsRegex);

		return $pattern;
	}

	/**
	 * Compile pattern
	 * @param Route $route
	 * @param string $pattern
	 * @return array
	 */
	private static function compilePattern(Route $route, string $pattern): array
	{
		$tokens = [];
		$variables = [];
		$matches = [];
		$pos = 0;
		$defaultSeparator = '/';

		// Match all variables enclosed in "{}" and iterate over them. But we only want to match the innermost variable
		// in case of nested "{}", e.g. {foo{bar}}. This in ensured because \w does not match "{" or "}" itself.
		preg_match_all('#\{(!)?([\w\x80-\xFF]+)\}#', $pattern, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
		foreach ($matches as $match) {
			$important = $match[1][1] >= 0;
			$varName = $match[2][0];
			// get all static text preceding the current variable
			$precedingText = substr($pattern, $pos, $match[0][1] - $pos);
			$pos = $match[0][1] + strlen($match[0][0]);

			if (!strlen($precedingText)) {
				$precedingChar = '';
			} else {
				$precedingChar = substr($precedingText, -1);
			}
			$isSeparator = '' !== $precedingChar && str_contains(static::SEPARATORS, $precedingChar);

			// A PCRE subpattern name must start with a non-digit. Also a PHP variable cannot start with a digit so the
			// variable would not be usable as a Controller action argument.
			if (preg_match('/^\d/', $varName)) {
				throw new DomainException(sprintf('Variable name "%s" cannot start with a digit in route pattern "%s". Please use a different name.', $varName, $pattern));
			}
			if (in_array($varName, $variables)) {
				throw new LogicException(sprintf('Route pattern "%s" cannot reference variable name "%s" more than once.', $pattern, $varName));
			}

			if (strlen($varName) > self::VARIABLE_MAXIMUM_LENGTH) {
				throw new DomainException(sprintf('Variable name "%s" cannot be longer than %d characters in route pattern "%s". Please use a shorter name.', $varName, self::VARIABLE_MAXIMUM_LENGTH, $pattern));
			}

			if ($isSeparator && $precedingText !== $precedingChar) {
				$tokens[] = ['text', substr($precedingText, 0, -strlen($precedingChar))];
			} elseif (!$isSeparator && '' !== $precedingText) {
				$tokens[] = ['text', $precedingText];
			}

			$regexp = $route->getVarRegex($varName);
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

			if ($important) {
				$token = ['variable', $isSeparator ? $precedingChar : '', $regexp, $varName, false, true];
			} else {
				$token = ['variable', $isSeparator ? $precedingChar : '', $regexp, $varName];
			}

			$tokens[] = $token;
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
			'staticPrefix' => self::determineStaticPrefix($route, $tokens),
			'regex' => $regexp,
			'variables' => $variables,
		];
	}

	/**
	 * Determine static prefix
	 * @param Route $route
	 * @param array $tokens
	 * @return string
	 */
	private static function determineStaticPrefix(Route $route, array $tokens): string
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
