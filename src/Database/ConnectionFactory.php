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
     * Estabelece conexão com o nó Legado (Origem)
     */
/**
     * Estabelece conexão com o nó Legado (Origem) com Fallback Inteligente de Banco
     */
    public static function getLegadoConnection(array $infraConfig, SgbdSyntaxInterface $syntax, ?string $banco = null): PDO
    {
        // Se foi passado o array completo do JSON em vez de apenas o nó de infra, resolve adequadamente
        $nodeConfig = $infraConfig['origem_command'] ?? $infraConfig['configuracao_infraestrutura']['origem_command'] ?? $infraConfig;
        $host = $nodeConfig['host'] ?? 'localhost';
        
        // CORREÇÃO: Fallback dinâmico para buscar o banco de dados legados se não estiver explícito no nó
        $dbNome = $banco;
        if (empty($dbNome)) {
            $dbNome = $nodeConfig['banco'] ?? $nodeConfig['banco_legado'] ?? '';
        }
        
        // Se ainda estiver vazio, inspeciona o escopo global para capturar o banco mapeado nos gerenciados
        if (empty($dbNome) && isset($infraConfig['bancos_gerenciados'][0]['banco_legado'])) {
            $dbNome = $infraConfig['bancos_gerenciados'][0]['banco_legado'];
        }

        $chave = get_class($syntax) . ":{$host}:{$dbNome}";

        if (!isset(self::$legadoInstances[$chave])) {
            $config = $nodeConfig;
            $config['banco'] = $dbNome;
            self::$legadoInstances[$chave] = self::createConnection($config, $syntax);
        }
        return self::$legadoInstances[$chave];
    }

    /**
     * Estabelece conexão com o nó Moderno (Destino) com Auto-Criação de Banco de Dados
     */
    public static function getModernoConnection(array $infraConfig, SgbdSyntaxInterface $syntax, ?string $bancoAlvo = null): PDO
    {
        $nodeConfig = $infraConfig['destino_query'];
        $host = $nodeConfig['host'];
        
        if ($bancoAlvo !== null) {
            self::garantirExistenciaBanco($nodeConfig, $syntax, $bancoAlvo);
        }

        $dbNome = $bancoAlvo ?? $nodeConfig['banco'] ?? $syntax->obterBancoAdministrativo();
        $chave = get_class($syntax) . ":{$host}:{$dbNome}";

        if (!isset(self::$modernoInstances[$chave])) {
            $configTemporaria = $nodeConfig;
            $configTemporaria['banco'] = $dbNome;
            self::$modernoInstances[$chave] = self::createConnection($configTemporaria, $syntax);
        }

        return self::$modernoInstances[$chave];
    }

    /**
     * Garante a existência física do Banco delegando as regras à Strategy ativa
     */
    private static function garantirExistenciaBanco(array $nodeConfig, SgbdSyntaxInterface $syntax, string $bancoAlvo): void
    {
        $configBase = $nodeConfig;
        $configBase['banco'] = $syntax->obterBancoAdministrativo();

        try {
            $conexaoAdmin = self::createConnection($configBase, $syntax);
            $ddlConfig = $syntax->obterDdlGarantirBanco($bancoAlvo);
            
            $precisaCriar = true;

            // Se a estratégia exigir uma checagem prévia em tabelas de catálogo do sistema
            if (!empty($ddlConfig['checagem'])) {
                $stmt = $conexaoAdmin->query($ddlConfig['checagem']);
                if ($stmt->fetch()) {
                    $precisaCriar = false;
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
        $host = $node['host'];
        $port = (int)$node['porta'];
        $user = $node['usuario'] ?? $node['user'] ?? '';
        $pass = $node['senha'] ?? $node['password'] ?? $node['pass'] ?? '';
        $db   = $node['banco'] ?? '';

        // Montagem 100% dinâmica delegada à Strategy
        $dsn = $syntax->obterDsn($host, $port, $db);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ];

        return new PDO($dsn, $user, $pass, $options);
    }
}