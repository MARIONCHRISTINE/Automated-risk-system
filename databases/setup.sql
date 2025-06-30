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


-- Add missing columns to risk_incidents table to match the risk register template
ALTER TABLE risk_incidents 
ADD COLUMN existing_or_new ENUM('Existing', 'New') DEFAULT 'New',
ADD COLUMN to_be_reported_to_board ENUM('Yes', 'No') DEFAULT 'No',
ADD COLUMN inherent_likelihood DECIMAL(3,2) DEFAULT 0.00,
ADD COLUMN inherent_consequence DECIMAL(3,2) DEFAULT 0.00,
ADD COLUMN inherent_rating DECIMAL(5,2) DEFAULT 0.00,
ADD COLUMN residual_likelihood DECIMAL(3,2) DEFAULT 0.00,
ADD COLUMN residual_consequence DECIMAL(3,2) DEFAULT 0.00,
ADD COLUMN residual_rating DECIMAL(5,2) DEFAULT 0.00,
ADD COLUMN treatment_action TEXT,
ADD COLUMN controls_action_plan TEXT,
ADD COLUMN planned_completion_date DATE,
ADD COLUMN progress_update TEXT,
ADD COLUMN risk_status ENUM('Not Started', 'In Progress', 'Completed', 'Overdue') DEFAULT 'Not Started';

-- Create risk assignments table for better tracking
CREATE TABLE IF NOT EXISTS risk_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    risk_id INT NOT NULL,
    assigned_to INT NOT NULL,
    assigned_by INT NOT NULL,
    assignment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('Pending', 'Accepted', 'In Progress', 'Completed') DEFAULT 'Pending',
    notes TEXT,
    FOREIGN KEY (risk_id) REFERENCES risk_incidents(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (assigned_by) REFERENCES users(id)
);

-- Check and add missing columns to risk_incidents table (skip existing ones)
-- First, let's see what columns already exist
DESCRIBE risk_incidents;

-- Add only the missing columns (skip existing_or_new if it already exists)
ALTER TABLE risk_incidents 
ADD COLUMN IF NOT EXISTS to_be_reported_to_board ENUM('Yes', 'No') DEFAULT 'No',
ADD COLUMN IF NOT EXISTS inherent_likelihood DECIMAL(3,2) DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS inherent_consequence DECIMAL(3,2) DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS inherent_rating DECIMAL(5,2) DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS residual_likelihood DECIMAL(3,2) DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS residual_consequence DECIMAL(3,2) DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS residual_rating DECIMAL(5,2) DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS treatment_action TEXT,
ADD COLUMN IF NOT EXISTS controls_action_plan TEXT,
ADD COLUMN IF NOT EXISTS planned_completion_date DATE,
ADD COLUMN IF NOT EXISTS progress_update TEXT,
ADD COLUMN IF NOT EXISTS risk_status ENUM('Not Started', 'In Progress', 'Completed', 'Overdue') DEFAULT 'Not Started';

-- Create risk assignments table for better tracking
CREATE TABLE IF NOT EXISTS risk_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    risk_id INT NOT NULL,
    assigned_to INT NOT NULL,
    assigned_by INT NOT NULL,
    assignment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('Pending', 'Accepted', 'In Progress', 'Completed') DEFAULT 'Pending',
    notes TEXT,
    FOREIGN KEY (risk_id) REFERENCES risk_incidents(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (assigned_by) REFERENCES users(id)
);

-- Auto-assign existing risks to risk owners based on department
INSERT IGNORE INTO risk_assignments (risk_id, assigned_to, assigned_by, status)
SELECT 
    r.id as risk_id,
    u.id as assigned_to,
    1 as assigned_by, -- System assignment
    'Pending' as status
FROM risk_incidents r
JOIN users u ON u.department = r.department AND u.role = 'risk_owner'
WHERE r.risk_owner_id IS NULL
AND NOT EXISTS (
    SELECT 1 FROM risk_assignments ra WHERE ra.risk_id = r.id
);

-- Update risk_owner_id for existing risks
UPDATE risk_incidents r
JOIN users u ON u.department = r.department AND u.role = 'risk_owner'
SET r.risk_owner_id = u.id
WHERE r.risk_owner_id IS NULL;







-- Only run this after department column exists and is populated

-- Auto-assign existing risks to risk owners based on department
INSERT IGNORE INTO risk_assignments (risk_id, assigned_to, assigned_by, status)
SELECT 
    r.id as risk_id,
    u.id as assigned_to,
    1 as assigned_by, -- System assignment (assuming admin user ID is 1)
    'Pending' as status
FROM risk_incidents r
JOIN users u ON u.department = r.department AND u.role = 'risk_owner'
WHERE r.risk_owner_id IS NULL
AND r.department IS NOT NULL
AND NOT EXISTS (
    SELECT 1 FROM risk_assignments ra WHERE ra.risk_id = r.id
);

-- Update risk_owner_id for existing risks
UPDATE risk_incidents r
JOIN users u ON u.department = r.department AND u.role = 'risk_owner'
SET r.risk_owner_id = u.id
WHERE r.risk_owner_id IS NULL AND r.department IS NOT NULL;

-- Show assignment results
SELECT 
    r.id,
    r.risk_name,
    r.department,
    u.full_name as risk_owner,
    ra.status as assignment_status
FROM risk_incidents r
LEFT JOIN users u ON r.risk_owner_id = u.id
LEFT JOIN risk_assignments ra ON r.id = ra.risk_id
ORDER BY r.created_at DESC
LIMIT 10;





-- Create risk assignments table separately
CREATE TABLE IF NOT EXISTS risk_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    risk_id INT NOT NULL,
    assigned_to INT NOT NULL,
    assigned_by INT NOT NULL,
    assignment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('Pending', 'Accepted', 'In Progress', 'Completed') DEFAULT 'Pending',
    notes TEXT,
    FOREIGN KEY (risk_id) REFERENCES risk_incidents(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (assigned_by) REFERENCES users(id)
);

-- Show the table was created
DESCRIBE risk_assignments;
