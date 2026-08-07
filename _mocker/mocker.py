import random
from datetime import datetime, timedelta

# ==============================================================================
# CONFIGURAÇÕES E FLAGS
# ==============================================================================
# Configure True para 'tb_clientes_com_update' ou False para 'tb_clientes_sem_update'
COL_LAST_UPDATED = False

# Quantidade de registros a gerar (ex: 100, 1000, 5000)
TOTAL_REGISTROS = 10000

# Nome da tabela de destino
NOME_TABELA = "tb_clientes_com_update_3" if COL_LAST_UPDATED else "tb_clientes_sem_update_3"

# Lista de dados fictícios para variação nos testes
NOMES = ["Ana", "Bruno", "Carlos", "Daniela", "Eduardo", "Fernanda", "Gabriel", "Helena", "Saverio", "Luciano", "Flavia", "Joao", "Maykon"]
SOBRENOMES = ["Silva", "Santos", "Oliveira", "Souza", "Rodrigues", "Ferreira", "Almeida", "Sabadini"]
STATUS_OPCOES = ["ATIVO", "INATIVO", "PENDENTE", "BLOQUEADO"]

# ==============================================================================
# GERADOR DE SQL INSERTS
# ==============================================================================
def gerar_inserts():
    print(f"-- ======================================================================")
    print(f"-- GERANDO {TOTAL_REGISTROS} INSERTS PARA A TABELA: {NOME_TABELA}")
    print(f"-- FLAG COL_LAST_UPDATED = {COL_LAST_UPDATED}")
    print(f"-- ======================================================================\n")

    data_base = datetime.now()

    for i in range(1, TOTAL_REGISTROS + 1):
        nome_completo = f"{random.choice(NOMES)} {random.choice(SOBRENOMES)}"
        email = f"cliente_{i}@teste.com.br"
        cpf_fake = f"{random.randint(100,999)}.{random.randint(100,999)}.{random.randint(100,999)}-{random.randint(10,99)}"
        status = random.choice(STATUS_OPCOES)
        credito = round(random.uniform(500.0, 50000.0), 2)

        if COL_LAST_UPDATED:
            # Gera datas levemente distintas no passado recente para simular atualizações contínuas
            data_registro = data_base - timedelta(minutes=random.randint(1, 1000))
            data_str = data_registro.strftime("%Y-%m-%d %H:%M:%S")

            sql = (
                f"INSERT INTO {NOME_TABELA} "
                f"(id, cpf_cnpj, nome, email, status_cadastro, valor_credito, last_updated) "
                f"VALUES ({i}, '{cpf_fake}', '{nome_completo}', '{email}', '{status}', {credito}, '{data_str}');"
            )
        else:
            sql = (
                f"INSERT INTO {NOME_TABELA} "
                f"(id, cpf_cnpj, nome, email, status_cadastro, valor_credito) "
                f"VALUES ({i}, '{cpf_fake}', '{nome_completo}', '{email}', '{status}', {credito});"
            )

        print(sql)

if __name__ == "__main__":
    gerar_inserts()