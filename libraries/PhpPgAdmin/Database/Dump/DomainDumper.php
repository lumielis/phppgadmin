<?php

namespace PhpPgAdmin\Database\Dump;

/**
 * Dumper for PostgreSQL domains.
 */
class DomainDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $domainName = $params['domain'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$domainName) {
            return;
        }

        $c_domain = $domainName;
        $c_schema = $schema;
        $this->connection->clean($c_domain);
        $this->connection->clean($c_schema);

        $sql = "SELECT t.oid, t.typname,
                pg_catalog.format_type(t.typbasetype, t.typtypmod) AS basetype,
                t.typdefault, t.typnotnull,
                (SELECT pg_catalog.obj_description(t.oid, 'pg_type')) AS comment
                FROM pg_catalog.pg_type t
                JOIN pg_catalog.pg_namespace n ON n.oid = t.typnamespace
                WHERE t.typname = '{$c_domain}' AND n.nspname = '{$c_schema}'
                    AND t.typtype = 'd'";

        $rs = $this->connection->selectSet($sql);

        if (!$rs) {
            return;
        }

        if ($rs->EOF) {
            return;
        }

        $this->write("\n-- Domain: \"" . addslashes($c_schema) . "\".\"" . addslashes($c_domain) . "\"\n");
        $this->writeDrop('DOMAIN', "\"" . addslashes($c_schema) . "\".\"" . addslashes($c_domain), $options);

        $this->write("CREATE DOMAIN \"" . addslashes($c_schema) . "\".\"" . addslashes($c_domain) . "\" AS {$rs->fields['basetype']}");

        if (isset($rs->fields['typdefault']) && $rs->fields['typdefault'] !== null) {
            $this->write("\n    DEFAULT {$rs->fields['typdefault']}");
        }

        if ($this->connection->phpBool($rs->fields['typnotnull'])) {
            $this->write("\n    NOT NULL");
        }

        // Constraints
        $this->dumpConstraints($rs->fields['oid'], $options);

        $this->write(";\n");

        if ($this->shouldIncludeComments($options) && isset($rs->fields['comment']) && $rs->fields['comment'] !== null) {
            $this->connection->clean($rs->fields['comment']);
            $this->write(
                "COMMENT ON DOMAIN \"" . addslashes($c_schema) .
                "\".\"" . addslashes($c_domain) .
                "\" IS '{$rs->fields['comment']}';\n"
            );
        }

        $this->writePrivileges($domainName, 'type', $schema);
    }

    protected function dumpConstraints($domainOid, $options)
    {
        $sql = "SELECT conname, pg_catalog.pg_get_constraintdef(oid, true) AS consrc
                FROM pg_catalog.pg_constraint
                WHERE contypid = '{$domainOid}'::oid";

        $rs = $this->connection->selectSet($sql);
        if (!$rs) {
            return;
        }
        while (!$rs->EOF) {
            $conname = $rs->fields['conname'];
            $this->connection->clean($conname);
            $this->write("\n    CONSTRAINT \"" . addslashes($conname) . "\" {$rs->fields['consrc']}");
            $rs->moveNext();
        }
    }
}
