<?php
/**
 * Database Configuration File
 *
 * NOTE: This file has been updated using the Full Control credentials
 * provided in your screenshot, as they are required for modifying the database
 * in admin_lots.php.
 */

// Database connection parameters
define('DB_SERVER', 'cis3870-2504.mysql.database.azure.com');
define('DB_USERNAME', 'f\appstate-cis3870-2504-gomezf\$appstate-cis3870-2504-gomezf');
define('DB_PASSWORD', 'u1hyaYBPoEdtss0L4bjsYKEaxjrsZPzMao1PWHuGv1Wd6L3Dfhn7FHiS7LLl');
define('DB_NAME', 'gomezf_db');

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("ERROR: Could not connect to the database. " . $conn->connect_error);
}
?>