<?php
declare(strict_types=1);

use App\Core\Rbac;
use App\Shared\SessionHelper;

/**
 * Recursively render offcanvas menu items.
 */
function render_offcanvas_level(array $items, string $current, int $depth = 0): string {
    $html = '';
    static $seq = 0;
    $loginAgreementPending = (bool)SessionHelper::get('auth.login_agreement_pending', false);

    foreach ($items as $it) {
        if (!menu_item_visible($it)) {
            continue;
        }

        $hasKids  = !empty($it['children']);
        $isActive = route_is_active($current, $it);
        $icon     = !empty($it['icon']) ? '<i class="bi bi-'.htmlspecialchars($it['icon'], ENT_QUOTES).' me-2"></i>' : '';

        if ($hasKids) {
            $id = 'oc_' . (++$seq);
            $expanded = $isActive ? 'true' : 'false';
            $show = $isActive ? ' show' : '';
            if ($loginAgreementPending) {
                $html .= '<span class="list-group-item list-group-item-action d-flex align-items-center disabled text-muted"'
                      .  ' aria-disabled="true" tabindex="-1">'
                      .  $icon . htmlspecialchars($it['label'] ?? 'Menu', ENT_QUOTES)
                      .  '<i class="bi bi-chevron-right ms-auto chev"></i>'
                      .  '</span>';
            } else {
                $html .= '<a class="list-group-item list-group-item-action d-flex align-items-center"'
                      .  ' data-bs-toggle="collapse" href="#'.$id.'" role="button"'
                      .  ' aria-expanded="'.$expanded.'" aria-controls="'.$id.'">'
                      .  $icon . htmlspecialchars($it['label'] ?? 'Menu', ENT_QUOTES)
                      .  '<i class="bi bi-chevron-right ms-auto chev"></i>'
                      .  '</a>';
            }
            $html .= '<div class="collapse'.$show.'" id="'.$id.'"><div class="list-group list-group-flush ms-3">';
            $html .= render_offcanvas_level($it['children'], $current, $depth+1);
            $html .= '</div></div>';
        } else {
            $href = !empty($it['route']) ? 'index.php?route=' . urlencode($it['route']) : '#';
            if ($loginAgreementPending) {
                $html .= '<span class="list-group-item list-group-item-action'.($isActive ? ' active' : '').' disabled text-muted"'
                      .  ' aria-disabled="true" tabindex="-1">'
                      .  $icon . htmlspecialchars($it['label'] ?? 'Item', ENT_QUOTES)
                      .  '</span>';
            } else {
                $html .= '<a class="list-group-item list-group-item-action'.($isActive ? ' active' : '').'"'
                      .  ' href="'.htmlspecialchars($href, ENT_QUOTES).'">'
                      .  $icon . htmlspecialchars($it['label'] ?? 'Item', ENT_QUOTES)
                      .  '</a>';
            }
        }
    }
    return $html;
}

/**
 * Check if a menu item should be visible.
 */
function menu_item_visible(array $item): bool {
    $loggedIn = (bool)SessionHelper::get('auth.user_id');
    if (!$loggedIn) {
        return false;
    }

    $isAdminOverride = Rbac::canAny(['ADMIN_ALL', 'SYSADMIN']);
    if ($isAdminOverride && menu_item_is_admin_feature($item)) {
        return true;
    }

    $roles = (array)($item['roles'] ?? []);
    $perms = (array)($item['perms'] ?? []);
    if (empty($roles) && empty($perms)) {
        $route = (string)($item['route'] ?? '');
        if ($route === 'home/index') {
            return true;
        }

        if (!empty($item['children']) && is_array($item['children'])) {
            foreach ($item['children'] as $child) {
                if (is_array($child) && menu_item_visible($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    if (!empty($perms) && !Rbac::canAny($perms)) {
        return false;
    }
    if (!empty($roles) && !Rbac::hasAnyRole($roles)) {
        return false;
    }
    return true;
}

function menu_item_is_admin_feature(array $item): bool {
    $roles = array_map('strtolower', array_map('strval', (array)($item['roles'] ?? [])));
    if (in_array('admin', $roles, true) || in_array('sysadmin', $roles, true)) {
        return true;
    }

    foreach ((array)($item['perms'] ?? []) as $perm) {
        $p = strtoupper(trim((string)$perm));
        if ($p === 'ADMIN_ALL' || $p === 'SYSADMIN' || str_ends_with($p, '_ADMIN')) {
            return true;
        }
    }

    if (!empty($item['children']) && is_array($item['children'])) {
        foreach ($item['children'] as $child) {
            if (is_array($child) && menu_item_is_admin_feature($child)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Check if the current route is active.
 */
function route_is_active(string $current, array $item): bool {
    if (!empty($item['route']) && $current === $item['route']) return true;
    if (!empty($item['active']) && is_array($item['active'])) {
        foreach ($item['active'] as $p) {
            if ($p === $current) return true;
            if (str_ends_with($p, '*') && str_starts_with($current, rtrim($p, '*'))) return true;
        }
    }
    if (!empty($item['children'])) {
        foreach ($item['children'] as $c) {
            if (route_is_active($current, $c)) return true;
        }
    }
    return false;
}

/**
 * Debug banner: show current roles and perms if APP_DEBUG=true
 */
function render_menu_debug(): string {
    if (!envFlag('APP_DEBUG', false)) {
        return '';
    }
    $roles = implode(', ', Rbac::roles());
    $perms = implode(', ', Rbac::perms());
    return <<<HTML
<div class="alert alert-warning m-2 p-2">
  <strong>DEBUG:</strong> Roles = [$roles] | Perms = [$perms]
</div>
HTML;
}
