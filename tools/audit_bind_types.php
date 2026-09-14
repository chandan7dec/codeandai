<?php

declare(strict_types=1);

/**
 * Bind-type auditor (dev only, never deployed).
 *
 * Scans the codebase for MySQLi prepared-statement calls and verifies that
 * the type-string length matches both the parameter count and the number of
 * '?' placeholders in the SQL. A mismatch is silent on SQLite (PDO ignores
 * type strings) but fatal on MySQL (bind_param throws) — exactly the class
 * of bug behind "works locally, Class management failed on production".
 *
 * Usage: php tools/audit_bind_types.php
 * Exit 0 = clean, 1 = mismatches found.
 */

$root = dirname(__DIR__);
$files = array_merge(
    glob($root . '/*.php') ?: [],
    glob($root . '/includes/*.php') ?: [],
    glob($root . '/includes/lib/*.php') ?: [],
    glob($root . '/organizer/*.php') ?: [],
    glob($root . '/whatsapp/*.php') ?: [],
    glob($root . '/tools/*.php') ?: []
);

$issues = [];
$checked = 0;

/**
 * Extract a full parenthesised argument list starting at the '(' offset.
 */
function captureArgs(string $src, int $openParen): string
{
    $depth = 0;
    $len = strlen($src);
    for ($i = $openParen; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $openParen + 1, $i - $openParen - 1);
            }
        } elseif ($ch === '\'' || $ch === '"') {
            $quote = $ch;
            $i++;
            while ($i < $len) {
                if ($src[$i] === '\\') {
                    $i += 2;
                    continue;
                }
                if ($src[$i] === $quote) {
                    break;
                }
                $i++;
            }
        }
    }
    return substr($src, $openParen + 1);
}

/**
 * Count top-level commas in an argument list (ignores commas nested in
 * parens/brackets/strings and the ?? operator).
 */
function countArgs(string $args): int
{
    $depth = 0;
    $count = 1;
    $len = strlen($args);
    for ($i = 0; $i < $len; $i++) {
        $ch = $args[$i];
        if ($ch === '\'' || $ch === '"') {
            $quote = $ch;
            $i++;
            while ($i < $len) {
                if ($args[$i] === '\\') {
                    $i += 2;
                    continue;
                }
                if ($args[$i] === $quote) {
                    break;
                }
                $i++;
            }
            continue;
        }
        if ($ch === '(' || $ch === '[') {
            $depth++;
        } elseif ($ch === ')' || $ch === ']') {
            $depth--;
        } elseif ($ch === ',' && $depth === 0) {
            $count++;
        }
    }
    return $count;
}

/**
 * First single-quoted string literal in the text (handles \' escapes).
 */
function firstSqlLiteral(string $text): ?string
{
    $start = strpos($text, '\'');
    if ($start === false) {
        return null;
    }
    $len = strlen($text);
    for ($i = $start + 1; $i < $len; $i++) {
        if ($text[$i] === '\\') {
            $i++;
            continue;
        }
        if ($text[$i] === '\'') {
            return substr($text, $start + 1, $i - $start - 1);
        }
    }
    return null;
}

foreach ($files as $file) {
    $src = file_get_contents($file);
    if ($src === false) {
        continue;
    }
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file);

    // ── Pattern 1: $stmt->bind_param('types', a, b, c) ──
    if (preg_match_all('/->bind_param\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $callStart = $hit[1] + strlen($hit[0]) - 1;
            $args = captureArgs($src, $callStart);
            $types = firstSqlLiteral($args) ?? '';
            if (!preg_match('/^[sidb]+$/', $types)) {
                continue;
            }
            $checked++;
            // drop the types literal, count remaining top-level args
            $rest = strstr($args, ',') !== false ? substr($args, strpos($args, ',') + 1) : '';
            $paramCount = countArgs($rest);
            if (strlen($types) !== $paramCount) {
                $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
                $issues[] = sprintf('%s:%d bind_param types \'%s\' (%d) vs %d params', $rel, $line, $types, strlen($types), $paramCount);
            }
        }
    }

    // ── Pattern 2: ->execute('SQL', [params], 'types') / ->query('SQL', [params], 'types') ──
    if (preg_match_all('/->(execute|query|exec)\s*\(/', $src, $m2, PREG_OFFSET_CAPTURE)) {
        foreach ($m2[0] as $hit) {
            $callStart = $hit[1] + strlen($hit[0]) - 1;
            $args = captureArgs($src, $callStart);
            // Only three-arg calls with a trailing types literal are relevant.
            if (!preg_match("/,\s*'([sidb]{2,})'\s*$/s", $args, $tm)) {
                continue;
            }
            $types = $tm[1];
            $sql = firstSqlLiteral($args);
            if ($sql === null) {
                continue;
            }
            $checked++;
            $placeholders = substr_count($sql, '?');
            // param array = the [...] group
            $bracketOpen = strpos($args, '[');
            $bracketClose = strrpos($args, ']');
            $paramCount = ($bracketOpen !== false && $bracketClose !== false)
                ? countArgs(substr($args, $bracketOpen + 1, $bracketClose - $bracketOpen - 1))
                : 0;
            if (strlen($types) !== $placeholders || $placeholders !== $paramCount) {
                $line = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
                $issues[] = sprintf(
                    '%s:%d %s() types \'%s\' (%d) vs %d placeholders vs %d params',
                    $rel,
                    $line,
                    $hit[0],
                    $types,
                    strlen($types),
                    $placeholders,
                    $paramCount
                );
            }
        }
    }
}

echo "Checked {$checked} prepared-statement calls.\n";
if ($issues === []) {
    echo "ALL CLEAN — every type string matches its placeholders and parameters.\n";
    exit(0);
}
echo "MISMATCHES FOUND:\n";
foreach ($issues as $issue) {
    echo '  ✗ ' . $issue . "\n";
}
exit(1);
