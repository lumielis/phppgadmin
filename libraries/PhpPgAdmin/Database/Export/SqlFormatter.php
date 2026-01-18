<?php

namespace PhpPgAdmin\Database\Export;

use PhpPgAdmin\Core\AppContainer;

/**
 * SQL Format Formatter
 * Outputs PostgreSQL SQL statements as-is or slightly processed
 */
class SqlFormatter extends OutputFormatter
{
    /** @var string */
    protected $mimeType = 'text/plain; charset=utf-8';
    /** @var string */
    protected $fileExtension = 'sql';
    /** @var bool */
    protected $supportsGzip = true;

    private const ESCAPE_MODE_NONE = 0;
    private const ESCAPE_MODE_STRING = 1;
    private const ESCAPE_MODE_BYTEA = 2;

    /** @var \PhpPgAdmin\Database\Postgres */
    private $connection;

    public function __construct()
    {
        $this->connection = AppContainer::getPostgres();
    }

    /**
     * Format ADORecordSet as SQL INSERT statements
     * @param \ADORecordSet $recordset ADORecordSet
     * @param array $metadata with keys: table, columns, insert_format
     */
    public function format($recordset, $metadata = [])
    {
        $pg = AppContainer::getPostgres();
        $table_name = $metadata['table'] ?? 'data';
        $insert_format = $metadata['insert_format'] ?? 'multi'; // multi, single, or copy

        if (!$recordset || $recordset->EOF) {
            return;
        }

        // Get column information
        $columns = [];
        $escape_mode = []; // 0 = none, 1 = literal, 2 = bytea
        for ($i = 0; $i < count($recordset->fields); $i++) {
            $finfo = $recordset->fetchField($i);
            $name = $finfo->name ?? "column_$i";
            $columns[$i] = $name;
            $type = strtolower($finfo->type ?? '');

            // numeric types → no escaping
            if (
                isset([
                    'int2' => true,
                    'int4' => true,
                    'int8' => true,
                    'integer' => true,
                    'bigint' => true,
                    'smallint' => true,
                    'float4' => true,
                    'float8' => true,
                    'real' => true,
                    'double precision' => true,
                    'numeric' => true,
                    'decimal' => true
                ][$type])
            ) {
                $escape_mode[$i] = self::ESCAPE_MODE_NONE;
                continue;
            }

            // boolean → no escaping
            if ($type === 'bool' || $type === 'boolean') {
                $escape_mode[$i] = self::ESCAPE_MODE_NONE;
                continue;
            }

            // bytea → escapeBytea
            if ($type === 'bytea') {
                $escape_mode[$i] = self::ESCAPE_MODE_BYTEA;
                continue;
            }

            // anything else → escapeLiteral
            $escape_mode[$i] = self::ESCAPE_MODE_STRING;
        }


        if ($insert_format === 'copy') {
            // COPY format
            $line = "COPY " . $pg->escapeIdentifier($table_name) . " (" . implode(', ', array_map([$pg, 'escapeIdentifier'], $columns)) . ") FROM stdin;\n";
            $this->write($line);

            while (!$recordset->EOF) {
                $first = true;
                $line = '';
                foreach ($recordset->fields as $i => $v) {
                    if ($v !== null) {
                        if ($escape_mode[$i] === self::ESCAPE_MODE_BYTEA) {
                            // COPY bytea escaping
                            $v = bytea_to_octal($v);
                        } else {
                            // COPY escaping: backslash and non-printable chars
                            $v = addcslashes($v, "\0\\\n\r\t");
                            $v = preg_replace('/\\\\([0-7]{3})/', '\\\\\1', $v);
                        }
                    }
                    if ($first) {
                        $line .= ($v === null) ? '\\N' : $v;
                        $first = false;
                    } else {
                        $line .= "\t" . (($v === null) ? '\\N' : $v);
                    }
                }
                $line .= "\n";
                $this->write($line);
                $recordset->moveNext();
            }
            $this->write("\\.\n");
        } else {
            // Standard INSERT statements (multi or single)
            $batch_size = $metadata['batch_size'] ?? 100; // for multi-row inserts
            $is_multi = ($insert_format === 'multi');
            $rows_in_batch = 0;
            $insert_begin = $line = "INSERT INTO " . $pg->escapeIdentifier($table_name) . " (" . implode(', ', array_map([$pg, 'escapeIdentifier'], $columns)) . ") VALUES";

            while (!$recordset->EOF) {

                $values = "(";
                $sep = "";
                foreach ($recordset->fields as $i => $v) {
                    $values .= $sep;
                    $sep = ",";
                    if ($v === null) {
                        $values .= "NULL";
                    } elseif ($escape_mode[$i] === self::ESCAPE_MODE_STRING) {
                        $values .= $pg->escapeLiteral($v);
                    } elseif ($escape_mode[$i] === self::ESCAPE_MODE_BYTEA) {
                        $values .= "'" . $pg->escapeBytea($v) . "'";
                    } else {
                        $values .= $v;
                    }
                }
                $values .= ")";

                if ($is_multi) {
                    if ($rows_in_batch === 0) {
                        $this->write("$insert_begin\n");
                    } elseif ($rows_in_batch >= $batch_size) {
                        $this->write(";\n\n$insert_begin\n");
                        $rows_in_batch = 0;
                    } else {
                        $this->write(",\n");
                    }
                    $this->write($values);
                    $rows_in_batch++;
                } else {
                    $this->write("$insert_begin $values;\n");
                }

                $recordset->moveNext();
            }

            // Output multi-row INSERT statements
            if ($is_multi && $rows_in_batch > 0) {
                $this->write(";\n");
            }
        }
    }

    private const INSERT_COPY = 1;
    private const INSERT_MULTI = 2;
    private const INSERT_SINGLE = 3;

    private const ESCAPE_NONE = 0;
    private const ESCAPE_STRING = 1;
    private const ESCAPE_BYTEA = 2;

    private $escapeModes = null;
    private $insertBegin = null;
    private $rowsInBatch = 0;
    private $batchSize = 0;
    private $insertFormat = 0;
    private $tableName = null;


    public function writeHeader($fields = [], $metadata = [])
    {
        switch ($metadata['insert_format'] ?? 'copy') {
            default:
            case 'copy':
                $this->insertFormat = self::INSERT_COPY;
                break;
            case 'multi':
                $this->insertFormat = self::INSERT_MULTI;
                break;
            case 'single':
                $this->insertFormat = self::INSERT_SINGLE;
                break;
        }

        $this->rowsInBatch = 0;
        $this->batchSize = $metadata['batch_size'] ?? 1000;
        $this->tableName = $metadata['table'] ?? 'data';

        $columnNames = array_map(function ($field) {
            return $this->connection->escapeString(
                $field['name']
            );
        }, $fields);
        $this->escapeModes = $this->determineEscapeModes($fields);

        if ($this->insertFormat === self::INSERT_COPY) {
            $line = "COPY {$this->tableName} (" . implode(', ', $columnNames) . ") FROM stdin;\n";
            $this->write($line);
        } else {
            $this->insertBegin = "INSERT INTO {$this->tableName} (" . implode(', ', $columnNames) . ") VALUES";
            if ($this->insertFormat === self::INSERT_MULTI) {
                $this->write("{$this->insertBegin}\n");
            }
        }
    }

    public function writeRow($row)
    {
        // Write row data
        if ($this->insertFormat === self::INSERT_COPY) {
            $this->writeCopyRow($row, $this->escapeModes);
        } elseif ($this->insertFormat === self::INSERT_MULTI) {
            // Break into batches
            if ($this->rowsInBatch >= $this->batchSize) {
                $this->write(";\n\n{$this->insertBegin}\n");
                $this->rowsInBatch = 0;
            } elseif ($this->rowsInBatch > 0) {
                $this->write(",\n");
            }
            $this->writeInsertValues($row, $this->escapeModes);
            $this->rowsInBatch++;
        } else {
            // Single-row INSERT
            $this->write($this->insertBegin . " ");
            $this->writeInsertValues($row, $this->escapeModes);
            $this->write(";\n");
        }
    }

    public function writeFooter()
    {
        if ($this->insertFormat === self::INSERT_COPY) {
            // Finalize COPY
            $this->write("\\.\n");
        } elseif ($this->insertFormat === self::INSERT_MULTI && $this->rowsInBatch > 0) {
            // Finalize multi-row INSERT
            $this->write(";\n");
        }

    }

    /**
     * Determine escape modes for each field based on type
     * 
     * @param array $fields Field metadata
     * @return array Escape modes (0=none, 1=string, 2=bytea)
     */
    protected function determineEscapeModes($fields)
    {
        $escapeModes = [];

        foreach ($fields as $i => $field) {
            $type = strtolower($field['type'] ?? '');

            // Numeric types - no escaping
            if (
                in_array($type, [
                    'int2',
                    'int4',
                    'int8',
                    'integer',
                    'bigint',
                    'smallint',
                    'float4',
                    'float8',
                    'real',
                    'double precision',
                    'numeric',
                    'decimal'
                ])
            ) {
                $escapeModes[$i] = self::ESCAPE_NONE;
            }
            // Boolean - no escaping
            elseif (in_array($type, ['bool', 'boolean'])) {
                $escapeModes[$i] = self::ESCAPE_NONE;
            }
            // Bytea - special escaping
            elseif ($type === 'bytea') {
                $escapeModes[$i] = self::ESCAPE_BYTEA;
            }
            // Everything else - string escaping
            else {
                $escapeModes[$i] = self::ESCAPE_STRING;
            }
        }

        return $escapeModes;
    }

    /**
     * Write a row in COPY format
     * 
     * @param array $row Numeric array of values
     * @param array $escapeModes Escape modes for each column
     */
    protected function writeCopyRow($row, $escapeModes)
    {
        $line = '';
        $first = true;

        foreach ($row as $i => $v) {
            if (!$first) {
                $line .= "\t";
            }
            $first = false;

            if ($v === null) {
                $line .= '\\N';
            } else {
                if ($escapeModes[$i] === self::ESCAPE_BYTEA) {
                    // Bytea - octal escaping for COPY
                    $line .= bytea_to_octal($v);
                } else {
                    // COPY escaping: backslash and special chars
                    $v = addcslashes($v, "\0\\\n\r\t");
                    $line .= $v;
                }
            }
        }

        $this->write($line . "\n");
    }

    /**
     * Write INSERT VALUES clause
     * 
     * @param array $row Numeric array of values
     * @param array $escapeModes Escape modes for each column
     */
    protected function writeInsertValues($row, $escapeModes)
    {
        $values = "(";
        $first = true;

        foreach ($row as $i => $v) {
            if (!$first) {
                $values .= ",";
            }
            $first = false;

            if ($v === null) {
                $values .= "NULL";
            } elseif ($escapeModes[$i] === self::ESCAPE_STRING) {
                // String escaping
                $values .= $this->connection->conn->qstr($v);
            } elseif ($escapeModes[$i] === self::ESCAPE_BYTEA) {
                // Bytea escaping
                $values .= "'\\x" . bin2hex($v) . "'";
            } else {
                // No escaping (numeric/boolean)
                $values .= $v;
            }
        }

        $values .= ")";
        $this->write($values);
    }
}
