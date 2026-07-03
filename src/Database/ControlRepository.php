<?php

namespace Miida\Database;

class ControlRepository
{
    private \PDO $conexao;
    private $syntax;

    public function __construct(\PDO $conexao, $syntax)
    {
        $this->conexao = $conexao;
        $this->syntax = $syntax;
    }

    /**
     * Atualiza ou Insere o estado de sincronização de uma tabela
     */
    public function atualizarVersao(string $banco, string $tabela, string $status, int $afetados): void
    {
        $tabelaControle = $this->syntax->obterNomeQualificadoTabelaControle();

        // 1. Verifica se o registro de controle já existe para esta tabela específica
        $sqlCheck = "SELECT 1 FROM {$tabelaControle} WHERE banco_nome = :banco AND tabela_nome = :tabela";
        $stmtCheck = $this->conexao->prepare($sqlCheck);
        $stmtCheck->bindValue(':banco', $banco);
        $stmtCheck->bindValue(':tabela', $tabela);
        $stmtCheck->execute();
        $existe = $stmtCheck->fetchColumn();
        $stmtCheck->closeCursor();
        $stmtCheck = null;

        if ($existe) {
            // 2. Se já existe, faz o UPDATE com os tokens exatos
            $sqlUpdate = "UPDATE {$tabelaControle} 
                          SET ultima_sincronizacao = CURRENT_TIMESTAMP, 
                              status_execucao = :status, 
                              registros_afetados = :afetados 
                          WHERE banco_nome = :banco AND tabela_nome = :tabela";
            
            $stmt = $this->conexao->prepare($sqlUpdate);
        } else {
            // 3. Se não existe, faz o INSERT inicial
            $sqlInsert = "INSERT INTO {$tabelaControle} 
                          (banco_nome, tabela_nome, ultima_sincronizacao, status_execucao, registros_afetados) 
                          VALUES (:banco, :tabela, CURRENT_TIMESTAMP, :status, :afetados)";
            
            $stmt = $this->conexao->prepare($sqlInsert);
        }

        // Bind manual e seguro de todos os tokens para evitar o erro HY093 no dblib
        $stmt->bindValue(':banco', $banco);
        $stmt->bindValue(':tabela', $tabela);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':afetados', $afetados, \PDO::PARAM_INT);

        $stmt->execute();
        $stmt->closeCursor();
        $stmt = null;
    }
}