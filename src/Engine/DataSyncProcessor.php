<?php

namespace Miida\Engine;

use Miida\Database\ControlRepository;
use Miida\Database\Syntax\SgbdSyntaxInterface;
use Miida\Services\AntiCorruptionLayer;

/**
 * MIIDA - DataSyncProcessor
 * Motor de Sincronizacao de Dados de Alta Performance Multi-SGBD
 */
class DataSyncProcessor
{
    private \PDO $connLegado;
    private \PDO $connModerno;
    private SgbdSyntaxInterface $syntaxLegado;
    private SgbdSyntaxInterface $syntaxModerno;
    private ControlRepository $controlRepo;

    public function __construct(
        \PDO $connLegado,
        \PDO $connModerno,
        SgbdSyntaxInterface $syntaxLegado,
        SgbdSyntaxInterface $syntaxModerno,
        ControlRepository $controlRepo
    ) {
        $this->connLegado   = $connLegado;
        $this->connModerno  = $connModerno;
        $this->syntaxLegado = $syntaxLegado;
        $this->syntaxModerno = $syntaxModerno;
        $this->controlRepo  = $controlRepo;
    }

    /**
     * Sincroniza uma tabela individual baseando-se no Manifesto JSON
     */
    public function sincronizarTabela(array $configBanco, array $configTabela): int
    {
        $nomeBancoDestino  = $configBanco['banco_moderno'];
        $schemaDestino     = $configTabela['schema_moderno'] ?? 'dbo';
        $tabelaDestino     = $configTabela['tabela_moderna'];
        $tabelaOrigem      = $configTabela['tabela_legada'];
        
        // Define o nome qualificado estrutural da tabela destino no SQL Server
        $tabelaQualificada = "[{$nomeBancoDestino}].[{$schemaDestino}].[{$tabelaDestino}]";

        // CORREÇÃO PRECISA: Acessa o mapeamento dentro do nó 'camada_anticorrupcao' igual ao seu JSON
        $mapeamento = $configTabela['camada_anticorrupcao']['mapeamento_colunas'] ?? [];

        if (empty($mapeamento)) {
            throw new \Exception("Erro de Modelagem: O bloco [mapeamento_colunas] nao foi encontrado dentro de [camada_anticorrupcao] para a tabela [{$tabelaDestino}].");
        }

        // 1. Identifica as Chaves Primarias (PKs) configuradas no mapeamento
        $pks = [];
        foreach ($mapeamento as $colOriginal => $props) {
            if (!empty($props['pk'])) {
                $pks[] = $props['nome_destino'] ?? $colOriginal;
            }
        }

        if (empty($pks)) {
            throw new \Exception("Erro de Modelagem: A tabela [{$tabelaDestino}] nao possui Chaves Primarias (pk: true) mapeadas no JSON.");
        }

        // 2. Recupera de forma blindada a data da ultima sincronizacao bem-sucedida
        $ultimaData = $this->obterDataUltimaSincronizacao($nomeBancoDestino, $tabelaDestino);

        // 3. Registra o inicio da execucao na tabela tecnica de controle
        $this->controlRepo->atualizarVersao($nomeBancoDestino, $tabelaDestino, 'PROCESSANDO', 0);

        // 4. Mapeia a coluna incremental real baseada na chave do seu JSON (coluna_timestamp_controle)
        $colunaIncremental = $configTabela['coluna_timestamp_controle'] ?? null;

        // 5. Monta a query delta de extracao incremental na Origem (MySQL)
        $sqlExtracao = "SELECT * FROM `{$tabelaOrigem}`";
        if ($ultimaData && !empty($colunaIncremental)) {
            $sqlExtracao .= " WHERE `{$colunaIncremental}` > :ultima_data";
        }

        $stmtOrigem = $this->connLegado->prepare($sqlExtracao);
        if ($ultimaData && !empty($colunaIncremental)) {
            $stmtOrigem->bindValue(':ultima_data', $ultimaData);
        }
        $stmtOrigem->execute();

        $linhasProcessadas = 0;

        // 6. Inicia o loop atomico de carga registro por registro
        $this->connModerno->beginTransaction();
        try {
            while ($registroBruto = $stmtOrigem->fetch(\PDO::FETCH_ASSOC)) {
                
                // Passa os dados pela ACL purificada (Higienizacao dinamica sem hardcodes)
                $registroLimpo = AntiCorruptionLayer::processar($registroBruto, $configTabela);

                // Executa a Strategy de persistencia do SQL Server de forma segura
                $this->syntaxModerno->executarUpsert($this->connModerno, $tabelaQualificada, $registroLimpo, $pks);
                
                $linhasProcessadas++;
            }
            
            $this->connModerno->commit();

        } catch (\Exception $e) {
            $this->connModerno->rollBack();
            $this->controlRepo->atualizarVersao($nomeBancoDestino, $tabelaDestino, 'FALHA', $linhasProcessadas);
            throw $e;
        }

        // 7. Atualiza o status final de sucesso da esteira de dados
        $this->controlRepo->atualizarVersao($nomeBancoDestino, $tabelaDestino, 'SUCESSO', $linhasProcessadas);

        return $linhasProcessadas;
    }

    /**
     * Busca de forma isolada e blindada o timestamp da ultima execucao bem-sucedida
     */
    private function obterDataUltimaSincronizacao(string $banco, string $tabela): ?string
    {
        $tabelaControle = $this->syntaxModerno->obterNomeQualificadoTabelaControle();

        $sql = "SELECT ultima_sincronizacao 
                FROM {$tabelaControle} 
                WHERE banco_nome = :banco 
                  AND tabela_nome = :tabela 
                  AND status_execucao = 'SUCESSO'";

        try {
            $stmt = $this->connModerno->prepare($sql);
            $stmt->bindValue(':banco', $banco);
            $stmt->bindValue(':tabela', $tabela);
            $stmt->execute();
            
            $resultado = $stmt->fetchColumn();
            return $resultado ? $resultado : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}