<?php

/**
 * MIIDA - Autoloader Nativo Padrão PSR-4
 * Mapeia o Namespace 'Miida\\' para a pasta 'src/' de forma dinâmica
 */
spl_autoload_register(function ($classe) {
    // Prefixo do namespace do seu projeto
    $prefixo = 'Miida\\';
    
    // Diretório base onde as classes estão guardadas
    $diretorioBase = __DIR__ . '/src/';

    // Verifica se a classe chamada usa o prefixo do nosso namespace
    $tamanhoPrefixo = strlen($prefixo);
    if (strncmp($prefixo, $classe, $tamanhoPrefixo) !== 0) {
        // Se não for do MIIDA, passa para o próximo autoloader (se houver)
        return;
    }

    // Pega o nome relativo da classe (ex: Engine\DataSyncProcessor)
    $classeRelativa = substr($classe, $tamanhoPrefixo);

    // Substitui as barras invertidas (namespace) pelas barras do sistema de arquivos e adiciona .php
    $arquivo = $diretorioBase . str_replace('\\', '/', $classeRelativa) . '.php';

    // Se o arquivo físico existir, inclui ele automaticamente
    if (file_exists($arquivo)) {
        require_once $arquivo;
    }
});