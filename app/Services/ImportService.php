<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\Lead;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel / CSV import: sheet + header detection, column mapping, preview validation and idempotent processing.
 */
class ImportService
{
    /**
     * Header aliases (normalised: lower case ascii, alphanumerics only) for automatic column detection.
     *
     * @var array<string, list<string>>
     */
    public const ALIASES = [
        'name' => ['nev', 'name', 'fullname', 'teljesnev', 'ugyfel', 'ugyfelnev', 'kapcsolattarto', 'kontakt', 'contact', 'contactname', 'vollstandigername', 'kunde', 'kundenname'],
        'first_name' => ['keresztnev', 'utonev', 'firstname', 'givenname', 'vorname'],
        'last_name' => ['vezeteknev', 'csaladnev', 'lastname', 'surname', 'familyname', 'nachname'],
        'email' => ['email', 'emailcim', 'mail', 'emailaddress', 'emailadresse', 'emailadres'],
        'phone' => ['telefon', 'telefonszam', 'tel', 'mobil', 'mobilszam', 'phone', 'phonenumber', 'mobile', 'telefonnummer', 'handy'],
        'company' => ['ceg', 'cegnev', 'vallalat', 'vallalkozas', 'company', 'companyname', 'organization', 'organisation', 'firma', 'unternehmen'],
        'address' => ['cim', 'lakcim', 'szekhely', 'address', 'adresse', 'anschrift'],
        'notes' => ['megjegyzes', 'jegyzet', 'notes', 'note', 'comment', 'comments', 'notiz', 'notizen', 'bemerkung'],
        'message' => ['uzenet', 'message', 'nachricht'],
        'tags' => ['cimke', 'cimkek', 'tags', 'tag', 'schlagworter', 'labels'],
        'category' => ['kategoria', 'category', 'kategorie'],
        'status' => ['statusz', 'status', 'allapot'],
        'type' => ['tipus', 'type', 'typ'],
    ];

    /**
     * @var array<string, list<string>>
     */
    public const TARGET_FIELDS = [
        'clients' => ['name', 'first_name', 'last_name', 'company', 'email', 'phone', 'address', 'type', 'status', 'category', 'tags', 'notes'],
        'leads' => ['name', 'first_name', 'last_name', 'company', 'email', 'phone', 'message', 'status', 'tags'],
    ];

    public function __construct(protected PlanService $plans, protected AuditLogger $audit) {}

    /**
     * Reads the sheet list and headers of a freshly uploaded file and stores a suggested mapping.
     */
    public function inspect(Import $import, ?string $sheet = null): Import
    {
        $spreadsheet = $this->load($import);
        $sheets = $spreadsheet->getSheetNames();
        $worksheet = $sheet && in_array($sheet, $sheets, true) ? $spreadsheet->getSheetByName($sheet) : $spreadsheet->getSheet(0);

        [$headerRow, $headers] = $this->detectHeader($worksheet);
        $total = max(0, $this->lastDataRow($worksheet) - $headerRow);

        $import->forceFill([
            'sheets' => $sheets,
            'sheet' => $worksheet->getTitle(),
            'headers' => $headers,
            'mapping' => $this->suggestMapping($headers, $import->target),
            'total_rows' => $total,
            'status' => 'uploaded',
        ])->save();

        return $import;
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string|null>
     */
    public function suggestMapping(array $headers, string $target): array
    {
        $allowed = self::TARGET_FIELDS[$target];
        $mapping = [];
        $used = [];

        foreach ($headers as $index => $header) {
            $normalised = $this->normalise($header);
            $mapping[(string) $index] = null;

            foreach (self::ALIASES as $field => $aliases) {
                if (in_array($field, $allowed, true) && ! in_array($field, $used, true) && in_array($normalised, $aliases, true)) {
                    $mapping[(string) $index] = $field;
                    $used[] = $field;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Validates the first $limit rows with the current mapping without writing anything.
     *
     * @return array{rows: list<array{row: int, values: array<string, mixed>, errors: list<string>, duplicate: bool}>, summary: array{valid: int, invalid: int, duplicates: int}}
     */
    public function preview(Import $import, int $limit = 20): array
    {
        $rows = [];
        $summary = ['valid' => 0, 'invalid' => 0, 'duplicates' => 0];
        $seen = [];

        foreach ($this->rows($import) as $rowNumber => $raw) {
            $values = $this->mapRow($raw, $import->mapping ?? []);
            $errors = $this->validate($values);
            $duplicate = $errors === [] && ($this->findDuplicate($import, $values) !== null || $this->seenBefore($values, $seen));

            $summary[$errors ? 'invalid' : 'valid']++;
            if ($duplicate) {
                $summary['duplicates']++;
            }

            if (count($rows) < $limit) {
                $rows[] = ['row' => $rowNumber, 'values' => $values, 'errors' => $errors, 'duplicate' => $duplicate];
            }
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /**
     * Processes all rows. Safe to retry: rows already recorded in import_rows are skipped,
     * so an interrupted import never creates duplicated records.
     */
    public function process(Import $import): Import
    {
        $workspace = Workspace::findOrFail($import->workspace_id);
        $import->forceFill(['status' => 'processing', 'started_at' => $import->started_at ?? now(), 'error' => null])->save();

        $done = ImportRow::where('import_id', $import->id)->pluck('row_number')->flip();
        $maxRows = $this->plans->limit($workspace, 'import_max_rows');
        $processed = $done->count();
        $seen = [];

        try {
            foreach ($this->rows($import) as $rowNumber => $raw) {
                $values = $this->mapRow($raw, $import->mapping ?? []);

                if ($done->has($rowNumber)) {
                    $this->seenBefore($values, $seen);

                    continue;
                }

                if ($maxRows !== null && $processed >= $maxRows) {
                    $this->record($import, $rowNumber, 'skipped', [__('crm.import.row_limit', ['max' => $maxRows])]);

                    continue;
                }

                $processed++;
                $this->processRow($workspace, $import, $rowNumber, $values, $seen);
            }

            $import->forceFill(['status' => 'completed', 'finished_at' => now()])->save();
            $this->audit->log('import.completed', $import, ['target' => $import->target], $import->workspace_id);
        } catch (\Throwable $exception) {
            $import->forceFill(['status' => 'failed', 'error' => Str::limit($exception->getMessage(), 500)])->save();
            report($exception);
        } finally {
            $this->refreshCounters($import);
        }

        if ($import->status === 'completed' && $import->path) {
            Storage::disk($import->disk)->delete($import->path);
            $import->forceFill(['path' => null])->save();
        }

        return $import->refresh();
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, true>  $seen
     */
    protected function processRow(Workspace $workspace, Import $import, int $rowNumber, array $values, array &$seen): void
    {
        $errors = $this->validate($values);

        if ($errors !== []) {
            $this->record($import, $rowNumber, 'failed', $errors);

            return;
        }

        if ($this->seenBefore($values, $seen)) {
            $this->record($import, $rowNumber, 'skipped', [__('crm.import.duplicate_in_file')]);

            return;
        }

        DB::transaction(function () use ($workspace, $import, $rowNumber, $values): void {
            $existing = $this->findDuplicate($import, $values);
            $attributes = $this->attributesFor($import->target, $values);

            if ($existing) {
                $canUpdate = $import->duplicate_mode === 'update' && $this->plans->hasFeature($workspace, 'import_update_duplicates');

                if (! $canUpdate) {
                    $this->record($import, $rowNumber, 'skipped', [__('crm.import.duplicate_skipped')], $existing->getKey());

                    return;
                }

                $existing->fill(array_filter($attributes, fn ($value) => $value !== null && $value !== ''))->save();
                $this->record($import, $rowNumber, 'updated', [], $existing->getKey());

                return;
            }

            $limitKey = $import->target === 'clients' ? 'clients' : 'active_leads';
            $remaining = $this->plans->remaining($workspace, $limitKey);

            if ($remaining !== null && $remaining <= 0) {
                $this->record($import, $rowNumber, 'skipped', [__('crm.plan_limit_reached')]);

                return;
            }

            $model = $import->target === 'clients' ? new Client : new Lead;
            $model->forceFill($attributes + ['workspace_id' => $import->workspace_id, 'source' => 'import', 'owner_id' => $import->user_id])->save();

            $this->record($import, $rowNumber, 'imported', [], $model->getKey());
        });
    }

    /**
     * @param  list<string>  $messages
     */
    protected function record(Import $import, int $rowNumber, string $status, array $messages = [], ?int $recordId = null): void
    {
        ImportRow::updateOrCreate(
            ['import_id' => $import->id, 'row_number' => $rowNumber],
            ['status' => $status, 'messages' => $messages ?: null, 'record_id' => $recordId],
        );
    }

    public function refreshCounters(Import $import): void
    {
        $counts = ImportRow::where('import_id', $import->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $import->forceFill([
            'imported_count' => (int) ($counts['imported'] ?? 0),
            'updated_count' => (int) ($counts['updated'] ?? 0),
            'skipped_count' => (int) ($counts['skipped'] ?? 0),
            'failed_count' => (int) ($counts['failed'] ?? 0),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function validate(array $values): array
    {
        $errors = [];

        if (blank($values['name'] ?? null) && blank($values['email'] ?? null) && blank($values['company'] ?? null)) {
            $errors[] = __('crm.import.name_required');
        }

        if (filled($values['email'] ?? null) && ! filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('crm.import.invalid_email', ['value' => $values['email']]);
        }

        if (filled($values['phone'] ?? null) && ! preg_match('/^[0-9+()\/\-\s.]{5,30}$/', (string) $values['phone'])) {
            $errors[] = __('crm.import.invalid_phone', ['value' => $values['phone']]);
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function findDuplicate(Import $import, array $values): ?Model
    {
        $query = ($import->target === 'clients' ? Client::query() : Lead::query())
            ->withoutGlobalScopes()
            ->where('workspace_id', $import->workspace_id)
            ->whereNull('deleted_at');

        if (filled($values['email'] ?? null)) {
            return $query->whereRaw('lower(email) = ?', [Str::lower($values['email'])])->first();
        }

        if (filled($values['phone'] ?? null)) {
            return $query->where('phone', $values['phone'])->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, true>  $seen
     */
    protected function seenBefore(array $values, array &$seen): bool
    {
        $key = filled($values['email'] ?? null) ? 'e:'.Str::lower($values['email']) : (filled($values['phone'] ?? null) ? 'p:'.$values['phone'] : null);

        if ($key === null) {
            return false;
        }

        if (isset($seen[$key])) {
            return true;
        }

        $seen[$key] = true;

        return false;
    }

    /**
     * @param  list<mixed>  $raw
     * @param  array<string, string|null>  $mapping
     * @return array<string, mixed>
     */
    public function mapRow(array $raw, array $mapping): array
    {
        $values = [];

        foreach ($mapping as $index => $field) {
            if ($field) {
                $value = $raw[(int) $index] ?? null;
                $values[$field] = is_string($value) ? trim($value) : $value;
            }
        }

        if (blank($values['name'] ?? null) && (filled($values['first_name'] ?? null) || filled($values['last_name'] ?? null))) {
            /* Hungarian order: family name first. */
            $values['name'] = trim(($values['last_name'] ?? '').' '.($values['first_name'] ?? ''));
        }

        unset($values['first_name'], $values['last_name']);

        if (blank($values['name'] ?? null) && filled($values['company'] ?? null)) {
            $values['name'] = $values['company'];
        }

        if (filled($values['email'] ?? null)) {
            $values['email'] = Str::lower((string) $values['email']);
        }

        if (isset($values['phone']) && is_numeric($values['phone'])) {
            $values['phone'] = (string) $values['phone'];
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function attributesFor(string $target, array $values): array
    {
        $attributes = [
            'name' => Str::limit((string) ($values['name'] ?? $values['email']), 250, ''),
            'company' => $values['company'] ?? null,
            'email' => $values['email'] ?? null,
            'phone' => $values['phone'] ?? null,
            'tags' => filled($values['tags'] ?? null) ? array_values(array_filter(array_map('trim', preg_split('/[,;|]/', (string) $values['tags'])))) : null,
        ];

        if ($target === 'clients') {
            $status = Str::lower((string) ($values['status'] ?? ''));
            $type = Str::lower((string) ($values['type'] ?? ''));

            return $attributes + [
                'address' => $values['address'] ?? null,
                'category' => $values['category'] ?? null,
                'notes' => $values['notes'] ?? null,
                'status' => in_array($status, Client::STATUSES, true) ? $status : 'active',
                'type' => in_array($type, Client::TYPES, true) ? $type : (filled($values['company'] ?? null) ? 'company' : 'person'),
            ];
        }

        $status = Str::lower((string) ($values['status'] ?? ''));

        return $attributes + [
            'message' => $values['message'] ?? null,
            'status' => in_array($status, ['new', 'contacted', 'qualified'], true) ? $status : 'new',
        ];
    }

    /**
     * Iterates the data rows of the selected sheet, keyed by the spreadsheet row number.
     *
     * @return \Generator<int, list<mixed>>
     */
    public function rows(Import $import): \Generator
    {
        $spreadsheet = $this->load($import);
        $worksheet = ($import->sheet ? $spreadsheet->getSheetByName($import->sheet) : null) ?? $spreadsheet->getSheet(0);
        [$headerRow, $headers] = $this->detectHeader($worksheet);
        $lastRow = $this->lastDataRow($worksheet);
        $columns = max(1, count($headers));

        for ($row = $headerRow + 1; $row <= $lastRow; $row++) {
            $values = [];

            for ($column = 1; $column <= $columns; $column++) {
                $cell = $worksheet->getCell([$column, $row]);
                $value = $cell->getValue();

                if ($value instanceof RichText) {
                    $value = $value->getPlainText();
                } elseif (is_string($value) && str_starts_with($value, '=')) {
                    $value = $cell->getCalculatedValue();
                }

                $values[] = $value;
            }

            if (array_filter($values, fn ($value) => $value !== null && $value !== '') === []) {
                continue;
            }

            yield $row => $values;
        }
    }

    /**
     * The first row containing at least two text cells is treated as the header row.
     *
     * @return array{0: int, 1: list<string>}
     */
    protected function detectHeader(Worksheet $worksheet): array
    {
        $highestColumn = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());
        $limit = min(10, $worksheet->getHighestDataRow());

        for ($row = 1; $row <= $limit; $row++) {
            $cells = [];

            for ($column = 1; $column <= $highestColumn; $column++) {
                $cells[] = trim((string) $worksheet->getCell([$column, $row])->getValue());
            }

            if (count(array_filter($cells, fn ($cell) => $cell !== '' && ! is_numeric($cell))) >= 2) {
                while ($cells !== [] && end($cells) === '') {
                    array_pop($cells);
                }

                return [$row, array_map(fn ($cell, $index) => $cell !== '' ? $cell : 'Column '.($index + 1), $cells, array_keys($cells))];
            }
        }

        return [1, array_map(fn ($index) => 'Column '.$index, range(1, max(1, $highestColumn)))];
    }

    protected function lastDataRow(Worksheet $worksheet): int
    {
        return $worksheet->getHighestDataRow();
    }

    protected function load(Import $import): Spreadsheet
    {
        $path = Storage::disk($import->disk)->path($import->path);
        $extension = Str::lower(pathinfo($import->original_name, PATHINFO_EXTENSION));

        if (in_array($extension, ['csv', 'txt'], true)) {
            $reader = new Csv;
            $reader->setInputEncoding(Csv::guessEncoding($path, 'CP1250'));
            $reader->setDelimiter(null);
            $reader->setTestAutoDetect(true);

            return $reader->load($path);
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        return $reader->load($path);
    }

    protected function normalise(string $header): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($header)));
    }
}
