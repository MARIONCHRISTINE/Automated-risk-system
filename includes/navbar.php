<?php
// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);
?>

<nav class="navbar">
    <div class="navbar-container">
        <div class="navbar-menu">
            <a href="risk_owner_dashboard.php" class="navbar-item <?php echo ($current_page == 'risk_owner_dashboard.php') ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="report_risk.php" class="navbar-item <?php echo ($current_page == 'report_risk.php') ? 'active' : ''; ?>">
                <i class="fas fa-edit"></i>
                <span>Report Risk</span>
            </a>
            <a href="my_reports.php" class="navbar-item <?php echo ($current_page == 'my_reports.php') ? 'active' : ''; ?>">
                <i class="fas fa-eye"></i>
                <span>My Reports</span>
            </a>
            <a href="procedures.php" class="navbar-item <?php echo ($current_page == 'procedures.php') ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-list"></i>
                <span>Procedures</span>
            </a>
        </div>
    </div>
</nav>

<style>
.navbar {
    position: fixed;
    top: 100px;
    left: 0;
    right: 0;
    background: white;
    border-bottom: 2px solid #E60012;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    z-index: 999;
    padding: 0;
}

.navbar-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 2rem;
}

.navbar-menu {
    display: flex;
    align-items: center;
    gap: 0;
}

.navbar-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem 1.5rem;
    color: #666;
    text-decoration: none;
    font-weight: 500;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    border-bottom: 3px solid transparent;
    position: relative;
}

.navbar-item:hover {
    color: #E60012;
    background: rgba(230, 0, 18, 0.05);
    border-bottom-color: #E60012;
}

.navbar-item.active {
    color: #E60012;
    background: rgba(230, 0, 18, 0.1);
    border-bottom-color: #E60012;
    font-weight: 600;
}

.navbar-item i {
    font-size: 1.1rem;
    width: 20px;
    text-align: center;
}

.navbar-item span {
    white-space: nowrap;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .navbar {
        top: 120px;
    }
    
    .navbar-container {
        padding: 0 1rem;
    }
    
    .navbar-menu {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    
    .navbar-menu::-webkit-scrollbar {
        display: none;
    }
    
    .navbar-item {
        padding: 0.75rem 1rem;
        font-size: 0.9rem;
        flex-shrink: 0;
    }
    
    .navbar-item span {
        display: none;
    }
    
    .navbar-item i {
        font-size: 1.2rem;
    }
}

@media (max-width: 480px) {
    .navbar-item {
        padding: 0.75rem 0.75rem;
    }
}
</style>
