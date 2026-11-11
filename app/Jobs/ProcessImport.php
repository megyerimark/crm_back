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

        $current->set($import->workspace()->first());

        try {
            $imports->process($import);
        } finally {
            $current->clear();
        }
    }
}
