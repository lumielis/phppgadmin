<?php

namespace PhpPgAdmin\Database\Dump;

/**
 * Dumper for PostgreSQL views.
 */
class ViewDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $view = $params['view'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$view) {
            return;
        }

        // Properly escape parameters
        $c_view = $view;
        $c_schema = $schema;
        $this->connection->clean($c_view);
        $this->connection->clean($c_schema);

        $sql = "SELECT c.oid, pg_catalog.pg_get_viewdef(c.oid, true) AS vwdefinition
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relname = '{$c_view}' AND n.nspname = '{$c_schema}'";

        $rs = $this->connection->selectSet($sql);

        if (!$rs) {
            return;
        }

        if ($rs->EOF) {
            return;
        }

        $oid = $rs->fields['oid'];
        $def = $rs->fields['vwdefinition'];

        $this->writeDrop('VIEW', "\"" . addslashes($c_schema) . "\".\"" . addslashes($c_view), $options);

        // pg_get_viewdef returns just the SELECT part, we need to wrap it
        // Note: CREATE OR REPLACE requires same columns, CREATE VIEW IF NOT EXISTS only works with full definition
        if (!empty($options['if_not_exists'])) {
            $this->write("CREATE VIEW IF NOT EXISTS \"" . addslashes($c_schema) . "\".\"" . addslashes($c_view) . "\" AS\n{$def};\n");
        } else {
            $this->write("CREATE OR REPLACE VIEW \"" . addslashes($c_schema) . "\".\"" . addslashes($c_view) . "\" AS\n{$def};\n");
        }

        // Add comment if present and requested
        if ($this->shouldIncludeComments($options)) {
            $commentSql = "SELECT pg_catalog.obj_description(c.oid, 'pg_class') AS comment FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = '{$c_view}' AND n.nspname = '{$c_schema}' AND c.relkind = 'v'";
            $commentRs = $this->connection->selectSet($commentSql);
            if ($commentRs && !$commentRs->EOF && !empty($commentRs->fields['comment'])) {
                $this->connection->clean($commentRs->fields['comment']);
                $this->write("COMMENT ON VIEW \"" . addslashes($c_schema) . "\".\"" . addslashes($c_view) . "\" IS '{$commentRs->fields['comment']}';\\n");
            }
        }

        $this->dumpRules($view, $schema, $options);
        $this->dumpTriggers($view, $schema, $options);

        $this->writePrivileges($view, 'view', $schema);
    }

    protected function dumpRules($view, $schema, $options)
    {
        // Properly escape parameters
        $c_view = $view;
        $c_schema = $schema;
        $this->connection->clean($c_view);
        $this->connection->clean($c_schema);

        $sql = "SELECT definition FROM pg_rules WHERE schemaname = '{$c_schema}' AND tablename = '{$c_view}'";
        $rs = $this->connection->selectSet($sql);
        if (!$rs) {
            return;
        }
        if ($rs->EOF) {
            return;
        }
        $this->write("\n-- Rules on view \"" . addslashes($c_schema) . "\".\"" . addslashes($c_view) . "\"\n");
        while (!$rs->EOF) {
            $this->write($rs->fields['definition'] . "\n");
            $rs->moveNext();
        }
    }

    protected function dumpTriggers($view, $schema, $options)
    {
        // Properly escape parameters
        $c_view = $view;
        $c_schema = $schema;
        $this->connection->clean($c_view);
        $this->connection->clean($c_schema);

        // pg_get_triggerdef(oid) is available since 9.0
        $sql = "SELECT pg_get_triggerdef(oid) as definition FROM pg_trigger WHERE tgrelid = (SELECT oid FROM pg_class WHERE relname = '{$c_view}' AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = '{$c_schema}'))";
        $rs = $this->connection->selectSet($sql);
        if (!$rs) {
            return;
        }
        if ($rs->EOF) {
            return;
        }
        $this->write("\n-- Triggers on view \"" . addslashes($c_schema) . "\".\"" . addslashes($c_view) . "\"\n");
        while (!$rs->EOF) {
            $this->write($rs->fields['definition'] . ";\n");
            $rs->moveNext();
        }
    }

    /**
     * Get view data as an ADORecordSet for export formatting.
     * Views are usually read-only, so this executes SELECT * FROM view.
     *
     * @param array $params View parameters (schema, view)
     * @return mixed ADORecordSet or null if view cannot be read
     */
    public function getTableData($params)
    {
        $view = $params['view'] ?? $params['table'] ?? null;  // Support 'table' param for compatibility
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$view) {
            return null;
        }

        // Use a simple SELECT * FROM view to get data
        $sql = "SELECT * FROM \"" . str_replace('"', '""', $schema) . "\".\"" . str_replace('"', '""', $view) . "\"";

        $this->connection->conn->setFetchMode(ADODB_FETCH_NUM);
        $recordset = $this->connection->selectSet($sql);

        return $recordset;
    }
}
