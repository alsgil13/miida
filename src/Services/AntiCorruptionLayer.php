<?php

namespace Miida\Services;

class AntiCorruptionLayer
{
    /**
     * Processa e higieniza uma única linha (registro) vinda do banco legado,
     * transformando-a no formato aceito pelo nó moderno.
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
            
            // Se a coluna não existir no retorno bruto do legado, define como nula defensivamente
            $valor = $linhaBruta[$colunaLegada] ?? null;

            if ($valor !== null) {
                // Higienização de strings contra espaços indesejados
                if ($removerEspacos && is_string($valor)) {
                    $valor = trim(preg_replace('/\s+/', ' ', $valor));
                }

                // Conversão forçada e segura para UTF-8 de bases antigas
                if ($forcarUtf8 && is_string($valor)) {
                    if (!mb_check_encoding($valor, 'UTF-8')) {
                        $valor = mb_convert_encoding($valor, 'UTF-8', 'ISO-8859-1');
                    }
                }
                
                // Coerção Estrita de Tipos baseada no JSON para blindar o SGBD moderno
                $tipoLower = strtolower($detalhes['tipo']);
                if (strpos($tipoLower, 'int') !== false) {
                    $valor = (int)$valor;
                } elseif (strpos($tipoLower, 'float') !== false || strpos($tipoLower, 'decimal') !== false) {
                    $valor = (float)$valor;
                } elseif ($tipoLower === 'bit' || $tipoLower === 'bool' || $tipoLower === 'boolean') { 
                    // EVOLUÇÃO MULTI-SGBD: Passamos a retornar booleano real do PHP.
                    // O PDO se encarrega de mapear para true/false no Postgres e 1/0 no MySQL/SQL Server.
                    $valor = filter_var($valor, FILTER_VALIDATE_BOOLEAN);
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
        sort($valoresParaHash);
        $linhaTratada['hash_versao'] = md5(implode(';', $valoresParaHash));

        return $linhaTratada;
    }
}