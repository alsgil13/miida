<?php

namespace Miida\Engine;

use PDO;
use Exception;
use Miida\Database\ControlRepository;
use Miida\Database\Syntax\SgbdSyntaxInterface;
use Miida\Services\AntiCorruptionLayer;
use Miida\Services\Logger;

class DataSyncProcessor
{
    private PDO $connLegado;
    private PDO $connModerno;
    private SgbdSyntaxInterface $syntaxLegado;
    private SgbdSyntaxInterface $syntaxModerno;
    private ControlRepository $controlRepo;
    private AntiCorruptionLayer $acl;
    private Logger $logger;

    /**
     * O Construtor agora é totalmente parametrizado com as abstrações de sintaxe de ambos os mundos
     */
    public function __construct(
        PDO $connLegado, 
        PDO $connModerno, 
        SgbdSyntaxInterface $syntaxLegado,
        SgbdSyntaxInterface $syntaxModerno,
        ControlRepository $controlRepo, 
        AntiCorruptionLayer $acl, 
        Logger $logger
    ) {
        $this->connLegado = $connLegado;
        $this->connModerno = $connModerno;
        $this->syntaxLegado = $syntaxLegado;
        $this->syntaxModerno = $syntaxModerno;
        $this->controlRepo = $controlRepo;
        $this->acl = $acl;
        $this->logger = $logger;
    }

    public function sincronizarTabela(array $configBanco, array $configTabela): void
    {
        $bancoLegado   = $configBanco['banco_legado'];
        $bancoModerno  = $configBanco['banco_moderno'];
        $tabelaLegada  = $configTabela['tabela_legada'];
        $schemaLegado  = $configTabela['schema_legado'] ?? null;
        $tabelaModerna = $configTabela['tabela_moderna'];
        $schemaModerno = $configTabela['schema_moderno'] ?? null;
        $colunaUpdate  = $configTabela['coluna_last_updated'];

        $timestampCiclo = date('Y-m-d H:i:s');
        
        // Qualifica os nomes das tabelas de forma dinâmica respeitando o dialeto de cada SGBD
        $tabelaFqLegado  = $this->syntaxLegado->obterNomeQualificado($bancoLegado, $schemaLegado, $tabelaLegada);
        $tabelaFqModerno = $this->syntaxModerno->obterNomeQualificado($bancoModerno, $schemaModerno, $tabelaModerna);

        $componenteNome = "DataSyncProcessor -> {$tabelaFqModerno}";
        echo "  ├── Sincronizando: [{$tabelaFqLegado}] -> [{$tabelaFqModerno}]\n";

        try {
            $inicioMiliAllSinc = microtime(true);

            // Resgata o cursor de rastreamento do ControlRepository de forma agnóstica
            $dataCorte = $this->controlRepo->obterUltimaDataSincronizacao($bancoModerno, $tabelaModerna);

            // Monta a query de extração incremental baseada na estratégia de sintaxe da origem
            if ($colunaUpdate !== null) {
                // Algumas sintaxes exigem escape em colunas temporais, o padrão ANSI atende a maioria
                $sqlExtracao = "SELECT * FROM {$tabelaFqLegado} WHERE {$colunaUpdate} >= :dataCorte";
            } else {
                $sqlExtracao = "SELECT * FROM {$tabelaFqLegado}";
            }

            $stmtExtracao = $this->connLegado->prepare($sqlExtracao);
            $stmtExtracao->execute($colunaUpdate !== null ? [':dataCorte' => $dataCorte] : []);
            
            // Mapeia chaves primárias e colunas de timestamp vindas do JSON
            $chavesPrimarias = [];
            $camposTimestamp = [];
            $mapeamento = $configTabela['camada_anticorrupcao']['mapeamento_colunas'];
            
            foreach ($mapeamento as $colunaOriginal => $detalhes) {
                if (isset($detalhes['pk']) && $detalhes['pk'] === true) {
                    $chavesPrimarias[] = $detalhes['nome_destino'] ?? $colunaOriginal;
                }
                if (isset($detalhes['tipo']) && $detalhes['tipo'] === 'timestamp') {
                    $camposTimestamp[] = $detalhes['nome_destino'] ?? $colunaOriginal;
                }
            }

            if (empty($chavesPrimarias)) {
                throw new Exception("A tabela {$tabelaFqModerno} não possui nenhuma Chave Primária ('pk') mapeada.");
            }

            // Processamento em streaming por lotes (Batches) para alta volumetria
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
                        $tabelaFqModerno,
                        $configTabela,
                        $timestampCiclo,
                        $chavesPrimarias,
                        $camposTimestamp
                    );
                    $registrosBatch = [];
                }
            }

            if (!empty($registrosBatch)) {
                $inseridosOuAtualizados += $this->processarBatch(
                    $registrosBatch,
                    $tabelaFqModerno,
                    $configTabela,
                    $timestampCiclo,
                    $chavesPrimarias,
                    $camposTimestamp
                );
            }

            // Atualização de estado no banco de metadados
            $identificadorTabelaControle = ($schemaModerno ? $schemaModerno . "." : "") . $tabelaModerna;
            
            if ($totalRegistros === 0) {
                $this->controlRepo->atualizarEstadoSincronizacao($bancoModerno, $identificadorTabelaControle, 'SUCESSO', 0, $timestampCiclo);
                return;
            }

            $this->controlRepo->atualizarEstadoSincronizacao($bancoModerno, $identificadorTabelaControle, 'SUCESSO', $inseridosOuAtualizados, $timestampCiclo);
            
            $tempoGastoMili = round((microtime(true) - $inicioMiliAllSinc) * 1000, 2);
            
            if ($inseridosOuAtualizados > 0) {
                $this->logger->success($componenteNome, "Sincronização executada com sucesso.", "Modificados: {$inseridosOuAtualizados} de {$totalRegistros} registros em {$tempoGastoMili}ms");
            }

            echo "  │    └── [ OK ] Ciclo concluído. Registros modificados no destino: {$inseridosOuAtualizados} em {$tempoGastoMili}ms\n";

        } catch (Exception $e) {
            $identificadorTabelaControle = ($schemaModerno ? $schemaModerno . "." : "") . $tabelaModerna;
            $this->controlRepo->atualizarEstadoSincronizacao($bancoModerno, $identificadorTabelaControle, 'ERRO', 0, $timestampCiclo);
            
            $this->logger->error($componenteNome, "Falha crítica durante a sincronização incremental.", $e->getMessage());
            echo "  │    └── [ X ] ERRO NO CICLO: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    private function processarBatch(
        array $registrosBatch, 
        string $tabelaFqModerno, 
        array $configTabela, 
        string $timestampCiclo, 
        array $chavesPrimarias, 
        array $camposTimestamp
    ): int {
        $colunaUpdate = $configTabela['coluna_last_updated'];
        $registrosBatchProcessados = 0;

        foreach ($registrosBatch as $linhaBruta) {
            // Passa os dados pela Camada de Anti-Corrupção (Higienização e cálculo do Hash MD5)
            $linhaHigienizada = $this->acl->processarLinha($linhaBruta, $configTabela);
            
            // 1. CHECAGEM DE CONCORRÊNCIA AGGNÓSTICA
            $clausulasCheck = [];
            $paramsCheck = [];
            foreach ($chavesPrimarias as $pk) {
                // Remove os colchetes hardcoded, deixando as chaves limpas para os Named Parameters do PDO
                $clausulasCheck[] = "{$pk} = :pk_{$pk}";
                $paramsCheck[":pk_{$pk}"] = $linhaHigienizada[$pk];
            }
            $stringClausulasCheck = implode(" AND ", $clausulasCheck);

            $sqlCheckHash = "SELECT hash_versao FROM {$tabelaFqModerno} WHERE {$stringClausulasCheck}";
            $stmtCheck = $this->connModerno->prepare($sqlCheckHash);
            $stmtCheck->execute($paramsCheck);
            $registroDestino = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            // Curto-circuito defensivo: se o registro já existe no destino com o mesmo hash, ignora a escrita
            if ($registroDestino && $registroDestino['hash_versao'] === $linhaHigienizada['hash_versao']) {
                continue;
            }

            // Injeta o marcador temporal do middleware se a coluna do legado for nula
            $colunasLista = array_keys($linhaHigienizada);
            if ($colunaUpdate === null && !in_array('middleware_last_updated', $colunasLista)) {
                $linhaHigienizada['middleware_last_updated'] = $timestampCiclo;
                $colunasLista[] = 'middleware_last_updated';
            }

            // Filtra colunas ignorando os tipos nativos de timestamp que conflitam no INSERT
            $colunasEfetivas = array_filter($colunasLista, fn($c) => !in_array($c, $camposTimestamp));

            // 2. O STRATEGY EM AÇÃO: Invoca a montagem do Upsert/Merge perfeito do SGBD Alvo
            $sqlUpsert = $this->syntaxModerno->obterSqlUpsert($tabelaFqModerno, $colunasEfetivas, $chavesPrimarias);

            $stmtUpsert = $this->connModerno->prepare($sqlUpsert);
            $params = [];
            foreach ($linhaHigienizada as $key => $value) {
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