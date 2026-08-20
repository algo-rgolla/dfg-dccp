<?php
declare(strict_types=1);

namespace App\Controllers;

final class AnalyticsController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true, 'permsAny' => ['ANALYTICS_VIEW']],
    ];

    public function index(): void
    {
        $this->render('analytics/AnalyticsIndex', [
            'title' => 'Analytics',
        ]);
    }
}
