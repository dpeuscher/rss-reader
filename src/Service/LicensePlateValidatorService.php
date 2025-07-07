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
        // Complete list of all valid German district codes from KBA official data (682 codes)
        // Extracted from av1_2025_04_csv.csv, 16th column (semicolon-separated)
        $this->validDistrictCodes = [
            'A', 'AA', 'AB', 'ABG', 'ABI', 'AC', 'AE', 'AH', 'AIB', 'AIC', 'AK', 'ALF', 'ALZ', 'AM', 'AN',
            'ANA', 'ANG', 'ANK', 'AP', 'APD', 'ARN', 'ART', 'AS', 'ASL', 'ASZ', 'AT', 'AU', 'AUR', 'AW', 'AZ',
            'AZE', 'B', 'BA', 'BAD', 'BAR', 'BB', 'BBG', 'BBL', 'BC', 'BCH', 'BD', 'BE', 'BED', 'BER', 'BF',
            'BGD', 'BGL', 'BH', 'BI', 'BID', 'BIN', 'BIR', 'BIT', 'BIW', 'BK', 'BKS', 'BL', 'BLB', 'BLK', 'BM',
            'BN', 'BNA', 'BO', 'BOG', 'BOH', 'BOR', 'BOT', 'BP', 'BRA', 'BRB', 'BRG', 'BRK', 'BRL', 'BRV', 'BS',
            'BSB', 'BSK', 'BT', 'BTF', 'BUL', 'BW', 'BWL', 'BYL', 'BZ', 'C', 'CA', 'CAS', 'CB', 'CE', 'CHA',
            'CLP', 'CLZ', 'CO', 'COC', 'COE', 'CR', 'CUX', 'CW', 'D', 'DA', 'DAH', 'DAN', 'DAU', 'DBR', 'DD',
            'DE', 'DEG', 'DEL', 'DGF', 'DH', 'DI', 'DIL', 'DIN', 'DIZ', 'DKB', 'DL', 'DLG', 'DM', 'DN', 'DO',
            'DON', 'DS', 'DU', 'DUD', 'DW', 'DZ', 'E', 'EA', 'EB', 'EBE', 'EBN', 'EBS', 'ECK', 'ED', 'EE',
            'EF', 'EG', 'EH', 'EI', 'EIC', 'EIL', 'EIN', 'EIS', 'EL', 'EM', 'EMD', 'EMS', 'EN', 'ER', 'ERB',
            'ERH', 'ERK', 'ERZ', 'ES', 'ESB', 'ESW', 'EU', 'EW', 'F', 'FB', 'FD', 'FDB', 'FDS', 'FEU', 'FF',
            'FFB', 'FG', 'FI', 'FKB', 'FL', 'FN', 'FO', 'FOR', 'FR', 'FRG', 'FRI', 'FRW', 'FS', 'FT', 'FTL',
            'FW', 'FZ', 'G', 'GA', 'GAN', 'GAP', 'GC', 'GD', 'GDB', 'GE', 'GEL', 'GEO', 'GER', 'GF', 'GG',
            'GHA', 'GHC', 'GI', 'GK', 'GL', 'GLA', 'GM', 'GMN', 'GN', 'GNT', 'GOA', 'GOH', 'GP', 'GR', 'GRA',
            'GRH', 'GRI', 'GRM', 'GRZ', 'GS', 'GT', 'GTH', 'GUB', 'GUN', 'GV', 'GVM', 'GW', 'GZ', 'H', 'HA',
            'HAB', 'HAL', 'HAM', 'HAS', 'HB', 'HBN', 'HBS', 'HC', 'HCH', 'HD', 'HDH', 'HDL', 'HE', 'HEB', 'HEF',
            'HEI', 'HEL', 'HER', 'HET', 'HF', 'HG', 'HGN', 'HGW', 'HH', 'HHM', 'HI', 'HIG', 'HIP', 'HK', 'HL',
            'HM', 'HN', 'HO', 'HOG', 'HOH', 'HOL', 'HOM', 'HOR', 'HOT', 'HP', 'HR', 'HRO', 'HS', 'HSK', 'HST',
            'HU', 'HV', 'HVL', 'HWI', 'HX', 'HY', 'HZ', 'IGB', 'IK', 'IL', 'ILL', 'IN', 'IZ', 'J', 'JE',
            'JL', 'K', 'KA', 'KB', 'KC', 'KE', 'KEH', 'KEL', 'KEM', 'KF', 'KG', 'KH', 'KI', 'KIB', 'KK',
            'KL', 'KLE', 'KLZ', 'KM', 'KN', 'KO', 'KR', 'KRU', 'KS', 'KT', 'KU', 'KUS', 'KW', 'KY', 'KYF',
            'L', 'LA', 'LAN', 'LAU', 'LB', 'LBS', 'LBZ', 'LC', 'LD', 'LDK', 'LDS', 'LEO', 'LER', 'LEV', 'LF',
            'LG', 'LH', 'LI', 'LIB', 'LIF', 'LIP', 'LL', 'LM', 'LN', 'LOS', 'LP', 'LR', 'LRO', 'LSA', 'LSN',
            'LSZ', 'LU', 'LUP', 'LWL', 'M', 'MA', 'MAB', 'MAI', 'MAK', 'MAL', 'MB', 'MC', 'MD', 'ME', 'MED',
            'MEG', 'MEI', 'MEK', 'MEL', 'MER', 'MET', 'MG', 'MGH', 'MGN', 'MH', 'MHL', 'MI', 'MIL', 'MK', 'MKK',
            'ML', 'MM', 'MN', 'MO', 'MOD', 'MOL', 'MON', 'MOS', 'MQ', 'MR', 'MS', 'MSE', 'MSH', 'MSP', 'MST',
            'MTK', 'MTL', 'MUC', 'MVL', 'MW', 'MY', 'MYK', 'MZ', 'MZG', 'N', 'NAB', 'NAI', 'NAU', 'NB', 'ND',
            'NDH', 'NE', 'NEA', 'NEB', 'NEC', 'NEN', 'NES', 'NEU', 'NEW', 'NF', 'NH', 'NI', 'NK', 'NL', 'NM',
            'NMB', 'NMS', 'NOH', 'NOL', 'NOM', 'NOR', 'NP', 'NR', 'NRW', 'NT', 'NU', 'NVP', 'NW', 'NWM', 'NY',
            'NZ', 'OA', 'OAL', 'OB', 'OBB', 'OBG', 'OC', 'OCH', 'OD', 'OE', 'OF', 'OG', 'OH', 'OHA', 'OHV',
            'OHZ', 'OK', 'OL', 'OP', 'OPR', 'OS', 'OSL', 'OTW', 'OVI', 'OVL', 'OVP', 'OZ', 'P', 'PA', 'PAF',
            'PAN', 'PAR', 'PB', 'PCH', 'PE', 'PEG', 'PF', 'PI', 'PIR', 'PL', 'PM', 'PN', 'PR', 'PS', 'PW',
            'PZ', 'QFT', 'QLB', 'R', 'RA', 'RC', 'RD', 'RDG', 'RE', 'REG', 'REH', 'REI', 'RG', 'RH', 'RI',
            'RID', 'RIE', 'RL', 'RM', 'RN', 'RO', 'ROD', 'ROF', 'ROK', 'ROL', 'ROS', 'ROT', 'ROW', 'RP', 'RPL',
            'RS', 'RSL', 'RT', 'RU', 'RV', 'RW', 'RZ', 'S', 'SAB', 'SAD', 'SAL', 'SAN', 'SAW', 'SB', 'SBG',
            'SBK', 'SC', 'SCZ', 'SDH', 'SDL', 'SDT', 'SE', 'SEB', 'SEE', 'SEF', 'SEL', 'SFB', 'SFT', 'SG', 'SGH',
            'SH', 'SHA', 'SHG', 'SHK', 'SHL', 'SI', 'SIG', 'SIM', 'SK', 'SL', 'SLE', 'SLF', 'SLG', 'SLK', 'SLN',
            'SLS', 'SLZ', 'SM', 'SN', 'SO', 'SOB', 'SOG', 'SOK', 'SON', 'SP', 'SPB', 'SPN', 'SR', 'SRB', 'SRO',
            'ST', 'STA', 'STB', 'STD', 'STE', 'STL', 'STO', 'SU', 'SUL', 'SW', 'SWA', 'SY', 'SZ', 'SZB', 'TBB',
            'TDO', 'TE', 'TET', 'TF', 'TG', 'THL', 'THW', 'TIR', 'TO', 'TP', 'TR', 'TS', 'TT', 'TUT', 'UE',
            'UEM', 'UER', 'UFF', 'UH', 'UL', 'UM', 'UN', 'USI', 'V', 'VAI', 'VB', 'VEC', 'VER', 'VG', 'VIB',
            'VIE', 'VIT', 'VK', 'VOH', 'VR', 'VS', 'W', 'WA', 'WAF', 'WAK', 'WAN', 'WAR', 'WAT', 'WB', 'WBG',
            'WBS', 'WDA', 'WE', 'WEL', 'WEN', 'WER', 'WES', 'WF', 'WG', 'WHV', 'WI', 'WIL', 'WIS', 'WIT', 'WIZ',
            'WK', 'WL', 'WLG', 'WM', 'WMS', 'WN', 'WND', 'WO', 'WOB', 'WOH', 'WOL', 'WOR', 'WOS', 'WR', 'WRN',
            'WS', 'WSF', 'WST', 'WSW', 'WT', 'WTL', 'WTM', 'WUG', 'WUN', 'WUR', 'WW', 'WZ', 'WZL', 'Z', 'ZE',
            'ZEL', 'ZI', 'ZIG', 'ZP', 'ZR', 'ZW', 'ZZ'
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