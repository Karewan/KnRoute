<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Plugins\Inertia;

use Closure;
use Karewan\KnRoute\HttpUtils;

class Inertia
{
	/** @var string */
	private const string
		HEADER_INERTIA = 'X-Inertia',
		HEADER_ERROR_BAG = 'X-Inertia-Error-Bag',
		HEADER_LOCATION = 'X-Inertia-Location',
		HEADER_VERSION = 'X-Inertia-Version',
		HEADER_PARTIAL_COMPONENT = 'X-Inertia-Partial-Component',
		HEADER_PARTIAL_ONLY = 'X-Inertia-Partial-Data',
		HEADER_PARTIAL_EXCEPT = 'X-Inertia-Partial-Except';

	/** @var Closure */
	private static Closure $viewFnc;

	/** @var string */
	private static string $version;

	/** @var array */
	private static array $viewData = [];

	/** @var array */
	private static array $sharedProps = [];

	/**
	 * Init Inertia
	 * @param Closure $viewFnc
	 * @param string $version
	 * @return void
	 */
	public static function init(Closure $viewFnc, string $version = ''): void
	{
		self::$viewFnc = $viewFnc;
		self::$version = $version;
	}

	/**
	 * Return the version
	 * @return string
	 */
	public static function getVersion(): string
	{
		return self::$version;
	}

	/**
	 * View data
	 * @param string|array $key
	 * @param mixed $data
	 * @return void
	 */
	public static function viewData(string|array $key, mixed $data): void
	{
		if (is_array($key)) {
			self::$viewData = array_merge(self::$viewData, $key);
		} else {
			self::$viewData[$key] = $data;
		}
	}

	/**
	 * Get view data
	 * @return array
	 */
	public static function getViewData(): array
	{
		return self::$viewData;
	}

	/**
	 * Flush view data
	 * @return void
	 */
	public static function flushViewData(): void
	{
		self::$viewData = [];
	}

	/**
	 * Share props
	 * @param string|array $key
	 * @param mixed $data
	 * @return void
	 */
	public static function share(string|array $key, mixed $data): void
	{
		if (is_array($key)) {
			self::$sharedProps = array_merge(self::$sharedProps, $key);
		} else {
			self::$sharedProps[$key] = $data;
		}
	}

	/**
	 * Get shared props
	 * @return array
	 */
	public static function getShared(): array
	{
		return self::$sharedProps;
	}

	/**
	 * Flush shared props
	 * @return void
	 */
	public static function flushShared(): void
	{
		self::$sharedProps = [];
	}

	/**
	 * Inertia redirect (this initiate a full page reload on the client side)
	 * @param string $url
	 * @return never
	 */
	public static function location(string $url = '/'): never
	{
		HttpUtils::setHeader(self::HEADER_LOCATION, $url, 409);
		die();
	}

	/**
	 * Return a new AlwaysProp instance
	 * @param mixed $data
	 * @return AlwaysProp
	 */
	public static function always(mixed $data): AlwaysProp
	{
		return new AlwaysProp($data);
	}

	/**
	 * Return a new LazyProp instance
	 * @param Closure $data
	 * @return LazyProp
	 */
	public static function lazy(Closure $data): LazyProp
	{
		return new LazyProp($data);
	}

	/**
	 * Render the page
	 * @param string $component
	 * @param array $props
	 * @return never
	 */
	public static function render(string $component, array $props = []): never
	{
		// Compose the page
		$page = [
			'component' => $component,
			'props' => self::evaluateProps(
				props: array_merge($props, self::$sharedProps),
				isPartial: ($isPartial = HttpUtils::getHeader(self::HEADER_PARTIAL_COMPONENT) == $component),
				only: ($isPartial ? array_flip(array_filter(explode(',', HttpUtils::getHeader(self::HEADER_PARTIAL_ONLY)))) : []),
				except: ($isPartial ? array_flip(array_filter(explode(',', HttpUtils::getHeader(self::HEADER_PARTIAL_EXCEPT)))) : [])
			),
			'url' => HttpUtils::getPath(),
			'version' => self::$version
		];

		// Direct page access => Output the HTML view
		if (!HttpUtils::hasHeader(self::HEADER_INERTIA)) HttpUtils::outputHtml((self::$viewFnc)(array_merge(
			['inertiaPage' => 'data-page="' . htmlspecialchars(json_encode($page), ENT_QUOTES, 'UTF-8') . '"'],
			self::$viewData
		)));

		// Compare assets version => Not the same version => Inertia redirect
		if (HttpUtils::getMethod() == 'GET' && HttpUtils::getHeader(self::HEADER_VERSION) != $page['version']) self::location($page['url']);

		// Output the Inertia JSON
		HttpUtils::setHeader(self::HEADER_INERTIA, 'true');
		HttpUtils::outputJson($page);
	}

	/**
	 * Evaluate props
	 * @param array $props
	 * @param bool $isPartial
	 * @param array $only
	 * @param array $except
	 * @return array
	 */
	private static function evaluateProps(array $props, bool $isPartial, array $only, array $except): array
	{
		foreach ($props as $key => $value) {
			// Always present in the response
			if ($value instanceof AlwaysProp) {
				$props[$key] = $value->getData();
				continue;
			}

			// Partial req
			if ($isPartial) {
				// Except
				if (isset($except[$key])) {
					unset($props[$key]);
					continue;
				}

				// Only
				if (isset($only[$key])) {
					// Lazy
					if ($value instanceof LazyProp) {
						$props[$key] = $value->getData();
						continue;
					}
				} else {
					unset($props[$key]);
					continue;
				}
			} else {
				// Lazy
				if ($value instanceof LazyProp) {
					unset($props[$key]);
					continue;
				}
			}

			// Closure
			if ($value instanceof Closure) {
				$props[$key] = $value();
				continue;
			}
		}

		return $props;
	}
}
