<?php
if (!function_exists('workflow_status_icon')) {
    function workflow_status_icon(string $status): string {
        $iconMap = [
            'Open'        => 'bi-circle text-info',        // brighter blue
            'In Progress' => 'bi-hourglass-split text-primary',
            'Completed'   => 'bi-check-circle text-success',
            'Approved'    => 'bi-hand-thumbs-up text-success',
            'Rejected'    => 'bi-x-circle text-danger',
            'Closed'      => 'bi-lock text-warning',       // yellow so visible
            'Not Set'     => 'bi-question-circle text-warning',
        ];

        $cls = $iconMap[$status] ?? 'bi-circle';
        return '<i class="bi ' . $cls . ' me-1"></i>';
    }
}
