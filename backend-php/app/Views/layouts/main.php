<?php declare(strict_types=1); ?>

<!doctype html>

<html lang="<?= \App\Shared\Lang::getActiveLang() ?>">

<head>

    <meta charset="utf-8">

    <title><?= htmlspecialchars(__t($title ?? 'CC Portal'), ENT_QUOTES, 'UTF-8') ?></title>

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="assets/css/bootstrap.min.css" rel="stylesheet">

    <link href="assets/icons/bootstrap-icons.css" rel="stylesheet">

    <style>

        .list-group-item .chev { transition: transform .15s ease-in-out; }

        .list-group-item[aria-expanded="true"] .chev { transform: rotate(90deg); }

        .offcanvas .list-group-item { border: 0; }

        .modal-xxl { --bs-modal-width: 900px; }

        .navbar .btn, .navbar .dropdown-toggle { white-space: nowrap; }

        .navbar-brand-wrap {
            gap: .5rem;
            flex: 0 0 auto;
        }

        .navbar-title-wrap {
            flex: 1 1 auto;
            min-width: 0;
            display: flex;
            justify-content: center;
            padding-inline: 1rem;
        }

        .navbar-brand-center {
            min-width: 0;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            text-align: center;
        }

        .navbar-actions {
            flex: 0 0 auto;
        }

        .navbar-logo {

            height: 54px;

            width: auto;

            display: block;

        }

        .btn-group-sm .btn, .btn.btn-sm { padding: .25rem .5rem; font-size: .875rem; line-height: 1.2; }

        .table-admin > :not(caption) > * > * {

            padding-top: .5rem;

            padding-bottom: .5rem;

            vertical-align: middle;

       }

        .table-admin .btn-group-sm .btn {

            padding: .125rem .375rem;

            font-size: .875rem;

            line-height: 1.2;

        }

        .table-admin .btn i {

            font-size: 1em;

            line-height: 1;

            vertical-align: -0.125em;

        }

        .table-admin .badge {

            font-size: .75rem;

        }

        .feedback-stars {

            display: inline-flex;

            gap: .25rem;

        }

        .feedback-star {

            border: 0;

            background: transparent;

            padding: 0;

            font-size: 1.8rem;

            line-height: 1;

            color: #c5cbd3;

            cursor: pointer;

        }

        .feedback-star.is-active {

            color: #f4c542;

        }

        .pending-agreement-lock {

            opacity: .65;

            pointer-events: none;

        }

        .feedback-star:focus-visible {

            outline: 2px solid #0d6efd;

            outline-offset: 2px;

            border-radius: .25rem;

        }

    </style>

</head>

<body class="bg-light">

<?php

    use App\Shared\Lang;

    use App\Shared\SessionHelper;

 

    require_once __DIR__ . '/../../../shared/workflow_helpers.php';

 

    $isLoggedIn = (bool) SessionHelper::get('auth.user_id');

 

    if ($isLoggedIn):

        if (!isset($conn) || !($conn instanceof \PDO)):

            require __DIR__ . '/../../../config/db.php';

        endif;

        require_once __DIR__ . '/../../../app/Models/FiscalContextModel.php';

        require_once __DIR__ . '/../../../app/Models/FeedbackModel.php';

        require_once __DIR__ . '/../../../shared/csrf.php';

 

        $ctxModel = new \App\Models\FiscalContextModel($conn);

        $fiscalYears = $ctxModel->listFiscalYears();

       $currentFY = (int)(SessionHelper::get('FiscalYearID') ?? 0);

        $currentVer = (int)(SessionHelper::get('VersionID') ?? 0);

        $versionsList = $currentFY ? $ctxModel->listVersions($currentFY) : [];

        $_csrfToken = csrf_token();

 

        $fyLabel = 'FY';

        if (!empty($fiscalYears)):

            foreach ($fiscalYears as $fy):

                if ((int)$fy['FiscalYearID'] === $currentFY):

                    $fyLabel = (string)($fy['YearLabel'] ?? $currentFY);

                    break;

                endif;

            endforeach;

        endif;

 

        $verLabel = 'Version';

        if (!empty($versionsList)):

            foreach ($versionsList as $v):

                if ((int)$v['VersionID'] === $currentVer):

                    $verLabel = (string)($v['VersionLabel'] ?? $currentVer);

                    break;

                endif;

            endforeach;

        endif;

    endif;

 

    $menuFile = __DIR__ . '/../../../config/menu.php';

    $menu = (is_file($menuFile) && is_array($tmp = require $menuFile)) ? $tmp : [];

    require_once __DIR__ . '/../../../shared/nav_tree.php';

 

    $currentRoute = (string)($_GET['route'] ?? 'home/index');

    $activeLang = Lang::getActiveLang();

    $feedbackFormState = SessionHelper::pull('feedback.form', ['open' => false, 'comments' => '', 'stars' => 0]);

    $feedbackComments = (string)($feedbackFormState['comments'] ?? '');

    $feedbackStars = (int)($feedbackFormState['stars'] ?? 0);

    $feedbackOpen = !empty($feedbackFormState['open']);

    if ($isLoggedIn && !$feedbackOpen && isset($conn) && $conn instanceof \PDO) {

        $feedbackEmployeeId = trim((string)SessionHelper::get('auth.employee_id', ''));

        if ($feedbackEmployeeId !== '') {

            try {

                $feedbackModel = new \App\Models\FeedbackModel($conn);

                $existingFeedback = $feedbackModel->findByEmployeeId($feedbackEmployeeId);

                if (is_array($existingFeedback)) {

                    $feedbackComments = (string)($existingFeedback['Comments'] ?? '');

                    $feedbackStars = (int)($existingFeedback['Stars'] ?? 0);

                }

            } catch (\Throwable $e) {

            }

        }

    }

    $availableLangs = ['en' => 'English', 'fr' => 'Français', 'es' => 'Español'];

 

    $helpButtonUrl = '';
    if ($isLoggedIn && isset($conn) && $conn instanceof \PDO) {
        try {
            $layoutSettings = new \App\Models\SystemSettingsModel($conn);
            $helpButtonUrl = trim((string)($layoutSettings->get('PORTAL_CARDS_HANDY_LINK_URL') ?? ''));
        } catch (\Throwable $e) {
            $helpButtonUrl = '';
        }
    }

    if (!function_exists('envFlag')):

        function envFlag(string $key, bool $default = false): bool {

            $val = getenv($key);

            if ($val === false) return $default;

            $val = strtolower(trim((string)$val));

            return in_array($val, ['1','true','yes','on'], true);

        }

    endif;

 

    $scopeCode = (string)(SessionHelper::get('scope.dataobject_code') ?? '');

    $scopeName = (string)(SessionHelper::get('scope.dataobject_name') ?? '');

    $scopeLabel = $scopeCode !== ''

        ? trim(($scopeName !== '' ? $scopeName : $scopeCode) . ($scopeName && $scopeCode ? " ($scopeCode)" : ''))

        : __t('not_set');

 

    $pickerUrl = 'index.php?route=dataobjects/picker&iframe=1'

        . ($scopeCode !== '' ? '&selected=' . urlencode($scopeCode) : '')

        . '&fy=' . (int)($currentFY ?? 0)

        . '&ver=' . (int)($currentVer ?? 0);

 

    $returnUrl = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php?route=home/index');

    $clearScopeUrl = "index.php?route=dataobjects/select&clear=1&return={$returnUrl}";
    $loginAgreementPending = (bool)\App\Shared\SessionHelper::get('auth.login_agreement_pending', false);

 

?>

<nav class="navbar navbar-dark bg-dark">

    <div class="container-fluid">

        <div class="d-flex align-items-center w-100">

            <div class="d-flex align-items-center navbar-brand-wrap">

                <span class="navbar-brand m-0 p-0" aria-label="Defence Credit Card Portal">

                    <img src="assets/img/defence_logo_light.png" alt="Defence" class="navbar-logo">

                </span>

            </div>

            <div class="navbar-title-wrap">
                <span class="navbar-brand m-0 navbar-brand-center">Defence Credit Card Portal</span>
            </div>

           

            <div class="d-flex align-items-center gap-2 ms-auto flex-wrap navbar-actions">

                <?php if (SessionHelper::get('auth.user_id')): ?>

                    <span class="navbar-text text-light d-none d-sm-inline">

                        <?= __t('user') ?>:

                        <?= htmlspecialchars((string)((isset($headerUserDisplay) && trim((string)$headerUserDisplay) !== '') ? $headerUserDisplay : SessionHelper::get('auth.username')), ENT_QUOTES, 'UTF-8') ?>

                    </span>

                    <button class="btn btn-outline-light btn-sm<?= $loginAgreementPending ? ' pending-agreement-lock' : '' ?>"

                            type="button"

                            id="helpBtn"

                            data-route="<?= htmlspecialchars($currentRoute, ENT_QUOTES) ?>"
                            data-handy-url="<?= htmlspecialchars($helpButtonUrl, ENT_QUOTES, 'UTF-8') ?>"<?= $loginAgreementPending ? ' disabled aria-disabled="true" tabindex="-1"' : '' ?>>

                        <i class="bi bi-question-circle"></i> Help

                    </button>

                    <button class="btn btn-outline-light btn-sm<?= $loginAgreementPending ? ' pending-agreement-lock' : '' ?>" type="button" data-bs-toggle="modal" data-bs-target="#feedbackModal"<?= $loginAgreementPending ? ' disabled aria-disabled="true" tabindex="-1"' : '' ?>>

                        <i class="bi bi-chat-left-text me-1"></i> Feedback

                    </button>

                    <button class="btn btn-outline-light btn-sm<?= $loginAgreementPending ? ' pending-agreement-lock' : '' ?>" type="button"

                            data-bs-toggle="offcanvas" data-bs-target="#appMenu" aria-controls="appMenu"<?= $loginAgreementPending ? ' disabled aria-disabled="true" tabindex="-1"' : '' ?>>

                        <i class="bi bi-list me-1"></i> <?= __t('menu') ?>

                    </button>

                    <?php if ($loginAgreementPending): ?>
                        <span class="btn btn-outline-light btn-sm pending-agreement-lock" aria-disabled="true">Home</span>
                    <?php else: ?>
                        <a class="btn btn-outline-light btn-sm" href="index.php?route=home/index"><i class="bi bi-house-door me-1"></i>Home</a>
                    <?php endif; ?>

                    <?php if ($loginAgreementPending): ?>
                        <span class="btn btn-outline-light btn-sm pending-agreement-lock" aria-disabled="true"><?= __t('account') ?></span>
                    <?php else: ?>
                        <a class="btn btn-outline-light btn-sm" href="index.php?route=auth/account"><?= __t('account') ?></a>
                    <?php endif; ?>

                    <a class="btn btn-outline-light btn-sm" href="index.php?route=auth/logout"><?= __t('logout') ?></a>

                <?php else: ?>

                    <button class="btn btn-outline-light btn-sm" type="button"

                            data-bs-toggle="offcanvas" data-bs-target="#appMenu" aria-controls="appMenu">

                        <i class="bi bi-list me-1"></i> <?= __t('menu') ?>

                    </button>

                    <span class="navbar-text text-light d-none d-sm-inline"><?= __t('guest') ?></span>

                    <a class="btn btn-light btn-sm" href="index.php?route=auth/loginForm"><?= __t('login') ?></a>

                <?php endif; ?>

            </div>

        </div>

    </div>

</nav>

<div class="offcanvas offcanvas-start" tabindex="-1" id="appMenu" aria-labelledby="appMenuLabel" data-bs-scroll="true">

    <div class="offcanvas-header">

        <h5 class="offcanvas-title" id="appMenuLabel"><?= __t('navigation') ?></h5>

        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>

    </div>

    <div class="offcanvas-body p-0">

        <?php if (envFlag('APP_DEBUG', false)): ?>

            <div class="alert alert-warning m-2 p-2">

                <strong>DEBUG MENU</strong><br>

                <?= 'Menu items loaded: ' . count($menu) ?><br>

                <?= 'Current route: ' . htmlspecialchars($currentRoute, ENT_QUOTES) ?><br>

                <?= 'Roles in session: ' . htmlspecialchars(json_encode(SessionHelper::get('auth.roles', [])), ENT_QUOTES) ?><br>

                <?= 'Perms in session: ' . htmlspecialchars(json_encode(SessionHelper::get('auth.perms', [])), ENT_QUOTES) ?>

            </div>

        <?php endif; ?>

        <div class="list-group list-group-flush">

            <?= render_offcanvas_level($menu, $currentRoute, 0) ?>

        </div>

    </div>

</div>

<main class="container my-3">

    <?php

        $flash = SessionHelper::get('flash.message', null);

        if (is_array($flash) && !empty($flash['text'])):

            SessionHelper::forget('flash.message');

            unset($_SESSION['flash']['message'], $_SESSION['flash.message']);

            if (isset($_SESSION['flash']) && empty($_SESSION['flash'])) unset($_SESSION['flash']);

            $type = $flash['type'] ?? 'info';

            $allowed = ['success','danger','warning','info'];

            if (!in_array($type, $allowed, true)) $type = 'info';

            $autoDismiss = in_array($type, ['success','info'], true);

    ?>

        <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show <?= $autoDismiss ? 'auto-dismiss' : '' ?>" role="alert">

            <?= htmlspecialchars((string)$flash['text'], ENT_QUOTES, 'UTF-8') ?>

            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>

        </div>

    <?php endif; ?>

    <?php if ($currentRoute === 'auth/loginForm'): ?>

        <?= $content ?? '' ?>

    <?php else: ?>

        <?php if (SessionHelper::get('auth.user_id')): ?>

            <?= $content ?? '' ?>

        <?php else: ?>

            <?php

$isPublicRoute = $currentRoute === 'auth/loginForm'

              || str_starts_with($currentRoute, 'onboarding/');

 

if ($isPublicRoute || SessionHelper::get('auth.user_id')):

    echo $content ?? '';

else: ?>

    <p>Please log in to view this content.</p>

<?php endif; ?>

        <?php endif; ?>

    <?php endif; ?>

</main>

<div class="modal fade" id="dataObjectPickerModal" tabindex="-1" aria-hidden="true" aria-labelledby="dataObjectPickerLabel">

    <div class="modal-dialog modal-dialog-centered modal-xxl">

        <div class="modal-content">

            <div class="modal-header">

                <h5 id="dataObjectPickerLabel" class="modal-title">

                    <i class="bi bi-diagram-3 me-2"></i><?= __t('select_data_scope') ?>

                </h5>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __t('close') ?>"></button>

            </div>

            <div class="modal-body p-0">

                <iframe

                    id="dataObjectPickerFrame"

                    src="<?= htmlspecialchars($pickerUrl, ENT_QUOTES, 'UTF-8') ?>"

                    title="DataObject Picker"

                    style="width:100%;height:520px;border:0;"

                    loading="lazy"

                ></iframe>

            </div>

            <div class="modal-footer">

                <a class="btn btn-outline-secondary" href="<?= $clearScopeUrl ?>">

                    <i class="bi bi-x-circle me-1"></i><?= __t('clear_scope') ?>

                </a>

                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">

                    <?= __t('done') ?>

                </button>

            </div>

        </div>

    </div>

</div>

<?php if ($isLoggedIn): ?>

<div class="modal fade" id="feedbackModal" tabindex="-1" aria-hidden="true" aria-labelledby="feedbackModalLabel">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="post" action="index.php?route=feedback/save">

                <div class="modal-header">

                    <h5 id="feedbackModalLabel" class="modal-title">

                        <i class="bi bi-chat-left-text me-2"></i>Share Feedback

                    </h5>

                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>

                </div>

                <div class="modal-body">

                    <?= csrf_field() ?>

                    <input type="hidden" name="return_url" value="<?= htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? 'index.php?route=home/index'), ENT_QUOTES, 'UTF-8') ?>">

                    <div class="mb-3">

                        <label class="form-label" for="feedbackComments">Your feedback</label>

                        <textarea

                            class="form-control"

                            id="feedbackComments"

                            name="comments"

                            rows="5"

                            maxlength="500"

                            required

                            placeholder="Tell us what is working well or what we should improve."

                        ><?= htmlspecialchars($feedbackComments, ENT_QUOTES, 'UTF-8') ?></textarea>

                        <div class="d-flex justify-content-between mt-1">

                            <div class="form-text">Maximum 500 characters.</div>

                            <div class="form-text"><span id="feedbackCharCount"><?= strlen($feedbackComments) ?></span>/500</div>

                        </div>

                    </div>

                    <div>

                        <label class="form-label d-block">Rating</label>

                        <input type="hidden" name="stars" id="feedbackStarsInput" value="<?= $feedbackStars > 0 ? $feedbackStars : '' ?>">

                        <div class="feedback-stars" id="feedbackStars" role="radiogroup" aria-label="Feedback rating">

                            <?php for ($i = 1; $i <= 5; $i++): ?>

                                <button

                                    type="button"

                                    class="feedback-star<?= $feedbackStars >= $i ? ' is-active' : '' ?>"

                                    data-value="<?= $i ?>"

                                    aria-label="<?= $i ?> star<?= $i === 1 ? '' : 's' ?>"

                                    aria-pressed="<?= $feedbackStars >= $i ? 'true' : 'false' ?>"

                                >

                                    <i class="bi bi-star-fill"></i>

                                </button>

                            <?php endfor; ?>

                        </div>

                        <div class="form-text mt-1">Click a star to rate from 1 to 5.</div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>

                    <button type="submit" class="btn btn-primary">Submit Feedback</button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php endif; ?>

<script src="assets/js/bootstrap.bundle.min.js"></script>

<script>

window.addEventListener('error', function(e) { console.log('[WF] JS error:', e.message); });

 

document.addEventListener("DOMContentLoaded", () => {

    if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) return;

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {

        bootstrap.Tooltip.getOrCreateInstance(el);

    });

});

document.addEventListener("DOMContentLoaded", () => {

    const DELAY_MS = 5000;

    document.querySelectorAll('.alert.auto-dismiss').forEach((el) => {

        setTimeout(() => {

            if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {

                const instance = bootstrap.Alert.getOrCreateInstance(el);

                instance.close();

            } else {

                el.classList.remove('show');

                setTimeout(() => el.remove(), 200);

            }

        }, DELAY_MS);

    });

});

 

document.addEventListener("DOMContentLoaded", () => {

    const textarea = document.getElementById('feedbackComments');

    const charCount = document.getElementById('feedbackCharCount');

    const starsWrap = document.getElementById('feedbackStars');

    const starsInput = document.getElementById('feedbackStarsInput');

    const feedbackModalEl = document.getElementById('feedbackModal');

    if (!textarea || !charCount || !starsWrap || !starsInput || !feedbackModalEl) return;

    const starButtons = Array.from(starsWrap.querySelectorAll('.feedback-star'));

    const updateCharCount = () => {

        charCount.textContent = String(textarea.value.length);

    };

    const renderStars = (selected) => {

        starButtons.forEach((button, idx) => {

            const active = idx < selected;

            button.classList.toggle('is-active', active);

            button.setAttribute('aria-pressed', active ? 'true' : 'false');

        });

    };

    starButtons.forEach((button) => {

        button.addEventListener('click', () => {

            const value = Number(button.getAttribute('data-value') || '0');

            starsInput.value = value > 0 ? String(value) : '';

            renderStars(value);

        });

    });

    textarea.addEventListener('input', updateCharCount);

    feedbackModalEl.addEventListener('shown.bs.modal', () => {

        updateCharCount();

        renderStars(Number(starsInput.value || '0'));

        textarea.focus();

    });

    updateCharCount();

    renderStars(Number(starsInput.value || '0'));

});

(function () {

    const frame = document.getElementById('dataObjectPickerFrame');

    if (!frame) return;

 

    window.addEventListener('message', function (e) {

        try {

            const data = e.data || {};

            if (data && data.type === 'dataobject:selected' && data.code) {

                const ret = encodeURIComponent(window.location.href);

                const url = 'index.php?route=dataobjects/select'

                    + '&code=' + encodeURIComponent(data.code)

                    + (data.name ? '&name=' + encodeURIComponent(data.name) : '')

                    + '&return=' + ret;

                window.location.href = url;

            }

        } catch (err) { }

    }, false);

})();

</script>

<?php if ($isLoggedIn): ?>

    <?php if ($feedbackOpen): ?>

    <script>

    document.addEventListener('DOMContentLoaded', () => {

        const modalEl = document.getElementById('feedbackModal');

        if (!modalEl) return;

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        modal.show();

    });

    </script>

    <?php endif; ?>

    <script>

    (function FiscalContextUI() {

        const form = document.getElementById('fiscalContextForm');

        const fyHidden = document.getElementById('FiscalYearID_hidden');

        const verHidden = document.getElementById('VersionID_hidden');

        const fyBtn = document.getElementById('fyDropdownBtn');

        const verBtn = document.getElementById('verDropdownBtn');

        const verMenu = document.getElementById('verDropdownMenu');

 

        if (!form || !fyHidden || !verHidden || !fyBtn || !verBtn || !verMenu) return;

 

        function rebuildVersionMenu(list, activeId) {

            verMenu.innerHTML = '';

            if (!list || list.length === 0) {

                verMenu.innerHTML = '<li><span class="dropdown-item disabled"><?= __t('no_results') ?></span></li>';

                return;

            }

            list.forEach(v => {

                const li = document.createElement('li');

                const a = document.createElement('a');

                a.href = '#';

                a.className = 'dropdown-item' + (String(v.VersionID) === String(activeId) ? ' active' : '');

                a.dataset.versionId = v.VersionID;

                a.textContent = v.VersionLabel || v.VersionID;

                li.appendChild(a);

                verMenu.appendChild(li);

            });

        }

 

        document.addEventListener('click', async (e) => {

            const a = e.target.closest('a[data-fy-id]');

            if (!a) return;

            e.preventDefault();

 

            const fyId = a.dataset.fyId;

            fyHidden.value = fyId;

            fyBtn.innerHTML = '<i class="bi bi-calendar3 me-1"></i>' + a.textContent;

 

            verMenu.innerHTML = '<li><span class="dropdown-item disabled"><?= __t('loading') ?></span></li>';

            verHidden.value = '';

            verBtn.innerHTML = '<i class="bi bi-layers me-1"></i><?= __t('version') ?>';

 

            try {

                const res = await fetch('index.php?route=context/listVersions&FiscalYearID=' + encodeURIComponent(fyId), { credentials: 'same-origin' });

                const data = await res.json();

 

                if (!data || data.length === 0) {

                    verMenu.innerHTML = '<li><span class="dropdown-item disabled"><?= __t('no_results') ?></span></li>';

                    return;

                }

 

                const def = data.find(v => String(v.IsDefault) === '1' || v.IsDefault === 1 || v.IsDefault === true) || data[0];

                verHidden.value = def.VersionID;

                verBtn.innerHTML = '<i class="bi bi-layers me-1"></i>' + (def.VersionLabel || def.VersionID);

 

                rebuildVersionMenu(data, def.VersionID);

                form.submit();

            } catch {

                verMenu.innerHTML = '<li><span class="dropdown-item disabled"><?= __t('error') ?></span></li>';

            }

        });

 

        document.addEventListener('click', (e) => {

            const a = e.target.closest('a[data-version-id]');

            if (!a) return;

            e.preventDefault();

 

            const verId = a.dataset.versionId;

            verHidden.value = verId;

            verBtn.innerHTML = '<i class="bi bi-layers me-1"></i>' + a.textContent;

 

            form.submit();

        });

    })();

    </script>

    <script>

    document.addEventListener("DOMContentLoaded", () => {

        const helpBtn = document.getElementById("helpBtn");

        if (!helpBtn) return;

 

        helpBtn.addEventListener("click", async () => {

            const route = helpBtn.getAttribute("data-route");
            const handyUrl = (helpBtn.getAttribute("data-handy-url") || "").trim();
            if (handyUrl !== "") {
                window.open(handyUrl, "_blank", "noopener");
                return;
            }

            const bodyEl = document.getElementById("helpModalBody");

            const titleEl = document.getElementById("helpModalLabel");

 

            bodyEl.innerHTML = `<div class="text-center my-4">

                <div class="spinner-border text-primary" role="status">

                    <span class="visually-hidden">Loading…</span>

                </div>

            </div>`;

 

            try {

                const res = await fetch("index.php?route=help/show&screen=" + encodeURIComponent(route), { credentials: "same-origin" });

                const html = await res.text();

                bodyEl.innerHTML = html;

                if (titleEl) {

                    titleEl.innerHTML = `<i class="bi bi-question-circle me-2"></i> Help – ${route}`;

                }

            } catch (err) {

                console.error("Failed to load help", err);

                bodyEl.innerHTML = `<p class="text-danger">Failed to load help content.</p>`;

            }

 

            const modalEl = document.getElementById("helpModal");

            if (modalEl) {

                const modal = bootstrap.Modal.getOrCreateInstance(modalEl, {

                    backdrop: "static",

                    keyboard: true

                });

                modal.show();

            }

        });

   });

 

    function printHelpContent() {

        const bodyEl = document.getElementById("helpModalBody");

        if (!bodyEl) return;

 

        const printWindow = window.open('', '_blank', 'width=900,height=650');

        printWindow.document.write(`

            <html>

                <head>

                    <title>Help</title>

                    <link href="assets/css/bootstrap.min.css" rel="stylesheet">

                    <style>body { font-family: Arial, sans-serif; padding: 20px; }</style>

                </head>

                <body>

                    ${bodyEl.innerHTML}

                </body>

            </html>

        `);

        printWindow.document.close();

        printWindow.focus();

        printWindow.print();

        printWindow.close();

    }

    </script>

    <script>

    document.addEventListener("DOMContentLoaded", () => {

        const fy = <?= (int)($currentFY ?? 0) ?>;

        const ver = <?= (int)($currentVer ?? 0) ?>;

        const code = "<?= htmlspecialchars($scopeCode, ENT_QUOTES) ?>";

 

        const btnLabel = document.getElementById("wfStatusLabel");

        const btnIcon = document.getElementById("wfStatusIcon");

        const wfBtn = document.getElementById("wfStatusBtn");

 

        function updateStatusUI(status) {

            if (!btnLabel || !wfBtn) return;

            const iconMap = {

                "Open": "bi-circle text-info",

                "In Progress": "bi-hourglass-split text-primary",

                "Completed": "bi-check-circle text-success",

                "Approved": "bi-hand-thumbs-up text-success",

                "Rejected": "bi-x-circle text-danger",

                "Closed": "bi-lock text-warning",

                "Not Set": "bi-question-circle text-warning"

            };

            btnLabel.textContent = status;

            if (btnIcon) btnIcon.className = "bi " + (iconMap[status] || "bi-circle");

            if (status === "Not Set") { wfBtn.classList?.add("disabled"); } else { wfBtn.classList?.remove("disabled"); }

        }

 

        if (!fy || !ver || !code) {

            updateStatusUI("Not Set");

            return;

        }

    });

    </script>

<?php else: ?>

    <script>

    console.log("User not logged in, skipping FiscalContextUI");

    </script>

<?php endif; ?>

<div class="modal fade" id="helpModal" tabindex="-1" aria-hidden="true" aria-labelledby="helpModalLabel">

    <div class="modal-dialog modal-lg modal-dialog-scrollable">

        <div class="modal-content">

            <div class="modal-header">

                <h5 id="helpModalLabel" class="modal-title">

                    <i class="bi bi-question-circle me-2"></i><?= __t('help') ?>

                </h5>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __t('close') ?>"></button>

            </div>

            <div class="modal-body" id="helpModalBody">

                <div class="text-center text-muted">

                    <div class="spinner-border text-primary" role="status">

                        <span class="visually-hidden">Loading…</span>

                    </div>

                </div>

            </div>

            <div class="modal-footer">

                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">

                    <i class="bi bi-x-circle me-1"></i><?= __t('close') ?>

                </button>

                <button type="button" class="btn btn-outline-primary" onclick="printHelpContent()">

                    <i class="bi bi-printer me-1"></i><?= __t('print') ?>

                </button>

            </div>

        </div>

    </div>

</div>

</body>

</html>
