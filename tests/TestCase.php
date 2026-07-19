<?php

namespace Jvjvjv\CodeTalker\Tests;

use Jvjvjv\CodeTalker\CodeTalkerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Ai\AiServiceProvider::class,
            CodeTalkerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $app['config']->set('code-talker.schedule', false);
        $app['config']->set('code-talker.user_model', \Illuminate\Foundation\Auth\User::class);
    }
}