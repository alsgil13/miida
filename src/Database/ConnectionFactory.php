<?php

namespace Miida\Database;

use PDO;
use PDOException;
use Exception;
use Miida\Database\Syntax\SgbdSyntaxInterface;

/**
 * MIIDA - ConnectionFactory
 * Fábrica de Conexões Centralizada e Agnóstica baseada no Padrão Strategy
 */
class ConnectionFactory
{
    private static array $legadoInstances = [];
    private static array $modernoInstances = [];

    /**
     * Estabelece conexão com o nó Legado (Origem) com Fallback Inteligente de Banco
     */
    public static function getLegadoConnection(array $infraConfig, SgbdSyntaxInterface $syntax, ?string $banco = null): PDO
    {
        $nodeConfig = $infraConfig['origem_command'] ?? $infraConfig['configuracao_infraestrutura']['origem_command'] ?? $infraConfig;
        $host = $nodeConfig['host'] ?? 'localhost';
        
        $dbNome = $banco;
        if (empty($dbNome)) {
            $dbNome = $nodeConfig['banco'] ?? $nodeConfig['banco_legado'] ?? '';
        }
        
        if (empty($dbNome) && isset($infraConfig['bancos_gerenciados'][0]['banco_legado'])) {
            $dbNome = $infraConfig['bancos_gerenciados'][0]['banco_legado'];
        }

        $chave = get_class($syntax) . ":{$host}:{$dbNome}";

        if (!isset(self::$legadoInstances[$chave])) {
            $config = $nodeConfig;
            $config['banco_resolvido'] = $dbNome;
            self::$legadoInstances[$chave] = self::createConnection($config, $syntax);
        }
        return self::$legadoInstances[$chave];
    }

/**
     * Estabelece conexão com o nó Moderno (Destino) com Auto-Criação de Banco de Dados
     */
    public static function getModernoConnection(array $infraConfig, SgbdSyntaxInterface $syntax, ?string $bancoAlvo = null): PDO
    {
        $nodeConfig = $infraConfig['destino_query'] ?? $infraConfig['configuracao_infraestrutura']['destino_query'] ?? $infraConfig;
        $host = $nodeConfig['host'] ?? 'localhost';
        
        // Se o inicializador ou o sincronizador não passaram o banco, pega obrigatoriamente do JSON para não cair no 'postgres'
        if (empty($bancoAlvo) && isset($infraConfig['bancos_gerenciados'][0]['banco_moderno'])) {
            $bancoAlvo = $infraConfig['bancos_gerenciados'][0]['banco_moderno'];
        }

        // Se há um banco alvo definido, garante a existência física dele primeiro
        if ($bancoAlvo !== null) {
            self::garantirExistenciaBanco($nodeConfig, $syntax, $bancoAlvo);
        }

        $dbNome = $bancoAlvo ?? $syntax->obterBancoAdministrativo();
        $chave = get_class($syntax) . ":{$host}:{$dbNome}";

        if (!isset(self::$modernoInstances[$chave])) {
            $configTemporaria = $nodeConfig;
            // Força o DSN a usar o banco correto (ex: dw_moderno_db)
            $configTemporaria['banco_resolvido'] = $dbNome;
            self::$modernoInstances[$chave] = self::createConnection($configTemporaria, $syntax);
        }

        return self::$modernoInstances[$chave];
    }

    /**
     * Garante a existência física do Banco delegando as regras à Strategy activa
     */
    private static function garantirExistenciaBanco(array $nodeConfig, SgbdSyntaxInterface $syntax, string $bancoAlvo): void
    {
        $configBase = $nodeConfig;
        $configBase['banco_resolvido'] = $syntax->obterBancoAdministrativo();

        try {
            $conexaoAdmin = self::createConnection($configBase, $syntax);
            $ddlConfig = $syntax->obterDdlGarantirBanco($bancoAlvo);
            
            $precisaCriar = true;

            if (!empty($ddlConfig['checagem'])) {
                $stmt = $conexaoAdmin->query($ddlConfig['checagem']);
                if ($stmt !== false && $stmt->fetch()) {
                    $precisaCriar = false;
                }
                if ($stmt !== false) {
                    $stmt->closeCursor();
                }
            }

            if ($precisaCriar && !empty($ddlConfig['criacao'])) {
                $conexaoAdmin->exec($ddlConfig['criacao']);
            }
            
            $conexaoAdmin = null; 
        } catch (PDOException $e) {
            throw new Exception("Erro na Camada de Persistência ao tentar auto-criar o banco [{$bancoAlvo}]: " . $e->getMessage());
        }
    }

    /**
     * Instanciação purificada do PDO através de sua Strategy dedicada
     */
    private static function createConnection(array $node, SgbdSyntaxInterface $syntax): PDO
    {
        $host = $node['host'] ?? 'localhost';
        $port = (int)($node['porta'] ?? $node['port'] ?? 5432);
        $user = $node['usuario'] ?? $node['user'] ?? '';
        $pass = $node['senha'] ?? $node['password'] ?? $node['pass'] ?? '';
        
        // CORREÇÃO ESSENCIAL: Lê estritamente a variável injetada, ignorando falhas do JSON
        $db = $node['banco_resolvido'] ?? '';

        $dsn = $syntax->obterDsn($host, $port, $db);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];

        return new PDO($dsn, $user, $pass, $options);
    }
}