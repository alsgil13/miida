<?php

namespace Miida\Engine;

use PDO;
use Exception;
use Miida\Database\ControlRepository;
use Miida\Services\AntiCorruptionLayer;
use Miida\Services\Logger; // INCLUA O LOGGER

class DataSyncProcessor
{
    private PDO $connLegado;
    private PDO $connModerno;
    private ControlRepository $controlRepo;
    private AntiCorruptionLayer $acl;
    private Logger $logger; // ADICIONE A PROPRIEDADE

    // INJETE O LOGGER NO CONSTRUTOR
    public function __construct(PDO $connLegado, PDO $connModerno, ControlRepository $controlRepo, AntiCorruptionLayer $acl, Logger $logger)
    {
        $this->connLegado = $connLegado;
        $this->connModerno = $connModerno;
        $this->controlRepo = $controlRepo;
        $this->acl = $acl;
        $this->logger = $logger;
    }

    public function sincronizarTabela(array $configBanco, array $configTabela): void
    {
        $bancoLegado   = $configBanco['banco_legado'];
        $bancoModerno  = $configBanco['banco_moderno'];
        $tabelaLegada  = $configTabela['tabela_legada'];
        $schemaLegado = $configTabela['schema_legado'] ?? "dbo";
        $tabelaModerna = $configTabela['tabela_moderna'];
        $schemaModerno = $configTabela['schema_moderno'] ?? "dbo";
        $colunaUpdate  = $configTabela['coluna_last_updated'];

        $timestampCiclo = date('Y-m-d H:i:s');
        $componenteNome = "DataSyncProcessor -> {$schemaModerno}.{$tabelaModerna}";
        
        echo "  ├── Sincronizando: [{$bancoLegado}].[{$schemaLegado}].[{$tabelaLegada}] -> [{$bancoModerno}].[{$schemaModerno}].[{$tabelaModerna}]\n";

        try {
            $inicioMiliAllSinc = microtime(true);
            $this->connLegado->exec("USE [{$bancoLegado}]");
            $this->connModerno->exec("USE [{$bancoModerno}]");

            $dataCorte = $this->controlRepo->obterUltimaDataSincronizacao($bancoModerno, $tabelaModerna);

            if ($colunaUpdate !== null) {
                $sqlExtracao = "SELECT * FROM [{$bancoLegado}].[{$schemaLegado}].[{$tabelaLegada}] WHERE [{$colunaUpdate}] >= :dataCorte";
            } else {
                $sqlExtracao = "SELECT * FROM [{$bancoLegado}].[{$schemaLegado}].[{$tabelaLegada}]";
            }

            $stmtExtracao = $this->connLegado->prepare($sqlExtracao);
            $stmtExtracao->execute($colunaUpdate !== null ? [':dataCorte' => $dataCorte] : []);
            
            $chavesPrimarias = [];
            $mapeamento = $configTabela['camada_anticorrupcao']['mapeamento_colunas'];
            foreach ($mapeamento as $colunaOriginal => $detalhes) {
                if (isset($detalhes['pk']) && $detalhes['pk'] === true) {
                    $chavesPrimarias[] = $detalhes['nome_destino'] ?? $colunaOriginal;
                }
            }
##### Parei aquiiiiiiiiii
            if (empty($chavesPrimarias)) {
                throw new Exception("A tabela {$bancoModerno}.{$schemaModerno}.{$tabelaModerna} não possui nenhuma Chave Primária ('pk') mapeada no JSON.");
            }

            // Identifica os campos timestamp
            $camposTimestamp = [];
            foreach ($mapeamento as $colunaOriginal => $detalhes) {
                if (isset($detalhes['tipo']) && $detalhes['tipo'] === 'timestamp') {
                    $camposTimestamp[] = $detalhes['nome_destino'] ?? $colunaOriginal;
                }
            }

            // Processa registros em lotes para economizar memória
            $tamanhoBatch = 1000;
            $registrosBatch = [];
            $totalRegistros = 0;
            $inseridosOuAtualizados = 0;

            while ($linhaBruta = $stmtExtracao->fetch(PDO::FETCH_ASSOC)) {
                $registrosBatch[] = $linhaBruta;
                $totalRegistros++;
                
                if (count($registrosBatch) >= $tamanhoBatch) {
                    $inseridosOuAtualizados += $this->processarBatch(
                        $registrosBatch,
                        $configBanco,
                        $configTabela,
                        $timestampCiclo,
                        $chavesPrimarias,
                        $camposTimestamp
                    );
                    $registrosBatch = []; // Libera memória
                }
            }

            // Processa último lote
            if (!empty($registrosBatch)) {
                $inseridosOuAtualizados += $this->processarBatch(
                    $registrosBatch,
                    $configBanco,
                    $configTabela,
                    $timestampCiclo,
                    $chavesPrimarias,
                    $camposTimestamp
                );
            }

            if ($totalRegistros === 0) {
                $this->controlRepo->atualizarEstadoSincronizacao($bancoModerno, $schemaModerno.".".$tabelaModerna, 'SUCESSO', 0, $timestampCiclo);
                return;
            }

            $this->controlRepo->atualizarEstadoSincronizacao($bancoModerno, $schemaModerno.".".$tabelaModerna, 'SUCESSO', $inseridosOuAtualizados, $timestampCiclo);
            $fimMiliAllSinc = microtime(true);
            $tempoGastoMili = round(($fimMiliAllSinc - $inicioMiliAllSinc) * 1000, 2); // Arredonda para 2 casas decimais
            // LOG DE SUCESSO SE HOUVER ALTERAÇÕES
            if ($inseridosOuAtualizados > 0) {
                $componenteNome = "DataSyncProcessor -> {$schemaModerno}.{$tabelaModerna}";
                $this->logger->success($componenteNome, "Sincronização executada.", "Registros processados: {$inseridosOuAtualizados} de um lote de {$totalRegistros} em {$tempoGastoMili}ms");
            }

            echo "  │    └── [ OK ] Ciclo concluído. Registros modificados/inseridos no destino: {$inseridosOuAtualizados} em {$tempoGastoMili}ms\n";

        } catch (Exception $e) {
            $bancoModerno  = $configBanco['banco_moderno'];
            $tabelaModerna = $configTabela['tabela_moderna'];
            $schemaModerno = $configTabela['schema_moderno'];
            $this->controlRepo->atualizarEstadoSincronizacao($bancoModerno, $schemaModerno.".".$tabelaModerna, 'ERRO', 0, $timestampCiclo);
            
            // LOG DE ERRO OPERACIONAL
            $componenteNome = "DataSyncProcessor -> {$schemaModerno}.{$tabelaModerna}";
            $this->logger->error($componenteNome, "Falha crítica durante a sincronização incremental.", $e->getMessage());
            
            echo "  │    └── [ X ] ERRO NO CICLO: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    private function processarBatch(array $registrosBatch, array $configBanco, array $configTabela, string $timestampCiclo, array $chavesPrimarias, array $camposTimestamp): int
    {
        $bancoModerno  = $configBanco['banco_moderno'];
        $tabelaModerna = $configTabela['tabela_moderna'];
        //$schemaLegado = $configTabela['schema_legado'] ?? "dbo";
        $schemaModerno = $configTabela['schema_moderno'] ?? "dbo";
        $colunaUpdate  = $configTabela['coluna_last_updated'];
        $tabelaModernaFQ = $bancoModerno.".".$schemaModerno.".".$tabelaModerna;
        $registrosBatchProcessados = 0;

        foreach ($registrosBatch as $linhaBruta) {
            $linhaHigienizada = $this->acl->processarLinha($linhaBruta, $configTabela);
            
            $clausulasCheck = [];
            $paramsCheck = [];
            foreach ($chavesPrimarias as $pk) {
                $clausulasCheck[] = "[{$pk}] = :pk_{$pk}";
                $paramsCheck[":pk_{$pk}"] = $linhaHigienizada[$pk];
            }
            $stringClausulasCheck = implode(" AND ", $clausulasCheck);

            $sqlCheckHash = "SELECT hash_versao FROM {$tabelaModernaFQ} WHERE {$stringClausulasCheck}";
            $stmtCheck = $this->connModerno->prepare($sqlCheckHash);
            $stmtCheck->execute($paramsCheck);
            $registroDestino = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($registroDestino && $registroDestino['hash_versao'] === $linhaHigienizada['hash_versao']) {
                continue; 
            }

            $colunasLista = array_keys($linhaHigienizada);
            if ($colunaUpdate === null && !in_array('middleware_last_updated', $colunasLista)) {
                $linhaHigienizada['middleware_last_updated'] = $timestampCiclo;
                $colunasLista[] = 'middleware_last_updated';
            }

            $camposMergeSource = [];
            $camposUpdate = [];
            $camposInsertColunas = [];
            $camposInsertValores = [];

            foreach ($colunasLista as $coluna) {
                // Pula campos timestamp
                if (in_array($coluna, $camposTimestamp)) {
                    continue;
                }
                $camposMergeSource[] = ":{$coluna} AS [{$coluna}]";
                $camposInsertColunas[] = "[{$coluna}]";
                $camposInsertValores[] = "s.[{$coluna}]";
                
                if (!in_array($coluna, $chavesPrimarias)) {
                    $camposUpdate[] = "t.[{$coluna}] = s.[{$coluna}]";
                }
            }

            $stringJoinMerge = implode(" AND ", array_map(fn($pk) => "t.[{$pk}] = s.[{$pk}]", $chavesPrimarias));
            $stringMergeSource = implode(", ", $camposMergeSource);
            $stringUpdate = !empty($camposUpdate) ? "UPDATE SET " . implode(", ", $camposUpdate) : "UPDATE SET t.[hash_versao] = s.[hash_versao]";
            $stringInsertColunas = implode(", ", $camposInsertColunas);
            $stringInsertValores = implode(", ", $camposInsertValores);

            $sqlUpsert = "
                MERGE [{$bancoModerno}].[{$schemaModerno}].[{$tabelaModerna}] AS t
                USING (SELECT {$stringMergeSource}) AS s
                ON ({$stringJoinMerge})
                WHEN MATCHED THEN {$stringUpdate}
                WHEN NOT MATCHED THEN INSERT ({$stringInsertColunas}) VALUES ({$stringInsertValores});
            ";

            $stmtUpsert = $this->connModerno->prepare($sqlUpsert);
            $params = [];
            foreach ($linhaHigienizada as $key => $value) {
                // Pule campos timestamp também nos parâmetros
                if (!in_array($key, $camposTimestamp)) {
                    $params[":{$key}"] = $value;
                }
            }
            $stmtUpsert->execute($params);
            $registrosBatchProcessados++;
        }
        
        return $registrosBatchProcessados;
    }
}