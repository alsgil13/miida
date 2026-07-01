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
    public function sincronizarTabela(array $bancoConfig, array $tabelaConfig): int
    {
        $nomeBancoOrigem  = $bancoConfig['banco_legado'];
        $nomeBancoDestino = $bancoConfig['banco_moderno'];
        
        $tabelaOrigem  = $tabelaConfig['tabela_legada'];
        $tabelaDestino = $tabelaConfig['tabela_moderna'];
        $schemaOrigem  = $tabelaConfig['schema_legado'] ?? null;
        $schemaDestino = $tabelaConfig['schema_moderno'] ?? 'dbo';
        $colunaControle = $tabelaConfig['coluna_timestamp_controle'] ?? null;

        $mapeamento = $tabelaConfig['camada_anticorrupcao']['mapeamento_colunas'] ?? [];

        // 1. Identifica as PKs usando o nome_destino do JSON
        $pks = [];
        foreach ($mapeamento as $colOriginal => $props) {
            if (!empty($props['pk'])) {
                $pks[] = $props['nome_destino'] ?? $colOriginal;
            }
        }

        // 2. Resolve o nome totalmente qualificado da tabela destino conforme o SGBD ativo
        $tabelaQualificada = $this->syntaxModerno->obterNomeQualificado($nomeBancoDestino, $schemaDestino, $tabelaDestino);

        // 3. Busca o ponteiro da última sincronização para a estratégia incremental
        $ultimaData = $this->obterDataUltimaSincronizacao($nomeBancoDestino, $tabelaDestino);

        // 4. Monta e executa a query de extração respeitando o dialeto de origem
        if (!empty($colunaControle) && !empty($ultimaData)) {
            $sqlOrigem = $this->syntaxLegado->obterSqlSelecaoIncremental($nomeBancoOrigem, $tabelaOrigem, $colunaControle);
            $stmtOrigem = $this->connLegado->prepare($sqlOrigem);
            $stmtOrigem->bindValue(':ultima_data', $ultimaData);
        } else {
            $tabelaOrigemQualificada = $this->syntaxLegado->obterNomeQualificado($nomeBancoOrigem, $schemaOrigem, $tabelaOrigem);
            $sqlOrigem = "SELECT * FROM {$tabelaOrigemQualificada}";
            $stmtOrigem = $this->connLegado->prepare($sqlOrigem);
        }

        $stmtOrigem->execute();
        $linhasProcessadas = 0;

        try {
            while ($row = $stmtOrigem->fetch(\PDO::FETCH_ASSOC)) {
                // 5. Envia o registro para a Camada de Anticorrupção (ACL) ser higienizado
                $dadosHigienizados = AntiCorruptionLayer::higienizar($row, $tabelaConfig);

                // CORREÇÃO: Mantém as chaves limpas para que as Strategies gerenciem os tokens do PDO perfeitamente
                $registroLimpo = [];
                foreach ($dadosHigienizados as $col => $val) {
                    if (!is_numeric($col)) {
                        $registroLimpo[$col] = $val;
                    }
                }

                // INJEÇÃO DAS COLUNAS AUXILIARES DO MIDDLEWARE EXIGIDAS NO BANCO MODERNO
                $colunaTracking = $tabelaConfig['coluna_last_updated'] ?? 'middleware_last_updated';
                $registroLimpo[$colunaTracking] = date('Y-m-d H:i:s');
                
                // Calcula de forma simples um MD5 dos dados brutos para preencher o hash_versao exigido
                $registroLimpo['hash_versao'] = md5(json_encode($row));

                // 6. Delega a persistência idempotente de forma transparente à Strategy do banco moderno ativo
                // CORREÇÃO: Passando $tabelaConfig como 5º parâmetro para viabilizar tratamento dinâmico de tipo
                $this->syntaxModerno->executarUpsert($this->connModerno, $tabelaQualificada, $registroLimpo, $pks, $tabelaConfig);
                $linhasProcessadas++;
            }            

        } catch (\Exception $e) {
            $this->controlRepo->atualizarVersao($nomeBancoDestino, $tabelaDestino, 'FALHA', $linhasProcessadas);
            throw $e;
        }

        $this->controlRepo->atualizarVersao($nomeBancoDestino, $tabelaDestino, 'SUCESSO', $linhasProcessadas);

        return $linhasProcessadas;
    }

    /**
     * Busca o timestamp da última execução bem-sucedida
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
            
            $resultado = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $resultado['ultima_sincronizacao'] ?? null;
        } catch (\Exception $e) {
            return null; // Retorna nulo se a tabela de controle ainda não foi provisionada
        }
    }
}