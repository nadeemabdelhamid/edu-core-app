<?php
/**
 * limit_gate.php — Include at top of any "create" page to enforce plan limits.
 * Usage:
 *   $limitResource = 'students'; // or 'groups', 'teachers'
 *   $limitRedirect = '/educore/modules/students/';
 *   include __DIR__ . '/../../includes/limit_gate.php';
 */
if (!isset($limitResource)) return;

$_limitCheck = checkLimit($limitResource);
if (!$_limitCheck['ok']) {
    $sub = getSubscription();
    // If we're in a non-HTML context, just redirect
    if (!headers_sent()) {
        flash('error', ucfirst($limitResource).' limit reached ('.$_limitCheck['used'].'/'.$_limitCheck['max'].') on your '.$_limitCheck['plan'].' plan. Upgrade to add more.');
        redirect($limitRedirect ?? '/educore/dashboard.php');
    }
    exit;
}
