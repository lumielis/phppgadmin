<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Actions\TypeActions;

/**
 * Orchestrator dumper for a PostgreSQL schema.
 */
class SchemaDumper extends ExportDumper
{
    private $selectedObjects = [];
    private $hasObjectSelection = false;

    public function dump($subject, array $params, array $options = [])
    {
        $schema = $params['schema'] ?? $this->connection->_schema;
        if (!$schema) {
            return;
        }

        // Get list of selected objects (tables/views/sequences)
        $this->selectedObjects = $options['objects'] ?? [];
        $this->hasObjectSelection = isset($options['objects']);
        $this->selectedObjects = array_combine($this->selectedObjects, $this->selectedObjects);
        $includeSchemaObjects = $options['include_schema_objects'] ?? true;

        // Save and set schema context for Actions that depend on it
        $oldSchema = $this->connection->_schema;
        $this->connection->_schema = $schema;

        $c_schema = $schema;
        $this->connection->clean($c_schema);

        // Write standard dump header for schema exports
        $this->writeHeader("Schema: " . addslashes($c_schema));

        // Optional schema creation for super users
        if (!empty($options['add_create_schema'])) {
            $this->writeDrop('SCHEMA', $c_schema, $options);
            $this->write("CREATE SCHEMA " . $this->getIfNotExists($options) . "\"" . addslashes($c_schema) . "\";\n");
        }

        // Always set the search_path so subsequent object DDL applies to the intended schema
        $this->write("SET search_path = \"" . addslashes($c_schema) . "\", pg_catalog;\n\n");

        // 1. Types & Domains
        if ($includeSchemaObjects) {
            $this->dumpTypes($schema, $options);
        }

        // 2. Sequences
        $this->dumpSequences($schema, $options);

        // 3. Tables
        $this->dumpTables($schema, $options);

        // 4. Views
        $this->dumpViews($schema, $options);

        // 5. Functions
        if ($includeSchemaObjects) {
            $this->dumpFunctions($schema, $options);
        }

        // 6. Aggregates, Operators, etc.
        if ($includeSchemaObjects) {
            $this->dumpOtherObjects($schema, $options);
        }

        $this->writePrivileges($schema, 'schema');

        // Restore original schema context
        $this->connection->_schema = $oldSchema;
    }

    protected function dumpTypes($schema, $options)
    {
        $this->connection->clean($schema);

        // 1. Get types
        $types = $this->connection->selectSet(
            "SELECT t.oid, t.typname, t.typtype, t.typnamespace, t.typbasetype
                FROM pg_type t
                JOIN pg_namespace n ON n.oid = t.typnamespace
                WHERE n.nspname = '{$schema}'
                AND t.typtype IN ('b','c','d','e')
                AND t.typelem = 0 -- Exclude array types
                AND t.typrelid = 0 -- Exclude types that are tied to a table
                ORDER BY t.oid"
        );

        // 2. Get dependencies
        $deps = $this->connection->selectSet(
            "SELECT d.objid AS type_oid, d.refobjid AS depends_on_oid
                FROM pg_depend d
                JOIN pg_type t ON t.oid = d.objid
                WHERE d.classid = 'pg_type'::regclass
                AND d.refclassid = 'pg_type'::regclass
                AND d.deptype IN ('n','i')"
        );

        // 3. Build arrays
        $typeList = [];
        while ($types && !$types->EOF) {
            $typeList[$types->fields['oid']] = $types->fields;
            $types->moveNext();
        }

        $depList = [];
        while ($deps && !$deps->EOF) {
            $depList[] = $deps->fields;
            $deps->moveNext();
        }

        // 4. Topologically sort
        $sortedOids = $this->sortTypesTopologically($typeList, $depList);

        // 5. Dumper
        $typeDumper = $this->createSubDumper('type');
        $domainDumper = $this->createSubDumper('domain');

        foreach ($sortedOids as $oid) {
            $t = $typeList[$oid];

            if ($t['typtype'] === 'd') {
                $domainDumper->dump('domain', [
                    'schema' => $schema,
                    'domain' => $t['typname'],
                ], $options);
            } else {
                $typeDumper->dump('type', [
                    'schema' => $schema,
                    'type' => $t['typname'],
                ], $options);
            }
        }
    }

    protected function sortTypesTopologically(array $types, array $deps)
    {
        // Build graph
        $graph = [];
        $incoming = [];

        foreach ($types as $t) {
            $oid = $t['oid'];
            $graph[$oid] = [];
            $incoming[$oid] = 0;
        }

        foreach ($deps as $d) {
            $from = $d['type_oid'];
            $to = $d['depends_on_oid'];

            if (isset($graph[$from]) && isset($graph[$to])) {
                $graph[$from][] = $to;
                $incoming[$to]++;
            }
        }

        // Nodes without incoming edges
        $queue = [];
        foreach ($incoming as $oid => $count) {
            if ($count === 0) {
                $queue[] = $oid;
            }
        }

        $sorted = [];

        while (!empty($queue)) {
            $oid = array_shift($queue);
            $sorted[] = $oid;

            foreach ($graph[$oid] as $dep) {
                $incoming[$dep]--;
                if ($incoming[$dep] === 0) {
                    $queue[] = $dep;
                }
            }
        }

        return $sorted;
    }

    protected function dumpRelkindObjects($schema, $options, $relkind, $typeName)
    {
        $c_schema = $schema;
        $this->connection->clean($c_schema);

        $sql = "SELECT c.relname
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relkind = '{$relkind}'
                AND n.nspname = '{$c_schema}'
                ORDER BY c.relname";

        $result = $this->connection->selectSet($sql);
        $dumper = $this->createSubDumper($typeName);

        while ($result && !$result->EOF) {
            $name = $result->fields['relname'];

            if (!$this->hasObjectSelection || isset($this->selectedObjects[$name])) {
                $dumper->dump($typeName, [
                    $typeName => $name,
                    'schema' => $schema
                ], $options);
            }

            $result->moveNext();
        }
    }

    protected function dumpSequences($schema, $options)
    {
        $this->dumpRelkindObjects($schema, $options, 'S', 'sequence');
    }

    protected function dumpTables($schema, $options)
    {
        $this->dumpRelkindObjects($schema, $options, 'r', 'table');
    }

    protected function dumpViews($schema, $options)
    {
        $this->dumpRelkindObjects($schema, $options, 'v', 'view');
    }

    /*
    protected function dumpSequences($schema, $options)
    {
        $c_schema = $schema;
        $this->connection->clean($c_schema);

        $sql = "SELECT c.relname AS seqname
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relkind = 'S'
                AND n.nspname = '{$c_schema}'
                ORDER BY c.relname";

        $sequences = $this->connection->selectSet($sql);
        $dumper = $this->createSubDumper('sequence');

        while ($sequences && !$sequences->EOF) {
            $seqName = $sequences->fields['seqname'];
            if (!$this->hasObjectSelection || isset($this->selectedObjects[$seqName])) {
                $dumper->dump('sequence', ['sequence' => $seqName, 'schema' => $schema], $options);
            }
            $sequences->moveNext();
        }
    }

    protected function dumpTables($schema, $options)
    {
        $c_schema = $schema;
        $this->connection->clean($c_schema);

        $sql = "SELECT c.relname
                FROM pg_catalog.pg_class c
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relkind = 'r'
                AND n.nspname = '{$c_schema}'
                ORDER BY c.relname";

        $tables = $this->connection->selectSet($sql);
        $dumper = $this->createSubDumper('table');

        while ($tables && !$tables->EOF) {
            $tableName = $tables->fields['relname'];
            if (!$this->hasObjectSelection || isset($this->selectedObjects[$tableName])) {
                $dumper->dump('table', ['table' => $tableName, 'schema' => $schema], $options);
            }
            $tables->moveNext();
        }
    }

    protected function dumpViews($schema, $options)
    {
        $c_schema = $schema;
        $this->connection->clean($c_schema);

        $sql = "SELECT c.relname
                FROM pg_catalog.pg_class c
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relkind = 'v'
                AND n.nspname = '{$c_schema}'
                ORDER BY c.relname";

        $views = $this->connection->selectSet($sql);
        $dumper = $this->createSubDumper('view');

        while ($views && !$views->EOF) {
            $viewName = $views->fields['relname'];
            if (!$this->hasObjectSelection || isset($this->selectedObjects[$viewName])) {
                $dumper->dump('view', ['view' => $viewName, 'schema' => $schema], $options);
            }
            $views->moveNext();
        }
    }
    */

    protected function dumpFunctions($schema, $options)
    {
        $c_schema = $schema;
        $this->connection->clean($c_schema);

        $sql = "SELECT p.oid AS prooid
                FROM pg_catalog.pg_proc p
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname = '{$c_schema}'
                AND p.prokind = 'f'
                ORDER BY p.proname";

        $functions = $this->connection->selectSet($sql);
        $dumper = $this->createSubDumper('function');

        while ($functions && !$functions->EOF) {
            $dumper->dump('function', ['function_oid' => $functions->fields['prooid'], 'schema' => $schema], $options);
            $functions->moveNext();
        }
    }

    protected function dumpOtherObjects($schema, $options)
    {
        $c_schema = $schema;
        $this->connection->clean($c_schema);

        // Aggregates
        $sql = "SELECT p.proname, pg_catalog.pg_get_function_arguments(p.oid) AS proargtypes
                FROM pg_catalog.pg_proc p
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname = '{$c_schema}'
                AND p.prokind = 'a'
                ORDER BY p.proname";

        $aggregates = $this->connection->selectSet($sql);
        $aggDumper = $this->createSubDumper('aggregate');

        while ($aggregates && !$aggregates->EOF) {
            $aggDumper->dump('aggregate', [
                'aggregate' => $aggregates->fields['proname'],
                'basetype' => $aggregates->fields['proargtypes'],
                'schema' => $schema
            ], $options);
            $aggregates->moveNext();
        }

        // Operators
        $sql = "SELECT o.oid
                FROM pg_catalog.pg_operator o
                LEFT JOIN pg_catalog.pg_namespace n ON n.oid = o.oprnamespace
                WHERE n.nspname = '{$c_schema}'
                ORDER BY o.oid";

        $operators = $this->connection->selectSet($sql);
        $opDumper = $this->createSubDumper('operator');

        while ($operators && !$operators->EOF) {
            $opDumper->dump('operator', [
                'operator_oid' => $operators->fields['oid'],
                'schema' => $schema
            ], $options);
            $operators->moveNext();
        }
    }
}
