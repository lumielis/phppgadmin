<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\AclActions;
use PhpPgAdmin\Database\Actions\RuleActions;
use PhpPgAdmin\Database\Export\SqlFormatter;
use PhpPgAdmin\Database\Actions\AdminActions;
use PhpPgAdmin\Database\Actions\IndexActions;
use PhpPgAdmin\Database\Actions\TableActions;
use PhpPgAdmin\Database\Actions\ConstraintActions;

/**
 * Dumper for PostgreSQL tables (structure and data).
 */
class TableDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$table) {
            return;
        }

        $this->write("\n-- Table: \"{$schema}\".\"{$table}\"\n");

        if (empty($options['data_only'])) {
            $this->dumpStructure($table, $schema, $options);
        }

        if (empty($options['structure_only'])) {
            $this->dumpData($table, $schema, $options);
        }

        if (empty($options['data_only'])) {
            $this->dumpIndexesTriggersRules($table, $schema, $options);
        }
    }

    protected function dumpStructure($table, $schema, $options)
    {
        // Use existing logic from TableActions/Postgres driver but adapted
        $prefix = $this->getTableDefPrefix($table, !empty($options['clean']));
        if ($prefix) {
            // Handle IF NOT EXISTS if requested
            if (!empty($options['if_not_exists'])) {
                $prefix = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $prefix);
            }
            $this->write($prefix);
        }

        $this->dumpAutovacuumSettings($table, $schema);
    }

    protected function dumpData($table, $schema, $options)
    {
        $this->write("\n-- Data for table \"{$schema}\".\"{$table}\"\n");

        $insertFormat = $options['insert_format'] ?? 'copy'; // 'copy', 'single', or 'multi'
        $oids = !empty($options['oids']);

        // Optionally set session_replication_role to replica to avoid firing triggers during restore
        $replication_role_set = false;
        if (empty($options['suppress_replication_role'])) {
            $this->write("SET session_replication_role = 'replica';\n\n");
            $replication_role_set = true;
        }

        // Set fetch mode to NUM for data dumping
        $this->connection->conn->setFetchMode(ADODB_FETCH_NUM);

        $rs = $this->connection->dumpRelation($table, $oids);

        if (!$rs) {
            // No recordset at all
            if ($replication_role_set) {
                $this->write("SET session_replication_role = 'origin';\n\n");
            }
            return;
        }

        // Move to first record (recordset may be positioned at EOF after initial select)
        if (is_callable([$rs, 'moveFirst'])) {
            $rs->moveFirst();
        }

        // Check if there's actually data after moving to first record
        if ($rs->EOF) {
            // No data to export
            if ($replication_role_set) {
                $this->write("SET session_replication_role = 'origin';\n\n");
            }
            return;
        }

        // Use SqlFormatter to generate SQL output
        $formatter = new SqlFormatter();

        // Set formatter to use dumper's output stream
        $formatter->setOutputStream($this->outputStream);

        // Format the recordset and write to output
        $metadata = [
            'table' => "\"{$schema}\".\"{$table}\"",
            'insert_format' => $insertFormat
        ];

        $formatter->format($rs, $metadata);


        // Restore fetch mode
        $this->connection->conn->setFetchMode(ADODB_FETCH_ASSOC);

        // Reset session replication role if we set it earlier
        if ($replication_role_set) {
            $this->write("SET session_replication_role = 'origin';\n\n");
        }
    }

    protected function dumpIndexesTriggersRules($table, $schema, $options)
    {
        $suffix = $this->getTableDefSuffix($table);

        if ($suffix) {
            if (!empty($options['if_not_exists'])) {
                // Indexes - support from Postgres 9.5 onwards
                if ($this->connection->major_version >= 9.5) {
                    $suffix = str_replace(
                        'CREATE INDEX',
                        'CREATE INDEX IF NOT EXISTS',
                        $suffix
                    );
                    $suffix = str_replace(
                        'CREATE UNIQUE INDEX',
                        'CREATE UNIQUE INDEX IF NOT EXISTS',
                        $suffix
                    );
                }

                // Trigger - use OR REPLACE as emulation?
                if ($this->connection->major_version >= 14) {
                    $suffix = str_replace(
                        'CREATE TRIGGER',
                        'CREATE OR REPLACE TRIGGER',
                        $suffix
                    );
                    $suffix = str_replace(
                        'CREATE CONSTRAINT TRIGGER',
                        'CREATE OR REPLACE CONSTRAINT TRIGGER',
                        $suffix
                    );
                }

                // Rules - use OR REPLACE as emulation?
                $suffix = str_replace(
                    'CREATE RULE',
                    'CREATE OR REPLACE RULE',
                    $suffix
                );
            }
            $this->write($suffix);
        }

        $this->writePrivileges($table, 'table', $schema);
    }

    /**
     * Returns SQL for changing current user.
     */
    private function getChangeUserSQL($user)
    {
        $this->connection->clean($user);
        return "SET SESSION AUTHORIZATION '{$user}';";
    }

    /**
     * Get table definition prefix. Must be run within a transaction.
     */
    public function getTableDefPrefix($table, $clean = false)
    {
        $tableActions = new TableActions($this->connection);
        $t = $tableActions->getTable($table);
        if (!is_object($t) || $t->recordCount() != 1) {
            $this->connection->rollbackTransaction();
            return null;
        }
        $this->connection->fieldClean($t->fields['relname']);
        $this->connection->fieldClean($t->fields['nspname']);

        $atts = $tableActions->getTableAttributes($table);
        if (!is_object($atts)) {
            $this->connection->rollbackTransaction();
            return null;
        }

        $cons = (new ConstraintActions($this->connection))->getConstraints($table);
        if (!is_object($cons)) {
            $this->connection->rollbackTransaction();
            return null;
        }

        $sql = $this->getChangeUserSQL($t->fields['relowner']) . "\n\n";
        $sql .= "SET search_path = \"{$t->fields['nspname']}\", pg_catalog;\n\n";
        $sql .= "-- Definition\n\n";
        if (!$clean)
            $sql .= "-- ";
        $sql .= "DROP TABLE \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\";\n";
        $sql .= "CREATE TABLE \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\" (\n";

        $col_comments_sql = '';
        $first_attr = true;
        while (!$atts->EOF) {
            if ($first_attr) {
                $first_attr = false;
            } else {
                $sql .= ",\n";
            }
            $this->connection->fieldClean($atts->fields['attname']);
            $sql .= "    \"{$atts->fields['attname']}\"";
            if (
                $this->connection->phpBool($atts->fields['attisserial']) &&
                ($atts->fields['type'] == 'integer' || $atts->fields['type'] == 'bigint')
            ) {
                $sql .= ($atts->fields['type'] == 'integer') ? " SERIAL" : " BIGSERIAL";
            } else {
                $sql .= " " . $this->connection->formatType($atts->fields['type'], $atts->fields['atttypmod']);
                if ($this->connection->phpBool($atts->fields['attnotnull'])) {
                    $sql .= " NOT NULL";
                }
                if ($atts->fields['adsrc'] !== null) {
                    $sql .= " DEFAULT {$atts->fields['adsrc']}";
                }
            }

            if ($atts->fields['comment'] !== null) {
                $this->connection->clean($atts->fields['comment']);
                $col_comments_sql .= "COMMENT ON COLUMN \"{$t->fields['relname']}\".\"{$atts->fields['attname']}\"  IS '{$atts->fields['comment']}';\n";
            }

            $atts->moveNext();
        }

        while (!$cons->EOF) {
            if ($cons->fields['contype'] == 'n') {
                // Skip NOT NULL constraints as they are dumped with the column definition
                $cons->moveNext();
                continue;
            }
            $sql .= ",\n";
            $this->connection->fieldClean($cons->fields['conname']);
            $sql .= "    CONSTRAINT \"{$cons->fields['conname']}\" ";
            if ($cons->fields['consrc'] !== null) {
                $sql .= $cons->fields['consrc'];
            } else {
                switch ($cons->fields['contype']) {
                    case 'p':
                        $keys = $tableActions->getAttributeNames($table, explode(' ', $cons->fields['indkey']));
                        $sql .= "PRIMARY KEY (" . join(',', $keys) . ")";
                        break;
                    case 'u':
                        $keys = $tableActions->getAttributeNames($table, explode(' ', $cons->fields['indkey']));
                        $sql .= "UNIQUE (" . join(',', $keys) . ")";
                        break;
                    default:
                        $this->connection->rollbackTransaction();
                        return null;
                }
            }

            $cons->moveNext();
        }

        $sql .= "\n)";

        if ($this->connection->hasObjectID($table)) {
            $sql .= " WITH OIDS";
        } else {
            $sql .= " WITHOUT OIDS";
        }

        $sql .= ";\n";

        $atts->moveFirst();
        $first = true;
        while (!$atts->EOF) {
            $this->connection->fieldClean($atts->fields['attname']);
            // Only output SET STATISTICS if the value is non-negative and not empty
            if (isset($atts->fields['attstattarget']) && $atts->fields['attstattarget'] !== '' && $atts->fields['attstattarget'] >= 0) {
                if ($first) {
                    $sql .= "\n";
                    $first = false;
                }
                $sql .= "ALTER TABLE ONLY \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\" ALTER COLUMN \"{$atts->fields['attname']}\" SET STATISTICS {$atts->fields['attstattarget']};\n";
            }
            if ($atts->fields['attstorage'] != $atts->fields['typstorage']) {
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
                        $this->connection->rollbackTransaction();
                        return null;
                }
                $sql .= "ALTER TABLE ONLY \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\" ALTER COLUMN \"{$atts->fields['attname']}\" SET STORAGE {$storage};\n";
            }

            $atts->moveNext();
        }

        if ($t->fields['relcomment'] !== null) {
            $this->connection->clean($t->fields['relcomment']);
            $sql .= "\n-- Comment\n\n";
            $sql .= "COMMENT ON TABLE \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\" IS '{$t->fields['relcomment']}';\n";
        }

        if ($col_comments_sql != '') {
            $sql .= $col_comments_sql;
        }

        $privs = (new AclActions($this->connection))->getPrivileges($table, 'table');
        if (!is_array($privs)) {
            $this->connection->rollbackTransaction();
            return null;
        }

        if (sizeof($privs) > 0) {
            $sql .= "\n-- Privileges\n\n";
            $sql .= "REVOKE ALL ON TABLE \"{$t->fields['nspname']}\".\"{$t->fields['relname']}\" FROM PUBLIC;\n";
            foreach ($privs as $v) {
                $nongrant = array_diff($v[2], $v[4]);
                if (sizeof($v[2]) == 0 || ($v[0] == 'user' && $v[1] == $t->fields['relowner']))
                    continue;
                if ($v[3] != $t->fields['relowner']) {
                    $grantor = $v[3];
                    $this->connection->clean($grantor);
                    $sql .= "SET SESSION AUTHORIZATION '{$grantor}';\n";
                }
                $sql .= "GRANT " . join(', ', $nongrant) . " ON TABLE \"{$t->fields['relname']}\" TO ";
                switch ($v[0]) {
                    case 'public':
                        $sql .= "PUBLIC;\n";
                        break;
                    case 'user':
                        $this->connection->fieldClean($v[1]);
                        $sql .= "\"{$v[1]}\";\n";
                        break;
                    case 'group':
                        $this->connection->fieldClean($v[1]);
                        $sql .= "GROUP \"{$v[1]}\";\n";
                        break;
                    default:
                        $this->connection->rollbackTransaction();
                        return null;
                }

                if ($v[3] != $t->fields['relowner']) {
                    $sql .= "RESET SESSION AUTHORIZATION;\n";
                }

                if (sizeof($v[4]) == 0)
                    continue;

                if ($v[3] != $t->fields['relowner']) {
                    $grantor = $v[3];
                    $this->connection->clean($grantor);
                    $sql .= "SET SESSION AUTHORIZATION '{$grantor}';\n";
                }

                $sql .= "GRANT " . join(', ', $v[4]) . " ON \"{$t->fields['relname']}\" TO ";
                switch ($v[0]) {
                    case 'public':
                        $sql .= "PUBLIC";
                        break;
                    case 'user':
                        $this->connection->fieldClean($v[1]);
                        $sql .= "\"{$v[1]}\"";
                        break;
                    case 'group':
                        $this->connection->fieldClean($v[1]);
                        $sql .= "GROUP \"{$v[1]}\"";
                        break;
                    default:
                        return null;
                }
                $sql .= " WITH GRANT OPTION;\n";

                if ($v[3] != $t->fields['relowner']) {
                    $sql .= "RESET SESSION AUTHORIZATION;\n";
                }
            }
        }

        $sql .= "\n";

        return $sql;
    }

    /**
     * Returns extra table definition (indexes, triggers, rules).
     */
    public function getTableDefSuffix($table)
    {
        $sql = '';

        $indexes = $this->getIndexes($table);
        if (!is_object($indexes)) {
            $this->connection->rollbackTransaction();
            return null;
        }

        if ($indexes->recordCount() > 0) {
            $sql .= "\n-- Indexes\n\n";
            while (!$indexes->EOF) {
                $sql .= $indexes->fields['inddef'] . ";\n";
                $indexes->moveNext();
            }
        }

        $triggers = $this->getTriggers($table);
        if (!is_object($triggers)) {
            $this->connection->rollbackTransaction();
            return null;
        }

        if ($triggers->recordCount() > 0) {
            $sql .= "\n-- Triggers\n\n";
            while (!$triggers->EOF) {
                $sql .= $triggers->fields['tgdef'];
                $sql .= ";\n";
                $triggers->moveNext();
            }
        }

        $rules = (new RuleActions($this->connection))->getRules($table);
        if (!is_object($rules)) {
            $this->connection->rollbackTransaction();
            return null;
        }

        if ($rules->recordCount() > 0) {
            $sql .= "\n-- Rules\n\n";
            while (!$rules->EOF) {
                $sql .= $rules->fields['definition'] . "\n";
                $rules->moveNext();
            }
        }

        return $sql;
    }

    /**
     * Grabs a list of indexes for a table.
     */
    public function getIndexes($table = '')
    {
        $this->connection->clean($table);

        $sql = "SELECT
                c2.relname AS indname, i.indisprimary, i.indisunique, i.indisclustered,
                pg_catalog.pg_get_indexdef(i.indexrelid, 0, true) AS inddef
            FROM pg_catalog.pg_class c, pg_catalog.pg_class c2, pg_catalog.pg_index i
            WHERE c.relname = '{$table}'
                AND pg_catalog.pg_table_is_visible(c.oid)
                AND c.oid = i.indrelid
                AND i.indexrelid = c2.oid
                AND i.indisprimary = false
            ORDER BY c2.relname";

        return $this->connection->selectSet($sql);
    }

    private function getTriggers($table)
    {
        $c_schema = $this->connection->_schema;
        $this->connection->clean($c_schema);
        $this->connection->clean($table);

        $sql = "SELECT
                t.tgname, pg_catalog.pg_get_triggerdef(t.oid) AS tgdef,
                CASE WHEN t.tgenabled = 'D' THEN FALSE ELSE TRUE END AS tgenabled, p.oid AS prooid,
                p.proname || ' (' || pg_catalog.oidvectortypes(p.proargtypes) || ')' AS proproto,
                ns.nspname AS pronamespace
            FROM pg_catalog.pg_trigger t, pg_catalog.pg_proc p, pg_catalog.pg_namespace ns
            WHERE t.tgrelid = (SELECT oid FROM pg_catalog.pg_class WHERE relname='{$table}'
                AND relnamespace=(SELECT oid FROM pg_catalog.pg_namespace WHERE nspname='{$c_schema}'))
                AND ( tgconstraint = 0 OR NOT EXISTS
                        (SELECT 1 FROM pg_catalog.pg_depend d    JOIN pg_catalog.pg_constraint c
                            ON (d.refclassid = c.tableoid AND d.refobjid = c.oid)
                        WHERE d.classid = t.tableoid AND d.objid = t.oid AND d.deptype = 'i' AND c.contype = 'f'))
                AND p.oid=t.tgfoid
                AND p.pronamespace = ns.oid";

        return $this->connection->selectSet($sql);
    }

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
     * Get table data as an ADORecordSet for export formatting.
     *
     * @param array $params Table parameters (schema, table)
     * @return mixed ADORecordSet or null if table cannot be read
     */
    public function getTableData($params)
    {
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$table) {
            return null;
        }

        // Use existing dumpRelation method from connection to get table data
        $this->connection->conn->setFetchMode(ADODB_FETCH_NUM);
        $recordset = $this->connection->dumpRelation($table, false);

        return $recordset;
    }
}
