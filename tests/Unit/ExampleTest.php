<?php

namespace Tests\Unit;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_runtime_versions_match_the_wave_zero_baseline(): void
    {
        $this->assertSame(8, PHP_MAJOR_VERSION);
        $this->assertSame(4, PHP_MINOR_VERSION);
        $this->assertSame(13, (int) explode('.', Application::VERSION)[0]);
    }
}
