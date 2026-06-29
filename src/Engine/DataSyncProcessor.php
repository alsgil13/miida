<?php

namespace Miida\Engine;

use PDO;
use Exception;
use Miida\Database\ControlRepository;
use Miida\Database\Syntax\SgbdSyntaxInterface;
use Miida\Services\AntiCorruptionLayer;

/**
 * MIIDA - DataSyncProcessor
 * Core Engine: Orquestrador de Extração, ACL e Carga Híbrida (Incremental / Full-Hash)
 * Implementação Pura do Padrão Strategy (100% Agnóstica a SGBDs)
 */
class DataSyncProcessor
{
    private PDO $origem;
    private PDO $destino;
    private SgbdSyntaxInterface $syntaxLegado;
    private SgbdSyntaxInterface $syntaxModerno;
    private ControlRepository $controlRepo;

    public function __construct(
        PDO $origem, 
        PDO $destino, 
        SgbdSyntaxInterface $syntaxLegado, 
        SgbdSyntaxInterface $syntaxModerno, 
        ControlRepository $controlRepo
    ) {
        $this->origem = $origem;
        $this->destino = $destino;
        $this->syntaxLegado = $syntaxLegado;
        $this->syntaxModerno = $syntaxModerno;
        $this->controlRepo = $controlRepo;
    }

    /**
     * Executa a sincronização baseando-se em metadados json (Incremental Temporal ou Comparação por Hash)
     */
    public function sincronizarTabela(array $bancoConfig, array $tabelaConfig): int
    {
        $bancoLegado   = $bancoConfig['banco_legado'];
        $bancoModerno  = $bancoConfig['banco_moderno'];
        
        $tabelaLegada  = $tabelaConfig['tabela_legada'];
        $tabelaModerna = $tabelaConfig['tabela_moderna'];
        
        $schemaLegado  = $tabelaConfig['schema_legado'] ?? 'dbo';
        $schemaModerno = $tabelaConfig['schema_moderno'] ?? 'dbo';

        $colunaControleOrigem = $tabelaConfig['coluna_timestamp_controle'] ?? null;
        $colunaControleDestino = $tabelaConfig['coluna_last_updated'] ?? 'middleware_last_updated';

        $chavesPrimarias = [];
        foreach ($tabelaConfig['camada_anticorrupcao']['mapeamento_colunas'] as $colOrigem => $props) {
            if (!empty($props['pk'])) {
                $chavesPrimarias[] = $props['nome_destino'];
            }
        }

        // Resolução Simétrica: Cada Strategy lida com sua própria normalização interna de schema e delimitadores
        $tabelaOrigemQualificada  = $this->syntaxLegado->obterNomeQualificado($bancoLegado, $schemaLegado, $tabelaLegada);
        $tabelaDestinoQualificada = $this->syntaxModerno->obterNomeQualificado($bancoModerno, $schemaModerno, $tabelaModerna);

        // --- 1. RESOLUÇÃO DA ESTRATÉGIA DE EXTRAÇÃO ---
        $modoPorHash = empty($colunaControleOrigem);
        $novaDataSincronizacao = date('Y-m-d H:i:s');

        if (!$modoPorHash) {
            // Estratégia A: Captura Incremental baseada em data/hora de controle
            $ultimaData = $this->controlRepo->obterUltimaDataSincronizacao($bancoModerno, "{$schemaModerno}.{$tabelaModerna}");
            $novaDataSincronizacao = $ultimaData; 

            // Escapa a coluna de controle na origem delegando para a Strategy Legada
            $colControleOrigemEscapada = $this->syntaxLegado->escaparColuna($colunaControleOrigem);

            $sqlOrigem = "SELECT * FROM {$tabelaOrigemQualificada} WHERE {$colControleOrigemEscapada} > :ultima_data ORDER BY {$colControleOrigemEscapada} ASC";
            $stmtOrigem = $this->origem->prepare($sqlOrigem);
            $stmtOrigem->execute([':ultima_data' => $ultimaData]);
        } else {
            // Estratégia B: Fallback por Hash Semântico (Carga total de validação)
            $sqlOrigem = "SELECT * FROM {$tabelaOrigemQualificada}";
            $stmtOrigem = $this->origem->query($sqlOrigem);
        }

        $registros = $stmtOrigem->fetchAll(PDO::FETCH_ASSOC);
        $totalLote = count($registros);
        
        if ($totalLote === 0) {
            return 0;
        }

        $linhasSincronizadasEfetivas = 0;
        $this->destino->beginTransaction();

        try {
            foreach ($registros as $linha) {
                if (!$modoPorHash && !empty($linha[$colunaControleOrigem])) {
                    $novaDataSincronizacao = $linha[$colunaControleOrigem];
                }

                // Passagem obrigatória pela Camada de Anticorrupção (ACL)
                $registroHigienizado = AntiCorruptionLayer::processar($linha, $tabelaConfig['camada_anticorrupcao']);
                $hashVersao = md5(json_encode($registroHigienizado));

                // Injeção dos dados técnicos e metadados de rastreabilidade
                $registroHigienizado[$colunaControleDestino] = date('Y-m-d H:i:s');
                $registroHigienizado['hash_versao'] = $hashVersao;

                $camposUpdate = [];
                $clausulaWhere = [];
                $paramsPdo = [];

                foreach ($registroHigienizado as $coluna => $valor) {
                    $placeholder = ":" . str_replace([' ', '-', '.'], '_', $coluna);
                    $paramsPdo[$placeholder] = $valor;

                    // Uso nativo e agnóstico da Strategy destino para escapar as colunas
                    $colunaEscapada = $this->syntaxModerno->escaparColuna($coluna);

                    if (in_array($coluna, $chavesPrimarias)) {
                        $clausulaWhere[] = "{$colunaEscapada} = {$placeholder}";
                    } elseif ($coluna !== $colunaControleDestino && $coluna !== 'hash_versao') {
                        $camposUpdate[] = "{$colunaEscapada} = {$placeholder}";
                    }
                }

                // Se operando em Fallback de Carga Total por Hash, checa mutações de dados
                if ($modoPorHash) {
                    $condicaoWhereString = implode(' AND ', $clausulaWhere);
                    
                    // Escapa a coluna hash_versao dinamicamente na query de verificação
                    $colHashCheckEscapada = $this->syntaxModerno->escaparColuna('hash_versao');
                    $sqlCheck = "SELECT {$colHashCheckEscapada} FROM {$tabelaDestinoQualificada} WHERE {$condicaoWhereString}";
                    
                    $stmtCheck = $this->destino->prepare($sqlCheck);
                    
                    $paramsPkCheck = [];
                    foreach ($chavesPrimarias as $pkCol) {
                        $pKey = ":" . str_replace([' ', '-', '.'], '_', $pkCol);
                        $paramsPkCheck[$pKey] = $registroHigienizado[$pkCol];
                    }
                    
                    $stmtCheck->execute($paramsPkCheck);
                    $registroModernoExistente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                    // Se hashes idênticos na comparação semântica, evita gravação de I/O desnecessária
                    if ($registroModernoExistente && $registroModernoExistente['hash_versao'] === $hashVersao) {
                        continue;
                    }
                }

                // Injeta as colunas técnicas no lote final de atualização usando a Strategy de destino
                $camposUpdate[] = $this->syntaxModerno->escaparColuna($colunaControleDestino) . " = :{$colunaControleDestino}";
                $camposUpdate[] = $this->syntaxModerno->escaparColuna('hash_versao') . " = :hash_versao";

                // PASSO 1: Tenta realizar o UPDATE
                $sqlUpdate = "UPDATE {$tabelaDestinoQualificada} SET " . implode(', ', $camposUpdate) . " WHERE " . implode(' AND ', $clausulaWhere);
                $stmtUpdate = $this->destino->prepare($sqlUpdate);
                $stmtUpdate->execute($paramsPdo);

                // PASSO 2: Caso o registro seja inédito (0 linhas afetadas), realiza o INSERT
                if ($stmtUpdate->rowCount() === 0) {
                    $colunasInsert = array_keys($registroHigienizado);
                    
                    // Formata as colunas do INSERT dinamicamente via Strategy
                    $listaColunasFormatadas = array_map(function($col) {
                        return $this->syntaxModerno->escaparColuna($col);
                    }, $colunasInsert);

                    $listaPlaceholders = ':' . implode(', :|:', array_map(function($col) {
                        return str_replace([' ', '-', '.'], '_', $col);
                    }, $colunasInsert));
                    $listaPlaceholders = str_replace('|', '', $listaPlaceholders);

                    $sqlInsert = "INSERT INTO {$tabelaDestinoQualificada} (" . implode(', ', $listaColunasFormatadas) . ") VALUES ({$listaPlaceholders})";
                    $stmtInsert = $this->destino->prepare($sqlInsert);
                    $stmtInsert->execute($paramsPdo);
                }

                $linhasSincronizadasEfetivas++;
            }
            
            $this->destino->commit();
            
            // Grava os metadados agregados do ciclo na tabela técnica de controle
            $this->controlRepo->atualizarEstadoSincronizacao(
                $bancoModerno, 
                "{$schemaModerno}.{$tabelaModerna}", 
                'SUCESSO', 
                $linhasSincronizadasEfetivas, 
                $modoPorHash ? date('Y-m-d H:i:s') : $novaDataSincronizacao
            );
            
        } catch (Exception $e) {
            $this->destino->rollBack();
            $this->controlRepo->atualizarEstadoSincronizacao($bancoModerno, "{$schemaModerno}.{$tabelaModerna}", 'ERRO', 0, date('Y-m-d H:i:s'));
            throw $e;
        }

        return $linhasSincronizadasEfetivas;
    }
}