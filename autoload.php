<?php


date_default_timezone_set('America/Sao_Paulo');

// --- 1. CARREGAMENTO DO ARQUIVO .env NA INICIALIZAÇÃO ---
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $linhas = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($linhas as $linha) {
        $linha = trim($linha);
        // Ignora linhas vazias ou comentários
        if (empty($linha) || strpos($linha, '#') === 0) continue; 
        
        // Divide a linha apenas no primeiro sinal de '='
        if (strpos($linha, '=') !== false) {
            list($nome, $valor) = explode('=', $linha, 2);
            $nome = trim($nome);
            $valor = trim($valor, " \t\n\r\0\x0B\"'"); // Remove aspas extras se houver
            putenv("{$nome}={$valor}");
            $_ENV[$nome] = $valor;
            $_SERVER[$nome] = $valor;
        }
    }
}

// --- 2. FUNÇÃO HELPER DE CONFIGURAÇÃO PIPELINE (RESOLVE AS VARIÁVEIS ENV) ---
if (!function_exists('carregarConfiguracaoPipeline')) {
    /**
     * Carrega o JSON de pipeline e desmascara automaticamente as variáveis env:
     */
    function carregarConfiguracaoPipeline(?string $caminhoJson = null): array
    {
        $caminhoJson = $caminhoJson ?? __DIR__ . '/config/pipeline_config.json';
        
        if (!file_exists($caminhoJson)) {
            throw new \RuntimeException("ERRO: Arquivo de configuração [{$caminhoJson}] não encontrado.");
        }

        $config = json_decode(file_get_contents($caminhoJson), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("ERRO: O JSON de configuração é inválido - " . json_last_error_msg());
        }

        // Função recursiva para desmascarar qualquer 'env:NOME_VAR' dentro de qualquer nível do array
        $desmascarar = function (&$item) use (&$desmascarar) {
            if (is_array($item)) {
                foreach ($item as &$valor) {
                    $desmascarar($valor);
                }
            } elseif (is_string($item) && strpos($item, 'env:') === 0) {
                $varNome = substr($item, 4);
                $item = getenv($varNome) ?: ($_ENV[$varNome] ?? ($_SERVER[$varNome] ?? ''));
            }
        };

        $desmascarar($config);
        return $config;
    }
}

// --- 3. AUTOLOADER NATIVO PADRÃO PSR-4 ---
spl_autoload_register(function ($classe) {
    $prefixo = 'Miida\\';
    $diretorioBase = __DIR__ . '/src/';

    $tamanhoPrefixo = strlen($prefixo);
    if (strncmp($prefixo, $classe, $tamanhoPrefixo) !== 0) {
        return;
    }

    $classeRelativa = substr($classe, $tamanhoPrefixo);
    $arquivo = $diretorioBase . str_replace('\\', '/', $classeRelativa) . '.php';

    if (file_exists($arquivo)) {
        require_once $arquivo;
    }
});