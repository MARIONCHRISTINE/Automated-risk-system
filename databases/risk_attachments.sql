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
