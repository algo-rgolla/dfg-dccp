<?php declare(strict_types=1); ?>
<style>
  .submit-feedback-overlay {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(33, 37, 41, .45);
    z-index: 2000;
  }
  .submit-feedback-overlay.is-visible {
    display: flex;
  }
  .submit-feedback-card {
    min-width: 240px;
    max-width: 90vw;
  }
</style>
<div id="submitFeedbackOverlay" class="submit-feedback-overlay" aria-hidden="true">
  <div class="card shadow submit-feedback-card">
    <div class="card-body d-flex align-items-center gap-3">
      <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
      <div>
        <div class="fw-semibold">Submitting</div>
        <div class="text-muted small">Please wait while we save your request.</div>
      </div>
    </div>
  </div>
</div>
<script>
  (function () {
    if (window.__submitFeedbackInitialised) {
      return;
    }
    window.__submitFeedbackInitialised = true;

    document.addEventListener('DOMContentLoaded', function () {
      var overlay = document.getElementById('submitFeedbackOverlay');
      if (!overlay) {
        return;
      }

      var activeSubmit = false;
      var forms = document.querySelectorAll('form.js-submit-feedback-form');
      forms.forEach(function (form) {
        form.addEventListener('submit', function (evt) {
          window.setTimeout(function () {
            if (evt.defaultPrevented || activeSubmit) {
              return;
            }

            activeSubmit = true;
            overlay.classList.add('is-visible');
            overlay.setAttribute('aria-hidden', 'false');

            var submitter = document.activeElement;
            if (submitter && (submitter.tagName === 'BUTTON' || submitter.tagName === 'INPUT')) {
              if (typeof submitter.disabled !== 'undefined') {
                submitter.disabled = true;
              }
              if (submitter.tagName === 'BUTTON') {
                submitter.setAttribute('data-original-text', submitter.innerHTML);
                submitter.innerHTML = 'Submitting...';
              } else if ((submitter.getAttribute('type') || '').toLowerCase() === 'submit') {
                submitter.setAttribute('data-original-value', submitter.value);
                submitter.value = 'Submitting...';
              }
            }
          }, 0);
        });
      });

      window.addEventListener('pageshow', function () {
        activeSubmit = false;
        overlay.classList.remove('is-visible');
        overlay.setAttribute('aria-hidden', 'true');
      });
    });
  })();
</script>
