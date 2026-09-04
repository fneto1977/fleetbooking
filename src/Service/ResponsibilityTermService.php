<?php

namespace GlpiPlugin\Fleetbooking\Service;

use GlpiPlugin\Fleetbooking\Request;
use GlpiPlugin\Fleetbooking\Config;
use User;
use Html;
use Document;
use Document_Item;
use Dompdf\Dompdf;
use Dompdf\Options;

class ResponsibilityTermService
{
    /**
     * Generates the filled Driver Responsibility Term PDF, attaches it to the Ticket
     * as a native GLPI Document, and updates the request record with the document ID.
     *
     * @param int $requestId ID of the approved booking request
     * @return int The created GLPI Document ID
     * @throws \Exception
     */
    public function generateAndAttach(int $requestId): int
    {
        $request = new Request();
        if (!$request->getFromDB($requestId)) {
            throw new \Exception("Request #$requestId not found.");
        }

        $data = $this->collectTermData($request);
        $html = $this->renderTemplate($data);
        $pdfContent = $this->renderPdf($html);
        $documentId = $this->createGlpiDocument($request, $pdfContent);

        // Update the request with the generated document ID
        $request->update([
            'id' => $requestId,
            'term_document_id' => $documentId,
        ]);

        return $documentId;
    }

    /**
     * Collects all template data from the request, requester, vehicle and acceptance info.
     *
     * @param Request $request
     * @return array
     */
    public function collectTermData(Request $request): array
    {
        $fields = $request->fields;

        // Requester / Driver Name
        $driverName = 'Condutor';
        $user = new User();
        if ($user->getFromDB((int) $fields['requester_users_id'])) {
            $driverName = $user->getFriendlyName();
            if (empty($driverName)) {
                $driverName = $user->fields['name'] ?? 'Condutor';
            }
        }

        // Driver Identification
        $isReg = ($fields['driver_id_type'] ?? 'cpf') === 'registration';
        $driverIdLabel = $isReg ? 'Matrícula' : 'CPF';
        $driverIdValue = $isReg
            ? (!empty($fields['driver_registration']) ? $fields['driver_registration'] : '-')
            : (!empty($fields['driver_cpf']) ? $fields['driver_cpf'] : '-');

        // CNH
        $cnhNumber = !empty($fields['driver_cnh_number']) ? $fields['driver_cnh_number'] : '-';
        $cnhCategory = !empty($fields['driver_cnh_category']) ? $fields['driver_cnh_category'] : 'B';
        $cnhExpiry = !empty($fields['driver_cnh_expiry']) ? Html::convDate($fields['driver_cnh_expiry']) : '-';

        // Vehicle Name and Plate
        $vehicleName = $fields['itemtype'];
        $vehiclePlate = '-';
        if (!empty($fields['itemtype']) && class_exists($fields['itemtype'])) {
            $item = new $fields['itemtype']();
            if ($item->getFromDB((int) $fields['items_id'])) {
                $vehicleName = $item->getName();
                $vehiclePlate = $item->fields['custom_placa']
                    ?? $item->fields['placa']
                    ?? $item->fields['license_plate']
                    ?? $item->fields['serial']
                    ?? '-';
            }
        }

        // Dates & Times
        $startDate = !empty($fields['start_datetime']) ? Html::convDateTime($fields['start_datetime']) : '-';
        $endDate = !empty($fields['end_datetime']) ? Html::convDateTime($fields['end_datetime']) : '-';
        $reason = !empty($fields['reason']) ? nl2br(htmlspecialchars($fields['reason'], ENT_QUOTES, 'UTF-8')) : '-';

        // Acceptance details
        $policyDate = !empty($fields['policy_accepted_at'])
            ? Html::convDateTime($fields['policy_accepted_at'])
            : (!empty($fields['date_creation']) ? Html::convDateTime($fields['date_creation']) : date('d/m/Y H:i:s'));
        $policyIp = !empty($fields['policy_accepted_ip']) ? $fields['policy_accepted_ip'] : '127.0.0.1';

        $acceptanceStamp = sprintf(
            'Aceito eletronicamente por %s em %s — Endereço IP: %s',
            $driverName,
            $policyDate,
            $policyIp
        );

        $verificationHash = hash('sha256', sprintf(
            '%d|%d|%s|%s|%s|%s',
            $fields['id'],
            $fields['requester_users_id'],
            $fields['policy_accepted_at'] ?? '',
            $policyIp,
            $fields['start_datetime'],
            $fields['end_datetime']
        ));

        return [
            'driver_name'        => htmlspecialchars($driverName, ENT_QUOTES, 'UTF-8'),
            'driver_id_label'    => htmlspecialchars($driverIdLabel, ENT_QUOTES, 'UTF-8'),
            'driver_id_value'    => htmlspecialchars($driverIdValue, ENT_QUOTES, 'UTF-8'),
            'driver_cnh_number'  => htmlspecialchars($cnhNumber, ENT_QUOTES, 'UTF-8'),
            'driver_cnh_category'=> htmlspecialchars($cnhCategory, ENT_QUOTES, 'UTF-8'),
            'driver_cnh_expiry'  => htmlspecialchars($cnhExpiry, ENT_QUOTES, 'UTF-8'),
            'vehicle_name'       => htmlspecialchars($vehicleName, ENT_QUOTES, 'UTF-8'),
            'vehicle_plate'      => htmlspecialchars($vehiclePlate, ENT_QUOTES, 'UTF-8'),
            'start_date'         => htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'),
            'end_date'           => htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'),
            'reason'             => $reason,
            'request_id'         => (int) $fields['id'],
            'ticket_id'          => (int) ($fields['tickets_id'] ?? 0),
            'acceptance_stamp'   => htmlspecialchars($acceptanceStamp, ENT_QUOTES, 'UTF-8'),
            'issue_date'         => date('d/m/Y H:i:s'),
            'verification_hash'  => $verificationHash,
        ];
    }

    /**
     * Replaces placeholders in the HTML template.
     *
     * @param array $data
     * @return string
     */
    public function renderTemplate(array $data): string
    {
        $templatePath = dirname(__DIR__, 2) . '/templates/responsibility_term.html';
        if (!file_exists($templatePath)) {
            throw new \Exception("Responsibility term template not found at: $templatePath");
        }

        $html = file_get_contents($templatePath);
        foreach ($data as $key => $val) {
            $html = str_replace('{{' . $key . '}}', (string) $val, $html);
        }

        return $html;
    }

    /**
     * Generates the binary PDF content from HTML using Dompdf.
     *
     * @param string $html
     * @return string Binary PDF stream
     */
    public function renderPdf(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Saves the PDF to GLPI's document directory, creates a Document record,
     * and links it to the associated ticket via Document_Item.
     *
     * @param Request $request
     * @param string $pdfContent
     * @return int Created Document ID
     */
    private function createGlpiDocument(Request $request, string $pdfContent): int
    {
        $fields = $request->fields;
        $requestId = (int) $fields['id'];
        $ticketId = (int) ($fields['tickets_id'] ?? 0);
        $entitiesId = (int) ($fields['entities_id'] ?? 0);
        $userId = (int) ($fields['requester_users_id'] ?? 0);

        // Target storage directory inside GLPI
        $baseDocDir = defined('GLPI_DOC_DIR') ? GLPI_DOC_DIR : dirname(__DIR__, 4) . '/files';
        $termsDir = $baseDocDir . '/_plugins/fleetbooking/terms';
        if (!is_dir($termsDir)) {
            @mkdir($termsDir, 0775, true);
        }

        $filename = sprintf('termo_responsabilidade_%d_%s.pdf', $requestId, date('Ymd_His'));
        $fullPath = $termsDir . '/' . $filename;
        file_put_contents($fullPath, $pdfContent);

        // Compute relative filepath for GLPI document record
        $relativePath = '_plugins/fleetbooking/terms/' . $filename;

        // Also save a copy inside plugin doc dir as backup
        $pluginDocDir = Config::getDocDir();
        @file_put_contents($pluginDocDir . '/' . $filename, $pdfContent);

        $doc = new Document();
        $docId = $doc->add([
            'entities_id'   => $entitiesId,
            'name'          => sprintf('Termo de Responsabilidade - Reserva #%d', $requestId),
            'filename'      => $filename,
            'filepath'      => $relativePath,
            'mime'          => 'application/pdf',
            'users_id'      => $userId,
            'is_recursive'  => 0,
            'comment'       => sprintf('Gerado automaticamente pelo plugin FleetBooking na aprovação da reserva #%d.', $requestId),
        ]);

        if ($docId && $ticketId > 0) {
            $docItem = new Document_Item();
            $docItem->add([
                'documents_id' => $docId,
                'items_id'     => $ticketId,
                'itemtype'     => 'Ticket',
            ]);
        }

        return (int) $docId;
    }
}
