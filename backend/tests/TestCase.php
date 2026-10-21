<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $config = $app['config'];
        if ($config->get('database.default') !== 'pgsql'
            || !empty($config->get('database.connections.pgsql.url'))
            || !str_ends_with((string) $config->get('database.connections.pgsql.database'), '_test')) {
            throw new \RuntimeException('Las pruebas requieren una base PostgreSQL separada cuyo nombre termine en _test.');
        }
        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        return $app;
    }
}
