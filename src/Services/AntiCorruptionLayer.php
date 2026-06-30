<?php

namespace Miida\Services;

/**
 * MIIDA - AntiCorruptionLayer (ACL)
 * Camada de Higienizacao, Sanitizacao e Conformidade de Dados Multi-SGBD
 */
class AntiCorruptionLayer
{
    /**
     * Metodo Principal de Sanitizacao e Higienizacao dos Dados
     */
    public static function higienizar(array $dadosBrutos, array $configAcl): array
    {
        $dadosLimpos = [];
        
        // Resolve o aninhamento correto do bloco de configuracao vindo do JSON
        $configReal = $configAcl['camada_anticorrupcao'] ?? $configAcl;
        $mapeamento = $configReal['mapeamento_colunas'] ?? [];
        $sanitizacao = $configReal['sanitizacao'] ?? [];

        foreach ($mapeamento as $colunaOrigem => $propsTarget) {
            $nomeDestino = $propsTarget['nome_destino'] ?? $colunaOrigem;
            
            // Busca insensivel a maiusculas/minusculas no array vindo do banco legado
            $valor = null;
            $encontrado = false;
            foreach ($dadosBrutos as $chaveBruta => $valBruto) {
                if (strcasecmp((string)$chaveBruta, (string)$colunaOrigem) === 0) {
                    $valor = $valBruto;
                    $encontrado = true;
                    break;
                }
            }

            // Fallback se nao achou no loop de comparacao de strings
            if (!$encontrado) {
                $valor = $dadosBrutos[$colunaOrigem] ?? null;
            }

            if ($valor !== null) {
                // Aplica politicas declarativas de higienizacao de strings se ativo
                if (!empty($sanitizacao['remover_espacos_excesso']) && is_string($valor)) {
                    $valor = trim(preg_replace('/\s+/', ' ', $valor));
                }

                if (!empty($sanitizacao['forcar_utf8']) && is_string($valor)) {
                    $valor = mb_convert_encoding($valor, 'UTF-8', 'UTF-8');
                }
            } else {
                // BLINDAGEM CONTRA NOT NULL: Se o dado veio nulo da origem (como o CPF do usuario 3),
                // trata dinamicamente baseado no tipo esperado para nao quebrar as constraints do SQL Server
                $tipo = strtoupper($propsTarget['tipo'] ?? $propsTarget['tipo_destino'] ?? 'VARCHAR');
                if (strpos($tipo, 'INT') !== false || strpos($tipo, 'NUMERIC') !== false || strpos($tipo, 'DECIMAL') !== false) {
                    $valor = 0;
                } else {
                    $valor = ''; // Substitui o NULL por uma string vazia segura
                }
            }

            $dadosLimpos[$nomeDestino] = $valor;
        }

        // -------------------------------------------------------------------------
        // INJEÇÃO AUTOMÁTICA DA COLUNA DE TIMESTAMPS DE AUDITORIA (MIDDLEWARE)
        // -------------------------------------------------------------------------
        $colunaAudit = $configAcl['coluna_last_updated'] ?? null;
        if (!empty($colunaAudit)) {
            $dadosLimpos[$colunaAudit] = date('Y-m-d H:i:s');
        }

        // -------------------------------------------------------------------------
        // INJEÇÃO DINÂMICA DO HASH DE VERSÃO DA ACL
        // -------------------------------------------------------------------------
        $dadosLimpos['hash_versao'] = md5(json_encode($dadosLimpos));

        return $dadosLimpos;
    }

    public static function processar(array $dadosBrutos, array $configAcl): array
    {
        return self::higienizar($dadosBrutos, $configAcl);
    }
}