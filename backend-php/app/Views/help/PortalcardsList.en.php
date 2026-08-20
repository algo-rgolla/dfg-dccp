<?php
declare(strict_types=1);
?>
<div class="p-3">
  <h5 class="mb-3"><i class="bi bi-credit-card-2-front me-2"></i>Help - My Cards</h5>
  <p class="text-muted">
    This screen lists the cards available to you in the portal and provides the supported actions for each card.
  </p>

  <div class="alert alert-info py-2 mb-3" role="alert">
    <i class="bi bi-info-circle me-2"></i>
    Use this help as a guide to the main purpose of the screen, the important checks to make, and any workflow or data impacts to be aware of.
  </div>

  <hr>

  <h6><i class="bi bi-info-circle me-2"></i>What The List Shows</h6>
  <ul>
    <li>cardholder and card details</li>
    <li>card type and status</li>
    <li>available actions based on the current card state</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i>Available Actions</h6>
  <ul>
    <li>view card history</li>
    <li>request a limit change</li>
    <li>change address details</li>
    <li>cancel a card</li>
    <li>review submitted change requests</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i>Why An Action May Be Unavailable</h6>
  <ul>
    <li>a pending request already exists for the card</li>
    <li>the card status does not support that action</li>
    <li>business rules restrict the action in the current state</li>
  </ul>

  <hr>
  <p class="text-muted small mb-0">
    Tip: If you need to understand why an action is missing, check the card status and the Change Requests screen first.
  </p>
</div>
