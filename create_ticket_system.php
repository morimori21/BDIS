<?php
require_once 'includes/config.php';

echo "=== Creating Support Ticket System ===\n\n";

try {
    // Create support_tickets table
    echo "1. Creating support_tickets table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS support_tickets (
        ticket_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        request_id INT NULL,
        subject VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
        status ENUM('open', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
        assigned_to INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP NULL,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (request_id) REFERENCES document_requests(request_id) ON DELETE SET NULL,
        FOREIGN KEY (assigned_to) REFERENCES users(user_id) ON DELETE SET NULL
    )";
    
    $pdo->exec($sql);
    echo "   ✅ support_tickets table created successfully!\n";
    
    // Create ticket_responses table for conversation history
    echo "\n2. Creating ticket_responses table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS ticket_responses (
        response_id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        is_internal BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES support_tickets(ticket_id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    )";
    
    $pdo->exec($sql);
    echo "   ✅ ticket_responses table created successfully!\n";
    
    echo "\n3. Database schema created:\n";
    echo "   - support_tickets: Main ticket information\n";
    echo "   - ticket_responses: Conversation history and responses\n";
    
    echo "\n✅ Support ticket system database ready!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>