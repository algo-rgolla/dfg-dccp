<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

use App\Shared\SessionHelper;

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('renderAgreementText')) {
    function renderAgreementText(string $text): string
    {
        $escaped = h($text);
        $placeholders = [];
        $index = 0;

        $escaped = preg_replace_callback(
            '~\[(.*?)\]\(((?:https?://)[^\s)]+|mailto:[^\s)]+|objective:[^\s)]+)\)~i',
            static function (array $matches) use (&$placeholders, &$index): string {
                $label = trim((string)($matches[1] ?? ''));
                $href = trim((string)($matches[2] ?? ''));
                if ($label === '' || $href === '') {
                    return $matches[0];
                }

                $safeLabel = h($label);
                $safeHref = h($href);
                $lowerHref = strtolower($href);
                $rel = str_starts_with($lowerHref, 'mailto:') || str_starts_with($lowerHref, 'objective:') ? '' : ' target="_blank" rel="noopener noreferrer"';
                $token = '%%SUBMIT_AGREEMENT_LINK_' . $index++ . '%%';
                $placeholders[$token] = '<a href="' . $safeHref . '"' . $rel . '>' . $safeLabel . '</a>';
                return $token;
            },
            $escaped
        );

        $escaped = preg_replace_callback(
            '~(?:(https?://[^\s<\]]+)|(objective:[^\s<\]]+)|([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}))~i',
            static function (array $matches): string {
                if (!empty($matches[1])) {
                    $url = $matches[1];
                    $href = h($url);
                    return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $href . '</a>';
                }

                if (!empty($matches[2])) {
                    $url = $matches[2];
                    $href = h($url);
                    return '<a href="' . $href . '">' . $href . '</a>';
                }

                $email = $matches[3] ?? '';
                if ($email !== '') {
                    $safeEmail = h($email);
                    return '<a href="mailto:' . $safeEmail . '">' . $safeEmail . '</a>';
                }

                return $matches[0];
            },
            $escaped
        );

        if ($placeholders !== []) {
            $escaped = strtr($escaped, $placeholders);
        }

        $escaped = preg_replace(
            '~\*\*(.+?)\*\*~s',
            '<strong>$1</strong>',
            $escaped
        );

        $escaped = preg_replace(
            '~(?<!\*)\*(?![\s*])(.+?)(?<![\s*])\*(?!\*)~s',
            '<em>$1</em>',
            $escaped
        );

        $escaped = preg_replace(
            '~(?<![A-Z0-9])_([^_\r\n]+)_~i',
            '<em>$1</em>',
            $escaped
        );

        $lines = preg_split("/\r\n|\n|\r/", $escaped) ?: [];
        $output = [];
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                if ($inList) {
                    $output[] = '</ul>';
                    $inList = false;
                }
                $output[] = '<br>';
                continue;
            }

            if (preg_match('/^(?:&bull;|&#8226;|•|\*|-)\s+(.+)$/u', $trimmed, $matches)) {
                if (!$inList) {
                    $output[] = '<ul>';
                    $inList = true;
                }
                $output[] = '<li>' . $matches[1] . '</li>';
                continue;
            }

            if ($inList) {
                $output[] = '</ul>';
                $inList = false;
            }

            $output[] = $trimmed . '<br>';
        }

        if ($inList) {
            $output[] = '</ul>';
        }

        return implode('', $output);
    }
}

if (!function_exists('normaliseManagedLinkHref')) {
    function normaliseManagedLinkHref(string $href): string
    {
        $href = trim($href);
        if ($href !== '' && preg_match('/^[A-Za-z]:[\\\\\\/]/', $href) === 1) {
            return 'objective:' . $href;
        }
        return $href;
    }
}

/** @var array $app */
/** @var array $data */
/** @var array $progress */
/** @var array $workflow */
/** @var array $runtimeSteps */

$runtimeSteps = $runtimeSteps ?? [];
$canSubmit = !empty($canSubmit);
$submitBlockingSteps = isset($submitBlockingSteps) && is_array($submitBlockingSteps) ? array_values(array_filter(array_map('strval', $submitBlockingSteps))) : [];

$csrf = h(csrf_token());

$applicationId = (int)($app['ApplicationID'] ?? 0);
$status        = (string)($app['Status'] ?? 'Draft');
$typeId        = (int)($app['ApplicationTypeID'] ?? 0);
$typeName      = (string)($app['ApplicationTypeName'] ?? '');
$applicantDisplay = trim((string)($applicantDisplay ?? ''));
$empName = $applicantDisplay;
$isLodgeApplication = $typeId === 4;
$brandingCardImages = [];
if ($typeId === 1) {
    $brandingCardImages = [
        'Branded' => 'assets/img/GenCardDPC.jpg',
        'Unbranded' => 'assets/img/GenCardDPC_Unbranded.jpg',
    ];
} elseif (in_array($typeId, [2, 3], true)) {
    $brandingCardImages = [
        'Branded' => 'assets/img/GenCardDTC.jpg',
        'Unbranded' => 'assets/img/GenCardDTC_Unbranded.jpg',
    ];
}
$brandingImageOptions = [
    'images' => $brandingCardImages,
    'fallbackAlt' => trim($typeName) !== '' ? ($typeName . ' card preview') : 'Selected card preview',
];
$currentKey    = (string)($app['CurrentStepKey'] ?? '');
$statusNorm    = strtolower(trim($status));
$canEdit       = in_array($statusNorm, ['draft', 'inprogress'], true);
$workflowDisplay = is_array($workflow ?? null) ? $workflow : [];
usort($workflowDisplay, static function (array $a, array $b): int {
    $aKey = strtolower(trim((string)($a['StepKey'] ?? '')));
    $bKey = strtolower(trim((string)($b['StepKey'] ?? '')));

    if ($aKey === 'cms_complete' && $bKey === 'training_completed') {
        return -1;
    }
    if ($aKey === 'training_completed' && $bKey === 'cms_complete') {
        return 1;
    }

    return ((int)($a['StepOrder'] ?? 0)) <=> ((int)($b['StepOrder'] ?? 0));
});
$adminViewMode = !empty($adminViewMode);
$adminEditMode = !empty($adminEditMode);
if ($adminViewMode) {
    $canEdit = false;
}
$employeeId    = trim((string)($app['EmployeeID'] ?? ($data['employee_id'] ?? (SessionHelper::get('auth.employee_id') ?? ''))));
$suburbDatasetVersion = '';
$suburbDatasetPath = __DIR__ . '/../../../public/assets/data/au_suburbs.json';
if (is_file($suburbDatasetPath)) {
    $suburbDatasetVersion = (string)(filemtime($suburbDatasetPath) ?: '');
}

// Validation errors (passed in by controller)
$validationErrors = $validationErrors ?? [];
$validationErrors = is_array($validationErrors) ? $validationErrors : [];

$showErrors = !empty($validationErrors);
$eligibilityNotice = trim((string)($eligibilityNotice ?? ''));
$employeeTypeEntitled = isset($employeeTypeEntitled) ? (bool)$employeeTypeEntitled : true;
$mobileNumberHoverText = trim((string)($mobileNumberHoverText ?? ''));
$ddPostalAddressesLink = trim((string)($ddPostalAddressesLink ?? ''));
$ddPostalAddressesLabel = trim((string)($ddPostalAddressesLabel ?? ''));
$brandingSectionLink = trim((string)($brandingSectionLink ?? ''));
$brandingSectionLabel = trim((string)($brandingSectionLabel ?? ''));
$submitDeclarationText = trim((string)($submitDeclarationText ?? ''));
$submissionToken = trim((string)($submissionToken ?? ''));
$selectedSupervisor = is_array($selectedSupervisor ?? null) ? $selectedSupervisor : [];
$selectedSupervisorLabel = trim((string)($selectedSupervisor['label'] ?? ($data['supervisor_name'] ?? '')));

function field_error(array $errs, string $key): ?string {
    return $errs[$key] ?? null;
}

function format_dmy(?string $date): string
{
    if (!$date) return '';
    $ts = strtotime($date);
    return $ts ? date('d-m-Y', $ts) : '';
}


$data = is_array($data ?? null) ? $data : [];
$phoneValue = trim((string)($data['phone'] ?? ''));
$phoneLocked = str_starts_with(preg_replace('/\D+/', '', $phoneValue) ?? '', '025');
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
?>
<style>
  .form-control.readonly-field { background-color:#f8f9fa; color:#495057; cursor:not-allowed; }
  .readonly-hint { font-size:0.8rem; color:#6c757d; }
  .section-title { margin-bottom: 0; font-size: 1rem; }
  .supervisor-search-wrap { position: relative; }
  .supervisor-suggestions {
    position: absolute;
    top: calc(100% + 0.25rem);
    left: 0;
    right: 0;
    z-index: 1055;
    max-height: 18rem;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
  }
  .supervisor-search-status {
    display: none;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.5rem;
    padding: 0.5rem 0.75rem;
    border-radius: 0.375rem;
    background: #e7f1ff;
    color: #0a58ca;
    font-size: 0.9rem;
    font-weight: 600;
  }
  .supervisor-search-status.is-visible {
    display: inline-flex;
  }
</style>
<?php
?>

<section class="container-fluid mt-4" aria-labelledby="applicationPageHeading">

  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h1 class="h3 mb-1" id="applicationPageHeading">Application</h1>
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="badge bg-dark">ID: <?= h((string)$applicationId) ?></span>
        <span class="badge bg-success-subtle text-dark border">Status: <?= h($status) ?></span>
        <span class="fw-semibold">
          <?= $typeName !== '' ? h($typeName) : 'TypeID: ' . h((string)$typeId) ?>
          <?php if ($applicantDisplay !== ''): ?>
            · <?= h($empName) ?>
          <?php endif; ?>
          · EmployeeID: <?= h((string)$employeeId) ?>
        </span>
      </div>
      
    </div>

    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="<?= ($adminViewMode || $adminEditMode) ? 'index.php?route=admin/applications-view&id=' . h((string)$applicationId) : 'index.php?route=home/index' ?>" id="backToCardsBtn">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Cards
      </a>
    </div>
  </div>

  <?php if ($adminViewMode): ?>
    <div class="alert alert-info" role="status" aria-live="polite">
      <strong>Administrator View:</strong> This is the live application form in read-only mode. Saving, submitting, and deleting are disabled in this view.
    </div>
  <?php endif; ?>
  <?php if ($adminEditMode): ?>
    <div class="alert alert-warning" role="status" aria-live="polite">
      <strong>Administrator Edit Mode:</strong> You are editing this application on behalf of the applicant. Reopen it to <strong>Draft</strong> or <strong>InProgress</strong> first if the form is locked.
    </div>
  <?php endif; ?>

  <div class="row g-3">

    <!-- Left: Progress + Checklist -->
    <div class="col-lg-4">
      <?php if ($statusNorm !== 'draft'): ?>
      <section class="card shadow-sm mb-3" aria-labelledby="applicationProgressHeading">
        <div class="card-header">
          <h2 class="section-title" id="applicationProgressHeading">Progress</h2>
        </div>
        <div class="card-body">
          <div class="list-group list-group-flush" role="list" aria-label="Application progress">
            <?php foreach (($progress ?? []) as $p): ?>
              <?php
                $isActive = !empty($p['IsActive']);
                $done     = !empty($p['Complete']);
              ?>
              <div class="list-group-item d-flex justify-content-between align-items-center" role="listitem">
                <div>
                  <?php if ($done): ?>
                    <i class="bi bi-check-circle-fill text-success me-2" aria-hidden="true"></i>
                  <?php else: ?>
                    <i class="bi bi-circle text-muted me-2" aria-hidden="true"></i>
                  <?php endif; ?>
                  <span class="<?= $isActive ? 'fw-semibold' : '' ?>">
                    <?= h((string)($p['Label'] ?? $p['StepKey'] ?? $p['Key'] ?? 'Step')) ?>
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
      <?php endif; ?>
        <section class="card shadow-sm mb-3" aria-labelledby="applicationChecklistHeading">
          <div class="card-header">
            <h2 class="section-title" id="applicationChecklistHeading">Checklist</h2>
            <div class="text-muted small">Auto-evaluated from the form where possible.</div>
          </div>

          <div class="card-body">
            <div class="list-group" role="list" aria-label="Application checklist">
              <?php foreach ($workflowDisplay as $ws): ?>
                <?php
                  $key = strtolower(trim((string)($ws['StepKey'] ?? '')));
                  if ($key === '' || in_array($key, ['sent_to_bank','card_issued'], true)) continue;

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
                  $label = (string)($ws['StepLabel'] ?? $key);
                  if ($key === 'address_correct') {
                      $label = 'Employee Details Correct';
                  }
                ?>
              <div class="list-group-item d-flex align-items-center" data-step-key="<?= h($key) ?>" role="listitem">
                  <span class="check-icon" data-step-key="<?= h($key) ?>"><?= $icon ?></span>
                  <span><?= h($label) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
    </div>

    

    <!-- Right: Single-page form -->
    <div class="col-lg-8">
      <form method="post" action="index.php?route=applications/save&id=<?= h((string)$applicationId) ?><?= $adminEditMode ? '&admin_edit=1' : '' ?>" class="js-submit-feedback-form" aria-describedby="applicationFormIntro">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="_action" id="_action" value="save">
        <input type="hidden" name="submission_token" value="<?= h($submissionToken) ?>">
        <input type="hidden" name="application_type_id" value="<?= h((string)$typeId) ?>">
        <?php if ($adminEditMode): ?>
          <input type="hidden" name="admin_override" value="1">
        <?php endif; ?>

        <div id="applicationFormIntro" class="visually-hidden">Complete the application form sections and review any validation messages before submitting.</div>

        <?php if ($showErrors): ?>
          <div class="alert alert-danger" role="alert" aria-live="assertive">
            <strong>Validation errors:</strong>
            <?= h(implode(', ', array_keys($validationErrors))) ?>
          </div>
        <?php endif; ?>
        <?php if ($eligibilityNotice !== ''): ?>
          <div class="alert alert-warning" role="alert">
            <?= h($eligibilityNotice) ?>
          </div>
        <?php endif; ?>

        <fieldset <?= $canEdit ? '' : 'disabled' ?>>
        <legend class="visually-hidden">Application form details</legend>
        <!-- Section: Employee details -->
        <section class="card shadow-sm mb-3" aria-labelledby="employeeDetailsHeading">
          <div class="card-header">
            <h2 class="section-title" id="employeeDetailsHeading">Employee Details</h2>
            <div class="text-muted small">Fields marked <span class="text-danger">*</span> are required.</div>
            <?php if ($ddPostalAddressesLink !== ''): ?>
              <?php
                $ddPostalHref = normaliseManagedLinkHref($ddPostalAddressesLink);
                $ddPostalIsExternal = preg_match('~^https?://~i', $ddPostalHref) === 1;
                $ddPostalRel = $ddPostalIsExternal ? ' target="_blank" rel="noopener noreferrer"' : '';
                $ddPostalLabel = $ddPostalAddressesLabel !== '' ? $ddPostalAddressesLabel : 'DD Postal Addresses';
              ?>
              <div class="small mt-1">
                <a href="<?= h($ddPostalHref) ?>"<?= $ddPostalRel ?>><?= h($ddPostalLabel) ?></a>
              </div>
            <?php endif; ?>
          </div>

          <div class="card-body">
            <div class="row g-3">

            

            

              <?php $e = $showErrors ? field_error($validationErrors, 'title') : null; ?>
              <div class="col-md-2">
                <label class="form-label" for="titleSelect">Title <span class="text-danger">*</span></label>
                <?php $titleValue = strtoupper(trim((string)($data['title'] ?? ''))); ?>
                <select class="form-select <?= $e ? 'is-invalid' : '' ?>" name="title" id="titleSelect">
                  <option value="">Select a title</option>
                  <?php foreach (['PROF','DR','MR','MRS','MS','MISS','MX'] as $t): ?>
                    <option value="<?= h($t) ?>" <?= ($titleValue === $t) ? 'selected' : '' ?>>
                      <?= h($t) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
                <div id="titleReqError" class="invalid-feedback" style="display:none;">Title is required.</div>
              </div>

              <?php $e = $showErrors ? field_error($validationErrors, 'gender') : null; ?>
              <div class="col-md-2">
                <label class="form-label" for="genderSelect">Gender <span class="text-danger">*</span></label>
                <?php $genderValue = strtoupper(trim((string)($data['gender'] ?? ''))); ?>
                <select class="form-select <?= $e ? 'is-invalid' : '' ?>" name="gender" id="genderSelect">
                  <option value="">Select</option>
                  <?php foreach (['M', 'F', 'X'] as $g): ?>
                    <option value="<?= h($g) ?>" <?= ($genderValue === $g) ? 'selected' : '' ?>>
                      <?= h($g) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
                <div id="genderReqError" class="invalid-feedback" style="display:none;">Gender is required.</div>
              </div>

              <div class="col-md-4">
                <label class="form-label">
                  First Name
                  <i class="bi bi-lock-fill text-muted ms-1" aria-hidden="true"></i>
                </label>
                <input class="form-control readonly-field"
                       value="<?= h((string)($data['first_name'] ?? '')) ?>"
                       readonly>
                <input type="hidden" name="first_name" value="<?= h((string)($data['first_name'] ?? '')) ?>">
                <div class="readonly-hint">Pre-filled by system</div>
              </div>

              <div class="col-md-4">
                <label class="form-label">
                  Surname
                  <i class="bi bi-lock-fill text-muted ms-1" aria-hidden="true"></i>
                </label>
                <input class="form-control readonly-field"
                       value="<?= h((string)($data['surname'] ?? '')) ?>"
                       readonly>
                <input type="hidden" name="surname" value="<?= h((string)($data['surname'] ?? '')) ?>">
                <div class="readonly-hint">Pre-filled by system</div>
              </div>

              <?php $e = $showErrors ? field_error($validationErrors, 'address1') : null; ?>
              <div class="col-md-6">
                <label class="form-label" for="address1">Address Line 1 <span class="text-danger">*</span></label>
                <input class="form-control <?= $e ? 'is-invalid' : '' ?>"
                       id="address1"
                       name="address1"
                       value="<?= h((string)($data['address1'] ?? '')) ?>"
                       maxlength="30">
                <div id="address1LenHelp" class="form-text"></div>
                <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
                <div id="address1ReqError" class="invalid-feedback" style="display:none;">Address Line 1 is required.</div>
                <div id="address1LenError" class="invalid-feedback" style="display:none;">Address Line 1 must be 30 characters or less.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="address2">Address Line 2</label>
                <input class="form-control"
                       id="address2"
                       name="address2"
                       value="<?= h((string)($data['address2'] ?? '')) ?>"
                       maxlength="30">
                <div id="address2LenHelp" class="form-text"></div>
                <div id="address2LenError" class="invalid-feedback">Address Line 2 must be 30 characters or less.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label" for="address3">Address Line 3</label>
                <input class="form-control"
                       id="address3"
                       name="address3"
                       value="<?= h((string)($data['address3'] ?? '')) ?>"
                       maxlength="30">
                <div id="address3LenHelp" class="form-text"></div>
                <div id="addressLenError" class="invalid-feedback" style="display:none;">One or more address lines exceed 30 characters.</div>
                <div id="address3LenError" class="invalid-feedback" style="display:none;">Address Line 3 must be 30 characters or less.</div>
              </div>

              <?php $e = $showErrors ? field_error($validationErrors, 'state') : null; ?>
              <div class="col-md-4">
                <label class="form-label" for="stateSelect">State <span class="text-danger">*</span></label>
                <select class="form-select <?= $e ? 'is-invalid' : '' ?>" name="state" id="stateSelect">
                  <option value="">Select a state</option>
                  <?php foreach (['ACT','NSW','NT','QLD','SA','TAS','VIC','WA'] as $st): ?>
                    <option value="<?= h($st) ?>" <?= ((string)($data['state'] ?? '') === $st) ? 'selected' : '' ?>>
                      <?= h($st) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
              </div>

              <?php $e = $showErrors ? field_error($validationErrors, 'postcode') : null; ?>
              <div class="col-md-4">
                <label class="form-label" for="postcode">Postcode <span class="text-danger">*</span></label>
                <input class="form-control <?= $e ? 'is-invalid' : '' ?>"
                       id="postcode"
                       name="postcode"
                       value="<?= h((string)($data['postcode'] ?? '')) ?>">
                <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
                <div id="postcodeNumError" class="invalid-feedback">Postcode must contain only numbers and be 4 digits or less.</div>
              </div>

              <?php $e = $showErrors ? field_error($validationErrors, 'suburb') : null; ?>
              <div class="col-md-4">
                <label class="form-label" for="suburb">Suburb <span class="text-danger">*</span></label>
                <input class="form-control <?= $e ? 'is-invalid' : '' ?>"
                       id="suburb"
                       name="suburb"
                       value="<?= h((string)($data['suburb'] ?? '')) ?>"
                       list="suburbSuggestions"
                       maxlength="21"
                       autocomplete="off"
                       placeholder="Search or enter suburb manually">
                <datalist id="suburbSuggestions"></datalist>
                <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
                <div id="suburbLenError" class="invalid-feedback">Suburb must be 21 characters or less.</div>
                <div class="form-text" id="suburbHelpText">Entering State and Post Code will filter the Suburbs.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label">
                  Group <i class="bi bi-lock-fill text-muted ms-1" aria-hidden="true"></i>
                </label>

                <input class="form-control readonly-field"
                      value="<?= h((string)($data['group_name'] ?? '')) ?>"
                      readonly>

                <input type="hidden" name="group_name" value="<?= h((string)($data['group_name'] ?? '')) ?>">
                <div class="readonly-hint">Pre-filled by system</div>
              </div>


<!-- Section: Contact Details -->
<section class="card shadow-sm mb-3" aria-labelledby="contactDetailsHeading">
  <div class="card-header">
    <h2 class="section-title" id="contactDetailsHeading">Contact Details</h2>
    <div class="text-muted small">
      Details used to contact you regarding this application.
    </div>
  </div>

  <div class="card-body">
    <div class="row g-3">

      <div class="col-md-6">
  <?php $e = $showErrors ? field_error($validationErrors, 'email') : null; ?>
  <label class="form-label">
        Email
        <i class="bi bi-lock-fill text-muted ms-1" aria-hidden="true"></i>
      </label>

      <input
          class="form-control readonly-field <?= $e ? 'is-invalid' : '' ?>"
          value="<?= h((string)($data['email'] ?? '')) ?>"
          readonly>

      <input type="hidden"
            name="email"
            value="<?= h((string)($data['email'] ?? '')) ?>">

      <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>

      <div class="readonly-hint">
        Pre-filled by system
      </div>
    </div>

      <?php $e = $showErrors ? field_error($validationErrors, 'mobile') : null; ?>
      <div class="col-md-6">
        <label class="form-label">
          Mobile Number <span class="text-danger">*</span>
          <?php if ($mobileNumberHoverText !== ''): ?>
            <span class="ms-1 text-info" aria-hidden="true">
              <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
            </span>
          <?php endif; ?>
        </label>
        <?php if ($mobileNumberHoverText !== ''): ?>
          <div class="visually-hidden" id="mobileInfoText"><?= h($mobileNumberHoverText) ?></div>
        <?php endif; ?>
        <?php $mobileCodeVal = trim((string)($data['mobile_country_code'] ?? '+61')); ?>
        <div class="input-group">
          <select class="form-select" name="mobile_country_code" id="mobileCountryCode" style="max-width: 160px;" aria-label="Mobile country code">
            <?php foreach ($phoneCountryOptions as $code => $label): ?>
              <option value="<?= h($code) ?>" <?= $mobileCodeVal === $code ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <input class="form-control <?= $e ? 'is-invalid' : '' ?>"
                 id="mobile"
                 name="mobile"
                 value="<?= h((string)($data['mobile'] ?? '')) ?>"
                 inputmode="tel"
                 maxlength="20"
                 placeholder="Enter mobile number"
                 aria-describedby="<?= $mobileNumberHoverText !== '' ? 'mobileInfoText ' : '' ?>mobileHelpText mobileFormatError">
        </div>
        <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
        <div id="mobileFormatError" class="invalid-feedback d-none">Enter a valid mobile number for the selected country code.</div>
        <div class="form-text" id="mobileHelpText">International mobile numbers are supported.</div>
      </div>

      <?php $e = $showErrors ? field_error($validationErrors, 'phone') : null; ?>
      <div class="col-md-6">
        <label class="form-label" for="phone">Work Phone</label>
        <?php $phoneCodeVal = trim((string)($data['phone_country_code'] ?? '+61')); ?>
        <div class="input-group">
          <select class="form-select" name="phone_country_code" id="phoneCountryCode" style="max-width: 140px;" aria-label="Work phone country code" <?= $phoneLocked ? 'disabled' : '' ?>>
            <?php foreach ($phoneCountryOptions as $code => $label): ?>
              <option value="<?= h($code) ?>" <?= $phoneCodeVal === $code ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($phoneLocked): ?>
            <input type="hidden" name="phone_country_code" value="<?= h($phoneCodeVal) ?>">
          <?php endif; ?>
          <input class="form-control <?= $e ? 'is-invalid' : '' ?><?= $phoneLocked ? ' readonly-field' : '' ?>"
                 id="phone"
                 name="phone"
                 value="<?= h($phoneValue) ?>"
                 aria-describedby="phoneFormatError<?= $phoneLocked ? ' phoneReadonlyHint' : '' ?>"
                 <?= $phoneLocked ? 'readonly aria-readonly="true"' : '' ?>>
        </div>
        <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
        <div id="phoneFormatError" class="invalid-feedback d-none">Enter a valid non-mobile phone number for the selected country code.</div>
        <?php if ($phoneLocked): ?><div class="form-text readonly-hint" id="phoneReadonlyHint">This phone number is managed by DCD and cannot be changed here.</div><?php endif; ?>
      </div>

    </div>
  </div>
</section>

<?php require __DIR__ . '/../shared/submit_feedback.php'; ?>



<!-- Section: Eligibility -->
<section class="card shadow-sm mb-3" aria-labelledby="eligibilityHeading">
  <div class="card-header">
    <h2 class="section-title" id="eligibilityHeading">Eligibility</h2>
    <div class="text-muted small">
      Information used to determine eligibility for this application.
    </div>
  </div>

  <div class="card-body">
    <div class="row g-3">

      <?php $e = $showErrors ? field_error($validationErrors, 'date_of_birth') : null; ?>
      <div class="col-md-6">
        <label class="form-label">
          Date of Birth <span class="text-danger">*</span>
          <i class="bi bi-lock-fill text-muted ms-1" aria-hidden="true"></i>
        </label>

        <input class="form-control readonly-field <?= $e ? 'is-invalid' : '' ?>"
              value="<?= h(format_dmy($data['date_of_birth'] ?? '')) ?>"
              readonly>

        <input type="hidden"
              name="date_of_birth"
              value="<?= h((string)($data['date_of_birth'] ?? '')) ?>">

        <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
        <div class="readonly-hint">Pre-filled by system</div>
      </div>


     <?php $e = $showErrors ? field_error($validationErrors, 'employee_type') : null; ?>
     <div class="col-md-6">
  <label class="form-label">
    Employee Type <span class="text-danger">*</span>
    <i class="bi bi-lock-fill text-muted ms-1" aria-hidden="true"></i>
  </label>

  <input class="form-control readonly-field <?= $e ? 'is-invalid' : '' ?>"
         value="<?= h((string)($data['employee_type'] ?? '')) ?>"
         readonly>

  <input type="hidden"
         name="employee_type"
         value="<?= h((string)($data['employee_type'] ?? '')) ?>">

  <?php if ($e): ?>
    <div class="invalid-feedback d-block"><?= h($e) ?></div>
  <?php endif; ?>
  <?php if (!$employeeTypeEntitled): ?>
    <div class="text-danger small mt-1">
      <?= h($e ?: 'This employee type is not entitled for this application based on CAPS entitlement settings.') ?>
    </div>
  <?php endif; ?>

  <div class="readonly-hint">Pre-filled by system</div>
</section>

    </div>
  </div>
</div>


<!-- Section: CMS -->
<section class="card shadow-sm mb-3" aria-labelledby="cmsHeading">
  <div class="card-header">
    <h2 class="section-title" id="cmsHeading">CMS</h2>
    <div class="text-muted small">CMS-related details for this application.</div>
  </div>

  <div class="card-body">
    <div class="row g-3">

    <div class="col-md-4">
      <?php $e = $showErrors ? field_error($validationErrors, 'company') : null; ?>
      <label class="form-label" for="companySelect">Company <span class="text-danger">*</span></label>
      <select class="form-select <?= $e ? 'is-invalid' : '' ?>" name="company" id="companySelect">
        <option value="">Select a company</option>
        <?php foreach (($companies ?? []) as $c): ?>
          <?php
            $companyCode = trim((string)(is_array($c) ? ($c['CompanyCode'] ?? '') : $c));
            $companyName = trim((string)(is_array($c) ? ($c['CompanyName'] ?? '') : ''));
            $companyLabel = $companyCode;
            if ($companyName !== '') {
              $companyLabel .= ' - ' . $companyName;
            }
          ?>
          <option value="<?= h($companyCode) ?>" <?= ((string)($data['company'] ?? '') === $companyCode) ? 'selected' : '' ?>>
            <?= h($companyLabel) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
      <div id="companyReqError" class="invalid-feedback" style="display:none;">Company is required.</div>
    </div>

    <div class="col-md-4">
      <?php
        $e = $showErrors ? field_error($validationErrors, 'cost_centre') : null;
        $selectedCostCentre = (string)($data['cost_centre'] ?? '');
        $selectedCostCentreLabel = $selectedCostCentre;
        foreach (($costCentres ?? []) as $cc) {
          $ccCode = (string)($cc['CostCentre'] ?? '');
          $ccName = (string)($cc['CostCentreName'] ?? '');
          if ($ccCode !== $selectedCostCentre) {
            continue;
          }
          $selectedCostCentreLabel = $ccName !== '' ? ($ccCode . ' - ' . $ccName) : $ccCode;
          break;
        }
      ?>
      <label class="form-label" for="costCentreSearch">Cost Centre <span class="text-danger">*</span></label>
      <input type="hidden" name="cost_centre" id="costCentreSelect" value="<?= h($selectedCostCentre) ?>">
      <input
        type="text"
        class="form-control <?= $e ? 'is-invalid' : '' ?>"
        id="costCentreSearch"
        value="<?= h($selectedCostCentreLabel) ?>"
        list="costCentreSuggestions"
        autocomplete="off"
        placeholder="Search cost centre">
      <datalist id="costCentreSuggestions">
        <?php foreach (($costCentres ?? []) as $cc): ?>
          <?php
            $ccCode = (string)($cc['CostCentre'] ?? '');
            $ccName = (string)($cc['CostCentreName'] ?? '');
            $label = $ccName !== '' ? ($ccCode . ' - ' . $ccName) : $ccCode;
          ?>
          <option value="<?= h($label) ?>"></option>
        <?php endforeach; ?>
      </datalist>
      <div class="form-text" id="costCentreHelpText">Choose a company first to load cost centres, then search by code or name.</div>
      <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
      <div id="costCentreReqError" class="invalid-feedback" style="display:none;">Cost Centre is required.</div>
    </div>

    <div class="col-md-4">
      <?php
        $e = $showErrors ? field_error($validationErrors, 'wbs') : null;
        $selectedWbs = (string)($data['wbs'] ?? '');
        $selectedWbsLabel = $selectedWbs;
        foreach (($wbsList ?? []) as $w) {
          $wCode = (string)($w['CostCentre'] ?? '');
          $wName = (string)($w['CostCentreName'] ?? '');
          if ($wCode !== $selectedWbs) {
            continue;
          }
          $selectedWbsLabel = $wName !== '' ? ($wCode . ' - ' . $wName) : $wCode;
          break;
        }
      ?>
      <label class="form-label" for="wbsSearch">WBS</label>
      <input type="hidden" name="wbs" id="wbsSelect" value="<?= h($selectedWbs) ?>">
      <input
        type="text"
        class="form-control <?= $e ? 'is-invalid' : '' ?>"
        id="wbsSearch"
        value="<?= h($selectedWbsLabel) ?>"
        list="wbsSuggestions"
        autocomplete="off"
        placeholder="Search WBS">
      <datalist id="wbsSuggestions">
        <?php foreach (($wbsList ?? []) as $w): ?>
          <?php
            $wCode = (string)($w['CostCentre'] ?? '');
            $wName = (string)($w['CostCentreName'] ?? '');
            $label = $wName !== '' ? ($wCode . ' - ' . $wName) : $wCode;
          ?>
          <option value="<?= h($label) ?>"></option>
        <?php endforeach; ?>
      </datalist>
      <div class="form-text" id="wbsHelpText">Choose a company first, then type at least 3 characters to search WBS by code or name.</div>
      <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
    </div>

    <div class="col-md-4">
      <?php
        $e = $showErrors ? field_error($validationErrors, 'cms_account_holder') : null;
        $cmsHolderValue = (string)($data['cms_account_holder'] ?? '');
        $cmsHolderLabel = $cmsHolderValue;
        $cmsHolderIsActive = false;
        if ($cmsHolderValue === '' && !empty($cmsAccountHolders)) {
            $firstHolder = $cmsAccountHolders[0];
            $cmsHolderValue = is_array($firstHolder) ? (string)($firstHolder['value'] ?? '') : (string)$firstHolder;
            $cmsHolderLabel = is_array($firstHolder) ? (string)($firstHolder['label'] ?? $cmsHolderValue) : (string)$firstHolder;
            $cmsHolderIsActive = is_array($firstHolder) ? (bool)($firstHolder['is_active'] ?? true) : true;
        } else {
            foreach (($cmsAccountHolders ?? []) as $h) {
                $hValue = is_array($h) ? (string)($h['value'] ?? '') : (string)$h;
                if ($hValue !== $cmsHolderValue) {
                    continue;
                }
                $cmsHolderLabel = is_array($h) ? (string)($h['label'] ?? $hValue) : (string)$h;
                $cmsHolderIsActive = is_array($h) ? (bool)($h['is_active'] ?? true) : true;
                break;
            }
        }
        $cmsHelperMessage = '';
        if ($cmsHolderValue === '') {
            $cmsHelperMessage = 'An Active Account is required. Please allow up to 30 minutes for your new CMS User ID to be recognised in the portal';
        } elseif (!$cmsHolderIsActive) {
            $cmsHelperMessage = 'Email a screenshot of the above CMS User ID field to defence.creditcards@defence.gov.au and request re-activation of your CMS Account.';
        }
      ?>
      <label class="form-label" for="cmsAccountHolderDisplay">
        CMS User ID <span class="text-danger">*</span>
        <i class="bi bi-lock-fill text-muted ms-1" aria-hidden="true"></i>
        <?php if ($cmsHolderValue !== ''): ?>
          <?php if ($cmsHolderIsActive): ?>
            <i class="bi bi-check-circle-fill text-success ms-1" aria-hidden="true"></i>
            <span class="visually-hidden">Active CMS User ID</span>
          <?php else: ?>
            <i class="bi bi-x-circle-fill text-danger ms-1" aria-hidden="true"></i>
            <span class="visually-hidden">Inactive CMS User ID</span>
          <?php endif; ?>
        <?php endif; ?>
      </label>
      <input type="hidden" name="cms_account_holder" id="cmsAccountHolderSelect" value="<?= h($cmsHolderValue) ?>">
      <input type="hidden" id="cmsAccountHolderActiveFlag" value="<?= $cmsHolderIsActive ? '1' : '0' ?>">
      <input
        type="text"
        class="form-control readonly-field <?= $e ? 'is-invalid' : '' ?>"
        id="cmsAccountHolderDisplay"
        value="<?= h($cmsHolderLabel) ?>"
        readonly
        disabled
      >
      <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
      <div
        id="cmsAccountHolderStatusMessage"
        class="<?= $cmsHolderValue === '' ? 'form-text text-muted' : 'small text-danger' ?>"
        style="<?= $cmsHelperMessage === '' ? 'display:none;' : '' ?>"
      ><?= h($cmsHelperMessage) ?></div>
      <div id="cmsAccountHolderReqError" class="invalid-feedback" style="display:none;"></div>
    </div>

    </div>
  </div>
</section>

<?php if ($typeId === 1): ?>
  <section class="card shadow-sm mb-3" aria-labelledby="supervisorApprovalHeading">
    <div class="card-header">
      <h2 class="section-title" id="supervisorApprovalHeading">Supervisor Approval</h2>
      <div class="text-muted small">Select the supervisor who should review and approve this DPC application.</div>
    </div>
    <div class="card-body">
      <?php $e = $showErrors ? field_error($validationErrors, 'supervisor_employee_id') : null; ?>
      <div class="row g-3">
        <div class="col-md-8 supervisor-search-wrap">
          <label class="form-label" for="supervisorSearch">Supervisor <span class="text-danger">*</span></label>
          <input type="hidden" name="supervisor_employee_id" id="supervisorEmployeeId" value="<?= h((string)($data['supervisor_employee_id'] ?? '')) ?>">
          <input type="hidden" name="supervisor_name" id="supervisorName" value="<?= h((string)($data['supervisor_name'] ?? '')) ?>">
          <input type="hidden" name="supervisor_email" id="supervisorEmail" value="<?= h((string)($data['supervisor_email'] ?? '')) ?>">
          <input
            type="text"
            class="form-control <?= $e ? 'is-invalid' : '' ?><?= $canEdit ? '' : ' readonly-field' ?>"
            id="supervisorSearch"
            value="<?= h($selectedSupervisorLabel) ?>"
            autocomplete="off"
            placeholder="Search by name, employee ID, or email"
            <?= $canEdit ? '' : 'readonly aria-disabled="true"' ?>>
          <div class="form-text" id="supervisorHelpText">Start typing at least 2 characters to search supervisors.</div>
          <div id="supervisorSearchStatus" class="supervisor-search-status" role="status" aria-live="polite">
            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
            <span>Searching supervisors...</span>
          </div>
          <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
          <div id="supervisorReqError" class="invalid-feedback" style="display:none;">Supervisor is required.</div>
          <div id="supervisorSuggestions" class="supervisor-suggestions list-group d-none"></div>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="supervisorEmailDisplay">Supervisor Email</label>
          <input
            type="text"
            class="form-control readonly-field"
            id="supervisorEmailDisplay"
            value="<?= h((string)($data['supervisor_email'] ?? '')) ?>"
            readonly>
        </div>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php if (!$isLodgeApplication): ?>
  <!-- Section: Branding -->
  <section class="card shadow-sm mb-3" aria-labelledby="brandingHeading">
    <div class="card-header">
      <h2 class="section-title" id="brandingHeading">Branding</h2>
      <div class="text-muted small">Choose whether the card should be branded.</div>
      <?php if ($brandingSectionLink !== ''): ?>
        <?php
          $brandingSectionHref = normaliseManagedLinkHref($brandingSectionLink);
          $brandingSectionIsExternal = preg_match('~^https?://~i', $brandingSectionHref) === 1;
          $brandingSectionRel = $brandingSectionIsExternal ? ' target="_blank" rel="noopener noreferrer"' : '';
          $brandingSectionText = $brandingSectionLabel !== '' ? $brandingSectionLabel : 'Branding';
        ?>
        <div class="small mt-1">
          <a href="<?= h($brandingSectionHref) ?>"<?= $brandingSectionRel ?>><?= h($brandingSectionText) ?></a>
        </div>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <?php $e = $showErrors ? field_error($validationErrors, 'branding') : null; ?>
          <label class="form-label" for="brandingSelect">Branding <span class="text-danger">*</span></label>
          <select class="form-select <?= $e ? 'is-invalid' : '' ?>" name="branding" id="brandingSelect">
            <?php $brandingVal = (string)($data['branding'] ?? ''); ?>
            <option value="">Please select</option>
            <option value="Branded" <?= $brandingVal === 'Branded' ? 'selected' : '' ?>>Branded</option>
            <option value="Unbranded" <?= $brandingVal === 'Unbranded' ? 'selected' : '' ?>>Unbranded</option>
          </select>
          <?php if ($e): ?><div class="invalid-feedback"><?= h($e) ?></div><?php endif; ?>
          <div id="brandingReqError" class="invalid-feedback" style="display:none;">Branding is required.</div>
        </div>
        <?php if ($brandingCardImages !== []): ?>
          <div class="col-md-8">
            <div class="border rounded bg-light p-3 h-100" id="brandingCardPreviewWrap">
              <div class="fw-semibold mb-2">Card Preview</div>
              <div class="small text-muted mb-3">The preview updates when you switch between branded and unbranded.</div>
              <img
                id="brandingCardPreview"
                src=""
                alt="<?= h($brandingImageOptions['fallbackAlt']) ?>"
                class="img-fluid d-none"
                style="max-height: 220px; object-fit: contain;"
              >
              <div id="brandingCardPreviewPlaceholder" class="small text-muted">
                Select a branding option to preview the card image.
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>
<?php endif; ?>


              <!-- Add the fields your checklist expects -->
        
            <!-- Section: Training Status -->
<section class="card shadow-sm mb-3" aria-labelledby="trainingHeading">
  <div class="card-header">
    <h2 class="section-title" id="trainingHeading">Training (Mandatory)</h2>
    <div class="text-muted small">
      Training status is automatically determined by the system.
    </div>
  </div>

  <div class="card-body">

    <?php
      $trainingCompleted = !empty($data['training_completed']);
      $trainingLinks = is_array($trainingLinks ?? null) ? $trainingLinks : [];
      $trainingUrl = trim((string)($trainingLinks['training_url'] ?? ''));
      $trainingLabel = trim((string)($trainingLinks['training_label'] ?? ''));
      $trainingFaqUrl = trim((string)($trainingLinks['faq_url'] ?? ''));
      if ($trainingLabel === '') {
        $trainingLabel = 'Training (Mandatory)';
      }
    ?>

    <div class="d-flex align-items-center gap-3">
      <div class="fw-semibold">Training Status:</div>

      <?php if ($trainingCompleted): ?>
        <span class="badge bg-success">
          <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
          Completed
        </span>
      <?php else: ?>
        <span class="badge bg-danger">
          <i class="bi bi-x-circle me-1" aria-hidden="true"></i>
          Not Completed
        </span>
      <?php endif; ?>
    </div>

    <?php if ($trainingCompleted): ?>
      <div class="form-text mt-2">
        Training Completed in LXP can take 24-48 hours to update in the Defence Credit Card Portal.
      </div>
    <?php else: ?>
      <div class="form-text mt-2">
        For further information see
        <?php if ($trainingUrl !== ''): ?>
          <a href="<?= h($trainingUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($trainingLabel) ?></a>
        <?php else: ?>
          <?= h($trainingLabel) ?>
        <?php endif; ?>
        and
        <?php if ($trainingFaqUrl !== ''): ?>
          <a href="<?= h($trainingFaqUrl) ?>" target="_blank" rel="noopener noreferrer">FAQ's - Credit Card eLearning</a>.
        <?php else: ?>
          FAQ's - Credit Card eLearning.
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>
</section>


           

            </div>
          </div>
        </section>



       

        </fieldset>

        <div class="d-flex justify-content-end gap-2">
          <button type="submit" class="btn btn-outline-primary"
                  formnovalidate
                  onclick="document.getElementById('_action').value='save'"
                  <?= $canEdit ? '' : 'disabled' ?>>
            Save (Draft)
          </button>
<button type="button"
        class="btn btn-success"
        id="openDeclarationBtn"
        <?= $canEdit ? '' : 'disabled' ?>>
  Submit Application
</button>
          <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteAppModal" <?= $canEdit ? '' : 'disabled' ?>>
            Delete Application
          </button>

        </div>

        <!-- Declaration Modal -->
<div class="modal fade" id="declarationModal" tabindex="-1" aria-labelledby="declarationModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="declarationModalLabel">Declaration</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <!-- Put your terms here -->
        <div class="border rounded p-3 bg-light" role="document">
          <p class="mb-2"><strong>Declaration</strong></p>
          <p class="mb-0">
            <?= renderAgreementText($submitDeclarationText !== '' ? $submitDeclarationText : 'By submitting this application, you confirm the details provided are true and correct.') ?>
          </p>
        </div>

        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" id="agree_modal" />
          <label class="form-check-label" for="agree_modal">
            I confirm I have read and agree to the terms and conditions.
          </label>
        </div>

        <!-- Optional: show a hint if they try to submit without ticking -->
        <div id="agree_modal_error" class="text-danger small mt-2 d-none" role="alert" aria-live="assertive">
          You must agree before submitting.
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>

        <!-- This is the REAL submit -->
        <button type="button" class="btn btn-success" id="confirmSubmitBtn" disabled>
          Confirm & Submit
        </button>
      </div>

    </div>
  </div>
</div>

<!-- Submit Blocked Modal -->
<div class="modal fade" id="submitBlockedModal" tabindex="-1" aria-labelledby="submitBlockedModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-warning">
      <div class="modal-header">
        <h5 class="modal-title" id="submitBlockedModalLabel">Cannot Submit Application</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">Please complete all mandatory checks, including training, before submitting.</p>
        <div id="submitBlockedItemsWrap" class="<?= $submitBlockingSteps !== [] ? '' : 'd-none' ?>">
          <div class="small text-muted mb-2">Outstanding items:</div>
          <ul class="mb-0" id="submitBlockedItems"></ul>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>
<script>
  (function () {
    const form = document.querySelector('form[action*="applications/save"]');
    const agree = document.getElementById('agree_modal');
    const btn = document.getElementById('confirmSubmitBtn');
    const err = document.getElementById('agree_modal_error');
    const openDeclarationBtn = document.getElementById('openDeclarationBtn');
    const declarationModalEl = document.getElementById('declarationModal');
    let isSubmitting = false;

    if (!agree || !btn) return;

    agree.addEventListener('change', function () {
      btn.disabled = !agree.checked;
      if (err) err.classList.toggle('d-none', agree.checked);
      agree.setAttribute('aria-invalid', agree.checked ? 'false' : 'true');
    });

    btn.addEventListener('click', function (e) {
      if (isSubmitting) {
        e.preventDefault();
        return;
      }
      if (!agree.checked) {
        e.preventDefault();
        if (err) err.classList.remove('d-none');
        agree.setAttribute('aria-invalid', 'true');
        return;
      }
      isSubmitting = true;
      btn.disabled = true;
      btn.textContent = 'Submitting...';
      if (openDeclarationBtn) {
        openDeclarationBtn.disabled = true;
      }
      const actionInput = document.getElementById('_action');
      if (actionInput) {
        actionInput.value = 'submit';
      }
      if (form) {
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      }
    });

    if (declarationModalEl) {
      declarationModalEl.addEventListener('show.bs.modal', function () {
        agree.checked = false;
        btn.disabled = true;
        if (err) err.classList.add('d-none');
      });
    }
  })();
</script>

<script>
  (function () {
    const companySel = document.getElementById('companySelect');
    const ccSel = document.getElementById('costCentreSelect');
    const wbsSel = document.getElementById('wbsSelect');
    const ccSearch = document.getElementById('costCentreSearch');
    const wbsSearch = document.getElementById('wbsSearch');
    const ccSuggestions = document.getElementById('costCentreSuggestions');
    const wbsSuggestions = document.getElementById('wbsSuggestions');
    const ccHelpText = document.getElementById('costCentreHelpText');
    const wbsHelpText = document.getElementById('wbsHelpText');
    let costCentreItems = [];
    let wbsItems = [];
    let wbsSearchTimer = null;
    let wbsSearchRequestId = 0;
    const minWbsSearchLength = 3;
    const wbsSearchDelayMs = 250;

    if (!companySel || !ccSel || !wbsSel || !ccSearch || !wbsSearch || !ccSuggestions || !wbsSuggestions) return;

    function normaliseValue(value) {
      return String(value || '').trim().toUpperCase();
    }

    function formatLabel(item) {
      const code = item.CostCentre || item.CostCentreCode || item.code || '';
      const name = item.CostCentreName || item.name || '';
      return name ? (code + ' - ' + name) : code;
    }

    function renderSuggestions(listEl, items) {
      listEl.innerHTML = '';
      items.forEach(function (item) {
        const code = item.CostCentre || item.CostCentreCode || item.code || '';
        if (!code) return;
        const opt = document.createElement('option');
        opt.value = formatLabel(item);
        listEl.appendChild(opt);
      });
    }

    function setHelpText(el, text) {
      if (el) {
        el.textContent = text;
      }
    }

    function findMatch(items, rawValue) {
      const normalised = normaliseValue(rawValue);
      if (!normalised) return null;

      for (const item of items) {
        const code = String(item.CostCentre || item.CostCentreCode || item.code || '');
        if (normaliseValue(code) === normalised) {
          return item;
        }
      }

      for (const item of items) {
        if (normaliseValue(formatLabel(item)) === normalised) {
          return item;
        }
      }

      return null;
    }

    function syncSearchField(searchEl, hiddenEl, items, isRequired) {
      const rawValue = searchEl.value || '';
      const match = findMatch(items, rawValue);
      if (match) {
        const code = String(match.CostCentre || match.CostCentreCode || match.code || '');
        hiddenEl.value = code;
        searchEl.value = formatLabel(match);
        searchEl.classList.remove('is-invalid');
        return;
      }

      if (rawValue.trim() === '') {
        hiddenEl.value = '';
        searchEl.classList.toggle('is-invalid', !!isRequired);
        return;
      }

      hiddenEl.value = '';
      searchEl.classList.add('is-invalid');
    }

    function resetCostCentreFields(message) {
      costCentreItems = [];
      ccSel.value = '';
      ccSearch.value = '';
      ccSearch.classList.remove('is-invalid');
      renderSuggestions(ccSuggestions, []);
      setHelpText(ccHelpText, message);
    }

    function resetWbsFields(message) {
      wbsItems = [];
      wbsSel.value = '';
      wbsSearch.value = '';
      wbsSearch.classList.remove('is-invalid');
      renderSuggestions(wbsSuggestions, []);
      setHelpText(wbsHelpText, message);
    }

    function loadWbsMatches(forceSearch) {
      const company = companySel.value || '';
      const term = (wbsSearch.value || '').trim();
      const requestId = ++wbsSearchRequestId;
      const existingMatch = findMatch(wbsItems, term);

      if (!company) {
        resetWbsFields('Choose a company first, then type at least 3 characters to search WBS by code or name.');
        return Promise.resolve();
      }

      if (existingMatch) {
        syncSearchField(wbsSearch, wbsSel, wbsItems, false);
        setHelpText(wbsHelpText, 'WBS selected.');
        return Promise.resolve();
      }

      if (!forceSearch && term.length < minWbsSearchLength) {
        wbsItems = [];
        wbsSel.value = '';
        renderSuggestions(wbsSuggestions, []);
        setHelpText(wbsHelpText, 'Type at least 3 characters to search WBS by code or name.');
        return Promise.resolve();
      }

      setHelpText(wbsHelpText, 'Searching WBS...');
      return fetch(
        'index.php?route=applications/wbs-search&company=' + encodeURIComponent(company) + '&q=' + encodeURIComponent(term),
        { credentials: 'same-origin' }
      )
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (requestId !== wbsSearchRequestId) {
          return;
        }

        wbsItems = data && Array.isArray(data.items) ? data.items : [];
        renderSuggestions(wbsSuggestions, wbsItems);
        syncSearchField(wbsSearch, wbsSel, wbsItems, false);
        setHelpText(
          wbsHelpText,
          wbsItems.length > 0
            ? 'Select a WBS from the matching results.'
            : 'No WBS options matched your search.'
        );
      })
      .catch(function () {
        if (requestId !== wbsSearchRequestId) {
          return;
        }
        wbsItems = [];
        renderSuggestions(wbsSuggestions, []);
        wbsSel.value = '';
        setHelpText(wbsHelpText, 'WBS options could not be loaded right now.');
      });
    }

    ccSearch.addEventListener('input', function () {
      syncSearchField(ccSearch, ccSel, costCentreItems, true);
    });
    ccSearch.addEventListener('change', function () {
      syncSearchField(ccSearch, ccSel, costCentreItems, true);
    });
    ccSearch.addEventListener('blur', function () {
      syncSearchField(ccSearch, ccSel, costCentreItems, true);
    });

    wbsSearch.addEventListener('input', function () {
      syncSearchField(wbsSearch, wbsSel, wbsItems, false);
      clearTimeout(wbsSearchTimer);
      wbsSearchTimer = setTimeout(function () {
        loadWbsMatches(false);
      }, wbsSearchDelayMs);
    });
    wbsSearch.addEventListener('change', function () {
      syncSearchField(wbsSearch, wbsSel, wbsItems, false);
      clearTimeout(wbsSearchTimer);
      if (wbsSel.value) {
        setHelpText(wbsHelpText, 'WBS selected.');
        return;
      }
      loadWbsMatches(true);
    });
    wbsSearch.addEventListener('blur', function () {
      syncSearchField(wbsSearch, wbsSel, wbsItems, false);
      clearTimeout(wbsSearchTimer);
      if (wbsSel.value) {
        setHelpText(wbsHelpText, 'WBS selected.');
      }
    });

    costCentreItems = <?= json_encode(array_values($costCentres ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    wbsItems = <?= json_encode(array_values($wbsList ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    renderSuggestions(ccSuggestions, costCentreItems);
    renderSuggestions(wbsSuggestions, wbsItems);
    syncSearchField(ccSearch, ccSel, costCentreItems, true);
    syncSearchField(wbsSearch, wbsSel, wbsItems, false);
    if (companySel.value && !wbsSel.value) {
      setHelpText(wbsHelpText, 'Type at least 3 characters to search WBS by code or name.');
    }

    companySel.addEventListener('change', function () {
      const company = companySel.value || '';
      resetCostCentreFields('Loading cost centres...');
      resetWbsFields('Type at least 3 characters to search WBS by code or name.');

      if (!company) {
        resetCostCentreFields('Choose a company first to load cost centres, then search by code or name.');
        resetWbsFields('Choose a company first, then type at least 3 characters to search WBS by code or name.');
        return;
      }

      fetch('index.php?route=applications/caps-options&company=' + encodeURIComponent(company), {
        credentials: 'same-origin'
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && Array.isArray(data.costCentres)) {
          costCentreItems = data.costCentres;
          renderSuggestions(ccSuggestions, costCentreItems);
          setHelpText(ccHelpText, costCentreItems.length > 0 ? 'Search cost centres by code or name.' : 'No cost centres found for this company.');
        }
      })
      .catch(function () {
        resetCostCentreFields('Cost centres could not be loaded for this company.');
        resetWbsFields('WBS options could not be loaded right now.');
      });
    });
  })();
</script>

<script>
  (function () {
    const formEl = document.querySelector('form[action*="applications/save"]');
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
    const postcode = document.getElementById('postcode');
    const postErr = document.getElementById('postcodeNumError');
    const stateSel = document.getElementById('stateSelect');
    const mobile = document.getElementById('mobile');
    const mobileErr = document.getElementById('mobileFormatError');
    const mobileCodeSel = document.getElementById('mobileCountryCode');
    const phone = document.getElementById('phone');
    const phoneErr = document.getElementById('phoneFormatError');
    const phoneCodeSel = document.getElementById('phoneCountryCode');
    const titleSel = document.getElementById('titleSelect');
    const genderSel = document.getElementById('genderSelect');
    const titleReqErr = document.getElementById('titleReqError');
    const genderReqErr = document.getElementById('genderReqError');
    const brandingReqErr = document.getElementById('brandingReqError');
    const dobInput = document.querySelector('input[name="date_of_birth"]');
    const employeeTypeInput = document.querySelector('input[name="employee_type"]');
    const openDeclarationBtn = document.getElementById('openDeclarationBtn');
    const declarationModalEl = document.getElementById('declarationModal');
    const submitBlockedModalEl = document.getElementById('submitBlockedModal');
    const submitLockedByPermissions = !!(openDeclarationBtn && openDeclarationBtn.hasAttribute('disabled'));
    const submitBlockedItemsWrap = document.getElementById('submitBlockedItemsWrap');
    const submitBlockedItems = document.getElementById('submitBlockedItems');
    const trainingCompleted = <?= !empty($data['training_completed']) ? 'true' : 'false' ?>;
    const companySel = document.getElementById('companySelect');
    const costSel = document.getElementById('costCentreSelect');
    const wbsSel = document.getElementById('wbsSelect');
    const costSearch = document.getElementById('costCentreSearch');
    const wbsSearch = document.getElementById('wbsSearch');
    const cmsSel = document.getElementById('cmsAccountHolderSelect');
    const cmsActiveFlag = document.getElementById('cmsAccountHolderActiveFlag');
    const cmsDisplay = document.getElementById('cmsAccountHolderDisplay');
    const companyReqErr = document.getElementById('companyReqError');
    const costReqErr = document.getElementById('costCentreReqError');
    const cmsStatusMsg = document.getElementById('cmsAccountHolderStatusMessage');
    const cmsReqErr = document.getElementById('cmsAccountHolderReqError');
    const brandingSel = document.getElementById('brandingSelect');
    const brandingCardPreview = document.getElementById('brandingCardPreview');
    const brandingCardPreviewPlaceholder = document.getElementById('brandingCardPreviewPlaceholder');
    const supervisorSearch = document.getElementById('supervisorSearch');
    const supervisorEmployeeId = document.getElementById('supervisorEmployeeId');
    const supervisorName = document.getElementById('supervisorName');
    const supervisorEmail = document.getElementById('supervisorEmail');
    const supervisorEmailDisplay = document.getElementById('supervisorEmailDisplay');
    const supervisorReqErr = document.getElementById('supervisorReqError');
    const supervisorHelpText = document.getElementById('supervisorHelpText');
    const supervisorSearchStatus = document.getElementById('supervisorSearchStatus');
    const supervisorSuggestions = document.getElementById('supervisorSuggestions');
    const applicantEmployeeId = <?= json_encode((string)$employeeId, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const brandingImageOptions = <?= json_encode($brandingImageOptions, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    if (!a1 || !a2 || !a3 || !suburb || !a1Help || !a2Help || !a3Help || !addrErr || !a1ReqErr || !a1LenErr || !a2Err || !a3Err || !suburbErr) return;

    const suburbDatasetUrl = 'assets/data/au_suburbs.json<?= $suburbDatasetVersion !== '' ? '?v=' . h($suburbDatasetVersion) : '' ?>';
    let suburbRows = [];
    let suburbRowsLoaded = false;
    let suburbRowsFailed = false;

    function lenTrim(s) { return (s || '').trim().length; }
    function lenRaw(s) { return (s || '').length; }
    function norm(s) { return String(s || '').trim().toUpperCase(); }
    function trimSuburbValue(raw) { return String(raw || '').slice(0, 21); }

    function syncCmsAccountMessage() {
      if (!cmsReqErr && !cmsStatusMsg) return;

      const cmsValue = cmsSel ? String(cmsSel.value || '').trim() : '';
      const cmsIsActive = !cmsActiveFlag || String(cmsActiveFlag.value || '') === '1';
      let message = '';
      let mode = '';

      if (cmsValue === '') {
        message = 'An Active Account is required. Please allow up to 30 minutes for your new CMS User ID to be recognised in the portal';
        mode = 'blank';
      } else if (!cmsIsActive) {
        message = 'Email a screenshot of the above CMS User ID field to defence.creditcards@defence.gov.au and request re-activation of your CMS Account.';
        mode = 'inactive';
      }

      if (cmsStatusMsg) {
        cmsStatusMsg.textContent = message;
        cmsStatusMsg.style.display = message === '' ? 'none' : '';
        if (mode === 'blank') {
          cmsStatusMsg.className = 'form-text text-muted';
        } else if (mode === 'inactive') {
          cmsStatusMsg.className = 'small text-danger';
        }
      }

    }

    function syncBrandingCardPreview() {
      if (!brandingCardPreview || !brandingCardPreviewPlaceholder) return;
      const images = brandingImageOptions && typeof brandingImageOptions === 'object' ? (brandingImageOptions.images || {}) : {};
      const brandingValue = brandingSel ? String(brandingSel.value || '').trim() : '';
      const imageSrc = typeof images[brandingValue] === 'string' ? images[brandingValue] : '';
      const fallbackAlt = brandingImageOptions && typeof brandingImageOptions.fallbackAlt === 'string'
        ? brandingImageOptions.fallbackAlt
        : 'Selected card preview';

      if (imageSrc !== '') {
        brandingCardPreview.src = imageSrc;
        brandingCardPreview.alt = brandingValue !== '' ? `${brandingValue} ${fallbackAlt}` : fallbackAlt;
        brandingCardPreview.classList.remove('d-none');
        brandingCardPreviewPlaceholder.classList.add('d-none');
        return;
      }

      brandingCardPreview.removeAttribute('src');
      brandingCardPreview.alt = fallbackAlt;
      brandingCardPreview.classList.add('d-none');
      brandingCardPreviewPlaceholder.classList.remove('d-none');
    }

    let supervisorSearchSeq = 0;

    function clearSupervisorSelection() {
      if (supervisorEmployeeId) supervisorEmployeeId.value = '';
      if (supervisorName) supervisorName.value = '';
      if (supervisorEmail) supervisorEmail.value = '';
      if (supervisorEmailDisplay) supervisorEmailDisplay.value = '';
    }

    function hideSupervisorSuggestions() {
      if (!supervisorSuggestions) return;
      supervisorSuggestions.innerHTML = '';
      supervisorSuggestions.classList.add('d-none');
    }

    function setSupervisorSearchLoading(isLoading) {
      if (!supervisorSearchStatus) return;
      supervisorSearchStatus.classList.toggle('is-visible', !!isLoading);
    }

    function applySupervisorSelection(item) {
      if (!item || !supervisorSearch) return;
      supervisorSearch.value = String(item.label || item.display_name || '');
      if (supervisorEmployeeId) supervisorEmployeeId.value = String(item.employee_id || '');
      if (supervisorName) supervisorName.value = String(item.display_name || '');
      if (supervisorEmail) supervisorEmail.value = String(item.email || '');
      if (supervisorEmailDisplay) supervisorEmailDisplay.value = String(item.email || '');
      if (supervisorReqErr) supervisorReqErr.style.display = 'none';
      supervisorSearch.classList.remove('is-invalid');
      hideSupervisorSuggestions();
      queueUpdate();
    }

    function renderSupervisorSuggestions(items) {
      if (!supervisorSuggestions || !supervisorSearch) return;
      supervisorSuggestions.innerHTML = '';
      if (!Array.isArray(items) || items.length === 0) {
        supervisorSuggestions.classList.add('d-none');
        return;
      }

      items.forEach(function (item) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'list-group-item list-group-item-action';
        button.textContent = String(item.label || item.display_name || '');
        button.addEventListener('mousedown', function (evt) {
          evt.preventDefault();
          applySupervisorSelection(item);
        });
        supervisorSuggestions.appendChild(button);
      });

      supervisorSuggestions.classList.remove('d-none');
    }

    function searchSupervisors(query) {
      if (!supervisorSearch || typeof fetch !== 'function') return;
      const trimmed = String(query || '').trim();
      if (trimmed.length < 2) {
        hideSupervisorSuggestions();
        setSupervisorSearchLoading(false);
        if (supervisorHelpText) {
          supervisorHelpText.textContent = 'Start typing at least 2 characters to search supervisors.';
        }
        return;
      }

      const seq = ++supervisorSearchSeq;
      setSupervisorSearchLoading(true);
      if (supervisorHelpText) {
        supervisorHelpText.textContent = 'Searching supervisors...';
      }

      fetch('index.php?route=applications/supervisor-search&q=' + encodeURIComponent(trimmed), {
        credentials: 'same-origin'
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('HTTP ' + response.status);
          }
          return response.json();
        })
        .then(function (payload) {
          if (seq !== supervisorSearchSeq) return;
          setSupervisorSearchLoading(false);
          const items = payload && Array.isArray(payload.items) ? payload.items : [];
          renderSupervisorSuggestions(items);
          if (supervisorHelpText) {
            supervisorHelpText.textContent = items.length > 0
              ? 'Select the correct supervisor from the results below.'
              : 'No supervisors matched your search.';
          }
        })
        .catch(function () {
          if (seq !== supervisorSearchSeq) return;
          setSupervisorSearchLoading(false);
          hideSupervisorSuggestions();
          if (supervisorHelpText) {
            supervisorHelpText.textContent = 'Supervisor search is unavailable right now.';
          }
        });
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
      if (state) {
        rows = rows.filter((row) => row.s === state);
      }
      if (/^\d{4}$/.test(postcodeVal)) {
        const postcodeRows = rows.filter((row) => row.p === postcodeVal);
        if (postcodeRows.length > 0) {
          rows = postcodeRows;
        }
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
        if (stateRows.length > 0) {
          rows = stateRows;
        }
      }
      if (/^\d{4}$/.test(postcodeVal)) {
        const postcodeRows = rows.filter((row) => row.p === postcodeVal);
        if (postcodeRows.length > 0) {
          rows = postcodeRows;
        }
      }

      const uniqueStates = [...new Set(rows.map((row) => row.s))];
      const uniquePostcodes = [...new Set(rows.map((row) => row.p))];

      if (stateSel && !state && uniqueStates.length === 1) {
        stateSel.value = uniqueStates[0];
      }
      if (postcode && postcodeVal === '' && uniquePostcodes.length === 1) {
        postcode.value = uniquePostcodes[0];
      }
    }

    function loadSuburbDataset() {
      if (suburbRowsLoaded || suburbRowsFailed || typeof fetch !== 'function') {
        return;
      }
      fetch(suburbDatasetUrl, { credentials: 'same-origin' })
        .then((response) => {
          if (!response.ok) {
            throw new Error('HTTP ' + response.status);
          }
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

    function getSubmitBlockingItems() {
      const l1 = lenRaw(a1.value);
      const l2 = lenRaw(a2.value);
      const l3 = lenRaw(a3.value);
      const subLen = lenRaw(suburb.value);
      const postVal = postcode ? (postcode.value || '').trim() : '';
      if (costSearch && costSel && typeof syncSearchField === 'function') {
        syncSearchField(costSearch, costSel, costCentreItems, true);
      }
      const ok = l1 <= 30
        && l2 <= 30
        && l3 <= 30
        && subLen <= 22
        && lenTrim(a1.value) > 0
        && lenTrim(suburb.value) > 0
        && (stateSel.value || '').trim() !== ''
        && (postVal !== '' && /^\d+$/.test(postVal) && postVal.length <= 4);
      const titleVal = (titleSel ? (titleSel.value || '') : '').trim().toUpperCase();
      const genderVal = (genderSel ? (genderSel.value || '') : '').trim().toUpperCase();
      const titleOk = ['PROF', 'DR', 'MR', 'MRS', 'MS', 'MISS', 'MX'].includes(titleVal);
      const genderOk = ['M', 'F', 'X'].includes(genderVal);
      const employeeDetailsOk = ok && titleOk && genderOk;
      const mobileCountryCode = mobileCodeSel ? (mobileCodeSel.value || '+61') : '+61';
      const phoneCountryCode = phoneCodeSel ? (phoneCodeSel.value || '+61') : '+61';
      const mobileOk = mobile ? isValidMobileByCountryCode((mobile.value || ''), mobileCountryCode) : false;
      const phoneVal = phone ? (phone.value || '') : '';
      const phoneOk = phone ? (phoneVal.trim() === '' || isValidPhoneByCountryCode(phoneVal, phoneCountryCode)) : true;
      const companyOk = companySel && (companySel.value || '').trim() !== '';
      const costOk = costSel && (costSel.value || '').trim() !== '';
      const cmsValueOk = cmsSel && (cmsSel.value || '').trim() !== '';
      const cmsActiveOk = !cmsActiveFlag || (cmsActiveFlag.value || '') === '1';
      const cmsFieldOk = !!(cmsValueOk && cmsActiveOk);
      const cmsOk = !!(companyOk && costOk && cmsFieldOk);
      const brandingRequired = !!brandingSel;
      const brandingOk = !brandingRequired || (brandingSel.value || '').trim() !== '';
      const supervisorRequired = !!supervisorSearch;
      const selectedSupervisorEmployeeId = supervisorEmployeeId ? (supervisorEmployeeId.value || '').trim() : '';
      const supervisorIsApplicant = supervisorRequired
        && selectedSupervisorEmployeeId !== ''
        && applicantEmployeeId.trim() !== ''
        && selectedSupervisorEmployeeId.toLowerCase() === applicantEmployeeId.trim().toLowerCase();
      const supervisorOk = !supervisorRequired
        || (selectedSupervisorEmployeeId !== ''
          && (supervisorEmail && (supervisorEmail.value || '').trim() !== '')
          && !supervisorIsApplicant);
      const dobOk = dobInput && (dobInput.value || '').trim() !== '';
      const employeeTypeOk = employeeTypeInput && (employeeTypeInput.value || '').trim() !== '';
      const blockers = [];
      if (!(ok && titleOk && genderOk)) blockers.push('Employee Details Correct');
      if (!mobileOk) blockers.push('Mobile Number Correct');
      if (!trainingCompleted) blockers.push('Training Completed');
      if (!cmsOk) blockers.push('CMS Details Complete');
      if (!brandingOk) blockers.push('Branding Selected');
      if (!supervisorOk) blockers.push('Supervisor Selected');
      if (!dobOk) blockers.push('Date of Birth Provided');
      if (!employeeTypeOk) blockers.push('Employee Type Valid');

      a1Help.textContent = `${l1}/30 characters`;
      a2Help.textContent = `${l2}/30 characters`;
      a3Help.textContent = `${l3}/30 characters`;
      a1Help.classList.toggle('text-danger', l1 > 30);
      a2Help.classList.toggle('text-danger', l2 > 30);
      a3Help.classList.toggle('text-danger', l3 > 30);

      // Clear or show inline validation on address/suburb
      const a1Err = a1.parentElement ? a1.parentElement.querySelector('.invalid-feedback') : null;
      const subErr = suburb.parentElement ? suburb.parentElement.querySelector('.invalid-feedback') : null;
      // Address line required + length warnings
      if (lenTrim(a1.value) === 0) {
        a1.classList.add('is-invalid');
        a1ReqErr.style.display = '';
      } else {
        a1ReqErr.style.display = 'none';
      }

      if (l1 > 30) {
        a1.classList.add('is-invalid');
        a1LenErr.style.display = '';
      } else {
        if (lenTrim(a1.value) > 0) {
          a1.classList.remove('is-invalid');
        }
        a1LenErr.style.display = 'none';
        if (a1Err && lenTrim(a1.value) > 0) {
          a1Err.style.display = 'none';
        }
      }
      addrErr.style.display = (l1 > 30 || l2 > 30 || l3 > 30) ? '' : 'none';

      if (l2 > 30) {
        a2.classList.add('is-invalid');
        a2Err.style.display = '';
      } else {
        a2.classList.remove('is-invalid');
        a2Err.style.display = 'none';
      }

      if (l3 > 30) {
        a3.classList.add('is-invalid');
        a3Err.style.display = '';
      } else {
        a3.classList.remove('is-invalid');
        a3Err.style.display = 'none';
      }
      if (subLen <= 22 && lenTrim(suburb.value) > 0) {
        suburb.classList.remove('is-invalid');
        if (subErr) subErr.style.display = 'none';
        suburbErr.style.display = 'none';
      } else {
        if (subLen > 22) {
          suburb.classList.add('is-invalid');
        }
        if (subErr) subErr.style.display = '';
        suburbErr.style.display = subLen > 22 ? '' : 'none';
      }

      if (titleSel && titleReqErr) {
        if (titleOk) {
          titleSel.classList.remove('is-invalid');
          titleReqErr.style.display = 'none';
        } else {
          titleSel.classList.add('is-invalid');
          titleReqErr.style.display = '';
        }
      }

      if (genderSel && genderReqErr) {
        if (genderOk) {
          genderSel.classList.remove('is-invalid');
          genderReqErr.style.display = 'none';
        } else {
          genderSel.classList.add('is-invalid');
          genderReqErr.style.display = '';
        }
      }

      if (postcode && postErr) {
        const postOk = postVal === '' || (/^\d+$/.test(postVal) && postVal.length <= 4);
        if (postOk) {
          postcode.classList.remove('is-invalid');
          postErr.style.display = 'none';
        } else {
          postcode.classList.add('is-invalid');
          postErr.style.display = '';
        }
      }

      if (stateSel) {
        stateSel.classList.toggle('is-invalid', (stateSel.value || '').trim() === '');
      }
      if (companySel) {
        companySel.classList.toggle('is-invalid', !companyOk);
        if (companyReqErr) {
          companyReqErr.style.display = companyOk ? 'none' : '';
        }
      }
      if (costSel) {
        costSel.classList.toggle('is-invalid', !costOk);
      }
      if (costSearch) {
        costSearch.classList.toggle('is-invalid', !costOk);
        if (costReqErr) {
          costReqErr.style.display = costOk ? 'none' : '';
        }
      }
      if (wbsSearch) {
        wbsSearch.classList.toggle('is-invalid', false);
      }
      if (cmsSel) {
        cmsSel.classList.toggle('is-invalid', !cmsFieldOk);
      }
      if (cmsDisplay) {
        cmsDisplay.classList.toggle('is-invalid', !cmsFieldOk);
        if (cmsReqErr) {
          cmsReqErr.style.display = 'none';
        }
      }
      syncCmsAccountMessage();
      if (brandingSel) {
        brandingSel.classList.toggle('is-invalid', !brandingOk);
        if (brandingReqErr) {
          brandingReqErr.style.display = brandingOk ? 'none' : '';
        }
      }
      if (supervisorSearch) {
        supervisorSearch.classList.toggle('is-invalid', !supervisorOk);
        if (supervisorReqErr) {
          supervisorReqErr.textContent = supervisorIsApplicant
            ? 'Supervisor cannot be the applicant.'
            : 'Supervisor is required.';
          supervisorReqErr.style.display = supervisorOk ? 'none' : '';
        }
      }

      // Update checklist icon for address_correct if present
      const icon = document.querySelector('.check-icon[data-step-key="address_correct"]');
      if (icon) {
        icon.innerHTML = employeeDetailsOk
          ? '<i class="bi bi-check-circle-fill text-success me-2"></i>'
          : '<i class="bi bi-x-circle-fill text-danger me-2"></i>';
      }

      const phoneIcon = document.querySelector('.check-icon[data-step-key="phone_correct"]');
      if (phoneIcon) {
        const mobileOk = mobile && isValidMobileByCountryCode((mobile.value || ''), mobileCountryCode);
        phoneIcon.innerHTML = mobileOk
          ? '<i class="bi bi-check-circle-fill text-success me-2"></i>'
          : '<i class="bi bi-x-circle-fill text-danger me-2"></i>';
      }

      const cmsIcon = document.querySelector('.check-icon[data-step-key="cms_complete"]');
      if (cmsIcon) {
        cmsIcon.innerHTML = cmsOk
          ? '<i class="bi bi-check-circle-fill text-success me-2"></i>'
          : '<i class="bi bi-x-circle-fill text-danger me-2"></i>';
      }

      if (mobile && mobileErr) {
        if (mobileOk) {
          mobile.classList.remove('is-invalid');
          mobileErr.classList.add('d-none');
          mobileErr.classList.remove('d-block');
        } else {
          mobile.classList.add('is-invalid');
          mobileErr.classList.remove('d-none');
          mobileErr.classList.add('d-block');
        }
      }
      if (phone && phoneErr) {
        if (phoneOk) {
          phone.classList.remove('is-invalid');
          phoneErr.classList.add('d-none');
          phoneErr.classList.remove('d-block');
        } else {
          phone.classList.add('is-invalid');
          phoneErr.classList.remove('d-none');
          phoneErr.classList.add('d-block');
        }
      }

      if (openDeclarationBtn) {
        openDeclarationBtn.dataset.allRequiredComplete = blockers.length === 0 ? '1' : '0';
      }

      return blockers;
    }

    function updateAddressState() {
      return getSubmitBlockingItems();
    }

    function renderSubmitBlockingItems() {
      if (!submitBlockedItemsWrap || !submitBlockedItems) return;
      const items = getSubmitBlockingItems();
      submitBlockedItems.innerHTML = '';
      if (items.length === 0) {
        submitBlockedItemsWrap.classList.add('d-none');
        return;
      }
      submitBlockedItemsWrap.classList.remove('d-none');
      items.forEach(function (item) {
        const li = document.createElement('li');
        li.textContent = item;
        submitBlockedItems.appendChild(li);
      });
    }

    function showSubmitBlockedMessage() {
      renderSubmitBlockingItems();
      if (submitBlockedModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = bootstrap.Modal.getOrCreateInstance(submitBlockedModalEl);
        modal.show();
        return;
      }
      alert('Please complete all mandatory fields marked with * before submitting.');
    }

    function normalizeDigits(raw) {
      return (raw || '').replace(/\D/g, '');
    }

    function normalizePhoneInput(raw) {
      let value = String(raw || '').replace(/[^\d+]/g, '');
      if (value.startsWith('+')) {
        value = '+' + value.slice(1).replace(/\+/g, '');
      } else {
        value = value.replace(/\+/g, '');
      }
      return value.slice(0, 20);
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

    function isValidPhoneByCountryCode(raw, countryCode) {
      const digits = normalizeDigits(raw);
      if (!digits) return false;

      switch ((countryCode || '+61').trim()) {
        case '+61': {
          const d = digits.startsWith('61') ? digits.slice(2) : (digits.startsWith('0') ? digits.slice(1) : digits);
          return /^[2378]\d{8}$/.test(d);
        }
        case '+1': {
          const d = (digits.length === 11 && digits.startsWith('1')) ? digits.slice(1) : digits;
          return /^\d{10}$/.test(d);
        }
        case '+44': {
          const d = digits.startsWith('44') ? digits.slice(2) : (digits.startsWith('0') ? digits.slice(1) : digits);
          return /^\d{9,10}$/.test(d) && !/^7\d{9}$/.test(d);
        }
        case '+64': {
          const d = digits.startsWith('64') ? digits.slice(2) : (digits.startsWith('0') ? digits.slice(1) : digits);
          return /^\d{8,10}$/.test(d) && !/^2\d{7,9}$/.test(d);
        }
        default:
          return /^\d{6,14}$/.test(digits);
      }
    }

    function queueUpdate() {
      if (typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(updateAddressState);
      } else {
        setTimeout(updateAddressState, 0);
      }
    }

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
      if (mobileCodeSel) mobileCodeSel.addEventListener(evt, queueUpdate);
      if (phone) phone.addEventListener(evt, queueUpdate);
      if (phoneCodeSel) phoneCodeSel.addEventListener(evt, queueUpdate);
      if (titleSel) titleSel.addEventListener(evt, queueUpdate);
      if (genderSel) genderSel.addEventListener(evt, queueUpdate);
      if (companySel) companySel.addEventListener(evt, queueUpdate);
      if (costSearch) costSearch.addEventListener(evt, queueUpdate);
      if (wbsSearch) wbsSearch.addEventListener(evt, queueUpdate);
      if (costSel) costSel.addEventListener(evt, queueUpdate);
      if (wbsSel) wbsSel.addEventListener(evt, queueUpdate);
      if (cmsSel) cmsSel.addEventListener(evt, queueUpdate);
      if (brandingSel) brandingSel.addEventListener(evt, queueUpdate);
      if (supervisorSearch) supervisorSearch.addEventListener(evt, queueUpdate);
    });

    suburb.addEventListener('input', function () {
      const trimmed = trimSuburbValue(suburb.value);
      if (suburb.value !== trimmed) {
        suburb.value = trimmed;
      }
    });

    if (mobile) {
      mobile.addEventListener('input', function () {
        const normalized = normalizePhoneInput(mobile.value);
        if (mobile.value !== normalized) {
          mobile.value = normalized;
        }
      });
    }

    syncBrandingCardPreview();
    if (brandingSel) {
      brandingSel.addEventListener('change', syncBrandingCardPreview);
      brandingSel.addEventListener('input', syncBrandingCardPreview);
    }
    if (supervisorSearch && !supervisorSearch.hasAttribute('readonly')) {
      supervisorSearch.addEventListener('input', function () {
        const currentLabel = String(supervisorSearch.value || '').trim();
        const selectedEmployeeId = supervisorEmployeeId ? String(supervisorEmployeeId.value || '').trim() : '';
        const selectedName = supervisorName ? String(supervisorName.value || '').trim() : '';
        const selectedEmail = supervisorEmail ? String(supervisorEmail.value || '').trim() : '';
        const selectedLabel = [selectedName, selectedEmployeeId, selectedEmail].filter(Boolean).join(' - ');
        if (selectedEmployeeId !== '' && currentLabel !== '' && currentLabel !== selectedLabel) {
          clearSupervisorSelection();
        }
        searchSupervisors(currentLabel);
      });
      supervisorSearch.addEventListener('blur', function () {
        setTimeout(hideSupervisorSuggestions, 150);
      });
      if (supervisorEmailDisplay && supervisorEmail && !supervisorEmailDisplay.value) {
        supervisorEmailDisplay.value = supervisorEmail.value || '';
      }
    }

    document.addEventListener('click', function (evt) {
      if (!supervisorSuggestions || !supervisorSearch) return;
      if (evt.target.closest('#supervisorSuggestions') || evt.target === supervisorSearch) return;
      hideSupervisorSuggestions();
    });

    loadSuburbDataset();
    syncCmsAccountMessage();
    updateAddressState();
    setTimeout(updateAddressState, 200);
    setTimeout(updateAddressState, 1000);

    if (openDeclarationBtn) {
      openDeclarationBtn.addEventListener('click', function (e) {
        updateAddressState();
        const ready = getSubmitBlockingItems().length === 0;
        if (submitLockedByPermissions) {
          e.preventDefault();
          e.stopPropagation();
          return;
        }
        if (!ready) {
          e.preventDefault();
          e.stopPropagation();
          showSubmitBlockedMessage();
          return;
        }
        if (declarationModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
          const modal = bootstrap.Modal.getOrCreateInstance(declarationModalEl);
          modal.show();
        }
      });
    }

    if (form) {
      form.addEventListener('keydown', function (evt) {
        if (evt.key !== 'Enter') {
          return;
        }
        const target = evt.target;
        if (!(target instanceof HTMLElement)) {
          return;
        }
        const tagName = target.tagName.toUpperCase();
        if (tagName === 'TEXTAREA') {
          return;
        }
        if (tagName === 'BUTTON') {
          return;
        }
        if (tagName === 'INPUT') {
          const inputType = String(target.getAttribute('type') || 'text').toLowerCase();
          if (['submit', 'button', 'checkbox', 'radio', 'file'].includes(inputType)) {
            return;
          }
        }
        evt.preventDefault();
      });
    }

    if (declarationModalEl) {
      declarationModalEl.addEventListener('show.bs.modal', function (e) {
        updateAddressState();
        const ready = getSubmitBlockingItems().length === 0;
        if (!ready || submitLockedByPermissions) {
          e.preventDefault();
          showSubmitBlockedMessage();
        }
      });
    }
  })();
</script>


      </form>
    </div>
  </div>
</section>

<!-- Unsaved changes modal -->
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

<script>
  (function () {
    const form = document.querySelector('form[action*="applications/save"]');
    if (!form) return;

    const backBtn = document.getElementById('backToCardsBtn');
    const leaveModalEl = document.getElementById('unsavedChangesModal');
    let leaveModal = null;
    const confirmLeaveBtn = document.getElementById('confirmLeaveBtn');
    let pendingHref = '';
    let isDirty = false;

    function snapshotForm() {
      const data = new FormData(form);
      // Exclude internal fields that can change without user intent
      data.delete('_csrf');
      data.delete('_action');
      return Array.from(data.entries())
        .map(([k, v]) => k + '=' + String(v))
        .join('&');
    }

    const initialSnapshot = snapshotForm();

    function checkDirty() {
      isDirty = snapshotForm() !== initialSnapshot;
    }

    form.addEventListener('input', checkDirty);
    form.addEventListener('change', checkDirty);
    form.addEventListener('submit', checkDirty);

    function getLeaveModal() {
      if (!leaveModalEl || !(window.bootstrap && window.bootstrap.Modal)) {
        return null;
      }
      if (!leaveModal) {
        leaveModal = new window.bootstrap.Modal(leaveModalEl);
      }
      return leaveModal;
    }

    function maybeInterceptNavigation(e, href) {
      checkDirty();
      if (!isDirty) {
        return false;
      }
      const modal = getLeaveModal();
      if (!modal || !confirmLeaveBtn) {
        return false;
      }
      e.preventDefault();
      pendingHref = href || 'index.php?route=home/index';
      confirmLeaveBtn.setAttribute('href', pendingHref);
      modal.show();
      return true;
    }

    if (backBtn) {
      backBtn.addEventListener('click', function (e) {
        maybeInterceptNavigation(e, backBtn.getAttribute('href') || 'index.php?route=home/index');
      });
    }

    if (confirmLeaveBtn) {
      confirmLeaveBtn.addEventListener('click', function () {
        if (pendingHref !== '') {
          confirmLeaveBtn.setAttribute('href', pendingHref);
        }
      });
    }

    document.addEventListener('click', function (e) {
      const link = e.target.closest('a[href]');
      if (!link || link.id === 'confirmLeaveBtn') return;
      if (link.hasAttribute('download') || link.getAttribute('target') === '_blank') return;

      const href = link.getAttribute('href') || '';
      if (href === '' || href.startsWith('#') || href.startsWith('javascript:')) return;
      if (/^(mailto:|tel:|https?:\/\/)/i.test(href)) return;

      maybeInterceptNavigation(e, href);
    });
  })();
</script>

<!-- Delete Application Modal (outside main form to avoid nested forms) -->
<div class="modal fade" id="deleteAppModal" tabindex="-1" aria-labelledby="deleteAppModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteAppModalLabel">Delete Application</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __t('close') ?? 'Close' ?>"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to delete this application? This action cannot be undone.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
          <?= __t('cancel') ?? 'Cancel' ?>
        </button>
        <form method="post" action="index.php?route=applications/delete&id=<?= h((string)$applicationId) ?>">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
          <button type="submit" class="btn btn-danger">
            Delete
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
