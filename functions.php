<?php

/**
 * Created by Adam Steele.
 * User: steelea
 * Date: 5/16/24
 * Time: 9:01 AM
 */

require_once('/DATA/www/phpadmin/webdev_classes/Authenticate.php');
require_once('/DATA/www/phpadmin/webdev_classes/Ldap.php');
require_once('/DATA/www/phpadmin/webdev_classes/UserHandler.php');
require_once('/DATA/www/support/webdev_classes/MssqlDb.php');
require_once('/DATA/www/support/webdev_classes/EmailHandler.php');


$username = Authenticate::ensure_logged_in();
//$username = 'steelea';

/*if ($username == 'steelea') {
    $username = 'nortoby17';
}*/

$ADMIN = UserHandler::has_access($username,224,USER_ADMIN);
$SUPER = UserHandler::has_access($username,224,USER_SUPER);
$REG = UserHandler::has_access($username,224,USER_REGULAR);

// Get the users in groups for the email, group for this app is 217, I added new users and sorted by id desc to find it in webdevelopement databse pum_user-groups
$adminUsers = UserHandler::get_users_in_group(217);

/*var_dump($adminUsers);
exit();*/

/*
 * config.php has the database information that is used
 */
include_once('config.php');

try {
// Connect to database
$pdo = new PDO('dblib:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USERNAME, DB_PASSWORD);

//Turn on error reporting comment this out when not debugging
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

//test connection string for debugging
/*$stmt = $pdo->query('SELECT @@VERSION');
    $version = $stmt->fetchColumn();
    echo $version;*/


} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}

//Mail API functionality 

function mg_send($to, $subject, $message, $cc, $bcc) {
    try {
        $apiKey = 'key-ff8c5642a1ebcc3a6d32c4412b081584';
        if (!$apiKey) {
            throw new Exception('Mailgun API key is not set.');
        }

        $domain = 'mailgun.juniata.edu';
        $url = 'https://api.mailgun.net/v3/' . $domain . '/messages';

        $ch = curl_init();

        if ($ch === false) {
            throw new Exception('Failed to initialize cURL session.');
        }

        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, 'api:' . $apiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $plain = strip_tags(nl2br($message));

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            'from' => 'webmaster@juniata.edu',
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $subject,
            'html' => $message,
            'text' => $plain
        ));

        $response = curl_exec($ch);

        if ($response === false) {
            throw new Exception('cURL error: ' . curl_error($ch));
        }

        $info = curl_getinfo($ch);

        if ($info['http_code'] != 200) {
            throw new Exception('Error Sending: ' . $response);
        }

        curl_close($ch);

        return json_decode($response);

    } catch (Exception $e) {
        error_log($e->getMessage());
        return false;
    }
}

/*var_dump($pdo);
exit();*/

//Pull the users information to pre fill out fields.
$stmt = $pdo->prepare("SELECT * FROM directory_faculty WHERE username = ?");
$stmt->execute([$username]);

// Fetch the tickets
$employeeInfoPull = $stmt->fetchAll(PDO::FETCH_ASSOC);

$employeeInfo = $employeeInfoPull[0];

/*var_dump($employeeInfo);
exit();*/



