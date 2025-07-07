<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MainController extends AbstractController
{
    #[Route('/', name: 'app_main')]
    public function index(): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('main/index.html.twig', [
            'controller_name' => 'MainController',
        ]);
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function dashboard(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        return $this->render('main/dashboard.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    #[Route('/license-plate-validator', name: 'app_license_plate_validator')]
    public function licensePlateValidator(Request $request): Response
    {
        $licensePlate = $request->request->get('license_plate');
        $isValid = false;
        $errorMessage = '';

        if ($licensePlate !== null) {
            $result = $this->validateGermanLicensePlate($licensePlate);
            $isValid = $result['valid'];
            $errorMessage = $result['error'];
        }

        return $this->render('main/license_plate_validator.html.twig', [
            'license_plate' => $licensePlate,
            'is_valid' => $isValid,
            'error_message' => $errorMessage,
        ]);
    }

    private function validateGermanLicensePlate(string $licensePlate): array
    {
        $licensePlate = strtoupper(trim($licensePlate));
        
        // German license plate patterns:
        // 1. Standard format: 1-3 letters (district code) + 1-2 letters (series) + 1-4 numbers
        // 2. Examples: M-AB 123, HH-XY 1234, B-A 1
        
        $pattern = '/^[A-Z]{1,3}-[A-Z]{1,2}\s[0-9]{1,4}$/';
        
        if (!preg_match($pattern, $licensePlate)) {
            return [
                'valid' => false,
                'error' => 'Ungültiges Format. Erwartetes Format: ABC-XY 1234 (z.B. M-AB 123, HH-XY 1234)'
            ];
        }
        
        // Additional validation for known district codes (basic list)
        $parts = explode('-', $licensePlate);
        $districtCode = $parts[0];
        
        // Basic list of valid German district codes
        $validDistrictCodes = [
            'A', 'AA', 'AB', 'ABG', 'AC', 'AIC', 'AM', 'AN', 'ANG', 'AO', 'AUR', 'AW', 'AZ',
            'B', 'BA', 'BAD', 'BAR', 'BB', 'BC', 'BGL', 'BH', 'BI', 'BIR', 'BK', 'BL', 'BM',
            'BN', 'BO', 'BOR', 'BOT', 'BRA', 'BRB', 'BT', 'BUL', 'BUR', 'BZ',
            'C', 'CB', 'CE', 'CO', 'CR', 'CW', 'CUX',
            'D', 'DA', 'DAH', 'DAN', 'DAU', 'DD', 'DE', 'DEL', 'DH', 'DI', 'DIN', 'DL', 'DM',
            'DN', 'DO', 'DON', 'DU', 'DW',
            'E', 'EA', 'EB', 'ED', 'EE', 'EF', 'EH', 'EI', 'EL', 'EM', 'EN', 'ER', 'ES', 'EU',
            'F', 'FB', 'FD', 'FG', 'FH', 'FI', 'FL', 'FN', 'FO', 'FR', 'FRG', 'FS', 'FT', 'FU',
            'G', 'GA', 'GAN', 'GAP', 'GC', 'GDB', 'GE', 'GEL', 'GER', 'GF', 'GG', 'GH', 'GI',
            'GL', 'GM', 'GN', 'GO', 'GP', 'GR', 'GS', 'GT', 'GU', 'GV', 'GW', 'GZ',
            'H', 'HA', 'HAL', 'HAM', 'HAS', 'HB', 'HBN', 'HBS', 'HC', 'HD', 'HE', 'HEI', 'HER',
            'HF', 'HG', 'HGW', 'HH', 'HI', 'HIG', 'HIP', 'HK', 'HL', 'HM', 'HN', 'HO', 'HOH',
            'HOM', 'HOR', 'HOT', 'HP', 'HR', 'HRO', 'HS', 'HSK', 'HST', 'HU', 'HV', 'HVL', 'HW',
            'HX', 'HY', 'HZ',
            'IK', 'IL', 'IN', 'IZ',
            'J', 'JE', 'JL',
            'K', 'KA', 'KB', 'KC', 'KE', 'KEH', 'KF', 'KG', 'KH', 'KI', 'KL', 'KLE', 'KM', 'KN',
            'KO', 'KR', 'KS', 'KT', 'KU', 'KUS', 'KW', 'KY', 'KZ',
            'L', 'LA', 'LAU', 'LB', 'LC', 'LD', 'LDK', 'LE', 'LEV', 'LG', 'LH', 'LI', 'LIF',
            'LIP', 'LL', 'LM', 'LN', 'LO', 'LP', 'LR', 'LS', 'LSA', 'LSN', 'LSZ', 'LU', 'LW',
            'LWL', 'LZ',
            'M', 'MA', 'MAB', 'MAI', 'MAL', 'MB', 'MC', 'MD', 'ME', 'MEG', 'MEI', 'MET', 'MG',
            'MH', 'MI', 'MIL', 'MK', 'ML', 'MM', 'MN', 'MO', 'MOD', 'MOS', 'MQ', 'MR', 'MS',
            'MSH', 'MSP', 'MST', 'MU', 'MV', 'MW', 'MY', 'MZ', 'MZG',
            'N', 'NB', 'ND', 'NE', 'NEA', 'NES', 'NEW', 'NF', 'NH', 'NI', 'NK', 'NL', 'NM',
            'NMS', 'NOH', 'NOM', 'NOR', 'NP', 'NR', 'NU', 'NV', 'NW', 'NY', 'NZ',
            'OA', 'OAL', 'OB', 'OBB', 'OBG', 'OC', 'OD', 'OE', 'OF', 'OG', 'OH', 'OHA', 'OHV',
            'OHZ', 'OK', 'OL', 'OM', 'ON', 'OP', 'OR', 'OS', 'OSL', 'OZ',
            'P', 'PA', 'PAF', 'PAN', 'PAR', 'PB', 'PC', 'PE', 'PEI', 'PF', 'PI', 'PIR', 'PK',
            'PL', 'PLO', 'PM', 'PN', 'PO', 'PR', 'PS', 'PU', 'PW', 'PZ',
            'QLB', 'QR',
            'R', 'RA', 'RB', 'RC', 'RD', 'RE', 'REG', 'RH', 'RI', 'RID', 'RN', 'RO', 'ROD',
            'ROF', 'ROL', 'ROS', 'ROT', 'RP', 'RS', 'RSL', 'RT', 'RU', 'RV', 'RW', 'RZ',
            'S', 'SAB', 'SAD', 'SAL', 'SAW', 'SB', 'SC', 'SCZ', 'SE', 'SEB', 'SEE', 'SEF', 'SEL',
            'SF', 'SG', 'SH', 'SI', 'SIG', 'SIM', 'SK', 'SL', 'SLE', 'SLF', 'SLK', 'SLN', 'SLS',
            'SM', 'SN', 'SO', 'SOB', 'SOG', 'SOK', 'SOM', 'SON', 'SP', 'SPB', 'SPN', 'SR', 'SRB',
            'SRO', 'ST', 'STA', 'STB', 'STD', 'STE', 'STL', 'STO', 'STP', 'STR', 'STS', 'STU',
            'STV', 'STW', 'SU', 'SUD', 'SV', 'SW', 'SY', 'SZ', 'SZB',
            'T', 'TA', 'TAB', 'TB', 'TC', 'TD', 'TE', 'TET', 'TF', 'TG', 'TH', 'THW', 'TIR',
            'TK', 'TL', 'TM', 'TN', 'TO', 'TOL', 'TOP', 'TP', 'TR', 'TS', 'TT', 'TU', 'TUB',
            'TUT', 'TV', 'TW', 'TZ',
            'UE', 'UEM', 'UH', 'UL', 'UM', 'UN', 'UP', 'UR', 'US', 'UZ',
            'V', 'VB', 'VEC', 'VER', 'VG', 'VIB', 'VK', 'VL', 'VOH', 'VR', 'VS', 'VW',
            'W', 'WA', 'WAF', 'WAI', 'WAK', 'WAN', 'WAR', 'WAS', 'WAT', 'WB', 'WC', 'WD', 'WE',
            'WEB', 'WED', 'WEL', 'WEN', 'WER', 'WES', 'WF', 'WG', 'WH', 'WHV', 'WI', 'WIL', 'WIS',
            'WIT', 'WIZ', 'WK', 'WL', 'WM', 'WMS', 'WN', 'WO', 'WOB', 'WOH', 'WOL', 'WOR', 'WOS',
            'WP', 'WR', 'WS', 'WSF', 'WST', 'WSW', 'WT', 'WTM', 'WU', 'WUG', 'WV', 'WW', 'WX',
            'WY', 'WZ',
            'X', 'XK',
            'Y', 'YG',
            'Z', 'ZE', 'ZEL', 'ZI', 'ZIG', 'ZP', 'ZR', 'ZS', 'ZU', 'ZV', 'ZW', 'ZZ'
        ];
        
        if (!in_array($districtCode, $validDistrictCodes)) {
            return [
                'valid' => false,
                'error' => 'Ungültiger Bezirkscode: ' . $districtCode
            ];
        }
        
        return [
            'valid' => true,
            'error' => ''
        ];
    }
}