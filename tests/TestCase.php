<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\PendingCommand;

abstract class TestCase extends BaseTestCase
{
    /**
     * Migrations run as migrator/owner; app assertions use default pgsql (runtime).
     * Child tests keep using RefreshDatabase — inject --database here.
     *
     * @param  string  $command
     * @param  array<string, mixed>  $parameters
     * @return PendingCommand|int
     */
    public function artisan($command, $parameters = [])
    {
        if ($command === 'migrate:fresh' && ! array_key_exists('--database', $parameters)) {
            $parameters['--database'] = 'pgsql_migrator';
        }

        return parent::artisan($command, $parameters);
    }
}
