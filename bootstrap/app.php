<?php

use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureWorkspaceRole;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [SetLocale::class]);

        $middleware->alias([
            'workspace' => ResolveWorkspace::class,
            'workspace.role' => EnsureWorkspaceRole::class,
            'super-admin' => EnsureSuperAdmin::class,
            'verified-email' => EnsureEmailIsVerified::class,
        ]);

        /* The workspace must be resolved before route model binding so bindings are tenant scoped. */
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveWorkspace::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
