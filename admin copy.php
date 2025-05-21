<?php
/**
 * Created by Maddie Iyengar
 * User: iyengmr22
 * Date: 2/24/25
 * Time: 9:00 AM
 */

header('Content-Type: application/json'); // Ensure JSON output

include("includes/functions.php");
require_once('/DATA/www/phpadmin/webdev_classes/Authenticate.php');
require_once('/DATA/www/phpadmin/webdev_classes/Ldap.php');
require_once('/DATA/www/phpadmin/webdev_classes/UserHandler.php');
require_once('/DATA/www/support/webdev_classes/MssqlDb.php');

// Authenticate user
$username = Authenticate::ensure_logged_in();
$ADMIN = UserHandler::has_access($username, 224, USER_ADMIN);
$SUPER = UserHandler::has_access($username, 224, USER_SUPER);

// Restrict access to admin or super users
if (!$ADMIN && !$SUPER) {
    echo json_encode(["error" => "You do not have permission to access this page."]);
    exit;
}

include_once('includes/config.php');

// Database connection
try {
    $pdo = new PDO('dblib:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(["error" => "Database connection failed: " . $e->getMessage()]);
    exit;
}

// Fetch orders
function getOrders($pdo) {
    $sql = "SELECT 
                MIN(id) as id,
                orderId, 
                dateRequested, 
                requestedBy, 
                vendor, 
                account, 
                budgetLine,
                COUNT(*) as itemCount
            FROM chemical_order 
            GROUP BY orderId, dateRequested, requestedBy, vendor, account, budgetLine
            ORDER BY dateRequested DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch order details
function getOrderDetails($pdo, $orderId) {
    $sql = "SELECT * FROM chemical_order WHERE orderId = :orderId";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':orderId', $orderId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle API requests
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'getOrders') {
        $orders = getOrders($pdo);
        echo json_encode($orders);
        exit;
    }

    if ($_GET['action'] == 'getOrderDetails' && isset($_GET['orderId']) && is_numeric($_GET['orderId'])) {
        $orderDetails = getOrderDetails($pdo, $_GET['orderId']);
        echo json_encode($orderDetails);
        exit;
    }
}

// If no valid action, return an error
echo json_encode(["error" => "Invalid action."]);
exit;
