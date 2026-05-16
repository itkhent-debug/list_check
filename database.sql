-- CRM Checklist Database Setup
-- Run this in phpMyAdmin or MySQL command line

CREATE DATABASE IF NOT EXISTS crm_checklist;
USE crm_checklist;

-- Batches table
CREATE TABLE IF NOT EXISTS batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    workflow_name VARCHAR(255) DEFAULT '',
    assigned_to VARCHAR(255) DEFAULT '',
    organization VARCHAR(255) DEFAULT '',
    casino_name VARCHAR(255) DEFAULT '',
    campaign_dates VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Items table
CREATE TABLE IF NOT EXISTS items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    label VARCHAR(100) NOT NULL,
    item_date DATE NOT NULL,
    item_time TIME DEFAULT '10:00:00',
    checked TINYINT(1) DEFAULT 0,
    time_ok TINYINT(1) DEFAULT 0,
    crm_ok TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
);

-- Tags table
CREATE TABLE IF NOT EXISTS tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    color VARCHAR(7) DEFAULT '#3b82f6',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Batch-Tags junction table (many-to-many relationship)
CREATE TABLE IF NOT EXISTS batch_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    tag_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_batch_tag (batch_id, tag_id),
    FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

-- Index for faster queries
CREATE INDEX idx_items_batch ON items(batch_id);
CREATE INDEX idx_items_date ON items(item_date, item_time);
CREATE INDEX idx_batch_tags_batch ON batch_tags(batch_id);
CREATE INDEX idx_batch_tags_tag ON batch_tags(tag_id);

-- Users table (authentication)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME DEFAULT NULL
);

-- Default user (password: 247ga2024)
INSERT IGNORE INTO users (email, name, password_hash) VALUES
('paul.valencia@247ga.co', 'Paul Valencia', '$2y$10$placeholder_run_auth_endpoint_to_seed');
