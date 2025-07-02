-- Create table for storing risk document information
CREATE TABLE IF NOT EXISTS risk_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    risk_id INT NOT NULL,
    section_type ENUM('controls_action_plans', 'progress_update') NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    uploaded_by INT NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_risk_id (risk_id),
    INDEX idx_section_type (section_type),
    FOREIGN KEY (risk_id) REFERENCES risk_incidents(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
);






CREATE TABLE risk_treatments (
    id INT(11) NOT NULL AUTO_INCREMENT,
    risk_id INT(11) NOT NULL,
    treatment_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
    progress_update TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
    treatment_type ENUM('avoid', 'mitigate', 'transfer', 'accept') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'mitigate',
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'pending',
    assigned_to INT(11) NULL,
    due_date DATE NULL,
    completion_date DATE NULL,
    cost_estimate DECIMAL(15,2) NULL,
    effectiveness_rating INT(11) NULL,
    created_by INT(11) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_risk_id (risk_id),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date),
    INDEX created_by (created_by),
    INDEX idx_risk_treatments_risk_id (risk_id),
    INDEX idx_risk_treatments_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;




to drop the duplicates :
ALTER TABLE risk_treatments
DROP INDEX idx_risk_treatments_risk_id,
DROP INDEX idx_risk_treatments_status;

