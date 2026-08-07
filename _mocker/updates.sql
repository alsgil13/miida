UPDATE tb_clientes_com_update_1 SET valor_credito = 1.99, last_updated = CURRENT_TIMESTAMP
WHERE id % 2 <> 0;

UPDATE tb_clientes_sem_update_1 SET valor_credito = 1.99, last_updated = CURRENT_TIMESTAMP 
WHERE id % 2 <> 0;

---------------------------------------
UPDATE tb_clientes_com_update_2 SET valor_credito = 1.99, last_updated = CURRENT_TIMESTAMP
WHERE id % 2 <> 0;

UPDATE tb_clientes_sem_update_2 SET valor_credito = 1.99, last_updated = CURRENT_TIMESTAMP 
WHERE id % 2 <> 0;

---------------------------------------
UPDATE tb_clientes_com_update_3 SET valor_credito = 1.99, last_updated = CURRENT_TIMESTAMP
WHERE id % 2 <> 0;

UPDATE tb_clientes_sem_update_3 SET valor_credito = 1.99, last_updated = CURRENT_TIMESTAMP 
WHERE id % 2 <> 0;