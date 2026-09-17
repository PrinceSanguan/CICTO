<?php

namespace App\Support;

/**
 * The one set of rules and messages for a document file upload.
 *
 * Three endpoints take a file -- registering a document, the corrected copy on
 * a return or resubmit, and a new version -- and each carried its own copy of
 * the rule with Laravel's stock messages, which list raw MIME types
 * ("application/vnd.openxmlformats-officedocument...") at a records clerk.
 *
 * The same limits and wording are shared with the browser (HandleInertiaRequests)
 * so the upload pop-up can refuse a wrong file the moment it is picked. The
 * browser check is a courtesy only; these rules are the guard.
 */
final class DocumentUpload
{
    /** How an extension reads to a clerk. Anything unlisted is shown upper-cased. */
    private const LABELS = [
        'pdf' => 'PDF',
        'doc' => 'Word',
        'docx' => 'Word',
        'xls' => 'Excel',
        'xlsx' => 'Excel',
        'png' => 'PNG',
        'jpg' => 'JPG',
        'jpeg' => 'JPG',
    ];

    /**
     * Written out rather than File::types()->extensions(), because the order
     * decides which message a clerk sees first: File builds MIME before
     * extension, so a .txt answered "not a real PDF..." when the honest answer
     * is "that type is not accepted". Extension first, then the content check,
     * which is what catches a .txt renamed to .pdf. Both stay: the MIME check
     * alone passes a .php file carrying a PDF magic header. SVG is permanently
     * excluded from the allow-list -- stored XSS.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'file',
            'extensions:'.implode(',', self::extensions()),
            'mimetypes:'.implode(',', config('cicto.uploads.mimes')),
            'max:'.self::maxKb(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $field = 'file'): array
    {
        $messages = self::clientMessages();

        return [
            "{$field}.required" => 'Choose a file to upload.',
            "{$field}.file" => $messages['incomplete'],
            "{$field}.uploaded" => $messages['incomplete'],
            "{$field}.extensions" => $messages['type'],
            "{$field}.mimetypes" => 'This file is not a real '.self::allowed().' file. It may have been renamed or be damaged.',
            "{$field}.max" => $messages['size'],
        ];
    }

    /**
     * @return array{extensions: list<string>, maxKb: int, allowed: string, messages: array{type: string, size: string, empty: string, incomplete: string}}
     */
    public static function forClient(): array
    {
        return [
            'extensions' => self::extensions(),
            'maxKb' => self::maxKb(),
            'allowed' => self::allowed(),
            'messages' => self::clientMessages(),
        ];
    }

    /**
     * "PDF, Word, Excel, PNG or JPG".
     */
    public static function allowed(): string
    {
        $labels = array_values(array_unique(array_map(
            fn (string $extension): string => self::LABELS[$extension] ?? strtoupper($extension),
            self::extensions(),
        )));

        $last = array_pop($labels);

        return $labels === [] ? (string) $last : implode(', ', $labels).' or '.$last;
    }

    /**
     * @return array{type: string, size: string, empty: string, incomplete: string}
     */
    private static function clientMessages(): array
    {
        $megabytes = round(self::maxKb() / 1024, 1);

        return [
            'type' => 'This type of file cannot be uploaded. Only '.self::allowed().' files are accepted.',
            'size' => 'This file is too large. The limit is '.rtrim(rtrim(number_format($megabytes, 1), '0'), '.').' MB.',
            'empty' => 'This file is empty, so there is nothing to upload.',
            // A file PHP dropped before validation -- usually over the host's
            // own upload limit, which can sit below max_size_kb.
            'incomplete' => 'The file did not finish uploading. It may be larger than the server allows, so try a smaller file.',
        ];
    }

    /**
     * @return list<string>
     */
    private static function extensions(): array
    {
        return array_values(array_map(strtolower(...), config('cicto.uploads.extensions')));
    }

    private static function maxKb(): int
    {
        return (int) config('cicto.uploads.max_size_kb');
    }
}
