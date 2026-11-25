<?php

namespace App\Http\Controllers\Api;

use App\Models\ClientAccount;
use App\Services\DocumentReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Settings → Business Ownership / Classification & Compliance / Valid IDs & Permits
 * scanned documents of the client, kept in S3 under  ID_Solutions/{client #}/{type}/…
 *
 *   GET    /settings/documents                 documents + the extra profile fields
 *   POST   /settings/documents/scan            { doc_type, file } → fields read from the document (nothing saved)
 *   POST   /settings/documents/fields          { doc_type, text } → fields from text the browser read off a scan
 *   POST   /settings/documents                 { doc_type, valid_id_id? (doc_type valid_id), label?, doc_no?, name_on_doc?, date_registered?, issued_at?, expires_at?, extracted?, file_name, mime, data }
 *   PUT    /settings/documents/{id}            { label?, doc_no?, name_on_doc?, date_registered?, issued_at?, expires_at? }
 *   DELETE /settings/documents/{id}
 *   GET    /settings/documents/{id}/file       { url } (link valid 10 minutes)
 *   PUT    /settings/profile-extra             { date_of_operation, is_withholding_agent, msme_class }
 */
class ClientDocumentController extends SalesController
{
    const FOLDER = 'ID_Solutions';
    const MAX_BYTES = 20 * 1024 * 1024;   // 20 MB (photos are made smaller in the browser first)
    const MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    const TYPES = [
        'dti' => 'ownership', 'sec' => 'ownership', 'cda' => 'ownership',
        'bir_2303' => 'bir', 'wh_notice' => 'withholding',
        'denr' => 'permit', 'boc' => 'permit', 'boi' => 'permit', 'other' => 'permit',
        'valid_id' => 'id',
    ];

    private function clientNum(Request $request): string
    {
        $caid = $this->caidFor($request);
        $num = $caid ? ClientAccount::where('id', $caid)->value('client_num') : null;
        abort_unless($num, 422, 'No client is selected.');
        return (string) $num;
    }

    private function disk()
    {
        return Storage::disk(config('filesystems.client_docs_disk', env('CLIENT_DOCS_DISK', 's3')));
    }

    private function documents(string $num)
    {
        return DB::table('t_client_document')->where('client_num', $num)
            ->orderBy('doc_group')->orderByDesc('id')
            ->get(['id', 'doc_group', 'doc_type', 'valid_id_id', 'label', 'doc_no', 'name_on_doc', 'date_registered', 'issued_at', 'expires_at',
                'extracted', 'file_name', 'mime', 'size', 'created_at'])
            ->map(function ($d) {
                $d->extracted = $d->extracted ? json_decode($d->extracted, true) : null;
                return $d;
            });
    }

    private function profile(string $num): array
    {
        $cols = ['date_of_operation', 'is_withholding_agent', 'msme_class'];
        $have = array_values(array_filter($cols, fn ($c) => Schema::hasColumn('m_client', $c)));
        return (array) (DB::table('m_client')->where('client_num', $num)->first($have ?: ['client_num']) ?? []);
    }

    /** base64 payload → bytes (checks size + type) */
    /** the uploaded file: multipart "file" (normal) or base64 "data" (old screens) → [bytes, mime] */
    private function bytes(array &$d, ?Request $request = null): array
    {
        @ini_set('memory_limit', '512M');
        if ($request && $request->hasFile('file')) {
            $up = $request->file('file');
            abort_unless($up->isValid(), 422, 'The upload failed: ' . $up->getErrorMessage());
            $bytes = (string) file_get_contents($up->getRealPath());
            $d['file_name'] = $d['file_name'] ?? $up->getClientOriginalName();
        } else {
            abort_if(empty($d['data']), 422, 'No file was sent.');
            $bytes = base64_decode(preg_replace('/^data:[^;]+;base64,/', '', (string) $d['data']), true);
            unset($d['data']);
        }
        abort_if($bytes === false || $bytes === '', 422, 'The file could not be read.');
        abort_if(strlen($bytes) > self::MAX_BYTES, 422, 'The file is too big (20 MB at most).');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: ($d['mime'] ?? '');
        abort_unless(in_array($mime, self::MIMES, true), 422, 'Upload a PDF, JPG, PNG or WEBP file.');
        return [$bytes, $mime];
    }

    private function fileRules(): array
    {
        return [
            'doc_type' => ['required', 'in:' . implode(',', array_keys(self::TYPES))],
            'file_name' => ['nullable', 'string', 'max:255'],
            'mime' => ['nullable', 'string', 'max:100'],
            'file' => ['nullable', 'file', 'max:' . (self::MAX_BYTES / 1024)],
            'data' => ['required_without:file', 'nullable', 'string'],
        ];
    }

    private function fieldRules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:150'],
            'doc_no' => ['nullable', 'string', 'max:100'],
            'name_on_doc' => ['nullable', 'string', 'max:255'],
            'date_registered' => ['nullable', 'date'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ];
    }

    /* GET /settings/documents */
    public function index(Request $request)
    {
        $num = $this->clientNum($request);
        return response()->json(['documents' => $this->documents($num), 'profile' => $this->profile($num)]);
    }

    /* POST /settings/documents/scan — read only, nothing saved */
    public function scan(Request $request, DocumentReader $reader)
    {
        $this->clientNum($request);
        $d = $request->validate($this->fileRules());
        [$bytes, $mime] = $this->bytes($d, $request);
        $read = $reader->text($bytes, $mime, $d['file_name'] ?? 'file');
        $detected = $read['text'] !== '' ? $reader->detect($read['text']) : null;     // what the file really is
        $fields = $read['text'] !== '' ? $reader->fields($reader->rulesFor($detected, $d['doc_type']), $read['text']) : [];
        $found = count(array_filter($fields, fn ($v) => $v !== null && $v !== []));
        return response()->json([
            'how' => $read['how'],
            'detected' => $detected,
            'found' => $found,
            'fields' => $fields,
            'message' => $read['how'] === 'none'
                ? ($reader->canOcr()
                    ? "Couldn't read this file. Fill in the details yourself."
                    : 'This file is a scan. Reading scans is not turned on on the server yet (Tesseract) – fill in the details yourself.')
                : ($found ? "Read {$found} detail(s) from the document. Check them before saving." : 'No details found in the document. Fill them in yourself.'),
        ]);
    }

    /* POST /settings/documents/fields   { doc_type, text } — text read from a scan in the browser (Tesseract.js) → the same fields */
    public function fieldsFromText(Request $request, DocumentReader $reader)
    {
        $this->clientNum($request);
        $d = $request->validate([
            'doc_type' => ['required', 'in:' . implode(',', array_keys(self::TYPES))],
            'text' => ['required', 'string', 'max:200000'],
        ]);
        $detected = $reader->detect($d['text']);
        $fields = $reader->fields($reader->rulesFor($detected, $d['doc_type']), $d['text']);
        $found = count(array_filter($fields, fn ($v) => $v !== null && $v !== []));
        return response()->json([
            'how' => 'browser-ocr', 'detected' => $detected, 'found' => $found, 'fields' => $fields,
            'message' => $found ? "Read {$found} detail(s) from the scan. Check them before saving." : 'No details found in the scan. Fill them in yourself.',
        ]);
    }

    /* POST /settings/documents */
    public function store(Request $request)
    {
        $num = $this->clientNum($request);
        $d = $request->validate($this->fileRules() + $this->fieldRules() + ['extracted' => ['nullable'], 'valid_id_id' => ['nullable', 'integer']]);
        [$bytes, $mime] = $this->bytes($d, $request);
        if (isset($d['extracted']) && is_string($d['extracted'])) $d['extracted'] = json_decode($d['extracted'], true);   // multipart sends it as JSON text
        $group = self::TYPES[$d['doc_type']];
        if ($group === 'id') {
            abort_unless(!empty($d['valid_id_id']) && \App\Models\ClientValidId::where('id', $d['valid_id_id'])->where('client_num', $num)->exists(), 422, 'Valid ID not found.');
        }

        $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        $safeNum = preg_replace('/[^A-Za-z0-9_-]+/', '_', $num);
        $d['file_name'] = $d['file_name'] ?? ($d['doc_type'] . '.' . (['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? 'bin'));
        $base = Str::slug(pathinfo($d['file_name'], PATHINFO_FILENAME)) ?: $d['doc_type'];
        $path = self::FOLDER . "/{$safeNum}/{$d['doc_type']}/" . now()->format('Ymd-His') . '-' . Str::lower(Str::random(6)) . "-{$base}.{$ext}";
        abort_unless($this->disk()->put($path, $bytes, ['visibility' => 'private', 'ContentType' => $mime]), 500, "Couldn't upload the file to S3.");

        // a Valid ID keeps one file: a new upload replaces the old one
        if ($group === 'id') {
            DB::table('t_client_document')->where('client_num', $num)->where('valid_id_id', $d['valid_id_id'])->get(['id', 'file_path'])
                ->each(function ($old) {
                    try { $this->disk()->delete($old->file_path); } catch (\Throwable $e) { report($e); }
                    DB::table('t_client_document')->where('id', $old->id)->delete();
                });
        }

        // one registration certificate type per client: a new DTI / SEC / CDA replaces the other kinds
        if ($group === 'ownership') {
            DB::table('t_client_document')->where('client_num', $num)->where('doc_group', 'ownership')
                ->where('doc_type', '<>', $d['doc_type'])->get(['id', 'file_path'])
                ->each(function ($old) {
                    try { $this->disk()->delete($old->file_path); } catch (\Throwable $e) { report($e); }
                    DB::table('t_client_document')->where('id', $old->id)->delete();
                });
        }

        DB::table('t_client_document')->insert([
            'client_num' => $num, 'doc_group' => $group, 'doc_type' => $d['doc_type'], 'valid_id_id' => $group === 'id' ? $d['valid_id_id'] : null,
            'label' => $d['label'] ?? null, 'doc_no' => $d['doc_no'] ?? null, 'name_on_doc' => $d['name_on_doc'] ?? null,
            'date_registered' => $d['date_registered'] ?? null, 'issued_at' => $d['issued_at'] ?? null, 'expires_at' => $d['expires_at'] ?? null,
            'extracted' => isset($d['extracted']) ? json_encode($d['extracted']) : null,
            'file_path' => $path, 'file_name' => mb_substr($d['file_name'], 0, 255), 'mime' => $mime, 'size' => strlen($bytes),
            'uploaded_by' => $request->user()?->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Document saved.', 'documents' => $this->documents($num)], 201);
    }

    /* PUT /settings/documents/{id} */
    public function update(Request $request, int $id)
    {
        $num = $this->clientNum($request);
        $d = $request->validate($this->fieldRules());
        $n = DB::table('t_client_document')->where('id', $id)->where('client_num', $num)->update($d + ['updated_at' => now()]);
        abort_unless($n || DB::table('t_client_document')->where('id', $id)->where('client_num', $num)->exists(), 404, 'Document not found.');
        return response()->json(['message' => 'Document updated.', 'documents' => $this->documents($num)]);
    }

    /* DELETE /settings/documents/{id} */
    public function destroy(Request $request, int $id)
    {
        $num = $this->clientNum($request);
        $doc = DB::table('t_client_document')->where('id', $id)->where('client_num', $num)->first();
        abort_unless($doc, 404, 'Document not found.');
        try { $this->disk()->delete($doc->file_path); } catch (\Throwable $e) { report($e); }
        DB::table('t_client_document')->where('id', $id)->delete();
        return response()->json(['message' => 'Document removed.', 'documents' => $this->documents($num)]);
    }

    /* GET /settings/documents/{id}/file → short-lived link to the S3 file */
    public function file(Request $request, int $id)
    {
        $num = $this->clientNum($request);
        $doc = DB::table('t_client_document')->where('id', $id)->where('client_num', $num)->first();
        abort_unless($doc, 404, 'Document not found.');
        try {
            $url = $this->disk()->temporaryUrl($doc->file_path, now()->addMinutes(10), [
                'ResponseContentDisposition' => 'inline; filename="' . str_replace('"', '', $doc->file_name) . '"',
                'ResponseContentType' => $doc->mime ?: 'application/octet-stream',   // shows in the popup instead of downloading
            ]);
            return response()->json(['url' => $url]);
        } catch (\Throwable $e) {
            // a disk without signed links (local) → send the file itself
            return response()->json(['name' => $doc->file_name, 'mime' => $doc->mime, 'data' => base64_encode($this->disk()->get($doc->file_path))]);
        }
    }

    /* PUT /settings/profile-extra */
    public function profileExtra(Request $request)
    {
        $num = $this->clientNum($request);
        $d = $request->validate([
            'date_of_operation' => ['nullable', 'date'],
            'is_withholding_agent' => ['nullable', 'boolean'],
            'msme_class' => ['nullable', 'in:nano,micro,small,medium,large'],
        ]);
        $d['is_withholding_agent'] = !empty($d['is_withholding_agent']);
        $cols = array_flip(Schema::getColumnListing('m_client'));
        DB::table('m_client')->where('client_num', $num)->update(array_intersect_key($d, $cols) + ['updated_at' => now()]);
        return response()->json(['message' => 'Saved.', 'profile' => $this->profile($num)]);
    }
}