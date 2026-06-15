<?php

namespace Miida\Database;

use PDO;
use PDOException;
use Exception;

class ConnectionFactory
{
    private static ?PDO $legadoInstance = null;
    private static ?PDO $modernoInstance = null;

    /**
     * Instancia ou retorna a conexão PDO adequada para o nó solicitado
     */
    private static function createConnection(array $nodeConfig, string $bancoNome, bool $isModerno): PDO
    {
        $host   = $nodeConfig['host'];
        $port   = $nodeConfig['porta'] ?? 1433;
        $user   = $nodeConfig['usuario'];
        $pass   = $nodeConfig['senha'] ?? '';
        $driver = $nodeConfig['driver'] ?? 'pdo_sqlsrv'; // Fallback padrão

        try {
            if ($driver === 'pdo_sqlsrv' || $driver === 'sqlsrv') {
                // Monta o DSN no formato do driver oficial Microsoft SQLSRV
                $dsn = "sqlsrv:Server={$host},{$port};Database={$bancoNome}";
                
                if ($isModerno) {
                    // SQL Server 2022 em container frequentemente exige TrustServerCertificate
                    $dsn .= ";TrustServerCertificate=true";
                } else {
                    // SQL Server 2005 legado pode falhar se tentar forçar criptografia moderna
                    $dsn .= ";Encrypt=false";
                }
                
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 3,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ];

                return new PDO($dsn, $user, $pass, $options);

            } elseif ($driver === 'pdo_dblib' || $driver === 'dblib') {
                // Formato alternativo de DSN usando dblib (FreeTDS para ambientes Linux/Docker)
                $dsn = "dblib:host={$host}:{$port};dbname={$bancoNome};version=7.0;charset=UTF-8";
                
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 3,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ];

                return new PDO($dsn, $user, $pass, $options);
            } else {
                throw new Exception("O driver de conexao especificado '{$driver}' nao e suportado pelo MIIDA.");
            }

        } catch (PDOException $e) {
            $contexto = $isModerno ? "Moderno (SQL 2022)" : "Legado (SQL 2005)";
            throw new Exception("Falha na Camada de Persistencia MIIDA ao conectar no no {$contexto}: " . $e->getMessage());
        }
    }

    public static function getLegadoConnection(array $infraConfig, string $bancoNome): PDO
    {
        if (self::$legadoInstance === null) {
            self::$legadoInstance = self::createConnection($infraConfig['origem_command'], $bancoNome, false);
        }
        return self::$legadoInstance;
    }

    public static function getModernoConnection(array $infraConfig, string $bancoNome): PDO
    {
        if (self::$modernoInstance === null) {
            self::$modernoInstance = self::createConnection($infraConfig['destino_query'], $bancoNome, true);
        }
        return self::$modernoInstance;
    }

    public static function killConnections(): void
    {
        self::$legadoInstance = null;
        self::$modernoInstance = null;
    }
}