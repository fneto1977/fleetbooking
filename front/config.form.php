<?php

include("../../../inc/includes.php");

Session::checkRight("fleetbooking_admin", UPDATE);

$config = new \GlpiPlugin\Fleetbooking\Config();

$allowedFields = [
    'id',
    'entities_id',
    'itilcategories_id',
    'default_tickets_entities_id',
    'observer_groups_id',
    'readonly_profile_id',
    'vehicle_itemtype',
    'workday_start',
    'workday_end',
    'auto_close_ticket_on_decision',
    'show_pending_on_calendar',
    'approved_color',
    'pending_color',
    'reserved_color',
    'policy_document_path',
    'term_template_path',
];

$entities_id = (int) ($_POST['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);

// Process uploaded documents
$docDir = \GlpiPlugin\Fleetbooking\Config::getDocDir();

if (isset($_FILES['policy_document']) && is_uploaded_file($_FILES['policy_document']['tmp_name'])) {
    $fileInfo = $_FILES['policy_document'];
    $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
    if ($ext === 'pdf' && $fileInfo['error'] === UPLOAD_ERR_OK) {
        $targetFile = $docDir . '/policy_entity_' . $entities_id . '_' . time() . '.pdf';
        if (move_uploaded_file($fileInfo['tmp_name'], $targetFile)) {
            $_POST['policy_document_path'] = $targetFile;
        }
    }
}

if (isset($_FILES['term_template_document']) && is_uploaded_file($_FILES['term_template_document']['tmp_name'])) {
    $fileInfo = $_FILES['term_template_document'];
    $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
    if ($ext === 'pdf' && $fileInfo['error'] === UPLOAD_ERR_OK) {
        $targetFile = $docDir . '/term_template_entity_' . $entities_id . '_' . time() . '.pdf';
        if (move_uploaded_file($fileInfo['tmp_name'], $targetFile)) {
            $_POST['term_template_path'] = $targetFile;
        }
    }
}

if (isset($_POST["update"])) {
    Session::validateCSRF($_POST['_glpi_csrf_token'] ?? '');
    Session::checkRight("fleetbooking_admin", UPDATE);
    $input = array_intersect_key($_POST, array_flip($allowedFields));
    $config->update($input);
} elseif (isset($_POST["add"])) {
    Session::validateCSRF($_POST['_glpi_csrf_token'] ?? '');
    Session::checkRight("fleetbooking_admin", CREATE);
    $addFields = array_diff($allowedFields, ['id']);
    $input = array_intersect_key($_POST, array_flip($addFields));
    $config->add($input);
}

// Auto-grant vehicle asset READ access to the configured read-only profile
\GlpiPlugin\Fleetbooking\Config::ensureVehicleAssetReadAccess(
    (int) ($_POST['readonly_profile_id'] ?? 0)
);

$entities_id = (int) ($_POST['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
$tab = urlencode('GlpiPlugin\Fleetbooking\Config$1');

Html::back();
