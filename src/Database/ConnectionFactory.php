<?php

namespace Miida\Database;

use PDO;
use PDOException;
use Exception;

class ConnectionFactory
{
    /**
     * O Multiton armazena instâncias indexadas por uma chave única estendida.
     * Exemplo de índice: "sqlserver:host_ip:Dados_hp"
     */
    private static array $legadoInstances = [];
    private static array $modernoInstances = [];

    /**
     * Instancia e retorna uma conexão PDO baseada dinamicamente nas propriedades do Servidor/Nó
     */
    private static function createConnection(array $nodeConfig, bool $isModerno): PDO
    {
        // Captura o SGBD direto do nó do Servidor no JSON (Default: sqlserver se não informado)
        $sgbdType = strtolower($nodeConfig['sgbd'] ?? 'sqlserver');
        
        $host   = $nodeConfig['host'];
        $user   = $nodeConfig['usuario'];
        $pass   = $nodeConfig['senha'] ?? '';
        $driver = $nodeConfig['driver'] ?? null;

        try {
            switch ($sgbdType) {
                case 'sqlserver':
                    // Se o driver no .env/JSON for nulo, deduz dinamicamente com base nas extensões do PHP
                    $driver = $driver ?? (extension_loaded('pdo_sqlsrv') ? 'pdo_sqlsrv' : 'pdo_dblib');
                    $porta  = $nodeConfig['porta'] ?? 1433;

                    if ($driver === 'pdo_sqlsrv' || $driver === 'sqlsrv') {
                        $dsn = "sqlsrv:Server={$host},{$porta}";
                        // SQL Server 2022 frequentemente exige TrustServerCertificate em Docker
                        $dsn .= $isModerno ? ";TrustServerCertificate=true" : ";Encrypt=false";
                    } else { 
                        // Fallback pdo_dblib (FreeTDS para Linux/Docker)
                        $dsn = "dblib:host={$host}:{$porta};version=7.0;charset=UTF-8";
                    }
                    break;

                case 'mysql':
                    $porta = $nodeConfig['porta'] ?? 3306;
                    $dsn = "mysql:host={$host};port={$porta};charset=utf8mb4";
                    break;

                case 'postgre':
                case 'postgres':
                case 'postgresql':
                    $porta = $nodeConfig['porta'] ?? 5432;
                    $dsn = "pgsql:host={$host};port={$porta}";
                    break;

                default:
                    throw new Exception("O SGBD do servidor especificado '{$sgbdType}' não é suportado pelo motor MIIDA.");
            }

            // Configurações universais de resiliência para Daemons/Workers CLI de longa execução
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT            => 5, 
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Desativa emulação para herdar tipos nativos do MySQL/Postgres (evita converter int para string)
                PDO::ATTR_EMULATE_PREPARES   => false 
            ];

            return new PDO($dsn, $user, $pass, $options);

        } catch (PDOException $e) {
            $contexto = $isModerno ? "Moderno ({$sgbdType})" : "Legado ({$sgbdType})";
            throw new Exception("Falha na Camada de Persistência MIIDA ao conectar no servidor {$contexto} [Host: {$host}]: " . $e->getMessage());
        }
    }

    /**
     * Garante e recupera a conexão correta do servidor Legado (Origem)
     */
    public static function getLegadoConnection(array $infraConfig): PDO
    {
        $nodeConfig = $infraConfig['origem_command'];
        $sgbd  = $nodeConfig['sgbd'] ?? 'sqlserver';
        $host  = $nodeConfig['host'];
        
        // A chave única do Multiton agora se baseia no servidor físico
        $chave = "{$sgbd}:{$host}";

        if (!isset(self::$legadoInstances[$chave])) {
            self::$legadoInstances[$chave] = self::createConnection($nodeConfig, false);
        }
        return self::$legadoInstances[$chave];
    }

    /**
     * Garante e recupera a conexão correta do servidor Moderno (Destino)
     */
    public static function getModernoConnection(array $infraConfig): PDO
    {
        $nodeConfig = $infraConfig['destino_query'];
        $sgbd  = $nodeConfig['sgbd'] ?? 'sqlserver';
        $host  = $nodeConfig['host'];
        
        $chave = "{$sgbd}:{$host}";

        if (!isset(self::$modernoInstances[$chave])) {
            self::$modernoInstances[$chave] = self::createConnection($nodeConfig, true);
        }
        return self::$modernoInstances[$chave];
    }

    /**
     * Libera explicitamente a memória e fecha todos os sockets abertos com os SGBDs
     */
    public static function killConnections(): void
    {
        self::$legadoInstances = [];
        self::$modernoInstances = [];
    }
}