<?php

namespace App\Console\Commands;

use App\Models\Quote;
use App\Models\Workspace;
use App\Services\PlanService;
use App\Services\Quotes\QuoteService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crm:quote-follow-ups')]
#[Description('Expire quotes past their validity and remind owners to follow up unanswered quotes')]
class SendQuoteFollowUps extends Command
{
    public function handle(QuoteService $quotes, PlanService $plans): int
    {
        $expired = $quotes->expireOverdue();
        $reminded = 0;

        Quote::withoutGlobalScopes()
            ->with('owner')
            ->whereIn('status', Quote::AWAITING)
            ->whereNull('deleted_at')
            ->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', now())
            ->orderBy('workspace_id')
            ->chunkById(200, function ($batch) use ($quotes, $plans, &$reminded): void {
                $workspaces = Workspace::whereIn('id', $batch->pluck('workspace_id')->unique())->get()->keyBy('id');

                foreach ($batch as $quote) {
                    $workspace = $workspaces[$quote->workspace_id] ?? null;

                    if (! $workspace || $workspace->suspended_at || ! $plans->hasFeature($workspace, 'quote_follow_ups')) {
                        $quote->forceFill(['follow_up_at' => null])->saveQuietly();

                        continue;
                    }

                    $quotes->followUp($quote, $workspace);
                    $reminded++;
                }
            });

        $this->info("Expired {$expired} quotes, sent {$reminded} follow-up reminders.");

        return self::SUCCESS;
    }
}
