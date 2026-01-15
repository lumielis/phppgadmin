<?php

namespace PhpPgAdmin\Database\Dump;

/**
 * Dumper for PostgreSQL rules.
 */
class RuleDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $name = $params['rule'] ?? null;
        $table = $params['table'] ?? $params['view'] ?? null;
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

        $this->write("\n-- Rule: \"" . addslashes($c_name) . "\" ON \"" . addslashes($c_schema) . "\".\"" . addslashes($c_table) . "\"\n");

        if (!empty($options['clean'])) {
            $this->write("DROP RULE IF EXISTS \"" . addslashes($c_name) . "\" ON \"" . addslashes($c_schema) . "\".\"" . addslashes($c_table) . "\" CASCADE;\n");
        }

        // pg_get_ruledef(oid) is the easiest way
        $sql = "SELECT pg_get_ruledef(oid) as definition FROM pg_rewrite WHERE rulename = '{$c_name}' AND ev_class = (SELECT oid FROM pg_class WHERE relname = '{$c_table}' AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = '{$c_schema}'))";
        $defRs = $this->connection->selectSet($sql);

        if (!$defRs) {
            return;
        }

        if (!$defRs->EOF) {
            $this->write($defRs->fields['definition'] . ";\n");
        }
    }
}
