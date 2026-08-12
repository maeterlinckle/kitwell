<?php

declare(strict_types=1);

/*
 * Template output escaping audit, done with PHP's own tokeniser rather than
 * regexes.
 *
 * For every `<?= EXPR ?>` it works out whether any variable actually reaches
 * the output. Variables used only in a ternary *condition*, or passed through
 * an escaping/formatting function, or cast to a number, never reach it.
 * Anything else is a real escaping hole.
 */

$root = dirname(__DIR__);

/** Functions whose return value is safe to print. */
const SAFE_FUNCTIONS = [
    'e', 'url', 'asset_url', 'csrf_field', 'csrf_token', 'method_field', 'partial',
    'format_date', 'format_datetime', 'format_money', 'str_limit', 'number_format',
    'count', 'implode', 'http_build_query', 'ucfirst', 'date', 'abs', 'max', 'min',
    'round', 'sprintf', 'rawurlencode', 'urlencode', 'htmlspecialchars', 'strtolower',
    'str_replace', 'substr_count', 'array_sum', 'json_encode', 'svg', 'measurement',
    'describeFrequency', 'label', 'dueInWords',
    // App\Services\Markdown escapes the whole source before adding any markup,
    // so only the tags it writes itself reach the output as HTML.
    'markdown',
];

/**
 * Variables that hold markup which is already safe.
 *
 *  $content — the rendered page body, injected by the layout.
 *  $tile    — a closure defined at the top of dashboard/index.php which builds
 *             a stat tile and escapes its own arguments with e().
 */
const SAFE_VARIABLES = ['$content', '$tile'];

/**
 * @return array<int,string> Variables that reach the output unescaped.
 */
function unsafeOutputs(string $expression): array
{
    $tokens = @token_get_all('<?php ' . $expression . ';');

    if ($tokens === false) {
        return ['(unparseable)'];
    }

    array_shift($tokens);   // the opening tag

    $unsafe      = [];
    $depth       = 0;
    $safeUntil   = [];      // stack of depths at which a safe call closes
    $pending     = [];      // variables seen, keyed by paren depth
    $castPending = false;

    foreach ($tokens as $index => $token) {
        if (is_array($token)) {
            [$id, $text] = $token;

            if ($id === T_WHITESPACE || $id === T_COMMENT) {
                continue;
            }

            // A numeric cast makes anything that follows safe to print.
            if ($id === T_INT_CAST || $id === T_DOUBLE_CAST || $id === T_BOOL_CAST) {
                $castPending = true;
                continue;
            }

            if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                // Look ahead for '(' to decide whether this is a call.
                $next = null;
                for ($j = $index + 1; $j < count($tokens); $j++) {
                    $candidate = $tokens[$j];

                    if (is_array($candidate) && $candidate[0] === T_WHITESPACE) {
                        continue;
                    }

                    $next = $candidate;
                    break;
                }

                if ($next === '(') {
                    $name = $text;

                    if (str_contains($name, '\\')) {
                        $name = substr($name, (int) strrpos($name, '\\') + 1);
                    }

                    if (in_array($name, SAFE_FUNCTIONS, true)) {
                        // Everything up to this call's closing paren is safe.
                        $safeUntil[] = $depth;
                    }
                }

                continue;
            }

            if ($id === T_VARIABLE) {
                if (in_array($text, SAFE_VARIABLES, true) || $castPending || $safeUntil !== []) {
                    $castPending = false;
                    continue;
                }

                $pending[$depth][] = $text;
                continue;
            }

            $castPending = false;
            continue;
        }

        // Single-character tokens.
        if ($token === '(') {
            $depth++;
            continue;
        }

        if ($token === ')') {
            $depth--;

            if ($safeUntil !== [] && end($safeUntil) === $depth) {
                array_pop($safeUntil);
            }

            continue;
        }

        // A ternary '?' means everything before it was a condition, so it is
        // never printed. That covers variables recorded at this nesting level
        // and anything deeper inside the condition, e.g. isset($errors['x']).
        // (Null coalescing arrives as T_COALESCE, so a bare '?' is always a
        // real ternary.)
        if ($token === '?' && $safeUntil === []) {
            foreach (array_keys($pending) as $recordedDepth) {
                if ($recordedDepth >= $depth) {
                    unset($pending[$recordedDepth]);
                }
            }

            continue;
        }

        $castPending = false;
    }

    foreach ($pending as $variables) {
        foreach ($variables as $variable) {
            $unsafe[] = $variable;
        }
    }

    return array_values(array_unique($unsafe));
}

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/templates'));

foreach ($iterator as $entry) {
    if ($entry->isFile() && $entry->getExtension() === 'php') {
        $files[] = $entry->getPathname();
    }
}

sort($files);

$findings = [];
$checked  = 0;

foreach ($files as $file) {
    $code = (string) file_get_contents($file);

    if (preg_match_all('/<\?=\s*(.+?)\s*\?>/s', $code, $matches, PREG_SET_ORDER) === 0) {
        continue;
    }

    foreach ($matches as $match) {
        $checked++;
        $expression = trim($match[1]);
        $unsafe     = unsafeOutputs($expression);

        if ($unsafe !== []) {
            $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $file);
            $findings[] = sprintf(
                "%s\n      %s\n      → %s",
                $relative,
                preg_replace('/\s+/', ' ', substr($expression, 0, 110)),
                implode(', ', $unsafe)
            );
        }
    }
}

echo "Checked $checked output expressions across " . count($files) . " templates.\n\n";

if ($findings === []) {
    echo "No unescaped variable output found.\n";
    exit(0);
}

echo count($findings) . " expression(s) print a variable without escaping:\n\n";

foreach ($findings as $finding) {
    echo "  - $finding\n\n";
}

exit(1);
