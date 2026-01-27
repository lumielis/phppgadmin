<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Database\Postgres;

/**
 * Factory for creating dumper instances.
 */
class DumpFactory
{
    /**
     * Creates a dumper for the specified subject.
     * 
     * @param string $subject
     * @param Postgres $connection
     * @return ExportDumper
     * @throws \Exception If subject is not supported
     */
    public static function create($subject, Postgres $connection): ExportDumper
    {
        $className = __NAMESPACE__ . '\\' . ucfirst(strtolower($subject)) . 'Dumper';

        if (class_exists($className)) {
            return new $className($connection);
        }

        // Fallback for subjects that might have different naming or are handled by orchestrators
        switch (strtolower($subject)) {
            case 'server':
                return new ServerDumper($connection);
            case 'database':
                return new DatabaseDumper($connection);
            case 'schema':
                return new SchemaDumper($connection);
            case 'table':
                return new TableDumper($connection);
            case 'view':
                return new ViewDumper($connection);
            case 'function':
                return new FunctionDumper($connection);
            case 'sequence':
                return new SequenceDumper($connection);
            case 'role':
                return new RoleDumper($connection);
            case 'tablespace':
                return new TablespaceDumper($connection);
            case 'type':
                return new TypeDumper($connection);
            case 'domain':
                return new DomainDumper($connection);
            case 'aggregate':
                return new AggregateDumper($connection);
            case 'operator':
                return new OperatorDumper($connection);
            case 'rule':
                return new RuleDumper($connection);
            case 'trigger':
                return new TriggerDumper($connection);
            default:
                throw new \Exception("Unsupported dump subject: {$subject}");
        }
    }
}
