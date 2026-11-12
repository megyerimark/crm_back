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
use App\Services\Billing\BillingGateway;
use App\Services\Billing\StripeBillingGateway;
use App\Support\CurrentWorkspace;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentWorkspace::class);
        $this->app->bind(BillingGateway::class, StripeBillingGateway::class);

        /* The workspace (not the user) is the paying customer; our own webhook route replaces Cashier's. */
        Cashier::useCustomerModel(Workspace::class);
        Cashier::ignoreRoutes();
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
            /* Per account: the e-mail address, or the 2FA challenge (which identifies the pending login). */
            Limit::perMinute(5)->by('auth-id:'.(filled($request->input('email')) ? strtolower((string) $request->input('email')) : sha1((string) $request->input('challenge')))),
        ]);

        RateLimiter::for('public-form', fn (Request $request) => [
            Limit::perMinute(5)->by('form:'.$request->route('slug').':'.$request->ip()),
            Limit::perHour(30)->by('form-ip:'.$request->ip()),
        ]);

        /* Public quote links: generous for viewing, strict on accept/decline and on token guessing per IP. */
        RateLimiter::for('public-quote', fn (Request $request) => $request->isMethod('GET')
            ? [Limit::perMinute(60)->by('quote-ip:'.$request->ip())]
            : [Limit::perMinute(5)->by('quote-answer:'.$request->ip())]);
    }
}
