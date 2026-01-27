<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\AclActions;
use PhpPgAdmin\Database\Actions\RuleActions;
use PhpPgAdmin\Database\Actions\TriggerActions;
use PhpPgAdmin\Database\Export\SqlFormatter;
use PhpPgAdmin\Database\Actions\AdminActions;
use PhpPgAdmin\Database\Actions\IndexActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\ConstraintActions;
use PhpPgAdmin\Database\Cursor\CursorReader;

/**
 * Dumper for PostgreSQL tables (structure and data).
 */
class TableDumper extends ExportDumper
{
    private $tableQuoted;
    private $schemaQuoted;
    private $deferredConstraints = [];
    private $deferredIndexes = [];

    public function dump($subject, array $params, array $options = [])
    {
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$table) {
            return;
        }

        $this->tableQuoted = $this->connection->quoteIdentifier($table);
        $this->schemaQuoted = $this->connection->quoteIdentifier($schema);

        $this->write("\n-- Table: \"{$schema}\".\"{$table}\"\n\n");

        if (empty($options['data_only'])) {
            // Reset deferred constraints/indexes for this table
            $this->deferredConstraints = [];
            $this->deferredIndexes = [];

            // Use existing logic from TableActions/Postgres driver but adapted
            // Use writer-style method instead of getting SQL back
            $this->dumpTableStructure($table, $options);

            $this->dumpAutovacuumSettings($table, $schema);
        }

        if (empty($options['structure_only'])) {
            $this->dumpData($table, $schema, $options);
        }

        if (empty($options['data_only'])) {
            // Apply constraints and indexes AFTER data import for better performance
            $this->applyDeferredConstraints($options);
            $this->writeIndexes($table, $options);
            $this->deferTriggers($table, $schema, $options);
            $this->deferRules($table, $schema, $options);
        }

        // Register this table as dumped (for sequence ownership validation)
        if ($this->parentDumper && method_exists($this->parentDumper, 'registerDumpedTable')) {
            $this->parentDumper->registerDumpedTable($schema, $table);
        }
    }

    protected function dumpData($table, $schema, $options)
    {
        $this->write("\n-- Data for table \"{$schema}\".\"{$table}\"\n");

        try {
            // Build SQL query for table export
            $sql = "SELECT * FROM {$this->schemaQuoted}.{$this->tableQuoted}";

            // Create cursor reader with automatic chunk size calculation
            $reader = new CursorReader(
                $this->connection,
                $sql,
                null, // Auto-calculate chunk size
                $table,
                $schema,
                'r' // relation kind
            );

            // Open cursor (begins transaction)
            $reader->open();

            // Send data to SQL formatter for output
            $sqlFormatter = new SqlFormatter();
            $sqlFormatter->setOutputStream($this->outputStream);
            $metadata = [
                'table' => "{$this->schemaQuoted}.{$this->tableQuoted}",
                'batch_size' => $options['batch_size'] ?? 1000,
                'insert_format' => $options['insert_format'] ?? 'copy',
            ];
            $reader->processRows($sqlFormatter, $metadata);

            // Close cursor (commits transaction)
            $reader->close();

        } catch (\Exception $e) {
            error_log('Error dumping table data: ' . $e->getMessage());
            $this->write("-- Error dumping data: " . $e->getMessage() . "\n");
        }
    }

    /**
     * Write table definition prefix (columns, constraints, comments, privileges).
     * Returns true on success, false on failure or missing table.
     */
    protected function dumpTableStructure($table, $options)
    {
        $tableActions = new TableActions($this->connection);
        $t = $tableActions->getTable($table);
        if (!is_object($t) || $t->recordCount() != 1) {
            return false;
        }

        $atts = $tableActions->getTableAttributes($table);
        if (!is_object($atts)) {
            return false;
        }

        $constraintActions = new ConstraintActions($this->connection);
        $cons = $constraintActions->getConstraints($table);
        if (!is_object($cons)) {
            return false;
        }

        // header / drop / create begin
        $this->write("-- Definition\n\n");
        $this->writeDrop('TABLE', "{$this->schemaQuoted}.{$this->tableQuoted}", $options);
        $this->write("CREATE TABLE {$this->schemaQuoted}.{$this->tableQuoted} (\n");

        // columns
        $col_comments_sql = '';
        $first_attr = true;
        while (!$atts->EOF) {
            if ($first_attr) {
                $first_attr = false;
            } else {
                $this->write(",\n");
            }
            $name = $this->connection->quoteIdentifier($atts->fields['attname']);
            $this->write("    {$name} {$atts->fields['type']}");

            // Check for generated column first (PostgreSQL 12+)
            if (isset($atts->fields['attgenerated']) && $atts->fields['attgenerated'] === 's') {
                // Generated stored column - write GENERATED ALWAYS AS
                if ($atts->fields['adsrc'] !== null) {
                    $this->write(" GENERATED ALWAYS AS ({$atts->fields['adsrc']}) STORED");
                }
            } else {
                // Regular column - handle NOT NULL and DEFAULT
                if ($this->connection->phpBool($atts->fields['attnotnull'])) {
                    $this->write(" NOT NULL");
                }

                if ($atts->fields['sequence_name'] !== null) {
                    // Case 1: Owned sequence (has AUTO dependency in pg_depend)
                    $seqSchema = $this->connection->quoteIdentifier($atts->fields['sequence_schema']);
                    $seqName = $this->connection->quoteIdentifier($atts->fields['sequence_name']);
                    $default = "nextval('{$seqSchema}.{$seqName}'::regclass)";
                    $this->write(" DEFAULT {$default}");
                } elseif ($atts->fields['adsrc'] !== null && preg_match("/nextval\\('((?:[^']|'')+)'/", $atts->fields['adsrc'], $matches)) {
                    // Case 2: Non-owned sequence - parse and resolve schema
                    // Unescape doubled single quotes
                    $seqIdentifier = str_replace("''", "'", $matches[1]);
                    $resolvedSeq = $this->resolveSequenceSchema($seqIdentifier);

                    if ($resolvedSeq) {
                        $seqSchema = $this->connection->quoteIdentifier($resolvedSeq['schema']);
                        $seqName = $this->connection->quoteIdentifier($resolvedSeq['name']);
                        $default = "nextval('{$seqSchema}.{$seqName}'::regclass)";
                        $this->write(" DEFAULT {$default}");
                    } else {
                        // Fallback to original if resolution fails
                        $this->write(" DEFAULT {$atts->fields['adsrc']}");
                    }
                } elseif ($atts->fields['adsrc'] !== null) {
                    // Case 3: Other defaults (not sequence-related)
                    $this->write(" DEFAULT {$atts->fields['adsrc']}");
                }
            }

            if ($atts->fields['comment'] !== null) {
                $comment = $this->connection->escapeString($atts->fields['comment']);
                $col_comments_sql .= "COMMENT ON COLUMN {$this->schemaQuoted}.{$this->tableQuoted}.{$this->connection->quoteIdentifier($atts->fields['attname'])} IS '{$comment}';\n";
            }

            $atts->moveNext();
        }

        // Store constraints for deferred application (except NOT NULL)
        while (!$cons->EOF) {
            if ($cons->fields['contype'] == 'n') {
                // Skip NOT NULL constraints as they are dumped with the column definition
                $cons->moveNext();
                continue;
            }

            $name = $this->connection->quoteIdentifier($cons->fields['conname']);
            $src = $cons->fields['consrc'];
            if (empty($src)) {
                // Build constraint source from type and columns
                $columns = trim($cons->fields['columns'], '{}');
                switch ($cons->fields['contype']) {
                    case 'p':
                        $src = "PRIMARY KEY ($columns)";
                        break;
                    case 'u':
                        $src = "UNIQUE ($columns)";
                        break;
                    case 'f':
                        // Foreign key - should not happen as consrc is always populated
                        $src = $cons->fields['consrc'];
                        break;
                    case 'c':
                        // Check constraint - should not happen as consrc is always populated
                        $src = $cons->fields['consrc'];
                        break;
                    default:
                        $cons->moveNext();
                        continue 2;
                }
            }

            // Add schema qualification to foreign key references
            // pg_get_constraintdef() doesn't include schema when search_path is empty
            if ($cons->fields['contype'] === 'f' && !empty($cons->fields['f_schema']) && !empty($cons->fields['f_table'])) {
                $fSchema = $this->connection->quoteIdentifier($cons->fields['f_schema']);
                $fTable = $this->connection->quoteIdentifier($cons->fields['f_table']);
                $unqualifiedTable = $cons->fields['f_table'];

                // Replace unqualified table reference with schema-qualified version
                // Pattern: REFERENCES tablename( or REFERENCES tablename (
                $src = preg_replace(
                    '/REFERENCES\s+' . preg_quote($unqualifiedTable, '/') . '\s*\(/i',
                    "REFERENCES {$fSchema}.{$fTable}(",
                    $src
                );
            }

            // Store constraint for later application
            $this->deferredConstraints[] = [
                'name' => $name,
                'definition' => $src,
                'type' => $cons->fields['contype']
            ];

            $cons->moveNext();
        }

        $this->write("\n)");

        if ($this->connection->hasObjectID($table)) {
            $this->write(" WITH OIDS");
        } else {
            $this->write(" WITHOUT OIDS");
        }

        $this->write(";\n");

        // per-column ALTERs (statistics, storage)
        $atts->moveFirst();
        $first = true;
        while (!$atts->EOF) {
            $fieldQuoted = $this->connection->quoteIdentifier($atts->fields['attname']);

            // Set sequence ownership if applicable
            if (!empty($atts->fields['sequence_name'])) {
                if ($first) {
                    $this->write("\n");
                    $first = false;
                }
                $sequenceQuoted = $this->connection->quoteIdentifier($atts->fields['sequence_name']);
                $this->write("\nALTER SEQUENCE {$this->schemaQuoted}.{$sequenceQuoted} OWNED BY {$this->schemaQuoted}.{$this->tableQuoted}.{$fieldQuoted};\n");
            }

            // Set statistics target
            $stat = $atts->fields['attstattarget'];
            if ($stat !== null && $stat !== '' && is_numeric($stat) && $stat >= 0) {
                if ($first) {
                    $this->write("\n");
                    $first = false;
                }
                $this->write("ALTER TABLE ONLY {$this->schemaQuoted}.{$this->tableQuoted} ALTER COLUMN {$fieldQuoted} SET STATISTICS {$stat};\n");
            }

            // Set storage parameter
            if ($atts->fields['attstorage'] != $atts->fields['typstorage']) {
                if ($first) {
                    $this->write("\n");
                    $first = false;
                }
                $storage = null;
                switch ($atts->fields['attstorage']) {
                    case 'p':
                        $storage = 'PLAIN';
                        break;
                    case 'e':
                        $storage = 'EXTERNAL';
                        break;
                    case 'm':
                        $storage = 'MAIN';
                        break;
                    case 'x':
                        $storage = 'EXTENDED';
                        break;
                    default:
                        return false;
                }
                $this->write("ALTER TABLE ONLY {$this->schemaQuoted}.{$this->tableQuoted} ALTER COLUMN {$fieldQuoted} SET STORAGE {$storage};\n");
            }

            $atts->moveNext();
        }

        // table comment
        if ($t->fields['relcomment'] !== null) {
            $comment = $this->connection->escapeString($t->fields['relcomment']);
            $this->write("\n-- Comment\n\n");
            $this->write("COMMENT ON TABLE {$this->schemaQuoted}.{$this->tableQuoted} IS '{$comment}';\n");
        }

        // column comments
        if ($col_comments_sql != '') {
            $this->write($col_comments_sql);
        }

        // privileges
        $this->writePrivileges(
            $table,
            'table',
            $t->fields['relowner'],
            $t->fields['relacl']
        );

        $this->write("\n");

        return true;
    }

    /**
     * Apply deferred constraints after data import.
     */
    private function applyDeferredConstraints($options)
    {
        if (empty($this->deferredConstraints)) {
            return;
        }

        $this->write("\n-- Constraints (applied after data import)\n\n");

        foreach ($this->deferredConstraints as $constraint) {
            $this->write("ALTER TABLE {$this->schemaQuoted}.{$this->tableQuoted} ");
            $this->write("ADD CONSTRAINT {$constraint['name']} {$constraint['definition']}");
            $this->write(";\n");
        }
    }

    /**
     * Write indexes for the table.
     */
    private function writeIndexes($table, $options)
    {
        $indexActions = new IndexActions($this->connection);

        $indexes = $indexActions->getIndexes($table);

        if (!is_object($indexes) || $indexes->EOF) {
            return;
        }

        $this->write("\n-- Indexes\n\n");

        while (!$indexes->EOF) {
            if ($indexes->fields['indisprimary']) {
                // Skip primary key index (created with constraint)
                $indexes->moveNext();
                continue;
            }

            $def = $indexes->fields['inddef'];

            // Replace tablename with schema-qualified name
            $def = preg_replace(
                '/ ON ([^ ]+) /',
                " ON {$this->schemaQuoted}.$1 ",
                $def
            );

            if (!empty($options['if_not_exists'])) {
                if ($this->connection->major_version >= 9.5) {
                    $def = str_replace(
                        'CREATE UNIQUE INDEX',
                        'CREATE UNIQUE INDEX IF NOT EXISTS',

                        $def
                    );
                    $def = str_replace(
                        'CREATE INDEX',
                        'CREATE INDEX IF NOT EXISTS',
                        $def
                    );
                }
            }
            $this->write("$def;\n");
            $indexes->moveNext();
        }
    }

    /**
     * Defer triggers for the table (to be applied after functions are created).
     */
    private function deferTriggers($table, $schema, $options)
    {
        $triggerActions = new TriggerActions($this->connection);
        $triggers = $triggerActions->getTriggers($table);

        if (!is_object($triggers) || $triggers->EOF) {
            return;
        }

        while (!$triggers->EOF) {
            $def = $triggers->fields['tgdef'];
            if (!empty($options['if_not_exists'])) {
                if ($this->connection->major_version >= 14) {
                    $def = str_replace(
                        'CREATE CONSTRAINT TRIGGER',
                        'CREATE OR REPLACE CONSTRAINT TRIGGER',
                        $def
                    );
                    $def = str_replace(
                        'CREATE TRIGGER',
                        'CREATE OR REPLACE TRIGGER',
                        $def
                    );
                }
            }

            // Add to parent SchemaDumper's deferred collection
            if ($this->parentDumper && method_exists($this->parentDumper, 'addDeferredTrigger')) {
                $this->parentDumper->addDeferredTrigger($schema, $table, $def);
            }

            $triggers->moveNext();
        }
    }

    /**
     * Defer rules for the table (to be applied after functions are created).
     */
    private function deferRules($table, $schema, $options)
    {
        $ruleActions = new RuleActions($this->connection);
        $rules = $ruleActions->getRules($table);

        if (!is_object($rules) || $rules->EOF) {
            return;
        }

        while (!$rules->EOF) {
            $def = $rules->fields['definition'];
            $def = str_replace('CREATE RULE', 'CREATE OR REPLACE RULE', $def);

            // Add to parent SchemaDumper's deferred collection
            if ($this->parentDumper && method_exists($this->parentDumper, 'addDeferredRule')) {
                $this->parentDumper->addDeferredRule($schema, $table, $def);
            }

            $rules->moveNext();
        }
    }


    /**
     * Dump autovacuum settings for the table.
     */
    protected function dumpAutovacuumSettings($table, $schema)
    {
        $adminActions = new AdminActions($this->connection);

        $oldSchema = $this->connection->_schema;
        $this->connection->_schema = $schema;

        $autovacs = $adminActions->getTableAutovacuum($table);

        $this->connection->_schema = $oldSchema;

        if (!$autovacs || $autovacs->EOF) {
            return;
        }

        while ($autovacs && !$autovacs->EOF) {
            $options = [];
            foreach ($autovacs->fields as $key => $value) {
                if (is_int($key)) {
                    continue;
                }
                if ($key === 'nspname' || $key === 'relname') {
                    continue;
                }
                if ($value === null || $value === '') {
                    continue;
                }
                $options[] = $key . '=' . $value;
            }

            if (!empty($options)) {
                $this->write("ALTER TABLE \"{$schema}\".\"{$table}\" SET (" . implode(', ', $options) . ");\n");
                $this->write("\n");
            }

            $autovacs->moveNext();
        }
    }

    /**
     * Resolve sequence schema from identifier (handles both qualified and unqualified names).
     * 
     * @param string $identifier The sequence identifier from nextval() expression
     * @return array|null Array with 'schema' and 'name' keys, or null if not found
     */
    private function resolveSequenceSchema($identifier)
    {
        // Remove quotes if present
        $identifier = trim($identifier, '"');

        // If already schema-qualified (contains dot), parse it
        if (strpos($identifier, '.') !== false) {
            $parts = explode('.', $identifier, 2);
            return [
                'schema' => trim($parts[0], '"'),
                'name' => trim($parts[1], '"')
            ];
        }

        // Query to find sequence schema (using pg_table_is_visible for search_path)
        $this->connection->clean($identifier);
        $sql = "SELECT n.nspname AS schema, c.relname AS name
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON c.relnamespace = n.oid
                WHERE c.relkind = 'S' 
                  AND c.relname = '{$identifier}'
                  AND pg_catalog.pg_table_is_visible(c.oid)
                LIMIT 1";

        $result = $this->connection->selectSet($sql);
        if ($result && !$result->EOF) {
            return [
                'schema' => $result->fields['schema'],
                'name' => $result->fields['name']
            ];
        }

        return null;
    }

}
