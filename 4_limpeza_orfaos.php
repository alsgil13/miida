<?php

/**
 * MIIDA - Middleware de Ingestão, Integração e Desacoplamento de Arquiteturas
 * Script Auxiliar CLI para Auditoria e Expurgos de Registros Órfãos (Deleções com PK Composta)
 */

namespace Miida\Engine;

use Exception;
use PDO;
use Miida\Database\ConnectionFactory;
use Miida\Services\Logger;

class LimpezaOrfaosProcessor 
{
    private $connLegado;
    private $connModerno;
    private $logger;

    public function __construct($connLegado, $connModerno, $logger)
    {
        $this->connLegado = $connLegado;
        $this->connModerno = $connModerno;
        $this->logger = $logger;
    }

    public function executarLimpeza(array $banco, array $tabela)
    {
        $bancoLegado   = $banco['banco_legado'];
        $bancoModerno  = $banco['banco_moderno'];
        $tabelaLegada  = $tabela['tabela_legada'];
        $tabelaModerna = $tabela['tabela_moderna'];

        // 1. MAPEAMENTO DINÂMICO DE PKS VIA CONFIG_JSON
        $pksOrigem  = [];
        $pksDestino = [];

        if (isset($tabela['camada_anticorrupcao']['mapeamento_colunas'])) {
            foreach ($tabela['camada_anticorrupcao']['mapeamento_colunas'] as $colunaOrigem => $meta) {
                if (isset($meta['pk']) && $meta['pk'] === true) {
                    $pksOrigem[]  = $colunaOrigem;
                    $pksDestino[] = $meta['nome_destino'] ?? $colunaOrigem;
                }
            }
        }

        // Fallback histórico para manter retrocompatibilidade caso não esteja mapeado
        if (empty($pksOrigem)) {
            $pkPadrao = $tabela['chave_primaria'] ?? 'id';
            $pksOrigem[]  = $pkPadrao;
            $pksDestino[] = $pkPadrao;
        }

        $chaveCronometro = "{$bancoModerno}.{$tabelaModerna}";
        $pksLogStr = implode(' + ', $pksDestino);
        echo "\n [" . date('H:i:s') . "] Iniciando auditoria de órfãos em: [{$chaveCronometro}] via PKs [{$pksLogStr}]\n";

        $inicioMili = microtime(true);

        try {
            // 2. CONSTRUÇÃO DO CONCATENADOR DE CHAVES EM SQL (Separado por hífen)
            // Transforma multiplas PKs em uma única string virtual EX: CAST(area AS VARCHAR) + '-' + CAST(codusp AS VARCHAR)
            $concatOrigem  = implode(" + '-' + ", array_map(function($col) { return "CAST({$col} AS VARCHAR(64))"; }, $pksOrigem));
            $concatDestino = implode(" + '-' + ", array_map(function($col) { return "CAST({$col} AS VARCHAR(64))"; }, $pksDestino));

            // Coleta hashes de PK no banco Legado
            $sqlLegado = "SELECT {$concatOrigem} AS pk_virtual FROM {$bancoLegado}.dbo.{$tabelaLegada}";
            $stmtLegado = $this->connLegado->query($sqlLegado);
            $hashesLegado = $stmtLegado->fetchAll(PDO::FETCH_COLUMN, 0);

            // Coleta hashes de PK no banco Moderno
            $sqlModerno = "SELECT {$concatDestino} AS pk_virtual FROM {$bancoModerno}.dbo.{$tabelaModerna}";
            $stmtModerno = $this->connModerno->query($sqlModerno);
            $hashesModerno = $stmtModerno->fetchAll(PDO::FETCH_COLUMN, 0);

            // Identifica chaves que sumiram na origem (órfãos)
            $hashesDeletadas = array_diff($hashesModerno, $hashesLegado);
            $registrosExcluidos = 0;

            if (!empty($hashesDeletadas)) {
                $totalParaDeletar = count($hashesDeletadas);
                echo "   -> Detectados {$totalParaDeletar} registros órfãos para expurgo.\n";

                // 3. REMOÇÃO CIRÚRGICA (TRATAMENTO DE CHAVES SIMPLES OU COMPOSTAS)
                foreach ($hashesDeletadas as $hash) {
                    // Divide a hash de volta nas suas partes originais
                    $valoresChaves = explode('-', $hash);
                    
                    $whereClauses = [];
                    foreach ($pksDestino as $index => $colunaDestino) {
                        $valor = $valoresChaves[$index];
                        // Escapa strings para evitar quebra de sintaxe no SQL
                        $valorTratado = is_numeric($valor) ? $valor : "'{$valor}'";
                        $whereClauses[] = "{$colunaDestino} = {$valorTratado}";
                    }

                    $whereString = implode(' AND ', $whereClauses);
                    $sqlDelete = "DELETE FROM {$bancoModerno}.dbo.{$tabelaModerna} WHERE {$whereString}";
                    
                    $registrosExcluidos += $this->connModerno->exec($sqlDelete);
                }
            }

            $fimMili = microtime(true);
            $tempoGastoMili = round(($fimMili - $inicioMili) * 1000, 2);

            if ($registrosExcluidos > 0) {
                echo " [" . date('H:i:s') . "] Concluído: [{$chaveCronometro}] -> {$registrosExcluidos} registros deletados em {$tempoGastoMili} ms\n";
            } else {
                echo " [" . date('H:i:s') . "] Concluído: [{$chaveCronometro}] -> Nenhum órfão encontrado. Sincronia perfeita em {$tempoGastoMili} ms\n";
            }

            // Gravação do Log usando a sua classe Logger
            $detalhesJson = json_encode([
                'tabela' => $chaveCronometro,
                'pks_utilizadas' => $pksDestino,
                'registros_deletados' => $registrosExcluidos,
                'tempo_processamento_ms' => $tempoGastoMili
            ], JSON_UNESCAPED_UNICODE);

            $this->logger->success(
                "LimpezaOrfaos",
                "Auditoria e expurgo concluído para {$chaveCronometro}", 
                $detalhesJson
            );

            return $registrosExcluidos;

        } catch (Exception $e) {
            $fimMili = microtime(true);
            $tempoGastoMili = round(($fimMili - $inicioMili) * 1000, 2);
            
            echo " [❌ " . date('H:i:s') . "] Falha ao auditar órfãos em [{$chaveCronometro}] após {$tempoGastoMili} ms. Erro: " . $e->getMessage() . "\n";
            
            $detalhesErroJson = json_encode([
                'erro' => $e->getMessage(),
                'tempo_decorrido_ms' => $tempoGastoMili
            ], JSON_UNESCAPED_UNICODE);

            $this->logger->error(
                "LimpezaOrfaos",
                "Falha na auditoria de órfãos da tabela {$chaveCronometro}",
                $detalhesErroJson
            );
            throw $e;
        }
    }
}

// Inicialização CLI
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    require_once __DIR__ . '/autoload.php';

    $jsonPath = __DIR__ . '/config/pipeline_config.json';
    if (!file_exists($jsonPath)) {
        die("ERRO: pipeline_config.json ausente.\n");
    }

    $config = json_decode(file_get_contents($jsonPath), true);
    $infra = $config['configuracao_infraestrutura'];

    foreach (['origem_command', 'destino_query'] as $no) {
        foreach ($infra[$no] as $chave => $valor) {
            if (strpos((string)$valor, 'env:') === 0) {
                $infra[$no][$chave] = getenv(substr($valor, 4)) ?: '';
            }
        }
    }

    try {
        $connLegado  = ConnectionFactory::getLegadoConnection($infra, 'master');
        $connModerno = ConnectionFactory::getModernoConnection($infra, 'master');
        $logger      = new Logger($connModerno);

        $limpador = new LimpezaOrfaosProcessor($connLegado, $connModerno, $logger);

        echo "=========================================================\n";
        echo "          MIIDA - AUDITORIA DE EXCLUSÕES MANUAL          \n";
        echo "=========================================================\n";
        
        $inicioCiclo = microtime(true);
        foreach ($config['bancos_gerenciados'] as $banco) {
            foreach ($banco['tabelas'] as $tabela) {
                $limpador->executarLimpeza($banco, $tabela);
            }
        }
        $tempoCicloMili = round((microtime(true) - $inicioCiclo) * 1000, 2);
        echo "\n=========================================================\n";
        echo "[✔] Ciclo global de auditoria finalizado em {$tempoCicloMili} ms\n";
        echo "=========================================================\n";
    } catch (Exception $e) {
        echo "\nERRO FATAL NA EXECUÇÃO ISOLADA: " . $e->getMessage() . "\n";
    } finally {
        ConnectionFactory::killConnections();
    }
}