<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use InterNACHI\Modular\Support\Facades\Modules;
use InterNACHI\Modular\Support\ModuleRegistry;
use Modules\Auth\Providers\AuthServiceProvider;
use Modules\Candidates\Providers\CandidatesServiceProvider;
use Modules\GuestAccess\Providers\GuestAccessServiceProvider;
use Modules\Jobs\Providers\JobsServiceProvider;
use Modules\LookupData\Providers\LookupDataServiceProvider;
use Modules\Placement\Providers\PlacementServiceProvider;
use Tests\TestCase;

class ModularStructureTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function expectedModules(): array
    {
        return [
            'auth',
            'candidates',
            'guest-access',
            'jobs',
            'lookup-data',
            'placement',
        ];
    }

    public function test_internachi_modular_is_installed_and_six_modules_are_discovered(): void
    {
        $this->assertTrue(
            class_exists(ModuleRegistry::class),
            'internachi/modular must be installed.'
        );

        $names = Modules::modules()->keys()->sort()->values()->all();

        $this->assertSame(
            $this->expectedModules(),
            $names,
            'Exactly the six authority domain modules must be discovered.'
        );
    }

    public function test_each_module_has_public_boundary_directory(): void
    {
        foreach ($this->expectedModules() as $name) {
            $module = Modules::module($name);
            $this->assertNotNull($module, "Module [{$name}] must be registered.");

            $publicPath = $module->path('src/Public');
            $this->assertDirectoryExists(
                $publicPath,
                "Module [{$name}] must expose src/Public/ for inter-module contracts."
            );
        }
    }

    public function test_module_service_providers_are_loadable(): void
    {
        $providers = [
            AuthServiceProvider::class,
            CandidatesServiceProvider::class,
            GuestAccessServiceProvider::class,
            JobsServiceProvider::class,
            LookupDataServiceProvider::class,
            PlacementServiceProvider::class,
        ];

        foreach ($providers as $provider) {
            $this->assertTrue(class_exists($provider), "{$provider} must autoload.");
            $this->assertContains(
                $provider,
                array_keys(app()->getLoadedProviders()),
                "{$provider} must be registered with the application."
            );
        }
    }

    public function test_composer_path_repository_covers_app_modules(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

        $urls = collect($composer['repositories'] ?? [])
            ->pluck('url')
            ->filter()
            ->values()
            ->all();

        $this->assertContains('app-modules/*', $urls);

        foreach ($this->expectedModules() as $name) {
            $this->assertSame(
                '*',
                $composer['require']["modules/{$name}"] ?? null,
                "composer.json must require modules/{$name}:*"
            );
        }
    }

    public function test_negative_gate_modules_have_no_domain_scaffold(): void
    {
        $forbiddenBasenamePatterns = [
            '/Model\.php$/',
            '/Controller\.php$/',
            '/Policy\.php$/',
            '/Factory\.php$/',
            '/Seeder\.php$/',
            '/Migration\.php$/',
            '/Livewire\.php$/',
        ];

        $phpFiles = File::allFiles(base_path('app-modules'));
        $phpPaths = [];

        foreach ($phpFiles as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $phpPaths[] = $relative;

            foreach ($forbiddenBasenamePatterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $file->getFilename(),
                    "Domain scaffold file is forbidden in W0-T5: {$relative}"
                );
            }

            // Public/ must stay a boundary directory only — no PHP contracts yet.
            $this->assertStringNotContainsString(
                '/Public/',
                '/'.$relative,
                "Public/ must not contain PHP classes in W0-T5: {$relative}"
            );

            // Only ServiceProviders are allowed under src/ for this task.
            $this->assertMatchesRegularExpression(
                '#^[^/]+/src/Providers/[A-Za-z]+ServiceProvider\.php$#',
                $relative,
                "Only module ServiceProviders may exist under app-modules in W0-T5: {$relative}"
            );
        }

        $this->assertCount(
            6,
            $phpPaths,
            'Exactly one ServiceProvider PHP file per module is allowed (no domain scaffold).'
        );

        // Negative: no module-owned migrations directory scaffold.
        foreach ($this->expectedModules() as $name) {
            $this->assertDirectoryDoesNotExist(
                base_path("app-modules/{$name}/database/migrations"),
                "Module [{$name}] must not ship domain migrations in W0-T5."
            );
            $this->assertDirectoryDoesNotExist(
                base_path("app-modules/{$name}/src/Domain"),
                "Module [{$name}] must not pre-scaffold Domain/ layer in W0-T5."
            );
            $this->assertDirectoryDoesNotExist(
                base_path("app-modules/{$name}/src/Application"),
                "Module [{$name}] must not pre-scaffold Application/ layer in W0-T5."
            );
            $this->assertFileDoesNotExist(
                base_path("app-modules/{$name}/routes/web.php"),
                "Module [{$name}] must not register domain routes in W0-T5."
            );
        }
    }
}
