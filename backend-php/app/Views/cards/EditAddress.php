<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('normaliseManagedLinkHref')) {
    function normaliseManagedLinkHref(string $href): string
    {
        $href = trim($href);
        if ($href === '') {
            return '';
        }
        if (preg_match('~^(?:https?://|mailto:|/|#)~i', $href) === 1) {
            return $href;
        }
        return 'https://' . ltrim($href, '/');
    }
}

$csrf = h(csrf_token());
$progress = $progress ?? [];
$workflow = $workflow ?? [];
$runtimeSteps = $runtimeSteps ?? [];
$data = is_array($data ?? null) ? $data : [];
$ddPostalAddressesLink = trim((string)($ddPostalAddressesLink ?? ''));
$ddPostalAddressesLabel = trim((string)($ddPostalAddressesLabel ?? ''));
$phoneCountryOptions = [
  '+61' => 'Australia +61',
  '+1' => 'United States/Canada +1',
  '+44' => 'United Kingdom +44',
  '+64' => 'New Zealand +64',
  '+27' => 'South Africa +27',
  '+31' => 'Netherlands +31',
  '+32' => 'Belgium +32',
  '+33' => 'France +33',
  '+34' => 'Spain +34',
  '+39' => 'Italy +39',
  '+41' => 'Switzerland +41',
  '+43' => 'Austria +43',
  '+45' => 'Denmark +45',
  '+46' => 'Sweden +46',
  '+47' => 'Norway +47',
  '+48' => 'Poland +48',
  '+49' => 'Germany +49',
  '+51' => 'Peru +51',
  '+52' => 'Mexico +52',
  '+53' => 'Cuba +53',
  '+54' => 'Argentina +54',
  '+55' => 'Brazil +55',
  '+56' => 'Chile +56',
  '+57' => 'Colombia +57',
  '+58' => 'Venezuela +58',
  '+60' => 'Malaysia +60',
  '+62' => 'Indonesia +62',
  '+63' => 'Philippines +63',
  '+65' => 'Singapore +65',
  '+66' => 'Thailand +66',
  '+81' => 'Japan +81',
  '+82' => 'South Korea +82',
  '+84' => 'Vietnam +84',
  '+86' => 'China +86',
  '+90' => 'Turkey +90',
  '+91' => 'India +91',
  '+92' => 'Pakistan +92',
  '+93' => 'Afghanistan +93',
  '+94' => 'Sri Lanka +94',
  '+95' => 'Myanmar +95',
  '+98' => 'Iran +98',
  '+211' => 'South Sudan +211',
  '+212' => 'Morocco +212',
  '+213' => 'Algeria +213',
  '+216' => 'Tunisia +216',
  '+218' => 'Libya +218',
  '+220' => 'Gambia +220',
  '+221' => 'Senegal +221',
  '+223' => 'Mali +223',
  '+224' => 'Guinea +224',
  '+225' => 'Cote dIvoire +225',
  '+226' => 'Burkina Faso +226',
  '+227' => 'Niger +227',
  '+228' => 'Togo +228',
  '+229' => 'Benin +229',
  '+230' => 'Mauritius +230',
  '+231' => 'Liberia +231',
  '+232' => 'Sierra Leone +232',
  '+233' => 'Ghana +233',
  '+234' => 'Nigeria +234',
  '+235' => 'Chad +235',
  '+236' => 'Central African Republic +236',
  '+237' => 'Cameroon +237',
  '+238' => 'Cape Verde +238',
  '+239' => 'Sao Tome and Principe +239',
  '+240' => 'Equatorial Guinea +240',
  '+241' => 'Gabon +241',
  '+242' => 'Republic of the Congo +242',
  '+243' => 'DR Congo +243',
  '+244' => 'Angola +244',
  '+245' => 'Guinea-Bissau +245',
  '+246' => 'British Indian Ocean Territory +246',
  '+248' => 'Seychelles +248',
  '+249' => 'Sudan +249',
  '+250' => 'Rwanda +250',
  '+251' => 'Ethiopia +251',
  '+252' => 'Somalia +252',
  '+253' => 'Djibouti +253',
  '+254' => 'Kenya +254',
  '+255' => 'Tanzania +255',
  '+256' => 'Uganda +256',
  '+257' => 'Burundi +257',
  '+258' => 'Mozambique +258',
  '+260' => 'Zambia +260',
  '+261' => 'Madagascar +261',
  '+262' => 'Reunion/Mayotte +262',
  '+263' => 'Zimbabwe +263',
  '+264' => 'Namibia +264',
  '+265' => 'Malawi +265',
  '+266' => 'Lesotho +266',
  '+267' => 'Botswana +267',
  '+268' => 'Eswatini +268',
  '+269' => 'Comoros +269',
  '+290' => 'Saint Helena +290',
  '+291' => 'Eritrea +291',
  '+297' => 'Aruba +297',
  '+298' => 'Faroe Islands +298',
  '+299' => 'Greenland +299',
  '+350' => 'Gibraltar +350',
  '+351' => 'Portugal +351',
  '+352' => 'Luxembourg +352',
  '+353' => 'Ireland +353',
  '+354' => 'Iceland +354',
  '+355' => 'Albania +355',
  '+356' => 'Malta +356',
  '+357' => 'Cyprus +357',
  '+358' => 'Finland +358',
  '+359' => 'Bulgaria +359',
  '+370' => 'Lithuania +370',
  '+371' => 'Latvia +371',
  '+372' => 'Estonia +372',
  '+373' => 'Moldova +373',
  '+374' => 'Armenia +374',
  '+375' => 'Belarus +375',
  '+376' => 'Andorra +376',
  '+377' => 'Monaco +377',
  '+378' => 'San Marino +378',
  '+380' => 'Ukraine +380',
  '+381' => 'Serbia +381',
  '+382' => 'Montenegro +382',
  '+383' => 'Kosovo +383',
  '+385' => 'Croatia +385',
  '+386' => 'Slovenia +386',
  '+387' => 'Bosnia and Herzegovina +387',
  '+389' => 'North Macedonia +389',
  '+420' => 'Czech Republic +420',
  '+421' => 'Slovakia +421',
  '+423' => 'Liechtenstein +423',
  '+500' => 'Falkland Islands +500',
  '+501' => 'Belize +501',
  '+502' => 'Guatemala +502',
  '+503' => 'El Salvador +503',
  '+504' => 'Honduras +504',
  '+505' => 'Nicaragua +505',
  '+506' => 'Costa Rica +506',
  '+507' => 'Panama +507',
  '+508' => 'Saint Pierre and Miquelon +508',
  '+509' => 'Haiti +509',
  '+590' => 'Guadeloupe +590',
  '+591' => 'Bolivia +591',
  '+592' => 'Guyana +592',
  '+593' => 'Ecuador +593',
  '+594' => 'French Guiana +594',
  '+595' => 'Paraguay +595',
  '+596' => 'Martinique +596',
  '+597' => 'Suriname +597',
  '+598' => 'Uruguay +598',
  '+599' => 'Curacao/Caribbean Netherlands +599',
  '+670' => 'Timor-Leste +670',
  '+672' => 'Antarctica +672',
  '+673' => 'Brunei +673',
  '+674' => 'Nauru +674',
  '+675' => 'Papua New Guinea +675',
  '+676' => 'Tonga +676',
  '+677' => 'Solomon Islands +677',
  '+678' => 'Vanuatu +678',
  '+679' => 'Fiji +679',
  '+680' => 'Palau +680',
  '+681' => 'Wallis and Futuna +681',
  '+682' => 'Cook Islands +682',
  '+683' => 'Niue +683',
  '+685' => 'Samoa +685',
  '+686' => 'Kiribati +686',
  '+687' => 'New Caledonia +687',
  '+688' => 'Tuvalu +688',
  '+689' => 'French Polynesia +689',
  '+690' => 'Tokelau +690',
  '+691' => 'Micronesia +691',
  '+692' => 'Marshall Islands +692',
  '+850' => 'North Korea +850',
  '+852' => 'Hong Kong +852',
  '+853' => 'Macau +853',
  '+855' => 'Cambodia +855',
  '+856' => 'Laos +856',
  '+880' => 'Bangladesh +880',
  '+886' => 'Taiwan +886',
  '+960' => 'Maldives +960',
  '+961' => 'Lebanon +961',
  '+962' => 'Jordan +962',
  '+963' => 'Syria +963',
  '+964' => 'Iraq +964',
  '+965' => 'Kuwait +965',
  '+966' => 'Saudi Arabia +966',
  '+967' => 'Yemen +967',
  '+968' => 'Oman +968',
  '+970' => 'Palestine +970',
  '+971' => 'United Arab Emirates +971',
  '+972' => 'Israel +972',
  '+973' => 'Bahrain +973',
  '+974' => 'Qatar +974',
  '+975' => 'Bhutan +975',
  '+976' => 'Mongolia +976',
  '+977' => 'Nepal +977',
  '+992' => 'Tajikistan +992',
  '+993' => 'Turkmenistan +993',
  '+994' => 'Azerbaijan +994',
  '+995' => 'Georgia +995',
  '+996' => 'Kyrgyzstan +996',
  '+998' => 'Uzbekistan +998',
];
$cardId = (int)($cardId ?? 0);
$cardType = (string)($cardType ?? '');
$cardNumber = trim((string)($cardNumber ?? ''));
$cardNumberMasked = $cardNumber !== '' ? ('************' . substr($cardNumber, -4)) : '';
$pendingAddress = (bool)($pendingAddress ?? false);
$pendingCancel = (bool)($pendingCancel ?? false);
$submissionToken = trim((string)($submissionToken ?? ''));
$historyRows = $historyRows ?? [];
$mobileNumberHoverText = trim((string)($mobileNumberHoverText ?? ''));
$workPostalAddressHoverText = trim((string)($workPostalAddressHoverText ?? ''));
$suburbDatasetVersion = '';
$suburbDatasetPath = __DIR__ . '/../../../public/assets/data/au_suburbs.json';
if (is_file($suburbDatasetPath)) {
    $suburbDatasetVersion = (string)(filemtime($suburbDatasetPath) ?: '');
}
$rawMobile = trim((string)($data['mobile'] ?? ''));
$mobileCountryCode = trim((string)($data['mobile_country_code'] ?? '+61'));
$mobileLocalValue = $rawMobile;
$workPhoneValue = trim((string)($data['work_phone'] ?? ''));
$emailValue = trim((string)($data['email'] ?? ''));
if ($workPhoneValue === '' && trim((string)($data['phone'] ?? '')) !== '') {
    $workPhoneValue = trim((string)($data['phone'] ?? ''));
}
$workPhoneLocked = str_starts_with(preg_replace('/\D+/', '', $workPhoneValue) ?? '', '025');
if ($emailValue === '') {
    $emailValue = trim((string)($data['email_address'] ?? ''));
}
if ($emailValue !== '') {
    $emailValue = strtolower($emailValue);
}
$phoneCountryCodes = array_keys($phoneCountryOptions);
usort($phoneCountryCodes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

if ($rawMobile !== '') {
    $selectedCode = isset($phoneCountryOptions[$mobileCountryCode]) ? $mobileCountryCode : '';
    if ($selectedCode !== '') {
        $selectedDigitsCode = ltrim($selectedCode, '+');
        if (str_starts_with($rawMobile, $selectedCode)) {
            $mobileLocalValue = trim(substr($rawMobile, strlen($selectedCode)));
        } elseif ($selectedDigitsCode !== '' && str_starts_with($rawMobile, $selectedDigitsCode)) {
            $mobileLocalValue = trim(substr($rawMobile, strlen($selectedDigitsCode)));
        }
    } elseif (str_starts_with($rawMobile, '+')) {
        foreach ($phoneCountryCodes as $code) {
            if (str_starts_with($rawMobile, $code)) {
                $mobileCountryCode = $code;
                $mobileLocalValue = trim(substr($rawMobile, strlen($code)));
                break;
            }
        }
    }
}
?>
<style>
  .form-control.readonly-field { background-color:#f8f9fa; color:#495057; cursor:not-allowed; }
  .readonly-hint { font-size:0.8rem; color:#6c757d; }
</style>

<section class="container-fluid mt-4" aria-labelledby="editAddressHeading">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h1 class="h3 mb-1" id="editAddressHeading">Edit Contact details</h1>
      <div class="text-muted small">
        <?php if ($cardType !== ''): ?>
          · Card Type: <?= h($cardType) ?>
        <?php endif; ?>
        <?php if ($cardNumberMasked !== ''): ?>
          · Card Number: <?= h($cardNumberMasked) ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="index.php?route=home/index" id="backToCardsBtn">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Cards
      </a>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-4">
      <section class="card shadow-sm mb-3" aria-labelledby="editAddressProgressHeading">
        <div class="card-header">
          <h2 class="h5 mb-0" id="editAddressProgressHeading">Progress</h2>
        </div>
        <div class="card-body">
          <div class="list-group list-group-flush" role="list" aria-label="Address update progress">
            <?php foreach ($progress as $p): ?>
              <?php
                $isActive = !empty($p['IsActive']);
                $done = !empty($p['Complete']);
              ?>
              <div class="list-group-item d-flex justify-content-between align-items-center" role="listitem">
                <div>
                  <?php if ($done): ?>
                    <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                  <?php else: ?>
                    <i class="bi bi-circle text-muted me-2" aria-hidden="true"></i>
                  <?php endif; ?>
                  <span class="<?= $isActive ? 'fw-semibold' : '' ?>">
                    <?= h((string)($p['Label'] ?? 'Step')) ?>
                  </span>
                  <?php if ($isActive): ?>
                    <span class="badge bg-primary ms-2">Current</span>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section class="card shadow-sm mb-3" aria-labelledby="editAddressChecklistHeading">
        <div class="card-header">
          <h2 class="h5 mb-0" id="editAddressChecklistHeading">Checklist</h2>
          <div class="text-muted small">Auto-evaluated from the form.</div>
        </div>
        <div class="card-body">
          <div class="list-group" role="list" aria-label="Address update checklist">
            <?php foreach ($workflow as $ws): ?>
              <?php
                $key = strtolower(trim((string)($ws['StepKey'] ?? '')));
                $rt = $runtimeSteps[$key] ?? null;
                $state = null;
                if ($rt && !empty($rt['LastSavedAt'])) {
                    $state = ((int)($rt['IsComplete'] ?? 0) === 1) ? 'pass' : 'fail';
                }
                $icon = match ($state) {
                    'pass' => '<i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>',
                    'fail' => '<i class="bi bi-x-circle-fill text-danger me-2" aria-hidden="true"></i>',
                    default => '<i class="bi bi-circle text-muted me-2" aria-hidden="true"></i>',
                };
              ?>
              <div class="list-group-item d-flex align-items-center" data-step-key="<?= h($key) ?>" role="listitem">
                <span class="check-icon" data-step-key="<?= h($key) ?>"><?= $icon ?></span>
                <span><?= h((string)($ws['StepLabel'] ?? $key)) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    </div>

    <div class="col-lg-8">
      <?php if ($pendingCancel): ?>
        <div class="alert alert-warning" role="alert">
          A cancel request is already submitted for this card. Address updates are blocked until it is completed.
        </div>
      <?php endif; ?>
      <?php if ($pendingAddress): ?>
        <div class="alert alert-info" role="status" aria-live="polite">
          An address change is already submitted for this card. You can view the history below.
        </div>
      <?php endif; ?>
      <div class="alert alert-danger d-none" id="addressValidationFlash" role="alert">
        Please correct the highlighted fields before submitting the address change.
      </div>
      <form method="post" action="index.php?route=cards/change-address-save&id=<?= h((string)$cardId) ?>" class="js-submit-feedback-form" aria-describedby="editAddressHeading">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="apply_all_cards" id="applyAllCardsInput" value="1">
        <input type="hidden" name="submission_token" value="<?= h($submissionToken) ?>">

        <section class="card shadow-sm mb-3" aria-labelledby="workPostalAddressHeading">
          <div class="card-header">
            <h2 class="h5 mb-0">
              <span id="workPostalAddressHeading">Work Postal Address</span>
              <?php if ($workPostalAddressHoverText !== ''): ?>
                <span
                  class="ms-1 text-info"
                  aria-hidden="true"
                  data-bs-toggle="tooltip"
                  data-bs-placement="top"
                  title="<?= h($workPostalAddressHoverText) ?>"
                >
                  <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                </span>
              <?php endif; ?>
            </h2>
            <?php if ($workPostalAddressHoverText !== ''): ?>
              <div class="visually-hidden" id="workPostalAddressInfo"><?= h($workPostalAddressHoverText) ?></div>
            <?php endif; ?>
            <?php if ($ddPostalAddressesLink !== ''): ?>
              <?php
                $ddPostalHref = normaliseManagedLinkHref($ddPostalAddressesLink);
                $ddPostalIsExternal = preg_match('~^https?://~i', $ddPostalHref) === 1;
                $ddPostalRel = $ddPostalIsExternal ? ' target="_blank" rel="noopener noreferrer"' : '';
                $ddPostalLabel = $ddPostalAddressesLabel !== '' ? $ddPostalAddressesLabel : 'DD Postal Addresses';
              ?>
              <?php if ($ddPostalHref !== ''): ?>
                <div class="small mt-2">
                  <a href="<?= h($ddPostalHref) ?>"<?= $ddPostalRel ?>><?= h($ddPostalLabel) ?></a>
                </div>
                <div class="small text-muted mt-1">
                  This list is maintained by <a href="https://dpeintranet-seg.defence.gov.au/services-support/corporate-services/defence-mail" target="_blank" rel="noopener noreferrer">Defence Mail</a>. Contact <a href="mailto:defence.mail@defence.gov.au">defence.mail@defence.gov.au</a> for any enquiries.
                </div>
              <?php endif; ?>
            <?php endif; ?>
            <div class="text-muted small">Fields marked <span class="text-danger">*</span> are required.</div>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label" for="address1">Address Line 1 <span class="text-danger">*</span></label>
                <input class="form-control" id="address1" name="address1" value="<?= h((string)($data['address1'] ?? '')) ?>" aria-describedby="address1LenHelp address1ReqError address1LenError<?= $workPostalAddressHoverText !== '' ? ' workPostalAddressInfo' : '' ?>">
                <div id="address1LenHelp" class="form-text"></div>
                <div id="address1ReqError" class="invalid-feedback" style="display:none;">Address Line 1 is required.</div>
                <div id="address1LenError" class="invalid-feedback" style="display:none;">Address Line 1 must be 30 characters or less.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="address2">Address Line 2</label>
                <input class="form-control" id="address2" name="address2" value="<?= h((string)($data['address2'] ?? '')) ?>" aria-describedby="address2LenHelp address2LenError">
                <div id="address2LenHelp" class="form-text"></div>
                <div id="address2LenError" class="invalid-feedback">Address Line 2 must be 30 characters or less.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="address3">Address Line 3</label>
                <input class="form-control" id="address3" name="address3" value="<?= h((string)($data['address3'] ?? '')) ?>" aria-describedby="address3LenHelp addressLenError address3LenError">
                <div id="address3LenHelp" class="form-text"></div>
                <div id="addressLenError" class="invalid-feedback" style="display:none;">One or more address lines exceed 30 characters.</div>
                <div id="address3LenError" class="invalid-feedback" style="display:none;">Address Line 3 must be 30 characters or less.</div>
              </div>

              <div class="col-md-4">
                <label class="form-label" for="stateSelect">State <span class="text-danger">*</span></label>
                <select class="form-select" name="state" id="stateSelect" aria-describedby="stateError">
                  <option value="">Select a state</option>
                  <?php foreach (['ACT','NSW','NT','QLD','SA','TAS','VIC','WA'] as $st): ?>
                    <option value="<?= h($st) ?>" <?= ((string)($data['state'] ?? '') === $st) ? 'selected' : '' ?>>
                      <?= h($st) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div id="stateError" class="invalid-feedback">State is required.</div>
              </div>

              <div class="col-md-4">
                <label class="form-label" for="postcode">Postcode <span class="text-danger">*</span></label>
                <input class="form-control" id="postcode" name="postcode" value="<?= h((string)($data['postcode'] ?? '')) ?>" aria-describedby="postcodeNumError">
                <div id="postcodeNumError" class="invalid-feedback">Postcode must contain only numbers and be 4 digits or less.</div>
              </div>

              <div class="col-md-4">
                <label class="form-label" for="suburb">Suburb <span class="text-danger">*</span></label>
                <input class="form-control"
                       id="suburb"
                       name="suburb"
                       value="<?= h((string)($data['suburb'] ?? '')) ?>"
                       list="suburbSuggestions"
                       maxlength="21"
                       autocomplete="off"
                       placeholder="Search or enter suburb manually"
                       aria-describedby="suburbLenError suburbHelpText">
                <datalist id="suburbSuggestions"></datalist>
                <div id="suburbLenError" class="invalid-feedback">Suburb must be 21 characters or less.</div>
                <div class="form-text" id="suburbHelpText">Entering State and Post Code will filter the Suburbs.</div>
              </div>
            </div>
          </div>
        </section>

        <section class="card shadow-sm mb-3" aria-labelledby="contactDetailsHeading">
          <div class="card-header">
            <h2 class="h5 mb-0" id="contactDetailsHeading">Contact Details</h2>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">
                  Mobile Number <span class="text-danger">*</span>
                  <?php if ($mobileNumberHoverText !== ''): ?>
                    <span
                      class="ms-1 text-info"
                      aria-hidden="true"
                      data-bs-toggle="tooltip"
                      data-bs-placement="top"
                      title="<?= h($mobileNumberHoverText) ?>"
                    >
                      <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                    </span>
                  <?php endif; ?>
                </label>
                <?php if ($mobileNumberHoverText !== ''): ?>
                  <div class="visually-hidden" id="mobileInfoText"><?= h($mobileNumberHoverText) ?></div>
                <?php endif; ?>
                <div class="input-group">
                  <select class="form-select" id="mobileCountryCode" name="mobile_country_code" style="max-width: 210px;" aria-label="Mobile country code">
                    <?php foreach ($phoneCountryOptions as $code => $label): ?>
                      <option value="<?= h($code) ?>" <?= $mobileCountryCode === $code ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input class="form-control"
                         id="mobile"
                         name="mobile"
                         value="<?= h($mobileLocalValue) ?>"
                         inputmode="tel"
                         maxlength="20"
                         placeholder="Enter mobile number"
                         aria-describedby="<?= $mobileNumberHoverText !== '' ? 'mobileInfoText ' : '' ?>mobileFormatError mobileHelpText">
                </div>
                <div id="mobileFormatError" class="invalid-feedback" style="display:none;">Enter a valid mobile number for the selected country code.</div>
                <div class="form-text" id="mobileHelpText">International mobile numbers are supported.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="workPhone">Work Phone</label>
                <input class="form-control<?= $workPhoneLocked ? ' readonly-field' : '' ?>"
                       id="workPhone"
                       name="work_phone"
                       value="<?= h($workPhoneValue) ?>"
                       inputmode="tel"
                       maxlength="20"
                       <?= $workPhoneLocked ? 'readonly aria-readonly="true"' : '' ?>
                       placeholder="Enter work phone number"
                       aria-describedby="workPhoneFormatError workPhoneHelpText<?= $workPhoneLocked ? ' workPhoneReadonlyHint' : '' ?>">
                <div id="workPhoneFormatError" class="invalid-feedback" style="display:none;">Enter a valid non-mobile work phone number.</div>
                <div class="form-text" id="workPhoneHelpText">Numbers only. Leave blank if no work phone update is needed.</div>
                <?php if ($workPhoneLocked): ?><div class="readonly-hint" id="workPhoneReadonlyHint">This phone number is managed by CAPS and cannot be changed here.</div><?php endif; ?>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="email">
                  Email Address <span class="text-danger">*</span>
                  <i class="bi bi-lock-fill text-muted ms-1" aria-hidden="true"></i>
                </label>
                <input class="form-control readonly-field"
                       id="email"
                       name="email"
                       type="email"
                       maxlength="70"
                       required
                       readonly
                       value="<?= h($emailValue) ?>"
                       placeholder="Enter email address"
                       aria-describedby="emailFormatError">
                <div id="emailFormatError" class="invalid-feedback" style="display:none;">Email address is required and must be valid.</div>
                <div class="readonly-hint">Pre-filled by system</div>
              </div>
            </div>
          </div>
        </section>

        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-success" id="openSubmitAddressModal"
                  <?= ($pendingAddress || $pendingCancel) ? 'disabled' : '' ?>>
            Submit Update
          </button>
        </div>
      </form>

      <!-- Submit confirmation modal -->
      <div class="modal fade" id="submitAddressModal" tabindex="-1" aria-labelledby="submitAddressLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="submitAddressLabel">Submit Address Change</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              Are you sure you want to submit this address change?
              <div class="alert alert-info mt-3 mb-3" role="status" aria-live="polite">
                By default, this address change will be applied to all cards you currently hold, unless you uncheck the box below.
              </div>
              <div class="form-check mt-3">
                <input class="form-check-input" type="checkbox" id="applyAllCardsCheckbox" checked>
                <label class="form-check-label" for="applyAllCardsCheckbox">
                  Apply this address change to all cards I currently hold.
                </label>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-success" id="confirmSubmitAddress">Yes, Submit</button>
            </div>
          </div>
        </div>
      </div>

      <div class="modal fade" id="unsavedChangesModal" tabindex="-1" aria-labelledby="unsavedChangesLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="unsavedChangesLabel">Unsaved Changes</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              You have unsaved changes. Leaving this page will discard them.
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Stay</button>
              <a class="btn btn-danger" href="index.php?route=home/index" id="confirmLeaveBtn">Leave Without Saving</a>
            </div>
          </div>
        </div>
      </div>

      <div class="card shadow-sm mt-3">
        <div class="card-header">
          <strong>Change History</strong>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
              <thead>
                <tr>
                  <th>Request ID</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th>Created</th>
                  <th>Submitted</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$historyRows): ?>
                  <tr>
                    <td colspan="5" class="text-muted text-center">No changes recorded.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($historyRows as $r): ?>
                    <?php
                      $createdAt = trim((string)($r['CreatedAt'] ?? ''));
                      $submittedAt = trim((string)($r['SubmittedAt'] ?? ''));
                      $submittedDisplay = ($submittedAt !== '' && $submittedAt !== $createdAt) ? $submittedAt : '-';
                    ?>
                    <tr>
                      <td><?= h((string)($r['RequestID'] ?? '')) ?></td>
                      <td><?= h((string)($r['RequestType'] ?? '')) ?></td>
                      <td><?= h((string)($r['Status'] ?? '')) ?></td>
                      <td><?= h($createdAt) ?></td>
                      <td><?= h($submittedDisplay) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<script>
  (function () {
    const a1 = document.getElementById('address1');
    const a2 = document.getElementById('address2');
    const a3 = document.getElementById('address3');
    const suburb = document.getElementById('suburb');
    const a1Help = document.getElementById('address1LenHelp');
    const a2Help = document.getElementById('address2LenHelp');
    const a3Help = document.getElementById('address3LenHelp');
    const suburbLenHelp = document.getElementById('suburbLenHelp');
    const addrErr = document.getElementById('addressLenError');
    const a1ReqErr = document.getElementById('address1ReqError');
    const a1LenErr = document.getElementById('address1LenError');
    const a2Err = document.getElementById('address2LenError');
    const a3Err = document.getElementById('address3LenError');
    const suburbErr = document.getElementById('suburbLenError');
    const suburbHelpText = document.getElementById('suburbHelpText');
    const suburbSuggestions = document.getElementById('suburbSuggestions');
    const validationFlash = document.getElementById('addressValidationFlash');
    const postcode = document.getElementById('postcode');
    const postErr = document.getElementById('postcodeNumError');
    const stateSel = document.getElementById('stateSelect');
    const stateErr = document.getElementById('stateError');
    const mobile = document.getElementById('mobile');
    const mobileCountryCode = document.getElementById('mobileCountryCode');
    const mobileErr = document.getElementById('mobileFormatError');
    const workPhone = document.getElementById('workPhone');
    const workPhoneErr = document.getElementById('workPhoneFormatError');
    const email = document.getElementById('email');
    const emailErr = document.getElementById('emailFormatError');
    if (!a1 || !a2 || !a3 || !suburb || !a1Help || !a2Help || !a3Help || !addrErr || !a1ReqErr || !a1LenErr || !a2Err || !a3Err || !suburbErr) return;

    const suburbDatasetUrl = 'assets/data/au_suburbs.json<?= $suburbDatasetVersion !== '' ? '?v=' . h($suburbDatasetVersion) : '' ?>';
    let suburbRows = [];
    let suburbRowsLoaded = false;
    let suburbRowsFailed = false;

    function lenTrim(s) { return (s || '').trim().length; }
    function lenRaw(s) { return (s || '').length; }
    function norm(s) { return String(s || '').trim().toUpperCase(); }
    function trimSuburbValue(raw) { return String(raw || '').slice(0, 21); }
    function normalizeIntlPhoneInput(raw) {
      let value = String(raw || '').replace(/[^\d+]/g, '');
      if (value.startsWith('+')) {
        value = '+' + value.slice(1).replace(/\+/g, '');
      } else {
        value = value.replace(/\+/g, '');
      }
      return value.slice(0, 20);
    }

    function normalizePhoneInput(raw) {
      return String(raw || '').replace(/[^\d]/g, '').slice(0, 15);
    }

    function isValidEmail(value) {
      const trimmed = String(value || '').trim();
      if (trimmed === '') return false;
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed);
    }

    function setSuburbHelp(message, muted) {
      if (!suburbHelpText) return;
      suburbHelpText.textContent = message;
      suburbHelpText.classList.toggle('text-danger', !muted);
      suburbHelpText.classList.toggle('text-muted', !!muted);
    }

    function renderSuburbSuggestions(rows) {
      if (!suburbSuggestions) return;
      suburbSuggestions.innerHTML = '';
      rows.forEach((row) => {
        const opt = document.createElement('option');
        opt.value = String(row.n || '');
        opt.label = `${row.s} ${row.p}`;
        suburbSuggestions.appendChild(opt);
      });
    }

    function findSuburbMatches(query) {
      if (!suburbRowsLoaded || !Array.isArray(suburbRows)) return [];

      const q = norm(query);
      const state = norm(stateSel ? stateSel.value : '');
      const postcodeVal = String(postcode ? (postcode.value || '').trim() : '');

      let rows = suburbRows;
      if (state) rows = rows.filter((row) => row.s === state);
      if (/^\d{4}$/.test(postcodeVal)) {
        const postcodeRows = rows.filter((row) => row.p === postcodeVal);
        if (postcodeRows.length > 0) rows = postcodeRows;
      }
      if (q !== '') {
        const starts = rows.filter((row) => String(row.n || '').startsWith(q));
        const contains = starts.length >= 50 ? starts : rows.filter((row) => !String(row.n || '').startsWith(q) && String(row.n || '').includes(q));
        rows = starts.concat(contains);
      }
      return rows.slice(0, 100);
    }

    function syncSuburbSuggestions() {
      if (!suburbSuggestions) return;
      if (suburbRowsFailed) {
        setSuburbHelp('Australian suburb search is unavailable right now. You can still enter the suburb manually.', false);
        return;
      }
      if (!suburbRowsLoaded) {
        setSuburbHelp('Loading Australian suburb list...', true);
        return;
      }
      const query = String(suburb.value || '');
      if (query.trim().length < 2) {
        renderSuburbSuggestions(findSuburbMatches(''));
        setSuburbHelp('Entering State and Post Code will filter the Suburbs.', true);
        return;
      }
      const matches = findSuburbMatches(query);
      renderSuburbSuggestions(matches);
      if (matches.length === 0) {
        setSuburbHelp('No suburb matches found for the current state/postcode filter.', false);
      } else {
        setSuburbHelp(`${matches.length} suburb match${matches.length === 1 ? '' : 'es'} available.`, true);
      }
    }

    function maybeAutofillSuburbMeta() {
      if (!suburbRowsLoaded) return;
      const suburbValue = norm(suburb.value);
      if (!suburbValue) return;

      const state = norm(stateSel ? stateSel.value : '');
      const postcodeVal = String(postcode ? (postcode.value || '').trim() : '');
      let rows = suburbRows.filter((row) => row.n === suburbValue);

      if (state) {
        const stateRows = rows.filter((row) => row.s === state);
        if (stateRows.length > 0) rows = stateRows;
      }
      if (/^\d{4}$/.test(postcodeVal)) {
        const postcodeRows = rows.filter((row) => row.p === postcodeVal);
        if (postcodeRows.length > 0) rows = postcodeRows;
      }

      const uniqueStates = [...new Set(rows.map((row) => row.s))];
      const uniquePostcodes = [...new Set(rows.map((row) => row.p))];
      if (stateSel && !state && uniqueStates.length === 1) stateSel.value = uniqueStates[0];
      if (postcode && postcodeVal === '' && uniquePostcodes.length === 1) postcode.value = uniquePostcodes[0];
    }

    function loadSuburbDataset() {
      if (suburbRowsLoaded || suburbRowsFailed || typeof fetch !== 'function') return;
      fetch(suburbDatasetUrl, { credentials: 'same-origin' })
        .then((response) => {
          if (!response.ok) throw new Error('HTTP ' + response.status);
          return response.json();
        })
        .then((rows) => {
          suburbRows = Array.isArray(rows) ? rows : [];
          suburbRowsLoaded = true;
          syncSuburbSuggestions();
          maybeAutofillSuburbMeta();
          queueUpdate();
        })
        .catch(() => {
          suburbRowsFailed = true;
          setSuburbHelp('Australian suburb search is unavailable right now. You can still enter the suburb manually.', false);
        });
    }

    function normalizeDigits(raw) {
      return String(raw || '').replace(/\D/g, '');
    }

    function isValidMobileByCountryCode(raw, countryCode) {
      const digits = normalizeDigits(raw);
      if (!digits) return false;

      switch ((countryCode || '+61').trim()) {
        case '+61': {
          const d = digits.startsWith('61') ? digits.slice(2) : (digits.startsWith('0') ? digits.slice(1) : digits);
          return /^4\d{8}$/.test(d);
        }
        case '+1': {
          const d = (digits.length === 11 && digits.startsWith('1')) ? digits.slice(1) : digits;
          return /^\d{10}$/.test(d);
        }
        case '+44': {
          const d = digits.startsWith('44') ? digits.slice(2) : (digits.startsWith('0') ? digits.slice(1) : digits);
          return /^\d{9,10}$/.test(d);
        }
        case '+64': {
          const d = digits.startsWith('64') ? digits.slice(2) : (digits.startsWith('0') ? digits.slice(1) : digits);
          return /^\d{8,10}$/.test(d);
        }
        default:
          return /^\d{6,14}$/.test(digits);
      }
    }

    function isValidWorkPhone(raw) {
      const digits = normalizeDigits(raw);
      if (!digits) return false;

      if (/^0?4\d{8}$/.test(digits) || /^614\d{8}$/.test(digits)) {
        return false;
      }

      return /^\d{6,14}$/.test(digits);
    }

    function setValidationFlash(show) {
      if (!validationFlash) return;
      validationFlash.classList.toggle('d-none', !show);
    }

    function setFieldValidity(field, isValid) {
      if (!field) return;
      field.classList.toggle('is-invalid', !isValid);
      field.setAttribute('aria-invalid', isValid ? 'false' : 'true');
    }

    function updateAddressState(showSummary) {
      const l1 = lenRaw(a1.value);
      const l2 = lenRaw(a2.value);
      const l3 = lenRaw(a3.value);
      const subLen = lenRaw(suburb.value);
      const postVal = postcode ? (postcode.value || '').trim() : '';
      const address1Ok = lenTrim(a1.value) > 0 && l1 <= 30;
      const address2Ok = l2 <= 30;
      const address3Ok = l3 <= 30;
      const suburbOk = lenTrim(suburb.value) > 0 && subLen <= 21;
      const stateOk = !!((stateSel.value || '').trim() !== '');
      const postcodeOk = postVal !== '' && /^\d+$/.test(postVal) && postVal.length <= 4;
      const mobileOk = !!(mobile && isValidMobileByCountryCode((mobile.value || ''), mobileCountryCode ? (mobileCountryCode.value || '+61') : '+61'));
      const workPhoneVal = workPhone ? String(workPhone.value || '').trim() : '';
      const workPhoneOk = !workPhoneVal || isValidWorkPhone(workPhoneVal);
      const emailOk = !!(email && isValidEmail(email.value));
      const ok = address1Ok && address2Ok && address3Ok && suburbOk && stateOk && postcodeOk && mobileOk && workPhoneOk && emailOk;

      a1Help.textContent = `${l1}/30 characters`;
      a2Help.textContent = `${l2}/30 characters`;
      a3Help.textContent = `${l3}/30 characters`;
      a1Help.classList.toggle('text-danger', l1 > 30);
      a2Help.classList.toggle('text-danger', l2 > 30);
      a3Help.classList.toggle('text-danger', l3 > 30);

      const a1Err = a1.parentElement ? a1.parentElement.querySelector('.invalid-feedback') : null;
      const subErr = suburb.parentElement ? suburb.parentElement.querySelector('.invalid-feedback') : null;

      if (lenTrim(a1.value) === 0) {
        setFieldValidity(a1, false);
        a1ReqErr.style.display = '';
      } else {
        a1ReqErr.style.display = 'none';
      }

      if (l1 > 30) {
        setFieldValidity(a1, false);
        a1LenErr.style.display = '';
      } else {
        if (lenTrim(a1.value) > 0) {
          setFieldValidity(a1, true);
        }
        a1LenErr.style.display = 'none';
        if (a1Err && lenTrim(a1.value) > 0) {
          a1Err.style.display = 'none';
        }
      }
      addrErr.style.display = (l1 > 30 || l2 > 30 || l3 > 30) ? '' : 'none';

      if (l2 > 30) {
        setFieldValidity(a2, false);
        a2Err.style.display = '';
      } else {
        setFieldValidity(a2, true);
        a2Err.style.display = 'none';
      }

      if (l3 > 30) {
        setFieldValidity(a3, false);
        a3Err.style.display = '';
      } else {
        setFieldValidity(a3, true);
        a3Err.style.display = 'none';
      }
      if (subLen <= 21 && lenTrim(suburb.value) > 0) {
        setFieldValidity(suburb, true);
        if (subErr) subErr.style.display = 'none';
        suburbErr.style.display = 'none';
      } else {
        if (subLen > 21 || lenTrim(suburb.value) === 0) {
          setFieldValidity(suburb, false);
        }
        if (subErr) subErr.style.display = '';
        suburbErr.style.display = (subLen > 21 || lenTrim(suburb.value) === 0) ? '' : 'none';
      }

      if (stateSel && stateErr) {
        if (stateOk) {
          setFieldValidity(stateSel, true);
          stateErr.style.display = 'none';
        } else {
          setFieldValidity(stateSel, false);
          stateErr.style.display = '';
        }
      }

      if (postcode && postErr) {
        if (postcodeOk) {
          setFieldValidity(postcode, true);
          postErr.style.display = 'none';
        } else {
          setFieldValidity(postcode, false);
          postErr.style.display = '';
        }
      }

      const icon = document.querySelector('.check-icon[data-step-key="address_correct"]');
      if (icon) {
        icon.innerHTML = ok
          ? '<i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>'
          : '<i class="bi bi-x-circle-fill text-danger me-2" aria-hidden="true"></i>';
      }

      const phoneIcon = document.querySelector('.check-icon[data-step-key="phone_correct"]');
      if (phoneIcon) {
        phoneIcon.innerHTML = mobileOk
          ? '<i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>'
          : '<i class="bi bi-x-circle-fill text-danger me-2" aria-hidden="true"></i>';
      }

      if (mobile && mobileErr) {
        const mobileOk = isValidMobileByCountryCode((mobile.value || ''), mobileCountryCode ? (mobileCountryCode.value || '+61') : '+61');
        if (mobileOk) {
          setFieldValidity(mobile, true);
          mobileErr.style.display = 'none';
        } else {
          setFieldValidity(mobile, false);
          mobileErr.style.display = '';
        }
      }

      if (workPhone) {
        const normalized = normalizePhoneInput(workPhone.value);
        if (workPhone.value !== normalized) {
          workPhone.value = normalized;
        }
      }

      if (workPhone && workPhoneErr) {
        if (workPhoneOk) {
          setFieldValidity(workPhone, true);
          workPhoneErr.style.display = 'none';
        } else {
          setFieldValidity(workPhone, false);
          workPhoneErr.style.display = '';
        }
      }

      if (email && emailErr) {
        if (emailOk) {
          setFieldValidity(email, true);
          emailErr.style.display = 'none';
        } else {
          setFieldValidity(email, false);
          emailErr.style.display = '';
        }
      }

      setValidationFlash(!!showSummary && !ok);
      return ok;
    }

    function queueUpdate() {
      if (typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(function () { updateAddressState(false); });
      } else {
        setTimeout(function () { updateAddressState(false); }, 0);
      }
    }

    window.validateEditAddressForm = function (showSummary) {
      return updateAddressState(showSummary);
    };

    ['input', 'change', 'keyup', 'blur', 'compositionend', 'focus'].forEach(evt => {
      a1.addEventListener(evt, queueUpdate);
      a2.addEventListener(evt, queueUpdate);
      a3.addEventListener(evt, queueUpdate);
      suburb.addEventListener(evt, queueUpdate);
      suburb.addEventListener(evt, syncSuburbSuggestions);
      suburb.addEventListener(evt, maybeAutofillSuburbMeta);
      if (postcode) postcode.addEventListener(evt, queueUpdate);
      if (postcode) postcode.addEventListener(evt, syncSuburbSuggestions);
      stateSel.addEventListener(evt, queueUpdate);
      stateSel.addEventListener(evt, syncSuburbSuggestions);
      stateSel.addEventListener(evt, maybeAutofillSuburbMeta);
      if (mobile) mobile.addEventListener(evt, queueUpdate);
      if (mobileCountryCode) mobileCountryCode.addEventListener(evt, queueUpdate);
      if (workPhone) workPhone.addEventListener(evt, queueUpdate);
      if (email) email.addEventListener(evt, queueUpdate);
    });

    suburb.addEventListener('input', function () {
      const trimmed = trimSuburbValue(suburb.value);
      if (suburb.value !== trimmed) {
        suburb.value = trimmed;
      }
    });

    if (mobile) {
      mobile.addEventListener('input', function () {
        const normalized = normalizeIntlPhoneInput(mobile.value);
        if (mobile.value !== normalized) {
          mobile.value = normalized;
        }
      });
    }

    if (workPhone) {
      workPhone.addEventListener('input', function () {
        const normalized = normalizePhoneInput(workPhone.value);
        if (workPhone.value !== normalized) {
          workPhone.value = normalized;
        }
      });
    }

    loadSuburbDataset();
    updateAddressState(false);
    setTimeout(function () { updateAddressState(false); }, 200);
    setTimeout(function () { updateAddressState(false); }, 1000);
  })();
</script>

<script>
  (function () {
    const openSubmitModalBtn = document.getElementById('openSubmitAddressModal');
    const form = document.querySelector('form[action*="cards/change-address-save"]');
    const btn = document.getElementById('confirmSubmitAddress');
    const submitModalEl = document.getElementById('submitAddressModal');
    const applyAll = document.getElementById('applyAllCardsCheckbox');
    const applyAllInput = document.getElementById('applyAllCardsInput');
    const workPhone = document.getElementById('workPhone');
    const email = document.getElementById('email');
    let isSubmitting = false;
    if (!form || !btn) return;

    function isValidEmail(value) {
      const trimmed = String(value || '').trim();
      if (trimmed === '') return false;
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed);
    }

    function validateBeforeOpenModal() {
      const formOk = typeof window.validateEditAddressForm === 'function'
        ? window.validateEditAddressForm(true)
        : true;
      if (!formOk) {
        const firstInvalid = form.querySelector('.is-invalid');
        if (firstInvalid && typeof firstInvalid.focus === 'function') {
          firstInvalid.focus();
        }
      }
      return formOk;
    }

    if (applyAllInput) {
      applyAllInput.value = applyAll && applyAll.checked ? '1' : '0';
    }

    if (openSubmitModalBtn) {
      openSubmitModalBtn.addEventListener('click', function () {
        if (!validateBeforeOpenModal()) {
          return;
        }

        if (submitModalEl && window.bootstrap && window.bootstrap.Modal) {
          window.bootstrap.Modal.getOrCreateInstance(submitModalEl).show();
        }
      });
    }

    form.addEventListener('submit', function (event) {
      if (typeof window.validateEditAddressForm === 'function' && !window.validateEditAddressForm(true)) {
        event.preventDefault();
        event.stopPropagation();
        const firstInvalid = form.querySelector('.is-invalid');
        if (firstInvalid && typeof firstInvalid.focus === 'function') {
          firstInvalid.focus();
        }
        return;
      }
      if (workPhone) {
        workPhone.value = String(workPhone.value || '').replace(/[^\d]/g, '').slice(0, 15);
      }
    });
    btn.addEventListener('click', function () {
      if (isSubmitting) {
        return;
      }
      if (typeof window.validateEditAddressForm === 'function' && !window.validateEditAddressForm(true)) {
        const firstInvalid = form.querySelector('.is-invalid');
        if (firstInvalid && typeof firstInvalid.focus === 'function') {
          firstInvalid.focus();
        }
        return;
      }
      if (workPhone) {
        workPhone.value = String(workPhone.value || '').replace(/[^\d]/g, '').slice(0, 15);
      }
      if (applyAllInput) {
        applyAllInput.value = applyAll && applyAll.checked ? '1' : '0';
      }
      isSubmitting = true;
      btn.disabled = true;
      btn.textContent = 'Submitting...';
      if (openSubmitModalBtn) {
        openSubmitModalBtn.disabled = true;
      }
      form.submit();
    });
  })();
</script>

<?php require __DIR__ . '/../shared/submit_feedback.php'; ?>

<script>
  (function () {
    const form = document.querySelector('form[action*="cards/change-address-save"]');
    if (!form) return;

    const backBtn = document.getElementById('backToCardsBtn');
    const leaveModalEl = document.getElementById('unsavedChangesModal');
    const confirmLeaveBtn = document.getElementById('confirmLeaveBtn');
    let leaveModal = null;
    let pendingHref = '';
    let isDirty = false;

    function snapshotForm() {
      const data = new FormData(form);
      data.delete('_csrf');
      return Array.from(data.entries())
        .map(([key, value]) => key + '=' + String(value))
        .join('&');
    }

    const initialSnapshot = snapshotForm();

    function checkDirty() {
      isDirty = snapshotForm() !== initialSnapshot;
    }

    function getLeaveModal() {
      if (!leaveModalEl || !(window.bootstrap && window.bootstrap.Modal)) {
        return null;
      }
      if (!leaveModal) {
        leaveModal = new window.bootstrap.Modal(leaveModalEl);
      }
      return leaveModal;
    }

    function maybeInterceptNavigation(event, href) {
      checkDirty();
      if (!isDirty) {
        return false;
      }
      const modal = getLeaveModal();
      if (!modal || !confirmLeaveBtn) {
        return false;
      }
      event.preventDefault();
      pendingHref = href || 'index.php?route=home/index';
      confirmLeaveBtn.setAttribute('href', pendingHref);
      modal.show();
      return true;
    }

    form.addEventListener('input', checkDirty);
    form.addEventListener('change', checkDirty);
    form.addEventListener('submit', checkDirty);

    if (backBtn) {
      backBtn.addEventListener('click', function (event) {
        maybeInterceptNavigation(event, backBtn.getAttribute('href') || 'index.php?route=home/index');
      });
    }

    if (confirmLeaveBtn) {
      confirmLeaveBtn.addEventListener('click', function () {
        if (pendingHref !== '') {
          confirmLeaveBtn.setAttribute('href', pendingHref);
        }
      });
    }

    document.addEventListener('click', function (event) {
      const link = event.target.closest('a[href]');
      if (!link || link.id === 'confirmLeaveBtn') return;
      if (link.hasAttribute('download') || link.getAttribute('target') === '_blank') return;

      const href = link.getAttribute('href') || '';
      if (href === '' || href.startsWith('#') || href.startsWith('javascript:')) return;
      if (/^(mailto:|tel:|https?:\/\/)/i.test(href)) return;

      maybeInterceptNavigation(event, href);
    });
  })();
</script>
