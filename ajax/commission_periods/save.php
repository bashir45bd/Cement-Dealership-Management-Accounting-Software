<?php
/**
 * Maruf Traders - AJAX Save Period Commission (3-Month / Yearly)
 *
 * Enforces (authoritative, server-side):
 *   - Quarterly ("3-Month") periods may span at most 3 months.
 *   - Yearly periods may span at most 12 months.
 *   - No two periods of the SAME commission_type for the SAME company
 *     may cover overlapping dates (prevents double-claiming the same
 *     month(s)/year more than once).
 */

define('APP_INIT', true);
define('IS_AJAX', true);
require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/includes/auth_check.php';
require_once ROOT_PATH . '/includes/permission_check.php';

requirePermission('commission_periods.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.', [], 405);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    jsonResponse(false, 'Invalid security token (CSRF).', [], 403);
}

$companyId = (int)($_POST['company_id'] ?? 0);
$commissionType = trim($_POST['commission_type'] ?? '');
$startDate = trim($_POST['start_date'] ?? '');
$endDate = trim($_POST['end_date'] ?? '');
$ratePerBag = (float)($_POST['rate_per_bag'] ?? 0);
$periodLabel = trim($_POST['period_label'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if (!$companyId) {
    jsonResponse(false, 'Please select a Company.');
}
if (!in_array($commissionType, ['quarterly', 'yearly'], true)) {
    jsonResponse(false, 'Invalid commission type.');
}
if (empty($startDate) || empty($endDate)) {
    jsonResponse(false, 'Please provide both start and end dates.');
}
if (strtotime($startDate) === false || strtotime($endDate) === false) {
    jsonResponse(false, 'Invalid date format.');
}
if (strtotime($endDate) < strtotime($startDate)) {
    jsonResponse(false, 'End date cannot be before start date.');
}
if ($ratePerBag <= 0) {
    jsonResponse(false, 'Rate per bag must be greater than 0.');
}

// --------------------------------------------------------------
// Duration validation
//   - quarterly ("3-Month") -> max 3 months
//   - yearly                -> max 12 months
// --------------------------------------------------------------
$maxMonths = ($commissionType === 'yearly') ? 12 : 3;
$maxAllowedEnd = date('Y-m-d', strtotime($startDate . " +{$maxMonths} months -1 day"));

if (strtotime($endDate) > strtotime($maxAllowedEnd)) {
    $durationLabel = ($commissionType === 'yearly') ? '12 months (1 year)' : '3 months';
    $typeLabel = ($commissionType === 'yearly') ? 'Yearly' : '3-Month';
    jsonResponse(false, "The selected period exceeds the maximum allowed duration for {$typeLabel} commission ({$durationLabel}). Please choose a shorter date range.");
}

$db = Database::getConnection();

try {
    // Verify the company exists
    $cStmt = $db->prepare("SELECT id, name FROM companies WHERE id = :id");
    $cStmt->execute([':id' => $companyId]);
    $company = $cStmt->fetch();
    if (!$company) {
        jsonResponse(false, 'Selected company not found.');
    }

    // --------------------------------------------------------------
    // Overlap validation — same company + same commission_type +
    // overlapping date range is not allowed (covers both pending and
    // already-received records, since a received record already
    // "used up" that date range for that company/type).
    // --------------------------------------------------------------
    $ovStmt = $db->prepare(
        "SELECT id, period_label, start_date, end_date
         FROM company_period_commissions
         WHERE company_id = :cid
           AND commission_type = :ctype
           AND NOT (end_date < :start OR start_date > :end)
         LIMIT 1"
    );
    $ovStmt->execute([
        ':cid'   => $companyId,
        ':ctype' => $commissionType,
        ':start' => $startDate,
        ':end'   => $endDate
    ]);
    $conflict = $ovStmt->fetch();

    if ($conflict) {
        $typeLabel = ($commissionType === 'yearly') ? 'Yearly' : '3-Month';
        jsonResponse(false, "A {$typeLabel} commission period for {$company['name']} already exists covering this date range (\"{$conflict['period_label']}\", " .
            date('d M Y', strtotime($conflict['start_date'])) . ' - ' . date('d M Y', strtotime($conflict['end_date'])) .
            "). Each period must cover a distinct, non-overlapping range.");
    }

    if (empty($periodLabel)) {
        $periodLabel = ($commissionType === 'yearly' ? 'Yearly' : '3-Month') . ' Commission (' .
            date('d M Y', strtotime($startDate)) . ' - ' . date('d M Y', strtotime($endDate)) . ')';
    }

    $insStmt = $db->prepare(
        "INSERT INTO company_period_commissions
            (company_id, commission_type, period_label, start_date, end_date, rate_per_bag, total_bags, total_amount, status, notes, created_by)
         VALUES
            (:cid, :ctype, :label, :start, :end, :rate, 0, 0.00, 'pending', :notes, :uid)"
    );
    $insStmt->execute([
        ':cid'   => $companyId,
        ':ctype' => $commissionType,
        ':label' => $periodLabel,
        ':start' => $startDate,
        ':end'   => $endDate,
        ':rate'  => $ratePerBag,
        ':notes' => $notes,
        ':uid'   => $_SESSION['user_id'] ?? null
    ]);

    $newId = (int)$db->lastInsertId();

    logAudit('commission_periods', 'create', (string)$newId, null, [
        'company' => $company['name'],
        'type' => $commissionType,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'rate_per_bag' => $ratePerBag
    ]);

    jsonResponse(true, 'Period commission created. Bags and amount will calculate automatically from sales data.', ['id' => $newId]);

} catch (Exception $e) {
    jsonResponse(false, 'Failed to save period commission: ' . $e->getMessage(), [], 500);
}