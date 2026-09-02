<?php
/**
 * Maruf Traders - AJAX Save Retailer Endpoint
 */

define('APP_INIT', true);
define('IS_AJAX', true);
require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/includes/auth_check.php';
require_once ROOT_PATH . '/includes/permission_check.php';

requirePermission('retailers.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    jsonResponse(false, 'Invalid security token (CSRF).', [], 403);
}

$id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
$name = trim($_POST['name'] ?? '');
$mobile = trim($_POST['mobile'] ?? '');
$address = trim($_POST['address'] ?? '');
$creditLimit = (float)($_POST['credit_limit'] ?? 100000.00);
$openingBalance = (float)($_POST['opening_balance'] ?? 0.00);
$advanceBalance = (float)($_POST['advance_balance'] ?? 0.00);
$status = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';
$notes = trim($_POST['notes'] ?? '');

if (empty($name)) {
    jsonResponse(false, 'Retailer Name is required.');
}
if (empty($mobile)) {
    jsonResponse(false, 'Mobile Number is required.');
}

$db = Database::getConnection();

try {
    $db->beginTransaction();

    if ($id) {
        // Update Existing Retailer
        $stmt = $db->prepare("SELECT * FROM retailers WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $id]);
        $oldRetailer = $stmt->fetch();

        if (!$oldRetailer) {
            $db->rollBack();
            jsonResponse(false, 'Retailer not found.');
        }

        $uStmt = $db->prepare("UPDATE retailers 
                               SET name = :name, mobile = :mobile, address = :address, 
                                   credit_limit = :credit_limit, status = :status, notes = :notes 
                               WHERE id = :id");
        $uStmt->execute([
            ':name'         => $name,
            ':mobile'       => $mobile,
            ':address'      => $address,
            ':credit_limit' => $creditLimit,
            ':status'       => $status,
            ':notes'        => $notes,
            ':id'           => $id
        ]);

        logAudit('retailers', 'update', $oldRetailer['retailer_code'], $oldRetailer, [
            'name' => $name, 'mobile' => $mobile, 'credit_limit' => $creditLimit, 'status' => $status
        ]);

        $db->commit();
        jsonResponse(true, 'Retailer updated successfully.', ['id' => $id, 'code' => $oldRetailer['retailer_code']]);

    } else {
        // Create New Retailer with Auto ID
        $code = generateCode('retailers', 'retailer_code', PREFIX_RETAILER, 3);
        $currentDue = $openingBalance;

        $insStmt = $db->prepare("INSERT INTO retailers 
            (retailer_code, name, mobile, address, opening_balance, advance_balance, current_due, credit_limit, status, notes) 
            VALUES (:code, :name, :mobile, :address, :opening, :advance, :due, :limit, :status, :notes)");

        $insStmt->execute([
            ':code'    => $code,
            ':name'    => $name,
            ':mobile'  => $mobile,
            ':address' => $address,
            ':opening' => $openingBalance,
            ':advance' => $advanceBalance,
            ':due'     => $currentDue,
            ':limit'   => $creditLimit,
            ':status'  => $status,
            ':notes'   => $notes
        ]);

        $newId = (int)$db->lastInsertId();

        // Create Ledger Entry for Opening Balance if > 0
        if ($openingBalance > 0) {
            $lStmt = $db->prepare("INSERT INTO retailer_ledger 
                (retailer_id, transaction_date, transaction_type, reference_id, description, debit, credit, running_balance, created_by)
                VALUES (:rid, CURDATE(), 'OPENING', :ref, 'Opening Due Balance Recorded', :debit, 0.00, :run_bal, :uid)");
            
            $lStmt->execute([
                ':rid'     => $newId,
                ':ref'     => 'INIT-DUE-' . $code,
                ':debit'   => $openingBalance,
                ':run_bal' => $openingBalance,
                ':uid'     => $_SESSION['user_id'] ?? null
            ]);
        }

        // Create Ledger Entry for Advance Balance if > 0
        if ($advanceBalance > 0) {
            $advRunBal = $openingBalance - $advanceBalance;
            $aStmt = $db->prepare("INSERT INTO retailer_ledger 
                (retailer_id, transaction_date, transaction_type, reference_id, description, debit, credit, running_balance, created_by)
                VALUES (:rid, CURDATE(), 'ADVANCE', :ref, 'Opening Advance Balance Deposited', 0.00, :credit, :run_bal, :uid)");
            
            $aStmt->execute([
                ':rid'     => $newId,
                ':ref'     => 'INIT-ADV-' . $code,
                ':credit'  => $advanceBalance,
                ':run_bal' => $advRunBal,
                ':uid'     => $_SESSION['user_id'] ?? null
            ]);
        }

        logAudit('retailers', 'create', $code, null, [
            'name' => $name, 'mobile' => $mobile, 'opening_balance' => $openingBalance, 'credit_limit' => $creditLimit
        ]);

        $db->commit();
        jsonResponse(true, 'Retailer added successfully.', ['id' => $newId, 'code' => $code]);
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(false, 'Failed to save retailer: ' . $e->getMessage(), [], 500);
}
