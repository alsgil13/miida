<?php

namespace Miida\Database;

use PDO;
use Exception;
use Miida\Database\Syntax\SgbdSyntaxInterface;

class ControlRepository
{
    private PDO $connModerno;
    private SgbdSyntaxInterface $syntax;
    private string $tabelaControle;

    /**
     * O repositório agora recebe a conexão e a estratégia de sintaxe do SGBD destino
     */
    public function __construct(PDO $connModerno, SgbdSyntaxInterface $syntax)
    {
        $this->connModerno = $connModerno;
        $this->syntax = $syntax;
        
        // Monta o nome qualificado da tabela de controle usando o padrão do SGBD ativo
        // Criará [master].[dbo].[miida_controle_sincronizacao] no SQLServer ou "public"."miida_controle_sincronizacao" no Postgres
        $this->tabelaControle = $this->syntax->obterNomeQualificado('master', 'dbo', 'miida_controle_sincronizacao');
    }

    /**
     * Recupera a data/hora da última sincronização bem-sucedida de uma tabela específica.
     */
    public function obterUltimaDataSincronizacao(string $banco, string $tabela): string
    {
        // Query compatível com padrão ANSI SQL (Funciona em todos os SGBDs homologados)
        $sql = "SELECT ultima_sincronizacao 
                FROM {$this->tabelaControle} 
                WHERE banco_nome = :banco AND tabela_nome = :tabela AND status_execucao = 'SUCESSO'";

        try {
            $stmt = $this->connModerno->prepare($sql);
            $stmt->execute([
                ':banco'   => $banco,
                ':tabela'  => $tabela
            ]);

            $resultado = $stmt->fetch();

            if ($resultado && isset($resultado['ultima_sincronizacao'])) {
                return $resultado['ultima_sincronizacao'];
            }

            return '1970-01-01 00:00:00';

        } catch (Exception $e) {
            throw new Exception("Erro ao ler metadados temporais no ControlRepository: " . $e->getMessage());
        }
    }

    /**
     * Atualiza ou insere o estado atual de sincronização de uma tabela delegando ao Strategy
     */
    public function atualizarEstadoSincronizacao(
        string $banco, 
        string $tabela, 
        string $status, 
        int $registrosAfetados, 
        string $timestampCiclo
    ): void {
        
        // Colunas envolvidas no processo de log de controle
        $colunas = ['banco_nome', 'tabela_nome', 'ultima_sincronizacao', 'status_execucao', 'registros_afetados'];
        
        // O STRATEGY EM AÇÃO: Delega a montagem do comando de INSERT/MERGE/UPSERT correto para o SGBD alvo
        $sql = $this->syntax->obterSqlUpsert($this->tabelaControle, $colunas);

        try {
            $stmt = $this->connModerno->prepare($sql);
            $stmt->execute([
                ':banco_nome'          => $banco,
                ':tabela_nome'         => $tabela,
                ':ultima_sincronizacao'=> $timestampCiclo,
                ':status_execucao'     => $status,
                ':registros_afetados'  => $registrosAfetados
            ]);
        } catch (Exception $e) {
            throw new Exception("Erro ao persistir metadados temporais via Strategy no ControlRepository: " . $e->getMessage());
        }
    }
}