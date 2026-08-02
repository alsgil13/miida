<?php

namespace Miida\Engine;

use PDO;
use Exception;
use Miida\Database\Syntax\SgbdSyntaxInterface;
use Miida\Pipeline\FilterInterface;

/**
 * MIIDA - SchemaCloner
 * Mecanismo de Engenharia Reversa e Clonagem Estrutural Declarativa (Multi-SGBD)
 * Implementação Pura do Padrão Strategy com suporte à FilterInterface
 */
class SchemaCloner implements FilterInterface
{
    private PDO $destino;
    private SgbdSyntaxInterface $syntax;

    public function __construct(PDO $destino, SgbdSyntaxInterface $syntax)
    {
        $this->destino = $destino;
        $this->syntax = $syntax;
    }

    /**
     * Ponto de entrada exigido pela FilterInterface para execução no Pipeline.
     * 
     * @param array $config Manifesto completo do pipeline JSON
     * @return array Relatório padronizado de execução da clonagem
     */
    public function process(array $config): array
    {
        $relatorio = [
            'engine'           => 'SchemaCloner',
            'status'           => 'SUCESSO',
            'total_processado' => 0,
            'detalhes'         => []
        ];

        $bancosGerenciados = $config['bancos_gerenciados'] ?? [];

        try {
            // Delega a execução para o método de clonagem estrutural
            $this->clonar($bancosGerenciados, $relatorio);
        } catch (\Exception $e) {
            $relatorio['status'] = 'FALHA_CRITICA';
            $relatorio['detalhes'][] = [
                'status' => 'ERRO',
                'motivo' => $e->getMessage()
            ];
        }

        return $relatorio;
    }

    /**
     * Executa o provisionamento estrutural baseado nos bancos gerenciados do manifesto JSON
     */
    public function clonar(array $bancosGerenciados, array &$relatorio = []): void
    {
        foreach ($bancosGerenciados as $bancoConfig) {
            $bancoModerno = $bancoConfig['banco_moderno'];

            // 1. Delega a instrução de mudança de contexto de banco à Strategy ativa
            $sqlMudarBanco = $this->syntax->obterComandoTrocaBanco($bancoModerno);
            if (!empty($sqlMudarBanco)) {
                try {
                    $this->destino->exec($sqlMudarBanco);
                } catch (Exception $e) {
                    // Alguns SGBDs tratam troca de banco na DSN, ignoramos se falhar por comando isolado
                }
            }

            foreach ($bancoConfig['tabelas'] as $tabelaConfig) {
                $tabelaModerna = $tabelaConfig['tabela_moderna'];
                $schemaModerno = $tabelaConfig['schema_moderno'] ?? 'dbo';
                $mapeamento    = $tabelaConfig['camada_anticorrupcao']['mapeamento_colunas'] ?? [];

                try {
                    // 2. Cria o Schema lógico se o SGBD der suporte
                    $sqlSchema = $this->syntax->obterDdlCriarSchema($schemaModerno);
                    if (!empty($sqlSchema)) {
                        $stmtSchema = $this->destino->query($sqlSchema);
                        if ($stmtSchema) {
                            $stmtSchema->closeCursor();
                        }
                    }

                    // 3. Monta o mapa bruto de colunas para a Strategy assumir 100% da sintaxe final
                    $colunas = [];
                    $pks = [];
                    foreach ($mapeamento as $colunaOrigem => $props) {
                        $nomeColDestino = $props['nome_destino'] ?? $colunaOrigem;
                        $tipoDestino = strtoupper($props['tipo'] ?? $props['tipo_destino'] ?? 'VARCHAR(255)');

                        if ($tipoDestino === 'TEXT') {
                            $tipoDestino = $this->syntax->obterTipoTextoLongo();
                        }

                        $colunas[$nomeColDestino] = $tipoDestino;

                        if (!empty($props['pk'])) {
                            $pks[] = $nomeColDestino;
                        }
                    }

                    // Injeta coluna técnica de tracking apenas como fallback quando o manifesto não a definiu.
                    $colunaLastUpdated = $tabelaConfig['coluna_last_updated'] ?? null;
                    if (!empty($colunaLastUpdated) && !array_key_exists($colunaLastUpdated, $colunas)) {
                        $colunas[$colunaLastUpdated] = $this->syntax->obterTipoDataHora();
                    }

                    if (empty($colunaLastUpdated) && !array_key_exists('middleware_last_updated', $colunas)) {
                        $colunas['middleware_last_updated'] = $this->syntax->obterTipoDataHora();
                    }

                    if (!array_key_exists('hash_versao', $colunas)) {
                        $colunas['hash_versao'] = 'VARCHAR(32)';
                    }

                    if (!empty($pks)) {
                        $pks = array_values(array_unique($pks));
                    }

                    // 4. Executa a criação física da tabela delegando totalmente para a Strategy ativa
                    $sqlCriarTabela = $this->syntax->obterDdlCriarTabela($schemaModerno, $tabelaModerna, $colunas, $pks);
                    
                    $stmtTable = $this->destino->query($sqlCriarTabela);
                    if ($stmtTable) {
                        $stmtTable->closeCursor();
                    }

                    // Registra sucesso nos detalhes do relatório do pipeline
                    if (isset($relatorio['detalhes'])) {
                        $relatorio['total_processado']++;
                        $relatorio['detalhes'][] = [
                            'banco'  => $bancoModerno,
                            'tabela' => "{$schemaModerno}.{$tabelaModerna}",
                            'status' => 'SUCESSO'
                        ];
                    }

                } catch (Exception $e) {
                    // Evita quebra caso a tabela já exista no ambiente moderno
                    $msgErro = $e->getMessage();
                    $tabelaJaExiste = (strpos($msgErro, 'already') !== false || strpos($msgErro, 'exist') !== false);

                    if (!$tabelaJaExiste) {
                        if (isset($relatorio['detalhes'])) {
                            $relatorio['status'] = 'FALHA_PARCIAL';
                            $relatorio['detalhes'][] = [
                                'banco'  => $bancoModerno,
                                'tabela' => "{$schemaModerno}.{$tabelaModerna}",
                                'status' => 'ERRO',
                                'motivo' => $msgErro
                            ];
                        } else {
                            throw new Exception("Falha ao criar tabela de negócio {$tabelaModerna}: " . $msgErro);
                        }
                    }
                }
            }
        }
    }
}