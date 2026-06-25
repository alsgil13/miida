<?php

namespace Miida\Database;

use PDO;
use PDOException;

/**
 * MIIDA - ConnectionFactory
 * Fabrica de Conexoes Centralizada com Provisionamento de Bancos de Dados
 */
class ConnectionFactory
{
    private static array $legadoInstances = [];
    private static array $modernoInstances = [];

    /**
     * Estabece conexao com o no Legado (Origem)
     */
    public static function getLegadoConnection(array $infraConfig, ?string $banco = null): PDO
    {
        $nodeConfig = $infraConfig['origem_command'];
        $sgbd = strtolower($nodeConfig['sgbd'] ?? 'mysql');
        $host = $nodeConfig['host'];
        $dbNome = $banco ?? $nodeConfig['banco'] ?? '';
        
        $chave = "{$sgbd}:{$host}:{$dbNome}";

        if (!isset(self::$legadoInstances[$chave])) {
            $config = $nodeConfig;
            if ($dbNome) {
                $config['banco'] = $dbNome;
            }
            self::$legadoInstances[$chave] = self::createConnection($config, false);
        }
        return self::$legadoInstances[$chave];
    }

    /**
     * Estabece conexao com o no Moderno (Destino) com Auto-Criacao de Banco de Dados
     */
    public static function getModernoConnection(array $infraConfig, ?string $bancoAlvo = null): PDO
    {
        $nodeConfig = $infraConfig['destino_query'];
        $sgbd  = strtolower($nodeConfig['sgbd'] ?? 'sqlserver');
        $host  = $nodeConfig['host'];
        
        // Se um banco alvo foi pedido, tentamos garantir a existencia dele primeiro
        if ($bancoAlvo !== null) {
            self::garantirExistenciaBanco($nodeConfig, $sgbd, $bancoAlvo);
        }

        $dbNome = $bancoAlvo ?? $nodeConfig['banco'] ?? 'master';
        $chave = "{$sgbd}:{$host}:{$dbNome}";

        if (!isset(self::$modernoInstances[$chave])) {
            $configTemporaria = $nodeConfig;
            $configTemporaria['banco'] = $dbNome;
            self::$modernoInstances[$chave] = self::createConnection($configTemporaria, true);
        }

        return self::$modernoInstances[$chave];
    }

    /**
     * Metodo interno isolado para forcar a criacao fisica do Banco no SGBD de destino
     */
    private static function garantirExistenciaBanco(array $nodeConfig, string $sgbd, string $bancoAlvo): void
    {
        // Conecta na base administrativa padrao do servidor que sempre existe
        $configBase = $nodeConfig;
        $configBase['banco'] = ($sgbd === 'postgres') ? 'postgres' : (($sgbd === 'mysql') ? 'mysql' : 'master');

        try {
            $conexaoAdmin = self::createConnection($configBase, true);
            
            if ($sgbd === 'sqlserver') {
                // No SQL Server, checa a sys.databases. Se nao houver, cria imediatamente.
                $stmt = $conexaoAdmin->query("SELECT database_id FROM sys.databases WHERE name = '{$bancoAlvo}'");
                if (!$stmt->fetch()) {
                    // Executa fora de transacao implicitamente para o SQL Server nao reter o comando
                    $conexaoAdmin->exec("CREATE DATABASE [{$bancoAlvo}];");
                }
            } elseif ($sgbd === 'postgres') {
                $stmt = $conexaoAdmin->query("SELECT 1 FROM pg_database WHERE datname = '{$bancoAlvo}'");
                if (!$stmt->fetch()) {
                    $conexaoAdmin->exec("CREATE DATABASE \"{$bancoAlvo}\";");
                }
            } else {
                $conexaoAdmin->exec("CREATE DATABASE IF NOT EXISTS `{$bancoAlvo}`;");
            }
            
            $conexaoAdmin = null; // Fecha a conexao administrativa para consolidar no disco
        } catch (PDOException $e) {
            // Se falhar porque nao tem permissao ou erro de rede, repassa o erro
            throw new \Exception("Erro na Camada de Persistencia ao tentar auto-criar o banco [{$bancoAlvo}]: " . $e->getMessage());
        }
    }

    /**
     * Fabrica primitiva de instanciacao do PDO baseada no driver
     */
    private static function createConnection(array $node, bool $isDestino): PDO
    {
        $sgbd = strtolower($node['sgbd'] ?? ($isDestino ? 'sqlserver' : 'mysql'));
        $host = $node['host'];
        $port = $node['porta'];
        $user = $node['usuario'] ?? $node['user'] ?? '';
        $pass = $node['senha'] ?? $node['password'] ?? $node['pass'] ?? '';
        $db   = $node['banco'] ?? '';

        if ($sgbd === 'sqlserver' || $sgbd === 'dblib') {
            // Sintaxe DSN FreeTDS / DBLIB usada no Linux para conectar ao SQL Server
            $dsn = "dblib:host={$host};port={$port}";
            if ($db) {
                $dsn .= ";dbname={$db}";
            }
        } elseif ($sgbd === 'postgres' || $sgbd === 'pgsql') {
            $dsn = "pgsql:host={$host};port={$port}";
            if ($db) {
                $dsn .= ";dbname={$db}";
            }
        } else {
            // Padrao MySQL
            $dsn = "mysql:host={$host};port={$port}";
            if ($db) {
                $dsn .= ";dbname={$db}";
            }
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ];

        return new PDO($dsn, $user, $pass, $options);
    }
}