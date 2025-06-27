-- Create database
CREATE DATABASE IF NOT EXISTS risk_management_system;
USE risk_management_system;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    role ENUM('staff', 'risk_owner', 'compliance', 'admin') NOT NULL,
    department VARCHAR(100),
    status ENUM('pending', 'approved', 'suspended') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Risk categories table
CREATE TABLE risk_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Risk incidents table
CREATE TABLE risk_incidents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    risk_name VARCHAR(255) NOT NULL,
    risk_description TEXT NOT NULL,
    cause_of_risk TEXT NOT NULL,
    department VARCHAR(100) NOT NULL,
    category_id INT,
    reported_by INT NOT NULL,
    risk_owner_id INT,
    probability DECIMAL(3,2) DEFAULT 0.00,
    impact DECIMAL(3,2) DEFAULT 0.00,
    risk_score DECIMAL(5,2) DEFAULT 0.00,
    risk_level ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Low',
    status ENUM('Open', 'In Progress', 'Mitigated', 'Closed') DEFAULT 'Open',
    mitigation_plan TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (reported_by) REFERENCES users(id),
    FOREIGN KEY (risk_owner_id) REFERENCES users(id),
    FOREIGN KEY (category_id) REFERENCES risk_categories(id)
);

-- Risk documents table
CREATE TABLE risk_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    risk_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    uploaded_by INT NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (risk_id) REFERENCES risk_incidents(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
);

-- Risk comments table
CREATE TABLE risk_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    risk_id INT NOT NULL,
    user_id INT NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (risk_id) REFERENCES risk_incidents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Chat messages table
CREATE TABLE chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Insert default admin user
INSERT INTO users (email, password, full_name, role, status) 
VALUES ('admin@airtel.africa', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin', 'approved');

-- Insert default risk categories
INSERT INTO risk_categories (category_name, description) VALUES
('Operational Risk', 'Risks related to day-to-day operations'),
('Financial Risk', 'Risks related to financial transactions and processes'),
('Compliance Risk', 'Risks related to regulatory compliance'),
('Technology Risk', 'Risks related to IT systems and technology'),
('Strategic Risk', 'Risks related to business strategy and planning');

-- Add password reset tokens table
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
);
