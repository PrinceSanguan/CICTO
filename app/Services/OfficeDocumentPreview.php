<?php

namespace App\Services;

use App\Models\DocumentFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use Throwable;

/**
 * Word and Excel, rendered as HTML so they can be READ before they are signed.
 *
 * THE PROBLEM. §15 binds a signature to one exact file version, and the
 * signing panel shows that version so nobody signs what they have not read.
 * Browsers render PDF, PNG and JPEG and nothing else -- so a .docx arrived at
 * the signing panel as a grey box saying "download it to read it before you
 * sign", which is the one instruction a signer is most likely to skip. The
 * client reported it on 2026-09-20.
 *
 * WHY CONVERT ON THE SERVER rather than in the browser, when PDF stamping went
 * the other way. The PDF work is client-side because no free PHP library can
 * rewrite PDF 1.5+ object streams; nothing like that is true here, and
 * converting here buys the one thing that matters most for a file somebody
 * else uploaded: the output is served through DocumentFileController::preview
 * under its `default-src 'none'` policy, where a script smuggled through the
 * conversion cannot run, cannot load anything and cannot phone home. Rendering
 * the same markup into the app's own origin would put it a CSP bypass away
 * from the signer's session.
 *
 * WHAT IT IS NOT. Not a faithful reproduction. PhpWord keeps text, headings,
 * bold, tables and their borders, and drops some layout; a spreadsheet becomes
 * a plain table of its values. That is the right trade for "read it before you
 * sign" and the wrong one for "print it" -- which is why the download button
 * never goes away.
 *
 * FAILURE IS ALWAYS SOFT. Any file this cannot open renders the same sentence
 * the signer used to get, with the download link. A conversion that throws
 * must never become a 500 on the page somebody is trying to sign from.
 */
final class OfficeDocumentPreview
{
    /**
     * What this can open, and what to call it when it cannot.
     *
     * Legacy .doc and .xls are deliberately absent. PhpWord's MsDoc reader
     * handles a narrow slice of a format Microsoft retired, and OpenSpout does
     * not read the old BIFF spreadsheet at all -- offering a viewer that works
     * for one file in five is worse than the honest download it replaces.
     */
    public const TYPES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'excel',
    ];

    /**
     * Above this, the download link is the answer.
     *
     * Conversion holds the whole document in memory and a preview is a button
     * anyone holding the folder can press repeatedly. A 60-page memo is well
     * under this; a 30 MB spreadsheet of scanned images is not a thing anybody
     * reads on screen before signing.
     */
    public const MAX_BYTES = 8_000_000;

    /** How many spreadsheet rows are worth rendering before it stops being reading. */
    private const MAX_ROWS = 2_000;

    public function supports(?string $mimeType): bool
    {
        return $mimeType !== null && array_key_exists($mimeType, self::TYPES);
    }

    /**
     * A complete HTML document for this version, or the "download it" page.
     *
     * Always returns markup: the caller is streaming a response into an
     * iframe, and there is no failure mode there that a blank frame improves.
     */
    public function render(DocumentFile $file): string
    {
        if ($file->size_bytes > self::MAX_BYTES) {
            return $this->cannotShow(
                $file,
                'This file is too large to show on screen.',
            );
        }

        $body = $this->body($file);

        return $body === null
            ? $this->cannotShow($file, 'This file could not be shown on screen.')
            : $this->page($file, $body);
    }

    /**
     * Just the converted markup, with no page around it.
     *
     * Public since 2026-09-21 so SignablePdf can render the SAME content into
     * a PDF for the signer to place a mark on. Both views having one renderer
     * is not tidiness: if the signer puts their signature two thirds down page
     * three, that has to be the page three they read, and two converters would
     * eventually disagree about where page three ends.
     *
     * Null rather than an exception when the file cannot be opened -- both
     * callers have something better to show than a stack trace, and neither
     * can do anything about a .docx with a feature the reader does not know.
     */
    public function body(DocumentFile $file): ?string
    {
        if (! $this->supports($file->mime_type) || $file->size_bytes > self::MAX_BYTES) {
            return null;
        }

        $local = null;

        try {
            // PhpWord and OpenSpout both want a path on a local filesystem,
            // and the documents disk may be S3.
            $local = $this->copyToTempFile($file);

            return self::TYPES[(string) $file->mime_type] === 'word'
                ? $this->word($local)
                : $this->excel($local);
        } catch (Throwable) {
            /*
             * Deliberately swallowed. A file this cannot parse is not broken
             * -- it is a file with a feature the converter does not know --
             * and the caller's next step is the same either way.
             */
            return null;
        } finally {
            if ($local !== null && is_file($local)) {
                @unlink($local);
            }
        }
    }

    /**
     * Word, walked as PhpWord's object model rather than run through its HTML
     * writer.
     *
     * THE WRITER IS NOT USABLE FOR THIS. It emits one `<p>` per RUN, and Word
     * splits a paragraph into runs on every formatting or spell-check-language
     * change -- so a single ordinary sentence came out as a column of
     * fragments, one word per line:
     *
     *     <p>List of offices and also code for the offices. (</p>
     *     <p>example</p>
     *     <p>;</p>
     *     <p>galing</p>
     *
     * The READER is fine: it hands back a TextRun per paragraph with its runs
     * as children, which is exactly the structure that got lost on the way
     * out. Joining the children of each paragraph is all this needs to do, and
     * it is also what lets list items come back as a list.
     */
    private function word(string $path): string
    {
        $out = '';
        $list = false;

        foreach (IOFactory::load($path)->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $isItem = $element instanceof ListItem || $element instanceof ListItemRun;

                // Consecutive items are one list, so the bullets read as one.
                if ($isItem && ! $list) {
                    $out .= '<ul>';
                    $list = true;
                } elseif (! $isItem && $list) {
                    $out .= '</ul>';
                    $list = false;
                }

                $out .= $this->element($element);
            }
        }

        return $this->strip($out.($list ? '</ul>' : ''));
    }

    /** One element of a Word document, as the markup a reader needs. */
    private function element(mixed $element): string
    {
        return match (true) {
            $element instanceof Title => $this->heading($element),
            $element instanceof ListItem => '<li>'.$this->inline($element->getTextObject()).'</li>',
            $element instanceof ListItemRun => '<li>'.$this->children($element).'</li>',
            $element instanceof TextRun => $this->paragraph($this->children($element)),
            $element instanceof Table => $this->table($element),
            $element instanceof TextBreak => '',
            default => $this->paragraph($this->inline($element)),
        };
    }

    /** A heading, at the depth Word gave it and no deeper than h4. */
    private function heading(Title $title): string
    {
        $level = min(4, max(1, (int) $title->getDepth() + 1));
        // Title::getText() is a TextRun when the heading carries mixed
        // formatting, and a plain string when it does not.
        $text = $title->getText();
        $inner = $text instanceof TextRun ? $this->children($text) : $this->text($text);

        return $inner === '' ? '' : "<h{$level}>{$inner}</h{$level}>";
    }

    /** The runs of one paragraph, joined the way Word laid them out. */
    private function children(mixed $container): string
    {
        if (! is_object($container) || ! method_exists($container, 'getElements')) {
            return $this->inline($container);
        }

        $out = '';

        foreach ($container->getElements() as $child) {
            $out .= $child instanceof TextBreak ? '<br>' : $this->inline($child);
        }

        return $out;
    }

    /**
     * A run of text, with the emphasis Word gave it.
     *
     * Bold and italic only. They are what a reader checking a document
     * actually needs -- a heading that reads as a heading, a defined term that
     * reads as one -- and every further style is a font name this viewer has
     * no business reproducing.
     */
    private function inline(mixed $element): string
    {
        if (! is_object($element) || ! method_exists($element, 'getText')) {
            return '';
        }

        $text = $element->getText();

        if (is_object($text)) {
            return $this->children($text);
        }

        $html = $this->text((string) $text);

        if ($html === '') {
            return '';
        }

        $style = method_exists($element, 'getFontStyle') ? $element->getFontStyle() : null;

        if (is_object($style) && method_exists($style, 'isBold') && $style->isBold()) {
            $html = "<strong>{$html}</strong>";
        }

        if (is_object($style) && method_exists($style, 'isItalic') && $style->isItalic()) {
            $html = "<em>{$html}</em>";
        }

        return $html;
    }

    /**
     * Text from the Word reader, escaped exactly once.
     *
     * PhpWord's reader hands back text that is ALREADY HTML-escaped -- an
     * apostrophe arrives as `&#039;` -- so escaping it again printed
     * "mayor&amp;#039;s office" on screen. Decoding first and escaping once is
     * what makes the output both correct and safe: the decode undoes the
     * reader's pass, and e() is then the only thing standing between a
     * document's contents and the markup around it.
     */
    private function text(string $text): string
    {
        return e(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** A Word table, cells and all, recursing for whatever is inside them. */
    private function table(Table $table): string
    {
        $out = '<table>';

        foreach ($table->getRows() as $row) {
            $out .= '<tr>';

            foreach ($row->getCells() as $cell) {
                $inner = '';

                foreach ($cell->getElements() as $element) {
                    $inner .= $this->element($element);
                }

                $out .= '<td>'.($inner === '' ? '&nbsp;' : $inner).'</td>';
            }

            $out .= '</tr>';
        }

        return $out.'</table>';
    }

    private function paragraph(string $inner): string
    {
        return trim(strip_tags($inner)) === '' && ! str_contains($inner, '<br>')
            ? ''
            : "<p>{$inner}</p>";
    }

    /**
     * Excel, as the table of values it is.
     *
     * OpenSpout streams row by row rather than building a cell graph, which is
     * why the project already depends on it: a spreadsheet that would exhaust
     * memory in a DOM-based reader is read in constant space here.
     */
    private function excel(string $path): string
    {
        $reader = new XlsxReader;
        $reader->open($path);

        $out = '';

        foreach ($reader->getSheetIterator() as $sheet) {
            $out .= '<h2>'.e($sheet->getName()).'</h2><table>';
            $rows = 0;

            foreach ($sheet->getRowIterator() as $row) {
                if (++$rows > self::MAX_ROWS) {
                    $out .= '<tr><td class="more">Showing the first '
                        .number_format(self::MAX_ROWS)
                        .' rows. Download the file to read the rest.</td></tr>';
                    break;
                }

                $out .= '<tr>';

                foreach ($row->getCells() as $cell) {
                    // Every value escaped: a spreadsheet cell is the most
                    // ordinary place in this system for somebody to have typed
                    // a tag, on purpose or by accident.
                    $out .= '<td>'.e($this->cellText($cell->getValue())).'</td>';
                }

                $out .= '</tr>';
            }

            $out .= '</table>';
        }

        $reader->close();

        return $out === '' ? '<p>This spreadsheet has no sheets.</p>' : $out;
    }

    /**
     * A cell as the reader should see it.
     *
     * OpenSpout hands back the TYPE it found, not a string: a date cell is a
     * DateTimeInterface and a checkbox is a bool. Casting those blindly throws
     * on the date and prints "1" for the tick -- so each is written out the
     * way it would read on paper.
     */
    private function cellText(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i'),
            $value instanceof \DateInterval => $value->format('%h:%I'),
            is_float($value) || is_int($value) => (string) $value,
            default => (string) $value,
        };
    }

    /**
     * Belt and braces on top of the response's `default-src 'none'`.
     *
     * That policy is what actually makes converted markup inert, and it is not
     * being leaned on alone: script tags, framed content and inline event
     * handlers come out here too, so the output is still safe if this ever
     * gets rendered somewhere with a laxer policy.
     */
    private function strip(string $html): string
    {
        $html = preg_replace('#<\s*(script|iframe|object|embed|link|meta)\b.*?(</\s*\1\s*>|>)#is', '', $html) ?? '';
        $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? '';

        return preg_replace('#(href|src)\s*=\s*("|\')?\s*javascript:[^"\'>]*("|\')?#i', '', $html) ?? '';
    }

    /** The shell, with a stylesheet that makes an office document readable. */
    private function page(DocumentFile $file, string $body): string
    {
        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <title>{$this->safeName($file)}</title>
            <style>
                :root { color-scheme: light; }
                body {
                    margin: 0; padding: 24px 28px;
                    background: #fff; color: #111827;
                    font: 14px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
                }
                h1, h2, h3 { line-height: 1.3; margin: 1.2em 0 0.5em; }
                h1 { font-size: 1.4em; } h2 { font-size: 1.2em; } h3 { font-size: 1.05em; }
                p { margin: 0 0 0.7em; }
                img { max-width: 100%; height: auto; }
                table { border-collapse: collapse; margin: 0 0 1em; max-width: 100%; }
                td, th { border: 1px solid #d1d5db; padding: 4px 8px; vertical-align: top; }
                td.more { font-style: italic; color: #6b7280; }
                .converted {
                    margin: 0 0 20px; padding: 8px 12px;
                    background: #eff6ff; border-left: 3px solid #3b82f6;
                    font-size: 12px; color: #1e3a5f;
                }
            </style>
            </head>
            <body>
            <p class="converted">
                Converted for reading. Formatting may differ from the original —
                download the file if you need an exact copy.
            </p>
            {$body}
            </body>
            </html>
            HTML;
    }

    /** The page a file that cannot be converted gets, instead of a blank frame. */
    private function cannotShow(DocumentFile $file, string $reason): string
    {
        $name = $this->safeName($file);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <title>{$name}</title>
            <style>
                :root { color-scheme: light; }
                body {
                    margin: 0; min-height: 100vh; display: flex;
                    align-items: center; justify-content: center;
                    background: #f3f4f6; color: #4b5563; text-align: center;
                    font: 14px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
                }
                p { margin: 0 24px; max-width: 28em; }
                strong { color: #111827; }
            </style>
            </head>
            <body>
            <p>
                <strong>{$name}</strong><br>
                {$reason} Download it to read it before you sign.
            </p>
            </body>
            </html>
            HTML;
    }

    private function safeName(DocumentFile $file): string
    {
        return e(mb_substr($file->original_name, 0, 120));
    }

    /**
     * The version's bytes on a local path, for libraries that cannot read a
     * stream. Removed by the caller's `finally` whatever happens.
     */
    private function copyToTempFile(DocumentFile $file): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cicto-preview-');

        if ($path === false) {
            throw new \RuntimeException('No temporary file could be created.');
        }

        $stream = Storage::disk($file->disk)->readStream($file->path);

        if ($stream === null) {
            throw new \RuntimeException('The stored file could not be read.');
        }

        $out = fopen($path, 'wb');

        if ($out === false) {
            throw new \RuntimeException('The temporary file could not be opened.');
        }

        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        return $path;
    }
}
