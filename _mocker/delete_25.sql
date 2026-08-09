DELETE FROM tb_clientes_com_update_1
WHERE id % 4 = 0;

DELETE FROM tb_clientes_com_update_2
WHERE id % 4 = 0;

DELETE FROM tb_clientes_com_update_3
WHERE id % 4 = 0;

DELETE FROM tb_clientes_sem_update_1
WHERE id % 4 = 0;

DELETE FROM tb_clientes_sem_update_2
WHERE id % 4 = 0;

DELETE FROM tb_clientes_sem_update_3
WHERE id % 4 = 0;

