<?php

namespace PhpPgAdmin\Database\Import\Data;

/**
 * Optimierter JSON-Streaming-Importer
 * Erwartet Format:
 * {
 *   "columns": [ { "name": "...", "type": "..." }, ... ],
 *   "data": [ { "col": value, ... }, ... ]
 * }
 */
class JsonRowParser implements RowStreamingParser
{
    public function parse(string $chunk, array &$state): array
    {
        // Initial state
        $state += [
            'mode' => 'root',
            'columns' => null,
            'rows' => [],
            'currentRow' => null,
            'currentKey' => null,
            'stack' => [],
        ];

        // Tokenize and consume
        foreach ($this->tokenize($chunk, $state) as $token) {
            $this->consume($token, $state);
        }

        // Extract rows
        $rows = $state['rows'];
        $state['rows'] = [];

        return [
            'rows' => $rows,
            'remainder' => $chunk,
            'header' => $state['columns'],
        ];
    }

    /**
     * Minimalistischer JSON-Tokenizer für dein Exportformat.
     * Unterstützt: { } [ ] : , strings, numbers, true/false/null
     */
    private function tokenize(string &$buffer, array &$state): \Generator
    {
        $len = strlen($buffer);
        $i = 0;

        while ($i < $len) {
            $c = $buffer[$i];

            // Skip whitespace
            if ($c <= " ") {
                $i++;
                continue;
            }

            // Structural tokens
            if (strpos("{}[]:,", $c) !== false) {
                yield ['t' => $c];
                $i++;
                continue;
            }

            // String
            if ($c === '"') {
                $i++;
                $start = $i;
                $escaped = false;
                $str = '';

                while ($i < $len) {
                    $ch = $buffer[$i];

                    if ($escaped) {
                        $str .= '\\' . $ch;
                        $escaped = false;
                        $i++;
                        continue;
                    }

                    if ($ch === '\\') {
                        $escaped = true;
                        $i++;
                        continue;
                    }

                    if ($ch === '"') {
                        // Complete string
                        $decoded = json_decode('"' . $str . '"');
                        yield ['t' => 'string', 'v' => $decoded];
                        $i++;
                        continue 2;
                    }

                    $str .= $ch;
                    $i++;
                }

                // Incomplete string → keep remainder
                break;
            }

            // Literal (number, true, false, null)
            if (preg_match('/[0-9tfn\-]/', $c)) {
                $start = $i;
                while ($i < $len && preg_match('/[0-9eE\+\-a-zA-Z\.]/', $buffer[$i])) {
                    $i++;
                }

                $token = substr($buffer, $start, $i - $start);
                $decoded = json_decode($token, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    yield ['t' => 'literal', 'v' => $decoded];
                } else {
                    // incomplete literal
                    break;
                }

                continue;
            }

            // Unknown → stop
            break;
        }

        // Keep remainder
        $buffer = substr($buffer, $i);
    }

    private function consume(array $t, array &$state): void
    {
        switch ($t['t']) {
            case '{':
                $state['stack'][] = '{';
                if ($state['mode'] === 'data-array') {
                    $state['currentRow'] = [];
                    $state['mode'] = 'row';
                }
                break;

            case '}':
                array_pop($state['stack']);
                if ($state['mode'] === 'row') {
                    $state['rows'][] = $state['currentRow'];
                    $state['currentRow'] = null;
                    $state['mode'] = 'data-array';
                }
                break;

            case '[':
                $state['stack'][] = '[';
                if ($state['mode'] === 'root') {
                    // waiting for "columns" or "data"
                } elseif ($state['mode'] === 'columns') {
                    $state['columns'] = [];
                    $state['mode'] = 'columns-array';
                } elseif ($state['mode'] === 'data') {
                    $state['mode'] = 'data-array';
                }
                break;

            case ']':
                array_pop($state['stack']);
                if ($state['mode'] === 'columns-array') {
                    $state['mode'] = 'root';
                } elseif ($state['mode'] === 'data-array') {
                    $state['mode'] = 'root';
                }
                break;

            case 'string':
                $this->handleString($t['v'], $state);
                break;

            case 'literal':
                $this->handleLiteral($t['v'], $state);
                break;

            case ':':
            case ',':
                break;
        }
    }

    private function handleString(string $v, array &$state): void
    {
        if ($state['mode'] === 'root') {
            if ($v === 'columns') {
                $state['mode'] = 'columns';
            } elseif ($v === 'data') {
                $state['mode'] = 'data';
            }
            return;
        }

        if ($state['mode'] === 'columns-array') {
            // Expecting objects: { "name": "...", "type": "..." }
            if ($state['currentRow'] === null) {
                $state['currentRow'] = ['name' => null, 'type' => null];
                $state['currentKey'] = 'name';
            } else {
                if ($state['currentKey'] === 'name') {
                    $state['currentRow']['name'] = $v;
                    $state['currentKey'] = 'type';
                } else {
                    $state['currentRow']['type'] = $v;
                    $state['columns'][] = $state['currentRow'];
                    $state['currentRow'] = null;
                    $state['currentKey'] = null;
                }
            }
            return;
        }

        if ($state['mode'] === 'row') {
            if ($state['currentKey'] === null) {
                $state['currentKey'] = $v;
            } else {
                $state['currentRow'][$state['currentKey']] = $v;
                $state['currentKey'] = null;
            }
        }
    }

    private function handleLiteral($v, array &$state): void
    {
        if ($state['mode'] === 'row' && $state['currentKey'] !== null) {
            $state['currentRow'][$state['currentKey']] = $v;
            $state['currentKey'] = null;
        }
    }
}
