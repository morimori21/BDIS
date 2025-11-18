<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';

use Dompdf\Dompdf;
use Dompdf\Options;

class DocumentGenerator {
    private $pdo;
    private $templatesPath;
    private $outputPath;
    private $assetsPath;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->templatesPath = __DIR__ . '/../templates/';
        $this->outputPath = __DIR__ . '/../uploads/generated_documents/';
        $this->assetsPath = __DIR__ . '/../assets/images/';
        
        // Create output directory if it doesn't exist
        if (!file_exists($this->outputPath)) {
            mkdir($this->outputPath, 0755, true);
        }
    }
    
    /**
     * Helper function to get full name from request data
     */
    private function getFullName($request) {
        if ($request['first_name'] && $request['surname']) {
            $firstName = ucwords(strtolower($request['first_name']));
            $middleName = $request['middle_name'] ? ucwords(strtolower($request['middle_name'])) . ' ' : '';
            $surname = ucwords(strtolower($request['surname']));
            return trim($firstName . ' ' . $middleName . $surname);
        } else {
            return $request['email'];
        }
    }
    
    /**
     * Helper function to get address from request data
     */
    private function getAddress($request) {
        return $request['street'] ?: 'Address not provided';
    }
    
    /**
     * Generate a document based on the request
     */
    public function generateDocument($requestId) {
        // Get request details with resident information
        $stmt = $this->pdo->prepare("
            SELECT dr.*, dt.doc_name as doc_type_name, 
                   u.first_name, u.surname, u.middle_name, u.street, 
                   u.birthdate, u.sex, u.contact_number, e.email
            FROM document_requests dr 
            JOIN document_types dt ON dr.doc_type_id = dt.doc_type_id 
            JOIN users u ON dr.resident_id = u.user_id
            LEFT JOIN account a ON u.user_id = a.user_id
            LEFT JOIN email e ON a.email_id = e.email_id
            WHERE dr.request_id = ?
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        
        if (!$request) {
            throw new Exception("Request not found");
        }
        
        // Get barangay details from barangay_config
        $barangayDetails = getBarangayDetails();
        $request['barangay_name'] = $barangayDetails['brgy_name'];
        $request['municipality'] = $barangayDetails['municipality'];
        $request['province'] = $barangayDetails['province'];
        $request['brgy_logo_src'] = $barangayDetails['brgy_logo_src'];
        $request['city_logo_src'] = $barangayDetails['city_logo_src'];
        
        // Set default captain name (can be customized later)
        $request['captain_name'] = 'HON. RENATO B. MARTIN';
        
        // Generate the document based on type using HTML templates
        switch ($request['doc_type_name']) {
            case 'Barangay Clearance':
                return $this->generateFromHTMLTemplate($request, 'barangay_clearance.html');
            case 'Barangay Indigency':
            case 'Certificate of Indigency':
                return $this->generateFromHTMLTemplate($request, 'certificate_of_indigency.html');
            default:
                throw new Exception("Document type not supported: " . $request['doc_type_name']);
        }
    }
    
    /**
     * Get barangay officials from database based on their roles
     */
    private function getBarangayOfficials() {
        $officials = [];
        
        try {
            // Get Captain (Punong Barangay)
            $stmt = $this->pdo->prepare("
                SELECT u.first_name, u.middle_name, u.surname
                FROM users u 
                JOIN user_roles ur ON u.user_id = ur.user_id 
                WHERE ur.role = 'captain' AND u.status = 'verified' 
                LIMIT 1
            ");
            $stmt->execute();
            $captain = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($captain) {
                $firstName = ucwords(strtolower($captain['first_name']));
                $middleName = $captain['middle_name'] ? ucwords(strtolower($captain['middle_name'])) . ' ' : '';
                $surname = ucwords(strtolower($captain['surname']));
                $officials['Captain'] = trim($firstName . ' ' . $middleName . $surname);
            } else {
                $officials['Captain'] = 'Punong Barangay Name';
            }
            
            // Get Councilors (Kagawad)
            $stmt = $this->pdo->prepare("
                SELECT u.first_name, u.middle_name, u.surname
                FROM users u 
                JOIN user_roles ur ON u.user_id = ur.user_id 
                WHERE ur.role = 'councilor' AND u.status = 'verified' 
                ORDER BY ur.role_id LIMIT 7
            ");
            $stmt->execute();
            $councilors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Fill councilor positions (up to 7)
            for ($i = 0; $i < 7; $i++) {
                if (isset($councilors[$i])) {
                    $firstName = ucwords(strtolower($councilors[$i]['first_name']));
                    $middleName = $councilors[$i]['middle_name'] ? ucwords(strtolower($councilors[$i]['middle_name'])) . ' ' : '';
                    $surname = ucwords(strtolower($councilors[$i]['surname']));
                    $officials['Councilor_' . ($i + 1)] = trim($firstName . ' ' . $middleName . $surname);
                } else {
                    $officials['Councilor_' . ($i + 1)] = 'Kagawad Name';
                }
            }
            
            // Get SK Chairman
            $stmt = $this->pdo->prepare("
                SELECT u.first_name, u.middle_name, u.surname
                FROM users u 
                JOIN user_roles ur ON u.user_id = ur.user_id 
                WHERE ur.role = 'sk_chairman' AND u.status = 'verified' 
                LIMIT 1
            ");
            $stmt->execute();
            $sk_chairman = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($sk_chairman) {
                $firstName = ucwords(strtolower($sk_chairman['first_name']));
                $middleName = $sk_chairman['middle_name'] ? ucwords(strtolower($sk_chairman['middle_name'])) . ' ' : '';
                $surname = ucwords(strtolower($sk_chairman['surname']));
                $officials['SK_CHAIRMAN'] = trim($firstName . ' ' . $middleName . $surname);
            } else {
                $officials['SK_CHAIRMAN'] = 'SK Chairman Name';
            }
            
            // Get Treasurer
            $stmt = $this->pdo->prepare("
                SELECT u.first_name, u.middle_name, u.surname
                FROM users u 
                JOIN user_roles ur ON u.user_id = ur.user_id 
                WHERE ur.role = 'treasurer' AND u.status = 'verified' 
                LIMIT 1
            ");
            $stmt->execute();
            $treasurer = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($treasurer) {
                $firstName = ucwords(strtolower($treasurer['first_name']));
                $middleName = $treasurer['middle_name'] ? ucwords(strtolower($treasurer['middle_name'])) . ' ' : '';
                $surname = ucwords(strtolower($treasurer['surname']));
                $officials['TREASURER'] = trim($firstName . ' ' . $middleName . $surname);
            } else {
                $officials['TREASURER'] = 'Treasurer Name';
            }
            
            // Get Secretary
            $stmt = $this->pdo->prepare("
                SELECT u.first_name, u.middle_name, u.surname
                FROM users u 
                JOIN user_roles ur ON u.user_id = ur.user_id 
                WHERE ur.role = 'secretary' AND u.status = 'verified' 
                LIMIT 1
            ");
            $stmt->execute();
            $secretary = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($secretary) {
                $firstName = ucwords(strtolower($secretary['first_name']));
                $middleName = $secretary['middle_name'] ? ucwords(strtolower($secretary['middle_name'])) . ' ' : '';
                $surname = ucwords(strtolower($secretary['surname']));
                $officials['SECRETARY'] = trim($firstName . ' ' . $middleName . $surname);
            } else {
                $officials['SECRETARY'] = 'Secretary Name';
            }
            
        } catch (Exception $e) {
            // If there's any error, use default values
            $officials = [
                'Captain' => 'Punong Barangay Name',
                'Councilor_1' => 'Kagawad Name',
                'Councilor_2' => 'Kagawad Name',
                'Councilor_3' => 'Kagawad Name',
                'Councilor_4' => 'Kagawad Name',
                'Councilor_5' => 'Kagawad Name',
                'Councilor_6' => 'Kagawad Name',
                'Councilor_7' => 'Kagawad Name',
                'SK_CHAIRMAN' => 'SK Chairman Name',
                'TREASURER' => 'Treasurer Name',
                'SECRETARY' => 'Secretary Name'
            ];
        }
        
        return $officials;
    }
    
    /**
     * Generate HTML from template (no PDF generation)
     */
    private function generateFromHTMLTemplate($request, $templateFile) {
        // Load HTML template
        $templatePath = $this->templatesPath . $templateFile;
        
        if (!file_exists($templatePath)) {
            throw new Exception("Template file not found: " . $templateFile);
        }
        
        $html = file_get_contents($templatePath);
        
        // Calculate age
        $age = 'N/A';
        if ($request['birthdate']) {
            $birthDate = new DateTime($request['birthdate']);
            $today = new DateTime();
            $age = $today->diff($birthDate)->y;
        }
        
        // Format dates
        $currentDate = new DateTime();
        $day = $currentDate->format('j');
        $month = $this->getFilipinoMonth($currentDate->format('F'));
        $year = $currentDate->format('Y');
        
        // Format birthdate
        $birthdateFormatted = 'N/A';
        if ($request['birthdate']) {
            $bDate = new DateTime($request['birthdate']);
            $birthdateFormatted = $bDate->format('F j, Y');
        }
        
        // Get logo paths from request (already base64 data URLs from getBarangayDetails)
        $barangayLogoPath = $request['brgy_logo_src'] ?? ($this->assetsPath . 'default_logo.png');
        $municipalityLogoPath = $request['city_logo_src'] ?? ($this->assetsPath . 'default_logo.png');
        
        // Get barangay officials from database based on their roles
        $officials = $this->getBarangayOfficials();
        
        // Replace placeholders
        $replacements = [
            '{{NAME}}' => strtoupper($this->getFullName($request)),
            '{{AGE}}' => $age,
            '{{BIRTHDATE}}' => $birthdateFormatted,
            '{{ADDRESS}}' => $this->getAddress($request),
            '{{STREET}}' => $request['street'] ?? 'N/A',
            '{{PURPOSE}}' => $request['reason'] ?? 'General Purpose',
            '{{DAY}}' => $day,
            '{{MONTH}}' => $month,
            '{{YEAR}}' => $year,
            '{{BARANGAY_NAME}}' => $request['barangay_name'] ?? 'San Jose Norte',
            '{{MUNICIPALITY}}' => $request['municipality'] ?? 'Cabanatuan',
            '{{PROVINCE}}' => $request['province'] ?? 'Nueva Ecija',
            '{{WATERMARK_PATH}}' => $barangayLogoPath,
            '{{LOGO_LEFT}}' => $barangayLogoPath,
            '{{LOGO_RIGHT}}' => $municipalityLogoPath,
            // Barangay Officials - using database values (supports both template formats)
            '{{Captain}}' => $officials['Captain'],
            '{{PUNONG_BARANGAY}}' => $officials['Captain'], // Alternative format for Certificate of Indigency
            '{{SK_CHAIRMAN}}' => $officials['SK_CHAIRMAN'],
            '{{TREASURER}}' => $officials['TREASURER'],
            '{{SECRETARY}}' => $officials['SECRETARY'],
        ];
        
        foreach ($replacements as $placeholder => $value) {
            $html = str_replace($placeholder, $value, $html);
        }
        
        // Handle multiple Councilor placeholders sequentially
        for ($i = 1; $i <= 7; $i++) {
            $councilor_name = $officials['Councilor_' . $i] ?? 'Kagawad Name';
            $html = preg_replace('/\{\{Councilor\}\}/', $councilor_name, $html, 1); // Replace only first occurrence
        }
        
        // Return HTML directly (no PDF generation, no database storage)
        return $html;
    }
    
    /**
     * Get Filipino month name
     */
    private function getFilipinoMonth($englishMonth) {
        $months = [
            'January' => 'Enero',
            'February' => 'Pebrero',
            'March' => 'Marso',
            'April' => 'Abril',
            'May' => 'Mayo',
            'June' => 'Hunyo',
            'July' => 'Hulyo',
            'August' => 'Agosto',
            'September' => 'Setyembre',
            'October' => 'Oktubre',
            'November' => 'Nobyembre',
            'December' => 'Disyembre'
        ];
        
        return $months[$englishMonth] ?? $englishMonth;
    }
    
    /**
     * Generate Barangay Clearance document
     */
    private function generateBarangayClearance($request) {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Barangay Document Issuance System');
        $pdf->SetAuthor('Barangay ' . ($request['barangay_name'] ?? 'Office'));
        $pdf->SetTitle('Barangay Clearance - ' . $this->getFullName($request));
        
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        
        // Header
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'REPUBLIC OF THE PHILIPPINES', 0, 1, 'C');
        $pdf->Cell(0, 8, 'PROVINCE OF ' . strtoupper($request['province'] ?? 'PROVINCE'), 0, 1, 'C');
        $pdf->Cell(0, 8, 'MUNICIPALITY OF ' . strtoupper($request['municipality'] ?? 'MUNICIPALITY'), 0, 1, 'C');
        $pdf->Cell(0, 8, 'BARANGAY ' . strtoupper($request['barangay_name'] ?? 'BARANGAY'), 0, 1, 'C');
        
        $pdf->Ln(10);
        
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'BARANGAY CLEARANCE', 0, 1, 'C');
        
        $pdf->Ln(10);
        
        // Content
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 8, 'TO WHOM IT MAY CONCERN:', 0, 1, 'L');
        
        $pdf->Ln(5);
        
        $fullName = trim($request['first_name'] . ' ' . ($request['middle_name'] ? $request['middle_name'] . ' ' : '') . $request['surname']);
        $age = $this->calculateAge($request['birthdate']);
        
        $content = "     This is to certify that $fullName, $age years old, " . 
                  strtolower($request['gender'] ?? 'resident') . ", is a bonafide resident of Barangay " .
                  ($request['barangay_name'] ?? 'Barangay') . ", " . ($request['municipality'] ?? 'Municipality') . 
                  ", " . ($request['province'] ?? 'Province') . " with postal address at " . $request['address'] . ".";
        
        $pdf->MultiCell(0, 8, $content, 0, 'J');
        
        $pdf->Ln(5);
        
        $pdf->MultiCell(0, 8, "     This certification is issued upon the request of the above-named person for " . 
                       strtolower($request['reason']) . ".", 0, 'J');
        
        $pdf->Ln(5);
        
        $pdf->Cell(0, 8, "     Issued this " . date('jS') . " day of " . date('F Y') . " at Barangay " . 
                  ($request['barangay_name'] ?? 'Office') . ".", 0, 1, 'L');
        
        $pdf->Ln(20);
        
        // Signature section
        $pdf->Cell(0, 8, '', 0, 1, 'L');
        $pdf->Cell(0, 8, '_________________________________', 0, 1, 'R');
        $pdf->Cell(0, 8, 'BARANGAY CAPTAIN', 0, 1, 'R');
        
        // Save the document
        $filename = 'barangay_clearance_' . $request['request_id'] . '_' . time() . '.pdf';
        $filepath = $this->outputPath . $filename;
        $pdf->Output($filepath, 'F');
        
        return $filename;
    }
    
    /**
     * Generate Barangay Indigency document
     */
    private function generateBarangayIndigency($request) {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Barangay Document Issuance System');
        $pdf->SetAuthor('Barangay ' . ($request['barangay_name'] ?? 'Office'));
        $pdf->SetTitle('Certificate of Indigency - ' . $request['first_name'] . ' ' . $request['surname']);
        
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        
        // Header
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'REPUBLIC OF THE PHILIPPINES', 0, 1, 'C');
        $pdf->Cell(0, 8, 'PROVINCE OF ' . strtoupper($request['province'] ?? 'PROVINCE'), 0, 1, 'C');
        $pdf->Cell(0, 8, 'MUNICIPALITY OF ' . strtoupper($request['municipality'] ?? 'MUNICIPALITY'), 0, 1, 'C');
        $pdf->Cell(0, 8, 'BARANGAY ' . strtoupper($request['barangay_name'] ?? 'BARANGAY'), 0, 1, 'C');
        
        $pdf->Ln(10);
        
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'CERTIFICATE OF INDIGENCY', 0, 1, 'C');
        
        $pdf->Ln(10);
        
        // Content
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 8, 'TO WHOM IT MAY CONCERN:', 0, 1, 'L');
        
        $pdf->Ln(5);
        
        $fullName = trim($request['first_name'] . ' ' . ($request['middle_name'] ? $request['middle_name'] . ' ' : '') . $request['surname']);
        $age = $this->calculateAge($request['birthdate']);
        
        $content = "     This is to certify that $fullName, $age years old, " . 
                  strtolower($request['gender'] ?? 'resident') . ", is a bonafide resident of Barangay " .
                  ($request['barangay_name'] ?? 'Barangay') . ", " . ($request['municipality'] ?? 'Municipality') . 
                  ", " . ($request['province'] ?? 'Province') . " with postal address at " . $request['address'] . ".";
        
        $pdf->MultiCell(0, 8, $content, 0, 'J');
        
        $pdf->Ln(5);
        
        $pdf->MultiCell(0, 8, "     This is to certify further that the above-named person belongs to an indigent family in our barangay and is in need of financial assistance.", 0, 'J');
        
        $pdf->Ln(5);
        
        $pdf->MultiCell(0, 8, "     This certification is issued upon the request of the above-named person for " . 
                       strtolower($request['reason']) . ".", 0, 'J');
        
        $pdf->Ln(5);
        
        $pdf->Cell(0, 8, "     Issued this " . date('jS') . " day of " . date('F Y') . " at Barangay " . 
                  ($request['barangay_name'] ?? 'Office') . ".", 0, 1, 'L');
        
        $pdf->Ln(20);
        
        // Signature section
        $pdf->Cell(0, 8, '', 0, 1, 'L');
        $pdf->Cell(0, 8, '_________________________________', 0, 1, 'R');
        $pdf->Cell(0, 8, 'BARANGAY CAPTAIN', 0, 1, 'R');
        
        // Save the document
        $filename = 'certificate_indigency_' . $request['request_id'] . '_' . time() . '.pdf';
        $filepath = $this->outputPath . $filename;
        $pdf->Output($filepath, 'F');
        
        return $filename;
    }
    
    /**
     * Generate Barangay Business Permit document
     */
    private function generateBarangayBusinessPermit($request) {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Barangay Document Issuance System');
        $pdf->SetAuthor('Barangay ' . ($request['barangay_name'] ?? 'Office'));
        $pdf->SetTitle('Barangay Business Permit - ' . $request['first_name'] . ' ' . $request['surname']);
        
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        
        // Header
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'REPUBLIC OF THE PHILIPPINES', 0, 1, 'C');
        $pdf->Cell(0, 8, 'PROVINCE OF ' . strtoupper($request['province'] ?? 'PROVINCE'), 0, 1, 'C');
        $pdf->Cell(0, 8, 'MUNICIPALITY OF ' . strtoupper($request['municipality'] ?? 'MUNICIPALITY'), 0, 1, 'C');
        $pdf->Cell(0, 8, 'BARANGAY ' . strtoupper($request['barangay_name'] ?? 'BARANGAY'), 0, 1, 'C');
        
        $pdf->Ln(10);
        
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'BARANGAY BUSINESS PERMIT', 0, 1, 'C');
        
        $pdf->Ln(10);
        
        // Content
        $pdf->SetFont('helvetica', '', 12);
        
        $fullName = trim($request['first_name'] . ' ' . ($request['middle_name'] ? $request['middle_name'] . ' ' : '') . $request['surname']);
        
        $pdf->Cell(40, 8, 'Permit No:', 0, 0, 'L');
        $pdf->Cell(0, 8, 'BP-' . str_pad($request['request_id'], 6, '0', STR_PAD_LEFT), 0, 1, 'L');
        
        $pdf->Cell(40, 8, 'Date Issued:', 0, 0, 'L');
        $pdf->Cell(0, 8, date('F j, Y'), 0, 1, 'L');
        
        $pdf->Ln(5);
        
        $pdf->Cell(0, 8, 'BUSINESS INFORMATION:', 0, 1, 'L');
        $pdf->Cell(40, 8, 'Owner/Operator:', 0, 0, 'L');
        $pdf->Cell(0, 8, $fullName, 0, 1, 'L');
        
        $pdf->Cell(40, 8, 'Business Address:', 0, 0, 'L');
        $pdf->Cell(0, 8, $request['address'], 0, 1, 'L');
        
        $pdf->Ln(5);
        
        $content = "     This permit is issued to the above-named person/entity to operate a business in Barangay " .
                  ($request['barangay_name'] ?? 'Barangay') . ", " . ($request['municipality'] ?? 'Municipality') . 
                  ", " . ($request['province'] ?? 'Province') . " for the purpose of " . $request['reason'] . ".";
        
        $pdf->MultiCell(0, 8, $content, 0, 'J');
        
        $pdf->Ln(5);
        
        $pdf->MultiCell(0, 8, "     This permit is valid for one (1) year from the date of issuance and is subject to the rules and regulations of the barangay.", 0, 'J');
        
        $pdf->Ln(5);
        
        $pdf->Cell(0, 8, "     Issued this " . date('jS') . " day of " . date('F Y') . " at Barangay " . 
                  ($request['barangay_name'] ?? 'Office') . ".", 0, 1, 'L');
        
        $pdf->Ln(20);
        
        // Signature section
        $pdf->Cell(0, 8, '', 0, 1, 'L');
        $pdf->Cell(0, 8, '_________________________________', 0, 1, 'R');
        $pdf->Cell(0, 8, 'BARANGAY CAPTAIN', 0, 1, 'R');
        
        // Save the document
        $filename = 'business_permit_' . $request['request_id'] . '_' . time() . '.pdf';
        $filepath = $this->outputPath . $filename;
        $pdf->Output($filepath, 'F');
        
        return $filename;
    }
    
    /**
     * Generate Barangay Residency document
     */
    private function generateBarangayResidency($request) {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Barangay Document Issuance System');
        $pdf->SetAuthor('Barangay ' . ($request['barangay_name'] ?? 'Office'));
        $pdf->SetTitle('Certificate of Residency - ' . $request['first_name'] . ' ' . $request['surname']);
        
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 12);
        
        // Header
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'REPUBLIC OF THE PHILIPPINES', 0, 1, 'C');
        $pdf->Cell(0, 8, 'PROVINCE OF ' . strtoupper($request['province'] ?? 'PROVINCE'), 0, 1, 'C');
        $pdf->Cell(0, 8, 'MUNICIPALITY OF ' . strtoupper($request['municipality'] ?? 'MUNICIPALITY'), 0, 1, 'C');
        $pdf->Cell(0, 8, 'BARANGAY ' . strtoupper($request['barangay_name'] ?? 'BARANGAY'), 0, 1, 'C');
        
        $pdf->Ln(10);
        
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 12, 'CERTIFICATE OF RESIDENCY', 0, 1, 'C');
        
        $pdf->Ln(10);
        
        // Content
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 8, 'TO WHOM IT MAY CONCERN:', 0, 1, 'L');
        
        $pdf->Ln(5);
        
        $fullName = trim($request['first_name'] . ' ' . ($request['middle_name'] ? $request['middle_name'] . ' ' : '') . $request['surname']);
        $age = $this->calculateAge($request['birthdate']);
        
        $content = "     This is to certify that $fullName, $age years old, " . 
                  strtolower($request['gender'] ?? 'resident') . ", is a bonafide resident of Barangay " .
                  ($request['barangay_name'] ?? 'Barangay') . ", " . ($request['municipality'] ?? 'Municipality') . 
                  ", " . ($request['province'] ?? 'Province') . " with postal address at " . $request['address'] . ".";
        
        $pdf->MultiCell(0, 8, $content, 0, 'J');
        
        $pdf->Ln(5);
        
        $pdf->MultiCell(0, 8, "     The above-named person has been a resident of this barangay and is known to be of good moral character and a law-abiding citizen.", 0, 'J');
        
        $pdf->Ln(5);
        
        $pdf->MultiCell(0, 8, "     This certification is issued upon the request of the above-named person for " . 
                       strtolower($request['reason']) . ".", 0, 'J');
        
        $pdf->Ln(5);
        
        $pdf->Cell(0, 8, "     Issued this " . date('jS') . " day of " . date('F Y') . " at Barangay " . 
                  ($request['barangay_name'] ?? 'Office') . ".", 0, 1, 'L');
        
        $pdf->Ln(20);
        
        // Signature section
        $pdf->Cell(0, 8, '', 0, 1, 'L');
        $pdf->Cell(0, 8, '_________________________________', 0, 1, 'R');
        $pdf->Cell(0, 8, 'BARANGAY CAPTAIN', 0, 1, 'R');
        
        // Save the document
        $filename = 'certificate_residency_' . $request['request_id'] . '_' . time() . '.pdf';
        $filepath = $this->outputPath . $filename;
        $pdf->Output($filepath, 'F');
        
        return $filename;
    }
    
    /**
     * Calculate age from birthdate
     */
    private function calculateAge($birthdate) {
        if (!$birthdate) {
            return 'N/A';
        }
        
        $today = new DateTime();
        $birth = new DateTime($birthdate);
        $age = $today->diff($birth)->y;
        
        return $age;
    }
    
    /**
     * Save generated document information to database (deprecated - not used for HTML output)
     */
    public function saveGeneratedDocument($requestId, $filename) {
        // No longer needed - HTML is generated on-the-fly
        return true;
    }
    
    /**
     * Get the path to a generated document
     */
    public function getDocumentPath($filename) {
        return $this->outputPath . $filename;
    }
}
?>