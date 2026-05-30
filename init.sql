-- 冰箱库存管理系统 · 数据库初始化脚本
-- 使用方法：在 phpMyAdmin 中选中 fridge_manager 数据库后执行

CREATE DATABASE IF NOT EXISTS `fridge_manager` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `fridge_manager`;

-- 1. 冰箱表
CREATE TABLE IF NOT EXISTS `fridges` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `location` VARCHAR(255) DEFAULT '',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. 分类表（预置数据）
CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL,
    `icon` VARCHAR(20) DEFAULT '',
    `sort_order` INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `categories` (`name`, `icon`, `sort_order`) VALUES
('蔬菜', '🥬', 1),
('肉类', '🥩', 2),
('水果', '🍎', 3),
('乳制品', '🥛', 4),
('饮料', '🥤', 5),
('调料', '🧂', 6),
('速食', '🍜', 7),
('其他', '📦', 8);

-- 3. 物品表
CREATE TABLE IF NOT EXISTS `items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `category_id` INT NOT NULL,
    `fridge_id` INT NOT NULL,
    `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1,
    `unit` VARCHAR(20) DEFAULT '个',
    `production_date` DATE DEFAULT NULL,
    `shelf_life_value` INT DEFAULT NULL,
    `shelf_life_unit` ENUM('day','month','year') DEFAULT NULL,
    `storage_type` ENUM('cold','frozen') DEFAULT NULL,
    `added_date` DATE NOT NULL,
    `notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`),
    FOREIGN KEY (`fridge_id`) REFERENCES `fridges`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. 转移记录表
CREATE TABLE IF NOT EXISTS `transfer_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT NOT NULL,
    `from_fridge_id` INT NOT NULL,
    `to_fridge_id` INT NOT NULL,
    `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1,
    `transfer_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `notes` TEXT,
    FOREIGN KEY (`item_id`) REFERENCES `items`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`from_fridge_id`) REFERENCES `fridges`(`id`),
    FOREIGN KEY (`to_fridge_id`) REFERENCES `fridges`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
