<?php

namespace Tests\Feature;

use App\Exports\ArrayExport;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\ClassListCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** FR-CLS-16: check a class list (Name, Section) against who has joined. Read only. */
class ClassListCheckTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->class = $this->makeClass(null, ['subject' => 'ITE101', 'section' => 'BSIT-4B']);
        $bsit = fn ($name, $no, $year, $sec) => User::factory()->create(['name' => $name, 'id_number' => $no, 'course' => 'BSIT', 'year_level' => $year, 'section' => $sec]);

        $this->enroll($this->class, $bsit('Maria Teresa A. Loreno', '2023-00001', 4, 'B'));
        $this->enroll($this->class, $bsit('Juan Dela Cruz', '2023-00002', 4, 'A'), Enrollment::PENDING);
        $this->enroll($this->class, $bsit('Ana Reyes', '2023-00003', 4, 'B'));
        $this->enroll($this->class, $bsit('Ben Lim', '2023-00004', 4, 'B'), Enrollment::REMOVED);
        $bsit('Pedro Santos', '2023-00005', 4, 'B'); // has an account, never joined
    }

    /** The list used by most tests, as rows under the headings. */
    private function rows(): array
    {
        return [
            ['Loreno, Maria Teresa', '4-B'],      // Joined: surname first, no middle initial
            ['juan  dela cruz.', 'BSIT 4-A'],     // Pending: case, spaces, period ignored
            ['Reyes, Ana', '4-A'],                // Possible match: in class as BSIT 4-B
            ['ANA REYES', 'B'],                   // Joined: section letter only
            ['Ben Lim', 'B'],                     // Not joined: removed
            ['Pedro Santos', '4-B'],              // Not joined: never joined
            ['', ''],                             // empty row: skipped
            ['', '4-B'],                          // missing name: reported
        ];
    }

    private function csv(array $rows, array $headings = ['Name', 'Section']): UploadedFile
    {
        $lines = array_map(fn ($r) => implode(',', array_map(fn ($c) => '"'.$c.'"', $r)), [$headings, ...$rows]);

        return UploadedFile::fake()->createWithContent('class-list.csv', implode("\n", $lines));
    }

    private function xlsx(array $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('class-list.xlsx', Excel::raw(new ArrayExport(['Student Name', 'Yr & Sec'], $rows), ExcelFormat::XLSX));
    }

    private function upload(UploadedFile $file, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->class->teacher)->post(route('class-list.check', $this->class), ['file' => $file]);
    }

    private function assertGroups(array $result): void
    {
        $names = fn ($group) => array_column($result['groups'][$group], 'name');
        $this->assertSame(['Loreno, Maria Teresa', 'ANA REYES'], $names(ClassListCheckService::JOINED));
        $this->assertSame(['juan  dela cruz.'], $names(ClassListCheckService::PENDING));
        $this->assertSame(['Reyes, Ana'], $names(ClassListCheckService::POSSIBLE));
        $this->assertSame(['Ben Lim', 'Pedro Santos'], $names(ClassListCheckService::NOT_JOINED));
        $this->assertSame(['row 9'], $result['missing_name']); // line 9: heading is line 1
        $this->assertSame(7, $result['rows']);
        $this->assertSame(['2023-00001', '2023-00003'], array_column($result['groups'][ClassListCheckService::JOINED], 'id_number'));
        $this->assertSame('BSIT 4-B', $result['groups'][ClassListCheckService::POSSIBLE][0]['student_section']);
    }

    public function test_fr_cls_16_csv_is_checked_into_groups_and_shown(): void
    {
        $this->upload($this->csv($this->rows()))->assertRedirect(route('classes.students', $this->class).'#class-list-check');
        $this->assertGroups(session(\App\Http\Controllers\ClassListCheckController::sessionKey($this->class)));

        $this->actingAs($this->class->teacher)->get(route('classes.students', $this->class))->assertOk()
            ->assertSeeInOrder(['Join requests', 'Check class list', 'class-list.csv', 'Joined', 'Loreno, Maria Teresa', '2023-00001',
                'Pending request', 'juan  dela cruz.', '2023-00002', 'Not joined', 'Ben Lim', 'Pedro Santos', 'Possible match', 'Reyes, Ana', 'In class as BSIT 4-B'])
            ->assertSee('1 row with no name was skipped: row 9.')
            ->assertSee('Download result (CSV)');
    }

    public function test_fr_cls_16_xlsx_with_other_headings_works_the_same(): void
    {
        $this->upload($this->xlsx($this->rows()))->assertSessionHasNoErrors();
        $this->assertGroups(session(\App\Http\Controllers\ClassListCheckController::sessionKey($this->class)));
    }

    public function test_fr_cls_16_name_order_and_section_formats(): void
    {
        $loreno = User::where('id_number', '2023-00001')->first();
        $same = fn ($a, $b) => ClassListCheckService::sameName(ClassListCheckService::nameTokens($a), ClassListCheckService::nameTokens($b));

        $this->assertTrue($same('Loreno, Maria Teresa', 'Maria Teresa A. Loreno'));
        $this->assertTrue($same('MARIA TERESA LORENO', 'Maria Teresa A. Loreno'));
        $this->assertTrue($same('loreno,  maria teresa a', 'Maria Teresa A. Loreno'));
        $this->assertFalse($same('Maria Loreno', 'Maria Teresa A. Loreno')); // a whole given name missing
        $this->assertFalse($same('Loreno', 'Maria Teresa A. Loreno'));

        foreach (['4-B', 'BSIT 4-B', 'bsit 4b', 'B', 'Section 4-B', ''] as $ok) {
            $this->assertTrue(ClassListCheckService::sameSection($ok, $loreno), $ok);
        }
        foreach (['4-A', 'A', 'BSIT 3-B', 'BSCS 4-B'] as $no) {
            $this->assertFalse(ClassListCheckService::sameSection($no, $loreno), $no);
        }
    }

    public function test_fr_cls_16_nothing_is_written_and_no_one_is_notified(): void
    {
        Notification::fake();
        $tables = ['users', 'enrollments', 'activity_log', 'notifications'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);
        $statuses = Enrollment::orderBy('id')->pluck('status', 'id');

        $this->upload($this->csv($this->rows()))->assertSessionHasNoErrors();
        $this->actingAs($this->class->teacher)->get(route('class-list.result', $this->class))->assertOk()->assertDownload();

        $this->assertEquals($before, collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]));
        $this->assertEquals($statuses, Enrollment::orderBy('id')->pluck('status', 'id'));
        Notification::assertNothingSent();
    }

    public function test_fr_cls_16_result_and_template_downloads(): void
    {
        $this->actingAs($this->class->teacher)->get(route('class-list.result', $this->class))->assertNotFound(); // nothing checked yet

        $this->upload($this->csv($this->rows()));
        $csv = $this->actingAs($this->class->teacher)->get(route('class-list.result', $this->class))->assertOk();
        $csv->assertDownload('ITE101_BSIT-4B_Class_list_check.csv');
        $body = $csv->streamedContent();
        $this->assertStringContainsString('"Group","Name","Section","Student No.","Section on record","Row"', $body);
        $this->assertStringContainsString('"Joined","Loreno, Maria Teresa","4-B","2023-00001","BSIT 4-B","row 2"', $body);
        $this->assertStringContainsString('"Possible match","Reyes, Ana","4-A","2023-00003","BSIT 4-B","row 4"', $body);
        $this->assertStringContainsString('"Missing name","","","","","row 9"', $body);

        $this->actingAs($this->class->teacher)->get(route('class-list.template', [$this->class, 'csv']))->assertOk()->assertDownload('class-list-template.csv');
        $this->actingAs($this->class->teacher)->get(route('class-list.template', [$this->class, 'xlsx']))->assertOk()->assertDownload('class-list-template.xlsx');

        // Clear forgets the result.
        $this->actingAs($this->class->teacher)->delete(route('class-list.clear', $this->class));
        $this->actingAs($this->class->teacher)->get(route('classes.students', $this->class))->assertDontSee('data-class-list-result', false);
    }

    public function test_fr_cls_16_bad_files_are_explained(): void
    {
        $this->upload($this->csv([['Ana Reyes', 'B']], ['Student No.', 'Section']))
            ->assertSessionHasErrors(['file' => 'Missing the "Name" column. Found in your file: "Student No.", "Section". Expected: Name (required), Section (optional).']);
        $this->upload($this->csv([]))->assertSessionHasErrors('file');
        $this->upload(UploadedFile::fake()->create('list.pdf', 10, 'application/pdf'))->assertSessionHasErrors(['file' => 'Upload a .csv or .xlsx file.']);
        $this->upload(UploadedFile::fake()->create('big.csv', 2100, 'text/csv'))->assertSessionHasErrors(['file' => 'The file must be 2 MB or smaller.']);

        // A title row above the headings is fine.
        $file = UploadedFile::fake()->createWithContent('titled.csv', "\"BSIT 4-B class list\",\n\"Name\",\"Section\"\n\"Ana Reyes\",\"4-B\"");
        $this->upload($file)->assertSessionHasNoErrors();
        $this->assertSame('Ana Reyes', session(\App\Http\Controllers\ClassListCheckController::sessionKey($this->class))['groups']['joined'][0]['name']);
    }

    public function test_fr_cls_16_a_file_without_a_section_column_matches_by_name(): void
    {
        $this->upload($this->csv([['Loreno, Maria Teresa'], ['Juan Dela Cruz'], ['Pedro Santos'], ['']], ['Name']))->assertSessionHasNoErrors();
        $result = session(\App\Http\Controllers\ClassListCheckController::sessionKey($this->class));

        $this->assertSame(['Loreno, Maria Teresa'], array_column($result['groups'][ClassListCheckService::JOINED], 'name'));
        $this->assertSame(['Juan Dela Cruz'], array_column($result['groups'][ClassListCheckService::PENDING], 'name'));
        $this->assertSame(['Pedro Santos'], array_column($result['groups'][ClassListCheckService::NOT_JOINED], 'name'));
        $this->assertSame([], $result['groups'][ClassListCheckService::POSSIBLE]);
        $this->assertSame(3, $result['rows']);
        $this->assertSame(['' => 3], $result['sections']);

        $this->actingAs($this->class->teacher)->get(route('classes.students', $this->class))->assertSee('No section: 3');
    }

    public function test_fr_cls_16_several_sections_in_one_workbook(): void
    {
        // One sheet per section (no Section column: the sheet name gives it), a mixed sheet, and a notes sheet.
        $sheet = fn (string $title, array $headings, array $rows) => new class($title, $headings, $rows) implements
            \Maatwebsite\Excel\Concerns\FromArray, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\WithTitle
        {
            public function __construct(private string $t, private array $h, private array $r) {}

            public function array(): array { return $this->r; }

            public function headings(): array { return $this->h; }

            public function title(): string { return $this->t; }
        };
        $book = new class([
            $sheet('4-A', ['Name'], [['Juan Dela Cruz'], ['Ana Reyes']]),          // Ana is in 4-B: possible match
            $sheet('4-B', ['Name'], [['Loreno, Maria Teresa'], ['Ana Reyes']]),    // both joined
            $sheet('Mixed', ['Name', 'Section'], [['Pedro Santos', '4-B'], ['Juan Dela Cruz', '4-A']]),
            $sheet('Notes', ['How to fill'], [['Put one student per row']]),       // no Name column: skipped
        ]) implements \Maatwebsite\Excel\Concerns\Export, \Maatwebsite\Excel\Concerns\WithMultipleSheets
        {
            public function __construct(private array $s) {}

            public function sheets(): array { return $this->s; }
        };
        $file = UploadedFile::fake()->createWithContent('BSIT 4.xlsx', Excel::raw($book, ExcelFormat::XLSX));

        $this->upload($file)->assertSessionHasNoErrors();
        $result = session(\App\Http\Controllers\ClassListCheckController::sessionKey($this->class));

        $this->assertSame(['4-A', '4-B', 'Mixed'], $result['sheets']);
        $this->assertSame(['4-A' => 3, '4-B' => 3], $result['sections']);
        $pick = fn ($g) => array_map(fn ($r) => $r['sheet'].': '.$r['name'], $result['groups'][$g]);
        $this->assertSame(['4-B: Loreno, Maria Teresa', '4-B: Ana Reyes'], $pick(ClassListCheckService::JOINED));
        $this->assertSame(['4-A: Juan Dela Cruz', 'Mixed: Juan Dela Cruz'], $pick(ClassListCheckService::PENDING));
        $this->assertSame(['4-A: Ana Reyes'], $pick(ClassListCheckService::POSSIBLE));
        $this->assertSame(['Mixed: Pedro Santos'], $pick(ClassListCheckService::NOT_JOINED));

        $this->actingAs($this->class->teacher)->get(route('classes.students', $this->class))
            ->assertSee('3 sheets')->assertSee('Section 4-A: 3 · Section 4-B: 3')->assertSee('Mixed · Row 2');
    }

    public function test_fr_cls_16_sheet_names_that_give_a_section(): void
    {
        foreach (['4-B', '4B', 'BSIT 4-A', 'Section B', 'B', 'bsit 4-b'] as $ok) {
            $this->assertSame($ok, ClassListCheckService::sectionFromTitle($ok), $ok);
        }
        foreach (['Sheet1', 'Class list', 'BSIT', 'Notes', '2026'] as $no) {
            $this->assertNull(ClassListCheckService::sectionFromTitle($no), $no);
        }
    }

    public function test_fr_cls_16_template_marks_section_optional_with_two_sections(): void
    {
        $csv = $this->actingAs($this->class->teacher)->get(route('class-list.template', [$this->class, 'csv']))->streamedContent();
        $this->assertStringContainsString('"Name","Section (optional)"', $csv);
        $this->assertStringContainsString('"Loreno, Maria Teresa","4-B"', $csv);
        $this->assertStringContainsString('"Dela Cruz, Juan","4-A"', $csv);
        $this->assertStringContainsString('"Reyes, Ana",""', $csv);
    }

    public function test_fr_cls_16_only_the_class_teacher(): void
    {
        $student = User::where('id_number', '2023-00001')->first();
        $other = $this->makeClass()->teacher;

        foreach ([$student, $other] as $user) {
            $this->upload($this->csv($this->rows()), $user)->assertForbidden();
            $this->actingAs($user)->get(route('class-list.template', [$this->class, 'csv']))->assertForbidden();
            $this->actingAs($user)->get(route('class-list.result', $this->class))->assertForbidden();
        }
    }
}
