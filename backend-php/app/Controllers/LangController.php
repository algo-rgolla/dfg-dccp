<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;

class LangController extends BaseController
{
    public function switch(): void
    {
        $lang = $_GET['lang'] ?? 'en';
        $allowed = ['en', 'fr', 'es']; // extendable

        if (in_array($lang, $allowed, true)) {
            SessionHelper::set('lang', $lang);
        }

        // Redirect back to referer or home
        $redirect = $_SERVER['HTTP_REFERER'] ?? 'index.php?route=home/index';
        header('Location: ' . $redirect);
        exit;
    }
}
