<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create the application while refusing to boot tests against a non-test database.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $config = $app->make('config');
        $connectionName = (string) $config->get('database.default');
        $driver = (string) $config->get("database.connections.{$connectionName}.driver");
        $database = (string) $config->get("database.connections.{$connectionName}.database");

        if (
            ! $app->environment('testing')
            || $driver !== 'pgsql'
            || preg_match('/(?:^|[_-])test(?:ing)?$/i', $database) !== 1
        ) {
            throw new RuntimeException(
                'Refusing to run tests against a non-test PostgreSQL database.',
            );
        }

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
