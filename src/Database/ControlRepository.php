<?php

namespace Miida\Database;

use PDO;
use Exception;
use Miida\Database\Syntax\SgbdSyntaxInterface;

/**
 * MIIDA - ControlRepository
 * Guardião relacional do estado temporal das tabelas e cursores de sincronização
 * Implementação purificada e agnóstica (Multi-SGBD)
 */
class ControlRepository
{
    private PDO $conexao;
    private SgbdSyntaxInterface $syntax;
    private string $tabelaControleQualificada;

    public function __construct(PDO $conexao, SgbdSyntaxInterface $syntax)
    {
        $this->conexao = $conexao;
        $this->syntax = $syntax;
        
        $this->tabelaControleQualificada = $this->syntax->obterNomeQualificadoTabelaControle();
    }

    /**
     * Recupera a última data em que uma tabela específica foi sincronizada com sucesso
     */
    public function obterUltimaSincronizacao(string $banco, string $tabela): string
    {
        $sql = "SELECT ultima_sincronizacao FROM {$this->tabelaControleQualificada} 
                WHERE banco_nome = :banco AND tabela_nome = :tabela";
                
        $stmt = $this->conexao->prepare($sql);
        $stmt->execute([
            ':banco' => $banco,
            ':tabela' => $tabela
        ]);
        
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Se nunca foi sincronizada, retorna uma data base antiga para forçar a carga inicial total
        return $resultado['ultima_sincronizacao'] ?? '1970-01-01 00:00:00';
    }

    /**
     * Método Auxiliar (Alias/Apelido) para manter compatibilidade com o DataSyncProcessor
     */
    public function obterUltimaDataSincronizacao(string $banco, string $tabela): string
    {
        return $this->obterUltimaSincronizacao($banco, $tabela);
    }

    /**
     * Atualiza o estado temporal, status e volumetria de sincronização de uma tabela
     */
    public function atualizarEstadoSincronizacao(
        string $banco, 
        string $tabela, 
        string $status, 
        int $registrosAfetados, 
        string $dataSincronizacao
    ): void {
        try {
            // Estratégia Agnóstica de Upsert Manual livre de erros de parâmetros:
            // Passo 1: Tenta realizar um UPDATE na chave composta
            $sqlUpdate = "UPDATE {$this->tabelaControleQualificada} 
                          SET ultima_sincronizacao = :ultima_sincronizacao, 
                              status_execucao = :status_execucao, 
                              registros_afetados = :registros_afetados 
                          WHERE banco_nome = :banco_nome AND tabela_nome = :tabela_nome";

            $stmtUpdate = $this->conexao->prepare($sqlUpdate);
            $stmtUpdate->execute([
                ':banco_nome' => $banco,
                ':tabela_nome' => $tabela,
                ':ultima_sincronizacao' => $dataSincronizacao,
                ':status_execucao' => $status,
                ':registros_afetados' => $registrosAfetados
            ]);

            // Passo 2: Se nenhuma linha foi alterada (registro novo), executa o INSERT nativo
            if ($stmtUpdate->rowCount() === 0) {
                $sqlInsert = "INSERT INTO {$this->tabelaControleQualificada} 
                              (banco_nome, tabela_nome, ultima_sincronizacao, status_execucao, registros_afetados) \r
                              VALUES (:banco_nome, :tabela_nome, :ultima_sincronizacao, :status_execucao, :registros_afetados)";

                $stmtInsert = $this->conexao->prepare($sqlInsert);
                $stmtInsert->execute([
                    ':banco_nome' => $banco,
                    ':tabela_nome' => $tabela,
                    ':ultima_sincronizacao' => $dataSincronizacao,
                    ':status_execucao' => $status,
                    ':registros_afetados' => $registrosAfetados
                ]);
            }
            
        } catch (Exception $e) {
            throw new Exception("Falha ao registrar estado de sincronizacao no ControlRepository: " . $e->getMessage());
        }
    }
}