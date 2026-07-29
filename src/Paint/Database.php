<?php

declare(strict_types=1);

namespace App\Paint;

use PDO;

/**
 * Opens Paint's database connection and verifies required document tables.
 */
final class Database
{
    private const REQUIRED_TABLES = [
        'paint_documents',
    ];

    /** @param array<string, mixed> $config */
    public static function pdo(array $config): PDO
    {
        /** @var array{host:string,port:int,name:string,username:string,password:string,charset:string} $database */
        $database = $config['database'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $database['host'],
            $database['port'],
            $database['name'],
            $database['charset']
        );

        return new PDO($dsn, $database['username'], $database['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public static function schemaReady(PDO $pdo): bool
    {
        foreach (self::REQUIRED_TABLES as $table) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = :table'
            );
            $stmt->execute(['table' => $table]);
            if ((int) $stmt->fetchColumn() !== 1) {
                return false;
            }
        }

        return true;
    }
}
