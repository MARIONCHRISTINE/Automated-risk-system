<?php
// Auto-assignment functions for distributing risks to risk owners

function getNextRiskOwner($department, $db) {
    // Get all risk owners in the department with 'approved' status
    // Using LOWER() for case-insensitive department comparison
    $query = "SELECT id FROM users WHERE role = 'risk_owner' AND status = 'approved' AND LOWER(department) = LOWER(:department) ORDER BY id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':department', $department);
    $stmt->execute();
    $risk_owners = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($risk_owners)) {
        return null; // No risk owners in this department
    }

    // Get the last assigned risk owner for this department
    $query = "SELECT last_assigned_owner_id FROM department_assignment_tracker WHERE department = :department";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':department', $department);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $last_assigned_id = $result ? $result['last_assigned_owner_id'] : null;

    // Find the next risk owner in round-robin fashion
    if ($last_assigned_id === null) {
        // First assignment for this department
        $next_owner_id = $risk_owners[0];
    } else {
        // Find current position and get next
        $current_index = array_search($last_assigned_id, $risk_owners);
        if ($current_index === false) {
            // Last assigned owner no longer exists, start from beginning
            $next_owner_id = $risk_owners[0];
        } else {
            // Get next owner (loop back to start if at end)
            $next_index = ($current_index + 1) % count($risk_owners);
            $next_owner_id = $risk_owners[$next_index];
        }
    }

    // Update the tracker
    updateAssignmentTracker($department, $next_owner_id, $db);

    return $next_owner_id;
}

function updateAssignmentTracker($department, $owner_id, $db) {
    // Check if tracker exists for this department
    $query = "SELECT id FROM department_assignment_tracker WHERE department = :department";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':department', $department);
    $stmt->execute();

    if ($stmt->fetch()) {
        // Update existing tracker
        $query = "UPDATE department_assignment_tracker
                  SET last_assigned_owner_id = :owner_id,
                      last_assignment_date = NOW(),
                      total_assignments = total_assignments + 1
                  WHERE department = :department";
    } else {
        // Create new tracker
        $query = "INSERT INTO department_assignment_tracker
                  (department, last_assigned_owner_id, last_assignment_date, total_assignments)
                  VALUES (:department, :owner_id, NOW(), 1)";
    }

    $stmt = $db->prepare($query);
    $stmt->bindParam(':department', $department);
    $stmt->bindParam(':owner_id', $owner_id);
    $stmt->execute();
}

function assignRiskAutomatically($risk_id, $reported_by_user_id, $db) {
    // Get the department of the reporting staff member
    $user_dept_query = "SELECT department, assigned_risk_owner_id FROM users WHERE id = :user_id";
    $user_dept_stmt = $db->prepare($user_dept_query);
    $user_dept_stmt->bindParam(':user_id', $reported_by_user_id);
    $user_dept_stmt->execute();
    $user_info = $user_dept_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user_info || empty($user_info['department'])) {
        return [
            'success' => false,
            'message' => 'Reporting user or their department not found.'
        ];
    }

    $department = $user_info['department'];
    $designated_risk_owner_id = $user_info['assigned_risk_owner_id'];

    $owner_to_assign_id = null;

    if ($designated_risk_owner_id) {
        // Check if the designated risk owner is approved and in the same department
        // Changed status from 'active' to 'approved'
        $check_designated_query = "SELECT id, full_name, email FROM users WHERE id = :owner_id AND role = 'risk_owner' AND status = 'approved' AND LOWER(department) = LOWER(:department)";
        $check_designated_stmt = $db->prepare($check_designated_query);
        $check_designated_stmt->bindParam(':owner_id', $designated_risk_owner_id);
        $check_designated_stmt->bindParam(':department', $department);
        $check_designated_stmt->execute();
        $designated_owner = $check_designated_stmt->fetch(PDO::FETCH_ASSOC);

        if ($designated_owner) {
            $owner_to_assign_id = $designated_owner['id'];
        }
    }

    // If no designated owner or designated owner is not valid/approved, fall back to round-robin within the department
    if (!$owner_to_assign_id) {
        $owner_to_assign_id = getNextRiskOwner($department, $db);
    }

    if ($owner_to_assign_id) {
        // Assign the risk to the selected owner
        $query = "UPDATE risk_incidents SET risk_owner_id = :owner_id WHERE id = :risk_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':owner_id', $owner_to_assign_id);
        $stmt->bindParam(':risk_id', $risk_id);
        $stmt->execute();

        // Get owner details for notification
        $query = "SELECT full_name, email FROM users WHERE id = :owner_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':owner_id', $owner_to_assign_id);
        $stmt->execute();
        $owner = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'owner_id' => $owner_to_assign_id,
            'owner_name' => $owner['full_name'],
            'owner_email' => $owner['email']
        ];
    }

    return [
        'success' => false,
        'message' => 'No risk owners available in ' . $department . ' department for assignment.'
    ];
}

function getDepartmentAssignmentStats($department, $db) {
    // Get assignment statistics for the department
    // Changed status from 'active' to 'approved'
    $query = "SELECT
                u.id,
                u.full_name,
                u.email,
                COUNT(ri.id) as assigned_risks,
                COUNT(CASE WHEN ri.risk_status = 'completed' THEN 1 END) as completed_risks
              FROM users u
              LEFT JOIN risk_incidents ri ON u.id = ri.risk_owner_id
              WHERE u.role = 'risk_owner' AND u.department = :department AND u.status = 'approved'
              GROUP BY u.id, u.full_name, u.email
              ORDER BY assigned_risks ASC, u.full_name";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':department', $department);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
