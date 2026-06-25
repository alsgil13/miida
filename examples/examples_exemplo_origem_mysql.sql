-- =========================================================
-- MIIDA - Cenário de Testes para o Legado: MYSQL
-- =========================================================

CREATE DATABASE IF NOT EXISTS `erp_legado_db`;
USE `erp_legado_db`;

-- 1. Tabela com Chave Simples
CREATE TABLE IF NOT EXISTS `tb_usuarios` (
    `cod_usuario` INT AUTO_INCREMENT PRIMARY KEY,
    `nome_completo` VARCHAR(150) NOT NULL,
    `documento` VARCHAR(20) NULL,
    `flg_ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `dt_cadastro` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Tabela com Chave Primária Composta
CREATE TABLE IF NOT EXISTS `tb_vendas_itens` (
    `num_venda` INT NOT NULL,
    `seq_item` INT NOT NULL,
    `cod_produto` INT NOT NULL,
    `qtd_vendida` DECIMAL(10,2) NOT NULL,
    `vlr_unitario` DECIMAL(10,2) NOT NULL,
    `dt_alteracao` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`num_venda`, `seq_item`)
) ENGINE=InnoDB;

-- Limpar dados anteriores
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE `tb_vendas_itens`;
TRUNCATE TABLE `tb_usuarios`;
SET FOREIGN_KEY_CHECKS = 1;

-- Inserir dados com espaços em excesso para validar a camada de anticorrupção
INSERT INTO `tb_usuarios` (`nome_completo`, `documento`, `flg_ativo`) VALUES 
('  Joao   Silva Junior  ', '11122233344', 1),
('Maria  Eduarda Santos ', '55566677788', 1),
(' Carlos Alberto Desativado ', NULL, 0);

INSERT INTO `tb_vendas_itens` (`num_venda`, `seq_item`, `cod_produto`, `qtd_vendida`, `vlr_unitario`) VALUES 
(1001, 1, 50, 2.00, 15.50),
(1001, 2, 55, 1.00, 45.00),
(1002, 1, 50, 10.00, 14.00);
