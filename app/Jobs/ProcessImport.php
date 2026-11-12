<?php

namespace App\Jobs;

use App\Models\Import;
use App\Services\ImportService;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $importId) {}

    public function uniqueId(): string
    {
        return (string) $this->importId;
    }

    public function handle(ImportService $imports, CurrentWorkspace $current): void
    {
        $import = Import::withoutGlobalScopes()->find($this->importId);

        if (! $import || $import->status === 'completed') {
            return;
        }

        $workspace = $import->workspace()->first();
        $current->set($workspace);
        /* Row messages are stored in the language of the user who started the import. */
        app()->setLocale($import->user?->preferredLocale() ?? $workspace->locale);

        try {
            $imports->process($import);
        } finally {
            $current->clear();
        }
    }
}
