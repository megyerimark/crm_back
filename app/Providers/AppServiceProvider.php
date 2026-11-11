<?php

namespace App\Providers;

use App\Models\AutomationRule;
use App\Models\Client;
use App\Models\CustomFieldDefinition;
use App\Models\Deal;
use App\Models\Form;
use App\Models\Import;
use App\Models\Invitation;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentWorkspace::class);
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'workspace' => Workspace::class,
            'client' => Client::class,
            'lead' => Lead::class,
            'deal' => Deal::class,
            'task' => Task::class,
            'form' => Form::class,
            'import' => Import::class,
            'invitation' => Invitation::class,
            'pipeline' => Pipeline::class,
            'automation_rule' => AutomationRule::class,
            'custom_field' => CustomFieldDefinition::class,
        ]);

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->letters()->numbers()->uncompromised()
            : Password::min(8));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('auth:'.$request->ip()),
            Limit::perMinute(5)->by('auth-email:'.strtolower((string) $request->input('email'))),
        ]);

        RateLimiter::for('public-form', fn (Request $request) => [
            Limit::perMinute(5)->by('form:'.$request->route('slug').':'.$request->ip()),
            Limit::perHour(30)->by('form-ip:'.$request->ip()),
        ]);
    }
}
