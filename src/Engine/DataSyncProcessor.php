<?php

namespace Miida\Engine;

use PDO;
use Exception;
use Miida\Database\ControlRepository;
use Miida\Database\Syntax\SgbdSyntaxInterface;
use Miida\Services\AntiCorruptionLayer;

/**
 * MIIDA - DataSyncProcessor
 * Core Engine: Orquestrador de Extracao (Origem), Aplicacao de ACL e Carga Incremental (Destino)
 */
class DataSyncProcessor
{
    private PDO $origem;
    private PDO $destino;
    private ControlRepository $controlRepo;
    private SgbdSyntaxInterface $syntaxModerno;

    public function __construct(PDO $origem, PDO $destino, ControlRepository $controlRepo, SgbdSyntaxInterface $syntaxModerno)
    {
        $this->origem = $origem;
        $this->destino = $destino;
        $this->controlRepo = $controlRepo;
        $this->syntaxModerno = $syntaxModerno;
    }

    /**
     * Executa a sincronizacao incremental de uma tabela baseando-se em metadados json
     */
    public function sincronizarTabela(array $bancoConfig, array $tabelaConfig): int
    {
        $bancoLegado   = $bancoConfig['banco_legado'];
        $bancoModerno  = $bancoConfig['banco_moderno'];
        
        $tabelaLegada  = $tabelaConfig['tabela_legada'];
        $tabelaModerna = $tabelaConfig['tabela_moderna'];
        $schemaModerno = $tabelaConfig['schema_moderno'] ?? 'dbo';
        
        $sgbdDestino = strtolower(get_class($this->syntaxModerno));
        if (strpos($sgbdDestino, 'sqlserver') !== false && strtolower($schemaModerno) === 'public') {
            $schemaModerno = 'dbo';
        }

        $colunaControleOrigem = $tabelaConfig['coluna_timestamp_controle'];
        $colunaControleDestino = $tabelaConfig['coluna_last_updated'] ?? 'middleware_last_updated';

        $ultimaData = $this->controlRepo->obterUltimaDataSincronizacao($bancoModerno, "{$schemaModerno}.{$tabelaModerna}");
        $novaDataSincronizacao = date('Y-m-d H:i:s');

        $sqlOrigem = "SELECT * FROM `{$bancoLegado}`.`{$tabelaLegada}` WHERE `{$colunaControleOrigem}` > :ultima_data";
        
        $stmtOrigem = $this->origem->prepare($sqlOrigem);
        $stmtOrigem->execute([':ultima_data' => $ultimaData]);
        $registros = $stmtOrigem->fetchAll(PDO::FETCH_ASSOC);

        $totalProcessados = count($registros);
        if ($totalProcessados === 0) {
            $this->controlRepo->atualizarEstadoSincronizacao($bancoModerno, "{$schemaModerno}.{$tabelaModerna}", 'SUCESSO', 0, $novaDataSincronizacao);
            return 0;
        }

        $chavesPrimarias = [];
        foreach ($tabelaConfig['camada_anticorrupcao']['mapeamento_colunas'] as $colOrigem => $props) {
            if (!empty($props['pk'])) {
                $chavesPrimarias[] = $props['nome_destino'];
            }
        }

        $tabelaDestinoQualificada = "[{$bancoModerno}].[{$schemaModerno}].[{$tabelaModerna}]";

        $this->destino->beginTransaction();
        try {
            foreach ($registros as $linha) {
                $registroHigienizado = AntiCorruptionLayer::processar($linha, $tabelaConfig['camada_anticorrupcao']);
                
                $registroHigienizado[$colunaControleDestino] = $novaDataSincronizacao;
                $registroHigienizado['hash_versao'] = md5(json_encode($registroHigienizado));

                // --- NOVO SISTEMA DE UPSERT SEGURO E SEPARADO EM DOIS PASSOS ---
                $camposUpdate = [];
                $clausulaWhere = [];
                $paramsPdo = [];

                foreach ($registroHigienizado as $coluna => $valor) {
                    $paramsPdo[":{$coluna}"] = $valor;
                    if (in_array($coluna, $chavesPrimarias)) {
                        $clausulaWhere[] = "[{$coluna}] = :{$coluna}";
                    } else {
                        $camposUpdate[] = "[{$coluna}] = :{$coluna}";
                    }
                }

                // Passo 1: Executa o UPDATE condicional
                $sqlUpdate = "UPDATE {$tabelaDestinoQualificada} SET " . implode(', ', $camposUpdate) . " WHERE " . implode(' AND ', $clausulaWhere);
                $stmtUpdate = $this->destino->prepare($sqlUpdate);
                $stmtUpdate->execute($paramsPdo);

                // Passo 2: Se o registro nao existia, faz o INSERT nativo
                if ($stmtUpdate->rowCount() === 0) {
                    $colunasInsert = array_keys($registroHigienizado);
                    $listaColunas = '[' . implode('], [', $colunasInsert) . ']';
                    $listaPlaceholders = ':' . implode(', :', $colunasInsert);

                    $sqlInsert = "INSERT INTO {$tabelaDestinoQualificada} ({$listaColunas}) VALUES ({$listaPlaceholders})";
                    $stmtInsert = $this->destino->prepare($sqlInsert);
                    $stmtInsert->execute($paramsPdo);
                }
            }
            
            $this->destino->commit();
            $this->controlRepo->atualizarEstadoSincronizacao($bancoModerno, "{$schemaModerno}.{$tabelaModerna}", 'SUCESSO', $totalProcessados, $novaDataSincronizacao);
            
        } catch (Exception $e) {
            $this->destino->rollBack();
            $this->controlRepo->atualizarEstadoSincronizacao($bancoModerno, "{$schemaModerno}.{$tabelaModerna}", 'ERRO', 0, $novaDataSincronizacao);
            throw $e;
        }

        return $totalProcessados;
    }
}