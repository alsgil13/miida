<?php

namespace Miida\Engine;

use Miida\Database\ControlRepository;
use Miida\Database\Syntax\SgbdSyntaxInterface;
use Miida\Database\Syntax\SqlServerSyntax;
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
        $schemaDestino = $tabelaConfig['schema_moderno'] ?? null;
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

        $usaTransacao = !($this->syntaxModerno instanceof SqlServerSyntax);
        $transacaoIniciada = false;
        try {
            if ($usaTransacao && !$this->connModerno->inTransaction()) {
                $this->connModerno->beginTransaction();
                $transacaoIniciada = true;
            }

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

                // -----------------------------------------------------------------
                // GERAÇÃO DO HASH_DE_VERSAO APENAS A PARTIR DAS COLUNAS DE NEGÓCIO
                // -----------------------------------------------------------------
                // 1) Monta um subconjunto estrito contendo somente as colunas mapeadas
                $businessSubset = [];
                foreach ($mapeamento as $colOrig => $props) {
                    $nomeDestino = $props['nome_destino'] ?? $colOrig;
                    if (array_key_exists($nomeDestino, $registroLimpo)) {
                        $businessSubset[$nomeDestino] = $registroLimpo[$nomeDestino];
                    } else {
                        $businessSubset[$nomeDestino] = null;
                    }
                }

                // 2) Normaliza a ordem das chaves para garantir hash determinístico
                ksort($businessSubset);

                // 3) Calcula o hash (UTF-8 safe)
                $hashVersao = md5(json_encode($businessSubset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                // -----------------------------------------------------------------
                // 4) Checa no destino se o hash já é o mesmo (evita UPSERT desnecessário)
                // -----------------------------------------------------------------
                $deveExecutarUpsert = true;
                if (!empty($pks)) {
                    $whereParts = [];
                    $whereParams = [];
                    foreach ($pks as $pk) {
                        $token = 'pk_' . preg_replace('/[^A-Za-z0-9_]/', '_', $pk);
                        $whereParts[] = $this->syntaxModerno->escaparColuna($pk) . " = :{$token}";
                        $whereParams[":{$token}"] = $registroLimpo[$pk] ?? null;
                    }

                    if (!empty($whereParts)) {
                        $sqlCheck = "SELECT " . $this->syntaxModerno->escaparColuna('hash_versao') . " FROM {$tabelaQualificada} WHERE " . implode(' AND ', $whereParts);
                        $stmtCheck = $this->connModerno->prepare($sqlCheck);
                        foreach ($whereParams as $tk => $tv) {
                            $stmtCheck->bindValue($tk, $tv);
                        }
                        $stmtCheck->execute();
                        $existing = $stmtCheck->fetch(\PDO::FETCH_ASSOC);
                        $existingHash = $existing['hash_versao'] ?? null;
                        $stmtCheck->closeCursor();
                        $stmtCheck = null;
                        if ($existingHash !== null && $existingHash === $hashVersao) {
                            // Hash idêntico -> ignora este registro completamente
                            $deveExecutarUpsert = false;
                        }
                    }
                }

                if (!$deveExecutarUpsert) {
                    // Registro não modificado — não conta como afetado
                    continue;
                }

                // 5) Injeta a coluna de tracking e o hash_versao somente quando realmente vamos persistir
                $colunaTracking = $tabelaConfig['coluna_last_updated'] ?? null;
                $nomeColTracking = $colunaTracking ?: 'middleware_last_updated';
                if (!array_key_exists($nomeColTracking, $registroLimpo)) {
                    $registroLimpo[$nomeColTracking] = date('Y-m-d H:i:s');
                }

                $registroLimpo['hash_versao'] = $hashVersao;

                // 6. Delega a persistência idempotente de forma transparente à Strategy do banco moderno ativo
                // CORREÇÃO: Passando $tabelaConfig como 5º parâmetro para viabilizar tratamento dinâmico de tipo
                $this->syntaxModerno->executarUpsert($this->connModerno, $tabelaQualificada, $registroLimpo, $pks, $tabelaConfig);
                $linhasProcessadas++;
            }

            if ($transacaoIniciada && $this->connModerno->inTransaction()) {
                $this->connModerno->commit();
            }

            $stmtOrigem->closeCursor();
            $stmtOrigem = null;

        } catch (\Exception $e) {
            if ($transacaoIniciada && $this->connModerno->inTransaction()) {
                $this->connModerno->rollBack();
            }
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
            $stmt->closeCursor();
            $stmt = null;
            return $resultado['ultima_sincronizacao'] ?? null;
        } catch (\Exception $e) {
            throw $e;
        }
    }
}