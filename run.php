<?php
/**
 * MIIDA - Launcher unificado do Ecossistema
 */

echo "=========================================================\n";
echo "          MIIDA - INICIANDO ECOSSISTEMA COMPLETO         \n";
echo "=========================================================\n\n";

// 1. Executa o Provisionador de Infraestrutura
echo "[PASSO 1] Executando Inicializador...\n";
passthru('php 1_inicializar.php');
echo "\n";

// 2. Executa a primeira carga completa (Carga inicial)
echo "[PASSO 2] Executando Sincronização Inicial...\n";
passthru('php 2_sincronizar.php');
echo "\n";

// 3. Passa o controle para o Daemon contínuo
echo "[PASSO 3] Inicializando o Orquestrador em tempo real...\n";
passthru('php 3_orquestrador.php');