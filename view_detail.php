<?php
/**
 * Created by Maddie Iyengar
 * User: iyengmr22
 * Date: 3/2/25
 * Time: 9:00 AM
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

include("includes/functions.php");
require_once('/DATA/www/phpadmin/webdev_classes/Authenticate.php');
require_once('/DATA/www/phpadmin/webdev_classes/Ldap.php');
require_once('/DATA/www/phpadmin/webdev_classes/UserHandler.php');
require_once('/DATA/www/support/webdev_classes/MssqlDb.php');
require_once('/DATA/www/support/webdev_classes/EmailHandler.php');

class ChemicalOrderDetail {
    private $pdo;
    private $username;
    private $isAdmin;
    private $isSuperAdmin;

    function __construct() {
        $this->setSecurityHeaders();
        try {
            $this->authenticateUser();
            $this->validateAdminAccess();
            $this->initializeDatabaseConnection();
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage(), 403);
        }
    }

    function setSecurityHeaders() {
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    function authenticateUser() {
        $this->username = Authenticate::ensure_logged_in();
        $this->isAdmin = UserHandler::has_access($this->username, 308, USER_ADMIN);
        $this->isSuperAdmin = UserHandler::has_access($this->username, 308, USER_SUPER);
    }

    function validateAdminAccess() {
        if (!$this->isAdmin && !$this->isSuperAdmin) {
            throw new Exception('Insufficient permissions for viewing chemical order details');
        }
    }

    function initializeDatabaseConnection() {
        try {
            $dsn = "dblib:host=" . DB_HOST . ";dbname=" . DB_NAME;
            $options = array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                //PDO::ATTR_EMULATE_PREPARES => false,
            );
            $this->pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, $options);
        } catch (PDOException $e) {
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }

    function getOrderById($orderId) {
        if (!is_numeric($orderId)) {
            throw new Exception('Invalid order ID');
        }

        try {
            $sql = "SELECT [id]
            ,[username]
            ,[orderID]
            ,[employeeID]
            ,[vendor]
            ,[vendorName]
            ,[vendorAddress]
            ,[vendorPhone]
            ,[catalogNumber]
            ,[quantity]
            ,[description]
            ,[size]
            ,[amountNeeded]
            ,[hazards]
            ,[price]
            ,[storageRoom]
            ,[websiteLink]
            ,[pListedWaste]
            ,[account]
            ,[budgetLine]
            ,[labStorage]
            ,[dateRequested]
            ,[shippingWait]
            ,[requestedBy]
            ,[shipToPerson]
            ,[requestorEmail]
            ,[requestorSignature]
            ,[deptChairSignature]
            ,[deptChairDate]
            ,[status]
        FROM [dbo].[chemical_order]
        WHERE [id] = :orderId";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':orderId', $orderId, PDO::PARAM_INT);
            $stmt->execute();
            
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                throw new Exception('Order not found');
            }
            
            return $order;
        } catch (PDOException $e) {
            throw new Exception('Error retrieving order: ' . $e->getMessage());
        }
    }

    function updateOrderStatus($orderId, $status) {
        if (!is_numeric($orderId)) {
            throw new Exception('Invalid order ID');
        }

        // Validate status
        $validStatuses = ['pending', 'approved', 'rejected'];
        if (!in_array(strtolower($status), $validStatuses)) {
            throw new Exception('Invalid status value');
        }

        try {
            $sql = "UPDATE [dbo].[chemical_order] 
                  SET [status] = :status,
                      [lastModifiedBy] = :username,
                      [lastModifiedDate] = GETDATE() 
                  WHERE [id] = :orderId";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            $stmt->bindParam(':username', $this->username, PDO::PARAM_STR);
            $stmt->bindParam(':orderId', $orderId, PDO::PARAM_INT);
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Order not found or no changes made');
            }
            
            // Get the updated order details
            $order = $this->getOrderById($orderId); 
            $emailSent = sendNotificationEmail($order['requestorEmail'], $status, $orderId);
            
            return true;
        } catch (PDOException $e) {
            throw new Exception('Error updating order status: ' . $e->getMessage());
        }
    }

    function processRequest() {
        $action = isset($_GET['action']) ? $_GET['action'] : null;
        $response = array('status' => 'error', 'message' => 'Invalid request');
        
        try {
            switch ($action) {
                case 'getOrderDetail':
                    if (!isset($_GET['id'])) {
                        throw new Exception('Order ID is required');
                    }
                    $orderId = $_GET['id'];
                    $order = $this->getOrderById($orderId);
                    $response = array(
                        'status' => 'success',
                        'order' => $order
                    );
                    break;
                    
                case 'updateOrderStatus':
                    if (!isset($_GET['id']) || !isset($_GET['status'])) {
                        throw new Exception('Order ID and status are required');
                    }
                    $orderId = $_GET['id'];
                    $status = $_GET['status'];
                    $this->updateOrderStatus($orderId, $status);
                    $response = array(
                        'status' => 'success',
                        'message' => 'Order status updated successfully'
                    );
                    break;
                
                default:
                    throw new Exception('Unknown action requested');
            }
        } catch (Exception $e) {
            $response = array(
                'status' => 'error',
                'message' => $e->getMessage()
            );
        }
    
        echo json_encode($response);
    }    
        
    function sendErrorResponse($message, $statusCode = 400) {
        http_response_code($statusCode);
        echo json_encode(array(
            'status' => 'error',
            'message' => $message
        ));
        exit;
    }
}

function sendNotificationEmail($toEmail, $status, $orderId) {
    $subject = "Chemical Order #$orderId has been " . ucfirst($status);
    $message = "Hello,\n\nYour chemical order (ID: $orderId) has been $status by the department. Please reach out to the respective Department Chair for more details.\n\nRegards,\nChemical Order System";
    $headers = "From: noreply@juniata.edu";

    return mail($toEmail, $subject, $message, $headers);
}

// Initialize and process the request
$handler = new ChemicalOrderDetail();
$handler->processRequest();
?>