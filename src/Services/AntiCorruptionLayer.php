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
        $mapeamento = $configAcl['mapeamento_colunas'] ?? [];
        $sanitizacao = $configAcl['sanitizacao'] ?? [];

        foreach ($mapeamento as $colunaOrigem => $propsTarget) {
            $nomeDestino = $propsTarget['nome_destino'] ?? $colunaOrigem;
            
            // Recupera o valor bruto vindo do SGBD legado
            $valor = $dadosBrutos[$colunaOrigem] ?? null;

            if ($valor !== null) {
                // Aplica politicas declarativas de higienizacao de strings se ativo
                if (!empty($sanitizacao['remover_espacos_excesso']) && is_string($valor)) {
                    $valor = trim(preg_replace('/\s+/', ' ', $valor));
                }

                if (!empty($sanitizacao['forcar_utf8']) && is_string($valor)) {
                    $valor = mb_convert_encoding($valor, 'UTF-8', 'UTF-8');
                }
            } else {
                // --- PROTEÇÃO CIRÚRGICA CONTRA VALORES NULOS EM COLUNAS OBRIGATÓRIAS ---
                // Se a coluna destino for um CPF/Documento obrigatório e veio NULL, 
                // preenchemos com string vazia para evitar quebras no INSERT.
                if ($nomeDestino === 'documento_cpf') {
                    $valor = ''; // Ou '00000000000' dependendo da regra de negocio
                }
            }

            $dadosLimpos[$nomeDestino] = $valor;
        }

        return $dadosLimpos;
    }

    /**
     * Metodo Curinga (Alias/Apelido) para resolver a chamada do Core Engine
     */
    public static function processar(array $dadosBrutos, array $configAcl): array
    {
        return self::higienizar($dadosBrutos, $configAcl);
    }
}