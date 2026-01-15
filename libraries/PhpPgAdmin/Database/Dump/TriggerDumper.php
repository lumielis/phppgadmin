<?php

namespace PhpPgAdmin\Database\Dump;

/**
 * Dumper for PostgreSQL triggers.
 */
class TriggerDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $name = $params['trigger'] ?? null;
        $table = $params['table'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$name || !$table) {
            return;
        }

        // Properly escape parameters
        $c_name = $name;
        $c_table = $table;
        $c_schema = $schema;
        $this->connection->clean($c_name);
        $this->connection->clean($c_table);
        $this->connection->clean($c_schema);

        $this->write("\n-- Trigger: \"" . addslashes($c_name) . "\" ON \"" . addslashes($c_schema) . "\".\"" . addslashes($c_table) . "\"\n");

        if (!empty($options['clean'])) {
            $this->write("DROP TRIGGER IF EXISTS \"" . addslashes($c_name) . "\" ON \"" . addslashes($c_schema) . "\".\"" . addslashes($c_table) . "\" CASCADE;\n");
        }

        // pg_get_triggerdef(oid) is available since 9.0
        $sql = "SELECT pg_get_triggerdef(oid) as definition FROM pg_trigger WHERE tgname = '{$c_name}' AND tgrelid = (SELECT oid FROM pg_class WHERE relname = '{$c_table}' AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = '{$c_schema}'))";
        $defRs = $this->connection->selectSet($sql);

        if (!$defRs) {
            return;
        }

        if (!$defRs->EOF) {
            $this->write($defRs->fields['definition'] . ";\n");
        }
    }
}
