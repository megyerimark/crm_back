<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Import;
use App\Models\ImportRow;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function csv(): UploadedFile
    {
        $content = "Név;E-mail cím;Telefonszám;Cégnév;Megjegyzés\n"
            ."Kiss János;janos@example.com;+36 30 111 2222;Kiss Kft.;VIP\n"
            ."Nagy Éva;eva@example.com;+36 30 333 4444;;\n"
            ."Hibás Sor;nem-email;;;\n"
            ."Kiss János másodszor;JANOS@example.com;;;\n"
            .";;;;\n"
            ."Meglévő Ügyfél;letezo@example.com;;;Frissítve\n";

        return UploadedFile::fake()->createWithContent('ugyfelek.csv', $content);
    }

    public function test_upload_detects_columns_and_previews_rows(): void
    {
        $this->signIn();

        $response = $this->post('/api/v1/imports', ['file' => $this->csv()], ['Accept' => 'application/json'])->assertCreated();

        $response->assertJsonPath('data.mapping', ['0' => 'name', '1' => 'email', '2' => 'phone', '3' => 'company', '4' => 'notes'])
            ->assertJsonPath('data.total_rows', 6)
            ->assertJsonPath('preview.summary.valid', 4)
            ->assertJsonPath('preview.summary.invalid', 1)
            ->assertJsonPath('preview.summary.duplicates', 1);
    }

    public function test_import_skips_duplicates_and_reports_failures(): void
    {
        $this->signIn();
        Client::factory()->create(['workspace_id' => $this->workspace->id, 'email' => 'letezo@example.com', 'notes' => null]);
        $id = $this->post('/api/v1/imports', ['file' => $this->csv()], ['Accept' => 'application/json'])->json('data.id');

        $this->postJson("/api/v1/imports/{$id}/start")->assertOk();

        $import = Import::findOrFail($id);
        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->imported_count);
        $this->assertSame(2, $import->skipped_count);
        $this->assertSame(1, $import->failed_count);
        $this->assertSame(3, Client::count());
        $this->assertSame('import', Client::where('email', 'janos@example.com')->value('source'));
        $this->assertNull($import->path, 'uploaded file is deleted after a successful import');

        $this->getJson("/api/v1/imports/{$id}/rows?status=failed")->assertOk()->assertJsonPath('data.0.row_number', 4);
    }

    public function test_update_mode_overwrites_existing_records_on_pro(): void
    {
        $this->signIn('pro');
        $existing = Client::factory()->create(['workspace_id' => $this->workspace->id, 'email' => 'letezo@example.com', 'notes' => null]);
        $id = $this->post('/api/v1/imports', ['file' => $this->csv()], ['Accept' => 'application/json'])->json('data.id');

        $this->patchJson("/api/v1/imports/{$id}", ['duplicate_mode' => 'update'])->assertOk();
        $this->postJson("/api/v1/imports/{$id}/start")->assertOk();

        $this->assertSame('Frissítve', $existing->fresh()->notes);
        $this->assertSame(1, Import::find($id)->updated_count);
    }

    public function test_free_plan_cannot_use_update_mode_and_has_row_limit(): void
    {
        $this->signIn('free');
        $id = $this->post('/api/v1/imports', ['file' => $this->csv()], ['Accept' => 'application/json'])->json('data.id');

        $this->patchJson("/api/v1/imports/{$id}", ['duplicate_mode' => 'update'])->assertStatus(402);
        $this->postJson("/api/v1/imports/{$id}/start")->assertOk();

        /* Only one import is allowed on Free. */
        $this->post('/api/v1/imports', ['file' => $this->csv()], ['Accept' => 'application/json'])->assertStatus(402);
    }

    public function test_processing_is_idempotent_when_retried(): void
    {
        $this->signIn();
        $id = $this->post('/api/v1/imports', ['file' => $this->csv()], ['Accept' => 'application/json'])->json('data.id');
        $import = Import::findOrFail($id);

        /* Simulate an interrupted run: the first row was written, then the worker died. */
        $client = Client::create(['workspace_id' => $this->workspace->id, 'name' => 'Kiss János', 'email' => 'janos@example.com', 'source' => 'import']);
        ImportRow::create(['import_id' => $import->id, 'row_number' => 2, 'status' => 'imported', 'record_id' => $client->id]);

        app(ImportService::class)->process($import);
        app(ImportService::class)->process($import->fresh());

        $this->assertSame(1, Client::where('email', 'janos@example.com')->count());
        $this->assertSame(3, Client::count());
        $this->assertSame(1, ImportRow::where('import_id', $import->id)->where('row_number', 2)->count());
    }

    public function test_xlsx_with_title_row_and_split_names(): void
    {
        $this->signIn();
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Ügyfelek')->fromArray([
            ['Ügyféllista 2026'],
            ['Vezetéknév', 'Keresztnév', 'Email', 'Mobil'],
            ['Szabó', 'Anna', 'anna@example.com', '+36301234567'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($spreadsheet))->save($path);

        $response = $this->post('/api/v1/imports', ['file' => new UploadedFile($path, 'lista.xlsx', null, null, true)], ['Accept' => 'application/json'])->assertCreated();
        $response->assertJsonPath('data.sheets', ['Ügyfelek'])->assertJsonPath('data.mapping.0', 'last_name')->assertJsonPath('preview.rows.0.values.name', 'Szabó Anna');

        $this->postJson('/api/v1/imports/'.$response->json('data.id').'/start')->assertOk();
        $this->assertSame('Szabó Anna', Client::firstOrFail()->name);
    }

    public function test_rejects_unsupported_file_types(): void
    {
        $this->signIn();

        $this->post('/api/v1/imports', ['file' => UploadedFile::fake()->create('virus.exe', 10)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }
}
