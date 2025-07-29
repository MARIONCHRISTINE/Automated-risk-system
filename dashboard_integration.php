<?php
// Add this to your existing risk_owner_dashboard.php file

// Add these buttons to your dashboard interface
?>

<div class="audit-security-section" style="margin: 2rem 0;">
    <div class="section-header">
        <h3>Security & Audit Tools</h3>
        <p>Monitor system activities and security events</p>
    </div>
    
    <div class="audit-buttons" style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1rem;">
        <a href="audit_dashboard.php" class="btn btn-export" style="background: #17a2b8; color: white; padding: 0.75rem 1.5rem; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem;">
            <i class="fas fa-download"></i> Export Audit Logs
        </a>
        
        <button class="btn btn-suspicious" onclick="window.open('audit_dashboard.php', '_blank')" style="background: #ffc107; color: #212529; padding: 0.75rem 1.5rem; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;">
            <i class="fas fa-exclamation-triangle"></i> Highlight Suspicious Activities
        </button>
        
        <button class="btn btn-history" onclick="window.open('audit_dashboard.php', '_blank')" style="background: #007bff; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;">
            <i class="fas fa-history"></i> View Login History / IP Tracking
        </button>
    </div>
</div>

<style>
.audit-security-section {
    background: white;
    padding: 2rem;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin: 2rem 0;
}

.section-header h3 {
    color: #E60012;
    margin-bottom: 0.5rem;
}

.section-header p {
    color: #666;
    margin-bottom: 0;
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

@media (max-width: 768px) {
    .audit-buttons {
        flex-direction: column;
    }
    
    .btn {
        justify-content: center;
    }
}
</style>
