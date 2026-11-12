<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImportResource;
use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Services\AuditLogger;
use App\Services\ImportService;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ImportController extends Controller
{
    public function __construct(protected ImportService $imports, protected PlanService $plans, protected AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        return ImportResource::collection(Import::with('user')->latest()->limit(50)->get());
    }

    /**
     * Uploads the file to private storage and detects sheets, header row and column mapping.
     */
    public function store(Request $request): JsonResponse
    {
        $workspace = $this->workspace();
        $this->plans->ensureWithinLimit($workspace, 'imports');

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('plans.fair_use.import_max_file_kb'), 'mimes:csv,txt,xlsx,xls'],
            'target' => ['sometimes', Rule::in(Import::TARGETS)],
        ]);

        $file = $data['file'];
        $extension = Str::lower($file->getClientOriginalExtension() ?: $file->extension());
        $path = $file->storeAs('imports/'.$workspace->id, Str::uuid().'.'.$extension, 'local');

        $import = Import::create([
            'user_id' => $request->user()->id,
            'target' => $data['target'] ?? 'clients',
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'disk' => 'local',
            'path' => $path,
            'status' => 'uploaded',
        ]);

        try {
            $this->imports->inspect($import);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            $import->delete();
            report($exception);

            throw ValidationException::withMessages(['file' => 'The file could not be read.']);
        }

        return response()->json($this->payload($import), 201);
    }

    public function show(Import $import): JsonResponse
    {
        return response()->json($this->payload($import));
    }

    /**
     * Changes sheet, target, column mapping or the duplicate strategy before starting.
     */
    public function update(Request $request, Import $import): JsonResponse
    {
        $this->ensureEditable($import);
        $data = $request->validate([
            'sheet' => ['sometimes', 'string', Rule::in($import->sheets ?? [])],
            'target' => ['sometimes', Rule::in(Import::TARGETS)],
            'mapping' => ['sometimes', 'array'],
            'mapping.*' => ['nullable', 'string'],
            'duplicate_mode' => ['sometimes', Rule::in(['skip', 'update'])],
        ]);

        if (($data['duplicate_mode'] ?? null) === 'update') {
            $this->plans->ensureFeature($this->workspace(), 'import_update_duplicates');
        }

        if (isset($data['target']) && $data['target'] !== $import->target) {
            $import->target = $data['target'];
            $import->mapping = $this->imports->suggestMapping($import->headers ?? [], $import->target);
        }

        if (isset($data['sheet']) && $data['sheet'] !== $import->sheet) {
            $import->save();
            $this->imports->inspect($import, $data['sheet']);
        }

        if (isset($data['mapping'])) {
            $allowed = ImportService::TARGET_FIELDS[$import->target];
            $mapping = [];

            foreach (array_keys($import->headers ?? []) as $index) {
                $field = $data['mapping'][(string) $index] ?? null;
                $mapping[(string) $index] = in_array($field, $allowed, true) ? $field : null;
            }

            $import->mapping = $mapping;
        }

        if (isset($data['duplicate_mode'])) {
            $import->duplicate_mode = $data['duplicate_mode'];
        }

        $import->save();

        return response()->json($this->payload($import));
    }

    public function start(Import $import): JsonResponse
    {
        $this->ensureEditable($import, allowFailed: true);
        $this->plans->ensureWithinLimit($this->workspace(), 'imports');

        if (! in_array('name', $import->mapping ?? [], true) && ! in_array('email', $import->mapping ?? [], true)
            && ! in_array('company', $import->mapping ?? [], true) && ! in_array('last_name', $import->mapping ?? [], true)) {
            throw ValidationException::withMessages(['mapping' => 'Map at least a name, e-mail or company column.']);
        }

        $import->forceFill(['status' => 'queued'])->save();
        $this->audit->log('import.started', $import, ['rows' => $import->total_rows]);
        ProcessImport::dispatch($import->id);

        return response()->json(['data' => ImportResource::make($import->refresh())]);
    }

    public function rows(Request $request, Import $import): JsonResponse
    {
        $rows = $import->rows()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->paginate($this->perPage($request, 50));

        return response()->json($rows);
    }

    public function destroy(Import $import): JsonResponse
    {
        if (in_array($import->status, ['queued', 'processing'], true)) {
            throw ValidationException::withMessages(['import' => 'The import is running.']);
        }

        if ($import->path) {
            Storage::disk($import->disk)->delete($import->path);
        }

        $import->delete();

        return response()->json(['ok' => true]);
    }

    protected function ensureEditable(Import $import, bool $allowFailed = false): void
    {
        $allowed = $allowFailed ? ['uploaded', 'failed'] : ['uploaded'];

        if (! in_array($import->status, $allowed, true) || ! $import->path) {
            throw ValidationException::withMessages(['import' => 'This import can no longer be changed.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Import $import): array
    {
        $editable = $import->status === 'uploaded' && $import->path;

        return [
            'data' => ImportResource::make($import),
            'fields' => ImportService::TARGET_FIELDS[$import->target],
            'max_rows' => $this->plans->limit($this->workspace(), 'import_max_rows'),
            'can_update_duplicates' => $this->plans->hasFeature($this->workspace(), 'import_update_duplicates'),
            'preview' => $editable ? $this->imports->preview($import) : null,
        ];
    }
}
