<?php
declare(strict_types=1);

namespace App\Controllers;

final class DashboardController extends BaseController
{
    // ACL — only logged-in users can access these pages
    protected array $acl = [
        'index'     => ['auth' => true],
        'flexdash'  => ['auth' => true],  // ← new dashboard
    ];

    public function __construct()
    {
        parent::__construct(); // Enforces login + ACL
    }

    // Your original main dashboard
    public function index(): void
    {
        $this->render('dashboards/HeadDash', [
            'title' => 'CBMS Analytics Dashboard',
        ]);
    }

    // New method for Flexmonster dashboard
    public function flexdash(): void
    {
        $this->render('dashboards/FlexDash', [
            'title' => 'CBMS Flexmonster Dashboard',
        ]);
    }
}