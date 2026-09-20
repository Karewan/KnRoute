<?php

declare(strict_types=1);

namespace {
	require __DIR__ . '/../src/IMiddleware.php';
}

namespace Tests {
	use Karewan\KnRoute\IMiddleware;
	use RuntimeException;

	class MiddlewareLifecycle
	{
		/** @var string[] */
		public static array $events = [];
	}

	class LifecycleMiddleware implements IMiddleware
	{
		public function __construct(
			private readonly string $name,
			private readonly bool $crashBefore = false,
			private readonly bool $crashAfter = false
		) {
		}

		public function before(): void
		{
			MiddlewareLifecycle::$events[] = "before:{$this->name}";
			if ($this->crashBefore) throw new RuntimeException("before:{$this->name}");
		}

		public function after(): void
		{
			MiddlewareLifecycle::$events[] = "after:{$this->name}";
			if ($this->crashAfter) throw new RuntimeException("after:{$this->name}");
		}
	}
}

namespace {
	use Karewan\KnRoute\Router;
	use Tests\LifecycleMiddleware;
	use Tests\MiddlewareLifecycle;

	const PROJECT_ROOT = __DIR__ . '/..';

	spl_autoload_register(static function (string $class): void {
		$prefixes = [
			'Karewan\\KnRoute\\' => PROJECT_ROOT . '/src/',
			'Tests\\Fixtures\\' => __DIR__ . '/Fixtures/'
		];

		foreach ($prefixes as $prefix => $directory) {
			if (!str_starts_with($class, $prefix)) continue;
			$file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (is_file($file)) require $file;
			return;
		}
	});

	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['REQUEST_URI'] = '/crash';

	assertLifecycle(false, false, ['before:A', 'before:B', 'action', 'after:B', 'after:A'], 'action crash');
	assertLifecycle(false, true, ['before:A', 'before:B', 'action', 'after:B'], 'after:B');
	assertLifecycle(true, false, ['before:A', 'before:B'], 'before:B');

	echo "PASS  Middleware hooks stop immediately while action failures run after hooks\n";

	/** @param string[] $expectedEvents */
	function assertLifecycle(bool $crashBefore, bool $crashAfter, array $expectedEvents, string $expectedException): void
	{
		MiddlewareLifecycle::$events = [];
		$router = new Router();
		$router->addGlobalMiddleware(new LifecycleMiddleware('A'));
		$router->addGlobalMiddleware(new LifecycleMiddleware('B', $crashBefore, $crashAfter));
		$router->registerRoutesFromControllers(__DIR__ . '/Fixtures/Crash', null);

		try {
			$router->run();
			throw new RuntimeException('The expected exception was not thrown.');
		} catch (RuntimeException $exception) {
			if ($exception->getMessage() !== $expectedException) {
				throw new RuntimeException("Expected exception {$expectedException}, got {$exception->getMessage()}");
			}
		}

		if (MiddlewareLifecycle::$events !== $expectedEvents) {
			throw new RuntimeException('Unexpected middleware lifecycle: ' . implode(', ', MiddlewareLifecycle::$events));
		}
	}
}
