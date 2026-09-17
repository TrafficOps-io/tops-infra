<?php

namespace TrafficOps\LaravelCloud\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use TrafficOps\LaravelCloud\LaravelCloudServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LaravelCloudServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('l', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('laravel-cloud.api.token', 'test-token');
        $app['config']->set('laravel-cloud.api.environment_id', 'environment-one');
        $app['config']->set('laravel-cloud.api.retries', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_owners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('test_string_owners', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }
}
