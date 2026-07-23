<?php

namespace Tests\Feature;

use InterNACHI\Modular\Support\Facades\Modules;
use Tests\TestCase;

class ModularStructureTest extends TestCase
{
    private const MODULES = [
        'auth',
        'candidates',
        'guest-access',
        'jobs',
        'lookup-data',
        'placement',
    ];

    public function test_required_modules_are_discovered(): void
    {
        $this->assertSame(
            self::MODULES,
            Modules::modules()->keys()->sort()->values()->all()
        );
    }

    public function test_each_module_has_a_public_boundary(): void
    {
        foreach (self::MODULES as $name) {
            $this->assertDirectoryExists(
                Modules::module($name)?->path('src/Public')
            );
        }
    }
}
