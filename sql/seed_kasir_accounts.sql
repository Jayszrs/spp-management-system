INSERT INTO `admin` (`username`, `password`, `nama`, `role`) VALUES
('kasir1', MD5('kasir123'), 'Kasir Loket 1', 'kasir'),
('kasir2', MD5('kasir123'), 'Kasir Loket 2', 'kasir'),
('kasir3', MD5('kasir123'), 'Kasir Loket 3', 'kasir'),
('kasir4', MD5('kasir123'), 'Kasir Loket 4', 'kasir')
ON DUPLICATE KEY UPDATE `nama`=VALUES(`nama`), `role`=VALUES(`role`);
