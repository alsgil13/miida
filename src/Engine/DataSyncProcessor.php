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
        $tabelaModerna = $configTabela['tabela_moderna'];
        $colunaUpdate  = $configTabela['coluna_last_updated'];

        $timestampCiclo = date('Y-m-d H:i:s');
        $componenteNome = "DataSyncProcessor -> {$tabelaModerna}";
        
        echo "  ├── Sincronizando: [{$bancoLegado}].[{$tabelaLegada}] -> [{$bancoModerno}].[{$tabelaModerna}]\n";

        try {
            $this->connLegado->exec("USE [{$bancoLegado}]");
            $this->connModerno->exec("USE [{$bancoModerno}]");

            $dataCorte = $this->controlRepo->obterUltimaDataSincronizacao($bancoModerno, $tabelaModerna);

            if ($colunaUpdate !== null) {
                $sqlExtracao = "SELECT * FROM [{$tabelaLegada}] WHERE [{$colunaUpdate}] >= :dataCorte";
            } else {
                $sqlExtracao = "SELECT * FROM [{$tabelaLegada}]";
            }

            $stmtExtracao = $this->connLegado->prepare($sqlExtracao);
            $stmtExtracao->execute($colunaUpdate !== null ? [':dataCorte' => $dataCorte] : []);
            $registrosLegados = $stmtExtracao->fetchAll(PDO::FETCH_ASSOC);
            $totalRegistros = count($registrosLegados);

            if ($totalRegistros === 0) {
                $this->controlRepo->atualizarEstadoSincronizacao($bancoModerno, $tabelaModerna, 'SUCESSO', 0, $timestampCiclo);
                return;
            }

            $chavesPrimarias = [];
            $mapeamento = $configTabela['camada_anticorrupcao']['mapeamento_colunas'];
            foreach ($mapeamento as $colunaOriginal => $detalhes) {
                if (isset($detalhes['pk']) && $detalhes['pk'] === true) {
                    $chavesPrimarias[] = $detalhes['nome_destino'] ?? $colunaOriginal;
                }
            }

            if (empty($chavesPrimarias)) {
                throw new Exception("A tabela {$tabelaModerna} não possui nenhuma Chave Primária ('pk') mapeada no JSON.");
            }

            $inseridosOuAtualizados = 0;

            foreach ($registrosLegados as $linhaBruta) {
                $linhaHigienizada = $this->acl->processarLinha($linhaBruta, $configTabela);
                
                $clausulasCheck = [];
                $paramsCheck = [];
                foreach ($chavesPrimarias as $pk) {
                    $clausulasCheck[] = "[{$pk}] = :pk_{$pk}";
                    $paramsCheck[":pk_{$pk}"] = $linhaHigienizada[$pk];
                }
                $stringClausulasCheck = implode(" AND ", $clausulasCheck);

                $sqlCheckHash = "SELECT hash_versao FROM [{$tabelaModerna}] WHERE {$stringClausulasCheck}";
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
                    $camposMergeSource[] = ":{$coluna} AS [{$coluna}]";
                    $camposInsertColunas[] = "[{$coluna}]";
                    $camposInsertValores[] = "s.[{$coluna}]";
                    
                    if (!in_array($coluna, $chavesPrimarias)) {
                        $camposUpdate[] = "t.[{$coluna}] = s.[{$coluna}]";
                    }
                }

                $stringJoinMerge = implode(" AND ", $clausulasJoinMerge = array_map(fn($pk) => "t.[{$pk}] = s.[{$pk}]", $chavesPrimarias));
                $stringMergeSource = implode(", ", $camposMergeSource);
                $stringUpdate = !empty($camposUpdate) ? "UPDATE SET " . implode(", ", $camposUpdate) : "UPDATE SET t.[hash_versao] = s.[hash_versao]";
                $stringInsertColunas = implode(", ", $camposInsertColunas);
                $stringInsertValores = implode(", ", $camposInsertValores);

                $sqlUpsert = "
                    MERGE [{$tabelaModerna}] AS t
                    USING (SELECT {$stringMergeSource}) AS s
                    ON ({$stringJoinMerge})
                    WHEN MATCHED THEN {$stringUpdate}
                    WHEN NOT MATCHED THEN INSERT ({$stringInsertColunas}) VALUES ({$stringInsertValores});
                ";

                $stmtUpsert = $this->connModerno->prepare($sqlUpsert);
                $params = [];
                foreach ($linhaHigienizada as $key => $value) {
                    $params[":{$key}"] = $value;
                }
                $stmtUpsert->execute($params);
                $inseridosOuAtualizados++;
            }

            $this->controlRepo->atualizarEstadoSincronizacao($bancoModerno, $tabelaModerna, 'SUCESSO', $inseridosOuAtualizados, $timestampCiclo);
            
            // LOG DE SUCESSO SE HOUVER ALTERAÇÕES
            if ($inseridosOuAtualizados > 0) {
                $this->logger->success($componenteNome, "Sincronização executada.", "Registros processados: {$inseridosOuAtualizados} de um lote de {$totalRegistros}");
            }

            echo "  │    └── [✔] Ciclo concluído. Registros modificados/inseridos no destino: {$inseridosOuAtualizados}\n";

        } catch (Exception $e) {
            $this->controlRepo->atualizarEstadoSincronizacao($bancoModerno, $tabelaModerna, 'ERRO', 0, $timestampCiclo);
            
            // LOG DE ERRO OPERACIONAL
            $this->logger->error($componenteNome, "Falha crítica durante a sincronização incremental.", $e->getMessage());
            
            echo "  │    └── [❌] ERRO NO CICLO: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}