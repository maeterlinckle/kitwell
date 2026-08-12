<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Renders the Markdown subset used by the files in /docs.
 *
 * Supported: ATX headings, paragraphs, bullet and numbered lists, pipe tables,
 * fenced and indented code, blockquotes, horizontal rules, and inline emphasis,
 * code, links and images.
 *
 * Every piece of source text is HTML-escaped before any markup is added, so the
 * output is safe to print. Raw HTML in the source is shown as text rather than
 * being passed through, and a link is only emitted for a relative path or an
 * http/https/mailto URL.
 */
final class Markdown
{
    /** Rewrites a link target, e.g. `assets.md` -> `/help/assets`. Set per render. */
    private static mixed $linkRewriter = null;

    /**
     * @param callable(string):string|null $linkRewriter Applied to every link href.
     */
    public static function render(string $source, ?callable $linkRewriter = null): string
    {
        self::$linkRewriter = $linkRewriter;

        $lines = preg_split('/\R/', $source) ?: [];
        $html  = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if (trim($line) === '') {
                continue;
            }

            // Fenced code.
            if (preg_match('/^\s*```(\w*)\s*$/', $line, $m) === 1) {
                $code = [];

                while (++$i < $count && preg_match('/^\s*```\s*$/', $lines[$i]) !== 1) {
                    $code[] = $lines[$i];
                }

                $class = $m[1] !== '' ? ' class="language-' . self::escape($m[1]) . '"' : '';
                $html[] = '<pre><code' . $class . '>' . self::escape(implode("\n", $code)) . "</code></pre>\n";
                continue;
            }

            // Horizontal rule.
            if (preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $line) === 1) {
                $html[] = "<hr>\n";
                continue;
            }

            // Heading.
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m) === 1) {
                $level  = strlen($m[1]);
                $text   = self::inline($m[2]);
                $anchor = self::anchor($m[2]);
                $html[] = sprintf("<h%d id=\"%s\">%s</h%d>\n", $level, self::escape($anchor), $text, $level);
                continue;
            }

            // Table: a header row followed by a delimiter row.
            if (str_contains($line, '|') && isset($lines[$i + 1])
                && preg_match('/^\s*\|?[\s:|-]+\|[\s:|-]*$/', $lines[$i + 1]) === 1) {
                $header = self::cells($line);
                $aligns = self::alignments($lines[$i + 1]);
                $i     += 2;
                $rows   = [];

                while ($i < $count && str_contains($lines[$i], '|') && trim($lines[$i]) !== '') {
                    $rows[] = self::cells($lines[$i]);
                    $i++;
                }
                $i--;

                $html[] = self::table($header, $aligns, $rows);
                continue;
            }

            // Blockquote.
            if (preg_match('/^\s*>\s?(.*)$/', $line, $m) === 1) {
                $quote = [$m[1]];

                while ($i + 1 < $count && preg_match('/^\s*>\s?(.*)$/', $lines[$i + 1], $m2) === 1) {
                    $quote[] = $m2[1];
                    $i++;
                }

                $html[] = "<blockquote>" . self::render(implode("\n", $quote)) . "</blockquote>\n";
                continue;
            }

            // List. Nesting is by indentation, in multiples of two spaces.
            if (preg_match('/^(\s*)([*+-]|\d+[.)])\s+(.*)$/', $line) === 1) {
                $block = [$line];

                while ($i + 1 < $count
                    && (trim($lines[$i + 1]) !== '' || (isset($lines[$i + 2]) && preg_match('/^\s+\S/', $lines[$i + 2]) === 1))
                    && preg_match('/^(\s*)([*+-]|\d+[.)])\s+|^\s+\S/', $lines[$i + 1]) === 1) {
                    $block[] = $lines[$i + 1];
                    $i++;
                }

                $html[] = self::list($block);
                continue;
            }

            // Paragraph: everything up to the next blank line or block opener.
            $paragraph = [$line];

            while ($i + 1 < $count && trim($lines[$i + 1]) !== ''
                && preg_match('/^(#{1,6}\s|\s*```|\s*>|\s*([*+-]|\d+[.)])\s|\s*(-{3,}|\*{3,}|_{3,})\s*$)/', $lines[$i + 1]) !== 1) {
                $paragraph[] = $lines[$i + 1];
                $i++;
            }

            $html[] = '<p>' . self::inline(implode("\n", $paragraph)) . "</p>\n";
        }

        self::$linkRewriter = null;

        return implode('', $html);
    }

    /** The id a heading gets, matching the `](#anchor)` links in the docs. */
    public static function anchor(string $heading): string
    {
        $text = preg_replace('/`([^`]*)`/', '$1', $heading) ?? $heading;
        $text = preg_replace('/\[([^]]*)]\([^)]*\)/', '$1', $text) ?? $text;
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9 \-]/u', '', $text) ?? $text;

        return str_replace(' ', '-', trim($text));
    }

    /** The first `# Heading` in a document, for the page title. */
    public static function title(string $source): ?string
    {
        return preg_match('/^#\s+(.+)$/m', $source, $m) === 1 ? trim($m[1]) : null;
    }

    /** @param array<int,string> $block */
    private static function list(array $block): string
    {
        $items   = [];
        $current = null;
        $ordered = preg_match('/^\s*\d+[.)]\s/', $block[0]) === 1;
        $baseIndent = null;

        foreach ($block as $line) {
            if (preg_match('/^(\s*)([*+-]|\d+[.)])\s+(.*)$/', $line, $m) === 1) {
                $indent = strlen($m[1]);
                $baseIndent ??= $indent;

                if ($indent > $baseIndent && $current !== null) {
                    $current[] = substr($line, $baseIndent + 2);
                    continue;
                }

                if ($current !== null) {
                    $items[] = $current;
                }

                $current = [$m[3]];
                continue;
            }

            if ($current !== null) {
                $current[] = trim($line);
            }
        }

        if ($current !== null) {
            $items[] = $current;
        }

        $out = $ordered ? "<ol>\n" : "<ul>\n";

        foreach ($items as $item) {
            $first = array_shift($item) ?? '';
            $rest  = array_filter($item, static fn (string $l): bool => trim($l) !== '');

            $out .= '<li>' . self::inline($first);

            if ($rest !== []) {
                $nested = implode("\n", $rest);
                $out .= preg_match('/^\s*([*+-]|\d+[.)])\s/', $nested) === 1
                    ? self::render($nested)
                    : ' ' . self::inline($nested);
            }

            $out .= "</li>\n";
        }

        return $out . ($ordered ? "</ol>\n" : "</ul>\n");
    }

    /** @return array<int,string> */
    private static function cells(string $row): array
    {
        $row = trim($row);
        $row = preg_replace('/^\||\|$/', '', $row) ?? $row;

        return array_map('trim', explode('|', $row));
    }

    /** @return array<int,string> */
    private static function alignments(string $delimiter): array
    {
        return array_map(static function (string $cell): string {
            $cell  = trim($cell);
            $left  = str_starts_with($cell, ':');
            $right = str_ends_with($cell, ':');

            if ($left && $right) {
                return 'center';
            }

            return $right ? 'right' : '';
        }, self::cells($delimiter));
    }

    /**
     * @param array<int,string>       $header
     * @param array<int,string>       $aligns
     * @param array<int,array<int,string>> $rows
     */
    private static function table(array $header, array $aligns, array $rows): string
    {
        $cell = static function (string $tag, string $text, string $align): string {
            $style = $align === '' ? '' : ' style="text-align:' . $align . '"';

            return "<$tag$style>" . self::inline($text) . "</$tag>";
        };

        $out = "<div class=\"table-wrap\">\n<table>\n<thead>\n<tr>";

        foreach ($header as $index => $text) {
            $out .= $cell('th', $text, $aligns[$index] ?? '');
        }

        $out .= "</tr>\n</thead>\n<tbody>\n";

        foreach ($rows as $row) {
            $out .= '<tr>';

            foreach ($row as $index => $text) {
                $out .= $cell('td', $text, $aligns[$index] ?? '');
            }

            $out .= "</tr>\n";
        }

        return $out . "</tbody>\n</table>\n</div>\n";
    }

    /**
     * Inline formatting. The text is escaped first, so anything the source
     * contains is shown rather than interpreted; only the markup this method
     * adds itself reaches the output as HTML.
     */
    private static function inline(string $text): string
    {
        $text = self::escape($text);

        // Code spans are taken out first so nothing inside them is formatted.
        $spans = [];
        $text  = preg_replace_callback('/(`+)(.+?)\1/s', static function (array $m) use (&$spans): string {
            $spans[] = '<code>' . trim($m[2]) . '</code>';

            return "\x00" . (count($spans) - 1) . "\x00";
        }, $text) ?? $text;

        // Images, then links.
        $text = preg_replace_callback('/!\[([^]]*)]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/', static function (array $m): string {
            $src = self::href($m[2], true);

            return $src === null
                ? $m[1]
                : '<img src="' . $src . '" alt="' . $m[1] . '">';
        }, $text) ?? $text;

        $text = preg_replace_callback('/\[([^]]*)]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/', static function (array $m): string {
            $href = self::href($m[2]);

            if ($href === null) {
                return $m[1];
            }

            $external = preg_match('#^(https?:)?//#i', $m[2]) === 1;
            $extra    = $external ? ' target="_blank" rel="noopener noreferrer"' : '';

            return '<a href="' . $href . '"' . $extra . '>' . $m[1] . '</a>';
        }, $text) ?? $text;

        $text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![\w*])\*(?=\S)([^*]+?)(?<=\S)\*(?![\w*])/s', '<em>$1</em>', $text) ?? $text;
        $text = str_replace("  \n", "<br>\n", $text);

        return preg_replace_callback('/\x00(\d+)\x00/', static fn (array $m): string => $spans[(int) $m[1]], $text) ?? $text;
    }

    /** Null for anything that is not a relative path or an http/https/mailto URL. */
    private static function href(string $target, bool $image = false): ?string
    {
        $target = html_entity_decode($target, ENT_QUOTES, 'UTF-8');

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1
            && preg_match('#^(https?|mailto):#i', $target) !== 1) {
            return null;
        }

        if (!$image && self::$linkRewriter !== null) {
            $target = (self::$linkRewriter)($target);
        }

        return self::escape($target);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
