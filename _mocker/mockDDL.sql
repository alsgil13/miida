-- 1. Tabela SEM a coluna de última atualização
CREATE TABLE tb_clientes_sem_update_1 (
        id INT NOT NULL,
        cpf_cnpj VARCHAR(18) NOT NULL,
        nome VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        status_cadastro VARCHAR(20) NOT NULL,
        valor_credito DECIMAL(10, 2) NOT NULL,
        CONSTRAINT pk_tb_clientes_sem_update PRIMARY KEY (id)
    );

-- 2. Tabela COM a coluna de última atualização
CREATE TABLE tb_clientes_com_update_1 (
    id INT NOT NULL,
    cpf_cnpj VARCHAR(18) NOT NULL,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    status_cadastro VARCHAR(20) NOT NULL,
    valor_credito DECIMAL(10, 2) NOT NULL,
    last_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, -- Diferença de 1 coluna
    CONSTRAINT pk_tb_clientes_com_update PRIMARY KEY (id)
);


-- 1. Tabela SEM a coluna de última atualização
CREATE TABLE tb_clientes_sem_update_2 (
        id INT NOT NULL,
        cpf_cnpj VARCHAR(18) NOT NULL,
        nome VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        status_cadastro VARCHAR(20) NOT NULL,
        valor_credito DECIMAL(10, 2) NOT NULL,
        CONSTRAINT pk_tb_clientes_sem_update PRIMARY KEY (id)
    );

-- 2. Tabela COM a coluna de última atualização
CREATE TABLE tb_clientes_com_update_2 (
    id INT NOT NULL,
    cpf_cnpj VARCHAR(18) NOT NULL,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    status_cadastro VARCHAR(20) NOT NULL,
    valor_credito DECIMAL(10, 2) NOT NULL,
    last_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, -- Diferença de 1 coluna
    CONSTRAINT pk_tb_clientes_com_update PRIMARY KEY (id)
);

-- 1. Tabela SEM a coluna de última atualização
CREATE TABLE tb_clientes_sem_update_3 (
        id INT NOT NULL,
        cpf_cnpj VARCHAR(18) NOT NULL,
        nome VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        status_cadastro VARCHAR(20) NOT NULL,
        valor_credito DECIMAL(10, 2) NOT NULL,
        CONSTRAINT pk_tb_clientes_sem_update PRIMARY KEY (id)
    );

-- 2. Tabela COM a coluna de última atualização
CREATE TABLE tb_clientes_com_update_3 (
    id INT NOT NULL,
    cpf_cnpj VARCHAR(18) NOT NULL,
    nome VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    status_cadastro VARCHAR(20) NOT NULL,
    valor_credito DECIMAL(10, 2) NOT NULL,
    last_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, -- Diferença de 1 coluna
    CONSTRAINT pk_tb_clientes_com_update PRIMARY KEY (id)
);