<?php

namespace App\Service;

/**
 * Service for validating German license plates.
 * 
 * This service provides validation for German license plates according to
 * the official format requirements and district codes.
 */
class LicensePlateValidatorService
{
    private array $validDistrictCodes;

    public function __construct()
    {
        // Updated comprehensive list of German license plate district codes (2024/2025)
        // Based on official KBA (Kraftfahrt-Bundesamt) data and current registrations
        $this->validDistrictCodes = [
            // A
            'A', 'AA', 'AB', 'ABG', 'AC', 'AD', 'AE', 'AF', 'AG', 'AH', 'AI', 'AIC', 'AK', 'ALF', 'ALZ',
            'AM', 'AN', 'ANA', 'ANG', 'ANK', 'AO', 'AP', 'APD', 'ARN', 'ART', 'AS', 'ASL', 'ASZ', 'AUR',
            'AW', 'AZ',
            
            // B
            'B', 'BA', 'BAD', 'BAR', 'BB', 'BC', 'BD', 'BE', 'BF', 'BG', 'BGL', 'BH', 'BI', 'BIR', 'BIT',
            'BK', 'BL', 'BLB', 'BLK', 'BM', 'BN', 'BO', 'BOR', 'BOT', 'BP', 'BRA', 'BRB', 'BRG', 'BRK',
            'BRV', 'BS', 'BT', 'BU', 'BUL', 'BUR', 'BW', 'BZ',
            
            // C
            'C', 'CA', 'CB', 'CD', 'CE', 'CG', 'CH', 'CLP', 'CM', 'CO', 'COC', 'COE', 'CR', 'CW', 'CUX',
            
            // D
            'D', 'DA', 'DAH', 'DAN', 'DAU', 'DB', 'DD', 'DE', 'DEG', 'DEL', 'DH', 'DI', 'DIL', 'DIN',
            'DL', 'DM', 'DN', 'DO', 'DON', 'DU', 'DW',
            
            // E
            'E', 'EA', 'EB', 'ED', 'EE', 'EF', 'EG', 'EH', 'EI', 'EIC', 'EIN', 'EL', 'EM', 'EN', 'ER',
            'ERB', 'ERH', 'ERK', 'ERZ', 'ES', 'ESW', 'EU', 'EW',
            
            // F
            'F', 'FB', 'FD', 'FDB', 'FDS', 'FEU', 'FF', 'FFB', 'FG', 'FH', 'FI', 'FIT', 'FL', 'FN',
            'FO', 'FOR', 'FR', 'FRG', 'FRI', 'FRW', 'FS', 'FT', 'FU', 'FUE', 'FW',
            
            // G
            'G', 'GA', 'GAN', 'GAP', 'GC', 'GD', 'GDB', 'GE', 'GEL', 'GEO', 'GER', 'GF', 'GG', 'GH',
            'GI', 'GK', 'GL', 'GM', 'GMN', 'GN', 'GNT', 'GO', 'GOA', 'GOH', 'GP', 'GR', 'GRH', 'GRI',
            'GRM', 'GRZ', 'GS', 'GT', 'GU', 'GUB', 'GUN', 'GV', 'GW', 'GZ',
            
            // H
            'H', 'HA', 'HAL', 'HAM', 'HAS', 'HB', 'HBN', 'HBS', 'HC', 'HD', 'HE', 'HEF', 'HEI', 'HEL',
            'HEN', 'HER', 'HET', 'HF', 'HG', 'HGN', 'HGW', 'HH', 'HI', 'HIG', 'HIP', 'HK', 'HL', 'HM',
            'HMÜ', 'HN', 'HO', 'HOG', 'HOH', 'HOM', 'HOR', 'HOT', 'HP', 'HR', 'HRO', 'HS', 'HSK', 'HST',
            'HU', 'HV', 'HVL', 'HW', 'HWI', 'HX', 'HY', 'HZ',
            
            // I
            'IK', 'IL', 'ILL', 'IN', 'IZ',
            
            // J
            'J', 'JE', 'JL', 'JÜL',
            
            // K
            'K', 'KA', 'KB', 'KC', 'KE', 'KEH', 'KF', 'KG', 'KH', 'KI', 'KIB', 'KK', 'KL', 'KLE', 'KLZ',
            'KM', 'KN', 'KO', 'KÖT', 'KR', 'KRU', 'KS', 'KT', 'KU', 'KÜN', 'KUS', 'KW', 'KY', 'KYF', 'KZ',
            
            // L
            'L', 'LA', 'LAU', 'LB', 'LC', 'LD', 'LDK', 'LE', 'LEV', 'LF', 'LG', 'LH', 'LI', 'LIB', 'LIF',
            'LIN', 'LIP', 'LL', 'LM', 'LN', 'LO', 'LÖR', 'LP', 'LR', 'LS', 'LSA', 'LSN', 'LSZ', 'LU',
            'LUN', 'LUP', 'LW', 'LWL', 'LZ',
            
            // M
            'M', 'MA', 'MAB', 'MAI', 'MAL', 'MAR', 'MB', 'MC', 'MD', 'ME', 'MEG', 'MEI', 'MEK', 'MET',
            'MG', 'MH', 'MI', 'MIL', 'MK', 'ML', 'MM', 'MN', 'MO', 'MOD', 'MOL', 'MOS', 'MQ', 'MR',
            'MS', 'MSH', 'MSP', 'MST', 'MU', 'MV', 'MW', 'MY', 'MZ', 'MZG',
            
            // N
            'N', 'NB', 'ND', 'NDH', 'NE', 'NEA', 'NES', 'NEW', 'NF', 'NH', 'NI', 'NK', 'NL', 'NM',
            'NMS', 'NOH', 'NOM', 'NOR', 'NP', 'NR', 'NU', 'NV', 'NW', 'NY', 'NZ',
            
            // O
            'OA', 'OAL', 'OB', 'OBB', 'OBG', 'OC', 'OD', 'OE', 'OF', 'OG', 'OH', 'OHA', 'OHV', 'OHZ',
            'OK', 'OL', 'OM', 'ON', 'OP', 'OR', 'OS', 'OSL', 'OZ',
            
            // P
            'P', 'PA', 'PAF', 'PAN', 'PAR', 'PB', 'PC', 'PE', 'PEI', 'PF', 'PI', 'PIR', 'PK', 'PL',
            'PLÖ', 'PLO', 'PM', 'PN', 'PO', 'PR', 'PS', 'PU', 'PW', 'PZ',
            
            // Q
            'QLB', 'QR',
            
            // R
            'R', 'RA', 'RB', 'RC', 'RD', 'RE', 'REG', 'REH', 'REI', 'RG', 'RH', 'RI', 'RID', 'RN', 'RO',
            'ROD', 'ROF', 'ROL', 'ROS', 'ROT', 'RP', 'RS', 'RSL', 'RT', 'RU', 'RV', 'RW', 'RZ',
            
            // S
            'S', 'SAB', 'SAD', 'SAL', 'SAN', 'SAW', 'SB', 'SC', 'SCZ', 'SE', 'SEB', 'SEE', 'SEF', 'SEL',
            'SF', 'SG', 'SH', 'SI', 'SIG', 'SIM', 'SK', 'SL', 'SLE', 'SLF', 'SLK', 'SLN', 'SLS', 'SM',
            'SN', 'SO', 'SOB', 'SOG', 'SOK', 'SOM', 'SON', 'SP', 'SPB', 'SPN', 'SR', 'SRB', 'SRO', 'ST',
            'STA', 'STB', 'STD', 'STE', 'STL', 'STO', 'STP', 'STR', 'STS', 'STU', 'STV', 'STW', 'SU',
            'SUD', 'SV', 'SW', 'SY', 'SZ', 'SZB',
            
            // T
            'T', 'TA', 'TAB', 'TB', 'TC', 'TD', 'TE', 'TET', 'TF', 'TG', 'TH', 'THW', 'TIR', 'TK',
            'TL', 'TM', 'TN', 'TO', 'TOL', 'TOP', 'TP', 'TR', 'TS', 'TT', 'TU', 'TUB', 'TÜB', 'TUT',
            'TV', 'TW', 'TZ',
            
            // U
            'UE', 'UEM', 'UH', 'UL', 'UM', 'UN', 'UP', 'UR', 'US', 'UZ',
            
            // V
            'V', 'VB', 'VEC', 'VER', 'VG', 'VIB', 'VK', 'VL', 'VOH', 'VR', 'VS', 'VW',
            
            // W
            'W', 'WA', 'WAF', 'WAI', 'WAK', 'WAN', 'WAR', 'WAS', 'WAT', 'WB', 'WC', 'WD', 'WE', 'WEB',
            'WED', 'WEI', 'WEL', 'WEN', 'WER', 'WES', 'WF', 'WG', 'WH', 'WHV', 'WI', 'WIL', 'WIS', 'WIT',
            'WIZ', 'WK', 'WL', 'WM', 'WMS', 'WN', 'WO', 'WOB', 'WOH', 'WOL', 'WOR', 'WOS', 'WP', 'WR',
            'WS', 'WSF', 'WST', 'WSW', 'WT', 'WTM', 'WU', 'WUG', 'WV', 'WW', 'WX', 'WY', 'WZ',
            
            // X
            'X', 'XK',
            
            // Y
            'Y', 'YG',
            
            // Z
            'Z', 'ZE', 'ZEL', 'ZI', 'ZIG', 'ZP', 'ZR', 'ZS', 'ZU', 'ZV', 'ZW', 'ZZ'
        ];
        
        // Convert to associative array for O(1) lookup performance
        $this->validDistrictCodes = array_flip($this->validDistrictCodes);
    }

    /**
     * Validates a German license plate.
     * 
     * @param string $licensePlate The license plate to validate
     * @return array Array containing 'valid' (bool) and 'error' (string) keys
     */
    public function validate(string $licensePlate): array
    {
        $licensePlate = strtoupper(trim($licensePlate));
        
        // German license plate patterns:
        // Format: 1-3 letters (district code) + hyphen + 1-2 letters (series) + space + 1-4 numbers
        // More restrictive pattern to avoid invalid combinations
        $pattern = '/^[A-Z]{1,3}-[A-Z]{1,2}\s[0-9]{1,4}$/';
        
        if (!preg_match($pattern, $licensePlate)) {
            return [
                'valid' => false,
                'error' => 'Ungültiges Format. Erwartetes Format: ABC-XY 1234 (z.B. M-AB 123, HH-XY 1234)'
            ];
        }
        
        // Extract district code for validation
        $parts = explode('-', $licensePlate);
        $districtCode = $parts[0];
        
        // Use array_key_exists for O(1) lookup instead of in_array
        if (!array_key_exists($districtCode, $this->validDistrictCodes)) {
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
?>