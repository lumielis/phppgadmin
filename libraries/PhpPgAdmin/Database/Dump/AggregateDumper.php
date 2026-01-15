<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\AggregateActions;

/**
 * Dumper for PostgreSQL aggregates.
 */
class AggregateDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $name = $params['aggregate'] ?? null;
        $basetype = $params['basetype'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$name) {
            return;
        }

        $aggregateActions = new AggregateActions($this->connection);
        $rs = $aggregateActions->getAggregate($name, $basetype);

        if ($rs && !$rs->EOF) {
            $this->write("\n-- Aggregate: \"{$schema}\".\"{$name}\"\n");

            // DROP AGGREGATE needs the type
            if (!empty($options['clean'])) {
                $typeStr = ($rs->fields['proargtypes'] === null) ? '*' : $rs->fields['proargtypes'];
                $this->write("DROP AGGREGATE IF EXISTS \"{$schema}\".\"{$name}\" ({$typeStr}) CASCADE;\n");
            }

            $this->write("CREATE AGGREGATE \"{$schema}\".\"{$name}\" (\n");
            $this->write("    BASETYPE = " . (($rs->fields['proargtypes'] === null) ? 'ANY' : $rs->fields['proargtypes']) . ",\n");
            $this->write("    SFUNC = {$rs->fields['aggtransfn']},\n");
            $this->write("    STYPE = {$rs->fields['aggstype']}");

            if ($rs->fields['aggfinalfn'] !== null && $rs->fields['aggfinalfn'] !== '-') {
                $this->write(",\n    FINALFUNC = {$rs->fields['aggfinalfn']}");
            }
            if ($rs->fields['agginitval'] !== null) {
                $this->write(",\n    INITCOND = '{$rs->fields['agginitval']}'");
            }
            if ($rs->fields['aggsortop'] !== null && $rs->fields['aggsortop'] !== '0') {
                // Need to resolve operator name
                $opSql = "SELECT oprname FROM pg_operator WHERE oid = '{$rs->fields['aggsortop']}'::oid";
                $opRs = $this->connection->selectSet($opSql);
                if ($opRs && !$opRs->EOF) {
                    $this->write(",\n    SORTOP = {$opRs->fields['oprname']}");
                }
            }

            $this->write("\n);\n");

            if ($this->shouldIncludeComments($options) && $rs->fields['aggrcomment'] !== null) {
                $this->connection->clean($rs->fields['aggrcomment']);
                $this->write("COMMENT ON AGGREGATE \"{$schema}\".\"{$name}\" (" . (($rs->fields['proargtypes'] === null) ? '*' : $rs->fields['proargtypes']) . ") IS '{$rs->fields['aggrcomment']}';\n");
            }
        }
    }
}
