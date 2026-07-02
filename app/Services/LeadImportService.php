<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * CSV lead import — all orchestration lives here, none in the controller.
 *
 * Per-row processing mirrors the codebase's bulk-mutation precedent
 * (invoice sweeps, ExpireDealProtections, task:sync): one DB::transaction
 * per row, one activity_log row per created lead, try/catch inside the
 * loop so a bad row is skipped and reported without voiding the rest.
 * Whole-batch rollback is deliberately NOT used — that pattern belongs to
 * financial statement locks (MaavelusStatementService), not record imports.
 *
 * Duplicate policy (decided up front, not re-derived here):
 *  - Lead match via ContactMatchService's email-then-phone cascade → SKIP.
 *  - Contact match (per-customer contact layer) → import anyway, FLAG.
 *  - No usable email or phone after trimming → reject the row (it can
 *    never be de-duplicated); no "import blind" toggle in v1.
 *  - Duplicate within the file itself (same normalized email, else phone)
 *    → intra-run seen-set skip, mirroring SyncTasksCommand's title set.
 *  - Never merge/update an existing lead.
 */
class LeadImportService
{
    /**
     * Hard per-file row cap (data rows, excluding the header).
     *
     * Sized for the synchronous single-request execution model: measured
     * locally at ~4 ms per created row (parse + cascade queries + per-row
     * transaction + activity_log insert). Allowing a 20× slowdown for the
     * shared cPanel host (~80 ms/row) keeps 250 rows around 20 s — inside
     * the host's 30 s max_execution_time with margin. Raise only alongside
     * a queued execution model.
     */
    public const MAX_ROWS = 250;

    /** CSV columns the import accepts; anything else in the header is ignored.
     *  Public: the template download + tests derive the header from it. */
    public const COLUMNS = [
        'first_name', 'last_name', 'email', 'phone',
        'company', 'job_title', 'estimated_value', 'notes',
    ];

    /** The template download's example row carries this address — rows
     *  matching it are ignored visibly (never imported, never a
     *  duplicate-skip, never a validation failure). The controller's
     *  template body derives the row from this const, so the pair can't
     *  drift. Email over the notes cell as the match key: notes get
     *  edited while the row lingers; the address doesn't. */
    public const TEMPLATE_EXAMPLE_EMAIL = 'alex@example.com';

    /** Bounds hostile input: bytes fgetcsv reads per line, cells per row. */
    private const MAX_LINE_BYTES = 65536;

    private const MAX_COLUMNS = 64;

    public function __construct(private readonly ContactMatchService $matcher) {}

    /**
     * Import a CSV from an absolute path. Throws ValidationException for
     * whole-file problems (unreadable, missing header, over the cap);
     * per-row problems land in the returned summary instead.
     *
     * @return array{
     *     created: int,
     *     total_rows: int,
     *     example_ignored: int,
     *     skipped: list<array{row: int, name: string, matched_on: string}>,
     *     flagged: list<array{row: int, name: string, matched_on: string, customer: string|null}>,
     *     failed: list<array{row: int, reason: string}>,
     * }
     */
    public function import(string $path, string $originalFilename, User $actor): array
    {
        // The upload context's client-MIME allowlist must keep the Excel
        // MIMEs (Windows browsers report genuine CSVs as vnd.ms-excel), so
        // a real .xls workbook can reach here — its BYTES give it away.
        // Reject anything that isn't plain text before fgetcsv chews zip
        // binary and emits a misleading "missing first_name column" error.
        $realMime = mime_content_type($path) ?: '';
        if (! in_array($realMime, ['text/csv', 'text/plain'], true)) {
            throw ValidationException::withMessages([
                'file' => 'The file is not a plain-text CSV. If it is an Excel workbook, save it as CSV first (File → Save As → CSV).',
            ]);
        }

        [$header, $rows] = $this->parse($path);

        // Label-only provenance (never a stored filename — storage names
        // are FileUploadService's UUIDs). Survives conversion via
        // convert()'s channel_detail carry-over.
        $sourceDetail = mb_substr(basename($originalFilename), 0, 255);

        $summary = [
            'created' => 0,
            'total_rows' => count($rows),
            'example_ignored' => 0,
            'skipped' => [],
            'flagged' => [],
            'failed' => [],
        ];

        // Intra-run seen-set (SyncTasksCommand precedent): lowercased so the
        // in-PHP set matches the DB's case-insensitive collation semantics.
        $seen = [];

        foreach ($rows as $rowNumber => $cells) {
            try {
                $this->importRow($rowNumber, $cells, $header, $sourceDetail, $actor, $seen, $summary);
            } catch (Throwable $e) {
                // Skip-and-continue: the row's own transaction has rolled
                // back; committed rows stay committed. Reason is generic on
                // purpose — raw exception text doesn't belong in the UI.
                report($e);
                $summary['failed'][] = ['row' => $rowNumber, 'reason' => 'Unexpected error — row skipped.'];
            }
        }

        return $summary;
    }

    /**
     * @param  list<string|null>  $cells
     * @param  list<string>  $header
     * @param  array<string, true>  $seen
     * @param  array{created: int, total_rows: int, example_ignored: int, skipped: list<array{row: int, name: string, matched_on: string}>, flagged: list<array{row: int, name: string, matched_on: string, customer: string|null}>, failed: list<array{row: int, reason: string}>}  $summary
     */
    private function importRow(int $rowNumber, array $cells, array $header, string $sourceDetail, User $actor, array &$seen, array &$summary): void
    {
        $data = $this->combine($header, $cells);

        $validator = Validator::make($data, [
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            // max mirrors the DECIMAL(10,2) column — without it an oversize
            // value passes is_numeric and dies as an opaque QueryException.
            'estimated_value' => 'nullable|numeric|min:0|max:99999999.99',
            'notes' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            $summary['failed'][] = [
                'row' => $rowNumber,
                'reason' => implode(' ', $validator->errors()->all()),
            ];

            return;
        }

        $valid = $validator->validated();
        $email = $this->matcher->normalizeEmail($valid['email'] ?? null);
        $phone = $this->matcher->normalizePhone($valid['phone'] ?? null);
        $name = trim(($valid['first_name'] ?? '').' '.($valid['last_name'] ?? ''));

        // The template's placeholder row, imported unedited — common enough
        // to handle. Ignored visibly, before dedup: not a created lead, not
        // a duplicate-skip, not a failure, and never enters the seen-set.
        if ($email !== null && mb_strtolower($email) === self::TEMPLATE_EXAMPLE_EMAIL) {
            $summary['example_ignored']++;

            return;
        }

        if ($email === null && $phone === null) {
            $summary['failed'][] = [
                'row' => $rowNumber,
                'reason' => 'No usable email or phone after trimming — the row cannot be de-duplicated.',
            ];

            return;
        }

        // Same cascade for the intra-run key as for the DB match: the email
        // identifies the row when present, else the phone.
        $seenKey = $email !== null ? 'e:'.mb_strtolower($email) : 'p:'.mb_strtolower($phone);
        if (isset($seen[$seenKey])) {
            $summary['skipped'][] = [
                'row' => $rowNumber,
                'name' => $name,
                'matched_on' => 'duplicate row earlier in this file',
            ];

            return;
        }

        if ($this->matcher->matchLead($email, $phone) !== null) {
            $summary['skipped'][] = [
                'row' => $rowNumber,
                'name' => $name,
                'matched_on' => $email !== null ? 'email' : 'phone',
            ];

            return;
        }

        $contactHit = $this->matcher->matchContact($email, $phone);

        DB::transaction(function () use ($valid, $email, $phone, $sourceDetail, $actor) {
            $lead = Lead::create([
                'first_name' => $valid['first_name'],
                'last_name' => $valid['last_name'] ?? null,
                'email' => $email,
                'phone' => $phone,
                'company' => $valid['company'] ?? null,
                'job_title' => $valid['job_title'] ?? null,
                'estimated_value' => $valid['estimated_value'] ?? null,
                'notes' => $valid['notes'] ?? null,
                'status' => 'new',
                'source' => 'import',
                'source_detail' => $sourceDetail,
                'created_by' => $actor->id,
            ]);

            // Same shape as LeadController::log's lead.created rows, plus
            // the task:sync-style 'via' provenance marker.
            ActivityLog::create([
                'user_id' => $actor->id,
                'user_role' => $actor->role,
                'action' => 'lead.created',
                'entity_type' => 'lead',
                'entity_id' => $lead->id,
                'before' => null,
                'after' => [
                    'name' => $lead->name,
                    'source' => $lead->source,
                    'via' => 'import',
                ],
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
            ]);
        });

        // Register the identity only after the commit — registering before
        // it would let a row that FAILS at the DB level silently swallow a
        // later same-identity row as an "intra-file duplicate".
        $seen[$seenKey] = true;

        $summary['created']++;

        if ($contactHit !== null) {
            $summary['flagged'][] = [
                'row' => $rowNumber,
                'name' => $name,
                'matched_on' => $contactHit['field'],
                'customer' => $contactHit['model']->customer?->name,
            ];
        }
    }

    /**
     * Parse the CSV: header row first, then data rows keyed by their
     * physical line number (header = line 1, first data row = 2) so the
     * summary's row numbers match what the operator sees in a spreadsheet.
     *
     * Every cell is trimmed here — CSV values never pass through the
     * TrimStrings middleware, and untrimmed leading whitespace silently
     * defeats the collation-backed matching.
     *
     * @return array{0: list<string>, 1: array<int, list<string|null>>}
     */
    private function parse(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'The uploaded file could not be read.']);
        }

        try {
            // Explicit escape='' — disables the legacy backslash-escape
            // quirk and silences the PHP 8.4 deprecation of the implicit
            // default. RFC 4180 CSVs only quote with ". The 64 KB line cap
            // bounds the per-line allocation (a hostile 10 MB line of bare
            // commas would otherwise balloon into millions of array cells);
            // no legitimate lead row approaches it.
            $header = fgetcsv($handle, self::MAX_LINE_BYTES, ',', '"', '');
            if ($header === false || $header === [null]) {
                throw ValidationException::withMessages(['file' => 'The CSV is empty.']);
            }

            // Excel's default "CSV UTF-8" export prepends a BOM; untouched
            // it corrupts the first header cell (rejecting the file, or
            // silently dropping that column from the mapping).
            if (isset($header[0]) && str_starts_with((string) $header[0], "\xEF\xBB\xBF")) {
                $header[0] = substr((string) $header[0], 3);
            }

            $this->assertSaneColumnCount($header, 1);

            $header = array_map(
                fn ($h) => strtolower(str_replace(' ', '_', trim((string) $h))),
                $header,
            );

            if (! in_array('first_name', $header, true)) {
                throw ValidationException::withMessages([
                    'file' => 'The CSV header must include a first_name column. Expected columns: '.implode(', ', self::COLUMNS).'.',
                ]);
            }

            $rows = [];
            $line = 1;
            while (($cells = fgetcsv($handle, self::MAX_LINE_BYTES, ',', '"', '')) !== false) {
                $line++;

                // fgetcsv yields [null] for blank lines — skip them.
                if ($cells === [null]) {
                    continue;
                }

                $this->assertSaneColumnCount($cells, $line);

                $rows[$line] = array_map(
                    function ($c): ?string {
                        $c = trim((string) $c);

                        return $c === '' ? null : $c;
                    },
                    $cells,
                );

                if (count($rows) > self::MAX_ROWS) {
                    throw ValidationException::withMessages([
                        'file' => 'The file has more than '.self::MAX_ROWS.' data rows. Split it into smaller files — the import runs synchronously and larger files risk timing out.',
                    ]);
                }
            }

            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'The CSV has a header but no data rows.']);
            }

            return [$header, $rows];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string|null>  $cells
     */
    private function assertSaneColumnCount(array $cells, int $line): void
    {
        if (count($cells) > self::MAX_COLUMNS) {
            throw ValidationException::withMessages([
                'file' => 'Line '.$line.' has more than '.self::MAX_COLUMNS.' columns — this does not look like a lead CSV.',
            ]);
        }
    }

    /**
     * Key a row's cells by the header, keeping only the accepted columns.
     * Short rows pad with null; extra cells are ignored.
     *
     * @param  list<string>  $header
     * @param  list<string|null>  $cells
     * @return array<string, string|null>
     */
    private function combine(array $header, array $cells): array
    {
        $data = [];
        foreach ($header as $i => $column) {
            if (in_array($column, self::COLUMNS, true)) {
                $data[$column] = $cells[$i] ?? null;
            }
        }

        return $data;
    }
}
