<?php
/**
 * Created by Maddie Iyengar
 * User: iyengmr22
 * Date: 2/24/25
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

class ChemicalOrdersAdmin {
    // Removed type declarations for PHP 5.6 compatibility.
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
        // Removed debug output to prevent extra data in the JSON response.
    }

    function validateAdminAccess() {
        if (!$this->isAdmin && !$this->isSuperAdmin) {
            throw new Exception('Insufficient permissions for chemical order management');
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

    function getAllOrderDetails() {
        try {
            $status = isset($_GET['status']) && $_GET['status'] !== 'all' ? $_GET['status'] : null;
    
            $sql = "SELECT [id]
                  ,[username]
                  ,[orderID]
                  ,[employeeID]
                  ,[requestorEmail]
                  ,[vendor]
                  ,[vendorName]
                  ,[dateRequested]
                  ,[requestedBy]
                  ,[status]
                  ,[account]
                  ,[budgetLine]
              FROM [dbo].[chemical_order]";
    
            // Add filter for status if provided
            if ($status) {
                $sql .= " WHERE status = :status";
            }
    
            $sql .= " ORDER BY dateRequested DESC";
    
            $stmt = $this->pdo->prepare($sql);
    
            // Bind parameters
            if ($status) {
                $stmt->bindParam(':status', $status, PDO::PARAM_STR);
            }
    
            $stmt->execute();
            $orders = $stmt->fetchAll();
    
            if (empty($orders)) {
                // Return empty array if no results
                return [];
            }
    
            return $orders;
        } catch (PDOException $e) {
            throw new Exception('Error retrieving order details: ' . $e->getMessage());
        }
    }

    function getOrderById($orderId) {
        try {
            $sql = "SELECT * FROM chemical_order WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':id', $orderId, PDO::PARAM_INT);
            $stmt->execute();
            
            $order = $stmt->fetch();
            
            if (!$order) {
                throw new Exception("Order #$orderId not found");
            }
            
            return $order;
        } catch (PDOException $e) {
            throw new Exception('Error retrieving order: ' . $e->getMessage());
        }
    }

    function processRequest() {
        $action = isset($_GET['action']) ? $_GET['action'] : null;
        $response = array('status' => 'error', 'message' => 'Invalid request');
        
        // Log the action for debugging purposes
        error_log("Action received: " . $action);
        
        try {
            switch ($action) {
                case 'getOrders':
                case 'getAllOrders':
                    $orders = $this->getAllOrderDetails();
                    $response = array(
                        'status' => 'success',
                        'total_orders' => count($orders),
                        'orders' => $orders
                    );
                    break;
                case 'getOrderDetails':
                case 'getOrderDetail':
                    if (!isset($_GET['id'])) {
                        throw new Exception('Order ID is required');
                    }
                    $orderId = $_GET['id'];
                    $order = $this->getOrderById($orderId);
                    $response = array(
                        'status' => 'success',
                        'orderDetails' => $order
                    );
                    break;
                default:
                    throw new Exception('Unknown action');
            }
        } catch (Exception $e) {
            $this->sendErrorResponse($e->getMessage(), 400);
        }
        $this->sendJsonResponse($response);
    }

    function sendErrorResponse($message, $statusCode = 400) {
        http_response_code($statusCode);
        $this->sendJsonResponse(array('status' => 'error', 'message' => $message));
    }

    function sendJsonResponse($data) {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
}

try {
    $admin = new ChemicalOrdersAdmin();
    $admin->processRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('status' => 'error', 'message' => 'Unexpected system error: ' . $e->getMessage()));
}