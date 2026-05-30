-- 冰箱库存管理系统 · v1.4+ 数据库迁移
-- quantity 字段从 INT 改为 DECIMAL(10,2)，支持小数重量（如 0.5斤、1.25公斤）

ALTER TABLE `items` MODIFY COLUMN `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1;
ALTER TABLE `transfer_logs` MODIFY COLUMN `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1;
