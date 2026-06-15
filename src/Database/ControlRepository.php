<?php

namespace Miida\Database;

use PDO;
use Exception;

class ControlRepository
{
    private PDO $connModerno;
    private string $tabelaControle = '[master].[dbo].[miida_controle_sincronizacao]';

    /**
     * O repositório opera exclusivamente injetando a conexão do nó de leitura (SQL 2022)
     */
    public function __construct(PDO $connModerno)
    {
        $this->connModerno = $connModerno;
    }

    /**
     * Recupera a data/hora da última sincronização bem-sucedida de uma tabela específica.
     * Se nunca tiver sido sincronizada, retorna uma data base antiga para forçar a carga inicial.
     */
    public function obterUltimaDataSincronizacao(string $banco, string $tabela): string
    {
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

            // Data de fallback padrão para tabelas que nunca rodaram (Força Carga Total Inicial)
            return '1970-01-01 00:00:00';

        } catch (Exception $e) {
            throw new Exception("Erro ao ler metadados temporais no ControlRepository: " . $e->getMessage());
        }
    }

    /**
     * Atualiza ou insere o estado atual de sincronização de uma tabela
     */
    public function atualizarEstadoSincronizacao(
        string $banco, 
        string $tabela, 
        string $status, 
        int $registrosAfetados, 
        string $timestampCiclo
    ): void {
        // Comando UPSERT (UPDATE ou INSERT) nativo do SQL Server usando MERGE
        $sql = "MERGE {$this->tabelaControle} AS t
                USING (SELECT :banco AS banco, :tabela AS tabela) AS s
                ON (t.banco_nome = s.banco AND t.tabela_nome = s.tabela)
                WHEN MATCHED THEN
                    UPDATE SET ultima_sincronizacao = :timestamp,
                               status_execucao = :status,
                               registros_afetados = :registros
                WHEN NOT MATCHED THEN
                    INSERT (banco_nome, tabela_nome, ultima_sincronizacao, status_execucao, registros_afetados)
                    VALUES (s.banco, s.tabela, :timestamp, :status, :registros);";

        try {
            $stmt = $this->connModerno->prepare($sql);
            $stmt->execute([
                ':banco'     => $banco,
                ':tabela'    => $tabela,
                ':timestamp' => $timestampCiclo,
                ':status'    => $status,
                ':registros' => $registrosAfetados
            ]);
        } catch (Exception $e) {
            throw new Exception("Erro ao persistir metadados temporais no ControlRepository: " . $e->getMessage());
        }
    }
}