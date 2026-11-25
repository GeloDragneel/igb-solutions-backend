<?php

namespace App\Services;

/**
 * Reads the text of an uploaded document and picks out the fields we need.
 *   PDF with text (DTI BNRS, BIR ORUS 2303, e-copies) → smalot/pdfparser  (composer require smalot/pdfparser)
 *   picture / scanned PDF                            → Tesseract OCR, only when TESSERACT_PATH is set in .env
 * Nothing is saved here: the result is shown to the user to confirm.
 */
class DocumentReader
{
    private const MONTHS = 'January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec';
    // "June 1, 2024" · "1 June 2024" · "06/01/2024" · "2024-06-01"
    private const DATE = '(?:(?:' . self::MONTHS . ')\\.?\\s+\\d{1,2},?\\s+\\d{4}|\\d{1,2}\\s+(?:' . self::MONTHS . ')\\.?,?\\s+\\d{4}|\\d{1,2}[\\/-]\\d{1,2}[\\/-]\\d{4}|\\d{4}-\\d{2}-\\d{2})';

    /** @return array{text:string, how:string} */
    public function text(string $bytes, string $mime, string $name): array
    {
        @set_time_limit(180);
        $isPdf = str_contains($mime, 'pdf') || str_ends_with(strtolower($name), '.pdf') || str_starts_with($bytes, '%PDF');
        if ($isPdf) {
            // a PDF with fonts has real text → read it (fast, exact)
            if (str_contains($bytes, '/Font') && class_exists(\Smalot\PdfParser\Parser::class)) {
                try {
                    $t = (new \Smalot\PdfParser\Parser())->parseContent($bytes)->getText();
                    if (mb_strlen(trim(preg_replace('/\s+/', ' ', $t))) > 40) return ['text' => $t, 'how' => 'pdf'];
                } catch (\Throwable $e) { /* fall through to OCR */ }
            }
            // a scanned PDF (pictures only): OCR the picture of page 1 (the scanner keeps it as a JPEG inside the PDF)
            $jpeg = $this->firstJpeg($bytes);
            $ocr = $jpeg !== '' ? $this->ocr($jpeg, 'jpg') : '';
            return $ocr !== '' ? ['text' => $ocr, 'how' => 'ocr'] : ['text' => '', 'how' => 'none'];
        }
        $ocr = $this->ocr($bytes, pathinfo($name, PATHINFO_EXTENSION) ?: 'png');
        return $ocr !== '' ? ['text' => $ocr, 'how' => 'ocr'] : ['text' => '', 'how' => 'none'];
    }

    /** OCR can be used (Tesseract installed + TESSERACT_PATH in .env) */
    public function canOcr(): bool
    {
        $bin = config('services.tesseract.path') ?: env('TESSERACT_PATH');
        return $bin && is_file($bin);
    }

    /** first JPEG picture stored in a PDF (scanners save each page as one JPEG) */
    private function firstJpeg(string $pdf): string
    {
        $at = 0;
        while (($i = strpos($pdf, '/DCTDecode', $at)) !== false) {
            $s = strpos($pdf, 'stream', $i);
            if ($s === false) break;
            $s += 6;
            if (substr($pdf, $s, 2) === "\r\n") $s += 2; elseif ($pdf[$s] === "\n" || $pdf[$s] === "\r") $s += 1;
            $e = strpos($pdf, 'endstream', $s);
            if ($e === false) break;
            $img = rtrim(substr($pdf, $s, $e - $s), "\r\n");
            if (str_starts_with($img, "\xFF\xD8") && strlen($img) > 20000) return $img;   // skip logos / tiny pictures
            $at = $e;
        }
        return '';
    }

    private function ocr(string $bytes, string $ext): string
    {
        if (!$this->canOcr()) return '';
        $bin = config('services.tesseract.path') ?: env('TESSERACT_PATH');
        $dir = storage_path('app' . DIRECTORY_SEPARATOR . 'ocr-tmp');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $ext = preg_replace('/[^a-z0-9]/i', '', strtolower($ext)) ?: 'png';
        $in = $dir . DIRECTORY_SEPARATOR . 'in-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $out = substr($in, 0, -strlen($ext) - 1) . '-out';
        file_put_contents($in, $bytes);
        try {
            $p = @proc_open([$bin, $in, $out, '-l', 'eng', '--psm', '3'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (is_resource($p)) {
                stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
            }
            return is_file("{$out}.txt") ? (string) file_get_contents("{$out}.txt") : '';
        } finally {
            @unlink($in); @unlink("{$out}.txt");
        }
    }

    /** fields per document type */
    public function fields(string $type, string $text): array
    {
        $t = preg_replace('/[ \t]+/', ' ', str_replace("\r", '', $text));
        $flat = preg_replace('/\s+/', ' ', $t);
        $out = ['doc_no' => null, 'name_on_doc' => null, 'date_registered' => null, 'issued_at' => null, 'expires_at' => null];

        switch ($type) {
            case 'dti':
                $out['doc_no'] = $this->first($flat, ['/(?:Business\s*Name\s*(?:No\.?|Number)|Certificate\s*(?:No\.?|Number))\s*[:#]?\s*([A-Z0-9-]{5,})/i']);
                $out['name_on_doc'] = $this->first($t, ['/(?:business\s*name|trade\s*name)\s*[:\n]\s*([^\n]{3,120})/i']);
                // "… valid from June 1, 2024 to June 1, 2029"
                $d = self::DATE;
                if (preg_match("/from\\s+({$d})\\s+(?:to|until)\\s+({$d})/i", $flat, $m)) {
                    $out['date_registered'] = $this->date($m[1]);
                    $out['expires_at'] = $this->date($m[2]);
                }
                $out['date_registered'] ??= $this->labelledDate($flat, ['date\s*(?:of\s*)?registration', 'registration\s*date', 'date\s*registered', 'issued\s*on']);
                break;

            case 'sec':
                $out['doc_no'] = $this->first($flat, [
                    '/(?:Company\s*Reg(?:istration)?\.?\s*(?:No\.?|Number)|SEC\s*Reg(?:istration)?\.?\s*(?:No\.?|Number)|Registration\s*(?:No\.?|Number))\s*[:#]?\s*([A-Z0-9-]{6,})/i',
                    '/\b((?:CS|CN|PG|A|AS)\d{6,}|\d{13}-\d{2})\b/',
                ]);
                $out['name_on_doc'] = $this->first($t, ['/(?:corporate\s*name|name\s*of\s*(?:the\s*)?corporation)\s*[:\n]\s*([^\n]{3,150})/i']);
                $out['date_registered'] = $this->dayOf($flat) ?? $this->labelledDate($flat, ['date\s*(?:of\s*)?(?:incorporation|registration)', 'registration\s*date', 'date\s*registered']);
                break;

            case 'cda':
                $out['doc_no'] = $this->first($flat, ['/(?:Registration\s*(?:No\.?|Number)|Reg\.?\s*No\.?|CIN)\s*[:#]?\s*([A-Z0-9-]{6,})/i']);
                $out['name_on_doc'] = $this->first($t, ['/(?:name\s*of\s*(?:the\s*)?cooperative)\s*[:\n]\s*([^\n]{3,150})/i', '/\n([^\n]*COOPERATIVE[^\n]*)\n/']);
                $out['date_registered'] = $this->dayOf($flat) ?? $this->labelledDate($flat, ['date\s*(?:of\s*)?registration', 'registration\s*date', 'date\s*registered']);
                break;

            case 'bir_2303':
                $out['doc_no'] = $this->first($flat, ['/\b(\d{3}-\d{3}-\d{3}-\d{3,5})\b/', '/TIN\s*[:#]?\s*([\d-]{9,17})/i']);
                $out['name_on_doc'] = $this->first($t, ['/(?:Taxpayer\'?s?\s*Name|Registered\s*Name)\s*[:\n]\s*([^\n]{3,150})/i']);
                $out['date_registered'] = $this->labelledDate($flat, ['tin\s*issuance\s*date', 'date\s*(?:of\s*)?registration', 'registration\s*date', 'date\s*registered']);
                $out['issued_at'] = $this->labelledDate($flat, ['date\s*ocn\s*generated', 'date\s*issued', 'issued\s*on', 'date\s*of\s*issue']);
                $out['ocn'] = $this->first($flat, ['/OCN\s*[:#]?\s*([0-9A-Z]{10,})/i']);
                // COR row: "288-538-893-00000  ECHAVEZ, ANDREW SOLOMON RAGOT  January 25, 2010"  (TIN & branch · name · TIN issuance date)
                $d = self::DATE;
                if (preg_match("/\\b\\d{3}-\\d{3}-\\d{3}-\\d{3,5}\\s+([A-Z][A-Z .,'Ñ-]{3,120}?)\\s+({$d})/u", $flat, $m)) {
                    $out['name_on_doc'] ??= trim($m[1]);
                    $out['date_registered'] ??= $this->date($m[2]);
                }
                $out['rdo'] ??= $this->first($flat, ['/(?:Revenue\s*District\s*Office|RDO)\s*(?:No\.?|Code)?\s*[:#]?\s*(\d{2,3}[A-Z]?)\b/i']);
                $out['tin'] = preg_match('/\b(\d{3}-\d{3}-\d{3})(?:-\d{3,5})?\b/', $flat, $m) ? $m[1] : null;
                $out['rdo'] = $this->first($flat, ['/RDO\s*(?:Code|No\.?)?\s*[:#]?\s*(\d{2,3}[A-Z]?)\b/i']) ?? ($out['rdo'] ?? null);
                if (!$out['rdo'] && !empty($out['ocn']) && preg_match('/^(\d{3})/', $out['ocn'], $m)) $out['rdo'] = $m[1];   // OCN starts with the RDO code
                $out['tax_types'] = $this->taxTypes($flat);
                break;

            case 'wh_notice':
                $out['doc_no'] = $this->first($flat, ['/(?:RR|RMC|RMO|Notice)\s*(?:No\.?)?\s*([0-9]{1,4}-[0-9]{2,4})/i']);
                $out['issued_at'] = $this->firstDate($flat);
                break;

            case 'valid_id': // any ID / permit: number, TIN, dates
                $out['tin'] = preg_match('/\b(\d{3}-\d{3}-\d{3})(?:-\d{3,5})?\b/', $flat, $m) ? $m[1] : null;
                // no break: same rules as permits
            default: // permits: DENR, BOC, BOI, others
                $out['doc_no'] = $this->first($flat, ['/(?:Permit|Certificate|Registration|Accreditation|Clearance)\s*(?:No\.?|Number)\s*[:#]?\s*([A-Z0-9-]{4,})/i']);
                $out['issued_at'] = $this->labelledDate($flat, ['date\s*issued', 'issued\s*on', 'issue\s*date', 'date\s*of\s*issuance']) ?? $this->dayOf($flat);
                $out['expires_at'] = $this->labelledDate($flat, ['valid\s*until', 'expir(?:y|ation)\s*date', 'expires\s*on', 'valid\s*up\s*to', 'until']);
        }

        foreach ($out as $k => $v) if (is_string($v)) $out[$k] = trim($v, " \t:,.-") ?: null;
        return $out;
    }

    /** tax types written on a 2303 */
    private function taxTypes(string $flat): array
    {
        $known = [
            'Income Tax' => '/income\s*tax/i',
            'Value-Added Tax' => '/value[\s-]*added\s*tax|\bVAT\b/i',
            'Percentage Tax' => '/percentage\s*tax/i',
            'Withholding Tax - Compensation' => '/withholding\s*tax\s*[-–]?\s*compensation/i',
            'Withholding Tax - Expanded' => '/withholding\s*tax\s*[-–]?\s*expanded/i',
            'Withholding Tax - Final' => '/withholding\s*tax\s*[-–]?\s*final/i',
            'Withholding Tax - VAT and Other Percentage Taxes' => '/withholding\s*tax\s*[-–]?\s*(?:vat|value)/i',
            'Registration Fee' => '/registration\s*fee/i',
            'Documentary Stamp Tax' => '/documentary\s*stamp/i',
            'Excise Tax' => '/excise\s*tax/i',
        ];
        return array_keys(array_filter($known, fn ($re) => preg_match($re, $flat)));
    }

    private function first(string $s, array $res): ?string
    {
        foreach ($res as $re) if (preg_match($re, $s, $m)) return trim($m[1]);
        return null;
    }

    /** "Date of Registration: March 3, 2021" */
    private function labelledDate(string $flat, array $labels): ?string
    {
        foreach ($labels as $l) {
            if (preg_match('/' . $l . '\s*[:\-]?\s*(.{6,30})/i', $flat, $m) && ($d = $this->date($m[1]))) return $d;
        }
        return null;
    }

    /** "… this 12th day of March, 2020" (SEC / CDA / permits) */
    private function dayOf(string $flat): ?string
    {
        if (preg_match('/(\d{1,2})(?:st|nd|rd|th)?\s+day\s+of\s+(' . self::MONTHS . ')\s*,?\s*(\d{4})/i', $flat, $m)) {
            return $this->date("{$m[2]} {$m[1]}, {$m[3]}");
        }
        return null;
    }

    private function firstDate(string $flat): ?string
    {
        if (preg_match('/((?:' . self::MONTHS . ')\.?\s+\d{1,2},?\s+\d{4}|\d{1,2}[\/-]\d{1,2}[\/-]\d{4}|\d{4}-\d{2}-\d{2})/i', $flat, $m)) return $this->date($m[1]);
        return null;
    }

    /** any date text at the start of $s → Y-m-d */
    public function date(?string $s): ?string
    {
        $s = trim((string) $s);
        if ($s === '') return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return checkdate($m[2], $m[3], $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        if (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})/', $s, $m)) {                  // Philippine forms: MM/DD/YYYY
            return checkdate((int) $m[1], (int) $m[2], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]) : null;
        }
        if (preg_match('/^((?:' . self::MONTHS . ')\.?\s+\d{1,2},?\s+\d{4})/i', $s, $m)
            || preg_match('/^(\d{1,2}\s+(?:' . self::MONTHS . ')\.?,?\s+\d{4})/i', $s, $m)) {
            $ts = strtotime(str_replace(['.', 'Sept'], ['', 'Sep'], $m[1]));
            return $ts ? date('Y-m-d', $ts) : null;
        }
        return null;
    }
}