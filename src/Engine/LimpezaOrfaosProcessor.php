<?php

namespace Miida\Engine;

use PDO;
use Exception;
use Miida\Database\Syntax\SgbdSyntaxInterface;
use Miida\Pipeline\FilterInterface;
use Miida\Services\Logger;

class LimpezaOrfaosProcessor implements FilterInterface
{
    private PDO $connLegado;
    private PDO $connModerno;
    private SgbdSyntaxInterface $syntaxLegado;
    private SgbdSyntaxInterface $syntaxModerno;
    private ?Logger $logger;

    public function __construct(
        PDO $connLegado, 
        PDO $connModerno, 
        SgbdSyntaxInterface $syntaxLegado,
        SgbdSyntaxInterface $syntaxModerno,
        ?Logger $logger = null
    ) {
        $this->connLegado = $connLegado;
        $this->connModerno = $connModerno;
        $this->syntaxLegado = $syntaxLegado;
        $this->syntaxModerno = $syntaxModerno;
        $this->logger = $logger;
    }

    /**
     * Ponto de entrada padrão exigido pela FilterInterface
     */
    public function process(array $config): array
    {
        $totalDeletadoGeral = 0;
        $detalhes = [];
        $temErro = false;

        $bancos = $config['bancos_gerenciados'] ?? [];

        foreach ($bancos as $banco) {
            $tabelas = $banco['tabelas'] ?? [];
            
            foreach ($tabelas as $tabela) {
                try {
                    $deletadosTabela = $this->executarLimpeza($banco, $tabela);
                    $totalDeletadoGeral += $deletadosTabela;

                    $detalhes[] = [
                        'banco'            => $banco['banco_moderno'],
                        'tabela'           => $tabela['tabela_moderna'],
                        'status'           => 'SUCESSO',
                        'linhas_removidas' => $deletadosTabela
                    ];
                } catch (Exception $e) {
                    $temErro = true;
                    $detalhes[] = [
                        'banco'  => $banco['banco_moderno'],
                        'tabela' => $tabela['tabela_moderna'],
                        'status' => 'ERRO',
                        'motivo' => $e->getMessage()
                    ];
                }
            }
        }

        return [
            'engine'           => 'LimpezaOrfaosProcessor',
            'status'           => $temErro ? 'AVISO' : 'SUCESSO',
            'total_processado' => $totalDeletadoGeral,
            'detalhes'         => $detalhes
        ];
    }

    /**
     * Executa a auditoria e expurgo de órfãos para uma tabela específica
     */
    public function executarLimpeza(array $banco, array $tabela): int
    {
        $bancoLegado   = $banco['banco_legado'];
        $bancoModerno  = $banco['banco_moderno'];
        $tabelaLegada  = $tabela['tabela_legada'];
        $tabelaModerna = $tabela['tabela_moderna'];
        $schemaModerno = $tabela['schema_moderno'] ?? null;
        $schemaLegado  = $tabela['schema_legado'] ?? null;

        $tabelaFqLegado  = $this->syntaxLegado->obterNomeQualificado($bancoLegado, $schemaLegado, $tabelaLegada);
        $tabelaFqModerno = $this->syntaxModerno->obterNomeQualificado($bancoModerno, $schemaModerno, $tabelaModerna);

        $componenteNome = "LimpezaOrfaosProcessor -> " . $tabelaModerna;

        $chavesPrimarias = [];
        $mapeamento = $tabela['camada_anticorrupcao']['mapeamento_colunas'] ?? [];
        
        foreach ($mapeamento as $colunaOriginal => $detalhes) {
            if (isset($detalhes['pk']) && $detalhes['pk'] === true) {
                $chavesPrimarias[] = [
                    'origem'  => $colunaOriginal,
                    'destino' => $detalhes['nome_destino'] ?? $colunaOriginal
                ];
            }
        }

        // Se a tabela não possuir Chave Primária configurada, pula a verificação
        if (empty($chavesPrimarias)) {
            return 0;
        }

        $pksOrigem  = array_column($chavesPrimarias, 'origem');
        $pksDestino = array_column($chavesPrimarias, 'destino');

        // 1. EXTRAÇÃO DINÂMICA DAS CHAVES EXISTENTES NA ORIGEM (LEGADO)
        $concatOrigem = $this->syntaxLegado->obterSqlConcat($pksOrigem);
        $sqlOrigem = "SELECT " . $concatOrigem . " AS chave_composta FROM " . $tabelaFqLegado;
        
        $stmtOrigem = $this->connLegado->prepare($sqlOrigem);
        $stmtOrigem->execute();
        $chavesOrigem = $stmtOrigem->fetchAll(PDO::FETCH_COLUMN, 0);

        // 2. EXTRAÇÃO DINÂMICA DAS CHAVES EXISTENTES NO DESTINO (MODERNO)
        $concatDestino = $this->syntaxModerno->obterSqlConcat($pksDestino);
        $sqlDestino = "SELECT " . $concatDestino . " AS chave_composta FROM " . $tabelaFqModerno;
        
        $stmtDestino = $this->connModerno->prepare($sqlDestino);
        $stmtDestino->execute();
        $chavesDestino = $stmtDestino->fetchAll(PDO::FETCH_COLUMN, 0);

        // Identifica chaves que existem no destino mas já não constam na origem (Órfãos)
        $chavesOrfas = array_diff($chavesDestino, $chavesOrigem);

        if (empty($chavesOrfas)) {
            return 0;
        }

        // 3. REMOÇÃO DOS ÓRFÃOS EM BATCHES PARA NÃO TRAVAR O BANCO
        $loteDelecao = array_chunk($chavesOrfas, 500);
        $totalDeletado = 0;

        foreach ($loteDelecao as $lote) {
            $clausulasOr = [];
            $params = [];
            $i = 0;

            foreach ($lote as $chaveComposta) {
                $partes = explode('-', $chaveComposta);
                $clausulasAnd = [];
                
                foreach ($pksDestino as $index => $colunaPk) {
                    $paramKey = ":p_" . $colunaPk . "_" . $i;
                    $clausulasAnd[] = $colunaPk . " = " . $paramKey;
                    $params[$paramKey] = $partes[$index] ?? '';
                }
                
                $clausulasOr[] = "(" . implode(" AND ", $clausulasAnd) . ")";
                $i++;
            }

            $sqlDelete = "DELETE FROM " . $tabelaFqModerno . " WHERE " . implode(" OR ", $clausulasOr);
            $stmtDelete = $this->connModerno->prepare($sqlDelete);
            $stmtDelete->execute($params);
            
            $totalDeletado += $stmtDelete->rowCount();
        }

        if ($totalDeletado > 0 && $this->logger !== null) {
            $this->logger->info(
                $componenteNome, 
                "Registros orfaos expurgados do ambiente moderno. Total de linhas removidas: " . $totalDeletado
            );
        }

        return $totalDeletado;
    }
}