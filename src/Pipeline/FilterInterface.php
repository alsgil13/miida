<?php
namespace Miida\Pipeline;

interface FilterInterface
{
    // Método padronizado de execução do filtro
    public function process(array $config): mixed;
}