<?php

include("../../../inc/includes.php");

Session::checkRight("fleetbooking_read", READ);

$entities_id = (int) ($_GET['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
$doc = (string) ($_GET['doc'] ?? 'policy');

$config = \GlpiPlugin\Fleetbooking\Config::getForEntity($entities_id);

$filePath = '';
$filename = 'document.pdf';

if ($doc === 'policy') {
    $filename = 'politica_uso_veiculo.pdf';
    $customPath = $config['policy_document_path'] ?? '';
    if (!empty($customPath) && file_exists($customPath)) {
        $filePath = $customPath;
    }
} elseif ($doc === 'term_template') {
    $filename = 'termo_responsabilidade_modelo.pdf';
    $customPath = $config['term_template_path'] ?? '';
    if (!empty($customPath) && file_exists($customPath)) {
        $filePath = $customPath;
    }
}

if (empty($filePath) || !file_exists($filePath)) {
    Html::displayErrorAndDie(__('Requested document not found.', 'fleetbooking'));
}

// Clean any buffer before streaming binary PDF
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($filePath);
exit;
