<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\OperatorActions;

/**
 * Dumper for PostgreSQL operators.
 */
class OperatorDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $oid = $params['operator_oid'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$oid) {
            return;
        }

        $operatorActions = new OperatorActions($this->connection);
        $rs = $operatorActions->getOperator($oid);

        if ($rs && !$rs->EOF) {
            $name = $rs->fields['oprname'];
            $leftType = $rs->fields['oprleftname'] ?? null;
            $rightType = $rs->fields['oprrightname'] ?? null;

            $oprcom = $rs->fields['oprcom'] ?? null;
            $oprnegate = $rs->fields['oprnegate'] ?? null;
            $oprrest = $rs->fields['oprrest'] ?? null;
            $oprjoin = $rs->fields['oprjoin'] ?? null;
            $oprcanhash = $rs->fields['oprcanhash'] ?? null;
            $oprcanmerge = $rs->fields['oprcanmerge'] ?? null;
            $oprcomment = $rs->fields['oprcomment'] ?? null;

            $this->write("\n-- Operator: \"{$schema}\".{$name}\n");

            if (!empty($options['clean'])) {
                $this->write("DROP OPERATOR IF EXISTS \"{$schema}\".{$name} (" . ($leftType ?: 'NONE') . ", " . ($rightType ?: 'NONE') . ") CASCADE;\n");
            }

            $this->write("CREATE OPERATOR \"{$schema}\".{$name} (\n");
            $this->write("    PROCEDURE = {$rs->fields['oprcode']}");

            if ($leftType !== null) {
                $this->write(",\n    LEFTARG = {$leftType}");
            }
            if ($rightType !== null) {
                $this->write(",\n    RIGHTARG = {$rightType}");
            }
            if (!empty($oprcom)) {
                $this->write(",\n    COMMUTATOR = {$oprcom}");
            }
            if (!empty($oprnegate)) {
                $this->write(",\n    NEGATOR = {$oprnegate}");
            }
            if (!empty($oprrest) && $oprrest !== '-' && $oprrest !== '0') {
                $this->write(",\n    RESTRICT = {$oprrest}");
            }
            if (!empty($oprjoin) && $oprjoin !== '-' && $oprjoin !== '0') {
                $this->write(",\n    JOIN = {$oprjoin}");
            }
            if ($oprcanhash === 't') {
                $this->write(",\n    HASHES");
            }
            if ($oprcanmerge === 't') {
                $this->write(",\n    MERGES");
            }

            $this->write("\n);\n");

            if ($this->shouldIncludeComments($options) && $oprcomment !== null) {
                $this->connection->clean($oprcomment);
                $this->write("COMMENT ON OPERATOR \"{$schema}\".{$name} (" . ($leftType ?: 'NONE') . ", " . ($rightType ?: 'NONE') . ") IS '{$oprcomment}';\n");
            }
        }
    }
}
