<?php

namespace App\Console\Commands;

use App\Models\Import;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('crm:prune-import-files {--hours=24}')]
#[Description('Delete uploaded import files that were never processed or are no longer needed')]
class PruneImportFiles extends Command
{
    public function handle(): int
    {
        $count = 0;

        Import::withoutGlobalScopes()
            ->whereNotNull('path')
            ->whereNotIn('status', ['queued', 'processing'])
            ->where('updated_at', '<', now()->subHours((int) $this->option('hours')))
            ->each(function (Import $import) use (&$count): void {
                Storage::disk($import->disk)->delete($import->path);
                $import->forceFill(['path' => null, 'status' => $import->status === 'uploaded' ? 'expired' : $import->status])->save();
                $count++;
            });

        $this->info("Pruned {$count} files.");

        return self::SUCCESS;
    }
}
