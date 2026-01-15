<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\SequenceActions;

/**
 * Dumper for PostgreSQL sequences.
 */
class SequenceDumper extends ExportDumper
{
    public function dump($subject, array $params, array $options = [])
    {
        $sequence = $params['sequence'] ?? null;
        $schema = $params['schema'] ?? $this->connection->_schema;

        if (!$sequence) {
            return;
        }


        $sequenceActions = new SequenceActions($this->connection);
        $rs = $sequenceActions->getSequence($sequence);

        if ($rs && !$rs->EOF) {
            $this->write("\n-- Sequence: \"{$schema}\".\"{$sequence}\"\n");
            $this->writeDrop('SEQUENCE', "{$schema}\".\"{$sequence}", $options);

            $ifNotExists = $this->getIfNotExists($options);
            $this->write("CREATE SEQUENCE {$ifNotExists}\"{$schema}\".\"{$sequence}\"\n");
            $this->write("    START WITH {$rs->fields['start_value']}\n");
            $this->write("    INCREMENT BY {$rs->fields['increment_by']}\n");
            $this->write("    MINVALUE {$rs->fields['min_value']}\n");
            $this->write("    MAXVALUE {$rs->fields['max_value']}\n");
            $this->write("    CACHE {$rs->fields['cache_value']}");
            if ($this->connection->phpBool($rs->fields['is_cycled'])) {
                $this->write("\n    CYCLE");
            }
            $this->write(";\n");

            // Set the current value
            if (!empty($rs->fields['last_value'])) {
                $c_schema = $schema;
                $c_sequence = $sequence;
                $this->connection->clean($c_schema);
                $this->connection->clean($c_sequence);
                $this->write("SELECT pg_catalog.setval('\"" . addslashes($c_schema) . "\".\"" . addslashes($c_sequence) . "\"', {$rs->fields['last_value']}, " . ($this->connection->phpBool($rs->fields['is_called']) ? 'true' : 'false') . ");\n");
            }

            // Add comment if present
            $c_schema = $schema;
            $c_sequence = $sequence;
            $this->connection->clean($c_schema);
            $this->connection->clean($c_sequence);
            // Add comment if present and requested
            if ($this->shouldIncludeComments($options)) {
                $commentSql = "SELECT pg_catalog.obj_description(s.oid, 'pg_class') AS comment FROM pg_catalog.pg_class s JOIN pg_catalog.pg_namespace n ON n.oid = s.relnamespace WHERE s.relname = '{$c_sequence}' AND n.nspname = '{$c_schema}' AND s.relkind = 'S'";
                $commentRs = $this->connection->selectSet($commentSql);
                if ($commentRs && !$commentRs->EOF && !empty($commentRs->fields['comment'])) {
                    $this->connection->clean($commentRs->fields['comment']);
                    $this->write("COMMENT ON SEQUENCE \"" . addslashes($c_schema) . "\".\"" . addslashes($c_sequence) . "\" IS '{$commentRs->fields['comment']}';\\n");
                }
            }

            $this->writePrivileges($sequence, 'sequence', $schema);
        }
    }
}
