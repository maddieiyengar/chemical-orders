<?php
/**
 * Created by Maddie Iyengar
 * User: iyengmr22
 * Date: 2/20/25
 * Time: 9:01 AM
 */

include("includes/functions.php");

require_once('/DATA/www/phpadmin/webdev_classes/Authenticate.php');
require_once('/DATA/www/phpadmin/webdev_classes/Ldap.php');
require_once('/DATA/www/phpadmin/webdev_classes/UserHandler.php');
require_once('/DATA/www/support/webdev_classes/MssqlDb.php');
require_once('/DATA/www/support/webdev_classes/EmailHandler.php');

// Authenticate user
$username = Authenticate::ensure_logged_in();

// Check user permissions
$ADMIN = UserHandler::has_access($username, 224, USER_ADMIN);
$SUPER = UserHandler::has_access($username, 224, USER_SUPER);
$REG = UserHandler::has_access($username, 224, USER_REGULAR);

// Get admin users for notifications
$adminUsers = UserHandler::get_users_in_group(217);

// Include database configuration
include_once('includes/config.php');

try {
    // Connect to database
    $pdo = new PDO('dblib:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // Validate vendor information
        $vendor = filter_input(INPUT_POST, 'vendor', FILTER_SANITIZE_STRING);
        $vendorName = filter_input(INPUT_POST, 'vendorName', FILTER_SANITIZE_STRING);
        $vendorAddress = filter_input(INPUT_POST, 'vendorAddress', FILTER_SANITIZE_STRING);
        $vendorPhone = filter_input(INPUT_POST, 'vendorPhone', FILTER_SANITIZE_STRING);

        if ($vendor === 'Other' && (empty($vendorName) || empty($vendorAddress) || empty($vendorPhone))) {
            throw new Exception("Additional vendor details required when 'Other' is selected.");
        }

        // Process chemical orders (multiple rows) 
        $catalogNumbers = isset($_POST['catalogNumber']) ? $_POST['catalogNumber'] : []; 
        $quantities = isset($_POST['quantity']) ? $_POST['quantity'] : []; 
        $descriptions = isset($_POST['description']) ? $_POST['description'] : []; 
        $sizes = isset($_POST['size']) ? $_POST['size'] : []; 
        $amountsNeeded = isset($_POST['amountNeeded']) ? $_POST['amountNeeded'] : []; 
        $hazards = isset($_POST['hazards']) ? $_POST['hazards'] : []; 
        $prices = isset($_POST['price']) ? $_POST['price'] : []; 
        $storageRooms = isset($_POST['storageRoom']) ? $_POST['storageRoom'] : []; 
        $websiteLinks = isset($_POST['websiteLink']) ? $_POST['websiteLink'] : [];

        // Validate other required fields
        $account = filter_input(INPUT_POST, 'account', FILTER_SANITIZE_STRING);
        $budgetLine = filter_input(INPUT_POST, 'budgetLine', FILTER_SANITIZE_STRING);
        $labStorage = filter_input(INPUT_POST, 'labStorage', FILTER_SANITIZE_STRING);
        $dateRequested = filter_input(INPUT_POST, 'dateRequested', FILTER_SANITIZE_STRING);
        $shippingWait = filter_input(INPUT_POST, 'shippingWait', FILTER_SANITIZE_STRING);
        $requestedBy = filter_input(INPUT_POST, 'requestedBy', FILTER_SANITIZE_STRING);
        $requestorEmail = filter_input(INPUT_POST, 'requestorEmail', FILTER_SANITIZE_EMAIL);
        $shipToPerson = filter_input(INPUT_POST, 'shipToPerson', FILTER_SANITIZE_STRING);
        $pListedWaste = filter_input(INPUT_POST, 'pListedWaste', FILTER_SANITIZE_STRING);
        $requestorSignature = filter_input(INPUT_POST, 'requestorSignature', FILTER_SANITIZE_STRING);
        $deptChairSignature = filter_input(INPUT_POST, 'deptChairSignature', FILTER_SANITIZE_STRING);
        $deptChairDate = filter_input(INPUT_POST, 'deptChairDate', FILTER_SANITIZE_STRING);

        if (!$account || !$budgetLine || !$labStorage || !$dateRequested || !$requestedBy) {
            throw new Exception("All required fields must be filled out.");
        }

        $orderId = $pdo->lastInsertId();

        // Insert chemical order details
        $sqlDetail = "INSERT INTO chemical_order
        (username, vendor, vendorName, vendorAddress, vendorPhone, account, budgetLine,
           labStorage, dateRequested, shippingWait, requestedBy, requestorEmail, shipToPerson,
           pListedWaste, requestorSignature, deptChairSignature, deptChairDate, orderId, 
           catalogNumber, quantity, description, size, amountNeeded, hazards, price, storageRoom, websiteLink) 
        VALUES 
        (:username, :vendor, :vendorName, :vendorAddress, :vendorPhone, :account, :budgetLine,
           :labStorage, :dateRequested, :shippingWait, :requestedBy, :requestorEmail, :shipToPerson,
           :pListedWaste, :requestorSignature, :deptChairSignature, :deptChairDate, :orderId, 
           :catalogNumber, :quantity, :description, :size, :amountNeeded, :hazards, :price, :storageRoom, :websiteLink)";

        $stmtDetail = $pdo->prepare($sqlDetail);
        // Begin transaction
        $pdo->beginTransaction();
        
        foreach ($catalogNumbers as $i => $catalogNumber) {
            if (!empty($catalogNumber) && !empty($quantities[$i])) {
                $stmtDetail->execute([
                    ':orderId' => $orderId,
                    ':catalogNumber' => $catalogNumber,
                    ':quantity' => $quantities[$i],
                    ':description' => $descriptions[$i],
                    ':size' => $sizes[$i],
                    ':amountNeeded' => $amountsNeeded[$i],
                    ':hazards' => $hazards[$i],
                    ':price' => $prices[$i],
                    ':storageRoom' => $storageRooms[$i],
                    ':websiteLink' => $websiteLinks[$i],
                    ':username' => $username,
                    ':vendor' => $vendor,
                    ':vendorName' => $vendorName,
                    ':vendorAddress' => $vendorAddress,
                    ':vendorPhone' => $vendorPhone,
                    ':account' => $account,
                    ':budgetLine' => $budgetLine,
                    ':labStorage' => $labStorage,
                    ':dateRequested' => $dateRequested,
                    ':shippingWait' => $shippingWait,
                    ':requestedBy' => $requestedBy,
                    ':requestorEmail' => $requestorEmail,
                    ':shipToPerson' => $shipToPerson,
                    ':pListedWaste' => $pListedWaste,
                    ':requestorSignature' => $requestorSignature,
                    ':deptChairSignature' => $deptChairSignature,
                    ':deptChairDate' => $deptChairDate
                    
                ]);
            }
        }

        // Commit transaction
        $pdo->commit();

        /**
         * Builds email message for order notification
         */
        function buildEmailMessage($orderId, $username, $vendor, $requestedBy) {
            $message = "A new chemical order has been submitted.\n\n";
            $message .= "Order ID: $orderId\n";
            $message .= "Submitted by: $username\n";
            $message .= "Requested by: $requestedBy\n";
            $message .= "Vendor: $vendor\n";
            $message .= "\nPlease log in to the system to review the complete order details.";
            return $message;
        }

        // Send email notifications
        $currentDeveloperEmail = 'steelea@juniata.edu';

        $subject = "New Chemical Order Submitted - Order #$orderId";
        $message = buildEmailMessage($orderId, $username, $vendor, $requestedBy);

        // Department chair email based on selection
        $deptChairEmail = null;
        if (!empty($deptChairSignature)) {
            // Map department chairs to their email addresses
            $chairEmails = [
                'Dr. Randy Bennett' => 'bennett@juniata.edu',
                'Dr. Ursula Williams' => 'williams@juniata.edu',
                'Dr. Dennis Johnson' => 'johnson@juniata.edu',
                'Dr. Uma Ramakrishnan' => 'ramakrishnan@juniata.edu',
                'Dr. Ryan Mathur' => 'mathur@juniata.edu',
                'Dr. Jim Borgardt' => 'borgardt@juniata.edu',
                'Dr. Kimberly Roth' => 'roth@juniata.edu',
                'Dr. Loren Rhodes' => 'rhodes@juniata.edu',
                'Dr. Kathy Westcott' => 'westcott@juniata.edu',
                'Dr. Alison Fletcher' => 'fletcher@juniata.edu',
                'Test' => 'iyengmr22@juniata.edu' // Added Test option with required email
            ];
            
            if (isset($chairEmails[$deptChairSignature])) {
                $deptChairEmail = $chairEmails[$deptChairSignature];
            }
        }

        // Determine CC recipients
        $ccEmails = [];
        
        // Always send a copy to developer during testing
        $ccEmails[] = $currentDeveloperEmail;
        
        // Add department chair if available
        if ($deptChairEmail) {
            $ccEmails[] = $deptChairEmail;
        }
        
        // Create comma-separated list for CC emails
        $ccList = implode(', ', $ccEmails);
        
        // Send notification to requester
        $requesterEmail = $username . '@juniata.edu';
        mg_send($requesterEmail, $subject, $message, $ccList, null);
        
        // For Test option, also send direct email to the test address
        if ($deptChairSignature === 'Test') {
            mg_send('iyengmr22@juniata.edu', $subject, $message, $currentDeveloperEmail, null);
        }

        // Redirect with success message
        header("Location: success.html?success=1&order_id=" . $orderId);
        exit;
    }
} 
catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("<strong>Database Error:</strong> " . htmlspecialchars($e->getMessage()));
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("<strong>Application Error:</strong> " . htmlspecialchars($e->getMessage()));
}
?>