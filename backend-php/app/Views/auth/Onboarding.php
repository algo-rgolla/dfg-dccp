<?php
declare(strict_types=1);



require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}



$csrf = h(csrf_token());

/** @var string $windowsLogin */
$windowsLogin = (string)($windowsLogin ?? '');
$activationEnabled = (bool)($activationEnabled ?? true);
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activate your DCCP account - Defence Credit Card Portal</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/icons/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; font-family: system-ui, -apple-system, sans-serif; }
        .card { max-width: 500px; margin: 3rem auto; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .card-header { background: #0d6efd; color: white; font-weight: 500; }
        .form-label { font-weight: 500; }
        .btn-primary { background: #0d6efd; border: none; }
        .btn-primary:hover { background: #0b5ed7; }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="card shadow-sm">
        <div class="card-header">
            <strong><i class="bi bi-link-45deg me-2"></i>Activate your DCCP account</strong>
        </div>
        <div class="card-body">

            <!-- FLASH MESSAGE DISPLAY - ADDED HERE -->
            <?php
            $flash = \App\Shared\SessionHelper::get('flash.message', null);
            if ($flash && is_array($flash) && !empty($flash['text'])):
                $type = $flash['type'] ?? 'danger';
                  if (!in_array($type, ['success', 'danger', 'warning', 'info'])) {
                      $type = 'danger';
                  }
                $allowed = ['success', 'danger', 'warning', 'info'];
                if (!in_array($type, $allowed)) $type = 'danger';
            ?>
                <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES) ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($flash['text'], ENT_QUOTES) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php 
                // Clean up flash after display
                \App\Shared\SessionHelper::forget('flash.message');
                if (isset($_SESSION['flash']['message'])) unset($_SESSION['flash']['message']);
            endif;
            ?>

            <p class="text-muted mb-3">
                We’ve detected your login:
                <span class="fw-semibold"><?= h($windowsLogin ?: '(not detected)') ?></span>
            </p>

            <p class="text-muted">
                To enable Single Sign-On to the DCCP for future access, please confirm your <strong>Employee ID</strong> and <strong>Email</strong>.
            </p>

            <?php if (!$activationEnabled): ?>
                <div class="alert alert-warning" role="alert">
                    New user activation is currently disabled. Existing activated users can still log in.
                </div>
            <?php else: ?>
                <form method="post" action="index.php?route=onboarding/save" class="mt-3">
                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">

                    <div class="mb-3">
                        <label class="form-label">EmployeeID</label>
                        <input name="EmployeeID" class="form-control" required autocomplete="off" maxlength="50">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email address</label>
                        <input name="Email" type="email" class="form-control" required maxlength="100">
                    </div>

                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" type="submit" onclick="console.log('Submit clicked'); return true;">
                            <i class="bi bi-check2-circle me-1"></i>Activate and Continue
                        </button>
                        <a class="btn btn-outline-secondary" href="index.php?route=auth/logout">
                            Cancel
                        </a>
                    </div>
                </form>
            <?php endif; ?>

        </div>
    </div>
</div>
<script>
const onboardingForm = document.querySelector('form');
if (onboardingForm) {
    onboardingForm.addEventListener('submit', function(e) {
        const btn = this.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Linking...';
    });
}
</script>
</body>
</html>
