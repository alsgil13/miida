<?php

namespace Miida\Services;

class AntiCorruptionLayer
{
    /**
     * Processa e higieniza uma única linha (registro) vinda do banco legado,
     * transformando-a no formato aceito pelo nó moderno.
     *
     * @param array $linhaBruta Registro retornado do SQL Server 2005
     * @param array $configTabela Sub-nó do JSON contendo as regras da tabela atual
     * @return array Registro higienizado pronto para inserção/comparação
     */
    public function processarLinha(array $linhaBruta, array $configTabela): array
    {
        $configAcl = $configTabela['camada_anticorrupcao'];
        $mapeamento = $configAcl['mapeamento_colunas'];
        $sanitizacao = $configAcl['sanitizacao'] ?? [];

        $removerEspacos = $sanitizacao['remover_espacos_excesso'] ?? false;
        $forcarUtf8 = $sanitizacao['forcar_utf8'] ?? false;

        $linhaTratada = [];
        $valoresParaHash = [];

        // 1. Varre o mapeamento declarativo do JSON para traduzir e higienizar
        foreach ($mapeamento as $colunaLegada => $detalhes) {
            $nomeDestino = $detalhes['nome_destino'] ?? $colunaLegada;
            
            // Se a coluna não existir no retorno do banco por algum motivo, inicializa como nulo
            $valor = $linhaBruta[$colunaLegada] ?? null;

            if ($valor !== null) {
                // Aplica regras de higienização de string baseadas no JSON
                if (is_string($valor)) {
                    if ($removerEspacos) {
                        $valor = trim($valor);
                    }
                    if ($forcarUtf8) {
                        // Converte de CP1252/ISO-8859-1 para UTF-8 caso venha corrompido do SQL 2005
                        $valor = mb_convert_encoding($valor, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
                    }
                }
                
                // Coerção Estrita de Tipos (Type Casting) baseado no JSON para blindar o SQL 2022
                $tipoLower = strtolower($detalhes['tipo']);
                if (strpos($tipoLower, 'int') !== false) {
                    $valor = (int)$valor;
                } elseif (strpos($tipoLower, 'float') !== false || strpos($tipoLower, 'decimal') !== false) {
                    $valueLower = strtolower((string)$valor);
                    $valor = (float)$valor;
                } elseif ($tipoLower === 'bit') {
                    $valor = (bool)$valor ? 1 : 0;
                }
            }

            // Aloca o valor higienizado na chave com o novo nome definido no CQRS
            $linhaTratada[$nomeDestino] = $valor;

            // Armazena uma string estável para o cálculo do hash (ignora chaves primárias ou timestamp automático)
            $isPk = $detalhes['pk'] ?? false;
            if (!$isPk) {
                $valoresParaHash[] = $nomeDestino . '=' . (is_bool($valor) ? (int)$valor : $valor);
            }
        }

        // Calcula o Hash de Versão MD5 (Coração da detecção de alterações do MIIDA)
        // Ordena os campos para garantir que a assinatura seja sempre idêntica se os dados forem iguais
        sort($valoresParaHash);
        $linhaTratada['hash_versao'] = md5(implode('|', $valoresParaHash));

        return $linhaTratada;
    }
}