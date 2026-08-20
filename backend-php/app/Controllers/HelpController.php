<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\Lang;

class HelpController extends BaseController
{
    public function show(): void
    {
        $screen = $_GET['screen'] ?? 'default';
        $safeScreen = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', strtolower($screen));

        // Build base key: e.g. "users/list" → "UsersList"
        $parts   = explode('/', $safeScreen);
        $viewKey = implode('', array_map('ucfirst', $parts));

        // Active language, e.g. "en", "fr", "es"
        $lang = Lang::getActiveLang();

        // Try language-specific file first: UsersList.en.php
        $viewFile = __DIR__ . "/../Views/help/{$viewKey}.{$lang}.php";

        // If not found, try English fallback
        if (!is_file($viewFile)) {
            $viewFile = __DIR__ . "/../Views/help/{$viewKey}.en.php";
        }

        // If still not found, fall back to default help in the active language
        if (!is_file($viewFile)) {
            $viewFile = __DIR__ . "/../Views/help/Default.{$lang}.php";
        }

        // Final fallback: Default.en.php
        if (!is_file($viewFile)) {
            $viewFile = __DIR__ . "/../Views/help/Default.en.php";
        }

        // Render modal content
        $this->renderPartial('help/HelpBody', [
            'screen'   => $screen,
            'viewFile' => $viewFile,
        ]);
    }
}
